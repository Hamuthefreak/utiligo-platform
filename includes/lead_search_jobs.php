<?php
/**
 * includes/lead_search_jobs.php
 *
 * The queue behind the async lead search.
 *
 * A lead search used to run inside the user's own request — api/find-leads.php
 * sleeps ~3s between Google Places page tokens and then fans out to four more
 * sources, so a multi-page search held a PHP worker for 30-60 seconds. This
 * module moves that work off the request path:
 *
 *   api/find-leads.php        validates + enqueues        (tens of ms)
 *   api/lead-search-status.php polls one job's progress   (one indexed SELECT)
 *   cron/lead_search_worker.php  claims + runs the search (the 30-60s)
 *
 * Job states: queued -> running -> done | error.
 *
 * Concurrency
 * -----------
 * A job is claimed with a single conditional UPDATE
 * (`WHERE token = ? AND status = 'queued'`), so two workers racing the same
 * job can never both run it — the loser's UPDATE matches zero rows. The same
 * trick re-claims a job whose worker died: `lead_search_job_reap()` flips
 * heartbeats older than STALE_SECONDS back to 'queued'.
 *
 * Triggering
 * ----------
 * cron is the reliable trigger (same pattern as cron/build_exports.php), but a
 * once-a-minute cron alone would make the user wait up to a minute, so
 * `lead_search_kick()` also fires a best-effort fire-and-forget HTTP request at
 * the worker from the enqueue and from the first status poll. If outbound HTTP
 * is blocked (as on some shared hosts) the cron still picks the job up. Set
 * UTILIGO_LEAD_SEARCH_KICK=0 to disable the kick.
 *
 * `lead_search_job_reap()` is called from both the enqueue and the poll, so a
 * dead worker self-heals without needing its own cron entry.
 */

require_once __DIR__ . '/error_logger.php';

/** How long a 'running' job may go without a heartbeat before it is re-queued. */
if (!defined('LEAD_SEARCH_JOB_STALE_SECONDS')) define('LEAD_SEARCH_JOB_STALE_SECONDS', 180);
/** How many times a job may be (re)claimed before it is failed outright. */
if (!defined('LEAD_SEARCH_JOB_MAX_ATTEMPTS'))  define('LEAD_SEARCH_JOB_MAX_ATTEMPTS', 3);
/**
 * How many queued/running jobs one user may hold at once.
 *
 * One. The UI already disables the search button while a search is running, so
 * a second submit means a double-click or a second tab — and api/find-leads.php
 * answers those by handing back the search that is already in flight rather
 * than starting a second one. With the cap at 2 a free user (two searches a
 * day) could burn their whole daily allowance with one impatient double-click,
 * because the quota is charged per accepted enqueue.
 */
if (!defined('LEAD_SEARCH_JOB_MAX_PER_USER'))  define('LEAD_SEARCH_JOB_MAX_PER_USER', 1);
/** Completed jobs are deleted after this many hours (keeps the table small). */
if (!defined('LEAD_SEARCH_JOB_RETENTION_HOURS')) define('LEAD_SEARCH_JOB_RETENTION_HOURS', 24);

/**
 * True when this request should try to start a worker over HTTP.
 * Never from CLI (the cron runner calls the worker directly).
 */
function lead_search_kick_enabled(): bool {
    if (PHP_SAPI === 'cli') return false;
    if (defined('LEAD_SEARCH_KICK_ENABLED')) return (bool)LEAD_SEARCH_KICK_ENABLED;
    $v = getenv('UTILIGO_LEAD_SEARCH_KICK');
    return !($v === '0' || $v === 'false' || $v === 'off');
}

/**
 * Canonical queue + dependency DDL.
 *
 * Mirrors migrations/024_lead_search_jobs.sql and the `unlocked_leads` block in
 * migrations/006_leads_full_schema.sql. Both are defensive safety-nets for a
 * fresh install where the migration runner hasn't run yet (the InfinityFree
 * first-run path) — the same convention api/find-leads.php and
 * api/bar-status.php already use. Keep them in sync with the migrations.
 */
