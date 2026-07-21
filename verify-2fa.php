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

$error   = '';
$resent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['resend'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh and try again.';
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
            ? 'Incorrect code. Open your authenticator app and enter the current 6-digit code.'
            : 'Invalid or expired code. Please check your inbox and try again.';
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
        $resent = true;
    }
}

$pageTitle = 'Verify Login — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="min-h-screen flex items-center justify-center px-4 py-12">
  <div class="w-full max-w-sm">

    <!-- Logo / brand mark -->
    <div class="flex justify-center mb-8">
      <a href="/" class="text-emerald-400 font-bold text-2xl tracking-tight">Utiligo</a>
    </div>

    <!-- Card -->
    <div class="glass rounded-2xl p-6 sm:p-8">

      <!-- Icon + heading -->
      <div class="flex flex-col items-center text-center mb-6">
        <?php if ($method === 'totp'): ?>
          <!-- Shield / authenticator icon -->
          <div class="w-14 h-14 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
            </svg>
          </div>
          <h1 class="text-xl sm:text-2xl font-bold text-white mb-1">Authenticator Code</h1>
          <p class="text-slate-400 text-sm leading-relaxed">
            Open your authenticator app (Google Authenticator, Authy, etc.) and enter the 6-digit code shown for Utiligo.
          </p>
        <?php else: ?>
          <!-- Email / envelope icon -->
          <div class="w-14 h-14 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
            </svg>
          </div>
          <h1 class="text-xl sm:text-2xl font-bold text-white mb-1">Check Your Email</h1>
          <p class="text-slate-400 text-sm leading-relaxed">
            We sent a 6-digit verification code to your inbox. It expires in
            <span class="text-white font-medium"><?= TWO_FA_CODE_EXPIRY_MINUTES ?> minutes</span>.
          </p>
        <?php endif; ?>
      </div>

      <!-- Error alert -->
      <?php if ($error): ?>
        <div class="flex items-start gap-3 bg-red-500/10 border border-red-400/30 text-red-400 rounded-xl px-4 py-3 mb-5 text-sm" role="alert">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
          </svg>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <!-- Resent confirmation -->
      <?php if ($resent): ?>
        <div class="flex items-start gap-3 bg-emerald-500/10 border border-emerald-400/30 text-emerald-400 rounded-xl px-4 py-3 mb-5 text-sm" role="status">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span>A new code has been sent to your inbox.</span>
        </div>
      <?php endif; ?>

      <!-- Code input form -->
      <form method="POST" class="space-y-4" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <!-- OTP digit boxes -->
        <div>
          <label for="code" class="block text-xs text-slate-400 font-medium mb-2 text-center uppercase tracking-wider">Verification Code</label>
          <input
            type="text"
            id="code"
            name="code"
            required
            maxlength="6"
            placeholder="· · · · · ·"
            autofocus
            inputmode="numeric"
            autocomplete="one-time-code"
            class="w-full text-center text-3xl font-bold tracking-[0.6em] bg-slate-800/70 border border-slate-600 text-white placeholder-slate-600 rounded-xl px-4 py-4 focus:border-emerald-400 focus:ring-2 focus:ring-emerald-400/20 focus:outline-none transition-all"
            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)"
          >
        </div>

        <button
          type="submit"
          class="w-full bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 py-3.5 rounded-xl font-semibold text-base transition-all duration-150 flex items-center justify-center gap-2"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
          </svg>
          Verify &amp; Log In
        </button>
      </form>

      <!-- Resend / TOTP hint -->
      <div class="mt-5 text-center">
        <?php if ($method === 'email'): ?>
          <p class="text-sm text-slate-500">
            Didn't receive it?
            <form method="POST" class="inline">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="resend" value="1">
              <button type="submit" class="text-emerald-400 hover:text-emerald-300 underline underline-offset-2 transition-colors">Resend code</button>
            </form>
          </p>
        <?php else: ?>
          <p class="text-xs text-slate-500 flex items-center justify-center gap-1.5">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Codes refresh every 30 seconds — enter the one currently shown in your app.
          </p>
        <?php endif; ?>
      </div>

      <!-- Back to login -->
      <div class="mt-6 pt-5 border-t border-white/5 text-center">
        <a href="/login.php" class="text-xs text-slate-500 hover:text-white transition-colors inline-flex items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
          </svg>
          Back to login
        </a>
      </div>

    </div><!-- /card -->

    <!-- Security note -->
    <p class="text-center text-xs text-slate-600 mt-6">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
      </svg>
      Your account is protected by two-factor authentication.
    </p>

  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
