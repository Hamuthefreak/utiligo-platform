<?php
/**
 * tests/lib/stripe_stub.php
 *
 * A stand-in for api.stripe.com, used as the router for a `php -S` instance.
 * It exists so the payment-path tests can run in CI with no network access and
 * no Stripe account, while still exercising the app's real request code.
 *
 * Endpoints:
 *   POST /v1/checkout/sessions           Create a session. The request body is
 *                                        recorded verbatim so a test can assert
 *                                        exactly what the app asked for.
 *   GET  /v1/checkout/sessions/{id}      Return the fixture registered for {id}
 *                                        by t_set_stripe_sessions().
 *   GET  /v1/subscriptions               List the fixtures registered by
 *                                        t_set_stripe_subscriptions(), filtered
 *                                        by the `customer` query parameter the
 *                                        way Stripe filters.
 *   GET  /v1/subscriptions/{id}          Return one of those fixtures.
 *   POST /v1/subscriptions/{id}          Apply a price/metadata change to the
 *                                        fixture and return the updated object,
 *                                        which is the object Stripe really
 *                                        returns and the one the app reconciles
 *                                        entitlement from.
 *
 * Fixtures live in tests/tmp/stripe_sessions.json and
 * tests/tmp/stripe_subscriptions.json, written by the tests that need them. A
 * fixture may carry a "_status" key to force an HTTP status, so error handling
 * (a declined card, a revoked key) can be exercised too.
 *
 * Every request is appended to tests/tmp/stripe_requests.log as one JSON object
 * per line, and not only to stripe_last_request.json. That log is what lets a
 * test assert the absence of a call: "no Checkout Session was created" is the
 * assertion that proves a second subscription was not sold, and it cannot be
 * made against the last request alone.
 *
 * Anything unrecognised returns Stripe-shaped JSON 404 so a test that forgets to
 * register a fixture fails loudly instead of silently getting an empty object.
 */

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw    = file_get_contents('php://input');
$tmpDir = __DIR__ . '/../tmp';

/* Record every request so tests can assert what the app actually sent. */
$record = [
    'method' => $method,
    'path'   => $path,
    'query'  => $_GET ?: [],
    'raw'    => $raw,
    'form'   => [],
    'auth'   => $_SERVER['PHP_AUTH_USER'] ?? '',
];

// PHP consumes the body of an application/x-www-form-urlencoded request while
// populating $_POST, so php://input can come back empty even though the request
// carried fields. Prefer $_POST and keep php://input for anything else.
$parsed = $_POST ?: [];
if (!$parsed) {
    parse_str((string)$raw, $parsed);
}
$record['form'] = $parsed;

if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
}
@file_put_contents($tmpDir . '/stripe_last_request.json', json_encode($record, JSON_PRETTY_PRINT));
@file_put_contents($tmpDir . '/stripe_requests.log', json_encode($record) . "\n", FILE_APPEND);

/* ── Injected failures ──────────────────────────────────────────────────────
 * tests/tmp/stripe_failures.json maps "METHOD PATH" to an HTTP status, so a
 * test can exercise what the app does when Stripe is unreachable or unhappy
 * WITHOUT also exercising what it does when the network is broken. The path
 * carries no query string, so one entry covers every variation of a call.
 *
 * This exists for the lookups that must fail closed: an app that cannot tell
 * whether a customer already subscribes must not conclude that they don't.
 */
$failureFile = $tmpDir . '/stripe_failures.json';
if (is_file($failureFile)) {
    $failures = json_decode((string)file_get_contents($failureFile), true);
    $forced   = is_array($failures) ? (int)($failures[$method . ' ' . rtrim($path, '/')] ?? 0) : 0;
    if ($forced > 0) {
        http_response_code($forced);
        echo json_encode(['error' => [
            'type'    => 'api_error',
            'message' => 'stub forced status ' . $forced . ' for ' . $method . ' ' . $path,
        ]]);
        return;
    }
}

/* ── Create a Checkout Session ─────────────────────────────────────────── */
if ($method === 'POST' && rtrim($path, '/') === '/v1/checkout/sessions') {
    $id = 'cs_test_created_' . bin2hex(random_bytes(4));

    echo json_encode([
        'id'                  => $id,
        'object'              => 'checkout.session',
        'status'              => 'open',
        'payment_status'      => 'unpaid',
        'mode'                => $parsed['mode'] ?? 'subscription',
        'url'                 => 'https://checkout.stripe.test/pay/' . $id,
        'client_reference_id' => $parsed['client_reference_id'] ?? null,
        'metadata'            => ['plan' => $parsed['metadata[plan]'] ?? null],
        'created'             => time(),
    ]);
    return;
}

