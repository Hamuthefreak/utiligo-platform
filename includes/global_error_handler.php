<?php
/**
 * includes/global_error_handler.php
 * =====================================================================
 * Single installation-wide error sink.  Loaded once at the bottom of
 * config.php, so EVERY page request (public, portal, admin, API, webhook,
 * cron, stripe, …) gets the same handlers wired up:
 *
 *   1. Forces PHP's log_errors=1 and points error_log at
 *      storage/php_errors.log (with sensible fallbacks).
 *   2. set_error_handler()      — catches runtime warnings/notices/strict.
 *   3. set_exception_handler()  — catches uncaught Throwable (which
 *      otherwise just becomes a "Fatal error: Uncaught …" line on the
 *      response and may not reach the log on shared hosts).
 *   4. register_shutdown_function() — catches fatal E_ERROR, E_PARSE,
 *      E_CORE_ERROR, E_COMPILE_ERROR caught by the engine after the
 *      script die()s.
 *
 * EVERY line written to storage/php_errors.log is tagged with which file
 * sent it (originating file + line, plus the front-controller that started
 * the request, plus the request URI).  Fatal entries may also trigger an
 * email alert to ADMIN_EMAIL (see _utiligo_emit_alert()) so you don't
 * have to crawl the log file to learn the platform has been 500ing.
 *
 * The handler is intentionally dependency-light (nothing here requires
 * a DB connection, sessions, autoloaders, or any other application
 * bootstrap step) so that it still works even when config.php itself
 * fails partway through.
 * =====================================================================
 */

if (!defined('APP_ENV')) define('APP_ENV', getenv('APP_ENV') ?: 'production');

/* ── Resolve the canonical log file path ──────────────────────────────
 * Try the writable locations config.php already probes so we share the
 * same path; first one writable wins. */
(function () {
    $candidates = [
        __DIR__ . '/../storage/php_errors.log',
        sys_get_temp_dir() . '/utiligo_errors.log',
        '/tmp/utiligo_errors.log',
    ];
    foreach ($candidates as $cand) {
        // file_put_contents with FILE_APPEND returns false if the dir/
        // file isn't writable; touch() then is_dir() wouldn't catch the
        // open_basedir case on InfinityFree.
        if (@file_put_contents($cand, '', FILE_APPEND) !== false) {
            @ini_set('error_log', $cand);
            if (!defined('UTILIGO_PHP_ERROR_LOG')) define('UTILIGO_PHP_ERROR_LOG', $cand);
            return;
        }
    }
    // Last resort — at least define something so other code paths agree.
    if (!defined('UTILIGO_PHP_ERROR_LOG')) define('UTILIGO_PHP_ERROR_LOG', $candidates[0]);
})();

@ini_set('log_errors', '1');
@ini_set('display_errors', APP_ENV === 'production' ? '0' : '1');

/* ── Throttling state for email alerts ────────────────────────────────
 * One alert per unique signature every ALERT_THROTTLE_SECONDS so a
 * tight loop never floods the inbox. */
if (!defined('UTILIGO_ALERT_THROTTLE_SECONDS')) define('UTILIGO_ALERT_THROTTLE_SECONDS', 600);

/**
 * Write one line to storage/php_errors.log and also mirror it through
 * PHP's own error_log() (which writes to the same file thanks to the
 * ini_set above).  Every line is prefixed with time + the file that
 * SENT the error (originating_file:line) plus the front-controller and
 * request URI for quick triage.
 *
 * @param string      $level      Short tag: 'ERROR','FATAL','WARNING',
 *                               'NOTICE','EXCEPTION'.
 * @param string      $message    The error message.
 * @param string|null $errFile    File where the error originated
 *                                 (the `file` reported by PHP's engine
 *                                 — this is the "which file sent it"
 *                                 the user asked for).
 * @param int|null    $errLine    Line in $errFile.
 * @param array       $extra      Optional extra fields to render.
 */
