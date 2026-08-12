<?php
/**
 * api/lead-count.php
 * Lightweight endpoint — returns the real unlocked lead count for the
 * logged-in user directly from the DB. Called on page load AND after
 * every search so the bar is always accurate regardless of cache.
 */
// Ensure session is active before anything reads $_SESSION (e.g. auth checks).
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();
header('Content-Type: application/json');

if (!is_logged_in()) {
    // HTTP 200 + auth_failed:true is preserved on purpose: changing the status
    // to 401 here would turn every expired-session page-load into a console
    // .catch in assets/js/leads.js (which today does r.json() unguarded).
    // auth_failed:true is the explicit contract marker for any future caller
    // that wants to react to expired sessions without parsing count==0.
    echo json_encode(['success' => false, 'auth_failed' => true, 'count' => 0, 'limit' => 0]);
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
    // ⚠ Canonical DDL for unlocked_leads lives in migrations/006_leads_full_schema.sql.
    // The CREATE IF NOT EXISTS here is a defensive safety-net for fresh installs
    // where the migration runner hasn't run yet (InfinityFree first-run path).
    // Keep it in sync with the migration if you alter the schema.
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

// Single source of truth: includes/plans.php honors config AND per-user
// admin overrides. -1 (=unlimited, entrepreneur) is normalized to 0 for the
// JS progress bar, exactly like api/bar-status.php does.
$raw_limit = plan_lead_limit($plan, (int)$user['id']);
$limit     = $raw_limit === -1 ? 0 : $raw_limit;

echo json_encode([
    'success' => true,
    'count'   => $count,
    'limit'   => $limit,
    'plan'    => $plan,
]);
