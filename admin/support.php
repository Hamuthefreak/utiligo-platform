<?php
/**
 * admin/support.php — the support inbox.
 *
 * Server-rendered like the rest of the admin panel, and it POSTs to itself so a
 * reply is one request and one redirect (POST/redirect/GET), which is what stops a
 * refresh from sending the same reply twice.
 *
 * Two views:
 *   (default)      the queue — every ticket, newest activity first, filterable by
 *                  status and searchable by subject, customer name or customer email
 *   ?view=<id>     one thread, with the reply box and the status control
 *
 * Custom display: the ticket rows live in the PLATFORM database and the accounts in
 * the USER database, so they are fetched together here rather than joined — there is
 * no cross-database join to write.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/support_uploads.php';

require_admin();
$admin = $GLOBALS['admin_user'];
$udb   = get_user_db();
$pdb   = get_platform_db();

/** Account rows for a set of ids, as id => row. Accounts live in the user DB. */
$accounts_for = static function (array $ids) use ($udb): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    // Built from ints only, so nothing caller-supplied reaches the SQL text.
    $in = implode(',', $ids);
    $out = [];
    try {
        $rows = $udb->query("SELECT id, full_name, email, plan FROM utiligo_users WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r;
        }
    } catch (\Throwable $e) {
        log_error('admin_support_accounts', $e);
    }
    return $out;
};

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify('support', $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'CSRF failure on support action');
        die('Invalid CSRF token.');
    }

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $action   = (string)($_POST['action'] ?? '');
    $back     = '/admin/support.php' . ($ticketId > 0 ? '?view=' . $ticketId : '');

    // One ticket row, loaded once and reused by both actions. Support is not
    // scoped to an owner — an operator may open any ticket — so there is no
    // user_id in this lookup, unlike the customer endpoint.
    $ticket = null;
    try {
        $ts = $pdb->prepare('SELECT * FROM support_tickets WHERE id = ? LIMIT 1');
        $ts->execute([$ticketId]);
        $ticket = $ts->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        log_error('admin_support_load', $e, ['ticket_id' => $ticketId]);
    }
    if (!$ticket) {
        header('Location: /admin/support.php?flash_error=' . urlencode('Ticket not found.'));
        exit;
    }

    if ($action === 'set_status') {
        $status = (string)($_POST['status'] ?? '');
        if (!support_status_valid($status)) {
            header('Location: ' . $back . '&flash_error=' . urlencode('Unknown status.'));
            exit;
        }
        $pdb->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, $ticketId]);
        _admin_log('INFO', "Support ticket {$ticketId} set to {$status}");
        header('Location: ' . $back . '&flash=' . urlencode('Ticket marked ' . $status . '.'));
        exit;
    }

    if ($action === 'reply') {
        $body  = support_body_clean((string)($_POST['body'] ?? ''));
        $files = support_collect_files();
        $v     = support_validate_files($files);
        if (!$v['ok']) {
            header('Location: ' . $back . '&flash_error=' . urlencode('Attachment rejected: ' . $v['error']));
            exit;
        }
        if (!support_body_valid($body) && !$v['files']) {
            header('Location: ' . $back . '&flash_error=' . urlencode('Write a reply or attach a file first.'));
            exit;
        }

        $moved = [];
        $pdb->beginTransaction();
        try {
            $pdb->prepare(
                'INSERT INTO support_messages (ticket_id, author_type, author_id, body, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$ticketId, 'admin', (int)$admin['id'], $body]);
            $messageId = (int)$pdb->lastInsertId();

            // An admin reply hands the ball back to the customer, so the ticket
            // becomes 'pending' and the customer's badge goes up by one.
            $pdb->prepare(
                'UPDATE support_tickets
                    SET status = ?, unread_user = unread_user + 1, unread_admin = 0,
                        message_count = message_count + 1, last_message_at = NOW(), updated_at = NOW()
                  WHERE id = ?'
            )->execute([support_status_after_admin_message((string)$ticket['status']), $ticketId]);

            $rows = support_store_files($v['files'], $ticketId, $messageId, (int)$ticket['user_id'], $moved);
            support_insert_attachments($pdb, $rows);

            $pdb->commit();
        } catch (\Throwable $e) {
            if ($pdb->inTransaction()) {
                $pdb->rollBack();
            }
            support_unlink_moved($moved);
            log_error('admin_support_reply', $e, ['ticket_id' => $ticketId]);
            header('Location: ' . $back . '&flash_error=' . urlencode('Could not send that reply.'));
            exit;
        }

        _admin_log('INFO', "Replied to support ticket {$ticketId}");
        header('Location: ' . $back . '&flash=' . urlencode('Reply sent.'));
        exit;
    }

    header('Location: /admin/support.php');
    exit;
}

