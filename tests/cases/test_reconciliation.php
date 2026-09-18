<?php
/**
 * Success reconciliation — purchase-success.php.
 *
 * This page is the safety net for a webhook that never arrived, so it can write
 * entitlement. The tests below are therefore mostly about what it must REFUSE:
 * a `?plan` parameter, a session belonging to someone else, a settled-but-unpaid
 * session, an old session id, and a cancelled subscription.
 *
 * It runs over HTTP against the real page with a real session, and reads the
 * real database afterwards.
 */

if (empty($context['app_url'])) {
    throw new T_Skip('the application server is not running');
}

$app = $context['app_url'];

/** GET the success page as a logged-in account. */
function t_success_page(string $app, int $userId, string $query): array
{
    return t_http('GET', $app . '/purchase-success.php' . $query, [
        'cookie' => t_login($userId),
    ]);
}

function t_plan_of(int $userId): string
{
    return (string)(t_user($userId)['plan'] ?? '?');
}

/** Local copy so this file does not depend on another test file's helpers. */
function t_rec_checkout_event(int $userId, string $plan, string $customer, string $sub, int $created): array
{
    return t_event('checkout.session.completed', [
        'client_reference_id' => (string)$userId,
        'metadata'            => ['plan' => $plan, 'user_id' => (string)$userId],
        'customer'            => $customer,
        'subscription'        => $sub,
    ], ['created' => $created]);
}

t_section('A verified session upgrades the account');

$buyer = t_fixture();
t_set_stripe_sessions([
    'cs_test_paid_pro' => t_session('cs_test_paid_pro', ['client_reference_id' => (string)$buyer]),
]);

$res = t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_paid_pro');
t_is($res['status'], 302, 'the page hands off to the portal');
t_is($res['location'], '/portal/index.php', 'to the dashboard');
t_is(t_plan_of($buyer), 'pro', 'the plan is reconciled from the Stripe session');
t_like($res['body'], 'utl_purchase_ob_pro', 'the purchase animation is signalled');
t_unlike($res['body'], 'onboarding.css', 'and the unused onboarding stylesheet is not loaded');

$user = t_user($buyer);
t_is($user['subscription_status'], 'active', 'the subscription is marked active');
t_is($user['stripe_subscription_id'], 'sub_test_' . substr(md5('cs_test_paid_pro'), 0, 10),
    'the subscription id is carried over from the session');

t_section('A cancelled subscription is not resurrected by a saved success URL');

$buyer       = t_fixture();
$purchasedAt = time() - 500;
$subId       = 'sub_test_' . substr(md5('cs_test_cancelled_replay'), 0, 10);

t_set_stripe_sessions([
    'cs_test_cancelled_replay' => t_session('cs_test_cancelled_replay', [
        'client_reference_id' => (string)$buyer,
        'created'             => $purchasedAt,
    ]),
]);

// The webhook records the purchase, then the cancellation 100 seconds later.
t_post_webhook($app, t_rec_checkout_event($buyer, 'pro', 'cus_replay2', $subId, $purchasedAt));
t_post_webhook($app, t_event('customer.subscription.deleted', [
    'id'       => $subId,
    'customer' => 'cus_replay2',
], ['created' => $purchasedAt + 100]));
t_is(t_plan_of($buyer), 'free', 'the account is cancelled first');

// The customer revisits their saved success URL. The session predates the
// cancellation because a session is created before the purchase completes, so
// it must lose — and it loses on time, not on a status check that would also
// have blocked a genuine re-purchase.
$res = t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_cancelled_replay');
t_is($res['status'], 302, 'the page still redirects rather than erroring');
t_is(t_plan_of($buyer), 'free', 'a stale success URL cannot resurrect the subscription');
t_unlike($res['body'], 'utl_purchase_ob', 'and it does not celebrate either');

t_section('Buying again after cancelling does work');

// The same account, a genuinely new session created after the cancellation.
// This is the case a "refuse if cancelled" guard would have broken.
t_set_stripe_sessions([
    'cs_test_rebuy' => t_session('cs_test_rebuy', [
        'client_reference_id' => (string)$buyer,
        'created'             => time(),
    ]),
]);
$res = t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_rebuy');
t_is(t_plan_of($buyer), 'pro', 'a new purchase re-grants the plan');
t_like($res['body'], 'utl_purchase_ob_pro', 'and celebrates it');
t_is(t_user($buyer)['subscription_status'], 'active', 'the account is active again');

t_section('The ?plan parameter decides nothing');

$buyer = t_fixture();
t_set_stripe_sessions([
    'cs_test_pro_only' => t_session('cs_test_pro_only', ['client_reference_id' => (string)$buyer]),
]);
// The session proves Pro; the URL asks for Entrepreneur.
t_success_page($app, $buyer, '?plan=entrepreneur&session_id=cs_test_pro_only');
t_is(t_plan_of($buyer), 'pro', 'the plan comes from the session, not the query string');

$buyer2 = t_fixture();
t_success_page($app, $buyer2, '?plan=entrepreneur');
t_is(t_plan_of($buyer2), 'free', 'a bare ?plan= with no session grants nothing');

