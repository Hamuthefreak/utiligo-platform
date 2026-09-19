<?php
/**
 * api/support.php — the storage behind the support bubble.
 *
 * POST with a JSON body:
 *   { csrf_token, op: "list" }
 *   { csrf_token, op: "get", ticket_id }
 *   { csrf_token, op: "create", subject, body }
 *   { csrf_token, op: "reply", ticket_id, body }
 *   { csrf_token, op: "close", ticket_id }
 *   { csrf_token, op: "reopen", ticket_id }
 *   { csrf_token, op: "unread" }
 *
 * create and reply ALSO accept multipart/form-data with the same fields plus
 * `files[]`, which is how attachments arrive. Multipart in one request rather than
 * an upload-then-attach dance: a half-attached file on a ticket is a thread neither
 * side can reason about, and it removes the whole class of orphaned "pending"
 * uploads.
 *
 * Authorization: every ticket read and write is scoped by user_id, and there is no
 * op that takes a ticket id without also matching it against the caller. A ticket
 * is a private conversation.
 *
 * Returns JSON. Errors are { success:false, error:"..." } with a 4xx/5xx.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/support_uploads.php';

header('Content-Type: application/json; charset=utf-8');

/** One JSON failure and out. Keeps the op switch readable. */
function sp_fail(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

api_bootstrap();

if (!is_logged_in()) {
    sp_fail(401, 'not_logged_in');
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    sp_fail(401, 'not_logged_in');
}

$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
$in = $_POST;
if (!$isMultipart) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    $in = is_array($decoded) ? $decoded : $_POST;
}

$csrf = (string)($in['csrf_token'] ?? '');
if (!csrf_verify($csrf)) {
    sp_fail(403, 'invalid_csrf');
}

if (!rate_limit_check('support', defined('RATE_LIMIT_SUPPORT') ? (int)RATE_LIMIT_SUPPORT : 40)) {
    sp_fail(429, 'rate_limited');
}

$op = (string)($in['op'] ?? '');

try {
    $pdo = get_platform_db();
} catch (\Throwable $e) {
    log_error('support_db', $e, ['uid' => $uid]);
    sp_fail(500, 'db_error');
}

/** The caller's own ticket, or null — never a ticket belonging to somebody else. */
$load_ticket = static function (PDO $pdo, int $ticketId, int $uid): ?array {
    if ($ticketId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$ticketId, $uid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

/* ─────────────────────────────────────────────────────────────────────────────
 * Ops
 * ──────────────────────────────────────────────────────────────────────────── */

switch ($op) {

case 'list':
    $stmt = $pdo->prepare(
        'SELECT id, subject, status, unread_user, message_count, last_message_at, created_at
           FROM support_tickets
          WHERE user_id = ?
          ORDER BY last_message_at DESC, id DESC
          LIMIT 100'
    );
    $stmt->execute([$uid]);
    $tickets = array_map('support_ticket_public', $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success'              => true,
        'tickets'              => $tickets,
        'unread'               => array_sum(array_column($tickets, 'unread')),
        'max_subject'          => SUPPORT_MAX_SUBJECT,
        'max_body'             => SUPPORT_MAX_BODY,
        'max_attachments'      => SUPPORT_MAX_ATTACHMENTS,
        // The EFFECTIVE ceiling, so what the bubble prints is what validation
        // enforces — see support_max_attachment_bytes().
        'max_attachment_bytes' => support_max_attachment_bytes(),
        'max_attachment_label' => support_format_bytes(support_max_attachment_bytes()),
    ]);
    exit;

case 'unread':
    echo json_encode(['success' => true, 'unread' => support_unread_user($uid, $pdo)]);
    exit;

case 'get': {
    $ticketId = (int)($in['ticket_id'] ?? 0);
    $ticket   = $load_ticket($pdo, $ticketId, $uid);
    if (!$ticket) {
        sp_fail(404, 'not_found');
    }

    // Reading the thread is what "read" means, so the badge clears here rather than
    // on a separate op a caller could forget to send.
    try {
        $pdo->prepare('UPDATE support_tickets SET unread_user = 0 WHERE id = ? AND user_id = ?')
            ->execute([$ticketId, $uid]);
        $ticket['unread_user'] = 0;
    } catch (\Throwable $e) {
        log_error('support_mark_read', $e, ['uid' => $uid, 'ticket_id' => $ticketId]);
    }

    $msgs = $pdo->prepare('SELECT id, author_type, author_id, body, created_at
                             FROM support_messages WHERE ticket_id = ? ORDER BY id ASC LIMIT 500');
    $msgs->execute([$ticketId]);

    $atts = $pdo->prepare('SELECT * FROM support_attachments WHERE ticket_id = ? ORDER BY id ASC');
    $atts->execute([$ticketId]);
    $byMessage = support_group_attachments($atts->fetchAll(PDO::FETCH_ASSOC));

    $messages = [];
    foreach ($msgs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $messages[] = support_message_public($row, 'user', $byMessage[(int)$row['id']] ?? []);
    }

    echo json_encode([
        'success'  => true,
        'ticket'   => support_ticket_public($ticket),
        'messages' => $messages,
    ]);
    exit;
}

case 'create': {
    $subject = support_subject_clean((string)($in['subject'] ?? ''));
    $body    = support_body_clean((string)($in['body'] ?? ''));

    if (!support_subject_valid($subject)) {
        sp_fail(400, 'invalid_subject');
    }

    $files = support_collect_files();
    $v = support_validate_files($files);
    if (!$v['ok']) {
        sp_fail(400, $v['error']);
    }
    if (!support_body_valid($body) && !$v['files']) {
        sp_fail(400, 'empty_message');
    }

    // Anti-spam backstop, counted in SQL because a race between two tabs is exactly
    // how a cap is beaten.
    $open = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status <> 'closed'");
    $open->execute([$uid]);
    if ((int)$open->fetchColumn() >= SUPPORT_MAX_OPEN_TICKETS) {
        sp_fail(409, 'too_many_open');
    }

    $moved = [];
    $ticketId = 0;
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO support_tickets
               (user_id, subject, status, unread_user, unread_admin, message_count, last_message_at, created_at)
             VALUES (?, ?, ?, 0, 1, 1, NOW(), NOW())'
        )->execute([$uid, $subject, support_status_after_user_message('open')]);
        $ticketId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO support_messages (ticket_id, author_type, author_id, body, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$ticketId, 'user', $uid, $body]);
        $messageId = (int)$pdo->lastInsertId();

        $rows = support_store_files($v['files'], $ticketId, $messageId, $uid, $moved);
        support_insert_attachments($pdo, $rows);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        support_unlink_moved($moved);
        log_error('support_create', $e, ['uid' => $uid]);
        sp_fail(500, 'db_error');
    }

    echo json_encode(['success' => true, 'ticket_id' => $ticketId, 'attachments' => count($moved)]);
    exit;
}

