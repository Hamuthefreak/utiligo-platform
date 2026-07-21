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
    const el = document.getElementById(id);
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
  });
});
</script>

</body>
</html>
