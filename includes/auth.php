<?php
/**
 * includes/auth.php — Session-based authentication helpers.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
    if (current_user() === null) {
        logout_user();
        header('Location: /login.php?expired=1');
        exit;
    }
}

function current_user(): ?array
{
    if (!is_logged_in()) return null;
    static $cached = null;
    if ($cached !== null) return $cached;

    $pdo = get_user_db();
    $stmt = $pdo->prepare('SELECT * FROM utiligo_users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    // Clear any lockout state on successful login
    unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function register_user(string $email, string $password, string $fullName): array
{
    $pdo = get_user_db();
    $email = strtolower(trim($email));

    $stmt = $pdo->prepare('SELECT id FROM utiligo_users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'An account with this email already exists.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO utiligo_users (email, password_hash, full_name, plan, subscription_status) VALUES (?, ?, ?, "free", "none")'
    );
    $stmt->execute([$email, $hash, $fullName]);
    $userId = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/../db.php';
    $platformDb = get_platform_db();
    $platformDb->prepare('INSERT INTO utiligo_whitelabel (user_id) VALUES (?)')->execute([$userId]);

    return ['success' => true, 'user_id' => $userId];
}

// ============================================================
// LOGIN WITH BRUTE-FORCE LOCKOUT
// ============================================================

function attempt_login(string $email, string $password): array
{
    $pdo   = get_user_db();
    $email = strtolower(trim($email));

    // --- Check session-based lockout ---
    $lockKey = 'login_locked_until_' . md5($email);
    $attKey  = 'login_attempts_' . md5($email);
    if (!empty($_SESSION[$lockKey]) && time() < $_SESSION[$lockKey]) {
        $wait = ceil(($_SESSION[$lockKey] - time()) / 60);
        return ['success' => false, 'error' => "Too many failed attempts. Try again in {$wait} minute(s).", 'locked' => true];
    }

    $stmt = $pdo->prepare('SELECT * FROM utiligo_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Increment attempt counter
        $_SESSION[$attKey] = ($_SESSION[$attKey] ?? 0) + 1;
        if ($_SESSION[$attKey] >= LOGIN_MAX_ATTEMPTS) {
            $_SESSION[$lockKey] = time() + (LOGIN_LOCKOUT_MINUTES * 60);
            unset($_SESSION[$attKey]);
            return ['success' => false, 'error' => 'Too many failed attempts. Account locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes.', 'locked' => true];
        }
        $remaining = LOGIN_MAX_ATTEMPTS - $_SESSION[$attKey];
        return ['success' => false, 'error' => "Invalid email or password. {$remaining} attempt(s) remaining."];
    }

    if ($user['subscription_status'] === 'banned') {
        return ['success' => false, 'error' => 'This account has been suspended.'];
    }

    // Success — clear counters
    unset($_SESSION[$attKey], $_SESSION[$lockKey]);
    return ['success' => true, 'user_id' => $user['id']];
}

// ============================================================
// EMAIL VERIFICATION
// ============================================================

function create_email_verification_token(int $userId): string
{
    $pdo = get_user_db();
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare('INSERT INTO utiligo_email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, $token, $expires]);
    return $token;
}

function verify_email_token(string $token): array
{
    $pdo = get_user_db();
    $stmt = $pdo->prepare('SELECT * FROM utiligo_email_verifications WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'error' => 'This verification link is invalid or has expired.'];
    }
    $pdo->prepare('UPDATE utiligo_users SET email_verified = 1 WHERE id = ?')->execute([$row['user_id']]);
    $pdo->prepare('UPDATE utiligo_email_verifications SET used = 1 WHERE id = ?')->execute([$row['id']]);
    return ['success' => true, 'user_id' => $row['user_id']];
}

// ============================================================
// PASSWORD RESET
// ============================================================

function create_password_reset_token(string $email): ?array
{
    $pdo = get_user_db();
    $stmt = $pdo->prepare('SELECT id, full_name FROM utiligo_users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();
    if (!$user) return null;

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes'));
    $pdo->prepare('INSERT INTO utiligo_password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$user['id'], $token, $expires]);
    return ['token' => $token, 'user_id' => $user['id'], 'full_name' => $user['full_name']];
}

function validate_password_reset_token(string $token): ?array
{
    $pdo = get_user_db();
    $stmt = $pdo->prepare('SELECT * FROM utiligo_password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function complete_password_reset(string $token, string $newPassword): bool
{
    $row = validate_password_reset_token($token);
    if (!$row) return false;
    $pdo = get_user_db();
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE utiligo_users SET password_hash = ? WHERE id = ?')->execute([$hash, $row['user_id']]);
    $pdo->prepare('UPDATE utiligo_password_resets SET used = 1 WHERE id = ?')->execute([$row['id']]);
    return true;
}

// ============================================================
// TWO-FACTOR AUTHENTICATION (email code)
// ============================================================

function create_2fa_code(int $userId): string
{
    $pdo = get_user_db();
    $code = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', strtotime('+' . TWO_FA_CODE_EXPIRY_MINUTES . ' minutes'));
    $pdo->prepare('INSERT INTO utiligo_2fa_codes (user_id, code, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, $code, $expires]);
    return $code;
}

function verify_2fa_code(int $userId, string $code): bool
{
    $pdo = get_user_db();
    $stmt = $pdo->prepare('SELECT * FROM utiligo_2fa_codes WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId, $code]);
    $row = $stmt->fetch();
    if (!$row) return false;
    $pdo->prepare('UPDATE utiligo_2fa_codes SET used = 1 WHERE id = ?')->execute([$row['id']]);
    return true;
}

// Handle logout action when this file is hit directly
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout_user();
    header('Location: /');
    exit;
}