// ── Flash ─────────────────────────────────────────────────────────────────────
$success = isset($_GET['flash'])       ? htmlspecialchars((string)$_GET['flash'])       : '';
$error   = isset($_GET['flash_error']) ? htmlspecialchars((string)$_GET['flash_error']) : '';

$csrf      = admin_csrf_token('support');
$viewId    = (int)($_GET['view'] ?? 0);
$filterQ   = trim((string)($_GET['q'] ?? ''));
$filterSt  = (string)($_GET['status'] ?? '');

$pageTitle = ($viewId > 0 ? 'Ticket #' . $viewId . ' — ' : 'Support — ') . 'Admin — Utiligo';
$adminPage = 'support';

/* ─────────────────────────────────────────────────────────────────────────────
 * Detail view
 * ──────────────────────────────────────────────────────────────────────────── */
$thread     = null;
$messages   = [];
$customer   = null;

if ($viewId > 0) {
    try {
        $ts = $pdb->prepare(
            'SELECT t.*, (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS msg_rows
               FROM support_tickets t WHERE t.id = ? LIMIT 1'
        );
        $ts->execute([$viewId]);
        $thread = $ts->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        log_error('admin_support_thread', $e, ['ticket_id' => $viewId]);
    }

    if ($thread) {
        // Opening the thread is what "read" means on this side too.
        try {
            $pdb->prepare('UPDATE support_tickets SET unread_admin = 0 WHERE id = ?')->execute([$viewId]);
        } catch (\Throwable $e) {
            log_error('admin_support_mark_read', $e, ['ticket_id' => $viewId]);
        }

        // Resolved BEFORE the messages are built, because each one is attributed by
        // name and the header needs the same account.
        $customer = $accounts_for([(int)$thread['user_id']])[(int)$thread['user_id']] ?? null;
        $threadCustomerName = trim(explode(' ', trim((string)($customer['full_name'] ?? '')))[0]);
        if ($threadCustomerName === '') {
            $threadCustomerName = 'Customer';
        }

        try {
            $ms = $pdb->prepare('SELECT id, author_type, author_id, body, created_at
                                   FROM support_messages WHERE ticket_id = ? ORDER BY id ASC LIMIT 500');
            $ms->execute([$viewId]);
            $atts = $pdb->prepare('SELECT * FROM support_attachments WHERE ticket_id = ? ORDER BY id ASC');
            $atts->execute([$viewId]);
            $byMessage = support_group_attachments($atts->fetchAll(PDO::FETCH_ASSOC));

            foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $messages[] = support_message_public($row, 'admin', $byMessage[(int)$row['id']] ?? []);
            }
        } catch (\Throwable $e) {
            log_error('admin_support_messages', $e, ['ticket_id' => $viewId]);
        }
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Queue
 * ──────────────────────────────────────────────────────────────────────────── */
$tickets = [];
$accounts = [];
if ($viewId <= 0) {
    $where  = [];
    $params = [];

    if (support_status_valid($filterSt)) {
        $where[]  = 'status = ?';
        $params[] = $filterSt;
    }
    if ($filterQ !== '') {
        // The customer's name and email are in the other database, so match them
        // by id: find the accounts first, then include their tickets.
        $ids = [];
        try {
            $us = $udb->prepare('SELECT id FROM utiligo_users WHERE email LIKE ? OR full_name LIKE ? LIMIT 500');
            $us->execute(['%' . $filterQ . '%', '%' . $filterQ . '%']);
            $ids = array_map('intval', $us->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            log_error('admin_support_search_accounts', $e);
        }
        if ($ids) {
            $where[]  = '(subject LIKE ? OR user_id IN (' . implode(',', $ids) . '))';
            $params[] = '%' . $filterQ . '%';
        } else {
            $where[]  = 'subject LIKE ?';
            $params[] = '%' . $filterQ . '%';
        }
    }

    $sql = 'SELECT * FROM support_tickets'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY last_message_at DESC, id DESC LIMIT 200';

    try {
        $stmt = $pdb->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        log_error('admin_support_list', $e);
    }
    $accounts = $accounts_for(array_column($tickets, 'user_id'));
}

$totalOpen   = 0;
$totalUnread = 0;
try {
    $totalOpen   = (int)$pdb->query("SELECT COUNT(*) FROM support_tickets WHERE status <> 'closed'")->fetchColumn();
    $totalUnread = support_unread_admin($pdb);
} catch (\Throwable $e) {
    log_error('admin_support_counts', $e);
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<?php /* Confirmations are neutral chrome; only a refusal gets the accent, so the eye
         is drawn to the one that needs doing something about. */ ?>
<?php if ($success): ?>
  <div class="mb-6 bg-white/[.04] border border-white/10 text-slate-200 px-4 py-3 rounded-lg text-sm"><?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="mb-6 bg-[#f0a83c]/10 border border-[#f0a83c]/30 text-[#f0a83c] px-4 py-3 rounded-lg text-sm"><?= $error ?></div>
<?php endif; ?>

<?php if ($viewId > 0 && $thread): ?>
<?php
  $tstatus = (string)$thread['status'];
  $custName  = $customer['full_name'] ?? 'Unknown account';
  $custEmail = $customer['email'] ?? '';
  $custPlan  = $customer['plan'] ?? 'free';
?>
<div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
  <div>
    <a href="/admin/support.php" class="text-xs text-slate-500 hover:text-slate-300">&larr; All tickets</a>
    <h1 class="text-2xl font-bold tracking-tight mt-1"><?= htmlspecialchars((string)$thread['subject']) ?></h1>
    <p class="text-sm text-slate-400 mt-1">
      #<?= (int)$thread['id'] ?> &middot;
      <?= htmlspecialchars($custName) ?><?= $custEmail !== '' ? ' &lt;' . htmlspecialchars($custEmail) . '&gt;' : '' ?>
      &middot;
      <span class="text-[10px] text-slate-300 border border-white/20 px-2 py-0.5 rounded-[5px] font-semibold uppercase tracking-[.1em]"><?= htmlspecialchars((string)$custPlan) ?></span>
    </p>
  </div>
  <div class="flex items-center gap-2">
    <span class="sp-pill <?= $tstatus === 'open' ? 'is-open' : ($tstatus === 'pending' ? 'is-pending' : '') ?>">
      <?= htmlspecialchars(support_status_label($tstatus)) ?>
    </span>
    <form method="POST" class="flex items-center gap-1.5">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="ticket_id"  value="<?= (int)$thread['id'] ?>">
      <input type="hidden" name="action"     value="set_status">
      <?php /* Value is the stored status; the label is what "awaiting support"
               means to the person reading the queue. */ ?>
      <select name="status" class="bg-white/5 border border-white/10 text-xs rounded-md px-2 py-1.5 text-slate-300">
        <?php foreach (support_statuses() as $s): ?>
          <option value="<?= $s ?>" <?= $s === $tstatus ? 'selected' : '' ?>><?= htmlspecialchars(support_status_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="bg-white/10 hover:bg-white/20 text-white px-3 py-1.5 rounded-md text-xs transition">Set</button>
    </form>
  </div>
</div>

<div class="space-y-4 mb-6 sp-channel">
  <?php foreach ($messages as $m): ?>
  <div class="glass rounded-xl border <?= $m['author_type'] === 'admin' ? 'border-white/20' : 'border-white/10' ?> p-5">
    <div class="flex items-center justify-between mb-2">
      <span class="text-xs font-bold <?= $m['author_type'] === 'admin' ? 'text-slate-200' : 'text-white' ?>">
        <?php /* The customer by name where we have it: in a queue that spans
                 accounts, "Customer" on its own says nothing about who is waiting. */ ?>
        <?= htmlspecialchars(support_author_label((string)$m['author_type'], $threadCustomerName)) ?>
      </span>
      <span class="text-[11px] text-slate-500"><?= $m['created_at'] ? htmlspecialchars(date('M j, Y H:i', strtotime((string)$m['created_at']))) : '' ?></span>
    </div>
    <?php if (trim((string)$m['body']) !== ''): ?>
      <?php /* NOT whitespace-pre-wrap: support_linkify() already turns newlines into
               <br>, and pre-wrap on top of that renders every blank line twice. */ ?>
      <div class="text-sm text-slate-300 leading-relaxed break-words"><?= $m['body_html'] /* escaped inside support_linkify */ ?></div>
    <?php endif; ?>
    <?php if ($m['attachments']): ?>
    <div class="flex flex-wrap gap-3 mt-3">
      <?php foreach ($m['attachments'] as $a): ?>
        <?php if ($a['is_image']): ?>
          <a href="<?= htmlspecialchars($a['url']) ?>" target="_blank" rel="noopener noreferrer" class="block">
            <img src="<?= htmlspecialchars($a['url']) ?>" alt="<?= htmlspecialchars($a['name']) ?>"
                 class="max-h-40 rounded-lg border border-white/10" loading="lazy">
            <span class="block text-[10px] text-slate-500 mt-1 max-w-[160px] truncate"><?= htmlspecialchars($a['name']) ?> &middot; <?= htmlspecialchars($a['size_label']) ?></span>
          </a>
        <?php else: ?>
          <a href="<?= htmlspecialchars($a['url']) ?>" target="_blank" rel="noopener noreferrer"
             class="flex items-center gap-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg px-3 py-2 text-xs text-slate-300 transition">
            <i class="fa-solid fa-paperclip"></i>
            <span class="max-w-[200px] truncate"><?= htmlspecialchars($a['name']) ?></span>
            <span class="text-slate-500"><?= htmlspecialchars($a['size_label']) ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if (support_can_admin_reply($tstatus)): ?>
<form method="POST" enctype="multipart/form-data" action="/admin/support.php?view=<?= (int)$thread['id'] ?>"
      class="glass rounded-xl border border-white/10 p-5 sp-channel">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="ticket_id"  value="<?= (int)$thread['id'] ?>">
  <input type="hidden" name="action"     value="reply">
  <label class="text-xs font-semibold text-slate-400 uppercase tracking-widest">Reply</label>
  <textarea name="body" rows="5" maxlength="<?= SUPPORT_MAX_BODY ?>"
            placeholder="Write a reply… links are clickable automatically."
            class="w-full mt-2 bg-white/5 border border-white/10 rounded-lg px-4 py-3 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-white/40 transition"></textarea>
  <div class="flex items-center justify-between gap-3 mt-3 flex-wrap">
    <div class="text-xs text-slate-500">
      <input type="file" name="files[]" multiple accept="image/*,application/pdf"
             class="text-xs text-slate-400 file:mr-3 file:bg-white/10 file:border-0 file:rounded-lg file:px-3 file:py-1.5 file:text-slate-300 file:text-xs file:cursor-pointer">
      <span class="ml-1">up to <?= SUPPORT_MAX_ATTACHMENTS ?> files, <?= support_format_bytes(support_max_attachment_bytes()) ?> each</span>
    </div>
    <button class="bg-white hover:bg-slate-200 text-black px-6 py-2.5 rounded-lg font-bold text-sm transition">
      <i class="fa-solid fa-paper-plane mr-1"></i> Send reply
    </button>
  </div>
</form>
<?php endif; ?>

<?php elseif ($viewId > 0): ?>
  <div class="glass rounded-2xl border border-white/10 p-12 text-center">
    <p class="text-slate-400">That ticket no longer exists.</p>
    <a href="/admin/support.php" class="inline-block mt-4 text-sm text-slate-300 hover:text-white">Back to support inbox</a>
  </div>

<?php else: ?>
<div class="mb-8 flex items-start justify-between gap-4 flex-wrap">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">Support</h1>
    <p class="text-sm text-slate-500 mt-1"><?= number_format($totalOpen) ?> open &middot; <?= number_format($totalUnread) ?> unread</p>
  </div>
</div>

<form method="GET" class="flex gap-3 mb-6 flex-wrap">        <input name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="Search subject, customer name or email…"
         class="flex-1 min-w-[240px] bg-white/5 border border-white/10 text-white placeholder-slate-500 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-white/40 transition">
  <select name="status" class="bg-white/5 border border-white/10 text-slate-300 rounded-lg px-4 py-2.5 text-sm">
    <option value="">All statuses</option>
    <?php foreach (support_statuses() as $s): ?>
      <option value="<?= $s ?>" <?= $filterSt === $s ? 'selected' : '' ?>><?= htmlspecialchars(support_status_label($s)) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="bg-white hover:bg-slate-200 text-black px-6 py-2.5 rounded-lg font-bold text-sm transition">Filter</button>
  <?php if ($filterQ !== '' || $filterSt !== ''): ?>
    <a href="/admin/support.php" class="bg-white/10 hover:bg-white/20 text-white px-4 py-2.5 rounded-lg text-sm flex items-center transition">Clear</a>
  <?php endif; ?>
</form>

<div class="glass rounded-xl border border-white/5 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="border-b border-white/5 text-slate-500 text-xs uppercase">
        <th class="px-5 py-3 text-left">Subject</th>
        <th class="px-5 py-3 text-left">Customer</th>
        <th class="px-5 py-3 text-left">Status</th>
        <?php /* Dropped on a narrow screen: the queue is scanned by subject, customer
                 and state, and this one column was costing the other three the room
                 they need — the table scrolls, but a cut-off status is worse than a
                 hidden count. */ ?>
        <th class="px-5 py-3 text-left hidden sm:table-cell">Messages</th>
        <?php /* Same rule as the count, applied to the two widest cells left. The
                 table scrolls, but a queue whose timestamp is half-visible reads as a
                 broken page rather than as a wide table, and the timestamp is the
                 least load-bearing thing here: it answers "is this current?", which
                 the unread badge already answers when it matters. */ ?>
        <th class="px-5 py-3 text-left hidden md:table-cell">Last activity</th>
      </tr></thead>
      <tbody class="divide-y divide-white/5">
      <?php if (!$tickets): ?>
        <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">No tickets match.</td></tr>
      <?php endif; ?>
      <?php foreach ($tickets as $t): ?>
      <?php
        $tstatus = (string)$t['status'];
        $acct    = $accounts[(int)$t['user_id']] ?? null;
        $unread  = (int)$t['unread_admin'];
        // The year only when it is not this one: a queue is read by recency, and
        // "Sep 19, 2026 18:26" is three wrapped lines on a narrow screen.
        $tAt    = strtotime((string)$t['last_message_at']);
        $tStamp = date($tAt !== false && date('Y', $tAt) !== date('Y') ? 'M j, Y H:i' : 'M j, H:i',
                        $tAt !== false ? $tAt : time());
      ?>
      <tr class="hover:bg-white/[.02] transition">
        <td class="px-5 py-3 min-w-[180px]">
          <?php /* The badge sits beside the subject rather than after it in the flow:
                   otherwise a wrapped subject drops "1 new" onto its own line. */ ?>
          <div class="flex items-center gap-2">
            <a href="/admin/support.php?view=<?= (int)$t['id'] ?>" class="font-medium text-white hover:text-slate-300 transition">
              <?= htmlspecialchars((string)$t['subject']) ?>
            </a>
            <?php if ($unread > 0): ?>
              <span class="flex-none text-[10px] bg-[#f0a83c] text-[#1c1917] px-2 py-0.5 rounded-[5px] font-bold whitespace-nowrap"><?= $unread ?> new</span>
            <?php endif; ?>
          </div>
        </td>
        <?php /* whitespace-nowrap so an email address cannot break mid-word; the
                 table already scrolls horizontally on a narrow screen. */ ?>
        <td class="px-5 py-3 text-slate-400 whitespace-nowrap">
          <?php if ($acct): ?>
            <?= htmlspecialchars((string)$acct['full_name']) ?>
            <span class="hidden md:block text-[11px] text-slate-600"><?= htmlspecialchars((string)$acct['email']) ?></span>
          <?php else: ?>
            <span class="text-slate-600">account #<?= (int)$t['user_id'] ?></span>
          <?php endif; ?>
        </td>
        <td class="px-5 py-3">
          <span class="sp-pill <?= $tstatus === 'open' ? 'is-open' : ($tstatus === 'pending' ? 'is-pending' : '') ?> whitespace-nowrap">
            <?= htmlspecialchars(support_status_label($tstatus)) ?>
          </span>
        </td>
        <td class="px-5 py-3 text-slate-500 hidden sm:table-cell"><?= (int)$t['message_count'] ?></td>
        <td class="px-5 py-3 text-slate-500 whitespace-nowrap hidden md:table-cell"
            title="<?= htmlspecialchars(date('D, M j, Y H:i', $tAt !== false ? $tAt : time())) ?>">
          <?= htmlspecialchars($tStamp) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
