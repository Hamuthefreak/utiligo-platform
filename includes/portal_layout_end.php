<?php
/**
 * includes/portal_layout_end.php
 * Closes the <main> and <body> tags opened by portal_layout.php.
 * Include this at the very end of every portal page.
 */
// asset_url() lives in functions.php; portal_layout.php normally loaded it.
if (!function_exists('asset_url')) {
    require_once __DIR__ . '/functions.php';
}
?>
  </div>
</main>

<script>
// Teleport any fixed overlays to <body> so they aren't clipped by content containers
document.addEventListener('DOMContentLoaded', function () {
  ['leadsRail', 'leadsRailDrawer', 'leadsRailOverlay'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
  });
});
</script>

<!-- Download confirmation helper: every <a data-download> / .zip-download
     gets progress + a "Downloaded" confirmation toast instead of silently
     loading forever. Loaded from portal_layout_end so every portal page
     (My Sites, Generate, leads) gets it automatically. -->
<script src="<?= asset_url('/assets/js/download_helper.js') ?>"></script>

</body>
</html>
