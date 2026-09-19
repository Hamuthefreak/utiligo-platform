<?php
/**
 * includes/stripe_api.php
 *
 * The two pieces of Stripe contact the app needs, in one place:
 * a raw-cURL request helper (this codebase deliberately has no Stripe SDK and
 * no Composer) and webhook signature verification.
 *
 * WHY THIS IS SHARED
 * ─────────────────
 * Both were previously written inline and separately — stripe-checkout.php built
 * its own request, purchase-success.php built a second one, and the signature
 * check lived at the bottom of stripe-webhook.php where nothing could reach it
 * without executing the whole endpoint. That made the payment path untestable
 * and let the two request copies drift (different timeouts, different error
 * handling, one of them missing the cURL-availability guard entirely).
 *
 * The base URL is overridable via the STRIPE_API_BASE environment variable
 * specifically so the payment-path test suite can point every caller at a local
 * stub and assert what was actually sent, without any test ever reaching
 * api.stripe.com.
 */

/**
 * How old a signed webhook request may be, in seconds.
 *
 * Stripe's own SDKs default to 300. Signature verification alone proves a
 * payload was signed with our secret, but not that the request is *new*:
 * without a window one captured request could be replayed indefinitely, and a
 * replayed customer.subscription.deleted is a paying customer locked out.
 *
 * Defined rather than hard-coded so tests can shrink it to exercise the
 * boundary without sleeping.
 */
if (!defined('STRIPE_WEBHOOK_TOLERANCE')) define('STRIPE_WEBHOOK_TOLERANCE', 300);

/** Base URL for the Stripe API, without a trailing slash. */
function stripe_api_base(): string
{
    $base = getenv('STRIPE_API_BASE');
    if (!is_string($base) || trim($base) === '') {
        $base = 'https://api.stripe.com';
    }
    return rtrim($base, '/');
}

/**
 * Is a real secret key configured? A placeholder means Stripe is not set up on
 * this install, which every caller treats as "degrade gracefully".
 */
function stripe_is_configured(): bool
{
    return STRIPE_SECRET_KEY !== '' && !str_starts_with(STRIPE_SECRET_KEY, 'YOUR_');
}

/**
 * One Stripe API call. Never throws.
 *
 * Returns ['ok' => bool, 'http' => int, 'data' => array, 'error' => string].
 * 'data' carries Stripe's parsed body even on failure, because Stripe puts the
 * human-readable reason in data['error']['message'] and the callers surface it.
 *
 * @param string     $method  'GET' or 'POST'
 * @param string     $path    e.g. 'v1/checkout/sessions/cs_123'
 * @param array|null $form    form fields for POST
 * @param int        $timeout seconds; kept per-caller because a redirect landing
 *                            page can afford less patience than a checkout start
 */
function stripe_request(string $method, string $path, ?array $form = null, int $timeout = 15): array
{
    // A host without cURL can never reach Stripe at all. Guarded rather than
    // assumed, because an undefined function here would 500 a page the customer
    // lands on seconds after paying.
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'cURL unavailable'];
    }

    $url = stripe_api_base() . '/' . ltrim($path, '/');

    try {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
            CURLOPT_TIMEOUT        => $timeout,
        ];

        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form ?? []);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string)curl_error($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        return ['ok' => false, 'http' => 0, 'data' => [], 'error' => $e->getMessage()];
    }

    $data = is_string($body) ? json_decode($body, true) : null;

    return [
        'ok'    => $http === 200 && is_array($data),
        'http'  => $http,
        'data'  => is_array($data) ? $data : [],
        'error' => $err !== '' ? $err : ($http === 200 ? '' : 'http ' . $http),
    ];
}

