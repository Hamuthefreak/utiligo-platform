/**
 * onboarding-signup.js
 * Plan showcase modal shown on fresh register.php page load.
 * Skipped when a plan is already pre-selected via URL param.
 */
(function () {
  'use strict';

  // Don't show if plan already picked (came from pricing page)
  const params = new URLSearchParams(window.location.search);
  if (params.get('plan') && params.get('plan') !== 'free') return;
  // Don't show if already dismissed this session
  if (sessionStorage.getItem('utl_signup_ob_done')) return;

  const PLANS = [
    {
      key:      'free',
      cls:      'ob-plan-free',
      badge:    '🌱 Free Forever',
      name:     'Free',
      price:    '<strong>$0</strong> / month',
      features: ['3 lead results per search', '1 generated website', '2 daily searches', 'Core templates'],
      color:    '#10b981',
      goCls:    '',
      goLabel:  'Start Free →',
      url:      '/register.php',
    },
    {
      key:      'pro',
      cls:      'ob-plan-pro',
      badge:    '👑 Most Popular',
      name:     'Pro',
      price:    '<strong>$21.99</strong> / month',
      features: ['700 leads per search', '50 generated sites', 'Unlimited daily generates', 'All templates', 'Priority support'],
      color:    '#8b5cf6',
      goCls:    'ob-plan-pro-go',
      goLabel:  'Start Pro →',
      url:      '/register.php?plan=pro',
    },
    {
      key:      'entrepreneur',
      cls:      'ob-plan-ent',
      badge:    '🚀 Scale Up',
      name:     'Entrepreneur',
      price:    '<strong>$49.99</strong> / month',
      features: ['Unlimited leads', '500 sites', '5 team seats', 'Custom domains', 'White-label branding'],
      color:    '#f59e0b',
      goCls:    'ob-plan-ent-go',
      goLabel:  'Start Entrepreneur →',
      url:      '/register.php?plan=entrepreneur',
    },
  ];

  let selectedPlan = PLANS[0];

  /* ── Build overlay ─────────────────────────────────────────── */
  const overlay = document.createElement('div');
  overlay.id        = 'ob-signup';
  overlay.className = 'ob-overlay';

  const cardsHtml = PLANS.map((p, i) => `
    <div class="ob-plan-card ${p.cls}" data-plan="${p.key}" data-delay="${400 + i * 130}" style="--plan-color:${p.color}">
      <div class="ob-plan-badge">${p.badge}</div>
      <div class="ob-plan-name">${p.name}</div>
      <div class="ob-plan-price">${p.price}</div>
      <ul class="ob-plan-features">
        ${p.features.map((f, fi) => `<li data-fi="${fi}"><span class="ob-feat-dot"></span>${_esc(f)}</li>`).join('')}
      </ul>
      <button class="ob-plan-select-btn" data-plan="${p.key}">Select ${p.name}</button>
    </div>
  `).join('');

  overlay.innerHTML = `
    <canvas class="ob-canvas" id="ob-signup-canvas"></canvas>
    <div class="ob-signup-inner">
      <h2 class="ob-signup-heading">Choose your plan</h2>
      <p class="ob-signup-sub">Pick the plan that fits you — you can upgrade anytime.</p>
      <div class="ob-plans">${cardsHtml}</div>
      <div class="ob-signup-cta">
        <button class="ob-signup-go" id="ob-signup-go">Start Free →</button>
        <button class="ob-skip" id="ob-signup-skip">Skip for now</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  // Only lock body scroll on desktop; on mobile the overlay scrolls itself.
  if (window.innerWidth >= 600) document.body.style.overflow = 'hidden';

  /* ── Stagger cards in ───────────────────────────────────────── */
  overlay.querySelectorAll('.ob-plan-card[data-delay]').forEach(card => {
    const delay = +card.dataset.delay;
    setTimeout(() => {
      card.classList.add('ob-in');
      // Stagger feature bullets
      card.querySelectorAll('.ob-plan-features li').forEach((li, i) => {
        setTimeout(() => li.classList.add('ob-in'), 120 + i * 60);
      });
    }, delay);
  });

  /* ── Select plan ────────────────────────────────────────────── */
  overlay.querySelectorAll('[data-plan]').forEach(el => {
    el.addEventListener('click', () => {
      const key  = el.dataset.plan;
      const plan = PLANS.find(p => p.key === key);
      if (!plan) return;
      selectedPlan = plan;

      // Highlight card
      overlay.querySelectorAll('.ob-plan-card').forEach(c => c.classList.remove('ob-selected'));
      overlay.querySelector(`.ob-plan-card[data-plan="${key}"]`).classList.add('ob-selected');

      // Update CTA button
      const goBtn = document.getElementById('ob-signup-go');
      goBtn.textContent = plan.goLabel;
      goBtn.className   = 'ob-signup-go ' + plan.goCls;
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

  /* ── Subtle particle bg ─────────────────────────────────────── */
  _initParticles(document.getElementById('ob-signup-canvas'), '#3b82f6', 28);

  /* ── Helpers ────────────────────────────────────────────────── */
  function _dismiss(cb) {
    overlay.classList.add('ob-exit');
    document.body.style.overflow = '';
    setTimeout(() => { overlay.remove(); if (cb) cb(); }, 600);
  }

  function _esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function _initParticles(canvas, color, count) {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H;
    const resize = () => { W = canvas.width = canvas.offsetWidth; H = canvas.height = canvas.offsetHeight; };
    resize();
    window.addEventListener('resize', resize);
    const particles = Array.from({length: count}, () => ({
      x: Math.random() * (W||800), y: Math.random() * (H||600),
      r: Math.random() * 1.5 + 0.5,
      vx: (Math.random()-0.5)*0.3, vy: (Math.random()-0.5)*0.3,
      a: Math.random(),
    }));
    let alive = true;
    overlay.addEventListener('transitionend', () => { alive = false; });
    const frame = () => {
      if (!alive) return;
      ctx.clearRect(0,0,W,H);
      particles.forEach(p => {
        p.x+=p.vx; p.y+=p.vy; p.a+=0.007;
        if(p.x<0)p.x=W; if(p.x>W)p.x=0; if(p.y<0)p.y=H; if(p.y>H)p.y=0;
        ctx.beginPath(); ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
        ctx.fillStyle = color + Math.floor((0.1+0.2*Math.abs(Math.sin(p.a)))*255).toString(16).padStart(2,'0');
        ctx.fill();
      });
      requestAnimationFrame(frame);
    };
    frame();
  }
}());
