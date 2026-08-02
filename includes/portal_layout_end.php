<?php
/**
 * includes/portal_layout_end.php
 * Closes the <main> and <body> tags opened by portal_layout.php.
 */
?>
  </div>
</main>

<script>
// ── Teleport fixed overlays to <body> ──────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  ['leadsRail', 'leadsRailDrawer', 'leadsRailOverlay'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
  });
});

// ── Page entry loader ────────────────────────────────────────────────
(function () {
  var loader = document.getElementById('page-loader');
  var fill   = document.getElementById('loader-fill');
  if (!loader || !fill) return;

  // Eye-blink: 120-220ms bar, feels instant but visible
  var dur = 120 + Math.floor(Math.random() * 100);
  fill.style.transition = 'width ' + dur + 'ms cubic-bezier(.4,0,.2,1)';

  // Animate bar to 100% on next paint
  requestAnimationFrame(function () {
    requestAnimationFrame(function () {
      fill.style.width = '100%';
    });
  });

  var dismissed = false;
  function dismiss() {
    if (dismissed) return;
    dismissed = true;
    loader.style.transition  = 'opacity .22s ease, visibility .22s ease';
    loader.style.opacity     = '0';
    loader.style.visibility  = 'hidden';
    setTimeout(function () {
      if (loader.parentNode) loader.parentNode.removeChild(loader);
    }, 240);
  }

  // Fire dismiss after bar finishes — don't wait for page load at all
  // The loader is purely cosmetic; page content renders behind it
  setTimeout(dismiss, dur + 40);

  // Safety net: also dismiss immediately on DOMContentLoaded / load
  // so it NEVER gets stuck on any page
  document.addEventListener('DOMContentLoaded', function () { setTimeout(dismiss, 0); }, { once: true });
  window.addEventListener('load', dismiss, { once: true });

  // Nuclear fallback: no matter what, gone within 800ms
  setTimeout(dismiss, 800);
})();

// ── Page leave: flash dark overlay when navigating away ────────────────
(function () {
  var overlay = document.getElementById('page-leave');
  if (!overlay) return;

  document.addEventListener('click', function (e) {
    var anchor = e.target.closest('a[href]');
    if (!anchor) return;
    var href = anchor.getAttribute('href') || '';
    if (
      href.startsWith('#') ||
      href.startsWith('javascript') ||
      anchor.hasAttribute('download') ||
      anchor.getAttribute('target') === '_blank' ||
      (href.startsWith('http') && !href.startsWith(location.origin))
    ) return;
    if (anchor.hasAttribute('data-modal') || anchor.hasAttribute('data-action')) return;
    e.preventDefault();
    var dest = anchor.href;
    overlay.classList.add('flash');
    setTimeout(function () { window.location.href = dest; }, 160);
  });

  window.addEventListener('pageshow', function (e) {
    if (e.persisted) overlay.classList.remove('flash');
  });
})();
</script>

</body>
</html>
