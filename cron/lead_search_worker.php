<?php
/**
 * cron/lead_search_worker.php — async lead-search worker.
 *
 * Schedules via cPanel / cron once a minute (same pattern as
 * cron/build_exports.php and cron/scheduled_searches.php):
 *   * * * * * curl -s "https://utiligo.ca/cron/lead_search_worker.php?secret=YOUR_CRON_SECRET" > /dev/null
 *
 * It is also the target of the fire-and-forget kick that api/find-leads.php and
 * api/lead-search-status.php send, with `&job=<token>`, so a queued search
 * normally starts within a second instead of waiting for the next cron tick.
 *
 * What it does:
 *   1. Reap jobs whose worker died (stale heartbeat) — re-queue or fail.
 *   2. Claim a job atomically (see lead_search_job_claim()) — either the token
 *      it was kicked for, or the oldest queued job in cron mode.
 *   3. Hand the connection back to the caller, then run the search from
 *      includes/lead_search_runner.php with a progress callback, writing
 *      progress into the job row the browser is polling.
 *
 * Gated by CRON_SECRET (same pattern as the other cron scripts).
 *
 * Note the runner owns the 3s-per-pagetoken sleeps that used to happen inside
 * the user's request. That is the whole point: this process may take a minute
 * and nobody is waiting on a socket for it.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';            // get_user_db() — the owner's plan
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/leads_logger.php';
require_once __DIR__ . '/../includes/lead_search_jobs.php';
require_once __DIR__ . '/../includes/lead_search_runner.php';

header('Content-Type: text/plain; charset=utf-8');

$secret = $_GET['secret'] ?? '';
if (!is_string($secret) || !hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    echo "denied\n";
    exit;
}

@set_time_limit(600);
@ini_set('memory_limit', '512M');

/** Jobs one invocation will run before yielding. */
if (!defined('LEAD_SEARCH_WORKER_MAX_JOBS')) define('LEAD_SEARCH_WORKER_MAX_JOBS', 3);
/** Wall-clock budget for one invocation. */
if (!defined('LEAD_SEARCH_WORKER_MAX_SECONDS')) define('LEAD_SEARCH_WORKER_MAX_SECONDS', 240);

// Hold every diagnostic line until we know whether a job was claimed. If none
// was, this is a cron heartbeat and the lines are the useful output; if one was,
// the socket gets a fixed 2-byte body and the details go to the log instead.
ob_start();

/**
 * Close the HTTP connection so the slow search below has no client attached.
 *
 * Under PHP-FPM that is fastcgi_finish_request(). Everywhere else we emit a
 * fixed-length body and flush it, with ignore_user_abort() so the script keeps
 * running after the peer goes away.
 */
function lead_search_worker_detach(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    ignore_user_abort(true);

    // Run from a shell (a human debugging the queue), there is no client
    // holding a socket — keep the diagnostics readable instead of replacing
    // them with the terse "ok" a kicker gets.
    if (PHP_SAPI === 'cli') return;

    echo "ok\n";

    if (function_exists('fastcgi_finish_request')) {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        fastcgi_finish_request();
        return;
    }
    // Non-FPM: the diagnostics buffered so far are dropped, because we have to
    // commit to a Content-Length before anything leaves the process.
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Connection: close');
    header('Content-Length: 3');
    echo "ok\n";
    @flush();
}

$job_token = isset($_GET['job']) ? trim((string)$_GET['job']) : null;
if ($job_token !== null && ($job_token === '' || strlen($job_token) !== 32 || !ctype_xdigit($job_token))) {
    $job_token = null;   // malformed — fall back to the queue head
}

$pdo = get_platform_db();

// ── Crash recovery ────────────────────────────────────────────────────────
$reaped = lead_search_job_reap($pdo);
if ($reaped > 0) {
    echo "reaped={$reaped}\n";
    leads_log_info('worker_reaped', ['count' => $reaped]);
}

// ── Housekeeping ──────────────────────────────────────────────────────────
// The queue only holds in-flight jobs plus a day of history, so this DELETE is
// tiny and keeping it here avoids a third cron entry.
$purged = lead_search_job_purge($pdo);
if ($purged > 0) leads_log_info('worker_purged', ['count' => $purged]);

