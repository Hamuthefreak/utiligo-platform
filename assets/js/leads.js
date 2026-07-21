document.addEventListener('DOMContentLoaded', function () {
  const form        = document.getElementById('leadSearchForm');
  const resultsWrap = document.getElementById('leadsResultsWrap');
  const leadsList   = document.getElementById('leadsList');
  const lockedWrap  = document.getElementById('lockedWrap');
  const lockedList  = document.getElementById('lockedList');
  const loadingEl   = document.getElementById('leadsLoading');
  const csrfToken   = document.body.dataset.csrf;
  const statusChip  = document.getElementById('searchStatusChip');
  const searchBtn   = document.getElementById('searchBtn');
  const searchBtnLbl = document.getElementById('searchBtnLabel');

  const cfg     = document.getElementById('leadsPageConfig');
  const PLAN    = cfg?.dataset.plan    ?? 'free';
  const IS_PAID = PLAN === 'pro' || PLAN === 'entrepreneur';

  let leadUsed   = parseInt(cfg?.dataset.leadUsed  ?? 0);
  let leadLimit  = parseInt(cfg?.dataset.leadLimit ?? 0);
  let quotaUsed  = parseInt(cfg?.dataset.quotaUsed  ?? 0);
  let quotaLimit = parseInt(cfg?.dataset.quotaLimit ?? 0);

  if (!form) return;

  // ─ localStorage seen-leads ──────────────────────────────────────────────────────
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

  // ─ Slider ──────────────────────────────────────────────────────────────────
  const slider     = document.getElementById('leadCountSlider');
  const sliderDisp = document.getElementById('leadCountDisplay');
  const sliderHid  = document.getElementById('leadCountHidden');
  if (slider) slider.addEventListener('input', () => { sliderDisp.textContent = slider.value; sliderHid.value = slider.value; });

  const seenCb = document.getElementById('includeSeenLeads');

  // ─ Live pro lead-unlock bar ──────────────────────────────────────────────
  function syncLeadCounter(serverCount, serverLimit) {
    if (typeof serverCount === 'number' && serverCount >= 0) leadUsed  = serverCount;
    if (typeof serverLimit === 'number' && serverLimit  > 0) leadLimit = serverLimit;
    const bar      = document.getElementById('leadLimitBar');
    const subtitle = document.getElementById('leadLimitSubtitle');
    const noteEl   = document.getElementById('leadLimitNote');
    const upgBtn   = document.getElementById('leadUpgradeBtn');
    if (!bar || leadLimit <= 0) return;
    const pct = Math.min(100, Math.round((leadUsed / leadLimit) * 100));
    bar.style.width = pct + '%';
    bar.className   = 'h-full transition-all ' + (pct >= 100 ? 'bg-red-500' : pct >= 80 ? 'bg-amber-400' : 'bg-emerald-500');
    if (subtitle) subtitle.textContent = leadUsed + ' of ' + leadLimit + ' used';
    if (noteEl)   noteEl.textContent   = Math.max(0, leadLimit - leadUsed) + ' remaining';
    if (upgBtn) { pct >= 80 ? upgBtn.classList.remove('hidden') : upgBtn.classList.add('hidden'); }
  }

  // ─ Live free quota bar ─────────────────────────────────────────────────
  function updateLiveQuotaCounter(newUsed) {
    quotaUsed = newUsed;
    const badge = document.getElementById('quotaBadge');
    const bar   = document.getElementById('quotaBar');
    const text  = document.getElementById('quotaText');
    const rem   = Math.max(0, quotaLimit - quotaUsed);
    const pct   = quotaLimit > 0 ? Math.min(100, Math.round((quotaUsed / quotaLimit) * 100)) : 0;
    if (badge) {
      badge.className = 'text-xs font-bold px-2.5 py-1 rounded border ' +
        (rem===0?'bg-red-500/10 border-red-500/20 text-red-400':rem===1?'bg-amber-500/10 border-amber-500/20 text-amber-400':'bg-white/5 border-white/8 text-slate-300');
      badge.textContent = rem===0?'No searches left':rem+' search'+(rem!==1?'es':'')+' left';
    }
    if (bar) { bar.style.width=pct+'%'; bar.className='h-full transition-all duration-500 '+(pct>=100?'bg-red-500':pct>=50?'bg-amber-400':'bg-emerald-500'); }
    if (text) text.textContent = quotaUsed+' of '+quotaLimit+' used';
  }

  // ─ Helpers ─────────────────────────────────────────────────────────────────
  function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function scoreColor(s) { return s>=80?'bg-white/10 text-white':s>=60?'bg-amber-500/20 text-amber-400':'bg-red-500/20 text-red-400'; }
  function scorLabel(s)  { return s>=80?'High':s>=60?'Med':'Low'; }

  // Exact timestamp: "Today at 3:42 PM", "Yesterday at 9:00 AM", or "Jul 14 at 2:30 PM"
  function fmtTimestamp(dateStr) {
    const d    = new Date(dateStr.replace(' ', 'T'));
    const now  = new Date();
    const sod  = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yest = new Date(sod - 86400000);
    const time = d.toLocaleTimeString('en-CA', { hour: 'numeric', minute: '2-digit', hour12: true });
    if (d >= sod)  return 'Today at '     + time;
    if (d >= yest) return 'Yesterday at ' + time;
    return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' }) + ' at ' + time;
  }

  // Relative time: "2 seconds ago", "just now", etc.
  function fmtRelative(ms) {
    const s = Math.floor(ms / 1000);
    if (s < 5)  return 'just now';
    if (s < 60) return s + ' seconds ago';
    const m = Math.floor(s / 60);
    if (m < 60) return m + ' minute' + (m!==1?'s':'') + ' ago';
    return 'a while ago';
  }

  // Copy to clipboard helper
  function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-check text-[10px]"></i>';
      btn.classList.add('text-emerald-400');
      setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('text-emerald-400'); }, 1500);
    }).catch(() => {});
  }

  // ─ Lead card ──────────────────────────────────────────────────────────────────
  function renderLeadRow(lead, seenIdsBefore, idx) {
    const row     = document.createElement('div');
    const wasSeen = seenIdsBefore.has(String(lead.id));
    row.className = 'lead-card-enter border bg-white/[.02] rounded-lg p-4 transition-all hover:bg-white/[.04] ' +
                    (wasSeen ? 'border-white/5 opacity-70' : 'border-white/8 hover:border-white/15');
    row.style.animationDelay = (idx * 40) + 'ms';
    row.dataset.leadId = lead.id;

    const sc = scoreColor(lead.opportunity_score);
    const hasRating = lead.rating && parseFloat(lead.rating) > 0;
    const ratingStars = hasRating
      ? '★'.repeat(Math.round(parseFloat(lead.rating))) + '☆'.repeat(5 - Math.round(parseFloat(lead.rating)))
      : '';

    const generateUrl = '/portal/generate?lead_id=' + encodeURIComponent(lead.id)
      + '&name='     + encodeURIComponent(lead.business_name     || '')
      + '&category=' + encodeURIComponent(lead.business_category || '')
      + '&city='     + encodeURIComponent(lead.business_city     || '')
      + '&phone='    + encodeURIComponent(lead.business_phone    || '');

    const phoneLine = lead.business_phone
      ? `<span class="inline-flex items-center gap-1.5 text-xs bg-white/5 border border-white/8 text-slate-300 px-2.5 py-1.5 rounded font-medium">
           <i class="fa-solid fa-phone text-[10px] text-slate-500"></i>${escHtml(lead.business_phone)}
           <button type="button" class="copy-phone ml-1 text-slate-600 hover:text-white transition" data-phone="${escHtml(lead.business_phone)}" title="Copy phone">
             <i class="fa-regular fa-copy text-[10px]"></i>
           </button>
         </span>`
      : `<span class="inline-flex items-center gap-1.5 text-xs text-slate-700 px-2.5 py-1.5"><i class="fa-solid fa-phone text-[10px]"></i>No phone</span>`;

    const mapsLine = lead.maps_url
      ? `<a href="${escHtml(lead.maps_url)}" target="_blank" rel="noopener"
            class="inline-flex items-center gap-1.5 text-xs bg-white/5 border border-white/8 text-slate-300 hover:bg-white/10 px-2.5 py-1.5 rounded font-medium transition">
           <i class="fa-brands fa-google text-[10px] text-slate-500"></i>Maps
         </a>`
      : '';

    row.innerHTML = `
      <div class="flex flex-col sm:flex-row sm:items-start gap-3">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1.5">
            <h3 class="font-bold text-white text-sm leading-tight">${escHtml(lead.business_name)}</h3>
            <span class="text-[10px] px-1.5 py-0.5 rounded font-bold ${sc}">${scorLabel(lead.opportunity_score)} · ${lead.opportunity_score}</span>
            ${lead.no_website ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-white/6 border border-white/8 text-slate-400 font-semibold">No Website</span>' : ''}
            ${wasSeen ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-500 font-semibold">Seen</span>' : ''}
          </div>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
            <span><i class="fa-solid fa-location-dot mr-1 text-slate-600"></i>${escHtml(lead.business_address || 'Address unavailable')}</span>
            ${hasRating ? `<span class="text-amber-500/80 text-[11px]" title="${lead.rating} stars">${ratingStars} <span class="text-slate-500">${lead.rating}</span></span>` : ''}
            ${lead.total_ratings ? `<span class="text-slate-600">${lead.total_ratings} reviews</span>` : ''}
            ${lead.business_category ? `<span class="text-slate-600"><i class="fa-solid fa-tag mr-1"></i>${escHtml(lead.business_category)}</span>` : ''}
          </div>
        </div>
        <a href="${generateUrl}"
           class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-100 active:scale-95 text-black px-4 py-2 rounded-md font-bold whitespace-nowrap transition-all shrink-0">
          <i class="fa-solid fa-bolt text-[10px]"></i> Build Site
        </a>
      </div>
      <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-white/5">
        ${phoneLine}${mapsLine}
      </div>`;

    // Copy phone handler
    row.querySelectorAll('.copy-phone').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        copyToClipboard(btn.dataset.phone, btn);
      });
    });

    return row;
  }

  const FAKE_NAMES  = ['Montreal Plumbing Co.','Apex Roofing Services','Bella Vista Restaurant','ProClean Janitorial','City Electrical Works','Green Thumb Landscaping','Maple Auto Repair','Studio 514 Hair Salon'];
  const FAKE_CITIES = ['Montreal, QC','Laval, QC','Longueuil, QC','Brossard, QC'];
  const FAKE_SCORES = [72,81,68,90,77,85,63,79];

  function renderLockedRow(lead, index) {
    const row = document.createElement('div');
    row.className = 'lead-card-enter border border-white/5 bg-white/[.02] rounded-lg p-4 flex items-center gap-4 overflow-hidden';
    row.style.animationDelay = (index * 40) + 'ms';
    const score = FAKE_SCORES[index % FAKE_SCORES.length];
    row.innerHTML = `
      <div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">
        <div class="flex items-center gap-2 mb-1"><h3 class="font-bold text-white text-sm">${escHtml(FAKE_NAMES[index%FAKE_NAMES.length])}</h3>
        <span class="text-[10px] px-1.5 py-0.5 rounded font-bold ${scoreColor(score)}">${score}</span></div>
        <p class="text-xs text-slate-500"><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(FAKE_CITIES[index%FAKE_CITIES.length])}</p>
      </div>
      <span class="inline-flex items-center gap-1.5 text-xs border border-white/8 text-slate-500 px-3 py-1.5 rounded font-semibold shrink-0">
        <i class="fa-solid fa-lock text-[10px]"></i> Locked
      </span>`;
    return row;
  }

  // ─ History sidebar ────────────────────────────────────────────────────────────
  function fillAndFocusSearch(city, industry, keywords) {
    document.getElementById('fieldCity').value     = city;
    document.getElementById('fieldIndustry').value = industry;
    document.getElementById('fieldKeywords').value = keywords || '';
    document.getElementById('leadSearchForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderHistoryItem(entry) {
    const item = document.createElement('div');
    item.className = 'group';
    const ts = fmtTimestamp(entry.created_at);

    item.innerHTML = `
      <button type="button"
        class="w-full text-left flex flex-col gap-0.5 px-3 py-2.5 rounded hover:bg-white/5 active:bg-white/8 transition-colors cursor-pointer"
        data-city="${escHtml(entry.city)}" data-industry="${escHtml(entry.industry)}" data-keywords="${escHtml(entry.keywords||'')}">
        <div class="flex items-center justify-between gap-2 w-full">
          <span class="text-xs font-semibold text-white truncate flex-1 leading-snug">${escHtml(entry.city)}</span>
          ${entry.result_count > 0 ? `<span class="text-[10px] font-bold text-slate-600 shrink-0 tabular-nums">${entry.result_count}</span>` : ''}
        </div>
        <span class="text-[11px] text-slate-500 truncate w-full">${escHtml(entry.industry)}${entry.keywords ? ' · ' + escHtml(entry.keywords) : ''}</span>
        <span class="text-[10px] text-slate-700 mt-0.5">${ts}</span>
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

  // ─ Search state ────────────────────────────────────────────────────────────
  function setSearching(on) {
    if (!searchBtn || !searchBtnLbl) return;
    if (on) {
      searchBtn.disabled = true;
      searchBtnLbl.textContent = 'Searching…';
      searchBtn.classList.add('opacity-60', 'cursor-not-allowed');
    } else {
      searchBtn.disabled = false;
      searchBtnLbl.textContent = 'Find Leads';
      searchBtn.classList.remove('opacity-60', 'cursor-not-allowed');
    }
  }

  // ─ Main search ──────────────────────────────────────────────────────────────
  function runSearch(city, industry, keywords, leadCount, includeSeen, forceRefresh) {
    const searchStart = Date.now();
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML  = '';
    lockedList.innerHTML = '';
    if (statusChip) statusChip.classList.add('hidden');
    setSearching(true);

    fetch('/api/find-leads.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ city, industry, keywords: keywords||null, lead_count: leadCount||10, include_seen: includeSeen, csrf_token: csrfToken, force_refresh: !!forceRefresh })
    })
    .then(r => r.json())
    .then(data => {
      const elapsed = Date.now() - searchStart;
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      setSearching(false);

      if (!data.success) {
        leadsList.innerHTML = `<div class="border border-white/8 bg-white/[.02] rounded-lg p-5 text-sm text-center ${data.rate_limited?'text-amber-400':'text-red-400'}">
          <i class="fa-solid fa-${data.rate_limited?'clock':'triangle-exclamation'} mr-2"></i>${escHtml(data.error||'Search failed.')}
          ${data.resets_at ? `<span class="block text-xs text-slate-500 mt-1">Resets at <strong>${new Date(data.resets_at*1000).toLocaleTimeString('en-CA',{hour:'numeric',minute:'2-digit',hour12:true})}</strong></span>` : ''}
        </div>`;
        lockedWrap.classList.add('hidden');
        return;
      }

      if (typeof data.pro_lead_count === 'number') syncLeadCounter(data.pro_lead_count, data.lead_limit || leadLimit);
      if (typeof data.searches_used  === 'number') updateLiveQuotaCounter(data.searches_used);

      const seenIdsBefore = getSeenIds();
      if (data.leads && data.leads.length) markSeen(data.leads.map(l => String(l.id)));

      // Results header
      const header = document.createElement('div');
      header.className = 'flex items-center justify-between text-xs text-slate-500 mb-3 px-0.5';
      const leadCount2 = data.leads?.length || 0;
      const seenCount  = (data.leads||[]).filter(l => seenIdsBefore.has(String(l.id))).length;
      const secStr     = (elapsed / 1000).toFixed(1);

      let leftHtml = `<span><strong class="text-white">${leadCount2}</strong> leads`;
      if (data.from_cache) leftHtml += ' <span class="text-slate-700">(cached)</span>';
      leftHtml += ` &middot; <em class="not-italic text-slate-400">${escHtml(city)}, ${escHtml(industry)}</em>`;
      leftHtml += ` &middot; <span class="text-slate-600">${secStr}s</span></span>`;

      let rightHtml = '';
      if (seenCount > 0) rightHtml += `<span class="text-slate-600">${seenCount} seen</span>`;
      if (data.from_cache) {
        const cachedTs = data.cached_at ? fmtTimestamp(data.cached_at) : '';
        rightHtml += `<span class="text-slate-700 ml-2">cached ${cachedTs}</span>
          <button type="button" id="refreshBtn" class="ml-2 text-slate-400 hover:text-white font-semibold text-[11px] underline">Refresh</button>`;
      }
      header.innerHTML = `<div>${leftHtml}</div><div class="flex items-center gap-1">${rightHtml}</div>`;
      leadsList.appendChild(header);

      if (data.from_cache) {
        const rb = leadsList.querySelector('#refreshBtn');
        if (rb) rb.addEventListener('click', () => runSearch(city, industry, keywords, leadCount, includeSeen, true));
      }

      // Show status chip in form header
      if (statusChip) {
        statusChip.classList.remove('hidden');
        statusChip.innerHTML = `<i class="fa-solid fa-circle-check mr-1"></i>${leadCount2} results · ${secStr}s`;
      }

      if (!data.leads || !data.leads.length) {
        const em = document.createElement('p');
        em.className = 'text-slate-500 text-center py-8 text-sm';
        em.textContent = 'No leads found. Try a different city or industry.';
        leadsList.appendChild(em);
      } else {
        let toShow = data.leads;
        if (!includeSeen) {
          toShow = data.leads.filter(l => !seenIdsBefore.has(String(l.id)));
          if (!toShow.length) {
            const as = document.createElement('p');
            as.className = 'text-slate-500 text-center py-8 text-sm';
            as.innerHTML = '<i class="fa-solid fa-eye-slash mr-2"></i>All results were already seen. Toggle <em>Include seen leads</em>.';
            leadsList.appendChild(as);
          }
        }
        toShow.forEach((lead, i) => leadsList.appendChild(renderLeadRow(lead, seenIdsBefore, i)));
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
      setSearching(false);
      leadsList.innerHTML = '<div class="border border-white/8 bg-white/[.02] rounded-lg p-5 text-red-400 text-sm text-center"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Something went wrong. Please try again.</div>';
      lockedWrap.classList.add('hidden');
    });
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    const city      = form.querySelector('[name="city"]').value.trim();
    const industry  = form.querySelector('[name="industry"]').value.trim();
    const keywords  = form.querySelector('[name="keywords"]')?.value.trim() || '';
    const leadCount = parseInt(sliderHid?.value) || 10;
    const incSeen   = seenCb?.checked ?? false;
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
