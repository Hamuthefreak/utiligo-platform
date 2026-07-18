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

$allTpls     = get_all_site_templates();
$totalSites  = count($sites);
$activeSites = count(array_filter($sites, fn($s) => !empty($s['public_slug']) && !empty($s['link_active'])));
$thisMonth   = count(array_filter($sites, fn($s) => date('Y-m', strtotime($s['created_at'])) === date('Y-m')));

$pageTitle = 'My Sites — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<!-- Page header -->
<div class="flex items-center justify-between mb-8 flex-wrap gap-4">
  <div>
    <h1 class="text-3xl font-black tracking-tight">My Sites</h1>
    <p class="text-slate-500 text-sm mt-1">Manage, share &amp; download your generated websites.</p>
  </div>
  <a href="/portal/generate.php"
     class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 px-5 py-2.5 rounded-xl font-bold text-sm transition-all shadow-lg shadow-emerald-500/20">
    <i class="fa-solid fa-bolt text-xs"></i> Generate New Site
  </a>
</div>

<!-- Stats strip -->
<div class="grid grid-cols-3 gap-3 mb-8">
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-widest mb-1">Total</p>
    <p class="text-3xl font-black"><?= $totalSites ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-widest mb-1">Live</p>
    <p class="text-3xl font-black text-emerald-400"><?= $activeSites ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-widest mb-1">This Month</p>
    <p class="text-3xl font-black text-blue-400"><?= $thisMonth ?></p>
  </div>
</div>

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
       class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-xl text-sm font-bold">
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

  $diff = $expiresTs ? ($expiresTs - time()) : null;
  if ($diff === null)         $exLabel = null;
  elseif ($diff <= 0)        $exLabel = 'Expired';
  elseif ($diff < 3600)      $exLabel = 'Expires in ' . floor($diff/60) . 'm ' . ($diff%60) . 's';
  elseif ($diff < 86400)     $exLabel = 'Expires in ' . floor($diff/3600) . 'h ' . floor(($diff%3600)/60) . 'm';
  else                       $exLabel = 'Expires ' . date('M j', $expiresTs);
?>

  <div class="glass rounded-2xl border <?= $isLive ? 'border-emerald-500/20' : 'border-white/5' ?> hover:border-white/10 transition-all"
       data-site-id="<?= (int)$site['id'] ?>">
    <div class="flex items-center gap-4 p-4 flex-wrap sm:flex-nowrap">

      <!-- Color dot / template identity -->
      <div class="w-11 h-11 rounded-xl shrink-0 flex items-center justify-center"
           style="background:linear-gradient(135deg,<?= $tpl['secondary'] ?> 0%,<?= $tpl['primary'] ?> 100%);">
        <i class="fa-solid fa-globe text-white/70 text-sm"></i>
      </div>

      <!-- Main info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <h3 class="font-bold text-base truncate"><?= htmlspecialchars($site['business_name']) ?></h3>

          <!-- Status pill -->
          <?php if ($isLive): ?>
            <span class="status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30">● Live</span>
          <?php elseif ($isExpired): ?>
            <span class="status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30">⏱ Expired</span>
          <?php else: ?>
            <span class="status-badge text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/8 text-slate-500">Offline</span>
          <?php endif; ?>

          <!-- Template chip -->
          <span class="text-[10px] text-slate-600 px-2 py-0.5 rounded-full bg-white/5"><?= htmlspecialchars($tpl['label']) ?></span>
        </div>

        <div class="flex items-center gap-3 mt-1 flex-wrap">
          <span class="text-xs text-slate-500">Generated <?= date('M j, Y', strtotime($site['created_at'])) ?></span>

          <!-- Countdown -->
          <?php if ($expiresIso && $exLabel): ?>
            <span class="expiry-label text-xs <?= $isExpired ? 'text-red-400' : 'text-slate-500' ?>"
                  data-expires-at="<?= htmlspecialchars($expiresIso) ?>">
              <i class="fa-regular fa-clock mr-0.5 text-[10px]"></i><?= $exLabel ?>
            </span>
          <?php endif; ?>

          <!-- Share link -->
          <?php if ($publicUrl && $isLive): ?>
            <a href="<?= $publicUrl ?>" target="_blank"
               class="text-xs text-emerald-400 hover:text-emerald-300 inline-flex items-center gap-1 truncate max-w-[200px]">
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
             class="inline-flex items-center gap-1.5 text-xs bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 px-3 py-1.5 rounded-lg font-semibold transition">
            <i class="fa-solid fa-eye text-[10px]"></i> Preview
          </a>
        <?php endif; ?>

        <?php if ($is_pro && $hasSlug): ?>
          <button class="extend-btn inline-flex items-center gap-1.5 text-xs bg-white/6 hover:bg-white/12 text-slate-300 px-3 py-1.5 rounded-lg font-semibold transition"
                  data-id="<?= (int)$site['id'] ?>">
            <i class="fa-solid fa-clock-rotate-left text-[10px]"></i>
            <?= $isExpired ? 'Reactivate' : 'Extend' ?>
          </button>
        <?php endif; ?>

        <?php if ($zipUrl): ?>
          <a href="<?= htmlspecialchars($zipUrl) ?>"
             class="inline-flex items-center gap-1.5 text-xs bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 px-3 py-1.5 rounded-lg font-semibold transition">
            <i class="fa-solid fa-download text-[10px]"></i> ZIP
          </a>
        <?php endif; ?>

        <button class="delete-btn inline-flex items-center gap-1 text-xs bg-red-500/8 hover:bg-red-500/20 text-red-500 px-2.5 py-1.5 rounded-lg transition"
                data-id="<?= (int)$site['id'] ?>">
          <i class="fa-solid fa-trash text-[10px]"></i>
        </button>
      </div>

    </div>
  </div>

<?php endforeach; endif; ?>
</div>

<script src="/assets/js/my_sites.js?v=v203"></script>
