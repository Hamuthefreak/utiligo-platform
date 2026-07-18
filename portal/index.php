<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user   = current_user();
$is_pro = ($user['plan'] ?? 'free') === 'pro';
$pdo    = get_platform_db();

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ?');
$stmt->execute([$user['id']]); $leadCount = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_generated_sites WHERE user_id = ? AND status = "completed"');
$stmt->execute([$user['id']]); $sitesCount = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM utiligo_revenue_tracking WHERE user_id = ?');
$stmt->execute([$user['id']]); $totalRevenue = (float)$stmt->fetch()['total'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ? AND l.status = "won"');
$stmt->execute([$user['id']]); $dealsWon = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT l.business_name, l.status, l.created_at FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ? ORDER BY l.created_at DESC LIMIT 6');
$stmt->execute([$user['id']]); $recentActivity = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id FROM utiligo_whitelabel WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]); $hasWhiteLabel = (bool)$stmt->fetch();

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = htmlspecialchars(explode(' ', trim($user['full_name'] ?: 'there'))[0]);

$pageTitle = 'Dashboard — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';

function statusDot(string $s): string {
    return match($s) { 'won'=>'bg-emerald-400','pitched'=>'bg-blue-400','contacted'=>'bg-amber-400','lost'=>'bg-red-400','archived'=>'bg-slate-500',default=>'bg-slate-400' };
}
function statusLabel(string $s): string {
    return match($s) { 'won'=>'Won','pitched'=>'Pitched','contacted'=>'Contacted','lost'=>'Lost','archived'=>'Archived',default=>'New' };
}
?>

<!-- Greeting -->
<div class="mb-8 flex items-center justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5"><?= $greeting ?>, <?= $firstName ?> 👋</p>
    <h1 class="text-3xl font-bold tracking-tight">Your Dashboard</h1>
  </div>
  <?php if (!$is_pro): ?>
  <a href="/portal/billing.php?upgrade=1"
     class="flex items-center gap-2 bg-gradient-to-r from-emerald-500 to-teal-400 text-slate-950 px-5 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/40 hover:scale-105 transition-all">
    <i class="fa-solid fa-crown"></i> Upgrade to Pro
  </a>
  <?php else: ?>
  <div class="flex items-center gap-2 bg-emerald-500/10 border border-emerald-500/20 rounded-full px-4 py-2 text-sm">
    <i class="fa-solid fa-crown text-emerald-400"></i>
    <span class="text-emerald-300 font-semibold">Pro Plan Active</span>
  </div>
  <?php endif; ?>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-emerald-500/30 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-emerald-500/8 group-hover:bg-emerald-500/15 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-emerald-500/15 flex items-center justify-center mb-4"><i class="fa-solid fa-users text-emerald-400 text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Leads Found</p>
    <p class="text-4xl font-black"><?= $leadCount ?></p>
    <a href="/portal/leads.php" class="text-xs text-emerald-400 hover:text-emerald-300 mt-2 inline-block">Find more &rarr;</a>
  </div>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-blue-500/30 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-blue-500/8 group-hover:bg-blue-500/15 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-blue-500/15 flex items-center justify-center mb-4"><i class="fa-solid fa-globe text-blue-400 text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Sites Built</p>
    <p class="text-4xl font-black"><?= $sitesCount ?></p>
    <a href="/portal/my_sites.php" class="text-xs text-blue-400 hover:text-blue-300 mt-2 inline-block">View sites &rarr;</a>
  </div>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-amber-500/30 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-amber-500/8 group-hover:bg-amber-500/15 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-amber-500/15 flex items-center justify-center mb-4"><i class="fa-solid fa-handshake text-amber-400 text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Deals Won</p>
    <p class="text-4xl font-black"><?= $dealsWon ?></p>
    <span class="text-xs text-slate-500 mt-2 inline-block">Keep pushing!</span>
  </div>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-violet-500/30 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-violet-500/8 group-hover:bg-violet-500/15 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-violet-500/15 flex items-center justify-center mb-4"><i class="fa-solid fa-dollar-sign text-violet-400 text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Revenue</p>
    <p class="text-4xl font-black text-emerald-400"><?= format_currency($totalRevenue) ?></p>
    <span class="text-xs text-slate-500 mt-2 inline-block">Tracked manually</span>
  </div>
</div>

