<?php
require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/entitlements.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
// For whop_manage_url()/whop_log(), and for the state read below: who is actually
// billing this account decides which cancel control the page may show.
require_once __DIR__ . '/../includes/whop.php';

require_login();
$user    = current_user();
$message = '';
$error   = '';

/**
 * Is manual activation available on this deployment?
 *
 * The billing page used to render a card form that activated a plan with no
 * payment behind it, under a banner reading "any 12-digit number works, no real
 * charge". In a real browser that is a free-plan button: a free account could
 * click "Subscribe to Pro", type twelve digits, and have the product.
 *
 * So the form needs BOTH of two independent conditions, and either one alone is
 * enough to refuse it:
 *
 *   TEST_PAYMENT_MODE   the product's own flag, which an admin turns on for
 *                       testing. It defaults to OFF now — a flag that gives away
 *                       paid plans should not ship enabled.
 *   APP_ENV             and never in production, whatever the flag says. A
 *                       mis-set flag is exactly the kind of mistake that costs
 *                       money, so it is not allowed to be the only gate.
 *
 * The POST handler below checks the same value, because a hand-crafted request
 * must not be worth more than the UI. */
$canActivateLocally = (defined('TEST_PAYMENT_MODE') ? (bool)TEST_PAYMENT_MODE : false)
    && !(defined('APP_ENV') && APP_ENV === 'production');
$_whop_manage_url = '';   // set only by a refused cancel, and rendered as a link beside it

$_target_plan = 'pro';
if (isset($_GET['plan']) && $_GET['plan'] === 'entrepreneur')                            $_target_plan = 'entrepreneur';
elseif (isset($_POST['subscribe_plan']) && $_POST['subscribe_plan'] === 'entrepreneur') $_target_plan = 'entrepreneur';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh the page and try again.';
    } elseif ($_POST['action'] === 'test_subscribe' && $canActivateLocally) {
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
            try {
                // Test-mode activation. There is no Stripe event behind this, so
                // it is applied as a local change: immediate, downgrade allowed
                // (this is the plan-switch path), and it still stamps the
                // ordering clock so a stale Stripe event cannot overturn it.
                entitlement_set_plan_local((int)$user['id'], $subscribePlan, [
                    'source' => 'billing.test_subscribe',
                ]);

                $listIds = [BREVO_LIST_ALL_USERS, BREVO_LIST_PRO_USERS];
                brevo_upsert_contact($user['email'], ['FIRSTNAME' => $user['full_name']], $listIds);
                send_welcome_email($user['email'], $user['full_name']);
                header('Location: /portal/index?upgraded=1'); exit;
            } catch (\Throwable $ex) {
                error_log('[billing] test_subscribe failed: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
                $error = 'Something went wrong while activating your plan. Please try again or contact support.';
            }
        }
    } elseif ($_POST['action'] === 'test_subscribe') {
        // Refused, and the form that would send it is not rendered either. This
        // branch grants a paid plan with no payment behind it, so both halves are
        // gated on the same value — see $canActivateLocally above.
        error_log('[billing] refused a test_subscribe POST: manual activation is off (test mode '
            . (defined('TEST_PAYMENT_MODE') && TEST_PAYMENT_MODE ? 'on' : 'off')
            . ', APP_ENV ' . (defined('APP_ENV') ? APP_ENV : 'unset') . ')');
        $error = 'Card payments are handled by Whop — use the subscribe button to continue.';
    } elseif ($_POST['action'] === 'cancel') {
        // A WHOP subscription is not ours to cancel, and pretending otherwise is
        // the worst possible outcome: this branch would mark the account
        // 'cancelled', show the customer "active until the end of your period",
        // and Whop would go on charging their card every month. So the request is
        // refused and handed to the one place that can actually stop it.
        $whopState = entitlement_whop_state((int)$user['id']);

        if (trim((string)$whopState['membership_id']) !== '') {
            $_whop_manage_url = whop_manage_url((string)$whopState['member_id']);
            $error = 'Your subscription is billed by Whop, so it has to be cancelled there — cancelling it here would stop nothing.';
            whop_log('billing', 'refused a local cancel for account ' . (int)$user['id'] . ' — the Whop membership is ' . $whopState['membership_id']);
        } else {
            // Self-service cancel. Status only — the plan is deliberately left alone
            // so the paid features run to the end of the period, which is what the
            // message below promises. The Stripe customer.subscription.deleted event
            // finalises the downgrade when the period actually ends.
            // stamp_clock: the customer decided at this moment, so a saved success
            // URL replayed afterwards must lose to this decision on time.
            $result = entitlement_set_status((int)$user['id'], 'cancelled', [
                'source'      => 'billing.cancel',
                'stamp_clock' => true,
            ]);

            if ($result['applied'] || $result['reason'] === 'no change') {
                $message = 'Subscription cancelled. Your plan features remain active until the end of your billing period.';
                $user['subscription_status'] = 'cancelled';
            } else {
                error_log('[billing] cancel failed: ' . $result['reason']);
                $error = 'Could not cancel subscription right now. Please try again.';
            }
        }
    }
}

