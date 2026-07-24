<?php
/**
 * admin/email.php — Email blast composer.
 * Supports: all users, all pro, all free, or a custom list of addresses.
 * Uses the existing send_email() helper from includes/mailer.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/mailer.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$sent = 0;
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify('email_blast', $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'CSRF failure on email blast');
        die('Invalid CSRF token.');
    }

    $subject  = trim(strip_tags($_POST['subject'] ?? ''));
    $bodyHtml = trim($_POST['body_html'] ?? '');
    $audience = $_POST['audience'] ?? 'custom';
    $customRaw= trim($_POST['custom_emails'] ?? '');

    if (!$subject || !$bodyHtml) {
        $errors[] = 'Subject and body are required.';
    } else {
        $udb = get_user_db();
        $recipients = [];

        if ($audience === 'all') {
            $rows = $udb->query('SELECT email, full_name FROM utiligo_users WHERE email_verified=1')->fetchAll(PDO::FETCH_ASSOC);
            $recipients = $rows;
        } elseif ($audience === 'pro') {
            $rows = $udb->query("SELECT email, full_name FROM utiligo_users WHERE plan='pro' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
            $recipients = $rows;
        } elseif ($audience === 'free') {
            $rows = $udb->query("SELECT email, full_name FROM utiligo_users WHERE plan='free' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
            $recipients = $rows;
        } else {
            // Custom email addresses
            $lines = preg_split('/[\r\n,;]+/', $customRaw);
            foreach ($lines as $line) {
                $e = strtolower(trim($line));
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = ['email' => $e, 'full_name' => $e];
                }
            }
        }

        $footer = "<p style=\"font-size:11px;color:#334155;margin:12px 0 0;text-align:center;\">"
                . "<a href=\"https://utiligo.ca/unsubscribe\" style=\"color:#475569;\">Unsubscribe</a>"
                . "</p>";

        _admin_log('INFO', "Email blast started: subject='{$subject}' audience={$audience} recipients=" . count($recipients));

        foreach ($recipients as $r) {
            $html = email_wrapper('Message from Utiligo', "<p>Hi {$r['full_name']},</p>{$bodyHtml}", $footer);
            $ok   = send_email($r['email'], $subject, $html, '', $r['full_name']);
            if ($ok) {
                $sent++;
            } else {
                $errors[] = 'Failed: ' . $r['email'];
            }
            // Small delay to respect Brevo rate limits
            if ($sent % 10 === 0 && $sent > 0) usleep(300000); // 0.3s every 10
        }

        _admin_log('INFO', "Email blast done: sent={$sent} errors=" . count($errors));
        $success = "Blast complete! Sent to {$sent} recipient(s).";
    }
}

$csrf = admin_csrf_token('email_blast');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email Blast — Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body{font-family:'Inter',sans-serif;background:#0A0F1E;color:#E2E8F0;}
  .card{background:rgba(30,41,59,.8);border:1px solid rgba(255,255,255,.07);border-radius:16px;}
  .sidebar-link{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;font-size:.875rem;color:#94A3B8;transition:background .15s,color .15s;}
  .sidebar-link:hover,.sidebar-link.active{background:rgba(16,185,129,.12);color:#10B981;}
  textarea,input[type=text]{background:#1E293B;border:1px solid rgba(255,255,255,.1);color:#E2E8F0;border-radius:12px;padding:12px 16px;width:100%;font-family:'Inter',sans-serif;font-size:.875rem;transition:border-color .2s;}
  textarea:focus,input[type=text]:focus{outline:none;border-color:#10B981;}
  .audience-btn{padding:8px 18px;border-radius:999px;font-size:.8rem;font-weight:600;border:1px solid rgba(255,255,255,.1);cursor:pointer;transition:all .15s;color:#94A3B8;background:transparent;}
  .audience-btn.selected{background:#10B981;color:#0F172A;border-color:#10B981;}
</style>
</head>
<body>
<div class="flex min-h-screen">
  <aside class="w-60 shrink-0 border-r border-white/5 flex flex-col py-6 px-3" style="background:#080D18">
    <div class="px-3 mb-8">
      <span class="text-xl font-black tracking-tight text-white">utili<span class="text-emerald-400">go</span></span>
      <div class="text-xs text-purple-400 font-semibold mt-0.5">Admin Panel</div>
    </div>
    <nav class="flex-1 space-y-1">
      <a href="/admin/index.php" class="sidebar-link">&#127968; Dashboard</a>
      <a href="/admin/users.php" class="sidebar-link">&#128100; Users</a>
      <a href="/admin/email.php" class="sidebar-link active">&#128140; Email Blast</a>
    </nav>
    <div class="px-3 pt-4 border-t border-white/5">
      <div class="text-xs text-slate-500 mb-1">Signed in as</div>
      <div class="text-sm font-medium text-white truncate"><?= htmlspecialchars($admin['email']) ?></div>
      <a href="/logout.php" class="mt-3 block text-xs text-red-400 hover:text-red-300">Sign out</a>
    </div>
  </aside>

  <main class="flex-1 overflow-y-auto">
    <header class="flex items-center px-8 py-5 border-b border-white/5">
      <h1 class="text-xl font-bold">Email Blast</h1>
    </header>
    <div class="p-8 max-w-3xl">

      <?php if ($success): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 px-5 py-4 rounded-xl mb-6 flex items-center gap-3">
          <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>
      <?php foreach ($errors as $e): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 px-5 py-3 rounded-xl mb-3 text-sm"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <form method="POST" class="card p-8 space-y-7" onsubmit="return confirmBlast(this)">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="audience" id="audience_val" value="all">

        <!-- Audience -->
        <div>
          <label class="block text-sm font-semibold text-white mb-3">Audience</label>
          <div class="flex flex-wrap gap-2" id="audience_btns">
            <button type="button" class="audience-btn selected" data-val="all" onclick="setAudience(this)">&#127760; All Users</button>
            <button type="button" class="audience-btn" data-val="pro"  onclick="setAudience(this)">&#11088; Pro Only</button>
            <button type="button" class="audience-btn" data-val="free" onclick="setAudience(this)">&#128274; Free Only</button>
            <button type="button" class="audience-btn" data-val="custom" onclick="setAudience(this)">&#9998; Custom Emails</button>
          </div>
        </div>

        <!-- Custom emails (shown only when custom selected) -->
        <div id="custom_box" class="hidden">
          <label class="block text-sm font-semibold text-white mb-2">Email Addresses <span class="text-slate-400 font-normal">(one per line, or comma-separated)</span></label>
          <textarea name="custom_emails" rows="4" placeholder="john@example.com&#10;jane@example.com"></textarea>
        </div>

        <!-- Subject -->
        <div>
          <label class="block text-sm font-semibold text-white mb-2">Subject Line</label>
          <input type="text" name="subject" placeholder="e.g. 🚀 New features just dropped!" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
        </div>

        <!-- Body -->
        <div>
          <label class="block text-sm font-semibold text-white mb-2">
            Email Body <span class="text-slate-400 font-normal">(HTML supported)</span>
          </label>
          <textarea name="body_html" rows="12" required placeholder="&lt;p&gt;Hi there,&lt;/p&gt;&lt;p&gt;We have exciting news…&lt;/p&gt;"><?= htmlspecialchars($_POST['body_html'] ?? '') ?></textarea>
          <p class="text-xs text-slate-500 mt-1">Wraps automatically in the Utiligo branded email template. The recipient's name will be prefixed automatically.</p>
        </div>

        <!-- Preview toggle -->
        <div>
          <button type="button" onclick="togglePreview()" class="text-sm text-emerald-400 hover:text-emerald-300 underline">Preview HTML</button>
          <div id="preview_box" class="hidden mt-3 bg-slate-900 border border-slate-700 rounded-xl p-4 max-h-64 overflow-y-auto text-xs text-slate-300 font-mono"></div>
        </div>

        <button type="submit"
                class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3.5 rounded-full font-bold text-sm transition">
          &#128140; Send Blast
        </button>
      </form>
    </div>
  </main>
</div>

<script>
function setAudience(btn) {
  document.querySelectorAll('.audience-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('audience_val').value = btn.dataset.val;
  document.getElementById('custom_box').classList.toggle('hidden', btn.dataset.val !== 'custom');
}
function togglePreview() {
  const box  = document.getElementById('preview_box');
  const html = document.querySelector('[name=body_html]').value;
  box.textContent = html;
  box.classList.toggle('hidden');
}
function confirmBlast(form) {
  const aud  = document.getElementById('audience_val').value;
  const subj = form.subject.value;
  return confirm('Send "' + subj + '" to ' + aud + ' users?\n\nThis cannot be undone.');
}
</script>
</body>
</html>
