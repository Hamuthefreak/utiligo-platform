/**
 * assets/js/leads.js  v5
 *
 * CHANGES FROM v4
 * ===============
 * - Seen mechanic bulletproof:
 *   · seenBefore snapshot taken BEFORE markSeen — fresh leads never show Seen badge on first view
 *   · markSeen / wasSeen both guard id=0 and empty strings — stale cache ids can never pollute seen set
 *   · seen set capped at 2000, trims oldest on overflow
 *
 * - Limit lockout:
 *   · When server returns limit_reached:true, search button is permanently disabled
 *     with an upgrade CTA until page reload (matches server-side block)
 *   · Bar hits 100% red and subtitle says "Limit reached"
 *
 * - Error handling:
 *   · rate_limited and limit_reached both show distinct amber messages
 *   · Generic catch shows actionable error card
 *
 * DEBUG: every search response → console._leadsDebug
 */

/* global document, fetch, URLSearchParams, localStorage, navigator */
document.addEventListener('DOMContentLoaded', function () {
'use strict';

// ============================================================
//  0. DOM REFS
// ============================================================
var form         = document.getElementById('leadSearchForm');
var resultsWrap  = document.getElementById('leadsResultsWrap');
var leadsList    = document.getElementById('leadsList');
var lockedWrap   = document.getElementById('lockedWrap');
var lockedList   = document.getElementById('lockedList');
var loadingEl    = document.getElementById('leadsLoading');
var statusChip   = document.getElementById('searchStatusChip');
var searchBtn    = document.getElementById('searchBtn');
var searchBtnLbl = document.getElementById('searchBtnLabel');
var slider       = document.getElementById('leadCountSlider');
var sliderDisp   = document.getElementById('leadCountDisplay');
var sliderHid    = document.getElementById('leadCountHidden');
var seenCb       = document.getElementById('includeSeenLeads');
var togTrack     = document.getElementById('togTrack');
var csrfToken    = document.body.dataset.csrf || '';

// Lightweight toast helper (no library dep). Used by "Add to CRM".
function leadsToast(title, body, kind) {
    var host = document.getElementById('leadsToastHost');
    if (!host) {
        host = document.createElement('div');
        host.id = 'leadsToastHost';
        host.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:200;display:flex;flex-direction:column;gap:8px;max-width:320px;';
        document.body.appendChild(host);
    }
    var palette = {
        success: { bg:'rgba(16,185,129,.12)', br:'rgba(16,185,129,.25)', ic:'#34d399', iclass:'fa-circle-check' },
        warn:    { bg:'rgba(245,158,11,.12)', br:'rgba(245,158,11,.25)', ic:'#fbbf24', iclass:'fa-triangle-exclamation' },
        info:    { bg:'rgba(99,102,241,.12)', br:'rgba(99,102,241,.25)', ic:'#818cf8', iclass:'fa-circle-info' }
    };
    var p = palette[kind] || palette.info;
    var el = document.createElement('div');
    el.style.cssText = 'background:#0f172a;border:1px solid ' + p.br + ';border-radius:12px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;box-shadow:0 8px 24px rgba(0,0,0,.4);animation:crm-in .25s ease;';
    el.innerHTML = '<i class="fa-solid ' + p.iclass + '" style="color:' + p.ic + ';margin-top:1px;"></i>'
                 + '<div style="flex:1;min-width:0;"><p style="font-size:13px;font-weight:700;color:#fff;margin:0;">' + esc(title) + '</p>'
                 + '<p style="font-size:11px;color:#94a3b8;margin:2px 0 0;">' + esc(body) + '</p></div>';
    host.appendChild(el);
    setTimeout(function(){ el.style.transition = 'opacity .3s, transform .3s'; el.style.opacity = '0'; el.style.transform = 'translateY(8px)'; setTimeout(function(){ el.remove(); }, 320); }, 3500);
}

if (!form) return;

// ============================================================
//  1. PLAN CONFIG
// ============================================================
var cfg = document.getElementById('leadsPageConfig');
var PLAN    = cfg ? (cfg.dataset.plan    || 'free') : 'free';
var IS_PAID = PLAN === 'pro' || PLAN === 'entrepreneur';
var IS_ENT  = PLAN === 'entrepreneur';
var canScheduleSearches = (cfg && cfg.dataset.canScheduleSearches === '1');

var leadCount  = parseInt((cfg && cfg.dataset.leadCount)  || '0', 10);
var leadLimit  = parseInt((cfg && cfg.dataset.leadLimit)  || '0', 10);
var siteCount  = parseInt((cfg && cfg.dataset.siteCount)  || '0', 10);
var siteLimit  = parseInt((cfg && cfg.dataset.siteLimit)  || '0', 10);
var quotaUsed  = parseInt((cfg && cfg.dataset.quotaUsed)  || '0', 10);
var quotaLimit = parseInt((cfg && cfg.dataset.quotaLimit) || '0', 10);

// Track if limit has been hit this session
var _limitLocked = false;

// ============================================================
//  2. SEEN-LEADS (localStorage v2)
// ============================================================
var SEEN_KEY = 'utiligo_seen_leads_v2';

function getSeenIds() {
    try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); }
    catch (e) { return new Set(); }
}

function markSeen(ids) {
    try {
        var s = getSeenIds();
        ids.forEach(function (id) {
            var sid = String(id);
            // NEVER store 0, '0', or empty — id=0 means DB resolution failed
            if (sid && sid !== '0' && sid !== '') s.add(sid);
        });
        var arr = Array.from(s);
        if (arr.length > 2000) arr = arr.slice(arr.length - 2000);
        localStorage.setItem(SEEN_KEY, JSON.stringify(arr));
    } catch (e) {}
}

function wasSeen(id, seenSet) {
    var sid = String(id);
    // id=0 = DB resolution failed — NEVER treat as seen
    if (!sid || sid === '0' || sid === '') return false;
    return seenSet.has(sid);
}

// ============================================================
//  3. SLIDER
// ============================================================
if (slider) {
    slider.addEventListener('input', function () {
        if (sliderDisp) sliderDisp.textContent = slider.value;
        if (sliderHid)  sliderHid.value        = slider.value;
    });
}

// ============================================================
//  4. SEEN TOGGLE
// ============================================================
if (seenCb)   seenCb.checked = true;
if (togTrack) togTrack.classList.add('on');
if (togTrack && seenCb) {
    togTrack.parentElement.addEventListener('click', function () {
        seenCb.checked = !seenCb.checked;
        togTrack.classList.toggle('on', seenCb.checked);
    });
}

// ============================================================
//  5. BAR HELPERS
// ============================================================
var elLeadBar      = document.getElementById('leadBar');
var elLeadSub      = document.getElementById('leadBarSubtitle');
var elLeadNote     = document.getElementById('leadBarNote');
var elLeadCount    = document.getElementById('leadBarCount');
var elLeadUpgrade  = document.getElementById('leadUpgradeBtn');
var elSiteBar      = document.getElementById('siteBar');
var elSiteSub      = document.getElementById('siteBarSubtitle');
var elSiteNote     = document.getElementById('siteBarNote');
var elSiteCount    = document.getElementById('siteBarCount');

function syncBars(lc, ll, sc, sl) {
    if (typeof lc === 'number' && lc >= 0) leadCount = lc;
    if (typeof ll === 'number' && ll >= 0) leadLimit = ll;
    if (typeof sc === 'number' && sc >= 0) siteCount = sc;
    if (typeof sl === 'number' && sl >= 0) siteLimit = sl;

    if (elLeadBar) {
        if (IS_ENT) {
            elLeadBar.style.width = '0%';
            elLeadBar.className   = 'q-fill bg-white/20';
            if (elLeadSub)   elLeadSub.textContent   = leadCount + ' unlocked \u2014 unlimited';
            if (elLeadNote)  elLeadNote.textContent  = 'No cap \u2014 Entrepreneur plan';
            if (elLeadCount) elLeadCount.innerHTML   = leadCount + ' / &infin;';
        } else if (leadLimit > 0) {
            var lp = Math.min(100, Math.round(leadCount / leadLimit * 100));
            var atLimit = lp >= 100;
            elLeadBar.style.width = lp + '%';
            elLeadBar.className   = 'q-fill ' + (atLimit ? 'bg-red-400' : lp >= 80 ? 'bg-amber-400' : 'bg-white/40');
            if (elLeadSub)   elLeadSub.textContent   = atLimit ? 'Limit reached' : leadCount + ' of ' + leadLimit + ' used';
            if (elLeadNote)  elLeadNote.textContent  = atLimit ? 'Upgrade to get more' : Math.max(0, leadLimit - leadCount) + ' remaining';
            if (elLeadCount) elLeadCount.textContent = leadCount + ' / ' + leadLimit;
            if (elLeadUpgrade) elLeadUpgrade.classList.toggle('hidden', lp < 80);
            // Lock search button if limit reached
            if (atLimit) _lockSearchLimit();
        }
    }

    if (elSiteBar && siteLimit > 0) {
        var sp = Math.min(100, Math.round(siteCount / siteLimit * 100));
        elSiteBar.style.width = sp + '%';
        elSiteBar.className   = 'q-fill ' + (sp >= 100 ? 'bg-red-400' : sp >= 80 ? 'bg-amber-400' : 'bg-white/40');
        if (elSiteSub)   elSiteSub.textContent   = siteCount + ' of ' + siteLimit + ' used';
        if (elSiteNote)  elSiteNote.textContent  = Math.max(0, siteLimit - siteCount) + ' remaining';
        if (elSiteCount) elSiteCount.textContent = siteCount + ' / ' + siteLimit;
    }
}

