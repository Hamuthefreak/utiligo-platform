<?php
/**
 * portal/debug_errors.php
 * One-stop error probe — visit this page in your browser to see exactly
 * what is broken. DELETE or password-protect this file after debugging.
 *
 * Access: /portal/debug_errors.php
 * No login required intentionally — so it works even when auth is broken.
 */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Load config without dying
try {
    require_once __DIR__ . '/../config.php';
    // Re-force display_errors ON after config.php silences them
    ini_set('display_errors', '1');
} catch (Throwable $e) {
    die('<pre style="color:red">FATAL: config.php failed: ' . htmlspecialchars($e->getMessage()) . "\nFile: " . $e->getFile() . ':' . $e->getLine() . '</pre>');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Utiligo Error Probe</title>
<style>
body{font-family:monospace;background:#0d0d14;color:#cdd6f4;padding:30px;font-size:13px;}
h2{color:#89b4fa;border-bottom:1px solid #313244;padding-bottom:6px;}
.ok{color:#a6e3a1;} .fail{color:#f38ba8;} .warn{color:#fab387;}
pre{background:#1e1e2e;padding:14px;border-radius:8px;overflow-x:auto;white-space:pre-wrap;}
table{border-collapse:collapse;width:100%;margin-bottom:20px;}
td,th{border:1px solid #313244;padding:6px 10px;text-align:left;}
th{background:#181825;color:#89b4fa;}
</style>
</head>
<body>
<h1 style="color:#cba6f7">&#x1F50D; Utiligo Error Probe</h1>
<p style="color:#6c7086">Generated: <?= date('Y-m-d H:i:s T') ?></p>

<h2>1. PHP Info</h2>
<table>
<tr><th>Key</th><th>Value</th></tr>
<tr><td>PHP version</td><td><?= phpversion() ?></td></tr>
<tr><td>APP_ENV</td><td><?= defined('APP_ENV') ? APP_ENV : '<span class="warn">not defined</span>' ?></td></tr>
<tr><td>display_errors</td><td><?= ini_get('display_errors') ?></td></tr>
<tr><td>error_log path</td><td><?= ini_get('error_log') ?: '(none set)' ?></td></tr>
<tr><td>session.status</td><td><?= session_status() === PHP_SESSION_ACTIVE ? '<span class="ok">active</span>' : '<span class="warn">'.session_status().'</span>' ?></td></tr>
<tr><td>PDO drivers</td><td><?= implode(', ', PDO::getAvailableDrivers()) ?></td></tr>
</table>

<h2>2. Platform DB (DB_HOST / DB_NAME)</h2>
<?php
$_db_host = defined('DB_HOST') ? DB_HOST : '??';
$_db_name = defined('DB_NAME') ? DB_NAME : '??';
$_db_user = defined('DB_USER') ? DB_USER : '??';
$_db_pass = defined('DB_PASS') ? DB_PASS : '??';
echo "<p>Host: <strong>$_db_host</strong> &nbsp; DB: <strong>$_db_name</strong> &nbsp; User: <strong>$_db_user</strong></p>";
try {
    require_once __DIR__ . '/../db.php';
    $pdb = get_platform_db();
    $pdb->query('SELECT 1');
    echo '<p class="ok">&#x2705; Platform DB connection OK</p>';
    // Check schema_migrations table
    $t = $pdb->query("SHOW TABLES LIKE 'schema_migrations'")->fetch();
    echo $t ? '<p class="ok">&#x2705; schema_migrations table exists</p>' : '<p class="fail">&#x274C; schema_migrations table MISSING</p>';
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; Platform DB FAILED: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

<h2>3. User DB (USERDB_HOST / USERDB_NAME)</h2>
<?php
$_udb_host = defined('USERDB_HOST') ? USERDB_HOST : '??';
$_udb_name = defined('USERDB_NAME') ? USERDB_NAME : '??';
$_udb_user = defined('USERDB_USER') ? USERDB_USER : '??';
echo "<p>Host: <strong>$_udb_host</strong> &nbsp; DB: <strong>$_udb_name</strong> &nbsp; User: <strong>$_udb_user</strong></p>";
try {
    require_once __DIR__ . '/../userdb.php';
    $udb = get_user_db();
    $udb->query('SELECT 1');
    echo '<p class="ok">&#x2705; User DB connection OK</p>';
    // Check critical tables
    foreach (['utiligo_users', 'utiligo_remember_tokens', 'utiligo_email_verifications'] as $tbl) {
        $exists = $udb->query("SHOW TABLES LIKE '$tbl'")->fetch();
        echo $exists
            ? "<p class=\"ok\">&#x2705; $tbl exists</p>"
            : "<p class=\"fail\">&#x274C; $tbl MISSING</p>";
    }
    // Check utiligo_users columns
    $cols = $udb->query("SHOW COLUMNS FROM utiligo_users")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['id','email','password_hash','full_name','plan','subscription_status','subscription_started_at','is_admin','email_verified','created_at'];
    echo '<p><strong>utiligo_users columns:</strong> ' . implode(', ', $cols) . '</p>';
    foreach ($required as $col) {
        if (!in_array($col, $cols)) {
            echo "<p class=\"fail\">&#x274C; Column '$col' MISSING from utiligo_users</p>";
        }
    }
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; User DB FAILED: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

<h2>4. Key Files</h2>
<?php
$files = [
    '../config.php',
    '../db.php',
    '../userdb.php',
    '../includes/auth.php',
    '../includes/functions.php',
    '../includes/plans.php',
    '../includes/mailer.php',
    '../includes/portal_layout.php',
    '../includes/portal_layout_end.php',
    '../includes/run_migrations.php',
    '../includes/bootstrap_migrations.php',
];
echo '<table><tr><th>File</th><th>Status</th></tr>';
foreach ($files as $f) {
    $full = __DIR__ . '/' . $f;
    $ok   = file_exists($full);
    echo '<tr><td>' . htmlspecialchars($f) . '</td><td class="' . ($ok ? 'ok' : 'fail') . '">' . ($ok ? '&#x2705; exists' : '&#x274C; MISSING') . '</td></tr>';
}
echo '</table>';
?>

<h2>5. PHP Error Log (last 60 lines)</h2>
<?php
$logPath = __DIR__ . '/../storage/php_errors.log';
if (file_exists($logPath) && is_readable($logPath)) {
    $lines = file($logPath);
    $tail  = array_slice($lines, -60);
    echo '<pre>' . htmlspecialchars(implode('', $tail)) . '</pre>';
} else {
    echo '<p class="warn">&#x26A0; Log file not found or not readable at: ' . htmlspecialchars($logPath) . '</p>';
    echo '<p style="color:#6c7086">InfinityFree may log to a different path. Check your hosting control panel Error Log viewer.</p>';
}
?>

<h2>6. auth.php load test</h2>
<?php
try {
    if (!function_exists('check_remember_me_cookie')) {
        require_once __DIR__ . '/../includes/auth.php';
    }
    echo '<p class="ok">&#x2705; auth.php loaded without fatal error</p>';
    echo '<p>check_remember_me_cookie defined: ' . (function_exists('check_remember_me_cookie') ? '<span class="ok">yes</span>' : '<span class="fail">NO</span>') . '</p>';
    echo '<p>csrf_token defined: ' . (function_exists('csrf_token') ? '<span class="ok">yes</span>' : '<span class="warn">no (loaded by functions.php)</span>') . '</p>';
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; auth.php FAILED: ' . htmlspecialchars($e->getMessage()) . ' in ' . $e->getFile() . ':' . $e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}
?>

<h2>7. Full require chain for billing.php</h2>
<?php
$chain = [
    '../db.php'              => 'get_platform_db',
    '../userdb.php'          => 'get_user_db',
    '../includes/auth.php'   => 'require_login',
    '../includes/plans.php'  => 'get_plan_config',
    '../includes/functions.php' => 'csrf_token',
    '../includes/mailer.php' => 'send_welcome_email',
];
foreach ($chain as $file => $fn) {
    try {
        if (!function_exists($fn)) {
            require_once __DIR__ . '/' . $file;
        }
        echo '<p class="ok">&#x2705; ' . htmlspecialchars($file) . ' &mdash; <code>' . $fn . '()</code> defined</p>';
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; ' . htmlspecialchars($file) . ' FAILED: ' . htmlspecialchars($e->getMessage()) . ' in ' . $e->getFile() . ':' . $e->getLine() . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
}
?>

<p style="margin-top:40px;color:#45475a">&#x26A0; Delete this file when done debugging.</p>
</body>
</html>