/**
 * Verify a Stripe webhook request.
 *
 * Returns true only for a request that is both correctly signed AND recent.
 *
 * Two behaviours are load-bearing:
 *
 *   • A placeholder secret skips verification entirely. Stripe is not
 *     configured on that install, so there is nothing to verify against —
 *     consistent with how the rest of the codebase reads YOUR_* values.
 *
 *   • With a real secret, an UNSIGNED request is rejected. The previous
 *     implementation short-circuited to true on an empty signature header
 *     (`!$header || …`), which meant anyone could POST an unsigned event to
 *     stripe-webhook.php and grant themselves a paid plan. It read like a
 *     dev-mode convenience and was a live privilege-escalation hole.
 *
 * @param int|null $tolerance override the freshness window, in seconds
 */
function stripe_webhook_verify_signature(string $payload, string $header, string $secret, ?int $tolerance = null): bool
{
    if ($secret === '' || str_starts_with($secret, 'YOUR_')) {
        return true; // Stripe not configured — nothing to verify against.
    }

    if ($header === '') {
        return false; // A configured install must not accept unsigned events.
    }

    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k][] = $v;
    }

    $timestamp = (int)($parts['t'][0] ?? 0);
    if ($timestamp <= 0) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    // Any of the v1 signatures may match: Stripe sends more than one during a
    // secret rotation, and rejecting a rotation window would drop real events.
    $signed = false;
    foreach ($parts['v1'] ?? [] as $sig) {
        if (hash_equals($expected, $sig)) { $signed = true; break; }
    }
    if (!$signed) {
        return false;
    }

    // Signature first, freshness second: an unsigned request learns nothing
    // about our clock, and a valid signature is a prerequisite for spending any
    // effort on the timestamp. Logged with the actual skew, because a drifted
    // server clock is the one thing that would make this reject real traffic.
    $tolerance ??= STRIPE_WEBHOOK_TOLERANCE;
    $skew = abs(time() - $timestamp);
    if ($skew > $tolerance) {
        if (function_exists('error_log')) {
            error_log('[stripe] rejected replayed webhook: signed ' . $skew
                . 's ago (tolerance ' . $tolerance . 's)');
        }
        return false;
    }

    return true;
}

/**
 * Build the Stripe-Signature header for a payload.
 *
 * Production code has no use for this — it exists so the test suite can produce
 * genuine signatures instead of stubbing the verifier out, which keeps the
 * signature tests honest and lets the same helper drive the end-to-end webhook
 * tests over real HTTP.
 */
function stripe_webhook_sign(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
}

/**
 * The form fields for a Checkout Session.
 *
 * Pure, so what a purchase actually asks Stripe for can be asserted directly
 * rather than inferred from a live API call. Two of these fields are
 * security-relevant and are the reason this is worth pinning down:
 *
 *   client_reference_id  who is paying. stripe-checkout.php stamps it from the
 *                        session, which is what lets the webhook and
 *                        purchase-success.php attribute the purchase to an
 *                        account without trusting anything in the URL.
 *   metadata[plan]       what they bought. The plan is taken from here later —
 *                        never from a `?plan` query parameter, which anyone can
 *                        type.
 *
 * The {CHECKOUT_SESSION_ID} token in success_url is Stripe's own placeholder,
 * substituted when it redirects the customer back.
 *
 * @param array  $user       the paying account (needs id + email)
 * @param string $plan       'pro' or 'entrepreneur'
 * @param string $priceId    Stripe price id for that plan
 * @param string $baseUrl    e.g. https://utiligo.ca, no trailing slash needed
 * @param string $customerId the Stripe customer this account already has, if
 *                           any. Passing it is not an optimisation — it is how
 *                           a customer who has bought before keeps ONE customer
 *                           object instead of acquiring a new one per purchase.
 *                           Stripe rejects a request that carries both
 *                           `customer` and `customer_email`, so only one of the
 *                           two is ever sent.
 */
