<?php
/**
 * api/bar-status.php  v3
 *
 * Single source of truth for the leads-page stat bars.
 * Returns live DB counts for lead unlocks AND active sites,
 * plus plan limits, so JS can update both bars in one fetch.
 *
 * Limits come from plan_lead_limit() / plan_site_limit() in includes/plans.php,
 * which in turn read from config.php constants.
 * To change any limit: edit config.php only — no other file needs touching.
 *
 * Plans:
 *   free         => no bars (returns counts=0, limits=0)
 *   pro          => lead cap = PRO_LEAD_LIMIT, site cap = PRO_SITE_LIMIT
 *   entrepreneur => lead cap = ENT_LEAD_LIMIT (-1=unlimited), site cap = ENT_SITE_LIMIT
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
    echo json_encode(['success'=>false,'lead_count'=>0,'lead_limit'=>0,'site_count'=>0,'site_limit'=>0]);
    exit;
}

$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);
$uid     = (int)$user['id'];

if (!$is_paid) {
    echo json_encode(['success'=>true,'plan'=>$plan,'lead_count'=>0,'lead_limit'=>0,'site_count'=>0,'site_limit'=>0]);
    exit;
}

// Use helpers — limits come from config.php via plans.php.
// plan_lead_limit() returns -1 for unlimited (entrepreneur); JS treats <=0 as unlimited.
$lead_limit = plan_lead_limit($plan);
$site_limit = plan_site_limit($plan);
$lead_count = 0;
$site_count = 0;
$errors     = [];

try {
    $pdo = get_platform_db();

    // Ensure tables exist (safe on repeated calls)
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `unlocked_leads` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     INT UNSIGNED NOT NULL,
            `lead_id`     INT UNSIGNED NOT NULL,
            `unlocked_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_lead` (`user_id`,`lead_id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (\Throwable $e) { $errors[] = 'create_table: '.substr($e->getMessage(),0,80); }

    // Lead unlock count
    try {
        $s = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
        $s->execute([$uid]);
        $lead_count = (int)$s->fetchColumn();
    } catch (\Throwable $e) {
        log_error('bar_status_lead_count', $e, ['uid'=>$uid]);
        $errors[] = 'lead_count: '.substr($e->getMessage(),0,80);
    }

    // Active sites count
    try {
        $s2 = $pdo->prepare('SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id=? AND link_active=1');
        $s2->execute([$uid]);
        $site_count = (int)$s2->fetchColumn();
    } catch (\Throwable $e) {
        log_error('bar_status_site_count', $e, ['uid'=>$uid]);
        $errors[] = 'site_count: '.substr($e->getMessage(),0,80);
    }

} catch (\Throwable $e) {
    log_error('bar_status_db', $e, ['uid'=>$uid]);
    echo json_encode(['success'=>false,'error'=>'DB unavailable','lead_count'=>0,'lead_limit'=>0,'site_count'=>0,'site_limit'=>0]);
    exit;
}

echo json_encode([
    'success'    => true,
    'plan'       => $plan,
    'lead_count' => $lead_count,
    'lead_limit' => $lead_limit,
    'site_count' => $site_count,
    'site_limit' => $site_limit,
    '_errors'    => $errors,
]);
