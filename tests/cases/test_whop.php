<?php
/**
 * Whop — the payment path, end to end.
 *
 * Whop is the merchant of record for both paid plans, so this file covers the
 * whole of it: the signature that proves a webhook is really Whop's, the intent
 * table that says what a verified event means, the identity rules that decide
 * which account it is about, the ledger that makes a redelivery harmless, and
 * the checkout call that carries the buyer's account id to Whop and back.
 *
 * Whop's API is never contacted. tests/lib/whop_stub.php stands in for
 * api.whop.com, and every webhook here is signed with the test secret using the
 * same Standard-Webhooks construction Whop uses — by a helper that names its own
 * key, so a broken key derivation in the application cannot sign its own way past
 * these tests.
 *
 * The five scenarios that cost money, and where each one is asserted:
 *   a forged payment grants nothing ................... "Nothing is granted without a signature"
 *   a redelivery grants twice ......................... "The same delivery twice changes nothing"
 *   a delayed event overturns a newer one ............. "An older event cannot overturn a newer one"
 *   a cancelled-then-rebought customer loses the new
 *     plan to the old membership's cancellation ....... "The old membership cannot revoke the new one"
 *   a paying customer is left on free because the
 *     handler could not identify them ................. "An unidentifiable payment asks Whop to retry"
 */

if (empty($context['app_url']) || empty($context['whop_stub_url'])) {
    throw new T_Skip('the application server and the Whop stub are required for this file');
}

$app = $context['app_url'];

t_reset_whop_stub();
t_set_whop_checkout(['id' => 'ch_test_default']);

/** An ISO-8601 instant in the shape Whop sends, $secondsAgo in the past. */
function t_whop_at(float $secondsAgo = 0.0): string
{
    return gmdate('Y-m-d\TH:i:s.000\Z', (int)round(time() - $secondsAgo));
}

/** The webhook envelope Whop delivers for a payment that just succeeded. */
function t_whop_payment_event(int $userId, string $planId, string $memberId, string $membershipId, array $dataOverrides = [], array $envelopeOverrides = []): array
{
    return t_whop_event(
        'payment.succeeded',
        t_whop_payment($userId, $planId, $memberId, $membershipId, $dataOverrides),
        $envelopeOverrides
    );
}

/** The envelope Whop delivers when access is taken away. */
function t_whop_deactivation_event(string $memberId, string $membershipId, array $dataOverrides = [], array $envelopeOverrides = []): array
{
    return t_whop_event('membership.deactivated', $dataOverrides + [
        'id'         => $membershipId,
        'member'     => ['id' => $memberId],
        'membership' => ['id' => $membershipId, 'status' => 'deactivated'],
        'status'     => 'deactivated',
    ], $envelopeOverrides);
}

/** One header set, for the tests that are about the headers themselves. */
function t_whop_headers(string $body, string $id, int $timestamp, string $key, ?string $signature = null): array
{
    return [
        'id'        => $id,
        'timestamp' => (string)$timestamp,
        'signature' => $signature ?? t_whop_signature($body, $id, $timestamp, $key),
    ];
}

/** How many ledger rows this run has left behind for one delivery id. */
function t_whop_ledger_row(string $webhookId): ?array
{
    $stmt = t_db()->prepare('SELECT * FROM whop_events WHERE webhook_id = ? LIMIT 1');
    $stmt->execute([$webhookId]);

    return $stmt->fetch() ?: null;
}

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. Configuration — the module must be usable before anyone has a Whop account
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('What the module knows about our plans');

t_is(whop_plan_id_for('pro'), WHOP_PRO_PLAN_ID, 'Pro is sold through its configured plan id');
t_is(whop_plan_id_for('entrepreneur'), WHOP_ENT_PLAN_ID, 'so is Entrepreneur');
t_is(whop_plan_id_for('PRO'), WHOP_PRO_PLAN_ID, 'the plan name is matched case-insensitively');
t_is(whop_plan_id_for('free'), '', 'the free tier is not sold through Whop, so it has no plan id');
t_is(whop_plan_id_for('nonsense'), '', 'nor is anything we do not sell');

t_is(whop_plan_for_id(WHOP_PRO_PLAN_ID), 'pro', 'a payment for the Pro plan id means Pro');
t_is(whop_plan_for_id(WHOP_ENT_PLAN_ID), 'entrepreneur', 'and Entrepreneur for the other one');
t_is(whop_plan_for_id('plan_not_ours'), null, 'a plan id we do not sell maps to nothing rather than a guess');
t_is(whop_plan_for_id(''), null, 'and an empty one maps to nothing');

t_ok(whop_can_verify(), 'a webhook secret is configured, so events can be verified');
t_ok(whop_can_create_checkout(), 'and an API key with an account id, so checkouts can carry identity');

// The YOUR_* placeholders config.php ships are not values. Reaching an accessor
// with one must produce "not configured", never a request authenticated with the
// literal string "YOUR_WHOP_API_KEY".
define('WHOP_TEST_PLACEHOLDER', 'YOUR_WHOP_TEST_VALUE');
t_is(whop_setting('WHOP_TEST_PLACEHOLDER'), '', 'a YOUR_* placeholder counts as unconfigured');
t_is(whop_setting('WHOP_TEST_NOT_DEFINED_ANYWHERE'), '', 'and so does a constant that does not exist');
t_is(whop_setting('WHOP_PRO_PLAN_ID'), WHOP_PRO_PLAN_ID, 'a real value comes through trimmed');

t_section('Where a customer is sent to pay, and to manage');

t_is(whop_plan_link('pro'), WHOP_PRO_CHECKOUT_URL, 'the shareable Pro link is the configured one');
t_is(whop_plan_link('ENTREPRENEUR'), WHOP_ENT_CHECKOUT_URL, 'and so is the Entrepreneur link');
t_is(whop_plan_link('free'), '', 'a plan with no link configured has none');

$plain = whop_checkout_url('pro');   // no account id asked for, so no API call is made
t_is($plain['url'], WHOP_PRO_CHECKOUT_URL, 'without an account to attach, checkout is the plain plan link');
t_ok(!$plain['carries_identity'], 'which carries no identity, so the webhook will have to match by email');

