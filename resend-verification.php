<?php
/**
 * resend-verification.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $email = trim($_POST['email'] ?? '');
    $userdb = get_user_db();
    $stmt = $userdb->prepare('SELECT id, full_name, email_verified FROM utiligo_users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower($email)]);
    $u = $stmt->fetch();
    if ($u && !$u['email_verified']) {
        $token = create_email_verification_token($u['id']);
        $link = APP_BASE_URL . '/verify-email.php?token=' . $token;
        send_verification_email($email, $u['full_name'], $link);
    }
}
header('Location: /login.php?resent=1');
exit;
