<?php
$SECRET = 'utiligo-reset-2026';
if (!hash_equals($SECRET, $_GET['token'] ?? '')) {
    http_response_code(403); die('Forbidden.');
}
header('Content-Type: text/plain');

$plan_limits_path = __DIR__ . '/includes/plan_limits.php';
echo "plan_limits.php md5: " . md5_file($plan_limits_path) . "\n";
echo "plan_limits.php mtime: " . date('Y-m-d H:i:s', filemtime($plan_limits_path)) . "\n\n";

echo "--- FIRST 30 LINES ON DISK ---\n";
$lines = file($plan_limits_path);
echo implode('', array_slice($lines, 0, 30));
echo "\n\n";

require_once __DIR__ . '/config.php';

echo "--- CONSTANTS ---\n";
foreach ([
    'FREE_LEAD_LIMIT','FREE_SEARCH_DAILY_LIMIT','FREE_SITE_LIMIT',
    'PRO_LEAD_LIMIT','PRO_SITE_LIMIT',
    'ENT_LEAD_LIMIT','ENT_SITE_LIMIT',
    'PRO_PLAN_PRICE','ENTREPRENEUR_PLAN_PRICE',
] as $c) {
    echo $c . ': ' . (defined($c) ? constant($c) : 'NOT DEFINED') . "\n";
}

@unlink(__FILE__);
echo "\nSelf-deleted. Done.\n";
