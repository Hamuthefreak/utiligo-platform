<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$udb = get_user_db();
$pdb = get_platform_db();

function safe_count(PDO $pdo, string $sql): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

$totalUsers    = safe_count($udb, 'SELECT COUNT(*) FROM utiligo_users');
$proUsers      = safe_count($udb, "SELECT COUNT(*) FROM utiligo_users WHERE plan='pro'");
$entUsers      = safe_count($udb, "SELECT COUNT(*) FROM utiligo_users WHERE plan='entrepreneur'");
$freeUsers     = $totalUsers - $proUsers - $entUsers;
$verifiedUsers = safe_count($udb, 'SELECT COUNT(*) FROM utiligo_users WHERE email_verified=1');
$newToday      = safe_count($udb, "SELECT COUNT(*) FROM utiligo_users WHERE DATE(created_at)=CURDATE()");
$totalSites    = safe_count($pdb, 'SELECT COUNT(*) FROM utiligo_generated_sites');
$activeSites   = safe_count($pdb, 'SELECT COUNT(*) FROM utiligo_generated_sites WHERE link_active=1');

$recent = [];
try {
    $recent = $udb->query(
        'SELECT id,email,full_name,plan,created_at,is_admin FROM utiligo_users ORDER BY id DESC LIMIT 8'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pageTitle = 'Dashboard — Admin — Utiligo';
$adminPage = 'dashboard';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- Page header -->
<div class="mb-8 flex items-center justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5">Welcome back, <?= htmlspecialchars(explode(' ', trim($admin['full_name'] ?? 'Admin'))[0]) ?></p>
    <h1 class="text-3xl font-bold tracking-tight">Admin Dashboard</h1>
  </div>
  <span class="text-xs text-slate-500"><?= date('D, M j Y \a\t g:i A') ?></span>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
  <?php
  $stats = [
    ['Total Users',   $totalUsers,    'fa-users',          'text-white'],
    ['Pro',           $proUsers,      'fa-star',           'text-emerald-400'],
    ['Entrepreneur',  $entUsers,      'fa-rocket',         'text-indigo-400'],
    ['Free',          $freeUsers,     'fa-lock',           'text-slate-400'],
    ['Verified',      $verifiedUsers, 'fa-circle-check',   'text-blue-400'],
    ['New Today',     $newToday,      'fa-user-plus',      'text-yellow-400'],
    ['Total Sites',   $totalSites,    'fa-globe',          'text-purple-400'],
    ['Active Sites',  $activeSites,   'fa-link',           'text-emerald-300'],
  ];
  foreach ($stats as [$label, $val, $icon, $cls]):
  ?>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-white/20 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-white/5 group-hover:bg-white/10 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4">
      <i class="fa-solid <?= $icon ?> text-white text-sm"></i>
    </div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1"><?= $label ?></p>
    <p class="text-4xl font-black <?= $cls ?>"><?= number_format($val) ?></p>
  </div>
  <?php endforeach; ?>
</div>

<!-- Quick actions -->
<div class="flex flex-wrap gap-3 mb-8">
  <a href="/admin/email.php"
     class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-5 py-2.5 rounded-xl font-bold text-sm transition">
    <i class="fa-solid fa-envelope"></i> Send Email Blast
  </a>
  <a href="/admin/users.php"
     class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 text-white px-5 py-2.5 rounded-xl font-semibold text-sm transition">
    <i class="fa-solid fa-users"></i> Manage Users
  </a>
</div>

<!-- Recent signups -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden">
  <div class="flex items-center justify-between px-6 py-4 border-b border-white/5">
    <div class="flex items-center gap-2">
      <i class="fa-solid fa-clock-rotate-left text-slate-400 text-sm"></i>
      <h3 class="font-semibold text-sm">Recent Signups</h3>
    </div>
    <a href="/admin/users.php" class="text-xs text-slate-500 hover:text-white transition">View all &rarr;</a>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="border-b border-white/5 text-slate-500 text-xs uppercase">
        <th class="px-6 py-3 text-left">Name</th>
        <th class="px-6 py-3 text-left">Email</th>
        <th class="px-6 py-3 text-left">Plan</th>
        <th class="px-6 py-3 text-left">Joined</th>
      </tr></thead>
      <tbody class="divide-y divide-white/5">
      <?php if (!$recent): ?>
        <tr><td colspan="4" class="px-6 py-10 text-center text-slate-500">No users yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $u): ?>
      <tr class="hover:bg-white/[0.02] transition">
        <td class="px-6 py-3 font-medium text-white">
          <?= htmlspecialchars($u['full_name']) ?>
          <?php if (!empty($u['is_admin'])): ?>
            <span class="ml-1 text-[10px] bg-purple-500/20 text-purple-300 border border-purple-500/30 px-2 py-0.5 rounded-full font-semibold">ADMIN</span>
          <?php endif; ?>
        </td>
        <td class="px-6 py-3 text-slate-400"><?= htmlspecialchars($u['email']) ?></td>
        <td class="px-6 py-3">
          <?php if ($u['plan']==='entrepreneur'): ?>
            <span class="text-[10px] bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 px-2 py-0.5 rounded-full font-semibold">ENT</span>
          <?php elseif ($u['plan']==='pro'): ?>
            <span class="text-[10px] bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 px-2 py-0.5 rounded-full font-semibold">PRO</span>
          <?php else: ?>
            <span class="text-[10px] bg-white/5 text-slate-400 border border-white/10 px-2 py-0.5 rounded-full font-semibold">FREE</span>
          <?php endif; ?>
        </td>
        <td class="px-6 py-3 text-slate-500"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