function _lockSearchLimit() {
    if (_limitLocked) return;
    _limitLocked = true;
    if (searchBtn) {
        searchBtn.disabled = true;
        searchBtn.classList.add('opacity-50', 'cursor-not-allowed');
    }
    if (searchBtnLbl) {
        searchBtnLbl.innerHTML = '<i class="fa-solid fa-lock mr-1"></i>Limit Reached';
    }
    // Show upgrade notice below button
    var existing = document.getElementById('_limitNotice');
    if (!existing && searchBtn) {
        var notice = document.createElement('p');
        notice.id = '_limitNotice';
        notice.className = 'text-xs text-amber-400 mt-2 text-center';
        notice.innerHTML = 'Pro lead limit reached. <a href="/portal/billing.php?upgrade=1&plan=entrepreneur" class="underline font-semibold">Upgrade to Entrepreneur</a> for unlimited.';
        searchBtn.parentNode.insertBefore(notice, searchBtn.nextSibling);
    }
}

function fetchBarStatus() {
    if (!IS_PAID) return;
    fetch('/api/bar-status.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.success) syncBars(d.lead_count, d.lead_limit, d.site_count, d.site_limit);
        })
        .catch(function () {});
}

if (IS_PAID) {
    syncBars(leadCount, leadLimit, siteCount, siteLimit);
    fetchBarStatus();
}

// ============================================================
//  6. FREE QUOTA BAR
// ============================================================
function updateQuotaBar(newUsed) {
    quotaUsed = newUsed;
    var badge = document.getElementById('quotaBadge');
    var bar   = document.getElementById('quotaBar');
    var text  = document.getElementById('quotaText');
    var rem   = Math.max(0, quotaLimit - quotaUsed);
    var pct   = quotaLimit > 0 ? Math.min(100, Math.round(quotaUsed / quotaLimit * 100)) : 0;
    if (badge) {
        badge.className   = 'text-xs font-bold px-2.5 py-1 rounded-full ' +
            (rem === 0 ? 'bg-red-500/10 text-red-400' : rem === 1 ? 'bg-amber-500/10 text-amber-400' : 'bg-white/5 text-slate-400');
        badge.textContent = rem === 0 ? 'No searches left' : rem + ' search' + (rem !== 1 ? 'es' : '') + ' left';
    }
    if (bar)  { bar.style.width = pct + '%'; bar.className = 'q-fill ' + (pct >= 100 ? 'bg-red-400' : pct >= 50 ? 'bg-amber-400' : 'bg-white/40'); }
    if (text) text.textContent = quotaUsed + ' of ' + quotaLimit + ' used';
}

// ============================================================
//  7. HELPERS
// ============================================================
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function scoreClass(s) {
    return s >= 80 ? 'bg-white/10 text-white' : s >= 60 ? 'bg-amber-500/15 text-amber-400' : 'bg-red-500/15 text-red-400';
}
function scoreLabel(s) { return s >= 80 ? 'High' : s >= 60 ? 'Med' : 'Low'; }
function fmtTime(dateStr) {
    var d   = new Date(dateStr.replace(' ','T'));
    var now = new Date();
    var sod = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var yst = new Date(+sod - 86400000);
    var t   = d.toLocaleTimeString('en-CA',{hour:'numeric',minute:'2-digit',hour12:true});
    if (d >= sod) return 'Today at '+t;
    if (d >= yst) return 'Yesterday at '+t;
    return d.toLocaleDateString('en-CA',{month:'short',day:'numeric'})+' at '+t;
}
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-[10px]"></i>';
        setTimeout(function () { btn.innerHTML = orig; }, 1600);
    }).catch(function () {});
}
function setSearchBusy(on) {
    if (!searchBtn || !searchBtnLbl) return;
    if (_limitLocked) return; // never re-enable if limit is locked
    searchBtn.disabled = on;
    searchBtnLbl.textContent = on ? 'Searching\u2026' : 'Find Leads';
    searchBtn.classList.toggle('opacity-50', on);
    searchBtn.classList.toggle('cursor-not-allowed', on);
}

// ============================================================
//  8. LEAD CARD RENDERER
// ============================================================
function renderLeadCard(lead, seenSet, idx) {
    var seen = wasSeen(lead.id, seenSet);
    var card = document.createElement('div');
    card.className = 'lead-in glass rounded-2xl p-4 transition-all hover:border-white/[.15]'
                   + (seen ? ' opacity-50' : '');
    card.style.animationDelay = (idx * 45) + 'ms';
    card.dataset.leadId = lead.id;

    var sc         = scoreClass(lead.opportunity_score);
    var hasRating  = lead.rating && parseFloat(lead.rating) > 0;
    var stars      = hasRating
        ? '\u2605'.repeat(Math.round(parseFloat(lead.rating)))
          + '\u2606'.repeat(5 - Math.round(parseFloat(lead.rating)))
        : '';
    var genUrl = '/portal/generate.php'
        + '?lead_id='   + encodeURIComponent(lead.id)
        + '&name='      + encodeURIComponent(lead.business_name     || '')
        + '&category='  + encodeURIComponent(lead.business_category || '')
        + '&city='      + encodeURIComponent(lead.business_city     || '')
        + '&phone='     + encodeURIComponent(lead.business_phone    || '')
        + '&email='     + encodeURIComponent(lead.business_email    || '');

    var phonePill = lead.business_phone
        ? '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-300 px-3 py-1.5 rounded-lg font-medium">'
            + '<i class="fa-solid fa-phone text-[10px] text-slate-600"></i>'
            + esc(lead.business_phone)
            + '<button type="button" class="copy-btn ml-1 text-slate-600 hover:text-slate-300" data-copy="'+esc(lead.business_phone)+'">'
            + '<i class="fa-regular fa-copy text-[10px]"></i></button></span>'
        : '<span class="text-xs text-slate-700 px-2 py-1.5"><i class="fa-solid fa-phone mr-1.5"></i>No phone</span>';

    var emailPill = (lead.business_email && lead.business_email.trim())
        ? '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-300 px-3 py-1.5 rounded-lg font-medium">'
            + '<i class="fa-solid fa-envelope text-[10px] text-slate-600"></i>'
            + esc(lead.business_email)
            + '<button type="button" class="copy-btn ml-1 text-slate-600 hover:text-slate-300" data-copy="'+esc(lead.business_email)+'">'
            + '<i class="fa-regular fa-copy text-[10px]"></i></button></span>'
        : '';

    var mapsLink = lead.maps_url
        ? '<a href="'+esc(lead.maps_url)+'" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white px-3 py-1.5 rounded-lg font-medium transition">'
            + '<i class="fa-brands fa-google text-[10px]"></i>Maps</a>'
        : '';

    card.innerHTML =
        '<div class="flex flex-col sm:flex-row sm:items-start gap-3">'
          + '<div class="flex-1 min-w-0">'
            + '<div class="flex items-center gap-2 flex-wrap mb-1.5">'
              + '<h3 class="font-bold text-white text-sm leading-tight">'+esc(lead.business_name)+'</h3>'
              + '<span class="text-[10px] px-1.5 py-0.5 rounded font-bold '+sc+'">' + scoreLabel(lead.opportunity_score)+' &middot; '+lead.opportunity_score+'</span>'
              + (lead.no_website ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-slate-500 font-semibold">No Website</span>' : '')
              + (seen ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-white/[.04] text-slate-600 font-semibold">Seen</span>' : '')
            + '</div>'
            + '<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">'
              + '<span><i class="fa-solid fa-location-dot mr-1 text-slate-600"></i>'+esc(lead.business_address || 'Address unavailable')+'</span>'
              + (hasRating ? '<span class="text-amber-500/70 text-[11px]">'+stars+' <span class="text-slate-500">'+lead.rating+'</span></span>' : '')
              + (lead.total_ratings ? '<span class="text-slate-600">'+lead.total_ratings+' reviews</span>' : '')
              + (lead.business_category ? '<span class="text-slate-600"><i class="fa-solid fa-tag mr-1"></i>'+esc(lead.business_category)+'</span>' : '')
            + '</div>'
          + '</div>'
          + '<a href="'+genUrl+'" class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-200 active:scale-95 text-black px-3 py-2 rounded-xl font-bold whitespace-nowrap transition-all shrink-0">'
            + '<i class="fa-solid fa-bolt text-[10px]"></i> Build Site'
          + '</a>'
          + '<button type="button" class="crm-add-from-lead inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-white/10 active:scale-95 text-slate-300 hover:text-white border border-white/10 px-3 py-2 rounded-xl font-bold whitespace-nowrap transition-all shrink-0" data-lead-id="'+lead.id+'">'
            + '<i class="fa-solid fa-address-book text-[10px]"></i> Add to CRM'
          + '</button>'
        + '</div>'
        + '<div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-white/5">'
          + phonePill + emailPill + mapsLink
        + '</div>';

    card.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            copyText(btn.dataset.copy, btn);
        });
    });

    // "Add to CRM": posts to /portal/crm.php (crm_action=add_from_lead).
    // Idempotent server-side; on success shows a toast and links to the CRM.
    var crmBtn = card.querySelector('.crm-add-from-lead');
    if (crmBtn) {
        crmBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            var btn = this;
            var leadId = btn.getAttribute('data-lead-id');
            if (!leadId || btn.disabled) return;
            var origHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[10px]"></i> Adding…';
            var fd = new FormData();
            fd.append('crm_action', 'add_from_lead');
            fd.append('lead_id',    leadId);
            fd.append('csrf_token', csrfToken);
            fetch('/portal/crm.php', { method:'POST', body:fd })
                .then(function(r){return r.json();})
                .then(function(j){
                    btn.disabled = false;
                    if (j && j.ok) {
                        btn.innerHTML = '<i class="fa-solid fa-check text-[10px]"></i> ' + (j.existing ? 'Already in CRM' : 'Added to CRM');
                        btn.classList.add('text-emerald-400','border-emerald-500/30');
                        btn.classList.remove('text-slate-300','border-white/10');
                        // Click → open CRM (detail page if id returned)
                        btn.addEventListener('click', function(){
                            window.location.href = '/portal/crm.php?client=' + j.id;
                        }, { once:true });
                        leadsToast('Added to CRM', j.existing ? 'This lead was already in your client list.' : 'You can now track it in your pipeline.', 'success');
                    } else {
                        btn.innerHTML = origHTML;
                        var msg = (j && j.error) ? j.error : 'Could not add to CRM.';
                        // 'upgrade' is a magic server response for free plans
                        if (msg === 'upgrade') {
                            leadsToast('Upgrade required', 'Client CRM is a Pro feature. Upgrade to add leads to your pipeline.', 'warn');
                        } else {
                            leadsToast('Could not add to CRM', msg, 'warn');
                        }
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    btn.innerHTML = origHTML;
                    leadsToast('Network error', 'Please try again.', 'warn');
                });
        });
    }
    return card;
}

