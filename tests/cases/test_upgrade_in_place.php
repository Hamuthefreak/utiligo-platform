<?php
/**
 * In-place plan changes — stripe-checkout.php.
 *
 * The bug this covers: buying a second plan used to create a SECOND live Stripe
 * subscription. A customer on Pro who upgraded to Entrepreneur was billed for
 * both, and the duplicate was invisible from inside the product — the
 * entitlement side read Entrepreneur, so nothing in the app ever noticed. It
 * only surfaced on the customer's bank statement.
 *
 * The rule under test is therefore not "the plan changed" (that always worked).
 * It is "there is still only one subscription". That is why the sharpest
 * assertion in this file is the absence of a call to
 * POST /v1/checkout/sessions, which a last-request snapshot could never make.
 *
 * Everything here goes through the real page over HTTP against the real
 * database, with tests/lib/stripe_stub.php standing in for api.stripe.com.
 */

if (empty($context['stub_url']) || empty($context['app_url'])) {
    throw new T_Skip('the Stripe stub and the application server are required for this file');
}

putenv('STRIPE_API_BASE=' . $context['stub_url']);
$app = $context['app_url'];

t_reset_stripe_stub();
t_set_stripe_sessions([]);
t_set_stripe_subscriptions([]);

/** POST the real checkout page as a signed-in customer. */
$submit = function (int $userId, string $plan) use ($app): array {
    $csrf = bin2hex(random_bytes(32));

    return t_http('POST', $app . '/stripe-checkout.php', [
        'form'   => ['plan' => $plan, 'csrf_token' => $csrf],
        'cookie' => t_login($userId, ['csrf_token' => $csrf]),
    ]);
};

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. The decision table, with no HTTP and no Stripe in it
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Which subscriptions still charge the customer');

foreach (['active', 'trialing', 'past_due'] as $status) {
    t_ok(stripe_subscription_is_live(['status' => $status]), $status . ' is live, so it is changed rather than duplicated');
}

foreach ([
    'canceled'           => 'it has ended',
    'incomplete'         => 'the first payment never succeeded',
    'incomplete_expired' => 'it timed out',
    'unpaid'             => 'dunning gave up',
    'paused'             => 'it yields no entitlement',
] as $status => $why) {
    t_ok(!stripe_subscription_is_live(['status' => $status]), $status . ' is not live (' . $why . '), so a fresh checkout is right');
}

t_ok(!stripe_subscription_is_live([]), 'a subscription with no status is not live');
t_ok(stripe_subscription_is_live(['status' => ' Active ']), 'the status is compared case- and space-insensitively');

t_section('Choosing which subscription to change');

$older = ['id' => 'sub_older', 'status' => 'active',  'created' => 1000];
$newer = ['id' => 'sub_newer', 'status' => 'active',  'created' => 2000];
$dead  = ['id' => 'sub_dead',  'status' => 'canceled', 'created' => 3000];

t_is(stripe_pick_live_subscription([]), null, 'with nothing running there is nothing to change');
t_is(stripe_pick_live_subscription([$dead]), null, 'a dead subscription is not a candidate');
t_is(stripe_pick_live_subscription([$older, $newer])['id'] ?? '', 'sub_newer',
    'the newest live one wins, so a subscription the customer replaced is not changed');
t_is(stripe_pick_live_subscription([$dead, $older, $newer])['id'] ?? '', 'sub_newer',
    'a newer dead one does not outrank a live one');

t_section('What an in-place plan change asks Stripe for');

$proSubscription = t_subscription('sub_swap');
$swap = stripe_subscription_swap_params($proSubscription, STRIPE_ENT_PRICE_ID, 'entrepreneur', 42);

t_is($swap['items[0][id]'], stripe_subscription_item_id($proSubscription),
    'it names the subscription item being replaced — naming only a price would ADD an item and bill for both');
t_like($swap['items[0][id]'], 'si_', 'and that is a real item id');
t_is($swap['items[0][price]'], STRIPE_ENT_PRICE_ID, 'it moves that item to the plan that was bought');
t_not($swap['items[0][price]'], stripe_subscription_price_id($proSubscription),
    'which is not the price the subscription is already on');
t_is($swap['proration_behavior'], 'create_prorations',
    'the difference is prorated onto the next invoice rather than charged on the spot');
