<?php
/**
 * customer.subscription.created / .updated
 *
 * The events that carry everything which happens to a subscription AFTER the
 * sale. Two layers, because they are two different claims:
 *
 *   1. The intent table, asserted directly. entitlement_subscription_intent() is
 *      a pure function of the payload, so all fourteen status rows can be checked
 *      with no server, no database and no Stripe.
 *
 *   2. The same handlers end to end — a genuinely signed POST to the real
 *      stripe-webhook.php, then a read of the real row. "The intent was right" is
 *      not the same claim as "the column changed".
 *
 * Helper names are prefixed t_sub_ rather than reusing test_webhook.php's t_state
 * and t_delete_event: run.php globs these files in alphabetical order, so this
 * file is executed BEFORE test_webhook.php and would otherwise call functions
 * that do not exist yet.
 */

require_once __DIR__ . '/../../includes/entitlements.php';

/** Read back the entitlement columns for an account. */
function t_sub_state(int $id): array
{
    $u = t_user($id) ?: [];
    return [
        'plan'     => (string)($u['plan'] ?? '?'),
        'status'   => (string)($u['subscription_status'] ?? '?'),
        'customer' => (string)($u['stripe_customer_id'] ?? ''),
        'sub'      => (string)($u['stripe_subscription_id'] ?? ''),
        'event_at' => (string)($u['subscription_event_at'] ?? ''),
        'started'  => (string)($u['subscription_started_at'] ?? ''),
    ];
}

/**
 * A subscription object as Stripe sends it.
 *
 * $overrides carries the parts a test varies: status, the price it is on, what
 * our metadata says (which is NOT the same thing — that is the bug), and the
 * period-end flag.
 */
function t_sub_object(string $customer, string $sub, array $overrides = []): array
{
    $object = [
        'id'                   => $sub,
        'object'               => 'subscription',
        'customer'             => $customer,
        'status'               => $overrides['status'] ?? 'active',
        'items'                => ['data' => [[
            'price' => ['id' => $overrides['price'] ?? STRIPE_PRO_PRICE_ID],
        ]]],
        'metadata'             => [
            'plan'    => $overrides['meta_plan'] ?? 'pro',
            'user_id' => (string)($overrides['user_id'] ?? ''),
        ],
    ];

    if (array_key_exists('cancel_at_period_end', $overrides)) {
        $object['cancel_at_period_end'] = $overrides['cancel_at_period_end'];
    }

    return $object;
}

/** A customer.subscription.* event for $sub. */
function t_sub_event(string $type, string $customer, string $sub, array $overrides = [], ?int $created = null): array
{
    $event = t_event($type, t_sub_object($customer, $sub, $overrides));
    if ($created !== null) {
        $event['created'] = $created;
    }
    return $event;
}

