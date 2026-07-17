<?php
/**
 * portal/billing.php — Billing & subscription management.
 * TEST_PAYMENT_MODE (config.php) simulates checkout with a fake card form so
 * you can test upgrade/downgrade flows without a real Stripe account or bank
 * account. Flip TEST_PAYMENT_MODE to false and wire in real Stripe Checkout
 * once you're ready to accept real payments.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

require_login();
$user = current_user();
$showUpgrade = isset($_GET['upgrade']);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } elseif ($_POST['action'] === 'test_subscribe' && TEST_PAYMENT_MODE) {
        $cardNumber = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
        $cardExpiry = trim($_POST['card_expiry'] ?? '');
        $cardCvc = preg_replace('/\D/', '', $_POST['card_cvc'] ?? '');
        if (strlen($cardNumber) < 12) {
            $error = 'Please enter a valid test card number (any 12+ digits work in test mode).';
        } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $cardExpiry)) {
            $error = 'Please enter a valid expiry date (MM/YY).';
        } elseif (strlen($cardCvc) < 3) {
            $error = 'Please enter a valid CVC.';
        } else {
            $userdb = get_user_db();
            $userdb->prepare("UPDATE utiligo_users SET plan='pro', subscription_status='active', subscription_started_at=NOW() WHERE id=?")
                ->execute([$user['id']]);

            brevo_upsert_contact($user['email'], ['FIRSTNAME' => $user['full_name']], [BREVO_LIST_ALL_USERS, BREVO_LIST_PRO_USERS]);
            send_welcome_email($user['email'], $user['full_name']);

            header('Location: /portal/onboarding.php?step=1');
            exit;
        }
    } elseif ($_POST['action'] === 'cancel') {
        $userdb = get_user_db();
        $userdb->prepare("UPDATE utiligo_users SET subscription_status='cancelled' WHERE id=?")->execute([$user['id']]);
        $message = 'Your subscription has been cancelled. Your Pro features remain active until the end of your billing period.';
        $user['subscription_status'] = 'cancelled';
    }
}

$pageTitle = 'Billing — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-2xl mx-auto px-6 py-10">
  <a href="/portal/index.php" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white mb-6">
    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
  </a>
  <h1 class="text-2xl font-bold mb-2">Billing</h1>
  <p class="text-slate-400 text-sm mb-8">Manage your Utiligo subscription.</p>

  <?php if ($message): ?>
    <div class="bg-emerald-500/10 border border-emerald-400/30 text-emerald-400 rounded-lg px-4 py-3 mb-6 text-sm"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="bg-red-500/10 border border-red-400/30 text-red-400 rounded-lg px-4 py-3 mb-6 text-sm"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($user['plan'] === 'pro' && $user['subscription_status'] === 'active'): ?>
    <div class="glass rounded-xl p-6 mb-6">
      <div class="flex justify-between items-center mb-4">
        <div>
          <p class="font-semibold">Utiligo Pro</p>
          <p class="text-slate-400 text-sm">$21.99/month — Active</p>
        </div>
        <span class="text-xs bg-emerald-500/20 text-emerald-400 px-3 py-1 rounded-full">Active</span>
      </div>
      <form method="POST" onsubmit="return confirm('Cancel your Pro subscription?');">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="text-sm text-red-400 hover:underline">Cancel Subscription</button>
      </form>
    </div>

  <?php elseif ($user['subscription_status'] === 'cancelled'): ?>
    <div class="glass rounded-xl p-6 mb-6">
      <p class="font-semibold mb-1">Utiligo Pro — Cancelled</p>
      <p class="text-slate-400 text-sm">Your Pro access remains active until the end of your current billing period.</p>
    </div>

  <?php else: ?>
    <div class="glass rounded-xl p-6 mb-6">
      <p class="font-semibold mb-1">Free Plan</p>
      <p class="text-slate-400 text-sm">Upgrade to Pro for unlimited leads, website generation, and white-labeling.</p>
    </div>

    <?php if (TEST_PAYMENT_MODE): ?>
      <div class="glass rounded-xl p-6">
        <div class="flex items-center gap-2 mb-4">
          <i class="fa-solid fa-flask text-amber-400"></i>
          <p class="text-sm text-amber-400 font-semibold">Test Payment Mode — no real card or bank account needed</p>
        </div>
        <h3 class="font-bold text-lg mb-1">Upgrade to Pro — $21.99/mo</h3>
        <p class="text-xs text-slate-500 mb-5">In test mode, any card number 12+ digits long works — nothing is actually charged.</p>

        <form method="POST" class="space-y-4" id="billingForm">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="test_subscribe">

          <div>
            <label class="block text-sm mb-2">Card Number</label>
            <div class="relative">
              <input type="text" name="card_number" id="cardNumberInput" inputmode="numeric" autocomplete="cc-number"
                placeholder="4242 4242 4242 4242" maxlength="19" required
                class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg pl-4 pr-11 py-2.5 tracking-wider">
              <i class="fa-solid fa-credit-card absolute right-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm mb-2">Expiry</label>
              <input type="text" id="cardExpiryInput" name="card_expiry" inputmode="numeric" autocomplete="cc-exp"
                placeholder="MM/YY" maxlength="5" required
                class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
            </div>
            <div>
              <label class="block text-sm mb-2">CVC</label>
              <input type="text" id="cardCvcInput" name="card_cvc" inputmode="numeric" autocomplete="cc-csc"
                placeholder="123" maxlength="4" required
                class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
            </div>
          </div>

          <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold transition">
            <i class="fa-solid fa-lock mr-2"></i>Subscribe to Pro
          </button>

          <div class="flex items-center justify-center gap-2 text-xs text-slate-500 pt-1">
            <i class="fa-solid fa-lock text-slate-500"></i>
            <span>Payments are securely processed by</span>
            <i class="fa-brands fa-stripe text-xl text-slate-300 -mb-0.5"></i>
          </div>
        </form>
      </div>
    <?php else: ?>
      <a href="#" class="block text-center bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">
        <i class="fa-solid fa-lock mr-2"></i>Subscribe via Stripe
      </a>
      <div class="flex items-center justify-center gap-2 text-xs text-slate-500 mt-4">
        <i class="fa-solid fa-lock text-slate-500"></i>
        <span>Payments are securely processed by</span>
        <i class="fa-brands fa-stripe text-xl text-slate-300 -mb-0.5"></i>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/billing_card.js?v=v162"></script>
