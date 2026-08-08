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
<script src="/assets/js/download_helper.js?v=1"></script>

</body>
</html>
