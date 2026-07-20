document.addEventListener('DOMContentLoaded', function () {
  const form        = document.getElementById('leadSearchForm');
  const resultsWrap = document.getElementById('leadsResultsWrap');
  const leadsList   = document.getElementById('leadsList');
  const lockedWrap  = document.getElementById('lockedWrap');
  const lockedList  = document.getElementById('lockedList');
  const loadingEl   = document.getElementById('leadsLoading');
  const csrfToken   = document.body.dataset.csrf;

  const cfg     = document.getElementById('leadsPageConfig');
  const PLAN    = cfg?.dataset.plan    ?? 'free';
  const IS_PAID = PLAN === 'pro' || PLAN === 'entrepreneur';

  let leadUsed   = parseInt(cfg?.dataset.leadUsed  ?? 0);
  let leadLimit  = parseInt(cfg?.dataset.leadLimit ?? 0);
  let quotaUsed  = parseInt(cfg?.dataset.quotaUsed  ?? 0);
  let quotaLimit = parseInt(cfg?.dataset.quotaLimit ?? 0);

  if (!form) return;

  // ─ localStorage seen-leads ─────────────────────────────────────────────────
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

  // ─ Slider ────────────────────────────────────────────────────────────────
  const slider     = document.getElementById('leadCountSlider');
  const sliderDisp = document.getElementById('leadCountDisplay');
  const sliderHid  = document.getElementById('leadCountHidden');
  if (slider) slider.addEventListener('input', () => { sliderDisp.textContent = slider.value; sliderHid.value = slider.value; });

  const seenCb = document.getElementById('includeSeenLeads');

  // ─ Live pro lead-unlock bar ─────────────────────────────────────────────
  function syncLeadCounter(serverCount, serverLimit) {
    if (typeof serverCount === 'number' && serverCount >= 0) leadUsed  = serverCount;
    if (typeof serverLimit === 'number' && serverLimit  > 0) leadLimit = serverLimit;
    const bar      = document.getElementById('leadLimitBar');
    const subtitle = document.getElementById('leadLimitSubtitle');
    const countEl  = document.getElementById('leadLimitCount');
    const noteEl   = document.getElementById('leadLimitNote');
    const upgBtn   = document.getElementById('leadUpgradeBtn');
    if (!bar || leadLimit <= 0) return;
    const pct = Math.min(100, Math.round((leadUsed / leadLimit) * 100));
    bar.style.width = pct + '%';
    bar.className   = 'h-2 rounded-full transition-all ' + (pct >= 100 ? 'bg-red-500' : pct >= 80 ? 'bg-amber-500' : 'bg-white/60');
    if (subtitle) subtitle.textContent = leadUsed + ' of ' + leadLimit + ' used';
    if (countEl)  countEl.textContent  = leadUsed + ' / ' + leadLimit;
    if (noteEl)   noteEl.textContent   = Math.max(0, leadLimit - leadUsed) + ' remaining';
    if (upgBtn) { pct >= 80 ? upgBtn.classList.remove('hidden') : upgBtn.classList.add('hidden'); }
  }

  // ─ Live free quota bar ──────────────────────────────────────────────────
  function updateLiveQuotaCounter(newUsed) {
    quotaUsed = newUsed;
    const badge = document.getElementById('quotaBadge');
    const bar   = document.getElementById('quotaBar');
    const text  = document.getElementById('quotaText');
    const rem   = Math.max(0, quotaLimit - quotaUsed);
    const pct   = quotaLimit > 0 ? Math.min(100, Math.round((quotaUsed / quotaLimit) * 100)) : 0;
    if (badge) {
      badge.className = 'flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ' +
        (rem===0?'bg-red-500/10 border border-red-500/20 text-red-400':rem===1?'bg-amber-500/10 border border-amber-500/20 text-amber-400':'bg-white/8 border border-white/10 text-slate-300');
      badge.textContent = rem===0?'No searches left today':rem+' search'+(rem!==1?'es':'')+' left';
    }
    if (bar) { bar.style.width=pct+'%'; bar.className='h-2 rounded-full transition-all duration-500 '+(pct>=100?'bg-red-500':pct>=50?'bg-amber-500':'bg-white/60'); }
    if (text) text.textContent = quotaUsed+' of '+quotaLimit+' searches used';
  }

  // ─ Helpers ─────────────────────────────────────────────────────────────
  function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function scoreColor(s) { return s>=80?'bg-white/15 text-white':s>=60?'bg-amber-500/20 text-amber-400':'bg-red-500/20 text-red-400'; }
  function scorLabel(s)  { return s>=80?'High':s>=60?'Med':'Low'; }

  // ─ Lead card ────────────────────────────────────────────────────────────────
  function renderLeadRow(lead, seenIdsBefore) {
    const row      = document.createElement('div');
    const wasSeen  = seenIdsBefore.has(String(lead.id));
    row.className  = 'glass rounded-2xl border transition-all p-4 flex flex-col gap-3' + (wasSeen ? ' border-white/8 opacity-75' : ' border-white/5 hover:border-white/15');
    row.dataset.leadId = lead.id;
    const sc = scoreColor(lead.opportunity_score);
    const hasRating = lead.rating && parseFloat(lead.rating) > 0;
    const stars = hasRating ? '\u2605'.repeat(Math.round(parseFloat(lead.rating))) + '\u2606'.repeat(5-Math.round(parseFloat(lead.rating))) : '';

    const phoneLine = lead.business_phone
      ? `<a href="tel:${escHtml(lead.business_phone)}" class="inline-flex items-center gap-1.5 text-xs bg-white/8 hover:bg-white/15 text-slate-200 px-3 py-1.5 rounded-lg font-medium transition-all"><i class="fa-solid fa-phone text-[10px]"></i>${escHtml(lead.business_phone)}</a>`
      : `<span class="inline-flex items-center gap-1.5 text-xs bg-white/4 text-slate-600 px-3 py-1.5 rounded-lg"><i class="fa-solid fa-phone text-[10px]"></i>No phone listed</span>`;
    const mapsLine = lead.maps_url
      ? `<a href="${escHtml(lead.maps_url)}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs bg-white/8 hover:bg-white/15 text-slate-200 px-3 py-1.5 rounded-lg font-medium transition-all"><i class="fa-brands fa-google text-[10px]"></i>Google Maps</a>`
      : '';

    // FIX: pass all prefill fields as URL params so generate.php pre-fills the form
    const generateUrl = '/portal/generate.php?lead_id=' + encodeURIComponent(lead.id)
      + '&name='     + encodeURIComponent(lead.business_name     || '')
      + '&category=' + encodeURIComponent(lead.business_category || '')
      + '&city='     + encodeURIComponent(lead.business_city     || '')
      + '&phone='    + encodeURIComponent(lead.business_phone    || '');

    row.innerHTML = `
      <div class="flex flex-col sm:flex-row sm:items-start gap-3">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1">
            <h3 class="font-bold text-white text-base">${escHtml(lead.business_name)}</h3>
            <span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${sc}">${scorLabel(lead.opportunity_score)} &middot; ${lead.opportunity_score}</span>
            ${lead.no_website?'<span class="text-[10px] px-2 py-0.5 rounded-full bg-white/8 text-slate-400 font-semibold">No Website</span>':''}
            ${wasSeen?'<span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-700/60 text-slate-400 font-semibold">Seen</span>':''}
          </div>
          <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400 mt-1">
            <span><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(lead.business_address||'Address unavailable')}</span>
            ${hasRating?`<span class="text-amber-400" title="${lead.rating} stars">${stars} <span class="text-slate-400">${lead.rating}</span></span>`:''}
            ${lead.total_ratings?`<span class="text-slate-500">${lead.total_ratings} reviews</span>`:''}
            ${lead.business_category?`<span class="text-slate-500"><i class="fa-solid fa-tag mr-1"></i>${escHtml(lead.business_category)}</span>`:''}
          </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <a href="${generateUrl}"
             class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-200 active:scale-95 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all">
            <i class="fa-solid fa-bolt text-[10px]"></i> Build Site
          </a>
        </div>
      </div>
      <div class="flex flex-wrap gap-2 border-t border-white/5 pt-3">${phoneLine}${mapsLine}</div>
    `;
    return row;
  }

  const FAKE_NAMES  = ['Montreal Plumbing Co.','Apex Roofing Services','Bella Vista Restaurant','ProClean Janitorial','City Electrical Works','Green Thumb Landscaping','Maple Auto Repair','Studio 514 Hair Salon'];
  const FAKE_CITIES = ['Montreal, QC','Laval, QC','Longueuil, QC','Brossard, QC'];
  const FAKE_SCORES = [72,81,68,90,77,85,63,79];

  function renderLockedRow(lead, index) {
    const row = document.createElement('div');
    row.className = 'glass rounded-2xl border border-white/5 p-4 flex items-center gap-4 overflow-hidden';
    const score = FAKE_SCORES[index % FAKE_SCORES.length];
    row.innerHTML = `
      <div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">
        <div class="flex items-center gap-2 mb-1"><h3 class="font-bold text-white">${escHtml(FAKE_NAMES[index%FAKE_NAMES.length])}</h3><span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${scoreColor(score)}">${score}</span></div>
        <p class="text-xs text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(FAKE_CITIES[index%FAKE_CITIES.length])} &nbsp;<i class="fa-solid fa-phone mr-1"></i>(514) 555-0${100+index}</p>
      </div>
      <span class="inline-flex items-center gap-1.5 text-xs bg-white/5 border border-white/10 text-slate-400 px-3 py-2 rounded-xl font-semibold shrink-0"><i class="fa-solid fa-lock text-[10px]"></i> Locked</span>`;
    return row;
  }

  // ─ History sidebar — ChatGPT-style ─────────────────────────────────────────
  function fillAndFocusSearch(city, industry, keywords) {
    document.getElementById('fieldCity').value     = city;
    document.getElementById('fieldIndustry').value = industry;
    document.getElementById('fieldKeywords').value = keywords || '';
    document.getElementById('leadSearchForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderHistoryItem(entry) {
    const item = document.createElement('div');
    item.className = 'group relative';

    const date = new Date(entry.created_at.replace(' ', 'T'));
    const label = date.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' });

    item.innerHTML = `
      <button type="button"
        class="w-full text-left flex flex-col gap-0.5 px-3 py-2.5 rounded-xl hover:bg-white/6 active:bg-white/10 transition-colors cursor-pointer"
        data-city="${escHtml(entry.city)}" data-industry="${escHtml(entry.industry)}" data-keywords="${escHtml(entry.keywords||'')}">
        <div class="flex items-center justify-between gap-2 w-full">
          <span class="text-sm font-semibold text-white truncate flex-1 leading-snug">${escHtml(entry.city)}</span>
          ${entry.result_count > 0 ? `<span class="text-[10px] font-bold text-slate-600 shrink-0 tabular-nums">${entry.result_count}</span>` : ''}
        </div>
        <span class="text-xs text-slate-500 truncate w-full leading-snug">${escHtml(entry.industry)}${entry.keywords ? ' &middot; ' + escHtml(entry.keywords) : ''}</span>
        <span class="text-[10px] text-slate-700 mt-0.5">${label}</span>
      </button>`;

    item.querySelector('button').addEventListener('click', function () {
      fillAndFocusSearch(this.dataset.city, this.dataset.industry, this.dataset.keywords);
    });
    return item;
  }

  function loadSearchHistory() {
    const list    = document.getElementById('searchHistoryList');
    const empty   = document.getElementById('searchHistoryEmpty');
    const countEl = document.getElementById('historyCount');
    if (!list) return;
    fetch('/api/lead-search-history.php')
      .then(r => r.json())
      .then(data => {
        list.innerHTML = '';
        const hasItems = data.history && data.history.length > 0;
        if (empty) empty.style.display = hasItems ? 'none' : '';
        if (countEl) {
          if (hasItems) { countEl.textContent = data.history.length; countEl.classList.remove('hidden'); }
          else { countEl.classList.add('hidden'); }
        }
        if (hasItems) data.history.forEach(e => list.appendChild(renderHistoryItem(e)));
      })
      .catch(() => {});
  }

  loadSearchHistory();

  // ─ Main search ────────────────────────────────────────────────────────────
  function runSearch(city, industry, keywords, leadCount, includeSeen, forceRefresh) {
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML  = '';
    lockedList.innerHTML = '';

    fetch('/api/find-leads.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ city, industry, keywords: keywords||null, lead_count: leadCount||10, include_seen: includeSeen, csrf_token: csrfToken, force_refresh: !!forceRefresh })
    })
    .then(r => r.json())
    .then(data => {
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');

      if (!data.success) {
        leadsList.innerHTML = `<div class="glass rounded-xl p-5 text-sm text-center ${data.rate_limited?'text-amber-400':'text-red-400'}"><i class="fa-solid fa-${data.rate_limited?'clock':'triangle-exclamation'} mr-2"></i>${escHtml(data.error||'Search failed.')}${data.resets_at?`<span class="block text-xs text-slate-400 mt-1">Resets at ${new Date(data.resets_at*1000).toLocaleTimeString()}</span>`:''}</div>`;
        lockedWrap.classList.add('hidden');
        return;
      }

      // Always sync both counters
      if (typeof data.pro_lead_count === 'number') syncLeadCounter(data.pro_lead_count, data.lead_limit || leadLimit);
      if (typeof data.searches_used  === 'number') updateLiveQuotaCounter(data.searches_used);

      const seenIdsBefore = getSeenIds();
      if (data.leads && data.leads.length) markSeen(data.leads.map(l => String(l.id)));

      if (data.from_cache) {
        const notice = document.createElement('div');
        notice.className = 'flex items-center justify-between gap-3 text-xs text-slate-400 mb-3 px-1';
        const cachedDate = data.cached_at ? new Date(data.cached_at.replace(' ','T')).toLocaleString() : '';
        notice.innerHTML = `<span><i class="fa-solid fa-clock-rotate-left mr-1"></i>Showing saved results from ${cachedDate}</span>`;
        const rb = document.createElement('button');
        rb.type = 'button'; rb.className = 'text-slate-300 hover:text-white font-semibold underline'; rb.textContent = 'Refresh';
        rb.addEventListener('click', () => runSearch(city, industry, keywords, leadCount, includeSeen, true));
        notice.appendChild(rb);
        leadsList.appendChild(notice);
      }

      if (data.leads && data.leads.length > 0) {
        const seenCount = data.leads.filter(l => seenIdsBefore.has(String(l.id))).length;
        const summary = document.createElement('div');
        summary.className = 'flex items-center justify-between text-xs text-slate-500 mb-2 px-1';
        summary.innerHTML = `<span><i class="fa-solid fa-list mr-1"></i><strong class="text-white">${data.leads.length}</strong> leads in <strong class="text-white">${escHtml(city)}</strong> &bull; <strong class="text-white">${escHtml(industry)}</strong>${keywords?` &bull; <em class="text-slate-400">${escHtml(keywords)}</em>`:''}</span>${seenCount>0?`<span class="text-slate-600">${seenCount} seen before</span>`:''}`;
        leadsList.appendChild(summary);
      }

      if (!data.leads || !data.leads.length) {
        const em = document.createElement('p');
        em.className = 'text-slate-400 text-center py-6';
        em.textContent = 'No leads found. Try a different city or industry.';
        leadsList.appendChild(em);
      } else {
        let toShow = data.leads;
        if (!includeSeen) {
          toShow = data.leads.filter(l => !seenIdsBefore.has(String(l.id)));
          if (!toShow.length) {
            const as = document.createElement('p');
            as.className = 'text-slate-400 text-center py-6 text-sm';
            as.innerHTML = '<i class="fa-solid fa-eye-slash mr-2"></i>All results were already seen. Check <em>Include already-seen leads</em> to show them.';
            leadsList.appendChild(as);
          }
        }
        toShow.forEach(lead => leadsList.appendChild(renderLeadRow(lead, seenIdsBefore)));
      }

      if (data.is_free_tier && data.locked_leads && data.locked_leads.length) {
        data.locked_leads.forEach((lead,i) => lockedList.appendChild(renderLockedRow(lead,i)));
        lockedWrap.classList.remove('hidden');
      } else {
        lockedWrap.classList.add('hidden');
      }

      loadSearchHistory();
    })
    .catch(() => {
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      leadsList.innerHTML = '<div class="glass rounded-xl p-5 text-red-400 text-sm text-center"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Something went wrong. Please try again.</div>';
      lockedWrap.classList.add('hidden');
    });
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    const city       = form.querySelector('[name="city"]').value.trim();
    const industry   = form.querySelector('[name="industry"]').value.trim();
    const keywords   = form.querySelector('[name="keywords"]')?.value.trim() || '';
    const leadCount  = parseInt(sliderHid?.value) || 10;
    const incSeen    = seenCb?.checked ?? false;
    if (!city || !industry) return;
    runSearch(city, industry, keywords, leadCount, incSeen, false);
  });

  // Auto-run from URL params
  const params = new URLSearchParams(window.location.search);
  if (params.get('autorun') === '1') {
    const city     = params.get('city')     || '';
    const industry = params.get('industry') || '';
    const keywords = params.get('keywords') || '';
    const count    = parseInt(params.get('count')) || 10;
    if (city && industry) {
      document.getElementById('fieldCity').value     = city;
      document.getElementById('fieldIndustry').value = industry;
      document.getElementById('fieldKeywords').value = keywords;
      runSearch(city, industry, keywords, count, false, false);
    }
  }
});
