<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();
$pdo = get_platform_db();

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ?');
$stmt->execute([$user['id']]);
$leadCount = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_generated_sites WHERE user_id = ? AND status = "completed"');
$stmt->execute([$user['id']]);
$sitesCount = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM utiligo_revenue_tracking WHERE user_id = ?');
$stmt->execute([$user['id']]);
$totalRevenue = (float)$stmt->fetch()['total'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ? AND l.status = "won"');
$stmt->execute([$user['id']]);
$dealsWon = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT l.business_name, l.status, l.created_at FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ? ORDER BY l.created_at DESC LIMIT 5');
$stmt->execute([$user['id']]);
$recentActivity = $stmt->fetchAll();

$pageTitle = 'Dashboard — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto px-6 py-10">
  <div class="flex justify-between items-center mb-8">
    <div>
      <h1 class="text-2xl font-bold">Welcome back, <?= htmlspecialchars(explode(' ', $user['full_name'] ?: 'there')[0]) ?></h1>
      <p class="text-slate-400 text-sm">Here's what's happening with your business.</p>
    </div>
    <span class="text-xs px-3 py-1.5 rounded-full <?= $user['plan'] === 'pro' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/10 text-slate-400' ?>">
      <?= plan_label($user['plan']) ?> Plan
    </span>
  </div>

  <div class="grid md:grid-cols-4 gap-4 mb-10">
    <div class="glass rounded-xl p-5">
      <p class="text-slate-400 text-xs uppercase mb-1">Leads Found</p>
      <p class="text-3xl font-bold"><?= $leadCount ?></p>
    </div>
    <div class="glass rounded-xl p-5">
      <p class="text-slate-400 text-xs uppercase mb-1">Sites Generated</p>
      <p class="text-3xl font-bold"><?= $sitesCount ?></p>
    </div>
    <div class="glass rounded-xl p-5">
      <p class="text-slate-400 text-xs uppercase mb-1">Deals Won</p>
      <p class="text-3xl font-bold"><?= $dealsWon ?></p>
    </div>
    <div class="glass rounded-xl p-5">
      <p class="text-slate-400 text-xs uppercase mb-1">Revenue Tracked</p>
      <p class="text-3xl font-bold text-emerald-400"><?= format_currency($totalRevenue) ?></p>
    </div>
  </div>

  <div class="grid md:grid-cols-4 gap-4 mb-10">
    <a href="/portal/leads.php" class="glass rounded-xl p-6 hover:border-emerald-400/40 transition">
      <i class="fa-solid fa-magnifying-glass text-emerald-400 text-xl mb-3"></i>
      <h3 class="font-semibold">Find Leads</h3>
      <p class="text-slate-400 text-sm">Search a city and industry.</p>
    </a>
    <a href="/portal/generate.php" class="glass rounded-xl p-6 hover:border-emerald-400/40 transition">
      <i class="fa-solid fa-bolt text-emerald-400 text-xl mb-3"></i>
      <h3 class="font-semibold">Generate Website</h3>
      <p class="text-slate-400 text-sm">Build a site in 60 seconds.</p>
    </a>
    <a href="/portal/my_sites.php" class="glass rounded-xl p-6 hover:border-emerald-400/40 transition">
      <i class="fa-solid fa-globe text-emerald-400 text-xl mb-3"></i>
      <h3 class="font-semibold">My Sites</h3>
      <p class="text-slate-400 text-sm">Manage links &amp; expiry.</p>
    </a>
    <a href="/portal/settings.php" class="glass rounded-xl p-6 hover:border-emerald-400/40 transition">
      <i class="fa-solid fa-paintbrush text-emerald-400 text-xl mb-3"></i>
      <h3 class="font-semibold">White-Label</h3>
      <p class="text-slate-400 text-sm">Customize your brand.</p>
    </a>
  </div>

  <div class="glass rounded-xl p-6">
    <h3 class="font-semibold mb-4">Recent Activity</h3>
    <?php if (!$recentActivity): ?>
      <p class="text-slate-500 text-sm">No activity yet. Start by finding your first leads.</p>
    <?php else: ?>
      <ul class="space-y-3 text-sm">
        <?php foreach ($recentActivity as $a): ?>
          <li class="flex justify-between border-b border-white/5 pb-2">
            <span><?= htmlspecialchars($a['business_name']) ?></span>
            <span class="text-slate-500"><?= ucfirst($a['status']) ?> — <?= date('M j', strtotime($a['created_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
