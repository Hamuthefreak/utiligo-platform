<?php
/**
 * Webhook signature verification and the replay window.
 *
 * This one matters disproportionately. The webhook is the only unauthenticated
 * endpoint that can change what a customer is entitled to, and the previous
 * implementation short-circuited to "verified" whenever the signature header was
 * empty — so an unsigned POST could grant a paid plan. That case is pinned
 * below, by name.
 */

$secret  = 'whsec_test_stub_secret';
$payload = json_encode([
    'id'      => 'evt_test_sig',
    'type'    => 'checkout.session.completed',
    'created' => time(),
    'data'    => ['object' => ['client_reference_id' => '1', 'metadata' => ['plan' => 'pro']]],
]);

$verify = fn(string $body, string $header, string $sec, ?int $tol = null): bool
    => stripe_webhook_verify_signature($body, $header, $sec, $tol);

t_section('A correctly signed request');

t_ok($verify($payload, stripe_webhook_sign($payload, $secret), $secret),
    'a fresh, correctly signed request is accepted');
t_ok($verify($payload, stripe_webhook_sign($payload, $secret, time() - 100), $secret),
    'a request from within the window is accepted');

t_section('Forgery');

t_ok(!$verify($payload . ' ', stripe_webhook_sign($payload, $secret), $secret),
    'a tampered payload is rejected');
t_ok(!$verify($payload, stripe_webhook_sign($payload, 'whsec_attacker'), $secret),
    'a signature made with a different secret is rejected');
t_ok(!$verify($payload, 't=' . time() . ',v1=' . str_repeat('a', 64), $secret),
    'a well-formed but wrong signature is rejected');

t_section('The unsigned-request hole');

t_ok(!$verify($payload, '', $secret),
    'an UNSIGNED request is rejected when a real secret is configured');
t_ok(!$verify($payload, '', $secret, 999999),
    'a long tolerance window does not make an unsigned request acceptable');

t_section('Stripe not configured (placeholders)');

t_ok($verify($payload, '', 'YOUR_STRIPE_WEBHOOK_SECRET'),
    'a placeholder secret skips verification — Stripe is not set up on this install');
t_ok($verify($payload, stripe_webhook_sign($payload, 'anything'), 'YOUR_STRIPE_WEBHOOK_SECRET'),
    'and it skips regardless of what the header says');
t_ok($verify($payload, '', ''),
    'an empty secret also means verification is not applicable');

t_section('Replay: a valid signature is not the same as a fresh request');

t_ok(!$verify($payload, stripe_webhook_sign($payload, $secret, time() - 3600), $secret),
    'a correctly signed request from an hour ago is rejected');
t_ok(!$verify($payload, stripe_webhook_sign($payload, $secret, time() + 3600), $secret),
    'a far-future timestamp is rejected too');
t_ok(!$verify($payload, stripe_webhook_sign($payload, $secret, time() - 500), $secret),
    'a request outside the default 300s window is rejected');

t_section('The window boundary is configurable, not accidental');

t_ok($verify($payload, stripe_webhook_sign($payload, $secret, time() - 500), $secret, 1000),
    'a wider tolerance accepts the same request');
t_ok(!$verify($payload, stripe_webhook_sign($payload, $secret, time() - 10), $secret, 2),
    'a narrower tolerance rejects a ten-second-old request');
t_is(STRIPE_WEBHOOK_TOLERANCE, 300, 'the shipped default matches Stripe\'s own SDKs');

t_section('Malformed headers');

t_ok(!$verify($payload, 'garbage', $secret), 'a header that is not key=value is rejected');
t_ok(!$verify($payload, 'v1=abc', $secret), 'a header with no timestamp is rejected');
t_ok(!$verify($payload, 't=0,v1=abc', $secret), 'a zero timestamp is rejected');
t_ok(!$verify($payload, 't=abc,v1=abc', $secret), 'a non-numeric timestamp is rejected');
t_ok(!$verify($payload, 't=' . time() . ',v1=', $secret), 'an empty signature is rejected');

t_section('Secret rotation (Stripe sends several v1 values)');

$ts      = time();
$rotated = 't=' . $ts
         . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, 'whsec_previous')
         . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, $secret);
t_ok($verify($payload, $rotated, $secret), 'a valid signature among several is accepted');

$bothWrong = 't=' . $ts
           . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, 'whsec_a')
           . ',v1=' . hash_hmac('sha256', $ts . '.' . $payload, 'whsec_b');
t_ok(!$verify($payload, $bothWrong, $secret), 'and all of them must fail to be rejected');
