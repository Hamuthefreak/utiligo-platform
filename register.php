<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/userdb.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

if (is_logged_in()) { header('Location: /portal/index.php'); exit; }

$_plan_param  = isset($_GET['plan']) && in_array($_GET['plan'], ['pro','entrepreneur']) ? $_GET['plan'] : 'free';
$_plan_labels = ['free' => 'Free', 'pro' => 'Pro — $21.99/mo', 'entrepreneur' => 'Entrepreneur — $49.99/mo'];

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session token. Please try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $password  = $_POST['password'] ?? '';
        $plan      = in_array($_POST['plan'] ?? '', ['free','pro','entrepreneur']) ? $_POST['plan'] : 'free';

        if (!$full_name)                                    { $error = 'Please enter your full name.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please enter a valid email address.'; }
        elseif (strlen($password) < 8)                      { $error = 'Password must be at least 8 characters.'; }
        else {
            $userdb = get_user_db();
            $stmt   = $userdb->prepare('SELECT id FROM utiligo_users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'An account with that email already exists. <a href="/login.php" class="underline">Sign in instead?</a>';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                // Insert with email_verified = 0
                try {
                    $userdb->prepare('INSERT INTO utiligo_users (full_name, email, password_hash, plan, subscription_status, email_verified, created_at) VALUES (?,?,?,?,?,0,NOW())')
                        ->execute([$full_name, $email, $hash, 'free', 'none']);
                } catch (\PDOException $e) {
                    // Fallback for older schema without subscription_status column
                    $userdb->prepare('INSERT INTO utiligo_users (full_name, email, password_hash, plan, email_verified, created_at) VALUES (?,?,?,?,0,NOW())')
                        ->execute([$full_name, $email, $hash, 'free']);
                }

                // Get the new user's ID
                $stmt2   = $userdb->prepare('SELECT id FROM utiligo_users WHERE email = ? LIMIT 1');
                $stmt2->execute([$email]);
                $newUser = $stmt2->fetch(PDO::FETCH_ASSOC);

                if ($newUser) {
                    // Generate and send verification email
                    $token       = create_email_verification_token($newUser['id']);
                    $verifyLink  = rtrim(APP_BASE_URL, '/') . '/verify.php?token=' . $token;
                    try { send_verification_email($email, $full_name, $verifyLink); } catch (\Throwable $e) {}

                    // Add to Brevo contacts list (fire-and-forget)
                    try { brevo_upsert_contact($email, ['FIRSTNAME' => $full_name], [BREVO_LIST_ALL_USERS]); } catch (\Throwable $e) {}
                }

                // Store chosen plan in session so billing redirect works after verify
                $_SESSION['pending_plan']  = $plan;
                $_SESSION['pending_email'] = $email;

                // Show "check your inbox" screen — do NOT log in yet
                $success = true;
            }
        }
    }
}

$pageTitle = 'Create Account — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="min-h-screen flex items-center justify-center px-4 py-16">
  <div class="w-full max-w-md">

    <?php if ($success): ?>
    <!-- ====== Check your inbox screen ====== -->
    <div class="text-center">
      <div class="w-20 h-20 rounded-full bg-white/8 border border-white/15 flex items-center justify-center mx-auto mb-6">
        <i class="fa-solid fa-envelope-circle-check text-white text-3xl"></i>
      </div>
      <h1 class="text-2xl font-bold mb-2">Check your inbox!</h1>
      <p class="text-slate-400 text-sm mb-6 max-w-xs mx-auto">
        We sent a verification link to <strong class="text-white"><?= htmlspecialchars($email) ?></strong>.
        Click it to activate your account.
      </p>
      <p class="text-xs text-slate-500">Didn’t get it? Check spam, or
        <form method="POST" action="/resend-verification.php" class="inline">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
          <button type="submit" class="text-white underline text-xs">resend it</button>
        </form>.
      </p>
    </div>

    <?php else: ?>
    <!-- ====== Registration form ====== -->
    <div class="text-center mb-8">
      <a href="/" class="inline-flex items-center gap-2 mb-6">
        <div class="w-9 h-9 rounded-xl bg-white flex items-center justify-center">
          <i class="fa-solid fa-bolt text-black text-base"></i>
        </div>
        <span class="text-2xl font-black">Utiligo</span>
      </a>
      <h1 class="text-2xl font-bold">Create your account</h1>
      <p class="text-slate-400 text-sm mt-1">No credit card required to start.</p>
    </div>

    <?php if ($_plan_param !== 'free'): ?>
    <div class="flex items-center gap-2 bg-white/8 border border-white/15 rounded-xl px-4 py-3 mb-5 text-sm">
      <i class="fa-solid fa-<?= $_plan_param === 'entrepreneur' ? 'rocket' : 'crown' ?> text-white"></i>
      <span class="text-white font-semibold">Starting on <strong><?= $_plan_labels[$_plan_param] ?></strong> &mdash; you’ll complete payment after signup.</span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl px-4 py-3 mb-5 text-sm"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" class="glass rounded-2xl p-8 border border-white/10 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="plan" value="<?= htmlspecialchars($_plan_param) ?>">

      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Full Name</label>
        <input type="text" name="full_name" required autofocus
               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
      </div>
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Email</label>
        <input type="email" name="email" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
      </div>
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Password</label>
        <input type="password" name="password" required minlength="8"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
        <p class="text-xs text-slate-500 mt-1">Minimum 8 characters</p>
      </div>
      <button type="submit"
              class="w-full bg-white hover:bg-slate-200 active:scale-95 text-black py-3.5 rounded-xl font-bold transition-all">
        <?= $_plan_param !== 'free' ? 'Create Account &amp; Continue to Billing' : 'Create Free Account' ?>
      </button>
    </form>

    <p class="text-center text-sm text-slate-500 mt-6">
      Already have an account? <a href="/login.php" class="text-white hover:underline">Sign in</a>
    </p>
    <?php endif; ?>

  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