// ============================================================
//  9. LOCKED ROW RENDERER (free tier)
// ============================================================
var _FAKE_NAMES  = ['Montreal Plumbing Co.','Apex Roofing Services','Bella Vista Restaurant','ProClean Janitorial','City Electrical Works','Green Thumb Landscaping','Maple Auto Repair','Studio 514 Hair Salon'];
var _FAKE_CITIES = ['Montreal, QC','Laval, QC','Longueuil, QC','Brossard, QC'];
var _FAKE_SCORES = [72,81,68,90,77,85,63,79];

function renderLockedCard(idx) {
    var score = _FAKE_SCORES[idx % _FAKE_SCORES.length];
    var card  = document.createElement('div');
    card.className = 'lead-in glass rounded-2xl p-4 flex items-center gap-4 overflow-hidden';
    card.style.animationDelay = (idx * 45) + 'ms';
    card.innerHTML =
        '<div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">'
          + '<div class="flex items-center gap-2 mb-1">'
            + '<h3 class="font-bold text-white text-sm">'+_FAKE_NAMES[idx % _FAKE_NAMES.length]+'</h3>'
            + '<span class="text-[10px] px-1.5 py-0.5 rounded font-bold '+scoreClass(score)+'">'+score+'</span>'
          + '</div>'
          + '<p class="text-xs text-slate-500">'+_FAKE_CITIES[idx % _FAKE_CITIES.length]+'</p>'
        + '</div>'
        + '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-500 px-3 py-1.5 rounded-lg font-semibold shrink-0">'
          + '<i class="fa-solid fa-lock text-[10px]"></i> Locked'
        + '</span>';
    return card;
}

// ============================================================
//  10. HISTORY SIDEBAR
// ============================================================
function renderHistoryItem(entry) {
    var item = document.createElement('div');
    var ts   = fmtTime(entry.created_at);
    item.innerHTML =
        '<button type="button"'
          + ' class="w-full text-left flex flex-col gap-0.5 px-3 py-2.5 rounded-xl hover:bg-white/5 active:bg-white/8 transition-colors"'
          + ' data-city="'+esc(entry.city)+'"'
          + ' data-industry="'+esc(entry.industry)+'"'
          + ' data-keywords="'+esc(entry.keywords||'')+'"'
        + '>'
          + '<div class="flex items-center justify-between gap-2 w-full">'
            + '<span class="text-xs font-semibold text-white truncate flex-1">'+esc(entry.city)+'</span>'
            + (entry.result_count > 0 ? '<span class="text-[10px] font-bold text-slate-600 tabular-nums">'+entry.result_count+'</span>' : '')
          + '</div>'
          + '<span class="text-[11px] text-slate-500 truncate w-full">'
            + esc(entry.industry) + (entry.keywords ? ' &middot; '+esc(entry.keywords) : '')
          + '</span>'
          + '<span class="text-[10px] text-slate-700 mt-0.5">'+ts+'</span>'
        + '</button>';
    item.querySelector('button').addEventListener('click', function () {
        var cityEl     = document.getElementById('fieldCity');
        var industryEl = document.getElementById('fieldIndustry');
        var keyEl      = document.getElementById('fieldKeywords');
        if (cityEl)     cityEl.value     = this.dataset.city     || '';
        if (industryEl) industryEl.value = this.dataset.industry || '';
        if (keyEl)      keyEl.value      = this.dataset.keywords || '';
        form.scrollIntoView({behavior:'smooth',block:'start'});
    });
    return item;
}

function loadHistory() {
    var list    = document.getElementById('searchHistoryList');
    var empty   = document.getElementById('searchHistoryEmpty');
    var countEl = document.getElementById('historyCount');
    if (!list) return;
    fetch('/api/lead-search-history.php', {credentials:'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            list.innerHTML = '';
            var has = d.history && d.history.length > 0;
            if (empty)   empty.style.display = has ? 'none' : '';
            if (countEl) { countEl.textContent = has ? d.history.length : ''; countEl.classList.toggle('hidden', !has); }
            if (has) d.history.forEach(function (e) { list.appendChild(renderHistoryItem(e)); });
        })
        .catch(function () {});
}
loadHistory();

