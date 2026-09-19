<?php
/**
 * api/lead-search-status.php  v1
 *
 * Poll target for the async lead search. The browser calls this after
 * api/find-leads.php has enqueued a job, roughly every 1.5 seconds, until the
 * job reports done or error.
 *
 * GET param: token (the job token handed back by api/find-leads.php)
 *
 * Responses
 * ---------
 *   in flight  { success:true, job:{token,status,progress,stage} }
 *   done       { success:true, job:{...,status:'done',result:{...}} }
 *   failed     { success:true, job:{...,status:'error',error,error_code,<extra>} }
 *   unknown    { success:false, error:'Search not found.' }   (HTTP 404)
 *
 * The lookup is scoped by user_id as well as token, so one account can never
 * read another's params or results even with a guessed token.
 *
 * Cost is one indexed SELECT on the poll path — no writes, no reaping, no
 * kicking, unless something actually needs doing (see below). That matters:
 * a 40-second search polls this ~27 times.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/lead_search_jobs.php';

api_bootstrap();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}
$user  = current_user();
$uid   = (int)$user['id'];
$token = trim((string)($_GET['token'] ?? ''));

try {
    $pdo = get_platform_db();
    $row = lead_search_job_get($pdo, $uid, $token);
} catch (\Throwable $e) {
    log_error('lead_search_status_db', $e, ['uid' => $uid]);
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Search status unavailable. Please try again.']);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Search not found.', 'error_code' => 'not_found']);
    exit;
}

// ── Recovery ──────────────────────────────────────────────────────────────
// A worker that died mid-search leaves a 'running' row whose heartbeat has
// stopped. We only pay for the reaper when the user is actually watching such
// a job, which is exactly the moment it matters — otherwise the next cron tick
// would clear it anyway.
if ($row['status'] === 'running'
    && (empty($row['heartbeat_at']) || strtotime((string)$row['heartbeat_at']) < time() - LEAD_SEARCH_JOB_STALE_SECONDS)) {
    lead_search_job_reap($pdo);
    $row = lead_search_job_get($pdo, $uid, $token) ?? $row;
}

// ── Keep a queued job moving ──────────────────────────────────────────────
// The enqueue's kick can be lost (worker busy, socket refused, outbound HTTP
// blocked for a moment). Rather than leave the user watching "Queued" until
// the next cron tick, retry the kick on the poll path — throttled by kicked_at
// so a 1.5s poll interval doesn't open a socket every time.
if (lead_search_kick_enabled() && lead_search_job_should_kick($row)) {
    lead_search_job_mark_kicked($pdo, (int)$row['id']);
    lead_search_kick((string)$row['token']);
}

echo json_encode(['success' => true, 'job' => lead_search_job_view($row)]);
