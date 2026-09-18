<?php
/**
 * Cancellation, end to end.
 *
 * Two distinct things cancel an account and they must not be confused:
 *
 *   • customer.subscription.deleted — Stripe saying the subscription has ended.
 *     That is the real downgrade.
 *   • the self-service cancel button — the customer asking to stop. Features
 *     keep running to the end of the period, so the plan is deliberately left
 *     alone here.
 *
 * The case worth naming is re-subscription: a customer who cancels and buys
 * again ends up with a NEW subscription id, and the OLD subscription's deletion
 * event can arrive after the new purchase. Ordering by time alone cannot save
 * them, because that event is genuinely the newest thing Stripe sent — only
 * matching on the subscription id can.
 */

if (empty($context['app_url'])) {
    throw new T_Skip('the application server is not running');
}

$app = $context['app_url'];

function t_cancel_state(int $id): array
{
    $u = t_user($id) ?: [];
    return [
        'plan' => (string)($u['plan'] ?? '?'),
        'status' => (string)($u['subscription_status'] ?? '?'),
        'sub' => (string)($u['stripe_subscription_id'] ?? ''),
    ];
}

function t_cancel_checkout(int $userId, string $plan, string $customer, string $sub, int $created): array
{
    return t_event('checkout.session.completed', [
        'client_reference_id' => (string)$userId,
        'metadata'            => ['plan' => $plan, 'user_id' => (string)$userId],
        'customer'            => $customer,
        'subscription'        => $sub,
    ], ['created' => $created]);
}

function t_cancel_deleted(string $customer, string $sub, int $created): array
{
    return t_event('customer.subscription.deleted', [
        'id'       => $sub,
        'customer' => $customer,
    ], ['created' => $created]);
}

t_section('Stripe ending a subscription downgrades the account');

$buyer = t_fixture();
t_post_webhook($app, t_cancel_checkout($buyer, 'pro', 'cus_c1', 'sub_c1', time() - 100));
t_is(t_cancel_state($buyer)['plan'], 'pro', 'the purchase grants Pro');

t_post_webhook($app, t_cancel_deleted('cus_c1', 'sub_c1', time()));
$state = t_cancel_state($buyer);
t_is($state['plan'], 'free', 'the deletion returns the account to free');
t_is($state['status'], 'cancelled', 'and records that it was cancelled');

t_section('The OLD subscription\'s deletion cannot cancel a NEW one');

$buyer = t_fixture();
$t0    = time() - 1000;

// Buys, cancels, then buys again — a new subscription id, same customer.
t_post_webhook($app, t_cancel_checkout($buyer, 'pro', 'cus_c2', 'sub_first', $t0));
t_post_webhook($app, t_cancel_deleted('cus_c2', 'sub_first', $t0 + 100));
t_post_webhook($app, t_cancel_checkout($buyer, 'entrepreneur', 'cus_c2', 'sub_second', $t0 + 200));
t_is(t_cancel_state($buyer)['plan'], 'entrepreneur', 'the second purchase grants Entrepreneur');
t_is(t_cancel_state($buyer)['sub'], 'sub_second', 'and the account is on the new subscription');

// The first subscription's deletion finally arrives. Its timestamp is newer
// than nothing that matters — but it is for a subscription that no longer
// belongs to this account.
$late = t_post_webhook($app, t_cancel_deleted('cus_c2', 'sub_first', $t0 + 150));
t_is($late['status'], 200, 'the late delivery is acknowledged');
$state = t_cancel_state($buyer);
t_is($state['plan'], 'entrepreneur', 'and the account keeps the plan it bought');
t_is($state['status'], 'active', 'the status is untouched too');

t_section('A deletion for the CURRENT subscription still works');

t_post_webhook($app, t_cancel_deleted('cus_c2', 'sub_second', $t0 + 400));
t_is(t_cancel_state($buyer)['plan'], 'free', 'the current subscription can still be cancelled');

t_section('A legacy account with no recorded subscription id is still cancellable');

// Accounts that predate the stripe_subscription_id column have NULL there. A
// real cancellation must not be lost for them, even though identity cannot be
// checked.
$buyer = t_fixture();
t_post_webhook($app, t_cancel_checkout($buyer, 'pro', 'cus_legacy', 'sub_legacy', time() - 500));
t_db()->prepare('UPDATE utiligo_users SET stripe_subscription_id = NULL WHERE id = ?')->execute([$buyer]);
t_is(t_cancel_state($buyer)['sub'], '', 'the fixture is back to a NULL subscription id');

t_post_webhook($app, t_cancel_deleted('cus_legacy', 'sub_legacy', time()));
t_is(t_cancel_state($buyer)['plan'], 'free', 'the cancellation is applied rather than lost');

t_section('A deletion for an unknown customer changes nothing');

$buyer = t_fixture();
t_post_webhook($app, t_cancel_checkout($buyer, 'pro', 'cus_known', 'sub_known', time() - 100));
$res = t_post_webhook($app, t_cancel_deleted('cus_someone_else', 'sub_elsewhere', time()));
t_is($res['status'], 200, 'it is acknowledged');
t_is(t_cancel_state($buyer)['plan'], 'pro', 'and no unrelated account is touched');

t_section('Self-service cancel keeps the plan until the period ends');

// The button is a local action with no Stripe event behind it, so it is
// exercised through the module the billing page calls.
$buyer = t_fixture(['plan' => 'pro', 'subscription_status' => 'active']);

$result = entitlement_set_status($buyer, 'cancelled', ['source' => 'test.self_service_cancel']);
t_ok($result['applied'], 'the status change is applied');
$state = t_cancel_state($buyer);
t_is($state['status'], 'cancelled', 'the account reads as cancelled');
t_is($state['plan'], 'pro', 'but keeps Pro — which is what the page promises the customer');

