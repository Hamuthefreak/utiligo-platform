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
    const stars = hasRating ? '★'.repeat(Math.round(parseFloat(lead.rating))) + '☆'.repeat(5-Math.round(parseFloat(lead.rating))) : '';

    const phoneLine = lead.business_phone
      ? `<a href="tel:${escHtml(lead.business_phone)}" class="inline-flex items-center gap-1.5 text-xs bg-white/8 hover:bg-white/15 text-slate-200 px-3 py-1.5 rounded-lg font-medium transition-all"><i class="fa-solid fa-phone text-[10px]"></i>${escHtml(lead.business_phone)}</a>`
      : `<span class="inline-flex items-center gap-1.5 text-xs bg-white/4 text-slate-600 px-3 py-1.5 rounded-lg"><i class="fa-solid fa-phone text-[10px]"></i>No phone listed</span>`;
    const ma