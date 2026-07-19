<?php
/**
 * api/track_view.php
 * Records a public site preview view.
 * Called by s.php on every public page load.
 * POST { site_id: int }
 * Returns: 200 OK (no body needed)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$siteId = (int)(($_POST['site_id'] ?? 0) ?: ($_GET['site_id'] ?? 0));
if (!$siteId) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

try {
    $pdo = get_platform_db();
    // Increment counter — safe even if column doesn't exist yet (migration adds it)
    $pdo->prepare('UPDATE utiligo_generated_sites SET view_count = COALESCE(view_count,0)+1 WHERE id = ?')
        ->execute([$siteId]);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
