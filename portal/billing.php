<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

require_login();
$user        = current_user();
$showUpgrade = isset($_GET['upgrade']);
$message     = '';
$error       = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } elseif ($_POST['action'] === 'test_subscribe' && TEST_PAYMENT_MODE) {
        $cardNumber = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
        $cardExpiry = trim($_POST['card_expiry'] ?? '');
        $cardCvc    = preg_replace('/\D/', '', $_POST['card_cvc'] ?? '');
        if (strlen($cardNumber) < 12) {
            $error = 'Please enter a valid test card number (any 12+ digits work in test mode).';
        } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $cardExpiry)) {
            $error = 'Please enter a valid expiry date (MM/YY).';
        } elseif (strlen($cardCvc) < 3) {
            $error = 'Please enter a valid CVC.';
        } else {
            $userdb = get_user_db();
            // Try with subscription_started_at first; fall back if column missing
            try {
                $userdb->prepare("UPDATE utiligo_users SET plan='pro', subscription_status='active', subscription_started_at=NOW() WHERE id=?")
                    ->execute([$user['id']]);
            } catch (\PDOException $e) {
                $userdb->prepare("UPDATE utiligo_users SET plan='pro', subscription_status='active' WHERE id=?")
                    ->execute([$user['id']]);
            }
            brevo_upsert_contact($user['email'], ['FIRSTNAME' => $user['full_name']], [BREVO_LIST_ALL_USERS, BREVO_LIST_PRO_USERS]);
            send_welcome_email($user['email'], $user['full_name']);
            header('Location: /portal/index.php?upgraded=1'); exit;
        }
    } elseif ($_POST['action'] === 'cancel') {
        $userdb = get_user_db();
        $userdb->prepare("UPDATE utiligo_users SET subscription_status='cancelled' WHERE id=?")->execute([$user['id']]);
        $message = 'Subscription cancelled. Pro features remain active until the end of your billing period.';
        $user['subscription_status'] = 'cancelled';
    }
}

$is_pro       = ($user['plan'] ?? 'free') === 'pro';
$is_active    = ($user['subscription_status'] ?? '') === 'active';
$is_cancelled = ($user['subscription_status'] ?? '') === 'cancelled';

$pageTitle = 'Billing — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Billing</h1>
  <p class="text-slate-400 text-sm mt-1">Manage your Utiligo subscription and plan.</p>
</div>

<?php if ($message): ?>
<div class="flex items-center gap-3 bg-emerald-500/10 border border-emerald-400/20 text-emerald-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check shrink-0"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Current plan card -->
<div class="glass rounded-2xl p-6 border border-white/5 mb-6">
  <div class="flex items-center justify-between flex-wrap gap-4">
    <div class="flex items-center gap-4">
      <div class="w-12 h-12 rounded-2xl <?= $is_pro ? 'bg-emerald-500/20' : 'bg-white/5' ?> flex items-center justify-center">
        <i class="fa-solid fa-<?= $is_pro ? 'crown text-emerald-400' : 'user text-slate-400' ?> text-lg"></i>
      </div>
      <div>
        <p class="font-bold text-lg"><?= $is_pro ? 'Utiligo Pro' : 'Free Plan' ?></p>
        <p class="text-slate-400 text-sm"><?php
          if ($is_pro && $is_active)        echo '$21.99 / month &mdash; Active';
          elseif ($is_pro && $is_cancelled) echo 'Cancelled &mdash; Active until end of period';
          else                              echo 'Limited to 3 leads &bull; 1 site/day &bull; 2 templates';
        ?></p>
      </div>
    </div>
    <span class="text-xs px-3 py-1.5 rounded-full font-semibold <?= $is_pro && $is_active ? 'bg-emerald-500/20 text-emerald-400' : ($is_cancelled ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400') ?>">
      <?= $is_pro && $is_active ? 'Active' : ($is_cancelled ? 'Cancelled' : 'Free') ?>
    </span>
  </div>
  <?php if ($is_pro && $is_active): ?>
  <div class="mt-5 pt-5 border-t border-white/5">
    <form method="POST" onsubmit="return confirm('Cancel your Pro subscription?');">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="text-sm text-red-400 hover:text-red-300 transition">
        <i class="fa-solid fa-xmark mr-1"></i>Cancel Subscription
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if (!$is_pro || $is_cancelled): ?>
<!-- Upgrade card -->
<div class="rounded-2xl border border-emerald-500/20 overflow-hidden mb-6" style="background:linear-gradient(135deg,#0c1f16 0%,#0a1520 100%)">
  <div class="px-6 py-6">
    <div class="flex items-start justify-between flex-wrap gap-4 mb-6">
      <div>
        <div class="flex items-center gap-2 mb-1">
          <i class="fa-solid fa-crown text-emerald-400"></i>
          <span class="text-xs font-bold text-emerald-400 uppercase tracking-widest">Pro Plan</span>
        </div>
        <p class="text-2xl font-black">$21.99 <span class="text-sm text-slate-400 font-normal">/ month</span></p>
        <p class="text-slate-400 text-sm mt-1">Everything you need to run a full client-getting machine.</p>
      </div>
      <div class="space-y-2 text-sm">
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-4"></i>Unlimited lead searches</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-4"></i>Full phone numbers</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-4"></i>Unlimited site generation</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-4"></i>All 12 templates</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-4"></i>White-label branding</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-4"></i>Priority support</div>
      </div>
    </div>

    <?php if (TEST_PAYMENT_MODE): ?>
    <div class="flex items-center gap-2 bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-2.5 mb-5 text-xs text-amber-400">
      <i class="fa-solid fa-flask"></i>
      <span><strong>Test Mode</strong> &mdash; No real card needed. Any 12+ digit number works.</span>
    </div>
    <form method="POST" class="space-y-4" id="billingForm">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="test_subscribe">
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Card Number</label>
        <div class="relative">
          <input type="text" name="card_number" id="cardNumberInput" inputmode="numeric"
            placeholder="4242 4242 4242 4242" maxlength="19" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-4 pr-12 py-3 tracking-wider focus:outline-none focus:border-emerald-500 transition">
          <i class="fa-solid fa-credit-card absolute right-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Expiry</label>
          <input type="text" id="cardExpiryInput" name="card_expiry" inputmode="numeric"
            placeholder="MM/YY" maxlength="5" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-emerald-500 transition">
        </div>
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">CVC</label>
          <input type="text" id="cardCvcInput" name="card_cvc" inputmode="numeric"
            placeholder="123" maxlength="4" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-emerald-500 transition">
        </div>
      </div>
      <button type="submit"
              class="w-full bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 py-3.5 rounded-xl font-bold transition-all shadow-lg shadow-emerald-500/20">
        <i class="fa-solid fa-lock mr-2"></i>Subscribe to Pro &mdash; $21.99/mo
      </button>
      <div class="flex items-center justify-center gap-2 text-xs text-slate-500">
        <i class="fa-solid fa-lock"></i>
        <span>Secured by</span>
        <i class="fa-brands fa-stripe text-xl text-slate-300"></i>
      </div>
    </form>
    <?php else: ?>
    <a href="#" class="block w-full text-center bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3.5 rounded-xl font-bold transition shadow-lg shadow-emerald-500/20">
      <i class="fa-solid fa-lock mr-2"></i>Subscribe via Stripe
    </a>
    <div class="flex items-center justify-center gap-2 text-xs text-slate-500 mt-3">
      <i class="fa-solid fa-lock"></i><span>Secured by</span><i class="fa-brands fa-stripe text-xl text-slate-300"></i>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div></main>
<script src="/assets/js/billing_card.js?v=v166"></script>
</body></html>
