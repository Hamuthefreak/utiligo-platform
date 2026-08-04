<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site_templates.php';

require_login();
$user = current_user();
$uid  = (int)$user['id'];
$plan = $user['plan'] ?? 'free';
$pdo  = get_platform_db();

$site_id = (int)($_GET['site_id'] ?? 0);
if (!$site_id) { header('Location: /portal/my_sites.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM utiligo_generated_sites WHERE id = ? AND user_id = ?");
$stmt->execute([$site_id, $uid]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$site) { header('Location: /portal/my_sites.php'); exit; }

$allTpls = get_all_site_templates();
$tplKey  = $site['template_name'] ?? 'modern';
$tpl     = $allTpls[$tplKey] ?? $allTpls['modern'];
$isDark  = !empty($tpl['dark']);

$hasSlug   = !empty($site['public_slug']);
$isActive  = $hasSlug && !empty($site['link_active']);
$expiresTs = ($hasSlug && !empty($site['link_expires_at'])) ? strtotime($site['link_expires_at']) : null;
$isExpired = $isActive && $expiresTs && $expiresTs < time();
$isLive    = $isActive && !$isExpired;
$publicUrl = $hasSlug ? 'https://utiligo.ca/s/' . $site['public_slug'] : null;

// ── Ensure site_view_log table exists ─────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utiligo_site_view_log (
          id         BIGINT AUTO_INCREMENT PRIMARY KEY,
          site_id    INT NOT NULL,
          viewed_at  DATETIME NOT NULL DEFAULT NOW(),
          referrer   VARCHAR(300) DEFAULT NULL,
          country    VARCHAR(4)   DEFAULT NULL,
          device     ENUM('desktop','mobile','tablet') DEFAULT 'desktop',
          INDEX idx_site_date (site_id, viewed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (\Throwable $e) {}

// ── Last 30 days daily views ───────────────────────────────────────────────────
$dailyViews = [];
for ($i = 29; $i >= 0; $i--) {
    $dailyViews[date('Y-m-d', strtotime("-$i days"))] = 0;
}
try {
    $dvStmt = $pdo->prepare("
        SELECT DATE(viewed_at) AS day, COUNT(*) AS cnt
        FROM utiligo_site_view_log
        WHERE site_id = ? AND viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(viewed_at)
    ");
    $dvStmt->execute([$site_id]);
    foreach ($dvStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($dailyViews[$row['day']])) {
            $dailyViews[$row['day']] = (int)$row['cnt'];
        }
    }
} catch (\Throwable $e) {}

// ── Device breakdown ──────────────────────────────────────────────────────────
$devices = ['desktop'=>0,'mobile'=>0,'tablet'=>0];
try {
    $dStmt = $pdo->prepare("
        SELECT device, COUNT(*) AS cnt FROM utiligo_site_view_log
        WHERE site_id = ? GROUP BY device
    ");
    $dStmt->execute([$site_id]);
    foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $devices[$row['device']] = (int)$row['cnt'];
    }
} catch (\Throwable $e) {}

// ── Top referrers ─────────────────────────────────────────────────────────────
$referrers = [];
try {
    $rStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(referrer,''), 'Direct') AS ref, COUNT(*) AS cnt
        FROM utiligo_site_view_log
        WHERE site_id = ?
        GROUP BY ref ORDER BY cnt DESC LIMIT 5
    ");
    $rStmt->execute([$site_id]);
    $referrers = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// ── Summary stats ─────────────────────────────────────────────────────────────
$totalViews  = (int)($site['view_count'] ?? 0);
$last7       = array_sum(array_slice($dailyViews, -7));
$last30      = array_sum($dailyViews);
$peakDay     = $dailyViews ? max($dailyViews) : 0;
$peakDate    = $peakDay ? array_search($peakDay, $dailyViews) : null;
$totalDevice = array_sum($devices) ?: 1;

$chartLabels = json_encode(array_map(fn($d) => date('M j', strtotime($d)), array_keys($dailyViews)));
$chartData   = json_encode(array_values($dailyViews));
$chartColor  = htmlspecialchars($tpl['primary']);

$pageTitle = 'Analytics: ' . htmlspecialchars($site['business_name']) . ' — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
.analytics-hero {
  border-radius: 20px;
  padding: 28px;
  margin-bottom: 24px;
  background: linear-gradient(135deg, <?= $tpl['secondary'] ?> 0%, <?= $tpl['primary'] ?> 100%);
  position: relative;
  overflow: hidden;
}
.analytics-hero::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,.35);
  pointer-events: none;
}
.analytics-hero > * { position: relative; z-index: 1; }

.stat-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 16px;
  padding: 20px;
}
.stat-label {
  font-size: .65rem;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: #64748b;
  margin-bottom: 6px;
}
.stat-value {
  font-size: 2rem;
  font-weight: 900;
  color: #f1f5f9;
  line-height: 1;
}
.stat-sub {
  font-size: .7rem;
  color: #475569;
  margin-top: 4px;
}

