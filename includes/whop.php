<?php
/**
 * includes/whop.php — everything that knows Whop exists.
 *
 * WHAT THIS FILE IS
 * ─────────────────
 * Whop is the merchant of record for the two subscription plans. This file is the
 * whole boundary between it and us, in four parts:
 *
 *   1. CONFIG        which plan ids and secrets we have, and whether they are real
 *   2. SIGNATURE     proving a webhook really came from Whop
 *   3. INTENT        what a verified event means, as a pure function of its payload
 *   4. DELIVERY      the API call that starts a checkout, and the ledger + handoff
 *                    that apply an event to an account
 *
 * WHY THERE IS NO SDK
 * ───────────────────
 * Whop's SDKs are Node, Python, Ruby, Rust and Go. This application is PHP with no
 * composer dependencies and deliberately no SDK for Stripe either
 * (includes/stripe_api.php says the same thing at more length). What an SDK would
 * do for us is two things, and both are small enough to own outright:
 *
 *   • verify a signature. Whop signs `{webhook-id}.{webhook-timestamp}.{raw body}`
 *     with HMAC-SHA256 and sends the base64 result as `v1,<signature>`. That is
 *     about thirty lines, and it is the part of the integration nobody should
 *     take on faith — so it lives here where it can be read and tested.
 *   • POST one endpoint to start a checkout. That is one curl call.
 *
 * THE FIVE WAYS THIS COULD COST MONEY, AND WHAT STOPS THEM
 * ──────────────────────────────────────────────────────
 *   1. An attacker POSTs a forged payment.succeeded and gives themselves a paid
 *      plan. Stopped by verifying the HMAC over the raw body with the webhook
 *      secret and comparing in constant time — before anything is read from the
 *      payload or written to the database.
 *   2. Whop redelivers an event (it says it will, for up to ~71 hours) and the
 *      customer is granted twice — or, worse, a redelivered cancellation lands
 *      after a re-purchase. Stopped twice over: the `whop_events.webhook_id`
 *      ledger drops a delivery we already finished, and every change carries the
 *      event's own timestamp into entitlement, which refuses anything that is not
 *      strictly newer than what already applied.
 *   3. A payment arrives for an account we cannot identify, and either nothing
 *      happens (customer paid, still free) or it lands on the wrong account.
 *      Stopped by resolving identity in a fixed order — our own metadata first,
 *      then the member id we already stored, then a unique verified email — and
 *      refusing to act on an ambiguous match.
 *   4. A secret leaks. Stopped by never echoing one: no config value from this
 *      file reaches a page, an attribute, a log line or an exception message.
 *      whop_redact() is applied to anything that might quote one.
 *   5. A stale delivery is replayed by someone who captured it. The timestamp in
 *      the signature covers both the id and the body and is refused when it is
 *      more than WHOP_WEBHOOK_TOLERANCE seconds from our clock — exactly what
 *      Whop's own verifiers do.
 *
 * The payload is never trusted for anything but facts about the payment itself.
 * It cannot name a plan we do not sell (whop_plan_for_id), it cannot name the
 * free tier in a purchase (entitlement_grant_from_whop refuses), and it cannot
 * move a plan backwards on a payment (the never-downgrade guard in
 * includes/entitlements.php, which is upstream of every write).
 */

require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/entitlements.php';

/* ═══════════════════════════════════════════════════════════════════════════
 * 1. CONFIG
 *
 * A placeholder is not a value. Every accessor below treats the YOUR_* defaults
 * config.php ships (and an empty string) as "not configured", the same convention
 * includes/stripe_api.php and the mailer already use — so the site runs without a
 * Whop account, the buttons still work through the shareable checkout links, and
 * nothing tries to authenticate with the literal string "YOUR_WHOP_API_KEY".
 * ═══════════════════════════════════════════════════════════════════════════ */

/** A real configuration value, or '' when it is missing or still a placeholder. */
function whop_setting(string $constant): string
{
    if (!defined($constant)) {
        return '';
    }

    $value = trim((string)constant($constant));
    if ($value === '' || str_starts_with($value, 'YOUR_')) {
        return '';
    }

    return $value;
}

function whop_api_key(): string
{
    return whop_setting('WHOP_API_KEY');
}

function whop_webhook_secret(): string
{
    return whop_setting('WHOP_WEBHOOK_SECRET');
}

function whop_account_id(): string
{
    return whop_setting('WHOP_ACCOUNT_ID');
}

function whop_api_base(): string
{
    $base = defined('WHOP_API_BASE') ? trim((string)WHOP_API_BASE) : '';
    return rtrim($base === '' ? 'https://api.whop.com' : $base, '/');
}

/**
 * Can we create a checkout ourselves (and therefore attach the buyer's account to
 * the payment as metadata)?
 *
 * Both the key and the account id are needed: the key authenticates, the account
 * id says which business the checkout belongs to. Without either, whop_checkout_url()
 * falls back to the shareable plan link, which still sells the plan — the
 * difference is that the resulting payment cannot say which Utiligo account is
 * buying, so identity falls back to the payer's email.
 */
function whop_can_create_checkout(): bool
{
    return whop_api_key() !== '' && whop_account_id() !== '';
}