/** A checkout.session.completed event, for setting up a starting state. */
function t_sub_checkout(int $userId, string $plan, string $customer, string $sub, int $created): array
{
    return t_event('checkout.session.completed', [
        'client_reference_id' => (string)$userId,
        'metadata'            => ['plan' => $plan, 'user_id' => (string)$userId],
        'customer'            => $customer,
        'subscription'        => $sub,
    ], ['created' => $created]);
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Layer 1 — the intent table, without a database
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A subscription names the plan by its price, not by our metadata');

// The order matters more than it looks. checkout stamps metadata[plan] once and
// Stripe's billing portal changes the PRICE, so a lookup that prefers metadata
// reports the plan a customer started on for the rest of their life.
$portalUpgrade = t_sub_object('cus_intent', 'sub_intent', [
    'price'    => STRIPE_ENT_PRICE_ID,
    'meta_plan' => 'pro',
]);
t_is(entitlement_plan_from_subscription($portalUpgrade), 'entrepreneur',
    'a price that says entrepreneur beats metadata that still says pro');

$portalDowngrade = t_sub_object('cus_intent', 'sub_intent', [
    'price'    => STRIPE_PRO_PRICE_ID,
    'meta_plan' => 'entrepreneur',
]);
t_is(entitlement_plan_from_subscription($portalDowngrade), 'pro',
    'and the same the other way round');

t_is(entitlement_plan_from_subscription(t_sub_object('c', 's', ['price' => 'price_unknown', 'meta_plan' => 'pro'])),
    'pro', 'an unrecognised price falls back to metadata rather than giving up');

t_is(entitlement_plan_from_subscription(t_sub_object('c', 's', ['price' => 'price_unknown', 'meta_plan' => 'free'])),
    null, 'but the fallback cannot turn a sync into a downgrade to free');

t_is(entitlement_plan_from_subscription(t_sub_object('c', 's', ['price' => 'price_unknown', 'meta_plan' => 'nonsense'])),
    null, 'and an unknown plan name is not a plan');

t_is(entitlement_plan_from_price_id('YOUR_STRIPE_PRO_PRICE_ID'), null,
    'an unconfigured placeholder price never matches');

/* ─────────────────────────────────────────────────────────────────────────────
 * Layer 2 — the same mapping, end to end
 * ──────────────────────────────────────────────────────────────────────────── */

if (empty($context['app_url'])) {
    throw new T_Skip('the application server is not running');
}

$app = $context['app_url'];

t_section('A plan change made in Stripe\'s portal is picked up');

$buyer = t_fixture();
$t0    = time() - 600;

// Bought Pro through checkout: metadata[plan] = 'pro' is stamped on the
// subscription, and the price is Pro's.
t_post_webhook($app, t_sub_checkout($buyer, 'pro', 'cus_portal', 'sub_portal', $t0));
t_is(t_sub_state($buyer)['plan'], 'pro', 'the purchase grants Pro');

// Pin the start date to a sentinel before the update. subscription_started_at
// has second precision and the two writes happen inside the same second, so
// comparing the value the checkout wrote against the value afterwards cannot
// tell a preserved column from a rewritten one — the assertion would pass on the
// very bug it is there to catch.
t_db()->prepare('UPDATE utiligo_users SET subscription_started_at = ? WHERE id = ?')
    ->execute(['2020-01-02 03:04:05', $buyer]);
$startedAtPurchase = t_sub_state($buyer)['started'];
t_is($startedAtPurchase, '2020-01-02 03:04:05', 'the start date is pinned so the next assertion can see a rewrite');

// The customer now switches to Entrepreneur in Stripe's billing portal. Stripe
// swaps the price; it cannot rewrite the metadata checkout stamped.
$res = t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_portal', 'sub_portal', [
    'price'     => STRIPE_ENT_PRICE_ID,
    'meta_plan' => 'pro',          // still the plan they STARTED on
    'user_id'   => $buyer,
], $t0 + 60));

t_is($res['status'], 200, 'the event is acknowledged');
$state = t_sub_state($buyer);
t_is($state['plan'], 'entrepreneur', 'the price on the subscription is what decides the plan');
t_is($state['status'], 'active', 'and the account reads active');
t_is($state['sub'], 'sub_portal', 'the subscription id is unchanged — this is a plan change, not a new subscription');
// A tier change is the same subscription. Resetting this would rewrite "member
// since" on every plan change — and it is what an event matched on the customer
// (which arrives with no user id at all) would do if the first-grant test were
// asked with a user id of 0 every time.
t_is($state['started'], $startedAtPurchase, 'and does not restart the subscription clock');

t_section('A downgrade decided by the customer also applies');

$res = t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_portal', 'sub_portal', [
    'price'     => STRIPE_PRO_PRICE_ID,
    'meta_plan' => 'entrepreneur',
    'user_id'   => $buyer,
], $t0 + 120));

t_is($res['status'], 200, 'acknowledged');
t_is(t_sub_state($buyer)['plan'], 'pro',
    'moving down a tier is applied — the event clock, not the never-downgrade rule, is what refuses a STALE downgrade');

t_section('A recovered payment un-flags the account');

// The bug: invoice.payment_failed flags the account, and until this event was
// handled nothing ever cleared it. Stripe re-activates the subscription when its
// retry succeeds, and the customer's own billing page went on saying past due.
$buyer = t_fixture();
$t1    = time() - 600;

