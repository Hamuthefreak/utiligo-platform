<?php
/**
 * tests/run.php — payment-path test suite
 *
 *   php tests/run.php              run everything
 *   php tests/run.php --require-db fail instead of skipping when the DB is down
 *
 * No network access, no Stripe account, no Composer, and no test framework.
 * Stripe is replaced by tests/lib/stripe_stub.php, reached by pointing
 * STRIPE_API_BASE at it — which is a supported configuration of the real code,
 * not a test-only branch inside it.
 *
 * The suite needs two PHP extensions (`curl`, `pdo_mysql`) and a reachable
 * MySQL server. On a machine where the extensions are installed but not
 * enabled, this script re-executes itself once with them enabled; on a machine
 * with no MySQL it skips the database-backed tests with a clear reason rather
 * than reporting a false pass.
 */

$requireDb = in_array('--require-db', $argv ?? [], true);

/* ── Re-exec once with the extensions enabled, if they are merely disabled ── */
$missing = array_values(array_filter(['curl', 'pdo_mysql'], fn($e) => !extension_loaded($e)));
if ($missing && PHP_SAPI === 'cli' && getenv('UTILIGO_TESTS_REEXEC') !== '1' && function_exists('proc_open')) {
    $extensionDir = (string)ini_get('extension_dir');
    $loadable     = [];
    foreach ($missing as $ext) {
        foreach (['php_' . $ext . '.dll', $ext . '.so'] as $candidate) {
            if (is_file($extensionDir . DIRECTORY_SEPARATOR . $candidate)) {
                $loadable[] = $ext;
                break;
            }
        }
    }
    if ($loadable) {
        $extensionFlags = array_merge(...array_map(fn($e) => ['-d', 'extension=' . $e], $loadable));
        $command = array_merge(
            [PHP_BINARY],
            $extensionFlags,
            [__FILE__],
            $requireDb ? ['--require-db'] : []
        );
        putenv('UTILIGO_TESTS_REEXEC=1');
        // Hand the flags to the child servers this run spawns. They are new
        // processes: without this they come up with no database driver, and
        // every database-backed assertion fails for a reason the output cannot
        // explain.
        putenv('UTILIGO_TEST_PHP_FLAGS=' . implode(' ', $extensionFlags));
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, __DIR__);
        exit($process === false ? 1 : proc_close($process));
    }
}

/* ── Test-time configuration ────────────────────────────────────────────────
 * APP_ENV=test keeps config.php from redirecting error_log at the tracked
 * storage/php_errors.log, so running the suite never dirties the working tree.
 */
$defaults = [
    'APP_ENV'               => 'test',
    'DB_HOST'               => '127.0.0.1',
    'DB_NAME'               => 'utiligo_test_platform',
    'DB_USER'               => 'root',
    // Deliberately NOT empty. config.php reads credentials as
    // `getenv('USERDB_PASS') ?: 'CHANGE_ME'`, and an empty string is falsy — so
    // an empty password here silently becomes the literal 'CHANGE_ME' and every
    // connection is refused. Give the test server a real password instead.
    'DB_PASS'               => 'utiligo_test_pw',
    'USERDB_HOST'           => '127.0.0.1',
    'USERDB_NAME'           => 'utiligo_test_users',
    'USERDB_USER'           => 'root',
    'USERDB_PASS'           => 'utiligo_test_pw',
    // Fixed so the test process and the spawned application server agree on
    // CRON_SECRET. Left unset, config.php generates a random one per process,
    // which makes the cron endpoints (lead_search_worker.php) untestable.
    'UTILIGO_CRON_SECRET'   => 'cron_test_secret',
    // Pinned to the placeholder so a developer machine with a real Places key
    // in its environment can never make the suite call Google for real. The
    // runner refuses to start on this value, which is what the worker's
    // failure-path test asserts.
    'GOOGLE_PLACES_API_KEY' => 'YOUR_GOOGLE_PLACES_API_KEY',
    // The background worker is invoked explicitly by the tests, so the
    // fire-and-forget kick is off: otherwise the enqueue would start a worker
    // against the job before the test has finished arranging its fixtures.
    'UTILIGO_LEAD_SEARCH_KICK' => '0',
    'STRIPE_SECRET_KEY'     => 'sk_test_stub_key',
    'STRIPE_WEBHOOK_SECRET' => 'whsec_test_stub_secret',
    'STRIPE_PRO_PRICE_ID'   => 'price_test_pro',
    'STRIPE_ENT_PRICE_ID'   => 'price_test_ent',
];
foreach ($defaults as $key => $value) {
    if (getenv($key) === false || getenv($key) === '') {
        putenv("$key=$value");
    }
}

// Before config.php loads includes/global_error_handler.php: keep the app's
// error log inside tests/tmp so a fault raised during a test cannot append to
// storage/php_errors.log, which is tracked in git.
require_once __DIR__ . '/lib/prepend.php';

require_once __DIR__ . '/lib/harness.php';

// The databases have to exist before config.php loads: it applies every pending
// migration on the way in, and that is where the schema comes from.
[$dbReady, $dbWhy] = t_bootstrap_databases();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/entitlements.php';
require_once __DIR__ . '/../includes/stripe_api.php';

