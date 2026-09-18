<?php
/**
 * Webhook handling, end to end.
 *
 * Every case here POSTs a genuinely signed payload to the real
 * stripe-webhook.php running under a real PHP server, and then reads the real
 * database. Stripe is never contacted — the signature is produced with the test
 * webhook secret by the same helper production uses.
 *
 * The scenarios are the ones that cost money: a replayed event re-granting a
 * cancelled plan, a delayed event revoking a fresh one, and an unsigned request
 * granting a paid plan for free.
 */

if (empty($context['app_url'])) {
    throw new T_Skip('the application server is not running');
}

$app = $context['app_url'];

/** Read back the entitlement columns for an account. */
function t_state(int $id): array
{
    $u = t_user($id) ?: [];
    return [
        'plan'     => (string)($u['plan'] ?? '?'),
        'status'   => (string)($u['subscription_status'] ?? '?'),
        'customer' => (string)($u['stripe_customer_id'] ?? ''),
        'sub'      => (string)($u['stripe_subscription_id'] ?? ''),
        'event_at' => (string)($u['subscription_event_at'] ?? ''),
    ];
}

/** A checkout.session.completed event for $userId. */
function t_checkout_event(int $userId, string $plan, string $customer, string $sub, int $created): array
{
    return t_event('checkout.session.completed', [
        'client_reference_id' => (string)$userId,
        'metadata'            => ['plan' => $plan, 'user_id' => (string)$userId],
        'customer'            => $customer,
        'subscription'        => $sub,
    ], ['created' => $created]);
}

/** A customer.subscription.deleted event for a subscription. */
function t_delete_event(string $customer, string $sub, int $created): array
{
    return t_event('customer.subscription.deleted', [
        'id'       => $sub,
        'customer' => $customer,
    ], ['created' => $created]);
}

t_section('A completed checkout grants the plan');

$buyer = t_fixture();
$res   = t_post_webhook($app, t_checkout_event($buyer, 'pro', 'cus_wh_1', 'sub_wh_1', time()));

t_is($res['status'], 200, 'the webhook answers 200 so Stripe stops retrying');
$state = t_state($buyer);
t_is($state['plan'], 'pro', 'the plan is granted');
t_is($state['status'], 'active', 'the subscription is marked active');
t_is($state['customer'], 'cus_wh_1', 'the Stripe customer id is recorded');
t_is($state['sub'], 'sub_wh_1', 'the Stripe subscription id is recorded');
t_ok($state['event_at'] !== '', 'the ordering clock is stamped from the event');

t_section('A replayed checkout cannot re-grant a cancelled plan');

$buyer   = t_fixture();
$created = time() - 600;
$checkoutEvent = t_checkout_event($buyer, 'pro', 'cus_replay', 'sub_replay', $created);

t_post_webhook($app, $checkoutEvent);
t_is(t_state($buyer)['plan'], 'pro', 'the first delivery grants the plan');

t_post_webhook($app, t_delete_event('cus_replay', 'sub_replay', $created + 60));
t_is(t_state($buyer)['plan'], 'free', 'the cancellation downgrades to free');
t_is(t_state($buyer)['status'], 'cancelled', 'and marks the subscription cancelled');

$retry = t_post_webhook($app, $checkoutEvent);
t_is($retry['status'], 200, 'Stripe\'s retry is still acknowledged');
t_is(t_state($buyer)['plan'], 'free', 'but the replayed purchase does not hand entitlement back');

t_section('Delivery order does not change the outcome (the newest event wins)');

// A customer buys, cancels, then re-activates the SAME subscription: same
// customer, same subscription id, so identity cannot break the tie — only the
// event clock can. The stale cancellation arriving last must be ignored.
$buyer = t_fixture();
$t0    = time() - 900;

t_post_webhook($app, t_checkout_event($buyer, 'pro', 'cus_order', 'sub_order', $t0));
t_post_webhook($app, t_delete_event('cus_order', 'sub_order', $t0 + 100));
t_post_webhook($app, t_checkout_event($buyer, 'pro', 'cus_order', 'sub_order', $t0 + 200));

t_is(t_state($buyer)['plan'], 'pro', 'the re-activation takes effect');
t_is(t_state($buyer)['status'], 'active', 'and the account is active again');

// The original cancellation, redelivered late.
$late = t_post_webhook($app, t_delete_event('cus_order', 'sub_order', $t0 + 100));
t_is($late['status'], 200, 'the late delivery is acknowledged, not retried forever');
t_is(t_state($buyer)['plan'], 'pro', 'a stale cancellation cannot revoke the newer purchase');
t_is(t_state($buyer)['status'], 'active', 'and cannot flip the status back');

t_section('Out-of-order arrival of the same two events agrees');

$buyer = t_fixture();
$t0    = time() - 900;
// Same facts, delivered newest-first.
t_post_webhook($app, t_checkout_event($buyer, 'pro', 'cus_shuffle', 'sub_shuffle', $t0 + 200));
t_post_webhook($app, t_delete_event('cus_shuffle', 'sub_shuffle', $t0 + 100));
t_post_webhook($app, t_checkout_event($buyer, 'pro', 'cus_shuffle', 'sub_shuffle', $t0));

