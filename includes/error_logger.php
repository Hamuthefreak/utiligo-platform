<?php
/**
 * includes/error_logger.php — Centralized error dump for the whole platform.
 * Every API endpoint calls log_error() instead of letting exceptions/warnings
 * vanish into PHP's default log, which most shared hosts don't expose.
 * Entries are appended as JSON lines to storage/error_log.txt, which is
 * blocked from public access by storage/.htaccess.
 */

if (!defined('ERROR_LOG_PATH')) {
    define('ERROR_LOG_PATH', __DIR__ . '/../storage/error_log.txt');
}
if (!defined('ERROR_LOG_MAX_BYTES')) {
    define('ERROR_LOG_MAX_BYTES', 5 * 1024 * 1024);
}

function log_error(string $context, $error, array $extra = []): void
{
    $message = $error instanceof Throwable ? $error->getMessage() : (string)$error;
    $trace = $error instanceof Throwable ? $error->getTraceAsString() : null;

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'context' => $context,
        'message' => $message,
        'trace' => $trace,
        'extra' => $extra,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'user_id' => $_SESSION['user_id'] ?? null,
    ];

    rotate_error_log_if_needed();

    $storageDir = dirname(ERROR_LOG_PATH);
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }

    @file_put_contents(
        ERROR_LOG_PATH,
        json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );

    error_log("[{$context}] {$message}");
}

function rotate_error_log_if_needed(): void
{
    if (!file_exists(ERROR_LOG_PATH)) {
        return;
    }
    if (filesize(ERROR_LOG_PATH) < ERROR_LOG_MAX_BYTES) {
        return;
    }
    $archivePath = ERROR_LOG_PATH . '.' . date('YmdHis') . '.bak';
    @rename(ERROR_LOG_PATH, $archivePath);
}

function api_try(string $context, callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        log_error($context, $e);
        json_response(['success' => false, 'error' => 'Something went wrong. Please try again.'], 500);
    }
}

function get_recent_errors(int $limit = 50): array
{
    if (!file_exists(ERROR_LOG_PATH)) {
        return [];
    }
    $lines = file(ERROR_LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice(array_reverse($lines), 0, $limit);
    $entries = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if ($decoded !== null) {
            $entries[] = $decoded;
        }
    }
    return $entries;
}
