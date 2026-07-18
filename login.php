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
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $result   = attempt_login($email, $password);

        if ($result['success']) {
            $userdb = get_user_db();

            // Detect whether two_factor_enabled column exists yet.
            // If the migration hasn't run, fall back to a query without it.
            try {
                $stmt = $userdb->prepare('SELECT id, full_name, email, email_verified, two_factor_enabled FROM utiligo_users WHERE email = ? LIMIT 1');
                $stmt->execute([strtolower(trim($email))]);
            } catch (\PDOException $e) {
                // Column missing — run without it (2FA treated as disabled)
                $stmt = $userdb->prepare('SELECT id, full_name, email, email_verified FROM utiligo_users WHERE email = ? LIMIT 1');
                $stmt->execute([strtolower(trim($email))]);
            }
            $u = $stmt->fetch();
            $u['two_factor_enabled'] = $u['two_factor_enabled'] ?? 0;

            if (EMAIL_VERIFICATION_REQUIRED && !$u['email_verified']) {
                $unverifiedEmail = $u['email'];
                $error = 'Please verify your email before logging in.';
            } elseif ($u['two_factor_enabled']) {
                $code = create_2fa_code($u['id']);
                send_2fa_code_email($u['email'], $u['full_name'], $code);
                $_SESSION['pending_2fa_user_id'] = $u['id'];
                header('Location: /verify-2fa.php');
                exit;
            } else {
                login_user($u['id']);
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
        <div class="text-right">
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
