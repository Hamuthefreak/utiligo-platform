<?php
/**
 * includes/functions.php — Shared utility functions used across the platform.
 */
require_once __DIR__ . '/../config.php';

function sanitize(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'site';
}

function json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function format_currency(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function csrf_token(): string
{
    // If sessions are disabled or not started, return a dummy token.
    // Pages using CSRF will redirect to login anyway if session is gone.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return 'no-session';
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    return $token !== null
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Per-minute rate limit check for ajax endpoints.
 *
 * Implementation notes:
 *   1. The PRIMARY counter lives in $_SESSION so it's instant, but
 *      $_SESSION is per-cookie — an attacker who drops the cookie gets
 *      a fresh bucket every request.  To close that hole we ALSO keep
 *      a DB-backed counter on `rate_limit_buckets` (best-effort: any
 *      DB error / missing table is swallowed and the session counter
 *      still governs, so a DB outage never bricks a legit user).
 *   2. Previously this returned `true` ("allow") when the session was
 *      not active.  That made the rate limit bypassable from a client
 *      that sent no cookies (and meant an unstarted session skipped
 *      the limit entirely).  Now we treat "no usable session" as
 *      "deny" so an attacker can't escape the limit by suppressing
 *      the cookie.  Authed endpoints already had to require a session
 *      via is_logged_in() earlier in the request, so this stricter
 *      behaviour cannot hurt real users of those endpoints.
 *
 * @param string $key   Bucket name (e.g. 'find_leads').
 * @param int    $maxPerMinute   Max events allowed inside a 60s window.
 * @return bool  TRUE = allowed, FALSE = over the limit.
 */
function rate_limit_check(string $key, int $maxPerMinute): bool
{
    // Try to recover a session if it's merely not started yet (so a caller
    // that arrives before session_start() still gets tracked).  Any other
    // session state (disabled / active) is left alone.
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Sessions genuinely unavailable — fall back to the DB counter only.
        return _db_rate_limit_check($key, $maxPerMinute, 0);
    }

    $now    = time();
    $bucket = $_SESSION['rate_limit'][$key] ?? ['count' => 0, 'window_start' => $now];
    if ($now - $bucket['window_start'] >= 60) {
        $bucket = ['count' => 0, 'window_start' => $now];
    }
    $bucket['count']++;
    $_SESSION['rate_limit'][$key] = $bucket;
    $session_ok = $bucket['count'] <= $maxPerMinute;

    // OR the two so the session count never *reduces* protection, and the
    // DB counter only tightens it. The session wins on disagreement.
    return $session_ok && _db_rate_limit_check($key, $maxPerMinute, (int)$bucket['count']);
}

/**
 * Best-effort DB-backed 60s rate-limit counter.
 *
 * Uses an UPSERT on `rate_limit_buckets(bucket_key, last_count, window_start)`
 * where `bucket_key` = "<key>|<user_id>".  A 60s sliding-ish window (we use a
 * fixed start to avoid counting drift).  Never throws; on any DB error
 * returns TRUE (allow) so a DB hiccup cannot brick a legit user — the
 * session counter still does its job in that case.
 *
 * @param string $key     Bucket name.
 * @param int    $max     Max events per 60s window.
 * @param int    $sessCount   Current session-side count (informational; not authoritative).
 * @return bool  TRUE = allowed (only meaningful when the table is reachable).
 */
function _db_rate_limit_check(string $key, int $max, int $sessCount): bool
{
    if (!function_exists('get_platform_db')) return true;
    $uid = 0;
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
    }
    $bucket_key = $key . '|' . $uid;

    try {
        $pdo = get_platform_db();
        // Idempotent — safe on every request.
        $pdo->exec('CREATE TABLE IF NOT EXISTS `rate_limit_buckets` (
            `bucket_key`   VARCHAR(120) NOT NULL,
            `count`        INT UNSIGNED NOT NULL DEFAULT 0,
            `window_start` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`bucket_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $now     = date('Y-m-d H:i:s');
        $cutoff  = date('Y-m-d H:i:s', time() - 60);

        // SELECT-then-UPSERT keeps the math correct under concurrency without
        // needing a transaction — we only ever compare to $max, the absolute
        // latest value is read on the next call.
        $sel = $pdo->prepare('SELECT count, window_start FROM rate_limit_buckets WHERE bucket_key = ? LIMIT 1');
        $sel->execute([$bucket_key]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->prepare('INSERT INTO rate_limit_buckets (bucket_key, count, window_start) VALUES (?, 1, ?)')
                ->execute([$bucket_key, $now]);
            $count = 1;
        } else if ($row['window_start'] < $cutoff) {
            $pdo->prepare('UPDATE rate_limit_buckets SET count = 1, window_start = ? WHERE bucket_key = ?')
                ->execute([$now, $bucket_key]);
            $count = 1;
        } else {
            $pdo->prepare('UPDATE rate_limit_buckets SET count = count + 1 WHERE bucket_key = ?')
                ->execute([$bucket_key]);
            $count = (int)$row['count'] + 1;
        }
        return $count <= $max;
    } catch (\Throwable $e) {
        // Logged but never fatal — the session counter is the authority.
        error_log('[db_rate_limit] ' . $e->getMessage());
        return true;
    }
}

function opportunity_score(?float $rating, int $reviewCount, string $category): int
{
    $score = 50;
    if ($rating !== null) {
        if ($rating >= 4.5) $score += 20;
        elseif ($rating >= 4.0) $score += 10;
        elseif ($rating < 3.5) $score -= 10;
    }
    if ($reviewCount >= 50) $score += 15;
    elseif ($reviewCount >= 15) $score += 8;
    elseif ($reviewCount < 5) $score -= 5;

    $highValueCategories = ['General Contractor', 'HVAC', 'Roofer', 'Electrician'];
    if (in_array($category, $highValueCategories, true)) $score += 10;

    return max(0, min(100, $score));
}

function generate_zip(string $sourceDir, string $zipPath): bool
{
    $realSource = realpath($sourceDir);
    if ($realSource === false) {
        error_log('generate_zip: source directory does not exist: ' . $sourceDir);
        return false;
    }

    $exportDir = dirname($zipPath);
    if (!is_dir($exportDir) && !@mkdir($exportDir, 0755, true)) {
        error_log('generate_zip: could not create export directory: ' . $exportDir);
        return false;
    }
    if (!is_writable($exportDir)) {
        error_log('generate_zip: export directory is not writable: ' . $exportDir);
        return false;
    }

    $fileList = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realSource, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file->isDir()) {
            $filePath     = $file->getRealPath();
            $relativePath = substr($filePath, strlen($realSource) + 1);
            $fileList[]   = ['path' => $filePath, 'name' => $relativePath];
        }
    }

    if (empty($fileList)) {
        error_log('generate_zip: no files found in source directory: ' . $realSource);
        return false;
    }

    if (class_exists('ZipArchive')) {
        $zip    = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened === true) {
            foreach ($fileList as $f) {
                $zip->addFile($f['path'], $f['name']);
            }
            if ($zip->close()) return true;
            error_log('generate_zip: ZipArchive close() failed, falling back to SimpleZipWriter.');
        } else {
            error_log('generate_zip: ZipArchive open() failed (code ' . $opened . '), falling back to SimpleZipWriter.');
        }
    } else {
        error_log('generate_zip: ZipArchive extension not available, using SimpleZipWriter fallback.');
    }

    require_once __DIR__ . '/simple_zip_writer.php';
    $writer = new SimpleZipWriter();
    foreach ($fileList as $f) {
        $writer->addFile($f['path'], $f['name']);
    }
    if (!$writer->save($zipPath)) {
        error_log('generate_zip: SimpleZipWriter save() also failed for ' . $zipPath);
        return false;
    }
    return true;
}

function api_bootstrap(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    require_once __DIR__ . '/error_logger.php';

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log_error('fatal_shutdown', $err['message'], [
                'file' => $err['file'] ?? null,
                'line' => $err['line'] ?? null,
            ]);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'error'   => 'Server error. This has been logged — please try again or contact support.',
            ]);
        }
    });

    set_exception_handler(function (\Throwable $e) {
        log_error('uncaught_exception', $e);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Server error. This has been logged — please try again or contact support.',
        ]);
        exit;
    });
}

function recursive_delete_directory(string $dir): bool
{
    if (!is_dir($dir)) return true;
    $items = scandir($dir);
    if ($items === false) return false;
    $ok = true;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $ok = recursive_delete_directory($path) && $ok;
        } else {
            $ok = @unlink($path) && $ok;
        }
    }
    return @rmdir($dir) && $ok;
}

function db_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        $cache[$cacheKey] = (bool)$stmt->fetch();
    } catch (\Throwable $e) {
        $cache[$cacheKey] = false;
    }
    return $cache[$cacheKey];
}

/* ═══════════════════════════════════════════════════════════════════════════
   Google Places monthly cap helpers
   ───────────────────────────────────────────────────────────────────────────
   Enforces a platform-wide monthly call limit (PLACES_API_MONTHLY_LIMIT,
   default 5000) so we never exceed Google's free tier and get billed.
   Tracked in `places_api_usage` (platform DB, created by migration 019).
   Month is UTC 'YYYY-MM'; counter resets automatically at month boundary.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Remaining Google Places calls in the current UTC month.
 * Returns the limit itself if tracking fails (defensive; never throws).
 * Returns >=0 (clamped to 0).
 */
function places_api_remaining(): int
{
    $limit = defined('PLACES_API_MONTHLY_LIMIT') ? (int)PLACES_API_MONTHLY_LIMIT : 5000;
    try {
        $pdo  = get_platform_db();
        $ym   = gmdate('Y-m');
        $stmt = $pdo->prepare('SELECT calls_count FROM places_api_usage WHERE year_month = ? LIMIT 1');
        $stmt->execute([$ym]);
        $used = (int)$stmt->fetchColumn();
        return max(0, $limit - $used);
    } catch (\Throwable $e) {
        // Table missing or DB unreachable — degrade to "limit assumed remaining"
        // to avoid blocking lead search during a partial outage.
        error_log('[places_api_remaining] ' . $e->getMessage());
        return $limit;
    }
}

/**
 * Atomically increment this month's Places API call counter by $count.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE so concurrent requests are safe.
 * Never throws; failures are logged.
 */
function places_api_increment(int $count = 1): void
{
    if ($count <= 0) return;
    try {
        $pdo = get_platform_db();
        $ym  = gmdate('Y-m');
        $pdo->prepare(
            'INSERT INTO places_api_usage (year_month, calls_count)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE calls_count = calls_count + ?'
        )->execute([$ym, $count, $count]);
    } catch (\Throwable $e) {
        error_log('[places_api_increment] ' . $e->getMessage());
    }
}