$state = t_state($buyer);
t_is($state['plan'], 'pro', 'the newest event still wins whatever order they arrive in');
t_is($state['status'], 'active', 'and the status agrees');

t_section('A stale-but-newer downgrade is refused');

$buyer = t_fixture();
t_post_webhook($app, t_checkout_event($buyer, 'entrepreneur', 'cus_down', 'sub_down', time() - 100));
t_is(t_state($buyer)['plan'], 'entrepreneur', 'they start on Entrepreneur');

t_post_webhook($app, t_checkout_event($buyer, 'pro', 'cus_down', 'sub_down', time()));
t_is(t_state($buyer)['plan'], 'entrepreneur',
    'a Pro checkout event cannot walk an Entrepreneur account backwards');

t_section('Signature failures change nothing');

$buyer = t_fixture();
$event = t_checkout_event($buyer, 'entrepreneur', 'cus_bad', 'sub_bad', time());
$body  = json_encode($event);

$unsigned = t_http('POST', $app . '/stripe-webhook.php', ['raw' => $body, 'headers' => []]);
t_is($unsigned['status'], 400, 'an unsigned request is rejected when a secret is configured');
t_is(t_state($buyer)['plan'], 'free', 'and grants nothing');

$wrongKey = t_http('POST', $app . '/stripe-webhook.php', [
    'raw'     => $body,
    'headers' => ['Stripe-Signature: ' . stripe_webhook_sign($body, 'whsec_attacker')],
]);
t_is($wrongKey['status'], 400, 'a signature made with the wrong secret is rejected');
t_is(t_state($buyer)['plan'], 'free', 'and grants nothing');

$tampered = t_http('POST', $app . '/stripe-webhook.php', [
    'raw'     => $body,
    'headers' => ['Stripe-Signature: ' . stripe_webhook_sign($body . ' ', STRIPE_WEBHOOK_SECRET)],
]);
t_is($tampered['status'], 400, 'a tampered payload is rejected');
t_is(t_state($buyer)['plan'], 'free', 'and grants nothing');

$stale = t_http('POST', $app . '/stripe-webhook.php', [
    'raw'     => $body,
    'headers' => ['Stripe-Signature: ' . stripe_webhook_sign($body, STRIPE_WEBHOOK_SECRET, time() - 3600)],
]);
t_is($stale['status'], 400, 'a replayed request outside the freshness window is rejected');
t_is(t_state($buyer)['plan'], 'free', 'and grants nothing even though its signature is genuine');

t_section('Events that should not change anything');

$buyer = t_fixture();

$noPlan = t_post_webhook($app, t_event('checkout.session.completed', [
    'client_reference_id' => (string)$buyer, 'metadata' => [],
], ['created' => time()]));
t_is($noPlan['status'], 200, 'a checkout with no plan metadata is acknowledged');
t_is(t_state($buyer)['plan'], 'free', 'and grants nothing');

$badPlan = t_post_webhook($app, t_event('checkout.session.completed', [
    'client_reference_id' => (string)$buyer, 'metadata' => ['plan' => 'platinum'],
], ['created' => time()]));
t_is($badPlan['status'], 200, 'an unknown plan name is acknowledged');
t_is(t_state($buyer)['plan'], 'free', 'and is not treated as a plan');

$freeAttempt = t_post_webhook($app, t_event('checkout.session.completed', [
    'client_reference_id' => (string)$buyer, 'metadata' => ['plan' => 'free'],
], ['created' => time()]));
t_is(t_state($buyer)['plan'], 'free', 'a checkout claiming the free plan is not a purchase');

$ghost = t_post_webhook($app, t_event('checkout.session.completed', [
    'client_reference_id' => '999999999', 'metadata' => ['plan' => 'pro'],
], ['created' => time()]));
t_is($ghost['status'], 200, 'an event for an account that does not exist is acknowledged');

$unknown = t_post_webhook($app, t_event('customer.created', ['id' => 'cus_nope'], ['created' => time()]));
t_is($unknown['status'], 200, 'an event type we do not handle is acknowledged');

t_section('A failed payment flags the account without revoking it');

$buyer = t_fixture();
t_post_webhook($app, t_checkout_event($buyer, 'pro', 'cus_pd', 'sub_pd', time() - 50));

t_post_webhook($app, t_event('invoice.payment_failed', [
    'customer' => 'cus_pd',
    'subscription' => 'sub_pd',
], ['created' => time()]));

$state = t_state($buyer);
t_is($state['status'], 'past_due', 'the account is flagged past due');
t_is($state['plan'], 'pro', 'but keeps its plan while dunning runs');

t_section('An event for an unknown customer is harmless');

$res = t_post_webhook($app, t_delete_event('cus_never_seen', 'sub_never_seen', time()));
t_is($res['status'], 200, 'a cancellation for an unknown customer is acknowledged');
