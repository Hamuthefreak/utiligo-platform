<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_pro  = $plan === 'pro';
$is_ent  = $plan === 'entrepreneur';
$is_paid = $is_pro || $is_ent;
$pdo     = get_platform_db();

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ?');
$stmt->execute([$user['id']]); $leadCount = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_generated_sites WHERE user_id = ? AND status = "completed"');
$stmt->execute([$user['id']]); $sitesCount = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_generated_sites WHERE user_id = ? AND link_active = 1');
$stmt->execute([$user['id']]); $activeSites = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM utiligo_revenue_tracking WHERE user_id = ?');
$stmt->execute([$user['id']]); $totalRevenue = (float)$stmt->fetch()['total'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ? AND l.status = "won"');
$stmt->execute([$user['id']]); $dealsWon = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT l.business_name, l.status, l.created_at FROM utiligo_leads l JOIN utiligo_lead_searches s ON l.search_id = s.id WHERE s.user_id = ? ORDER BY l.created_at DESC LIMIT 6');
$stmt->execute([$user['id']]); $recentActivity = $stmt->fetchAll();

$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = htmlspecialchars(explode(' ', trim($user['full_name'] ?: 'there'))[0]);

$site_limit      = plan_site_limit($plan); // 1 free, 200 pro, 500 ent
$lead_limit      = plan_lead_limit($plan);
$site_limit_pct  = ($site_limit > 0) ? min(100, round(($activeSites / $site_limit) * 100)) : 0;
$lead_limit_pct  = ($lead_limit > 0) ? min(100, round(($leadCount / $lead_limit) * 100)) : 0;

$pageTitle = 'Dashboard — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';

function statusDot(string $s): string {
    return match($s) { 'won'=>'bg-white','pitched'=>'bg-slate-300','contacted'=>'bg-amber-400','lost'=>'bg-red-400','archived'=>'bg-slate-500',default=>'bg-slate-400' };
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
  <?php if ($is_ent): ?>
  <div class="flex items-center gap-2 bg-white/8 border border-white/15 rounded-full px-4 py-2 text-sm">
    <i class="fa-solid fa-rocket text-white"></i>
    <span class="text-white font-semibold">Entrepreneur Plan</span>
  </div>
  <?php elseif ($is_pro): ?>
  <div class="flex items-center gap-2 bg-white/8 border border-white/15 rounded-full px-4 py-2 text-sm">
    <i class="fa-solid fa-crown text-white"></i>
    <span class="text-white font-semibold">Pro Plan</span>
  </div>
  <?php else: ?>
  <a href="/portal/billing.php?upgrade=1"
     class="flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-5 py-2.5 rounded-xl font-bold text-sm transition">
    <i class="fa-solid fa-crown"></i> Upgrade Plan
  </a>
  <?php endif; ?>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-white/20 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-white/5 group-hover:bg-white/10 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4"><i class="fa-solid fa-users text-white text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Leads Found</p>
    <p class="text-4xl font-black"><?= $leadCount ?></p>
    <a href="/portal/leads.php" class="text-xs text-slate-400 hover:text-white mt-2 inline-block">Find more &rarr;</a>
  </div>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-white/20 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-white/5 group-hover:bg-white/10 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4"><i class="fa-solid fa-globe text-white text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Sites Built</p>
    <p class="text-4xl font-black"><?= $sitesCount ?></p>
    <a href="/portal/my_sites.php" class="text-xs text-slate-400 hover:text-white mt-2 inline-block">View sites &rarr;</a>
  </div>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-white/20 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-white/5 group-hover:bg-white/10 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4"><i class="fa-solid fa-handshake text-white text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Deals Won</p>
    <p class="text-4xl font-black"><?= $dealsWon ?></p>
    <span class="text-xs text-slate-500 mt-2 inline-block">Keep pushing!</span>
  </div>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-white/20 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-white/5 group-hover:bg-white/10 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4"><i class="fa-solid fa-dollar-sign text-white text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Revenue</p>
    <p class="text-4xl font-black"><?= format_currency($totalRevenue) ?></p>
    <span class="text-xs text-slate-500 mt-2 inline-block">Tracked manually</span>
  </div>
</div>

<?php if ($is_paid): ?>
<!-- Plan usage bars (paid plans only) -->
<div class="glass rounded-2xl p-5 border border-white/5 mb-8">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Plan Usage</p>
  <div class="grid sm:grid-cols-2 gap-5">
    <div>
      <div class="flex justify-between text-xs mb-1.5">
        <span class="text-slate-400">Active websites</span>
        <span class="text-white font-semibold">
          <?= $activeSites ?> / <?= $site_limit === -1 ? '&infin;' : $site_limit ?>
        </span>
      </div>
      <?php if ($site_limit > 0): $sc = min(100, round(($activeSites/$site_limit)*100)); ?>
      <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
        <div class="h-1.5 rounded-full transition-all <?= $sc >= 90 ? 'bg-red-500' : ($sc >= 70 ? 'bg-amber-500' : 'bg-white/60') ?>" style="width:<?= $sc ?>%"></div>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <div class="flex justify-between text-xs mb-1.5">
        <span class="text-slate-400">Leads unlocked</span>
        <span class="text-white font-semibold">
          <?= $leadCount ?> / <?= $lead_limit === -1 ? '&infin;' : $lead_limit ?>
        </span>
      </div>
      <?php if ($lead_limit > 0): $lc = min(100, round(($leadCount/$lead_limit)*100)); ?>
      <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
        <div class="h-1.5 rounded-full transition-all <?= $lc >= 90 ? 'bg-red-500' : ($lc >= 70 ? 'bg-amber-500' : 'bg-white/60') ?>" style="width:<?= $lc ?>%"></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($is_pro): ?>
  <div class="mt-4 pt-4 border-t border-white/5 flex items-center justify-between">
    <p class="text-xs text-slate-500">Need more capacity?</p>
    <a href="/portal/billing.php?upgrade=1&plan=entrepreneur" class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold transition">
      <i class="fa-solid fa-rocket mr-1"></i>Go Entrepreneur
    </a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Quick actions + Getting Started -->
<div class="grid md:grid-cols-3 gap-6 mb-8">
  <div class="md:col-span-2">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">Quick Actions</p>
    <div class="grid grid-cols-2 gap-3">
      <a href="/portal/leads.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-white/20 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center shrink-0 group-hover:bg-white/15 transition"><i class="fa-solid fa-magnifying-glass text-white"></i></div>
        <div><p class="font-semibold text-sm">Find Leads</p><p class="text-slate-500 text-xs">Search any city</p></div>
      </a>
      <a href="/portal/generate.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-white/20 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center shrink-0 group-hover:bg-white/15 transition"><i class="fa-solid fa-bolt text-white"></i></div>
        <div><p class="font-semibold text-sm">Generate Site</p><p class="text-slate-500 text-xs">60-second build</p></div>
      </a>
      <a href="/portal/my_sites.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-white/20 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center shrink-0 group-hover:bg-white/15 transition"><i class="fa-solid fa-folder-open text-white"></i></div>
        <div><p class="font-semibold text-sm">My Sites</p><p class="text-slate-500 text-xs">Manage &amp; share</p></div>
      </a>
      <a href="/portal/billing.php" class="group flex items-center gap-3 glass rounded-2xl px-4 py-4 border border-white/5 hover:border-white/20 hover:-translate-y-0.5 transition-all">
        <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center shrink-0 group-hover:bg-white/15 transition"><i class="fa-solid fa-credit-card text-white"></i></div>
        <div><p class="font-semibold text-sm">Billing</p><p class="text-slate-500 text-xs">Manage plan</p></div>
      </a>
    </div>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <div class="flex items-center gap-2 mb-4">
      <div class="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center"><i class="fa-solid fa-list-check text-white text-xs"></i></div>
      <p class="text-sm font-semibold">Getting Started</p>
    </div>
    <ul class="space-y-3">
      <?php
      $steps = [
          ['done'=>$leadCount>0,  'label'=>'Find your first lead',  'href'=>'/portal/leads.php'],
          ['done'=>$sitesCount>0, 'label'=>'Generate a website',    'href'=>'/portal/generate.php'],
          ['done'=>$dealsWon>0,   'label'=>'Close your first deal', 'href'=>'/portal/leads.php'],
          ['done'=>$is_paid,      'label'=>'Upgrade your plan',     'href'=>'/portal/billing.php?upgrade=1'],
      ];
      $total_done = count(array_filter($steps, fn($s)=>$s['done']));
      ?>
      <?php foreach($steps as $step): ?>
      <li class="flex items-center gap-2.5">
        <div class="w-5 h-5 rounded-full shrink-0 flex items-center justify-center <?= $step['done']?'bg-white':'bg-white/5 border border-white/10' ?>">
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
        <div class="h-1.5 rounded-full bg-white transition-all" style="width:<?= round($total_done/count($steps)*100) ?>%"></div>
      </div>
    </div>
  </div>
</div>

<!-- Activity feed -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden">
  <div class="flex items-center justify-between px-6 py-4 border-b border-white/5">
    <div class="flex items-center gap-2"><i class="fa-solid fa-clock-rotate-left text-slate-400 text-sm"></i><h3 class="font-semibold text-sm">Recent Activity</h3></div>
    <a href="/portal/leads.php" class="text-xs text-slate-500 hover:text-white transition">View all &rarr;</a>
  </div>
  <?php if (!$recentActivity): ?>
  <div class="px-6 py-12 text-center">
    <div class="w-14 h-14 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-4"><i class="fa-solid fa-inbox text-slate-500 text-xl"></i></div>
    <p class="text-slate-400 font-medium">No activity yet</p>
    <p class="text-slate-600 text-sm mt-1">Start by finding your first leads.</p>
    <a href="/portal/leads.php" class="inline-flex items-center gap-2 mt-4 bg-white hover:bg-slate-200 text-black px-5 py-2 rounded-xl text-sm font-bold">
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
