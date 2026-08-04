<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site_templates.php';

require_login();
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);
$pdo     = get_platform_db();

$stmt = $pdo->prepare("SELECT * FROM utiligo_generated_sites WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$sites = $stmt->fetchAll();

$allTpls     = get_all_site_templates();
$totalSites  = count($sites);
$activeSites = count(array_filter($sites, fn($s) => !empty($s['link_active'])));
$thisMonth   = count(array_filter($sites, fn($s) => date('Y-m', strtotime($s['created_at'])) === date('Y-m')));
$totalViews  = array_sum(array_column($sites, 'view_count'));

$site_limit = plan_site_limit($plan);
$sl_pct     = ($site_limit > 0) ? min(100, round(($activeSites / $site_limit) * 100)) : 0;
$sl_colour  = $sl_pct >= 90 ? 'bg-red-500' : ($sl_pct >= 70 ? 'bg-amber-500' : 'bg-white/60');
$sl_hit     = $site_limit > 0 && $activeSites >= $site_limit;

$pageTitle = 'My Sites — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<style>
/* ── Card grid ────────────────────────────────────────────────────────── */
.sites-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
}

/* ── Site card ────────────────────────────────────────────────────────── */
.site-card {
  position: relative;
  border-radius: 20px;
  overflow: hidden;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.08);
  transition: border-color .2s, box-shadow .2s, transform .2s;
  display: flex;
  flex-direction: column;
}
.site-card:hover {
  border-color: rgba(255,255,255,.18);
  box-shadow: 0 8px 40px rgba(0,0,0,.45);
  transform: translateY(-3px);
}

/* ── Template swatch header ───────────────────────────────────────────── */
.card-swatch {
  height: 96px;
  position: relative;
  flex-shrink: 0;
  overflow: hidden;
}
.card-swatch-inner {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 16px;
}
.swatch-bubble {
  width: 28px; height: 28px;
  border-radius: 50%;
  border: 2px solid rgba(255,255,255,.25);
  flex-shrink: 0;
}
.swatch-label {
  font-size: .65rem;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  opacity: .85;
  text-shadow: 0 1px 4px rgba(0,0,0,.5);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 130px;
}
.card-status-badge {
  position: absolute;
  top: 10px;
  right: 10px;
  font-size: .6rem;
  font-weight: 800;
  padding: 3px 9px;
  border-radius: 999px;
  letter-spacing: .05em;
  text-transform: uppercase;
  backdrop-filter: blur(6px);
}
.card-views-badge {
  position: absolute;
  bottom: 10px;
  left: 10px;
  font-size: .6rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(0,0,0,.45);
  color: rgba(255,255,255,.8);
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  gap: 4px;
}