$plan         = $user['plan'] ?? 'free';
$is_pro       = $plan === 'pro';
$is_ent       = $plan === 'entrepreneur';
$is_paid      = $is_pro || $is_ent;
$is_active    = ($user['subscription_status'] ?? '') === 'active';
$is_cancelled = ($user['subscription_status'] ?? '') === 'cancelled';

/* WHICH PROVIDER BILLS THIS ACCOUNT
 * A subscription can come from Stripe (the original integration) or from Whop
 * (the merchant of record for both plans now). The difference is not cosmetic: a
 * Whop membership can only be cancelled at Whop, so the page shows that link
 * instead of a cancel form that would do nothing. Read through the module that
 * owns the columns — one read, used by both the control and any message. */
$_whop_state     = entitlement_whop_state((int)$user['id']);
$_whop_member_id = trim((string)$_whop_state['member_id']);

/**
 * THE PURCHASE CONTROL. One helper rather than one form per card, because the
 * two cards already drifted apart once: the Pro card and the Entrepreneur card
 * carried their own copies of the same markup, so a change to one missed the
 * other, and both ended up pointing at register.php even for a signed-in
 * customer.
 *
 * What it posts to is the part that matters. /whop-checkout.php creates a
 * checkout configuration with THIS ACCOUNT'S ID IN ITS METADATA and hands the
 * customer to Whop. Nothing is granted here and nothing is granted by the
 * redirect that follows: only a signed payment.succeeded does that, which is why
 * there is no plan-switching SQL anywhere near this button.
 */
$render_whop_button = static function (string $plan, string $label): void { ?>
  <form method="POST" action="/whop-checkout.php" class="mt-4"
        onsubmit="var b=this.querySelector('button[type=submit]');if(b){b.disabled=true;b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin mr-2\'></i>Taking you to Whop…';}">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="plan" value="<?= htmlspecialchars($plan) ?>">
    <button type="submit"
      class="btn-accent w-full py-3.5 rounded-xl font-black text-sm tracking-tight flex items-center justify-center gap-2">
      <i class="fa-solid fa-lock text-xs"></i><?= htmlspecialchars($label) ?>
    </button>
  </form>
  <div class="trust-row justify-center pt-3">
    <i class="fa-solid fa-shield-halved"></i><span>Secure checkout by</span>
    <span class="font-black text-slate-300">Whop</span>
    <span class="mx-1 text-slate-700">·</span>
    <span>Visa &middot; Mastercard &middot; Amex</span>
  </div>
<?php };

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

/**
 * ONE PURCHASE CARD, BOTH PLANS.
 *
 * The page used to carry two near-identical cards built inline: the Pro one and
 * the Entrepreneur one, with different badges, different chrome and a different
 * shape of feature list. A customer comparing tiers saw two designs rather than two
 * prices, and every copy change had to be made twice. This is one card driven by
 * one array per plan, so the only thing that differs between the tiers is what
 * actually differs: the name, the price and the contents.
 *
 * It is also arranged the way a checkout is: what you get on the left, what you
 * pay and the button on the right, and the one line that matters — Whop is the
 * merchant of record — underneath the button where the doubt is.
 */