t_post_webhook($app, t_sub_checkout($buyer, 'pro', 'cus_recover', 'sub_recover', $t1));
t_post_webhook($app, t_event('invoice.payment_failed', [
    'customer'     => 'cus_recover',
    'subscription' => 'sub_recover',
], ['created' => $t1 + 60]));

$state = t_sub_state($buyer);
t_is($state['status'], 'past_due', 'a failed payment flags the account');
t_is($state['plan'], 'pro', 'and keeps the plan while dunning runs');

t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_recover', 'sub_recover', [
    'price'   => STRIPE_PRO_PRICE_ID,
    'user_id' => $buyer,
], $t1 + 120));

$state = t_sub_state($buyer);
t_is($state['status'], 'active', 'a successful retry clears the past-due flag');
t_is($state['plan'], 'pro', 'the plan is untouched by the status repair');

t_section('A cancel-at-period-end update does not revoke anything');

$buyer = t_fixture();
$t2    = time() - 600;

t_post_webhook($app, t_sub_checkout($buyer, 'pro', 'cus_period', 'sub_period', $t2));
t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_period', 'sub_period', [
    'price'                => STRIPE_PRO_PRICE_ID,
    'user_id'              => $buyer,
    'cancel_at_period_end' => true,
], $t2 + 60));

$state = t_sub_state($buyer);
t_is($state['plan'], 'pro', 'a scheduled cancellation keeps the plan they paid for');
t_is($state['status'], 'active', 'and is not a cancellation');

t_section('The statuses that do NOT entitle are not treated as entitlement');

// `incomplete` means the FIRST payment has not succeeded — there is no sale to
// honour. The event deliberately carries the highest price and matching
// metadata, so a handler that granted on any recognised plan would be caught
// here rather than quietly upgrading somebody who never paid.
$neverpaid = t_fixture(['stripe_customer_id' => 'cus_incomplete']);
t_post_webhook($app, t_sub_event('customer.subscription.created', 'cus_incomplete', 'sub_incomplete', [
    'status'    => 'incomplete',
    'price'     => STRIPE_ENT_PRICE_ID,
    'meta_plan' => 'entrepreneur',
], time() - 60));

t_is(t_sub_state($neverpaid)['plan'], 'free',
    'an incomplete subscription does not grant the plan it is priced for');

// `paused` must not revoke either: the subscription exists and was paid for, it
// has simply stopped collecting. Seeded directly rather than through a checkout
// so the assertion is about the pause and nothing else.
$paused = t_fixture([
    'plan'                   => 'pro',
    'subscription_status'    => 'active',
    'stripe_customer_id'     => 'cus_paused',
    'stripe_subscription_id' => 'sub_paused',
]);
t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_paused', 'sub_paused', [
    'status' => 'paused',
], time() - 60));

$state = t_sub_state($paused);
t_is($state['plan'], 'pro', 'pausing collection does not take the plan away');
t_is($state['status'], 'active', 'and does not rewrite the status');

t_section('A subscription that ends takes the plan with it');

$buyer = t_fixture();
$t4    = time() - 600;

t_post_webhook($app, t_sub_checkout($buyer, 'entrepreneur', 'cus_unpaid', 'sub_unpaid', $t4));
t_is(t_sub_state($buyer)['plan'], 'entrepreneur', 'the purchase grants Entrepreneur');

t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_unpaid', 'sub_unpaid', [
    'status'  => 'unpaid',
    'user_id' => $buyer,
], $t4 + 60));

$state = t_sub_state($buyer);
t_is($state['plan'], 'free', 'dunning that finished without payment revokes the plan');
t_is($state['status'], 'cancelled', 'and marks it cancelled');

$buyer = t_fixture();
$t5    = time() - 600;
t_post_webhook($app, t_sub_checkout($buyer, 'pro', 'cus_cancelupd', 'sub_cancelupd', $t5));
t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_cancelupd', 'sub_cancelupd', [
    'status'  => 'canceled',
    'user_id' => $buyer,
], $t5 + 60));
t_is(t_sub_state($buyer)['plan'], 'free', 'an ended subscription revokes through this path too');

