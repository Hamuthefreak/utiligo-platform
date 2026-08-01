<?php
/**
 * admin-bootstrap.php — ONE-TIME admin grant script.
 * Deletes itself after running. Cannot be reused.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb.php';
require_once __DIR__ . '/includes/auth.php';

$lock_file     = __DIR__ . '/storage/bootstrap_used.lock';
$allowed_email = 'harman.s.lakhian@gmail.com';

if (file_exists($lock_file)) {
    http_response_code(410);
    die('<h1 style="font-family:sans-serif;color:#ef4444">Already used</h1><p>This bootstrap script has already been run and is permanently disabled.</p>');
}

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode('/admin-bootstrap.php'));
    exit;
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
      . 'Log in as <strong>' . htmlspecialchars($allowed_email) . '</strong> and try again.</p>');
}

if (!empty($user['is_admin'])) {
    file_put_contents($lock_file, date('c') . ' already-admin user_id=' . $user['id']);
    @unlink(__FILE__);
    header('Location: /admin/');
    exit;
}

try {
    $pdo->prepare('UPDATE utiligo_users SET is_admin = 1 WHERE id = ?')->execute([(int)$user['id']]);
} catch (Throwable $e) {
    http_response_code(500);
    die('<h1 style="font-family:sans-serif">Update failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}

file_put_contents($lock_file, date('c') . ' granted user_id=' . $user['id'] . ' email=' . $user['email']);
@unlink(__FILE__);

header('Location: /admin/');
exit;
