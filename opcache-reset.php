<?php
$SECRET = 'utiligo-reset-2026';
if (!hash_equals($SECRET, $_GET['token'] ?? '')) {
    http_response_code(403); die('Forbidden.');
}
header('Content-Type: text/plain');

$config_path      = __DIR__ . '/config.php';
$plan_limits_path = __DIR__ . '/includes/plan_limits.php';

echo "config.php md5:      " . md5_file($config_path) . "\n";
echo "config.php mtime:    " . date('Y-m-d H:i:s', filemtime($config_path)) . "\n";
echo "plan_limits.php md5: " . md5_file($plan_limits_path) . "\n\n";

echo "--- config.php FULL CONTENT ---\n";
echo file_get_contents($config_path);
echo "\n\n--- END config.php ---\n";

@unlink(__FILE__);
echo "\nSelf-deleted.\n";