$buyer3 = t_fixture();
t_success_page($app, $buyer3, '?plan=entrepreneur&session_id=cs_test_fabricated_1234');
t_is(t_plan_of($buyer3), 'free', 'a fabricated session id grants nothing');

$buyer4 = t_fixture();
t_success_page($app, $buyer4, '?plan=entrepreneur&session_id=' . urlencode("cs_test_a'; DROP TABLE utiligo_users;--"));
t_is(t_plan_of($buyer4), 'free', 'a malformed session id grants nothing');
t_is((string)(t_user($buyer4)['plan'] ?? '?'), 'free', 'and the account is still readable');

t_section('A session that belongs to someone else');

$victim  = t_fixture(['plan' => 'entrepreneur']);
$attacker = t_fixture();
t_set_stripe_sessions([
    'cs_test_victim' => t_session('cs_test_victim', ['client_reference_id' => (string)$victim]),
]);
t_success_page($app, $attacker, '?plan=entrepreneur&session_id=cs_test_victim');
t_is(t_plan_of($attacker), 'free', 'another account\'s session id cannot be replayed');

t_section('A session that is not settled and paid');

$cases = [
    'cs_test_open'    => ['status' => 'open',       'payment_status' => 'unpaid'],
    'cs_test_unpaid'  => ['status' => 'complete',   'payment_status' => 'unpaid'],
    'cs_test_expired' => ['status' => 'expired',    'payment_status' => 'unpaid'],
];
foreach ($cases as $id => $overrides) {
    $buyer = t_fixture();
    t_set_stripe_sessions([
        $id => t_session($id, $overrides + ['client_reference_id' => (string)$buyer]),
    ]);
    t_success_page($app, $buyer, '?plan=pro&session_id=' . $id);
    t_is(t_plan_of($buyer), 'free', "a session with status={$overrides['status']} and payment_status="
        . "{$overrides['payment_status']} grants nothing");
}

t_section('Delayed payment methods still count as paid when Stripe says so');

$buyer = t_fixture();
t_set_stripe_sessions([
    'cs_test_delayed' => t_session('cs_test_delayed', [
        'client_reference_id' => (string)$buyer,
        'payment_status'      => 'no_payment_required',
    ]),
]);
t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_delayed');
t_is(t_plan_of($buyer), 'pro', 'no_payment_required is a settled session');

t_section('An old session id is a replayed URL, not a purchase');

$buyer = t_fixture();
t_set_stripe_sessions([
    'cs_test_stale' => t_session('cs_test_stale', [
        'client_reference_id' => (string)$buyer,
        'created'             => time() - (60 * 60 * 48),
    ]),
]);
t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_stale');
t_is(t_plan_of($buyer), 'free', 'a two-day-old session id grants nothing');

t_section('Metadata is required to name a plan');

foreach ([['plan' => 'platinum'], [], ['plan' => '']] as $metadata) {
    $buyer = t_fixture();
    t_set_stripe_sessions([
        'cs_test_meta' => t_session('cs_test_meta', [
            'client_reference_id' => (string)$buyer,
            'metadata'            => $metadata,
        ]),
    ]);
    t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_meta');
    t_is(t_plan_of($buyer), 'free', 'metadata ' . json_encode($metadata) . ' grants nothing');
}

t_section('Never a downgrade');

$buyer = t_fixture(['plan' => 'entrepreneur', 'subscription_status' => 'active']);
t_set_stripe_sessions([
    'cs_test_old_pro' => t_session('cs_test_old_pro', ['client_reference_id' => (string)$buyer]),
]);
t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_old_pro');
t_is(t_plan_of($buyer), 'entrepreneur', 'an old Pro session cannot walk an Entrepreneur account back');

t_section('It can double up with the webhook without conflicting');

$buyer   = t_fixture();
$created = time();
t_set_stripe_sessions([
    'cs_test_both' => t_session('cs_test_both', [
        'client_reference_id' => (string)$buyer,
        'created'             => $created,
        'metadata'            => ['plan' => 'pro'],
    ]),
]);

// The webhook gets there first, as it usually does.
t_post_webhook($app, t_rec_checkout_event($buyer, 'pro', 'cus_both', 'sub_test_both', $created + 5));
t_is(t_plan_of($buyer), 'pro', 'the webhook grants the plan');

$res = t_success_page($app, $buyer, '?plan=pro&session_id=cs_test_both');
t_is($res['status'], 302, 'the success page still redirects');
t_is($res['location'], '/portal/index.php', 'to the dashboard');
t_is(t_plan_of($buyer), 'pro', 'and the plan is unchanged by the second writer');
t_is(t_user($buyer)['subscription_status'], 'active', 'the subscription is still active');

t_section('Logging out is not a way in');

$res = t_http('GET', $app . '/purchase-success.php?plan=entrepreneur&session_id=cs_test_paid_pro');
t_is($res['status'], 302, 'a signed-out visitor is redirected');
t_is($res['location'], '/login.php', 'to the login page');
