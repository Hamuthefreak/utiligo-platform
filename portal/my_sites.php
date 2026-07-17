<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_pro();
$user = current_user();
$pdo = get_platform_db();

$stmt = $pdo->prepare(
    "SELECT * FROM utiligo_generated_sites WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC"
);
$stmt->execute([$user['id']]);
$sites = $stmt->fetchAll();

$pageTitle = 'My Sites — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-6 py-10">
  <a href="/portal/index.php" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white mb-6">
    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
  </a>
  <h1 class="text-2xl font-bold mb-2">My Sites</h1>
  <p class="text-slate-400 text-sm mb-8">Manage your generated websites, share links, and expiry dates.</p>

  <div id="sitesList" class="space-y-3">
    <?php if (empty($sites)): ?>
      <div class="glass rounded-xl p-10 text-center text-slate-400">
        <i class="fa-solid fa-globe text-3xl mb-3 opacity-50"></i>
        <p>You haven't generated any sites yet.</p>
        <a href="/portal/leads.php" class="inline-block mt-4 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-full text-sm font-semibold">Find Leads to Get Started</a>
      </div>
    <?php else: foreach ($sites as $site): ?>
      <?php
        $isExpired = $site['link_expires_at'] && strtotime($site['link_expires_at']) < time();
        $isActive = $site['link_active'] && !$isExpired;
        $publicUrl = $site['public_slug'] ? ('/s/' . $site['public_slug']) : null;
      ?>
      <div class="glass rounded-xl p-5 flex items-center justify-between gap-4 flex-wrap" data-site-id="<?= (int)$site['id'] ?>">
        <div class="flex-1 min-w-[200px]">
          <div class="flex items-center gap-2 mb-1">
            <h3 class="font-semibold"><?= htmlspecialchars($site['business_name']) ?></h3>
            <?php if ($isActive): ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400">Active</span>
            <?php else: ?>
              <span class="text-xs px-2 py-0.5 rounded-full bg-red-500/20 text-red-400">Expired</span>
            <?php endif; ?>
          </div>
          <p class="text-sm text-slate-400"><?= htmlspecialchars($site['business_category']) ?> · <?= htmlspecialchars($site['business_city']) ?></p>
          <?php if ($publicUrl && $isActive): ?>
            <a href="<?= $publicUrl ?>" target="_blank" class="text-xs text-emerald-400 hover:text-emerald-300 mt-1 inline-block">utiligo.ca<?= $publicUrl ?> <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i></a>
          <?php endif; ?>
          <p class="text-xs text-slate-500 mt-1">
            <?php if ($site['link_expires_at']): ?>
              <?= $isExpired ? 'Expired' : 'Expires' ?> <?= date('M j, Y \a\t g:i A', strtotime($site['link_expires_at'])) ?>
            <?php endif; ?>
          </p>
        </div>
        <div class="flex gap-2 items-center">
          <?php if ($isActive): ?>
            <button class="extend-btn text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-4 py-2 rounded-full font-semibold whitespace-nowrap" data-id="<?= (int)$site['id'] ?>">
              <i class="fa-solid fa-clock-rotate-left mr-1"></i>Extend 7 Days
            </button>
          <?php endif; ?>
          <?php if ($isActive): ?>
            <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>" class="text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-4 py-2 rounded-full font-semibold whitespace-nowrap">
              <i class="fa-solid fa-pen mr-1"></i>Edit
            </a>
          <?php endif; ?>
          <a href="<?= htmlspecialchars($site['zip_file_path']) ?>" class="text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-4 py-2 rounded-full font-semibold whitespace-nowrap">
            <i class="fa-solid fa-download mr-1"></i>ZIP
          </a>
          <button class="delete-btn text-xs bg-red-500/10 hover:bg-red-500/20 text-red-400 px-4 py-2 rounded-full font-semibold whitespace-nowrap" data-id="<?= (int)$site['id'] ?>">
            <i class="fa-solid fa-trash mr-1"></i>Delete
          </button>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/my_sites.js?v=v162"></script>
