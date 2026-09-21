<?php
/**
 * whop-webhook.php — the endpoint Whop delivers subscription events to.
 *
 * Configure in Whop Dashboard -> Developer -> Webhooks:
 *   Endpoint URL: https://utiligo.ca/whop-webhook.php
 *   Events:       payment.succeeded, membership.deactivated
 *   API version:  v1          (only v1 uses Standard Webhooks signatures)
 *   Secret:       copy it into WHOP_WEBHOOK_SECRET
 *
 * WHAT THIS FILE IS, AND IS NOT
 * ─────────────────────────────
 * It is a door. It opens the raw body, proves the delivery came from Whop, hands
 * the parsed event to includes/whop.php, and answers. It decides nothing about
 * plans itself — no UPDATE, no tier comparison, no customer lookup by hand. Every
 * rule about who gets which plan lives in the entitlement module, which is the
 * only writer, so a change to those rules cannot miss this file.
 *
 * THE ORDER OF THE FIRST THREE STATEMENTS IS THE SECURITY MODEL
 * ─────────────────────────────────────────────────────────────
 *   1. read the raw bytes (never $_POST, never a re-encode — the signature covers
 *      exactly these bytes)
 *   2. verify, and return 401 if it does not match
 *   3. only then decode the JSON
 *
 * Nothing in the payload is read, logged or acted on before step 2 succeeds. An
 * unauthenticated POST therefore cannot reach the database at all, cannot write a
 * ledger row, and cannot spend a lookup — the only work it can cause is one HMAC
 * over the body it sent, and the replay window rejects the cheap cases before
 * that.
 *
 * WHY 401 AND NOT 200 FOR A BAD SIGNATURE
 * ───────────────────────────────────────
 * Whop retries a non-2xx delivery with backoff for about three days. A forged
 * request is not from Whop and retrying it achieves nothing — but a *misconfigured
 * secret* looks identical from here, and the retries plus the "your endpoint is
 * failing" email are exactly how that gets noticed. Answering 200 to something we
 * refused would hide it.
 *
 * WHY 500 FOR A FAILED WRITE, AND 200 FOR EVERYTHING ELSE
 * ──────────────────────────────────────────────────────
 * Whop's retry schedule is the recovery mechanism for a transient database
 * problem, so a failed entitlement write asks for a retry. Everything else —
 * applied, already applied, deliberately ignored, an event type we do not handle —
 * is finished business, and asking Whop to send it again for three days would be
 * noise. includes/whop.php makes that decision and returns the status to use; this
 * file only echoes it.
 *
 * @see includes/whop.php       signature verification, interpretation, ledger
 * @see includes/entitlements.php  the only writer of utiligo_users.plan
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb.php';
require_once __DIR__ . '/includes/whop.php';

header('Content-Type: application/json');
// Nothing here is a page: no cookies, no session, no caching, no framing.
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$raw     = (string)file_get_contents('php://input');
$headers = whop_webhook_headers();

// A body bigger than any Whop webhook is not one; refuse it before hashing it.
if (strlen($raw) > 512 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'body too large']);
    exit;
}

$verify = whop_verify_webhook($raw, $headers);
if (!$verify['ok']) {
    // The reason is logged (it names a header or the clock, never a secret) and
    // NOT returned: a caller who fails verification learns nothing about whether
    // the id, the timestamp or the signature was wrong.
    whop_log('webhook', 'refused a delivery from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ': ' . $verify['reason']);
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'signature verification failed']);
    exit;
}

// Verified. From here the payload is trusted as a statement of what happened, and
// still not trusted as a statement of what should happen to an account: the plan
// is resolved against our own configuration and every write goes through the
// entitlement guards.
$parsed = whop_parse_event($raw);

$result = whop_handle_event($parsed, $headers['id']);

http_response_code((int)$result['http']);
echo json_encode([
    'ok'     => $result['status'] !== 'failed',
    'status' => $result['status'],
    // Deliberately terse. The full reason is in the error log with the account id,
    // which is the same information and is not a public endpoint.
    'id'     => $headers['id'],
], JSON_UNESCAPED_SLASHES);