t_is($swap['metadata[plan]'], 'entrepreneur', 'the metadata follows the price, so it cannot name the plan they left');
t_is($swap['metadata[user_id]'], '42', 'and keeps the account attribution');
t_ok(!isset($swap['cancel_at_period_end']),
    'a subscription that was not scheduled to cancel is left alone rather than sent a redundant field');

$scheduled = stripe_subscription_swap_params(
    t_subscription('sub_scheduled', ['cancel_at_period_end' => true]), STRIPE_ENT_PRICE_ID, 'entrepreneur', 42);
t_is($scheduled['cancel_at_period_end'] ?? '', 'false',
    'one that WAS scheduled to cancel is reactivated — taking the plan away at period end would undo a plan they just bought');

t_section('A replacement subscription is sold on the customer we already have');

$known = stripe_checkout_session_params(
    ['id' => 7, 'email' => 'p@example.test'], 'pro', 'price_test_pro', 'https://utiligo.ca', 'cus_test_known');

t_is($known['customer'], 'cus_test_known', 'the existing customer is reused, so one customer does not become one-per-purchase');
t_ok(!isset($known['customer_email']), 'and the email is NOT also sent, because Stripe rejects a request carrying both');
t_is($known['metadata[plan]'], 'pro', 'the plan stamp is unaffected');

$fresh = stripe_checkout_session_params(
    ['id' => 7, 'email' => 'p@example.test'], 'pro', 'price_test_pro', 'https://utiligo.ca');

t_is($fresh['customer_email'], 'p@example.test', 'with no recorded customer the email is used, as before');
t_ok(!isset($fresh['customer']), 'and no customer field is sent');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. The same paths, through the real page and the real database
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Upgrading a running subscription changes it instead of selling a second');

$upgradeCustomer = 'cus_test_' . bin2hex(random_bytes(4));
$upgradeSub      = 'sub_test_' . bin2hex(random_bytes(4));
$subscription    = t_subscription($upgradeSub, ['customer' => $upgradeCustomer]);

$upgradeUser = t_fixture([
    'plan'                   => 'pro',
    'subscription_status'    => 'active',
    'stripe_customer_id'     => $upgradeCustomer,
    'stripe_subscription_id' => $upgradeSub,
]);

t_set_stripe_subscriptions([$upgradeSub => $subscription]);
t_reset_stripe_stub();

$response = $submit($upgradeUser, 'entrepreneur');

t_is($response['status'], 302, 'the purchase start is answered with a redirect');
t_like((string)$response['location'], '/portal/index', 'the customer lands back in the portal instead of on a hosted checkout');
t_unlike((string)$response['location'], 'checkout.stripe.test', 'they are never sent to buy a second subscription');
t_like((string)$response['location'], 'changed=1', 'and the page is told a plan change happened');

t_is(count(t_stripe_requests_to('/v1/checkout/sessions')), 0,
    'NO Checkout Session was created — this is the assertion that a second subscription does not exist');
$swapCalls = t_stripe_requests_to('/v1/subscriptions/' . $upgradeSub);
t_is(count($swapCalls), 1, 'the one running subscription was changed, exactly once');
t_is($swapCalls[0]['method'] ?? '', 'POST', 'with a POST');
t_is($swapCalls[0]['form']['items'][0]['id'] ?? '', stripe_subscription_item_id($subscription),
    'naming the item it replaces');
t_is($swapCalls[0]['form']['items'][0]['price'] ?? '', STRIPE_ENT_PRICE_ID,
    'moving it to the plan that was actually bought');

$lookups = t_stripe_requests_to('/v1/subscriptions');
t_is(count($lookups), 1, 'Stripe was asked once what this customer has running');
t_is($lookups[0]['query']['customer'] ?? '', $upgradeCustomer,
    'and it was asked about the CUSTOMER, not the subscription id we happen to have stored');
t_is($lookups[0]['query']['status'] ?? '', 'all',
    'with no status filter that could hide a live subscription and cause a second one');

$upgraded = t_user($upgradeUser);
t_is($upgraded['plan'] ?? '', 'entrepreneur', 'the account reads as Entrepreneur straight away, without waiting for the webhook');
t_is($upgraded['subscription_status'] ?? '', 'active', 'and active');
t_is($upgraded['stripe_subscription_id'] ?? '', $upgradeSub, 'still on the single subscription it had');

$state = entitlement_stripe_state($upgradeUser);
t_is($state['customer_id'], $upgradeCustomer, 'the Stripe state reader returns the customer on the row');
t_ok($state['found'], 'and reports that it found the row at all');