function lead_search_jobs_ensure_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS `lead_search_jobs` (
        `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `user_id`          INT UNSIGNED     NOT NULL,
        `auto`             TINYINT(1)       NOT NULL DEFAULT 0,
        `token`            CHAR(32)         NOT NULL,
        `status`           VARCHAR(12)      NOT NULL DEFAULT \'queued\',
        `params_json`      TEXT             NOT NULL,
        `progress`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `stage`            VARCHAR(80)      NOT NULL DEFAULT \'\',
        `result_json`      MEDIUMTEXT       NULL,
        `error_code`       VARCHAR(40)      NOT NULL DEFAULT \'\',
        `error_message`    VARCHAR(500)     NOT NULL DEFAULT \'\',
        `error_extra_json` TEXT             NULL,
        `attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `worker`           VARCHAR(64)      NOT NULL DEFAULT \'\',
        `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `kicked_at`        DATETIME         NULL,
        `started_at`       DATETIME         NULL,
        `heartbeat_at`     DATETIME         NULL,
        `finished_at`      DATETIME         NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_token` (`token`),
        KEY `idx_status_created` (`status`, `created_at`),
        KEY `idx_user_created` (`user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // `auto` arrives with migration 025. A table created before it is missing the
    // column, and CREATE TABLE IF NOT EXISTS above will not add it — mirror the
    // pattern migrations/022 columns use in includes/lead_search_runner.php.
    try {
        // Checked directly rather than through lead_search_runner.php's helper:
        // that one is declared inside lead_search_run(), so it does not exist
        // until a search has already run.
        $cols = $pdo->query('SHOW COLUMNS FROM `lead_search_jobs`')->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('auto', $cols, true)) {
            $pdo->exec('ALTER TABLE `lead_search_jobs` ADD COLUMN `auto` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_id`');
        }
    } catch (\Throwable $e) {
        // 1060 = duplicate column, which just means somebody else won the race.
        if (strpos($e->getMessage(), '1060') === false) {
            log_error('lead_search_jobs_ensure_tables_auto', $e);
        }
    }

    // The enqueue path counts unlocked_leads to enforce the Pro lead cap, so
    // this table has to exist before the runner ever touches it.
    $pdo->exec('CREATE TABLE IF NOT EXISTS `unlocked_leads` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`     INT UNSIGNED NOT NULL,
        `lead_id`     INT UNSIGNED NOT NULL,
        `unlocked_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_lead` (`user_id`,`lead_id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}

/** Fresh opaque job id handed to the browser. */
function lead_search_new_token(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Insert a job in the 'queued' state.
 *
 * @param PDO   $pdo
 * @param int   $uid     Owner.
 * @param array $params  Search params (city/industry/keywords/lead_count/sources/force_refresh).
 * @param bool  $auto    true when cron/scheduled_searches.php started this, not
 *                       the customer. Stored as a column rather than a key in
 *                       params_json so the "is a search already in flight?"
 *                       check can exclude it in SQL — see
 *                       lead_search_job_active_count().
 * @return array The job row (id, token, status, created_at).
 */
function lead_search_job_enqueue(PDO $pdo, int $uid, array $params, bool $auto = false): array {
    lead_search_jobs_ensure_tables($pdo);
    $token = lead_search_new_token();
    $pdo->prepare('INSERT INTO lead_search_jobs (user_id, auto, token, status, params_json, stage, created_at)
                   VALUES (?, ?, ?, \'queued\', ?, ?, NOW())')
        ->execute([$uid, $auto ? 1 : 0, $token, json_encode($params), 'Queued']);
    return [
        'id'     => (int)$pdo->lastInsertId(),
        'token'  => $token,
        'status' => 'queued',
    ];
}

/**
 * How many queued/running jobs this user already holds.
 *
 * $includeAuto defaults to true so this stays the literal question it used to be.
 * The one caller that passes false is the interactive enqueue in
 * api/find-leads.php, and the reason is worth stating: a scheduled search uses
 * the same queue, so counting it here would make a customer's own click look like
 * a duplicate submit. They would then be handed the automation's job — its
 * progress, its results — and their chosen city would be silently ignored.
 */
function lead_search_job_active_count(PDO $pdo, int $uid, bool $includeAuto = true): int {
    $sql = "SELECT COUNT(*) FROM lead_search_jobs
             WHERE user_id = ? AND status IN ('queued','running')";
    if (!$includeAuto) {
        $sql .= ' AND auto = 0';
    }
    $s = $pdo->prepare($sql);
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
}

/**
 * How many automatic runs this user has in flight.
 *
 * The automation may only hold one at a time. A customer with five saved
 * searches all due at once would otherwise be five concurrent Places crawls, and
 * the per-user cap that bounds a human's clicking says nothing about it.
 */
function lead_search_job_active_auto_count(PDO $pdo, int $uid): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM lead_search_jobs
                         WHERE user_id = ? AND auto = 1 AND status IN ('queued','running')");
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
}

/**
 * Fetch a job, scoped to its owner.
 *
 * Ownership is part of the WHERE clause rather than a PHP check afterwards, so
 * a guessed token can never read another account's params or results.
 */
function lead_search_job_get(PDO $pdo, int $uid, string $token): ?array {
    if ($token === '' || strlen($token) !== 32 || !ctype_xdigit($token)) return null;
    $s = $pdo->prepare('SELECT * FROM lead_search_jobs WHERE token = ? AND user_id = ? LIMIT 1');
    $s->execute([$token, $uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * The browser-facing projection of a job.
 *
 * A done job exposes its full result payload; a failed one exposes the error
 * code plus whatever extra flags the client needs to render the right message
 * (limit_reached / rate_limited / resets_at). Everything in flight is just
 * progress.
 */
function lead_search_job_view(array $row): array {
    $base = [
        'token'    => (string)$row['token'],
        'status'   => (string)$row['status'],
        'progress' => (int)$row['progress'],
        'stage'    => (string)$row['stage'],
    ];
    if ($row['status'] === 'done') {
        $result = json_decode((string)$row['result_json'], true);
        $base['result'] = is_array($result) ? $result : [];
    } elseif ($row['status'] === 'error') {
        $base['error']      = (string)$row['error_message'];
        $base['error_code'] = (string)$row['error_code'];
        $extra = json_decode((string)$row['error_extra_json'], true);
        if (is_array($extra)) $base += $extra;
    }
    return $base;
}

/** Unique-per-invocation worker stamp, so re-selecting the claimed row is unambiguous. */
function lead_search_worker_id(): string {
    $host = function_exists('gethostname') ? (string)@gethostname() : 'host';
    return substr($host . '-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 64);
}

/**
 * Atomically claim a job to run.
 *
 * @param PDO         $pdo
 * @param string      $worker   Stamp from lead_search_worker_id().
 * @param string|null $token    Claim this specific job (the kick path), or null
 *                              for "the oldest queued job" (the cron path).
 * @return array|null The claimed row, or null if there was nothing to claim
 *                    (which includes losing the race to another worker).
 */
function lead_search_job_claim(PDO $pdo, string $worker, ?string $token = null): ?array {
    lead_search_jobs_ensure_tables($pdo);
    $set = "status = 'running', worker = ?, attempts = attempts + 1,
            started_at = NOW(), heartbeat_at = NOW(),
            progress = 1, stage = 'Starting', error_code = '', error_message = '', error_extra_json = NULL";

    if ($token !== null) {
        $upd = $pdo->prepare("UPDATE lead_search_jobs SET $set WHERE token = ? AND status = 'queued' LIMIT 1");
        $upd->execute([$worker, $token]);
        if ($upd->rowCount() !== 1) return null;      // taken, finished, or unknown
    } else {
        $upd = $pdo->prepare("UPDATE lead_search_jobs SET $set WHERE status = 'queued' ORDER BY created_at ASC, id ASC LIMIT 1");
        $upd->execute([$worker]);
        if ($upd->rowCount() !== 1) return null;      // queue empty (or lost the race)
    }

    // The worker stamp is unique to this invocation, so this re-select can only
    // return the row this call just claimed.
    $s = $pdo->prepare('SELECT * FROM lead_search_jobs WHERE worker = ? AND status = \'running\' LIMIT 1');
    $s->execute([$worker]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Record progress. Best-effort — a dead worker must not fail the job on a lost heartbeat. */
function lead_search_job_progress(PDO $pdo, int $jobId, int $pct, string $stage): void {
    try {
        $pdo->prepare('UPDATE lead_search_jobs SET progress = ?, stage = ?, heartbeat_at = NOW() WHERE id = ?')
            ->execute([max(0, min(100, $pct)), substr($stage, 0, 80), $jobId]);
    } catch (\Throwable $e) { /* progress is cosmetic */ }
}

/** Store the successful result payload. */
function lead_search_job_finish(PDO $pdo, int $jobId, array $result): void {
    $pdo->prepare("UPDATE lead_search_jobs
                      SET status = 'done', progress = 100, stage = 'Done',
                          result_json = ?, finished_at = NOW(), heartbeat_at = NOW(),
                          error_code = '', error_message = '', error_extra_json = NULL
                    WHERE id = ?")
        ->execute([json_encode($result), $jobId]);
}

/**
 * Store a failure.
 *
 * @param array $extra Client-visible flags (limit_reached, rate_limited, resets_at, …).
 */
function lead_search_job_fail(PDO $pdo, int $jobId, string $code, string $message, array $extra = []): void {
    $pdo->prepare("UPDATE lead_search_jobs
                      SET status = 'error', stage = 'Failed',
                          error_code = ?, error_message = ?, error_extra_json = ?,
                          finished_at = NOW(), heartbeat_at = NOW()
                    WHERE id = ?")
        ->execute([substr($code, 0, 40), substr($message, 0, 500), $extra ? json_encode($extra) : null, $jobId]);
}

/**
 * Run one step of crash recovery.
 *
 * A worker that dies mid-search (host kills the process, fatal error, restart)
 * leaves a 'running' row with a stale heartbeat that would otherwise block the
 * user's poll forever. Re-queue it while attempts remain, otherwise fail it
 * with a clear message so the browser stops waiting.
 *
 * @return int Number of jobs touched.
 */
function lead_search_job_reap(PDO $pdo, int $staleSeconds = LEAD_SEARCH_JOB_STALE_SECONDS, int $maxAttempts = LEAD_SEARCH_JOB_MAX_ATTEMPTS): int {
    try {
        $requeue = $pdo->prepare("UPDATE lead_search_jobs
                                     SET status = 'queued', worker = '', stage = 'Queued (retry)',
                                         heartbeat_at = NULL
                                   WHERE status = 'running'
                                     AND attempts < ?
                                     AND (heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND))");
        $requeue->execute([$maxAttempts, $staleSeconds]);
        $n = $requeue->rowCount();

        $fail = $pdo->prepare("UPDATE lead_search_jobs
                                  SET status = 'error', stage = 'Failed',
                                      error_code = 'worker_lost',
                                      error_message = 'The search worker stopped before finishing. Please try again.',
                                      finished_at = NOW()
                                WHERE status = 'running'
                                  AND attempts >= ?
                                  AND (heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND))");
        $fail->execute([$maxAttempts, $staleSeconds]);
        return $n + $fail->rowCount();
    } catch (\Throwable $e) {
        log_error('lead_search_job_reap', $e);
        return 0;
    }
}

/** Delete old finished jobs so the queue table doesn't grow without bound. */
function lead_search_job_purge(PDO $pdo, ?int $hours = null): int {
    $hours = $hours ?? LEAD_SEARCH_JOB_RETENTION_HOURS;
    try {
        $s = $pdo->prepare("DELETE FROM lead_search_jobs
                             WHERE status IN ('done','error')
                               AND finished_at IS NOT NULL
                               AND finished_at < DATE_SUB(NOW(), INTERVAL ? HOUR)");
        $s->execute([$hours]);
        return $s->rowCount();
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Absolute URL of the worker endpoint, for the fire-and-forget kick. */
function lead_search_worker_url(?string $token = null, ?string $secret = null): string {
    $secret = $secret ?? (defined('CRON_SECRET') ? CRON_SECRET : '');
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
        $base = rtrim(APP_BASE_URL, '/');
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
    } else {
        $base = '';
    }
    $url = $base . '/cron/lead_search_worker.php?secret=' . urlencode($secret);
    if ($token !== null) $url .= '&job=' . urlencode($token);
    return $url;
}

/**
 * Best-effort nudge that starts the worker right now.
 *
 * Fire-and-forget: we open the connection, write the request, and close without
 * reading a byte of the response, so the caller is not held for the worker's
 * duration. Every failure is silent by design — cron is the backstop. Returns
 * true when the request was at least written to a live socket (useful for tests
 * and logging; callers must not depend on it).
 */
function lead_search_kick(?string $token = null): bool {
    if (!lead_search_kick_enabled()) return false;

    // Prefer the host we are actually serving on so the kick loops back to this
    // very deployment (important behind proxies and in tests), falling back to
    // the configured base URL.
    $url = lead_search_worker_url($token);
    $parts = @parse_url(str_replace('&amp;', '&', $url));
    if (!is_array($parts) || empty($parts['host'])) return false;

    $scheme = $parts['scheme'] ?? 'http';
    $port   = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $target = ($scheme === 'https' ? 'ssl://' : 'tcp://') . $parts['host'] . ':' . $port;

    $fp = @fsockopen($target, $port, $errno, $errstr, 1.0);
    if (!$fp) {
        // Loopback isn't always reachable (or the wrapper is missing). The cron
        // backstop handles it; log at debug volume only.
        if (function_exists('leads_log_debug')) {
            leads_log_debug('kick_failed', ['errno' => $errno, 'errstr' => $errstr, 'has_token' => $token !== null]);
        }
        return false;
    }
    stream_set_timeout($fp, 1);
    $req = "GET $path HTTP/1.1\r\n"
         . "Host: {$parts['host']}\r\n"
         . "Connection: close\r\n"
         . "User-Agent: utiligo-lead-search-kick\r\n\r\n";
    @fwrite($fp, $req);
    @fclose($fp);   // deliberately not reading the response — fire and forget
    return true;
}

/** Mark that a kick has been attempted for a job (throttles repeat kicks). */
function lead_search_job_mark_kicked(PDO $pdo, int $jobId): void {
    try {
        $pdo->prepare('UPDATE lead_search_jobs SET kicked_at = NOW() WHERE id = ?')->execute([$jobId]);
    } catch (\Throwable $e) {}
}

/**
 * Should we kick again for a job that is still sitting in 'queued'?
 *
 * Throttled by kicked_at so a 1.5s poll interval doesn't open a socket every
 * poll while the first kick is still spinning up a worker.
 */
function lead_search_job_should_kick(array $row, int $cooldownSeconds = 15): bool {
    if (($row['status'] ?? '') !== 'queued') return false;
    if (empty($row['kicked_at'])) return true;
    return (time() - strtotime((string)$row['kicked_at'])) >= $cooldownSeconds;
}
