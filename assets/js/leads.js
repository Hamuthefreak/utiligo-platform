/**
 * assets/js/leads.js  v4 (full rebuild)
 *
 * ARCHITECTURE
 * ============
 * - Seen tracking   : localStorage key v2 (v1 auto-ignored)
 *                     id=0 is NEVER stored or treated as seen
 * - Toggle          : defaults ON (show all, dim previously-seen)
 *                     when OFF, seen leads are hidden
 * - seenBefore      : snapshot taken BEFORE markSeen() so fresh
 *                     leads never carry a Seen badge on first view
 * - syncBars        : single function, called after every search
 *                     AND by fetchBarStatus() background poll
 * - renderLeadCard  : pure function, no side effects
 * - runSearch       : all fetch/render logic, fully self-contained
 *
 * DEBUG
 * =====
 * Every search response is logged to console._leadsDebug for easy
 * inspection in DevTools: open Network tab, click the find-leads
 * request and check the _debug field, OR open Console and type
 * console._leadsDebug to see the last response.
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

if (!form) return; // not on leads page

// ============================================================
//  1. PLAN CONFIG (baked by PHP into #leadsPageConfig)
// ============================================================
var cfg = document.getElementById('leadsPageConfig');
var PLAN    = cfg ? (cfg.dataset.plan    || 'free') : 'free';
var IS_PAID = PLAN === 'pro' || PLAN === 'entrepreneur';
var IS_ENT  = PLAN === 'entrepreneur';

var leadCount  = parseInt((cfg && cfg.dataset.leadCount)  || '0', 10);
var leadLimit  = parseInt((cfg && cfg.dataset.leadLimit)  || '0', 10);
var siteCount  = parseInt((cfg && cfg.dataset.siteCount)  || '0', 10);
var siteLimit  = parseInt((cfg && cfg.dataset.siteLimit)  || '0', 10);
var quotaUsed  = parseInt((cfg && cfg.dataset.quotaUsed)  || '0', 10);
var quotaLimit = parseInt((cfg && cfg.dataset.quotaLimit) || '0', 10);

// ============================================================
//  2. SEEN-LEADS  (localStorage, keyed v2)
// ============================================================
var SEEN_KEY = 'utiligo_seen_leads_v2';
// v1 is never read — old polluted data is automatically ignored

function getSeenIds() {
    try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); }
    catch (e) { return new Set(); }
}

function markSeen(ids) {
    try {
        var s = getSeenIds();
        ids.forEach(function (id) {
            var sid = String(id);
            // NEVER store 0 or empty — means DB id lookup failed
            if (sid && sid !== '0') s.add(sid);
        });
        var arr = Array.from(s);
        // Cap at 2000 entries; trim oldest
        if (arr.length > 2000) arr = arr.slice(arr.length - 2000);
        localStorage.setItem(SEEN_KEY, JSON.stringify(arr));
    } catch (e) { /* storage full or private mode */ }
}