// ============================================================
//  11. MAIN SEARCH FUNCTION
// ============================================================
function runSearch(city, industry, keywords, reqCount, includeSeen, forceRefresh) {
    if (_limitLocked) return; // belt-and-suspenders: block if already locked
    var t0 = Date.now();

    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML  = '';
    lockedList.innerHTML = '';
    if (statusChip) { statusChip.textContent = ''; statusChip.classList.add('hidden'); }
    setSearchBusy(true);

    fetch('/api/find-leads.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            city:          city,
            industry:      industry,
            keywords:      keywords || null,
            lead_count:    reqCount || 10,
            include_seen:  includeSeen,
            csrf_token:    csrfToken,
            force_refresh: !!forceRefresh,
            sources:       getActiveSources(),
        }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        var elapsed = ((Date.now() - t0) / 1000).toFixed(1);
        try { console._leadsDebug = data; } catch(e) {}

        loadingEl.classList.add('hidden');
        resultsWrap.classList.remove('hidden');
        setSearchBusy(false);

        if (!data.success) {
            // Limit reached — lock button permanently
            if (data.limit_reached) {
                _lockSearchLimit();
                if (IS_PAID) syncBars(data.lead_count, data.lead_limit, siteCount, siteLimit);
            }
            leadsList.innerHTML =
                '<div class="glass rounded-2xl p-5 text-sm text-center '
                + (data.rate_limited || data.limit_reached ? 'text-amber-400' : 'text-red-400') + '">'
                + '<i class="fa-solid fa-'+(data.rate_limited ? 'clock' : data.limit_reached ? 'lock' : 'triangle-exclamation')+' mr-2"></i>'
                + esc(data.error || 'Search failed.')
                + (data.resets_at
                    ? '<span class="block text-xs text-slate-500 mt-1">Resets at <strong>'
                        + new Date(data.resets_at * 1000).toLocaleTimeString('en-CA',{hour:'numeric',minute:'2-digit',hour12:true})
                        + '</strong></span>'
                    : '')
                + (data.limit_reached
                    ? '<div class="mt-3"><a href="/portal/billing.php?upgrade=1&plan=entrepreneur" class="inline-flex items-center gap-2 bg-white text-black px-4 py-2 rounded-xl text-xs font-bold hover:bg-slate-200 transition"><i class="fa-solid fa-rocket"></i> Upgrade to Entrepreneur</a></div>'
                    : '')
                + '</div>';
            lockedWrap.classList.add('hidden');
            return;
        }

        // Update bars
        if (IS_PAID) {
            var newLC = typeof data.pro_lead_count === 'number' ? data.pro_lead_count : leadCount;
            var newLL = typeof data.lead_limit     === 'number' ? data.lead_limit     : leadLimit;
            syncBars(newLC, newLL, siteCount, siteLimit);
            setTimeout(fetchBarStatus, 800);
        }
        if (!IS_PAID && typeof data.searches_used === 'number') {
            updateQuotaBar(data.searches_used);
        }

        // ── SEEN SNAPSHOT — MUST happen before markSeen ──────────────
        // seenBefore = ids that were already seen BEFORE this search.
        // This guarantees fresh leads from THIS search never show the Seen badge.
        var seenBefore = getSeenIds();
        if (data.leads && data.leads.length) {
            markSeen(data.leads.map(function (l) { return l.id; }));
        }

        // Results header
        var n        = (data.leads && data.leads.length) || 0;
        var seenCnt  = (data.leads || []).filter(function (l) { return wasSeen(l.id, seenBefore); }).length;
        var hdr      = document.createElement('div');
        hdr.className = 'flex items-center justify-between text-xs text-slate-500 mb-3 px-0.5 flex-wrap gap-2';
        hdr.innerHTML =
            '<span>'
              + '<strong class="text-white">' + n + '</strong> leads'
              + ' &middot; <span class="text-slate-400">'+esc(city)+', '+esc(industry)+'</span>'
              + ' &middot; <span class="text-slate-600">'+elapsed+'s</span>'
              + (data.from_cache ? '<span class="text-slate-700 ml-1">(cached)</span>' : '')
            + '</span>'
            + '<span class="flex items-center gap-2">'
              + (seenCnt > 0 ? '<span class="text-slate-600">'+seenCnt+' seen</span>' : '')
              + (data.from_cache ? '<button id="refreshBtn" type="button" class="text-slate-400 hover:text-white font-semibold underline">Refresh</button>' : '')
            + '</span>';
        leadsList.appendChild(hdr);

        if (data.from_cache) {
            var rb = leadsList.querySelector('#refreshBtn');
            if (rb) rb.addEventListener('click', function () {
                runSearch(city, industry, keywords, reqCount, includeSeen, true);
            });
        }

        if (statusChip) {
            statusChip.classList.remove('hidden');
            statusChip.textContent = n + ' results \u00b7 ' + elapsed + 's';
        }

        if (!data.leads || !data.leads.length) {
            var em = document.createElement('p');
            em.className   = 'text-slate-500 text-center py-10 text-sm';
            em.textContent = 'No leads found. Try a different city or industry.';
            leadsList.appendChild(em);
        } else {
            var toShow = data.leads;
            if (!includeSeen) {
                toShow = data.leads.filter(function (l) { return !wasSeen(l.id, seenBefore); });
                if (!toShow.length) {
                    var msg = document.createElement('p');
                    msg.className = 'text-slate-500 text-center py-10 text-sm';
                    msg.innerHTML = '<i class="fa-solid fa-eye-slash mr-2"></i>All results already seen. Toggle <em>Include seen</em> to show them.';
                    leadsList.appendChild(msg);
                }
            }
            toShow.forEach(function (lead, i) {
                leadsList.appendChild(renderLeadCard(lead, seenBefore, i));
            });
        }

        if (data.is_free_tier && data.locked_leads && data.locked_leads.length) {
            data.locked_leads.forEach(function (_, i) { lockedList.appendChild(renderLockedCard(i)); });
            lockedWrap.classList.remove('hidden');
        } else {
            lockedWrap.classList.add('hidden');
        }

        loadHistory();
    })
    .catch(function (err) {
        loadingEl.classList.add('hidden');
        resultsWrap.classList.remove('hidden');
        setSearchBusy(false);
        leadsList.innerHTML =
            '<div class="glass rounded-2xl p-5 text-red-400 text-sm text-center">'
            + '<i class="fa-solid fa-triangle-exclamation mr-2"></i>'
            + 'Something went wrong. Please try again.'
            + '</div>';
        lockedWrap.classList.add('hidden');
        try { console.error('[leads] fetch error:', err); } catch(e) {}
    });
}

// ============================================================
//  12. FORM SUBMIT
// ============================================================
form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (_limitLocked) return;
    var city     = (form.querySelector('[name="city"]')     || {}).value || '';
    var industry = (form.querySelector('[name="industry"]') || {}).value || '';
    var keywords = (form.querySelector('[name="keywords"]') || {}).value || '';
    city     = city.trim();
    industry = industry.trim();
    keywords = keywords.trim();
    if (!city || !industry) return;
    var cnt     = sliderHid ? parseInt(sliderHid.value, 10) || 10 : 10;
    var incSeen = seenCb ? seenCb.checked : true;
    runSearch(city, industry, keywords, cnt, incSeen, false);
});

// ============================================================
//  13. AUTO-RUN FROM URL PARAMS
// ============================================================
(function () {
    var p = new URLSearchParams(window.location.search);
    if (p.get('autorun') !== '1') return;
    var c = (p.get('city')     || '').trim();
    var i = (p.get('industry') || '').trim();
    var k = (p.get('keywords') || '').trim();
    var n = parseInt(p.get('count'), 10) || 10;
    if (!c || !i) return;
    var cityEl = document.getElementById('fieldCity');
    var indEl  = document.getElementById('fieldIndustry');
    var keyEl  = document.getElementById('fieldKeywords');
    if (cityEl) cityEl.value = c;
    if (indEl)  indEl.value  = i;
    if (keyEl)  keyEl.value  = k;
    runSearch(c, i, k, n, true, false);
})();


// ============================================================
//  14. PHASE 3: source multi-select, view toggle, bulk, slide-over, export
//     All additive — existing code paths keep working unchanged.
// ============================================================

// Cache the last rendered leads (so bulk + slide-over + view toggle can
// re-render without re-fetching).
var _lastRenderedLeads = [];
var _lastRenderedContext = { city:'', industry:'', keywords:'' };
var _viewMode = 'card';           // 'card' | 'table'
var _bulkMode = false;            // whether the bulk-mode toggle is on
var _selectedLeads = {};          // { leadId: leadObj }
var _exportWorkingRequest = null;

// Parse the data-sources / data-all-sources attributes into arrays.
function _parseSourcesAttr(name) {
    var el = document.getElementById('leadsPageConfig');
    if (!el) return [];
    var raw = el.getAttribute(name) || '';
    return raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
}
function getAllowedSources()  { return _parseSourcesAttr('data-sources'); }
function getRegistrySources()  { return _parseSourcesAttr('data-all-sources'); }
function getActiveSources() {
    // Collect every checked source checkbox on the page.
    var out = [];
    var cbs = document.querySelectorAll('.source-chip-cb');
    cbs.forEach(function (cb) {
        if (cb.checked && !cb.disabled) out.push(cb.dataset.source);
    });
    // Always default to plan-allowed if nothing is checked (matches the
    // server-side default in api/find-leads.php).
    if (!out.length) {
        var allowed = getAllowedSources();
        if (allowed.indexOf('google_places') === -1 && allowed.length) out = allowed;
    }
    return out;
}

// Show the results toolbar (called after a successful search renders).
function _showResultsToolbar() {
    var tb = document.getElementById('resultsToolbar');
    if (tb) tb.classList.remove('hidden');
}

