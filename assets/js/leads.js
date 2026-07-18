document.addEventListener('DOMContentLoaded', function () {
  const form         = document.getElementById('leadSearchForm');
  const resultsWrap  = document.getElementById('leadsResultsWrap');
  const leadsList    = document.getElementById('leadsList');
  const lockedWrap   = document.getElementById('lockedWrap');
  const lockedList   = document.getElementById('lockedList');
  const loadingEl    = document.getElementById('leadsLoading');
  const csrfToken    = document.body.dataset.csrf;

  // Config seeded from PHP
  const cfg      = document.getElementById('leadsPageConfig');
  const PLAN     = cfg ? cfg.dataset.plan      : 'free';
  const IS_PAID  = PLAN === 'pro' || PLAN === 'entrepreneur';

  // Live counter state
  let leadUsed  = parseInt(cfg?.dataset.leadUsed  ?? 0);
  let leadLimit = parseInt(cfg?.dataset.leadLimit ?? 0);
  let quotaUsed = parseInt(cfg?.dataset.quotaUsed ?? 0);
  let quotaLimit= parseInt(cfg?.dataset.quotaLimit ?? 0);

  if (!form) return;

  // ── Lead count pill toggle ────────────────────────────────────────────────
  document.querySelectorAll('.lead-count-option input[type=radio]').forEach(radio => {
    radio.addEventListener('change', () => {
      document.querySelectorAll('.lead-count-pill').forEach(p => {
        p.classList.remove('!bg-white','!text-black','!border-white');
        p.classList.add('text-slate-400','bg-slate-800/80','border-slate-600');
      });
      if (radio.checked) {
        const pill = radio.closest('label').querySelector('.lead-count-pill');
        pill.classList.add('!bg-white','!text-black','!border-white');
        pill.classList.remove('text-slate-400','bg-slate-800/80','border-slate-600');
      }
    });
  });
  // Activate default checked pill on load
  document.querySelectorAll('.lead-count-option input[type=radio]:checked').forEach(radio => {
    const pill = radio.closest('label').querySelector('.lead-count-pill');
    if (pill) {
      pill.classList.add('!bg-white','!text-black','!border-white');
      pill.classList.remove('text-slate-400','bg-slate-800/80','border-slate-600');
    }
  });

  // ── Live limit counter updater ────────────────────────────────────────────
  function updateLiveLeadCounter(newUnlocked) {
    leadUsed += newUnlocked;
    // Pro limit bar
    const bar      = document.getElementById('leadLimitBar');
    const subtitle = document.getElementById('leadLimitSubtitle');
    const countEl  = document.getElementById('leadLimitCount');
    const noteEl   = document.getElementById('leadLimitNote');
    const upgradeBtn = document.getElementById('leadUpgradeBtn');
    if (!bar || leadLimit <= 0) return;
    const pct = Math.min(100, Math.round((leadUsed / leadLimit) * 100));
    bar.style.width = pct + '%';
    bar.className = bar.className.replace(/bg-(white\/60|amber-500|red-500)/g, '');
    if (pct >= 100) bar.classList.add('bg-red-500');
    else if (pct >= 80) bar.classList.add('bg-amber-500');
    else bar.classList.add('bg-white/60');
    if (subtitle) subtitle.textContent = leadUsed + ' of ' + leadLimit + ' leads unlocked';
    if (countEl)  countEl.textContent  = leadUsed + ' / ' + leadLimit;
    if (noteEl)   noteEl.textContent   = Math.max(0, leadLimit - leadUsed) + ' leads remaining on your Pro plan';
    // Show upgrade button if now ≥ 80%
    if (pct >= 80 && upgradeBtn) upgradeBtn.classList.remove('hidden');
  }

  function updateLiveQuotaCounter(newUsed) {
    quotaUsed = newUsed;
    const badge = document.getElementById('quotaBadge');
    const bar   = document.getElementById('quotaBar');
    const text  = document.getElementById('quotaText');
    const rem   = Math.max(0, quotaLimit - quotaUsed);
    const pct   = quotaLimit > 0 ? Math.min(100, Math.round((quotaUsed/quotaLimit)*100)) : 0;
    if (badge) {
      badge.className = 'flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ' +
        (rem === 0 ? 'bg-red-500/10 border border-red-500/20 text-red-400'
          : rem === 1 ? 'bg-amber-500/10 border border-amber-500/20 text-amber-400'
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

  // ── Helpers ───────────────────────────────────────────────────────────────
  function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function scoreColor(score) {
    if (score >= 80) return 'bg-white/15 text-white';
    if (score >= 60) return 'bg-amber-500/20 text-amber-400';
    return 'bg-red-500/20 text-red-400';
  }

  function scorLabel(score) {
    if (score >= 80) return 'High';
    if (score >= 60) return 'Med';
    return 'Low';
  }

  function renderLeadRow(lead) {
    const row = document.createElement('div');
    row.className = 'glass rounded-2xl border border-white/5 hover:border-white/15 transition-all p-4 flex flex-col sm:flex-row sm:items-center gap-4';
    const sc = scoreColor(lead.opportunity_score);
    const hasPhone = !!lead.business_phone;
    const hasRating = lead.rating && parseFloat(lead.rating) > 0;
    const stars = hasRating ? '★'.repeat(Math.round(parseFloat(lead.rating))) + '☆'.repeat(5 - Math.round(parseFloat(lead.rating))) : '';
    row.innerHTML = `
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap mb-1">
          <h3 class="font-bold text-white text-base">${escHtml(lead.business_name)}</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${sc}">
            ${scorLabel(lead.opportunity_score)} · ${lead.opportunity_score}
          </span>
          ${lead.no_website ? '<span class="text-[10px] px-2 py-0.5 rounded-full bg-white/8 text-slate-400 font-semibold">No Website</span>' : ''}
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400 mt-1">
          <span><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(lead.business_address || 'Address unavailable')}</span>
          ${hasPhone
            ? `<span class="text-slate-300"><i class="fa-solid fa-phone mr-1"></i>${escHtml(lead.business_phone)}</span>`
            : `<span class="text-slate-600"><i class="fa-solid fa-phone mr-1"></i>No phone</span>`
          }
          ${hasRating ? `<span class="text-amber-400" title="${lead.rating} stars">${stars} <span class="text-slate-400">${lead.rating}</span></span>` : ''}
          ${lead.total_ratings ? `<span class="text-slate-500">${lead.total_ratings} reviews</span>` : ''}
        </div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        ${lead.maps_url
          ? `<a href="${escHtml(lead.maps_url)}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 text-xs bg-white/8 hover:bg-white/15 text-slate-300 px-3 py-2 rounded-xl font-semibold transition">
               <i class="fa-brands fa-google text-[10px]"></i> Maps
             </a>`
          : ''
        }
        <a href="/portal/generate.php?lead_id=${encodeURIComponent(lead.id)}"
           class="inline-flex items-center gap-1.5 text-xs bg-white hover:bg-slate-200 active:scale-95 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all">
          <i class="fa-solid fa-bolt text-[10px]"></i> Build Site
        </a>
      </div>
    `;
    return row;
  }

  const FAKE_NAMES = [
    'Montreal Plumbing Co.','Apex Roofing Services','Bella Vista Restaurant',
    'ProClean Janitorial','City Electrical Works','Green Thumb Landscaping',
    'Maple Auto Repair','Studio 514 Hair Salon','North Star Painting',
    'FastFix HVAC','Royal Touch Flooring','Sunrise Bakery & Cafe'
  ];
  const FAKE_CITIES = ['Montreal, QC','Laval, QC','Longueuil, QC','Brossard, QC'];
  const FAKE_SCORES = [72,81,68,90,77,85,63,79];

  function renderLockedRow(lead, index) {
    const row = document.createElement('div');
    row.className = 'glass rounded-2xl border border-white/5 p-4 flex items-center gap-4 overflow-hidden relative';
    const score = FAKE_SCORES[index % FAKE_SCORES.length];
    const sc    = scoreColor(score);
    row.innerHTML = `
      <div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-bold text-white">${escHtml(FAKE_NAMES[index % FAKE_NAMES.length])}</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${sc}">${score}</span>
        </div>
        <p class="text-xs text-slate-400">
          <i class="fa-solid fa-location-dot mr-1"></i>${escHtml(FAKE_CITIES[index % FAKE_CITIES.length])}
          &nbsp;&nbsp;<i class="fa-solid fa-phone mr-1"></i>(514) 555-0${100+index}
        </p>
      </div>
      <div class="shrink-0">
        <span class="inline-flex items-center gap-1.5 text-xs bg-white/5 border border-white/10 text-slate-400 px-3 py-2 rounded-xl font-semibold">
          <i class="fa-solid fa-lock text-[10px]"></i> Locked
        </span>
      </div>
    `;
    return row;
  }

  // ── Main search function ──────────────────────────────────────────────────
  function runSearch(city, industry, keywords, leadCount, forceRefresh) {
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML   = '';
    lockedList.innerHTML  = '';

    fetch('/api/find-leads.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        city, industry,
        keywords:      keywords || null,
        lead_count:    leadCount || 10,
        csrf_token:    csrfToken,
        force_refresh: !!forceRefresh
      }),
    })
      .then(r => r.json())
      .then(data => {
        loadingEl.classList.add('hidden');
        resultsWrap.classList.remove('hidden');

        if (!data.success) {
          leadsList.innerHTML = `<div class="glass rounded-xl p-5 text-sm text-center ${
            data.rate_limited ? 'text-amber-400' : 'text-red-400'
          }">
            <i class="fa-solid fa-${ data.rate_limited ? 'clock' : 'triangle-exclamation' } mr-2"></i>
            ${escHtml(data.error || 'Search failed.')}
            ${ data.resets_at
              ? `<span class="block text-xs text-slate-400 mt-1">Resets at ${new Date(data.resets_at*1000).toLocaleTimeString()}</span>`
              : '' }
          </div>`;
          lockedWrap.classList.add('hidden');
          return;
        }

        // ── Live counter update ──────────────────────────────────────────
        if (typeof data.newly_unlocked === 'number' && data.newly_unlocked > 0) {
          updateLiveLeadCounter(data.newly_unlocked);
        }
        if (typeof data.searches_used === 'number') {
          updateLiveQuotaCounter(data.searches_used);
        }

        // Cache notice
        if (data.from_cache) {
          const notice = document.createElement('div');
          notice.className = 'flex items-center justify-between gap-3 text-xs text-slate-400 mb-3 px-1';
          const cachedDate = data.cached_at ? new Date(data.cached_at.replace(' ','T')).toLocaleString() : '';
          notice.innerHTML = `<span><i class="fa-solid fa-clock-rotate-left mr-1"></i>Showing saved results from ${cachedDate}</span>`;
          const refreshBtn = document.createElement('button');
          refreshBtn.type = 'button';
          refreshBtn.className = 'text-slate-300 hover:text-white font-semibold underline';
          refreshBtn.textContent = 'Refresh';
          refreshBtn.addEventListener('click', () => {
            const kw = form.querySelector('[name="keywords"]')?.value.trim() || '';
            const lc = parseInt(form.querySelector('[name="lead_count"]:checked')?.value) || 10;
            runSearch(city, industry, kw, lc, true);
          });
          notice.appendChild(refreshBtn);
          leadsList.appendChild(notice);
        }

        // Results summary row
        if (data.leads && data.leads.length > 0) {
          const summaryRow = document.createElement('div');
          summaryRow.className = 'flex items-center justify-between text-xs text-slate-500 mb-2 px-1';
          summaryRow.innerHTML = `
            <span><i class="fa-solid fa-list mr-1"></i>
              <strong class="text-white">${data.leads.length}</strong> leads found
              in <strong class="text-white">${escHtml(city)}</strong>
              for <strong class="text-white">${escHtml(industry)}</strong>
              ${keywords ? `&bull; keywords: <em class="text-slate-400">${escHtml(keywords)}</em>` : ''}
            </span>
            ${ IS_PAID && PLAN === 'pro' && leadLimit > 0
              ? `<span class="${leadLimit - leadUsed <= 5 ? 'text-red-400' : 'text-slate-500'}">${leadLimit - leadUsed} leads left</span>`
              : ''
            }
          `;
          leadsList.appendChild(summaryRow);
        }

        if (!data.leads || !data.leads.length) {
          const empty = document.createElement('p');
          empty.className = 'text-slate-400 text-center py-6';
          empty.textContent = 'No leads found. Try a different city or industry.';
          leadsList.appendChild(empty);
        } else {
          data.leads.forEach(lead => leadsList.appendChild(renderLeadRow(lead)));
        }

        if (data.is_free_tier && data.locked_leads && data.locked_leads.length) {
          data.locked_leads.forEach((lead,i) => lockedList.appendChild(renderLockedRow(lead,i)));
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
    const city     = form.querySelector('[name="city"]').value.trim();
    const industry = form.querySelector('[name="industry"]').value.trim();
    const keywords = form.querySelector('[name="keywords"]')?.value.trim() || '';
    const leadCount= parseInt(form.querySelector('[name="lead_count"]:checked')?.value) || 10;
    if (!city || !industry) return;
    runSearch(city, industry, keywords, leadCount, false);
  });

  // Auto-run from URL params
  const params = new URLSearchParams(window.location.search);
  if (params.get('autorun') === '1') {
    const city = params.get('city') || '';
    const industry = params.get('industry') || '';
    const keywords = params.get('keywords') || '';
    const leadCount = parseInt(params.get('count')) || 10;
    if (city && industry) {
      form.querySelector('[name="city"]').value = city;
      form.querySelector('[name="industry"]').value = industry;
      if (keywords && form.querySelector('[name="keywords"]')) form.querySelector('[name="keywords"]').value = keywords;
      runSearch(city, industry, keywords, leadCount, false);
    }
  }
});
