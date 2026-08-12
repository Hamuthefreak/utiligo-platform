<?php
/**
 * api/lead-enrichments.php — Phase 2 reader for the lead_enrichments table.
 *
 * Returns the full lead row + every enrichment row lifted into a flat
 * shape the slide-over panel renders directly. Inputs:
 *   POST lead_id (int)   required
 *   POST csrf_token      required
 *
 * IDOR: lead enrichments are public-side data (website scrape + public
 * email regex). The lead itself is also a row in the shared
 * utiligo_leads pool. There is no per-user ownership to enforce. CSRF
 * is still required so a malicious third-party site can't probe for
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
require_once __DIR__ . '/../includes/functions.php';

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

$pdo = get_platform_db();

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