t_is(whop_manage_url('mber_abc123'), 'https://whop.com/billing/manage/mber_abc123', 'a member id becomes a manage url');
t_is(whop_manage_url('  '), '', 'no member id means no manage url');
t_is(whop_manage_url('../../evil'), '', 'and a stored value that is not a member id is refused, because this is a Location header');
t_is(whop_manage_url('mber' . str_repeat('x', 80)), '', 'including one long enough to be a path traversal attempt');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. The signature, which is the whole security model of the endpoint
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Nothing is granted without a signature');

$secret = t_whop_secret();
$key    = t_whop_hex_key();
$body   = json_encode(t_whop_payment_event(7, WHOP_PRO_PLAN_ID, 'mber_sig', 'mem_sig'));
$id     = 'msg_sig_test';
$now    = time();

t_ok($key !== '', 'the test secret is a ws_ secret carrying a hex key');

$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now, $key), $secret, $now);
t_ok($result['ok'], 'a delivery signed with our secret is verified');
t_ok($result['key_index'] >= 0, 'and the derivation that matched is reported, so the list can be trimmed later');

$tampered = str_replace('"status":"paid"', '"status":"paid","extra":1', $body);
t_ok($body !== $tampered, 'the tampered body really is different');
$result = whop_verify_webhook($tampered, t_whop_headers($body, $id, $now, $key), $secret, $now);
t_ok(!$result['ok'], 'a body that does not match its signature is refused');
t_like($result['reason'], 'does not match', 'and the reason says so without naming which key or signature was wrong');

$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now, random_bytes(32)), $secret, $now);
t_ok(!$result['ok'], 'a signature made with a key we do not have is refused');

$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now, $key), 'ws_' . str_repeat('cd', 32), $now);
t_ok(!$result['ok'], 'so is a correct signature verified against the wrong secret');

t_section('Replay, and being sure the clock is ours');

$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now - WHOP_WEBHOOK_TOLERANCE - 60, $key), $secret, $now);
t_ok(!$result['ok'], 'a delivery captured an hour ago is refused');
t_like($result['reason'], 'timestamp', 'the reason names the clock, not the signature — that is the cheap check, and it runs first');

$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now + WHOP_WEBHOOK_TOLERANCE + 60, $key), $secret, $now);
t_ok(!$result['ok'], 'and so is one dated in the future, which would otherwise stay replayable');

$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now - 10, $key), $secret, $now);
t_ok($result['ok'], 'while a delivery seconds old is accepted — Whop retries are re-signed, so they are not stale');

t_section('Rotating the secret, and the shapes a ws_ secret can have');

$rotated = t_whop_headers($body, $id, $now, random_bytes(32));
$rotated['signature'] = $rotated['signature'] . ' ' . t_whop_signature($body, $id, $now, $key);
$result = whop_verify_webhook($body, $rotated, $secret, $now);
t_ok($result['ok'], 'both signatures are offered during a rotation, and a match on either is accepted');

$rawSecret = 'a-secret-with-no-prefix-at-all';
$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now, $rawSecret), $rawSecret, $now);
t_ok($result['ok'], 'a secret with no ws_ prefix is used as its own bytes');

$binary = random_bytes(32);
$b64Secret = 'ws_' . base64_encode($binary);
$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now, $binary), $b64Secret, $now);
t_ok($result['ok'], 'and a ws_ secret carrying base64 is decoded to the key, which is Standard Webhooks');

$noPrefix = t_whop_headers($body, $id, $now, $key);
$noPrefix['signature'] = substr($noPrefix['signature'], 3);
$result = whop_verify_webhook($body, $noPrefix, $secret, $now);
t_ok($result['ok'], 'a signature sent without its v1, version still verifies');

$result = whop_verify_webhook($body, t_whop_headers($body, $id, $now, $key, 'v1,not-base64-at-all'), $secret, $now);
t_ok(!$result['ok'], 'and a signature that is simply wrong is still wrong');

t_section('Malformed deliveries, each refused for its own reason');

foreach ([
    'id'        => 'no webhook-id',
    'timestamp' => 'no webhook-timestamp',
    'signature' => 'no webhook-signature',
] as $missing => $label) {
    $headers = t_whop_headers($body, $id, $now, $key);
    $headers[$missing] = '';
    $result = whop_verify_webhook($body, $headers, $secret, $now);
    t_ok(!$result['ok'], 'a delivery with ' . $label . ' is refused');
}

$headers = t_whop_headers($body, $id, $now, $key);
$headers['timestamp'] = 'yesterday';
$result = whop_verify_webhook($body, $headers, $secret, $now);
t_ok(!$result['ok'], 'a timestamp that is not a unix time is refused before any hashing');

$headers = t_whop_headers($body, $id, $now, $key);
$headers['signature'] = '   ';
$result = whop_verify_webhook($body, $headers, $secret, $now);
t_ok(!$result['ok'], 'a signature header carrying only whitespace is refused');

// The '' argument means "use the configured secret", so "unconfigured" cannot be
// asserted through it — the constant is set for this whole process. It is asserted
// at the endpoint instead, against a server started without one: see "An endpoint
// with no secret refuses everything".

t_ok(!whop_parse_event(str_repeat('x', 600 * 1024))['ok'], 'a body larger than any Whop webhook is refused before it is decoded');
t_ok(!whop_parse_event('{"type":"payment.succeeded"}')['ok'], 'an envelope with no data object is refused');

t_section('A secret never reaches a message');

$leaked = whop_redact('the key ' . $secret . ' and the body ' . $body);
t_unlike($leaked, $secret, 'whop_redact() removes the webhook secret');
t_unlike($leaked, WHOP_API_KEY, 'and the API key');
t_is(whop_redact('nothing secret here'), 'nothing secret here', 'leaving an ordinary message alone');