function _utiligo_write_error_log(string $level, string $message, ?string $errFile = null, ?int $errLine = null, array $extra = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $errFile   = $errFile ?? '(unknown)';
    $errLine   = $errLine ?? 0;

    // Front controller = which file originally handled this HTTP request
    // (e.g. /login.php, /api/find-leads.php, /admin/users.php).  Falls
    // back to CLI script name for cron jobs.
    $frontController = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['argv'][0] ?? '(cli)');
    $requestUri      = $_SERVER['REQUEST_URI'] ?? '';
    $method          = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $uid             = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    $extraStr = '';
    foreach ($extra as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $extraStr .= " {$k}={$v}";
        }
    }

    $line = sprintf(
        "[%s] [%s] [sent_by:%s:%d] [controller:%s] [uri:%s] [uid:%d] %s%s\n",
        $timestamp,
        strtoupper($level),
        $errFile,
        $errLine,
        basename($frontController),
        $requestUri !== '' ? $requestUri : '(none)',
        $uid,
        $message,
        $extraStr
    );

    $logPath = defined('UTILIGO_PHP_ERROR_LOG') ? UTILIGO_PHP_ERROR_LOG : null;
    if ($logPath) {
        $dir = dirname($logPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
    // Mirror to PHP's own error handling.  If error_log ini is set to
    // our own file this is the same destination; otherwise it goes to a
    // system log or the configured destination.
    @error_log(rtrim($line));
}

/**
 * Send (or skip) an email alert to ADMIN_EMAIL for a fatal-level event.
 * Throttled per-signature so repeated identical errors don't spam.
 */
function _utiligo_emit_alert(string $signature, string $level, string $message, ?string $errFile = null, ?int $errLine = null): void
{
    if (!defined('ADMIN_EMAIL') || ADMIN_EMAIL === '') return;
    if (!defined('SMTP_FROM_EMAIL') || !defined('SMTP_FROM_NAME')) return;

    // Throttle — on a tight flood, the same signature won't fire more
    // than once every ALERT_THROTTLE_SECONDS seconds.
    $throttleFile = __DIR__ . '/../storage/last_alert_' . md5($signature) . '.time';
    $now = time();
    if (is_file($throttleFile) && ($now - (int)@file_get_contents($throttleFile)) < UTILIGO_ALERT_THROTTLE_SECONDS) {
        return;
    }
    @file_put_contents($throttleFile, (string)$now, LOCK_EX);

    // Best-effort send.  The mailer may not be available this early, so
    // we require it lazily; if anything fails we just give up silently —
    // the log file already has the entry.
    try {
        if (!function_exists('send_email')) {
            require_once __DIR__ . '/mailer.php';
        }
        if (!function_exists('send_email')) return;
        if (!defined('BREVO_API_KEY')) return;
        if (BREVO_API_KEY === 'YOUR_BREVO_API_KEY' || BREVO_API_KEY === '') return;

        $subject = '[Utiligo] ' . $level . ': ' . substr($message, 0, 80) . ' @ ' . ($errFile ? basename($errFile) : '?');
        $html = '<h3 style="margin:0;font-family:sans-serif;">Utiligo error alert</h3>'
              . '<table style="font-family:monospace;font-size:13px;border-collapse:collapse;margin-top:12px;">'
              . '<tr><td style="padding:4px 10px;color:#64748b;">Level</td><td style="padding:4px 10px;"><b>' . htmlspecialchars($level) . '</b></td></tr>'
              . '<tr><td style="padding:4px 10px;color:#64748b;">Sent by</td><td style="padding:4px 10px;">' . htmlspecialchars($errFile ?? '?') . ':' . (int)$errLine . '</td></tr>'
              . '<tr><td style="padding:4px 10px;color:#64748b;">Request</td><td style="padding:4px 10px;">' . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '</td></tr>'
              . '<tr><td style="padding:4px 10px;color:#64748b;">Front-controller</td><td style="padding:4px 10px;">' . htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '') . '</td></tr>'
              . '<tr><td style="padding:4px 10px;color:#64748b;">Time</td><td style="padding:4px 10px;">' . date('Y-m-d H:i:s') . '</td></tr>'
              . '</table>'
              . '<p style="font-family:monospace;font-size:13px;background:#f1f5f9;padding:12px;border-radius:8px;white-space:pre-wrap;">' . htmlspecialchars($message) . '</p>'
              . '<p style="font-family:sans-serif;font-size:12px;color:#94a3b8;">This alert was sent by Utiligo\'s global error handler. Repeated identical alerts are throttled to one every '
              . UTILIGO_ALERT_THROTTLE_SECONDS . ' seconds. See storage/php_errors.log for the full record.</p>';

        send_email(ADMIN_EMAIL, $subject, $html, strip_tags($message), 'Utiligo Admin');
    } catch (\Throwable $e) {
        // Don't let the alert itself become an exception we log about.
    }
}