/* ── Card body ────────────────────────────────────────────────────────── */
.card-body {
  padding: 14px 16px 10px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.card-title {
  font-size: .95rem;
  font-weight: 800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: #f1f5f9;
}
.card-meta {
  font-size: .7rem;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.card-link {
  font-size: .67rem;
  color: #475569;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
  transition: color .15s;
  text-decoration: none;
}
.card-link:hover { color: #94a3b8; }

/* ── Action strip ─────────────────────────────────────────────────────── */
.card-actions {
  padding: 10px 12px 12px;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  border-top: 1px solid rgba(255,255,255,.05);
  margin-top: auto;
}
.ca-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: .68rem;
  font-weight: 700;
  padding: 5px 10px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  white-space: nowrap;
  transition: background .15s, color .15s;
  text-decoration: none;
}
.ca-btn-ghost {
  background: rgba(255,255,255,.06);
  color: #94a3b8;
}
.ca-btn-ghost:hover { background: rgba(255,255,255,.12); color: #f1f5f9; }
.ca-btn-white {
  background: rgba(255,255,255,.1);
  color: #e2e8f0;
}
.ca-btn-white:hover { background: rgba(255,255,255,.18); color: #fff; }
.ca-btn-danger {
  background: rgba(239,68,68,.08);
  color: #f87171;
  margin-left: auto;
}
.ca-btn-danger:hover { background: rgba(239,68,68,.18); }
.ca-btn-analytics {
  background: rgba(99,102,241,.12);
  color: #a5b4fc;
}
.ca-btn-analytics:hover { background: rgba(99,102,241,.22); color: #c7d2fe; }

/* ── Empty state ──────────────────────────────────────────────────────── */
.empty-state {
  border: 1.5px dashed rgba(255,255,255,.1);
  border-radius: 20px;
  padding: 60px 20px;
  text-align: center;
  grid-column: 1/-1;
}
</style>

<!-- Page header -->
<div class="flex items-center justify-between mb-8 flex-wrap gap-4">
  <div>
    <h1 class="text-3xl font-black tracking-tight">My Sites</h1>
    <p class="text-slate-500 text-sm mt-1">Manage, share &amp; download your generated websites.</p>
  </div>
  <a href="/portal/generate.php"
     class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 active:scale-95 text-black px-5 py-2.5 rounded-xl font-bold text-sm transition-all">
    <i class="fa-solid fa-bolt text-xs"></i> Generate New Site
  </a>
</div>

<!-- Stats strip -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-widest mb-1">Total</p>
    <p class="text-3xl font-black"><?= $totalSites ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-widest mb-1">Live</p>
    <p class="text-3xl font-black text-white"><?= $activeSites ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-widest mb-1">This Month</p>
    <p class="text-3xl font-black"><?= $thisMonth ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-widest mb-1">Total Views</p>
    <p class="text-3xl font-black text-white"><?= number_format($totalViews) ?></p>
  </div>
</div>

<!-- Site limit bar -->
<?php if ($site_limit > 0): ?>
<div class="glass rounded-2xl p-4 border <?= $sl_hit ? 'border-red-500/30' : 'border-white/5' ?> mb-8">
  <div class="flex items-center justify-between mb-2">
    <div class="flex items-center gap-2">
      <i class="fa-solid fa-globe text-slate-400 text-xs"></i>
      <span class="text-xs font-semibold text-slate-300">Active Site Slots</span>
    </div>
    <div class="flex items-center gap-3">
      <span class="text-xs font-bold <?= $sl_hit ? 'text-red-400' : 'text-white' ?>">
        <?= $activeSites ?> / <?= $site_limit ?> used
      </span>
      <?php if ($sl_hit && $plan === 'pro'): ?>
      <a href="/portal/billing.php?upgrade=1&plan=entrepreneur"
         class="text-xs bg-white hover:bg-slate-200 text-black px-3 py-1 rounded-full font-bold">
        <i class="fa-solid fa-rocket mr-1"></i>Upgrade
      </a>
      <?php elseif ($sl_hit && $plan === 'free'): ?>
      <a href="/portal/billing.php?upgrade=1"
         class="text-xs bg-white hover:bg-slate-200 text-black px-3 py-1 rounded-full font-bold">Upgrade</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
    <div class="h-1.5 rounded-full transition-all <?= $sl_colour ?>" style="width:<?= $sl_pct ?>%"></div>
  </div>
  <?php if ($sl_hit): ?>
  <p class="text-xs text-red-400 mt-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Limit reached. Deactivate or delete a site to generate a new one.</p>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="mb-8"></div>
<?php endif; ?>

<!-- Sites grid -->
<div id="sitesList" class="sites-grid">

<?php if (empty($sites)): ?>
  <div class="empty-state">
    <div class="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-4">
      <i class="fa-solid fa-globe text-slate-500 text-2xl"></i>
    </div>
    <p class="font-bold text-slate-300 mb-1">No sites yet</p>
    <p class="text-slate-500 text-sm mb-5">Generate your first site in under 60 seconds.</p>
    <a href="/portal/generate.php"
       class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-6 py-2.5 rounded-xl text-sm font-bold">
      <i class="fa-solid fa-bolt"></i> Generate Now
    </a>
  </div>

<?php else: foreach ($sites as $site):
  $hasSlug    = !empty($site['public_slug']);
  $isActive   = $hasSlug && !empty($site['link_active']);
  $expiresTs  = ($hasSlug && !empty($site['link_expires_at'])) ? strtotime($site['link_expires_at']) : null;
  $isExpired  = $isActive && $expiresTs && $expiresTs < time();
  $isLive     = $isActive && !$isExpired;
  $publicUrl  = $hasSlug ? '/s/' . $site['public_slug'] : null;
  $zipUrl     = !empty($site['zip_file_path']) ? $site['zip_file_path'] : null;
  $expiresIso = $expiresTs ? date('c', $expiresTs) : null;
  $tplKey     = $site['template_name'] ?? 'modern';
  $tpl        = $allTpls[$tplKey] ?? $allTpls['modern'];
  $views      = (int)($site['view_count'] ?? 0);
  $fullPublicUrl = $isLive && $publicUrl ? 'https://utiligo.ca' . $publicUrl : null;

  $diff = $expiresTs ? ($expiresTs - time()) : null;
  if ($diff === null)         $exLabel = null;
  elseif ($diff <= 0)        $exLabel = 'Expired';
  elseif ($diff < 3600)      $exLabel = 'Expires in ' . floor($diff/60) . 'm';
  elseif ($diff < 86400)     $exLabel = 'Expires in ' . floor($diff/3600) . 'h';
  else                       $exLabel = 'Expires ' . date('M j', $expiresTs);

  $regenUrl = '/portal/generate.php?'
    . 'name='      . urlencode($site['business_name']     ?? '')
    . '&category=' . urlencode($site['business_category'] ?? '')
    . '&city='     . urlencode($site['business_city']     ?? '')
    . '&phone='    . urlencode($site['business_phone']    ?? '')
    . '&email='    . urlencode($site['business_email']    ?? '');

  // swatch text color: white on dark templates, dark on light
  $isDark  = !empty($tpl['dark']);
  $swatchText = $isDark ? '#ffffff' : '#0f172a';
?>

  <div class="site-card" data-site-id="<?= (int)$site['id'] ?>">

    <!-- Template swatch -->
    <div class="card-swatch" style="background:linear-gradient(135deg, <?= htmlspecialchars($tpl['secondary']) ?> 0%, <?= htmlspecialchars($tpl['primary']) ?> 100%);">
      <div class="card-swatch-inner">
        <!-- accent + primary bubbles -->
        <div class="swatch-bubble" style="background:<?= htmlspecialchars($tpl['accent']) ?>;"></div>
        <div class="swatch-bubble" style="background:<?= htmlspecialchars($tpl['primary']) ?>;"></div>
        <span class="swatch-label" style="color:<?= $swatchText ?>"><?= htmlspecialchars($tpl['label']) ?></span>
      </div>

      <!-- Status badge -->
      <?php if ($isLive): ?>
        <span class="card-status-badge" style="background:rgba(16,185,129,.2);color:#6ee7b7;border:1px solid rgba(16,185,129,.3);">● Live</span>
      <?php elseif ($isExpired): ?>
        <span class="card-status-badge" style="background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3);">⏱ Expired</span>
      <?php else: ?>
        <span class="card-status-badge" style="background:rgba(255,255,255,.06);color:#64748b;border:1px solid rgba(255,255,255,.08);">Offline</span>
      <?php endif; ?>

      <!-- Views badge -->
      <?php if ($views > 0): ?>
        <div class="card-views-badge">
          <i class="fa-solid fa-eye" style="font-size:.55rem;"></i>
          <?= number_format($views) ?> view<?= $views !== 1 ? 's' : '' ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Body -->
    <div class="card-body">
      <p class="card-title"><?= htmlspecialchars($site['business_name']) ?></p>
      <div class="card-meta">
        <span><?= htmlspecialchars($tpl['category']) ?></span>
        <span>·</span>
        <span><?= date('M j, Y', strtotime($site['created_at'])) ?></span>
        <?php if ($exLabel): ?>
          <span>·</span>
          <span class="<?= $isExpired ? 'text-red-400' : '' ?>"
                <?= $expiresIso ? 'data-expires-at="' . htmlspecialchars($expiresIso) . '"' : '' ?>>
            <i class="fa-regular fa-clock mr-0.5"></i><?= $exLabel ?>
          </span>
        <?php endif; ?>
      </div>
      <?php if ($publicUrl && $isLive): ?>
        <a href="<?= $publicUrl ?>" target="_blank" class="card-link">
          <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.55rem;"></i>
          utiligo.ca<?= $publicUrl ?>
        </a>
      <?php endif; ?>
    </div>

    <!-- Action strip -->
    <div class="card-actions">

      <!-- Analytics (always shown) -->
      <a href="/portal/site_analytics.php?site_id=<?= (int)$site['id'] ?>"
         class="ca-btn ca-btn-analytics" title="View analytics">
        <i class="fa-solid fa-chart-line" style="font-size:.6rem;"></i> Analytics
      </a>

      <!-- Regenerate -->
      <a href="<?= htmlspecialchars($regenUrl) ?>" class="ca-btn ca-btn-ghost" title="Regenerate">
        <i class="fa-solid fa-rotate" style="font-size:.6rem;"></i> Regen
      </a>

      <!-- Edit -->
      <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>" class="ca-btn ca-btn-ghost">
        <i class="fa-solid fa-pen" style="font-size:.6rem;"></i> Edit
      </a>

      <?php if ($publicUrl && $isLive): ?>
        <!-- Preview -->
        <a href="<?= $publicUrl ?>" target="_blank" class="ca-btn ca-btn-white">
          <i class="fa-solid fa-eye" style="font-size:.6rem;"></i> Preview
        </a>
        <!-- QR -->
        <button class="qr-btn ca-btn ca-btn-ghost"
                data-url="<?= htmlspecialchars($fullPublicUrl) ?>"
                data-name="<?= htmlspecialchars($site['business_name']) ?>">
          <i class="fa-solid fa-qrcode" style="font-size:.6rem;"></i> QR
        </button>
      <?php endif; ?>

      <?php if ($isLive): ?>
        <button class="deactivate-btn ca-btn ca-btn-ghost" data-id="<?= (int)$site['id'] ?>" title="Deactivate">
          <i class="fa-solid fa-link-slash" style="font-size:.6rem;"></i> Deactivate
        </button>
      <?php elseif ($hasSlug && !$isLive): ?>
        <button class="reactivate-btn ca-btn ca-btn-ghost" data-id="<?= (int)$site['id'] ?>" title="Reactivate">
          <i class="fa-solid fa-rotate-right" style="font-size:.6rem;"></i> Activate
        </button>
      <?php endif; ?>

      <?php if ($is_paid && $hasSlug && ($isExpired || !$isLive)): ?>
        <button class="extend-btn ca-btn ca-btn-ghost" data-id="<?= (int)$site['id'] ?>">
          <i class="fa-solid fa-clock-rotate-left" style="font-size:.6rem;"></i> Extend
        </button>
      <?php endif; ?>

      <?php if ($zipUrl): ?>
        <a href="<?= htmlspecialchars($zipUrl) ?>" class="ca-btn ca-btn-ghost">
          <i class="fa-solid fa-download" style="font-size:.6rem;"></i> ZIP
        </a>
      <?php endif; ?>

      <!-- Delete -->
      <button class="delete-btn ca-btn ca-btn-danger" data-id="<?= (int)$site['id'] ?>" title="Delete permanently">
        <i class="fa-solid fa-trash" style="font-size:.6rem;"></i>
      </button>

    </div>
  </div>

<?php endforeach; endif; ?>
</div>

<!-- QR Modal -->
<div id="qrModal"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4"
     aria-modal="true" aria-hidden="true" role="dialog" aria-label="QR Code">
  <div class="glass rounded-2xl border border-white/10 p-6 w-full max-w-xs text-center relative">
    <button id="qrModalClose"
            class="absolute top-3 right-3 w-7 h-7 rounded-full bg-white/8 hover:bg-white/15 flex items-center justify-center text-slate-400 hover:text-white transition"
            aria-label="Close">
      <i class="fa-solid fa-xmark text-xs"></i>
    </button>
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">QR Code</p>
    <div class="flex items-center justify-center mb-4">
      <div class="bg-white rounded-xl p-2 inline-flex">
        <img id="qrModalImg" src="" alt="" width="220" height="220" class="block rounded">
      </div>
    </div>
    <p id="qrModalUrl" class="text-[10px] text-slate-500 break-all mb-4"></p>
    <a id="qrModalDownload" href="#" download="qrcode.png"
       class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black text-xs font-bold px-5 py-2.5 rounded-xl transition">
      <i class="fa-solid fa-download text-[10px]"></i> Download PNG
    </a>
  </div>
</div>

<script src="/assets/js/my_sites.js?v=v603"></script>