<!-- Quick actions + Getting Started -->
<div class="grid md:grid-cols-3 gap-6 mb-8">
  <div class="md:col-span-2">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">Quick Actions</p>
    <div class="grid grid-cols-2 gap-3">
      <a href="/portal/leads.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-emerald-500/40 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-emerald-500/15 flex items-center justify-center shrink-0 group-hover:bg-emerald-500/25 transition"><i class="fa-solid fa-magnifying-glass text-emerald-400"></i></div>
        <div><p class="font-semibold text-sm">Find Leads</p><p class="text-slate-500 text-xs">Search any city</p></div>
      </a>
      <a href="/portal/generate.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-blue-500/40 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-blue-500/15 flex items-center justify-center shrink-0 group-hover:bg-blue-500/25 transition"><i class="fa-solid fa-bolt text-blue-400"></i></div>
        <div><p class="font-semibold text-sm">Generate Site</p><p class="text-slate-500 text-xs">60-second build</p></div>
      </a>
      <a href="/portal/my_sites.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-amber-500/40 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-amber-500/15 flex items-center justify-center shrink-0 group-hover:bg-amber-500/25 transition"><i class="fa-solid fa-folder-open text-amber-400"></i></div>
        <div><p class="font-semibold text-sm">My Sites</p><p class="text-slate-500 text-xs">Manage &amp; share</p></div>
      </a>
      <a href="/portal/settings.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-violet-500/40 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-violet-500/15 flex items-center justify-center shrink-0 group-hover:bg-violet-500/25 transition"><i class="fa-solid fa-paintbrush text-violet-400"></i></div>
        <div><p class="font-semibold text-sm">White-Label</p><p class="text-slate-500 text-xs">Customize brand</p></div>
      </a>
    </div>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <div class="flex items-center gap-2 mb-4">
      <div class="w-7 h-7 rounded-lg bg-emerald-500/15 flex items-center justify-center"><i class="fa-solid fa-list-check text-emerald-400 text-xs"></i></div>
      <p class="text-sm font-semibold">Getting Started</p>
    </div>
    <ul class="space-y-3">
      <?php
      $steps = [
          ['done'=>$leadCount>0,  'label'=>'Find your first lead',     'href'=>'/portal/leads.php'],
          ['done'=>$sitesCount>0, 'label'=>'Generate a website',       'href'=>'/portal/generate.php'],
          ['done'=>$hasWhiteLabel,'label'=>'Set up white-label brand', 'href'=>'/portal/settings.php'],
          ['done'=>$dealsWon>0,   'label'=>'Close your first deal',    'href'=>'/portal/leads.php'],
          ['done'=>$is_pro,       'label'=>'Upgrade to Pro',           'href'=>'/portal/billing.php?upgrade=1'],
      ];
      $total_done = count(array_filter($steps, fn($s)=>$s['done']));
      ?>
      <?php foreach($steps as $step): ?>
      <li class="flex items-center gap-2.5">
        <div class="w-5 h-5 rounded-full shrink-0 flex items-center justify-center <?= $step['done']?'bg-emerald-500':'bg-white/5 border border-white/10' ?>">
          <?php if($step['done']): ?><i class="fa-solid fa-check text-slate-950 text-[9px]"></i><?php endif; ?>
        </div>
        <?php if($step['done']): ?>
          <span class="text-xs text-slate-500 line-through"><?= $step['label'] ?></span>
        <?php else: ?>
          <a href="<?= $step['href'] ?>" class="text-xs text-slate-300 hover:text-white transition"><?= $step['label'] ?></a>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <div class="mt-4 pt-4 border-t border-white/5">
      <div class="flex justify-between text-xs text-slate-500 mb-1.5"><span>Progress</span><span><?= $total_done ?>/<?= count($steps) ?></span></div>
      <div class="w-full bg-white/5 rounded-full h-1.5">
        <div class="h-1.5 rounded-full bg-emerald-500 transition-all" style="width:<?= round($total_done/count($steps)*100) ?>%"></div>
      </div>
    </div>
  </div>
</div>

<!-- Activity feed -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden">
  <div class="flex items-center justify-between px-6 py-4 border-b border-white/5">
    <div class="flex items-center gap-2"><i class="fa-solid fa-clock-rotate-left text-slate-400 text-sm"></i><h3 class="font-semibold text-sm">Recent Activity</h3></div>
    <a href="/portal/leads.php" class="text-xs text-slate-500 hover:text-emerald-400 transition">View all &rarr;</a>
  </div>
  <?php if (!$recentActivity): ?>
  <div class="px-6 py-12 text-center">
    <div class="w-14 h-14 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-4"><i class="fa-solid fa-inbox text-slate-500 text-xl"></i></div>
    <p class="text-slate-400 font-medium">No activity yet</p>
    <p class="text-slate-600 text-sm mt-1">Start by finding your first leads.</p>
    <a href="/portal/leads.php" class="inline-flex items-center gap-2 mt-4 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-5 py-2 rounded-xl text-sm font-bold">
      <i class="fa-solid fa-magnifying-glass"></i> Find First Leads
    </a>
  </div>
  <?php else: ?>
  <ul class="divide-y divide-white/5">
    <?php foreach ($recentActivity as $a): ?>
    <li class="flex items-center gap-4 px-6 py-3.5 hover:bg-white/2 transition">
      <div class="w-2 h-2 rounded-full <?= statusDot($a['status']) ?> shrink-0"></div>
      <div class="flex-1 min-w-0"><p class="font-medium text-sm truncate"><?= htmlspecialchars($a['business_name']) ?></p></div>
      <div class="flex items-center gap-3 shrink-0">
        <span class="text-xs px-2 py-0.5 rounded-full bg-white/5 text-slate-400"><?= statusLabel($a['status']) ?></span>
        <span class="text-xs text-slate-600"><?= date('M j', strtotime($a['created_at'])) ?></span>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
