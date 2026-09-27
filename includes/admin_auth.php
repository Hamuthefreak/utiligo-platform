<?php
/**
 * includes/admin_auth.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../userdb.php';

define('ADMIN_SESSION_IDLE_SECONDS', 7200);
define('ADMIN_LOG_FILE', __DIR__ . '/../storage/admin_access.log');

function _admin_log(string $level, string $msg): void
{
    $line = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] '
          . '[ip:' . ($_SERVER['REMOTE_ADDR'] ?? '-') . '] '
          . '[uid:' . ($_SESSION['user_id'] ?? '-') . '] '
          . $msg . PHP_EOL;
    @file_put_contents(ADMIN_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function _admin_deny(string $reason, int $code = 403): never
{
    _admin_log('DENY', $reason);
    http_response_code($code);
    if (!headers_sent()) header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg"><title>Access Denied</title>'
       . '<style>body{background:#0F172A;color:#94A3B8;font-family:Inter,sans-serif;'
       . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}'
       . '.box{text-align:center;}.box h1{color:#EF4444;font-size:2rem;margin:0 0 .5rem;}'
       . '.box p{font-size:1rem;}</style></head><body>'
       . '<div class="box"><h1>Access Denied</h1>'
       . '<p style="font-size:.75rem;color:#475569;">' . htmlspecialchars($reason) . '</p>'
       . '</div></body></html>';
    exit;
}

function require_admin(): void
{
    if (empty($_SESSION['user_id'])) {
        _admin_deny('Not authenticated', 401);
    }
    $user = _admin_fetch_user((int)$_SESSION['user_id']);
    if (!$user || empty($user['is_admin'])) {
        _admin_deny('Insufficient privileges');
    }
    if (!empty($user['subscription_status']) && $user['subscription_status'] === 'banned') {
        _admin_deny('Account suspended');
    }
    if (!empty($_SESSION['admin_last_active'])) {
        if (time() - $_SESSION['admin_last_active'] > ADMIN_SESSION_IDLE_SECONDS) {
            session_unset();
            session_destroy();
            _admin_deny('Admin session expired — please log in again', 401);
        }
    }
    $_SESSION['admin_last_active'] = time();

    // IP-bind the session on first admin access, but do NOT call
    // session_regenerate_id() here — regenerating on every page load
    // can orphan the session file on shared hosts (InfinityFree),
    // silently wiping $_SESSION['admin_csrf'] and killing every POST.
    // Session ID was already regenerated at login time.
    if (empty($_SESSION['admin_session_ip'])) {
        $_SESSION['admin_session_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        _admin_log('INFO', 'Admin session started for user_id=' . $user['id']);
    } elseif ($_SESSION['admin_session_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        session_unset();
        session_destroy();
        _admin_deny('Session IP mismatch — session terminated');
    }
    $GLOBALS['admin_user'] = $user;
}

function _admin_fetch_user(int $id): ?array
{
    try {
        $pdo  = get_user_db();
        $stmt = $pdo->prepare('SELECT * FROM utiligo_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function admin_get_all_users(int $page = 1, int $perPage = 25, string $search = ''): array
{
    $pdo    = get_user_db();
    $limit  = (int)$perPage;
    $offset = (int)(($page - 1) * $perPage);

    if ($search !== '') {
        $like  = '%' . $search . '%';
        $rows  = $pdo->prepare(
            'SELECT id,email,full_name,plan,subscription_status,email_verified,created_at,is_admin
             FROM utiligo_users
             WHERE email LIKE ? OR full_name LIKE ?
             ORDER BY id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $rows->execute([$like, $like]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM utiligo_users WHERE email LIKE ? OR full_name LIKE ?');
        $count->execute([$like, $like]);
    } else {
        $rows  = $pdo->prepare(
            'SELECT id,email,full_name,plan,subscription_status,email_verified,created_at,is_admin
             FROM utiligo_users
             ORDER BY id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $rows->execute();
        $count = $pdo->prepare('SELECT COUNT(*) FROM utiligo_users');
        $count->execute();
    }
    return [
        'users' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'total' => (int)$count->fetchColumn(),
    ];
}

function admin_csrf_token(string $form): string
{
    $token = bin2hex(random_bytes(24));
    $_SESSION['admin_csrf'][$form] = ['tokens' => admin_csrf_ring($form, $token)];
    return $token;
}

/**
 * The form's live tokens, newest first, with $newToken added and old ones dropped.
 *
 * Six is a number chosen for tabs, not for servers: a settings page open in three tabs,
 * each reloaded twice while its owner looks for a key, stays inside it. Tokens are still
 * random, session-bound and consumed on use, so the only thing a longer history costs is
 * a few more bytes of session.
 *
 * The single-token shape is read as well as written, because a request on another
 * worker may still be holding the session as it was before this change.
 *
 * @return list<array{token: string, ts: int}>
 */
