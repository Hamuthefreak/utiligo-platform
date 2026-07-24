<?php
/**
 * admin/users.php — User management (search, plan/status edit, ban).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$udb    = get_user_db();
$action = $_POST['action'] ?? '';

// ── Handle POST actions ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify('users', $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'CSRF failure on users action');
        die('Invalid CSRF token.');
    }
    $targetId = (int)($_POST['user_id'] ?? 0);
    // Prevent admin from demoting/banning themselves
    if ($targetId === (int)$admin['id'] && in_array($action, ['ban','demote'])) {
        $error = 'You cannot ban or demote your own account.';
    } elseif ($action === 'set_plan' && $targetId > 0) {
        $plan = in_array($_POST['plan'] ?? '', ['free','pro']) ? $_POST['plan'] : 'free';
        $udb->prepare('UPDATE utiligo_users SET plan=? WHERE id=?')->execute([$plan, $targetId]);
        _admin_log('INFO', "Set plan={$plan} for user_id={$targetId}");
        $success = 'Plan updated.';
    } elseif ($action === 'ban' && $targetId > 0) {
        $udb->prepare("UPDATE utiligo_users SET subscription_status='banned' WHERE id=?")->execute([$targetId]);
        _admin_log('WARN', "Banned user_id={$targetId}");
        $success = 'User banned.';
    } elseif ($action === 'unban' && $targetId > 0) {
        $udb->prepare("UPDATE utiligo_users SET subscription_status='none' WHERE id=?")->execute([$targetId]);
        _admin_log('INFO', "Unbanned user_id={$targetId}");
        $success = 'User unbanned.';
    } elseif ($action === 'promote_admin' && $targetId > 0) {
        $udb->prepare('UPDATE utiligo_users SET is_admin=1 WHERE id=?')->execute([$targetId]);
        _admin_log('WARN', "Promoted user_id={$targetId} to admin");
        $success = 'User promoted to admin.';
    } elseif ($action === 'demote' && $targetId > 0) {
        $udb->prepare('UPDATE utiligo_users SET is_admin=0 WHERE id=?')->execute([$targetId]);
        _admin_log('WARN', "Demoted admin user_id={$targetId}");
        $success = 'Admin rights removed.';
    }
}

// ── Fetch users ───────────────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$data    = admin_get_all_users($page, $perPage, $search);
$users   = $data['users'];
$total   = $data['total'];
$pages   = max(1, (int)ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Users — Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body{font-family:'Inter',sans-serif;background:#0A0F1E;color:#E2E8F0;}
  .card{background:rgba(30,41,59,.8);border:1px solid rgba(255,255,255,.07);border-radius:16px;}
  .badge-pro{background:#10B981;color:#0F172A;font-size:.6rem;padding:2px 8px;border-radius:999px;font-weight:700;}
  .badge-free{background:#334155;color:#94A3B8;font-size:.6rem;padding:2px 8px;border-radius:999px;font-weight:700;}
  .badge-admin{background:#8B5CF6;color:#fff;font-size:.6rem;padding:2px 8px;border-radius:999px;font-weight:700;}
  .badge-banned{background:#EF4444;color:#fff;font-size:.6rem;padding:2px 8px;border-radius:999px;font-weight:700;}
  .sidebar-link{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;font-size:.875rem;color:#94A3B8;transition:background .15s,color .15s;}
  .sidebar-link:hover,.sidebar-link.active{background:rgba(16,185,129,.12);color:#10B981;}
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
      <a href="/admin/users.php" class="sidebar-link active">&#128100; Users</a>
      <a href="/admin/email.php" class="sidebar-link">&#128140; Email Blast</a>
    </nav>
    <div class="px-3 pt-4 border-t border-white/5">
      <div class="text-xs text-slate-500 mb-1">Signed in as</div>
      <div class="text-sm font-medium text-white truncate"><?= htmlspecialchars($admin['email']) ?></div>
      <a href="/logout.php" class="mt-3 block text-xs text-red-400 hover:text-red-300">Sign out</a>
    </div>
  </aside>

  <main class="flex-1 overflow-y-auto">
    <header class="flex items-center justify-between px-8 py-5 border-b border-white/5">
      <h1 class="text-xl font-bold">Users <span class="text-slate-500 text-sm font-normal">(<?= number_format($total) ?>)</span></h1>
    </header>
    <div class="p-8 space-y-6">

      <?php if (!empty($success)): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 px-4 py-3 rounded-xl text-sm"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl text-sm"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- Search -->
      <form method="GET" class="flex gap-3">
        <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email…"
               class="flex-1 bg-slate-800 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-500">
        <button class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-xl font-semibold text-sm">Search</button>
        <?php if ($search): ?>
          <a href="/admin/users.php" class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2.5 rounded-xl text-sm flex items-center">Clear</a>
        <?php endif; ?>
      </form>

      <!-- Table -->
      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="border-b border-white/5 text-slate-400 text-xs uppercase">
              <th class="px-5 py-3 text-left">User</th>
              <th class="px-5 py-3 text-left">Email</th>
              <th class="px-5 py-3 text-left">Plan</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Joined</th>
              <th class="px-5 py-3 text-left">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-white/5">
            <?php foreach ($users as $u): ?>
            <tr class="hover:bg-white/[.02] transition">
              <td class="px-5 py-3 font-medium text-white">
                <?= htmlspecialchars($u['full_name']) ?>
                <?php if ($u['is_admin']): ?><span class="badge-admin ml-1">ADMIN</span><?php endif; ?>
              </td>
              <td class="px-5 py-3 text-slate-400"><?= htmlspecialchars($u['email']) ?></td>
              <td class="px-5 py-3">
                <?= $u['plan']==='pro' ? '<span class="badge-pro">PRO</span>' : '<span class="badge-free">FREE</span>' ?>
              </td>
              <td class="px-5 py-3">
                <?php if ($u['subscription_status']==='banned'): ?>
                  <span class="badge-banned">BANNED</span>
                <?php else: ?>
                  <span class="text-slate-400 text-xs"><?= htmlspecialchars($u['subscription_status']) ?></span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3 text-slate-500"><?= date('M j Y', strtotime($u['created_at'])) ?></td>
              <td class="px-5 py-3">
                <?php $csrf = admin_csrf_token('users'); ?>
                <form method="POST" class="flex flex-wrap gap-1">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <!-- Toggle plan -->
                  <?php if ($u['plan']!=='pro'): ?>
                    <input type="hidden" name="plan" value="pro">
                    <button name="action" value="set_plan" class="bg-emerald-600/20 hover:bg-emerald-600/40 text-emerald-400 border border-emerald-600/30 px-2.5 py-1 rounded-lg text-xs">Upgrade</button>
                  <?php else: ?>
                    <input type="hidden" name="plan" value="free">
                    <button name="action" value="set_plan" class="bg-slate-700 hover:bg-slate-600 text-slate-300 px-2.5 py-1 rounded-lg text-xs">Downgrade</button>
                  <?php endif; ?>
                  <!-- Ban / Unban -->
                  <?php if ($u['subscription_status']==='banned'): ?>
                    <button name="action" value="unban" class="bg-green-600/20 hover:bg-green-600/40 text-green-400 border border-green-600/30 px-2.5 py-1 rounded-lg text-xs">Unban</button>
                  <?php elseif ((int)$u['id'] !== (int)$admin['id']): ?>
                    <button name="action" value="ban" onclick="return confirm('Ban this user?')" class="bg-red-600/20 hover:bg-red-600/40 text-red-400 border border-red-600/30 px-2.5 py-1 rounded-lg text-xs">Ban</button>
                  <?php endif; ?>
                  <!-- Admin toggle -->
                  <?php if (!$u['is_admin']): ?>
                    <button name="action" value="promote_admin" onclick="return confirm('Promote to admin?')" class="bg-purple-600/20 hover:bg-purple-600/40 text-purple-400 border border-purple-600/30 px-2.5 py-1 rounded-lg text-xs">+Admin</button>
                  <?php elseif ((int)$u['id'] !== (int)$admin['id']): ?>
                    <button name="action" value="demote" onclick="return confirm('Remove admin rights?')" class="bg-yellow-600/20 hover:bg-yellow-600/40 text-yellow-400 border border-yellow-600/30 px-2.5 py-1 rounded-lg text-xs">Demote</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <?php if ($pages > 1): ?>
      <div class="flex gap-2">
        <?php for ($i=1;$i<=$pages;$i++): ?>
        <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>"
           class="w-9 h-9 flex items-center justify-center rounded-lg text-sm <?= $i===$page ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-slate-800 text-slate-400 hover:bg-slate-700' ?>">
          <?= $i ?>
        </a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>

    </div>
  </main>
</div>
</body>
</html>
