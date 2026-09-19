<?php
/**
 * api/lead-enrichments.php — Phase 2 reader for the lead_enrichments table.
 *
 * Returns the full lead row + every enrichment row lifted into a flat
 * shape the slide-over panel renders directly. Inputs:
 *   POST lead_id (int)   required
 *   POST csrf_token      required
 *
 * IDOR: this endpoint USED to answer to login + CSRF alone, on the reasoning that
 * enrichments are public-side data (a website scrape, a public email regex) and
 * that a shared pool has no per-user ownership to enforce. That reasoning was
 * wrong about the row it actually returns: `SELECT * FROM utiligo_leads` carries
 * business_phone and business_email — exactly the fields the free tier masks and
 * Pro customers pay to unlock. Anyone with a session could read the whole contact
 * database one sequential id at a time, and the paywall meant nothing.
 *
 * Two gates now, matching api/lead-outreach.php:
 *   - the lead workspace plan (can_use_lead_workspace), so the feature is not
 *     available on a plan that cannot open the page it belongs to; and
 *   - lead_visible_to(), which asks the grant table whether a search ever
 *     delivered THIS lead to THIS account.
 * CSRF is still required as well, so a malicious third-party site can't probe for
 * lead existence by issuing cross-site POSTs.
 *
 * Response shape:
 *   {
 *     "success": true,
 *     "lead": { /* full utiligo_leads row * },
 *     "enrichments": [
 *       { "provider":"website_finder", "field":"website", "value":"https://…", "confidence":"high", "found_at":"2026-…" },
 *       ...
 *     ]
 *   }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$csrf = (string)($in['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

$lead_id = (int)($in['lead_id'] ?? 0);
if ($lead_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_lead_id']);
    exit;
}

$account = current_user();
if (!$account) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

if (!can_use_lead_workspace((string)($account['plan'] ?? 'free'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'plan_required']);
    exit;
}

$pdo = get_platform_db();

// Is this a lead this account was actually delivered? See the ownership note at
// the top of this file — without this check the response is the paid contact
// record for any id the caller cares to guess.
if (!lead_visible_to($pdo, (int)$account['id'], $lead_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'lead_not_unlocked']);
    exit;
}

// Fetch the lead itself. The slide-over can re-render from this single row
// (with raw_payload un-serialized for the JS). We never expose user_id or
// any other ownership information — there isn't any on utiligo_leads.
try {
    $stmt = $pdo->prepare('SELECT * FROM utiligo_leads WHERE id = ? LIMIT 1');
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
    exit;
}

if (!$lead) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

// Decode raw_payload if present. We never expose the raw blob to JS; we
// surface easy-to-render sub-fields instead.
unset($lead['raw_payload']);

// Fetch enrichments. Denormalized columns (business_email, website) are
// already part of $lead — the enrichments rows add the *extras* (additional
// emails, DNS status, social profiles, …) and a per-provider audit trail.
try {
    $stmt = $pdo->prepare(
        'SELECT provider, field, value, confidence, found_at
           FROM lead_enrichments
          WHERE lead_id = ?
          ORDER BY FIELD(confidence,"high","medium","low"), provider, field'
    );
    $stmt->execute([$lead_id]);
    $rich = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Schema might be missing — degrade to no enrichments.
    $rich = [];
}

echo json_encode([
    'success'      => true,
    'lead'         => $lead,
    'enrichments'  => $rich,
]);