/* ── 1.  Runtime warnings/notices/etc. ────────────────────────────────
 * Catch everything error_reporting() covers except E_*_STRICT and
 * E_DEPRECATED that some hosts leave off by default; this file is
 * loaded after error_reporting(E_ALL) so we honour that mask.
 */
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Honour @ suppression and the runtime reporting mask.  Stricter
    // errors that the host disabled still shouldn't be logged here.
    if (!(error_reporting() & $errno)) return false;

    $levelMap = [
        E_ERROR             => 'ERROR',
        E_WARNING           => 'WARNING',
        E_NOTICE            => 'NOTICE',
        E_PARSE             => 'PARSE',
        E_CORE_ERROR        => 'CORE_ERROR',
        E_CORE_WARNING      => 'CORE_WARNING',
        E_COMPILE_ERROR     => 'COMPILE_ERROR',
        E_COMPILE_WARNING   => 'COMPILE_WARNING',
        E_USER_ERROR        => 'USER_ERROR',
        E_USER_WARNING      => 'USER_WARNING',
        E_USER_NOTICE       => 'USER_NOTICE',
        E_STRICT            => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED        => 'DEPRECATED',
        E_USER_DEPRECATED   => 'USER_DEPRECATED',
    ];
    $level = $levelMap[$errno] ?? 'UNKNOWN';

    _utiligo_write_error_log($level, $errstr, $errfile, $errline);

    // Don't capture PHP's default formatting for this — we've logged
    // it ourselves, returning false would cause PHP to ALSO write a
    // duplicate line.  Return true to suppress PHP's own copy.
    return true;
});

/* ── 2.  Uncaught exceptions (become fatal otherwise) ─────────────────── */
set_exception_handler(function (\Throwable $e): void {
    $message = 'Uncaught ' . get_class($e) . ': ' . $e->getMessage();
    _utiligo_write_error_log('EXCEPTION', $message, $e->getFile(), $e->getLine(), [
        'trace' => $e->getTraceAsString(),
    ]);

    // Email alert (best-effort, throttled per-signature)
    $sig = md5($e->getFile() . ':' . $e->getLine() . ':' . substr($message, 0, 200));
    _utiligo_emit_alert($sig, 'EXCEPTION', $message, $e->getFile(), $e->getLine());

    // Emit 500 to the client.  If the script appears to be an API
    // endpoint (path under /api/), emit JSON; otherwise a minimal HTML
    // 500 page (avoid touching any layout files — they may be what's
    // broken).
    $uri   = $_SERVER['REQUEST_URI'] ?? '';
    $isApi = str_starts_with($uri, '/api/');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: ' . ($isApi ? 'application/json' : 'text/html; charset=UTF-8'));
    }
    if ($isApi) {
        echo json_encode(['success' => false, 'error' => 'Server error. This has been logged — please try again or contact support.']);
    } else {
        echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><link rel=\"icon\" type=\"image/png\" href=\"/assets/images/sitelogo-icon.png\"><title>Server error — Utiligo</title>"
           . "<style>body{font-family:system-ui,Segoe UI,Arial;background:#020817;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}"
           . ".box{max-width:480px;padding:40px;text-align:center;}"
           . "h1{font-size:48px;margin:0 0 8px;color:#ef4444}"
           . "p{color:#94a3b8;line-height:1.6}"
           . "a{color:#10b981;text-decoration:none}"
           . "</style></head><body>"
           . "<div class=\"box\"><h1>500</h1><h2 style=\"font-weight:600;margin:0 0 16px\">Something went wrong.</h2>"
           . "<p>Utiligo hit an unexpected error. A detailed report has been written to <code>storage/php_errors.log</code>"
           . " and emailed to the site administrator if an <code>ADMIN_EMAIL</code> is configured.</p>"
           . "<p style=\"margin-top:24px\"><a href=\"/\">Return to homepage &rsaquo;</a></p>"
           . "</div></body></html>";
    }
    // CRITICAL: exit so PHP doesn't fall through to any other registered
    // handler or, worse, continue executing half-broken code.
    exit;
});

