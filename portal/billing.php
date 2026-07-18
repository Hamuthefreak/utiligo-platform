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

$_target_plan = isset($_GET['plan']) && $_GET['plan'] === 'entrepreneur' ? 'entrepreneur' : 'pro';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } elseif ($_POST['action'] === 'test_subscribe' && TEST_PAYMENT_MODE) {
        $subscribePlan = in_array($_POST['subscribe_plan'] ?? '', ['pro','entrepreneur']) ? $_POST['subscribe_plan'] : 'pro';
        $cardNumber    = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
        $cardExpiry    = trim($_POST['card_expiry'] ?? '');
        $cardCvc       = preg_replace('/\D/', '', $_POST['card_cvc'] ?? '');
        if (strlen($cardNumber) < 12) {
            $error = 'Please enter a valid test card number (any 12+ digits work in test mode).';
        } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $cardExpiry)) {
            $error = 'Please enter a valid expiry date (MM/YY).';
        } elseif (strlen($cardCvc) < 3) {
            $error = 'Please enter a valid CVC.';
        } else {
            $userdb = get_user_db();
            try {
                $userdb->prepare("UPDATE utiligo_users SET plan=?, subscription_status='active', subscription_started_at=NOW() WHERE id=?")
                    ->execute([$subscribePlan, $user['id']]);
            } catch (\PDOException $e) {
                $userdb->prepare("UPDATE utiligo_users SET plan=?, subscription_status='active' WHERE id=?")
                    ->execute([$subscribePlan, $user['id']]);
            }
            $listIds = $subscribePlan === 'entrepreneur'
                ? [BREVO_LIST_ALL_USERS, BREVO_LIST_PRO_USERS]
                : [BREVO_LIST_ALL_USERS, BREVO_LIST_PRO_USERS];
            brevo_upsert_contact($user['email'], ['FIRSTNAME' => $user['full_name']], $listIds);
            send_welcome_email($user['email'], $user['full_name']);
            header('Location: /portal/index.php?upgraded=1'); exit;
        }
    } elseif ($_POST['action'] === 'cancel') {
        $userdb = get_user_db();
        $userdb->prepare("UPDATE utiligo_users SET subscription_status='cancelled' WHERE id=?")->execute([$user['id']]);
        $message = 'Subscription cancelled. Your plan features remain active until the end of your billing period.';
        $user['subscription_status'] = 'cancelled';
    }
}

$plan         = $user['plan'] ?? 'free';
$is_pro       = $plan === 'pro';
$is_ent       = $plan === 'entrepreneur';
$is_paid      = $is_pro || $is_ent;
$is_active    = ($user['subscription_status'] ?? '') === 'active';
$is_cancelled = ($user['subscription_status'] ?? '') === 'cancelled';
$pcfg         = get_plan_config($plan);

$pageTitle = 'Billing — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Billing</h1>
  <p class="text-slate-400 text-sm mt-1">Manage your Utiligo subscription and plan.</p>
</div>

