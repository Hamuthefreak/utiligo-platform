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
// ── Teleport fixed overlays to <body> ────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  ['leadsRail', 'leadsRailDrawer', 'leadsRailOverlay'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
  });
});

// ── Page entry loader ────────────────────────────────────────────────────
(function () {
  var loader = document.getElementById('page-loader');
  var fill   = document.getElementById('loader-fill');
  if (!loader || !fill) return;

  // Random duration between 480ms and 900ms so it never feels mechanical
  var dur = 480 + Math.floor(Math.random() * 420);
  fill.style.transition = 'width ' + dur + 'ms cubic-bezier(.4,0,.2,1)';

  // Kick the bar to 100% on next paint (transition needs a frame to register)
  requestAnimationFrame(function () {
    requestAnimationFrame(function () {
      fill.style.width = '100%';
    });
  });

  function dismiss() {
    loader.style.transition = 'opacity .32s ease, visibility .32s ease';
    loader.style.opacity    = '0';
    loader.style.visibility = 'hidden';
    setTimeout(function () {
      if (loader.parentNode) loader.parentNode.removeChild(loader);
    }, 360);
  }

  // Dismiss after the bar finishes — fire whichever comes last: bar OR load event
  var barDone  = false;
  var pageDone = false;

  function tryDismiss() {
    if (barDone && pageDone) dismiss();
  }

  // Bar timer
  setTimeout(function () { barDone = true;  tryDismiss(); }, dur + 60);

  // Page load (handles all cases: already loaded, DOMContentLoaded, window.load)
  function markPageDone() { pageDone = true; tryDismiss(); }

  if (document.readyState === 'complete') {
    markPageDone();
  } else {
    // Use DOMContentLoaded as a fallback so heavy pages don't hang forever
    document.addEventListener('DOMContentLoaded', markPageDone, { once: true });
    window.addEventListener('load', markPageDone, { once: true });
  }
})();

// ── Page leave: flash dark overlay when navigating away ──────────────────
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
    setTimeout(function () { window.location.href = dest; }, 180);
  });

  window.addEventListener('pageshow', function (e) {
    if (e.persisted) overlay.classList.remove('flash');
  });
})();
</script>

</body>
</html>