$_checkout_plans = [
    'pro' => [
        'name'     => 'Pro',
        'icon'     => 'crown',
        'price'    => $_pro_price_fmt,
        'blurb'    => 'Everything you need to run a full client-getting operation of your own.',
        'flag'     => '',
        'features' => [
            [number_format($_pro_leads) . ' fresh leads per period'],
            [$_pro_sites . ' active websites'],
            ['Full phone numbers'],
            ['Call scripts on every lead'],
            ['All templates + ZIP export'],
            ['Revenue dashboard'],
        ],
        'cta'      => 'Subscribe to Pro — $' . $_pro_price_fmt . '/mo',
        'dev'      => ['form' => 'billingForm', 'number' => 'cardNumberInput', 'icon' => 'cardBrandIconPro', 'expiry' => 'cardExpiryInput', 'cvc' => 'cardCvcInput'],
    ],
    'entrepreneur' => [
        'name'     => 'Entrepreneur',
        'icon'     => 'bolt',
        'price'    => $_ent_price_fmt,
        'blurb'    => 'Built for agencies running several clients at once.',
        'flag'     => 'Most popular',
        'features' => [
            ['Unlimited leads'],
            [$_ent_sites . ' active websites'],
            [$_ent_seats . ' team seats'],
            ['Everything in Pro'],
            ['Call scripts on every lead'],
            ['Priority support'],
            ['Custom domains', true],
            ['Client reports', true],
        ],
        'cta'      => 'Unlock Entrepreneur — $' . $_ent_price_fmt . '/mo',
        'dev'      => ['form' => 'entForm', 'number' => 'cardNumberInputEnt', 'icon' => 'cardBrandIconEnt', 'expiry' => 'cardExpiryInputEnt', 'cvc' => 'cardCvcInputEnt'],
    ],
];

