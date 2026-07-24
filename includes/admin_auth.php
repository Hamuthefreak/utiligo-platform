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
    echo '<!DOCTYPE html><html><head><title>Access Denied</title>'
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
    if (empty($_SESSION['admin_session_ip'])) {
        $_SESSION['admin_session_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        session_regenerate_id(true);
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
    // Cast to int — MariaDB rejects string-bound LIMIT/OFFSET
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
    $_SESSION['admin_csrf'][$form] = ['token' => $token, 'ts' => time()];
    return $token;
}

function admin_csrf_verify(string $form, ?string $token): bool
{
    $stored = $_SESSION['admin_csrf'][$form] ?? null;
    if (!$stored || !$token) return false;
    if (time() - $stored['ts'] > 3600) return false;
    $ok = hash_equals($stored['token'], $token);
    unset($_SESSION['admin_csrf'][$form]);
    return $ok;
}
