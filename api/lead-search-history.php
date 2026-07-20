<?php
/**
 * api/lead-search-history.php
 * Returns the last 20 lead searches for the authenticated user.
 * Used by the Feature C history sidebar on portal/leads.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'history' => []]);
    exit;
}

$user = current_user();

try {
    $pdo = get_platform_db();
    $stmt = $pdo->prepare(
        'SELECT city, industry, keywords, result_count, created_at
         FROM utiligo_lead_search_history
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 20'
    );
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'history' => $rows]);
} catch (\Throwable $e) {
    // Table may not exist yet on first load — return empty gracefully
    echo json_encode(['success' => true, 'history' => []]);
}
