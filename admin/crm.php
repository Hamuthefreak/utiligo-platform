<?php
/**
 * admin/crm.php — Read-only admin analytics for the Client CRM.
 * Aggregates crm_clients / crm_tasks / crm_activities across all users.
 * No writes here. Admins do not edit individual user CRM data.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$pdo = get_platform_db();

// ── Aggregate stats (every query is wrapped so a missing column / table
//    on a partially-migrated DB doesn't fatality the page) ───────────────────
function crm_safe_query(PDO $pdo, string $sql, array $params = []): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[admin/crm] ' . $e->getMessage());
        return [];
    }
}
function crm_safe_scalar(PDO $pdo, string $sql, array $params = []) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[admin/crm] ' . $e->getMessage());
        return 0;
    }
}

// Total clients
$totalClients = (int)crm_safe_scalar($pdo, 'SELECT COUNT(*) FROM crm_clients');

// Total won value across all users (won stage)
$totalWonValue = (float)crm_safe_scalar($pdo, "SELECT COALESCE(SUM(deal_value),0) FROM crm_clients WHERE stage='won'");
// Pipeline value (probability-weighted, non-won/lost)
$totalPipelineValue = (float)crm_safe_scalar($pdo,
    "SELECT COALESCE(SUM(deal_value * probability / 100),0) FROM crm_clients WHERE stage NOT IN ('won','lost')");
// Total tasks / open tasks
$totalTasks     = (int)crm_safe_scalar($pdo, 'SELECT COUNT(*) FROM crm_tasks');
$openTasks      = (int)crm_safe_scalar($pdo, 'SELECT COUNT(*) FROM crm_tasks WHERE done=0');
// Total activities
$totalActivities = (int)crm_safe_scalar($pdo, 'SELECT COUNT(*) FROM crm_activities');

// Per-stage counts
$rows = crm_safe_query($pdo,
    "SELECT stage, COUNT(*) c, COALESCE(SUM(deal_value),0) v FROM crm_clients GROUP BY stage");
$stageStats = [
    'lead'=>0,'contacted'=>0,'proposal'=>0,'negotiation'=>0,'won'=>0,'lost'=>0,
];
$stageValues = $stageStats;
foreach ($rows as $r) {
    if (isset($stageStats[$r['stage']])) {
        $stageStats[$r['stage']] = (int)$r['c'];
        $stageValues[$r['stage']] = (float)$r['v'];
    }
}
$winConv = $totalClients > 0 ? round($stageStats['won'] / $totalClients * 100) : 0;

// Top 10 users by pipeline value (weighted)
$topUsers = crm_safe_query($pdo,
    "SELECT u.id, u.full_name, u.email, u.plan,
            COUNT(c.id) AS clients,
            SUM(CASE WHEN c.stage NOT IN ('won','lost') THEN c.deal_value * c.probability / 100 ELSE 0 END) AS pipeline_value,
            SUM(CASE WHEN c.stage='won' THEN c.deal_value ELSE 0 END) AS won_value,
            SUM(CASE WHEN c.stage='won' THEN 1 ELSE 0 END) AS won_count
     FROM crm_clients c
     LEFT JOIN (SELECT id, full_name, email, plan FROM utiligo_users) u ON u.id = c.user_id
     GROUP BY c.user_id
     ORDER BY pipeline_value DESC
     LIMIT 10");
// Note: utiligo_users lives in the user DB. On the platform DB this JOIN
// may return NULL columns for u.* (because the table doesn't exist here).
// In that case we resolve users by id from the user DB below.
if ($topUsers && ($topUsers[0]['email'] ?? null) === null) {
    try {
        $udb = get_user_db();
        $ids = array_map(fn($r) => (int)$r['id'], $topUsers);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $uq = $udb->prepare("SELECT id, full_name, email, plan FROM utiligo_users WHERE id IN ($placeholders)");
            $uq->execute($ids);
            $usersById = [];
            foreach ($uq->fetchAll(PDO::FETCH_ASSOC) as $ur) $usersById[(int)$ur['id']] = $ur;
            foreach ($topUsers as &$r) {
                if (isset($usersById[(int)$r['id']])) {
                    $r['full_name'] = $usersById[(int)$r['id']]['full_name'];
                    $r['email']     = $usersById[(int)$r['id']]['email'];
                    $r['plan']      = $usersById[(int)$r['id']]['plan'];
                }
            }
            unset($r);
        }
    } catch (\Throwable $e) {
        error_log('[admin/crm users] ' . $e->getMessage());
    }
}

// Recent activity across all users (last 25)
$recentActivity = crm_safe_query($pdo,
    "SELECT a.id, a.user_id, a.client_id, a.activity_type, a.title, a.created_at,
            u.email AS user_email, u.full_name, c.name AS client_name
     FROM crm_activities a
     LEFT JOIN (SELECT id, full_name, email FROM utiligo_users) u ON u.id = a.user_id
     LEFT JOIN crm_clients c ON c.id = a.client_id
     ORDER BY a.created_at DESC LIMIT 25");
// Same cross-DB resolution for user_email/full_name
if ($recentActivity && ($recentActivity[0]['user_email'] ?? null) === null) {
    try {
        $udb = get_user_db();
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $recentActivity)));
        $ids = array_filter($ids, fn($x) => $x > 0);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $uq = $udb->prepare("SELECT id, full_name, email FROM utiligo_users WHERE id IN ($placeholders)");
            $uq->execute($ids);
            $usersById = [];
            foreach ($uq->fetchAll(PDO::FETCH_ASSOC) as $ur) $usersById[(int)$ur['id']] = $ur;
            foreach ($recentActivity as &$r) {
                if (isset($usersById[(int)$r['user_id']])) {
                    $r['user_email'] = $usersById[(int)$r['user_id']]['email'];
                    $r['full_name']  = $usersById[(int)$r['user_id']]['full_name'];
                }
            }
            unset($r);
        }
    } catch (\Throwable $e) {
        error_log('[admin/crm activity users] ' . $e->getMessage());
    }
}

$stageMeta = [
    'lead'        => ['Lead',        '#3b82f6'],
    'contacted'   => ['Contacted',   '#f59e0b'],
    'proposal'    => ['Proposal',    '#8b5cf6'],
    'negotiation' => ['Negotiation', '#ec4899'],
    'won'         => ['Won',         '#10b981'],
    'lost'        => ['Lost',        '#ef4444'],
];

$pageTitle = 'CRM Analytics — Admin — Utiligo';
$adminPage = 'crm';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="mb-8 flex items-start justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5">Platform-wide CRM usage</p>
    <h1 class="text-3xl font-bold tracking-tight">CRM Analytics</h1>
    <p class="text-slate-500 text-xs mt-1">Read-only — aggregated across all paid users.</p>
  </div>
  <div class="text-right text-xs text-slate-600 mt-1">
    <p><?= number_format($totalClients) ?> clients &middot; <?= number_format($totalTasks) ?> tasks</p>
    <p><?= number_format($totalActivities) ?> activities logged</p>
  </div>
</div>

<!-- KPI tiles -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
  <?php
  $tiles = [
    ['fa-users',       '#3b82f6', 'Total Clients',     number_format($totalClients),           $openTasks . ' open tasks'],
    ['fa-trophy',      '#10b981', 'Won Revenue',       '$' . number_format($totalWonValue, 0),  $winConv . '% conversion'],
    ['fa-filter',      '#8b5cf6', 'Pipeline Value',    '$' . number_format($totalPipelineValue, 0), 'Probability-weighted'],
    ['fa-list-check',  '#f59e0b', 'Open Tasks',        number_format($openTasks),               $totalTasks . ' total'],
  ];
  foreach ($tiles as [$ic, $col, $lbl, $val, $sub]):?>
  <div class="bg-white/[0.03] border border-white/5 rounded-2xl p-4">
    <div class="flex items-center gap-2 mb-2">
      <i class="fa-solid <?=$ic?> text-xs" style="color:<?=$col?>"></i>
      <span class="text-[10px] font-semibold text-slate-600 uppercase tracking-wide"><?=$lbl?></span>
    </div>
    <p class="text-2xl font-extrabold text-white"><?=$val?></p>
    <p class="text-[11px] text-slate-600 mt-0.5"><?=$sub?></p>
  </div>
  <?php endforeach;?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

  <!-- Stage distribution -->
  <div class="bg-white/[0.03] border border-white/5 rounded-2xl p-5">
    <h3 class="text-sm font-bold mb-4">Clients by Stage</h3>
    <div class="space-y-3">
      <?php foreach ($stageMeta as $sk => $sd):
        $c   = $stageStats[$sk];
        $max = max(1, ...array_values($stageStats));
        $pct = $max > 0 ? (int)round($c / $max * 100) : 0;
      ?>
      <div>
        <div class="flex items-center justify-between text-xs mb-1">
          <span><span class="inline-block w-2 h-2 rounded-full" style="background:<?=$sd[1]?>"></span> <?=$sd[0]?></span>
          <span class="text-slate-400 font-semibold"><?=$c?> &middot; $<?=number_format((float)$stageValues[$sk], 0)?></span>
        </div>
        <div class="h-2 rounded-full bg-white/5 overflow-hidden">
          <div class="h-full rounded-full transition-all" style="width:<?=$pct?>%;background:<?=$sd[1]?>"></div>
        </div>
      </div>
      <?php endforeach;?>
    </div>
  </div>

  <!-- Top users -->
  <div class="bg-white/[0.03] border border-white/5 rounded-2xl p-5">
    <h3 class="text-sm font-bold mb-4">Top Users by Pipeline Value</h3>
    <?php if (empty($topUsers)): ?>
    <p class="text-slate-600 text-sm text-center py-8">No CRM data yet.</p>
    <?php else: ?>
    <div class="space-y-2.5">
      <?php foreach ($topUsers as $tu): ?>
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-white/5 flex items-center justify-center text-xs font-bold text-white shrink-0">
          <?=htmlspecialchars(strtoupper(mb_substr($tu['full_name'] ?? '?', 0, 1)))?>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-white truncate"><?=htmlspecialchars($tu['full_name'] ?? 'User #'.(int)$tu['id'])?></p>
          <p class="text-[11px] text-slate-600 truncate">
            <?=htmlspecialchars($tu['email'] ?? '')?>
            <?php if (!empty($tu['plan'])):?>
            <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded font-bold"
                  style="background:rgba(139,92,246,.12);color:#a78bfa;"><?=strtoupper($tu['plan'])?></span>
            <?php endif;?>
          </p>
        </div>
        <div class="text-right shrink-0">
          <p class="text-sm font-bold text-white">$<?=number_format((float)$tu['pipeline_value'], 0)?></p>
          <p class="text-[11px] text-slate-600"><?=$tu['won_count']?> won &middot; <?=$tu['clients']?> clients</p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Recent activity across platform -->
<div class="bg-white/[0.03] border border-white/5 rounded-2xl p-5">
  <h3 class="text-sm font-bold mb-4">Recent Activity <span class="text-[10px] text-slate-600 ml-1">(last 25)</span></h3>
  <?php if (empty($recentActivity)): ?>
    <p class="text-center py-8 text-slate-600 text-sm">No activity yet.</p>
  <?php else: ?>
    <div class="space-y-0">
      <?php foreach ($recentActivity as $a): ?>
        <div class="flex items-center gap-3 py-2.5 border-b border-white/5 last:border-0">
          <div class="w-7 h-7 rounded-lg bg-white/5 flex items-center justify-center text-[11px] text-slate-400 shrink-0">
            <i class="fa-solid fa-clock"></i>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm text-white truncate"><?=htmlspecialchars($a['title'])?></p>
            <p class="text-[11px] text-slate-600 truncate">
              <span class="text-slate-500"><?=htmlspecialchars($a['full_name'] ?? 'User')?></span>
              <?php if ($a['client_name']):?>&middot; <span class="text-slate-500"><?=htmlspecialchars($a['client_name'])?></span><?php endif;?>
              &middot; <?=date('M j, Y · g:i A', strtotime($a['created_at']))?>
            </p>
          </div>
          <span class="text-[10px] text-slate-700 capitalize shrink-0"><?=htmlspecialchars($a['activity_type'])?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php';