<?php if ($message): ?>
<div class="flex items-center gap-3 bg-white/5 border border-white/10 text-white rounded-2xl px-5 py-4 mb-6 text-sm">
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
      <div class="w-12 h-12 rounded-2xl <?= $is_paid ? 'bg-white/10' : 'bg-white/5' ?> flex items-center justify-center">
        <i class="fa-solid fa-<?= $is_ent ? 'rocket' : ($is_pro ? 'crown' : 'user') ?> <?= $is_paid ? 'text-white' : 'text-slate-400' ?> text-lg"></i>
      </div>
      <div>
        <p class="font-bold text-lg">
          <?php
            if ($is_ent)       echo 'Utiligo Entrepreneur';
            elseif ($is_pro)   echo 'Utiligo Pro';
            else               echo 'Free Plan';
          ?>
        </p>
        <p class="text-slate-400 text-sm"><?php
          if ($is_ent  && $is_active)    echo '$49.99 / month &mdash; Active';
          elseif ($is_ent  && $is_cancelled) echo 'Cancelled &mdash; Active until end of period';
          elseif ($is_pro  && $is_active)    echo '$21.99 / month &mdash; Active';
          elseif ($is_pro  && $is_cancelled) echo 'Cancelled &mdash; Active until end of period';
          else echo 'Limited to 3 leads &bull; 1 site/day &bull; 2 templates';
        ?></p>
      </div>
    </div>
    <span class="text-xs px-3 py-1.5 rounded-full font-semibold <?= $is_paid && $is_active ? 'bg-white/10 text-white' : ($is_cancelled ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400') ?>">
      <?= $is_paid && $is_active ? 'Active' : ($is_cancelled ? 'Cancelled' : 'Free') ?>
    </span>
  </div>

  <!-- Plan limits bar -->
  <?php if ($is_paid && $is_active):
    $lead_limit = plan_lead_limit($plan);
    $site_limit = plan_site_limit($plan);
  ?>
  <div class="mt-5 pt-5 border-t border-white/5 grid sm:grid-cols-2 gap-4 text-xs text-slate-400">
    <div>
      <div class="flex justify-between mb-1">
        <span>Leads unlocked</span>
        <span class="text-white font-semibold"><?= $lead_limit === -1 ? 'Unlimited' : '0 / '.$lead_limit ?></span>
      </div>
    </div>
    <div>
      <div class="flex justify-between mb-1">
        <span>Active websites</span>
        <span class="text-white font-semibold"><?= $site_limit === -1 ? 'Unlimited' : '0 / '.$site_limit ?></span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($is_paid && $is_active): ?>
  <div class="mt-5 pt-5 border-t border-white/5">
    <form method="POST" onsubmit="return confirm('Cancel your subscription?');">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="text-sm text-red-400 hover:text-red-300 transition">
        <i class="fa-solid fa-xmark mr-1"></i>Cancel Subscription
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- Upgrade to Entrepreneur if on Pro -->
<?php if ($is_pro && $is_active): ?>
<div class="glass rounded-2xl p-5 border border-white/10 mb-6 flex items-center justify-between flex-wrap gap-4">
  <div>
    <p class="font-bold text-sm text-white"><i class="fa-solid fa-rocket mr-2"></i>Upgrade to Entrepreneur</p>
    <p class="text-xs text-slate-400 mt-0.5">Unlimited leads &bull; 500 active sites &bull; Custom domains &bull; Team seats</p>
  </div>
  <a href="?plan=entrepreneur&upgrade=1" class="text-xs bg-white hover:bg-slate-200 text-black px-5 py-2 rounded-xl font-bold transition whitespace-nowrap">Upgrade &rarr;</a>
</div>
<?php endif; ?>

<?php if (!$is_paid || $is_cancelled): ?>
<!-- Plan selection tabs -->
<?php
  $showPlan = $_target_plan;
?>
<div class="flex gap-2 mb-5">
  <a href="?upgrade=1&plan=pro"
     class="px-5 py-2 rounded-full text-sm font-bold transition <?= $showPlan==='pro' ? 'bg-white text-black' : 'bg-white/8 text-slate-300 hover:bg-white/15' ?>">
    Pro &mdash; $21.99/mo
  </a>
  <a href="?upgrade=1&plan=entrepreneur"
     class="px-5 py-2 rounded-full text-sm font-bold transition <?= $showPlan==='entrepreneur' ? 'bg-white text-black' : 'bg-white/8 text-slate-300 hover:bg-white/15' ?>">
    Entrepreneur &mdash; $49.99/mo
  </a>
</div>

