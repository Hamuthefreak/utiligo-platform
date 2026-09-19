<?php
/**
 * tests/lib/harness.php
 *
 * Shared plumbing for the payment-path test suite. Loaded by tests/run.php.
 *
 * Design notes
 * ────────────
 * • The tests exercise the real files: real stripe-webhook.php over HTTP, real
 *   purchase-success.php, real entitlements.php, real database. Nothing here
 *   reimplements the thing under test, because the bugs worth catching in a
 *   payment path are exactly the ones a mock would paper over.
 *
 * • Stripe is never contacted. STRIPE_API_BASE points stripe_request() at
 *   tests/lib/stripe_stub.php, and webhook signatures are produced with the
 *   test webhook secret using the same helper the app uses.
 *
 * • No test touches a remote database. t_db() refuses any non-loopback host
 *   before it connects, so a stray storage/config_overrides.php cannot turn a
 *   test run into a production write.
 */

/** Thrown when a whole test file cannot run (no database, no HTTP). */
class T_Skip extends Exception {}

$GLOBALS['t_pass']    = 0;
$GLOBALS['t_fail']    = 0;
$GLOBALS['t_skipped'] = 0;
$GLOBALS['t_failures'] = [];
$GLOBALS['t_case']    = '(none)';

function t_section(string $name): void
{
    $GLOBALS['t_case'] = $name;
    echo "\n  \033[1m" . $name . "\033[0m\n";
}

function t_ok(bool $condition, string $label): bool
{
    if ($condition) {
        $GLOBALS['t_pass']++;
        echo "    \033[32m✓\033[0m " . $label . "\n";
    } else {
        $GLOBALS['t_fail']++;
        $GLOBALS['t_failures'][] = $GLOBALS['t_case'] . ' → ' . $label;
        echo "    \033[31m✗\033[0m " . $label . "\n";
    }
    return $condition;
}

function t_is($actual, $expected, string $label): bool
{
    if ($actual === $expected) {
        return t_ok(true, $label);
    }
    return t_ok(false, $label . ' (expected ' . t_show($expected) . ', got ' . t_show($actual) . ')');
}

function t_not($actual, $expected, string $label): bool
{
    return $actual !== $expected
        ? t_ok(true, $label)
        : t_ok(false, $label . ' (should not have been ' . t_show($expected) . ')');
}

function t_like(string $haystack, string $needle, string $label): bool
{
    return str_contains($haystack, $needle)
        ? t_ok(true, $label)
        : t_ok(false, $label . ' (missing "' . $needle . '")');
}

function t_unlike(string $haystack, string $needle, string $label): bool
{
    return !str_contains($haystack, $needle)
        ? t_ok(true, $label)
        : t_ok(false, $label . ' (unexpectedly contains "' . $needle . '")');
}

/** Both counts equal AND in order — used to pin SQL params to placeholders. */
function t_same_list(array $actual, array $expected, string $label): bool
{
    return t_is($actual, $expected, $label);
}

function t_skip(string $label, string $why): void
{
    $GLOBALS['t_skipped']++;
    echo "    \033[33m–\033[0m " . $label . ' (skipped: ' . $why . ")\n";
}

function t_show($value): string
{
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return 'null';
    }
    $text = (string)$value;
    return strlen($text) > 120 ? substr($text, 0, 117) . '...' : '"' . $text . '"';
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Database
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * Create the test databases if they are missing.
 *
 * This runs BEFORE config.php is loaded, and so reads the environment directly
 * rather than the constants config.php defines. config.php applies every
 * pending migration as it loads, and a migration cannot create the database it
 * needs to run in. Getting this order wrong produces a confusing failure —
 * "Table 'utiligo_test_users.utiligo_users' doesn't exist" — on a fresh
 * checkout, while passing on any machine where the databases already existed.
 *
 * @return array [bool ready, string why]
 */