function wasSeen(id, seenSet) {
    var sid = String(id);
    // id=0 means DB lookup failed — NEVER treat as seen
    return sid && sid !== '0' && seenSet.has(sid);
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
//  4. SEEN TOGGLE  (defaults ON = show all, dim seen)
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

    // Lead bar
    if (elLeadBar) {
        if (IS_ENT) {
            elLeadBar.style.width = '0%';
            elLeadBar.className   = 'q-fill bg-white/20';
            if (elLeadSub)   elLeadSub.textContent   = leadCount + ' unlocked \u2014 unlimited';
            if (elLeadNote)  elLeadNote.textContent  = 'No cap \u2014 Entrepreneur plan';
            if (elLeadCount) elLeadCount.innerHTML   = leadCount + ' / &infin;';
        } else if (leadLimit > 0) {
            var lp = Math.min(100, Math.round(leadCount / leadLimit * 100));
            elLeadBar.style.width = lp + '%';
            elLeadBar.className   = 'q-fill ' + (lp >= 100 ? 'bg-red-400' : lp >= 80 ? 'bg-amber-400' : 'bg-white/40');
            if (elLeadSub)      elLeadSub.textContent   = leadCount + ' of ' + leadLimit + ' used';
            if (elLeadNote)     elLeadNote.textContent  = Math.max(0, leadLimit - leadCount) + ' remaining';
            if (elLeadCount)    elLeadCount.textContent = leadCount + ' / ' + leadLimit;
            if (elLeadUpgrade)  elLeadUpgrade.classList.toggle('hidden', lp < 80);
        }
    }

    // Site bar
    if (elSiteBar && siteLimit > 0) {
        var sp = Math.min(100, Math.round(siteCount / siteLimit * 100));
        elSiteBar.style.width = sp + '%';
        elSiteBar.className   = 'q-fill ' + (sp >= 100 ? 'bg-red-400' : sp >= 80 ? 'bg-amber-400' : 'bg-white/40');
        if (elSiteSub)   elSiteSub.textContent   = siteCount + ' of ' + siteLimit + ' used';
        if (elSiteNote)  elSiteNote.textContent  = Math.max(0, siteLimit - siteCount) + ' remaining';
        if (elSiteCount) elSiteCount.textContent = siteCount + ' / ' + siteLimit;
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

// Initial bar render from page-baked values, then live refresh
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
//  7. SMALL HELPERS
// ============================================================
function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
          + '<a href="'+genUrl+'" class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-200 active:scale-95 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all shrink-0">'
            + '<i class="fa-solid fa-bolt text-[10px]"></i> Build Site'
          + '</a>'
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
    var t0 = Date.now();

    // Reset UI
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
        }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        var elapsed = ((Date.now() - t0) / 1000).toFixed(1);

        // Expose for DevTools inspection
        try { console._leadsDebug = data; } catch(e) {}

        loadingEl.classList.add('hidden');
        resultsWrap.classList.remove('hidden');
        setSearchBusy(false);

        // ── Error response ────────────────────────────────────────────
        if (!data.success) {
            leadsList.innerHTML =
                '<div class="glass rounded-2xl p-5 text-sm text-center '
                + (data.rate_limited ? 'text-amber-400' : 'text-red-400') + '">'
                + '<i class="fa-solid fa-'+(data.rate_limited?'clock':'triangle-exclamation')+' mr-2"></i>'
                + esc(data.error || 'Search failed.')
                + (data.resets_at
                    ? '<span class="block text-xs text-slate-500 mt-1">Resets at <strong>'
                        + new Date(data.resets_at * 1000).toLocaleTimeString('en-CA',{hour:'numeric',minute:'2-digit',hour12:true})
                        + '</strong></span>'
                    : '')
                + '</div>';
            lockedWrap.classList.add('hidden');
            return;
        }

        // ── Update bars ─────────────────────────────────────────────
        if (IS_PAID) {
            var newLC = typeof data.pro_lead_count === 'number' ? data.pro_lead_count : leadCount;
            var newLL = typeof data.lead_limit     === 'number' ? data.lead_limit     : leadLimit;
            syncBars(newLC, newLL, siteCount, siteLimit);
            // Background refresh to confirm
            setTimeout(fetchBarStatus, 800);
        }
        if (!IS_PAID && typeof data.searches_used === 'number') {
            updateQuotaBar(data.searches_used);
        }

        // ── SEEN SNAPSHOT (MUST happen before markSeen) ───────────────
        // seenBefore reflects what was already seen BEFORE this search.
        // Leads returned NOW will NOT have the Seen badge on this first view.
        var seenBefore = getSeenIds();
        if (data.leads && data.leads.length) {
            markSeen(data.leads.map(function (l) { return l.id; }));
        }

        // ── Results header ───────────────────────────────────────────
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

        // ── Render leads ───────────────────────────────────────────────
        if (!data.leads || !data.leads.length) {
            var em = document.createElement('p');
            em.className   = 'text-slate-500 text-center py-10 text-sm';
            em.textContent = 'No leads found. Try a different city or industry.';
            leadsList.appendChild(em);
        } else {
            // When toggle=ON  → show all (seen ones are dimmed)
            // When toggle=OFF → hide seen leads
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

        // ── Locked rows (free tier) ──────────────────────────────────
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
    var city     = (form.querySelector('[name="city"]')     || {}).value || '';
    var industry = (form.querySelector('[name="industry"]') || {}).value || '';
    var keywords = (form.querySelector('[name="keywords"]') || {}).value || '';
    city     = city.trim();
    industry = industry.trim();
    keywords = keywords.trim();
    if (!city || !industry) return;
    var cnt    = sliderHid ? parseInt(sliderHid.value, 10) || 10 : 10;
    var incSeen = seenCb ? seenCb.checked : true;
    runSearch(city, industry, keywords, cnt, incSeen, false);
});

// ============================================================
//  13. AUTO-RUN FROM URL PARAMS  (?autorun=1&city=...)
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

}); // end DOMContentLoaded