/** Can we verify a webhook at all? Without the secret every event is refused. */
function whop_can_verify(): bool
{
    return whop_webhook_secret() !== '';
}

/**
 * plan name => Whop plan id, in the order the product lists its plans.
 *
 * Built from the plan table rather than a literal pair, so adding a third plan is
 * a config change and not a code change. A plan whose id is not configured is
 * simply absent — which is what makes whop_plan_for_id() conservative.
 */
function whop_plan_ids(): array
{
    $ids = [];
    foreach (['pro' => 'WHOP_PRO_PLAN_ID', 'entrepreneur' => 'WHOP_ENT_PLAN_ID'] as $plan => $constant) {
        $id = whop_setting($constant);
        if ($id !== '' && is_paid_plan($plan)) {
            $ids[$plan] = $id;
        }
    }

    return $ids;
}

/** The Whop plan id for one of our paid plans, or '' when it is not sold on Whop. */
function whop_plan_id_for(string $plan): string
{
    $plan = strtolower(trim($plan));
    $ids  = whop_plan_ids();
    return $ids[$plan] ?? '';
}

/**
 * Which of OUR plans a Whop plan id stands for, or null when it is not one.
 *
 * Null is the answer that matters here. A plan id we do not recognise — a new
 * plan created in the dashboard, a one-off product, a legacy id — must not be
 * guessed at, because the only guesses available are "grant the wrong tier" or
 * "grant the cheapest tier", and both are worse than doing nothing and logging.
 */
function whop_plan_for_id(string $planId): ?string
{
    $planId = trim($planId);
    if ($planId === '') {
        return null;
    }

    $plan = array_search($planId, whop_plan_ids(), true);
    return $plan === false ? null : (string)$plan;
}

/**
 * Whop's own billing portal for one member: switch plan, change card, cancel.
 *
 * The authority for this URL is the `manage_url` on a membership payload, which we
 * do not currently store — the shape is stable and documented, and it is built from
 * the member id we do store. The pattern is validated before it is returned
 * because the value ends up in a Location header: a stored id that is not one can
 * only produce an empty string here, never a redirect somewhere unexpected.
 *
 * Returns '' when there is no usable member id, and the caller then says "manage
 * this in billing" instead of sending the customer into the void.
 */
function whop_manage_url(string $memberId): string
{
    $memberId = trim($memberId);
    if ($memberId === '' || !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $memberId)) {
        return '';
    }

    return 'https://whop.com/billing/manage/' . rawurlencode($memberId);
}

/** The shareable checkout link for a plan, used when the API is not available. */
function whop_plan_link(string $plan): string
{
    $links = [
        'pro'          => whop_setting('WHOP_PRO_CHECKOUT_URL'),
        'entrepreneur' => whop_setting('WHOP_ENT_CHECKOUT_URL'),
    ];

    return $links[strtolower(trim($plan))] ?? '';
}

/**
 * The url to hand a customer so they can buy a plan.
 *
 * $userId > 0 asks for a link that carries the account with it. That needs the
 * API (see whop_create_checkout); when the API is not configured or fails, this
 * returns the plain plan link rather than an error, because a customer who wants
 * to pay must always have somewhere to go. The consequence of the fallback is
 * stated where it matters: identity then has to come from the payer's email, so
 * whop_resolve_user() tries that too.
 *
 * Returns ['url' => string, 'carries_identity' => bool, 'error' => string].
 */
function whop_checkout_url(string $plan, int $userId = 0, string $returnUrl = ''): array
{
    $plan = strtolower(trim($plan));

    if ($userId > 0 && whop_can_create_checkout()) {
        $created = whop_create_checkout($userId, $plan, $returnUrl);
        if ($created['ok'] && $created['url'] !== '') {
            return ['url' => $created['url'], 'carries_identity' => true, 'error' => ''];
        }

        whop_log('checkout', 'falling back to the plan link: ' . $created['error']);
    }

    return ['url' => whop_plan_link($plan), 'carries_identity' => false, 'error' => ''];
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 2. SIGNATURE
 *
 * Whop signs the string `{webhook-id}.{webhook-timestamp}.{raw body}` with
 * HMAC-SHA256 and puts the base64 result in `webhook-signature` as `v1,<sig>`.
 * Two details in that sentence are load-bearing:
 *
 *   • RAW BODY. The signature is over the bytes Whop sent. Re-encoding the decoded
 *     JSON changes key order, whitespace and unicode escaping, and every delivery
 *     then fails verification — a failure that looks exactly like an attack.
 *   • THE KEY IS DERIVED FROM THE SECRET, NOT THE SECRET ITSELF. Whop's helper
 *     takes the `ws_...` string as it was issued and derives the key from it. The
 *     derivation is not spelled out in their docs, so whop_signature_keys() tries
 *     the readings a `ws_`-prefixed value can have, in order, and accepts a
 *     signature that matches any of them. All of them come from our own secret, so
 *     this widens nothing: an attacker still has to produce an HMAC over our body
 *     with a key derived from a secret they do not have. The candidate that
 *     matched is reported, so the log can be used to trim this list to one.
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * The byte strings the signing key might be, most likely first.
 *
 *   1. base64-decode the part after `ws_`   — the Standard Webhooks convention,
 *      which is what Whop's webhooks are built on (`whsec_` there, `ws_` here)
 *   2. hex-decode the part after `ws_`      — the form their secret example shows
 *      (64 hex characters = a 32-byte key)
 *   3. the part after `ws_` as literal bytes — if the secret is already raw
 *   4. the whole secret as literal bytes     — if there is no prefix at all
 *
 * Duplicates are removed, so a secret that reads the same two ways is not tried
 * twice.
 */
function whop_signature_keys(string $secret): array
{
    $secret = trim($secret);
    if ($secret === '') {
        return [];
    }

    $keys  = [];
    $inner = $secret;
    if (str_starts_with($secret, 'ws_')) {
        $inner = substr($secret, 3);
    }

    if ($inner !== '' && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $inner)) {
        $decoded = base64_decode($inner, true);
        if (is_string($decoded) && $decoded !== '' && base64_encode($decoded) === rtrim($inner, '=')) {
            $keys[] = $decoded;
        }
    }

    if ($inner !== '' && preg_match('/^[0-9a-fA-F]+$/', $inner) && strlen($inner) % 2 === 0) {
        $hex = @hex2bin($inner);
        if (is_string($hex) && $hex !== '') {
            $keys[] = $hex;
        }
    }

    $keys[] = $inner;
    $keys[] = $secret;

    return array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
}

