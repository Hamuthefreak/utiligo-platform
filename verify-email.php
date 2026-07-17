<?php
/**
 * verify-email.php — Confirms a user's email via the token emailed at signup.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$token = $_GET['token'] ?? '';
$result = $token ? verify_email_token($token) : ['success' => false, 'error' => 'Missing verification token.'];
$wantsPro = false;

if ($result['success']) {
    login_user($result['user_id']);
    $wantsPro = $_SESSION['pending_wants_pro'] ?? false;
    unset($_SESSION['pending_verification_user_id'], $_SESSION['pending_wants_pro']);
}

$pageTitle = 'Verify Email — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-md mx-auto px-6 py-20 text-center">
  <div class="glass rounded-2xl p-8">
    <?php if ($result['success']): ?>
      <div class="text-4xl mb-4">✅</div>
      <h1 class="text-2xl font-bold mb-2">Email Verified!</h1>
      <p class="text-slate-400 text-sm mb-6">Your account is now active.</p>
      <a href="<?= $wantsPro ? '/portal/billing.php?upgrade=1' : '/portal/index.php' ?>"
        class="inline-block bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-8 py-3 rounded-full font-semibold">
        <?= $wantsPro ? 'Continue to Upgrade' : 'Go to Dashboard' ?>
      </a>
    <?php else: ?>
      <div class="text-4xl mb-4">⚠️</div>
      <h1 class="text-2xl font-bold mb-2">Verification Failed</h1>
      <p class="text-slate-400 text-sm mb-6"><?= htmlspecialchars($result['error']) ?></p>
      <a href="/login.php" class="inline-block bg-white/10 hover:bg-white/20 px-8 py-3 rounded-full font-semibold">Back to Login</a>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
