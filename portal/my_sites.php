<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user   = current_user();
$is_pro = ($user['plan'] ?? 'free') === 'pro';
$pdo    = get_platform_db();

$stmt = $pdo->prepare("SELECT * FROM utiligo_generated_sites WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$sites = $stmt->fetchAll();

// Stats
$totalSites  = count($sites);
$activeSites = count(array_filter($sites, fn($s) => !empty($s['public_slug']) && !empty($s['link_active'])));
$thisMonth   = count(array_filter($sites, fn($s) => date('Y-m', strtotime($s['created_at'])) === date('Y-m')));

$pageTitle = 'My Sites — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-8 flex items-center justify-between flex-wrap gap-4">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">My Sites</h1>
    <p class="text-slate-400 text-sm mt-1">Manage generated websites, share preview links, and download ZIPs.</p>
  </div>
  <a href="/portal/generate.php"
     class="flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 px-5 py-2.5 rounded-xl font-bold text-sm transition-all">
    <i class="fa-solid fa-plus"></i> New Site
  </a>
</div>

<!-- Stats -->
<div class="grid grid-cols-3 gap-4 mb-8">
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Total Sites</p>
    <p class="text-3xl font-black"><?= $totalSites ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Active Links</p>
    <p class="text-3xl font-black text-emerald-400"><?= $activeSites ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">This Month</p>
    <p class="text-3xl font-black text-blue-400"><?= $thisMonth ?></p>
  </div>
</div>

<!-- Sites list -->
<div id="sitesList" class="space-y-4">
<?php if (empty($sites)): ?>
  <div class="glass rounded-2xl p-12 text-center border border-white/5">
    <div class="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-4">
      <i class="fa-solid fa-globe text-slate-500 text-2xl"></i>
    </div>
    <p class="font-semibold text-slate-300 mb-1">No sites generated yet</p>
    <p class="text-slate-500 text-sm mb-5">Find a lead and generate their website in 60 seconds.</p>
    <a href="/portal/generate.php"
       class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-xl text-sm font-bold">
      <i class="fa-solid fa-bolt"></i> Generate a Site
    </a>
  </div>
<?php else: foreach ($sites as $site):
  $hasSlug    = !empty($site['public_slug']);
  $isActive   = $hasSlug && !empty($site['link_active']);
  $isExpired  = $hasSlug && !empty($site['link_expires_at']) && strtotime($site['link_expires_at']) < time();
  $isLive     = $isActive && !$isExpired;
  $publicUrl  = $hasSlug ? '/s/' . $site['public_slug'] : null;
  $zipUrl     = !empty($site['zip_file_path']) ? $site['zip_file_path'] : null;

  // Expiry label
  $expiryLabel = '';
  if ($hasSlug && !empty($site['link_expires_at'])) {
    $diff = strtotime($site['link_expires_at']) - time();
    if ($diff <= 0) {
      $expiryLabel = 'Expired';
    } elseif ($diff < 86400) {
      $expiryLabel = 'Expires in ' . round($diff / 3600) . 'h';
    } else {
      $expiryLabel = 'Expires ' . date('M j', strtotime($site['link_expires_at']));
    }
  }
?>
  <div class="glass rounded-2xl p-5 border <?= $isLive ? 'border-emerald-500/20' : 'border-white/5' ?> hover:border-white/10 transition-all"
       data-site-id="<?= (int)$site['id'] ?>">
    <div class="flex items-start justify-between gap-4 flex-wrap">

      <!-- Left: info -->
      <div class="flex items-start gap-3 flex-1 min-w-0">
        <div class="w-10 h-10 rounded-xl <?= $isLive ? 'bg-emerald-500/15' : 'bg-blue-500/15' ?> flex items-center justify-center shrink-0 mt-0.5">
          <i class="fa-solid fa-globe <?= $isLive ? 'text-emerald-400' : 'text-blue-400' ?> text-sm"></i>
        </div>
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h3 class="font-semibold truncate"><?= htmlspecialchars($site['business_name']) ?></h3>
            <?php if ($isLive): ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400">Active</span>
            <?php elseif ($isExpired): ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400">Expired</span>
            <?php elseif ($hasSlug && !$isActive): ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-slate-500/20 text-slate-400">Inactive</span>
            <?php else: ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-slate-500/20 text-slate-400">No Share Link</span>
            <?php endif; ?>
          </div>

          <p class="text-xs text-slate-500 mt-0.5">
            Generated <?= date('M j, Y', strtotime($site['created_at'])) ?>
            <?php if ($site['template_name'] ?? null): ?>
              &middot; <span class="capitalize"><?= htmlspecialchars($site['template_name']) ?></span> template
            <?php endif; ?>
          </p>

          <?php if ($publicUrl && $isLive): ?>
            <a href="<?= $publicUrl ?>" target="_blank"
               class="text-xs text-emerald-400 hover:text-emerald-300 mt-1 inline-flex items-center gap-1">
              <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
              utiligo.ca<?= $publicUrl ?>
            </a>
          <?php endif; ?>

          <?php if ($expiryLabel): ?>
            <p class="text-xs mt-1 <?= $isExpired ? 'text-amber-400' : 'text-slate-500' ?>">
              <i class="fa-regular fa-clock mr-1"></i><?= $expiryLabel ?>
            </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right: actions -->
      <div class="flex gap-2 items-center flex-wrap shrink-0">

        <?php if ($is_pro): ?>
          <!-- Extend: show when has a share link (active OR expired) -->
          <?php if ($hasSlug): ?>
          <button class="extend-btn text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-3 py-2 rounded-xl font-semibold transition"
                  data-id="<?= (int)$site['id'] ?>" title="<?= $isExpired ? 'Reactivate & extend 7 days' : 'Extend expiry by 7 days' ?>">
            <i class="fa-solid fa-clock-rotate-left mr-1"></i><?= $isExpired ? 'Reactivate' : 'Extend' ?>
          </button>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Edit: always available -->
        <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>"
           class="text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-3 py-2 rounded-xl font-semibold transition">
          <i class="fa-solid fa-pen mr-1"></i>Edit
        </a>

        <!-- Preview: only when share link is live -->
        <?php if ($publicUrl && $isLive): ?>
        <a href="<?= $publicUrl ?>" target="_blank"
           class="text-xs bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 px-3 py-2 rounded-xl font-semibold transition">
          <i class="fa-solid fa-eye mr-1"></i>Preview
        </a>
        <?php endif; ?>

        <!-- Download ZIP -->
        <?php if ($zipUrl): ?>
        <a href="<?= htmlspecialchars($zipUrl) ?>"
           class="text-xs bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 px-3 py-2 rounded-xl font-semibold transition">
          <i class="fa-solid fa-download mr-1"></i>ZIP
        </a>
        <?php endif; ?>

        <!-- Delete -->
        <button class="delete-btn text-xs bg-red-500/10 hover:bg-red-500/20 text-red-400 px-3 py-2 rounded-xl font-semibold transition"
                data-id="<?= (int)$site['id'] ?>">
          <i class="fa-solid fa-trash"></i>
        </button>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>
</div>

<script src="/assets/js/my_sites.js?v=v200"></script>
