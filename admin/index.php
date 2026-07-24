<?php
/**
 * admin/index.php — Admin portal dashboard.
 * Access: is_admin = 1 users only (enforced by require_admin()).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$udb = get_user_db();
$pdb = get_platform_db();

$totalUsers   = (int)$udb->query('SELECT COUNT(*) FROM utiligo_users')->fetchColumn();
$proUsers     = (int)$udb->query("SELECT COUNT(*) FROM utiligo_users WHERE plan='pro'")->fetchColumn();
$freeUsers    = $totalUsers - $proUsers;
$verifiedUsers= (int)$udb->query('SELECT COUNT(*) FROM utiligo_users WHERE email_verified=1')->fetchColumn();
$newToday     = (int)$udb->query("SELECT COUNT(*) FROM utiligo_users WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$totalSites   = (int)$pdb->query('SELECT COUNT(*) FROM utiligo_sites')->fetchColumn();

$recent = $udb->query(
    'SELECT id,email,full_name,plan,created_at,is_admin FROM utiligo_users ORDER BY id DESC LIMIT 8'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Utiligo</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body{font-family:'Inter',sans-serif;background:#0A0F1E;color:#E2E8F0;}
  .card{background:rgba(30,41,59,.8);border:1px solid rgba(255,255,255,.07);border-radius:16px;backdrop-filter:blur(12px);}
  .stat-card{transition:transform .2s,box-shadow .2s;}
  .stat-card:hover{transform:translateY(-3px);box-shadow:0 12px 40px rgba(16,185,129,.12);}
  .badge-pro{background:#10B981;color:#0F172A;font-size:.6rem;padding:2px 8px;border-radius:999px;font-weight:700;}
  .badge-free{background:#334155;color:#94A3B8;font-size:.6rem;padding:2px 8px;border-radius:999px;font-weight:700;}
  .badge-admin{background:#8B5CF6;color:#fff;font-size:.6rem;padding:2px 8px;border-radius:999px;font-weight:700;}
  .sidebar-link{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;font-size:.875rem;color:#94A3B8;transition:background .15s,color .15s;}
  .sidebar-link:hover,.sidebar-link.active{background:rgba(16,185,129,.12);color:#10B981;}
  .sidebar-link svg{width:18px;height:18px;}
</style>
</head>
<body>
<div class="flex min-h-screen">

  <!-- Sidebar -->
  <aside class="w-60 shrink-0 border-r border-white/5 flex flex-col py-6 px-3" style="background:#080D18">
    <div class="px-3 mb-8">
      <span class="text-xl font-black tracking-tight text-white">utili<span class="text-emerald-400">go</span></span>
      <div class="text-xs text-purple-400 font-semibold mt-0.5">Admin Panel</div>
    </div>
    <nav class="flex-1 space-y-1">
      <a href="/admin/index.php" class="sidebar-link active">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/></svg>
        Dashboard
      </a>
      <a href="/admin/users.php" class="sidebar-link">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-5-5M9 20H4v-2a4 4 0 015-5m6 0a4 4 0 10-8 0 4 4 0 008 0z"/></svg>
        Users
      </a>
      <a href="/admin/email.php" class="sidebar-link">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        Email Blast
      </a>
    </nav>
    <div class="px-3 pt-4 border-t border-white/5">
      <div class="text-xs text-slate-500 mb-1">Signed in as</div>
      <div class="text-sm font-medium text-white truncate"><?= htmlspecialchars($admin['email']) ?></div>
      <a href="/logout.php" class="mt-3 block text-xs text-red-400 hover:text-red-300">Sign out</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 overflow-y-auto">
    <!-- Top bar -->
    <header class="flex items-center justify-between px-8 py-5 border-b border-white/5">
      <h1 class="text-xl font-bold">Dashboard</h1>
      <div class="flex items-center gap-3">
        <span class="text-xs text-slate-400"><?= date('D, M j Y \a\t g:i A') ?></span>
        <span class="badge-admin">ADMIN</span>
      </div>
    </header>

    <div class="p-8 space-y-8">

      <!-- Stat cards -->
      <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <?php
        $stats = [
          ['Total Users',    $totalUsers,    'text-white',         '&#128100;'],
          ['Pro Users',      $proUsers,      'text-emerald-400',   '&#11088;'],
          ['Free Users',     $freeUsers,     'text-slate-400',     '&#128274;'],
          ['Verified',       $verifiedUsers, 'text-blue-400',      '&#10003;'],
          ['New Today',      $newToday,      'text-yellow-400',    '&#128640;'],
          ['Total Sites',    $totalSites,    'text-purple-400',    '&#127758;'],
        ];
        foreach ($stats as [$label, $val, $cls, $icon]):
        ?>
        <div class="card stat-card p-5 text-center">
          <div class="text-2xl mb-1"><?= $icon ?></div>
          <div class="text-2xl font-black <?= $cls ?>"><?= number_format($val) ?></div>
          <div class="text-xs text-slate-500 mt-1"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Quick actions -->
      <div class="flex flex-wrap gap-3">
        <a href="/admin/email.php" class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-5 py-2.5 rounded-full font-semibold text-sm transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8"/></svg>
          Send Email Blast
        </a>
        <a href="/admin/users.php" class="inline-flex items-center gap-2 bg-slate-700 hover:bg-slate-600 text-white px-5 py-2.5 rounded-full font-semibold text-sm transition">
          Manage Users
        </a>
      </div>

      <!-- Recent signups -->
      <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-white/5">
          <h2 class="font-semibold text-white">Recent Signups</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="border-b border-white/5 text-slate-400 text-xs uppercase">
              <th class="px-6 py-3 text-left">Name</th>
              <th class="px-6 py-3 text-left">Email</th>
              <th class="px-6 py-3 text-left">Plan</th>
              <th class="px-6 py-3 text-left">Joined</th>
            </tr></thead>
            <tbody class="divide-y divide-white/5">
            <?php foreach ($recent as $u): ?>
            <tr class="hover:bg-white/2 transition">
              <td class="px-6 py-3 font-medium text-white">
                <?= htmlspecialchars($u['full_name']) ?>
                <?php if ($u['is_admin']): ?><span class="badge-admin ml-1">ADMIN</span><?php endif; ?>
              </td>
              <td class="px-6 py-3 text-slate-400"><?= htmlspecialchars($u['email']) ?></td>
              <td class="px-6 py-3">
                <?php if ($u['plan']==='pro'): ?>
                  <span class="badge-pro">PRO</span>
                <?php else: ?>
                  <span class="badge-free">FREE</span>
                <?php endif; ?>
              </td>
              <td class="px-6 py-3 text-slate-500"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>
</div>
</body>
</html>