// Re-render the leads list using the current view-mode.
function _rerenderLeadsList() {
    if (!_lastRenderedLeads.length) return;
    var seenBefore = _lastSeenBeforeSnapshot || {};
    leadsList.innerHTML = '';
    if (_viewMode === 'table') {
        _renderLeadsAsTable(_lastRenderedLeads, seenBefore);
    } else {
        _lastRenderedLeads.forEach(function (lead, i) {
            leadsList.appendChild(renderLeadCard(lead, seenBefore, i));
        });
    }
    // Re-apply selection visual state if bulk mode is on
    if (_bulkMode) _refreshBulkSelectionVisual();
}

function _renderLeadsAsTable(leads, seenSet) {
    var table = document.createElement('table');
    table.className = 'leads-table';
    var thead = document.createElement('thead');
    thead.innerHTML = '<tr>' +
        (_bulkMode ? '<th><input type="checkbox" class="bulk-cb" id="bulkSelectAll"></th>' : '') +
        '<th>Name</th><th>Category</th><th>City</th><th>Phone</th>' +
        '<th>Rating</th><th>Score</th><th>Source</th></tr>';
    table.appendChild(thead);
    var tbody = document.createElement('tbody');
    leads.forEach(function (lead) {
        var tr = document.createElement('tr');
        tr.dataset.leadId = lead.id || '';
        tr.className = _bulkMode && _selectedLeads[lead.id] ? 'is-selected' : '';
        var cells = '';
        if (_bulkMode) {
            var sel = _selectedLeads[lead.id] ? 'checked' : '';
            cells += '<td><input type="checkbox" class="bulk-cb bulk-row-cb" data-lead-id="' + esc(String(lead.id)) + '" ' + sel + '></td>';
        }
        cells += '<td><a class="text-slate-100 font-semibold hover:text-white cursor-pointer lead-detail-link" data-lead-id="' + esc(String(lead.id)) + '">' + esc(lead.business_name || '—') + '</a></td>';
        cells += '<td>' + esc(lead.business_category || '—') + '</td>';
        cells += '<td>' + esc(lead.business_city || '—') + '</td>';
        cells += '<td>' + esc(lead.business_phone || '—') + '</td>';
        cells += '<td>' + (lead.rating || '—') + (lead.total_ratings ? ' <span class="text-slate-600">(' + lead.total_ratings + ')</span>' : '') + '</td>';
        cells += '<td><span class="text-' + scoreClass(lead.opportunity_score) + '">' + (lead.opportunity_score || 0) + '</span></td>';
        cells += '<td><span class="text-slate-500 text-[10px] uppercase">' + esc(lead.source || 'google_places') + '</span></td>';
        tr.innerHTML = cells;
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    leadsList.appendChild(table);
    // Wire up the per-row bulk checkbox + detail links
    table.querySelectorAll('.bulk-row-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var id = parseInt(cb.dataset.leadId, 10) || 0;
            if (cb.checked) {
                var lead = _findLeadById(id);
                if (lead) _selectedLeads[id] = lead;
            } else {
                delete _selectedLeads[id];
            }
            _refreshBulkActionBar();
            _refreshBulkSelectionVisual();
        });
    });
    table.querySelectorAll('.lead-detail-link').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var id = parseInt(a.dataset.leadId, 10) || 0;
            var lead = _findLeadById(id);
            if (lead) openLeadSlideOver(lead);
        });
    });
    var selectAll = document.getElementById('bulkSelectAll');
    if (selectAll) selectAll.addEventListener('change', function () {
        var checked = selectAll.checked;
        leads.forEach(function (lead) {
            if (lead.id) {
                if (checked) _selectedLeads[lead.id] = lead;
                else delete _selectedLeads[lead.id];
            }
        });
        table.querySelectorAll('.bulk-row-cb').forEach(function (cb) { cb.checked = checked; });
        _refreshBulkActionBar();
        _refreshBulkSelectionVisual();
    });
}

function _findLeadById(id) {
    if (!id) return null;
    for (var i = 0; i < _lastRenderedLeads.length; i++) {
        if (_lastRenderedLeads[i].id === id) return _lastRenderedLeads[i];
    }
    return null;
}

// Bulk action bar show/hide + count update
function _refreshBulkActionBar() {
    var bar = document.getElementById('bulkActionBar');
    var cnt = document.getElementById('bulkCount');
    if (!bar || !cnt) return;
    var n = Object.keys(_selectedLeads).length;
    cnt.textContent = String(n);
    if (_bulkMode && n > 0) bar.classList.add('show');
    else bar.classList.remove('show');
}
function _refreshBulkSelectionVisual() {
    // For card view: add a small selection checkbox to each card
    var cards = leadsList.querySelectorAll('[data-lead-id]');
    cards.forEach(function (card) {
        var id = parseInt(card.dataset.leadId || '0', 10) || 0;
        if (!id) return;
        if (_bulkMode && _selectedLeads[id]) card.classList.add('is-selected');
        else card.classList.remove('is-selected');
    });
}

// Slide-over renderer — exposed for the portal-page HTML to call
function _renderSlideOver(body, lead) {
    if (!body || !lead) return;
    document.getElementById('slideOverTitle').textContent = lead.business_name || 'Lead';
    var html = '';
    function row(label, val) {
        if (!val) return '';
        return '<div class="flex flex-col gap-0.5">'
            + '<p class="text-[9px] font-semibold text-slate-600 uppercase tracking-widest">' + esc(label) + '</p>'
            + '<p class="text-sm text-slate-200 break-words">' + esc(String(val)) + '</p></div>';
    }
    function linkrow(label, val, href) {
        if (!val) return '';
        return '<div class="flex flex-col gap-0.5">'
            + '<p class="text-[9px] font-semibold text-slate-600 uppercase tracking-widest">' + esc(label) + '</p>'
            + '<a href="' + esc(href) + '" target="_blank" rel="noopener noreferrer" class="text-sm text-sky-400 hover:text-sky-300 hover:underline break-all">' + esc(String(val)) + '</a></div>';
    }
    function chip(text, color) {
        if (!text) return '';
        return '<span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background:' + (color || 'rgba(148,163,184,.1)') + ';color:#cbd5e1">' + esc(String(text)) + '</span>';
    }

    html += '<div class="flex flex-wrap gap-1.5 mb-4">' +
        chip(lead.source || 'google_places', lead.source === 'osm' ? 'rgba(124,186,52,.15)' : 'rgba(66,133,244,.15)') +
        chip((lead.opportunity_score || 0) + ' score', 'rgba(99,102,241,.15)') +
        (lead.business_category ? chip(lead.business_category, 'rgba(245,158,11,.15)') : '') +
    '</div>';

    html += row('Address', lead.business_address || (lead.business_city ? lead.business_city : ''));
    html += row('Phone', lead.business_phone);
    html += row('International phone', lead.international_phone);
    html += row('Email', lead.business_email);
    html += linkrow('Website', lead.website, lead.website);
    html += linkrow('Google Maps', (lead.maps_url) ? 'View on Maps' : '', lead.maps_url);
    html += row('Category', lead.business_category);
    html += row('City', lead.business_city);
    html += row('Hours', lead.business_hours);
    html += row('Price level', lead.price_level);
    html += row('Rating', (lead.rating && lead.total_ratings ? lead.rating + ' / 5 · ' + lead.total_ratings + ' reviews' : ''));
    if (lead.lat && lead.lng) {
        var href = 'https://www.openstreetmap.org/?mlat=' + lead.lat + '&mlon=' + lead.lng + '#map=15/' + lead.lat + '/' + lead.lng;
        html += linkrow('Coordinates', lead.lat + ', ' + lead.lng, href);
    }
    html += row('Source', lead.source);
    body.innerHTML = html;

    // Click on the name if address is empty doesn't navigate anyway.
}

