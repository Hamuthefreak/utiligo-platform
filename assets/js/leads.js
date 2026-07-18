document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('leadSearchForm');
  const resultsWrap = document.getElementById('leadsResultsWrap');
  const leadsList = document.getElementById('leadsList');
  const lockedWrap = document.getElementById('lockedWrap');
  const lockedList = document.getElementById('lockedList');
  const loadingEl = document.getElementById('leadsLoading');
  const csrfToken = document.body.dataset.csrf;

  if (!form) return;

  const FAKE_NAMES = [
    'Montreal Plumbing Co.', 'Apex Roofing Services', 'Bella Vista Restaurant',
    'ProClean Janitorial', 'City Electrical Works', 'Green Thumb Landscaping',
    'Maple Auto Repair', 'Studio 514 Hair Salon', 'North Star Painting',
    'FastFix HVAC', 'Royal Touch Flooring', 'Sunrise Bakery & Cafe',
    'Diamond Tile & Stone', 'Peak Performance Gym', 'Comfort Home Renovation'
  ];
  const FAKE_CITIES = [
    'Montreal, QC', 'Laval, QC', 'Longueuil, QC', 'Brossard, QC',
    'Saint-Laurent, QC', 'Verdun, QC', 'Anjou, QC', 'LaSalle, QC'
  ];
  const FAKE_SCORES = [72, 81, 68, 90, 77, 85, 63, 79, 88, 74];

  function fakeName(i)  { return FAKE_NAMES[i  % FAKE_NAMES.length]; }
  function fakeCity(i)  { return FAKE_CITIES[i  % FAKE_CITIES.length]; }
  function fakeScore(i) { return FAKE_SCORES[i  % FAKE_SCORES.length]; }

  function scoreColor(score) {
    if (score >= 80) return 'bg-emerald-500/20 text-emerald-400';
    if (score >= 60) return 'bg-amber-500/20 text-amber-400';
    return 'bg-red-500/20 text-red-400';
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function renderLeadRow(lead) {
    const row = document.createElement('div');
    row.className = 'glass rounded-xl p-4 flex items-center justify-between gap-4 flex-wrap';
    const sc = scoreColor(lead.opportunity_score);
    row.innerHTML = `
      <div class="flex-1 min-w-[200px]">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-semibold text-white">${escHtml(lead.business_name)}</h3>
          <span class="text-xs px-2 py-0.5 rounded-full ${sc} font-semibold">${lead.opportunity_score}</span>
        </div>
        <p class="text-sm text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(lead.business_address || '')}</p>
      </div>
      <div class="text-sm text-slate-300 min-w-[160px]">
        ${lead.business_phone
          ? `<i class="fa-solid fa-phone mr-1 text-emerald-500"></i>${escHtml(lead.business_phone)}`
          : `<i class="fa-solid fa-phone mr-1 text-slate-600"></i><span class="text-slate-500">No phone found</span>`
        }
      </div>
      <a href="/portal/generate.php?lead_id=${encodeURIComponent(lead.id)}"
         class="text-xs bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-4 py-2 rounded-full font-semibold whitespace-nowrap">
        <i class="fa-solid fa-bolt mr-1"></i>Generate Website
      </a>
    `;
    return row;
  }

  function renderLockedRow(lead, index) {
    const row = document.createElement('div');
    row.className = 'relative glass rounded-xl p-4 flex items-center justify-between gap-4 flex-wrap overflow-hidden';
    const score = fakeScore(index);
    const sc    = scoreColor(score);
    // Everything is fully blurred — name, address, phone all unreadable
    row.innerHTML = `
      <div class="flex-1 min-w-[200px]">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-semibold text-white blur-sm select-none pointer-events-none">${escHtml(fakeName(index))}</h3>
          <span class="text-xs px-2 py-0.5 rounded-full ${sc} font-semibold blur-sm select-none">${score}</span>
        </div>
        <p class="text-sm text-slate-400 blur-sm select-none pointer-events-none">
          <i class="fa-solid fa-location-dot mr-1"></i>${escHtml(fakeCity(index))}
        </p>
      </div>
      <div class="text-sm text-slate-300 min-w-[160px] blur-sm select-none pointer-events-none">
        <i class="fa-solid fa-phone mr-1 text-emerald-500"></i>(${514 + index}) 555-0${100 + index}
      </div>
      <div class="text-xs bg-amber-500/10 border border-amber-500/20 text-amber-400 px-4 py-2 rounded-full font-semibold whitespace-nowrap">
        <i class="fa-solid fa-lock mr-1"></i>Pro Only
      </div>
    `;
    return row;
  }

  function runSearch(city, industry, forceRefresh) {
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');

    fetch('/api/find-leads.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ city, industry, csrf_token: csrfToken, force_refresh: !!forceRefresh }),
    })
      .then((r) => r.json())
      .then((data) => {
        loadingEl.classList.add('hidden');
        resultsWrap.classList.remove('hidden');
        leadsList.innerHTML = '';
        lockedList.innerHTML = '';

        if (!data.success) {
          leadsList.innerHTML = `<div class="glass rounded-xl p-5 text-sm text-center ${
            data.rate_limited ? 'text-amber-400' : 'text-red-400'
          }">
            <i class="fa-solid fa-${ data.rate_limited ? 'clock' : 'triangle-exclamation' } mr-2"></i>
            ${escHtml(data.error || 'Search failed.')}
            ${ data.resets_at ? `<span class="block text-xs text-slate-400 mt-1">Your searches reset at ${new Date(data.resets_at * 1000).toLocaleTimeString()}</span>` : '' }
          </div>`;
          lockedWrap.classList.add('hidden');
          return;
        }

        // Show remaining searches for free tier
        if (data.is_free_tier && typeof data.searches_remaining !== 'undefined') {
          const badge = document.createElement('div');
          badge.className = 'flex items-center justify-between gap-3 text-xs mb-3 px-1';
          const left = data.searches_remaining;
          badge.innerHTML = `
            <span class="${ left === 0 ? 'text-red-400' : left === 1 ? 'text-amber-400' : 'text-slate-400' }">
              <i class="fa-solid fa-search mr-1"></i>
              <strong>${left}</strong> free search${left !== 1 ? 'es' : ''} remaining today
            </span>
            <a href="/portal/billing.php?upgrade=1" class="text-emerald-400 hover:text-emerald-300 font-semibold">Get unlimited &rarr;</a>
          `;
          leadsList.appendChild(badge);
        }

        if (data.from_cache) {
          const cacheNotice = document.createElement('div');
          cacheNotice.className = 'flex items-center justify-between gap-3 text-xs text-slate-400 mb-3 px-1';
          const cachedDate = data.cached_at ? new Date(data.cached_at.replace(' ', 'T')).toLocaleString() : '';
          cacheNotice.innerHTML = `<span><i class="fa-solid fa-clock-rotate-left mr-1"></i>Showing saved results from ${cachedDate}</span>`;
          const refreshBtn = document.createElement('button');
          refreshBtn.type = 'button';
          refreshBtn.className = 'text-emerald-400 hover:text-emerald-300 font-semibold';
          refreshBtn.textContent = 'Refresh';
          refreshBtn.addEventListener('click', () => runSearch(city, industry, true));
          cacheNotice.appendChild(refreshBtn);
          leadsList.appendChild(cacheNotice);
        }

        if (!data.leads.length) {
          const emptyMsg = document.createElement('p');
          emptyMsg.className = 'text-slate-400 text-center py-6';
          emptyMsg.textContent = 'No leads found for this search. Try a different city or industry.';
          leadsList.appendChild(emptyMsg);
        } else {
          data.leads.forEach((lead) => leadsList.appendChild(renderLeadRow(lead)));
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
    const city = form.querySelector('[name="city"]').value.trim();
    const industry = form.querySelector('[name="industry"]').value.trim();
    if (!city || !industry) return;
    runSearch(city, industry, false);
  });

  const params = new URLSearchParams(window.location.search);
  if (params.get('autorun') === '1') {
    const city = params.get('city') || '';
    const industry = params.get('industry') || '';
    if (city && industry) {
      form.querySelector('[name="city"]').value = city;
      form.querySelector('[name="industry"]').value = industry;
      runSearch(city, industry, false);
    }
  }
});
