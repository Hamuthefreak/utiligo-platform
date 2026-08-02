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

// ── Ensure tables exist ─────────────────────────────────────────────────────────────
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

// ── Counts ──────────────────────────────────────────────────────────────────────────────
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

// ── Plan limits ───────────────────────────────────────────────────────────────────
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
  background: linear-gradient(90deg, #a78bfa, #c4b5fd, #818cf8, #a78bfa);
  background-size: 200% auto;
  animation: ent-shimmer 3s linear infinite;
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
  <div class="flex items-center gap-2 bg-violet-500/10 border border-violet-500/25 rounded-full px-4 py-2 text-sm">
    <i class="fa-solid fa-bolt text-violet-400"></i>
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
<div class="rounded-2xl border border-violet-500/20 mb-8 overflow-hidden" style="background:linear-gradient(135deg,#0d0d14 0%,#0f0d1a 100%)">
  <div class="px-6 py-4 border-b border-white/5 flex items-center gap-3">
    <i class="fa-solid fa-bolt text-violet-400"></i>
    <span class="font-bold text-sm">Your Entrepreneur Features</span>
    <span class="ml-auto text-xs text-violet-400/60"><?= ENT_TEAM_SEATS ?> team seats &bull; <?= ENT_SITE_LIMIT ?> active sites &bull; Unlimited leads</span>
  </div>
  <div class="grid sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-white/5">

    <!-- Custom Domains -->
    <div class="px-6 py-5 flex flex-col gap-3">
      <div class="w-10 h-10 rounded-xl bg-violet-500/15 border border-violet-500/20 flex items-center justify-center">
        <i class="fa-solid fa-globe text-violet-400"></i>
      </div>
      <div>
        <p class="font-semibold text-sm text-white">Custom Domains</p>
        <p class="text-xs text-slate-400 mt-0.5">Point your own domain to any generated site. Unlimited domains included.</p>
      </div>
      <a href="/portal/my_sites.php" class="mt-auto text-xs text-violet-400 hover:text-violet-300 font-semibold transition">
        Manage sites <i class="fa-solid fa-arrow-right text-[10px]"></i>
      </a>
    </div>

    <!-- Client Reports -->
    <div class="px-6 py-5 flex flex-col gap-3">
      <div class="w-10 h-10 rounded-xl bg-violet-500/15 border border-violet-500/20 flex items-center justify-center">
        <i class="fa-solid fa-chart-line text-violet-400"></i>
      </div>
      <div>
        <p class="font-semibold text-sm text-white">Client Reports</p>
        <p class="text-xs text-slate-400 mt-0.5">Generate white-label performance reports to send directly to your clients.</p>
      </div>
      <a href="/portal/my_sites.php" class="mt-auto text-xs text-violet-400 hover:text-violet-300 font-semibold transition">
        View sites <i class="fa-solid fa-arrow-right text-[10px]"></i>
      </a>
    </div>

    <!-- Team Seats -->
    <div class="px-6 py-5 flex flex-col gap-3">
      <div class="w-10 h-10 rounded-xl bg-violet-500/15 border border-violet-500/20 flex items-center justify-center">
        <i class="fa-solid fa-users text-violet-400"></i>
      </div>
      <div>
        <p class="font-semibold text-sm text-white">Team Seats</p>
        <p class="text-xs text-slate-400 mt-0.5">Invite up to <?= ENT_TEAM_SEATS ?> team members to collaborate on your Utiligo account.</p>
      </div>
      <a href="/portal/settings.php" class="mt-auto text-xs text-violet-400 hover:text-violet-300 font-semibold transition">
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
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4"><i class="fa-solid fa-dollar-sign text-white text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Revenue Tracked</p>
    <p class="text-4xl font-black">$<?= number_format($totalRevenue, 0) ?></p>
    <span class="text-xs text-slate-500 mt-2 inline-block">Self-reported</span>
  </div>
  <div class="group relative glass rounded-2xl p-5 border border-white/5 overflow-hidden hover:border-white/20 transition-all hover:-translate-y-0.5">
    <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-white/5 group-hover:bg-white/10 transition-all"></div>
    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mb-4"><i class="fa-solid fa-handshake text-white text-sm"></i></div>
    <p class="text-slate-400 text-xs font-medium uppercase tracking-wider mb-1">Deals Won</p>
    <p class="text-4xl font-black"><?= $dealsWon ?></p>
    <span class="text-xs text-slate-500 mt-2 inline-block">This period</span>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/portal_layout_end.php'; ?>