t_section('Cancelling twice is harmless');

$again = entitlement_set_status($buyer, 'cancelled', ['source' => 'test.self_service_cancel']);
t_is($again['reason'], 'no change', 'a second cancel is reported as a no-op');
t_is(t_cancel_state($buyer)['plan'], 'pro', 'and still does not drop the plan');

// 'no change' is the right thing to return, but the log should distinguish a
// guarded refusal from a genuine nothing-to-do, or an incident cannot be read.
t_like(
    entitlement_no_change_detail(
        t_db(),
        ['user_id' => $buyer, 'status' => 'cancelled'],
        t_user($buyer),
        null,
        'cancelled'
    ),
    'already in the requested state',
    'the no-op is explained as already being in that state'
);

t_section('Re-subscribing after cancelling works, through both writers');

// The self-service cancel, then a genuine new purchase. This is the case a
// "refuse while the account is cancelled" guard would have silently broken —
// a paying customer left on the free plan.
$buyer = t_fixture(['plan' => 'pro', 'subscription_status' => 'active']);
entitlement_set_status($buyer, 'cancelled', ['source' => 'test.cancel', 'stamp_clock' => true]);
t_is(t_cancel_state($buyer)['status'], 'cancelled', 'the customer cancels');

// The purchase happens after the click, as it would in reality. The one-second
// gap keeps the ordering unambiguous, since the cancellation clock is stamped
// with the wall clock at the moment of the click.
sleep(1);
t_post_webhook($app, t_cancel_checkout($buyer, 'pro', 'cus_again', 'sub_again', time()));
t_is(t_cancel_state($buyer)['plan'], 'pro', 'a fresh purchase after cancelling re-grants the plan');
t_is(t_cancel_state($buyer)['status'], 'active', 'and clears the cancelled status');

// A saved success URL from BEFORE the cancellation must still lose — now on
// time alone, since there is no cancelled-status guard to lean on.
entitlement_set_status($buyer, 'cancelled', ['source' => 'test.cancel', 'stamp_clock' => true]);
t_post_webhook($app, t_cancel_checkout($buyer, 'pro', 'cus_again', 'sub_again', time() - 400));
t_is(t_cancel_state($buyer)['plan'], 'pro',
    'an older purchase event cannot undo a later cancellation');

// Stripe ends the subscription, and the customer buys again afterwards.
sleep(1);
t_post_webhook($app, t_cancel_deleted('cus_again', 'sub_again', time()));
t_is(t_cancel_state($buyer)['plan'], 'free', 'Stripe ends the subscription');

sleep(1);
t_post_webhook($app, t_cancel_checkout($buyer, 'entrepreneur', 'cus_again', 'sub_third', time()));
t_is(t_cancel_state($buyer)['plan'], 'entrepreneur', 'and the customer can buy again afterwards');

// The subscription they already cancelled cannot be cancelled a second time
// underneath the new one. This event is the NEWEST thing Stripe has sent, so
// only the subscription id can refuse it.
sleep(1);
t_post_webhook($app, t_cancel_deleted('cus_again', 'sub_again', time()));
t_is(t_cancel_state($buyer)['plan'], 'entrepreneur',
    'a second deletion for the old subscription changes nothing');
t_is(t_cancel_state($buyer)['status'], 'active', 'and the account stays active');

// That refusal is correct, and the log has to say WHICH guard refused it: a
// support engineer sees no change either way, and the difference between "this
// was a replay" and "this names a subscription we replaced" is the whole bug.
t_like(
    entitlement_no_change_detail(
        t_db(),
        [
            'user_id'            => $buyer,
            'subscription_id'    => 'sub_again',
            'match_subscription' => true,
        ],
        t_user($buyer),
        null,
        'cancelled'
    ),
    'it names subscription sub_again, but the account is on sub_third',
    'and the explanation names both subscriptions'
);

t_section('An admin change is authoritative and immediate');

$buyer = t_fixture(['plan' => 'entrepreneur', 'subscription_status' => 'active']);
$result = entitlement_set_plan_local($buyer, 'free', ['source' => 'test.admin']);
t_ok($result['applied'], 'the downgrade applies');
t_is(t_cancel_state($buyer)['plan'], 'free', 'the plan is cleared');
t_is(t_cancel_state($buyer)['status'], 'none', 'and the status follows the plan');

$result = entitlement_set_plan_local($buyer, 'entrepreneur', ['source' => 'test.admin']);
t_ok($result['applied'], 'an admin can grant a plan back');
t_is(t_cancel_state($buyer)['plan'], 'entrepreneur', 'the plan is restored');

t_section('An older Stripe event cannot undo an admin decision');

// An operator clears a plan that Stripe still believes is live. The event
// describing that purchase is older than the operator's action, and by rank it
// WOULD be an upgrade — so only the ordering clock can refuse it.
$buyer2 = t_fixture(['plan' => 'entrepreneur', 'subscription_status' => 'active']);
entitlement_set_plan_local($buyer2, 'free', ['source' => 'test.admin']);
t_is(t_cancel_state($buyer2)['plan'], 'free', 'the operator clears the plan');

t_post_webhook($app, t_cancel_checkout($buyer2, 'pro', 'cus_admin', 'sub_admin', time() - 500));
t_is(t_cancel_state($buyer2)['plan'], 'free',
    'a Stripe event older than the admin action is refused, even though it is an upgrade by rank');

t_post_webhook($app, t_cancel_checkout($buyer2, 'pro', 'cus_admin', 'sub_admin', time() + 5));
t_is(t_cancel_state($buyer2)['plan'], 'pro',
    'while a genuinely newer purchase is still honoured');
