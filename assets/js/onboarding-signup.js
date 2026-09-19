/**
 * onboarding-signup.js
 * Plan showcase modal shown on fresh register.php page load.
 * Skipped when a plan is already pre-selected via URL param.
 *
 * Styling note: this overlay intentionally borrows the product's own design
 * language rather than inventing one — monochrome white-on-#020817, glass
 * panels, rounded-full white CTAs (see the "Black & white accent overrides"
 * block in style.css and the #pricing cards in index.php). No gradients, no
 * glows, no per-plan accent colours, no decorative particle canvas.
 */
(function () {
  'use strict';

  // Don't show if plan already picked (came from pricing page)
  const params = new URLSearchParams(window.location.search);
  if (params.get('plan') && params.get('plan') !== 'free') return;
  // Don't show if already dismissed this session
  if (sessionStorage.getItem('utl_signup_ob_done')) return;

  /* ── Real plan limits & prices ────────────────────────────────
     Server-rendered as data-plan-info on <body> (includes/plans.php).
     Never hardcode a limit or price in this file: an admin can change
     any of them in Admin > Config Editor and hardcoded copy goes stale. */
  let INFO = {};
  try { INFO = JSON.parse(document.body.dataset.planInfo || '{}') || {}; } catch (e) { INFO = {}; }
  const P        = k => INFO[k] || {};
  const num      = (v, fb) => (typeof v === 'number' ? v : fb);
  const priceNum = (v, fb) => '$' + num(v, fb).toFixed(2);
  const leadsStr = (v, fb) => num(v, fb) === -1
    ? 'Unlimited lead unlocks'
    : num(v, fb).toLocaleString() + ' lead unlocks';
  const sitesStr = (v, fb) => {
    const n = num(v, fb);
    if (n === -1) return 'Unlimited active sites';
    return n.toLocaleString() + ' active ' + (n === 1 ? 'site' : 'sites');
  };

  // Copy mirrors index.php's #pricing section so the two read identically, and
  // each card's url mirrors that section's CTA — so choosing a plan here lands
  // on signup with that plan already selected. The url used to be missing
  // entirely, which sent the button to the literal string "undefined";
  // test_signup_modal.php now fails if a card is added without one.
  const PLANS = [
    {
      key:      'free',
      cls:      'ob-plan-free',
      tag:      'Free forever',
      tagSolid: false,
      name:     'Free',
      desc:     'Explore Utiligo with no commitment.',
      price:    '$0',
      url:      '/register.php',
      features: [
        num(P('free').leads, 3) + ' lead results per search',
        sitesStr(P('free').sites, 1),
        num(P('free').searches, 2) + ' searches per day',
        num(P('free').templates, 2) + ' templates',
      ],
    },
    {
      key:      'pro',
      cls:      'ob-plan-pro',
      tag:      'Most Popular',
      tagSolid: true,
      featured: true,
      name:     'Pro',
      desc:     'For freelancers ready to land real clients.',
      price:    priceNum(P('pro').price, 21.99),
      url:      '/register.php?plan=pro',
      features: [
        leadsStr(P('pro').leads, 700),
        sitesStr(P('pro').sites, 20),
        'Unlimited daily generates',
        'All templates',
        'Call scripts on every page',
        'Priority support',
      ],
    },
    {
      key:      'entrepreneur',
      cls:      'ob-plan-ent',
      tag:      'Best for agencies',
      tagSolid: false,
      name:     'Entrepreneur',
      desc:     'Scale with a full agency operation.',
      price:    priceNum(P('entrepreneur').price, 49.99),
      url:      '/register.php?plan=entrepreneur',
      features: [
        leadsStr(P('entrepreneur').leads, -1),
        sitesStr(P('entrepreneur').sites, 500),
        num(P('entrepreneur').seats, 5) + ' team seats',
        'Custom domains (coming soon)',
        'Call scripts on every page',
      ],
    },
  ];

  let selectedPlan = PLANS[0];

  /* ── Build overlay ─────────────────────────────────────────── */
  const overlay = document.createElement('div');
  overlay.id        = 'ob-signup';
  overlay.className = 'ob-overlay';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-labelledby', 'ob-signup-title');

  const cardsHtml = PLANS.map((p, i) => `
    <div class="ob-plan-card ${p.cls}${p.featured ? ' ob-featured' : ''}"
         data-plan="${p.key}" data-delay="${280 + i * 110}"
         role="button" tabindex="0" aria-pressed="false">
      <div class="ob-plan-tag${p.tagSolid ? ' ob-plan-tag-solid' : ''}">${_esc(p.tag)}</div>
      <div class="ob-plan-name">${_esc(p.name)}</div>
      <div class="ob-plan-desc">${_esc(p.desc)}</div>
      <div class="ob-plan-price">${_esc(p.price)}<span> / month</span></div>
      <ul class="ob-plan-features">
        ${p.features.map((f, fi) => `
          <li data-fi="${fi}"><i class="fa-solid ${_featIcon(f)}" aria-hidden="true"></i><span>${_esc(f)}</span></li>
        `).join('')}
      </ul>
      <button type="button" class="ob-plan-select-btn" data-plan="${p.key}">Choose ${_esc(p.name)}</button>
    </div>
  `).join('');

  overlay.innerHTML = `
    <div class="ob-signup-inner">
      <div class="ob-signup-mark">Utiligo</div>
      <h2 class="ob-signup-heading" id="ob-signup-title">Simple Pricing. Real Value.</h2>
      <p class="ob-signup-sub">Start free. Upgrade when you&rsquo;re ready to scale.</p>
      <div class="ob-plans">${cardsHtml}</div>
      <div class="ob-signup-cta">
        <button class="ob-signup-go" id="ob-signup-go" type="button">Continue with Free</button>
        <button class="ob-skip" id="ob-signup-skip" type="button">Skip for now</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  // Only lock body scroll on desktop; below the stacking breakpoint the overlay
  // (which is overflow:auto) scrolls itself. Keep this number in sync with the
  // .ob-plans media query in onboarding.css.
  if (window.innerWidth > 768) document.body.style.overflow = 'hidden';

  /* ── Stagger cards in ───────────────────────────────────────── */
  overlay.querySelectorAll('.ob-plan-card[data-delay]').forEach(card => {
    const delay = +card.dataset.delay;
    setTimeout(() => {
      card.classList.add('ob-in');
      // Stagger feature bullets
      card.querySelectorAll('.ob-plan-features li').forEach((li, i) => {
        setTimeout(() => li.classList.add('ob-in'), 90 + i * 45);
      });
    }, delay);
  });

  /* ── Select plan ────────────────────────────────────────────── */
  function selectPlan(key) {
    const plan = PLANS.find(p => p.key === key);
    if (!plan) return;
    selectedPlan = plan;

    overlay.querySelectorAll('.ob-plan-card').forEach(c => {
      const on = c.dataset.plan === key;
      c.classList.toggle('ob-selected', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    const goBtn = document.getElementById('ob-signup-go');
    goBtn.textContent = 'Continue with ' + plan.name;
  }

  overlay.querySelectorAll('[data-plan]').forEach(el => {
    el.addEventListener('click', () => selectPlan(el.dataset.plan));
  });

  // Keyboard: the cards are an interactive list, so Enter/Space must select.
  overlay.querySelectorAll('.ob-plan-card[data-plan]').forEach(card => {
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
        e.preventDefault();
        selectPlan(card.dataset.plan);
      }
    });
  });

  /* ── CTA: navigate to selected plan URL ─────────────────────── */
  document.getElementById('ob-signup-go').addEventListener('click', () => {
    sessionStorage.setItem('utl_signup_ob_done', '1');
    _dismiss(() => { window.location.href = selectedPlan.url; });
  });

  document.getElementById('ob-signup-skip').addEventListener('click', () => {
    sessionStorage.setItem('utl_signup_ob_done', '1');
    _dismiss();
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') _dismiss();
  });

  // Pre-select the recommended tier so the primary CTA is never ambiguous.
  selectPlan('pro');

  /* ── Helpers ────────────────────────────────────────────────── */
  let dismissed = false;
  function _dismiss(cb) {
    if (dismissed) return;
    dismissed = true;
    overlay.classList.add('ob-exit');
    document.body.style.overflow = '';
    setTimeout(() => { overlay.remove(); if (cb) cb(); }, 600);
  }

  function _esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // index.php marks "Unlimited …" features with an infinity glyph, the rest
  // with a check — same vocabulary here.
  function _featIcon(label) {
    return /^unlimited/i.test(String(label)) ? 'fa-infinity' : 'fa-check';
  }
}());