.chart-wrap {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 16px;
  padding: 22px;
  margin-bottom: 20px;
}
.chart-title {
  font-size: .75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: #64748b;
  margin-bottom: 16px;
}

.device-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid rgba(255,255,255,.05);
}
.device-row:last-child { border-bottom: none; }
.device-bar-track {
  flex: 1;
  height: 6px;
  border-radius: 3px;
  background: rgba(255,255,255,.06);
  overflow: hidden;
}
.device-bar-fill {
  height: 100%;
  border-radius: 3px;
  transition: width .6s cubic-bezier(.4,0,.2,1);
}

.ref-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid rgba(255,255,255,.05);
  font-size: .8rem;
}
.ref-row:last-child { border-bottom: none; }
.ref-name {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: #cbd5e1;
}
.ref-count {
  font-weight: 700;
  color: #f1f5f9;
  min-width: 32px;
  text-align: right;
}

.notice-no-data {
  text-align: center;
  padding: 32px 16px;
  color: #475569;
  font-size: .85rem;
}
</style>

<!-- Back link -->
<div class="mb-6">
  <a href="/portal/my_sites.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-white text-sm font-semibold transition">
    <i class="fa-solid fa-arrow-left text-xs"></i> Back to My Sites
  </a>
</div>

<!-- Hero -->
<div class="analytics-hero">
  <div class="flex items-start justify-between flex-wrap gap-4">
    <div>
      <p class="text-xs font-bold uppercase tracking-widest mb-1" style="color:<?= $isDark ? 'rgba(255,255,255,.5)' : 'rgba(0,0,0,.45)' ?>">
        <?= htmlspecialchars($tpl['label']) ?> · <?= htmlspecialchars($tpl['category']) ?>
      </p>
      <h1 class="text-2xl font-black" style="color:#fff; text-shadow:0 2px 8px rgba(0,0,0,.5);">
        <?= htmlspecialchars($site['business_name']) ?>
      </h1>
      <?php if ($site['business_city']): ?>
        <p class="text-sm mt-0.5" style="color:rgba(255,255,255,.6)">
          <i class="fa-solid fa-location-dot mr-1 text-xs"></i><?= htmlspecialchars($site['business_city']) ?>
        </p>
      <?php endif; ?>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
      <?php if ($isLive): ?>
        <span class="text-xs font-bold px-3 py-1 rounded-full" style="background:rgba(16,185,129,.2);color:#6ee7b7;border:1px solid rgba(16,185,129,.3)">● Live</span>
        <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank"
           class="text-xs font-bold px-3 py-1.5 rounded-xl transition"
           style="background:rgba(255,255,255,.15);color:#fff;">
          <i class="fa-solid fa-arrow-up-right-from-square mr-1 text-[10px]"></i>Visit Site
        </a>
      <?php elseif ($isExpired): ?>
        <span class="text-xs font-bold px-3 py-1 rounded-full" style="background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3)">⏱ Expired</span>
      <?php else: ?>
        <span class="text-xs font-bold px-3 py-1 rounded-full" style="background:rgba(255,255,255,.08);color:#64748b;">Offline</span>
      <?php endif; ?>
      <a href="/portal/site_editor.php?site_id=<?= $site_id ?>"
         class="text-xs font-bold px-3 py-1.5 rounded-xl transition"
         style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.8);">
        <i class="fa-solid fa-pen mr-1 text-[10px]"></i>Edit
      </a>
    </div>
  </div>