/**
 * The three headers, read case-insensitively out of $_SERVER.
 *
 * PHP normalises incoming headers to HTTP_* upper-snake, so the only thing left to
 * decide is which names to accept. Whop names four headers as contractually
 * frozen — webhook-id, webhook-signature, webhook-timestamp, content-type — so
 * those spellings are the contract. The `whop-`-prefixed alternatives are accepted
 * as well because a proxy or a tunnel can be configured to add a prefix, and
 * refusing a correctly signed request over a header name is a worse failure than
 * reading two spellings.
 */
function whop_webhook_headers(): array
{
    $read = static function (array $names): string {
        foreach ($names as $name) {
            if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
                return trim($_SERVER[$name]);
            }
        }
        return '';
    };

    return [
        'id'        => $read(['HTTP_WEBHOOK_ID', 'HTTP_WHOP_WEBHOOK_ID', 'HTTP_X_WEBHOOK_ID']),
        'timestamp' => $read(['HTTP_WEBHOOK_TIMESTAMP', 'HTTP_WHOP_WEBHOOK_TIMESTAMP', 'HTTP_X_WEBHOOK_TIMESTAMP']),
        'signature' => $read(['HTTP_WEBHOOK_SIGNATURE', 'HTTP_WHOP_WEBHOOK_SIGNATURE', 'HTTP_X_WEBHOOK_SIGNATURE']),
    ];
}

/**
 * Verify one delivery. Pure apart from the clock, so the whole attack surface is
 * assertable in tests: a valid signature, a tampered body, a wrong secret, a
 * stale timestamp, a rotated secret offered twice, and each malformed-header case.
 *
 * Returns ['ok' => bool, 'reason' => string, 'key_index' => int].
 *
 * Order of checks matters. The timestamp is checked BEFORE the HMAC so a flood of
 * captured old deliveries costs a subtraction per request rather than an HMAC
 * over the body, and so the cheapest rejection is also the first one.
 */
function whop_verify_webhook(string $rawBody, array $headers, string $secret = '', ?int $now = null): array
{
    $secret = $secret === '' ? whop_webhook_secret() : $secret;
    $now    = $now ?? time();

    if ($secret === '') {
        return whop_verify_result(false, 'no webhook secret is configured', -1);
    }

    $id        = trim((string)($headers['id'] ?? ''));
    $timestamp = trim((string)($headers['timestamp'] ?? ''));
    $signature = trim((string)($headers['signature'] ?? ''));

    if ($id === '' || $timestamp === '' || $signature === '') {
        return whop_verify_result(false, 'missing webhook-id, webhook-timestamp or webhook-signature', -1);
    }

    if (!preg_match('/^\d{1,12}$/', $timestamp)) {
        return whop_verify_result(false, 'the webhook-timestamp is not a unix time', -1);
    }

    $tolerance = defined('WHOP_WEBHOOK_TOLERANCE') ? max(30, (int)WHOP_WEBHOOK_TOLERANCE) : 300;
    $skew      = abs($now - (int)$timestamp);
    if ($skew > $tolerance) {
        // Replay window. Both directions are refused: a clock far in the future is
        // as suspect as one far in the past, and refusing only the past would let a
        // captured delivery be replayed until it aged out.
        return whop_verify_result(false, 'the webhook-timestamp is ' . $skew . 's from our clock (limit ' . $tolerance . 's)', -1);
    }

    $signed = $id . '.' . $timestamp . '.' . $rawBody;

    // The header may carry more than one signature — `v1,sig1 v1,sig2` — which is
    // how a secret rotation is delivered: both the old and the new key are offered
    // for a while. Every candidate is checked, and a match on any is accepted, so
    // rotating the secret in the dashboard does not drop every webhook in flight.
    $offered = [];
    foreach (preg_split('/\s+/', $signature) ?: [] as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        // `v1,<sig>`. A bare signature with no version is accepted too: refusing it
        // would be a needless failure for a payload whose bytes are still ours to
        // verify.
        $offered[] = str_starts_with($part, 'v1,') ? substr($part, 3) : $part;
    }

    if (!$offered) {
        return whop_verify_result(false, 'the webhook-signature header carried no signature', -1);
    }

    foreach (whop_signature_keys($secret) as $index => $key) {
        $expected = base64_encode(hash_hmac('sha256', $signed, $key, true));

        foreach ($offered as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return whop_verify_result(true, 'verified with key derivation ' . $index, $index);
            }
        }
    }

    // One sentence for every mismatch. Which key or which signature failed is
    // information an attacker can use and an operator cannot: the only actionable
    // fact is that this delivery is not ours.
    return whop_verify_result(false, 'the signature does not match this body and secret', -1);
}

