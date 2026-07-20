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

$stmt = $pdo->prepare("SELECT * FROM utiligo_generated_sites WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC");
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
@keyframes shimmer {
  0%   { background-position: -600px 0; }
  100% { background-position:  600px 0; }
}
.skeleton {
  border-radius: 12px;
  background: linear-gradient(90deg,rgba(255,255,255,.04) 25%,rgba(255,255,255,.09) 50%,rgba(255,255,255,.04) 75%);
  background-size: 600px 100%;
  animation: shimmer 1.4s infinite linear;
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

<!-- Site limit bar (paid plans) -->
<?php if ($site_limit > 0): ?>
<div class="glass rounded-2xl p-4 border <?= $sl_hit ? 'border-red-500/30' : 'border-white/5' ?> mb-8">
  <div class="flex items-center justify-between mb-2">
    <div class="flex items-center gap-2">
      <i class="fa-solid fa-globe text-slate-400 text-xs"></i>
      <span class="text-xs font-semibold text-slate-300">Active Site Slots</span>
    </div>
    <div class="flex items-center gap-3">
      <span class="text-xs font-bold <?= $sl_hit ? 'text-red-400' : 'text-white' ?>"
            data-active-slots="<?= $activeSites ?>"
            data-slot-limit="<?= $site_limit ?>">
        <?= $activeSites ?> / <?= $site_limit ?> used
      </span>
      <?php if ($sl_hit && $plan === 'pro'): ?>
      <a href="/portal/billing.php?upgrade=1&plan=entrepreneur"
         class="text-xs bg-white hover:bg-slate-200 text-black px-3 py-1 rounded-full font-bold">
        <i class="fa-solid fa-rocket mr-1"></i>Upgrade
      </a>
      <?php elseif ($sl_hit && $plan === 'free'): ?>
      <a href="/portal/billing.php?upgrade=1"
         class="text-xs bg-white hover:bg-slate-200 text-black px-3 py-1 rounded-full font-bold">
        Upgrade
      </a>
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

<!-- Sites list -->
<div id="sitesList" class="space-y-3">

<?php if (empty($sites)): ?>
  <div class="glass rounded-2xl p-14 text-center border border-dashed border-white/10">
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

  $diff = $expiresTs ? ($expiresTs - time()) : null;
  if ($diff === null)         $exLabel = null;
  elseif ($diff <= 0)        $exLabel = 'Expired';
  elseif ($diff < 3600)      $exLabel = 'Expires in ' . floor($diff/60) . 'm';
  elseif ($diff < 86400)     $exLabel = 'Expires in ' . floor($diff/3600) . 'h ' . floor(($diff%3600)/60) . 'm';
  else                       $exLabel = 'Expires ' . date('M j', $expiresTs);

  $fullPublicUrl = $isLive && $publicUrl ? 'https://utiligo.ca' . $publicUrl : null;
?>

  <div class="group glass rounded-2xl border <?= $isLive ? 'border-white/15' : 'border-white/5' ?> hover:border-white/20 transition-all"
       data-site-id="<?= (int)$site['id'] ?>">
    <div class="flex items-center gap-4 p-4 flex-wrap sm:flex-nowrap">

      <!-- Template colour dot -->
      <div class="w-11 h-11 rounded-xl shrink-0 flex items-center justify-center"
           style="background:linear-gradient(135deg,<?= $tpl['secondary'] ?> 0%,<?= $tpl['primary'] ?> 100%);">
        <i class="fa-solid fa-globe text-white/70 text-sm"></i>
      </div>

      <!-- Main info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <h3 class="font-bold text-base truncate"><?= htmlspecialchars($site['business_name']) ?></h3>

          <?php if ($isLive): ?>
            <span class="status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/10 text-white ring-1 ring-white/20">● Live</span>
          <?php elseif ($isExpired): ?>
            <span class="status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30">⏱ Expired</span>
          <?php else: ?>
            <span class="status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/5 text-slate-500">Offline</span>
          <?php endif; ?>

          <span class="text-[10px] text-slate-600 px-2 py-0.5 rounded-full bg-white/5"><?= htmlspecialchars($tpl['label']) ?></span>

          <?php if ($views > 0): ?>
            <span class="text-[10px] text-slate-400 px-2 py-0.5 rounded-full bg-white/5 inline-flex items-center gap-1">
              <i class="fa-solid fa-eye text-[9px]"></i><?= number_format($views) ?> view<?= $views !== 1 ? 's' : '' ?>
            </span>
          <?php endif; ?>
        </div>

        <div class="flex items-center gap-3 mt-1 flex-wrap">
          <span class="text-xs text-slate-500">Generated <?= date('M j, Y', strtotime($site['created_at'])) ?></span>
          <?php if ($expiresIso && $exLabel): ?>
            <span class="expiry-label text-xs <?= $isExpired ? 'text-red-400' : 'text-slate-500' ?>"
                  data-expires-at="<?= htmlspecialchars($expiresIso) ?>">
              <i class="fa-regular fa-clock mr-0.5 text-[10px]"></i><?= $exLabel ?>
            </span>
          <?php endif; ?>
          <?php if ($publicUrl && $isLive): ?>
            <a href="<?= $publicUrl ?>" target="_blank"
               class="text-xs text-slate-400 hover:text-white inline-flex items-center gap-1 truncate max-w-[200px]">
              <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
              utiligo.ca<?= $publicUrl ?>
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center gap-2 shrink-0 flex-wrap">
        <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>"
           class="inline-flex items-center gap-1.5 text-xs bg-white/6 hover:bg-white/12 text-slate-300 px-3 py-1.5 rounded-lg font-semibold transition">
          <i class="fa-solid fa-pen text-[10px]"></i> Edit
        </a>

        <?php if ($publicUrl && $isLive): ?>
          <a href="<?= $publicUrl ?>" target="_blank"
             class="inline-flex items-center gap-1.5 text-xs bg-white/8 hover:bg-white/15 text-white px-3 py-1.5 rounded-lg font-semibold transition">
            <i class="fa-solid fa-eye text-[10px]"></i> Preview
          </a>

          <button class="qr-btn inline-flex items-center gap-1.5 text-xs bg-white/6 hover:bg-white/12 text-slate-300 px-3 py-1.5 rounded-lg font-semibold transition"
                  data-url="<?= htmlspecialchars($fullPublicUrl) ?>"
                  data-name="<?= htmlspecialchars($site['business_name']) ?>"
                  title="Show QR code">
            <i class="fa-solid fa-qrcode text-[10px]"></i> QR
          </button>
        <?php endif; ?>

        <?php if ($isLive): ?>
        <button class="deactivate-btn inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-amber-500/10 text-slate-400 hover:text-amber-400 px-3 py-1.5 rounded-lg font-semibold transition"
                data-id="<?= (int)$site['id'] ?>" title="Deactivate (frees up a slot)">
          <i class="fa-solid fa-link-slash text-[10px]"></i> Deactivate
        </button>
        <?php elseif ($hasSlug && !$isLive): ?>
        <button class="reactivate-btn inline-flex items-center gap-1.5 text-xs bg-white/5 hover:bg-white/12 text-slate-400 hover:text-white px-3 py-1.5 rounded-lg font-semibold transition"
                data-id="<?= (int)$site['id'] ?>" title="Reactivate">
          <i class="fa-solid fa-rotate-right text-[10px]"></i> Activate
        </button>
        <?php endif; ?>

        <?php if ($is_paid && $hasSlug && ($isExpired || !$isLive)): ?>
          <button class="extend-btn inline-flex items-center gap-1.5 text-xs bg-white/6 hover:bg-white/12 text-slate-300 px-3 py-1.5 rounded-lg font-semibold transition"
                  data-id="<?= (int)$site['id'] ?>">
            <i class="fa-solid fa-clock-rotate-left text-[10px]"></i> Extend
          </button>
        <?php endif; ?>

        <?php if ($zipUrl): ?>
          <a href="<?= htmlspecialchars($zipUrl) ?>"
             class="inline-flex items-center gap-1.5 text-xs bg-white/8 hover:bg-white/15 text-white px-3 py-1.5 rounded-lg font-semibold transition">
            <i class="fa-solid fa-download text-[10px]"></i> ZIP
          </a>
        <?php endif; ?>

        <button class="delete-btn inline-flex items-center gap-1 text-xs bg-red-500/8 hover:bg-red-500/20 text-red-500 px-2.5 py-1.5 rounded-lg transition"
                data-id="<?= (int)$site['id'] ?>" title="Delete site permanently">
          <i class="fa-solid fa-trash text-[10px]"></i>
        </button>
      </div>

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
    <a id="qrModalDownload"
       href="#"
       download="qrcode.png"
       class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black text-xs font-bold px-5 py-2.5 rounded-xl transition">
      <i class="fa-solid fa-download text-[10px]"></i> Download PNG
    </a>
  </div>
</div>

<script src="/assets/js/my_sites.js?v=v600"></script>
