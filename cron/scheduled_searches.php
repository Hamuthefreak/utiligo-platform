<?php
/**
 * cron/scheduled_searches.php — saved searches that run themselves.
 *
 * Schedules via cPanel / cron every 30 minutes:
 *   (every 30 min) curl -s "https://utiligo.ca/cron/scheduled_searches.php?secret=YOUR_CRON_SECRET" > /dev/null
 *
 * WHAT IT USED TO DO
 * ─────────────────
 * For every saved_searches row with notify_email = 1 it looked for leads that
 * OTHER people's searches had already dropped into the shared pool, and emailed
 * the difference. So the Entrepreneur-only promise — "email me new leads on this
 * search" — held only when somebody else happened to search the same city that
 * day. A customer watching a quiet town got an email that said nothing, or no
 * email at all, for as long as they stayed subscribed. The one feature only the
 * top plan has could not find anything on its own.
 *
 * WHAT IT DOES NOW
 * ───────────────
 * Two phases, in one pass:
 *
 *   A. REPORT  For every saved search with a run outstanding (job_token set),
 *              read the finished lead_search_jobs row and email the customer what
 *              that run found. One email per run, and only when it found
 *              something: a daily "0 new leads" is how a useful notification gets
 *              filtered out of somebody's inbox. The drawer shows the last run
 *              and its count for the quiet days.
 *
 *   B. START   For every saved search that is DUE, enqueue a real search — into
 *              the same queue an interactive search uses, so it runs in the
 *              worker with the same retry and crash-recovery behaviour, and
 *              nobody's request is blocked for 30-60 seconds.
 *
 * Runs are enqueued rather than searched inline, so a pass finishes in
 * milliseconds. cron/lead_search_worker.php (every minute) picks the work up; the
 * next pass, half an hour later, delivers it. That lag is the cost of keeping the
 * search out of this request, and half an hour is nothing to a daily email.
 *
 * YIELDING TO THE HUMAN
 * ─────────────────────
 * An automated run is skipped while the customer has a search of their own in
 * flight, and a customer only ever has one automated run at a time. Both matter:
 * the first stops the automation competing for Places quota with the person
 * actually looking at the screen, and the second stops five due saved searches
 * from becoming five concurrent crawls. Because the cadence anchor is stamped
 * when a run STARTS, a deferral costs nothing — the search is simply due again on
 * the next pass.
 *
 * Gated by CRON_SECRET (same pattern as cron/build_exports.php).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';   // get_user_db() — owner email + plan
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/leads_logger.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/lead_activity_log.php';
require_once __DIR__ . '/../includes/lead_search_jobs.php';
require_once __DIR__ . '/../includes/auto_search.php';

header('Content-Type: text/plain; charset=utf-8');

$secret = $_GET['secret'] ?? '';
if (!is_string($secret) || !hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    echo "denied\n";
    exit;
}

@set_time_limit(240);
@ini_set('memory_limit', '256M');

$pdo = get_platform_db();

/* ── Read the work ─────────────────────────────────────────────────────────
 * saved_searches and lead_search_jobs live in the PLATFORM db; accounts live in
 * utiligo_users in the USER db. Those are two databases (and on some hosts two
 * servers), so this must NOT be a SQL JOIN — an earlier version of this file
 * joined a `users` table that exists nowhere in the codebase, which meant the
 * whole feature silently never ran for anybody. Read both sides here and resolve
 * owners in one IN() round-trip.
 */

try {
    $awaitingStmt = $pdo->prepare(
        'SELECT id, user_id, name, params, last_error, job_token
           FROM saved_searches
          WHERE job_token IS NOT NULL
          ORDER BY id ASC
          LIMIT ' . (int)AUTO_SEARCH_MAX_DELIVER
    );
    $awaitingStmt->execute();
    $awaiting = $awaitingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    log_error('scheduled_searches_awaiting', $e);
    echo "pull_error\n";
    exit;
}

try {
    $dueStmt = $pdo->prepare(
        'SELECT id, user_id, name, params, notify_email, run_every_hours, last_enqueued_at, job_token
           FROM saved_searches
          WHERE notify_email = 1
            AND job_token IS NULL
          ORDER BY last_enqueued_at ASC, id ASC
          LIMIT 200'
    );
    $dueStmt->execute();
    $candidates = $dueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    log_error('scheduled_searches_due', $e);
    echo "pull_error\n";
    exit;
}