function stripe_checkout_session_params(array $user, string $plan, string $priceId, string $baseUrl, string $customerId = ''): array
{
    $baseUrl    = rtrim($baseUrl, '/');
    $userId     = (string)($user['id'] ?? '');
    $customerId = trim($customerId);
    $successUrl = $baseUrl . '/purchase-success.php?plan=' . urlencode($plan) . '&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl  = $baseUrl . '/portal/billing.php?upgrade=1&plan=' . urlencode($plan) . '&cancelled=1';

    $params = [
        'mode'                                 => 'subscription',
        'line_items[0][price]'                 => $priceId,
        'line_items[0][quantity]'              => '1',
        'client_reference_id'                  => $userId,
        'metadata[plan]'                       => $plan,
        'metadata[user_id]'                    => $userId,
        'success_url'                          => $successUrl,
        'cancel_url'                           => $cancelUrl,
        // Carried onto the Subscription so the lifecycle events
        // (deleted / past_due), whose object has no client_reference_id, can
        // still be attributed without a database lookup.
        'subscription_data[metadata][plan]'    => $plan,
        'subscription_data[metadata][user_id]' => $userId,
    ];

    if ($customerId !== '') {
        $params['customer'] = $customerId;
    } else {
        $params['customer_email'] = (string)($user['email'] ?? '');
    }

    return $params;
}

/**
 * Statuses in which a subscription is still charging the customer.
 *
 * The list is short on purpose and every entry in it matters, because the
 * caller's rule is "if this account already has one of these, change it instead
 * of selling a second one":
 *
 *   active    the ordinary case, and the one that used to be billed twice.
 *   trialing  not paying yet, but will be. A second subscription here bills
 *             twice the day the trials end.
 *   past_due  Stripe is still retrying the card and the subscription is still
 *             live. Selling a second one leaves the customer holding two.
 *
 * Everything else — canceled, incomplete, incomplete_expired, unpaid, paused —
 * is a subscription that will not charge again, so a fresh checkout is the right
 * answer for it rather than an in-place change.
 *
 * @return string[]
 */
function stripe_subscription_live_statuses(): array
{
    return ['active', 'trialing', 'past_due'];
}

/** Is this subscription still charging the customer? */
function stripe_subscription_is_live(array $subscription): bool
{
    $status = strtolower(trim((string)($subscription['status'] ?? '')));
    return in_array($status, stripe_subscription_live_statuses(), true);
}

/**
 * The id of the subscription item whose price a plan change replaces, or ''.
 *
 * Stripe does not let you swap a subscription's price directly: you name the
 * ITEM (si_…) that currently holds it. Sending only a price id would ADD a
 * second item and bill for both — which is the same double-charge bug one level
 * down from the one this module fixes, so an empty id is treated as a refusal by
 * the caller rather than as "no item to worry about".
 */
function stripe_subscription_item_id(array $subscription): string
{
    return trim((string)($subscription['items']['data'][0]['id'] ?? ''));
}

/** The price id a subscription is currently on, or ''. */
function stripe_subscription_price_id(array $subscription): string
{
    return trim((string)($subscription['items']['data'][0]['price']['id'] ?? ''));
}

/**
 * The subscription a checkout should change, out of everything the customer
 * holds — or null when there is nothing to change.
 *
 * This asks Stripe rather than trusting our stored `stripe_subscription_id`,
 * because the recorded id is exactly the thing that goes stale: it is written by
 * a webhook that may have been missed, and it is empty for any subscription
 * started outside our checkout (in the Stripe dashboard, say). Selling a second
 * subscription is the failure mode, so it must not depend on our own bookkeeping
 * being current.
 *
 * When more than one is live the most recently created wins, which is the one a
 * checkout most likely produced. Oldest-first would repeatedly try to change a
 * subscription the customer replaced.
 */
function stripe_pick_live_subscription(array $subscriptions): ?array
{
    $best = null;

    foreach ($subscriptions as $subscription) {
        if (!is_array($subscription) || !stripe_subscription_is_live($subscription)) {
            continue;
        }

        if ($best === null || (int)($subscription['created'] ?? 0) > (int)($best['created'] ?? 0)) {
            $best = $subscription;
        }
    }

    return $best;
}