function whop_verify_result(bool $ok, string $reason, int $keyIndex): array
{
    return ['ok' => $ok, 'reason' => $reason, 'key_index' => $keyIndex];
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 3. THE EVENT, AND WHAT IT MEANS
 *
 * Parsing and interpretation are separated on purpose. whop_parse_event() only
 * answers "is this a well-formed v1 envelope, and what is in it"; whop_intent()
 * only answers "what should this do to an account". Neither touches the database,
 * so the whole decision table is assertable without MySQL — the same split
 * includes/entitlements.php uses for subscription statuses, and for the same
 * reason: this is the part that hands out paid plans.
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Decode and shape-check an envelope. Never throws, never trusts.
 *
 * Returns ['ok', 'reason', 'event', 'webhook_id', 'type', 'data', 'event_at'].
 * `event_at` is the event's own time in unix seconds with microseconds — the
 * value that ends up as the entitlement clock — taken from the envelope's
 * `timestamp` and falling back to `data.paid_at` / `data.created_at` (a payment
 * carries the moment it was paid, which is better evidence than nothing) and then
 * to our clock. It is never taken from the HTTP Date header or from arrival order,
 * which is the whole point: arrival order is what Whop explicitly does not
 * guarantee.
 */
function whop_parse_event(string $rawBody): array
{
    $fail = static fn(string $reason): array => [
        'ok' => false, 'reason' => $reason, 'event' => [], 'webhook_id' => '',
        'type' => '', 'data' => [], 'event_at' => null,
    ];

    if (strlen($rawBody) > 512 * 1024) {
        return $fail('the body is larger than any Whop webhook');
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event)) {
        return $fail('the body is not JSON');
    }

    $type = (string)($event['type'] ?? '');
    if ($type === '') {
        return $fail('the envelope names no event type');
    }

    // v1 is the only envelope that uses Standard Webhooks signatures. The legacy
    // v2/v5 envelopes exist for old integrations and are signed differently, so a
    // v1-shaped body arriving with one of those versions means the webhook was
    // created against the wrong API version and its signature cannot be trusted to
    // mean what this code assumes.
    $version = (string)($event['api_version'] ?? '');
    if ($version !== '' && $version !== 'v1') {
        return $fail('the envelope is api_version ' . $version . ', not v1');
    }

    $data = $event['data'] ?? null;
    if (!is_array($data)) {
        return $fail('the envelope carries no data object');
    }

    return [
        'ok'         => true,
        'reason'     => '',
        'event'      => $event,
        'webhook_id' => trim((string)($event['id'] ?? '')),
        'type'       => $type,
        'data'       => $data,
        'event_at'   => whop_event_time($event),
    ];
}

/**
 * The moment an event describes, in unix seconds (with microseconds), or null.
 *
 * Read from the envelope first because it is the same value the signature covers
 * and therefore the same value Whop believes; the payment's own timestamps are
 * the fallback for an envelope whose timestamp is missing or unparseable, and the
 * wall clock is the last resort — at which point ordering is degraded, not broken,
 * because the ledger still refuses a duplicate delivery.
 */
function whop_event_time(array $event): ?float
{
    foreach ([$event['timestamp'] ?? null, ($event['data']['paid_at'] ?? null), ($event['data']['created_at'] ?? null)] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $parsed = strtotime(trim($candidate));
            if ($parsed !== false && $parsed > 0) {
                // Microseconds are read out of the ISO string rather than lost.
                // Whop sends millisecond precision; two events inside one second
                // (a payment and the membership update it triggers) would otherwise
                // tie, and a tie is refused by the entitlement clock.
                $micros = 0.0;
                if (preg_match('/\.(\d{1,6})/', $candidate, $m)) {
                    $micros = (float)('0.' . $m[1]);
                }
                return (float)$parsed + $micros;
            }
        }
    }

    return null;
}

/**
 * The plan a payment is for, or null when we cannot tell.
 *
 * The plan ID decides; metadata is only a fallback — and the order matters for the
 * reason the Stripe code learned the hard way: metadata is stamped once, so a plan
 * changed in Whop's dashboard afterwards would still be reported by the old value.
 * The fallback earns its place for installs whose plan ids have moved, and it is
 * restricted to paid plans we actually sell.
 */
function whop_plan_from_payment(array $data): ?string
{
    $plan = whop_plan_for_id((string)($data['plan']['id'] ?? ''));

    if ($plan === null) {
        foreach ([$data['metadata']['plan'] ?? null, $data['plan']['metadata']['plan'] ?? null, $data['product']['metadata']['plan'] ?? null] as $named) {
            $guess = entitlement_normalize_plan((string)$named);
            if ($guess !== null && is_paid_plan($guess)) {
                $plan = $guess;
                break;
            }
        }
    }

    return $plan;
}

