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
// Is the file that holds these values being loaded at all? See the blocking entry
// in whop_config_report() — a value that was saved and is being ignored looks
// exactly like a value nobody entered.
require_once __DIR__ . '/config_overrides.php';

/* ═══════════════════════════════════════════════════════════════════════════
 * 1. CONFIG
 *
 * A placeholder is not a value. Every accessor below treats the YOUR_* defaults
 * config.php ships (and an empty string) as "not configured", the same convention
 * includes/stripe_api.php and the mailer already use — so the site runs without a
 * Whop account, the buttons still work through the shareable checkout links, and
 * nothing tries to authenticate with the literal string "YOUR_WHOP_API_KEY".
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * The keys this file reads out of storage/config_overrides.php itself.
 *
 * WHY THESE EIGHT KEYS ARE READ FROM THE FILE AND NOT FROM A CONSTANT
 * ───────────────────────────────────────────────────────────────────
 * Every other setting on the admin page arrives as a constant: config.php loads
 * storage/config_overrides.php in its first few lines, so the file's define()s win over
 * everything config.php defines later. That arrangement has one hole, and it is the hole
 * this product kept falling into: config.php is EXCLUDED from the FTP deploy, because it
 * holds the database credentials. The copy on a live server is edited by hand, so it can
 * be older than the code — and an older copy does not load the overrides file at all.
 * Then every value saved on the settings page is written and then ignored: "saved" on the
 * page that saved it, "not set" on the page that reports it, and a file that looks right
 * in the operator's FTP client, because it is right. The settings page can repair that
 * copy (it has a button for exactly this), but a repair is a click that can be missed,
 * and the failure it repairs is silent from the operator's side.
 *
 * So for these keys the payment code does not wait for that click: it reads the value out
 * of the file the settings page writes, and a value the file states wins. That is the
 * same precedence config.php's loader gives the file, applied where a stale config.php
 * would otherwise quietly break payments — a checkout that cannot say who is paying, a
 * webhook secret nothing can verify against, a customer charged for a plan nobody can
 * grant. The alternative is what shipped first, and it is how the webhook secret came to
 * be set in the repository, absent from the live process, and reported as "not set" by
 * the very page that had just been used to save it.
 *
 * @see config_overrides_mismatch()  which reports the split, so the strip can say which
 *                                   saved settings a stale config.php is still ignoring
 * @return list<string>
 */
function whop_self_read_keys(): array
{
    return [
        'WHOP_API_KEY',
        'WHOP_WEBHOOK_SECRET',
        'WHOP_ACCOUNT_ID',
        'WHOP_PRO_PLAN_ID',
        'WHOP_ENT_PLAN_ID',
        'WHOP_PRO_CHECKOUT_URL',
        'WHOP_ENT_CHECKOUT_URL',
        'WHOP_API_BASE',
    ];
}

/**
 * A real configuration value, or '' when it is missing or still a placeholder.
 *
 * The overrides file is consulted first — see whop_self_read_keys() for why a value the
 * settings page saved is the value in use even on a server whose config.php is older than
 * that page. A key the file says nothing about falls back to the constant, and the YOUR_*
 * placeholders config.php ships are still not values.
 */
