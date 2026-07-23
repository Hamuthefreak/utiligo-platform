<?php
/**
 * opcache-reset.php
 * =================
 * Visit this URL once in your browser to flush PHP's OPcache.
 * The script deletes itself immediately after running so it
 * cannot be used again.
 *
 * URL: https://utiligo.ca/opcache-reset.php
 *
 * DELETE THIS FILE after use if auto-delete fails for any reason.
 */

// Basic protection — only allow from server itself or with secret token.
// Change this token to something only you know before deploying.
$SECRET = 'utiligo-reset-2026';

$provided = $_GET['token'] ?? '';
if (!hash_equals($SECRET, $provided)) {
    http_response_code(403);
    die('Forbidden. Append ?token=' . $SECRET . ' to the URL.');
}

$results = [];

// 1. Reset OPcache
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    $results[] = 'opcache_reset(): ' . ($ok ? 'SUCCESS' : 'FAILED');
} else {
    $results[] = 'opcache_reset(): function not available (OPcache not loaded)';
}

// 2. Also invalidate plan_limits.php specifically
$target = __DIR__ . '/includes/plan_limits.php';
if (function_exists('opcache_invalidate') && file_exists($target)) {
    opcache_invalidate($target, true);
    $results[] = 'opcache_invalidate(plan_limits.php): done';
}

// 3. Self-delete
$self = __FILE__;
$deleted = @unlink($self);
$results[] = 'Self-delete: ' . ($deleted ? 'SUCCESS — file removed' : 'FAILED — please delete opcache-reset.php manually');

// 4. Report
header('Content-Type: text/plain');
echo implode("\n", $results) . "\n\nDone. Reload your homepage — limits should now show correctly.\n";
