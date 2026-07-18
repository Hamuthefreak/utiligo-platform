<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_pro();
$user = current_user();
$pdo  = get_platform_db();

$stmt = $pdo->prepare("SELECT * FROM utiligo_generated_sites WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$sites = $stmt->fetchAll();

$pageTitle = 'My Sites — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-8 flex items-center justify-between flex-wrap gap-4">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">My Sites</h1>
    <p class="text-slate-400 text-sm mt-1">Manage generated websites, share preview links, and download ZIPs.</p>
  </div>
  <a href="/portal/leads.php" class="flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 px-5 py-2.5 rounded-xl font-bold text-sm transition-all">
    <i class="fa-solid fa-plus"></i> New Site
  </a>
</div>

<!-- Stats strip -->
<div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-8">
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Total Sites</p>
    <p class="text-3xl font-black"><?= count($sites) ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Active</p>
    <p class="text-3xl font-black text-emerald-400"><?= count(array_filter($sites, fn($s) => !empty($s['share_token']))) ?></p>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Generated This Month</p>
    <p class="text-3xl font-black text-blue-400"><?= count(array_filter($sites, fn($s) => date('Y-m', strtotime($s['created_at'])) === date('Y-m'))) ?></p>
  </div>
</div>

<div id="sitesList" class="space-y-4">
  <?php if (empty($sites)): ?>
  <div class="glass rounded-2xl p-12 text-center border border-white/5">
    <div class="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center mx-auto mb-4">
      <i class="fa-solid fa-globe text-slate-500 text-2xl"></i>
    </div>
    <p class="font-semibold text-slate-300 mb-1">No sites generated yet</p>
    <p class="text-slate-500 text-sm mb-5">Find a lead and generate their website in 60 seconds.</p>
    <a href="/portal/leads.php" class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-xl text-sm font-bold">
      <i class="fa-solid fa-magnifying-glass"></i> Find Leads
    </a>
  </div>
  <?php else: foreach ($sites as $site):
    $isActive  = !empty($site['share_token']);
    $publicUrl = $isActive ? ('/s/' . $site['share_token']) : null;
  ?>
  <div class="glass rounded-2xl p-5 border border-white/5 hover:border-white/10 transition-all" data-site-id="<?= (int)$site['id'] ?>">
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div class="flex items-center gap-3 flex-1 min-w-0">
        <div class="w-10 h-10 rounded-xl bg-blue-500/15 flex items-center justify-center shrink-0">
          <i class="fa-solid fa-globe text-blue-400 text-sm"></i>
        </div>
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h3 class="font-semibold truncate"><?= htmlspecialchars($site['business_name']) ?></h3>
            <?php if ($isActive): ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 shrink-0">Active</span>
            <?php else: ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-slate-500/20 text-slate-400 shrink-0">No Link</span>
            <?php endif; ?>
          </div>
          <p class="text-xs text-slate-500 mt-0.5">Generated <?= date('M j, Y', strtotime($site['created_at'])) ?></p>
          <?php if ($publicUrl && $isActive): ?>
          <a href="<?= $publicUrl ?>" target="_blank" class="text-xs text-emerald-400 hover:text-emerald-300 mt-1 inline-flex items-center gap-1">
            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
            utiligo.ca<?= $publicUrl ?>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <div class="flex gap-2 items-center flex-wrap">
        <?php if ($isActive): ?>
        <button class="extend-btn text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-4 py-2 rounded-xl font-semibold transition" data-id="<?= (int)$site['id'] ?>">
          <i class="fa-solid fa-clock-rotate-left mr-1"></i>Extend
        </button>
        <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>" class="text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-4 py-2 rounded-xl font-semibold transition">
          <i class="fa-solid fa-pen mr-1"></i>Edit
        </a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($site['zip_path'] ?? '#') ?>" class="text-xs bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 px-4 py-2 rounded-xl font-semibold transition">
          <i class="fa-solid fa-download mr-1"></i>ZIP
        </a>
        <button class="delete-btn text-xs bg-red-500/10 hover:bg-red-500/20 text-red-400 px-4 py-2 rounded-xl font-semibold transition" data-id="<?= (int)$site['id'] ?>">
          <i class="fa-solid fa-trash"></i>
        </button>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/my_sites.js?v=v162"></script>