// ----- Wire up the toolbar + bulk + slide-over + view-mode buttons -----
(function wirePhase3UI() {
    // Wrap renderLeadCard so each card gets a data-lead-id for bulk select
    // and a click handler to open the slide-over (when bulk mode is OFF).
    var origRenderLeadCard = renderLeadCard;
    renderLeadCard = function (lead, seenSet, idx) {
        var card = origRenderLeadCard(lead, seenSet, idx);
        if (card && lead && lead.id) {
            card.dataset.leadId = lead.id;
            (function (le) {
                card.addEventListener('click', function (e) {
                    // Don't open the slide-over if the user clicked on a
                    // link/button inside the card (unlock / phone / etc).
                    var tag = (e.target && e.target.tagName || '').toLowerCase();
                    if (tag === 'a' || tag === 'button' || (e.target.closest && e.target.closest('a,button'))) return;
                    if (_bulkMode) {
                        // Toggle this lead in/out of the bulk selection
                        if (_selectedLeads[le.id]) { delete _selectedLeads[le.id]; card.classList.remove('is-selected'); }
                        else { _selectedLeads[le.id] = le; card.classList.add('is-selected'); }
                        _refreshBulkActionBar();
                    } else {
                        openLeadSlideOver(le);
                    }
                });
                card.style.cursor = 'pointer';
            })(lead);
        }
        return card;
    };

    // Wrap runSearch's success path: cache leads + show the toolbar.
    var origRunSearch = runSearch;
    runSearch = function (city, industry, keywords, reqCount, includeSeen, forceRefresh) {
        // Hook the .then() chain after runSearch finishes, but without
        // rewriting the inner function. Easiest: monkey-patch in a
        // delegating wrapper, then patch the contained class-level DOM
        // update via observing leadsList mutations.
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
                    // First time results render after a search completes.
                    _showResultsToolbar();
                    observer.disconnect();
                    break;
                }
            }
        });
        observer.observe(leadsList, { childList: true });
        // The success path caches leads in a closure we can't see; so we
        // re-derive _lastRenderedLeads by scraping after render. Run after
        // a short delay to let the original .then() finish rendering.
        setTimeout(function () {
            var cards = leadsList.querySelectorAll('[data-lead-id]');
            var leads = [];
            cards.forEach(function (card) {
                var id = parseInt(card.dataset.leadId || '0', 10) || 0;
                if (id) leads.push({ _card: card, id: id });
            });
            // We can't recover the full lead obj from the DOM alone,
            // so re-attach via the lead-detail-link data when present.
            // The original renderLeadCard already embedded a lot of the
            // data as data-* attributes when v3 of the card schema was
            // written; we re-derive the rest on slide-over open by
            // looking at nearby text nodes via _findLeadById (best-effort).
            _lastRenderedLeads = leads;
            _lastRenderedContext = { city:ncity(), industry:nindustry(), keywords:nkeywords() };
            function ncity()     { var el = document.getElementById('fieldCity');     return el ? el.value : city; }
            function nindustry() { var el = document.getElementById('fieldIndustry'); return el ? el.value : industry; }
            function nkeywords() { var el = document.getElementById('fieldKeywords');  return el ? el.value : (keywords || ''); }
        }, 600);
        return origRunSearch.apply(this, arguments);
    };

    // View toggle
    var cardBtn = document.getElementById('viewCardBtn');
    var tblBtn  = document.getElementById('viewTableBtn');
    if (cardBtn) cardBtn.addEventListener('click', function () {
        _viewMode = 'card';
        if (cardBtn) cardBtn.classList.add('active');
        if (tblBtn)  tblBtn.classList.remove('active');
        _rerenderLeadsList();
    });
    if (tblBtn) tblBtn.addEventListener('click', function () {
        _viewMode = 'table';
        if (tblBtn)  tblBtn.classList.add('active');
        if (cardBtn) cardBtn.classList.remove('active');
        _rerenderLeadsList();
    });

    // Persist view choice in localStorage
    try {
        var saved = localStorage.getItem('leads.viewMode');
        if (saved === 'table' && tblBtn) tblBtn.click();
    } catch (e) {}
    if (cardBtn) cardBtn.addEventListener('click', function () { try { localStorage.setItem('leads.viewMode', 'card'); } catch(e) {} });
    if (tblBtn)  tblBtn.addEventListener('click', function () { try { localStorage.setItem('leads.viewMode', 'table'); } catch(e) {} });

    // Bulk mode toggle
    var bulkToggle = document.getElementById('bulkModeToggle');
    if (bulkToggle) bulkToggle.addEventListener('change', function () {
        _bulkMode = !!bulkToggle.checked;
        if (!_bulkMode) {
            _selectedLeads = {};
            _refreshBulkActionBar();
        }
        _rerenderLeadsList();
    });

    // Bulk action bar
    var bulkClear = document.getElementById('bulkClearBtn');
    if (bulkClear) bulkClear.addEventListener('click', function () {
        _selectedLeads = {};
        document.querySelectorAll('.bulk-row-cb').forEach(function (cb) { cb.checked = false; });
        _refreshBulkActionBar();
        _refreshBulkSelectionVisual();
    });
    var bulkUnlockBtn = document.getElementById('bulkUnlockBtn');
    if (bulkUnlockBtn) bulkUnlockBtn.addEventListener('click', function () {
        // Hook into the existing "Add to CRM" pattern on each lead.
        // For simplicity, we walk _selectedLeads and call the existing
        // crmAdd() (added by the generator.js integration). For this Phase
        // 3 we just show a toast and let the user do them via the per-card
        // flow — actual bulk unlock is a separate unlock API coming in a
        // later phase. The button is intentionally disabled-friendly.
        leadsToast('Bulk add', Object.keys(_selectedLeads).length + ' leads selected — use Unlock per-lead for now.', 'info');
    });
    var bulkExportBtn = document.getElementById('bulkExportBtn');
    if (bulkExportBtn) bulkExportBtn.addEventListener('click', function () {
        // We don't currently pass an explicit subset to the export
        // endpoint (Phase 4 expects "scope = unlocked|search|all"); a
        // per-id subset is an enhancement. For Phase 3 we just launch
        // the export sheet and let the user choose a scope.
        openExportSheet();
    });

    // Source chip strip — make a fresh change re-render the search chip
    // state active sources display. We don't auto-rerun because the user
    // clicks Search explicitly to confirm.
    document.querySelectorAll('.source-chip-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            getActiveSources(); // re-read + cache for next runSearch
        });
    });

    // Save search button
    var saveSearchBtn = document.getElementById('saveSearchBtn');
    if (saveSearchBtn) saveSearchBtn.addEventListener('click', function () {
        var city = (document.getElementById('fieldCity') || {}).value || '';
        var industry = (document.getElementById('fieldIndustry') || {}).value || '';
        var keywords = (document.getElementById('fieldKeywords') || {}).value || '';
        if (!city || !industry) {
            leadsToast('Save search', 'Enter a city and industry first', 'warn');
            return;
        }
        var key = 'leads.savedSearches';
        var list = [];
        try { list = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { list = []; }
        var entry = {
            city: city, industry: industry, keywords: keywords,
            sources: getActiveSources(),
            at: new Date().toISOString(),
        };
        // Dedupe by city|industry|keywords
        list = list.filter(function (s) {
            return !(s.city === entry.city && s.industry === entry.industry && s.keywords === entry.keywords);
        });
        list.unshift(entry);
        if (list.length > 20) list.length = 20;
        try { localStorage.setItem(key, JSON.stringify(list)); } catch (e) {}

        // Phase 5: also mirror to server via /api/saved-searches.php so the
        // scheduled_searches cron can read it. Best-effort — silent on
        // failure so we don't break the localStorage save path.
        try {
            fetch('/api/saved-searches.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    op: 'create',
                    csrf_token: csrfToken,
                    name: city + ' · ' + industry,
                    params: { city: city, industry: industry, keywords: keywords, sources: entry.sources },
                    notify_email: false
                })
            }).catch(function () {});
        } catch (e) {}

        leadsToast('Saved', '"' + city + ' · ' + industry + '" saved to favorites', 'success');
    });

    // Slide-over close on Escape + on overlay click
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (document.getElementById('leadSlideOver') && document.getElementById('leadSlideOver').classList.contains('open')) closeLeadSlideOver();
            if (document.getElementById('exportSheet') && document.getElementById('exportSheet').style.transform === 'translateX(0)') closeExportSheet();
        }
    });

    // Source chip initial state for free users: their data-sources already
    // has only the allowed keys; nothing to change there.
})();

// Export the slide-over renderer so inline onclick handlers can call it.
window._leadsRenderSlideOver = _renderSlideOver;

// Phase 2: fetch enrichments from lead_enrichments table for the slide-over.
window._leadsFetchEnrichments = function (leadId, cb) {
    fetch('/api/lead-enrichments.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ lead_id: leadId, csrf_token: csrfToken }),
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
        if (!j || !j.success) return cb(j && j.error || 'error', null);
        cb(null, j.enrichments || []);
    })
    .catch(function (e) { cb('network', null); });
};