if (!$awaiting && !$candidates) {
    echo "nothing_to_do\n";
    exit;
}

/* ── Resolve every owner involved, once ─────────────────────────────────── */

$owners = [];
try {
    $ids = array_values(array_unique(array_merge(
        array_map(static fn($r) => (int)$r['user_id'], $awaiting),
        array_map(static fn($r) => (int)$r['user_id'], $candidates)
    )));
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));

    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));

        // full_name + outreach_profile are read for the digest's draft opening
        // line. `SELECT *` is not used here because these rows include password
        // hashes and 2FA secrets. The reduced retry covers an install whose
        // migration runner has not reached 026 yet — a missing column must cost
        // the digest its preview sentence, not the whole cron.
        $withProfile = "SELECT id, email, plan, full_name, outreach_profile FROM utiligo_users WHERE id IN ($ph)";
        $plain       = "SELECT id, email, plan FROM utiligo_users WHERE id IN ($ph)";

        try {
            $us = get_user_db()->prepare($withProfile);
            $us->execute($ids);
            $rows = $us->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            leads_log_warn('scheduled_searches_owner_profile', $e);
            $us = get_user_db()->prepare($plain);
            $us->execute($ids);
            $rows = $us->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($rows as $u) {
            $owners[(int)$u['id']] = $u;
        }
    }
} catch (\Throwable $e) {
    log_error('scheduled_searches_owners', $e);
    echo "pull_error\n";
    exit;
}

$owner = static function (int $uid) use ($owners): ?array {
    return $owners[$uid] ?? null;
};

$base_url = (defined('APP_BASE_URL') && APP_BASE_URL) ? APP_BASE_URL : 'https://utiligo.ca';

/* ── Phase A: report on runs that finished ──────────────────────────────── */

$reported  = 0;
$emails    = 0;
$still_running = 0;
$lost      = 0;

foreach ($awaiting as $row) {
    $ss_id = (int)$row['id'];
    $uid   = (int)$row['user_id'];
    $token = trim((string)$row['job_token']);

    $who = $owner($uid);
    if (!$who) {
        // The account is gone. Nothing to email, and no reason to keep a token
        // pointing at a job nobody owns.
        _sched_finish($pdo, $ss_id, 0, 'The account no longer exists');
        $lost++;
        continue;
    }

    try {
        $job = lead_search_job_get($pdo, $uid, $token);
    } catch (\Throwable $e) {
        log_error('scheduled_searches_job', $e, ['ss_id' => $ss_id]);
        continue;
    }

    if (!$job) {
        // The queue purges finished jobs after a day, so a cron that was down for
        // a weekend leaves a token pointing at nothing. Clear it or this saved
        // search never runs again.
        _sched_finish($pdo, $ss_id, 0, 'The run could not be found');
        $lost++;
        continue;
    }

    $status = (string)($job['status'] ?? '');
    if ($status === 'queued' || $status === 'running') {
        $still_running++;
        continue;   // not finished yet — the next pass will report it
    }

    if ($status !== 'done') {
        // A failed run is recorded but NOT emailed. The reasons a scheduled search
        // fails (no Places key, a quota stop, the database) are not things the
        // customer can fix, and one is enough to have them turning the feature off.
        // The drawer shows last_error.
        _sched_finish($pdo, $ss_id, 0, (string)($job['error_message'] ?? 'The run failed'));
        $reported++;
        continue;
    }

    $result = json_decode((string)($job['result_json'] ?? ''), true);
    $digest = auto_search_digest(is_array($result) ? $result : []);

    if ($digest['count'] > 0 && ($who['email'] ?? '') !== '') {
        $sent = auto_search_send_digest(
            (string)$who['email'],
            $row,
            $digest,
            $base_url,
            outreach_profile_from_user($who)
        );
        if ($sent) {
            $emails++;
            try {
                log_lead_activity($pdo, $uid, LEAD_ACT_NOTIFY_SENT, null, [
                    'ss_id'   => $ss_id,
                    'count'   => $digest['count'],
                    'auto'    => true,
                    'cached'  => $digest['from_cache'],
                ]);
            } catch (\Throwable $e) { /* audit is best effort */ }
        } else {
            // The run succeeded and its report could not be sent. Clear the token
            // anyway: keeping it would stall the search itself, and the search is
            // the thing being paid for. send_email() logs the reason.
            log_error('scheduled_searches_send_failed', null, ['ss_id' => $ss_id, 'uid' => $uid]);
            _sched_finish($pdo, $ss_id, $digest['count'], 'The report could not be emailed');
            $reported++;
            continue;
        }
    }

    _sched_finish($pdo, $ss_id, $digest['count'], '');
    $reported++;
}

