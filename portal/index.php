<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site_templates.php';

require_login();
$user    = current_user();
$uid     = (int)$user['id'];
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);
$pdo     = get_platform_db();

$firstName = trim(explode(' ', $user['full_name'] ?? 'there')[0]);
if ($firstName === '' || strtoupper($firstName) === 'THERE') $firstName = 'there';
$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// ── Sites stats ───────────────────────────────────────────────────────────────
$sites = [];
try {
    $s = $pdo->prepare("SELECT * FROM utiligo_generated_sites WHERE user_id = ? ORDER BY created_at DESC");
    $s->execute([$uid]);
    $sites = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

$totalSites  = count($sites);
$liveSites   = 0;
$totalViews  = 0;
$now         = time();
foreach ($sites as $st) {
    $totalViews += (int)($st['view_count'] ?? 0);
    if (!empty($st['link_active']) && !empty($st['public_slug'])) {
        $exp = !empty($st['link_expires_at']) ? strtotime($st['link_expires_at']) : null;
        if (!$exp || $exp > $now) $liveSites++;
    }
}
$recentSites = array_slice($sites, 0, 5);

// ── Views last 14 days (from analytics log) ───────────────────────────────────
$views14 = [];
for ($i = 13; $i >= 0; $i--) $views14[date('Y-m-d', strtotime("-$i days"))] = 0;
try {
    $v = $pdo->prepare("
        SELECT DATE(l.viewed_at) AS day, COUNT(*) AS cnt
        FROM utiligo_site_view_log l
        JOIN utiligo_generated_sites g ON g.id = l.site_id
        WHERE g.user_id = ? AND l.viewed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(l.viewed_at)
    ");
    $v->execute([$uid]);
    foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($views14[$row['day']])) $views14[$row['day']] = (int)$row['cnt'];
    }
} catch (\Throwable $e) {}
$views14Total = array_sum($views14);

