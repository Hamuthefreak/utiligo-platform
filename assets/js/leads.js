/**
 * assets/js/leads.js  v2100
 *
 * FIXES vs v2000:
 *  1. Seen leads are NEVER filtered out — they are always shown, just dimmed.
 *     The "Include seen" toggle now means "dim seen leads" vs "show clean only".
 *     Default is show-all (toggle ON). Filtering caused every result to vanish
 *     on any repeat search because IDs accumulated in localStorage.
 *  2. seenBefore snapshot is captured BEFORE markSeen() so current results
 *     are never flagged as "seen" on their very first render.
 *  3. Bar sync unchanged — still: PHP-baked instant + background bar-status
 *     fetch + post-search sync from find-leads response.
 */
document.addEventListener('DOMContentLoaded', function () {

  // ── DOM refs ──────────────────────────────────────────────────────────────
  var form         = document.getElementById('leadSearchForm');
  var resultsWrap  = document.getElementById('leadsResultsWrap');
  var leadsList    = document.getElementById('leadsList');
  var lockedWrap   = document.getElementById('lockedWrap');
  var lockedList   = document.getElementById('lockedList');
  var loadingEl    = document.getElementById('leadsLoading');
  var csrfToken    = document.body.dataset.csrf;
  var statusChip   = document.getElementById('searchStatusChip');
  var searchBtn    = document.getElementById('searchBtn');
  var searchBtnLbl = document.getElementById('searchBtnLabel');

  // ── Config (PHP-baked) ────────────────────────────────────────────────────
  var cfg     = document.getElementById('leadsPageConfig');
  var PLAN    = cfg ? cfg.dataset.plan : 'free';
  var IS_PAID = PLAN === 'pro' || PLAN === 'entrepreneur';
  var IS_ENT  = PLAN === 'entrepreneur';

  var leadCount  = parseInt((cfg && cfg.dataset.leadCount)  || '0', 10);
  var leadLimit  = parseInt((cfg && cfg.dataset.leadLimit)  || '0', 10);
  var siteCount  = parseInt((cfg && cfg.dataset.siteCount)  || '0', 10);
  var siteLimit  = parseInt((cfg && cfg.dataset.siteLimit)  || '0', 10);
  var quotaUsed  = parseInt((cfg && cfg.dataset.quotaUsed)  || '0', 10);
  var quotaLimit = parseInt((cfg && cfg.dataset.quotaLimit) || '0', 10);

  if (!form) return;

  // ── Bar DOM refs ──────────────────────────────────────────────────────────
  var elLeadBar      = document.getElementById('leadBar');
  var elLeadSubtitle = document.getElementById('leadBarSubtitle');
  var elLeadNote     = document.getElementById('leadBarNote');
  var elLeadCount    = document.getElementById('leadBarCount');
  var elLeadUpgrade  = document.getElementById('leadUpgradeBtn');
  var elSiteBar      = document.getElementById('siteBar');
  var elSiteSubtitle = document.getElementById('siteBarSubtitle');
  var elSiteNote     = document.getElementById('siteBarNote');
  var elSiteCount    = document.getElementById('siteBarCount');

  // ── Seen-leads localStorage ───────────────────────────────────────────────
  // IMPORTANT: seenBefore snapshot must be taken BEFORE markSeen() so the
  // current batch of results is never tagged as "seen" on first render.
  var SEEN_KEY = 'utiligo_seen_leads_v1';
  function getSeenIds() {
    try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); }
    catch (e) { return new Set(); }
  }
  function markSeen(ids) {
    try {
      var s = getSeenIds();
      ids.forEach(function (id) { s.add(String(id)); });
      var arr = Array.from(s);
      if (arr.length > 2000) arr.splice(0, arr.length - 2000);
      localStorage.setItem(SEEN_KEY, JSON.stringify(arr));
    } catch (e) {}
  }

  // ── Slider ────────────────────────────────────────────────────────────────
  var slider     = document.getElementById('leadCountSlider');
  var sliderDisp = document.getElementById('leadCountDisplay');
  var sliderHid  = document.getElementById('leadCountHidden');
  if (slider) slider.addEventListener('input', function () {
    if (sliderDisp) sliderDisp.textContent = slider.value;
    if (sliderHid)  sliderHid.value        = slider.value;
  });

  // ── "Hide seen" toggle — default ON (show all) ───────────────────────────
  // togTrack.on  = show all leads (including seen, just dimmed)
  // togTrack.off = hide seen leads entirely
  // Default: ON so users always see results on first load.
  var seenCb   = document.getElementById('includeSeenLeads');
  var togTrack = document.getElementById('togTrack');
  // Default the checkbox + track to checked/on
  if (seenCb)   seenCb.checked = true;
  if (togTrack) togTrack.classList.add('on');
  if (togTrack && seenCb) {
    togTrack.parentElement.addEventListener('click', function () {
      seenCb.checked = !seenCb.checked;
      togTrack.classList.toggle('on', seenCb.checked);
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  //  syncBars — single source of truth for BOTH stat bars
  // ═══════════════════════════════════════════════════════════════════════════
  function syncBars(leadCnt, leadLim, siteCnt, siteLim) {
    if (typeof leadCnt === 'number' && leadCnt >= 0) leadCount = leadCnt;
    if (typeof leadLim === 'number' && leadLim >= 0) leadLimit = leadLim;
    if (typeof siteCnt === 'number' && siteCnt >= 0) siteCount = siteCnt;
    if (typeof siteLim === 'number' && siteLim >= 0) siteLimit = siteLim;

    // Lead unlock bar
    if (elLeadBar) {
      if (IS_ENT) {
        elLeadBar.style.width = '0%';
        elLeadBar.className   = 'q-fill bg-white/20';
        if (elLeadSubtitle) elLeadSubtitle.textContent = leadCount + ' unlocked \u2014 unlimited';
        if (elLeadNote)     elLeadNote.textContent     = 'No cap \u2014 Entrepreneur plan';
        if (elLeadCount)    elLeadCount.innerHTML      = leadCount + ' / &infin;';
      } else if (leadLimit > 0) {
        var lPct = Math.min(100, Math.round((leadCount / leadLimit) * 100));
        elLeadBar.style.width = lPct + '%';
        elLeadBar.className   = 'q-fill ' + (lPct >= 100 ? 'bg-red-400' : lPct >= 80 ? 'bg-amber-400' : 'bg-white/40');
        if (elLeadSubtitle) elLeadSubtitle.textContent = leadCount + ' of ' + leadLimit + ' used';
        if (elLeadNote)     elLeadNote.textContent     = Math.max(0, leadLimit - leadCount) + ' remaining';
        if (elLeadCount)    elLeadCount.textContent    = leadCount + ' / ' + leadLimit;
        if (elLeadUpgrade) {
          elLeadUpgrade.classList.toggle('hidden', lPct < 80);
        }
      }
    }

    // Active sites bar
    if (elSiteBar && siteLimit > 0) {
      var sPct = Math.min(100, Math.round((siteCount / siteLimit) * 100));
      elSiteBar.style.width = sPct + '%';
      elSiteBar.className   = 'q-fill ' + (sPct >= 100 ? 'bg-red-400' : sPct >= 80 ? 'bg-amber-400' : 'bg-white/40');
      if (elSiteSubtitle) elSiteSubtitle.textContent = siteCount + ' of ' + siteLimit + ' used';
      if (elSiteNote)     elSiteNote.textContent     = Math.max(0, siteLimit - siteCount) + ' remaining';
      if (elSiteCount)    elSiteCount.textContent    = siteCount + ' / ' + siteLimit;
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

  // ── Free quota bar ────────────────────────────────────────────────────────
  function updateQuotaBar(newUsed) {
    quotaUsed = newUsed;
    var badge = document.getElementById('quotaBadge');
    var bar   = document.getElementById('quotaBar');
    var text  = document.getElementById('quotaText');
    var rem   = Math.max(0, quotaLimit - quotaUsed);
    var pct   = quotaLimit > 0 ? Math.min(100, Math.round((quotaUsed / quotaLimit) * 100)) : 0;
    if (badge) {
      badge.className   = 'text-xs font-bold px-2.5 py-1 rounded-full ' +
        (rem === 0 ? 'bg-red-500/10 text-red-400' : rem === 1 ? 'bg-amber-500/10 text-amber-400' : 'bg-white/5 text-slate-400');
      badge.textContent = rem === 0 ? 'No searches left' : rem + ' search' + (rem !== 1 ? 'es' : '') + ' left';
    }
    if (bar)  { bar.style.width = pct + '%'; bar.className = 'q-fill ' + (pct >= 100 ? 'bg-red-400' : pct >= 50 ? 'bg-amber-400' : 'bg-white/40'); }
    if (text) text.textContent = quotaUsed + ' of ' + quotaLimit + ' used';
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function scoreColor(s) {
    return s >= 80 ? 'bg-white/10 text-white' : s >= 60 ? 'bg-amber-500/15 text-amber-400' : 'bg-red-500/15 text-red-400';
  }
  function scoreLabel(s) { return s >= 80 ? 'High' : s >= 60 ? 'Med' : 'Low'; }
  function fmtTimestamp(dateStr) {
    var d    = new Date(dateStr.replace(' ', 'T'));
    var now  = new Date();
    var sod  = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var yest = new Date(sod - 86400000);
    var time = d.toLocaleTimeString('en-CA', { hour: 'numeric', minute: '2-digit', hour12: true });
    if (d >= sod)  return 'Today at ' + time;
    if (d >= yest) return 'Yesterday at ' + time;
    return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' }) + ' at ' + time;
  }
  function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
      var orig = btn.innerHTML;
      btn.innerHTML  = '<i class="fa-solid fa-check text-[10px]"></i>';
      btn.style.color = '#94a3b8';
      setTimeout(function () { btn.innerHTML = orig; btn.style.color = ''; }, 1600);
    }).catch(function () {});
  }

  // ── Lead card ─────────────────────────────────────────────────────────────
  function renderLeadRow(lead, seenBefore, idx) {
    var wasSeen = seenBefore.has(String(lead.id));
    var row = document.createElement('div');
    // NEVER hide seen leads — always render them, just dim if wasSeen
    row.className = 'lead-in glass rounded-2xl p-4 transition-all hover:border-white/[.15]'
                  + (wasSeen ? ' opacity-50' : '');
    row.style.animationDelay = (idx * 45) + 'ms';
    row.dataset.leadId = lead.id;

    var sc        = scoreColor(lead.opportunity_score);
    var hasRating = lead.rating && parseFloat(lead.rating) > 0;
    var stars     = hasRating
      ? '\u2605'.repeat(Math.round(parseFloat(lead.rating)))
        + '\u2606'.repeat(5 - Math.round(parseFloat(lead.rating)))
      : '';
    var generateUrl = '/portal/generate.php?lead_id=' + encodeURIComponent(lead.id)
      + '&name='     + encodeURIComponent(lead.business_name     || '')
      + '&category=' + encodeURIComponent(lead.business_category || '')
      + '&city='     + encodeURIComponent(lead.business_city     || '')
      + '&phone='    + encodeURIComponent(lead.business_phone    || '')
      + '&email='    + encodeURIComponent(lead.business_email    || '');

    var phoneLine = lead.business_phone
      ? '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-300 px-3 py-1.5 rounded-lg font-medium">'
          + '<i class="fa-solid fa-phone text-[10px] text-slate-600"></i>' + escHtml(lead.business_phone)
          + '<button type="button" class="copy-btn ml-1 text-slate-600 hover:text-slate-300 transition" data-copy="' + escHtml(lead.business_phone) + '" title="Copy">'
          + '<i class="fa-regular fa-copy text-[10px]"></i></button></span>'
      : '<span class="text-xs text-slate-700 px-2 py-1.5"><i class="fa-solid fa-phone mr-1.5"></i>No phone</span>';

    var emailLine = (lead.business_email && lead.business_email.trim())
      ? '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-300 px-3 py-1.5 rounded-lg font-medium">'
          + '<i class="fa-solid fa-envelope text-[10px] text-slate-600"></i>' + escHtml(lead.business_email)
          + '<button type="button" class="copy-btn ml-1 text-slate-600 hover:text-slate-300 transition" data-copy="' + escHtml(lead.business_email) + '" title="Copy">'
          + '<i class="fa-regular fa-copy text-[10px]"></i></button></span>'
      : '';

    var mapsLine = lead.maps_url
      ? '<a href="' + escHtml(lead.maps_url) + '" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white px-3 py-1.5 rounded-lg font-medium transition">'
          + '<i class="fa-brands fa-google text-[10px]"></i>Maps</a>'
      : '';

    row.innerHTML =
      '<div class="flex flex-col sm:flex-row sm:items-start gap-3">'
        + '<div class="flex-1 min-w-0">'
          + '<div class="flex items-center gap-2 flex-wrap mb-1.5">'
            + '<h3 class="font-bold text-white text-sm leading-tight">' + escHtml(lead.business_name) + '</h3>'
            + '<span class="text-[10px] px-1.5 py-0.5 rounded font-bold ' + sc + '">' + scoreLabel(lead.opportunity_score) + ' &middot; ' + lead.opportunity_score + '</span>'
            + (lead.no_website ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-slate-500 font-semibold">No Website</span>' : '')
            + (wasSeen ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-white/[.04] text-slate-600 font-semibold">Seen</span>' : '')
          + '</div>'
          + '<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">'
            + '<span><i class="fa-solid fa-location-dot mr-1 text-slate-600"></i>' + escHtml(lead.business_address || 'Address unavailable') + '</span>'
            + (hasRating ? '<span class="text-amber-500/70 text-[11px]">' + stars + ' <span class="text-slate-500">' + lead.rating + '</span></span>' : '')
            + (lead.total_ratings ? '<span class="text-slate-600">' + lead.total_ratings + ' reviews</span>' : '')
            + (lead.business_category ? '<span class="text-slate-600"><i class="fa-solid fa-tag mr-1"></i>' + escHtml(lead.business_category) + '</span>' : '')
          + '</div>'
        + '</div>'
        + '<a href="' + generateUrl + '" class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-200 active:scale-95 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all shrink-0">'
          + '<i class="fa-solid fa-bolt text-[10px]"></i> Build Site'
        + '</a>'
      + '</div>'
      + '<div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-white/5">' + phoneLine + emailLine + mapsLine + '</div>';

    row.querySelectorAll('.copy-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) { e.stopPropagation(); copyText(btn.dataset.copy, btn); });
    });
    return row;
  }

  // ── Locked rows (free tier) ───────────────────────────────────────────────
  var FAKE_NAMES  = ['Montreal Plumbing Co.','Apex Roofing Services','Bella Vista Restaurant','ProClean Janitorial','City Electrical Works','Green Thumb Landscaping','Maple Auto Repair','Studio 514 Hair Salon'];
  var FAKE_CITIES = ['Montreal, QC','Laval, QC','Longueuil, QC','Brossard, QC'];
  var FAKE_SCORES = [72,81,68,90,77,85,63,79];
  function renderLockedRow(lead, index) {
    var row   = document.createElement('div');
    var score = FAKE_SCORES[index % FAKE_SCORES.length];
    row.className = 'lead-in glass rounded-2xl p-4 flex items-center gap-4 overflow-hidden';
    row.style.animationDelay = (index * 45) + 'ms';
    row.innerHTML =
      '<div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">'
        + '<div class="flex items-center gap-2 mb-1">'
          + '<h3 class="font-bold text-white text-sm">' + escHtml(FAKE_NAMES[index % FAKE_NAMES.length]) + '</h3>'
          + '<span class="text-[10px] px-1.5 py-0.5 rounded font-bold ' + scoreColor(score) + '">' + score + '</span>'
        + '</div>'
        + '<p class="text-xs text-slate-500">' + escHtml(FAKE_CITIES[index % FAKE_CITIES.length]) + '</p>'
      + '</div>'
      + '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-500 px-3 py-1.5 rounded-lg font-semibold shrink-0">'
        + '<i class="fa-solid fa-lock text-[10px]"></i> Locked'
      + '</span>';
    return row;
  }

  // ── Search history sidebar ────────────────────────────────────────────────
  function renderHistoryItem(entry) {
    var item = document.createElement('div');
    var ts   = fmtTimestamp(entry.created_at);
    item.innerHTML =
      '<button type="button"'
        + ' class="w-full text-left flex flex-col gap-0.5 px-3 py-2.5 rounded-xl hover:bg-white/5 active:bg-white/8 transition-colors"'
        + ' data-city="'     + escHtml(entry.city)           + '"'
        + ' data-industry="' + escHtml(entry.industry)       + '"'
        + ' data-keywords="' + escHtml(entry.keywords || '') + '"'
      + '>'
        + '<div class="flex items-center justify-between gap-2 w-full">'
          + '<span class="text-xs font-semibold text-white truncate flex-1">' + escHtml(entry.city) + '</span>'
          + (entry.result_count > 0 ? '<span class="text-[10px] font-bold text-slate-600 tabular-nums">' + entry.result_count + '</span>' : '')
        + '</div>'
        + '<span class="text-[11px] text-slate-500 truncate w-full">'
          + escHtml(entry.industry) + (entry.keywords ? ' &middot; ' + escHtml(entry.keywords) : '')
        + '</span>'
        + '<span class="text-[10px] text-slate-700 mt-0.5">' + ts + '</span>'
      + '</button>';
    item.querySelector('button').addEventListener('click', function () {
      var cityEl     = document.getElementById('fieldCity');
      var industryEl = document.getElementById('fieldIndustry');
      var keywordsEl = document.getElementById('fieldKeywords');
      if (cityEl)     cityEl.value     = this.dataset.city     || '';
      if (industryEl) industryEl.value = this.dataset.industry || '';
      if (keywordsEl) keywordsEl.value = this.dataset.keywords || '';
      document.getElementById('leadSearchForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    return item;
  }

  function loadSearchHistory() {
    var list    = document.getElementById('searchHistoryList');
    var empty   = document.getElementById('searchHistoryEmpty');
    var countEl = document.getElementById('historyCount');
    if (!list) return;
    fetch('/api/lead-search-history.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        list.innerHTML = '';
        var has = data.history && data.history.length > 0;
        if (empty)   empty.style.display = has ? 'none' : '';
        if (countEl) {
          countEl.textContent = has ? data.history.length : '';
          countEl.classList.toggle('hidden', !has);
        }
        if (has) data.history.forEach(function (e) { list.appendChild(renderHistoryItem(e)); });
      }).catch(function () {});
  }
  loadSearchHistory();

  // ── Search button state ───────────────────────────────────────────────────
  function setSearching(on) {
    if (!searchBtn || !searchBtnLbl) return;
    searchBtn.disabled = on;
    searchBtnLbl.textContent = on ? 'Searching\u2026' : 'Find Leads';
    searchBtn.classList.toggle('opacity-50', on);
    searchBtn.classList.toggle('cursor-not-allowed', on);
  }

  // ═══════════════════════════════════════════════════════════════════════════
  //  runSearch
  // ═══════════════════════════════════════════════════════════════════════════
  function runSearch(city, industry, keywords, leadCntReq, includeSeen, forceRefresh) {
    var t0 = Date.now();
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML  = '';
    lockedList.innerHTML = '';
    if (statusChip) { statusChip.textContent = ''; statusChip.classList.add('hidden'); }
    setSearching(true);

    fetch('/api/find-leads.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        city:          city,
        industry:      industry,
        keywords:      keywords || null,
        lead_count:    leadCntReq || 10,
        include_seen:  includeSeen,
        csrf_token:    csrfToken,
        force_refresh: !!forceRefresh,
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var elapsed = ((Date.now() - t0) / 1000).toFixed(1);
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      setSearching(false);

      if (!data.success) {
        leadsList.innerHTML =
          '<div class="glass rounded-2xl p-5 text-sm text-center ' + (data.rate_limited ? 'text-amber-400' : 'text-red-400') + '">'
            + '<i class="fa-solid fa-' + (data.rate_limited ? 'clock' : 'triangle-exclamation') + ' mr-2"></i>'
            + escHtml(data.error || 'Search failed.')
            + (data.resets_at
              ? '<span class="block text-xs text-slate-500 mt-1">Resets at <strong>'
                  + new Date(data.resets_at * 1000).toLocaleTimeString('en-CA', { hour: 'numeric', minute: '2-digit', hour12: true })
                  + '</strong></span>'
              : '')
          + '</div>';
        lockedWrap.classList.add('hidden');
        return;
      }

      // ── Update bars immediately from search payload, then bg-refresh ──────
      if (IS_PAID) {
        var newLeadCnt = (typeof data.pro_lead_count === 'number') ? data.pro_lead_count : leadCount;
        var newLeadLim = (typeof data.lead_limit === 'number' && data.lead_limit >= 0) ? data.lead_limit : leadLimit;
        syncBars(newLeadCnt, newLeadLim, siteCount, siteLimit);
        fetchBarStatus();
      }
      if (!IS_PAID && typeof data.searches_used === 'number') {
        updateQuotaBar(data.searches_used);
      }

      // ── FIX: capture seenBefore BEFORE marking so current results aren't ──
      //         flagged as "seen" on this very render.
      var seenBefore = getSeenIds();
      if (data.leads && data.leads.length) {
        markSeen(data.leads.map(function (l) { return String(l.id); }));
      }

      // Results header
      var n       = (data.leads && data.leads.length) || 0;
      var seenCnt = (data.leads || []).filter(function (l) { return seenBefore.has(String(l.id)); }).length;
      var header  = document.createElement('div');
      header.className = 'flex items-center justify-between text-xs text-slate-500 mb-3 px-0.5 flex-wrap gap-2';
      header.innerHTML =
        '<span><strong class="text-white">' + n + '</strong> leads'
          + ' &middot; <span class="text-slate-400">' + escHtml(city) + ', ' + escHtml(industry) + '</span>'
          + ' &middot; <span class="text-slate-600">' + elapsed + 's</span>'
          + (data.from_cache ? '<span class="text-slate-700 ml-1">(cached)</span>' : '')
        + '</span>'
        + '<span class="flex items-center gap-2">'
          + (seenCnt > 0 ? '<span class="text-slate-600">' + seenCnt + ' seen</span>' : '')
          + (data.from_cache ? '<button id="refreshBtn" type="button" class="text-slate-400 hover:text-white font-semibold underline">Refresh</button>' : '')
        + '</span>';
      leadsList.appendChild(header);

      if (data.from_cache) {
        var rb = leadsList.querySelector('#refreshBtn');
        if (rb) rb.addEventListener('click', function () {
          runSearch(city, industry, keywords, leadCntReq, includeSeen, true);
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

        // includeSeen=false: HIDE seen leads (user explicitly toggled off)
        // includeSeen=true (default): show ALL leads, dim the seen ones
        if (!includeSeen) {
          toShow = data.leads.filter(function (l) { return !seenBefore.has(String(l.id)); });
          if (!toShow.length) {
            var as = document.createElement('p');
            as.className  = 'text-slate-500 text-center py-10 text-sm';
            as.innerHTML  = '<i class="fa-solid fa-eye-slash mr-2"></i>All results already seen. Toggle <em>Include seen</em> to show them.';
            leadsList.appendChild(as);
          }
        }

        toShow.forEach(function (lead, i) {
          leadsList.appendChild(renderLeadRow(lead, seenBefore, i));
        });
      }

      if (data.is_free_tier && data.locked_leads && data.locked_leads.length) {
        data.locked_leads.forEach(function (l, i) { lockedList.appendChild(renderLockedRow(l, i)); });
        lockedWrap.classList.remove('hidden');
      } else {
        lockedWrap.classList.add('hidden');
      }

      loadSearchHistory();
    })
    .catch(function () {
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      setSearching(false);
      leadsList.innerHTML = '<div class="glass rounded-2xl p-5 text-red-400 text-sm text-center"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Something went wrong. Please try again.</div>';
      lockedWrap.classList.add('hidden');
    });
  }

  // ── Form submit ───────────────────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var city     = form.querySelector('[name="city"]').value.trim();
    var industry = form.querySelector('[name="industry"]').value.trim();
    var keywords = (form.querySelector('[name="keywords"]') || {}).value || '';
    keywords     = keywords.trim();
    var leadCnt  = sliderHid ? parseInt(sliderHid.value, 10) || 10 : 10;
    var incSeen  = seenCb ? seenCb.checked : true;
    if (!city || !industry) return;
    runSearch(city, industry, keywords, leadCnt, incSeen, false);
  });

  // ── Auto-run from URL params ──────────────────────────────────────────────
  var params = new URLSearchParams(window.location.search);
  if (params.get('autorun') === '1') {
    var _city     = params.get('city')     || '';
    var _industry = params.get('industry') || '';
    var _keywords = params.get('keywords') || '';
    var _count    = parseInt(params.get('count'), 10) || 10;
    if (_city && _industry) {
      var cityEl     = document.getElementById('fieldCity');
      var industryEl = document.getElementById('fieldIndustry');
      var keywordsEl = document.getElementById('fieldKeywords');
      if (cityEl)     cityEl.value     = _city;
      if (industryEl) industryEl.value = _industry;
      if (keywordsEl) keywordsEl.value = _keywords;
      runSearch(_city, _industry, _keywords, _count, true, false);
    }
  }

});