/**
 * Everything a customer's subscriptions live under, so a checkout can ask
 * whether one of them is still billing them.
 *
 * Returns ['ok' => bool, 'subscription' => ?array, 'error' => string].
 *
 * `ok => false` is NOT the same as `subscription => null`, and the caller must
 * not conflate them: null means "this customer has nothing running, so selling
 * them a subscription is right", while a failure means "we could not tell" —
 * and the safe answer to that is to refuse the purchase, not to guess. Guessing
 * is how a customer ends up with two.
 *
 * `status=all` is explicit because the default filter has changed across API
 * versions, and a filter that hid `past_due` would report a live subscription as
 * absent.
 */
function stripe_customer_live_subscription(string $customerId): array
{
    $customerId = trim($customerId);
    if ($customerId === '') {
        return ['ok' => true, 'subscription' => null, 'error' => ''];
    }

    $query = http_build_query([
        'customer' => $customerId,
        'status'   => 'all',
        'limit'    => 20,
    ]);

    $response = stripe_request('GET', 'v1/subscriptions?' . $query);
    if (!$response['ok']) {
        return [
            'ok'           => false,
            'subscription' => null,
            'error'        => (string)($response['data']['error']['message'] ?? ($response['error'] ?: 'stripe_error')),
        ];
    }

    return [
        'ok'           => true,
        'subscription' => stripe_pick_live_subscription((array)($response['data']['data'] ?? [])),
        'error'        => '',
    ];
}

/**
 * The form fields for changing a running subscription's plan in place.
 *
 * Pure, so what an upgrade actually asks Stripe for can be asserted directly.
 *
 *   items[0][id]        the item being replaced. Naming it is what makes this a
 *                       change rather than an addition.
 *   items[0][price]     the plan being moved to.
 *   proration_behavior  create_prorations: the unused time on the old plan is
 *                       credited and the difference is billed on the next
 *                       invoice. Deliberately NOT `always_invoice`, which would
 *                       charge the card immediately and fail outright if the
 *                       card needs authentication — an upgrade that cannot
 *                       start is worse than one that settles at period end.
 *   cancel_at_period_end  cleared when set, because a customer who had scheduled
 *                       a cancellation and then chooses a plan has plainly
 *                       changed their mind; leaving it set would take the plan
 *                       away at the end of a period they just paid for.
 */
function stripe_subscription_swap_params(array $subscription, string $priceId, string $plan, int $userId): array
{
    $params = [
        'items[0][id]'       => stripe_subscription_item_id($subscription),
        'items[0][price]'    => $priceId,
        'proration_behavior' => 'create_prorations',
        // Keep the metadata in step with the price. Stripe's own portal cannot
        // rewrite our metadata, so leaving it stale would make the fallback in
        // entitlement_plan_from_subscription() name the plan they left.
        'metadata[plan]'     => $plan,
        'metadata[user_id]'  => (string)$userId,
    ];

    if (!empty($subscription['cancel_at_period_end'])) {
        $params['cancel_at_period_end'] = 'false';
    }

    return $params;
}

/**
 * Change a running subscription's plan. Never throws.
 *
 * Returns the same shape as stripe_request(), and its `data` is the UPDATED
 * subscription object — which the caller reconciles entitlement from, so a
 * delayed `customer.subscription.updated` cannot leave a paying customer
 * reading as though they had never upgraded.
 */
function stripe_change_subscription_plan(string $subscriptionId, array $params): array
{
    $subscriptionId = trim($subscriptionId);
    if ($subscriptionId === '') {
        return ['ok' => false, 'http' => 0, 'data' => [], 'error' => 'no subscription to change'];
    }

    return stripe_request('POST', 'v1/subscriptions/' . rawurlencode($subscriptionId), $params);
}
