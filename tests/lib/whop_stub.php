<?php
/**
 * tests/lib/whop_stub.php
 *
 * A stand-in for api.whop.com, used as the router for a `php -S` instance. It
 * exists so the Whop half of the payment path can be tested in CI with no
 * network access and no Whop account, while still exercising the application's
 * real request code — the same job tests/lib/stripe_stub.php does for Stripe.
 *
 * Endpoints:
 *   POST /api/v1/checkout_configurations   Create a checkout configuration.
 *                                          The request body is recorded
 *                                          verbatim, because the metadata in it
 *                                          is what the webhook later uses to
 *                                          identify the buyer, and a test has to
 *                                          assert the account id really is in
 *                                          there.
 *
 * Anything else returns a Whop-shaped 404 so a test that forgets to register a
 * fixture fails loudly instead of silently receiving an empty object.
 *
 * Fixtures live in tests/tmp/whop_checkout.json — {"id": "ch_test_1"} by
 * default. A "_status" key in the fixture forces an HTTP status, so "the API
 * refused" (a revoked key, a plan id that no longer exists) is exercisable
 * without breaking the network.
 *
 * Every request is appended to tests/tmp/whop_requests.log as one JSON object
 * per line, so a test can assert the ABSENCE of a call — "no second checkout was
 * created for a customer who already subscribes" is the assertion that proves a
 * customer was not billed twice, and it cannot be made against a last-request
 * snapshot.
 *
 * The Authorization header is recorded only as a boolean. The stub must never be
 * the reason an API key ends up in a log file the suite leaves behind.
 */

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw    = file_get_contents('php://input');
$tmpDir = __DIR__ . '/../tmp';

if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
}

$record = [
    'method'       => $method,
    'path'         => $path,
    'query'        => $_GET ?: [],
    'raw'          => $raw,
    'body'         => json_decode((string)$raw, true) ?: [],
    'authorized'   => isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
];

@file_put_contents($tmpDir . '/whop_last_request.json', json_encode($record, JSON_PRETTY_PRINT));
@file_put_contents($tmpDir . '/whop_requests.log', json_encode($record) . "\n", FILE_APPEND);

/* ── Injected failures ──────────────────────────────────────────────────────
 * tests/tmp/whop_failures.json maps "METHOD PATH" to an HTTP status, which is
 * how "the API is down" is tested without also testing what the app does when
 * the network itself is broken.
 */
$failures = [];
if (is_file($tmpDir . '/whop_failures.json')) {
    $failures = json_decode((string)file_get_contents($tmpDir . '/whop_failures.json'), true) ?: [];
}
$key = $method . ' ' . $path;
if (isset($failures[$key])) {
    http_response_code((int)$failures[$key]);
    echo json_encode(['message' => 'whop_stub: injected failure for ' . $key]);
    exit;
}

/* ── The one endpoint the application calls ───────────────────────────────── */

if ($method === 'POST' && $path === '/api/v1/checkout_configurations') {
    $fixture = ['id' => 'ch_test_default'];
    if (is_file($tmpDir . '/whop_checkout.json')) {
        $fixture = json_decode((string)file_get_contents($tmpDir . '/whop_checkout.json'), true) ?: $fixture;
    }

    $status = (int)($fixture['_status'] ?? 200);
    unset($fixture['_status']);

    http_response_code($status);
    if ($status >= 300) {
        echo json_encode(['message' => (string)($fixture['message'] ?? 'rejected')]);
        exit;
    }

    echo json_encode($fixture);
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'whop_stub: unexpected ' . $method . ' ' . $path]);
