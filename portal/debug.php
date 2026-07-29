<?php
/**
 * TEMPORARY DEBUG PROBE — DELETE AFTER USE
 * Visit: /portal/debug  (or /portal/debug.php)
 * Shows exactly what crashes billing.php
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== UTILIGO BILLING DEBUG PROBE ===\n\n";

// 1. PHP info
echo "PHP version : " . PHP_VERSION . "\n";
echo "SAPI        : " . PHP_SAPI . "\n";
echo "Error log   : " . ini_get('error_log') . "\n";
echo "display_err : " . ini_get('display_errors') . "\n\n";

// 2. Check every file billing.php requires
$root = __DIR__ . '/../';
$files = [
    'config.php',
    'db.php',
    'userdb.php',
    'includes/auth.php',
    'includes/plans.php',
    'includes/functions.php',
    'includes/mailer.php',
    'includes/portal_layout.php',
    'includes/portal_layout_end.php',
];

echo "=== FILE EXISTS CHECK ===\n";
foreach ($files as $f) {
    $path = $root . $f;
    $exists = file_exists($path) ? 'OK  ' : 'MISSING';
    echo "[$exists] $f\n";
}
echo "\n";

// 3. Try loading each file one by one and catch errors
echo "=== REQUIRE TEST ===\n";
foreach ($files as $f) {
    $path = $root . $f;
    if (!file_exists($path)) { echo "SKIP (missing): $f\n"; continue; }
    // Stop before portal_layout.php — it outputs HTML
    if (strpos($f, 'portal_layout') !== false) { echo "SKIP (html output): $f\n"; continue; }
    echo "Loading: $f ... ";
    try {
        require_once $path;
        echo "OK\n";
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\n";
    }
}
echo "\n";

// 4. Check DB connection
echo "=== DB CONNECTION ===\n";
if (function_exists('get_db')) {
    try {
        $db = get_db();
        $db->query('SELECT 1');
        echo "Main DB: OK\n";
    } catch (\Throwable $e) { echo "Main DB ERROR: " . $e->getMessage() . "\n"; }
} else {
    echo "get_db() not defined\n";
}
if (function_exists('get_user_db')) {
    try {
        $udb = get_user_db();
        $udb->query('SELECT 1');
        echo "User DB: OK\n";
    } catch (\Throwable $e) { echo "User DB ERROR: " . $e->getMessage() . "\n"; }
} else {
    echo "get_user_db() not defined\n";
}
echo "\n";

// 5. Check key constants from config.php
echo "=== CONFIG CONSTANTS ===\n";
$consts = ['PRO_LEAD_LIMIT','PRO_SITE_LIMIT','ENT_SITE_LIMIT','ENT_TEAM_SEATS',
           'FREE_LEAD_LIMIT','FREE_SITE_LIMIT','PRO_PLAN_PRICE','ENTREPRENEUR_PLAN_PRICE',
           'BREVO_LIST_ALL_USERS','BREVO_LIST_PRO_USERS'];
foreach ($consts as $c) {
    echo (defined($c) ? "[OK ] $c = " . constant($c) : "[MISSING] $c") . "\n";
}
echo "\n";

// 6. Session / last error
echo "=== LAST PHP ERROR ===\n";
$err = error_get_last();
if ($err) { print_r($err); } else { echo "None\n"; }

echo "\n=== DONE — delete portal/debug.php after reading this ===\n";
