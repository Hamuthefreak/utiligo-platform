<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $email = trim($_POST['email'] ?? '');
    $result = create_password_reset_token($email);
    if ($result) {
        $resetLink = APP_BASE_URL . '/reset-password.php?token=' . $result['token'];
        send_password_reset_email($email, $result['full_name'], $resetLink);
    }
    $submitted = true;
}

$pageTitle = 'Forgot Password — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-md mx-auto px-6 py-20">
  <div class="glass rounded-2xl p-8">
    <?php if ($submitted): ?>
      <div class="text-center">
        <div class="text-4xl mb-4">📬</div>
        <h1 class="text-2xl font-bold mb-2">Check Your Email</h1>
        <p class="text-slate-400 text-sm">If an account exists with that email, we've sent a password reset link. It expires in <?= PASSWORD_RESET_EXPIRY_MINUTES ?> minutes.</p>
      </div>
    <?php else: ?>
      <h1 class="text-2xl font-bold mb-2 text-center">Forgot Password</h1>
      <p class="text-slate-400 text-sm text-center mb-6">Enter your email and we'll send you a reset link.</p>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="email" name="email" required autofocus placeholder="you@example.com"
          class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">
          Send Reset Link
        </button>
      </form>
      <p class="text-center text-sm text-slate-400 mt-6">
        <a href="/login.php" class="text-emerald-400 hover:underline">Back to Login</a>
      </p>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