/* ── Phase B: start what is due ─────────────────────────────────────────── */

$now         = time();
$started     = 0;
$deferred    = 0;
$ineligible  = 0;

foreach ($candidates as $row) {
    if ($started >= (int)AUTO_SEARCH_MAX_ENQUEUE) {
        break;
    }

    if (!auto_search_is_due($row, $now)) {
        continue;
    }

    $uid = (int)$row['user_id'];
    $who = $owner($uid);

    // An owner who has stopped paying keeps whatever run is already in flight
    // (Phase A reports it) but does not get a new one. can_schedule_searches() is
    // the plan helper rather than a hardcoded string, so the gate follows the
    // plan table.
    if (!$who || ($who['email'] ?? '') === '' || !can_schedule_searches((string)($who['plan'] ?? 'free'))) {
        $ineligible++;
        continue;
    }

    $params = json_decode((string)($row['params'] ?? ''), true);
    $params = is_array($params) ? auto_search_run_params($params) : [];
    if (!auto_search_is_searchable($params)) {
        // Nothing to look for. Stamp the window so this row is not reconsidered
        // every thirty minutes for the rest of its life.
        _sched_stamp_enqueued($pdo, (int)$row['id'], null);
        continue;
    }

    try {
        if (lead_search_job_active_count($pdo, $uid, false) > 0) {
            $deferred++;   // the customer is searching; let them have the slot
            continue;
        }
        if (lead_search_job_active_auto_count($pdo, $uid) > 0) {
            $deferred++;
            continue;
        }
    } catch (\Throwable $e) {
        log_error('scheduled_searches_concurrency', $e, ['uid' => $uid]);
        continue;
    }

    try {
        $job = lead_search_job_enqueue($pdo, $uid, $params, true);
        _sched_stamp_enqueued($pdo, (int)$row['id'], (string)$job['token']);
        $started++;
        leads_log_info('auto_search_enqueued', [
            'ss_id' => (int)$row['id'], 'uid' => $uid,
            'city' => $params['city'], 'industry' => $params['industry'],
        ]);
    } catch (\Throwable $e) {
        log_error('scheduled_searches_enqueue', $e, ['ss_id' => (int)$row['id']]);
    }
}

echo "reported={$reported} emails={$emails} running={$still_running} lost={$lost} "
   . "started={$started} deferred={$deferred} ineligible={$ineligible}\n";

/* ── Helpers ─────────────────────────────────────────────────────────────
 * The token is cleared in the same UPDATE that records the outcome, so a saved
 * search can never be reported twice and can never be blocked by a run that is
 * already over.
 */

/** Record how a run ended and release the saved search. */
function _sched_finish(\PDO $pdo, int $ss_id, int $count, string $error): void
{
    try {
        $pdo->prepare('UPDATE saved_searches
                          SET job_token = NULL, last_run_at = NOW(), last_count = ?, last_error = ?
                        WHERE id = ?')
            ->execute([max(0, $count), substr($error, 0, 200), $ss_id]);
    } catch (\Throwable $e) {
        log_error('scheduled_searches_finish', $e, ['ss_id' => $ss_id]);
    }
}

/**
 * Record that a run has started, and which job it is.
 *
 * $token may be null, for the "there was nothing to search for" case: the window
 * still advances so this row is not re-examined every pass, but there is no job
 * to report on afterwards.
 */
function _sched_stamp_enqueued(\PDO $pdo, int $ss_id, ?string $token): void
{
    try {
        $pdo->prepare('UPDATE saved_searches
                          SET last_enqueued_at = NOW(), job_token = ?, last_error = ?
                        WHERE id = ?')
            ->execute([$token, $token === null ? 'This search has nothing to look for' : '', $ss_id]);
    } catch (\Throwable $e) {
        log_error('scheduled_searches_stamp', $e, ['ss_id' => $ss_id]);
    }
}