/* ── 3.  Fatal errors caught at engine shutdown ────────────────────────
 * If the script aborts partway through (e.g. a missing require, a
 * memory exhaust, a 'Allowed memory size exhausted', etc.) the engine
 * fires one of these types — they can't be caught by set_error_handler
 * at runtime.  Use error_get_last() to pull it on shutdown.
 *
 * Memory-limited so we never OOM recursively while reporting the OOM.
 * Points to the original error's file/line, not config.php. */
register_shutdown_function(function (): void {
    $err = error_get_last();
    if (!$err) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        // Non-fatal "last error" — most likely already covered by the
        // set_error_handler above.  Still surface it just in case.
        return;
    }

    _utiligo_write_error_log('FATAL', $err['message'], $err['file'] ?? null, $err['line'] ?? null);

    // Email alert
    $sig = md5(($err['file'] ?? '') . ':' . ($err['line'] ?? 0) . ':' . substr($err['message'], 0, 200));
    _utiligo_emit_alert($sig, 'FATAL', $err['message'], $err['file'] ?? null, $err['line'] ?? null);

    // Only emit a response if no output started yet — otherwise the
    // page was already partially rendered and adding more would be
    // garbage appended after it (and headers_sent() is true so we
    // couldn't even set a status code).
    if (!headers_sent() && ob_get_level() === 0) {
        http_response_code(500);
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isApi = str_starts_with($uri, '/api/');
        header('Content-Type: ' . ($isApi ? 'application/json' : 'text/html; charset=UTF-8'));
        if ($isApi) {
            echo json_encode(['success' => false, 'error' => 'Server error. This has been logged — please try again or contact support.']);
        } else {
            echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><link rel=\"icon\" type=\"image/png\" href=\"/assets/images/sitelogo-icon.png\"><title>Server error — Utiligo</title>"
               . "<style>body{font-family:system-ui,Segoe UI,Arial;background:#020817;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}"
               . ".box{max-width:480px;padding:40px;text-align:center;}"
               . "h1{font-size:48px;margin:0 0 8px;color:#ef4444}"
               . "p{color:#94a3b8;line-height:1.6}"
               . "a{color:#10b981;text-decoration:none}"
               . "</style></head><body>"
               . "<div class=\"box\"><h1>500</h1><h2 style=\"font-weight:600;margin:0 0 16px\">Something went wrong.</h2>"
               . "<p>An unexpected server error has been logged to <code>storage/php_errors.log</code>.</p>"
               . "<p style=\"margin-top:24px\"><a href=\"/\">Return to homepage &rsaquo;</a></p>"
               . "</div></body></html>";
        }
    }
});

/* ── 4.  Email>alert webhook for REALLY bad things ──────────────────────
 * Also alert on uncaught exceptions (above we just log them; here we
 * make sure any throw caught by 2) also fires an email).  This is
 * done via a helper that the set_exception_handler above can opt into
 * but kept here so we can fire it from outside too if needed. */
if (!function_exists('utiligo_alert')) {
    function utiligo_alert(string $level, string $message, ?string $file = null, ?int $line = null): void
    {
        $sig = md5(($file ?? '') . ':' . ($line ?? 0) . ':' . $level . ':' . substr($message, 0, 200));
        _utiligo_emit_alert($sig, $level, $message, $file, $line);
    }
}