/**
 * What a verified event should do. Pure: no database, no clock, no network.
 *
 * Returns ['action' => 'grant'|'revoke'|'ignore', 'plan' => ?string,
 *          'status' => ?string, 'reason' => string].
 *
 * The table, and why each row is what it is:
 *
 *   payment.succeeded, status paid, plan ours   GRANT. Also fires for every
 *       renewal (billing_reason subscription_cycle), which is why it is a grant
 *       and not a one-time thing: the entitlement clock makes a repeat cheap and
 *       a missed first delivery self-heal at the next successful charge.
 *
 *   payment.succeeded, status not paid          IGNORE. The event name is a claim
 *       about an event type, not about the object in it. A payment that does not
 *       say it is paid is not evidence that anyone paid.
 *
 *   payment.succeeded, plan we do not sell      IGNORE, loudly. See
 *       whop_plan_for_id: guessing the tier costs money in one direction and
 *       goodwill in the other.
 *
 *   membership.deactivated                      REVOKE. Whop's own docs point at
 *       this event for access being taken away, and it covers a cancellation and
 *       a lapse. The plan goes back to free and the status to 'cancelled'.
 *
 *   anything else                               IGNORE, with the type in the
 *       reason. The handler is subscribed to two events; a third arriving means
 *       someone edited the webhook's subscriptions in the dashboard, and the log
 *       is where that should be noticed rather than acted on.
 */
function whop_intent(array $event): array
{
    $ignore = static fn(string $reason): array => ['action' => 'ignore', 'plan' => null, 'status' => null, 'reason' => $reason];
    $type   = (string)($event['type'] ?? '');
    $data   = is_array($event['data'] ?? null) ? $event['data'] : [];

    if ($type === 'payment.succeeded') {
        $status = strtolower(trim((string)($data['status'] ?? '')));
        $sub    = strtolower(trim((string)($data['substatus'] ?? '')));

        // 'paid' is what the payment resource reports when the money is captured.
        // 'succeeded' is the substatus; some payloads carry only one of the two,
        // which is why either is accepted, but neither being present means the
        // object is not telling us it succeeded.
        if ($status !== 'paid' && $sub !== 'succeeded' && $status !== 'succeeded') {
            return $ignore('payment.succeeded with status "' . $status . '" — not evidence of payment');
        }

        $plan = whop_plan_from_payment($data);
        if ($plan === null) {
            return $ignore('a payment for a plan we do not sell (plan id '
                . trim((string)($data['plan']['id'] ?? '(none)')) . ')');
        }

        return [
            'action' => 'grant',
            'plan'   => $plan,
            'status' => 'active',
            'reason' => 'paid for ' . $plan . ' (billing_reason '
                . trim((string)($data['billing_reason'] ?? 'unknown')) . ')',
        ];
    }

    if ($type === 'membership.deactivated') {
        // Nothing about the plan is decided here: a deactivated membership means
        // the paid relationship is over, so the entitlement wrapper downgrades to
        // the free tier. The membership id travels with the change so the deletion
        // of a membership the customer has replaced cannot revoke their new one.
        return [
            'action' => 'revoke',
            'plan'   => null,
            'status' => 'cancelled',
            'reason' => 'membership deactivated (status '
                . trim((string)($data['status'] ?? 'unknown')) . ')',
        ];
    }

    return $ignore('unhandled event type "' . $type . '"');
}

/**
 * Which account an event is about.
 *
 * The order is deliberate and is the opposite of convenient:
 *
 *   1. OUR OWN METADATA. `metadata.utiligo_user_id` is written by
 *      whop_create_checkout with our API key and flows through the payment
 *      untouched, so it is the only identity in the payload that could not have
 *      been affected by anything a customer did. It is used only when it names an
 *      account that exists.
 *   2. THE MEMBER ID WE ALREADY STORE. Renewals and cancellations carry no
 *      metadata we set, so the second and subsequent events about a subscription
 *      are matched through utiligo_users.whop_member_id.
 *   3. A UNIQUE VERIFIED EMAIL. The path for a purchase made through a shareable
 *      plan link, where there is no metadata. It requires the account to have
 *      verified its address and requires the match to be unique — a payment must
 *      never be the thing that confirms somebody's email, and an ambiguous match
 *      is refused rather than guessed.
 *
 * Returns ['user_id' => int, 'via' => string, 'reason' => string]. user_id 0 means
 * we could not tell, and the caller records that instead of acting.
 */
