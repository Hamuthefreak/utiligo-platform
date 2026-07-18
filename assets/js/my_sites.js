document.addEventListener('DOMContentLoaded', function () {
  const csrfToken = document.body.dataset.csrf;

  // ── Live expiry countdowns ───────────────────────────────────────────────
  // PHP renders data-expires-at="<ISO timestamp>" on each card.
  // We tick every second and rewrite the label live.
  function formatCountdown(ms) {
    if (ms <= 0) return { text: 'Expired', cls: 'text-red-400' };
    const totalSec = Math.floor(ms / 1000);
    const d = Math.floor(totalSec / 86400);
    const h = Math.floor((totalSec % 86400) / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    let text;
    if (d > 0)      text = `Expires in ${d}d ${h}h`;
    else if (h > 0) text = `Expires in ${h}h ${m}m`;
    else if (m > 0) text = `Expires in ${m}m ${s}s`;
    else            text = `Expires in ${s}s`;
    const cls = d === 0 && h < 24 ? 'text-amber-400' : 'text-slate-400';
    return { text, cls };
  }

  function tickCountdowns() {
    document.querySelectorAll('[data-expires-at]').forEach(el => {
      const ts  = el.dataset.expiresAt;
      if (!ts) return;
      const ms  = new Date(ts) - new Date();
      const { text, cls } = formatCountdown(ms);
      if (el.textContent !== text) {
        el.textContent = text;
        el.className = 'expiry-label text-xs ' + cls;
        // If just expired, flip the card badge too
        if (ms <= 0) {
          const card  = el.closest('[data-site-id]');
          const badge = card && card.querySelector('.status-badge');
          if (badge && badge.textContent.trim() === 'Active') {
            badge.textContent = 'Expired';
            badge.className = 'status-badge text-xs px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400';
          }
        }
      }
    });
  }

  tickCountdowns();
  setInterval(tickCountdowns, 1000);

  // ── API helper ─────────────────────────────────────────────────────────
  function callApi(siteId, action) {
    return fetch('/api/manage-site.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site_id: siteId, action, csrf_token: csrfToken }),
    }).then(r => r.json());
  }

  // ── Extend ────────────────────────────────────────────────────────────
  document.querySelectorAll('.extend-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const id   = this.dataset.id;
      const orig = this.innerHTML;
      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Extending…';

      callApi(id, 'extend').then(data => {
        if (data.success) {
          const card = document.querySelector(`[data-site-id="${id}"]`);
          if (card && data.link_expires_at) {
            // Update the data attribute so the live ticker takes over immediately
            const expEl = card.querySelector('[data-expires-at]');
            if (expEl) expEl.dataset.expiresAt = data.link_expires_at;
            // Flip badge if it was Expired
            const badge = card.querySelector('.status-badge');
            if (badge && (badge.textContent.trim() === 'Expired' || badge.textContent.trim() === 'Inactive')) {
              badge.textContent = 'Active';
              badge.className = 'status-badge text-xs px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400';
            }
          }
          this.innerHTML = '<i class="fa-solid fa-check mr-1"></i>Extended!';
          setTimeout(() => { this.disabled = false; this.innerHTML = orig; }, 2500);
        } else {
          alert(data.error || 'Failed to extend link.');
          this.disabled = false;
          this.innerHTML = orig;
        }
      }).catch(() => {
        alert('Network error. Please try again.');
        this.disabled = false;
        this.innerHTML = orig;
      });
    });
  });

  // ── Delete ───────────────────────────────────────────────────────────
  document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      if (!confirm('Permanently delete this site and all its files? This cannot be undone.')) return;
      const id   = this.dataset.id;
      const orig = this.innerHTML;
      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

      callApi(id, 'delete').then(data => {
        if (data.success) {
          const card = document.querySelector(`[data-site-id="${id}"]`);
          if (card) {
            card.style.transition = 'opacity .3s, transform .3s';
            card.style.opacity    = '0';
            card.style.transform  = 'scale(.97)';
            setTimeout(() => card.remove(), 310);
          }
        } else {
          alert(data.error || 'Failed to delete site.');
          this.disabled = false;
          this.innerHTML = orig;
        }
      }).catch(() => {
        alert('Network error. Please try again.');
        this.disabled = false;
        this.innerHTML = orig;
      });
    });
  });
});