$render_checkout_card = static function (string $plan, array $cfg, string $switchNote = '') use ($render_whop_button, $canActivateLocally): void { ?>
<div class="checkout-card rounded-2xl overflow-hidden">

  <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">

    <!-- What the plan contains -->
    <div class="p-6 sm:p-8 border-b lg:border-b-0 lg:border-r border-white/5">
      <div class="flex items-center gap-2.5 mb-6">
        <i class="fa-solid fa-<?= $cfg['icon'] ?> text-slate-300 text-sm"></i>
        <span class="text-[11px] font-black uppercase tracking-[.18em] text-slate-400"><?= $cfg['name'] ?> plan</span>
        <?php if ($cfg['flag'] !== ''): ?>
        <span class="ml-auto text-[10px] font-bold uppercase tracking-wider border border-white/15 text-slate-300 rounded-full px-2.5 py-1"><?= $cfg['flag'] ?></span>
        <?php endif; ?>
      </div>

      <div class="flex items-end gap-2">
        <span class="text-5xl font-black tracking-tight">$<?= $cfg['price'] ?></span>
        <span class="text-slate-400 text-sm mb-2">/ month</span>
      </div>
      <p class="text-slate-400 text-sm mt-2 mb-7"><?= $cfg['blurb'] ?></p>

      <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-7 gap-y-3">
        <?php foreach ($cfg['features'] as $feature):
          $soon = !empty($feature[1]); ?>
        <li class="feature-item<?= $soon ? ' is-soon' : '' ?>">
          <i class="fa-solid <?= $soon ? 'fa-clock' : 'fa-check' ?>"></i>
          <span><?= $feature[0] ?><?= $soon ? ' <span class="text-slate-600">(soon)</span>' : '' ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- What happens when the button is pressed -->
    <div class="p-6 sm:p-7">
      <div class="summary-panel rounded-2xl p-5">
        <p class="text-[10px] font-bold uppercase tracking-[.16em] text-slate-500 mb-4">Order summary</p>

        <div class="summary-row">
          <span><?= $cfg['name'] ?>, billed monthly</span>
          <strong>$<?= $cfg['price'] ?> /mo</strong>
        </div>
        <div class="summary-row mt-2">
          <span>Setup fee</span>
          <strong>None</strong>
        </div>
        <div class="summary-due">
          <span class="text-sm text-slate-400">Due today</span>
          <span class="text-2xl font-black tracking-tight">$<?= $cfg['price'] ?></span>
        </div>

        <?php if ($switchNote !== ''): ?>
        <p class="text-[11px] text-slate-500 mt-3 leading-relaxed"><?= $switchNote ?></p>
        <?php endif; ?>

        <?php if (whop_can_accept_payments()): ?>
          <?php $render_whop_button($plan, $cfg['cta']); ?>
        <?php else: ?>
          <div class="mt-4 bg-amber-500/[.08] border border-amber-500/25 text-amber-300/90 rounded-xl px-4 py-3 text-xs leading-relaxed">
            <p class="font-semibold text-amber-300 mb-1"><i class="fa-solid fa-clock mr-1.5"></i>Card payments are being switched on</p>
            Nothing has been charged and nothing will be. Want this plan now?
            <a href="/portal/support.php" class="font-semibold underline">Message us</a> and we will set it up with you directly.
          </div>
        <?php endif; ?>
      </div>

      <p class="text-[11px] text-slate-500 mt-4 leading-relaxed">
        <strong class="text-slate-400 font-semibold">Whop is the merchant of record.</strong>
        The charge appears on your statement as Whop, your receipt is emailed by them, and the card details
        never reach our servers. Cancel any time — the link to manage the subscription is added to this page
        the moment it is active.
      </p>

      <?php if ($canActivateLocally): /* developer-only activation, see the guard in the POST handler above */ ?>
      <details class="mt-5 pt-4 border-t border-white/5">
        <summary class="text-[11px] font-semibold text-amber-400/80 cursor-pointer select-none">
          <i class="fa-solid fa-flask mr-1.5"></i>Development only &mdash; activates without a payment
        </summary>
        <form method="POST" action="/portal/billing?plan=<?= htmlspecialchars($plan) ?>" class="space-y-3 mt-4" id="<?= $cfg['dev']['form'] ?>">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="test_subscribe">
          <input type="hidden" name="subscribe_plan" value="<?= htmlspecialchars($plan) ?>">
          <div>
            <label class="input-label" for="<?= $cfg['dev']['number'] ?>">Card number</label>
            <div class="relative">
              <input type="text" name="card_number" id="<?= $cfg['dev']['number'] ?>" inputmode="numeric"
                placeholder="1234 5678 9012 3456" maxlength="19" required autocomplete="cc-number"
                class="card-input pr-14">
              <span id="<?= $cfg['dev']['icon'] ?>" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 text-lg pointer-events-none">
                <i class="fa-regular fa-credit-card"></i>
              </span>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="input-label" for="<?= $cfg['dev']['expiry'] ?>">Expiry</label>
              <input type="text" name="card_expiry" id="<?= $cfg['dev']['expiry'] ?>" inputmode="numeric"
                placeholder="MM / YY" maxlength="7" required autocomplete="cc-exp" class="card-input">
            </div>
            <div>
              <label class="input-label" for="<?= $cfg['dev']['cvc'] ?>">CVC</label>
              <input type="text" name="card_cvc" id="<?= $cfg['dev']['cvc'] ?>" inputmode="numeric"
                placeholder="123" maxlength="4" required autocomplete="cc-csc" class="card-input">
            </div>
          </div>
          <button type="submit" class="w-full bg-white/10 hover:bg-white/15 border border-white/10 text-white py-3 rounded-xl font-bold text-sm transition">
            Activate <?= $cfg['name'] ?> without paying
          </button>
          <p class="text-[11px] text-amber-400/60">This form only exists while TEST_PAYMENT_MODE is on and the site is not in production.</p>
        </form>
      </details>
      <?php endif; ?>
    </div>
  </div>

  <!-- The three things a buyer actually wants to know before typing a card number -->
  <div class="px-6 sm:px-8 py-5 border-t border-white/5 grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="step-block">
      <span class="step-num">1</span>
      <div>
        <p class="text-xs font-semibold text-slate-200">Pay at Whop</p>
        <p class="text-[11px] text-slate-500 leading-relaxed mt-0.5">Their checkout, their payment security. We never see your card.</p>
      </div>
    </div>
    <div class="step-block">
      <span class="step-num">2</span>
      <div>
        <p class="text-xs font-semibold text-slate-200">Your plan switches on</p>
        <p class="text-[11px] text-slate-500 leading-relaxed mt-0.5">Automatically, usually within seconds of the payment clearing.</p>
      </div>
    </div>
    <div class="step-block">
      <span class="step-num">3</span>
      <div>
        <p class="text-xs font-semibold text-slate-200">Manage it any time</p>
        <p class="text-[11px] text-slate-500 leading-relaxed mt-0.5">Change plan, change card or cancel from your Whop billing page.</p>
      </div>
    </div>
  </div>
</div>
<?php };

if (isset($_GET['cancelled'])) $message = 'Checkout cancelled — you were not charged.';

// A purchase that could not be started comes back with the reason. Showing it is
// not decoration: the refusal that matters most here — "we could not check
// whether you already subscribe" — is one the customer can act on by retrying,
// and an unexplained bounce back to this page reads as the site being broken.
// stripe-checkout.php uses this for every path it refuses, including the one it
// has always had: Stripe not yet configured on this install.
if (isset($_GET['stripe_error']) && $error === '') {
    $error = 'We could not start that purchase: ' . (string)$_GET['stripe_error'];
}

// A Whop purchase that could not be started comes back the same way, and the two
// reasons it can have are the two the customer can do something about, so they get
// sentences rather than codes.
if (isset($_GET['whop_error']) && $error === '') {
    $whopReason = (string)$_GET['whop_error'];
    $error = match ($whopReason) {
        'already_subscribed' => 'You already have a subscription, so we did not start a second one — that would have billed you twice. Manage or change your plan from the button below.',
        'not_configured'     => 'Card payments are not switched on for this plan yet, so nothing was charged. Message us from Support and we will set the plan up with you directly.',
        default              => 'We could not start that purchase: ' . $whopReason,
    };
}

