<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site_templates.php';

require_login();
$user   = current_user();
$is_pro = ($user['plan'] ?? 'free') === 'pro';
$pdo    = get_platform_db();

$stmt = $pdo->prepare("SELECT * FROM utiligo_generated_sites WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$sites = $stmt->fetchAll();

$allTpls    = get_all_site_templates();
$totalSites = count($sites);
$activeSites = count(array_filter($sites, fn($s) => !empty($s['public_slug']) && !empty($s['link_active'])));
$thisMonth   = count(array_filter($sites, fn($s) => date('Y-m', strtotime($s['created_at'])) === date('Y-m')));

$pageTitle = 'My Sites — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-6 flex items-center justify-between flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-black tracking-tight">My Sites</h1>
    <p class="text-slate-500 text-xs mt-0.5">Manage, share, and download your generated websites.</p>
  </div>
  <a href="/portal/generate.php"
     class="flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 px-4 py-2 rounded-xl font-bold text-sm transition-all shadow-lg shadow-emerald-500/20">
    <i class="fa-solid fa-plus text-xs"></i> New Site
  </a>
</div>

<!-- Stats row -->
<div class="grid grid-cols-3 gap-3 mb-7">
  <div class="glass rounded-2xl p-4 border border-white/5 flex items-center gap-3">
    <div class="w-9 h-9 rounded-xl bg-white/5 flex items-center justify-center shrink-0">
      <i class="fa-solid fa-layer-group text-slate-400 text-sm"></i>
    </div>
    <div>
      <p class="text-2xl font-black leading-none"><?= $totalSites ?></p>
      <p class="text-[11px] text-slate-500 mt-0.5">Total Sites</p>
    </div>
  </div>
  <div class="glass rounded-2xl p-4 border border-white/5 flex items-center gap-3">
    <div class="w-9 h-9 rounded-xl bg-emerald-500/10 flex items-center justify-center shrink-0">
      <i class="fa-solid fa-signal text-emerald-400 text-sm"></i>
    </div>
    <div>
      <p class="text-2xl font-black leading-none text-emerald-400"><?= $activeSites ?></p>
      <p class="text-[11px] text-slate-500 mt-0.5">Live Links</p>
    </div>
  </div>
  <div class="glass rounded-2xl p-4 border border-white/5 flex items-center gap-3">
    <div class="w-9 h-9 rounded-xl bg-blue-500/10 flex items-center justify-center shrink-0">
      <i class="fa-solid fa-calendar-check text-blue-400 text-sm"></i>
    </div>
    <div>
      <p class="text-2xl font-black leading-none text-blue-400"><?= $thisMonth ?></p>
      <p class="text-[11px] text-slate-500 mt-0.5">This Month</p>
    </div>
  </div>
</div>

<!-- Sites grid -->
<div id="sitesList">
<?php if (empty($sites)): ?>
  <div class="glass rounded-2xl p-14 text-center border border-dashed border-white/10">
    <div class="w-14 h-14 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-4">
      <i class="fa-solid fa-globe text-slate-500 text-xl"></i>
    </div>
    <p class="font-bold text-slate-300 mb-1">No sites yet</p>
    <p class="text-slate-500 text-sm mb-5">Generate your first site in under 60 seconds.</p>
    <a href="/portal/generate.php"
       class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-5 py-2.5 rounded-xl text-sm font-bold">
      <i class="fa-solid fa-bolt"></i> Generate Now
    </a>
  </div>
<?php else: ?>
  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php foreach ($sites as $site):
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
    $dark       = $tpl['dark'] ?? false;
    $swatchBg   = $dark ? $tpl['accent'] : $tpl['accent'];
  ?>
    <div class="glass rounded-2xl overflow-hidden border <?= $isLive ? 'border-emerald-500/25' : 'border-white/5' ?> hover:border-white/10 transition-all group"
         data-site-id="<?= (int)$site['id'] ?>">

      <!-- Template colour swatch banner -->
      <div class="h-16 relative flex items-end px-4 pb-3"
           style="background:linear-gradient(135deg,<?= $tpl['secondary'] ?> 0%,<?= $tpl['primary'] ?> 100%);">
        <!-- Status badge -->
        <span class="status-badge absolute top-3 right-3 text-[10px] font-bold px-2 py-0.5 rounded-full
          <?= $isLive ? 'bg-emerald-400/20 text-emerald-300 ring-1 ring-emerald-400/30' : ($isExpired ? 'bg-amber-400/20 text-amber-300 ring-1 ring-amber-400/30' : 'bg-white/10 text-slate-400') ?>">
          <?= $isLive ? '● Live' : ($isExpired ? '⏱ Expired' : 'Offline') ?>
        </span>
        <!-- Template label chip -->
        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-black/30 text-white/80">
          <?= htmlspecialchars($tpl['label']) ?>
        </span>
      </div>

      <!-- Card body -->
      <div class="p-4">
        <h3 class="font-bold text-sm truncate leading-tight"><?= htmlspecialchars($site['business_name']) ?></h3>
        <p class="text-[11px] text-slate-500 mt-0.5">Generated <?= date('M j, Y', strtotime($site['created_at'])) ?></p>

        <!-- Live countdown -->
        <?php if ($expiresIso): ?>
          <?php
            $diff = $expiresTs - time();
            if ($diff <= 0)        $exLabel = 'Expired';
            elseif ($diff < 3600)  $exLabel = 'Expires in ' . floor($diff/60) . 'm ' . ($diff%60) . 's';
            elseif ($diff < 86400) $exLabel = 'Expires in ' . floor($diff/3600) . 'h ' . floor(($diff%3600)/60) . 'm';
            else                   $exLabel = 'Expires ' . date('M j', $expiresTs);
          ?>
          <p class="expiry-label text-[11px] mt-1 <?= $isExpired ? 'text-red-400' : 'text-slate-500' ?>"
             data-expires-at="<?= htmlspecialchars($expiresIso) ?>">
            <i class="fa-regular fa-clock mr-0.5"></i><?= $exLabel ?>
          </p>
        <?php endif; ?>

        <!-- Share link -->
        <?php if ($publicUrl && $isLive): ?>
          <a href="<?= $publicUrl ?>" target="_blank"
             class="text-[11px] text-emerald-400 hover:text-emerald-300 mt-1.5 inline-flex items-center gap-1 truncate max-w-full">
            <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
            utiligo.ca<?= $publicUrl ?>
          </a>
        <?php endif; ?>
      </div>

      <!-- Action row -->
      <div class="border-t border-white/5 px-3 py-2.5 flex items-center gap-1.5 flex-wrap">

        <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>"
           class="action-btn flex items-center gap-1.5 text-[11px] bg-white/5 hover:bg-white/10 text-slate-300 px-3 py-1.5 rounded-lg font-semibold transition">
          <i class="fa-solid fa-pen text-[9px]"></i> Edit
        </a>

        <?php if ($publicUrl && $isLive): ?>
          <a href="<?= $publicUrl ?>" target="_blank"
             class="action-btn flex items-center gap-1.5 text-[11px] bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 px-3 py-1.5 rounded-lg font-semibold transition">
            <i class="fa-solid fa-eye text-[9px]"></i> Preview
          </a>
        <?php endif; ?>

        <?php if ($is_pro && $hasSlug): ?>
          <button class="extend-btn action-btn flex items-center gap-1.5 text-[11px] bg-white/5 hover:bg-white/10 text-slate-300 px-3 py-1.5 rounded-lg font-semibold transition"
                  data-id="<?= (int)$site['id'] ?>">
            <i class="fa-solid fa-clock-rotate-left text-[9px]"></i>
            <?= $isExpired ? 'Reactivate' : 'Extend' ?>
          </button>
        <?php endif; ?>

        <?php if ($zipUrl): ?>
          <a href="<?= htmlspecialchars($zipUrl) ?>"
             class="action-btn flex items-center gap-1.5 text-[11px] bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 px-3 py-1.5 rounded-lg font-semibold transition">
            <i class="fa-solid fa-download text-[9px]"></i> ZIP
          </a>
        <?php endif; ?>

        <button class="delete-btn action-btn ml-auto flex items-center gap-1 text-[11px] bg-red-500/8 hover:bg-red-500/20 text-red-500 px-2.5 py-1.5 rounded-lg transition"
                data-id="<?= (int)$site['id'] ?>">
          <i class="fa-solid fa-trash text-[9px]"></i>
        </button>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<script src="/assets/js/my_sites.js?v=v202"></script>