<?php if ($showPlan === 'entrepreneur'): ?>
<!-- Entrepreneur upgrade card -->
<div class="rounded-2xl border border-white/15 overflow-hidden mb-6" style="background:linear-gradient(135deg,#0f0f0f 0%,#1a1a2e 100%)">
  <div class="px-6 py-6">
    <div class="flex items-start justify-between flex-wrap gap-4 mb-6">
      <div>
        <div class="flex items-center gap-2 mb-1">
          <i class="fa-solid fa-rocket text-white"></i>
          <span class="text-xs font-bold text-white uppercase tracking-widest">Entrepreneur Plan</span>
        </div>
        <p class="text-2xl font-black">$49.99 <span class="text-sm text-slate-400 font-normal">/ month</span></p>
        <p class="text-slate-400 text-sm mt-1">Built for agencies running multiple clients at scale.</p>
      </div>
      <div class="space-y-2 text-sm">
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-infinity text-white w-4"></i>Unlimited leads</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>500 active websites</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>All templates + ZIP export</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>Custom domains</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>Client reports</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>Team seats</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>Priority support</div>
      </div>
    </div>
    <?php if (TEST_PAYMENT_MODE): ?>
    <div class="flex items-center gap-2 bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-2.5 mb-5 text-xs text-amber-400">
      <i class="fa-solid fa-flask"></i>
      <span><strong>Test Mode</strong> &mdash; No real card needed. Any 12+ digit number works.</span>
    </div>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="test_subscribe">
      <input type="hidden" name="subscribe_plan" value="entrepreneur">
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Card Number</label>
        <div class="relative">
          <input type="text" name="card_number" id="cardNumberInputEnt" inputmode="numeric"
            placeholder="4242 4242 4242 4242" maxlength="19" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-4 pr-12 py-3 tracking-wider focus:outline-none focus:border-white/40 transition">
          <i class="fa-solid fa-credit-card absolute right-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Expiry</label>
          <input type="text" name="card_expiry" inputmode="numeric" placeholder="MM/YY" maxlength="5" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
        </div>
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">CVC</label>
          <input type="text" name="card_cvc" inputmode="numeric" placeholder="123" maxlength="4" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
        </div>
      </div>
      <button type="submit"
              class="w-full bg-white hover:bg-slate-200 active:scale-95 text-black py-3.5 rounded-xl font-bold transition-all shadow-lg">
        <i class="fa-solid fa-lock mr-2"></i>Subscribe to Entrepreneur &mdash; $49.99/mo
      </button>
      <div class="flex items-center justify-center gap-2 text-xs text-slate-500">
        <i class="fa-solid fa-lock"></i>
        <span>Secured by</span>
        <i class="fa-brands fa-stripe text-xl text-slate-300"></i>
      </div>
    </form>
    <?php else: ?>
    <a href="#" class="block w-full text-center bg-white hover:bg-slate-200 text-black py-3.5 rounded-xl font-bold transition">
      <i class="fa-solid fa-lock mr-2"></i>Subscribe via Stripe
    </a>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- Pro upgrade card -->
<div class="rounded-2xl border border-white/15 overflow-hidden mb-6" style="background:linear-gradient(135deg,#0f0f0f 0%,#1c1c1c 100%)">
  <div class="px-6 py-6">
    <div class="flex items-start justify-between flex-wrap gap-4 mb-6">
      <div>
        <div class="flex items-center gap-2 mb-1">
          <i class="fa-solid fa-crown text-white"></i>
          <span class="text-xs font-bold text-white uppercase tracking-widest">Pro Plan</span>
        </div>
        <p class="text-2xl font-black">$21.99 <span class="text-sm text-slate-400 font-normal">/ month</span></p>
        <p class="text-slate-400 text-sm mt-1">Everything you need to run a full client-getting operation.</p>
      </div>
      <div class="space-y-2 text-sm">
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>120 leads unlocked / period</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>200 active websites</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>Full phone numbers</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>All templates + ZIP export</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>Revenue dashboard</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-white w-4"></i>Priority support</div>
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
      <input type="hidden" name="subscribe_plan" value="pro">
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Card Number</label>
        <div class="relative">
          <input type="text" name="card_number" id="cardNumberInput" inputmode="numeric"
            placeholder="4242 4242 4242 4242" maxlength="19" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-4 pr-12 py-3 tracking-wider focus:outline-none focus:border-white/40 transition">
          <i class="fa-solid fa-credit-card absolute right-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Expiry</label>
          <input type="text" id="cardExpiryInput" name="card_expiry" inputmode="numeric"
            placeholder="MM/YY" maxlength="5" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
        </div>
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">CVC</label>
          <input type="text" id="cardCvcInput" name="card_cvc" inputmode="numeric"
            placeholder="123" maxlength="4" required
            class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
        </div>
      </div>
      <button type="submit"
              class="w-full bg-white hover:bg-slate-200 active:scale-95 text-black py-3.5 rounded-xl font-bold transition-all shadow-lg">
        <i class="fa-solid fa-lock mr-2"></i>Subscribe to Pro &mdash; $21.99/mo
      </button>
      <div class="flex items-center justify-center gap-2 text-xs text-slate-500">
        <i class="fa-solid fa-lock"></i>
        <span>Secured by</span>
        <i class="fa-brands fa-stripe text-xl text-slate-300"></i>
      </div>
    </form>
    <?php else: ?>
    <a href="#" class="block w-full text-center bg-white hover:bg-slate-200 text-black py-3.5 rounded-xl font-bold transition">
      <i class="fa-solid fa-lock mr-2"></i>Subscribe via Stripe
    </a>
    <div class="flex items-center justify-center gap-2 text-xs text-slate-500 mt-3">
      <i class="fa-solid fa-lock"></i><span>Secured by</span><i class="fa-brands fa-stripe text-xl text-slate-300"></i>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

</div></main>
<script src="/assets/js/billing_card.js?v=v300"></script>
</body></html>
