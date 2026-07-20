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

  // ── localStorage seen-leads ──────────────────────────────────────────────────────────
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

  // ── Slider ───────────────────────────────────────────────────────────────────────
  const slider      = document.getElementById('leadCountSlider');
  const sliderDisp  = document.getElementById('leadCountDisplay');
  const sliderHid   = document.getElementById('leadCountHidden');
  if (slider) {
    slider.addEventListener('input', () => {
      sliderDisp.textContent = slider.value;
      sliderHid.value = slider.value;
    });
  }

  // Seen-leads checkbox
  const seenCb = document.getElementById('includeSeenLeads');
  if (seenCb) {
    seenCb.addEventListener('change', () => {
      const icon = seenCb.closest('label').querySelector('.peer-checked-show');
      if (icon) icon.style.display = seenCb.checked ? 'inline' : 'none';
    });
  }

  // ── Live counter ──────────────────────────────────────────────────────────────────
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
    bar.className = bar.className.replace(/bg-(white\/60|amber-500|red-500)/g, '');
    if (pct >= 100) bar.classList.add('bg-red-500');
    else if (pct >= 80) bar.classList.add('bg-amber-500');
    else bar.classList.add('bg-white/60');
    if (subtitle) subtitle.textContent = leadUsed + ' of ' + leadLimit + ' leads unlocked';
    if (countEl)  countEl.textContent  = leadUsed + ' / ' + leadLimit;
    if (noteEl)   noteEl.textContent   = Math.max(0, leadLimit - leadUsed) + ' leads remaining on your Pro plan';
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
        (rem===0?'bg-red-500/10 border border-red-500/20 text-red-400':
          rem===1?'bg-amber-500/10 border border-amber-500/20 text-amber-400':
          'bg-white/8 border border-white/10 text-slate-300');
      badge.textContent = rem===0?'No searches left today':rem+' search'+(rem!==1?'es':'')+' left';
    }
    if (bar) {
      bar.style.width = pct+'%';
      bar.className = 'h-2 rounded-full transition-all duration-500 '+(pct>=100?'bg-red-500':pct>=50?'bg-amber-500':'bg-white/60');
    }
    if (text) text.textContent = quotaUsed+' of '+quotaLimit+' searches used';
  }

  // ── Dot map ────────────────────────────────────────────────────────────────────────
  // Store last render args so resize can redraw
  let lastMapLeads = [];
  let lastMapCity  = '';

  function renderDotMap(leads, city) {
    const wrap = document.getElementById('leadMapWrap');
    const map  = document.getElementById('leadMap');
    const sub  = document.getElementById('mapSubtitle');
    if (!wrap || !map || !leads.length) { wrap && wrap.classList.add('hidden'); return; }

    // Show container first, then force reflow before reading width
    // so offsetWidth reflects the real rendered size (fixes mobile zero-width bug)
    wrap.classList.remove('hidden');
    void wrap.offsetHeight; // trigger layout

    if (sub) sub.textContent = '\u2014 ' + city;
    map.innerHTML = '';

    // Prevent dots from overflowing the container on narrow screens
    map.style.overflow = 'hidden';
    map.style.position = 'relative';

    // Draw subtle grid background
    map.style.backgroundImage = [
      'linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px)',
      'linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px)'
    ].join(',');
    map.style.backgroundSize = '32px 32px';

    // Read width AFTER reflow — falls back to 700 only if genuinely unavailable
    const W = map.getBoundingClientRect().width || map.offsetWidth || 700;
    const H = 220;
    const used = [];

    // Store for resize redraws
    lastMapLeads = leads;
    lastMapCity  = city;

    function noOverlap(x, y) {
      return used.every(p => Math.hypot(p.x-x, p.y-y) > 22);
    }

    leads.forEach((lead, i) => {
      const seed = i * 137.508;
      let x = 40 + ((seed * 9301 + 49297) % 233280) / 233280 * (W - 80);
      let y = 25 + ((seed * 1234 + 5678) % 97531) / 97531 * (H - 50);

      let attempts = 0;
      while (!noOverlap(x, y) && attempts < 12) {
        x = 40 + Math.random() * (W - 80);
        y = 25 + Math.random() * (H - 50);
        attempts++;
      }
      used.push({x, y});

      const hasWebsite = !lead.no_website;
      const isSeen     = isSeenLead(lead.id);
      const dot        = document.createElement('div');
      const score      = lead.opportunity_score || 50;
      const size       = score >= 80 ? 13 : score >= 60 ? 10 : 8;

      dot.style.cssText = [
        'position:absolute',
        `left:${x}px`, `top:${y}px`,
        `width:${size}px`, `height:${size}px`,
        'border-radius:50%',
        `background:${hasWebsite ? '#f59e0b' : isSeen ? 'rgba(255,255,255,.35)' : '#ffffff'}`,
        'transform:translate(-50%,-50%)',
        'cursor:pointer',
        `box-shadow:0 0 ${size*2}px ${hasWebsite ? 'rgba(245,158,11,.5)' : 'rgba(255,255,255,.3)'}`,
        'transition:transform .15s'
      ].join(';');

      const tip = document.createElement('div');
      tip.style.cssText = 'position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#1e293b;border:1px solid rgba(255,255,255,.1);color:#fff;font-size:11px;white-space:nowrap;padding:4px 8px;border-radius:8px;pointer-events:none;opacity:0;transition:opacity .15s;z-index:10';
      tip.textContent = lead.business_name + (hasWebsite ? ' (has website)' : ' (no website)');
      dot.appendChild(tip);

      dot.addEventListener('mouseenter', () => { dot.style.transform='translate(-50%,-50%) scale(1.6)'; tip.style.opacity='1'; });
      dot.addEventListener('mouseleave', () => { dot.style.transform='translate(-50%,-50%)';             tip.style.opacity='0'; });
      dot.addEventListener('click', () => {
        // Search both #leadsList and #lockedList for matching card
        const cards = document.querySelectorAll('#leadsList [data-lead-id], #lockedList [data-lead-id]');
        for (const c of cards) {
          if (c.dataset.leadId == lead.id) {
            c.scrollIntoView({behavior:'smooth', block:'center'});
            c.classList.add('ring-1','ring-white/40');
            setTimeout(() => c.classList.remove('ring-1','ring-white/40'), 1500);
            break;
          }
        }
      });

      map.appendChild(dot);
    });
  }

  // Redraw map on window resize (fixes stale dot positions after orientation change)
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (lastMapLeads.length) renderDotMap(lastMapLeads, lastMapCity);
    }, 150);
  });

  // ── Helpers ───────────────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function scoreColor(s) {
    return s>=80?'bg-white/15 text-white':s>=60?'bg-amber-500/20 text-amber-400':'bg-red-500/20 text-red-400';
  }
  function scorLabel(s) { return s>=80?'High':s>=60?'Med':'Low'; }

  function renderLeadRow(lead, seenIds) {
    const row = document.createElement('div');
    const wasSeen = seenIds.has(String(lead.id));
    row.className = 'glass rounded-2xl border transition-all p-4 flex flex-col sm:flex-row sm:items-center gap-4'
      + (wasSeen ? ' border-white/8 opacity-75' : ' border-white/5 hover:border-white/15');
    row.dataset.leadId = lead.id;
    const sc = scoreColor(lead.opportunity_score);
    const hasRating = lead.rating && parseFloat(lead.rating) > 0;
    const stars = hasRating ? '\u2605'.repeat(Math.round(parseFloat(lead.rating))) + '\u2606'.repeat(5-Math.round(parseFloat(lead.rating))) : '';
    row.innerHTML = `
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap mb-1">
          <h3 class="font-bold text-white text-base">${escHtml(lead.business_name)}</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${sc}">${scorLabel(lead.opportunity_score)} &middot; ${lead.opportunity_score}</span>
          ${lead.no_website?'<span class="text-[10px] px-2 py-0.5 rounded-full bg-white/8 text-slate-400 font-semibold">No Website</span>':''}
          ${wasSeen?'<span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-700/60 text-slate-400 font-semibold">Seen</span>':''}
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400 mt-1">
          <span><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(lead.business_address||'Address unavailable')}</span>
          ${lead.business_phone
            ?`<span class="text-slate-300"><i class="fa-solid fa-phone mr-1"></i>${escHtml(lead.business_phone)}</span>`
            :`<span class="text-slate-600"><i class="fa-solid fa-phone mr-1"></i>No phone</span>`}
          ${hasRating?`<span class="text-amber-400" title="${lead.rating} stars">${stars} <span class="text-slate-400">${lead.rating}</span></span>`:''}
          ${lead.total_ratings?`<span class="text-slate-500">${lead.total_ratings} reviews</span>`:''}
        </div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        ${lead.maps_url
          ?`<a href="${escHtml(lead.maps_url)}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 text-xs bg-white/8 hover:bg-white/15 text-slate-300 px-3 py-2 rounded-xl font-semibold transition">
               <i class="fa-brands fa-google text-[10px]"></i> Maps
             </a>`
          :''}
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
  const FAKE_SCORES = [72,81,68,90,77,85,63,79];

  function renderLockedRow(lead, index) {
    const row = document.createElement('div');
    row.className = 'glass rounded-2xl border border-white/5 p-4 flex items-center gap-4 overflow-hidden';
    row.dataset.leadId = lead.id || ('locked-' + index);
    const score = FAKE_SCORES[index%FAKE_SCORES.length];
    row.innerHTML = `
      <div class="flex-1 min-w-0 blur-sm select-none pointer-events-none">
        <div class="flex items-center gap-2 mb-1">
          <h3 class="font-bold text-white">${escHtml(FAKE_NAMES[index%FAKE_NAMES.length])}</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-full font-bold ${scoreColor(score)}">${score}</span>
        </div>
        <p class="text-xs text-slate-400"><i class="fa-solid fa-location-dot mr-1"></i>${escHtml(FAKE_CITIES[index%FAKE_CITIES.length])} &nbsp;<i class="fa-solid fa-phone mr-1"></i>(514) 555-0${100+index}</p>
      </div>
      <span class="inline-flex items-center gap-1.5 text-xs bg-white/5 border border-white/10 text-slate-400 px-3 py-2 rounded-xl font-semibold shrink-0">
        <i class="fa-solid fa-lock text-[10px]"></i> Locked
      </span>
    `;
    return row;
  }

  // ── Main search ─────────────────────────────────────────────────────────────────────
  function runSearch(city, industry, keywords, leadCount, includeSeen, forceRefresh) {
    loadingEl.classList.remove('hidden');
    resultsWrap.classList.add('hidden');
    leadsList.innerHTML  = '';
    lockedList.innerHTML = '';

    fetch('/api/find-leads.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
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
        leadsList.innerHTML = `<div class="glass rounded-xl p-5 text-sm text-center ${data.rate_limited?'text-amber-400':'text-red-400'}">
          <i class="fa-solid fa-${data.rate_limited?'clock':'triangle-exclamation'} mr-2"></i>
          ${escHtml(data.error||'Search failed.')}
          ${data.resets_at?`<span class="block text-xs text-slate-400 mt-1">Resets at ${new Date(data.resets_at*1000).toLocaleTimeString()}</span>`:''}
        </div>`;
        lockedWrap.classList.add('hidden');
        return;
      }

      if (typeof data.newly_unlocked==='number' && data.newly_unlocked>0) updateLiveLeadCounter(data.newly_unlocked);
      if (typeof data.searches_used==='number') updateLiveQuotaCounter(data.searches_used);

      const seenIds = getSeenIds();
      if (data.leads && data.leads.length) {
        markSeen(data.leads.map(l=>l.id));
      }

      renderDotMap(data.leads || [], city);

      if (data.from_cache) {
        const notice = document.createElement('div');
        notice.className = 'flex items-center justify-between gap-3 text-xs text-slate-400 mb-3 px-1';
        const cachedDate = data.cached_at ? new Date(data.cached_at.replace(' ','T')).toLocaleString() : '';
        notice.innerHTML = `<span><i class="fa-solid fa-clock-rotate-left mr-1"></i>Showing saved results from ${cachedDate}</span>`;
        const refreshBtn = document.createElement('button');
        refreshBtn.type='button';
        refreshBtn.className='text-slate-300 hover:text-white font-semibold underline';
        refreshBtn.textContent='Refresh';
        refreshBtn.addEventListener('click', () => runSearch(city, industry, keywords, leadCount, includeSeen, true));
        notice.appendChild(refreshBtn);
        leadsList.appendChild(notice);
      }

      if (data.leads && data.leads.length>0) {
        const currentSeenIds = getSeenIds();
        const seenCount = data.leads.filter(l=>currentSeenIds.has(String(l.id))).length;
        const summary = document.createElement('div');
        summary.className='flex items-center justify-between text-xs text-slate-500 mb-2 px-1';
        summary.innerHTML=`
          <span><i class="fa-solid fa-list mr-1"></i>
            <strong class="text-white">${data.leads.length}</strong> leads in
            <strong class="text-white">${escHtml(city)}</strong> •
            <strong class="text-white">${escHtml(industry)}</strong>
            ${keywords?`&bull; <em class="text-slate-400">${escHtml(keywords)}</em>`:''}
          </span>
          ${seenCount>0?`<span class="text-slate-600">${seenCount} seen before</span>`:''}
        `;
        leadsList.appendChild(summary);
      }

      if (!data.leads || !data.leads.length) {
        const empty=document.createElement('p');
        empty.className='text-slate-400 text-center py-6';
        empty.textContent='No leads found. Try a different city or industry.';
        leadsList.appendChild(empty);
      } else {
        const currentSeenIds = getSeenIds();
        let toShow = data.leads;
        // If "include seen" is unchecked, hide seen leads entirely
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
        data.locked_leads.forEach((lead,i) => lockedList.appendChild(renderLockedRow(lead,i)));
        lockedWrap.classList.remove('hidden');
      } else {
        lockedWrap.classList.add('hidden');
      }
    })
    .catch(() => {
      loadingEl.classList.add('hidden');
      resultsWrap.classList.remove('hidden');
      leadsList.innerHTML='<div class="glass rounded-xl p-5 text-red-400 text-sm text-center"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Something went wrong. Please try again.</div>';
      lockedWrap.classList.add('hidden');
    });
  }

  form.addEventListener('submit', function(e) {
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
  if (params.get('autorun')==='1') {
    const city=params.get('city')||''; const industry=params.get('industry')||'';
    const keywords=params.get('keywords')||'';
    const leadCount=parseInt(params.get('count'))||10;
    if (city&&industry) {
      form.querySelector('[name="city"]').value=city;
      form.querySelector('[name="industry"]').value=industry;
      if(form.querySelector('[name="keywords"]')) form.querySelector('[name="keywords"]').value=keywords;
      runSearch(city,industry,keywords,leadCount,false,false);
    }
  }
});