function t_bootstrap_databases(): array
{
    $host = (string)(getenv('USERDB_HOST') ?: '127.0.0.1');
    $user = (string)(getenv('USERDB_USER') ?: 'root');
    $pass = getenv('USERDB_PASS');
    $pass = $pass === false ? '' : (string)$pass;

    $names = [
        (string)(getenv('USERDB_NAME') ?: 'utiligo_users_db'),
        (string)(getenv('DB_NAME') ?: 'utiligo_platform'),
    ];

    try {
        t_assert_loopback($host, 'USERDB');
        t_assert_loopback((string)(getenv('DB_HOST') ?: '127.0.0.1'), 'DB');

        $pdo = new PDO('mysql:host=' . $host, $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach ($names as $name) {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '`
                        DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        return [true, ''];
    } catch (T_Skip $e) {
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/** Loopback-only guard: a test run must never reach a remote database. */
function t_assert_loopback(string $host, string $which): void
{
    $host = strtolower(trim($host));
    $ok = in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)
        || str_starts_with($host, '127.')
        || $host === '';
    if (!$ok) {
        throw new T_Skip("$which host is not loopback ($host) — refusing to run tests against it");
    }
}

function t_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    t_assert_loopback((string)USERDB_HOST, 'USERDB');

    try {
        $pdo = new PDO(
            'mysql:host=' . USERDB_HOST . ';dbname=' . USERDB_NAME . ';charset=utf8mb4',
            USERDB_USER,
            USERDB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) {
        throw new T_Skip('user database unreachable at ' . USERDB_HOST . ': ' . $e->getMessage());
    }

    return $pdo;
}

/**
 * Platform database handle.
 *
 * The account tables live in the user DB (t_db()); everything the lead
 * workspace owns — the lead pool, the cache and the search queue — lives in
 * the platform DB. Same loopback guard as t_db().
 */
function t_platform_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    t_assert_loopback((string)DB_HOST, 'DB');

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) {
        throw new T_Skip('platform database unreachable at ' . DB_HOST . ': ' . $e->getMessage());
    }

    return $pdo;
}

/** Create or reset an account fixture, and return its id. */
function t_fixture(array $overrides = []): int
{
    $pdo = t_db();
    $row = $overrides + [
        'email'               => 'buyer' . bin2hex(random_bytes(4)) . '@example.test',
        'password_hash'       => password_hash('test-password-123', PASSWORD_BCRYPT),
        'full_name'           => 'Test Buyer',
        'plan'                => 'free',
        'subscription_status' => 'none',
        'email_verified'      => 1,
        'is_admin'            => 0,
    ];

    $cols = array_keys($row);
    $sql = 'INSERT INTO utiligo_users (`' . implode('`, `', $cols) . '`) VALUES ('
         . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));

    return (int)$pdo->lastInsertId();
}

function t_user(int $id): ?array
{
    $stmt = t_db()->prepare('SELECT * FROM utiligo_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function t_cleanup_users(): void
{
    try {
        t_db()->exec("DELETE FROM utiligo_users WHERE email LIKE '%@example.test'");
    } catch (Throwable $e) {
        // Best effort: an empty database is fine too.
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
 * HTTP
 * ──────────────────────────────────────────────────────────────────────────── */

/** Free TCP port on loopback. */
function t_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) {
        throw new T_Skip('cannot allocate a local port: ' . $errstr);
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int)substr($name, strrpos($name, ':') + 1);
}

/**
 * Start a PHP built-in server as a child process.
 *
 * The child gets the whole environment plus $env, so it connects to the same
 * test database and the Stripe stub as the test process.
 */
function t_server(string $rootDir, ?string $router, array $env, string $label, array $extraPhpFlags = []): array
{
    $port = t_free_port();
    $cmd  = [PHP_BINARY];
    foreach (array_merge(t_php_flags(), $extraPhpFlags) as $flag) {
        $cmd[] = $flag;
    }
    $cmd[] = '-S';
    $cmd[] = '127.0.0.1:' . $port;
    $cmd[] = '-t';
    $cmd[] = $rootDir;
    if ($router !== null) {
        $cmd[] = $router;
    }

    $env['APP_BASE_URL'] = 'http://127.0.0.1:' . $port;

    // Output goes to a file rather than a pipe. Two reasons: a pipe is
    // inherited by the server process and keeps the parent's stdout open, which
    // leaves a wrapper (a shell, or a CI runner) waiting for EOF after the suite
    // has already finished; and a file leaves a readable record of what the
    // server said when a test fails.
    $logPath    = t_tmp_dir() . '/server-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.log';
    $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';
    $descriptors = [
        0 => ['file', $nullDevice, 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $rootDir, array_merge(getenv(), $env));
    if (!is_resource($proc)) {
        throw new T_Skip('could not start the ' . $label . ' server');
    }

    $server = [
        'proc' => $proc,
        'port' => $port,
        'url'  => 'http://127.0.0.1:' . $port,
        'log'  => $logPath,
    ];
    t_wait_for_server($server);
    t_servers()[] = $server;

    return $server;
}

function &t_servers(): array
{
    static $servers = [];
    return $servers;
}

function t_wait_for_server(array $server, int $timeoutSeconds = 15): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $server['port'], $errno, $errstr, 0.3);
        if ($conn) {
            fclose($conn);
            return;
        }
        usleep(100000);
    }
    throw new T_Skip('server on port ' . $server['port'] . ' never came up');
}

function t_stop_servers(): void
{
    foreach (t_servers() as $server) {
        if (is_resource($server['proc'])) {
            proc_terminate($server['proc']);
            proc_close($server['proc']);
        }
    }
}

/**
 * One HTTP request. Redirects are NOT followed, so tests can assert on the
 * Location header the app sends — which is how the checkout and cancel flows
 * are verified without a browser.
 *
 * @param array $opt 'json'|'form' body data, 'headers' array, 'cookies' jar path
 */
