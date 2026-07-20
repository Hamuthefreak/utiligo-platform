document.addEventListener('DOMContentLoaded', function () {
  const csrfToken = document.body.dataset.csrf;

  // ── Live expiry countdowns ───────────────────────────────────────────────
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
        if (ms <= 0) {
          const card  = el.closest('[data-site-id]');
          const badge = card && card.querySelector('.status-badge');
          if (badge && badge.textContent.trim() === '● Live') {
            badge.textContent = '⏱ Expired';
            badge.className = 'status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30';
          }
        }
      }
    });
  }

  tickCountdowns();
  setInterval(tickCountdowns, 1000);

  // ── API helper ─────────────────────────────────────────────────────────────
  function callApi(siteId, action) {
    return fetch('/api/manage-site.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site_id: siteId, action, csrf_token: csrfToken }),
    }).then(r => r.json());
  }

  // ── Active slot counter helper ─────────────────────────────────────────────
  function adjustActiveSlotCount(delta) {
    // The active count label sits inside the site-limit bar section
    const slotLabel = document.querySelector('[data-active-slots]');
    if (!slotLabel) return;
    const cur = parseInt(slotLabel.dataset.activeSlots || '0');
    const next = Math.max(0, cur + delta);
    slotLabel.dataset.activeSlots = next;
    const limit = parseInt(slotLabel.dataset.slotLimit || '0');
    slotLabel.textContent = next + ' / ' + (limit || '?') + ' used';
  }

  // ── Deactivate ────────────────────────────────────────────────────────────
  document.querySelectorAll('.deactivate-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const id   = this.dataset.id;
      const orig = this.innerHTML;
      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>…';

      callApi(id, 'deactivate').then(data => {
        if (data.success) {
          const card = document.querySelector(`[data-site-id="${id}"]`);
          if (card) {
            const badge = card.querySelector('.status-badge');
            if (badge) {
              badge.textContent = 'Offline';
              badge.className = 'status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/5 text-slate-500';
            }
            // Swap button to Activate
            this.innerHTML = '<i class="fa-solid fa-rotate-right text-[10px]"></i> Activate';
            this.className = 'reactivate-btn inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-white/12 text-slate-400 hover:text-white px-3 py-1.5 rounded-lg font-semibold transition';
            this.disabled  = false;
            this.addEventListener('click', reactivateHandler);
            adjustActiveSlotCount(-1);
          }
        } else {
          alert(data.error || 'Failed to deactivate site.');
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

  // ── Reactivate ────────────────────────────────────────────────────────────
  function reactivateHandler() {
    const id   = this.dataset.id;
    const orig = this.innerHTML;
    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>…';

    callApi(id, 'reactivate').then(data => {
      if (data.success) {
        const card = document.querySelector(`[data-site-id="${id}"]`);
        if (card) {
          const badge = card.querySelector('.status-badge');
          if (badge) {
            badge.textContent = '● Live';
            badge.className = 'status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/10 text-white ring-1 ring-white/20';
          }
          this.innerHTML = '<i class="fa-solid fa-link-slash text-[10px]"></i> Deactivate';
          this.className = 'deactivate-btn inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-amber-500/10 text-slate-400 hover:text-amber-400 px-3 py-1.5 rounded-lg font-semibold transition';
          this.disabled  = false;
          this.addEventListener('click', deactivateHandlerFor(id));
          adjustActiveSlotCount(+1);
        }
      } else {
        alert(data.error || 'Failed to reactivate site.');
        this.disabled = false;
        this.innerHTML = orig;
      }
    }).catch(() => {
      alert('Network error. Please try again.');
      this.disabled = false;
      this.innerHTML = orig;
    });
  }

  function deactivateHandlerFor(id) {
    return function () {
      const orig = this.innerHTML;
      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>…';
      callApi(id, 'deactivate').then(data => {
        if (data.success) {
          const card = document.querySelector(`[data-site-id="${id}"]`);
          if (card) {
            const badge = card.querySelector('.status-badge');
            if (badge) {
              badge.textContent = 'Offline';
              badge.className = 'status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/5 text-slate-500';
            }
            this.innerHTML = '<i class="fa-solid fa-rotate-right text-[10px]"></i> Activate';
            this.className = 'reactivate-btn inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-white/12 text-slate-400 hover:text-white px-3 py-1.5 rounded-lg font-semibold transition';
            this.disabled  = false;
            this.addEventListener('click', reactivateHandler);
            adjustActiveSlotCount(-1);
          }
        } else {
          alert(data.error || 'Failed to deactivate site.');
          this.disabled = false;
          this.innerHTML = orig;
        }
      }).catch(() => {
        alert('Network error. Please try again.');
        this.disabled = false;
        this.innerHTML = orig;
      });
    };
  }

  document.querySelectorAll('.reactivate-btn').forEach(btn => {
    btn.addEventListener('click', reactivateHandler);
  });

  // ── Extend ────────────────────────────────────────────────────────────────
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
            const expEl = card.querySelector('[data-expires-at]');
            if (expEl) expEl.dataset.expiresAt = data.link_expires_at;
            const badge = card.querySelector('.status-badge');
            if (badge && (badge.textContent.trim() === '⏱ Expired' || badge.textContent.trim() === 'Offline')) {
              badge.textContent = '● Live';
              badge.className = 'status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/10 text-white ring-1 ring-white/20';
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

  // ── Delete ───────────────────────────────────────────────────────────────
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
            // Adjust slot count if the site was live
            const badge = card.querySelector('.status-badge');
            if (badge && badge.textContent.trim() === '● Live') adjustActiveSlotCount(-1);
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

  // ── QR Code modal ─────────────────────────────────────────────────────────
  const qrModal     = document.getElementById('qrModal');
  const qrModalImg  = document.getElementById('qrModalImg');
  const qrModalUrl  = document.getElementById('qrModalUrl');
  const qrModalDl   = document.getElementById('qrModalDownload');
  const qrModalClose = document.getElementById('qrModalClose');

  function openQr(url, name) {
    if (!qrModal || !qrModalImg) return;
    const fullUrl = url.startsWith('http') ? url : window.location.origin + url;
    const qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&format=png&ecc=M&data='
      + encodeURIComponent(fullUrl);
    qrModalImg.src = qrSrc;
    qrModalImg.alt = 'QR code — ' + name;
    if (qrModalUrl)  qrModalUrl.textContent  = fullUrl;
    if (qrModalDl)   qrModalDl.href          = qrSrc + '&download=1';
    qrModal.classList.remove('hidden');
    qrModal.setAttribute('aria-hidden', 'false');
  }

  function closeQr() {
    if (!qrModal) return;
    qrModal.classList.add('hidden');
    qrModal.setAttribute('aria-hidden', 'true');
    if (qrModalImg) qrModalImg.src = '';
  }

  document.querySelectorAll('.qr-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      openQr(this.dataset.url, this.dataset.name || 'Site');
    });
  });

  if (qrModalClose) qrModalClose.addEventListener('click', closeQr);
  if (qrModal) {
    qrModal.addEventListener('click', function (e) {
      if (e.target === qrModal) closeQr();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeQr();
  });
});
