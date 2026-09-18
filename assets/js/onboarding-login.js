/**
 * onboarding-login.js
 * Welcome-back splash shown once per browser session after a successful login.
 *
 * Trigger: login.php / verify-2fa.php redirect to /portal/index.php?welcome=1
 * and portal/index.php is the only place that loads this file. Presence of the
 * file IS the trigger, so there is no sessionStorage flag the server has to
 * coordinate — sessionStorage is used purely to stop the splash replaying if
 * the user reloads the ?welcome=1 URL or navigates back to it.
 *
 * Styling: monochrome white-on-#020817, matching the rest of the product. No
 * particle canvas, no accent colour, no glow — see onboarding.css.
 */
(function () {
  'use strict';

  // Guard against a reload/back-nav replaying the splash.
  if (sessionStorage.getItem('utl_login_ob_shown')) return;
  sessionStorage.setItem('utl_login_ob_shown', '1');

  const userName = document.body.dataset.obName || '';

  /* ── Build overlay ─────────────────────────────────────────── */
  const overlay = document.createElement('div');
  overlay.id        = 'ob-login';
  overlay.className = 'ob-overlay';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-label', 'Welcome back');
  overlay.innerHTML = `
    <div class="ob-login-inner">
      <div class="ob-logo-ring">
        <i class="fa-solid fa-bolt ob-logo-icon" aria-hidden="true"></i>
      </div>
      <h1 class="ob-greeting">Welcome back${userName ? ', <span>' + _esc(userName.split(' ')[0]) + '</span>' : ''}</h1>
      <p class="ob-subtext">Your dashboard is ready. Let's get to work.</p>
      <div class="ob-stats" id="ob-login-stats">
        <div class="ob-stat" data-delay="700">
          <i class="fa-solid fa-magnifying-glass ob-stat-icon" aria-hidden="true"></i>
          <span class="ob-stat-label">Lead Finder</span>
          <span class="ob-stat-val">Ready</span>
        </div>
        <div class="ob-stat" data-delay="820">
          <i class="fa-solid fa-bolt ob-stat-icon" aria-hidden="true"></i>
          <span class="ob-stat-label">Site Builder</span>
          <span class="ob-stat-val">Ready</span>
        </div>
        <div class="ob-stat" data-delay="940">
          <i class="fa-solid fa-chart-line ob-stat-icon" aria-hidden="true"></i>
          <span class="ob-stat-label">Revenue</span>
          <span class="ob-stat-val">Tracking</span>
        </div>
      </div>
      <button class="ob-continue" id="ob-login-btn" type="button">
        Go to Dashboard
      </button>
    </div>
  `;
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';

  /* ── Stagger stat pills ─────────────────────────────────────── */
  overlay.querySelectorAll('.ob-stat[data-delay]').forEach(el => {
    setTimeout(() => el.classList.add('ob-in'), +el.dataset.delay);
  });

  /* ── Dismiss ────────────────────────────────────────────────── */
  let dismissed = false;
  const dismiss = () => {
    if (dismissed) return;
    dismissed = true;
    overlay.classList.add('ob-exit');
    document.body.style.overflow = '';
    setTimeout(() => overlay.remove(), 600);
  };

  document.getElementById('ob-login-btn').addEventListener('click', dismiss);
  // Auto-dismiss after 3.5 s, but let the user skip it with Escape too.
  setTimeout(dismiss, 3500);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') dismiss(); });

  /* ── Helpers ────────────────────────────────────────────────── */
  function _esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
}());
