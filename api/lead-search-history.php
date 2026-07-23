<?php
/**
 * api/lead-search-history.php  v2 (rebuild)
 * Returns the last 20 lead searches for the authenticated user.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();
header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success'=>false,'history'=>[]]);
    exit;
}

$uid = (int)current_user()['id'];

try {
    $pdo = get_platform_db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `utiligo_lead_search_history` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`      INT UNSIGNED NOT NULL,
            `city`         VARCHAR(100) NOT NULL,
            `industry`     VARCHAR(100) NOT NULL,
            `keywords`     VARCHAR(255) NOT NULL DEFAULT \'\',
            `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_search` (`user_id`,`city`,`industry`,`keywords`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $s = $pdo->prepare(
        'SELECT city,industry,keywords,result_count,created_at
         FROM utiligo_lead_search_history
         WHERE user_id=?
         ORDER BY created_at DESC
         LIMIT 20'
    );
    $s->execute([$uid]);
    $history = $s->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'history'=>$history]);
} catch (\Throwable $e) {
    log_error('lead_search_history', $e, ['uid'=>$uid]);
    echo json_encode(['success'=>false,'history'=>[]]);
}