function whop_resolve_user(array $event): array
{
    $data      = is_array($event['data'] ?? null) ? $event['data'] : [];
    $metadata  = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
    $memberId  = trim((string)($data['member']['id'] ?? ''));
    $email     = strtolower(trim((string)($data['user']['email'] ?? $data['email'] ?? '')));

    $userId = 0;
    foreach (['utiligo_user_id', 'user_id'] as $key) {
        if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
            $candidate = (int)$metadata[$key];
            if ($candidate > 0 && whop_user_exists($candidate)) {
                $userId = $candidate;
                break;
            }
        }
    }

    if ($userId > 0) {
        return ['user_id' => $userId, 'via' => 'metadata', 'reason' => 'metadata named account ' . $userId];
    }

    if ($memberId !== '') {
        $userId = entitlement_user_for_whop_member($memberId);
        if ($userId > 0) {
            return ['user_id' => $userId, 'via' => 'member', 'reason' => 'member ' . $memberId . ' is on account ' . $userId];
        }
    }

    if ($email !== '') {
        $userId = whop_user_for_verified_email($email);
        if ($userId > 0) {
            return ['user_id' => $userId, 'via' => 'email', 'reason' => 'the payer email matches verified account ' . $userId];
        }
    }

    return [
        'user_id' => 0,
        'via'     => 'none',
        'reason'  => 'no metadata, no known member'
            . ($email !== '' ? ' and no unique verified account for ' . $email : ' and no payer email'),
    ];
}

