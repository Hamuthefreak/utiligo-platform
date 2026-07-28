/**
 * onboarding-login.js
 * Welcome-back splash shown once per browser session after login.
 * Triggered by login.php setting sessionStorage flag before redirect.
 */
(function () {
  'use strict';

  const SESSION_KEY = 'utl_show_login_ob';
  const userName    = document.body.dataset.obName || '';

  if (!sessionStorage.getItem(SESSION_KEY)) return;
  sessionStorage.removeItem(SESSION_KEY);

  /* ── Build overlay ─────────────────────────────────────────── */
  const overlay = document.createElement('div');
  overlay.id        = 'ob-login';
  overlay.className = 'ob-overlay';
  overlay.innerHTML = `
    <canvas class="ob-canvas" id="ob-login-canvas"></canvas>
    <div class="ob-login-inner">
      <div class="ob-logo-ring">
        <i class="fa-solid fa-bolt ob-logo-icon"></i>
      </div>
      <h1 class="ob-greeting">Welcome back${userName ? ', <span>' + _esc(userName.split(' ')[0]) + '</span>' : ''}!</h1>
      <p class="ob-subtext">Your dashboard is ready. Let's get to work.</p>
      <div class="ob-stats" id="ob-login-stats">
        <div class="ob-stat" data-delay="900">
          <i class="fa-solid fa-magnifying-glass ob-stat-icon"></i>
          <span class="ob-stat-label">Lead Finder</span>
          <span class="ob-stat-val">Ready</span>
        </div>
        <div class="ob-stat" data-delay="1050">
          <i class="fa-solid fa-bolt ob-stat-icon"></i>
          <span class="ob-stat-label">Site Builder</span>
          <span class="ob-stat-val">Ready</span>
        </div>
        <div class="ob-stat" data-delay="1200">
          <i class="fa-solid fa-chart-line ob-stat-icon"></i>
          <span class="ob-stat-label">Revenue</span>
          <span class="ob-stat-val">Tracking</span>
        </div>
      </div>
      <button class="ob-continue" id="ob-login-btn">
        Go to Dashboard &nbsp;→
      </button>
    </div>
  `;
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';

  /* ── Stagger stat pills ─────────────────────────────────────── */
  overlay.querySelectorAll('.ob-stat[data-delay]').forEach(el => {
    setTimeout(() => el.classList.add('ob-in'), +el.dataset.delay);
  });

  /* ── Particle canvas ────────────────────────────────────────── */
  _initParticles(document.getElementById('ob-login-canvas'), '#10b981', 38);

  /* ── Auto-dismiss after 3.5 s ──────────────────────────────── */
  let dismissed = false;
  const dismiss = () => {
    if (dismissed) return;
    dismissed = true;
    overlay.classList.add('ob-exit');
    document.body.style.overflow = '';
    setTimeout(() => overlay.remove(), 600);
  };

  document.getElementById('ob-login-btn').addEventListener('click', dismiss);
  setTimeout(dismiss, 3500);

  /* ── Helpers ────────────────────────────────────────────────── */
  function _esc(s) {
    return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function _initParticles(canvas, color, count) {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H, particles;

    const resize = () => {
      W = canvas.width  = canvas.offsetWidth;
      H = canvas.height = canvas.offsetHeight;
    };
    resize();
    window.addEventListener('resize', resize);

    particles = Array.from({length: count}, () => ({
      x:  Math.random() * (W || 800),
      y:  Math.random() * (H || 600),
      r:  Math.random() * 1.8 + 0.4,
      vx: (Math.random() - 0.5) * 0.35,
      vy: (Math.random() - 0.5) * 0.35,
      a:  Math.random(),
    }));

    let alive = true;
    overlay.addEventListener('transitionend', () => { alive = false; });

    const frame = () => {
      if (!alive) return;
      ctx.clearRect(0, 0, W, H);
      particles.forEach(p => {
        p.x += p.vx; p.y += p.vy;
        p.a += 0.008;
        if (p.x < 0) p.x = W; if (p.x > W) p.x = 0;
        if (p.y < 0) p.y = H; if (p.y > H) p.y = 0;
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fillStyle = color + Math.floor((0.15 + 0.25 * Math.abs(Math.sin(p.a))) * 255).toString(16).padStart(2,'0');
        ctx.fill();
      });
      requestAnimationFrame(frame);
    };
    frame();
  }
}());