// ── CRM stats (paid only, table may not exist yet) ────────────────────────────
$crmClients   = [];
$crmTasks     = [];
$upcomingTasks = [];   // tasks due today, overdue, or in next 7 days (mobile-friendly bucketed feed)
$stageCounts  = ['lead'=>0,'contacted'=>0,'proposal'=>0,'negotiation'=>0,'won'=>0,'lost'=>0];
$pipelineVal  = 0.0;
$crmTotal     = 0;
if ($is_paid) {
    try {
        $c = $pdo->prepare("SELECT * FROM crm_clients WHERE user_id = ? ORDER BY updated_at DESC");
        $c->execute([$uid]);
        $crmClients = $c->fetchAll(PDO::FETCH_ASSOC);
        $crmTotal   = count($crmClients);
        foreach ($crmClients as $cl) {
            $stg = $cl['stage'] ?? 'lead';
            if (isset($stageCounts[$stg])) $stageCounts[$stg]++;
            if (!in_array($stg, ['won','lost'], true)) {
                $pipelineVal += (float)$cl['deal_value'] * ((int)($cl['probability'] ?? 50) / 100);
            }
        }
        $t = $pdo->prepare("SELECT t.*, c.name AS client_name FROM crm_tasks t LEFT JOIN crm_clients c ON c.id = t.client_id WHERE t.user_id = ? AND t.done = 0 ORDER BY (t.due_date IS NULL), t.due_date ASC LIMIT 5");
        $t->execute([$uid]);
        $crmTasks = $t->fetchAll(PDO::FETCH_ASSOC);

        // Bucketed follow-up tasks — for the dashboard "follow-up" widget.
        // due today / overdue / upcoming (next 7 days). Mobile-friendly.
        $bucketStmt = $pdo->prepare("
            SELECT t.*, c.name AS client_name
            FROM crm_tasks t
            LEFT JOIN crm_clients c ON c.id = t.client_id
            WHERE t.user_id = ? AND t.done = 0
              AND t.due_date IS NOT NULL
              AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY t.due_date ASC
            LIMIT 15
        ");
        $bucketStmt->execute([$uid]);
        $upcomingTasks = $bucketStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}
$recentClients = array_slice($crmClients, 0, 5);

// Bucket counters for the follow-up widget
$todayStr    = date('Y-m-d');
$upcomingEnd = date('Y-m-d', strtotime('+7 days'));
$tasksOverdue = 0; $tasksToday = 0; $tasksUpcoming = 0;
foreach ($upcomingTasks as $tk) {
    $d = $tk['due_date'] ?? null;
    if (!$d) continue;
    if ($d < $todayStr)        $tasksOverdue++;
    elseif ($d === $todayStr) $tasksToday++;
    else                       $tasksUpcoming++;
}

$stageMeta = [
    'lead'        => ['Lead',        '#3b82f6'],
    'contacted'   => ['Contacted',   '#f59e0b'],
    'proposal'    => ['Proposal',    '#8b5cf6'],
    'negotiation' => ['Negotiation', '#ec4899'],
    'won'         => ['Won',         '#10b981'],
    'lost'        => ['Lost',        '#ef4444'],
];

$allTpls = get_all_site_templates();

$viewsLabels = json_encode(array_map(fn($d) => date('M j', strtotime($d)), array_keys($views14)));
$viewsData   = json_encode(array_values($views14));
$stageLabels = json_encode(array_column(array_values($stageMeta), 0));
$stageData   = json_encode(array_values($stageCounts));
$stageColors = json_encode(array_column(array_values($stageMeta), 1));

$planBadge = match($plan) {
    'entrepreneur' => ['Entrepreneur', '#f59e0b'],
    'pro'          => ['Pro',          '#8b5cf6'],
    default        => ['Free',         '#64748b'],
};

$pageTitle = 'Dashboard — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
.dash-card { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:18px; padding:20px; }
@media (max-width:639px) {
  .dash-card { padding:16px; border-radius:14px; }
  .kpi-value { font-size:1.55rem; }
  .kpi-icon { width:32px; height:32px; font-size:.78rem; }
}
.kpi { display:flex; flex-direction:column; gap:4px; position:relative; overflow:hidden; }
.kpi-icon { position:absolute; top:16px; right:16px; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.85rem; }
.kpi-label { font-size:.65rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.kpi-value { font-size:1.9rem; font-weight:900; color:#f1f5f9; line-height:1.05; }
.kpi-sub   { font-size:.7rem; color:#475569; }
.qa-btn { display:flex; align-items:center; gap:12px; padding:14px 16px; border-radius:14px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); transition:all .15s; text-decoration:none; }
.qa-btn:hover { background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.15); transform:translateY(-2px); }
@media (max-width:639px) {
  .qa-btn { padding:12px 14px; gap:10px; }
  .qa-icon { width:34px; height:34px; font-size:.82rem; }
}
.qa-icon { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.9rem; }
.feed-row { display:flex; align-items:center; gap:11px; padding:10px 0; border-bottom:1px solid rgba(255,255,255,.05); }
.feed-row:last-child { border-bottom:none; }
.feed-dot { width:32px; height:32px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.7rem; color:#fff; }
.feed-title { font-size:.82rem; font-weight:700; color:#e2e8f0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.feed-sub { font-size:.68rem; color:#64748b; }
.stage-badge { font-size:.6rem; font-weight:800; padding:2px 8px; border-radius:999px; text-transform:uppercase; letter-spacing:.04em; }
.section-title { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; }
.section-title a { color:#818cf8; text-transform:none; letter-spacing:0; font-weight:600; font-size:.72rem; }
.section-title a:hover { color:#a5b4fc; }
.live-pill { font-size:.58rem; font-weight:800; padding:2px 7px; border-radius:999px; background:rgba(16,185,129,.15); color:#6ee7b7; }
.offline-pill { font-size:.58rem; font-weight:800; padding:2px 7px; border-radius:999px; background:rgba(255,255,255,.06); color:#64748b; }
.task-due { font-size:.62rem; font-weight:700; padding:2px 8px; border-radius:999px; }
</style>

<!-- Greeting header -->
<div class="flex items-start sm:items-center justify-between flex-wrap gap-3 mb-6 sm:mb-8">
  <div class="min-w-0">
    <div class="flex items-center gap-2 sm:gap-3 flex-wrap">
      <h1 class="text-2xl sm:text-3xl font-black tracking-tight"><?= $greeting ?>, <?= htmlspecialchars($firstName) ?></h1>
      <span class="text-[10px] font-extrabold px-2.5 py-1 rounded-full uppercase tracking-wider"
            style="background:<?= $planBadge[1] ?>22;color:<?= $planBadge[1] ?>;border:1px solid <?= $planBadge[1] ?>44;">
        <?= $planBadge[0] ?>
      </span>
    </div>
    <p class="text-slate-500 text-xs sm:text-sm mt-1"><?= date('l, F j') ?> — here's what's happening across your sites and clients.</p>
  </div>
  <a href="/portal/generate.php"
     class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 active:scale-95 text-black px-4 sm:px-5 py-2.5 rounded-xl font-bold text-sm transition-all w-full sm:w-auto justify-center sm:justify-start">
    <i class="fa-solid fa-bolt text-xs"></i> Generate New Site
  </a>
</div>

<!-- KPI cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3 mb-5 sm:mb-6">
  <div class="dash-card kpi">
    <div class="kpi-icon" style="background:rgba(16,185,129,.12);color:#34d399;"><i class="fa-solid fa-globe"></i></div>
    <p class="kpi-label">Live Sites</p>
    <p class="kpi-value"><?= $liveSites ?><span style="font-size:1rem;color:#475569;"> / <?= $totalSites ?></span></p>
    <p class="kpi-sub"><?= $totalSites - $liveSites ?> offline or expired</p>
  </div>
  <div class="dash-card kpi">
    <div class="kpi-icon" style="background:rgba(99,102,241,.12);color:#818cf8;"><i class="fa-solid fa-eye"></i></div>
    <p class="kpi-label">Views · 14 days</p>
    <p class="kpi-value"><?= number_format($views14Total) ?></p>
    <p class="kpi-sub"><?= number_format($totalViews) ?> all time</p>
  </div>
  <div class="dash-card kpi">
    <div class="kpi-icon" style="background:rgba(139,92,246,.12);color:#a78bfa;"><i class="fa-solid fa-users"></i></div>
    <p class="kpi-label">CRM Clients</p>
    <p class="kpi-value"><?= $is_paid ? $crmTotal : '—' ?></p>
    <p class="kpi-sub"><?= $is_paid ? ($stageCounts['won'] . ' won · ' . $stageCounts['lead'] . ' leads') : 'Upgrade to unlock' ?></p>
  </div>
  <div class="dash-card kpi">
    <div class="kpi-icon" style="background:rgba(245,158,11,.12);color:#fbbf24;"><i class="fa-solid fa-sack-dollar"></i></div>
    <p class="kpi-label">Pipeline Value</p>
    <p class="kpi-value"><?= $is_paid ? '$' . number_format($pipelineVal, 0) : '—' ?></p>
    <p class="kpi-sub"><?= $is_paid ? 'Probability-weighted' : 'Upgrade to unlock' ?></p>
  </div>
</div>

<!-- Quick actions -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3 mb-5 sm:mb-6">
  <a href="/portal/generate.php" class="qa-btn">
    <div class="qa-icon" style="background:rgba(16,185,129,.12);color:#34d399;"><i class="fa-solid fa-bolt"></i></div>
    <div><p class="text-sm font-bold text-slate-200">Generate Site</p><p class="text-[11px] text-slate-500">AI site in 60s</p></div>
  </a>
  <a href="/portal/leads.php" class="qa-btn">
    <div class="qa-icon" style="background:rgba(99,102,241,.12);color:#818cf8;"><i class="fa-solid fa-magnifying-glass"></i></div>
    <div><p class="text-sm font-bold text-slate-200">Find Leads</p><p class="text-[11px] text-slate-500">Search businesses</p></div>
  </a>
  <a href="/portal/crm.php" class="qa-btn">
    <div class="qa-icon" style="background:rgba(139,92,246,.12);color:#a78bfa;"><i class="fa-solid fa-users"></i></div>
    <div><p class="text-sm font-bold text-slate-200">Client CRM</p><p class="text-[11px] text-slate-500">Pipeline &amp; tasks</p></div>
  </a>
  <a href="/portal/my_sites.php" class="qa-btn">
    <div class="qa-icon" style="background:rgba(245,158,11,.12);color:#fbbf24;"><i class="fa-solid fa-layer-group"></i></div>
    <div><p class="text-sm font-bold text-slate-200">My Sites</p><p class="text-[11px] text-slate-500">Manage &amp; share</p></div>
  </a>
</div>

<!-- Charts row -->
<div class="grid grid-cols-1 lg:grid-cols-5 gap-3 sm:gap-4 mb-5 sm:mb-6">

  <div class="dash-card lg:col-span-3">
    <p class="section-title">Site Views — Last 14 Days
      <a href="/portal/my_sites.php">Details →</a>
    </p>
    <?php if ($views14Total > 0): ?>
      <canvas id="viewsChart" height="110"></canvas>
    <?php else: ?>
      <div class="text-center py-10 text-slate-600 text-sm">
        <i class="fa-solid fa-chart-line text-2xl block mb-2 opacity-30"></i>
        No tracked views yet. Share a live site link to start collecting data.
      </div>
    <?php endif; ?>
  </div>

  <div class="dash-card lg:col-span-2">
    <p class="section-title">CRM Pipeline
      <a href="/portal/crm.php">Open CRM →</a>
    </p>
    <?php if ($is_paid && $crmTotal > 0): ?>
      <canvas id="stageChart" height="130"></canvas>
    <?php elseif ($is_paid): ?>
      <div class="text-center py-10 text-slate-600 text-sm">
        <i class="fa-solid fa-users text-2xl block mb-2 opacity-30"></i>
        No clients yet. Add your first client in the CRM.
      </div>
    <?php else: ?>
      <div class="text-center py-8">
        <i class="fa-solid fa-lock text-slate-600 text-2xl block mb-3"></i>
        <p class="text-slate-400 text-sm font-semibold mb-1">Pipeline tracking is a Pro feature</p>
        <p class="text-slate-600 text-xs mb-4">Track every deal from lead to won.</p>
        <a href="/portal/billing.php?upgrade=1" class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-4 py-2 rounded-xl text-xs font-bold transition">
          <i class="fa-solid fa-rocket"></i> Upgrade
        </a>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Activity + tasks row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 sm:gap-4 mb-5 sm:mb-6">

  <!-- Recent sites -->
  <div class="dash-card">
    <p class="section-title">Recent Sites
      <a href="/portal/my_sites.php">View all →</a>
    </p>
    <?php if (empty($recentSites)): ?>
      <div class="text-center py-8 text-slate-600 text-sm">
        <i class="fa-solid fa-globe text-2xl block mb-2 opacity-30"></i>
        No sites yet — generate your first one.
      </div>
    <?php else: foreach ($recentSites as $rs):
      $rtpl  = $allTpls[$rs['template_name'] ?? 'modern'] ?? $allTpls['modern'];
      $rlive = !empty($rs['link_active']) && !empty($rs['public_slug'])
            && (empty($rs['link_expires_at']) || strtotime($rs['link_expires_at']) > $now);
    ?>
      <div class="feed-row">
        <div class="feed-dot" style="background:linear-gradient(135deg,<?= htmlspecialchars($rtpl['secondary']) ?>,<?= htmlspecialchars($rtpl['primary']) ?>);">
          <i class="fa-solid fa-globe"></i>
        </div>
        <div class="flex-1 min-w-0">
          <p class="feed-title"><?= htmlspecialchars($rs['business_name']) ?></p>
          <p class="feed-sub"><?= date('M j', strtotime($rs['created_at'])) ?> · <?= number_format((int)($rs['view_count'] ?? 0)) ?> views</p>
        </div>
        <span class="<?= $rlive ? 'live-pill' : 'offline-pill' ?>"><?= $rlive ? '● Live' : 'Offline' ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Recent clients -->
  <div class="dash-card">
    <p class="section-title">Recent Clients
      <a href="/portal/crm.php">View all →</a>
    </p>
    <?php if (!$is_paid): ?>
      <div class="text-center py-8 text-slate-600 text-sm">
        <i class="fa-solid fa-lock text-2xl block mb-2 opacity-30"></i>
        Client CRM requires a paid plan.
      </div>
    <?php elseif (empty($recentClients)): ?>
      <div class="text-center py-8 text-slate-600 text-sm">
        <i class="fa-solid fa-user-plus text-2xl block mb-2 opacity-30"></i>
        No clients yet.
      </div>
    <?php else: foreach ($recentClients as $rc):
      $sm = $stageMeta[$rc['stage'] ?? 'lead'] ?? $stageMeta['lead'];
    ?>
      <div class="feed-row">
        <div class="feed-dot" style="background:<?= htmlspecialchars($rc['avatar_color'] ?? '#3b82f6') ?>;">
          <?= strtoupper(substr($rc['name'], 0, 1)) ?>
        </div>
        <div class="flex-1 min-w-0">
          <p class="feed-title"><?= htmlspecialchars($rc['name']) ?></p>
          <p class="feed-sub"><?= htmlspecialchars($rc['business'] ?: '—') ?><?= (float)$rc['deal_value'] > 0 ? ' · $' . number_format((float)$rc['deal_value'], 0) : '' ?></p>
        </div>
        <span class="stage-badge" style="background:<?= $sm[1] ?>22;color:<?= $sm[1] ?>;"><?= $sm[0] ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Tasks due — Follow-ups -->
  <div class="dash-card">
    <p class="section-title">Follow-ups
      <a href="/portal/crm.php">Manage →</a>
    </p>
    <?php if (!$is_paid): ?>
      <div class="text-center py-8 text-slate-600 text-sm">
        <i class="fa-solid fa-lock text-2xl block mb-2 opacity-30"></i>
        Tasks require a paid plan.
      </div>
    <?php elseif (empty($upcomingTasks) && empty($crmTasks)): ?>
      <div class="text-center py-8 text-slate-600 text-sm">
        <i class="fa-solid fa-check-double text-2xl block mb-2 opacity-30"></i>
        All caught up. Nothing due.
      </div>
    <?php else: ?>
      <!-- Buckets -->
      <div class="grid grid-cols-3 gap-2 mb-3">
        <div class="rounded-xl p-2.5 text-center" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);">
          <p class="text-lg font-extrabold text-red-400"><?= $tasksOverdue ?></p>
          <p class="text-[10px] text-slate-500 uppercase tracking-wide font-semibold">Overdue</p>
        </div>
        <div class="rounded-xl p-2.5 text-center" style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.15);">
          <p class="text-lg font-extrabold" style="color:#fbbf24;"><?= $tasksToday ?></p>
          <p class="text-[10px] text-slate-500 uppercase tracking-wide font-semibold">Today</p>
        </div>
        <div class="rounded-xl p-2.5 text-center" style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.15);">
          <p class="text-lg font-extrabold" style="color:#818cf8;"><?= $tasksUpcoming ?></p>
          <p class="text-[10px] text-slate-500 uppercase tracking-wide font-semibold">7 days</p>
        </div>
      </div>
      <!-- Upcoming feed -->
      <?php
        $renderedList = 0;
        foreach ($upcomingTasks as $tk):
          if ($renderedList >= 6) break;
          $due    = $tk['due_date'] ?? null;
          $overdue = $due && strtotime($due) < strtotime('today');
          $today   = $due && date('Y-m-d', strtotime($due)) === date('Y-m-d');
          $priColor = ['high'=>'#f87171','medium'=>'#fbbf24','low'=>'#64748b'][$tk['priority'] ?? 'medium'];
          $renderedList++;
      ?>
        <div class="feed-row">
          <div class="feed-dot" style="background:rgba(255,255,255,.06);color:<?= $priColor ?>;">
            <i class="fa-solid fa-flag"></i>
          </div>
          <div class="flex-1 min-w-0">
            <p class="feed-title"><?= htmlspecialchars($tk['title']) ?></p>
            <p class="feed-sub"><?= htmlspecialchars($tk['client_name'] ?? 'General') ?></p>
          </div>
          <?php if ($due): ?>
            <span class="task-due" style="background:<?= $overdue ? 'rgba(239,68,68,.15);color:#f87171' : ($today ? 'rgba(245,158,11,.15);color:#fbbf24' : 'rgba(255,255,255,.06);color:#94a3b8') ?>;">
              <?= $overdue ? 'Overdue' : ($today ? 'Today' : date('M j', strtotime($due))) ?>
            </span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (count($upcomingTasks) > 6): ?>
      <p class="text-center text-[11px] text-slate-600 mt-2"><a href="/portal/crm.php#tasks" class="hover:text-slate-400 transition">+ <?= count($upcomingTasks) - 6 ?> more in CRM →</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<!-- Upgrade banner (free only) -->
<?php if (!$is_paid): ?>
<div class="dash-card" style="background:linear-gradient(135deg,rgba(139,92,246,.12),rgba(99,102,241,.08));border-color:rgba(139,92,246,.25);">
  <div class="flex items-center justify-between flex-wrap gap-4">
    <div>
      <p class="font-bold text-slate-100 mb-1"><i class="fa-solid fa-rocket mr-2" style="color:#a78bfa;"></i>Unlock the full toolkit</p>
      <p class="text-slate-400 text-sm">Client CRM, pipeline tracking, tasks, notes and more site slots — starting with Pro.</p>
    </div>
    <a href="/portal/billing.php?upgrade=1" class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-5 py-2.5 rounded-xl text-sm font-bold transition">
      View Plans
    </a>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const vc = document.getElementById('viewsChart');
  if (vc) {
    const ctx = vc.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 220);
    grad.addColorStop(0, 'rgba(99,102,241,.35)');
    grad.addColorStop(1, 'rgba(99,102,241,0)');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?= $viewsLabels ?>,
        datasets: [{
          data: <?= $viewsData ?>,
          fill: true,
          backgroundColor: grad,
          borderColor: '#6366f1',
          borderWidth: 2,
          pointRadius: 3,
          pointBackgroundColor: '#6366f1',
          tension: .35
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false },
          tooltip: { backgroundColor:'#1e293b', borderColor:'rgba(255,255,255,.1)', borderWidth:1,
            callbacks: { label: c => ' ' + c.parsed.y + ' views' } } },
        scales: {
          x: { ticks: { color:'#475569', font:{size:10}, maxTicksLimit:7 }, grid:{ color:'rgba(255,255,255,.04)' } },
          y: { beginAtZero:true, ticks:{ color:'#475569', font:{size:10}, precision:0 }, grid:{ color:'rgba(255,255,255,.06)' } }
        }
      }
    });
  }

  const sc = document.getElementById('stageChart');
  if (sc) {
    new Chart(sc.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= $stageLabels ?>,
        datasets: [{
          data: <?= $stageData ?>,
          backgroundColor: <?= $stageColors ?>,
          borderRadius: 6,
          maxBarThickness: 28
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false },
          tooltip: { backgroundColor:'#1e293b', borderColor:'rgba(255,255,255,.1)', borderWidth:1,
            callbacks: { label: c => ' ' + c.parsed.y + ' clients' } } },
        scales: {
          x: { ticks: { color:'#475569', font:{size:9} }, grid:{ display:false } },
          y: { beginAtZero:true, ticks:{ color:'#475569', font:{size:10}, precision:0 }, grid:{ color:'rgba(255,255,255,.06)' } }
        }
      }
    });
  }
})();
</script>
