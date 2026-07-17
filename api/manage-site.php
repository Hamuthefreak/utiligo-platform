<?php
/**
 * api/manage-site.php — Handles "extend" and "delete" actions for a
 * generated site from portal/my_sites.php. This endpoint was referenced by
 * assets/js/my_sites.js but did not exist yet, which is why both the
 * "Extend 7 Days" and "Delete" buttons previously did nothing (the fetch()
 * call 404'd silently since the JSON parse of an HTML 404 page threw, and
 * that rejection was not surfaced anywhere in the UI).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();

require_login();
header('Content-Type: application/json');

$user = current_user();
if ($user['plan'] !== 'pro') {
    json_response(['success' => false, 'error' => 'Managing sites is a Pro feature.'], 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!csrf_verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid session.'], 403);
}
if (!rate_limit_check('manage_site', defined('RATE_LIMIT_MANAGE_SITE') ? RATE_LIMIT_MANAGE_SITE : 30)) {
    json_response(['success' => false, 'error' => 'Too many requests. Please wait a moment.'], 429);
}

$siteId = (int)($input['site_id'] ?? 0);
$action = $input['action'] ?? '';

if (!$siteId || !in_array($action, ['extend', 'delete'], true)) {
    json_response(['success' => false, 'error' => 'Invalid request.'], 400);
}

api_try('manage_site', function () use ($siteId, $action, $user) {
    $pdo = get_platform_db();
    $stmt = $pdo->prepare('SELECT * FROM utiligo_generated_sites WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$siteId, $user['id']]);
    $site = $stmt->fetch();

    if (!$site) {
        json_response(['success' => false, 'error' => 'Site not found.'], 404);
    }

    if ($action === 'extend') {
        if (!db_table_has_column($pdo, 'utiligo_generated_sites', 'link_expires_at')) {
            json_response(['success' => false, 'error' => 'Share links are not enabled on this install yet.'], 400);
        }
        // Extend from "now" if already expired, otherwise add 7 days on top
        // of the existing expiry so repeated extensions stack correctly.
        $base = $site['link_expires_at'] && strtotime($site['link_expires_at']) > time()
            ? strtotime($site['link_expires_at'])
            : time();
        $newExpiry = date('Y-m-d H:i:s', $base + 7 * 24 * 60 * 60);

        $pdo->prepare('UPDATE utiligo_generated_sites SET link_expires_at = ?, link_active = 1 WHERE id = ?')
            ->execute([$newExpiry, $siteId]);

        json_response(['success' => true, 'link_expires_at' => $newExpiry]);
    }

    if ($action === 'delete') {
        $slugDir = slugify($site['business_name']) . '-' . $site['id'];
        $siteDir = __DIR__ . '/../assets/uploads/generated_sites/' . $slugDir;
        $zipPath = $site['zip_file_path'] ? __DIR__ . '/../' . ltrim($site['zip_file_path'], '/') : null;

        // Delete generated page files on disk. A failure here shouldn't
        // block the DB row from being removed — orphaned static files are
        // a minor disk-space concern, not a user-facing error — but it is
        // still logged so cleanup can be automated/monitored later.
        if (is_dir($siteDir)) {
            $ok = recursive_delete_directory($siteDir);
            if (!$ok) {
                log_error('manage_site.delete_files', "Could not fully remove site directory: {$siteDir}", ['site_id' => $siteId]);
            }
        }
        if ($zipPath && file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $pdo->prepare('DELETE FROM utiligo_generated_sites WHERE id = ?')->execute([$siteId]);

        if (db_table_has_column($pdo, 'utiligo_site_uploads', 'site_id')) {
            $pdo->prepare('DELETE FROM utiligo_site_uploads WHERE site_id = ?')->execute([$siteId]);
        }

        json_response(['success' => true]);
    }
});