t_section('Pressing the same button twice does not buy the plan twice');

$repeatCustomer = 'cus_test_' . bin2hex(random_bytes(4));
$repeatSub      = 'sub_test_' . bin2hex(random_bytes(4));
t_set_stripe_subscriptions([$repeatSub => t_subscription($repeatSub, ['customer' => $repeatCustomer])]);

$repeatUser = t_fixture([
    'plan'                   => 'pro',
    'subscription_status'    => 'active',
    'stripe_customer_id'     => $repeatCustomer,
    'stripe_subscription_id' => $repeatSub,
]);

t_reset_stripe_stub();
$response = $submit($repeatUser, 'pro');

t_like((string)$response['location'], 'already=1', 'they are told they already have this plan');
t_is(count(t_stripe_requests_to('/v1/checkout/sessions')), 0, 'no Checkout Session is created');
t_is(count(t_stripe_requests_to('/v1/subscriptions/' . $repeatSub)), 0,
    'and no change is sent to Stripe either — there was nothing to change');
t_is(t_user($repeatUser)['plan'] ?? '', 'pro', 'their plan is what it already was');

t_section('A customer whose subscription has ended is sold a new one — on the customer they already have');

$lapsedCustomer = 'cus_test_' . bin2hex(random_bytes(4));
$lapsedSub      = 'sub_test_' . bin2hex(random_bytes(4));
t_set_stripe_subscriptions([$lapsedSub => t_subscription($lapsedSub, [
    'customer' => $lapsedCustomer,
    'status'   => 'canceled',
])]);

$lapsedUser = t_fixture([
    'plan'                   => 'free',
    'subscription_status'    => 'cancelled',
    'stripe_customer_id'     => $lapsedCustomer,
    'stripe_subscription_id' => $lapsedSub,
]);

t_reset_stripe_stub();
$response = $submit($lapsedUser, 'entrepreneur');

t_like((string)$response['location'], 'checkout.stripe.test', 'they are sent to Stripe checkout');
$sessions = t_stripe_requests_to('/v1/checkout/sessions');
t_is(count($sessions), 1, 'exactly one Checkout Session is created');
t_is($sessions[0]['form']['customer'] ?? '', $lapsedCustomer, 'and it reuses the customer they already have');
t_ok(!isset($sessions[0]['form']['customer_email']), 'without also sending an email next to it');
t_is(count(t_stripe_requests_to('/v1/subscriptions/' . $lapsedSub)), 0,
    'the ended subscription is left alone — a change to it would revive something the customer no longer has');

t_section('A first purchase still creates the customer');

$newEmail = 'first' . bin2hex(random_bytes(4)) . '@example.test';
$newUser  = t_fixture(['plan' => 'free', 'email' => $newEmail]);

t_reset_stripe_stub();
$response = $submit($newUser, 'pro');

t_like((string)$response['location'], 'checkout.stripe.test', 'they are sent to Stripe checkout');
$sessions = t_stripe_requests_to('/v1/checkout/sessions');
t_is(count($sessions), 1, 'a Checkout Session is created');
t_is($sessions[0]['form']['customer_email'] ?? '', $newEmail, 'the email identifies the new customer');
t_ok(!isset($sessions[0]['form']['customer']), 'there is no customer to reuse');
t_is(count(t_stripe_requests_to('/v1/subscriptions')), 0, 'and nothing is looked up, because there is no customer to look up');

t_section('When Stripe cannot say whether they already subscribe, nothing is sold');

$blindCustomer = 'cus_test_' . bin2hex(random_bytes(4));
$blindSub      = 'sub_test_' . bin2hex(random_bytes(4));
t_set_stripe_subscriptions([$blindSub => t_subscription($blindSub, ['customer' => $blindCustomer])]);

$blindUser = t_fixture([
    'plan'                   => 'pro',
    'subscription_status'    => 'active',
    'stripe_customer_id'     => $blindCustomer,
    'stripe_subscription_id' => $blindSub,
]);

// Reset first, then inject: t_reset_stripe_stub() clears the injected failures
// along with the request log, so doing it the other way round silently
// un-injects them.
t_reset_stripe_stub();
t_set_stripe_failures(['GET /v1/subscriptions' => 500]);
$response = $submit($blindUser, 'entrepreneur');