</div>

<!-- Summary stat cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
  <div class="stat-card">
    <p class="stat-label">Total Views</p>
    <p class="stat-value"><?= number_format($totalViews) ?></p>
    <p class="stat-sub">All time</p>
  </div>
  <div class="stat-card">
    <p class="stat-label">Last 7 Days</p>
    <p class="stat-value"><?= number_format($last7) ?></p>
    <p class="stat-sub">Tracked views</p>
  </div>
  <div class="stat-card">
    <p class="stat-label">Last 30 Days</p>
    <p class="stat-value"><?= number_format($last30) ?></p>
    <p class="stat-sub">Tracked views</p>
  </div>
  <div class="stat-card">
    <p class="stat-label">Peak Day</p>
    <p class="stat-value"><?= number_format($peakDay) ?></p>
    <p class="stat-sub"><?= $peakDate ? date('M j', strtotime($peakDate)) : 'N/A' ?></p>
  </div>
</div>

<!-- Daily views chart -->
<div class="chart-wrap">
  <p class="chart-title"><i class="fa-solid fa-chart-line mr-1"></i>Daily Views — Last 30 Days</p>
  <?php if ($last30 > 0): ?>
    <canvas id="viewsChart" height="90"></canvas>
  <?php else: ?>
    <div class="notice-no-data">
      <i class="fa-solid fa-chart-line text-2xl mb-2 block opacity-30"></i>
      No view data logged yet. Views will appear here once visitors hit the live site.
    </div>
  <?php endif; ?>
</div>

<!-- Device breakdown + Referrers (side by side on md+) -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">

  <!-- Device -->
  <div class="chart-wrap" style="margin-bottom:0;">
    <p class="chart-title"><i class="fa-solid fa-mobile-screen-button mr-1"></i>Device Breakdown</p>
    <?php
      $devColors = ['desktop'=>'#6366f1','mobile'=>'#10b981','tablet'=>'#f59e0b'];
      $devIcons  = ['desktop'=>'fa-desktop','mobile'=>'fa-mobile-alt','tablet'=>'fa-tablet-alt'];
    ?>
    <?php if ($totalViews > 0): foreach (['desktop','mobile','tablet'] as $dev): ?>
      <div class="device-row">
        <i class="fa-solid <?= $devIcons[$dev] ?> text-xs" style="width:14px;color:<?= $devColors[$dev] ?>;"></i>
        <span style="font-size:.78rem;color:#cbd5e1;min-width:54px;"><?= ucfirst($dev) ?></span>
        <div class="device-bar-track">
          <div class="device-bar-fill" style="width:<?= $totalDevice > 0 ? round($devices[$dev]/$totalDevice*100) : 0 ?>%;background:<?= $devColors[$dev] ?>;"></div>
        </div>
        <span style="font-size:.75rem;font-weight:700;color:#f1f5f9;min-width:28px;text-align:right;"><?= $devices[$dev] ?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="notice-no-data" style="padding:20px;">No data yet</div>
    <?php endif; ?>
  </div>

  <!-- Referrers -->
  <div class="chart-wrap" style="margin-bottom:0;">
    <p class="chart-title"><i class="fa-solid fa-share-nodes mr-1"></i>Top Referrers</p>
    <?php if (!empty($referrers)): ?>
      <?php
        $maxRef = max(array_column($referrers, 'cnt')) ?: 1;
        foreach ($referrers as $ref):
          $pct = round($ref['cnt'] / $maxRef * 100);
      ?>
      <div class="ref-row">
        <i class="fa-solid fa-link" style="font-size:.6rem;color:#475569;"></i>
        <span class="ref-name"><?= htmlspecialchars($ref['ref']) ?></span>
        <div style="width:80px;height:4px;border-radius:2px;background:rgba(255,255,255,.06);overflow:hidden;">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $chartColor ?>;border-radius:2px;"></div>
        </div>
        <span class="ref-count"><?= number_format($ref['cnt']) ?></span>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="notice-no-data" style="padding:20px;">No referrer data yet</div>
    <?php endif; ?>
  </div>