// config.php loads includes/global_error_handler.php, which points PHP's own
// error_log at storage/php_errors.log — a tracked file. Several tests
// deliberately provoke a rejection the application logs, so point this
// process's error_log at tests/tmp instead. (The application server the HTTP
// tests spawn re-resolves that path on every request, so it can still append a
// few expected lines; the run reports that rather than hiding it.)
@ini_set('error_log', t_tmp_dir() . '/app_errors.log');

$trackedLog = dirname(__DIR__) . '/storage/php_errors.log';
$trackedLogBefore = is_file($trackedLog) ? (int)filesize($trackedLog) : -1;

echo "\n\033[1mUtiligo payment-path tests\033[0m  (PHP " . PHP_VERSION . ")\n";
echo "  user DB: " . USERDB_HOST . '/' . USERDB_NAME . "\n";

/* ── Databases ──────────────────────────────────────────────────────────────
 * Created if missing; the schema itself comes from migrations/ via the app's
 * own runner, so the tests always run against the real, current schema —
 * including any migration added later.
 */
if ($dbReady) {
    try {
        // config.php has just applied migrations/ to both databases. This only
        // proves the user table is really there before the first test assumes it.
        t_db()->query('SELECT 1 FROM utiligo_users LIMIT 1');
        echo "  schema:  applied from migrations/\n";
    } catch (T_Skip $e) {
        $dbReady = false;
        $dbWhy   = $e->getMessage();
    } catch (Throwable $e) {
        $dbReady = false;
        $dbWhy   = 'schema not applied: ' . $e->getMessage();
    }
}

/* ── Stripe stub + application server under test ───────────────────────────── */
$stub  = null;
$app   = null;
if ($dbReady) {
    try {
        $stub = t_server(__DIR__ . '/lib', __DIR__ . '/lib/stripe_stub.php', [], 'stripe stub');
        putenv('STRIPE_API_BASE=' . $stub['url']);

        // Sessions live in tests/tmp so the suite can hand a test an already
        // authenticated session, and so a test run never touches the real one.
        // The prepend file does the same for the application's error log.
        $app = t_server(dirname(__DIR__), null, [], 'application', [
            '-d', 'session.save_path=' . t_sessions_dir(),
            '-d', 'auto_prepend_file=' . __DIR__ . '/lib/prepend.php',
        ]);

        echo "  stripe:  stub at " . $stub['url'] . " (api.stripe.com never contacted)\n";
        echo "  app:     " . $app['url'] . "\n";
    } catch (T_Skip $e) {
        $app = null;
        echo "  servers: unavailable — " . $e->getMessage() . "\n";
    }
}

$context = [
    'db_ready' => $dbReady,
    'db_why'   => $dbWhy,
    'app_url'  => $app['url'] ?? null,
    'stub_url' => $stub['url'] ?? null,
];

register_shutdown_function(function () use ($trackedLog, $trackedLogBefore) {
    t_stop_servers();
    t_cleanup_users();

    // The suite must leave the checkout as it found it. It cannot stop the
    // spawned server from writing to the application's own log file, so say so
    // rather than silently modifying a tracked file.
    $after = is_file($trackedLog) ? (int)filesize($trackedLog) : -1;
    if ($after !== $trackedLogBefore) {
        echo "\nNote: storage/php_errors.log changed during this run — some tests\n"
           . "provoke log lines on purpose. Restore it with:\n"
           . "  git checkout -- storage/php_errors.log\n";
    }
});

/* ── Run the cases ─────────────────────────────────────────────────────────── */
$files = glob(__DIR__ . '/cases/test_*.php') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    echo "\n\033[1m▶ " . $name . "\033[0m";

    try {
        (function () use ($file, $context) {
            require $file;
        })();
    } catch (T_Skip $e) {
        echo "\n\n  (file skipped: " . $e->getMessage() . ")";
        $GLOBALS['t_skipped']++;
    } catch (Throwable $e) {
        echo "\n    \033[31m✗\033[0m unexpected error: " . $e->getMessage()
           . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $GLOBALS['t_fail']++;
        $GLOBALS['t_failures'][] = $name . ' → uncaught ' . get_class($e) . ': ' . $e->getMessage();
    }
}

/* ── Summary ───────────────────────────────────────────────────────────────── */
echo "\n" . str_repeat('─', 72) . "\n";

if (!$dbReady) {
    echo "\033[33mDatabase-backed and HTTP tests were skipped.\033[0m\n";
    echo 'Reason: ' . $dbWhy . "\n";
    echo "They are not reporting a pass — see tests/README.md for how to point the suite at a MySQL.\n";
}

printf("\033[1m%d passed, %d failed, %d skipped\033[0m\n",
    $GLOBALS['t_pass'], $GLOBALS['t_fail'], $GLOBALS['t_skipped']);

if ($GLOBALS['t_failures']) {
    echo "\nFailures:\n";
    foreach ($GLOBALS['t_failures'] as $failure) {
        echo '  • ' . $failure . "\n";
    }
}

if ($GLOBALS['t_fail'] > 0) {
    exit(1);
}
if ($requireDb && !$dbReady) {
    echo "\n--require-db was given, so a missing database is a failure.\n";
    exit(1);
}

echo "\n\033[32mOK\033[0m\n";
exit(0);