t_like((string)$response['location'], 'stripe_error=', 'the customer is refused with a reason they can act on');
t_is(count(t_stripe_requests_to('/v1/checkout/sessions')), 0,
    'and no Checkout Session is created — "we could not tell" is not evidence that they do not subscribe');
t_is(count(t_stripe_requests_to('/v1/subscriptions/' . $blindSub)), 0, 'and nothing is changed on a guess either');
t_is(t_user($blindUser)['plan'] ?? '', 'pro', 'their plan is untouched');
t_set_stripe_failures([]);

t_section('A plan change that fails does not fall back to selling a subscription');

$failCustomer = 'cus_test_' . bin2hex(random_bytes(4));
$failSub      = 'sub_test_' . bin2hex(random_bytes(4));
t_set_stripe_subscriptions([$failSub => t_subscription($failSub, ['customer' => $failCustomer])]);

$failUser = t_fixture([
    'plan'                   => 'pro',
    'subscription_status'    => 'active',
    'stripe_customer_id'     => $failCustomer,
    'stripe_subscription_id' => $failSub,
]);

t_reset_stripe_stub();
t_set_stripe_failures(['POST /v1/subscriptions/' . $failSub => 402]);
$response = $submit($failUser, 'entrepreneur');

t_like((string)$response['location'], 'stripe_error=', 'the failure is reported');
t_is(count(t_stripe_requests_to('/v1/subscriptions/' . $failSub)), 1, 'the change was attempted');
t_is(count(t_stripe_requests_to('/v1/checkout/sessions')), 0,
    'and no subscription was sold to work around it — that is how a customer ends up with two');
t_is(t_user($failUser)['plan'] ?? '', 'pro', 'their plan is left as it was rather than half-applied');
t_set_stripe_failures([]);

t_section('A scheduled cancellation is cleared when the customer chooses a plan instead');

$reviveCustomer = 'cus_test_' . bin2hex(random_bytes(4));
$reviveSub      = 'sub_test_' . bin2hex(random_bytes(4));
t_set_stripe_subscriptions([$reviveSub => t_subscription($reviveSub, [
    'customer'             => $reviveCustomer,
    'cancel_at_period_end' => true,
])]);

$reviveUser = t_fixture([
    'plan'                   => 'pro',
    'subscription_status'    => 'cancelled',
    'stripe_customer_id'     => $reviveCustomer,
    'stripe_subscription_id' => $reviveSub,
]);

t_reset_stripe_stub();
$response = $submit($reviveUser, 'entrepreneur');

$reviveCalls = t_stripe_requests_to('/v1/subscriptions/' . $reviveSub);
t_is(count($reviveCalls), 1, 'the subscription is changed');
t_is($reviveCalls[0]['form']['cancel_at_period_end'] ?? '', 'false',
    'and the pending cancellation is cleared, so the plan is not taken away at period end');
t_is(t_user($reviveUser)['plan'] ?? '', 'entrepreneur', 'the account is on the new plan');
t_is(t_user($reviveUser)['subscription_status'] ?? '', 'active', 'and reads active again');

t_section('A refused purchase is explained on the page the customer lands on');

// Every refusal above redirects to the billing page carrying a reason. If that
// page drops it, the customer sees an unexplained bounce back and nothing to do
// — and a refusal is precisely the case where retrying is the right action.
$explainedUser = t_fixture(['plan' => 'free']);
$cookie        = t_login($explainedUser);

$page = t_http('GET', $app . '/portal/billing.php?stripe_error=' . urlencode('we could not check your current subscription'), [
    'cookie' => $cookie,
]);
t_is($page['status'], 200, 'the billing page loads');
t_like($page['body'], 'we could not check your current subscription', 'and shows the reason the purchase was refused');
t_like($page['body'], 'We could not start that purchase', 'with a human sentence in front of it');

// The reason travels in a query parameter, so it must never be trusted as
// markup — a Stripe error message is remote text, and so is anyone's typed URL.
$hostile = t_http('GET', $app . '/portal/billing.php?stripe_error=' . urlencode('<script>alert(1)</script>'), [
    'cookie' => $cookie,
]);
t_unlike($hostile['body'], '<script>alert(1)', 'a reason containing markup is not echoed as markup');
t_like($hostile['body'], '&lt;script&gt;', 'it is escaped instead');

/* Leave nothing behind for the files that run after this one. */
t_set_stripe_subscriptions([]);
t_set_stripe_failures([]);
t_reset_stripe_stub();
