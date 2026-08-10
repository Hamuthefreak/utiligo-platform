<?php
/**
 * portal/debug_errors.php  —  Utiligo live error probe
 * Visit: /portal/debug_errors.php
 * DELETE this file once the 500 is resolved.
 */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="/assets/images/sitelogo-icon.png">
<link rel="apple-touch-icon" href="/assets/images/sitelogo-icon.png">
<title>Utiligo Error Probe</title>
<style>
body{font-family:monospace;background:#0d0d14;color:#cdd6f4;padding:30px;font-size:13px;line-height:1.6;}
h2{color:#89b4fa;border-bottom:1px solid #313244;padding-bottom:6px;margin-top:32px;}
.ok{color:#a6e3a1;} .fail{color:#f38ba8;} .warn{color:#fab387;}
pre{background:#1e1e2e;padding:14px;border-radius:8px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;}
table{border-collapse:collapse;width:100%;margin-bottom:20px;}
td,th{border:1px solid #313244;padding:6px 10px;text-align:left;}
th{background:#181825;color:#89b4fa;}
</style>
</head>
<body>
<h1 style="color:#cba6f7">&#x1F50D; Utiligo Error Probe</h1>
<p style="color:#6c7086">Generated: <?= date('Y-m-d H:i:s T') ?></p>

<?php
/* ============================================================
   SECTION 0 — Replay config.php boot sequence step by step
   This is where the 500 on billing.php is actually happening.
   ============================================================ */
?>
<h2>0. Config boot sequence (step-by-step replay)</h2>
<?php

// 0a. Define constants config.php would define (safe to re-define with if(!defined))
// Just load it — it's already safe to re-include since everything uses if(!defined)
// We can't re-run config.php because session_start() would throw. Instead we probe
// each dangerous call individually.

// 0b. Session
echo '<p>session_status before: ' . session_status() . ' (2=active, 1=disabled, 0=none)</p>';
if (session_status() === PHP_SESSION_NONE) {
    try {
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        echo '<p class="ok">&#x2705; session_start() OK &mdash; session ID: ' . session_id() . '</p>';
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; session_start() FAILED: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
} else {
    echo '<p class="ok">&#x2705; Session already active</p>';
}

// 0c. Load config (forces display_errors=0 but we reset it)
try {
    require_once __DIR__ . '/../config.php';
    ini_set('display_errors','1'); // re-enable after config silences
    echo '<p class="ok">&#x2705; config.php loaded OK</p>';
} catch (Throwable $e) {
    ini_set('display_errors','1');
    echo '<p class="fail">&#x274C; <strong>config.php THREW:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '<p class="warn">&#x26A0; This is most likely the root cause of the billing 500.</p>';
}

// 0d. Check if check_remember_me_cookie is defined and call it in isolation
echo '<p>check_remember_me_cookie defined: ' . (function_exists('check_remember_me_cookie') ? '<span class="ok">YES</span>' : '<span class="fail">NO</span>') . '</p>';
if (function_exists('check_remember_me_cookie')) {
    try {
        check_remember_me_cookie();
        echo '<p class="ok">&#x2705; check_remember_me_cookie() ran without exception</p>';
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; <strong>check_remember_me_cookie() THREW (UNCAUGHT):</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '<p class="warn">&#x26A0; This is the root cause of the 500. The try/catch inside auth.php is NOT catching it.</p>';
    }
}

// 0e. run_pending_migrations against both DBs
echo '<h2>0e. Migration runner</h2>';
try {
    if (!function_exists('get_platform_db')) require_once __DIR__ . '/../db.php';
    if (!function_exists('run_pending_migrations')) require_once __DIR__ . '/../includes/run_migrations.php';
    run_pending_migrations(get_platform_db(), dirname(__DIR__) . '/migrations');
    echo '<p class="ok">&#x2705; Platform DB migrations OK</p>';
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; Platform DB migration FAILED: ' . htmlspecialchars($e->getMessage()) . ' &mdash; ' . $e->getFile() . ':' . $e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}
try {
    if (!function_exists('get_user_db')) require_once __DIR__ . '/../userdb.php';
    run_pending_migrations(get_user_db(), dirname(__DIR__) . '/migrations');
    echo '<p class="ok">&#x2705; User DB migrations OK</p>';
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; User DB migration FAILED: ' . htmlspecialchars($e->getMessage()) . ' &mdash; ' . $e->getFile() . ':' . $e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}
?>

<h2>1. PHP Info</h2>
<table>
<tr><th>Key</th><th>Value</th></tr>
<tr><td>PHP version</td><td><?= phpversion() ?></td></tr>
<tr><td>APP_ENV</td><td><?= defined('APP_ENV') ? APP_ENV : '<span class="warn">not defined</span>' ?></td></tr>
<tr><td>display_errors</td><td><?= ini_get('display_errors') ?></td></tr>
<tr><td>error_log path</td><td><?= ini_get('error_log') ?: '(none)' ?></td></tr>
<tr><td>session status</td><td><?= session_status() ?></td></tr>
<tr><td>PDO drivers</td><td><?= implode(', ', PDO::getAvailableDrivers()) ?></td></tr>
<tr><td>open_basedir</td><td><?= ini_get('open_basedir') ?: '(not set)' ?></td></tr>
<tr><td>__DIR__ (portal)</td><td><?= __DIR__ ?></td></tr>
</table>

<h2>2. Platform DB</h2>
<?php
$_db_host = defined('DB_HOST') ? DB_HOST : '??';
$_db_name = defined('DB_NAME') ? DB_NAME : '??';
$_db_user = defined('DB_USER') ? DB_USER : '??';
echo "<p>Host: <strong>$_db_host</strong> &nbsp; DB: <strong>$_db_name</strong> &nbsp; User: <strong>$_db_user</strong></p>";
try {
    if (!function_exists('get_platform_db')) require_once __DIR__ . '/../db.php';
    $pdb = get_platform_db();
    $pdb->query('SELECT 1');
    echo '<p class="ok">&#x2705; Platform DB OK</p>';
    $t = $pdb->query("SHOW TABLES LIKE 'schema_migrations'")->fetch();
    echo $t ? '<p class="ok">&#x2705; schema_migrations exists</p>' : '<p class="fail">&#x274C; schema_migrations MISSING</p>';
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; Platform DB FAILED: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

<h2>3. User DB</h2>
<?php
$_udb_host = defined('USERDB_HOST') ? USERDB_HOST : '??';
$_udb_name = defined('USERDB_NAME') ? USERDB_NAME : '??';
$_udb_user = defined('USERDB_USER') ? USERDB_USER : '??';
echo "<p>Host: <strong>$_udb_host</strong> &nbsp; DB: <strong>$_udb_name</strong> &nbsp; User: <strong>$_udb_user</strong></p>";
try {
    if (!function_exists('get_user_db')) require_once __DIR__ . '/../userdb.php';
    $udb = get_user_db();
    $udb->query('SELECT 1');
    echo '<p class="ok">&#x2705; User DB OK</p>';
    foreach (['utiligo_users','utiligo_remember_tokens','utiligo_email_verifications'] as $tbl) {
        $exists = $udb->query("SHOW TABLES LIKE '$tbl'")->fetch();
        echo $exists ? "<p class=\"ok\">&#x2705; $tbl exists</p>" : "<p class=\"fail\">&#x274C; $tbl MISSING</p>";
    }
    // Column check
    $cols = $udb->query("SHOW COLUMNS FROM utiligo_users")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['id','email','password_hash','full_name','plan','subscription_status','subscription_started_at','is_admin','email_verified','created_at'];
    echo '<p><strong>utiligo_users columns:</strong> ' . implode(', ', $cols) . '</p>';
    foreach ($required as $col) {
        if (!in_array($col, $cols)) echo "<p class=\"fail\">&#x274C; Column '$col' MISSING</p>";
    }
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; User DB FAILED: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

<h2>4. Full require chain (billing.php order)</h2>
<?php
$chain = [
    '../db.php'                 => 'get_platform_db',
    '../userdb.php'             => 'get_user_db',
    '../includes/auth.php'      => 'require_login',
    '../includes/plans.php'     => 'get_plan_config',
    '../includes/functions.php' => 'csrf_token',
    '../includes/mailer.php'    => 'send_welcome_email',
];
foreach ($chain as $file => $fn) {
    try {
        if (!function_exists($fn)) require_once __DIR__ . '/' . $file;
        echo '<p class="ok">&#x2705; ' . htmlspecialchars($file) . ' &mdash; <code>' . $fn . '()</code> defined</p>';
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; ' . htmlspecialchars($file) . ' FAILED: ' . htmlspecialchars($e->getMessage()) . ' in ' . $e->getFile() . ':' . $e->getLine() . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
}
?>

<h2>5. Error logs</h2>
<?php
$logPaths = [
    __DIR__ . '/../storage/php_errors.log',
    '/tmp/php_errors.log',
    ini_get('error_log'),
];
$found = false;
foreach ($logPaths as $lp) {
    if ($lp && file_exists($lp) && is_readable($lp) && filesize($lp) > 0) {
        $lines = file($lp);
        $tail  = array_slice($lines, -80);
        echo '<p class="ok">Reading: ' . htmlspecialchars($lp) . '</p>';
        echo '<pre>' . htmlspecialchars(implode('', $tail)) . '</pre>';
        $found = true;
        break;
    }
}
if (!$found) {
    echo '<p class="warn">&#x26A0; No readable error log found. Check InfinityFree control panel → Error Log.</p>';
}
?>

<h2>6. run_migrations.php internals</h2>
<?php
try {
    if (!function_exists('run_pending_migrations')) require_once __DIR__ . '/../includes/run_migrations.php';
    $migrDir = dirname(__DIR__) . '/migrations';
    $files = glob($migrDir . '/*.sql');
    echo '<p>Migration dir: <strong>' . htmlspecialchars($migrDir) . '</strong></p>';
    echo '<p>SQL files found: <strong>' . count($files) . '</strong></p>';
    if ($files) {
        echo '<pre>' . htmlspecialchars(implode("\n", array_map('basename', $files))) . '</pre>';
    }
} catch (Throwable $e) {
    echo '<p class="fail">' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

<p style="margin-top:40px;color:#45475a">&#x26A0; Delete <code>portal/debug_errors.php</code> when done.</p>
</body>
</html>
