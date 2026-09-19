/**
 * onboarding-purchase.js
 * Per-plan purchase success splash.
 *
 * Trigger: purchase-success.php stores the purchased plan in
 * $_SESSION['purchase_animation_plan'] and sets a per-plan sessionStorage key
 * before bouncing to /portal/index.php, which is the only page that loads this
 * file (and which renders data-ob-plan + data-plan-info on <body>).
 *
 * Styling: monochrome white-on-#020817, matching the rest of the product. The
 * plan is communicated by the badge and the copy — there is no per-plan accent
 * colour, no gradient, no glow, and no particle canvas.
 */
(function () {
  'use strict';

  const plan = (document.body.dataset.obPlan || 'free').toLowerCase();
  const SESSION_KEY = 'utl_purchase_ob_' + plan;

  if (!sessionStorage.getItem(SESSION_KEY)) return;
  sessionStorage.removeItem(SESSION_KEY);

  /* ── Real plan limits & prices ────────────────────────────────
     Server-rendered as data-plan-info on <body> (includes/plans.php).
     Never hardcode a limit or price in this file — Admin > Config
     Editor can change any of them. */
  let INFO = {};
  try { INFO = JSON.parse(document.body.dataset.planInfo || '{}') || {}; } catch (e) { INFO = {}; }
  const P        = k => INFO[k] || {};
  const num      = (v, fb) => (typeof v === 'number' ? v : fb);
  const leadsStr = (v, fb) => num(v, fb) === -1
    ? 'Unlimited leads'
    : num(v, fb).toLocaleString() + ' lead unlocks';
  const sitesStr = (v, fb) => {
    const n = num(v, fb);
    if (n === -1) return 'Unlimited active sites';
    return n.toLocaleString() + ' active ' + (n === 1 ? 'site' : 'sites');
  };

  /* ── Plan config ────────────────────────────────────────────── */
  const CFG = {
    free: {
      badge:    'Free Plan Activated',
      title:    "You're in!",
      sub:      'Your free account is ready. Start finding clients today.',
      perks: [
        { icon: 'fa-magnifying-glass', text: num(P('free').searches, 2) + ' lead searches today' },
        { icon: 'fa-bolt',             text: num(P('free').sites, 1) + ' site to build right now' },
        { icon: 'fa-chart-line',       text: 'Revenue dashboard unlocked' },
      ],
      goLabel:  'Go to Dashboard',
    },
    pro: {
      badge:    'Pro Plan Unlocked',
      title:    'Pro mode: ON.',
      sub:      leadsStr(P('pro').leads, 700) + ', unlimited generates, all templates.',
      perks: [
        { icon: 'fa-crown',     text: leadsStr(P('pro').leads, 700) },
        { icon: 'fa-bolt',      text: 'Unlimited daily site generates' },
        { icon: 'fa-palette',   text: 'All premium templates' },
        { icon: 'fa-globe',     text: sitesStr(P('pro').sites, 20) },
      ],
      goLabel:  'Open Dashboard',
    },
    entrepreneur: {
      badge:    'Entrepreneur Plan Active',
      title:    "You're scaling now.",
      sub:      leadsStr(P('entrepreneur').leads, -1) + ', ' + num(P('entrepreneur').seats, 5) + ' team seats, and every Pro feature — the full stack.',
      perks: [
        { icon: 'fa-infinity',    text: leadsStr(P('entrepreneur').leads, -1) },
        { icon: 'fa-users',       text: num(P('entrepreneur').seats, 5) + ' team member seats' },
        { icon: 'fa-clock',       text: 'Custom domains (coming soon)' },
        { icon: 'fa-server',      text: sitesStr(P('entrepreneur').sites, 500) },
      ],
      goLabel:  'Launch Dashboard',
    },
  };

  const c = CFG[plan] || CFG.free;

  /* ── Build overlay ─────────────────────────────────────────── */
  const overlay = document.createElement('div');
  overlay.id        = 'ob-purchase';
  overlay.className = 'ob-overlay';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-label', c.badge);

  const perksHtml = c.perks.map((p, i) => `
    <div class="ob-perk" data-delay="${900 + i * 110}">
      <i class="fa-solid ${p.icon} ob-perk-icon" aria-hidden="true"></i>
      <span>${_esc(p.text)}</span>
    </div>
  `).join('');

  overlay.innerHTML = `
    <div class="ob-purchase-inner">
      <div class="ob-purchase-icon"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
      <div class="ob-purchase-plan-badge">${_esc(c.badge)}</div>
      <h1 class="ob-purchase-title">${_esc(c.title)}</h1>
      <p class="ob-purchase-sub">${_esc(c.sub)}</p>
      <div class="ob-purchase-perks">${perksHtml}</div>
      <button class="ob-purchase-go" id="ob-purchase-btn" type="button">${_esc(c.goLabel)}</button>
    </div>
  `;
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';

  /* ── Stagger perks ──────────────────────────────────────────── */
  overlay.querySelectorAll('.ob-perk[data-delay]').forEach(el => {
    setTimeout(() => el.classList.add('ob-in'), +el.dataset.delay);
  });

  /* ── Dismiss ───────────────────────────────────────────────── */
  let dismissed = false;
  const dismiss = () => {
    if (dismissed) return;
    dismissed = true;
    overlay.classList.add('ob-exit');
    document.body.style.overflow = '';
    setTimeout(() => overlay.remove(), 600);
  };
  document.getElementById('ob-purchase-btn').addEventListener('click', dismiss);
  setTimeout(dismiss, 5000);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') dismiss(); });

  /* ── Helpers ────────────────────────────────────────────────── */
  function _esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
}());
