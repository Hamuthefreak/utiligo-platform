<?php
/**
 * includes/portal_layout_end.php
 * Closes the <main> and <body> tags opened by portal_layout.php.
 * Include this at the very end of every portal page.
 */
?>
  </div>
</main>

<script>
// ── Teleport fixed overlays to <body> ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  ['leadsRail', 'leadsRailDrawer', 'leadsRailOverlay'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
  });
});

// ── Page entry: dismiss loader once page is fully painted ────────────────────
(function () {
  var loader = document.getElementById('page-loader');
  if (!loader) return;

  function dismiss() {
    loader.classList.add('fade-out');
    // Remove from DOM after transition so it never blocks clicks
    setTimeout(function () { loader.remove(); }, 400);
  }

  if (document.readyState === 'complete') {
    // Already fully loaded (e.g. bfcache restore)
    setTimeout(dismiss, 120);
  } else {
    window.addEventListener('load', function () {
      setTimeout(dismiss, 120);
    });
  }
})();

// ── Page leave: flash dark overlay when navigating away ─────────────────────
(function () {
  var overlay = document.getElementById('page-leave');
  if (!overlay) return;

  document.addEventListener('click', function (e) {
    // Find closest <a> with a same-origin href that isn't a hash or download
    var anchor = e.target.closest('a[href]');
    if (!anchor) return;

    var href = anchor.getAttribute('href') || '';

    // Skip: hash links, external links, target=_blank, download, javascript:
    if (
      href.startsWith('#') ||
      href.startsWith('javascript') ||
      anchor.hasAttribute('download') ||
      anchor.getAttribute('target') === '_blank' ||
      (href.startsWith('http') && !href.startsWith(location.origin))
    ) return;

    // Skip: links that open modals or trigger JS (data attributes present)
    if (anchor.hasAttribute('data-modal') || anchor.hasAttribute('data-action')) return;

    e.preventDefault();
    var dest = anchor.href;

    overlay.classList.add('flash');
    setTimeout(function () { window.location.href = dest; }, 180);
  });

  // On back/forward (bfcache), make sure overlay is hidden
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) overlay.classList.remove('flash');
  });
})();
</script>

</body>
</html>
