<?php
/**
 * opcache-reset.php  — diagnostics + hard reset
 * Visit: https://utiligo.ca/opcache-reset.php?token=utiligo-reset-2026
 * DELETE THIS FILE after use.
 */
$SECRET = 'utiligo-reset-2026';
if (!hash_equals($SECRET, $_GET['token'] ?? '')) {
    http_response_code(403); die('Forbidden.');
}

header('Content-Type: text/plain');

// 1. Show what PHP thinks the file path resolves to
echo "=== FILE PATHS ===\n";
$plan_limits_path = __DIR__ . '/includes/plan_limits.php';
echo 'plan_limits.php exists on disk: ' . (file_exists($plan_limits_path) ? 'YES' : 'NO') . "\n";
echo 'plan_limits.php mtime: ' . (file_exists($plan_limits_path) ? date('Y-m-d H:i:s', filemtime($plan_limits_path)) : 'N/A') . "\n";
echo 'plan_limits.php md5: ' . (file_exists($plan_limits_path) ? md5_file($plan_limits_path) : 'N/A') . "\n";

// 2. Load config and dump what constants actually resolved to
echo "\n=== CONSTANTS BEFORE LOAD ===\n";
echo 'PRO_LEAD_LIMIT defined: ' . (defined('PRO_LEAD_LIMIT') ? 'yes = ' . PRO_LEAD_LIMIT : 'no') . "\n";

require_once __DIR__ . '/config.php';

echo "\n=== CONSTANTS AFTER LOADING config.php ===\n";
$constants = [
    'FREE_LEAD_LIMIT', 'FREE_SEARCH_DAILY_LIMIT', 'FREE_SITE_LIMIT',
    'PRO_LEAD_LIMIT', 'PRO_SITE_LIMIT', 'PRO_GENERATE_DAILY_LIMIT',
    'ENT_LEAD_LIMIT', 'ENT_SITE_LIMIT',
    'PRO_PLAN_PRICE', 'ENTREPRENEUR_PLAN_PRICE',
];
foreach ($constants as $c) {
    echo $c . ': ' . (defined($c) ? constant($c) : 'NOT DEFINED') . "\n";
}

// 3. Show first 20 lines of the actual file on disk
echo "\n=== FIRST 25 LINES OF plan_limits.php ON DISK ===\n";
if (file_exists($plan_limits_path)) {
    $lines = file($plan_limits_path);
    echo implode('', array_slice($lines, 0, 25));
} else {
    echo "FILE NOT FOUND\n";
}

// 4. OPcache status
echo "\n=== OPCACHE STATUS ===\n";
if (function_exists('opcache_get_status')) {
    $s = opcache_get_status(false);
    echo 'enabled: ' . ($s['opcache_enabled'] ? 'yes' : 'no') . "\n";
    echo 'cached_scripts: ' . ($s['opcache_statistics']['num_cached_scripts'] ?? 'n/a') . "\n";
    echo 'validate_timestamps: ' . (ini_get('opcache.validate_timestamps') ? 'yes' : 'NO — THIS IS THE PROBLEM') . "\n";
    echo 'revalidate_freq: ' . ini_get('opcache.revalidate_freq') . "s\n";
} else {
    echo "opcache_get_status() not available\n";
}

// 5. Hard reset
echo "\n=== RESET ===\n";
if (function_exists('opcache_reset')) {
    echo 'opcache_reset(): ' . (opcache_reset() ? 'SUCCESS' : 'FAILED') . "\n";
} else {
    echo "opcache_reset() not available\n";
}
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($plan_limits_path, true);
    opcache_invalidate(__DIR__ . '/config.php', true);
    opcache_invalidate(__DIR__ . '/includes/plans.php', true);
    opcache_invalidate(__DIR__ . '/index.php', true);
    echo "opcache_invalidate() called on 4 files\n";
}

// 6. Self-delete
$deleted = @unlink(__FILE__);
echo 'Self-delete: ' . ($deleted ? 'SUCCESS' : 'FAILED — delete opcache-reset.php manually') . "\n";
echo "\nDONE. Now hard-refresh https://utiligo.ca (Ctrl+Shift+R).\n";
