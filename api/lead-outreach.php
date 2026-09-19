<?php
/**
 * api/lead-outreach.php — the email a customer actually sends.
 *
 * Inputs (JSON body, csrf_token always required):
 *   op = 'draft'         lead_id (int)                  → the draft for that lead
 *   op = 'save_profile'  sender_name, business_name,
 *                        offer, website, phone          → their details, once
 *
 * Output on draft:
 *   {
 *     "success": true,
 *     "draft":  { subject, body, angle, angle_label, evidence[], channel,
 *                 channel_label, send_to, profile_complete, signed },
 *     "mailto": "mailto:…",  "tel": "tel:…",
 *     "profile": { … }
 *   }
 *
 * WHY THE PROFILE LIVES HERE AND NOT ON A SETTINGS PAGE
 * ────────────────────────────────────────────────────
 * A customer does not go looking for a settings page to find out what an email
 * needs. They open a lead, press "Draft email", and discover the draft is signed
 * "—". That is the moment they will fill in what they sell, so that is where the
 * form is, and this op is what it saves through.
 *
 * ENTITLEMENT
 * ───────────
 * Three gates, all of them necessary. require_login() for identity, the lead
 * workspace plan for the feature, and lead_visible_to() for the LEAD — the last
 * one because the lead pool is shared and its rows carry no owner, so
 * "this is a lead I was delivered" is only answerable from the grant table. A
 * draft built from a lead the account is not entitled to is the contact details
 * of that lead with a letter wrapped around them.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/outreach.php';

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

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

$uid  = (int)$user['id'];
$plan = (string)($user['plan'] ?? 'free');

// Same gate the rest of the lead workspace uses, so the feature can never be
// available on a plan that cannot open the page it is part of.
if (!can_use_lead_workspace($plan)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'plan_required']);
    exit;
}

$op = (string)($in['op'] ?? 'draft');

/* ── Save the customer's own details ─────────────────────────────────────── */

if ($op === 'save_profile') {
    $profile = outreach_profile_clean([
        'sender_name'   => $in['sender_name']   ?? '',
        'business_name' => $in['business_name'] ?? '',
        'offer'         => $in['offer']         ?? '',
        'website'       => $in['website']       ?? '',
        'phone'         => $in['phone']         ?? '',
    ]);

    // The sender name has nothing to fall back to if the account has no name, so
    // refuse rather than storing a profile that cannot sign anything.
    if (trim($profile['sender_name']) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'sender_name_required']);
        exit;
    }

    try {
        $stmt = get_user_db()->prepare('UPDATE utiligo_users SET outreach_profile = ? WHERE id = ? LIMIT 1');
        $stmt->execute([json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $uid]);
    } catch (\Throwable $e) {
        // A schema predating migration 026 must not 500 the page the customer is
        // trying to use — say so instead.
        log_error('outreach_profile_save', $e, ['uid' => $uid]);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'profile_not_storable']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'profile' => $profile,
        'complete'=> outreach_profile_complete($profile),
    ]);
    exit;
}

/* ── Draft for one lead ──────────────────────────────────────────────────── */

if ($op !== 'draft') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown_op']);
    exit;
}

$leadId = (int)($in['lead_id'] ?? 0);
if ($leadId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_lead_id']);
    exit;
}

try {
    $pdo = get_platform_db();
} catch (\Throwable $e) {
    log_error('outreach_db', $e, ['uid' => $uid]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
    exit;
}

if (!lead_visible_to($pdo, $uid, $leadId)) {
    // Deliberately not a 404: the UI shows an upgrade prompt for this and nothing
    // useful for "not_found", and either way the response must not reveal whether
    // the id exists.
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'lead_not_unlocked']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM utiligo_leads WHERE id = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    log_error('outreach_lead_read', $e, ['uid' => $uid, 'lead_id' => $leadId]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
    exit;
}

if (!$lead) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

// raw_payload is the provider's original blob. It is not part of the product and
// has no business travelling to a browser, so it is dropped here the same way
// api/lead-enrichments.php drops it.
unset($lead['raw_payload']);

$profile = outreach_profile_from_user($user);
$draft   = outreach_draft($lead, $profile);

echo json_encode([
    'success' => true,
    'draft'   => $draft,
    'mailto'  => outreach_mailto($lead, $draft),
    'tel'     => outreach_tel($lead),
    'profile' => $profile,
]);