/* The plan pill is told apart by WEIGHT and BORDER, not by hue — the same rule the
 * dashboard tag follows, so the plan looks the same wherever the customer meets it.
 * Entrepreneur used to be violet and Pro white, which made the higher tier look
 * like a different product rather than the same one with more in it. */
if ($is_ent && $is_active) {
    $_plan_icon_bg   = 'bg-white/10 border border-white/10';
    $_plan_icon_col  = 'text-white';
    $_plan_icon_name = 'bolt';
    $_plan_badge_cls = 'border border-white/40 text-white';
    $_plan_badge_txt = 'Entrepreneur';
} elseif ($is_pro && $is_active) {
    $_plan_icon_bg   = 'bg-white/5 border border-white/10';
    $_plan_icon_col  = 'text-slate-200';
    $_plan_icon_name = 'crown';
    $_plan_badge_cls = 'border border-white/20 text-slate-200';
    $_plan_badge_txt = 'Pro';
} elseif ($is_cancelled) {
    $_plan_icon_bg   = 'bg-white/5';
    $_plan_icon_col  = 'text-slate-500';
    $_plan_icon_name = 'user';
    $_plan_badge_cls = 'border border-white/10 text-slate-500';
    $_plan_badge_txt = 'Cancelled';
} else {
    $_plan_icon_bg   = 'bg-white/5';
    $_plan_icon_col  = 'text-slate-500';
    $_plan_icon_name = 'user';
    $_plan_badge_cls = 'bg-white/5 text-slate-500';
    $_plan_badge_txt = 'Free';
}

$_pro_upgrading_to_ent = ($is_pro && $is_active && $_target_plan === 'entrepreneur');

$pageTitle = 'Billing — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
/* ── One plan theme, told apart by weight ────────────────────────────────────
   This was an Entrepreneur "violet theme": a shimmering gradient on the word, a
   violet glow-filled button that lifted on hover, a violet gradient card border,
   violet feature pills, a violet plan tab, a violet table column and a violet
   radial glow behind the header. Seven effects, all on one page, all saying "this
   tier is special" — and none of them carrying information. A customer choosing a
   plan needs the limits, which the comparison table already gives in plain text.
   Entrepreneur is now drawn the way the rest of the product draws it: the Pro card
   with a brighter hairline and a white button. */
.ent-badge{font-weight:800}
/* Every primary action on this page is the accent, and every card is the house
   card. The two "premium frames" that used to sit here were a gradient border
   painted with a padding-box/border-box trick over a flat #0d0d0d fill — a black
   card on a near-black canvas, which is why the page read as a different product
   from the dashboard one click away. */
