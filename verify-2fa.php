<?php
/**
 * verify-2fa.php — Second login step.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$pendingUserId) {
    header('Location: /login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['resend'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if (verify_2fa_code($pendingUserId, $code)) {
            unset($_SESSION['pending_2fa_user_id']);
            login_user($pendingUserId);
            header('Location: /portal/index.php');
            exit;
        }
        $error = 'Invalid or expired code. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    $userdb = get_user_db();
    $stmt = $userdb->prepare('SELECT email, full_name FROM utiligo_users WHERE id = ?');
    $stmt->execute([$pendingUserId]);
    $u = $stmt->fetch();
    if ($u) {
        $newCode = create_2fa_code($pendingUserId);
        send_2fa_code_email($u['email'], $u['full_name'], $newCode);
    }
}

$pageTitle = 'Verify Login — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-md mx-auto px-6 py-20">
  <div class="glass rounded-2xl p-8 text-center">
    <div class="text-4xl mb-4">🔐</div>
    <h1 class="text-2xl font-bold mb-2">Enter Your Code</h1>
    <p class="text-slate-400 text-sm mb-6">We emailed a 6-digit code to your inbox. It expires in <?= TWO_FA_CODE_EXPIRY_MINUTES ?> minutes.</p>

    <?php if ($error): ?>
      <div class="bg-red-500/10 border border-red-400/30 text-red-400 rounded-lg px-4 py-3 mb-6 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="text" name="code" required maxlength="6" placeholder="000000" autofocus
        class="w-full text-center text-2xl tracking-[0.5em] bg-slate-800 border border-slate-600 text-white placeholder-slate-500 rounded-lg px-4 py-3 focus:border-emerald-400 focus:outline-none">
      <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">
        Verify & Log In
      </button>
    </form>

    <form method="POST" class="mt-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="resend" value="1">
      <button type="submit" class="text-sm text-emerald-400 hover:underline">Resend code</button>
    </form>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
