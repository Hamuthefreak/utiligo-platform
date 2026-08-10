<?php
/**
 * portal/probe.php - Zero-dependency error probe.
 * Loads NOTHING from the Utiligo codebase.
 * DELETE after debugging.
 */
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html><html><head><meta charset=UTF-8>
<link rel="icon" type="image/png" href="/assets/images/sitelogo.png">
<link rel="apple-touch-icon" href="/assets/images/sitelogo.png">
<title>Probe</title>
<style>body{font:13px monospace;background:#0d0d14;color:#cdd6f4;padding:24px}
.ok{color:#a6e3a1}.fail{color:#f38ba8}.warn{color:#fab387}
pre{background:#1e1e2e;padding:12px;border-radius:6px;white-space:pre-wrap;word-break:break-all}
th{background:#181825;color:#89b4fa}td,th{border:1px solid #313244;padding:5px 9px}
table{border-collapse:collapse;margin-bottom:16px;width:100%}
h2{color:#89b4fa;border-bottom:1px solid #313244;padding-bottom:4px;margin-top:28px}
</style></head><body>
<h1 style="color:#cba6f7">&#x1F50D; Zero-dep Probe</h1>
<p style="color:#6c7086"><?= date('Y-m-d H:i:s T') ?> &mdash; PHP <?= phpversion() ?></p>

<h2>1. PHP environment</h2>
<table>
<?php
$rows = [
  'PHP version'      => phpversion(),
  'session.status'   => session_status() . ' (0=none 1=disabled 2=active)',
  'display_errors'   => ini_get('display_errors'),
  'error_reporting'  => ini_get('error_reporting'),
  'open_basedir'     => ini_get('open_basedir') ?: '(not set)',
  'session.save_path'=> ini_get('session.save_path') ?: '(empty)',
  'PDO drivers'      => implode(', ', PDO::getAvailableDrivers()),
  'disable_functions'=> ini_get('disable_functions') ?: '(none)',
  '__DIR__'          => __DIR__,
  'HTTPS'            => $_SERVER['HTTPS'] ?? '(not set)',
  'SERVER_PORT'      => $_SERVER['SERVER_PORT'] ?? '(not set)',
];
foreach ($rows as $k=>$v) echo "<tr><td>$k</td><td>".htmlspecialchars($v)."</td></tr>\n";
?>
</table>

<h2>2. session_start() raw test</h2>
<?php
if (session_status() === PHP_SESSION_NONE) {
    // Test 1: plain session_start with no params
    $ok = @session_start();
    if ($ok) {
        echo '<p class="ok">&#x2705; session_start() with no params: OK &mdash; session_id=' . session_id() . '</p>';
        $_SESSION['probe_test'] = 1;
        echo '<p class="ok">&#x2705; $_SESSION write: OK</p>';
    } else {
        echo '<p class="fail">&#x274C; session_start() with no params FAILED</p>';
        $e = error_get_last();
        if ($e) echo '<pre>'.htmlspecialchars(print_r($e,true)).'</pre>';
    }
} else {
    echo '<p class="warn">&#x26A0; session_status = ' . session_status() . ' before we even called session_start(). Something already started/disabled it.</p>';
}
?>

<h2>3. DB credentials from env</h2>
<?php
// Read env vars directly — no config.php
$dbHost   = getenv('DB_HOST')   ?: 'localhost';
$dbName   = getenv('DB_NAME')   ?: 'utiligo_platform';
$dbUser   = getenv('DB_USER')   ?: '(not set in env)';
$udbHost  = getenv('USERDB_HOST') ?: 'localhost';
$udbName  = getenv('USERDB_NAME') ?: 'utiligo_users_db';
$udbUser  = getenv('USERDB_USER') ?: '(not set in env)';
echo "<p>Platform DB: host=<b>$dbHost</b> name=<b>$dbName</b> user=<b>$dbUser</b></p>";
echo "<p>User DB: host=<b>$udbHost</b> name=<b>$udbName</b> user=<b>$udbUser</b></p>";
?>

<h2>4. Platform DB raw PDO test</h2>
<?php
// Pull credentials from config.php constants if available, else env
$cfgLoaded = false;
try {
    if (!defined('DB_HOST')) {
        require_once dirname(__DIR__).'/config.php';
        ini_set('display_errors','1'); // re-enable after config silences it
    }
    $cfgLoaded = true;
    echo '<p class="ok">&#x2705; config.php loaded OK</p>';
} catch (Throwable $e) {
    echo '<p class="fail">&#x274C; config.php THREW: '.htmlspecialchars($e->getMessage()).' @ '.$e->getFile().':'.$e->getLine().'</p>';
    echo '<pre>'.htmlspecialchars($e->getTraceAsString()).'</pre>';
}
ini_set('display_errors','1');

if ($cfgLoaded) {
    echo '<p>session_status AFTER config.php: <b>' . session_status() . '</b> (2=active means session boot worked)</p>';
    try {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]
        );
        $pdo->query('SELECT 1');
        echo '<p class="ok">&#x2705; Platform DB connected OK</p>';
        $t = $pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetch();
        echo $t ? '<p class="ok">&#x2705; schema_migrations exists</p>' : '<p class="fail">&#x274C; schema_migrations MISSING</p>';
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; Platform DB FAILED: '.htmlspecialchars($e->getMessage()).'</p>';
    }

    echo '<h2>5. User DB raw PDO test</h2>';
    try {
        $updo = new PDO(
            'mysql:host='.USERDB_HOST.';dbname='.USERDB_NAME.';charset=utf8mb4',
            USERDB_USER, USERDB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]
        );
        $updo->query('SELECT 1');
        echo '<p class="ok">&#x2705; User DB connected OK</p>';
        foreach (['utiligo_users','utiligo_remember_tokens'] as $tbl) {
            $t = $updo->query("SHOW TABLES LIKE '$tbl'")->fetch();
            echo $t ? "<p class=\"ok\">&#x2705; $tbl exists</p>" : "<p class=\"fail\">&#x274C; $tbl MISSING</p>";
        }
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; User DB FAILED: '.htmlspecialchars($e->getMessage()).'</p>';
    }

    echo '<h2>6. auth.php load test</h2>';
    try {
        if (!function_exists('check_remember_me_cookie')) require_once dirname(__DIR__).'/includes/auth.php';
        echo '<p class="ok">&#x2705; auth.php loaded OK</p>';
        echo '<p>check_remember_me_cookie defined: '.(function_exists('check_remember_me_cookie')?'<span class="ok">YES</span>':'<span class="fail">NO</span>').'</p>';
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; auth.php THREW: '.htmlspecialchars($e->getMessage()).' @ '.$e->getFile().':'.$e->getLine().'</p>';
        echo '<pre>'.htmlspecialchars($e->getTraceAsString()).'</pre>';
    }

    echo '<h2>7. plans.php load test</h2>';
    try {
        if (!function_exists('get_plan_config')) require_once dirname(__DIR__).'/includes/plans.php';
        echo '<p class="ok">&#x2705; plans.php loaded OK</p>';
    } catch (Throwable $e) {
        echo '<p class="fail">&#x274C; plans.php THREW: '.htmlspecialchars($e->getMessage()).' @ '.$e->getFile().':'.$e->getLine().'</p>';
        echo '<pre>'.htmlspecialchars($e->getTraceAsString()).'</pre>';
    }

    echo '<h2>8. Full billing.php require chain</h2>';
    $chain = [
        dirname(__DIR__).'/db.php'                 => 'get_platform_db',
        dirname(__DIR__).'/userdb.php'             => 'get_user_db',
        dirname(__DIR__).'/includes/auth.php'      => 'require_login',
        dirname(__DIR__).'/includes/plans.php'     => 'get_plan_config',
        dirname(__DIR__).'/includes/functions.php' => 'csrf_token',
        dirname(__DIR__).'/includes/mailer.php'    => 'send_welcome_email',
        dirname(__DIR__).'/includes/portal_layout.php' => null,
    ];
    foreach ($chain as $file=>$fn) {
        try {
            if ($fn === null || !function_exists($fn)) require_once $file;
            echo '<p class="ok">&#x2705; '.basename($file).' OK'.(($fn&&function_exists($fn))?' &mdash; '.$fn.'() defined':'').'</p>';
        } catch (Throwable $e) {
            echo '<p class="fail">&#x274C; '.basename($file).' THREW: '.htmlspecialchars($e->getMessage()).' @ '.$e->getFile().':'.$e->getLine().'</p>';
            echo '<pre>'.htmlspecialchars($e->getTraceAsString()).'</pre>';
        }
    }
}

$logPaths = [dirname(__DIR__).'/storage/php_errors.log', sys_get_temp_dir().'/utiligo_errors.log', '/tmp/utiligo_errors.log', ini_get('error_log')];
echo '<h2>9. Error logs</h2>';
$found=false;
foreach ($logPaths as $lp) {
    if ($lp && file_exists($lp) && is_readable($lp) && filesize($lp)>0) {
        $tail = array_slice(file($lp),-80);
        echo '<p class="ok">'.htmlspecialchars($lp).'</p><pre>'.htmlspecialchars(implode('',$tail)).'</pre>';
        $found=true; break;
    }
}
if (!$found) echo '<p class="warn">No readable log found. Check InfinityFree cPanel &rarr; Error Log.</p>';
?>
<p style="margin-top:32px;color:#45475a">Delete probe.php when done.</p>
</body></html>
