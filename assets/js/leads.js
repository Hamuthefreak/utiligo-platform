document.addEventListener('DOMContentLoaded', function () {
  const form         = document.getElementById('leadSearchForm');
  const resultsWrap  = document.getElementById('leadsResultsWrap');
  const leadsList    = document.getElementById('leadsList');
  const lockedWrap   = document.getElementById('lockedWrap');
  const lockedList   = document.getElementById('lockedList');
  const loadingEl    = document.getElementById('leadsLoading');
  const csrfToken    = document.body.dataset.csrf;
  const statusChip   = document.getElementById('searchStatusChip');
  const searchBtn    = document.getElementById('searchBtn');
  const searchBtnLbl = document.getElementById('searchBtnLabel');

  const cfg     = document.getElementById('leadsPageConfig');
  const PLAN    = cfg ? cfg.dataset.plan : 'free';
  const IS_PAID = PLAN === 'pro' || PLAN === 'entrepreneur';

  // Seed bar values from PHP-rendered data attrs
  let leadUsed   = parseInt((cfg && cfg.dataset.leadUsed)  || '0', 10);
  let leadLimit  = parseInt((cfg && cfg.dataset.leadLimit) || '0', 10);
  let quotaUsed  = parseInt((cfg && cfg.dataset.quotaUsed)  || '0', 10);
  let quotaLimit = parseInt((cfg && cfg.dataset.quotaLimit) || '0', 10);

  if (!form) return;

  // ── seen-leads cache ────────────────────────────────────────────────────────
  const SEEN_KEY = 'utiligo_seen_leads_v1';
  function getSeenIds() {
    try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); }
    catch { return new Set(); }
  }
  function markSeen(ids) {
    try {
      const s = getSeenIds();
      ids.forEach(id => s.add(String(id)));
      const arr = [...s];
      if (arr.length > 2000) arr.splice(0, arr.length - 2000);
      localStorage.setItem(SEEN_KEY, JSON.stringify(arr));
    } catch {}
  }

  // ── Slider ──────────────────────────────────────────────────────────────────
  const slider     = document.getElementById('leadCountSlider');
  const sliderDisp = document.getElementById('leadCountDisplay');
  const sliderHid  = document.getElementById('leadCountHidden');
  if (slider) slider.addEventListener('input', function () {
    if (sliderDisp) sliderDisp.textContent = slider.value;
    if (sliderHid)  sliderHid.value = slider.value;
  });

  // ── Toggle ──────────────────────────────────────────────────────────────────
  const seenCb   = document.getElementById('includeSeenLeads');
  const togTrack = document.getElementById('togTrack');
  if (togTrack && seenCb) {
    togTrack.parentElement.addEventListener('click', function () {
      seenCb.checked = !seenCb.checked;
      togTrack.classList.toggle('on', seenCb.checked);
    });
  }

  // ── Lead bar sync ───────────────────────────────────────────────────────────
  // Called after every search (and on page load) with the fresh DB count.
  // Works for both pro (capped bar) and entrepreneur (infinity display).
  function syncLeadBar(count, limit) {
    // Accept whatever we're given; fall back to module-level vars
    if (typeof count === 'number' && count >= 0) leadUsed  = count;
    // limit: 0 means unlimited (entrepreneur). Only update if explicitly provided and >= 0.
    if (typeof limit === 'number' && limit >= 0) leadLimit = limit;

    var bar      = document.getElementById('leadLimitBar');
    var subtitle = document.getElementById('leadLimitSubtitle');
    var noteEl   = document.getElementById('leadLimitNote');
    var countEl  = document.getElementById('leadLimitCount');
    var upgBtn   = document.getElementById('leadUpgradeBtn');

    if (!bar) return; // bar not rendered (free plan) — nothing to do

    if (PLAN === 'entrepreneur') {
      // Unlimited — show live count with infinity symbol
      if (subtitle) subtitle.textContent = leadUsed + ' leads unlocked \u2014 unlimited';
      if (noteEl)   noteEl.textContent   = 'No cap \u2014 Entrepreneur plan';
      if (countEl)  countEl.innerHTML    = leadUsed + ' / &infin;';
      bar.style.width = '0%'; // bar stays empty — no cap to fill toward
      return;
    }

    // Pro — capped bar
    if (leadLimit <= 0) return; // safety: no limit value yet
    var pct = Math.min(100, Math.round((leadUsed / leadLimit) * 100));
    bar.style.width = pct + '%';
    bar.className   = 'q-fill ' + (pct >= 100 ? 'bg-red-400' : pct >= 80 ? 'bg-amber-400' : 'bg-white/40');
    if (subtitle) subtitle.textContent = leadUsed + ' of ' + leadLimit + ' used';
    if (noteEl)   noteEl.textContent   = Math.max(0, leadLimit - leadUsed) + ' remaining';
    if (countEl)  countEl.textContent  = leadUsed + ' / ' + leadLimit;
    if (upgBtn) {
      if (pct >= 80) upgBtn.classList.remove('hidden');
      else            upgBtn.classList.add('hidden');
    }
  }

  // ── Poll the lightweight lead-count endpoint on page load ───────────────────
  // This guarantees the bar is accurate even if leads.php queried before
  // any unlocks existed, or if the DB is slightly stale.
  if (IS_PAID) {
    fetch('/api/lead-count.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.success) {
          syncLeadBar(data.count, data.limit);
        }
      })
      .catch(function () {}); // silent — bar just stays at PHP-rendered value
  }

  // Kick off initial render from PHP-baked values (instant, before fetch resolves)
  if (IS_PAID) syncLeadBar(leadUsed, leadLimit);

  // ── Free quota bar ──────────────────────────────────────────────────────────
  function updateQuotaBar(newUsed) {
    quotaUsed = newUsed;
    var badge = document.getElementById('quotaBadge');
    var bar   = document.getElementById('quotaBar');
    var text  = document.getElementById('quotaText');
    var rem   = Math.max(0, quotaLimit - quotaUsed);
    var pct   = quotaLimit > 0 ? Math.min(100, Math.round((quotaUsed / quotaLimit) * 100)) : 0;
    if (badge) {
      badge.className = 'text-xs font-bold px-2.5 py-1 rounded-full ' +
        (rem===0 ? 'bg-red-500/10 text-red-400' : rem===1 ? 'bg-amber-500/10 text-amber-400' : 'bg-white/5 text-slate-400');
      badge.textContent = rem===0 ? 'No searches left' : rem+' search'+(rem!==1?'es':'')+' left';
    }
    if (bar) {
      bar.style.width = pct + '%';
      bar.className   = 'q-fill '+(pct>=100?'bg-red-400':pct>=50?'bg-amber-400':'bg-white/40');
    }
    if (text) text.textContent = quotaUsed + ' of ' + quotaLimit + ' used';
  }

  // ── Helpers ─────────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function scoreColor(s) {
    return s>=80 ? 'bg-white/10 text-white' : s>=60 ? 'bg-amber-500/15 text-amber-400' : 'bg-red-500/15 text-red-400';
  }
  function scoreLabel(s) { return s>=80?'High':s>=60?'Med':'Low'; }
  function fmtTimestamp(dateStr) {
    var d    = new Date(dateStr.replace(' ','T'));
    var now  = new Date();
    var sod  = new Date(now.getFullYear(),now.getMonth(),now.getDate());
    var yest = new Date(sod - 86400000);
    var time = d.toLocaleTimeString('en-CA',{hour:'numeric',minute:'2-digit',hour12:true});
    if (d >= sod)  return 'Today at '+time;
    if (d >= yest) return 'Yesterday at '+time;
    return d.toLocaleDateString('en-CA',{month:'short',day:'numeric'})+' at '+time;
  }
  function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
      var orig = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-check text-[10px]"></i>';
      btn.style.color = '#94a3b8';
      setTimeout(function(){btn.innerHTML=orig;btn.style.color='';},1600);
    }).catch(function(){});
  }

  // ── Lead card ────────────────────────────────────────────────────────────────
  function renderLeadRow(lead, seenBefore, idx) {
    var row     = document.createElement('div');
    var wasSeen = seenBefore.has(String(lead.id));
    row.className = 'lead-in glass rounded-2xl p-4 transition-all hover:border-white/[.15]'
                  + (wasSeen ? ' opacity-60' : '');
    row.style.animationDelay = (idx * 45) + 'ms';
    row.dataset.leadId = lead.id;

    var sc        = scoreColor(lead.opportunity_score);
    var hasRating = lead.rating && parseFloat(lead.rating) > 0;
    var stars     = hasRating
      ? '\u2605'.repeat(Math.round(parseFloat(lead.rating))) + '\u2606'.repeat(5 - Math.round(parseFloat(lead.rating)))
      : '';

    var generateUrl = '/portal/generate.php?lead_id='+encodeURIComponent(lead.id)
      +'&name='+encodeURIComponent(lead.business_name||'')
      +'&category='+encodeURIComponent(lead.business_category||'')
      +'&city='+encodeURIComponent(lead.business_city||'')
      +'&phone='+encodeURIComponent(lead.business_phone||'')
      +'&email='+encodeURIComponent(lead.business_email||'');

    var phoneLine = lead.business_phone
      ? '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-300 px-3 py-1.5 rounded-lg font-medium">'
          +'<i class="fa-solid fa-phone text-[10px] text-slate-600"></i>'+escHtml(lead.business_phone)
          +'<button type="button" class="copy-btn ml-1 text-slate-600 hover:text-slate-300 transition" data-copy="'+escHtml(lead.business_phone)+'" title="Copy phone">'
          +'<i class="fa-regular fa-copy text-[10px]"></i></button></span>'
      : '<span class="text-xs text-slate-700 px-2 py-1.5"><i class="fa-solid fa-phone mr-1.5"></i>No phone</span>';

    var emailLine = (lead.business_email && lead.business_email.trim() !== '')
      ? '<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-300 px-3 py-1.5 rounded-lg font-medium">'
          +'<i class="fa-solid fa-envelope text-[10px] text-slate-600"></i>'+escHtml(lead.business_email)
          +'<button type="button" class="copy-btn ml-1 text-slate-600 hover:text-slate-300 transition" data-copy="'+escHtml(lead.business_email)+'" title="Copy email">'
          +'<i class="fa-regular fa-copy text-[10px]"></i></button></span>'
      : '';

    var mapsLine = lead.maps_url
      ? '<a href="'+escHtml(lead.maps_url)+'" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white px-3 py-1.5 rounded-lg font-medium transition">'
          +'<i class="fa-brands fa-google text-[10px]"></i>Maps</a>'
      : '';

    row.innerHTML =
      '<div class="flex flex-col sm:flex-row sm:items-start gap-3">'
        +'<div class="flex-1 min-w-0">'
          +'<div class="flex items-center gap-2 flex-wrap mb-1.5">'
            +'<h3 class="font-bold text-white text-sm leading-tight">'+escHtml(lead.business_name)+'</h3>'
            +'<span class="text-[10px] px-1.5 py-0.5 rounded font-bold '+sc+'">'+scoreLabel(lead.opportunity_score)+' &middot; '+lead.opportunity_score+'</span>'
            +(lead.no_website ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-slate-500 font-semibold">No Website</span>' : '')
            +(wasSeen         ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-white/4 text-slate-600 font-semibold">Seen</span>' : '')
          +'</div>'
          +'<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">'
            +'<span><i class="fa-solid fa-location-dot mr-1 text-slate-600"></i>'+escHtml(lead.business_address||'Address unavailable')+'</span>'
            +(hasRating ? '<span class="text-amber-500/70 text-[11px]">'+stars+' <span class="text-slate-500">'+lead.rating+'</span></span>' : '')
            +(lead.total_ratings ? '<span class="text-slate-600">'+lead.total_ratings+' reviews</span>' : '')
            +(lead.business_category ? '<span class="text-slate-600"><i class="fa-solid fa-tag mr-1"></i>'+escHtml(lead.business_category)+'</span>' : '')
          +'</div>'
        +'</div>'
        +'<a href="'+generateUrl+'" class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-200 active:scale-95 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all shrink-0">'
          +'<i class="fa-solid fa-bolt text-[10px]"></i> Build Site'
        +'</a>'
      +'</div>'
      +'<div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-white/5">'+phoneLine+emailLine+mapsLine+'</div>';

    row.querySelectorAll('.copy-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) { e.stopPropagation(); copyText(btn.dataset.copy, btn); });
    });
    return row;
  }

  // ── Locked rows ─────────────────────────────────────────────────────────────
  var FAKE_NAMES  = ['Montreal Plumbing Co.','Apex Roofing Services','Bella Vista Restaurant','ProClean Janitorial','City Electrical Works','Green Thumb Landscaping','Maple Auto Repair','Studio 514 Hair Salon'];
  var FAKE_CITIES = ['Montreal, QC','Laval, QC','Longueuil, QC','Brossard, QC'];
  var FAKE_SCORES = [72,81,68,90,77,85,63,79];
  function renderLockedRow(lead, index) {
    var row = document.createElement('div');
    row.className = 'lead-in glass rounded-2xl p-4 flex items-center gap-4 overflow-hidden';
    row.style.animationDelay = (index * 45) + 'ms';
    var score = FAKE_SCORES[index % FAKE_SCORES.length];
    row.innerHTML =
      '<div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">'
        +'<div class="flex items-center gap-2 mb-1">'
          +'<h3 class="font-bold text-white text-sm">'+escHtml(FAKE_NAMES[index%FAKE_NAMES.length])+'</h3>'
          +'<span class="text-[10px] px-1.5 py-0.5 rounded font-bold '+scoreColor(score)+'">'+score+'</span>'
        +'</div>'
        +'<p class="text-xs text-slate-500">'+escHtml(FAKE_CITIES[index%FAKE_CITIES.length])+'</p>'
      +'</div>'
      +'<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 text-slate-500 px-3 py-1.5 rounded-lg font-semibold shrink-0">'
        +'<i class="fa-solid fa-lock text-[10px]"></i> Locked'
      +'</span>';
    return row;
  }

  // ── History sidebar ─────────────────────────────────────────────────────────
  function renderHistoryItem(entry) {
    var item = document.createElement('div');
    var ts   = fmtTimestamp(entry.created_at);
    item.innerHTML =
      '<button type="button"'
        +' class="w-full text-left flex flex-col gap-0.5 px-3 py-2.5 rounded-xl hover:bg-white/5 active:bg-white/8 transition-colors"'
        +' data-city="'+escHtml(entry.city)+'"'
        +' data-industry="'+escHtml(entry.industry)+'"'
        +' data-keywords="'+escHtml(entry.keywords||'')+'"'
      +'>'
        +'<div class="flex items-center justify-between gap-2 w-full">'
          +'<span class="text-xs font-semibold text-white truncate flex-1">'+escHtml(entry.city)+'</span>'
          +(entry.result_count>0 ? '<span class="text-[10px] font-bold text-slate-600 tabular-nums">'+entry.result_count+'</span>' : '')
        +'</div>'
        +'<span class="text-[11px] text-slate-500 truncate w-full">'
          +escHtml(entry.industry)+(entry.keywords?' &middot; '+escHtml(entry.keywords):'')
        +'</span>'
        +'<span class="text-[10px] text-slate-700 mt-0.5">'+ts+'</span>'
      +'</button>';
    item.querySelector('button').addEventListener('click', function () {
      var cityEl     = document.getElementById('fieldCity');
      var industryEl = document.getElementById('fieldIndustry');
      var keywordsEl = document.getElementById('fieldKeywords');
      if (cityEl)     cityEl.value     = this.dataset.city     || '';
      if (industryEl) industryEl.value = this.dataset.industry || '';
      if (keywordsEl) keywordsEl.value = this.dataset.keywords || '';
      document.getElementById('leadSearchForm').scrollIntoView({behavior:'smooth',block:'start'});
    });
    return item;
  }

  function loadSearchHistory() {
    var list    = document.getElementById('searchHistoryList');
    var empty   = document.getElementById('searchHistoryEmpty');
    var countEl = document.getElementById('historyCount');
    if (!list) return;
    fetch('/api/lead-search-history.php')
      .then(function(r){return r.json();})
      .then(function(data){
        list.innerHTML = '';
        var has = data.history && data.history.length > 0;
        if (empty)   empty.style.display = has ? 'none' : '';
        if (countEl) { if(has){countEl.textContent=data.history.length;countEl.classList.remove('hidden');}else{countEl.classList.add('hidden');} }
        if (has) data.history.forEach(function(e){list.appendChild(renderHistoryItem(e));});
      }).catch(function(){});
  }
  loadSearchHistory();

  // ── Search state ────────────────────────────────────────────────────────────
  function setSearching(on) {
    if (!searchBtn || !searchBtnLbl) return;
    searchBtn.disabled = on;
    searchBtnLbl.textContent = on ? 'Searching\u2026' : 'Find Leads';
    searchBtn.classList.toggle('opacity-50', on);
    searchBtn.classList.toggle('cursor-not-allowed', on);
  }

  // ── Main search ─────────────────────────────────────────────────────────────
  function runSearch(city, industry, keywords, leadCount, includeSeen, forceRefresh) {
    var t0 = Date.now();
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML = '';
    lockedList.innerHTML = '';
    if (statusChip) { statusChip.textContent = ''; statusChip.classList.add('hidden'); }
    setSearching(true);

    fetch('/api/find-leads.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        city: city,
        industry: industry,
        keywords: keywords || null,
        lead_count: leadCount || 10,
        include_seen: includeSeen,
        csrf_token: csrfToken,
        force_refresh: !!forceRefresh,
      }),
    })
    .then(function(r){return r.json();})
    .then(function(data){
      var elapsed = ((Date.now() - t0) / 1000).toFixed(1);
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      setSearching(false);

      if (!data.success) {
        leadsList.innerHTML =
          '<div class="glass rounded-2xl p-5 text-sm text-center '+(data.rate_limited?'text-amber-400':'text-red-400')+'">'
            +'<i class="fa-solid fa-'+(data.rate_limited?'clock':'triangle-exclamation')+' mr-2"></i>'
            +escHtml(data.error||'Search failed.')
            +(data.resets_at
              ? '<span class="block text-xs text-slate-500 mt-1">Resets at <strong>'
                  +new Date(data.resets_at*1000).toLocaleTimeString('en-CA',{hour:'numeric',minute:'2-digit',hour12:true})
                  +'</strong></span>'
              : '')
          +'</div>';
        lockedWrap.classList.add('hidden');
        return;
      }

      // Update bars from API response
      if (IS_PAID && typeof data.pro_lead_count === 'number') {
        // Use API-returned limit if valid; otherwise keep current leadLimit
        var newLimit = (typeof data.lead_limit === 'number' && data.lead_limit >= 0)
          ? data.lead_limit : leadLimit;
        syncLeadBar(data.pro_lead_count, newLimit);
      }
      if (!IS_PAID && typeof data.searches_used === 'number') {
        updateQuotaBar(data.searches_used);
      }

      var seenBefore = getSeenIds();
      if (data.leads && data.leads.length) markSeen(data.leads.map(function(l){return String(l.id);}));

      // Results header
      var header = document.createElement('div');
      header.className = 'flex items-center justify-between text-xs text-slate-500 mb-3 px-0.5 flex-wrap gap-2';
      var n       = (data.leads && data.leads.length) || 0;
      var seenCnt = (data.leads||[]).filter(function(l){return seenBefore.has(String(l.id));}).length;
      header.innerHTML =
        '<span><strong class="text-white">'+n+'</strong> leads'
          +' &middot; <span class="text-slate-400">'+escHtml(city)+', '+escHtml(industry)+'</span>'
          +' &middot; <span class="text-slate-600">'+elapsed+'s</span>'
          +(data.from_cache?'<span class="text-slate-700 ml-1">(cached)</span>':'')
        +'</span>'
        +'<span class="flex items-center gap-2">'
          +(seenCnt>0?'<span class="text-slate-600">'+seenCnt+' seen</span>':'')
          +(data.from_cache?'<button id="refreshBtn" type="button" class="text-slate-400 hover:text-white font-semibold underline">Refresh</button>':'')
        +'</span>';
      leadsList.appendChild(header);

      if (data.from_cache) {
        var rb = leadsList.querySelector('#refreshBtn');
        if (rb) rb.addEventListener('click', function(){runSearch(city,industry,keywords,leadCount,includeSeen,true);});
      }

      if (statusChip) { statusChip.classList.remove('hidden'); statusChip.textContent = n+' results \u00b7 '+elapsed+'s'; }

      if (!data.leads || !data.leads.length) {
        var em = document.createElement('p');
        em.className = 'text-slate-500 text-center py-10 text-sm';
        em.textContent = 'No leads found. Try a different city or industry.';
        leadsList.appendChild(em);
      } else {
        var toShow = data.leads;
        if (!includeSeen) {
          toShow = data.leads.filter(function(l){return !seenBefore.has(String(l.id));});
          if (!toShow.length) {
            var as = document.createElement('p');
            as.className = 'text-slate-500 text-center py-10 text-sm';
            as.innerHTML = '<i class="fa-solid fa-eye-slash mr-2"></i>All results already seen. Toggle <em>Include seen</em>.';
            leadsList.appendChild(as);
          }
        }
        toShow.forEach(function(lead, i){ leadsList.appendChild(renderLeadRow(lead, seenBefore, i)); });
      }

      if (data.is_free_tier && data.locked_leads && data.locked_leads.length) {
        data.locked_leads.forEach(function(l,i){lockedList.appendChild(renderLockedRow(l,i));});
        lockedWrap.classList.remove('hidden');
      } else {
        lockedWrap.classList.add('hidden');
      }

      loadSearchHistory();
    })
    .catch(function(){
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      setSearching(false);
      leadsList.innerHTML = '<div class="glass rounded-2xl p-5 text-red-400 text-sm text-center"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Something went wrong. Please try again.</div>';
      lockedWrap.classList.add('hidden');
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var city      = form.querySelector('[name="city"]').value.trim();
    var industry  = form.querySelector('[name="industry"]').value.trim();
    var keywords  = form.querySelector('[name="keywords"]') ? form.querySelector('[name="keywords"]').value.trim() : '';
    var leadCount = sliderHid ? parseInt(sliderHid.value, 10) || 10 : 10;
    var incSeen   = seenCb ? seenCb.checked : false;
    if (!city || !industry) return;
    runSearch(city, industry, keywords, leadCount, incSeen, false);
  });

  // Auto-run from URL params
  var params = new URLSearchParams(window.location.search);
  if (params.get('autorun') === '1') {
    var city     = params.get('city')     || '';
    var industry = params.get('industry') || '';
    var keywords = params.get('keywords') || '';
    var count    = parseInt(params.get('count'), 10) || 10;
    var cityEl     = document.getElementById('fieldCity');
    var industryEl = document.getElementById('fieldIndustry');
    var keywordsEl = document.getElementById('fieldKeywords');
    if (city && industry) {
      if (cityEl)     cityEl.value     = city;
      if (industryEl) industryEl.value = industry;
      if (keywordsEl) keywordsEl.value = keywords;
      runSearch(city, industry, keywords, count, false, false);
    }
  }
});