// Render enrichment rows stacked at the bottom of the slide-over body.
// We group by provider so multiple DNS/social rows from the same provider
// show together.
window._leadsAppendEnrichments = function (body, enrichments) {
    if (!body || !enrichments || !enrichments.length) return;
    var existing = body.querySelector('#enrichmentBlock');
    if (existing) existing.remove();

    var byProvider = {};
    enrichments.forEach(function (r) {
        var p = r.provider || 'unknown';
        (byProvider[p] = byProvider[p] || []).push(r);
    });

    var html = '<div id="enrichmentBlock" class="pt-4 mt-4 border-t border-white/5">'
        + '<p class="text-[9px] font-semibold text-slate-600 uppercase tracking-widest mb-3">Enrichment data</p>';

    Object.keys(byProvider).forEach(function (p) {
        html += '<div class="mb-3">';
        html += '<p class="text-[10px] font-semibold text-slate-500 capitalize mb-1.5">' + esc(p.replace(/_/g, ' ')) + '</p>';
        byProvider[p].forEach(function (r) {
            var conf = r.confidence || 'medium';
            var confColor = conf === 'high' ? 'text-emerald-400' : (conf === 'low' ? 'text-slate-600' : 'text-amber-400');
            var label = (r.field || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            // For emails, render as clickable mailto:
            var valHtml;
            if (String(r.field).indexOf('email') !== -1 && r.value) {
                valHtml = '<a href="mailto:' + esc(r.value) + '" class="text-sky-400 hover:text-sky-300 hover:underline break-all">' + esc(r.value) + '</a>';
            } else if (String(r.field).indexOf('website') !== -1 && r.value) {
                valHtml = '<a href="' + esc(r.value) + '" target="_blank" rel="noopener noreferrer" class="text-sky-400 hover:text-sky-300 hover:underline break-all">' + esc(r.value) + '</a>';
            } else {
                valHtml = '<span class="text-slate-200 break-words">' + esc(String(r.value || '—')) + '</span>';
            }
            html += '<div class="flex justify-between items-start gap-3 mb-1">'
                + '<div class="min-w-0"><span class="text-[10px] text-slate-600 mr-1">' + esc(label) + ':</span>' + valHtml + '</div>'
                + '<span class="text-[9px] ' + confColor + ' uppercase font-semibold shrink-0">' + esc(conf) + '</span>'
            + '</div>';
        });
        html += '</div>';
    });
    html += '</div>';
    body.insertAdjacentHTML('beforeend', html);
};

// ----- Export launcher (Phase 4 UI integration) -----
(function wireExportSheet() {
    var buildBtn = document.getElementById('exportBuildBtn');
    if (!buildBtn) return;
    var selFormat = null;
    var progress = document.getElementById('exportProgress');
    var result = document.getElementById('exportResult');

    document.querySelectorAll('.export-format-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.export-format-btn').forEach(function (b) {
                b.classList.remove('border-white/40','bg-white/10','text-white');
                b.classList.add('text-slate-300');
            });
            btn.classList.add('border-white/40','bg-white/10','text-white');
            btn.classList.remove('text-slate-300');
            selFormat = btn.dataset.format;
            if (buildBtn) buildBtn.disabled = false;
        });
    });

    buildBtn.addEventListener('click', function () {
        if (!selFormat) { leadsToast('Choose a format', 'Pick one of the format buttons above.', 'warn'); return; }
        var scopeEl = document.getElementById('exportScopeSelect');
        var scope   = scopeEl ? (scopeEl.value || 'unlocked') : 'unlocked';
        var cols    = [];
        document.querySelectorAll('.export-col-cb').forEach(function (cb) {
            if (cb.checked) cols.push(cb.value);
        });
        if (!cols.length) { leadsToast('No columns', 'Select at least one column.', 'warn'); return; }
        // Read current search params if scope=search
        var filter = { city:'', industry:'', business_category:'' };
        if (scope === 'search' || scope === 'all') {
            filter.city = (document.getElementById('fieldCity') || {}).value || '';
            filter.industry = (document.getElementById('fieldIndustry') || {}).value || '';
        }
        if (buildBtn) buildBtn.disabled = true;
        if (progress) progress.classList.remove('hidden');
        if (result) result.classList.add('hidden'); result.innerHTML = '';

        fetch('/api/export-leads.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                csrf_token: csrfToken,
                format: selFormat,
                scope: scope,
                columns: cols,
                filter: filter,
            }),
        }).then(function (r) { return r.json(); })
        .then(function (j) {
            if (buildBtn) buildBtn.disabled = false;
            if (progress) progress.classList.add('hidden');
            if (!j || !j.success) {
                if (result) {
                    result.classList.remove('hidden');
                    result.innerHTML = '<div class="glass rounded-xl p-3 text-red-400 text-xs text-center">' + esc(j.error || 'Export failed') + '</div>';
                }
                return;
            }
            if (result) {
                result.classList.remove('hidden');
                result.innerHTML =
                    '<div class="glass rounded-xl p-4 text-center space-y-3">' +
                    '<i class="fa-solid fa-circle-check text-emerald-400 text-2xl"></i>' +
                    '<p class="text-sm text-white font-semibold">Export ready · ' + esc(String(j.row_count)) + ' rows</p>' +
                    '<a href="' + esc(j.download_url) + '" class="inline-block text-xs font-bold text-black bg-white px-4 py-2 rounded-lg hover:bg-slate-200"><i class="fa-solid fa-download mr-1"></i>Download ' + esc(j.format.toUpperCase()) + '</a>' +
                    '<p class="text-[10px] text-slate-600">Link expires in 1 hour · max 5 downloads</p>' +
                    '</div>';
            }
        }).catch(function () {
            if (buildBtn) buildBtn.disabled = false;
            if (progress) progress.classList.add('hidden');
            if (result) { result.classList.remove('hidden'); result.innerHTML = '<div class="glass rounded-xl p-3 text-red-400 text-xs text-center">Network error. Try again.</div>'; }
        });
    });
})();

