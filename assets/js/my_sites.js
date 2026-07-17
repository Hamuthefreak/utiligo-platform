document.addEventListener('DOMContentLoaded', function () {
  const csrfToken = document.body.dataset.csrf;

  function callManageApi(siteId, action) {
    return fetch('/api/manage-site.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site_id: siteId, action, csrf_token: csrfToken }),
    }).then((r) => r.json());
  }

  document.querySelectorAll('.extend-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      const id = this.dataset.id;
      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Extending...';
      callManageApi(id, 'extend').then((data) => {
        if (data.success) {
          window.location.reload();
        } else {
          alert(data.error || 'Failed to extend link.');
          this.disabled = false;
          this.innerHTML = '<i class="fa-solid fa-clock-rotate-left mr-1"></i>Extend 7 Days';
        }
      });
    });
  });

  document.querySelectorAll('.delete-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      if (!confirm('Delete this site and its shareable link permanently? This cannot be undone.')) return;
      const id = this.dataset.id;
      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Deleting...';
      callManageApi(id, 'delete').then((data) => {
        if (data.success) {
          const card = document.querySelector(`[data-site-id="${id}"]`);
          if (card) card.remove();
        } else {
          alert(data.error || 'Failed to delete site.');
          this.disabled = false;
          this.innerHTML = '<i class="fa-solid fa-trash mr-1"></i>Delete';
        }
      });
    });
  });
});