t_section('Replay and ordering are identical to the checkout path');

$buyer = t_fixture();
$t6    = time() - 900;

t_post_webhook($app, t_sub_checkout($buyer, 'pro', 'cus_replay2', 'sub_replay2', $t6));
t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_replay2', 'sub_replay2', [
    'price'   => STRIPE_ENT_PRICE_ID,
    'user_id' => $buyer,
], $t6 + 100));
t_is(t_sub_state($buyer)['plan'], 'entrepreneur', 'the portal upgrade lands');

// Stripe re-delivering the SAME event after a timeout: same id, same created.
$replay = t_sub_event('customer.subscription.updated', 'cus_replay2', 'sub_replay2', [
    'price'   => STRIPE_ENT_PRICE_ID,
    'user_id' => $buyer,
], $t6 + 100);
t_post_webhook($app, $replay);
t_post_webhook($app, $replay);
t_is(t_sub_state($buyer)['plan'], 'entrepreneur', 'a redelivered update is a no-op, not an error');

// The customer now cancels, then a stale subscription update from BEFORE the
// cancellation arrives late. It must not hand entitlement back — and because it
// carries a downgrade right, this is exactly where that would have happened.
t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_replay2', 'sub_replay2', [
    'status'  => 'canceled',
    'user_id' => $buyer,
], $t6 + 200));
t_is(t_sub_state($buyer)['plan'], 'free', 'the cancellation revokes');

t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_replay2', 'sub_replay2', [
    'price'   => STRIPE_ENT_PRICE_ID,
    'user_id' => $buyer,
], $t6 + 100));

$state = t_sub_state($buyer);
t_is($state['plan'], 'free', 'a stale update cannot resurrect entitlement after a later cancellation');
t_is($state['status'], 'cancelled', 'and cannot flip the status back');

t_section('An update for a superseded subscription is ignored');

// The customer held sub_old, cancelled, and bought again — which the app records
// as sub_new. If Stripe then sends an update for sub_old, acting on it would
// overwrite the plan they are actually paying for.
$buyer = t_fixture(['plan' => 'free', 'subscription_status' => 'none']);

t_post_webhook($app, t_sub_checkout($buyer, 'pro', 'cus_two', 'sub_old', time() - 900));
t_post_webhook($app, t_sub_checkout($buyer, 'entrepreneur', 'cus_two', 'sub_new', time() - 600));
t_is(t_sub_state($buyer)['plan'], 'entrepreneur', 'the newer purchase wins');

// A NEWER timestamp on the OLD subscription, so only the identity check can
// stop it — the event clock cannot.
t_post_webhook($app, t_sub_event('customer.subscription.updated', 'cus_two', 'sub_old', [
    'price'   => STRIPE_PRO_PRICE_ID,
    'user_id' => $buyer,
], time() - 60));

$state = t_sub_state($buyer);
t_is($state['plan'], 'entrepreneur', 'an update naming a replaced subscription cannot change the plan');
t_is($state['sub'], 'sub_new', 'and cannot steal the recorded subscription id');

t_section('A subscription created outside checkout is still honoured');

// Nothing here went through checkout: no metadata, no client_reference_id. The
// price is the only evidence, and the customer id is what identifies the payer.
$buyer = t_fixture(['stripe_customer_id' => 'cus_direct']);
t_post_webhook($app, t_sub_event('customer.subscription.created', 'cus_direct', 'sub_direct', [
    'price'     => STRIPE_ENT_PRICE_ID,
    'meta_plan' => '',
], time() - 60));

$state = t_sub_state($buyer);
t_is($state['plan'], 'entrepreneur', 'a dashboard-made subscription grants by customer id and price');
t_is($state['sub'], 'sub_direct', 'and its subscription id is recorded');
t_ok($state['started'] !== '',
    'and the subscription clock is set — asked by customer, since this event carries no user id');
