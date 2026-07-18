document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('leadSearchForm');
  const resultsWrap = document.getElementById('leadsResultsWrap');
  const leadsList = document.getElementById('leadsList');
  const lockedWrap = document.getElementById('lockedWrap');
  const lockedList = document.getElementById('lockedList');
  const loadingEl = document.getElementById('leadsLoading');
  const csrfToken = document.body.dataset.csrf;

  if (!form) return;

  // Fake-but-believable placeholder names for locked rows
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

  function fakeName(index) {
    return FAKE_NAMES[index % FAKE_NAMES.length];
  }
  function fakeCity(index) {
    return FAKE_CITIES[index % FAKE_CITIES.length];
  }
  function fakeScore(index) {
    return FAKE_SCORES[index % FAKE_SCORES.length];
  }

  function scoreColor(score) {
    if (score >= 80) return 'bg-emerald-500/20 text-emerald-400';
    if (score >= 60) return 'bg-amber-500/20 text-amber-400';
    return 'bg-red-500/20 text-red-400';
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
      <a href="/portal/generate.php?lead_id=${encodeURIComponent(lead.id)}" class="text-xs bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-4 py-2 rounded-full font-semibold whitespace-nowrap">
        <i class="fa-solid fa-bolt mr-1"></i>Generate Website
      </a>
    `;
    return row;
  }

  function renderLockedRow(lead, index) {
    const row = document.createElement('div');
    row.className = 'relative glass rounded-xl p-4 flex items-center justify-between gap-4 flex-wrap overflow-hidden';
    const name  = fakeName(index);
    const city  = fakeCity(index);
    const score = fakeScore(index);
    const sc    = scoreColor(score);
    row.innerHTML = `
      <div class="absolute inset-0 backdrop-blur-[3px] bg-slate-950/40 z-10 rounded-xl pointer-events-none"></div>
      <div class="flex-1 min-w-[200px] relative z-0">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-semibold text-white">${escHtml(name)}</h3>
          <span class="text-xs px-2 py-0.5 rounded-full ${sc} font-semibold">${score}</span>
        </div>
        <p class="text-sm text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(city)}</p>
      </div>
      <div class="text-sm text-slate-300 min-w-[160px] relative z-0">
        <i class="fa-solid fa-phone mr-1 text-emerald-500"></i><span class="blur-sm select-none">(514) 555-01${String(10 + index).padStart(2,'0')}</span>
      </div>
      <div class="text-xs bg-white/5 text-slate-400 px-4 py-2 rounded-full font-semibold whitespace-nowrap relative z-0">
        <i class="fa-solid fa-lock mr-1 text-amber-400"></i>Pro Only
      </div>
    `;
    return row;
  }

  function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
          leadsList.innerHTML = `<div class="glass rounded-xl p-5 text-red-400 text-sm text-center">
            <i class="fa-solid fa-triangle-exclamation mr-2"></i>${escHtml(data.error || 'Search failed.')}
          </div>`;
          lockedWrap.classList.add('hidden');
          return;
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
