<?php
/**
 * api/lead-count.php
 * Lightweight endpoint — returns the real unlocked lead count for the
 * logged-in user directly from the DB. Called on page load AND after
 * every search so the bar is always accurate regardless of cache.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();
header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'count' => 0, 'limit' => 0]);
    exit;
}

$user = current_user();
$plan = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro', 'entrepreneur'], true);

if (!$is_paid) {
    echo json_encode(['success' => true, 'count' => 0, 'limit' => 0, 'plan' => $plan]);
    exit;
}

$count = 0;
try {
    $pdo  = get_platform_db();
    // Ensure table exists — safe on first ever run
    $pdo->exec('CREATE TABLE IF NOT EXISTS `unlocked_leads` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`     INT UNSIGNED NOT NULL,
        `lead_id`     INT UNSIGNED NOT NULL,
        `unlocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_lead` (`user_id`, `lead_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $count = (int)$stmt->fetchColumn();
} catch (\Throwable $e) {
    log_error('lead_count_fetch', $e, ['user_id' => $user['id'] ?? null]);
}

$limit = ($plan === 'entrepreneur') ? 0 : (defined('PRO_LEAD_LIMIT') ? (int)PRO_LEAD_LIMIT : 120);

echo json_encode([
    'success' => true,
    'count'   => $count,
    'limit'   => $limit,
    'plan'    => $plan,
]);
