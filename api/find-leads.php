<?php
/**
 * api/find-leads.php  v6.0
 *
 * CHANGES FROM v5.6
 * =================
 * The search no longer runs here. This endpoint now does only the cheap,
 * request-shaped work — auth, CSRF, rate limit, validation, the free-search
 * quota and the Pro lead cap — then enqueues a job and returns immediately.
 *
 * Why: v5.6 ran the whole search inline, and the search sleeps ~3s between
 * Google Places page tokens plus a 15s timeout per source fetch. A three-page
 * search therefore held a PHP worker for 30-60 seconds, and two of them could
 * tie up the whole FPM pool on shared hosting.
 *
 * The heavy half now lives in includes/lead_search_runner.php and runs in
 * cron/lead_search_worker.php. The browser polls api/lead-search-status.php for
 * progress and picks up the result when the job is done — see the queue in
 * includes/lead_search_jobs.php.
 *
 * Response on success:
 *   { success: true, async: true, job: {token, status, progress, stage}, ... }
 * The client keeps the token and polls. All the old error shapes
 * (limit_reached / rate_limited / resets_at) are unchanged.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/lead_activity_log.php';
require_once __DIR__ . '/../includes/leads_logger.php';
require_once __DIR__ . '/../includes/lead_search_jobs.php';
require_once __DIR__ . '/../includes/lead_search_runner.php';   // LeadSearchException

api_bootstrap();
header('Content-Type: application/json');

// ── helpers ───────────────────────────────────────────────────────────────
function _leads_json_fail(string $msg, int $code = 200, array $extra = []): void {
    if ($code !== 200) http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'error' => $msg], $extra));
    exit;
}

// ── 1. Auth ───────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    leads_log_warn('auth_fail', ['reason' => 'not_logged_in']);
    _leads_json_fail('Not logged in.', 401);
}
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro', 'entrepreneur'], true);
$is_ent  = $plan === 'entrepreneur';
$uid     = (int)$user['id'];

// ── 2. Rate limit ─────────────────────────────────────────────────────────
// Counts enqueues, not searches: the limit exists to stop a runaway client
// hammering the queue, and one search is still one enqueue.
if (!rate_limit_check('find_leads', (int)RATE_LIMIT_FIND_LEADS)) {
    leads_log_warn('rate_limited', ['uid' => $uid, 'plan' => $plan]);
    _leads_json_fail('Too many requests. Please wait a moment.', 429, ['rate_limited' => true]);
}

// ── 3. Parse + validate ───────────────────────────────────────────────────
$raw_input = file_get_contents('php://input');
$body = json_decode($raw_input, true);
if (!is_array($body)) {
    leads_log_warn('bad_request', ['raw' => substr($raw_input, 0, 120)]);
    _leads_json_fail('Invalid request.', 400);
}
$city      = trim((string)($body['city']      ?? ''));
$industry  = trim((string)($body['industry']  ?? ''));
$keywords  = trim((string)($body['keywords']  ?? ''));
$force     = !empty($body['force_refresh']);
$req_count = max(1, min(40, (int)($body['lead_count'] ?? 10)));

// Phase 1: optional source multi-selector from the UI.  If the client sends
// an array of source keys, we restrict to the intersection with the user's
// plan-allowed sources.  Default (no sources[] in body) = all plan sources.
$requested_sources = [];
if (isset($body['sources']) && is_array($body['sources'])) {
    foreach ($body['sources'] as $s) {
        $s = trim((string)$s);
        if ($s !== '') $requested_sources[$s] = true;
    }
}
$allowed_sources = plan_lead_sources($plan);
$active_sources = $requested_sources
    ? array_values(array_intersect($allowed_sources, array_keys($requested_sources)))
    : $allowed_sources;
// Always default to at least Google if both sides are empty (the legacy
// behavior — Google only — was the universal behavior before Phase 1).
if (!$active_sources) $active_sources = ['google_places'];

if (!csrf_verify($body['csrf_token'] ?? null)) {
    leads_log_warn('csrf_fail', ['uid' => $uid]);
    _leads_json_fail('Security check failed. Please refresh the page.', 403);
}
if ($city === '' || $industry === '')                                        _leads_json_fail('City and industry are required.');
if (strlen($city) > 100 || strlen($industry) > 100)                        _leads_json_fail('Input too long.');
if (!preg_match('/^[\p{L}0-9 \-\',.]+$/u',   $city) ||
    !preg_match('/^[\p{L}0-9 \-\',.&]+$/u', $industry))                _leads_json_fail('Invalid characters in city or industry.');

// ── 4. DB connect (3 attempts, 250 ms back-off) ───────────────────────────
$pdo = null;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try { $pdo = get_platform_db(); break; }
    catch (\Throwable $e) {
        leads_log_warn('db_connect_attempt', ['attempt' => $attempt, 'error' => $e->getMessage()]);
        if ($attempt < 3) usleep(250_000);
    }
}
if (!$pdo) {
    leads_log_error('db_connect_failed', ['uid' => $uid]);
    log_error('find_leads_db_connect_failed', 'All 3 attempts failed', ['uid' => $uid]);
    _leads_json_fail('Database unavailable. Please try again shortly.');
}

// ── 5. Queue + dependency tables ──────────────────────────────────────────
// The DDL lives in migrations/024_lead_search_jobs.sql; the ensure helpers are
// the defensive safety-net for a fresh install where the migration runner
// hasn't run yet (the InfinityFree first-run path).
try {
    lead_search_jobs_ensure_tables($pdo);
} catch (\Throwable $e) {
    leads_log_error('queue_bootstrap', $e, ['uid' => $uid]);
    log_error('find_leads_queue_bootstrap', $e);
    _leads_json_fail('Search queue unavailable. Please try again shortly.');
}

// Crash recovery, run opportunistically on the enqueue path as well as the
// poll path so a dead worker self-heals without its own cron entry.
lead_search_job_reap($pdo);

// ── 6. Pro lead-limit check (with per-user override) ───────────────────────
preload_user_limit_overrides($uid);   // primes the limit-override cache
$pro_lead_limit = plan_lead_limit($plan, $uid);
if ($is_paid && !$is_ent && $pro_lead_limit > 0) {
    try {
        $lc_check = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
        $lc_check->execute([$uid]);
        $current_lead_count = (int)$lc_check->fetchColumn();
        if ($current_lead_count >= $pro_lead_limit) {
            leads_log_info('limit_blocked', ['uid' => $uid, 'count' => $current_lead_count, 'limit' => $pro_lead_limit]);
            _leads_json_fail(
                'You have reached your ' . $pro_lead_limit . ' lead limit on the Pro plan. Upgrade to Entrepreneur for unlimited leads.',
                200,
                ['limit_reached' => true, 'lead_count' => $current_lead_count, 'lead_limit' => $pro_lead_limit]
            );
        }
        $remaining = $pro_lead_limit - $current_lead_count;
        if ($req_count > $remaining) {
            leads_log_info('req_count_capped', ['uid' => $uid, 'from' => $req_count, 'to' => $remaining]);
            $req_count = $remaining;
        }
    } catch (\Throwable $e) {
        leads_log_error('limit_check', $e, ['uid' => $uid]);
        log_error('find_leads_limit_check', $e);
    }
}

// ── 7. Is a search already in flight? ─────────────────────────────────────
// Checked BEFORE the free quota is charged. A double-click, a duplicate submit
// or an impatient reload must not spend two of a free user's two daily
// searches on a single search — the old synchronous endpoint could not have
// this problem, because the second request simply ran a second search.
$job     = null;
$resumed = false;
try {
    // $includeAuto = false: a run the scheduled-search automation started is not
    // this customer's search. Counting it would make their own click look like a
    // duplicate submit, and they would be handed the automation's job — its
    // progress, its results, and none of the params they just chose.
    if (lead_search_job_active_count($pdo, $uid, false) >= LEAD_SEARCH_JOB_MAX_PER_USER) {
        $s = $pdo->prepare("SELECT * FROM lead_search_jobs
                             WHERE user_id = ? AND auto = 0 AND status IN ('queued','running')
                             ORDER BY created_at DESC, id DESC LIMIT 1");
        $s->execute([$uid]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $job     = ['id' => (int)$row['id'], 'token' => (string)$row['token'], 'status' => (string)$row['status']];
            $resumed = true;
            leads_log_info('search_resumed', ['uid' => $uid, 'token' => $row['token'], 'status' => $row['status']]);
        }
    }
} catch (\Throwable $e) {
    // A failed check must not block a legitimate search — fall through and
    // enqueue as normal.
    leads_log_error('resume_check', $e, ['uid' => $uid]);
}

// ── 8. Free quota — stored in USER DB so it always persists ──────────────
// Charged at enqueue time, not at completion: otherwise a client could queue
// dozens of searches and only pay for the ones it waited on. A *resumed*
// search is not charged again, so it reports no counter movement at all.
$searches_used = 0; $searches_remaining = null;
if ($resumed) {
    $searches_used = null; $searches_remaining = null;
}
if (!$resumed && !$is_paid) {
    $daily_limit = plan_search_daily_limit($plan, $uid);  // honors override
    $fingerprint = 'uid_' . $uid;
    try {
        $udb = get_user_db();
        $udb->exec('CREATE TABLE IF NOT EXISTS `lead_search_quota` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `fingerprint`  VARCHAR(80)  NOT NULL,
            `user_id`      INT UNSIGNED NOT NULL,
            `count`        INT UNSIGNED NOT NULL DEFAULT 0,
            `window_start` DATETIME     NOT NULL,
            PRIMARY KEY(`id`),
            UNIQUE KEY `uq_fp`(`fingerprint`),
            KEY `idx_uid`(`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $q = $udb->prepare('SELECT id,count,window_start FROM lead_search_quota WHERE fingerprint=? LIMIT 1');
        $q->execute([$fingerprint]);
        $qrow = $q->fetch(PDO::FETCH_ASSOC);
        if (!$qrow) {
            $udb->prepare('INSERT INTO lead_search_quota (fingerprint,user_id,count,window_start) VALUES(?,?,1,NOW())')->execute([$fingerprint, $uid]);
            $searches_used = 1; $searches_remaining = $daily_limit - 1;
        } elseif ($qrow['window_start'] < $cutoff) {
            $udb->prepare('UPDATE lead_search_quota SET count=1,window_start=NOW() WHERE id=?')->execute([$qrow['id']]);
            $searches_used = 1; $searches_remaining = $daily_limit - 1;
        } else {
            $searches_used = (int)$qrow['count'];
            if ($searches_used >= $daily_limit) {
                leads_log_info('quota_exhausted', ['uid' => $uid]);
                _leads_json_fail('All ' . $daily_limit . ' free searches used today. Upgrade for unlimited.', 200, [
                    'rate_limited' => true,
                    'resets_at' => strtotime($qrow['window_start']) + 86400,
                ]);
            }
            $udb->prepare('UPDATE lead_search_quota SET count=count+1 WHERE id=?')->execute([$qrow['id']]);
            $searches_used++; $searches_remaining = max(0, $daily_limit - $searches_used);
        }
    } catch (\Throwable $e) {
        leads_log_error('quota_check', $e, ['uid' => $uid]);
        log_error('find_leads_quota', $e, ['uid' => $uid]);
        _leads_json_fail('Search quota unavailable. Please try again in a moment.');
    }
}

// ── 9. Enqueue ────────────────────────────────────────────────────────────
// ($job is already set when we are handing back an in-flight search.)
try {
    if ($job === null) {
        $job = lead_search_job_enqueue($pdo, $uid, [
            'city'          => $city,
            'industry'      => $industry,
            'keywords'      => $keywords,
            'lead_count'    => $req_count,
            'sources'       => $active_sources,
            'force_refresh' => $force,
        ]);
        leads_log_info('search_enqueued', [
            'uid' => $uid, 'plan' => $plan, 'city' => $city, 'industry' => $industry,
            'req_count' => $req_count, 'sources' => $active_sources, 'job_id' => $job['id'],
        ]);
    }
} catch (\Throwable $e) {
    leads_log_error('enqueue_failed', $e, ['uid' => $uid]);
    log_error('find_leads_enqueue', $e, ['uid' => $uid]);
    _leads_json_fail('Could not queue the search. Please try again.');
}

// ── 10. Kick the worker (best-effort) ─────────────────────────────────────
// cron/lead_search_worker.php is the reliable trigger, but waiting up to a
// minute for the next cron tick would be a worse experience than the blocking
// version we just replaced, so try to start it right now. Every failure is
// silent and the cron backstop still runs the job.
$kicked = false;
if (!$resumed && lead_search_kick_enabled()) {
    // mark_kicked() first: if the kick succeeds or fails we do not want the
    // very next status poll to open another socket a second later.
    lead_search_job_mark_kicked($pdo, (int)$job['id']);
    $kicked = lead_search_kick($job['token']);
}
// A resumed job that is still queued may have lost its kick; the status poll
// retries it (also throttled), and cron is the backstop for both.

// ── 11. Payload ───────────────────────────────────────────────────────────
echo json_encode([
    'success'            => true,
    'async'              => true,
    'resumed'            => $resumed,
    'kicked'             => $kicked,
    'job'                => [
        'token'    => $job['token'],
        'status'   => $job['status'],
        'progress' => 0,
        'stage'    => 'Queued',
    ],
    // Deliberately no pro_lead_count / lead_limit here: those describe the
    // *result* (how many leads the account holds now) and only the finished job
    // knows them. The client syncs those bars from the result, never from the
    // enqueue acknowledgement.
    'plan'               => $plan,
    'is_paid'            => $is_paid,
    'searches_used'      => $searches_used,
    'searches_remaining' => $searches_remaining,
]);
