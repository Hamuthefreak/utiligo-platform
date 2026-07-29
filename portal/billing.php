<?php
// TEMPORARY DEBUG — remove after error is identified
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Read plan from GET or POST (so errors re-render the right card)
$_target_plan = 'pro';
if (isset($_GET['plan']) && $_GET['plan'] === 'entrepreneur')                            $_target_plan = 'entrepreneur';
elseif (isset($_POST['subscribe_plan']) && $_POST['subscribe_plan'] === 'entrepreneur') $_target_plan = 'entrepreneur';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh the page and try again.';
    } elseif ($_POST['action'] === 'test_subscribe') {
        $subscribePlan = in_array($_POST['subscribe_plan'] ?? '', ['pro','entrepreneur']) ? $_POST['subscribe_plan'] : 'pro';
        $cardNumber    = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
        $cardExpiry    = preg_replace('/\s/', '', trim($_POST['card_expiry'] ?? ''));
        $cardCvc       = preg_replace('/\D/', '', $_POST['card_cvc'] ?? '');
        if (strlen($cardNumber) < 12) {
            $error = 'Please enter a valid card number.';
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
            header('Location: /portal/index?upgraded=1'); exit;
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

if (isset($_GET['cancelled'])) $message = 'Checkout cancelled — you were not charged.';

$pageTitle = 'Billing — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
@keyframes ent-shimmer{0%{background-position:-200% center}100%{background-position:200% center}}
.ent-badge{background:linear-gradient(90deg,#f59e0b,#fbbf24,#f59e0b);background-size:200% auto;animation:ent-shimmer 2.5s linear infinite;-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.ent-glow-btn{background:linear-gradient(135deg,#f59e0b 0%,#f97316 60%,#ef4444 100%);box-shadow:0 4px 24px rgba(245,158,11,.4);transition:all .2s}
.ent-glow-btn:hover{box-shadow:0 8px 40px rgba(245,158,11,.65);transform:translateY(-2px)}
.ent-glow-btn:active{transform:scale(.97)}
.ent-card-wrap{background:linear-gradient(#0d0d14,#0d0d14) padding-box,linear-gradient(135deg,#f59e0b66,#f9731633,transparent) border-box;border:1.5px solid transparent}
.pro-card-wrap{background:linear-gradient(#0d0d0d,#0d0d0d) padding-box,linear-gradient(135deg,#ffffff22,#ffffff08,transparent) border-box;border:1.5px solid transparent}
.pill-feature{display:inline-flex;align-items:center;gap:.35rem;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);color:#fcd34d;border-radius:9999px;padding:.3rem .75rem;font-size:.7rem;font-weight:700}
.card-input{width:100%;background:rgba(15,23,42,.7);border:1.5px solid rgba(255,255,255,.1);color:#fff;border-radius:.875rem;padding:.875rem 1rem;font-size:.95rem;outline:none;transition:border-color .2s,box-shadow .2s}
.card-input::placeholder{color:rgba(148,163,184,.5)}
.card-input:focus{border-color:rgba(255,255,255,.35);box-shadow:0 0 0 3px rgba(255,255,255,.06)}
.card-input-ent:focus{border-color:rgba(245,158,11,.5);box-shadow:0 0 0 3px rgba(245,158,11,.08)}
.input-label{display:block;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.45rem;color:rgba(148,163,184,.8)}
.trust-row{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem .75rem;font-size:.7rem;color:rgba(100,116,139,.8)}
.plan-tab{padding:.5rem 1.25rem;border-radius:9999px;font-size:.8rem;font-weight:700;transition:all .2s;cursor:pointer;text-decoration:none}
.plan-tab-active{background:#fff;color:#000}
.plan-tab-inactive{background:rgba(255,255,255,.07);color:rgba(148,163,184,.9)}
.plan-tab-inactive:hover{background:rgba(255,255,255,.12)}
.plan-tab-ent-active{background:linear-gradient(135deg,#f59e0b,#f97316);color:#000;box-shadow:0 2px 12px rgba(245,158,11,.3)}
.compare-check{color:#22c55e}.compare-cross{color:#1e293b}.compare-ent{color:#f59e0b}
.ent-col{background:rgba(245,158,11,.05)}
</style>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Billing</h1>
  <p class="text-slate-400 text-sm mt-1">Manage your Utiligo subscription and plan.</p>
</div>

<?php if ($message): ?>
<div class="flex items-center gap-3 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check shrink-0"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- CURRENT PLAN -->
<div class="glass rounded-2xl p-6 border border-white/5 mb-6">
  <div class="flex items-center justify-between flex-wrap gap-4">
    <div class="flex items-center gap-4">
      <div class="w-11 h-11 rounded-xl <?= $is_ent ? 'bg-amber-500/15 border border-amber-500/30' : ($is_paid ? 'bg-white/10 border border-white/10' : 'bg-white/5') ?> flex items-center justify-center shrink-0">
        <i class="fa-solid fa-<?= $is_ent ? 'rocket' : ($is_pro ? 'crown' : 'user') ?> <?= $is_ent ? 'text-amber-400' : ($is_paid ? 'text-white' : 'text-slate-500') ?>"></i>
      </div>
      <div>
        <p class="font-bold"><?php
          if ($is_ent)     echo '<span class="ent-badge text-base">Utiligo Entrepreneur</span>';
          elseif ($is_pro) echo 'Utiligo Pro';
          else             echo 'Free Plan';
        ?></p>
        <p class="text-slate-400 text-xs mt-0.5"><?php
          if ($is_ent && $is_active)         echo '$'.$_ent_price_fmt.'/mo &mdash; <span class="text-emerald-400 font-semibold">Active</span>';
          elseif ($is_ent && $is_cancelled)  echo 'Cancelled &mdash; active until end of period';
          elseif ($is_pro && $is_active)     echo '$'.$_pro_price_fmt.'/mo &mdash; <span class="text-emerald-400 font-semibold">Active</span>';
          elseif ($is_pro && $is_cancelled)  echo 'Cancelled &mdash; active until end of period';
          else echo $_free_leads.' leads &bull; '.$_free_sites.' site/day &bull; 2 templates';
        ?></p>
      </div>
    </div>
    <span class="text-xs px-3 py-1 rounded-full font-bold <?=
      ($is_ent && $is_active)  ? 'bg-amber-500/15 text-amber-400 border border-amber-500/25' :
      ($is_pro && $is_active)  ? 'bg-white/10 text-white border border-white/10' :
      ($is_cancelled           ? 'bg-amber-500/15 text-amber-400' : 'bg-white/5 text-slate-500') ?>">
      <?= ($is_ent && $is_active) ? '🚀 Entrepreneur' : (($is_pro && $is_active) ? 'Pro' : ($is_cancelled ? 'Cancelled' : 'Free')) ?>
    </span>
  </div>
  <?php if ($is_paid && $is_active): ?>
  <div class="mt-5 pt-4 border-t border-white/5">
    <form method="POST" action="/portal/billing" onsubmit="return confirm('Cancel your subscription?');">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="text-xs text-red-400/70 hover:text-red-400 transition">
        <i class="fa-solid fa-xmark mr-1"></i>Cancel subscription
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- PRO -> ENT UPSELL BAR -->
<?php if ($is_pro && $is_active): ?>
<div class="rounded-2xl ent-card-wrap overflow-hidden mb-6" style="background:linear-gradient(135deg,#0f0f1a,#140f05)">
  <div class="px-6 py-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
      <p class="text-xs font-bold uppercase tracking-widest text-amber-400 mb-1"><i class="fa-solid fa-rocket mr-1"></i>Upgrade to Entrepreneur</p>
      <p class="text-white font-semibold text-sm">Unlimited leads, custom domains, team seats &amp; client reports</p>
    </div>
    <a href="/portal/billing?plan=entrepreneur" class="shrink-0 ent-glow-btn text-black text-sm font-black px-7 py-3 rounded-xl whitespace-nowrap">
      Upgrade &rarr; $<?= $_ent_price_fmt ?>/mo
    </a>
  </div>
</div>
<?php endif; ?>

<!-- UPGRADE SECTION -->
<?php if (!$is_paid || $is_cancelled): ?>

<div class="flex gap-2 mb-5">
  <a href="/portal/billing?plan=pro" class="plan-tab <?= $_target_plan==='pro' ? 'plan-tab-active' : 'plan-tab-inactive' ?>">
    <i class="fa-solid fa-crown mr-1.5 text-xs"></i>Pro &mdash; $<?= $_pro_price_fmt ?>/mo
  </a>
  <a href="/portal/billing?plan=entrepreneur" class="plan-tab <?= $_target_plan==='entrepreneur' ? 'plan-tab-ent-active' : 'plan-tab-inactive' ?>">
    <i class="fa-solid fa-rocket mr-1.5 text-xs"></i>Entrepreneur &mdash; $<?= $_ent_price_fmt ?>/mo
  </a>
</div>

<?php if ($_target_plan === 'entrepreneur'): ?>

<!-- ENTREPRENEUR PAYMENT CARD -->
<div class="rounded-2xl ent-card-wrap overflow-hidden mb-6" style="background:linear-gradient(155deg,#08080f 0%,#0f0a02 60%,#160b00 100%)">

  <div class="relative px-7 pt-8 pb-7 border-b border-white/5 overflow-hidden">
    <div class="absolute inset-0 pointer-events-none" style="background:radial-gradient(ellipse 70% 90% at 85% 10%,rgba(245,158,11,.13) 0%,transparent 65%)"></div>
    <div class="relative flex flex-wrap gap-6 items-start justify-between">
      <div>
        <div class="flex flex-wrap items-center gap-2 mb-4">
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black bg-amber-500/20 border border-amber-500/35">
            <i class="fa-solid fa-rocket text-amber-400 text-[10px]"></i>
            <span class="ent-badge tracking-widest">BEST VALUE</span>
          </span>
          <span class="text-[11px] px-2.5 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/25 text-emerald-400 font-bold">Most Popular</span>
        </div>
        <div class="flex items-end gap-2 mb-2">
          <span class="text-5xl font-black tracking-tight">$<?= $_ent_price_fmt ?></span>
          <span class="text-slate-400 text-sm mb-2">/ month</span>
        </div>
        <p class="text-slate-400 text-sm">Built for agencies running multiple clients at scale.</p>
      </div>
      <div class="text-xs text-slate-500 space-y-1.5">
        <p><i class="fa-solid fa-rotate text-slate-600 mr-1.5"></i>Billed monthly</p>
        <p><i class="fa-solid fa-ban text-slate-600 mr-1.5"></i>Cancel any time</p>
        <p><i class="fa-solid fa-shield-halved text-amber-500/50 mr-1.5"></i>No lock-in</p>
      </div>
    </div>
    <div class="mt-5 flex flex-wrap gap-1.5">
      <span class="pill-feature"><i class="fa-solid fa-infinity"></i>Unlimited leads</span>
      <span class="pill-feature"><i class="fa-solid fa-globe"></i>Custom domains</span>
      <span class="pill-feature"><i class="fa-solid fa-users"></i><?= $_ent_seats ?> team seats</span>
      <span class="pill-feature"><i class="fa-solid fa-file-chart-column"></i>Client reports</span>
      <span class="pill-feature"><i class="fa-solid fa-server"></i><?= $_ent_sites ?> sites</span>
      <span class="pill-feature"><i class="fa-solid fa-headset"></i>Priority support</span>
    </div>
  </div>

  <div class="px-7 py-5 border-b border-white/5 overflow-x-auto">
    <p class="text-[10px] font-bold text-slate-600 uppercase tracking-widest mb-3">Entrepreneur vs Pro</p>
    <table class="w-full text-xs min-w-[340px]">
      <thead><tr>
        <th class="text-left text-slate-500 font-semibold pb-2.5 pr-4">Feature</th>
        <th class="text-center text-slate-500 font-semibold pb-2.5 px-3">Pro</th>
        <th class="text-center pb-2.5 px-3 ent-col rounded-t-lg"><span class="ent-badge font-black">Entrepreneur</span></th>
      </tr></thead>
      <tbody>
      <?php $rows=[
        ['Leads','<span class="text-slate-400">'.number_format($_pro_leads).'</span>','<i class="fa-solid fa-infinity compare-ent"></i>'],
        ['Active sites','<span class="text-slate-500">'.$_pro_sites.'</span>','<span class="text-amber-400 font-bold">'.$_ent_sites.'</span>'],
        ['Custom domains','<i class="fa-solid fa-xmark compare-cross"></i>','<i class="fa-solid fa-check compare-ent"></i>'],
        ['Client reports','<i class="fa-solid fa-xmark compare-cross"></i>','<i class="fa-solid fa-check compare-ent"></i>'],
        ['Team seats','<i class="fa-solid fa-xmark compare-cross"></i>','<span class="text-amber-400 font-bold">'.$_ent_seats.' seats</span>'],
        ['Revenue dash','<i class="fa-solid fa-check compare-check"></i>','<i class="fa-solid fa-check compare-ent"></i>'],
        ['Price/mo','<span class="text-slate-400">$'.$_pro_price_fmt.'</span>','<span class="font-black text-amber-400">$'.$_ent_price_fmt.'</span>'],
      ];
      foreach($rows as $i=>[$f,$p,$e]):?>
      <tr class="<?=$i%2?'bg-white/[.02]':''?>">
        <td class="py-2 pr-4 text-slate-400"><?=$f?></td>
        <td class="py-2 px-3 text-center text-slate-400"><?=$p?></td>
        <td class="py-2 px-3 text-center ent-col <?=$i===count($rows)-1?'rounded-b-lg':''?>"><?=$e?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>

  <div class="px-7 py-3 border-b border-white/5 trust-row">
    <span><i class="fa-solid fa-star text-amber-500/60 mr-1"></i>200+ agencies</span>
    <span><i class="fa-solid fa-bolt text-amber-500/60 mr-1"></i>Instant activation</span>
    <span><i class="fa-solid fa-shield-halved text-amber-500/60 mr-1"></i>Cancel any time</span>
    <span><i class="fa-solid fa-headset text-amber-500/60 mr-1"></i>Priority support</span>
  </div>

  <div class="px-7 py-7">
    <div class="flex items-center gap-2 bg-amber-500/8 border border-amber-500/18 rounded-xl px-4 py-2.5 mb-6 text-xs text-amber-400/80">
      <i class="fa-solid fa-flask text-amber-500/70"></i>
      <span><strong class="text-amber-400">Test mode</strong> &mdash; any 12-digit number works, no real charge.</span>
    </div>
    <form method="POST" action="/portal/billing?plan=entrepreneur" class="space-y-4" id="entForm">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="test_subscribe">
      <input type="hidden" name="subscribe_plan" value="entrepreneur">
      <div>
        <label class="input-label text-slate-500" for="cardNumberInputEnt">Card number</label>
        <div class="relative">
          <input type="text" name="card_number" id="cardNumberInputEnt" inputmode="numeric"
            placeholder="1234 5678 9012 3456" maxlength="19" required autocomplete="cc-number"
            class="card-input card-input-ent pr-14">
          <span id="cardBrandIconEnt" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 text-lg pointer-events-none">
            <i class="fa-regular fa-credit-card"></i>
          </span>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="input-label text-slate-500" for="cardExpiryInputEnt">Expiry</label>
          <input type="text" name="card_expiry" id="cardExpiryInputEnt" inputmode="numeric"
            placeholder="MM / YY" maxlength="7" required autocomplete="cc-exp"
            class="card-input card-input-ent">
        </div>
        <div>
          <label class="input-label text-slate-500" for="cardCvcInputEnt">CVC</label>
          <div class="relative">
            <input type="text" name="card_cvc" id="cardCvcInputEnt" inputmode="numeric"
              placeholder="123" maxlength="4" required autocomplete="cc-csc"
              class="card-input card-input-ent pr-10">
            <i class="fa-solid fa-lock absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-600 text-xs pointer-events-none"></i>
          </div>
        </div>
      </div>
      <button type="submit" class="w-full ent-glow-btn text-black py-4 rounded-xl font-black text-base mt-1">
        <i class="fa-solid fa-rocket mr-2"></i>Unlock Entrepreneur &mdash; $<?= $_ent_price_fmt ?>/mo
      </button>
      <div class="trust-row justify-center pt-1">
        <i class="fa-solid fa-lock"></i><span>Secured by</span>
        <i class="fa-brands fa-stripe text-lg text-slate-400"></i>
        <span class="mx-1 text-slate-700">·</span>
        <i class="fa-solid fa-shield-halved text-slate-600"></i><span>256-bit SSL</span>
        <span class="mx-1 text-slate-700">·</span>
        <span>Cancel any time</span>
      </div>
    </form>
  </div>
</div>

<?php else: ?>

<!-- PRO PAYMENT CARD -->
<div class="rounded-2xl pro-card-wrap overflow-hidden mb-6" style="background:linear-gradient(155deg,#0a0a0a 0%,#111 100%)">
  <div class="px-7 pt-8 pb-7 border-b border-white/5">
    <div class="flex flex-wrap gap-6 items-start justify-between">
      <div>
        <div class="flex items-center gap-2 mb-4">
          <i class="fa-solid fa-crown text-white/80 text-sm"></i>
          <span class="text-xs font-black uppercase tracking-widest text-white/60">Pro Plan</span>
        </div>
        <div class="flex items-end gap-2 mb-2">
          <span class="text-5xl font-black tracking-tight">$<?= $_pro_price_fmt ?></span>
          <span class="text-slate-400 text-sm mb-2">/ month</span>
        </div>
        <p class="text-slate-400 text-sm">Everything you need to run a full client-getting operation.</p>
      </div>
      <ul class="space-y-2 text-sm">
        <li class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-3.5 shrink-0"></i><?= number_format($_pro_leads) ?> leads / period</li>
        <li class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-3.5 shrink-0"></i><?= $_pro_sites ?> active websites</li>
        <li class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-3.5 shrink-0"></i>Full phone numbers</li>
        <li class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-3.5 shrink-0"></i>All templates + ZIP export</li>
        <li class="flex items-center gap-2 text-slate-300"><i class="fa-solid fa-check text-emerald-400 w-3.5 shrink-0"></i>Revenue dashboard</li>
      </ul>
    </div>
  </div>
  <div class="px-7 py-7">
    <div class="flex items-center gap-2 bg-amber-500/8 border border-amber-500/18 rounded-xl px-4 py-2.5 mb-6 text-xs text-amber-400/80">
      <i class="fa-solid fa-flask text-amber-500/70"></i>
      <span><strong class="text-amber-400">Test mode</strong> &mdash; any 12-digit number works, no real charge.</span>
    </div>
    <form method="POST" action="/portal/billing?plan=pro" class="space-y-4" id="billingForm">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="test_subscribe">
      <input type="hidden" name="subscribe_plan" value="pro">
      <div>
        <label class="input-label text-slate-500" for="cardNumberInput">Card number</label>
        <div class="relative">
          <input type="text" name="card_number" id="cardNumberInput" inputmode="numeric"
            placeholder="1234 5678 9012 3456" maxlength="19" required autocomplete="cc-number"
            class="card-input pr-14">
          <span id="cardBrandIconPro" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 text-lg pointer-events-none">
            <i class="fa-regular fa-credit-card"></i>
          </span>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="input-label text-slate-500" for="cardExpiryInput">Expiry</label>
          <input type="text" name="card_expiry" id="cardExpiryInput" inputmode="numeric"
            placeholder="MM / YY" maxlength="7" required autocomplete="cc-exp"
            class="card-input">
        </div>
        <div>
          <label class="input-label text-slate-500" for="cardCvcInput">CVC</label>
          <div class="relative">
            <input type="text" name="card_cvc" id="cardCvcInput" inputmode="numeric"
              placeholder="123" maxlength="4" required autocomplete="cc-csc"
              class="card-input pr-10">
            <i class="fa-solid fa-lock absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-600 text-xs pointer-events-none"></i>
          </div>
        </div>
      </div>
      <button type="submit"
        class="w-full bg-white hover:bg-slate-100 active:scale-[.98] text-black py-4 rounded-xl font-black text-base shadow-lg shadow-white/5 transition-all mt-1">
        <i class="fa-solid fa-lock mr-2 text-sm"></i>Subscribe to Pro &mdash; $<?= $_pro_price_fmt ?>/mo
      </button>
      <div class="trust-row justify-center pt-1">
        <i class="fa-solid fa-lock"></i><span>Secured by</span>
        <i class="fa-brands fa-stripe text-lg text-slate-400"></i>
        <span class="mx-1 text-slate-700">·</span>
        <i class="fa-solid fa-shield-halved text-slate-600"></i><span>256-bit SSL</span>
        <span class="mx-1 text-slate-700">·</span>
        <span>Cancel any time</span>
      </div>
    </form>
  </div>
</div>

<div class="text-center text-xs text-slate-500 mb-6">
  Want unlimited leads, custom domains &amp; team seats?
  <a href="/portal/billing?plan=entrepreneur" class="text-amber-400 hover:text-amber-300 font-semibold ml-1 transition">
    See Entrepreneur plan <i class="fa-solid fa-arrow-right text-[10px]"></i>
  </a>
</div>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/portal_layout_end.php'; ?>
<script src="/assets/js/billing_card.js?v=v315"></script>
