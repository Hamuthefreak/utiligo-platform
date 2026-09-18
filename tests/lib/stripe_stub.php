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
 *
 * Fixtures live in tests/tmp/stripe_sessions.json, written by the test that
 * needs them. A fixture may carry a "_status" key to force an HTTP status, so
 * error handling (a declined card, a revoked key) can be exercised too.
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

/* ── Anything else is a test bug worth surfacing ───────────────────────── */
http_response_code(404);
echo json_encode([
    'error' => [
        'type'    => 'invalid_request_error',
        'message' => 'stripe_stub: unexpected ' . $method . ' ' . $path,
    ],
]);
