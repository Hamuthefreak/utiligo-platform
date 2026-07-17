document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('leadSearchForm');
  const resultsWrap = document.getElementById('leadsResultsWrap');
  const leadsList = document.getElementById('leadsList');
  const lockedWrap = document.getElementById('lockedWrap');
  const lockedList = document.getElementById('lockedList');
  const loadingEl = document.getElementById('leadsLoading');
  const csrfToken = document.body.dataset.csrf;

  if (!form) return;

  function renderLeadRow(lead) {
    const row = document.createElement('div');
    row.className = 'glass rounded-xl p-4 flex items-center justify-between gap-4 flex-wrap';
    row.innerHTML = `
      <div class="flex-1 min-w-[200px]">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-semibold">${lead.business_name}</h3>
          <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400">Score: ${lead.opportunity_score}</span>
        </div>
        <p class="text-sm text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i>${lead.business_address || ''}</p>
      </div>
      <div class="text-sm text-slate-300 min-w-[160px]">
        <i class="fa-solid fa-phone mr-1 text-slate-500"></i>${lead.business_phone || 'N/A'}
      </div>
      <a href="/portal/generate.php?lead_id=${lead.id}" class="text-xs bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-4 py-2 rounded-full font-semibold whitespace-nowrap">
        Generate Website
      </a>
    `;
    return row;
  }

  function renderLockedRow(lead) {
    const row = document.createElement('div');
    row.className = 'glass rounded-xl p-4 flex items-center justify-between gap-4 flex-wrap select-none';
    row.innerHTML = `
      <div class="flex-1 min-w-[200px] blur-sm">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-semibold">${lead.business_name}</h3>
          <span class="text-xs px-2 py-0.5 rounded-full bg-white/10 text-slate-400">Score: --</span>
        </div>
        <p class="text-sm text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i>${lead.business_address || ''}</p>
      </div>
      <div class="text-sm text-slate-300 min-w-[160px] blur-sm">
        <i class="fa-solid fa-phone mr-1 text-slate-500"></i>${lead.business_phone || ''}
      </div>
      <div class="text-xs bg-white/5 text-slate-500 px-4 py-2 rounded-full font-semibold whitespace-nowrap">
        <i class="fa-solid fa-lock mr-1"></i>Locked
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
          leadsList.innerHTML = `<p class="text-red-400">${data.error || 'Search failed.'}</p>`;
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
          refreshBtn.textContent = 'Search again';
          refreshBtn.addEventListener('click', () => runSearch(city, industry, true));
          cacheNotice.appendChild(refreshBtn);
          leadsList.appendChild(cacheNotice);
        }

        if (!data.leads.length) {
          const emptyMsg = document.createElement('p');
          emptyMsg.className = 'text-slate-400 text-center py-6';
          emptyMsg.textContent = 'No leads found. Try a different city or industry.';
          leadsList.appendChild(emptyMsg);
        } else {
          data.leads.forEach((lead) => leadsList.appendChild(renderLeadRow(lead)));
        }

        if (data.is_free_tier && data.locked_leads && data.locked_leads.length) {
          data.locked_leads.forEach((lead) => lockedList.appendChild(renderLockedRow(lead)));
          lockedWrap.classList.remove('hidden');
        } else {
          lockedWrap.classList.add('hidden');
        }
      })
      .catch(() => {
        loadingEl.classList.add('hidden');
        resultsWrap.classList.remove('hidden');
        leadsList.innerHTML = '<p class="text-red-400">Something went wrong. Please try again.</p>';
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
