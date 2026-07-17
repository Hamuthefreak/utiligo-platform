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
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    return $token !== null && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function rate_limit_check(string $key, int $maxPerMinute): bool
{
    $now = time();
    $bucket = $_SESSION['rate_limit'][$key] ?? ['count' => 0, 'window_start' => $now];
    if ($now - $bucket['window_start'] >= 60) {
        $bucket = ['count' => 0, 'window_start' => $now];
    }
    $bucket['count']++;
    $_SESSION['rate_limit'][$key] = $bucket;
    return $bucket['count'] <= $maxPerMinute;
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
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($realSource) + 1);
            $fileList[] = ['path' => $filePath, 'name' => $relativePath];
        }
    }

    if (empty($fileList)) {
        error_log('generate_zip: no files found in source directory: ' . $realSource);
        return false;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened === true) {
            foreach ($fileList as $f) {
                $zip->addFile($f['path'], $f['name']);
            }
            if ($zip->close()) {
                return true;
            }
            error_log('generate_zip: ZipArchive close() failed, falling back to SimpleZipWriter.');
        } else {
            error_log('generate_zip: ZipArchive open() failed (code ' . $opened . '), falling back to SimpleZipWriter.');
        }
    } else {
        error_log('generate_zip: ZipArchive extension not available, using SimpleZipWriter fallback.');
    }

    // Fallback: pure-PHP ZIP writer, no ext-zip required (common on free hosts).
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
    // API endpoints must always return valid JSON. If display_errors is on
    // (APP_ENV=development), a stray PHP warning/fatal gets printed as HTML
    // before/after our JSON, which breaks response.json() on the frontend
    // and surfaces as a generic "Something went wrong" to the user. So for
    // API responses specifically, we always suppress inline error display
    // and log instead, and catch fatals to still emit clean JSON.
    //
    // All errors caught here are also routed through log_error() into the
    // centralized dump file (storage/error_log.txt) so failures can be
    // diagnosed after the fact instead of vanishing silently.
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
                'error' => 'Server error. This has been logged — please try again or contact support.',
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
            'error' => 'Server error. This has been logged — please try again or contact support.',
        ]);
        exit;
    });
}

function recursive_delete_directory(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $items = scandir($dir);
    if ($items === false) {
        return false;
    }
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
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        $cache[$cacheKey] = (bool)$stmt->fetch();
    } catch (\Throwable $e) {
        $cache[$cacheKey] = false;
    }
    return $cache[$cacheKey];
}