function whop_setting(string $constant): string
{
    $stated = config_overrides_setting($constant);
    if ($stated === null && !defined($constant)) {
        return '';
    }

    $value = trim($stated ?? (string)constant($constant));
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
    // Same rule as whop_setting(): a value saved on the settings page is the one in use,
    // even where the running config.php predates the file it was saved into.
    $base = config_overrides_setting('WHOP_API_BASE')
         ?? (defined('WHOP_API_BASE') ? trim((string)WHOP_API_BASE) : '');
    $base = trim($base);

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
 * May we take money for a plan at all?
 *
 * Selling a subscription we cannot then apply is the worst state this product can
 * be in, and it is worse than selling nothing:
 *
 *   • Whop charges the card.
 *   • The webhook arrives, and an endpoint with no webhook secret has nothing to
 *     verify it with, so it answers 401 — see whop_verify_webhook().
 *   • Whop retries for about three days, then gives up.
 *   • The customer has paid and is still on the free plan.
 *
 * A customer who was never sold anything is a lost sale. A customer who paid and
 * got nothing is a refund, an angry email, and a support ticket. So the webhook
 * secret is not only "what the webhook needs": it gates the sale. whop-checkout.php
 * refuses while it is missing and says so in plain words, and the admin Payments
 * page (admin/payments.php) shows the same fact to the person who can fix it.
 *
 * Note what is deliberately NOT required: the API key. Without it the customer
 * still goes to the plan's shareable link and the payment is reconciled through
 * the payer's verified email, which works. Losing the metadata is a worse
 * reconciliation, not a broken one.
 */
function whop_can_accept_payments(): bool
{
    return whop_can_verify() && whop_plan_ids() !== [];
}

/**
 * The state of the payment configuration, for the admin Payments page.
 *
 * Reports WHETHER a value is present, never the value — not even a masked preview
 * of it. That is the whole design rule of this file (see the redaction note at the
 * top): the API key and the webhook secret are the two things that must never reach
 * a response body, and "the first four characters" is a value reaching a response
 * body. All the admin page gets is a boolean and a length, and a length is genuinely
 * useful: it catches the classic "the key was pasted with a trailing newline" and
 * tells nobody anything they could authenticate with.
 *
 * The plan ids and the shareable links are printed in full because they are not
 * secrets: the plan ids are already in the checkout links on the pricing page.
 */
function whop_config_report(): array
{
    $masked = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return strlen($value) <= 8
            ? str_repeat('•', strlen($value))
            : substr($value, 0, 4) . str_repeat('•', 6) . substr($value, -4);
    };

    $secret = whop_webhook_secret();
    $key    = whop_api_key();

    $blocking = [];
    if ($secret === '') {
        $blocking[] = 'WHOP_WEBHOOK_SECRET is not set, so no payment can be applied. Checkout is refused until it is — a customer who paid and got nothing is worse than a customer we did not sell to.';
    }
    if (whop_plan_ids() === []) {
        $blocking[] = 'Neither Whop plan id is set, so there is no plan to sell.';
    }
    if ($key === '') {
        $blocking[] = 'WHOP_API_KEY is not set. Payments still work through the shareable plan links, but the purchase carries no account id, so it has to be matched by the payer\'s verified email.';
    }
    if (whop_account_id() === '') {
        $blocking[] = 'WHOP_ACCOUNT_ID is not set, so a checkout configuration cannot be created and every purchase falls back to the shareable plan link.';
    }

    /*
     * THE BLOCKER THAT IS NOT A MISSING VALUE.
     *
     * Every sentence above assumes the file that holds these values is being read. For
     * the payment keys that worry is gone — whop_setting() reads them out of the file
     * itself, see whop_self_read_keys() — but the same file holds settings this page does
     * not own: the alert address, the Brevo keys, the feature flags, the rate limits, all
     * of them defined by config.php itself. On a server whose config.php predates the
     * overrides file those are saved and then ignored, and the operator who just saved
     * them is looking at the wrong problem. Reported by KEY NAME only, and only for the
     * keys nothing reads from the file; see includes/config_overrides.php.
     */
    $ovState = config_overrides_mismatch(null, whop_self_read_keys());
    if ($ovState['ignored'] !== []) {
        $ignored = count($ovState['ignored']);
        $named   = implode(', ', array_slice($ovState['ignored'], 0, 6))
                 . ($ignored > 6 ? ' and ' . ($ignored - 6) . ' more' : '');
        $blocking[] = 'storage/config_overrides.php is not being loaded by config.php, so '
            . $ignored . ' saved setting(s) are still on their previous values (' . $named . '). The payment keys'
            . ' this page reports are read straight out of that file, so they are in effect; the require_once at the'
            . ' top of config.php has to be restored before the rest of what was saved there is.';
    }

    return [
        // No 'masked' key on either secret, deliberately: the admin page renders
        // what it is given, and a masked value is still a value in a response body.
        'api_key'             => ['set' => $key !== '',    'length' => strlen($key)],
        'webhook_secret'      => ['set' => $secret !== '', 'length' => strlen($secret)],
        // The account id is not a secret — it is a public identifier that appears
        // in every checkout link — so a masked preview is honest and useful here.
        'account_id'          => ['set' => whop_account_id() !== '', 'masked' => $masked(whop_account_id())],
        'plans'               => whop_plan_ids(),
        'checkout_links'      => [
            'pro'          => whop_plan_link('pro'),
            'entrepreneur' => whop_plan_link('entrepreneur'),
        ],
        'api_base'            => whop_api_base(),
        'can_create_checkout' => whop_can_create_checkout(),
        'can_verify'          => whop_can_verify(),
        'can_accept_payments' => whop_can_accept_payments(),
        'blocking'            => $blocking,
    ];
}