/* ── Retrieve a Checkout Session ───────────────────────────────────────── */
if ($method === 'GET' && preg_match('#^/v1/checkout/sessions/([A-Za-z0-9_]+)$#', $path, $m)) {
    $sessions = [];
    $fixtureFile = $tmpDir . '/stripe_sessions.json';
    if (is_file($fixtureFile)) {
        $decoded  = json_decode((string)file_get_contents($fixtureFile), true);
        $sessions = is_array($decoded) ? $decoded : [];
    }

    $session = $sessions[$m[1]] ?? null;
    if (!is_array($session)) {
        http_response_code(404);
        echo json_encode([
            'error' => [
                'type'    => 'invalid_request_error',
                'code'    => 'resource_missing',
                'message' => 'No such checkout session: ' . $m[1],
            ],
        ]);
        return;
    }

    $status = (int)($session['_status'] ?? 200);
    unset($session['_status']);
    http_response_code($status);

    if ($status !== 200) {
        echo json_encode(['error' => ['type' => 'api_error', 'message' => 'stub forced status ' . $status]]);
        return;
    }

    echo json_encode($session);
    return;
}

/* ── Subscriptions ─────────────────────────────────────────────────────── */

/**
 * The registered subscription fixtures, keyed by id.
 *
 * Read fresh on every request: the stub is a router for `php -S`, so it is a
 * brand-new process each time and has no state to keep between requests. The
 * fixtures file is the state.
 */
function stub_subscriptions(string $tmpDir): array
{
    $file = $tmpDir . '/stripe_subscriptions.json';
    if (!is_file($file)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

/* List a customer's subscriptions. */
if ($method === 'GET' && rtrim($path, '/') === '/v1/subscriptions') {
    $customer = (string)($_GET['customer'] ?? '');

    $data = [];
    foreach (stub_subscriptions($tmpDir) as $id => $subscription) {
        if (!is_array($subscription)) {
            continue;
        }
        if ($customer !== '' && (string)($subscription['customer'] ?? '') !== $customer) {
            continue;
        }
        $data[] = $subscription + ['id' => (string)$id];
    }

    echo json_encode(['object' => 'list', 'data' => $data, 'has_more' => false]);
    return;
}

/* Retrieve one subscription. */
if ($method === 'GET' && preg_match('#^/v1/subscriptions/([A-Za-z0-9_]+)$#', $path, $m)) {
    $subscription = stub_subscriptions($tmpDir)[$m[1]] ?? null;
    if (!is_array($subscription)) {
        http_response_code(404);
        echo json_encode(['error' => [
            'type'    => 'invalid_request_error',
            'code'    => 'resource_missing',
            'message' => 'No such subscription: ' . $m[1],
        ]]);
        return;
    }

    echo json_encode($subscription + ['id' => $m[1]]);
    return;
}

/*
 * Change a subscription's plan.
 *
 * The response is built the way Stripe builds it — the price on the item the
 * caller named is REPLACED, not appended — because that object is what the app
 * reconciles entitlement from. A stub that merely echoed the request back would
 * let a wrong price swap pass unnoticed.
 */
if ($method === 'POST' && preg_match('#^/v1/subscriptions/([A-Za-z0-9_]+)$#', $path, $m)) {
    $all          = stub_subscriptions($tmpDir);
    $subscription = $all[$m[1]] ?? null;
    if (!is_array($subscription)) {
        http_response_code(404);
        echo json_encode(['error' => [
            'type'    => 'invalid_request_error',
            'code'    => 'resource_missing',
            'message' => 'No such subscription: ' . $m[1],
        ]]);
        return;
    }

    $itemId  = (string)($parsed['items'][0]['id'] ?? '');
    $item    = $subscription['items']['data'][0] ?? [];

    // Naming an item that is not this subscription's item is a request Stripe
    // would reject, and the stub rejects it too so a test cannot pass by
    // describing a change the app could not actually have made.
    if ($itemId === '' || $itemId !== (string)($item['id'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => [
            'type'    => 'invalid_request_error',
            'message' => 'No such subscription item: ' . $itemId,
        ]]);
        return;
    }

    $subscription['items']['data'][0]['price']['id'] = (string)($parsed['items'][0]['price'] ?? '');

    foreach ((array)($parsed['metadata'] ?? []) as $key => $value) {
        $subscription['metadata'][$key] = $value;
    }

    if (array_key_exists('cancel_at_period_end', $parsed)) {
        $subscription['cancel_at_period_end'] = $parsed['cancel_at_period_end'] === 'true';
    }

    $subscription['id'] = $m[1];
    echo json_encode($subscription);
    return;
}

/* ── Anything else is a test bug worth surfacing ───────────────────────── */
http_response_code(404);
echo json_encode([
    'error' => [
        'type'    => 'invalid_request_error',
        'message' => 'stripe_stub: unexpected ' . $method . ' ' . $path,
    ],
]);
