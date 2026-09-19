<?php
/**
 * tests/lib/mail_stub.php
 *
 * A stand-in for api.brevo.com, used as the router for a `php -S` instance.
 *
 * Why this exists: until now there was no way to prove the one thing that decides
 * whether a customer ever hears from us. `send_email()` posts to Brevo and, on any
 * failure, falls through to PHP's `mail()` — so a test could either send real mail
 * or assert nothing at all. Pointing MAIL_API_BASE at this file is a supported
 * configuration of the real code (see email_api_base() in includes/mailer.php), not
 * a test-only branch, so the request that gets built here is the request production
 * builds — subject line, recipient, HTML body and all.
 *
 * Endpoints:
 *   POST /v3/smtp/email   Accept one transactional email. The payload is recorded
 *                         verbatim so a test can assert the recipient and subject.
 *   POST /v3/contacts     Accept a contact upsert (Brevo lists).
 *
 * Every request is appended to tests/tmp/mail_requests.log as one JSON object per
 * line. A log rather than a single slot, because the assertion that matters is
 * usually "how many emails went out, and to whom" — and because a test that
 * expects silence needs to see that nothing at all arrived.
 *
 * Anything unrecognised returns a Brevo-shaped 404 so a test that gets the URL
 * wrong fails loudly instead of quietly believing a send succeeded.
 */

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw    = file_get_contents('php://input');
$tmpDir = __DIR__ . '/../tmp';

$decoded = json_decode((string)$raw, true);
$record  = [
    'method'  => $method,
    'path'    => $path,
    'api_key' => $_SERVER['HTTP_API_KEY'] ?? '',
    'payload' => is_array($decoded) ? $decoded : [],
];

if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
}
@file_put_contents($tmpDir . '/mail_requests.log', json_encode($record) . "\n", FILE_APPEND);

/* ── Send a transactional email ────────────────────────────────────────── */
if ($method === 'POST' && rtrim($path, '/') === '/v3/smtp/email') {
    if (empty($record['payload']['to'][0]['email'])) {
        http_response_code(400);
        echo json_encode(['code' => 'missing_parameter', 'message' => 'to is required']);
        return;
    }

    echo json_encode(['messageId' => '<stub-' . bin2hex(random_bytes(6)) . '@utiligo.test>']);
    return;
}

/* ── Upsert a contact ──────────────────────────────────────────────────── */
if ($method === 'POST' && rtrim($path, '/') === '/v3/contacts') {
    echo json_encode(['id' => random_int(1000, 9999)]);
    return;
}

/* ── Anything else is a test bug worth surfacing ───────────────────────── */
http_response_code(404);
echo json_encode([
    'code'    => 'not_found',
    'message' => 'mail_stub: unexpected ' . $method . ' ' . $path,
]);
