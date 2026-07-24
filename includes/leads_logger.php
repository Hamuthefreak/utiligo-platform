<?php
/**
 * includes/leads_logger.php
 *
 * Dedicated structured logger for the leads pipeline.
 * All entries written to storage/leads_log.txt as JSON-lines — completely
 * separate from the platform-wide error_log.txt.
 *
 * Levels:  error | warn | info | debug
 *
 * Storage:
 *   storage/leads_log.txt                  — current (auto-rotated at 5 MB)
 *   storage/leads_log.YYYYMMDDHHIISS.bak   — rotated archives
 *
 * Usage:
 *   leads_log_info('search_complete', ['city'=>$city, 'returned'=>10]);
 *   leads_log_error('google_api', new RuntimeException('REQUEST_DENIED'));
 *   leads_log_warn('id_resolve_fail', ['pid'=>$pid]);
 */

if (!defined('LEADS_LOG_PATH'))      define('LEADS_LOG_PATH',      __DIR__ . '/../storage/leads_log.txt');
if (!defined('LEADS_LOG_MAX_BYTES')) define('LEADS_LOG_MAX_BYTES', 5 * 1024 * 1024);
if (!defined('LEADS_LOG_DEBUG'))     define('LEADS_LOG_DEBUG',     false);

function leads_log(string $level, string $event, $payload = [], array $extra = []): void
{
    if ($level === 'debug' && !LEADS_LOG_DEBUG) return;

    $ctx = [];
    if ($payload instanceof \Throwable) {
        $ctx['message'] = $payload->getMessage();
        $ctx['file']    = $payload->getFile() . ':' . $payload->getLine();
        $ctx['trace']   = _leads_log_short_trace($payload);
    } elseif (is_array($payload)) {
        $ctx = $payload;
    }
    if (!empty($extra)) $ctx = array_merge($ctx, $extra);

    $entry = [
        'ts'      => date('Y-m-d H:i:s'),
        'level'   => $level,
        'event'   => $event,
        'uid'     => $_SESSION['user_id'] ?? null,
        'ip_hash' => isset($_SERVER['REMOTE_ADDR'])
                        ? substr(hash('sha256', $_SERVER['REMOTE_ADDR']), 0, 12)
                        : null,
        'ctx'     => $ctx,
    ];

    _leads_log_rotate();
    _leads_log_ensure_dir();

    @file_put_contents(
        LEADS_LOG_PATH,
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );

    if ($level === 'error' || $level === 'warn') {
        $msg = $ctx['message'] ?? json_encode($ctx, JSON_UNESCAPED_SLASHES);
        error_log("[leads:{$level}:{$event}] {$msg}");
    }
}

function leads_log_error(string $event, $payload = [], array $extra = []): void { leads_log('error', $event, $payload, $extra); }
function leads_log_warn (string $event, $payload = [], array $extra = []): void { leads_log('warn',  $event, $payload, $extra); }
function leads_log_info (string $event, $payload = [], array $extra = []): void { leads_log('info',  $event, $payload, $extra); }
function leads_log_debug(string $event, $payload = [], array $extra = []): void { leads_log('debug', $event, $payload, $extra); }

/**
 * Retrieve recent leads log entries (newest first).
 * @param int    $limit     Max entries
 * @param string $min_level error|warn|info|debug
 */
function leads_log_get_recent(int $limit = 100, string $min_level = 'debug'): array
{
    if (!file_exists(LEADS_LOG_PATH)) return [];
    $order = ['error'=>4,'warn'=>3,'info'=>2,'debug'=>1];
    $min   = $order[$min_level] ?? 1;
    $lines = array_reverse(file(LEADS_LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $out   = [];
    foreach ($lines as $line) {
        if (count($out) >= $limit) break;
        $d = json_decode($line, true);
        if ($d && ($order[$d['level'] ?? 'debug'] ?? 1) >= $min) $out[] = $d;
    }
    return $out;
}

function _leads_log_short_trace(\Throwable $e): string
{
    return implode(' | ', array_slice(explode("\n", $e->getTraceAsString()), 0, 3));
}

function _leads_log_rotate(): void
{
    if (!file_exists(LEADS_LOG_PATH)) return;
    if (@filesize(LEADS_LOG_PATH) < LEADS_LOG_MAX_BYTES) return;
    @rename(LEADS_LOG_PATH, LEADS_LOG_PATH . '.' . date('YmdHis') . '.bak');
}

function _leads_log_ensure_dir(): void
{
    $dir = dirname(LEADS_LOG_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) @file_put_contents($ht, "Order deny,allow\nDeny from all\n");
    }
}
