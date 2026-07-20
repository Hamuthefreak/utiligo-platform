document.addEventListener('DOMContentLoaded', function () {
  const form        = document.getElementById('leadSearchForm');
  const resultsWrap = document.getElementById('leadsResultsWrap');
  const leadsList   = document.getElementById('leadsList');
  const lockedWrap  = document.getElementById('lockedWrap');
  const lockedList  = document.getElementById('lockedList');
  const loadingEl   = document.getElementById('leadsLoading');
  const csrfToken   = document.body.dataset.csrf;

  const cfg      = document.getElementById('leadsPageConfig');
  const PLAN     = cfg?.dataset.plan      ?? 'free';
  const IS_PAID  = PLAN === 'pro' || PLAN === 'entrepreneur';

  let leadUsed   = parseInt(cfg?.dataset.leadUsed  ?? 0);
  let leadLimit  = parseInt(cfg?.dataset.leadLimit ?? 0);
  let quotaUsed  = parseInt(cfg?.dataset.quotaUsed ?? 0);
  let quotaLimit = parseInt(cfg?.dataset.quotaLimit ?? 0);

  if (!form) return;

  // ── localStorage seen-leads ──────────────────────────────────────────────────
  const SEEN_KEY = 'utiligo_seen_leads_v1';
  function getSeenIds() {
    try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); }
    catch { return new Set(); }
  }
  function markSeen(ids) {
    try {
      const existing = getSeenIds();
      ids.forEach(id => existing.add(String(id)));
      const arr = [...existing];
      if (arr.length > 2000) arr.splice(0, arr.length - 2000);
      localStorage.setItem(SEEN_KEY, JSON.stringify(arr));
    } catch {}
  }
  function isSeenLead(id) { return getSeenIds().has(String(id)); }

  // ── Slider ───────────────────────────────────────────────────────────────────
  const slider      = document.getElementById('leadCountSlider');
  const sliderDisp  = document.getElementById('leadCountDisplay');
  const sliderHid   = document.getElementById('leadCountHidden');
  if (slider) {
    slider.addEventListener('input', () => {
      sliderDisp.textContent = slider.value;
      sliderHid.value = slider.value;
    });
  }

  const seenCb = document.getElementById('includeSeenLeads');

  // ── Live Lead Unlocks counter (Pro only) ─────────────────────────────────────
  function updateLiveLeadCounter(newUnlocked) {
    leadUsed += newUnlocked;
    const bar        = document.getElementById('leadLimitBar');
    const subtitle   = document.getElementById('leadLimitSubtitle');
    const countEl    = document.getElementById('leadLimitCount');
    const noteEl     = document.getElementById('leadLimitNote');
    const upgradeBtn = document.getElementById('leadUpgradeBtn');
    if (!bar || leadLimit <= 0) return;

    const pct = Math.min(100, Math.round((leadUsed / leadLimit) * 100));
    bar.style.width = pct + '%';

    // className assignment avoids classList slash-class mismatch with Tailwind
    bar.className = 'h-2 rounded-full transition-all ' +
      (pct >= 100 ? 'bg-red-500' : pct >= 80 ? 'bg-amber-500' : 'bg-white/60');

    if (subtitle) subtitle.textContent = leadUsed + ' of ' + leadLimit + ' used';
    if (countEl)  countEl.textContent  = leadUsed + ' / ' + leadLimit;
    if (noteEl)   noteEl.textContent   = Math.max(0, leadLimit - leadUsed) + ' remaining';

    if (pct >= 80 && upgradeBtn) upgradeBtn.classList.remove('hidden');
  }

  // ── Live Quota counter (Free only) ──────────────────────────────────────────
  function updateLiveQuotaCounter(newUsed) {
    quotaUsed = newUsed;
    const badge = document.getElementById('quotaBadge');
    const bar   = document.getElementById('quotaBar');
    const text  = document.getElementById('quotaText');
    const rem   = Math.max(0, quotaLimit - quotaUsed);
    const pct   = quotaLimit > 0 ? Math.min(100, Math.round((quotaUsed / quotaLimit) * 100)) : 0;
    if (badge) {
      badge.className = 'flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ' +
        (rem === 0
          ? 'bg-red-500/10 border border-red-500/20 text-red-400'
          : rem === 1
            ? 'bg-amber-500/10 border border-amber-500/20 text-amber-400'
            : 'bg-white/8 border border-white/10 text-slate-300');
      badge.textContent = rem === 0 ? 'No searches left today' : rem + ' search' + (rem !== 1 ? 'es' : '') + ' left';
    }
    if (bar) {
      bar.style.width = pct + '%';
      bar.className = 'h-2 rounded-full transition-all duration-500 ' +
        (pct >= 100 ? 'bg-red-500' : pct >= 50 ? 'bg-amber-500' : 'bg-white/60');
    }
    if (text) text.textContent = quotaUsed + ' of ' + quotaLimit + ' searches used';
  }

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function scoreColor(s) {
    return s >= 80 ? 'bg-white/15 text-white' : s >= 60 ? 'bg-amber-500/20 text-amber-400' : 'bg-red-500/20 text-red-400';
  }
  function scorLabel(s) { return s >= 80 ? 'High' : s >= 60 ? 'Med' : 'Low'; }

  function renderLeadRow(lead, seenIds) {
    const row = document.createElement('div');
    const wasSeen = seenIds.has(String(lead.id));
    row.className = 'glass rounded-2xl border transition-all p-4 flex flex-col sm:flex-row sm:items-center gap-4'
      + (wasSeen ? ' border-white/8 opacity-75' : ' border-white/5 hover:border-white/15');
    row.dataset.leadId = lead.id;
    const sc = scoreColor(lead.opportunity_score);
    const hasRating = lead.rating && parseFloat(lead.rating) > 0;
    const stars = hasRating
      ? '\u2605'.repeat(Math.round(parseFloat(lead.rating))) + '\u2606'.repeat(5 - Math.round(parseFloat(lead.rating)))
      : '';
    row.innerHTML = `
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap mb-1">
          <h3 class="font-bold text-white text-base">${escHtml(lead.business_name)}</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${sc}">${scorLabel(lead.opportunity_score)} &middot; ${lead.opportunity_score}</span>
          ${lead.no_website ? '<span class="text-[10px] px-2 py-0.5 rounded-full bg-white/8 text-slate-400 font-semibold">No Website</span>' : ''}
          ${wasSeen ? '<span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-700/60 text-slate-400 font-semibold">Seen</span>' : ''}
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400 mt-1">
          <span><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(lead.business_address || 'Address unavailable')}</span>
          ${lead.business_phone
            ? `<span class="text-slate-300"><i class="fa-solid fa-phone mr-1"></i>${escHtml(lead.business_phone)}</span>`
            : `<span class="text-slate-600"><i class="fa-solid fa-phone mr-1"></i>No phone</span>`}
          ${hasRating ? `<span class="text-amber-400" title="${lead.rating} stars">${stars} <span class="text-slate-400">${lead.rating}</span></span>` : ''}
          ${lead.total_ratings ? `<span class="text-slate-500">${lead.total_ratings} reviews</span>` : ''}
        </div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <a href="/portal/generate.php?lead_id=${encodeURIComponent(lead.id)}"
           class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-200 active:scale-95 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all">
          <i class="fa-solid fa-bolt text-[10px]"></i> Build Site
        </a>
      </div>
    `;
    return row;
  }

  const FAKE_NAMES  = ['Montreal Plumbing Co.','Apex Roofing Services','Bella Vista Restaurant','ProClean Janitorial','City Electrical Works','Green Thumb Landscaping','Maple Auto Repair','Studio 514 Hair Salon'];
  const FAKE_CITIES = ['Montreal, QC','Laval, QC','Longueuil, QC','Brossard, QC'];
  const FAKE_SCORES = [72, 81, 68, 90, 77, 85, 63, 79];

  function renderLockedRow(lead, index) {
    const row = document.createElement('div');
    row.className = 'glass rounded-2xl border border-white/5 p-4 flex items-center gap-4 overflow-hidden';
    row.dataset.leadId = lead.id || ('locked-' + index);
    const score = FAKE_SCORES[index % FAKE_SCORES.length];
    row.innerHTML = `
      <div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-bold text-white">${escHtml(FAKE_NAMES[index % FAKE_NAMES.length])}</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${scoreColor(score)}">${score}</span>
        </div>
        <p class="text-xs text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(FAKE_CITIES[index % FAKE_CITIES.length])} &nbsp;<i class="fa-solid fa-phone mr-1"></i>(514) 555-0${100 + index}</p>
      </div>
      <span class="inline-flex items-center gap-1.5 text-xs bg-white/5 border border-white/10 text-slate-400 px-3 py-2 rounded-xl font-semibold shrink-0">
        <i class="fa-solid fa-lock text-[10px]"></i> Locked
      </span>
    `;
    return row;
  }

  // ── Main search ───────────────────────────────────────────────────────────────
  function runSearch(city, industry, keywords, leadCount, includeSeen, forceRefresh) {
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML  = '';
    lockedList.innerHTML = '';

    fetch('/api/find-leads.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        city, industry,
        keywords:      keywords || null,
        lead_count:    leadCount || 10,
        include_seen:  includeSeen,
        csrf_token:    csrfToken,
        force_refresh: !!forceRefresh
      })
    })
    .then(r => r.json())
    .then(data => {
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');

      if (!data.success) {
        leadsList.innerHTML = `<div class="glass rounded-xl p-5 text-sm text-center ${data.rate_limited ? 'text-amber-400' : 'text-red-400'}">
          <i class="fa-solid fa-${data.rate_limited ? 'clock' : 'triangle-exclamation'} mr-2"></i>
          ${escHtml(data.error || 'Search failed.')}
          ${data.resets_at ? `<span class="block text-xs text-slate-400 mt-1">Resets at ${new Date(data.resets_at * 1000).toLocaleTimeString()}</span>` : ''}
        </div>`;
        lockedWrap.classList.add('hidden');
        return;
      }

      if (typeof data.newly_unlocked === 'number' && data.newly_unlocked > 0) updateLiveLeadCounter(data.newly_unlocked);
      if (typeof data.searches_used === 'number') updateLiveQuotaCounter(data.searches_used);

      const seenIds = getSeenIds();
      if (data.leads && data.leads.length) markSeen(data.leads.map(l => l.id));

      if (data.from_cache) {
        const notice = document.createElement('div');
        notice.className = 'flex items-center justify-between gap-3 text-xs text-slate-400 mb-3 px-1';
        const cachedDate = data.cached_at ? new Date(data.cached_at.replace(' ', 'T')).toLocaleString() : '';
        notice.innerHTML = `<span><i class="fa-solid fa-clock-rotate-left mr-1"></i>Showing saved results from ${cachedDate}</span>`;
        const refreshBtn = document.createElement('button');
        refreshBtn.type = 'button';
        refreshBtn.className = 'text-slate-300 hover:text-white font-semibold underline';
        refreshBtn.textContent = 'Refresh';
        refreshBtn.addEventListener('click', () => runSearch(city, industry, keywords, leadCount, includeSeen, true));
        notice.appendChild(refreshBtn);
        leadsList.appendChild(notice);
      }

      if (data.leads && data.leads.length > 0) {
        const currentSeenIds = getSeenIds();
        const seenCount = data.leads.filter(l => currentSeenIds.has(String(l.id))).length;
        const summary = document.createElement('div');
        summary.className = 'flex items-center justify-between text-xs text-slate-500 mb-2 px-1';
        summary.innerHTML = `
          <span><i class="fa-solid fa-list mr-1"></i>
            <strong class="text-white">${data.leads.length}</strong> leads in
            <strong class="text-white">${escHtml(city)}</strong> &bull;
            <strong class="text-white">${escHtml(industry)}</strong>
            ${keywords ? `&bull; <em class="text-slate-400">${escHtml(keywords)}</em>` : ''}
          </span>
          ${seenCount > 0 ? `<span class="text-slate-600">${seenCount} seen before</span>` : ''}
        `;
        leadsList.appendChild(summary);
      }

      if (!data.leads || !data.leads.length) {
        const empty = document.createElement('p');
        empty.className = 'text-slate-400 text-center py-6';
        empty.textContent = 'No leads found. Try a different city or industry.';
        leadsList.appendChild(empty);
      } else {
        const currentSeenIds = getSeenIds();
        let toShow = data.leads;
        if (!includeSeen) {
          toShow = data.leads.filter(l => !currentSeenIds.has(String(l.id)));
          if (toShow.length === 0) {
            const allSeen = document.createElement('p');
            allSeen.className = 'text-slate-400 text-center py-6 text-sm';
            allSeen.innerHTML = '<i class="fa-solid fa-eye-slash mr-2"></i>All results were already seen. Check <em>Include already-seen leads</em> to show them.';
            leadsList.appendChild(allSeen);
          }
        }
        toShow.forEach(lead => leadsList.appendChild(renderLeadRow(lead, currentSeenIds)));
      }

      if (data.is_free_tier && data.locked_leads && data.locked_leads.length) {
        data.locked_leads.forEach((lead, i) => lockedList.appendChild(renderLockedRow(lead, i)));
        lockedWrap.classList.remove('hidden');
      } else {
        lockedWrap.classList.add('hidden');
      }
    })
    .catch(() => {
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      leadsList.innerHTML = '<div class="glass rounded-xl p-5 text-red-400 text-sm text-center"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Something went wrong. Please try again.</div>';
      lockedWrap.classList.add('hidden');
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const city       = form.querySelector('[name="city"]').value.trim();
    const industry   = form.querySelector('[name="industry"]').value.trim();
    const keywords   = form.querySelector('[name="keywords"]')?.value.trim() || '';
    const leadCount  = parseInt(sliderHid?.value) || 10;
    const includeSeen = seenCb?.checked ?? false;
    if (!city || !industry) return;
    runSearch(city, industry, keywords, leadCount, includeSeen, false);
  });

  // Auto-run from URL params
  const params = new URLSearchParams(window.location.search);
  if (params.get('autorun') === '1') {
    const city      = params.get('city') || '';
    const industry  = params.get('industry') || '';
    const keywords  = params.get('keywords') || '';
    const leadCount = parseInt(params.get('count')) || 10;
    if (city && industry) {
      form.querySelector('[name="city"]').value = city;
      form.querySelector('[name="industry"]').value = industry;
      if (form.querySelector('[name="keywords"]')) form.querySelector('[name="keywords"]').value = keywords;
      runSearch(city, industry, keywords, leadCount, false, false);
    }
  }
});
