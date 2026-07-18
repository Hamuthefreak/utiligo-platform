<?php
/**
 * api/manage-site.php — extend / delete actions for generated sites.
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

// Extend is Pro-only; delete is allowed for all users
if ($action === 'extend' && ($user['plan'] ?? 'free') !== 'pro') {
    json_response(['success' => false, 'error' => 'Extending share links is a Pro feature.'], 403);
}

$pdo  = get_platform_db();
$stmt = $pdo->prepare('SELECT * FROM utiligo_generated_sites WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$siteId, $user['id']]);
$site = $stmt->fetch();

if (!$site) {
    json_response(['success' => false, 'error' => 'Site not found.'], 404);
}

if ($action === 'extend') {
    if (!db_table_has_column($pdo, 'utiligo_generated_sites', 'link_expires_at')) {
        json_response(['success' => false, 'error' => 'Share links not enabled on this install yet.'], 400);
    }
    // Stack on top of existing expiry if still in future; otherwise extend from now.
    $base      = (!empty($site['link_expires_at']) && strtotime($site['link_expires_at']) > time())
                  ? strtotime($site['link_expires_at'])
                  : time();
    $newExpiry = date('Y-m-d H:i:s', $base + 7 * 86400);

    $pdo->prepare('UPDATE utiligo_generated_sites SET link_expires_at = ?, link_active = 1 WHERE id = ?')
        ->execute([$newExpiry, $siteId]);

    json_response(['success' => true, 'link_expires_at' => $newExpiry]);
}

if ($action === 'delete') {
    // Use stored public_slug as folder name — it contains the uniqid suffix.
    $slugDir = $site['public_slug'] ?: (slugify($site['business_name']) . '-' . $site['id']);
    $siteDir = __DIR__ . '/../assets/uploads/generated_sites/' . $slugDir;
    $zipPath = !empty($site['zip_file_path'])
               ? __DIR__ . '/../' . ltrim($site['zip_file_path'], '/')
               : null;

    if (is_dir($siteDir)) {
        $ok = recursive_delete_directory($siteDir);
        if (!$ok) log_error('manage_site.delete_files', "Could not fully remove: {$siteDir}", ['site_id' => $siteId]);
    }
    if ($zipPath && file_exists($zipPath)) @unlink($zipPath);

    $pdo->prepare('DELETE FROM utiligo_generated_sites WHERE id = ?')->execute([$siteId]);

    if (db_table_has_column($pdo, 'utiligo_site_uploads', 'site_id')) {
        $pdo->prepare('DELETE FROM utiligo_site_uploads WHERE site_id = ?')->execute([$siteId]);
    }

    json_response(['success' => true]);
}
