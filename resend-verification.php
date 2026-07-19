<?php
/**
 * resend-verification.php — Re-sends the verification email on request.
 * Can be reached by POST (from login or register page) or GET (with ?email=).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: /login.php'); exit;
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
} else {
    $email = strtolower(trim($_GET['email'] ?? ''));
}

if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $userdb = get_user_db();
    $stmt   = $userdb->prepare('SELECT id, full_name, email_verified FROM utiligo_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Only resend if account exists and is not yet verified
    if ($user && !(int)($user['email_verified'] ?? 0)) {
        $token      = create_email_verification_token((int)$user['id']);
        $verifyLink = rtrim(APP_BASE_URL, '/') . '/verify.php?token=' . $token;
        try { send_verification_email($email, $user['full_name'] ?? '', $verifyLink); } catch (\Throwable $e) {}
    }
}

// Always redirect to login with a success flash (don't reveal whether email exists)
header('Location: /login.php?resent=1');
exit;
