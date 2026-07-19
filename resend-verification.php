<?php
/**
 * resend-verification.php — Re-sends the verification email on request.
 * Rate-limited to RESEND_VERIFY_MAX attempts per RESEND_VERIFY_WINDOW minutes per email.
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

    // --- Rate limit: max RESEND_VERIFY_MAX per RESEND_VERIFY_WINDOW minutes per email ---
    $rlKey     = 'resend_verify_' . md5($email);
    $rlWindow  = defined('RESEND_VERIFY_WINDOW') ? RESEND_VERIFY_WINDOW : 60;
    $rlMax     = defined('RESEND_VERIFY_MAX')    ? RESEND_VERIFY_MAX    : 3;
    $rlResetAt = $_SESSION[$rlKey . '_reset'] ?? 0;

    if (time() > $rlResetAt) {
        // Window expired — reset counter
        $_SESSION[$rlKey]           = 0;
        $_SESSION[$rlKey . '_reset'] = time() + ($rlWindow * 60);
    }

    if (($_SESSION[$rlKey] ?? 0) < $rlMax) {
        $_SESSION[$rlKey]++;

        $userdb = get_user_db();
        $stmt   = $userdb->prepare('SELECT id, full_name, email_verified FROM utiligo_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !(int)($user['email_verified'] ?? 0)) {
            $token      = create_email_verification_token((int)$user['id']);
            $verifyLink = rtrim(APP_BASE_URL, '/') . '/verify.php?token=' . $token;
            try { send_verification_email($email, $user['full_name'] ?? '', $verifyLink); } catch (\Throwable $e) {}
        }
    }
    // Always redirect — never reveal whether the email exists or limit was hit
}

header('Location: /login.php?resent=1');
exit;