function t_http(string $method, string $url, array $opt = []): array
{
    if (!function_exists('curl_init')) {
        throw new T_Skip('the cURL extension is required for the HTTP tests');
    }

    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $headers[] = rtrim($line, "\r\n");
            return strlen($line);
        },
    ]);

    if (isset($opt['raw'])) {
        // Sent byte-for-byte as given, so a signature computed over exactly
        // these bytes is the one the server verifies.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['raw']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            ['Content-Type: application/json'],
            $opt['headers'] ?? []
        ));
    } elseif (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            ['Content-Type: application/json'],
            $opt['headers'] ?? []
        ));
    } elseif (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opt['headers'] ?? []);
    } elseif (!empty($opt['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opt['headers']);
    }

    if (!empty($opt['cookies'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opt['cookies']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opt['cookies']);
    }
    if (!empty($opt['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $opt['cookie']);
    }

    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    $location = null;
    foreach ($headers as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
            break;
        }
    }

    return [
        'status'   => $status,
        'headers'  => $headers,
        'body'     => is_string($body) ? $body : '',
        'location' => $location,
        'error'    => $error,
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Stripe stub control
 * ──────────────────────────────────────────────────────────────────────────── */

function t_tmp_dir(): string
{
    $dir = __DIR__ . '/../tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

/** Where the application server keeps its session files. */
function t_sessions_dir(): string
{
    $dir = t_tmp_dir() . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

/**
 * Build an authenticated session for $userId and return the Cookie header.
 *
 * The suite writes the session file directly rather than driving the login
 * form: logging in needs a verified email, a CSRF token and a second step, and
 * none of that is what these tests are about. What they need is a request that
 * arrives already signed in, which is exactly what a session file is.
 */
function t_login(int $userId, array $extra = []): string
{
    $sid  = bin2hex(random_bytes(16));
    $blob = '';
    foreach (['user_id' => $userId] + $extra as $key => $value) {
        $blob .= is_int($value)
            ? $key . '|i:' . $value . ';'
            : $key . '|s:' . strlen((string)$value) . ':"' . $value . '";';
    }
    file_put_contents(t_sessions_dir() . '/sess_' . $sid, $blob);

    return 'PHPSESSID=' . $sid;
}

/**
 * Publish the Checkout Sessions the stub should return, keyed by session id.
 * The test decides each scenario; the stub stays dumb.
 */
function t_set_stripe_sessions(array $sessions): void
{
    file_put_contents(t_tmp_dir() . '/stripe_sessions.json', json_encode($sessions, JSON_PRETTY_PRINT));
}

/** The last request the stub received, for asserting what the app actually sent. */
function t_last_stripe_request(): ?array
{
    $path = t_tmp_dir() . '/stripe_last_request.json';
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function t_reset_stripe_stub(): void
{
    @unlink(t_tmp_dir() . '/stripe_last_request.json');
}

/**
 * A Checkout Session shaped the way Stripe returns one, for the success page to
 * verify. Defaults describe a completed, paid purchase by $userId.
 */
function t_session(string $id, array $overrides = []): array
{
    return $overrides + [
        'id'                  => $id,
        'object'              => 'checkout.session',
        'status'              => 'complete',
        'payment_status'      => 'paid',
        'mode'                => 'subscription',
        'client_reference_id' => '',
        'customer'            => 'cus_test_' . substr(md5($id), 0, 10),
        'subscription'        => 'sub_test_' . substr(md5($id), 0, 10),
        'metadata'            => ['plan' => 'pro'],
        'created'             => time(),
        'url'                 => 'https://checkout.stripe.test/pay/' . $id,
    ];
}

/** The Stripe event envelope the webhook receives. */
function t_event(string $type, array $object, array $overrides = []): array
{
    return $overrides + [
        'id'      => 'evt_test_' . bin2hex(random_bytes(6)),
        'object'  => 'event',
        'type'    => $type,
        'created' => time(),
        'data'    => ['object' => $object],
    ];
}

/** POST an event to the real webhook endpoint with a genuine signature. */
function t_post_webhook(string $appUrl, array $event, ?string $secret = null, ?array $extraHeaders = null): array
{
    $payload = json_encode($event);
    $secret  = $secret ?? (defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '');

    $headers = $extraHeaders ?? ['Stripe-Signature: ' . stripe_webhook_sign($payload, $secret)];

    return t_http('POST', $appUrl . '/stripe-webhook.php', [
        'raw'     => $payload,
        'headers' => $headers,
    ]);
}

/**
 * Extensions the suite needs, as command-line flags for a child PHP process.
 *
 * Checking extension_loaded() in the current process is not enough: run.php
 * re-executes itself with the extensions enabled, so by the time tests run they
 * are loaded here — while a freshly spawned `php -S` still has them switched
 * off. That produced a server with no database driver, which failed every
 * database-backed assertion for a reason nothing in the output explained.
 * run.php therefore records the flags it re-executed with, and children reuse
 * them.
 */
function t_php_flags(): array
{
    $inherited = getenv('UTILIGO_TEST_PHP_FLAGS');
    if (is_string($inherited) && trim($inherited) !== '') {
        return array_values(array_filter(explode(' ', trim($inherited)), fn($f) => $f !== ''));
    }

    $flags = [];
    foreach (['curl', 'pdo_mysql'] as $ext) {
        if (!extension_loaded($ext)) {
            $flags[] = '-d';
            $flags[] = 'extension=' . $ext;
        }
    }
    return $flags;
}
