<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

require_login();
$user    = current_user();
$message = '';
$error   = '';

$_target_plan = (isset($_GET['plan']) && $_GET['plan'] === 'entrepreneur') ? 'entrepreneur' : 'pro';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } elseif ($_POST['action'] === 'test_subscribe') {
        $subscribePlan = in_array($_POST['subscribe_plan'] ?? '', ['pro','entrepreneur']) ? $_POST['subscribe_plan'] : 'pro';
        $cardNumber    = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
        $cardExpiry    = trim($_POST['card_expiry'] ?? '');
        $cardCvc       = preg_replace('/\D/', '', $_POST['card_cvc'] ?? '');
        if (strlen($cardNumber) < 12) {
            $error = 'Please enter a valid card number (12+ digits).';
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
            $listIds = [BREVO_LIST_ALL_USERS, BREVO_LIST_PRO_USERS];
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

$_pro_leads     = (int) PRO_LEAD_LIMIT;
$_pro_sites     = (int) PRO_SITE_LIMIT;
$_ent_sites     = (int) ENT_SITE_LIMIT;
$_ent_seats     = (int) ENT_TEAM_SEATS;
$_free_leads    = (int) FREE_LEAD_LIMIT;
$_free_sites    = (int) FREE_SITE_LIMIT;
$_pro_price     = (float) PRO_PLAN_PRICE;
$_ent_price     = (float) ENTREPRENEUR_PLAN_PRICE;
$_pro_price_fmt = number_format($_pro_price, 2);
$_ent_price_fmt = number_format($_ent_price, 2);

if (isset($_GET['cancelled'])) $message = 'Checkout cancelled \u2014 you were not charged.';

$pageTitle = 'Billing \u2014 Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
@keyframes ent-shimmer {
  0%   { background-position: -200% center; }
  100% { background-position:  200% center; }
}
@keyframes pulse-glow {
  0%, 100% { box-shadow: 0 0 20px rgba(245,158,11,.3); }
  50%       { box-shadow: 0 0 40px rgba(245,158,11,.6); }
}
.ent-badge {
  background: linear-gradient(90deg,#f59e0b,#fbbf24,#f59e0b);
  background-size: 200% auto;
  animation: ent-shimmer 2.5s linear infinite;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.ent-pill-border {
  background: linear-gradient(#0f172a,#0f172a) padding-box,
              linear-gradient(135deg,#f59e0b,#fbbf24,#f97316) border-box;
  border: 2px solid transparent;
}
.ent-glow-btn {
  background: linear-gradient(135deg,#f59e0b 0%,#f97316 50%,#ef4444 100%);
  box-shadow: 0 4px 24px rgba(245,158,11,.35);
  transition: all .2s;
}
.ent-glow-btn:hover  { box-shadow: 0 8px 36px rgba(245,158,11,.6); transform: translateY(-2px); }
.ent-glow-btn:active { transform: scale(.97); }
.ent-card-border {
  background: linear-gradient(#0d0d14,#0d0d14) padding-box,
              linear-gradient(135deg,#f59e0b55,#f9731633,#0d0d1400) border-box;
  border: 1.5px solid transparent;
}
.ent-col-highlight { background: rgba(245,158,11,.06); }
.compare-check { color:#22c55e; }
.compare-cross { color:#374151; }
.compare-ent   { color:#f59e0b; }
.pill-feature {
  display:inline-flex; align-items:center; gap:.35rem;
  background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.2);
  color:#fcd34d; border-radius:9999px; padding:.3rem .75rem; font-size:.7rem; font-weight:700;
}
</style>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Billing</h1>
  <p class="text-slate-400 text-sm mt-1">Manage your Utiligo subscription and plan.</p>
</div>

<?php if ($message): ?>
<div class="flex items-center gap-3 bg-white/5 border border-white/10 text-white rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check shrink-0 text-green-400"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- ===== CURRENT PLAN CARD ===== -->
<div class="glass rounded-2xl p-6 border border-white/5 mb-6">
  <div class="flex items-center justify-between flex-wrap gap-4">
    <div class="flex items-center gap-4">
      <div class="w-12 h-12 rounded-2xl <?= $is_ent ? 'bg-amber-500/15 border border-amber-500/30' : ($is_paid ? 'bg-white/10' : 'bg-white/5') ?> flex items-center justify-center">
        <i class="fa-solid fa-<?= $is_ent ? 'rocket' : ($is_pro ? 'crown' : 'user') ?> <?= $is_ent ? 'text-amber-400' : ($is_paid ? 'text-white' : 'text-slate-400') ?> text-lg"></i>
      </div>
      <div>
        <p class="font-bold text-lg"><?php
          if ($is_ent)     echo '<span class="ent-badge">Utiligo Entrepreneur</span>';
          elseif ($is_pro) echo 'Utiligo Pro';
          else             echo 'Free Plan';
        ?></p>
        <p class="text-slate-400 text-sm"><?php
          if ($is_ent  && $is_active)        echo '$'.$_ent_price_fmt.' / month &mdash; <span class="text-green-400 font-semibold">Active</span>';
          elseif ($is_ent  && $is_cancelled) echo 'Cancelled &mdash; Active until end of period';
          elseif ($is_pro  && $is_active)    echo '$'.$_pro_price_fmt.' / month &mdash; <span class="text-green-400 font-semibold">Active</span>';
          elseif ($is_pro  && $is_cancelled) echo 'Cancelled &mdash; Active until end of period';
          else echo 'Limited to '.$_free_leads.' leads &bull; '.$_free_sites.' site/day &bull; 2 templates';
        ?></p>
      </div>
    </div>
    <span class="text-xs px-3 py-1.5 rounded-full font-semibold <?=
      $is_ent && $is_active   ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30' :
      ($is_pro && $is_active  ? 'bg-white/10 text-white' :
      ($is_cancelled          ? 'bg-amber-500/20 text-amber-400' :
                                'bg-white/5 text-slate-400')) ?>">
      <?= $is_ent && $is_active  ? '&#x1F680; Entrepreneur' :
         ($is_pro && $is_active  ? 'Pro' :
         ($is_cancelled          ? 'Cancelled' : 'Free')) ?>
    </span>
  </div>

  <?php if ($is_paid && $is_active):
    $lead_limit = plan_lead_limit($plan);
    $site_limit = plan_site_limit($plan);
    $seats      = plan_team_seats($plan);
  ?>
  <div class="mt-5 pt-5 border-t border-white/5 grid sm:grid-cols-<?= $is_ent ? '3' : '2' ?> gap-4 text-xs text-slate-400">
    <div><div class="flex justify-between mb-1"><span>Leads unlocked</span><span class="text-white font-semibold"><?= $lead_limit===-1 ? 'Unlimited' : '0 / '.$lead_limit ?></span></div></div>
    <div><div class="flex justify-between mb-1"><span>Active websites</span><span class="text-white font-semibold"><?= $site_limit===-1 ? 'Unlimited' : '0 / '.$site_limit ?></span></div></div>
    <?php if ($is_ent): ?>
    <div><div class="flex justify-between mb-1"><span>Team seats</span><span class="text-amber-400 font-semibold"><?= $seats>0 ? $seats : 'N/A' ?></span></div></div>
    <?php endif; ?>
  </div>
  <?php if ($is_ent && $is_active): ?>
  <div class="mt-4 pt-4 border-t border-white/5 grid sm:grid-cols-2 gap-4 text-xs text-slate-400">
    <div class="flex justify-between"><span>Custom domains</span><span class="text-amber-400 font-semibold">Unlimited</span></div>
    <div class="flex justify-between"><span>Client reports</span><span class="text-amber-400 font-semibold">Included</span></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($is_paid && $is_active): ?>
  <div class="mt-5 pt-5 border-t border-white/5">
    <form method="POST" onsubmit="return confirm('Cancel your subscription? You will lose access at the end of the billing period.');">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="text-sm text-red-400 hover:text-red-300 transition">
        <i class="fa-solid fa-xmark mr-1"></i>Cancel Subscription
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- ===== PRO -> ENTREPRENEUR UPSELL ===== -->
<?php if ($is_pro && $is_active): ?>
<div class="rounded-2xl overflow-hidden mb-6 ent-card-border" style="background:linear-gradient(135deg,#0f0f1a 0%,#1a1205 100%)">
  <div class="px-6 py-5 flex flex-col sm:flex-row sm:items-center justify-between gap-5">
    <div>
      <div class="flex items-center gap-2 mb-2">
        <i class="fa-solid fa-rocket text-amber-400 text-sm"></i>
        <span class="text-xs font-bold uppercase tracking-widest text-amber-400">Upgrade to Entrepreneur</span>
      </div>
      <p class="text-white font-bold text-base">Unlock everything Pro can&rsquo;t do</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <span class="pill-feature"><i class="fa-solid fa-infinity"></i>Unlimited leads</span>
        <span class="pill-feature"><i class="fa-solid fa-globe"></i>Custom domains</span>
        <span class="pill-feature"><i class="fa-solid fa-users"></i><?= $_ent_seats ?> team seats</span>
        <span class="pill-feature"><i class="fa-solid fa-file-chart-column"></i>Client reports</span>
        <span class="pill-feature"><i class="fa-solid fa-server"></i><?= $_ent_sites ?> active sites</span>
      </div>
    </div>
    <a href="?plan=entrepreneur" class="shrink-0 text-sm font-bold px-8 py-3.5 rounded-xl text-black whitespace-nowrap ent-glow-btn">
      <i class="fa-solid fa-rocket mr-2"></i>Upgrade &rarr; $<?= $_ent_price_fmt ?>/mo
    </a>
  </div>
</div>
<?php endif; ?>

<!-- ===== UPGRADE SECTION (free / cancelled users always see this) ===== -->
<?php if (!$is_paid || $is_cancelled): ?>

<!-- Plan tabs -->
<div class="flex gap-2 mb-6">
  <a href="?plan=pro"
     class="px-5 py-2 rounded-full text-sm font-bold transition <?= $_target_plan==='pro' ? 'bg-white text-black' : 'bg-white/8 text-slate-300 hover:bg-white/15' ?>">
    <i class="fa-solid fa-crown mr-1 text-xs"></i>Pro &mdash; $<?= $_pro_price_fmt ?>/mo
  </a>
  <a href="?plan=entrepreneur"
     class="px-5 py-2 rounded-full text-sm font-bold transition <?= $_target_plan==='entrepreneur' ? 'ent-pill-border text-amber-300' : 'bg-white/8 text-slate-300 hover:bg-white/15' ?>">
    <i class="fa-solid fa-rocket mr-1 text-xs"></i>Entrepreneur &mdash; $<?= $_ent_price_fmt ?>/mo
  </a>
</div>

<?php if ($_target_plan === 'entrepreneur'): ?>
<!-- ===== ENTREPRENEUR CARD ===== -->
<div class="rounded-2xl overflow-hidden mb-6 ent-card-border" style="background:linear-gradient(160deg,#0a0a12 0%,#110d05 55%,#180c00 100%)">

  <!-- Glowing hero header -->
  <div class="relative px-6 pt-8 pb-6 border-b border-white/5 overflow-hidden">
    <!-- subtle radial glow background -->
    <div class="absolute inset-0 pointer-events-none" style="background:radial-gradient(ellipse 60% 80% at 80% 20%,rgba(245,158,11,.12) 0%,transparent 70%)"></div>
    <div class="relative">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black bg-amber-500/20 border border-amber-500/40">
              <i class="fa-solid fa-rocket text-amber-400 text-xs"></i>
              <span class="ent-badge tracking-widest">BEST VALUE</span>
            </span>
            <span class="text-xs px-2.5 py-1 rounded-full bg-green-500/15 border border-green-500/25 text-green-400 font-bold">Most Popular</span>
          </div>
          <h2 class="text-4xl font-black mb-1">$<?= $_ent_price_fmt ?><span class="text-lg text-slate-400 font-normal ml-1">/ month</span></h2>
          <p class="text-slate-400 text-sm">Built for agencies &amp; founders running multiple clients at scale.</p>
        </div>
        <div class="text-right text-xs text-slate-500 space-y-1">
          <p><i class="fa-solid fa-rotate mr-1"></i>Billed monthly</p>
          <p><i class="fa-solid fa-xmark mr-1 text-red-400/60"></i>Cancel any time</p>
          <p><i class="fa-solid fa-shield-halved mr-1 text-amber-400/60"></i>No lock-in</p>
        </div>
      </div>
      <!-- Feature pills -->
      <div class="mt-5 flex flex-wrap gap-2">
        <span class="pill-feature"><i class="fa-solid fa-infinity"></i>Unlimited leads</span>
        <span class="pill-feature"><i class="fa-solid fa-globe"></i>Custom domains</span>
        <span class="pill-feature"><i class="fa-solid fa-users"></i><?= $_ent_seats ?> team seats</span>
        <span class="pill-feature"><i class="fa-solid fa-file-chart-column"></i>Client reports</span>
        <span class="pill-feature"><i class="fa-solid fa-server"></i><?= $_ent_sites ?> sites</span>
        <span class="pill-feature"><i class="fa-solid fa-headset"></i>Priority support</span>
      </div>
    </div>
  </div>

  <!-- Plan comparison table -->
  <div class="px-6 py-5 border-b border-white/5 overflow-x-auto">
    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4">What you unlock vs Pro</p>
    <table class="w-full text-sm min-w-[380px]">
      <thead>
        <tr class="text-xs">
          <th class="text-left text-slate-500 font-semibold pb-3 pr-4">Feature</th>
          <th class="text-center text-slate-500 font-semibold pb-3 px-4">Pro</th>
          <th class="text-center pb-3 px-4 rounded-t-lg ent-col-highlight">
            <span class="ent-badge font-black text-xs">Entrepreneur</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php
        $rows = [
          ['Leads / period',    number_format($_pro_leads),                             '<i class="fa-solid fa-infinity compare-ent text-lg"></i>'],
          ['Active websites',   $_pro_sites.' sites',                                   '<span class="text-amber-400 font-bold">'.$_ent_sites.' sites</span>'],
          ['Custom domains',    '<i class="fa-solid fa-xmark compare-cross text-lg"></i>', '<i class="fa-solid fa-check compare-ent text-lg"></i>'],
          ['Client reports',    '<i class="fa-solid fa-xmark compare-cross text-lg"></i>', '<i class="fa-solid fa-check compare-ent text-lg"></i>'],
          ['Team seats',        '<i class="fa-solid fa-xmark compare-cross text-lg"></i>', '<span class="text-amber-400 font-bold">'.$_ent_seats.' seats</span>'],
          ['Revenue dashboard', '<i class="fa-solid fa-check compare-check text-lg"></i>', '<i class="fa-solid fa-check compare-ent text-lg"></i>'],
          ['ZIP export',        '<i class="fa-solid fa-check compare-check text-lg"></i>', '<i class="fa-solid fa-check compare-ent text-lg"></i>'],
          ['Priority support',  '<i class="fa-solid fa-check compare-check text-lg"></i>', '<i class="fa-solid fa-check compare-ent text-lg"></i>'],
          ['Monthly price',     '<span class="text-slate-400">$'.$_pro_price_fmt.'</span>',  '<span class="font-black text-amber-400">$'.$_ent_price_fmt.'</span>'],
        ];
        foreach ($rows as $i => [$feature, $pro_val, $ent_val]): ?>
        <tr class="<?= $i%2===0 ? '' : 'bg-white/2' ?>">
          <td class="py-2.5 pr-4 text-slate-300 font-medium text-xs"><?= $feature ?></td>
          <td class="py-2.5 px-4 text-center text-slate-400"><?= $pro_val ?></td>
          <td class="py-2.5 px-4 text-center ent-col-highlight <?= $i===count($rows)-1 ? 'rounded-b-lg' : '' ?>"><?= $ent_val ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Social proof strip -->
  <div class="px-6 py-3.5 border-b border-white/5 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-slate-500">
    <span><i class="fa-solid fa-star text-amber-400/70 mr-1"></i>Trusted by 200+ agencies</span>
    <span><i class="fa-solid fa-bolt text-amber-400/70 mr-1"></i>Instant activation</span>
    <span><i class="fa-solid fa-shield-halved text-amber-400/70 mr-1"></i>Cancel any time</span>
    <span><i class="fa-solid fa-headset text-amber-400/70 mr-1"></i>Priority support</span>
  </div>

  <!-- Payment form -->
  <div class="px-6 py-6">
    <div class="flex items-center gap-2 bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-2.5 mb-5 text-xs text-amber-400">
      <i class="fa-solid fa-flask"></i>
      <span><strong>Test Mode</strong> &mdash; No real card needed. Any 12+ digit number works.</span>
    </div>
    <form method="POST" class="space-y-4" id="entForm">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action"       value="test_subscribe">
      <input type="hidden" name="subscribe_plan" value="entrepreneur">
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Card Number</label>
        <div class="relative">
          <input type="text" name="card_number" id="cardNumberInputEnt" inputmode="numeric"
            placeholder="4242 4242 4242 4242" maxlength="19" required autocomplete="cc-number"
            class="w-full bg-slate-800/80 border border-slate-700 text-white placeholder-slate-500 rounded-xl pl-4 pr-12 py-3 tracking-wider focus:outline-none focus:border-amber-500/60 focus:ring-1 focus:ring-amber-500/20 transition">
          <i class="fa-solid fa-credit-card absolute right-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Expiry</label>
          <input type="text" name="card_expiry" id="cardExpiryInputEnt" inputmode="numeric"
            placeholder="MM/YY" maxlength="5" required autocomplete="cc-exp"
            class="w-full bg-slate-800/80 border border-slate-700 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-amber-500/60 focus:ring-1 focus:ring-amber-500/20 transition">
        </div>
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">CVC</label>
          <input type="text" name="card_cvc" id="cardCvcInputEnt" inputmode="numeric"
            placeholder="123" maxlength="4" required autocomplete="cc-csc"
            class="w-full bg-slate-800/80 border border-slate-700 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-amber-500/60 focus:ring-1 focus:ring-amber-500/20 transition">
        </div>
      </div>
      <button type="submit" id="entSubmitBtn" class="w-full ent-glow-btn text-black py-4 rounded-xl font-black text-base tracking-wide">
        <i class="fa-solid fa-rocket mr-2"></i>Unlock Entrepreneur &mdash; $<?= $_ent_price_fmt ?>/mo
      </button>
      <div class="flex items-center justify-center gap-2 text-xs text-slate-500">
        <i class="fa-solid fa-lock"></i><span>Secured by</span><i class="fa-brands fa-stripe text-xl text-slate-300"></i>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ===== PRO CARD ===== -->
<div class="rounded-2xl border border-white/10 overflow-hidden mb-6" style="background:linear-gradient(135deg,#0f0f0f 0%,#1c1c1c 100%)">
  <div class="px-6 py-6">
    <div class="flex items-start justify-between flex-wrap gap-4 mb-6">
      <div>
        <div class="flex items-center gap-2 mb-1">
          <i class="fa-solid fa-crown text-white"></i>
          <span class="text-xs font-bold text-white uppercase tracking-widest">Pro Plan</span>
        </div>
        <p class="text-3xl font-black">$<?= $_pro_price_fmt ?> <span class="text-sm text-slate-400 font-normal">/ month</span></p>
        <p class="text-slate-400 text-sm mt-1">Everything you need to run a full client-getting operation.</p>
      </div>
      <div class="space-y-2 text-sm">
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-green-400 w-4"></i><?= $_pro_leads ?> leads / period</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-green-400 w-4"></i><?= $_pro_sites ?> active websites</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-green-400 w-4"></i>Full phone numbers</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-green-400 w-4"></i>All templates + ZIP export</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-green-400 w-4"></i>Revenue dashboard</div>
        <div class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-green-400 w-4"></i>Priority support</div>
      </div>
    </div>
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
            placeholder="4242 4242 4242 4242" maxlength="19" required autocomplete="cc-number"
            class="w-full bg-slate-800/80 border border-slate-700 text-white placeholder-slate-500 rounded-xl pl-4 pr-12 py-3 tracking-wider focus:outline-none focus:border-white/50 focus:ring-1 focus:ring-white/10 transition">
          <i class="fa-solid fa-credit-card absolute right-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Expiry</label>
          <input type="text" id="cardExpiryInput" name="card_expiry" inputmode="numeric"
            placeholder="MM/YY" maxlength="5" required autocomplete="cc-exp"
            class="w-full bg-slate-800/80 border border-slate-700 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/50 transition">
        </div>
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">CVC</label>
          <input type="text" id="cardCvcInput" name="card_cvc" inputmode="numeric"
            placeholder="123" maxlength="4" required autocomplete="cc-csc"
            class="w-full bg-slate-800/80 border border-slate-700 text-white placeholder-slate-500 rounded-xl px-4 py-3 focus:outline-none focus:border-white/50 transition">
        </div>
      </div>
      <button type="submit"
              class="w-full bg-white hover:bg-slate-100 active:scale-95 text-black py-4 rounded-xl font-black transition-all shadow-lg text-base">
        <i class="fa-solid fa-lock mr-2"></i>Subscribe to Pro &mdash; $<?= $_pro_price_fmt ?>/mo
      </button>
      <div class="flex items-center justify-center gap-2 text-xs text-slate-500">
        <i class="fa-solid fa-lock"></i><span>Secured by</span><i class="fa-brands fa-stripe text-xl text-slate-300"></i>
      </div>
    </form>
  </div>
</div>

<div class="text-center text-xs text-slate-500 mb-6">
  Want unlimited leads, custom domains &amp; team seats?
  <a href="?plan=entrepreneur" class="text-amber-400 hover:text-amber-300 font-semibold transition ml-1">
    See Entrepreneur <i class="fa-solid fa-arrow-right text-[10px]"></i>
  </a>
</div>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/portal_layout_end.php'; ?>
<script src="/assets/js/billing_card.js?v=v312"></script>