/* ─────────────────────────────────────────────────────────────────────────────
 * 3. The event, and what it means — pure, so the whole table is assertable
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Reading the envelope');

$parsed = whop_parse_event($body);
t_ok($parsed['ok'], 'a well-formed v1 envelope parses');
t_is($parsed['type'], 'payment.succeeded', 'and reports its type');
t_is($parsed['webhook_id'], json_decode($body, true)['id'], 'and its delivery id, which is the ledger key');

t_ok(!whop_parse_event('not json at all')['ok'], 'a body that is not JSON is refused');
t_ok(!whop_parse_event('{"data":{}}')['ok'], 'an envelope with no type is refused');

$wrongVersion = whop_parse_event(json_encode(['id' => 'm1', 'type' => 'payment.succeeded', 'api_version' => 'v2', 'data' => []]));
t_ok(!$wrongVersion['ok'], 'a v2 envelope is refused, because only v1 is signed the way this code verifies');
t_like($wrongVersion['reason'], 'v2', 'and the reason names the version');

t_section('The event clock is the event time, not the arrival time');

$minuteAgo = whop_parse_event(json_encode(t_whop_payment_event(7, WHOP_PRO_PLAN_ID, 'mber_t', 'mem_t', [], ['timestamp' => t_whop_at(60)])));
$justNow   = whop_parse_event(json_encode(t_whop_payment_event(7, WHOP_PRO_PLAN_ID, 'mber_t', 'mem_t', [], ['timestamp' => t_whop_at(0)])));

t_ok($minuteAgo['event_at'] !== null && $justNow['event_at'] !== null, 'both events carry a time');
t_ok($justNow['event_at'] > $minuteAgo['event_at'], 'and the newer one sorts after the older one');

$noTimestamp = ['type' => 'payment.succeeded', 'data' => ['paid_at' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 30)]];
$fallback = whop_event_time($noTimestamp);
t_ok($fallback !== null && $fallback > 0, 'an envelope with no timestamp falls back to the payment\'s own paid_at');

t_is(whop_event_time(['type' => 'x', 'data' => []]), null, 'and an event with no time at all has none, rather than "now"');

$micro = whop_event_time(['timestamp' => '2026-01-01T00:00:00.250Z']);
t_ok($micro !== null && abs(fmod($micro, 1.0) - 0.25) < 0.0001, 'milliseconds in the envelope are kept, so two events inside a second do not tie');

t_section('What a verified event means for an account');

$intent = whop_intent(['type' => 'payment.succeeded', 'data' => ['status' => 'paid', 'plan' => ['id' => WHOP_PRO_PLAN_ID]]]);
t_is($intent['action'], 'grant', 'a paid payment for the Pro plan grants Pro');
t_is($intent['plan'], 'pro', 'and names the plan');
t_is($intent['status'], 'active', 'with an active status');

$intent = whop_intent(['type' => 'payment.succeeded', 'data' => ['status' => 'paid', 'plan' => ['id' => WHOP_ENT_PLAN_ID]]]);
t_is($intent['plan'], 'entrepreneur', 'the same event for the Entrepreneur plan grants Entrepreneur');

$intent = whop_intent(['type' => 'payment.succeeded', 'data' => ['status' => 'failed', 'plan' => ['id' => WHOP_PRO_PLAN_ID]]]);
t_is($intent['action'], 'ignore', 'a payment that does not say it is paid is not evidence of payment');
t_like($intent['reason'], 'not evidence', 'and says as much');

$intent = whop_intent(['type' => 'payment.succeeded', 'data' => ['status' => 'paid', 'plan' => ['id' => 'plan_someone_elses']]]);
t_is($intent['action'], 'ignore', 'a payment for a plan we do not sell is ignored, never guessed at');
t_like($intent['reason'], 'plan_someone_elses', 'and the log names the plan id an operator has to look at');

$intent = whop_intent(['type' => 'payment.succeeded', 'data' => [
    'status'   => 'paid',
    'plan'     => ['id' => 'plan_moved_in_the_dashboard'],
    'metadata' => ['plan' => 'entrepreneur'],
]]);
t_is($intent['plan'], 'entrepreneur', 'metadata is the fallback when a plan id has moved, and it still has to name a plan we sell');

$intent = whop_intent(['type' => 'payment.succeeded', 'data' => [
    'status'   => 'paid',
    'plan'     => ['id' => 'plan_moved_in_the_dashboard'],
    'metadata' => ['plan' => 'free'],
]]);
t_is($intent['action'], 'ignore', 'and a payment whose metadata claims the free tier is refused, not applied as a change to free');

$intent = whop_intent(['type' => 'membership.deactivated', 'data' => ['status' => 'deactivated']]);
t_is($intent['action'], 'revoke', 'a deactivated membership revokes');
t_is($intent['status'], 'cancelled', 'and marks the subscription cancelled');

$intent = whop_intent(['type' => 'membership.activated', 'data' => []]);
t_is($intent['action'], 'ignore', 'an event type we did not subscribe to is ignored');
t_like($intent['reason'], 'membership.activated', 'with the type in the reason, so a webhook edited in the dashboard is visible');

/* ─────────────────────────────────────────────────────────────────────────────
 * 4. Identity — the rules that decide whose account a payment is
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Which account a payment belongs to');

$known = t_fixture(['email' => 'whop-known@example.test']);

$resolved = whop_resolve_user(['type' => 'payment.succeeded', 'data' => ['metadata' => ['utiligo_user_id' => (string)$known]]]);
t_is($resolved['user_id'], $known, 'our own metadata resolves the account');
t_is($resolved['via'], 'metadata', 'and is reported as the identity source');

$resolved = whop_resolve_user(['type' => 'payment.succeeded', 'data' => ['metadata' => ['utiligo_user_id' => '999999']]]);
t_is($resolved['user_id'], 0, 'metadata naming an account that does not exist resolves nothing rather than failing');

$byMember = t_fixture(['email' => 'whop-member@example.test', 'whop_member_id' => 'mber_stored']);
$resolved = whop_resolve_user(['type' => 'payment.succeeded', 'data' => ['member' => ['id' => 'mber_stored']]]);
t_is($resolved['user_id'], $byMember, 'a member id we already stored resolves a renewal, which carries no metadata');
t_is($resolved['via'], 'member', 'and is reported as such');

$byEmail = t_fixture(['email' => 'whop-email@example.test', 'email_verified' => 1]);
$resolved = whop_resolve_user(['type' => 'payment.succeeded', 'data' => ['user' => ['email' => 'WHOP-EMAIL@example.test']]]);
t_is($resolved['user_id'], $byEmail, 'a unique verified email resolves a purchase made through the shareable link');
t_is($resolved['via'], 'email', 'and is reported as such, because it is the weakest of the three');

$unverified = t_fixture(['email' => 'whop-unverified@example.test', 'email_verified' => 0]);
$resolved = whop_resolve_user(['type' => 'payment.succeeded', 'data' => ['user' => ['email' => 'whop-unverified@example.test']]]);
t_is($resolved['user_id'], 0, 'an unverified address does not — a payment must never be what confirms an email');
t_like($resolved['reason'], 'no unique verified account', 'and the reason explains the refusal');

$resolved = whop_resolve_user(['type' => 'payment.succeeded', 'data' => ['user' => ['email' => 'nobody@example.test']]]);
t_is($resolved['user_id'], 0, 'nor does an address with no account behind it');

$resolved = whop_resolve_user(['type' => 'payment.succeeded', 'data' => []]);
t_is($resolved['user_id'], 0, 'and a payment carrying no identity at all resolves nothing');

t_is(entitlement_user_for_whop_member('mber_stored'), $byMember, 'the member lookup goes through the entitlement module, not a hand-written query');
t_is(entitlement_user_for_whop_member(''), 0, 'an empty member id matches nobody');
t_is(entitlement_user_for_whop_member('mber_nobody'), 0, 'and so does an unknown one');

/* ─────────────────────────────────────────────────────────────────────────────
 * 5. The ledger — what makes a redelivery harmless
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The ledger decides whether a delivery is new work');

$ledgerId = 'msg_ledger_' . bin2hex(random_bytes(4));

t_is(whop_ledger_claim($ledgerId, 'payment.succeeded', time(), $known)['state'], 'new', 'a delivery we have never seen is new work');
$row = t_whop_ledger_row($ledgerId);
t_ok($row !== null, 'and is recorded before the work starts, not after');
t_is((int)$row['user_id'], $known, 'with the account it was about, which is what an incident review reads');
t_is($row['status'], 'received', 'and a status that says the work is not finished');

t_is(whop_ledger_claim($ledgerId, 'payment.succeeded', time(), $known)['state'], 'retry', 'a second delivery while the first is unfinished is processed again, so a crashed handler cannot swallow a payment');

whop_ledger_finish($ledgerId, 'applied', 'granted pro', $known, 'pro');
t_is(whop_ledger_claim($ledgerId, 'payment.succeeded', time(), $known)['state'], 'done', 'once it is applied, a redelivery is dropped');
t_like(whop_ledger_claim($ledgerId, 'payment.succeeded', time(), $known)['reason'], 'already applied', 'and says which state it finished in');

$failedId = 'msg_ledger_failed_' . bin2hex(random_bytes(4));
whop_ledger_claim($failedId, 'payment.succeeded', time(), $known);
whop_ledger_finish($failedId, 'failed', 'database was down', $known);
t_is(whop_ledger_claim($failedId, 'payment.succeeded', time(), $known)['state'], 'retry', 'a delivery whose work failed is retried, which is the whole point of Whop\'s backoff');

$ignoredId = 'msg_ledger_ignored_' . bin2hex(random_bytes(4));
whop_ledger_claim($ignoredId, 'membership.activated', time(), $known);
whop_ledger_finish($ignoredId, 'ignored', 'not subscribed to');
t_is(whop_ledger_claim($ignoredId, 'membership.activated', time(), $known)['state'], 'done', 'and one we deliberately ignored is finished business, not a retry loop');

t_is(whop_ledger_claim('', 'payment.succeeded', time(), $known)['state'], 'new', 'a delivery with no webhook-id is processed rather than dropped, since there is nothing to duplicate');

t_is(whop_sql_time(null), null, 'a missing event time stores as NULL');
t_is(whop_sql_time(0.0), null, 'and so does a zero one');
t_is(whop_sql_time(1700000000.5), gmdate('Y-m-d H:i:s', 1700000000) . '.500000', 'while a real one keeps its microseconds, in UTC');

/* ─────────────────────────────────────────────────────────────────────────────
 * 6. The endpoint, over HTTP, against the real database
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A paid payment grants the plan, and only a paid one does');

$buyer = t_fixture(['email' => 'whop-buyer@example.test']);

// Stamped two minutes ago so the redelivery below has a stamp to lose to: an
// event inside the same second would be refused by the clock even on the first
// delivery, and that is a different test.
$buyerEvent = t_whop_payment_event($buyer, WHOP_PRO_PLAN_ID, 'mber_buyer', 'mem_buyer', [], ['timestamp' => t_whop_at(120)]);
$res = t_post_whop_webhook($app, $buyerEvent);
t_is($res['status'], 200, 'the webhook answers 200 so Whop stops retrying');
$state = t_whop_state($buyer);
t_is($state['plan'], 'pro', 'the account is on Pro');
t_is($state['status'], 'active', 'and its subscription is active');
t_is($state['member_id'], 'mber_buyer', 'the Whop member is stored, which is how the next event finds this account');
t_is($state['membership_id'], 'mem_buyer', 'and the membership, which is what a cancellation has to match');
t_ok($state['event_at'] !== '', 'and the event clock is stamped, so a replay has something to lose to');

$replay = json_decode($res['body'], true);
t_ok(!empty($replay['ok']), 'the body says the delivery was accepted');

t_section('The same delivery twice changes nothing');

$afterFirst = t_whop_state($buyer);
$row        = t_whop_ledger_row($buyerEvent['id']);
t_is($row['status'], 'applied', 'the first delivery finished as applied');

t_is(t_post_whop_webhook($app, $buyerEvent)['status'], 200, 'a redelivery is still a 200 — it is not an error, and a 500 would make Whop retry for days');
t_is(t_whop_state($buyer)['event_at'], $afterFirst['event_at'], 'and it does not move the entitlement clock, so nothing was applied twice');
t_is(t_whop_state($buyer)['plan'], 'pro', 'the plan is exactly where it was');

t_is((int)t_db()->query('SELECT COUNT(*) c FROM whop_events WHERE webhook_id = ' . t_db()->quote($buyerEvent['id']))->fetch()['c'], 1, 'the ledger holds exactly one row for that delivery, whatever Whop does');

t_section('An older event cannot overturn a newer one');

$upgrader = t_fixture(['email' => 'whop-upgrader@example.test']);

// Ordered by the events' own times, not by when they arrive:
//   t-20s  Pro          applies
//   t-90s  Entrepreneur arrives afterwards, but happened BEFORE — and it is an
//                       upgrade, so ONLY the clock can refuse it. That is the
//                       assertion that isolates the ordering guard from the
//                       never-downgrade guard, which would refuse it anyway.
//   t-10s  Entrepreneur genuinely newer — applies
//   t-0s   Pro, newest, but lower — refused, because a purchase cannot remove
//                       features the customer already has.
t_post_whop_webhook($app, t_whop_payment_event($upgrader, WHOP_PRO_PLAN_ID, 'mber_up', 'mem_up', [], ['timestamp' => t_whop_at(20)]));
t_is(t_whop_state($upgrader)['plan'], 'pro', 'the first payment applies');

$late = t_whop_payment_event($upgrader, WHOP_ENT_PLAN_ID, 'mber_up', 'mem_up', [], ['timestamp' => t_whop_at(90)]);
$res  = t_post_whop_webhook($app, $late);
t_is($res['status'], 200, 'a late delivery is answered 200 — retrying it would change nothing');
t_is(t_whop_state($upgrader)['plan'], 'pro', 'and an older event cannot upgrade the account, however late it is');
t_like(json_decode($res['body'], true)['status'], 'ignored', 'it is reported as ignored rather than applied');

t_post_whop_webhook($app, t_whop_payment_event($upgrader, WHOP_ENT_PLAN_ID, 'mber_up', 'mem_up', [], ['timestamp' => t_whop_at(10)]));
t_is(t_whop_state($upgrader)['plan'], 'entrepreneur', 'a genuinely newer Entrepreneur payment does apply');

t_post_whop_webhook($app, t_whop_payment_event($upgrader, WHOP_PRO_PLAN_ID, 'mber_up', 'mem_up', [], ['timestamp' => t_whop_at(0)]));
t_is(t_whop_state($upgrader)['plan'], 'entrepreneur', 'and the newest payment of all does not downgrade it');

t_section('An unidentified payment asks Whop to retry');

$stranger = t_whop_payment_event(0, WHOP_PRO_PLAN_ID, 'mber_stranger', 'mem_stranger', [
    'metadata' => [],
    'user'     => ['email' => 'nobody-at-all@example.test'],
]);
$res = t_post_whop_webhook($app, $stranger);
t_is($res['status'], 500, 'a verified payment nobody can be attached to asks for a retry rather than being dropped');
$body = json_decode($res['body'], true);
t_ok(empty($body['ok']), 'and does not claim to have applied anything');
t_is(t_whop_ledger_row($stranger['id'])['status'], 'failed', 'the ledger records it as failed, which is where an operator looks');

// The retry has to be able to succeed once the account can be identified, or the
// 500 is just noise. The same delivery, after the buyer verifies their address.
$verifying = t_fixture(['email' => 'verify-then-pay@example.test', 'email_verified' => 0]);
$verifyingEvent = t_whop_payment_event(0, WHOP_ENT_PLAN_ID, 'mber_verify', 'mem_verify', [
    'metadata' => [],
    'user'     => ['email' => 'verify-then-pay@example.test'],
]);
t_is(t_post_whop_webhook($app, $verifyingEvent)['status'], 500, 'an unverified address leaves the payment unidentified');

t_db()->prepare('UPDATE utiligo_users SET email_verified = 1 WHERE id = ?')->execute([$verifying]);
$res = t_post_whop_webhook($app, $verifyingEvent);
t_is($res['status'], 200, 'Whop\'s retry, after the address is verified, is accepted');
t_is(t_whop_state($verifying)['plan'], 'entrepreneur', 'and the customer who paid is finally on their plan');
t_is(t_whop_ledger_row($verifyingEvent['id'])['status'], 'applied', 'the failed ledger row is closed as applied by the retry, not left open');

t_section('A cancellation downgrades, and only the membership we hold can do it');

$res = t_post_whop_webhook($app, t_whop_deactivation_event('mber_buyer', 'mem_wrong_one'));
t_is(t_whop_state($buyer)['plan'], 'pro', 'a deactivation for a membership we do not hold is refused');
t_like(json_decode($res['body'], true)['status'], 'ignored', 'and reported as ignored rather than failed, because retrying will not help');
t_is(t_whop_state($buyer)['membership_id'], 'mem_buyer', 'the membership on file is untouched');

t_post_whop_webhook($app, t_whop_deactivation_event('mber_buyer', 'mem_buyer', [], ['timestamp' => t_whop_at(60)]));
$state = t_whop_state($buyer);
t_is($state['plan'], 'free', 'the real cancellation puts the account back on the free tier');
t_is($state['status'], 'cancelled', 'with a cancelled status');
t_is($state['membership_id'], 'mem_buyer', 'and the membership stays on file, so a second cancellation is still recognisable');

t_section('The old membership cannot revoke the new one');

$resub = t_fixture(['email' => 'whop-resub@example.test']);

t_post_whop_webhook($app, t_whop_payment_event($resub, WHOP_PRO_PLAN_ID, 'mber_resub', 'mem_first', [], ['timestamp' => t_whop_at(300)]));
t_is(t_whop_state($resub)['membership_id'], 'mem_first', 'the first subscription is recorded');

t_post_whop_webhook($app, t_whop_deactivation_event('mber_resub', 'mem_first', [], ['timestamp' => t_whop_at(200)]));
t_is(t_whop_state($resub)['plan'], 'free', 'its cancellation applies');

t_post_whop_webhook($app, t_whop_payment_event($resub, WHOP_PRO_PLAN_ID, 'mber_resub', 'mem_second', [], ['timestamp' => t_whop_at(100)]));
t_is(t_whop_state($resub)['plan'], 'pro', 'and the customer who rebought is back on Pro');
t_is(t_whop_state($resub)['membership_id'], 'mem_second', 'on a new membership');

// The first membership's cancellation finally arrives, with a NEWER timestamp —
// so ordering alone cannot save this customer. Only the membership match can.
$res = t_post_whop_webhook($app, t_whop_deactivation_event('mber_resub', 'mem_first', [], ['timestamp' => t_whop_at(0)]));
t_is(t_whop_state($resub)['plan'], 'pro', 'the cancelled-and-rebought customer keeps their new plan when the old cancellation lands late');
t_like(json_decode($res['body'], true)['status'], 'ignored', 'and the late cancellation is reported as ignored');
t_is(t_whop_state($resub)['membership_id'], 'mem_second', 'the membership on file is still the live one');

t_section('Forged and malformed deliveries never reach the database');

$forged = t_whop_payment_event($buyer, WHOP_ENT_PLAN_ID, 'mber_forged', 'mem_forged');
$res    = t_post_whop_webhook($app, $forged, ['key' => random_bytes(32)]);
t_is($res['status'], 401, 'a body signed with a key we do not have is refused');
t_is(t_whop_ledger_row($forged['id']), null, 'and leaves no ledger row at all — the database is not touched before verification');
t_is(t_whop_state($buyer)['plan'], 'free', 'and nothing was granted');

$res = t_post_whop_webhook($app, $forged, ['timestamp' => time() - 7200]);
t_is($res['status'], 401, 'a captured delivery replayed two hours later is refused');
t_is(t_whop_ledger_row($forged['id']), null, 'and likewise writes nothing');

$res = t_post_whop_webhook($app, $forged, ['headers' => []]);
t_is($res['status'], 401, 'a request with no signature headers at all is refused');

$res = t_http('GET', $app . '/whop-webhook.php');
t_is($res['status'], 405, 'and the endpoint only answers POST');

// A megabyte of junk is refused for its size rather than hashed: the cost of a
// rejection should not be proportional to what an attacker sends.
$res = t_http('POST', $app . '/whop-webhook.php', ['raw' => str_repeat('x', 600 * 1024)]);
t_is($res['status'], 413, 'an oversized body is refused before any work is done on it');
t_is(t_whop_ledger_row('msg_oversized'), null, 'and of course writes nothing');

t_section('An endpoint with no secret refuses everything');

// A deployment that forgot to copy WHOP_WEBHOOK_SECRET must not accept unsigned
// events, and config.php's YOUR_ placeholder is what "forgot" looks like. The
// constant cannot be unset in this process, so the assertion needs a server of
// its own — which is also the honest way to test it: the endpoint is a separate
// process with its own configuration.
$unconfigured = t_server(dirname(__DIR__, 2), null, ['WHOP_WEBHOOK_SECRET' => 'YOUR_WHOP_WEBHOOK_SECRET'], 'app without a whop secret', [
    '-d', 'session.save_path=' . t_sessions_dir(),
    '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/lib/prepend.php',
]);

$noSecret = t_whop_payment_event(0, WHOP_ENT_PLAN_ID, 'mber_nosecret', 'mem_nosecret', [
    'metadata' => ['utiligo_user_id' => (string)$buyer],
], ['timestamp' => t_whop_at(0)]);
$res = t_post_whop_webhook($unconfigured['url'], $noSecret);
t_is($res['status'], 401, 'a correctly signed event is refused by an endpoint with no secret to verify it against');
t_is(t_whop_ledger_row($noSecret['id']), null, 'and it writes nothing at all, which is what fail-closed means');

$res = t_http('GET', $unconfigured['url'] . '/whop-checkout.php?plan=pro');
t_is($res['status'], 302, 'the checkout page still answers on a deployment with no webhook secret');
t_like((string)$res['location'], '/register.php', 'by sending the visitor to sign up first, exactly as a configured one does');

t_section('Authenticated nonsense is ignored, not applied');

$signedButUnreadable = t_post_whop_webhook($app, 'this is not json');
t_is($signedButUnreadable['status'], 200, 'a correctly signed body that is not JSON is accepted and ignored, so Whop stops retrying');

$wrongType = t_post_whop_webhook($app, t_whop_event('membership.activated', ['id' => 'mem_x', 'member' => ['id' => 'mber_buyer']]));
t_is($wrongType['status'], 200, 'an event type we did not subscribe to is a 200 and a no-op');
t_is(t_whop_state($buyer)['plan'], 'free', 'and changes nothing');

// A plan id that moved in Whop's dashboard, with the plan name still in the
// metadata our own checkout wrote: the fallback earns its place here.
t_ok(is_paid_plan('pro'), 'pro is a plan we sell, which is what makes the fallback safe');
$movedPlan = t_whop_payment_event($buyer, 'plan_moved_in_the_dashboard', 'mber_buyer', 'mem_buyer_2', [
    'metadata' => ['utiligo_user_id' => (string)$buyer, 'plan' => 'pro'],
], ['timestamp' => t_whop_at(40)]);
t_is(t_post_whop_webhook($app, $movedPlan)['status'], 200, 'a paid payment for a plan id we do not recognise is accepted');
t_is(t_whop_state($buyer)['plan'], 'pro', 'and the plan name in the metadata is what decides the tier, so a renamed plan id does not cost a customer their plan');

// A plan carrying its OWN metadata — set once in Whop's dashboard, so a renamed
// plan id still says which tier it stands for even when our per-checkout metadata
// is gone. This is the last resort, and it is asserted because it is the one
// identity in the payload that is not written per purchase.
$dashboardNamed = t_whop_payment_event($buyer, 'plan_renamed_in_whop', 'mber_buyer', 'mem_buyer_4', [
    'plan'     => ['id' => 'plan_renamed_in_whop', 'metadata' => ['plan' => 'entrepreneur']],
    'metadata' => ['utiligo_user_id' => (string)$buyer],
], ['timestamp' => t_whop_at(35)]);
t_is(t_post_whop_webhook($app, $dashboardNamed)['status'], 200, 'an unrecognised plan id whose plan names a tier is accepted');
t_is(t_whop_state($buyer)['plan'], 'entrepreneur', 'and that name decides the tier, so a plan edited in the dashboard still grants what it stands for');

// The same event with nothing naming a plan anywhere must not be guessed at.
$unnameable = t_whop_payment_event($buyer, 'plan_someone_elses', 'mber_buyer', 'mem_buyer_5', [
    'plan'     => ['id' => 'plan_someone_elses'],
    'metadata' => ['utiligo_user_id' => (string)$buyer],
], ['timestamp' => t_whop_at(30)]);
$res = t_post_whop_webhook($app, $unnameable);
t_is($res['status'], 200, 'a paid payment for a plan nobody can name is accepted and ignored');
t_is(t_whop_state($buyer)['plan'], 'entrepreneur', 'and the account keeps the plan it has rather than getting a guessed tier');
t_is(t_whop_state($buyer)['membership_id'], 'mem_buyer_4', 'with nothing about the subscription changed either');

t_section('A renewal finds the account through the member, with no metadata');

$renewer = t_fixture(['email' => 'whop-renew@example.test']);
t_post_whop_webhook($app, t_whop_payment_event($renewer, WHOP_PRO_PLAN_ID, 'mber_renew', 'mem_renew', [], ['timestamp' => t_whop_at(600)]));
t_is(t_whop_state($renewer)['plan'], 'pro', 'the first payment grants Pro');

$renewal = t_whop_payment_event($renewer, WHOP_PRO_PLAN_ID, 'mber_renew', 'mem_renew', [
    'metadata'       => [],
    'billing_reason' => 'subscription_cycle',
    'paid_at'        => t_whop_at(0),
], ['timestamp' => t_whop_at(0)]);

$res = t_post_whop_webhook($app, $renewal);
t_is($res['status'], 200, 'the renewal is accepted');
t_is(t_whop_state($renewer)['plan'], 'pro', 'the account is still on Pro');
t_is((int)t_whop_ledger_row($renewal['id'])['user_id'], $renewer, 'and the ledger row names the account, resolved through the stored member id');

/* ─────────────────────────────────────────────────────────────────────────────
 * 7. Checkout — where the purchase starts, and where it must refuse to
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Checkout carries the buyer to Whop');

t_reset_whop_stub();
t_set_whop_checkout(['id' => 'ch_test_default']);

$res = t_http('GET', $app . '/whop-checkout.php?plan=pro');
t_is($res['status'], 302, 'a signed-out visitor is redirected');
t_like((string)$res['location'], '/register.php?plan=pro', 'to sign up, carrying the plan — the account has to exist before it can be attached to a purchase');

$buyer2 = t_fixture(['email' => 'whop-checkout@example.test']);
$res = t_http('GET', $app . '/whop-checkout.php?plan=pro', ['cookie' => t_login($buyer2)]);
t_is($res['status'], 302, 'a signed-in customer is redirected too');
t_like((string)$res['location'], 'https://whop.com/checkout/ch_test_default', 'to the checkout Whop created');

$sent = t_whop_requests_to('/api/v1/checkout_configurations');
t_is(count($sent), 1, 'exactly one checkout was created');
t_ok($sent[0]['authorized'], 'and it carried the API key in an Authorization header');
t_is($sent[0]['body']['plan']['id'], WHOP_PRO_PLAN_ID, 'for our Pro plan id, so the price is Whop\'s and cannot diverge');
t_is($sent[0]['body']['account_id'], WHOP_ACCOUNT_ID, 'under our Whop account');
t_is($sent[0]['body']['metadata']['utiligo_user_id'], (string)$buyer2, 'with the buyer\'s account id in the metadata, which is what the webhook matches on');
t_is($sent[0]['body']['metadata']['plan'], 'pro', 'and the plan name, for the fallback when a plan id moves');
t_like($sent[0]['body']['redirect_url'], '/purchase-success.php', 'the customer is returned to our own page, which grants nothing by itself');

t_section('A second checkout is never created for a customer who already pays');

t_reset_whop_stub();
t_db()->prepare("UPDATE utiligo_users SET whop_member_id = ?, whop_membership_id = ? WHERE id = ?")
    ->execute(['mber_has', 'mem_has', $buyer2]);

$res = t_http('GET', $app . '/whop-checkout.php?plan=entrepreneur', ['cookie' => t_login($buyer2)]);
t_is($res['status'], 302, 'the request is redirected');
t_like((string)$res['location'], 'https://whop.com/billing/manage/mber_has', 'to Whop\'s billing portal, where changing a plan is a supported operation');
t_is(t_whop_requests_to('/api/v1/checkout_configurations'), [], 'and NO checkout was created — this is the assertion that proves nobody was billed twice');

t_reset_whop_stub();
t_db()->prepare('UPDATE utiligo_users SET whop_member_id = NULL, whop_membership_id = NULL, stripe_subscription_id = ? WHERE id = ?')
    ->execute(['sub_from_stripe', $buyer2]);
$res = t_http('GET', $app . '/whop-checkout.php?plan=pro', ['cookie' => t_login($buyer2)]);
t_like((string)$res['location'], 'whop_error=already_subscribed', 'a Stripe subscriber is refused a Whop checkout too, because a plan cannot be moved between the two merchants');
t_is(t_whop_requests_to('/api/v1/checkout_configurations'), [], 'and again nothing is created');

t_section('When Whop is unreachable, the sale still happens');

t_reset_whop_stub();
$payer = t_fixture(['email' => 'whop-fallback@example.test']);
t_db()->prepare('UPDATE utiligo_users SET stripe_subscription_id = NULL WHERE id = ?')->execute([$payer]);
t_set_whop_failures(['POST /api/v1/checkout_configurations' => 503]);

$res = t_http('GET', $app . '/whop-checkout.php?plan=entrepreneur', ['cookie' => t_login($payer)]);
t_is($res['status'], 302, 'the customer is still redirected');
t_is((string)$res['location'], WHOP_ENT_CHECKOUT_URL, 'to the shareable plan link, so a working pricing button never becomes an error page');
t_is(count(t_whop_requests_to('/api/v1/checkout_configurations')), 1, 'the API was tried first, so this is a fallback and not a bypass');

t_reset_whop_stub();
t_set_whop_checkout(['id' => 'ch_test_default']);

t_section('Refusals land somewhere the customer can act');

foreach ([
    ''            => 'no plan at all',
    'free'        => 'the free tier',
    'enterprise'  => 'a plan name that does not exist',
] as $plan => $why) {
    $res = t_http('GET', $app . '/whop-checkout.php?plan=' . urlencode((string)$plan), ['cookie' => t_login($buyer2)]);
    // (the billing page is where every refusal lands, so the customer can act)
    t_is($res['status'], 302, 'a request for ' . $why . ' is redirected');
    t_like((string)$res['location'], '/portal/billing.php', 'back to the billing page');
    t_unlike((string)$res['location'], 'whop.com', 'and never to Whop');
}

t_is(t_whop_requests_to('/api/v1/checkout_configurations'), [], 'with no checkout created for any of them');

t_section('The POST shape the billing page uses');

$csrf = bin2hex(random_bytes(32));
$payerCookie = t_login($payer, ['csrf_token' => $csrf]);

$res = t_http('POST', $app . '/whop-checkout.php', [
    'form'   => ['plan' => 'pro'],
    'cookie' => $payerCookie,
]);
t_is($res['status'], 403, 'a POST without a CSRF token is refused');

$res = t_http('POST', $app . '/whop-checkout.php', [
    'form'   => ['plan' => 'pro', 'csrf_token' => $csrf],
    'cookie' => $payerCookie,
]);
t_is($res['status'], 302, 'a POST with one starts a checkout');
t_like((string)$res['location'], 'https://whop.com/checkout/ch_test_default', 'at Whop');

// The API key is a secret. It travels in one header and must not appear in a
// Location header, a body, or a log a customer could ever see.
t_unlike((string)$res['location'], WHOP_API_KEY, 'the API key is not in the redirect');
t_unlike($res['body'], WHOP_API_KEY, 'nor in the response body');

/* ─────────────────────────────────────────────────────────────────────────────
 * 8. The billing page tells the truth about who charges the card
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Billing says who bills, and refuses what it cannot do');

$pro = t_fixture(['email' => 'whop-billing@example.test', 'plan' => 'pro', 'subscription_status' => 'active']);
t_db()->prepare("UPDATE utiligo_users SET whop_member_id = ?, whop_membership_id = ? WHERE id = ?")
    ->execute(['mber_bill', 'mem_bill', $pro]);

$csrfBilling = bin2hex(random_bytes(32));
$cookie      = t_login($pro, ['csrf_token' => $csrfBilling]);

$res = t_http('GET', $app . '/portal/billing.php', ['cookie' => $cookie]);
t_is($res['status'], 200, 'the billing page renders for a Whop subscriber');
t_like($res['body'], 'https://whop.com/billing/manage/mber_bill', 'and offers the one control that can actually cancel: Whop\'s own billing page');
t_unlike($res['body'], 'test_subscribe', 'and offers no local activation form');

$res = t_http('POST', $app . '/portal/billing.php', [
    'form'   => ['action' => 'cancel', 'csrf_token' => $csrfBilling],
    'cookie' => $cookie,
]);
t_is($res['status'], 200, 'cancelling from inside the product is answered, not silently ignored');
t_like($res['body'], 'billed by Whop', 'with a sentence that says where the cancellation has to happen');
t_like($res['body'], 'whop.com/billing/manage/mber_bill', 'and a link to get there');
t_is(t_whop_state($pro)['status'], 'active', 'the subscription is still active locally, because nothing was cancelled');
t_is(t_whop_state($pro)['plan'], 'pro', 'and the plan is untouched');

t_section('A free account is sold a Whop checkout, not a card form that grants without payment');

$freeId = t_fixture(['email' => 'whop-free@example.test']);
$res    = t_http('GET', $app . '/portal/billing.php?plan=pro', ['cookie' => t_login($freeId)]);

t_is($res['status'], 200, 'the billing page renders');
t_like($res['body'], 'action="/whop-checkout.php"', 'its purchase button posts to the checkout endpoint');
t_like($res['body'], 'name="csrf_token"', 'with a CSRF token, because it is a state-changing POST');
t_like($res['body'], 'Development only', 'and the card form that activates without a payment is labelled as development-only');
t_unlike($res['body'], 'no real charge', 'the copy that made that form look like a real payment is gone');
t_unlike($res['body'], 'placeholder="1234 5678 9012 3456" class="card-input pr-14"', 'the production card field, which used to accept any 12 digits, is not the purchase path');

// The manual activation path still exists for development, and it is gated in
// both halves: the form is not rendered in production and the POST is refused
// there. Source-level, because the application server this suite runs against is
// deliberately not in production mode.
$billingSource = (string)file_get_contents(dirname(__DIR__, 2) . '/portal/billing.php');
t_like(
    $billingSource,
    "} elseif (\$_POST['action'] === 'test_subscribe' && \$canActivateLocally) {",
    'the branch that activates a plan without a payment is gated like the form that reaches it'
);
t_like($billingSource, 'Refused, and the form that would send it is not rendered either', 'and a crafted POST to it is refused, with why in the log');
t_like($billingSource, "defined('TEST_PAYMENT_MODE') ? (bool)TEST_PAYMENT_MODE : false", 'the gate reads the product\'s own test-mode flag, defaulting off when it is undefined');
t_like($billingSource, "!(defined('APP_ENV') && APP_ENV === 'production')", 'and requires a non-production environment as well, so a mis-set flag cannot give plans away on the live site');

// The flag itself must not ship enabled. This is the assertion that would have
// caught the live bug: any 12-digit card number activating a real plan.
$configSource = (string)file_get_contents(dirname(__DIR__, 2) . '/config.php');
t_unlike($configSource, "getenv('TEST_PAYMENT_MODE') ?: true", 'and test payment mode does not default to on — it was on, which is why a free account could subscribe itself');
t_like($configSource, "getenv('TEST_PAYMENT_MODE') ?: false", 'the default is off, and an admin turns it on deliberately');

t_section('A buyer who comes back before the webhook waits, rather than reading "Free"');

// Whop's redirect races its own webhook, so the return page has to be honest
// about a purchase that has not landed yet — and the query string must not be
// able to do anything but change that sentence.
$waiting = t_fixture(['email' => 'whop-returning@example.test']);
$res = t_http('GET', $app . '/purchase-success.php?whop_plan=pro', ['cookie' => t_login($waiting)]);
t_is($res['status'], 200, 'the return page renders for a signed-in buyer');
t_like($res['body'], 'Whop is confirming', 'and says the payment is being confirmed rather than showing a splash');
t_like($res['body'], 'dashboard by itself', 'with a sentence that explains what happens next');
t_is(t_whop_state($waiting)['plan'], 'free', 'and it grants nothing at all — only a signed webhook does');
t_unlike($res['body'], 'utl_purchase_ob_', 'the celebration flag is not emitted from a URL, only from a session this server verified');

$res = t_http('GET', $app . '/purchase-success.php?whop_plan=pro', ['cookie' => t_login($pro)]);
t_is($res['status'], 200, 'a customer who already holds the plan they asked about gets the ordinary hand-off');
t_unlike($res['body'], 'Whop is confirming', 'and is not told a payment is pending when the plan is already on their account');

// The same account asking about the tier ABOVE it is the real upgrade case: the
// webhook has not landed yet, so waiting is the honest answer.
$res = t_http('GET', $app . '/purchase-success.php?whop_plan=entrepreneur', ['cookie' => t_login($pro)]);
t_like($res['body'], 'Whop is confirming', 'while a Pro customer returning from an Entrepreneur checkout is told to wait');

/* ─────────────────────────────────────────────────────────────────────────────
 * Cleanup
 * ──────────────────────────────────────────────────────────────────────────── */

t_db()->exec("DELETE FROM whop_events WHERE webhook_id LIKE 'msg_%'");
t_db()->exec("DELETE FROM whop_events WHERE event_type LIKE 'membership.%' AND user_id IS NULL");
t_reset_whop_stub();
