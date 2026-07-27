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
$uid     = (int)$user['id'];
$pdo     = get_platform_db();

// ── Ensure tables exist so counts don't throw ─────────────────────────────
foreach ([
    'CREATE TABLE IF NOT EXISTS `unlocked_leads` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `lead_id` INT UNSIGNED NOT NULL,
        `unlocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_lead` (`user_id`,`lead_id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'CREATE TABLE IF NOT EXISTS `utiligo_generated_sites` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `link_active` TINYINT(1) NOT NULL DEFAULT 1,
        `status` VARCHAR(30) NOT NULL DEFAULT "completed",
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
] as $_sql) {
    try { $pdo->exec($_sql); } catch (\Throwable $_e) {}
}

// ── Real counts from correct tables ───────────────────────────────────────
$leadCount   = 0;
$sitesCount  = 0;
$activeSites = 0;

try {
    $s = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
    $s->execute([$uid]);
    $leadCount = (int)$s->fetchColumn();
} catch (\Throwable $e) {}

try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id=? AND status="completed"');
    $s->execute([$uid]);
    $sitesCount = (int)$s->fetchColumn();
} catch (\Throwable $e) {}

try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id=? AND link_active=1');
    $s->execute([$uid]);
    $activeSites = (int)$s->fetchColumn();
} catch (\Throwable $e) {}

// Revenue + deals — these tables may not exist; default to 0 gracefully
$totalRevenue = 0.0;
$dealsWon     = 0;
try {
    $s = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM utiligo_revenue_tracking WHERE user_id=?');
    $s->execute([$uid]);
    $totalRevenue = (float)$s->fetchColumn();
} catch (\Throwable $e) {}
try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM utiligo_won_deals WHERE user_id=?');
    $s->execute([$uid]);
    $dealsWon = (int)$s->fetchColumn();
} catch (\Throwable $e) {}

// ── Plan limits ───────────────────────────────────────────────────────────
$site_limit  = plan_site_limit($plan);
$lead_limit  = plan_lead_limit($plan);
$team_seats  = plan_team_seats($plan);

$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = htmlspecialchars(explode(' ', trim($user['full_name'] ?: 'there'))[0]);

$pageTitle = 'Dashboard — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
@keyframes ent-shimmer {
  0%   { background-position: -200% center; }
  100% { background-position:  200% center; }
}
.ent-badge-text {
  background: linear-gradient(90deg, #f59e0b, #fbbf24, #f97316, #f59e0b);
  background-size: 200% auto;
  animation: ent-shimmer 2.5s linear infinite;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
</style>

<!-- Greeting -->
<div class="mb-8 flex items-center justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5"><?= $greeting ?>, <?= $firstName ?> 👋</p>
    <h1 class="text-3xl font-bold tracking-tight">Your Dashboard</h1>
  </div>
  <?php if ($is_ent): ?>
  <div class="flex items-center gap-2 bg-amber-500/10 border border-amber-500/25 rounded-full px-4 py-2 text-sm">
    <i class="fa-solid fa-rocket text-amber-400"></i>
    <span class="ent-badge-text font-bold">Entrepreneur Plan</span>
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

<?php if ($is_ent): ?>
<!-- ===== ENTREPRENEUR FEATURE STRIP ===== -->
<div class="rounded-2xl border border-amber-500/20 mb-8 overflow-hidden" style="background:linear-gradient(135deg,#0d0d14 0%,#15100a 100%)">
  <div class="px-6 py-4 border-b border-white/5 flex items-center gap-3">
    <i class="fa-solid fa-rocket text-amber-400"></i>
    <span class="font-bold text-sm">Your Entrepreneur Features</span>
    <span class="ml-auto text-xs text-amber-500/70"><?= ENT_TEAM_SEATS ?> team seats &bull; <?= ENT_SITE_LIMIT ?> active sites &bull; Unlimited leads</span>
  </div>
  <div class="grid sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-white/5">

    <!-- Custom Domains -->
    <div class="px-6 py-5 flex flex-col gap-3">
      <div class="w-10 h-10 rounded-xl bg-amber-500/15 border border-amber-500/20 flex items-center justify-center">
        <i class="fa-solid fa-globe text-amber-400"></i>
      </div>
      <div>
        <p class="font-semibold text-sm text-white">Custom Domains</p>
        <p class="text-xs text-slate-400 mt-0.5">Point your own domain to any generated site. Unlimited domains included.</p>
      </div>
      <a href="/portal/my_sites.php" class="mt-auto text-xs text-amber-400 hover:text-amber-300 font-semibold transition">
        Manage sites <i class="fa-solid fa-arrow-right text-[10px]"></i>
      </a>
    </div>

    <!-- Client Reports -->
    <div class="px-6 py-5 flex flex-col gap-3">
      <div class="w-10 h-10 rounded-xl bg-amber-500/15 border border-amber-500/20 flex items-center justify-center">
        <i class="fa-solid fa-file-chart-column text-amber-400"></i>
      </div>
      <div>
        <p class="font-semibold text-sm text-white">Client Reports</p>
        <p class="text-xs text-slate-400 mt-0.5">Generate white-label performance reports to send directly to your clients.</p>
      </div>
      <a href="/portal/my_sites.php" class="mt-auto text-xs text-amber-400 hover:text-amber-300 font-semibold transition">
        View sites <i class="fa-solid fa-arrow-right text-[10px]"></i>
      </a>
    </div>

    <!-- Team Seats -->
    <div class="px-6 py-5 flex flex-col gap-3">
      <div class="w-10 h-10 rounded-xl bg-amber-500/15 border border-amber-500/20 flex items-center justify-center">
        <i class="fa-solid fa-users text-amber-400"></i>
      </div>
      <div>
        <p class="font-semibold text-sm text-white">Team Seats</p>
        <p class="text-xs text-slate-400 mt-0.5">Invite up to <?= ENT_TEAM_SEATS ?> team members to collaborate on your Utiligo account.</p>
      </div>
      <a href="/portal/settings.php" class="mt-auto text-xs text-amber-400 hover:text-amber-300 font-semibold transition">
        Manage team <i class="fa-solid fa-arrow-right text-[10px]"></i>
      </a>
    </div>

  </div>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-white/20 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-white/5 group-hover:bg-white/10 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4"><i class="fa-solid fa-users text-white text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Leads Unlocked</p>
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
          <?= $activeSites ?> / <?= ($site_limit <= 0) ? '&infin;' : $site_limit ?>
        </span>
      </div>
      <?php if ($site_limit > 0): $sc = min(100, (int)round(($activeSites / $site_limit) * 100)); ?>
      <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
        <div class="h-1.5 rounded-full transition-all <?= $sc >= 90 ? 'bg-red-500' : ($sc >= 70 ? 'bg-amber-500' : 'bg-white/60') ?>" style="width:<?= $sc ?>%"></div>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <div class="flex justify-between text-xs mb-1.5">
        <span class="text-slate-400">Leads unlocked</span>
        <span class="text-white font-semibold">
          <?= $leadCount ?> / <?= ($lead_limit <= 0) ? '&infin;' : $lead_limit ?>
        </span>
      </div>
      <?php if ($lead_limit > 0): $lc = min(100, (int)round(($leadCount / $lead_limit) * 100)); ?>
      <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
        <div class="h-1.5 rounded-full transition-all <?= $lc >= 90 ? 'bg-red-500' : ($lc >= 70 ? 'bg-amber-500' : 'bg-white/60') ?>" style="width:<?= $lc ?>%"></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($is_pro): ?>
  <div class="mt-4 pt-4 border-t border-white/5 flex items-center justify-between">
    <p class="text-xs text-slate-500">Need more capacity?</p>
    <a href="/portal/billing.php?upgrade=1&plan=entrepreneur"
       class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold transition">
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

<!-- Activity feed: show recent unlocked leads -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden">
  <div class="flex items-center justify-between px-6 py-4 border-b border-white/5">
    <div class="flex items-center gap-2"><i class="fa-solid fa-clock-rotate-left text-slate-400 text-sm"></i><h3 class="font-semibold text-sm">Recent Leads</h3></div>
    <a href="/portal/leads.php" class="text-xs text-slate-500 hover:text-white transition">Find more &rarr;</a>
  </div>
  <?php
  $recentLeads = [];
  try {
      $s = $pdo->prepare('
          SELECT ul.business_name, ul.business_city, ul.business_category, ul.opportunity_score, ul_j.unlocked_at
          FROM unlocked_leads ul_j
          JOIN utiligo_leads ul ON ul.id = ul_j.lead_id
          WHERE ul_j.user_id = ?
          ORDER BY ul_j.unlocked_at DESC
          LIMIT 6
      ');
      $s->execute([$uid]);
      $recentLeads = $s->fetchAll(PDO::FETCH_ASSOC);
  } catch (\Throwable $e) {}
  ?>
  <?php if (!$recentLeads): ?>
  <div class="px-6 py-12 text-center">
    <div class="w-14 h-14 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-4"><i class="fa-solid fa-inbox text-slate-500 text-xl"></i></div>
    <p class="text-slate-400 font-medium">No leads yet</p>
    <p class="text-slate-600 text-sm mt-1">Start by finding your first leads.</p>
    <a href="/portal/leads.php" class="inline-flex items-center gap-2 mt-4 bg-white hover:bg-slate-200 text-black px-5 py-2 rounded-xl text-sm font-bold">
      <i class="fa-solid fa-magnifying-glass"></i> Find First Leads
    </a>
  </div>
  <?php else: ?>
  <ul class="divide-y divide-white/5">
    <?php foreach ($recentLeads as $r): ?>
    <li class="flex items-center gap-4 px-6 py-3.5 hover:bg-white/[0.02] transition">
      <div class="w-2 h-2 rounded-full bg-white/40 shrink-0"></div>
      <div class="flex-1 min-w-0">
        <p class="font-medium text-sm truncate"><?= htmlspecialchars($r['business_name'] ?? '') ?></p>
        <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars(($r['business_city'] ?? '') . ($r['business_category'] ? ' · ' . $r['business_category'] : '')) ?></p>
      </div>
      <div class="flex items-center gap-3 shrink-0">
        <?php if (!empty($r['opportunity_score'])): ?>
        <span class="text-xs px-2 py-0.5 rounded-full bg-white/5 text-slate-400"><?= (int)$r['opportunity_score'] ?> score</span>
        <?php endif; ?>
        <span class="text-xs text-slate-600"><?= date('M j', strtotime($r['unlocked_at'])) ?></span>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/portal_layout_end.php'; ?>
