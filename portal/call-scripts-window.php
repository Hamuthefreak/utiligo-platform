<?php
/**
 * portal/call-scripts-window.php — the call-script dock, in its own window.
 *
 * Deliberately NOT a portal page. It loads no header, no nav, no footer and no
 * layout, because its whole job is to be the panel and nothing else: a window
 * you park on a second monitor next to your dialler, where a page chrome would
 * eat a third of the height and every link would navigate away from the script
 * you are mid-sentence in.
 *
 * It also uses its own document, which is the point — a real window survives the
 * customer clicking through the app in their main tab, and the two copies of the
 * panel stay in step over BroadcastChannel (assets/js/call_scripts.js).
 *
 * Gated server-side like every other door into the feature. Pro only — see
 * can_use_call_scripts() in includes/plans.php for why Entrepreneur is excluded
 * from this one feature — so neither a free nor an Entrepreneur account can
 * reach the panel by typing this URL.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();
$plan = (string)($user['plan'] ?? 'free');

if (!can_use_call_scripts($plan)) {
    header('Location: /portal/billing?upgrade=1&feature=call_scripts');
    exit;
}

$sender = trim((string)($user['full_name'] ?? ''));

/**
 * The panel's own stylesheet plus the icon font it borrows from the CDN. The
 * dock's stylesheet is loaded FIRST and the CDN is allowed to fail — the panel
 * is still fully usable with no icons, and a script that cannot be read because
 * a font request timed out would be the wrong trade entirely.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Call scripts — Utiligo</title>
<link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg">
<link rel="stylesheet" href="<?= asset_url('/assets/css/call_scripts.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  html, body { margin: 0; padding: 0; height: 100%; background: #020817; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
</style>
</head>
<body data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

<script>
  /* The same dock script runs here and in the portal, driven by this config.
     `standalone` is what tells it the panel is the whole page. */
  window.UTILIGO_CALL_SCRIPTS = {
    api: '/api/call-scripts.php',
    standalone: true,
    senderName: <?= json_encode($sender, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
</script>
<script src="<?= asset_url('/assets/js/call_scripts.js') ?>"></script>

</body>
</html>
