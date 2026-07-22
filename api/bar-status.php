<?php
/**
 * api/bar-status.php
 * Single source of truth for the leads page stat bars.
 * Returns live DB counts for lead unlocks AND active sites,
 * plus plan limits, so JS can update both bars in one fetch.
 *
 * Plans:
 *   free         => no bars shown (returns counts=0, limits=0)
 *   pro          => lead cap = PRO_LEAD_LIMIT (120), site cap = PRO_SITE_LIMIT (200)
 *   entrepreneur => lead cap = unlimited (limit=0), site cap = ENT_SITE_LIMIT (500)
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
    echo json_encode(['success' => false, 'lead_count' => 0, 'lead_limit' => 0, 'site_count' => 0, 'site_limit' => 0]);
    exit;
}

$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro', 'entrepreneur'], true);
$uid     = (int)$user['id'];

// Free users have no bars — return early
if (!$is_paid) {
    echo json_encode(['success' => true, 'plan' => $plan, 'lead_count' => 0, 'lead_limit' => 0, 'site_count' => 0, 'site_limit' => 0]);
    exit;
}

// Plan limits
$lead_limit = match($plan) {
    'entrepreneur' => 0,   // 0 = unlimited signal to JS
    'pro'          => defined('PRO_LEAD_LIMIT') ? (int)PRO_LEAD_LIMIT : 120,
    default        => 0,
};
$site_limit = match($plan) {
    'entrepreneur' => defined('ENT_SITE_LIMIT') ? (int)ENT_SITE_LIMIT : 500,
    'pro'          => defined('PRO_SITE_LIMIT') ? (int)PRO_SITE_LIMIT : 200,
    default        => 0,
};

$lead_count = 0;
$site_count = 0;

try {
    $pdo = get_platform_db();

    // Ensure tables exist (safe on first run)
    $pdo->exec('CREATE TABLE IF NOT EXISTS `unlocked_leads` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`     INT UNSIGNED NOT NULL,
        `lead_id`     INT UNSIGNED NOT NULL,
        `unlocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_lead` (`user_id`, `lead_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Lead unlocks count
    $s = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id = ?');
    $s->execute([$uid]);
    $lead_count = (int)$s->fetchColumn();

    // Active sites count
    $s2 = $pdo->prepare('SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id = ? AND link_active = 1');
    $s2->execute([$uid]);
    $site_count = (int)$s2->fetchColumn();

} catch (\Throwable $e) {
    log_error('bar_status_fetch', $e, ['uid' => $uid]);
}

echo json_encode([
    'success'     => true,
    'plan'        => $plan,
    'lead_count'  => $lead_count,
    'lead_limit'  => $lead_limit,
    'site_count'  => $site_count,
    'site_limit'  => $site_limit,
]);
