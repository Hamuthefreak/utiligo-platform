<?php
/**
 * stripe-checkout.php
 *
 * Starts a purchase, and — the part that matters — never starts a second one.
 *
 * Called via POST form from portal/billing.php (non-test mode).
 *
 * WHY THIS IS NOT JUST "CREATE A CHECKOUT SESSION"
 * ───────────────────────────────────────────────
 * It used to be. Every POST built a `mode=subscription` Checkout Session, and
 * Stripe happily created a brand-new Subscription for it. So a customer on Pro
 * who pressed "Upgrade to Entrepreneur" ended up holding TWO live
 * subscriptions: the Entrepreneur one they asked for, and the Pro one still
 * charging their card every month. Nothing in the app cancelled the old one,
 * and the entitlement side read as Entrepreneur, so the duplicate was invisible
 * from inside the product — it only ever announced itself on the customer's
 * bank statement.
 *
 * The rule now:
 *
 *   1. Ask Stripe whether this customer already has a subscription that is
 *      still charging them (includes-stripe_api.php does the asking, and asks
 *      about the CUSTOMER rather than trusting our stored subscription id,
 *      which is exactly the field a missed webhook leaves stale).
 *   2. If there is one, change it in place — same subscription, new price.
 *      There is nothing to bill twice, because there is only ever one.
 *   3. If there is not, sell a new subscription, reusing the customer object we
 *      already have so a customer does not accumulate one Stripe customer per
 *      purchase either.
 *
 * FAILING CLOSED
 * ──────────────
 * Two steps can fail: the lookup, and the change. Neither falls back to creating
 * a Checkout Session. "We could not tell whether you already subscribe" is not
 * evidence that you don't, and guessing wrong costs the customer money they
 * have to phone up to get back — so the request is refused with a message that
 * says to try again, which is a thing the customer can actually do.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/stripe_api.php';
require_once __DIR__ . '/includes/entitlements.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portal/billing.php');
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid session.');
}

$allowed = ['pro', 'entrepreneur'];
$plan    = in_array($_POST['plan'] ?? '', $allowed) ? $_POST['plan'] : null;
if (!$plan) {
    header('Location: /portal/billing.php');
    exit;
}

$priceId = ($plan === 'entrepreneur') ? STRIPE_ENT_PRICE_ID : STRIPE_PRO_PRICE_ID;

if (!$priceId || str_starts_with($priceId, 'YOUR_')) {
    // Price ID not configured yet — fall back gracefully
    header('Location: /portal/billing.php?stripe_error=not_configured');
    exit;
}

$user = current_user();

/** Every failure leaves the customer on the billing page with a reason. */
$bail = function (string $reason, string $detail = '') use ($plan) {
    error_log('[stripe-checkout] refused ' . $plan . ': ' . $reason . ($detail !== '' ? ' — ' . $detail : ''));
    header('Location: /portal/billing.php?upgrade=1&plan=' . urlencode($plan)
         . '&stripe_error=' . urlencode($reason));
    exit;
};

$state = entitlement_stripe_state((int)$user['id']);

/* ── Is there already a subscription charging this customer? ─────────────── */

$live = null;
if ($state['customer_id'] !== '') {
    $lookup = stripe_customer_live_subscription($state['customer_id']);

    if (!$lookup['ok']) {
        // Not "no subscription" — "unknown". Refusing costs a retry; guessing
        // costs a duplicate subscription.
        $bail('we could not check your current subscription, so nothing was charged. Please try again in a moment.',
            'subscription lookup failed: ' . $lookup['error']);
    }

    $live = $lookup['subscription'];
}

/* ── Change the running subscription in place ───────────────────────────── */

if ($live !== null) {
    $liveId = trim((string)($live['id'] ?? ''));

    // Reconcile from the object Stripe just handed us. A live read needs no
    // ordering stamp: it cannot be older than anything already applied, and
    // leaving the clock alone means the webhook Stripe is about to send still
    // wins. match_subscription is off because this is the subscription we
    // verified with Stripe, so recording it is the point — the stored id may be
    // empty or belong to a subscription the customer replaced.
    $settle = function (array $subscription) use ($user, $state, $plan, $liveId) {
        $result = entitlement_sync_subscription([
            'user_id'            => (int)$user['id'],
            'customer_id'        => $state['customer_id'],
            'subscription_id'    => $liveId,
            'subscription'       => $subscription,
            'match_subscription' => false,
            'source'             => 'checkout.plan-change',
        ]);

        if (!$result['applied'] && $result['reason'] !== 'no change') {
            // The money is right and the record is not. Worth a log line rather
            // than a refusal: Stripe's own event is still on its way and will
            // correct it.
            error_log('[stripe-checkout] entitlement for subscription ' . $liveId
                . ' (' . $plan . ') not applied: ' . $result['reason']);
        }
    };

    if (stripe_subscription_price_id($live) === $priceId) {
        // Already paying for exactly this. There is nothing to charge and
        // nothing to change — and before this check, a double-click or a
        // resubmit was enough to buy the plan a second time.
        $settle($live);

        header('Location: /portal/index?upgraded=1&plan=' . urlencode($plan) . '&already=1');
        exit;
    }

    $itemId = stripe_subscription_item_id($live);
    if ($itemId === '') {
        $bail('your current subscription could not be located at Stripe. Nothing was charged.',
            'subscription ' . $liveId . ' carried no item id');
    }

    // Swapping the ITEM's price is the whole change: the subscription, its
    // billing cycle and its remaining time are untouched, and Stripe prorates
    // the difference onto the next invoice.
    $swap = stripe_change_subscription_plan($liveId, stripe_subscription_swap_params($live, $priceId, $plan, (int)$user['id']));

    if (!$swap['ok']) {
        $reason = (string)($swap['data']['error']['message'] ?? ($swap['error'] ?: 'stripe_error'));
        $bail('Stripe could not change your plan, so nothing was charged. Please try again.', $reason);
    }

    $settle((array)$swap['data']);

    header('Location: /portal/index?upgraded=1&plan=' . urlencode($plan) . '&changed=1');
    exit;
}

/* ── No subscription running: sell one, on the customer we already have ──── */

// Build the Checkout Session through includes/stripe_api.php (raw cURL, no
// Composer, no Stripe SDK). The session is stamped server-side with the payer
// (client_reference_id) and the plan actually bought (metadata.plan) — that
// server-side stamp is what stripe-webhook.php and purchase-success.php later
// trust, and why neither of them may believe a `?plan` parameter.
$response = stripe_request('POST', 'v1/checkout/sessions',
    stripe_checkout_session_params($user, $plan, $priceId, APP_BASE_URL, $state['customer_id']));

if (!$response['ok'] || empty($response['data']['url'])) {
    $errMsg = urlencode($response['data']['error']['message'] ?? ($response['error'] ?: 'stripe_error'));
    header('Location: /portal/billing.php?upgrade=1&plan=' . urlencode($plan) . '&stripe_error=' . $errMsg);
    exit;
}

// Redirect to Stripe-hosted Checkout
header('Location: ' . $response['data']['url']);
exit;
