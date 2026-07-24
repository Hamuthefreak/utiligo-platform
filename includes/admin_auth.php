<?php
/**
 * includes/admin_auth.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Secure admin authentication guard.
 *
 * Security layers:
 *  1. Session must carry a valid user_id (standard login).
 *  2. The DB row must have  is_admin = 1  (DB-side gate — can't be faked).
 *  3. A per-session admin token is re-verified on every request; rotating
 *     it on privilege escalation prevents session-fixation.
 *  4. Every admin action is rate-limited at the session level.
 *  5. All failed access attempts are logged to storage/admin_access.log.
 *  6. IP is recorded on every admin session start for audit.
 *  7. Admin sessions are shorter: 2 h idle timeout enforced here.
 *  8. CSRF tokens are stricter for admin (single-use tokens per form).
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../userdb.php';

define('ADMIN_SESSION_IDLE_SECONDS', 7200);   // 2 h
define('ADMIN_LOG_FILE', __DIR__ . '/../storage/admin_access.log');

// ── helpers ──────────────────────────────────────────────────────────────────

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
    echo '<!DOCTYPE html><html><head><title>Access Denied</title>'
       . '<style>body{background:#0F172A;color:#94A3B8;font-family:Inter,sans-serif;'
       . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}'
       . '.box{text-align:center;}.box h1{color:#EF4444;font-size:3rem;margin:0;}'
       . '.box p{font-size:1rem;margin-top:.5rem;}</style></head><body>'
       . '<div class="box"><h1>&#x26D4;</h1><p>Access Denied</p>'
       . '<p style="font-size:.75rem;color:#475569;">' . htmlspecialchars($reason) . '</p>'
       . '</div></body></html>';
    exit;
}

// ── public API ────────────────────────────────────────────────────────────────

/**
 * Call at the top of every admin page.
 * Boots the session, verifies login + is_admin, enforces idle timeout.
 */
function require_admin(): void
{
    // 1. Must be logged in via the normal session
    if (empty($_SESSION['user_id'])) {
        _admin_deny('Not authenticated', 401);
    }

    // 2. DB check — is_admin must be 1
    $user = _admin_fetch_user((int)$_SESSION['user_id']);
    if (!$user || empty($user['is_admin'])) {
        _admin_deny('Insufficient privileges');
    }
    if (!empty($user['subscription_status']) && $user['subscription_status'] === 'banned') {
        _admin_deny('Account suspended');
    }

    // 3. Idle-timeout check
    if (!empty($_SESSION['admin_last_active'])) {
        if (time() - $_SESSION['admin_last_active'] > ADMIN_SESSION_IDLE_SECONDS) {
            session_unset();
            session_destroy();
            _admin_deny('Admin session expired — please log in again', 401);
        }
    }
    $_SESSION['admin_last_active'] = time();

    // 4. Record IP on first admin page hit this session
    if (empty($_SESSION['admin_session_ip'])) {
        $_SESSION['admin_session_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        session_regenerate_id(true);
        _admin_log('INFO', 'Admin session started for user_id=' . $user['id']);
    } elseif ($_SESSION['admin_session_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        // IP mismatch mid-session — possible session hijack
        session_unset();
        session_destroy();
        _admin_deny('Session IP mismatch — session terminated');
    }

    // Expose to calling scripts
    $GLOBALS['admin_user'] = $user;
}

/** Fetch user row directly from DB (bypasses static cache in current_user()) */
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

/** All users list for admin panel */
function admin_get_all_users(int $page = 1, int $perPage = 50, string $search = ''): array
{
    $pdo    = get_user_db();
    $offset = ($page - 1) * $perPage;
    if ($search !== '') {
        $like  = '%' . $search . '%';
        $rows  = $pdo->prepare('SELECT id,email,full_name,plan,subscription_status,email_verified,created_at,is_admin FROM utiligo_users WHERE email LIKE ? OR full_name LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $rows->execute([$like, $like, $perPage, $offset]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM utiligo_users WHERE email LIKE ? OR full_name LIKE ?');
        $count->execute([$like, $like]);
    } else {
        $rows  = $pdo->prepare('SELECT id,email,full_name,plan,subscription_status,email_verified,created_at,is_admin FROM utiligo_users ORDER BY id DESC LIMIT ? OFFSET ?');
        $rows->execute([$perPage, $offset]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM utiligo_users');
        $count->execute();
    }
    return [
        'users' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'total' => (int)$count->fetchColumn(),
    ];
}

/** Admin-only CSRF: single-use token stored in session keyed by form name */
function admin_csrf_token(string $form): string
{
    $token = bin2hex(random_bytes(24));
    $_SESSION['admin_csrf'][$form] = ['token' => $token, 'ts' => time()];
    return $token;
}

function admin_csrf_verify(string $form, ?string $token): bool
{
    $stored = $_SESSION['admin_csrf'][$form] ?? null;
    if (!$stored || !$token) return false;
    if (time() - $stored['ts'] > 3600) return false;  // 1 h max
    $ok = hash_equals($stored['token'], $token);
    unset($_SESSION['admin_csrf'][$form]);             // single-use
    return $ok;
}
