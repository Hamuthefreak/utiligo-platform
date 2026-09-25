<?php
/**
 * whop-checkout.php — starts a Whop purchase for the signed-in account.
 *
 * WHERE THIS SITS
 * ───────────────
 * The pricing buttons on the marketing page point here, and so does the billing
 * page's upgrade form. Two entry shapes on purpose:
 *
 *   GET  /whop-checkout.php?plan=pro     a link. What a pricing card is.
 *   POST /whop-checkout.php  plan=pro    a form. What the billing page uses.
 *
 * A POST additionally requires a CSRF token. A GET cannot carry one and does not
 * need one here: the session cookie is SameSite=Lax, so a cross-site request —
 * an <img> tag, a third-party form — arrives with no session at all and is sent
 * to sign up instead. The worst a same-site-forced GET can do is redirect its own
 * victim to Whop's payment page, where nothing is charged until they type a card.
 *
 * WHY IT REFUSES RATHER THAN STARTS A SECOND SUBSCRIPTION
 * ──────────────────────────────────────────────────────
 * A Whop membership is created by a checkout and cancelled by the customer in
 * Whop's own billing portal — we cannot swap the plan on an existing membership
 * from here. So the one thing this endpoint must never do is start a second
 * checkout for an account that already has a live subscription, because Whop would
 * create a second membership and the customer would be charged twice, with
 * nothing in the product showing it. stripe-checkout.php learned this the
 * expensive way; this file starts with the rule rather than growing it.
 *
 * That makes the answer to "I am on Pro, give me Entrepreneur" a redirect to
 * Whop's manage page, where switching a plan is a supported operation. It is one
 * more step for the customer and it is the correct one: the alternative is a
 * double charge we would have to refund.
 *
 * FAILING OPEN — BUT ONLY WHERE FAILING OPEN IS SAFE
 * ──────────────────────────────────────────────────
 * If the API key is missing or the API is unreachable, the customer still goes to
 * the plan's shareable checkout link — a sale that arrives without metadata is
 * reconciled by the webhook through the payer's email, and the alternative (an
 * error page on a working pricing button) loses a sale for no reason. What is
 * logged is that identity is missing, so an operator can see why a payment had to
 * be matched by email.
 *
 * There is one failure this file does NOT fall open on, and it is the one that
 * costs a customer money: a deployment with no webhook secret cannot apply any
 * payment at all (every delivery is refused — see whop_verify_webhook()). Sending
 * a customer to pay in that state takes their money and leaves them on the free
 * plan while Whop retries a webhook nobody can verify for three days. So the sale
 * is refused, in plain words, until the deployment can honour it — see
 * whop_can_accept_payments(), and admin/payments.php, which is where the fix is
 * described to the person who can make it.
 *
 * @see includes/whop.php      the API call, the fallback, and the reasoning
 * @see whop-webhook.php       what actually grants the plan
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/whop.php';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$plan = strtolower(trim((string)($isPost ? ($_POST['plan'] ?? '') : ($_GET['plan'] ?? ''))));

// Every refusal lands somewhere the person can act. A signed-out visitor is sent
// to sign up CARRYING the plan, which is what the pricing card already does.
$wanted = $plan !== '' ? '?plan=' . urlencode($plan) : '';

if (!is_logged_in()) {
    header('Location: /register.php' . $wanted);
    exit;
}

if ($isPost && !csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid session.');
}

$user   = current_user();
$userId = (int)($user['id'] ?? 0);

/** Every refusal explains itself on the billing page, the way the Stripe path does. */
$bail = function (string $reason, string $detail = '') use ($plan): void {
    whop_log('checkout', 'refused ' . $plan . ': ' . $reason . ($detail !== '' ? ' — ' . $detail : ''));
    header('Location: /portal/billing.php?upgrade=1&plan=' . urlencode($plan)
         . '&whop_error=' . urlencode($reason));
    exit;
};

if ($plan === '' || !is_paid_plan($plan)) {
    header('Location: /portal/billing.php');
    exit;
}

if (whop_plan_id_for($plan) === '') {
    // The plan is real but not sold on Whop — a deployment with one provider's
    // configuration missing. Say so rather than sending them to the wrong page.
    $bail('not_configured');
}

if (!whop_can_accept_payments()) {
    // The one refusal in this file that is not about the customer. A deployment
    // without a webhook secret can be paid but cannot grant the plan, so the
    // honest thing is to take no money and say why — for the customer on the
    // billing page, and for whoever has to fix it in storage/config_overrides.php
    // (or the admin Payments page, which lists exactly this as the blocker).
    whop_log('checkout', 'refused a ' . $plan . ' checkout for account ' . $userId
        . ': this deployment cannot apply a payment (webhook secret '
        . (whop_can_verify() ? 'set' : 'MISSING') . ', plans '
        . (whop_plan_ids() === [] ? 'none configured' : 'configured') . ')');
    $bail('not_configured');
}

/* ── The already-subscribed guard ────────────────────────────────────────────
 * Read from the module that owns the columns, never by hand: getting this wrong
 * by defaulting to "no subscription" is what charges someone twice.
 *
 * A stored membership id counts as subscribed even when the local status reads
 * 'cancelled', because a cancellation at Whop runs to the end of the period —
 * the money is still moving, so a second checkout would be a second charge.
 * A Stripe subscription counts too: we cannot move one of those onto Whop, and
 * quietly selling a Whop plan alongside it would bill both. */
$whopState = entitlement_whop_state($userId);
$stripeState = entitlement_stripe_state($userId);

$hasWhopSub   = trim((string)$whopState['membership_id']) !== '';
$hasStripeSub = trim((string)$stripeState['subscription_id']) !== '';

if ($hasWhopSub || $hasStripeSub) {
    $manageUrl = $hasWhopSub ? whop_manage_url((string)$whopState['member_id']) : '';

    if ($manageUrl !== '') {
        // Whop's billing portal: switch plans, change the card, cancel. The
        // customer keeps one membership and one charge either way.
        whop_log('checkout', 'sending account ' . $userId . ' to the Whop portal — it already subscribes');
        header('Location: ' . $manageUrl);
        exit;
    }

    $bail('already_subscribed');
}

/* ── Start it ──────────────────────────────────────────────────────────────── */

$returnUrl = rtrim(defined('APP_BASE_URL') ? (string)APP_BASE_URL : 'https://utiligo.ca', '/')
           . (defined('WHOP_RETURN_PATH') ? (string)WHOP_RETURN_PATH : '/purchase-success.php')
           . '?whop_plan=' . urlencode($plan);

$checkout = whop_checkout_url($plan, $userId, $returnUrl);

if ($checkout['url'] === '') {
    // No API key AND no plan link configured. Nothing to send them to.
    $bail('not_configured');
}

if (!$checkout['carries_identity']) {
    // Worth a log line: this purchase will have to be matched by the payer's
    // email, and if that address is not verified on the account the webhook will
    // record the payment as unidentified rather than guess.
    whop_log('checkout', 'no API key or the API refused — account ' . $userId
        . ' is being sent to the plan link, so the payment will carry no metadata');
}

whop_log('checkout', 'account ' . $userId . ' started a ' . $plan . ' checkout'
    . ($checkout['carries_identity'] ? ' with metadata' : ''));

// Out of the app and onto Whop. Nothing is granted here and nothing is stored:
// the plan changes when a signed payment.succeeded arrives, and not before.
header('Location: ' . $checkout['url']);
exit;
