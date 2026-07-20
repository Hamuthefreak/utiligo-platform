<?php
/**
 * verify-2fa.php — Second login step (email code OR TOTP authenticator app).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/totp.php';

$pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$pendingUserId) {
    header('Location: /login.php');
    exit;
}

// Determine method: 'totp' or 'email'
$method = $_SESSION['pending_2fa_method'] ?? 'email';

// For TOTP we need the stored secret
$totpSecret = null;
if ($method === 'totp') {
    $userdb = get_user_db();
    $st = $userdb->prepare('SELECT two_factor_secret FROM utiligo_users WHERE id = ? LIMIT 1');
    $st->execute([$pendingUserId]);
    $totpSecret = $st->fetchColumn() ?: null;
    // If secret somehow disappeared, fall back to email
    if (!$totpSecret) {
        $method = 'email';
        $_SESSION['pending_2fa_method'] = 'email';
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['resend'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } else {
        $code = preg_replace('/\s+/', '', trim($_POST['code'] ?? ''));

        $verified = false;
        if ($method === 'totp') {
            $verified = totp_verify($totpSecret, $code);
        } else {
            $verified = verify_2fa_code($pendingUserId, $code);
        }

        if ($verified) {
            unset(
                $_SESSION['pending_2fa_user_id'],
                $_SESSION['pending_2fa_method']
            );
            login_user($pendingUserId);
            header('Location: /portal/index.php');
            exit;
        }
        $error = $method === 'totp'
            ? 'Incorrect code. Open your authenticator app and try the current 6-digit code.'
            : 'Invalid or expired code. Please try again.';
    }
}

// Resend email code (only meaningful for email method)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend']) && $method === 'email') {
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

    <?php if ($method === 'totp'): ?>
      <div class="text-4xl mb-4">📱</div>
      <h1 class="text-2xl font-bold mb-2">Authenticator Code</h1>
      <p class="text-slate-400 text-sm mb-6">Open your authenticator app (Google Authenticator, Authy, etc.) and enter the 6-digit code for Utiligo.</p>
    <?php else: ?>
      <div class="text-4xl mb-4">🔐</div>
      <h1 class="text-2xl font-bold mb-2">Enter Your Code</h1>
      <p class="text-slate-400 text-sm mb-6">We emailed a 6-digit code to your inbox. It expires in <?= TWO_FA_CODE_EXPIRY_MINUTES ?> minutes.</p>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="bg-red-500/10 border border-red-400/30 text-red-400 rounded-lg px-4 py-3 mb-6 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="text" name="code" required maxlength="6" placeholder="000000" autofocus
             inputmode="numeric" autocomplete="one-time-code"
             class="w-full text-center text-2xl tracking-[0.5em] bg-slate-800 border border-slate-600 text-white placeholder-slate-500 rounded-lg px-4 py-3 focus:border-emerald-400 focus:outline-none"
             oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)">
      <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">
        Verify &amp; Log In
      </button>
    </form>

    <?php if ($method === 'email'): ?>
    <form method="POST" class="mt-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="resend" value="1">
      <button type="submit" class="text-sm text-emerald-400 hover:underline">Resend code</button>
    </form>
    <?php else: ?>
    <p class="text-xs text-slate-500 mt-4">
      <i class="fa-solid fa-clock mr-1"></i>Codes refresh every 30 seconds &mdash; enter the current one shown in your app.
    </p>
    <?php endif; ?>

    <div class="mt-6 pt-4 border-t border-white/5">
      <a href="/login.php" class="text-xs text-slate-500 hover:text-white transition">&larr; Back to login</a>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