case 'reply': {
    $ticketId = (int)($in['ticket_id'] ?? 0);
    $ticket   = $load_ticket($pdo, $ticketId, $uid);
    if (!$ticket) {
        sp_fail(404, 'not_found');
    }
    if (!support_can_user_reply((string)$ticket['status'])) {
        // Closed to the customer, not deleted: the UI offers Reopen.
        sp_fail(409, 'ticket_closed');
    }

    $body  = support_body_clean((string)($in['body'] ?? ''));
    $files = support_collect_files();
    $v = support_validate_files($files);
    if (!$v['ok']) {
        sp_fail(400, $v['error']);
    }
    if (!support_body_valid($body) && !$v['files']) {
        sp_fail(400, 'empty_message');
    }

    $moved = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO support_messages (ticket_id, author_type, author_id, body, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$ticketId, 'user', $uid, $body]);
        $messageId = (int)$pdo->lastInsertId();

        // A customer message always means support owes an answer, and it clears the
        // customer's own badge because they obviously just read the thread.
        $pdo->prepare(
            'UPDATE support_tickets
                SET status = ?, unread_admin = unread_admin + 1, unread_user = 0,
                    message_count = message_count + 1, last_message_at = NOW(), updated_at = NOW()
              WHERE id = ? AND user_id = ?'
        )->execute([support_status_after_user_message((string)$ticket['status']), $ticketId, $uid]);

        $rows = support_store_files($v['files'], $ticketId, $messageId, $uid, $moved);
        support_insert_attachments($pdo, $rows);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        support_unlink_moved($moved);
        log_error('support_reply', $e, ['uid' => $uid, 'ticket_id' => $ticketId]);
        sp_fail(500, 'db_error');
    }

    echo json_encode(['success' => true, 'ticket_id' => $ticketId, 'attachments' => count($moved)]);
    exit;
}

case 'close':
case 'reopen': {
    $ticketId = (int)($in['ticket_id'] ?? 0);
    $ticket   = $load_ticket($pdo, $ticketId, $uid);
    if (!$ticket) {
        sp_fail(404, 'not_found');
    }
    $status = $op === 'close' ? 'closed' : 'open';
    $pdo->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$status, $ticketId, $uid]);

    echo json_encode(['success' => true, 'ticket_id' => $ticketId, 'status' => $status]);
    exit;
}

default:
    sp_fail(400, 'unknown_op');
}
