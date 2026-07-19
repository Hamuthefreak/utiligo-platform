<?php
/**
 * verify.php — Email verification landing page.
 * User clicks the link from their verification email and lands here.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$token  = trim($_GET['token'] ?? '');
$result = ['success' => false, 'error' => 'No verification token provided.'];

if ($token !== '') {
    $result = verify_email_token($token);
}

if ($result['success']) {
    // Log the user in immediately after verification
    $userId = $result['user_id'];
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    // If they registered for a paid plan, redirect to billing
    $pending_plan = $_SESSION['pending_plan'] ?? 'free';
    unset($_SESSION['pending_plan'], $_SESSION['pending_email']);

    if ($pending_plan === 'pro') {
        header('Location: /portal/billing.php?upgrade=1&plan=pro&verified=1'); exit;
    } elseif ($pending_plan === 'entrepreneur') {
        header('Location: /portal/billing.php?upgrade=1&plan=entrepreneur&verified=1'); exit;
    } else {
        header('Location: /portal/index.php?welcome=1'); exit;
    }
}

// Show error page
$pageTitle = 'Verify Email — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>
<section class="min-h-screen flex items-center justify-center px-4 py-16">
  <div class="w-full max-w-md text-center">
    <div class="w-20 h-20 rounded-full bg-red-500/10 border border-red-500/20 flex items-center justify-center mx-auto mb-6">
      <i class="fa-solid fa-triangle-exclamation text-red-400 text-3xl"></i>
    </div>
    <h1 class="text-2xl font-bold mb-2">Verification Failed</h1>
    <p class="text-slate-400 text-sm mb-6"><?= htmlspecialchars($result['error']) ?></p>
    <p class="text-xs text-slate-500">
      Need a new link?
      <a href="/resend-verification.php" class="text-white underline">Resend verification email</a>
    </p>
    <a href="/register.php" class="mt-6 inline-block text-xs text-slate-500 hover:text-white">Back to register</a>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
