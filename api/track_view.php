<?php
/**
 * api/track_view.php — Beacon endpoint hit by s.php's sendBeacon() call.
 *
 * Does two things for a validated, live site:
 *   1) Increments utiligo_generated_sites.view_count   (all-time counter)
 *   2) Writes a detailed row to utiligo_site_view_log  (for site_analytics.php)
 *
 * Never outputs HTML. Returns 204 always. Never throws.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/site_view_logger.php';

http_response_code(204);
header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Accept site_id from POST body (sendBeacon) or GET (fallback)
$site_id = 0;
if (isset($_POST['site_id']))     $site_id = (int)$_POST['site_id'];
elseif (isset($_GET['site_id']))  $site_id = (int)$_GET['site_id'];

// sendBeacon with URLSearchParams sends as application/x-www-form-urlencoded,
// but if the content type was JSON or empty, php://input parsing is a safe fallback.
if ($site_id <= 0) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        if (preg_match('/site_id=(\d+)/', $raw, $m)) {
            $site_id = (int)$m[1];
        } else {
            $json = json_decode($raw, true);
            if (is_array($json) && !empty($json['site_id'])) {
                $site_id = (int)$json['site_id'];
            }
        }
    }
}

if ($site_id <= 0) exit;

try {
    $pdo = get_platform_db();

    // Only count views for sites that actually exist AND are live + unexpired.
    $stmt = $pdo->prepare("
        SELECT id FROM utiligo_generated_sites
        WHERE id = ?
          AND link_active = 1
          AND (link_expires_at IS NULL OR link_expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([$site_id]);
    if (!$stmt->fetchColumn()) exit;

    // 1) All-time counter — drives the "Total Views" stats everywhere
    $pdo->prepare('UPDATE utiligo_generated_sites SET view_count = view_count + 1 WHERE id = ?')
        ->execute([$site_id]);

    // 2) Detailed log row — drives site_analytics.php (daily chart, devices, referrers)
    log_site_view($site_id, $pdo);

} catch (\Throwable $e) {
    error_log('[track_view] ' . $e->getMessage());
}
exit;
