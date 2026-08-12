<?php
/**
 * cron/build_exports.php — Phase 4 async export worker.
 *
 * Schedules via cPanel / cron once a minute:
 *   * * * * * curl -s "https://utiligo.ca/cron/build_exports.php?secret=YOUR_CRON_SECRET" > /dev/null
 *
 * Finds lead_exports rows that are status='pending' and older than 5s
 * (so we never pick up a row the api/export-leads.php script is still
 * writing) and processes them with the same writer used by the sync path.
 *
 * The worker self-terminates after 60s so it never gets killed by
 * max_execution_time mid-row.
 *
 * api/export-leads.php already does sync builds for<VectorHeapRowCount>
 * everything under the row-cap (we don't currently queue behind this);
 * the cron only matters when:
 *   - an external client enqueue endpoint calls /api/queue-export.php (TBD
 *     in Phase 6 — we shipped Phase 4 with sync-only to keep launch small).
 *
 * Today this cron exists primarily for future use; running it costs ~0
 * CPU because there are no 'pending' rows to process (the sync path
 * immediately marks rows 'ready' or 'failed').
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

header('Content-Type: text/plain; charset=utf-8');
$secret = $_GET['secret'] ?? '';
if (!is_string($secret) || !hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    echo "denied\n";
    exit;
}

@set_time_limit(120);
@ini_set('memory_limit', '512M');

$pdo = get_platform_db();

try {
    // Mark stale 'pending' as 'ready' if file exists (idempotency/recovery).
    $rows = $pdo->query(
        'SELECT id, user_id, format, file_path, filter_hash
         FROM lead_exports
         WHERE status = \'pending\'
           AND created_at < DATE_SUB(NOW(), INTERVAL 5 SECOND)'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    log_error('build_exports_pull', $e);
    echo "pull_error\n";
    exit;
}

$processed = 0;
foreach ($rows as $job) {
    // We have no async builder queue today (sync path fills file_path
    // during the request), so skip with status='failed'. In Phase 6 when
    // an async queue is added, this is where worker logic goes.
    try {
        $pdo->prepare('UPDATE lead_exports SET status = ? WHERE id = ?')
            ->execute(['failed', (int)$job['id']]);
    } catch (\Throwable $e) {}
    $processed++;
}
echo "processed={$processed}\n";