</div>

<!-- Site info footer -->
<div class="chart-wrap">
  <p class="chart-title"><i class="fa-solid fa-circle-info mr-1"></i>Site Details</p>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-3" style="font-size:.8rem;">
    <?php
      $details = [
        ['label'=>'Business','value'=>$site['business_name']     ?? '—'],
        ['label'=>'Category','value'=>$site['business_category'] ?? '—'],
        ['label'=>'City',    'value'=>$site['business_city']     ?? '—'],
        ['label'=>'Phone',   'value'=>$site['business_phone']    ?? '—'],
        ['label'=>'Email',   'value'=>$site['business_email']    ?? '—'],
        ['label'=>'Template','value'=>$tpl['label']],
        ['label'=>'Created', 'value'=>date('M j, Y', strtotime($site['created_at']))],
        ['label'=>'Status',  'value'=>$isLive ? 'Live' : ($isExpired ? 'Expired' : 'Offline')],
      ];
      foreach ($details as $d):
    ?>
      <div>
        <p class="stat-label" style="margin-bottom:2px;"><?= $d['label'] ?></p>
        <p style="color:#cbd5e1;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($d['value']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($isLive && $publicUrl): ?>
    <div class="mt-4 pt-4" style="border-top:1px solid rgba(255,255,255,.06);">
      <p class="stat-label" style="margin-bottom:4px;">Public URL</p>
      <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank"
         class="text-sm font-semibold hover:text-white transition" style="color:#6366f1;">
        <i class="fa-solid fa-arrow-up-right-from-square mr-1 text-xs"></i><?= htmlspecialchars($publicUrl) ?>
      </a>
    </div>
  <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const canvas = document.getElementById('viewsChart');
  if (!canvas) return;
  const labels = <?= $chartLabels ?>;
  const data   = <?= $chartData ?>;
  const color  = '<?= $chartColor ?>';

  // Build gradient
  const ctx = canvas.getContext('2d');
  const grad = ctx.createLinearGradient(0, 0, 0, 200);
  grad.addColorStop(0,   color + '55');
  grad.addColorStop(1,   color + '00');

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Views',
        data,
        fill: true,
        backgroundColor: grad,
        borderColor: color,
        borderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 5,
        pointBackgroundColor: color,
        tension: 0.35,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1e293b',
          borderColor: 'rgba(255,255,255,.1)',
          borderWidth: 1,
          titleColor: '#94a3b8',
          bodyColor: '#f1f5f9',
          callbacks: {
            label: ctx => ' ' + ctx.parsed.y + ' views'
          }
        }
      },
      scales: {
        x: {
          ticks: { color: '#475569', font: { size: 10 }, maxTicksLimit: 10 },
          grid:  { color: 'rgba(255,255,255,.04)' }
        },
        y: {
          beginAtZero: true,
          ticks: { color: '#475569', font: { size: 10 }, precision: 0 },
          grid:  { color: 'rgba(255,255,255,.06)' }
        }
      }
    }
  });
})();
</script>