.btn-accent{
  background:var(--accent, #7fe3a8);color:var(--accent-ink, #04150c);
  transition:background .18s var(--ease, cubic-bezier(.22,.61,.36,1));
}
.btn-accent:hover{background:var(--accent-hi, #a2f3c4)}
.btn-accent:active{background:var(--accent, #7fe3a8)}
.btn-accent:disabled{opacity:.6;cursor:not-allowed}

/* ── The purchase card ────────────────────────────────────────────────────────
   One card for both plans, so a change to the purchase layout cannot reach one
   tier and miss the other — the same reason the button above is one helper. The
   accent hairline is what marks it as the card that asks for money; everything
   inside is ink on the house surface. */
.checkout-card{
  background:var(--fill-1);
  border:1px solid var(--accent-line, var(--hair));
  box-shadow:0 30px 70px -50px rgba(0,0,0,.9);
}
.summary-panel{
  background:var(--fill-2);
  border:1px solid var(--hair);
}
.summary-row{display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;font-size:.8125rem;color:var(--ink-2)}
.summary-row strong{color:var(--ink);font-weight:700}
.summary-due{display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;padding-top:.9rem;margin-top:.9rem;border-top:1px solid var(--hair)}
.feature-item{display:flex;align-items:flex-start;gap:.6rem;font-size:.8125rem;color:var(--ink-2);line-height:1.45}
.feature-item i{color:var(--accent, #7fe3a8);font-size:.7rem;margin-top:.28rem;flex-shrink:0}
.feature-item.is-soon{color:var(--ink-4)}
.feature-item.is-soon i{color:var(--ink-4)}
.step-block{display:flex;align-items:flex-start;gap:.7rem}
.step-num{
  width:1.375rem;height:1.375rem;border-radius:.5rem;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  background:var(--fill-3);color:var(--ink-2);font-size:.65rem;font-weight:800;
}
/* The upgrade bar above the plans. One rule, one user: the two "premium frames"
   that used to sit here painted a gradient border over a flat #0d0d0d fill — a
   black card on a near-black canvas, which is why this page read as a different
   product from the dashboard one click away. */
.ent-card-wrap{
  background:var(--fill-1);
  border:1px solid var(--hair);
  box-shadow:0 24px 60px -40px rgba(0,0,0,.85);
}
.card-input{width:100%;background:var(--fill-1);border:1px solid var(--hair, var(--hair));color:var(--ink, #e9edf5);border-radius:10px;padding:.875rem 1rem;font-size:.95rem;outline:none;transition:border-color .2s var(--ease, cubic-bezier(.22,.61,.36,1))}
.card-input::placeholder{color:rgba(148,163,184,.5)}
.card-input:focus{border-color:var(--accent-line, var(--accent-a34, rgba(127,227,168,.34)));box-shadow:none}
.input-label{display:block;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.45rem;color:rgba(148,163,184,.8)}
.trust-row{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem .75rem;font-size:.7rem;color:rgba(100,116,139,.8)}
.plan-tab{padding:.5rem 1.25rem;border-radius:8px;font-size:.8rem;font-weight:700;transition:background .18s,color .18s;cursor:pointer;text-decoration:none}
.plan-tab-active{background:var(--accent-soft, var(--accent-a12, rgba(127,227,168,.12)));color:var(--accent, #7fe3a8)}
.plan-tab-inactive{background:transparent;color:rgba(148,163,184,.9)}
.plan-tab-inactive:hover{background:var(--fill-2);color:var(--ink)}
.plan-tab-ent-active{background:var(--accent-soft, var(--accent-a12, rgba(127,227,168,.12)));color:var(--accent, #7fe3a8)}
/* Tick and cross are a yes/no pair; a green tick next to a violet tick in the next
   column made two different answers look like two different kinds of thing. */
.compare-check{color:var(--ink)}.compare-cross{color:var(--ink-4)}.compare-ent{color:var(--ink)}
.ent-col{background:var(--fill-1)}
</style>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Billing</h1>
  <p class="text-slate-400 text-sm mt-1">Manage your Utiligo subscription and plan.</p>
</div>

<?php if ($message): ?>
<div class="flex items-center gap-3 bg-white/5 border border-white/15 text-slate-200 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check shrink-0"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i><span><?= htmlspecialchars($error) ?><?php
    // The one refusal whose fix is a link rather than a retry: a Whop subscriber
    // can only cancel where they are billed, so the sentence above is half a
    // message without somewhere to go.
    if (!empty($_whop_manage_url)): ?>
    <a href="<?= htmlspecialchars($_whop_manage_url) ?>" target="_blank" rel="noopener" class="font-semibold underline ml-1">Open your Whop billing page &rarr;</a><?php endif; ?>
  </span>
</div>
<?php endif; ?>

<!-- CURRENT PLAN -->
<div class="glass rounded-2xl p-6 border border-white/5 mb-6">
  <div class="flex items-center justify-between flex-wrap gap-4">
    <div class="flex items-center gap-4">
      <div class="w-11 h-11 rounded-xl <?= $_plan_icon_bg ?> flex items-center justify-center shrink-0">
        <i class="fa-solid fa-<?= $_plan_icon_name ?> <?= $_plan_icon_col ?>"></i>
      </div>
      <div>
        <p class="font-bold"><?php
          if ($is_ent)     echo '<span class="text-base">Utiligo Entrepreneur</span>';
          elseif ($is_pro) echo 'Utiligo Pro';
          else             echo 'Free Plan';
        ?></p>
        <p class="text-slate-400 text-xs mt-0.5"><?php
          if ($is_ent && $is_active)        echo '$'.$_ent_price_fmt.'/mo &mdash; <span class="text-emerald-400 font-semibold">Active</span>';
          elseif ($is_ent && $is_cancelled) echo 'Cancelled &mdash; active until end of period';
          elseif ($is_pro && $is_active)    echo '$'.$_pro_price_fmt.'/mo &mdash; <span class="text-emerald-400 font-semibold">Active</span>';
          elseif ($is_pro && $is_cancelled) echo 'Cancelled &mdash; active until end of period';
          else                              echo $_free_leads.' leads &bull; '.$_free_sites.' site/day &bull; 2 templates';
        ?></p>
      </div>
    </div>
    <span class="text-[10px] px-2.5 py-1 rounded-[5px] font-bold uppercase tracking-[.12em] <?= $_plan_badge_cls ?>">
      <?= $_plan_badge_txt ?>
    </span>
  </div>
  <?php if ($is_paid && $is_active && !$_pro_upgrading_to_ent): ?>
  <div class="mt-5 pt-4 border-t border-white/5">
    <?php if ($is_active && $_whop_member_id !== ''): ?>
    <?php /* The three facts a subscriber asks about, in one line, before the control
             that changes anything: what happens next, where the receipt comes from,
             and who is actually charging the card. */ ?>
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 text-[11px] text-slate-500 mb-3.5">
      <span><i class="fa-solid fa-rotate mr-1.5 text-slate-600"></i>Renews monthly, automatically</span>
      <span><i class="fa-regular fa-envelope mr-1.5 text-slate-600"></i>Receipts emailed by Whop</span>
      <span><i class="fa-solid fa-shield-halved mr-1.5 text-slate-600"></i>Whop is the merchant of record</span>
    </div>
    <?php endif; ?>
    <?php if ($_whop_member_id !== ''):
      /* Billed by Whop: the honest control is a link to the page that can
         actually stop the charge. A cancel button here would look identical and
         do nothing, which is the one thing a billing page must never do. */
      $__whop_manage = whop_manage_url($_whop_member_id);
      if ($__whop_manage !== ''): ?>
      <a href="<?= htmlspecialchars($__whop_manage) ?>" target="_blank" rel="noopener" class="text-xs text-slate-400 hover:text-slate-200 transition inline-flex items-center gap-1.5">
        <i class="fa-solid fa-arrow-up-right-from-square"></i>Manage or cancel your subscription in Whop
      </a>
      <?php else: ?>
      <p class="text-xs text-slate-500">Your subscription is billed by Whop. Open your Whop billing page to manage or cancel it.</p>
      <?php endif; ?>
    <?php else: ?>
    <form method="POST" action="/portal/billing" onsubmit="return confirm('Cancel your subscription?');">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="text-xs text-red-400/70 hover:text-red-400 transition">
        <i class="fa-solid fa-xmark mr-1"></i>Cancel subscription
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- PRO -> ENT UPSELL BAR -->
<?php if ($is_pro && $is_active && !$_pro_upgrading_to_ent): ?>
<div class="rounded-2xl ent-card-wrap overflow-hidden mb-6">
  <div class="px-6 py-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
      <p class="text-xs font-bold uppercase tracking-widest text-slate-400 mb-1"><i class="fa-solid fa-bolt mr-1"></i>Upgrade to Entrepreneur</p>
      <p class="text-white font-semibold text-sm">Unlimited leads, team seats &amp; every Pro feature</p>
    </div>
    <a href="/portal/billing?plan=entrepreneur"
       class="shrink-0 btn-accent text-sm font-black px-7 py-3 rounded-xl whitespace-nowrap inline-block text-center">
      Upgrade &rarr; $<?= $_ent_price_fmt ?>/mo
    </a>
  </div>
</div>
<?php endif; ?>

<!-- UPGRADE SECTION -->
<?php if (!$is_paid || $is_cancelled || $_pro_upgrading_to_ent): ?>

<?php
/* Say it before the click, not after it. A customer who presses Subscribe, is
   bounced back and then reads why has already spent the moment of trust this page
   was building — so the state of the checkout is stated up here, in the same
   breath as the prices, and the card below repeats it where the button is. */
if (!whop_can_accept_payments()): ?>
<div class="flex items-start gap-3 bg-amber-500/[.07] border border-amber-500/20 rounded-2xl px-5 py-4 mb-5 text-xs text-amber-200/80 leading-relaxed">
  <i class="fa-solid fa-clock mt-0.5 shrink-0 text-amber-400"></i>
  <span><strong class="text-amber-200 font-semibold">Card payments are being switched on.</strong>
  You can look at both plans and their prices here, but nothing on this page can charge you yet.
  <a href="/portal/support.php" class="underline font-semibold">Message us</a> if you want one of them sooner.</span>
</div>
<?php endif; ?>

<?php if (!$_pro_upgrading_to_ent): ?>
<div class="inline-flex gap-1 p-1 rounded-xl mb-5" style="background:var(--fill-1);border:1px solid var(--hair)">
  <a href="/portal/billing?plan=pro"
     class="plan-tab <?= $_target_plan==='pro' ? 'plan-tab-active' : 'plan-tab-inactive' ?>">
    <i class="fa-solid fa-crown mr-1.5 text-xs"></i>Pro &mdash; $<?= $_pro_price_fmt ?>/mo
  </a>
  <a href="/portal/billing?plan=entrepreneur"
     class="plan-tab <?= $_target_plan==='entrepreneur' ? 'plan-tab-ent-active' : 'plan-tab-inactive' ?>">
    <i class="fa-solid fa-bolt mr-1.5 text-xs"></i>Entrepreneur &mdash; $<?= $_ent_price_fmt ?>/mo
  </a>
</div>
<?php endif; ?>

<?php if ($_target_plan === 'entrepreneur' || $_pro_upgrading_to_ent): ?>

<!-- ENTREPRENEUR CHECKOUT -->
<?php
// The purchase card, from the single renderer both plans share. The note appears
// only when this is a plan CHANGE rather than a first purchase: "the price replaces
// the old one" is reassuring when it is true, and confusing when there is no old
// plan to replace. Keeping the way back on Pro next to it is the other half of that
// sentence being honest.
$render_checkout_card('entrepreneur', $_checkout_plans['entrepreneur'], $_pro_upgrading_to_ent
    ? 'Whop settles the change in place: the Entrepreneur price replaces the Pro one on the same subscription, so there is nothing to cancel and no second charge. <a href="/portal/billing" class="underline">Stay on Pro</a>'
    : '');
?>

<div class="glass rounded-2xl p-6 mb-6 overflow-x-auto">
    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-3">Entrepreneur vs Pro</p>
    <table class="w-full text-xs min-w-[340px]">
      <thead><tr>
        <th class="text-left text-slate-500 font-semibold pb-2.5 pr-4">Feature</th>
        <th class="text-center text-slate-500 font-semibold pb-2.5 px-3">Pro</th>
        <th class="text-center pb-2.5 px-3 ent-col rounded-t-lg"><span class="font-black text-slate-100">Entrepreneur</span></th>
      </tr></thead>
      <tbody>
      <?php $rows=[
        ['Leads',
          '<span class="text-slate-400">'.number_format($_pro_leads).'</span>',
          '<i class="fa-solid fa-infinity compare-ent"></i>'],
        ['Active sites',
          '<span class="text-slate-500">'.$_pro_sites.'</span>',
          '<span class="text-slate-100 font-bold">'.$_ent_sites.'</span>'],
        ['Custom domains <span class="text-slate-600">(soon)</span>',
          '<i class="fa-solid fa-xmark compare-cross"></i>',
          '<span class="text-slate-500 font-semibold">Soon</span>'],
        ['Client reports <span class="text-slate-600">(soon)</span>',
          '<i class="fa-solid fa-xmark compare-cross"></i>',
          '<span class="text-slate-500 font-semibold">Soon</span>'],
        ['Team seats',
          '<i class="fa-solid fa-xmark compare-cross"></i>',
          '<span class="text-slate-100 font-bold">'.$_ent_seats.' seats</span>'],
        ['Revenue dash',
          '<i class="fa-solid fa-check compare-check"></i>',
          '<i class="fa-solid fa-check compare-ent"></i>'],
        ['Price/mo',
          '<span class="text-slate-400">$'.$_pro_price_fmt.'</span>',
          '<span class="font-black text-slate-100">$'.$_ent_price_fmt.'</span>'],
      ];
      foreach ($rows as $i => [$f, $p, $e]): ?>
      <tr class="<?= $i % 2 ? 'bg-white/[.02]' : '' ?>">
        <td class="py-2 pr-4 text-slate-400"><?= $f ?></td>
        <td class="py-2 px-3 text-center text-slate-400"><?= $p ?></td>
        <td class="py-2 px-3 text-center ent-col <?= $i === count($rows)-1 ? 'rounded-b-lg' : '' ?>"><?= $e ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>


<?php else: ?>

<!-- PRO CHECKOUT -->
<?php $render_checkout_card('pro', $_checkout_plans['pro']); ?>

<div class="text-center text-xs text-slate-500 mb-6">
  Want unlimited leads &amp; team seats?
  <a href="/portal/billing?plan=entrepreneur" class="text-slate-200 hover:text-white font-semibold ml-1 transition">
    See Entrepreneur plan <i class="fa-solid fa-arrow-right text-[10px]"></i>
  </a>
</div>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/portal_layout_end.php'; ?>
<script src="<?= asset_url('/assets/js/billing_card.js') ?>"></script>
