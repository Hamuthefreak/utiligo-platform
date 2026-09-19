<?php
/**
 * api/call-scripts.php — the storage behind the floating call dock.
 *
 * POST with a JSON body:
 *   { csrf_token, op: "list" }
 *   { csrf_token, op: "create", name, body }
 *   { csrf_token, op: "update", id, name, body }
 *   { csrf_token, op: "delete", id }
 *   { csrf_token, op: "reorder", ids: [ ...in the new order... ] }
 *   { csrf_token, op: "touch", id }          // "I used this one"
 *   { csrf_token, op: "import", blob }       // several at once, split on ---
 *
 * Plan gate: Pro ONLY, via can_use_call_scripts(). Not plan_has_pro_features():
 * this is the one feature Entrepreneur is deliberately locked out of. Free and
 * Entrepreneur accounts are both refused with `plan_required`, and neither is
 * even sent the dock, so this is the backstop rather than the first line.
 *
 * Authorization: every read and every write is scoped by user_id. There is no
 * op that takes an id without also matching it against the caller, because a
 * script is a customer's own words about their own business.
 *
 * `op:list` is also the seeding point. A dock that opens onto an empty box and
 * asks the customer to write a cold-call script is the exact experience this
 * feature exists to remove, so the first list response creates the three starter
 * scripts — once ever, recorded on the account (utiligo_users
 * .call_script_seeded_at), so deleting them does not bring them back.
 *
 * That marker is deliberately NOT a row in lead_activity_log, where it started:
 * that log is documented as best-effort, and a correctness rule cannot rest on a
 * write the code is told it may drop.
 *
 * Returns JSON. Errors are { success:false, error:"..." } with a 4xx/5xx.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/lead_activity_log.php';
require_once __DIR__ . '/../includes/call_scripts.php';

header('Content-Type: application/json; charset=utf-8');

/** One JSON failure and out. Keeps the op switch readable. */
function cs_fail(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

require_login();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$csrf = (string)($in['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    cs_fail(403, 'invalid_csrf');
}

$op  = (string)($in['op'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) cs_fail(401, 'not_logged_in');

// Read the plan from the record, not the session: nothing writes $_SESSION['plan'].
$user = current_user() ?: [];
$plan = (string)($user['plan'] ?? 'free');
if (!can_use_call_scripts($plan)) {
    cs_fail(403, 'plan_required');
}

if (!rate_limit_check('call_scripts', (int)RATE_LIMIT_CALL_SCRIPT)) {
    cs_fail(429, 'rate_limited');
}

$pdo = get_platform_db();

/** Every row this account owns, in the customer's order. */
$load_scripts = static function (PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare('SELECT id, name, body, sort_order, source, times_used, last_used_at, created_at
                             FROM call_scripts WHERE user_id = ?');
    $stmt->execute([$uid]);
    return call_script_order($stmt->fetchAll(PDO::FETCH_ASSOC));
};

/** Insert one script and return its id. */
$insert_script = static function (PDO $pdo, int $uid, string $name, string $body, int $order, string $source): int {
    $stmt = $pdo->prepare('INSERT INTO call_scripts
                             (user_id, name, body, sort_order, source, created_at)
                           VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$uid, $name, $body, $order, $source]);
    return (int)$pdo->lastInsertId();
};

try {
    switch ($op) {

    case 'list':
        $rows = $load_scripts($pdo, $uid);

        // Seed once per account. The marker is checked before the count so an
        // account that already had scripts (or that deleted every starter) is
        // never handed defaults it did not ask for.
        $seeded = false;
        $already = !empty($user['call_script_seeded_at']);

        if (!$already) {
            if (!$rows) {
                $order = CALL_SCRIPT_ORDER_STEP;
                foreach (call_script_starters() as $starter) {
                    $insert_script($pdo, $uid, $starter['name'], $starter['body'], $order, 'starter');
                    $order += CALL_SCRIPT_ORDER_STEP;
                }
                $rows   = $load_scripts($pdo, $uid);
                $seeded = (bool)$rows;
            }
            // Stamped even when nothing was inserted (an account that predates
            // seeding already has scripts), so we never reconsider this account.
            // A failure here is logged rather than swallowed: it is the one write
            // in this file whose loss would repeat a visible action forever.
            try {
                get_user_db()->prepare('UPDATE utiligo_users SET call_script_seeded_at = NOW() WHERE id = ?')
                    ->execute([$uid]);
            } catch (\Throwable $e) {
                log_error('call_scripts_seed_mark', $e, ['uid' => $uid]);
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $script = call_script_public($r);
            // What the panel needs to warn about unfilled slots without asking
            // again: which placeholders this body mentions, and which of those
            // the page may be able to fill in from an open lead.
            $script['tokens'] = call_script_tokens($script['body']);
            $out[] = $script;
        }

        echo json_encode([
            'success'        => true,
            'scripts'        => $out,
            'fields'         => call_script_fields(),
            'cap'            => CALL_SCRIPTS_PER_USER_CAP,
            'seeded'         => $seeded,
            'max_name'       => CALL_SCRIPT_MAX_NAME,
            'max_body'       => CALL_SCRIPT_MAX_BODY,
        ]);
        exit;

    case 'create':
        $v = call_script_validate($in);
        if (!$v['ok']) cs_fail(400, $v['error']);

        $rows = $load_scripts($pdo, $uid);
        if (count($rows) >= CALL_SCRIPTS_PER_USER_CAP) cs_fail(409, 'cap_reached');

        $id = $insert_script($pdo, $uid, $v['name'], $v['body'], call_script_next_order($rows), 'manual');
        try { log_lead_activity($pdo, $uid, LEAD_ACT_CALL_SCRIPT, $id, ['op' => 'create', 'name' => $v['name']]); } catch (\Throwable $e) {}

        echo json_encode(['success' => true, 'id' => $id]);
        exit;

    case 'update':
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) cs_fail(400, 'invalid_id');
        $v = call_script_validate($in);
        if (!$v['ok']) cs_fail(400, $v['error']);

        // Read-then-write, like saved-searches: MySQL reports 0 affected rows for
        // a save that wrote identical values, so the affected count cannot tell
        // "nothing changed" from "no such row" and would answer 404 on a no-op.
        $ro = $pdo->prepare('SELECT id FROM call_scripts WHERE id = ? AND user_id = ? LIMIT 1');
        $ro->execute([$id, $uid]);
        if (!$ro->fetchColumn()) cs_fail(404, 'not_found');

        $stmt = $pdo->prepare('UPDATE call_scripts SET name = ?, body = ?, updated_at = NOW()
                                WHERE id = ? AND user_id = ?');
        $stmt->execute([$v['name'], $v['body'], $id, $uid]);
        try { log_lead_activity($pdo, $uid, LEAD_ACT_CALL_SCRIPT, $id, ['op' => 'update', 'name' => $v['name']]); } catch (\Throwable $e) {}

        echo json_encode(['success' => true, 'id' => $id]);
        exit;

    case 'delete':
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) cs_fail(400, 'invalid_id');

        $stmt = $pdo->prepare('DELETE FROM call_scripts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        if ($stmt->rowCount() === 0) cs_fail(404, 'not_found');
        try { log_lead_activity($pdo, $uid, LEAD_ACT_CALL_SCRIPT, $id, ['op' => 'delete']); } catch (\Throwable $e) {}

        echo json_encode(['success' => true, 'id' => $id]);
        exit;

    case 'reorder':
        $ids = $in['ids'] ?? null;
        if (!is_array($ids)) cs_fail(400, 'invalid_ids');

        // Only ids this account owns are accepted, and the write is a transaction
        // so a half-applied ordering cannot leave two scripts sharing a position.
        $owned = [];
        foreach ($load_scripts($pdo, $uid) as $r) {
            $owned[(int)$r['id']] = true;
        }

        $stmts = $pdo->prepare('UPDATE call_scripts SET sort_order = ? WHERE id = ? AND user_id = ?');
        $order = CALL_SCRIPT_ORDER_STEP;
        $applied = 0;
        $pdo->beginTransaction();
        try {
            foreach ($ids as $rawId) {
                $id = (int)$rawId;
                if ($id <= 0 || !isset($owned[$id])) continue; // not ours: ignored, not written
                $stmts->execute([$order, $id, $uid]);
                $order += CALL_SCRIPT_ORDER_STEP;
                $applied++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            log_error('call_scripts_reorder', $e, ['uid' => $uid]);
            cs_fail(500, 'db_error');
        }

        echo json_encode(['success' => true, 'applied' => $applied]);
        exit;

    case 'touch':
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) cs_fail(400, 'invalid_id');

        // Counter rather than a log row: this fires on every copy, and "which of
        // these is actually working" is answered by the row, not by an archive.
        $stmt = $pdo->prepare('UPDATE call_scripts
                                  SET times_used = times_used + 1, last_used_at = NOW()
                                WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        if ($stmt->rowCount() === 0) cs_fail(404, 'not_found');

        echo json_encode(['success' => true, 'id' => $id]);
        exit;

    case 'import':
        $blob = isset($in['blob']) && is_scalar($in['blob']) ? (string)$in['blob'] : '';
        if (trim($blob) === '') cs_fail(400, 'invalid_blob');

        $parsed = call_script_parse_import($blob);
        if (!$parsed['scripts']) cs_fail(400, 'invalid_format');

        $rows    = $load_scripts($pdo, $uid);
        $room    = CALL_SCRIPTS_PER_USER_CAP - count($rows);
        $order   = call_script_next_order($rows);
        $created = 0;
        $dropped = 0;

        foreach ($parsed['scripts'] as $s) {
            if ($created >= $room) { $dropped++; continue; }
            $insert_script($pdo, $uid, $s['name'], $s['body'], $order, 'import');
            $order += CALL_SCRIPT_ORDER_STEP;
            $created++;
        }

        try { log_lead_activity($pdo, $uid, LEAD_ACT_CALL_SCRIPT, null, [
            'op' => 'import', 'created' => $created, 'skipped_format' => $parsed['skipped'],
        ]); } catch (\Throwable $e) {}

        // `dropped` is reported rather than hidden: a paste that silently lost
        // half its scripts is a paste the customer will trust when they should not.
        echo json_encode([
            'success' => true,
            'created' => $created,
            'skipped' => $parsed['skipped'],
            'dropped' => $dropped,
        ]);
        exit;

    default:
        cs_fail(400, 'unknown_op');
    }
} catch (\Throwable $e) {
    log_error('call_scripts_' . ($op !== '' ? $op : 'unknown'), $e, ['uid' => $uid]);
    cs_fail(500, 'db_error');
}
