<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$tokenRow = $token ? validate_password_reset_token($token) : null;
$error = '';
$success = false;

if (!$tokenRow) {
    $error = 'This reset link is invalid or has expired.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            complete_password_reset($token, $password);
            $success = true;
        }
    }
}

$pageTitle = 'Reset Password — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-md mx-auto px-6 py-20">
  <div class="glass rounded-2xl p-8">
    <?php if ($success): ?>
      <div class="text-center">
        <div class="text-4xl mb-4">✅</div>
        <h1 class="text-2xl font-bold mb-2">Password Updated</h1>
        <p class="text-slate-400 text-sm mb-6">You can now log in with your new password.</p>
        <a href="/login.php" class="inline-block bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-8 py-3 rounded-full font-semibold">Log In</a>
      </div>
    <?php elseif (!$tokenRow): ?>
      <div class="text-center">
        <div class="text-4xl mb-4">⚠️</div>
        <h1 class="text-2xl font-bold mb-2">Link Expired</h1>
        <p class="text-slate-400 text-sm mb-6"><?= htmlspecialchars($error) ?></p>
        <a href="/forgot-password.php" class="inline-block bg-white/10 hover:bg-white/20 px-8 py-3 rounded-full font-semibold">Request New Link</a>
      </div>
    <?php else: ?>
      <h1 class="text-2xl font-bold mb-2 text-center">Set New Password</h1>
      <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-400/30 text-red-400 rounded-lg px-4 py-3 mb-6 text-sm"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div>
          <label class="block text-sm mb-2">New Password</label>
          <input type="password" name="password" required minlength="8"
            class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        </div>
        <div>
          <label class="block text-sm mb-2">Confirm Password</label>
          <input type="password" name="password_confirm" required minlength="8"
            class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        </div>
        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">
          Update Password
        </button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
