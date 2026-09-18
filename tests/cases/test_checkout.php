<?php
/**
 * Checkout creation.
 *
 * Does the purchase ask Stripe for the right thing, and is what it asks for
 * recorded where the later stages can trust it? These assertions run against
 * tests/lib/stripe_stub.php, so nothing here reaches api.stripe.com.
 */

if (empty($context['stub_url'])) {
    throw new T_Skip('the Stripe stub server is not running (is STRIPE_API_BASE reachable?)');
}

putenv('STRIPE_API_BASE=' . $context['stub_url']);

t_section('What a purchase asks Stripe for');

$user   = ['id' => 42, 'email' => 'buyer@example.test'];
$params = stripe_checkout_session_params($user, 'entrepreneur', 'price_test_ent', 'https://utiligo.ca');

t_is($params['mode'], 'subscription', 'it is a subscription checkout');
t_is($params['line_items[0][price]'], 'price_test_ent', 'the price id matches the chosen plan');
t_is($params['customer_email'], 'buyer@example.test', 'the receipt goes to the account email');

t_section('The stamp the later stages depend on');

t_is($params['client_reference_id'], '42', 'the payer is stamped server-side');
t_is($params['metadata[plan]'], 'entrepreneur', 'the plan is stamped server-side, not taken from a URL');
t_is($params['metadata[user_id]'], '42', 'so is the user id');
t_is($params['subscription_data[metadata][plan]'], 'entrepreneur',
    'the plan is carried onto the subscription for its lifecycle events');

t_section('Redirect URLs');

t_like($params['success_url'], 'https://utiligo.ca/purchase-success.php?plan=entrepreneur',
    'the success URL comes back to this application');
t_like($params['success_url'], '{CHECKOUT_SESSION_ID}',
    'it carries Stripe\'s session placeholder, which is what proves the purchase');
t_is($params['cancel_url'],
    'https://utiligo.ca/portal/billing.php?upgrade=1&plan=entrepreneur&cancelled=1',
    'cancelling returns to the billing page');

t_section('The request that actually goes over the wire');

t_reset_stripe_stub();
$result = stripe_request('POST', 'v1/checkout/sessions', $params);

t_ok($result['ok'], 'the create call succeeds');
t_like((string)($result['data']['url'] ?? ''), 'checkout.stripe.test', 'and returns a hosted checkout URL');

$sent = t_last_stripe_request();
t_is($sent['method'] ?? '', 'POST', 'the stub received a POST');
t_is($sent['path'] ?? '', '/v1/checkout/sessions', 'at the sessions endpoint');
t_is($sent['auth'] ?? '', STRIPE_SECRET_KEY, 'the secret key is sent as basic auth');
// parse_str() expands the bracket notation into nested arrays, so these are
// asserted the way a form decoder sees them rather than as flat keys.
t_is($sent['form']['metadata']['plan'] ?? '', 'entrepreneur', 'the plan really travelled to Stripe');
t_is($sent['form']['client_reference_id'] ?? '', '42', 'so did the payer');
t_is($sent['form']['mode'] ?? '', 'subscription', 'and the mode');
t_is($sent['form']['subscription_data']['metadata']['plan'] ?? '', 'entrepreneur',
    'and the subscription metadata');

t_section('Pro uses the Pro price, not the Entrepreneur one');

$proParams = stripe_checkout_session_params(['id' => 7, 'email' => 'p@example.test'], 'pro', 'price_test_pro', 'https://utiligo.ca');
t_is($proParams['line_items[0][price]'], 'price_test_pro', 'the Pro price id is used');
t_is($proParams['metadata[plan]'], 'pro', 'the Pro plan is stamped');
t_not($proParams['line_items[0][price]'], $params['line_items[0][price]'],
    'the two plans do not share a price');

t_section('Session retrieval and failure handling');

$missing = stripe_request('GET', 'v1/checkout/sessions/cs_test_does_not_exist');
t_ok(!$missing['ok'], 'an unregistered session is not reported as ok');
t_is($missing['http'], 404, 'the HTTP status is surfaced');
t_like($missing['error'], '404', 'and so is an error description');

t_set_stripe_sessions(['cs_test_shape' => t_session('cs_test_shape')]);
$found = stripe_request('GET', 'v1/checkout/sessions/cs_test_shape');
t_ok($found['ok'], 'a registered session is returned');
t_is($found['data']['status'] ?? '', 'complete', 'with its status intact');
t_is($found['data']['metadata']['plan'] ?? '', 'pro', 'and its metadata intact');