/**
 * The webhook url to paste into Whop's dashboard for this deployment.
 *
 * Built from APP_BASE_URL so a staging install registers its own address rather
 * than the production one — registering production's url on staging is how a
 * staging webhook silently eats production events.
 */
function whop_webhook_url(): string
{
    $base = defined('APP_BASE_URL') ? trim((string)APP_BASE_URL) : '';
    if ($base === '') {
        $base = 'https://utiligo.ca';
    }

    return rtrim($base, '/') . '/whop-webhook.php';
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
        // Both sides are compared WITHOUT their "=" padding. Comparing the
        // re-encoded value to the trimmed input rejects every secret whose key is
        // not a multiple of three bytes — which is most of them, and includes the
        // 32-byte keys Standard Webhooks issues — so a real secret would have
        // failed verification for looking like an attack.
        if (is_string($decoded) && $decoded !== '' && rtrim(base64_encode($decoded), '=') === rtrim($inner, '=')) {
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
 *          'status' => ?string, 'reason' => string, 'alert' => string (optional)].
 *
 * 'alert' is the one place this table says "and tell a human". It is set only on
 * the ignore that means MONEY ARRIVED AND NOTHING HAPPENED — a paid payment for a
 * plan this deployment does not sell — because that is the case where every other
 * layer here is correct and the customer is still charged for nothing. The kind
 * is the alert name; see whop_alert().
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
            // Not the plain $ignore(): this is the one no-op that is about money.
            // Nothing is granted, correctly — see whop_plan_for_id, where the only
            // available guesses are "the wrong tier" or "the cheapest tier" — but
            // somebody's card was just charged and every page still says "Free".
            return [
                'action' => 'ignore',
                'alert'  => 'unmapped_plan',
                'plan'   => null,
                'status' => null,
                'reason' => 'a payment for a plan we do not sell (plan id '
                    . trim((string)($data['plan']['id'] ?? '(none)')) . ')',
            ];
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

        // A no-op that is nevertheless money: a verified, paid payment for a plan
        // we do not sell. The account is untouched, which is right, and a human is
        // told, which is the part that was missing.
        if (!empty($intent['alert'])) {
            whop_alert((string)$intent['alert'],
                'A payment arrived for a plan this deployment does not sell',
                $reason,
                ['webhook_id' => $webhookId, 'facts' => whop_event_facts($parsed)]);
        }

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

        // A paying customer reading "Free". Whop's retries may still resolve it on
        // their own (the common cause is an unverified payer email), but nobody
        // finds out until somebody is told — which is this line.
        whop_alert('unidentified_payment',
            'A payment arrived that could not be attached to any account',
            $intent['reason'] . '; ' . $reason,
            ['webhook_id' => $webhookId, 'facts' => whop_event_facts($parsed)]);

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

        // The account was identified and the write was refused: the customer has
        // paid and is still on free until Whop's retry lands. Rare, and always a
        // database problem, so the alert carries the same sentence the log does.
        whop_alert('write_failed',
            'A payment was identified but the plan could not be written',
            $reason,
            ['webhook_id' => $webhookId, 'facts' => whop_event_facts($parsed)]);

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

/* ═══════════════════════════════════════════════════════════════════════════
 * 5. TELLING A HUMAN
 *
 * WHY THIS EXISTS
 * ───────────────
 * Every failure in this file is silent from the only two places that could
 * notice it: the customer sees "Free" and has no way to know that is wrong (they
 * just paid, so they assume the plan is coming), and a log line is real but
 * nobody reads a log file on a hosted site. That is exactly the shape of the bug
 * this integration shipped with: payments taken, deliveries refused, nobody told.
 *
 * So the failures that cost money now send one email each, to ADMIN_EMAIL — the
 * same address, and the same best-effort attitude, as the global error handler's
 * fatal-error alerts. Three rules shape it:
 *
 *   • TELL THE TRUTH ABOUT WHAT TO DO. Each kind carries the actual next step,
 *     because "something failed" without one is a second notification, not a fix.
 *   • DON'T CRY WOLF. Whop retries a failed delivery with backoff for about three
 *     days, and every retry is the same event about the same payment. The alert
 *     is keyed to the delivery, so it fires once; the burst cap stops a hundred
 *     bad payments from becoming a hundred emails.
 *   • AN ALERT MUST NEVER BREAK THE MONEY PATH. Nothing here throws, nothing here
 *     is fatal, and a mail server that is down changes nothing about whether the
 *     payment is applied.
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * How often each kind of alert may be sent.
 *
 *   key 'delivery'  the alert is about one delivery, so the delivery id is the
 *                   throttle key and seconds '0' means "never twice": a retry of
 *                   the same event can never email again.
 *   key 'kind'      the alert is about a condition rather than a delivery (every
 *                   delivery being refused), so it is re-armed on a timer. A
 *                   rotated secret produces hundreds of refusals and exactly one
 *                   thing to fix.
 *
 * An unknown kind is throttled as a condition, hourly. Being wrong in that
 * direction sends one email too many; being wrong the other way hides a payment.
 */
function whop_alert_policy(string $kind): array
{
    $policies = [
        'unidentified_payment' => ['key' => 'delivery', 'seconds' => 0],
        'unmapped_plan'        => ['key' => 'delivery', 'seconds' => 0],
        'write_failed'         => ['key' => 'delivery', 'seconds' => 0],
        'signature_refused'    => ['key' => 'kind', 'seconds' => 21600],
    ];

    return $policies[$kind] ?? ['key' => 'kind', 'seconds' => 3600];
}

/** What the operator should actually do, per kind. The whole point of the email. */
function whop_alert_action(string $kind): string
{
    $actions = [
        'unidentified_payment' =>
            'The money is real and nobody is being charged twice. The usual cause is that the customer paid with an '
            . 'address that is not verified on their account, or paid signed in as somebody else. Fix it from Admin '
            . '→ Payments: the account can be found there by the payer\'s email and the Whop member id, and the plan '
            . 'can be granted by hand. The delivery is deliberately left open, so if the customer verifies that address '
            . 'first, Whop\'s own retries apply the payment without anybody doing anything.',
        'unmapped_plan' =>
            'Nothing was granted, and that is correct — guessing a tier is worse than doing nothing. Either the plan id '
            . 'changed in Whop (compare it with WHOP_PRO_PLAN_ID / WHOP_ENT_PLAN_ID in Admin → Settings), or '
            . 'another product on the same Whop account shares this webhook.',
        'write_failed' =>
            'The account was identified and the database refused the write, so Whop has been asked to retry and will. '
            . 'If it repeats, read storage/php_errors.log — until it lands, somebody who paid is still on the free plan.',
        'signature_refused' =>
            'Every delivery is being refused, which means one of two things: the webhook secret on this deployment is '
            . 'not the one Whop signs with (a rotation in the dashboard is the usual cause), or somebody is posting '
            . 'forged events. Fix the secret in Admin → Settings → Payments (Whop), then use '
            . 'Admin → Payments to deliver a signed test event. Until this is fixed, no purchase can be applied.',
    ];

    return $actions[$kind] ?? 'Open Admin → Payments for the delivery ledger and the current configuration.';
}

/**
 * The handful of facts about a delivery worth putting in an email.
 *
 * Read defensively — the payload is only partly ours, and a missing field must be
 * a missing row, never a warning. Amount is labelled "as Whop sent it" on purpose:
 * the scale (cents or units) is not documented in anything we can rely on, and a
 * wrong currency symbol in an alert is worse than no symbol.
 */
function whop_event_facts(array $parsed): array
{
    $data  = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
    $facts = [];

    $add = static function (string $label, $value) use (&$facts): void {
        if ((is_string($value) || is_numeric($value)) && trim((string)$value) !== '') {
            $facts[$label] = trim((string)$value);
        }
    };

    $add('Event', (string)($parsed['type'] ?? ''));
    $add('Whop plan id', $data['plan']['id'] ?? '');
    $add('Plan named in metadata', $data['metadata']['plan'] ?? ($data['plan']['metadata']['plan'] ?? ''));
    $add('Payment status', $data['status'] ?? '');
    $add('Billing reason', $data['billing_reason'] ?? '');
    $add('Payer email', $data['user']['email'] ?? ($data['email'] ?? ''));
    $add('Member id', $data['member']['id'] ?? '');
    $add('Membership id', $data['membership']['id'] ?? ($data['id'] ?? ''));
    $add('Paid at', $data['paid_at'] ?? '');
    if (isset($data['amount']) && is_numeric($data['amount'])) {
        $add('Amount (as Whop sent it)', $data['amount'] . (isset($data['currency']) ? ' ' . $data['currency'] : ''));
    }
    $add('Delivery id', (string)($parsed['webhook_id'] ?? ''));

    return $facts;
}

/**
 * Where the throttle state lives. A directory rather than a table: it is
 * bookkeeping with no recovery value — lose it and the worst outcome is one extra
 * email — and it has to work on a deployment whose database is the very thing
 * that is broken, which is one of the conditions worth alerting about.
 */
function whop_alert_dir(): string
{
    $dir = defined('WHOP_ALERT_DIR') ? (string)WHOP_ALERT_DIR : dirname(__DIR__) . '/storage';

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return rtrim($dir, '/\\');
}

/** Per-kind hourly budget, so a burst of bad payments is one email and not fifty. */
function whop_alert_budget(string $kind): int
{
    return defined('WHOP_ALERT_MAX_PER_HOUR') ? max(1, (int)WHOP_ALERT_MAX_PER_HOUR) : 3;
}

/**
 * May this alert be sent right now? Does not consume anything — the caller
 * records the send only once the mail server has actually taken the message.
 *
 * Returns ['ok' => bool, 'reason' => string, 'key' => string].
 */
function whop_alert_allowed(string $kind, array $context, int $now): array
{
    $dir    = whop_alert_dir();
    $policy = whop_alert_policy($kind);

    $delivery = trim((string)($context['webhook_id'] ?? ''));
    $window   = (int)$policy['seconds'];

    if ($policy['key'] === 'delivery' && $delivery !== '') {
        $bucket = $delivery;
    } else {
        // No delivery to key on, so the key is the period itself. The bucket size is
        // never smaller than a minute: a zero-second window with no key would
        // otherwise make every single refusal an email.
        $bucket = 'period-' . intdiv($now, max(60, $window));
        $window = max(60, $window);
    }

    $key  = md5($kind . '|' . $bucket);
    $mark = $dir . '/whop_alert_' . $key . '.time';

    if (is_file($mark)) {
        // seconds 0 means "this key never alerts twice".
        if ($window === 0 || ($now - (int)@file_get_contents($mark)) < $window) {
            return ['ok' => false, 'reason' => 'this one has already been alerted', 'key' => $key];
        }
    }

    $burstFile = $dir . '/whop_alert_burst.json';
    $burst     = json_decode((string)@file_get_contents($burstFile), true);
    $burst     = is_array($burst) ? $burst : [];
    $hour      = intdiv($now, 3600);

    if ((int)($burst['hour'] ?? 0) !== $hour) {
        $burst = ['hour' => $hour, 'kinds' => [], 'total' => 0];
    }

    $used = (int)($burst['kinds'][$kind] ?? 0);
    if ($used >= whop_alert_budget($kind)) {
        return ['ok' => false, 'reason' => 'the hourly budget for this kind of alert is used up', 'key' => $key];
    }

    return ['ok' => true, 'reason' => '', 'key' => $key];
}

/** Record a send that the mail server accepted, so the next one is throttled. */
function whop_alert_record(string $kind, string $key, int $now): void
{
    $dir = whop_alert_dir();
    @file_put_contents($dir . '/whop_alert_' . $key . '.time', (string)$now, LOCK_EX);

    $burstFile = $dir . '/whop_alert_burst.json';
    $burst     = json_decode((string)@file_get_contents($burstFile), true);
    $burst     = is_array($burst) ? $burst : [];

    if ((int)($burst['hour'] ?? 0) !== intdiv($now, 3600)) {
        $burst = ['hour' => intdiv($now, 3600), 'kinds' => [], 'total' => 0];
    }

    $burst['kinds'][$kind] = (int)($burst['kinds'][$kind] ?? 0) + 1;
    $burst['total']        = (int)($burst['total'] ?? 0) + 1;

    @file_put_contents($burstFile, json_encode($burst), LOCK_EX);
}

/**
 * Tell the site administrator that a delivery did something, or failed to.
 *
 * Returns ['sent' => bool, 'reason' => string]. Never throws, never blocks the
 * caller, and always writes the log line first — the email is the interruption,
 * the log is the record.
 */
function whop_alert(string $kind, string $headline, string $detail, array $context = []): array
{
    $kind = trim($kind);
    $now  = time();

    whop_log('alert:' . $kind, $headline . ' — ' . $detail);

    if (!defined('ADMIN_EMAIL') || trim((string)ADMIN_EMAIL) === '') {
        return ['sent' => false, 'reason' => 'no ADMIN_EMAIL is configured, so this reached the log only'];
    }

    $allowed = whop_alert_allowed($kind, $context, $now);
    if (!$allowed['ok']) {
        return ['sent' => false, 'reason' => $allowed['reason']];
    }

    $facts = [];
    foreach ((array)($context['facts'] ?? []) as $label => $value) {
        $facts[(string)$label] = (string)$value;
    }

    $rows = '';
    foreach ($facts as $label => $value) {
        $rows .= '<tr><td style="padding:4px 10px;color:#64748b;">' . htmlspecialchars($label) . '</td>'
               . '<td style="padding:4px 10px;">' . htmlspecialchars(whop_redact($value)) . '</td></tr>';
    }

    $text = $headline . "\n\n" . whop_redact($detail) . "\n\n";
    foreach ($facts as $label => $value) {
        $text .= $label . ': ' . whop_redact($value) . "\n";
    }
    $text .= "\nWhat to do\n" . whop_alert_action($kind) . "\n";

    $html = '<h3 style="margin:0;font-family:sans-serif;">' . htmlspecialchars($headline) . '</h3>'
          . '<p style="font-family:sans-serif;font-size:13px;color:#334155;">' . htmlspecialchars(whop_redact($detail)) . '</p>'
          . '<table style="font-family:monospace;font-size:13px;border-collapse:collapse;">' . $rows . '</table>'
          . '<p style="font-family:sans-serif;font-size:13px;background:#f8fafc;border-left:3px solid #0f7a45;padding:12px;border-radius:6px;">'
          . '<b>What to do.</b> ' . htmlspecialchars(whop_alert_action($kind)) . '</p>'
          . '<p style="font-family:sans-serif;font-size:12px;color:#94a3b8;">Sent by the payment handler. '
          . 'The delivery ledger, the current configuration and a test delivery are on '
          . '<a href="' . htmlspecialchars(whop_admin_url()) . '">Admin → Payments</a>. '
          . 'Repeats of the same delivery are not emailed again, and a kind of alert is capped at '
          . whop_alert_budget($kind) . ' an hour so a burst cannot bury this one.</p>';

    try {
        if (!function_exists('send_email')) {
            require_once __DIR__ . '/mailer.php';
        }
        if (!function_exists('send_email')) {
            return ['sent' => false, 'reason' => 'the mailer is not available'];
        }

        $subject = '[Utiligo] Payment: ' . substr(preg_replace('/\s+/', ' ', $headline) ?? $headline, 0, 90);
        $sent    = send_email((string)ADMIN_EMAIL, $subject, $html, $text, 'Utiligo Admin');

        if ($sent) {
            whop_alert_record($kind, (string)$allowed['key'], $now);
        }

        return ['sent' => (bool)$sent, 'reason' => $sent ? 'sent' : 'the mail server refused it'];
    } catch (\Throwable $e) {
        // An alert that raises becomes the incident it was reporting.
        whop_log('alert:' . $kind, 'could not email the administrator: ' . $e->getMessage());

        return ['sent' => false, 'reason' => 'the alert itself raised: ' . $e->getMessage()];
    }
}

/** The admin Payments page, absolute so it is clickable from a mail client. */
function whop_admin_url(): string
{
    $base = defined('APP_BASE_URL') ? trim((string)APP_BASE_URL) : '';
    if ($base === '') {
        $base = 'https://utiligo.ca';
    }

    return rtrim($base, '/') . '/admin/payments.php';
}