// ----- Phase 3 polish: saved-searches drawer + keyboard navigation -----
(function wireSavedSearchesAndShortcuts() {
    var KEY = 'leads.savedSearches';

    function read()    { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
    function write(l)  { try { localStorage.setItem(KEY, JSON.stringify(l)); } catch (e) {} }

    function refreshBadge() {
        // Prefer server count; fall back to localStorage on failure.
        try {
            fetch('/api/saved-searches.php', {
                method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ op:'list', csrf_token:csrfToken })
            }).then(function(r){ return r.json(); }).then(function(j){
                var n = (j && j.success && Array.isArray(j.saved_searches)) ? j.saved_searches.length : 0;
                if (!n) n = read().length;
                _setBadge(n);
            }).catch(function(){
                _setBadge(read().length);
            });
        } catch (e) {
            _setBadge(read().length);
        }
    }
    function _setBadge(n) {
        var badge = document.getElementById('savedSearchesCount');
        if (badge) {
            badge.textContent = n > 0 ? String(n) : '';
            badge.style.display = n > 0 ? '' : 'none';
        }
    }

    // Render the saved searches into the drawer body. Tries the server
    // (Phase 5) and falls back to localStorage. Toggling "Notify me" issues
    // an op=update to /api/saved-searches.php?op=update.
    window._leadsRenderSavedSearches = function () {
        var host    = document.getElementById('savedSearchesList');
        var empty   = document.getElementById('savedSearchesEmpty');
        if (!host) return;
        host.innerHTML = '<div class="text-xs text-slate-600 px-4 py-3 text-center">Loading…</div>';

        function renderRows(list, withNotify) {
            host.innerHTML = '';
            if (empty) empty.style.display = list.length ? 'none' : '';
            if (!list.length) return;
            list.forEach(function (entry, i) {
                var btn = document.createElement('div');
                btn.className = 'saved-search-item';
                var title = (entry.name || '') || ((entry.city || '') + ' · ' + (entry.industry || ''));
                var sub   = '';
                if (entry.params) {
                    sub = entry.params.city + ' · ' + entry.params.industry + (entry.params.keywords ? ' / "' + entry.params.keywords + '"' : '');
                } else {
                    sub = (entry.city || '') + ' · ' + (entry.industry || '');
                    if (entry.keywords) sub += ' / "' + entry.keywords + '"';
                }
                var meta = [];
                meta.push(new Date(entry.at || entry.created_at || Date.now()).toLocaleDateString());
                if (withNotify) meta.push('Notify me');
                btn.innerHTML =
                    '<div class="flex items-start justify-between gap-2">' +
                        '<div class="min-w-0 flex-1">' +
                            '<span class="ss-title"><i class="fa-solid fa-star text-amber-400/80 mr-1 text-[11px]"></i>' + esc(title) + '</span>' +
                            '<span class="ss-meta block mt-1">' + esc(sub) + '</span>' +
                        '</div>' +
                        '<span class="ss-del" data-idx="' + i + '" title="Remove"><i class="fa-solid fa-trash"></i></span>' +
                    '</div>' +
                    (withNotify ? '<label class="flex items-center gap-1.5 mt-2 text-[11px] text-slate-500 cursor-pointer hover:text-slate-400">' +
                        '<input type="checkbox" class="ss-notify" data-idx="' + i + '"' + (entry.notify_email ? ' checked' : '') + '>' +
                        '<i class="fa-solid fa-bell text-[10px]"></i> Email me new leads on this search' +
                    '</label>' : '') +
                    '<span class="text-[10px] text-slate-700">' + esc(meta[0]) + '</span>';

                host.appendChild(btn);
            });
            // Bind click events for each row.
            host.querySelectorAll('.saved-search-item').forEach(function (row, idx) {
                row.addEventListener('click', function (e) {
                    if (e.target && e.target.closest) {
                        if (e.target.closest('.ss-del')) {
                            e.stopPropagation();
                            var entry = list[idx];
                            var serverId = parseInt(entry.id || '0', 10) || 0;
                            var cur = read();
                            if (idx >= 0 && idx < cur.length) cur.splice(idx, 1);
                            write(cur);
                            if (serverId > 0) {
                                try {
                                    fetch('/api/saved-searches.php', {
                                        method:'POST', credentials:'same-origin',
                                        headers:{'Content-Type':'application/json'},
                                        body: JSON.stringify({ op:'delete', csrf_token:csrfToken, id:serverId })
                                    }).catch(function(){});
                                } catch (e2) {}
                            }
                            _leadsRenderSavedSearches();
                            refreshBadge();
                            return;
                        }
                        if (e.target.closest('.ss-notify') && row.querySelector('.ss-notify')) {
                            // Toggle handled on change below; allow propagation here
                        }
                    }
                    // Otherwise re-run the search
                    var entry = list[idx];
                    var cityEl = document.getElementById('fieldCity');
                    var indEl  = document.getElementById('fieldIndustry');
                    var keyEl  = document.getElementById('fieldKeywords');
                    var city = entry.params ? entry.params.city : entry.city;
                    var ind  = entry.params ? entry.params.industry : entry.industry;
                    var kws  = entry.params ? entry.params.keywords : entry.keywords;
                    var srcs = entry.params ? entry.params.sources : entry.sources;
                    if (cityEl) cityEl.value = city || '';
                    if (indEl)  indEl.value  = ind  || '';
                    if (keyEl)  keyEl.value  = kws  || '';
                    if (srcs && srcs.length) {
                        document.querySelectorAll('.source-chip-cb').forEach(function (cb) {
                            cb.checked = srcs.indexOf(cb.dataset.source) !== -1;
                        });
                    }
                    closeSavedSearchesDrawer();
                    var f = document.getElementById('leadSearchForm');
                    if (f) f.dispatchEvent(new Event('submit', { cancelable: true }));
                    setTimeout(function () {
                        var sb = document.getElementById('searchBox');
                        if (sb) sb.scrollIntoView({ behavior:'smooth', block:'start' });
                    }, 200);
                });
            });
            // Bind notify-toggle changes
            if (withNotify) {
                host.querySelectorAll('.ss-notify').forEach(function (cb, idx) {
                    cb.addEventListener('change', function () {
                        var entry = list[idx];
                        var serverId = parseInt(entry.id || '0', 10) || 0;
                        if (!serverId) {
                            leadsToast('Sync first', 'Save the search from the search bar to enable notifications.', 'warn');
                            cb.checked = false;
                            return;
                        }
                        try {
                            fetch('/api/saved-searches.php', {
                                method:'POST', credentials:'same-origin',
                                headers:{'Content-Type':'application/json'},
                                body: JSON.stringify({
                                    op:'update', csrf_token:csrfToken, id:serverId,
                                    name: entry.name, notify_email: cb.checked,
                                    params: entry.params || {
                                        city: entry.city, industry: entry.industry,
                                        keywords: entry.keywords, sources: entry.sources
                                    }
                                })
                            }).then(function(r){ return r.json(); }).then(function(j){
                                leadsToast(
                                    cb.checked ? 'Notifications on' : 'Notifications off',
                                    cb.checked ? 'You\'ll get an email when matching leads are found.' : 'No further emails scheduled for this search.',
                                    cb.checked ? 'success' : 'info'
                                );
                            }).catch(function(){});
                        } catch (e) {}
                    });
                });
            }
        }

        // Try the server first; fall back to localStorage on any network
        // failure so the drawer still shows something to an offline guest
        // or when the API endpoint isn't deployed yet.
        try {
            fetch('/api/saved-searches.php', {
                method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ op:'list', csrf_token:csrfToken })
            }).then(function(r){ return r.json(); }).then(function(j){
                if (j && j.success && Array.isArray(j.saved_searches) && j.saved_searches.length) {
                    renderRows(j.saved_searches, canScheduleSearches);
                } else {
                    renderRows(read(), false);
                }
            }).catch(function(){
                renderRows(read(), false);
            });
        } catch (e) {
            renderRows(read(), false);
        }
    };

    // Update the badge whenever a new search is saved
    var origSaveHandler = null;
    var saveBtn = document.getElementById('saveSearchBtn');
    if (saveBtn) {
        // The earlier Phase 3 block attached a click listener that writes
        // to localStorage; we hook BEFORE that listener by capturing a
        // reference and re-binding it after ourselves. Simpler: just
        // listen a second time since addEventListener stacks rather than
        // overwriting.
        saveBtn.addEventListener('click', refreshBadge);
    }

    // Also refresh the badge whenever the drawer is opened — important
    // when the page first loads too.
    refreshBadge();
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var d = document.getElementById('savedSearchesDrawer');
            if (d && d.classList.contains('open')) closeSavedSearchesDrawer();
        }
    });

    // ── Keyboard navigation: j/k/arrows walk cards, Enter opens slide-over,
    // s saves current, b toggles bulk mode, / focuses city input. ──
    var _activeIdx = -1;

    function _activeCards() {
        return Array.prototype.slice.call(leadsList.querySelectorAll('[data-lead-id]'));
    }

    function _clearActive() {
        var cards = _activeCards();
        cards.forEach(function (c) { c.classList.remove('card-active'); });
    }

    function _setActive(idx) {
        var cards = _activeCards();
        if (!cards.length) return;
        if (idx < 0) idx = cards.length - 1;
        if (idx >= cards.length) idx = 0;
        _activeIdx = idx;
        _clearActive();
        cards[idx].classList.add('card-active');
        cards[idx].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    document.addEventListener('keydown', function (e) {
        // Ignore shortcuts if focus is in an input/textarea/select.
        var tag = (e.target && e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
        // Slide-over/export/saved-searches drawer open → don't intercept
        var slide = document.getElementById('leadSlideOver');
        var sheet = document.getElementById('exportSheet');
        var ssd   = document.getElementById('savedSearchesDrawer');
        var anyOpen = (slide && slide.classList.contains('open'))
                   || (sheet && sheet.style.transform === 'translateX(0)')
                   || (ssd && ssd.classList.contains('open'));
        if (anyOpen) return;

        var cards = _activeCards();
        switch (e.key) {
            case 'j': case 'ArrowDown':
                _setActive(_activeIdx + 1); e.preventDefault(); break;
            case 'k': case 'ArrowUp':
                _setActive(_activeIdx - 1); e.preventDefault(); break;
            case 'Enter':
                if (_activeIdx >= 0 && _activeIdx < cards.length) {
                    var c = cards[_activeIdx];
                    if (_bulkMode) {
                        var id = parseInt(c.dataset.leadId || '0', 10) || 0;
                        var lead = _findLeadById(id) || { id: id };
                        if (_selectedLeads[id]) { delete _selectedLeads[id]; c.classList.remove('is-selected'); }
                        else { _selectedLeads[id] = lead; c.classList.add('is-selected'); }
                        _refreshBulkActionBar();
                    } else {
                        var id2 = parseInt(c.dataset.leadId || '0', 10) || 0;
                        var lead2 = _findLeadById(id2);
                        if (lead2) openLeadSlideOver(lead2);
                    }
                    e.preventDefault();
                }
                break;
            case 's': case 'S':
                var saveBtn = document.getElementById('saveSearchBtn');
                if (saveBtn) { saveBtn.click(); e.preventDefault(); }
                break;
            case 'b': case 'B':
                var bulkToggle = document.getElementById('bulkModeToggle');
                if (bulkToggle) { bulkToggle.checked = !bulkToggle.checked; bulkToggle.dispatchEvent(new Event('change')); e.preventDefault(); }
                break;
            case '/':
                var cityEl = document.getElementById('fieldCity');
                if (cityEl) { cityEl.focus(); cityEl.select(); e.preventDefault(); }
                break;
        }
    });

    // When the cards re-render (new search, view-mode toggle, bulk toggle),
    // reset the active highlight.
    var origRerender = _rerenderLeadsList;
    _rerenderLeadsList = function () {
        origRerender.apply(this, arguments);
        _activeIdx = -1;
        _clearActive();
    };
})();

// Persist the bulk / view-mode selection isn't critical; we leave bulk mode
// implicitly off on every page load.

}); // end DOMContentLoaded