/**
 * Resolve the job owner's plan from the user DB.
 *
 * The plan is re-read at run time rather than trusted from enqueue time: a job
 * may sit in the queue while the account upgrades or downgrades, and the
 * runner gates sources and the lead cap on it.
 */
function lead_search_worker_owner(int $uid): ?array {
    try {
        $s = get_user_db()->prepare('SELECT id, plan FROM utiligo_users WHERE id = ? LIMIT 1');
        $s->execute([$uid]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        log_error('lead_search_worker_owner', $e, ['uid' => $uid]);
        return null;
    }
}

/**
 * Claim and run at most one job.
 *
 * @return bool True when a job was claimed (whether or not it succeeded).
 */
function lead_search_worker_run_one(PDO $pdo, ?string $token, int &$budgetSeconds): bool {
    $worker = lead_search_worker_id();

    try {
        $job = lead_search_job_claim($pdo, $worker, $token);
    } catch (\Throwable $e) {
        log_error('lead_search_worker_claim', $e);
        echo "claim_error\n";
        return false;
    }
    if (!$job) return false;

    $jobId  = (int)$job['id'];
    $uid    = (int)$job['user_id'];
    $t0     = microtime(true);
    $params = json_decode((string)$job['params_json'], true);
    if (!is_array($params)) $params = [];

    // From here on nobody is listening on the socket — everything the browser
    // learns about this run goes through the job row.
    lead_search_worker_detach();

    $owner = lead_search_worker_owner($uid);
    if (!$owner) {
        lead_search_job_fail($pdo, $jobId, 'no_account', 'This account no longer exists.');
        leads_log_warn('worker_no_owner', ['uid' => $uid, 'job_id' => $jobId]);
        return true;
    }

    // Throttle progress writes: the runner reports ~10 steps, each a row UPDATE
    // that the user's poll reads. Cheap, but skip exact duplicates.
    $last = ['pct' => -1, 'stage' => ''];
    $on_progress = static function (int $pct, string $stage) use ($pdo, $jobId, &$last): void {
        if ($pct === $last['pct'] && $stage === $last['stage']) return;
        $last = ['pct' => $pct, 'stage' => $stage];
        lead_search_job_progress($pdo, $jobId, $pct, $stage);
    };

    try {
        $result = lead_search_run(
            $params,
            ['id' => $uid, 'plan' => (string)($owner['plan'] ?? 'free')],
            $on_progress
        );
        lead_search_job_finish($pdo, $jobId, $result);
        leads_log_info('worker_job_done', [
            'job_id' => $jobId, 'uid' => $uid,
            'returned' => count($result['leads'] ?? []),
            'from_cache' => !empty($result['from_cache']),
            'ms' => (int)round((microtime(true) - $t0) * 1000),
        ]);
    } catch (LeadSearchException $e) {
        lead_search_job_fail($pdo, $jobId, $e->errorCode, $e->getMessage(), $e->extra);
        leads_log_warn('worker_job_failed', [
            'job_id' => $jobId, 'uid' => $uid, 'code' => $e->errorCode,
            'ms' => (int)round((microtime(true) - $t0) * 1000),
        ]);
    } catch (\Throwable $e) {
        // Anything unexpected still has to terminalize the job, otherwise the
        // browser polls a 'running' row until the stale-heartbeat reaper fires.
        lead_search_job_fail($pdo, $jobId, 'internal_error', 'The search could not be completed. Please try again.');
        leads_log_error('worker_job_crashed', $e, ['job_id' => $jobId, 'uid' => $uid]);
        log_error('lead_search_worker_job', $e, ['job_id' => $jobId, 'uid' => $uid]);
    }

    $budgetSeconds -= (int)ceil(microtime(true) - $t0);
    return true;
}

$budget = (int)LEAD_SEARCH_WORKER_MAX_SECONDS;
$ran    = 0;

// The kick names one job; the cron runs the queue head. Either way we keep
// pulling while there is work and budget left, so a backlog doesn't wait a
// full minute per job.
$nextToken = $job_token;
do {
    $claimed = lead_search_worker_run_one($pdo, $nextToken, $budget);
    if (!$claimed) break;
    $ran++;
    $nextToken = null;                  // subsequent passes take the queue head
} while ($ran < (int)LEAD_SEARCH_WORKER_MAX_JOBS && $budget > 0);

if ($ran === 0) {
    echo "nothing_to_do\n";
} else {
    echo "processed={$ran}\n";
}
