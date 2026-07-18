document.addEventListener('DOMContentLoaded', function () {
  const csrfToken = document.body.dataset.csrf;

  function callApi(siteId, action) {
    return fetch('/api/manage-site.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site_id: siteId, action, csrf_token: csrfToken }),
    }).then(r => r.json());
  }

  // ── Extend ───────────────────────────────────────────────────────────
  document.querySelectorAll('.extend-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const id   = this.dataset.id;
      const orig = this.innerHTML;
      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Extending…';

      callApi(id, 'extend').then(data => {
        if (data.success) {
          // Update expiry label in the card without a full reload
          const card = document.querySelector(`[data-site-id="${id}"]`);
          if (card) {
            const expEl = card.querySelector('.expiry-label');
            if (expEl && data.link_expires_at) {
              const diff = new Date(data.link_expires_at) - new Date();
              const days = Math.round(diff / 86400000);
              expEl.textContent = `Expires in ${days} day${days !== 1 ? 's' : ''}`;
              expEl.classList.remove('text-amber-400');
              expEl.classList.add('text-slate-500');
            }
            // Flip badge from Expired → Active
            const badge = card.querySelector('.status-badge');
            if (badge && badge.textContent.trim() === 'Expired') {
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
            card.style.opacity = '0';
            card.style.transform = 'scale(.97)';
            setTimeout(() => card.remove(), 300);
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