/** Does this account exist? Never throws; a database error answers "no". */
function whop_user_exists(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    try {
        $stmt = get_user_db()->prepare('SELECT id FROM utiligo_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetch();
    } catch (\Throwable $e) {
        whop_log('identify', 'could not check account ' . $userId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * The account with this VERIFIED email, or 0 when there is not exactly one.
 *
 * Two conditions, both of which have to hold before a payment can be attached to
 * an account by email: the address must be verified (so an attacker cannot
 * register somebody else's address and wait for them to pay) and the match must be
 * unique (so the same address registered twice does not send the plan to an
 * arbitrary one of them).
 */
function whop_user_for_verified_email(string $email): int
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    try {
        $stmt = get_user_db()->prepare('SELECT id FROM utiligo_users WHERE LOWER(email) = ? AND email_verified = 1 LIMIT 2');
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();

        return count($rows) === 1 ? (int)$rows[0]['id'] : 0;
    } catch (\Throwable $e) {
        whop_log('identify', 'email lookup failed: ' . $e->getMessage());
        return 0;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 4. DELIVERY — the API call, the ledger, and the handoff to entitlement
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * One request to the Whop API. Never throws; every failure is a return value.
 *
 * The API key travels in an Authorization header and nothing else — not a query
 * string (which lands in access logs), not a body field. Timeouts are short and
 * fixed because this runs inside a customer's page load: a slow merchant API must
 * degrade to the shareable plan link, not hold the request open.
 *
 * Returns ['ok' => bool, 'status' => int, 'data' => array, 'error' => string].
 */
function whop_api_request(string $method, string $path, ?array $body = null): array
{
    $fail = static fn(string $error, int $status = 0): array => ['ok' => false, 'status' => $status, 'data' => [], 'error' => $error];

    $key = whop_api_key();
    if ($key === '') {
        return $fail('no Whop API key is configured');
    }

    if (!function_exists('curl_init')) {
        return $fail('the curl extension is not available');
    }

    $url = whop_api_base() . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    if ($ch === false) {
        return $fail('could not initialise a request');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ];

    if (strtoupper($method) !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        $options[CURLOPT_POSTFIELDS]    = json_encode($body ?? [], JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = (string)curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return $fail('the request failed: ' . whop_redact($err));
    }

    $data = json_decode((string)$raw, true);

    if ($status < 200 || $status >= 300) {
        // Whop's error bodies are JSON with a message. They are included because a
        // rejected plan id or a revoked key is exactly what an operator needs to
        // see, and they are redacted because an error body can echo a header.
        $message = is_array($data) ? (string)($data['message'] ?? $data['error'] ?? '') : trim((string)$raw);
        return $fail('HTTP ' . $status . ': ' . whop_redact(substr($message, 0, 200)), $status);
    }

    return ['ok' => true, 'status' => $status, 'data' => is_array($data) ? $data : [], 'error' => ''];
}

/**
 * Create a checkout configuration that carries the buyer's account with it.
 *
 * This is the whole reason the API key is worth having. The configuration's
 * `metadata` is echoed into the `payment.succeeded` webhook, so the handler can
 * attach a payment to an account without guessing — and, because it is written
 * with our key and signed on the way back, it is the one field in the payload a
 * customer cannot influence.
 *
 * Returns ['ok' => bool, 'url' => string, 'session' => string, 'error' => string].
 */
function whop_create_checkout(int $userId, string $plan, string $returnUrl = ''): array
{
    $planId = whop_plan_id_for($plan);
    if ($planId === '') {
        return ['ok' => false, 'url' => '', 'session' => '', 'error' => $plan . ' is not sold on Whop'];
    }

    $payload = [
        'account_id' => whop_account_id(),
        // An existing plan id: we are not creating a plan, we are choosing one, so
        // the price the customer is charged is the one configured in Whop's
        // dashboard and cannot diverge from it.
        'plan'       => ['id' => $planId],
        'metadata'   => [
            'utiligo_user_id' => (string)$userId,
            'plan'            => $plan,
        ],
    ];

    if ($returnUrl !== '') {
        // Whop returns the customer here with ?status=success|error. The redirect
        // never grants anything — it is a page, and a page is a URL anyone can
        // visit. Only a verified webhook does.
        $payload['redirect_url'] = $returnUrl;
    }

    $result = whop_api_request('POST', '/api/v1/checkout_configurations', $payload);
    if (!$result['ok']) {
        return ['ok' => false, 'url' => '', 'session' => '', 'error' => $result['error']];
    }

    $session = trim((string)($result['data']['id'] ?? ''));
    if ($session === '') {
        return ['ok' => false, 'url' => '', 'session' => '', 'error' => 'the API returned no checkout session id'];
    }

    return [
        'ok'      => true,
        'url'     => 'https://whop.com/checkout/' . rawurlencode($session),
        'session' => $session,
        'error'   => '',
    ];
}

/**
 * Claim a delivery in the ledger, so the same webhook-id is never applied twice.
 *
 * Returns ['state' => 'new'|'retry'|'done', 'reason' => string].
 *
 *   new    first sight of this id — process it
 *   retry  seen before, but the previous attempt did not finish (status
 *          'received' or 'failed') — process it again. This is the row that stops
 *          a crashed handler from silently swallowing a payment: treating every
 *          repeat as a duplicate would leave a paying customer on the free plan.
 *   done   seen and finished ('applied' or 'ignored') — do nothing at all
 *
 * A database that cannot answer returns 'new' rather than 'done'. The alternative
 * — refusing to process because the ledger is unreadable — would turn a transient
 * database problem into a lost payment, and the entitlement clock is a second,
 * independent guard against a genuine double-apply.
 */
function whop_ledger_claim(string $webhookId, string $type, ?float $eventAt, int $userId = 0): array
{
    $webhookId = trim($webhookId);
    if ($webhookId === '') {
        return ['state' => 'new', 'reason' => 'the delivery carried no webhook-id to record'];
    }

    try {
        $pdo = get_user_db();

        $stmt = $pdo->prepare('SELECT status FROM whop_events WHERE webhook_id = ? LIMIT 1');
        $stmt->execute([$webhookId]);
        $row = $stmt->fetch();

        if ($row) {
            $status = strtolower((string)$row['status']);
            if ($status === 'applied' || $status === 'ignored') {
                return ['state' => 'done', 'reason' => 'already ' . $status];
            }

            $pdo->prepare('UPDATE whop_events SET status = ?, detail = NULL WHERE webhook_id = ?')
                ->execute(['received', $webhookId]);

            return ['state' => 'retry', 'reason' => 'a previous attempt left it ' . $status];
        }

        // INSERT ... and let the unique key settle a race between two simultaneous
        // deliveries of the same event: one of them gets the row, the other gets a
        // duplicate-key error and is told to stand down.
        $pdo->prepare('INSERT INTO whop_events (webhook_id, event_type, event_at, user_id, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $webhookId,
                substr($type, 0, 64),
                whop_sql_time($eventAt),
                $userId > 0 ? $userId : null,
                'received',
            ]);

        return ['state' => 'new', 'reason' => 'first delivery'];
    } catch (\Throwable $e) {
        if (entitlement_is_schema_error($e)) {
            // Migration 030 has not run. Processing is still correct — the
            // entitlement clock refuses a duplicate — but the dedupe is now the
            // clock's job, so say so.
            whop_log('ledger', 'no whop_events table yet: ' . $e->getMessage());
            return ['state' => 'new', 'reason' => 'no ledger (migration 030 not applied)'];
        }

        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            return ['state' => 'done', 'reason' => 'another delivery of this event is already recorded'];
        }

        whop_log('ledger', 'could not record the delivery: ' . $e->getMessage());
        return ['state' => 'new', 'reason' => 'the ledger could not be read'];
    }
}

/** Close out a claimed delivery. Never throws — it is bookkeeping, not entitlement. */
function whop_ledger_finish(string $webhookId, string $status, string $detail, int $userId = 0, ?string $plan = null): void
{
    $webhookId = trim($webhookId);
    if ($webhookId === '') {
        return;
    }

    try {
        $stmt = get_user_db()->prepare(
            'UPDATE whop_events SET status = ?, detail = ?, applied_at = NOW(), '
            . 'user_id = COALESCE(?, user_id), plan = COALESCE(?, plan) WHERE webhook_id = ?'
        );
        $stmt->execute([
            substr(strtolower($status), 0, 12),
            substr($detail, 0, 255),
            $userId > 0 ? $userId : null,
            $plan,
            $webhookId,
        ]);
    } catch (\Throwable $e) {
        whop_log('ledger', 'could not close delivery ' . $webhookId . ': ' . $e->getMessage());
    }
}

/**
 * Apply one verified event to one account. The only function here that writes.
 *
 * Returns ['status' => 'applied'|'ignored'|'failed', 'http' => int, 'reason' => string,
 *          'user_id' => int, 'plan' => ?string].
 *
 * `http` is the status the endpoint should answer with, and it is chosen for
 * Whop's retry behaviour rather than for tidiness:
 *
 *   200  applied, or deliberately ignored. Nothing more will come of a retry.
 *   500  failed. Whop retries with backoff for about three days, and a transient
 *        database problem is exactly what that schedule is for. The ledger holds
 *        the delivery in 'received', so the retry is processed rather than
 *        mistaken for a duplicate.
 */
function whop_handle_event(array $parsed, string $webhookId = ''): array
{
    // $parsed is the output of whop_parse_event(): the endpoint parses the verified
    // body once, so this function never sees raw bytes and cannot be handed an
    // unverified payload by accident.
    if (empty($parsed['ok'])) {
        return ['status' => 'ignored', 'http' => 200, 'reason' => (string)($parsed['reason'] ?? 'unreadable event'), 'user_id' => 0, 'plan' => null];
    }

    $webhookId = $webhookId !== '' ? $webhookId : (string)$parsed['webhook_id'];
    $type      = (string)$parsed['type'];
    $eventAt   = $parsed['event_at'];
    $intent    = whop_intent($parsed);

    // Identity is resolved before the ledger claim so the row records which
    // account the event was about — that is the column an incident review reads.
    $identity = whop_resolve_user($parsed);
    $userId   = (int)$identity['user_id'];

    $claim = whop_ledger_claim($webhookId, $type, $eventAt, $userId);
    if ($claim['state'] === 'done') {
        return ['status' => 'ignored', 'http' => 200, 'reason' => 'duplicate delivery: ' . $claim['reason'], 'user_id' => $userId, 'plan' => null];
    }

    if ($intent['action'] === 'ignore') {
        $reason = $intent['reason'] . ' — ' . $identity['reason'];
        whop_ledger_finish($webhookId, 'ignored', $reason, $userId);
        whop_log('event', 'ignored ' . $type . ': ' . $reason);
        return ['status' => 'ignored', 'http' => 200, 'reason' => $reason, 'user_id' => $userId, 'plan' => null];
    }

    if ($userId <= 0) {
        // A verified event about a real payment that we cannot attach to anybody.
        // Not ignored: it is recorded as failed so it shows up as a warning, and
        // the delivery is left open deliberately — a retry an hour later may
        // resolve it once the account's email is verified, and Whop will send one.
        $reason = 'could not identify the account: ' . $identity['reason'];
        whop_ledger_finish($webhookId, 'failed', $reason);
        whop_log('event', $type . ' could not be applied: ' . $reason);
        return ['status' => 'failed', 'http' => 500, 'reason' => $reason, 'user_id' => 0, 'plan' => null];
    }

    $membershipId = trim((string)($parsed['data']['membership']['id'] ?? $parsed['data']['id'] ?? ''));
    $memberId     = trim((string)($parsed['data']['member']['id'] ?? ''));

    $result = $intent['action'] === 'grant'
        ? entitlement_grant_from_whop($userId, (string)$intent['plan'], [
            'member_id'     => $memberId,
            'membership_id' => $membershipId,
            'event_at'      => $eventAt,
            'source'        => 'whop-webhook',
        ])
        : entitlement_cancel_from_whop([
            'user_id'       => $userId,
            'member_id'     => $memberId,
            'membership_id' => $membershipId,
            'event_at'      => $eventAt,
            'source'        => 'whop-webhook',
        ]);

    // 'applied' means rows changed. 'no change' is the interesting middle: the
    // clock refused a replay, or the account is already in that state. Both are
    // finished business for Whop — a 500 would make it retry for three days for
    // nothing — so they close as ignored, and the reason says which it was.
    $applied = !empty($result['applied']);
    $reason  = $type . ': ' . ($result['reason'] ?? '') . ' (account ' . $userId . ', via ' . $identity['via'] . ')';

    $writeFailed = ($result['reason'] ?? '') === 'write failed' || ($result['reason'] ?? '') === 'database unavailable';

    whop_ledger_finish(
        $webhookId,
        $writeFailed ? 'failed' : ($applied ? 'applied' : 'ignored'),
        $reason,
        $userId,
        $intent['plan'] === null ? null : (string)$intent['plan']
    );

    if ($writeFailed) {
        whop_log('event', 'FAILED ' . $reason);
        return ['status' => 'failed', 'http' => 500, 'reason' => $reason, 'user_id' => $userId, 'plan' => $intent['plan']];
    }

    whop_log('event', ($applied ? 'applied ' : 'no change ') . $reason);
    return [
        'status'  => $applied ? 'applied' : 'ignored',
        'http'    => 200,
        'reason'  => $reason,
        'user_id' => $userId,
        'plan'    => $intent['plan'],
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
 * Housekeeping
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * A unix timestamp (with microseconds) as a DATETIME(6) literal, or null.
 *
 * Formatted in UTC rather than through FROM_UNIXTIME() because this value is
 * only ever read back for an incident review — the ordering guard uses
 * utiligo_users.subscription_event_at, which MySQL formats itself. Storing an
 * absolute UTC string keeps the ledger readable from any time zone.
 */
function whop_sql_time(?float $unix): ?string
{
    if ($unix === null || $unix <= 0) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', (int)$unix)
        . '.' . sprintf('%06d', (int)round(fmod($unix, 1.0) * 1000000));
}

/**
 * Remove anything secret-looking from a string before it is logged.
 *
 * The API key and the webhook secret are read from the constants on every call
 * rather than cached, so a value that is set after this file is loaded (as the
 * test suite does) is still redacted. Short values are skipped — replacing a
 * three-character string would mangle the message it appears in for no benefit.
 */
function whop_redact(string $message): string
{
    foreach ([whop_api_key(), whop_webhook_secret()] as $secret) {
        if (strlen($secret) >= 8 && strpos($message, $secret) !== false) {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }

    return $message;
}

function whop_log(string $scope, string $message): void
{
    error_log('[whop][' . $scope . '] ' . whop_redact($message));
}
