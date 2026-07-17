<?php
/**
 * register.php — User registration page.
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
$success = false;
$wantsPro = isset($_GET['plan']) && $_GET['plan'] === 'pro';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            $result = register_user($email, $password, $fullName);
            if ($result['success']) {
                $userId = $result['user_id'];

                brevo_upsert_contact($email, ['FIRSTNAME' => $fullName], [BREVO_LIST_ALL_USERS, BREVO_LIST_FREE_USERS]);

                if (EMAIL_VERIFICATION_REQUIRED) {
                    $token = create_email_verification_token($userId);
                    $verifyLink = APP_BASE_URL . '/verify-email.php?token=' . $token;
                    send_verification_email($email, $fullName, $verifyLink);
                    $success = true;
                    $_SESSION['pending_verification_user_id'] = $userId;
                    $_SESSION['pending_wants_pro'] = $wantsPro;
                } else {
                    login_user($userId);
                    if ($wantsPro) {
                        header('Location: /portal/billing.php?upgrade=1');
                    } else {
                        header('Location: /portal/index.php');
                    }
                    exit;
                }
            } else {
                $error = $result['error'];
            }
        }
    }
}

$pageTitle = 'Sign Up — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-5xl mx-auto px-6 py-16 md:py-24">
  <div class="grid md:grid-cols-2 gap-10 items-center">

    <!-- Left: value props -->
    <div class="hidden md:block">
      <span class="text-emerald-400 text-sm font-semibold uppercase tracking-wide">For Freelancers & Agencies</span>
      <h1 class="text-4xl font-extrabold mt-3 mb-6 leading-tight">Start finding clients in the next 5 minutes.</h1>
      <p class="text-slate-400 mb-8">Utiligo finds local businesses without a website, then generates one for them instantly. No coding required.</p>
      <ul class="space-y-4 text-sm text-slate-300">
        <li class="flex items-start gap-3">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-check text-emerald-400 text-xs"></i></span>
          <span>Search any city and industry for warm leads</span>
        </li>
        <li class="flex items-start gap-3">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-check text-emerald-400 text-xs"></i></span>
          <span>Generate a full website in about 60 seconds</span>
        </li>
        <li class="flex items-start gap-3">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-check text-emerald-400 text-xs"></i></span>
          <span>Export a clean ZIP — no lock-in, ever</span>
        </li>
        <li class="flex items-start gap-3">
          <span class="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-check text-emerald-400 text-xs"></i></span>
          <span>White-label everything with your own brand</span>
        </li>
      </ul>
    </div>

    <!-- Right: form -->
    <div class="glass rounded-2xl p-8">

      <?php if ($success): ?>
        <div class="text-center">
          <div class="text-4xl mb-4">📬</div>
          <h1 class="text-2xl font-bold mb-2">Check Your Email</h1>
          <p class="text-slate-400 text-sm">We sent a verification link to your inbox. Click it to activate your account and get started.</p>
        </div>
      <?php else: ?>

      <h1 class="text-2xl font-bold mb-1 text-center md:hidden">Create Your Account</h1>
      <h2 class="text-xl font-bold mb-1 text-center hidden md:block">Create Your Account</h2>
      <p class="text-slate-400 text-sm text-center mb-6">
        <?= $wantsPro ? "Sign up, verify your email, then activate Pro — no bank account needed to test it." : "Start free. Upgrade anytime." ?>
      </p>

      <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-400/30 text-red-400 rounded-lg px-4 py-3 mb-6 text-sm">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div>
          <label class="block text-sm mb-2">Full Name</label>
          <input type="text" name="full_name" required
            value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
            class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        </div>
        <div>
          <label class="block text-sm mb-2">Email</label>
          <input type="email" name="email" required
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        </div>
        <div>
          <label class="block text-sm mb-2">Password (min 8 characters)</label>
          <input type="password" name="password" required minlength="8"
            class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        </div>
        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold transition">
          <?= $wantsPro ? 'Create Account & Continue' : 'Start Free' ?>
        </button>
      </form>

      <p class="text-center text-sm text-slate-400 mt-6">
        Already have an account? <a href="/login.php" class="text-emerald-400 hover:underline">Log In</a>
      </p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