function admin_csrf_ring(string $form, ?string $newToken = null): array
{
    $stored = $_SESSION['admin_csrf'][$form] ?? null;
    $ring   = [];

    if (is_array($stored)) {
        if (isset($stored['tokens']) && is_array($stored['tokens'])) {
            $ring = $stored['tokens'];
        } elseif (isset($stored['token'])) {
            $ring[] = ['token' => (string)$stored['token'], 'ts' => (int)($stored['ts'] ?? 0)];
        }
    }

    if ($newToken !== null) {
        array_unshift($ring, ['token' => $newToken, 'ts' => time()]);
    }

    return array_slice(array_values($ring), 0, 6);
}

/**
 * Verify a token, and decide how long a form may sit on screen before it goes stale.
 *
 * $ttlSeconds is a parameter because one hour is the wrong answer for the settings page:
 * the values on it are copied out of two other dashboards, so the operator leaves the
 * form open while they go and find a key. The refusal that follows is correct and reads
 * as "the save did nothing" — see admin/settings.php, which asks for six hours. Every
 * other caller keeps the default, and the token is still bound to the session, to one
 * form, and consumed by the save that uses it.
 *
 * A FORM IS KEPT OPEN IN MORE THAN ONE TAB, SO ONE SLOT IS NOT ENOUGH.
 *
 * admin_csrf_token() used to overwrite the form's one token, which made every render a
 * race between tabs: the tab the operator did not reload last held a token that no longer
 * verified, and its Save was refused — a refusal that arrives only after the work of
 * copying two keys out of two dashboards, and that reads as "the save did nothing". The
 * session therefore keeps the last few tokens per form: any unexpired one verifies,
 * exactly one is consumed, and the rest age out.
 */
function admin_csrf_verify(string $form, ?string $token, int $ttlSeconds = 3600): bool
{
    if (!$token) return false;

    $now  = time();
    $live = [];
    foreach (admin_csrf_ring($form) as $entry) {
        if (!isset($entry['token'], $entry['ts'])) continue;
        // Expired tokens are dropped, not kept: a token that has aged out must not be
        // handed a fresh clock by a later request.
        if ($now - (int)$entry['ts'] > $ttlSeconds) continue;
        $live[] = ['token' => (string)$entry['token'], 'ts' => (int)$entry['ts']];
    }

    $matched = null;
    foreach ($live as $i => $entry) {
        if (hash_equals($entry['token'], $token)) {
            $matched = $i;
            break;
        }
    }

    if ($matched === null) {
        // Nothing matched: keep the unexpired tokens, so a stale tab, a double-submit or
        // a borrowed token does not burn the one the current tab is holding.
        $_SESSION['admin_csrf'][$form] = ['tokens' => $live];
        return false;
    }

    // Consume exactly the token that was used: its siblings — the ones issued to this
    // form's other tabs — stay valid, and a reused token is refused even while they do.
    unset($live[$matched]);
    $_SESSION['admin_csrf'][$form] = ['tokens' => array_values($live)];
    return true;
}
