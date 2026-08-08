<?php
/**
 * login.php — User login page.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

if (is_logged_in()) {
    header('Location: /portal/index.php');
    exit;
}

$error = '';
$unverifiedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } else {
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $rememberMe = !empty($_POST['remember_me']);
        $result     = attempt_login($email, $password);

        if ($result['success']) {
            $userdb = get_user_db();

            try {
                $stmt = $userdb->prepare('SELECT id, full_name, email, email_verified, two_factor_enabled, two_factor_secret FROM utiligo_users WHERE email = ? LIMIT 1');
                $stmt->execute([strtolower(trim($email))]);
            } catch (\PDOException $e) {
                try {
                    $stmt = $userdb->prepare('SELECT id, full_name, email, email_verified, two_factor_enabled FROM utiligo_users WHERE email = ? LIMIT 1');
                    $stmt->execute([strtolower(trim($email))]);
                } catch (\PDOException $e2) {
                    $stmt = $userdb->prepare('SELECT id, full_name, email, email_verified FROM utiligo_users WHERE email = ? LIMIT 1');
                    $stmt->execute([strtolower(trim($email))]);
                }
            }
            $u = $stmt->fetch();
            $u['two_factor_enabled'] = $u['two_factor_enabled'] ?? 0;
            $u['two_factor_secret']  = $u['two_factor_secret']  ?? null;

            if (EMAIL_VERIFICATION_REQUIRED && !$u['email_verified']) {
                $unverifiedEmail = $u['email'];
                $error = 'Please verify your email before logging in.';
            } elseif ($u['two_factor_enabled']) {
                $_SESSION['pending_2fa_user_id']  = $u['id'];
                $_SESSION['pending_2fa_remember'] = $rememberMe;

                if (!empty($u['two_factor_secret'])) {
                    $_SESSION['pending_2fa_method'] = 'totp';
                } else {
                    $_SESSION['pending_2fa_method'] = 'email';
                    $code = create_2fa_code($u['id']);
                    $sent = send_2fa_code_email($u['email'], $u['full_name'], $code);
                    if (!$sent) {
                        // Roll back the pending-2FA session state so the user
                        // isn't left on /verify-2fa.php with no code in their
                        // inbox. Previously send_2fa_code_email()'s failure was
                        // ignored and the user was redirected anyway, leaving
                        // them stuck.
                        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_method'], $_SESSION['pending_2fa_remember']);
                        $error = 'We couldn\'t send your login code right now. Please try again in a moment, or contact support.';
                        error_log('[login] send_2fa_code_email failed for user ' . $u['id']);
                        $u = null; // fall through to render the login form with $error
                    }
                }

                if (!empty($u) && $u['two_factor_enabled']) {
                    header('Location: /verify-2fa.php');
                    exit;
                }
            } else {
                login_user($u['id']);
                if ($rememberMe) {
                    set_remember_me_cookie($u['id']);
                }
                header('Location: /portal/index.php');
                exit;
            }
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Login — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-5xl mx-auto px-6 py-16 md:py-24">
  <div class="grid md:grid-cols-2 gap-10 items-center">

    <!-- Left: value props -->
    <div class="hidden md:block">
      <span class="text-emerald-400 text-sm font-semibold uppercase tracking-wide">Welcome back</span>
      <h1 class="text-4xl font-extrabold mt-3 mb-6 leading-tight">Pick up right where you left off.</h1>
      <p class="text-slate-400 mb-8">Your leads, generated sites, and revenue tracking are all waiting in your dashboard.</p>
      <ul class="space-y-4 text-sm text-slate-300">
        <li class="flex items-start gap-3">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-magnifying-glass text-emerald-400 text-xs"></i></span>
          <span>Jump back into your saved lead searches</span>
        </li>
        <li class="flex items-start gap-3">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-bolt text-emerald-400 text-xs"></i></span>
          <span>Generate more websites for new clients</span>
        </li>
        <li class="flex items-start gap-3">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-chart-line text-emerald-400 text-xs"></i></span>
          <span>Check your revenue dashboard</span>
        </li>
      </ul>
    </div>

    <!-- Right: form -->
    <div class="glass rounded-2xl p-8">
      <h1 class="text-2xl font-bold mb-1 text-center md:hidden">Welcome Back</h1>
      <h2 class="text-xl font-bold mb-6 text-center hidden md:block">Log in to Utiligo</h2>

      <?php if (isset($_GET['resent'])): ?>
        <div class="bg-emerald-500/10 border border-emerald-400/30 text-emerald-400 rounded-lg px-4 py-3 mb-6 text-sm">Verification email resent — check your inbox.</div>
      <?php endif; ?>
      <?php if (isset($_GET['expired'])): ?>
        <div class="bg-amber-500/10 border border-amber-400/30 text-amber-400 rounded-lg px-4 py-3 mb-6 text-sm">Your session expired. Please log in again.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-400/30 text-red-400 rounded-lg px-4 py-3 mb-6 text-sm">
          <?= htmlspecialchars($error) ?>
          <?php if ($unverifiedEmail): ?>
            <form method="POST" action="/resend-verification.php" class="mt-2">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="email" value="<?= htmlspecialchars($unverifiedEmail) ?>">
              <button type="submit" class="text-emerald-400 hover:underline text-sm">Resend verification email</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div>
          <label class="block text-sm mb-2">Email</label>
          <input type="email" name="email" required autofocus
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        </div>
        <div>
          <label class="block text-sm mb-2">Password</label>
          <input type="password" name="password" required
            class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        </div>

        <div class="flex items-center justify-between">
          <label class="flex items-center gap-2.5 cursor-pointer select-none group">
            <div class="relative">
              <input type="checkbox" name="remember_me" value="1" id="rememberMe"
                     class="sr-only peer"
                     <?= !empty($_POST['remember_me']) ? 'checked' : '' ?>>
              <div class="w-4 h-4 rounded border border-slate-500 bg-slate-800
                          peer-checked:bg-emerald-500 peer-checked:border-emerald-500
                          transition-colors"></div>
              <svg class="absolute inset-0 w-4 h-4 text-white opacity-0 peer-checked:opacity-100 pointer-events-none transition-opacity"
                   viewBox="0 0 16 16" fill="none">
                <path d="M3 8l3.5 3.5L13 5" stroke="currentColor" stroke-width="2"
                      stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <span class="text-sm text-slate-400 group-hover:text-slate-300 transition-colors">Remember me for 30 days</span>
          </label>
          <a href="/forgot-password.php" class="text-xs text-emerald-400 hover:underline">Forgot password?</a>
        </div>

        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold transition">
          Log In
        </button>
      </form>

      <p class="text-center text-sm text-slate-400 mt-6">
        Don't have an account? <a href="/register.php" class="text-emerald-400 hover:underline">Start Free</a>
      </p>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
