<?php
/**
 * admin-bootstrap.php
 * ONE-TIME script to grant is_admin=1 to the owner account.
 *
 * HOW TO USE:
 *   1. Visit https://utiligo.ca/admin-bootstrap.php in your browser
 *      while logged in as the account you want to make admin.
 *   2. Done — you will be redirected to /admin/
 *
 * SECURITY:
 *   - Only works if the logged-in user's email matches the
 *     UTILIGO_ADMIN_EMAIL environment variable (set in your host panel).
 *   - If UTILIGO_ADMIN_EMAIL is not set, it falls back to the ADMIN_EMAIL
 *     constant from config.php.
 *   - The script deletes itself after a successful grant so it can
 *     never be run again.
 *   - If the file cannot delete itself, it writes a USED flag to
 *     storage/bootstrap_used.lock and refuses to run again.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb.php';
require_once __DIR__ . '/includes/auth.php';

$lock_file = __DIR__ . '/storage/bootstrap_used.lock';

if (file_exists($lock_file)) {
    http_response_code(410);
    die('<h1 style="font-family:sans-serif;color:#ef4444">Already used</h1><p>This bootstrap script has already been run and is permanently disabled.</p>');
}

// Must be logged in
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode('/admin-bootstrap.php'));
    exit;
}

// Identify the authorised email
$allowed_email = getenv('UTILIGO_ADMIN_EMAIL') ?: (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '');
if (!$allowed_email) {
    http_response_code(500);
    die('<h1 style="font-family:sans-serif;color:#ef4444">Not configured</h1><p>Set the <code>UTILIGO_ADMIN_EMAIL</code> environment variable to your account email in your hosting control panel, then visit this URL again.</p>');
}

try {
    $pdo  = get_user_db();
    $stmt = $pdo->prepare('SELECT id, email, is_admin FROM utiligo_users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    die('<h1 style="font-family:sans-serif">DB error</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}

if (!$user) {
    http_response_code(404);
    die('<h1 style="font-family:sans-serif">User not found</h1>');
}

if (strtolower(trim($user['email'])) !== strtolower(trim($allowed_email))) {
    http_response_code(403);
    die('<h1 style="font-family:sans-serif;color:#ef4444">Wrong account</h1>'
      . '<p>You are logged in as <strong>' . htmlspecialchars($user['email']) . '</strong>.<br>'
      . 'The authorised admin email is <strong>' . htmlspecialchars($allowed_email) . '</strong>.<br>'
      . 'Log in with the correct account and try again.</p>');
}

if (!empty($user['is_admin'])) {
    // Already admin — just lock and redirect
    file_put_contents($lock_file, date('c') . ' already-admin user_id=' . $user['id']);
    @unlink(__FILE__);
    header('Location: /admin/');
    exit;
}

// Grant admin
try {
    $upd = $pdo->prepare('UPDATE utiligo_users SET is_admin = 1 WHERE id = ?');
    $upd->execute([(int)$user['id']]);
} catch (Throwable $e) {
    http_response_code(500);
    die('<h1 style="font-family:sans-serif">Update failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}

// Lock + self-destruct
file_put_contents($lock_file, date('c') . ' granted user_id=' . $user['id'] . ' email=' . $user['email']);
@unlink(__FILE__);

header('Location: /admin/');
exit;
