<?php
/**
 * cron/crm-reminders.php — Daily digest of CRM tasks with an enabled
 * email reminder that are due in the next 24 hours (overdue included).
 *
 * Schedule via cPanel / cron: once per day at 8am server time, e.g.:
 *   0 8 * * * curl -s "https://utiligo.ca/cron/crm-reminders.php?secret=YOUR_CRON_SECRET" > /dev/null
 *
 * Gate: must be called with ?secret=<CRON_SECRET> (defined in config.php).
 * Sends one digest email per user that has any eligible tasks.
 * Idempotent enough to re-run safely (no markers), but ideally hit once/day.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: text/plain; charset=utf-8');

// ── Auth gate ─────────────────────────────────────────────────────────────────
$secret = $_GET['secret'] ?? '';
if (!is_string($secret) || !hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    echo "denied\n";
    exit;
}

// Don't let a long-running digest hit PHP max_execution_time mid-user.
@set_time_limit(0);
@ini_set('memory_limit', '256M');

$pdo  = get_platform_db();
$udb  = get_user_db();

$today     = date('Y-m-d');
$tomorrow  = date('Y-m-d', strtotime('+1 day'));

// ── Find tasks where remind_email=1, not done, due in [today, tomorrow] ───────
$sql = "
    SELECT t.id, t.user_id, t.title, t.due_date, t.priority, t.client_id,
           c.name AS client_name
    FROM crm_tasks t
    LEFT JOIN crm_clients c ON c.id = t.client_id
    WHERE t.remind_email = 1
      AND t.done = 0
      AND t.due_date IS NOT NULL
      AND t.due_date <= ?
    ORDER BY t.user_id, t.due_date ASC
";

try {
    $st = $pdo->prepare($sql);
    $st->execute([$tomorrow]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[cron/crm-reminders] ' . $e->getMessage());
    echo "db_error: " . $e->getMessage() . "\n";
    exit;
}

if (empty($rows)) {
    echo "no_reminders_due\n";
    exit;
}

// Group by user_id
$byUser = [];
foreach ($rows as $r) {
    $byUser[(int)$r['user_id']][] = $r;
}

// ── Load user emails for each user_id (cross-DB lookup) ───────────────────────
$userIds = array_keys($byUser);
$userMeta = [];
if ($userIds) {
    try {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $uq = $udb->prepare("SELECT id, full_name, email, plan FROM utiligo_users WHERE id IN ($placeholders)");
        $uq->execute($userIds);
        foreach ($uq->fetchAll(PDO::FETCH_ASSOC) as $ur) {
            $userMeta[(int)$ur['id']] = $ur;
        }
    } catch (\Throwable $e) {
        error_log('[cron/crm-reminders users] ' . $e->getMessage());
    }
}

$sent = 0; $skipped = 0;
foreach ($byUser as $userId => $tasks) {
    if (!isset($userMeta[$userId])) { $skipped++; continue; }
    $u = $userMeta[$userId];
    if (empty($u['email'])) { $skipped++; continue; }

    // Build task list HTML
    $items = '';
    $pri_colors = ['high' => '#ef4444', 'medium' => '#f59e0b', 'low' => '#64748b'];
    foreach ($tasks as $t) {
        $pri   = htmlspecialchars($t['priority']);
        $pcol  = $pri_colors[$t['priority']] ?? '#64748b';
        $due   = !empty($t['due_date']) ? date('M j, Y', strtotime($t['due_date'])) : '';
        $overdue = !empty($t['due_date']) && $t['due_date'] < $today;
        $title = htmlspecialchars($t['title']);
        $cn    = !empty($t['client_name']) ? htmlspecialchars($t['client_name']) : 'General';

        $items .= '<div style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);">'
                . '<div style="display:flex;align-items:center;gap:8px;">'
                . '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' . $pcol . '"></span>'
                . '<span style="font-size:14px;font-weight:600;color:#f1f5f9;">' . $title . '</span>'
                . '<span style="margin-left:auto;font-size:11px;font-weight:700;color:' . $pcol . ';text-transform:uppercase;">' . $pri . '</span>'
                . '</div>'
                . '<p style="margin:4px 0 0 14px;font-size:12px;color:#94a3b8;">'
                . $cn . ' &middot; '
                . '<span style="color:' . ($overdue ? '#f87171' : '#64748b') . '">'
                . ($overdue ? 'Overdue — ' : '') . $due . '</span>'
                . '</p>'
                . '</div>';
    }

    $count   = count($tasks);
    $preheader = "You have {$count} CRM task" . ($count !== 1 ? 's' : '') . " due in the next 24 hours.";
    $heading   = $count === 1 ? '1 task needs your attention' : "{$count} tasks need your attention";
    $body     = '<h2 style="margin:0 0 16px;color:#F1F5F9;font-family:Inter,Arial,sans-serif;font-size:22px;font-weight:800;letter-spacing:-0.3px;">' . $heading . '</h2>'
              . '<p style="margin:0 0 16px;color:#CBD5E1;font-family:Inter,Arial,sans-serif;font-size:15px;line-height:1.7;">Hi ' . htmlspecialchars(explode(' ', trim($u['full_name'] ?? ''))[0] ?: 'there') . ', here\'s what\'s coming up in your Utiligo CRM:</p>'
              . $items
              . '<div style="text-align:center;margin:24px 0 0;">'
              . '<a href="' . rtrim(APP_BASE_URL, '/') . '/portal/crm.php" style="display:inline-block;padding:14px 36px;background:#10B981;border-radius:100px;color:#0F172A;font-family:Inter,Arial,sans-serif;font-size:15px;font-weight:700;text-decoration:none;">Open CRM</a>'
              . '</div>'
              . '<p style="margin:20px 0 0;font-size:12px;color:#475569;text-align:center;">Manage reminders on the Tasks tab in your CRM.</p>';

    // Compose + send via the existing mailer (Brevo API + email_wrapper)
    $html = email_wrapper($preheader, $body);
    $subject = $count === 1
        ? 'Reminder: 1 CRM task due soon'
        : "Reminder: {$count} CRM tasks due soon";

    $ok = send_email($u['email'], $subject, $html, '', $u['full_name'] ?? '');
    if ($ok) { $sent++; } else { $skipped++; }
}

echo "users_reminded={$sent} skipped={$skipped}\n";
