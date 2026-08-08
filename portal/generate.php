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

$gen_limit     = defined('FREE_GENERATE_DAILY_LIMIT') ? (int)FREE_GENERATE_DAILY_LIMIT : 1;
$gen_used      = 0;
$gen_resets_at = null;

if (!$is_paid) {
    try {
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt   = $pdo->prepare("SELECT COUNT(*) AS c, MIN(created_at) AS first_at FROM utiligo_generated_sites WHERE user_id = ? AND created_at > ?");
        $stmt->execute([$user['id'], $cutoff]);
        $row      = $stmt->fetch(PDO::FETCH_ASSOC);
        $gen_used = (int)($row['c'] ?? 0);
        if ($row['first_at']) $gen_resets_at = strtotime($row['first_at']) + 86400;
    } catch (\Throwable $e) {}
}

$site_limit        = plan_site_limit($plan);
$active_site_count = 0;
$site_limit_hit    = false;
if ($is_paid) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id = ? AND link_active = 1");
        $stmt->execute([$user['id']]);
        $active_site_count = (int)$stmt->fetchColumn();
        $site_limit_hit    = !can_generate_site($plan, $active_site_count);
    } catch (\Throwable $e) {}
}

$gen_remaining = $is_paid ? ($site_limit_hit ? 0 : PHP_INT_MAX) : max(0, $gen_limit - $gen_used);
$gen_pct       = ($gen_limit > 0 && !$is_paid) ? min(100, round(($gen_used / $gen_limit) * 100)) : 0;
$gen_locked    = (!$is_paid && $gen_remaining === 0) || $site_limit_hit;

$free_template_limit = defined('FREE_TEMPLATE_LIMIT') ? (int)FREE_TEMPLATE_LIMIT : 2;
$all_templates       = get_all_site_templates();
$template_keys       = array_keys($all_templates);
$free_keys           = array_slice($template_keys, 0, $free_template_limit);

// Pre-fill from regenerate link (?name=&category=&city=&phone=&email=)
// or from a lead (?lead_id=)
$prefill = ['business_name'=>'','business_category'=>'','business_city'=>'','business_phone'=>'','business_email'=>''];

if (!empty($_GET['name']) || !empty($_GET['city'])) {
    $prefill['business_name']     = trim($_GET['name']     ?? '');
    $prefill['business_category'] = trim($_GET['category'] ?? '');
    $prefill['business_city']     = trim($_GET['city']     ?? '');
    $prefill['business_phone']    = trim($_GET['phone']    ?? '');
    $prefill['business_email']    = trim($_GET['email']    ?? '');
} elseif (!empty($_GET['lead_id'])) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM utiligo_leads WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_GET['lead_id']]);
        $lead = $stmt->fetch();
        if ($lead) {
            $prefill['business_name']     = $lead['business_name']     ?? '';
            $prefill['business_category'] = $lead['business_category'] ?? '';
            $prefill['business_city']     = $lead['business_city']     ?? '';
            $prefill['business_phone']    = $lead['business_phone']    ?? '';
            $prefill['business_email']    = $lead['business_email']    ?? '';
        }
    } catch (\Throwable $e) {}
}

$templateCategories = [];
foreach ($all_templates as $key => $t) {
    $templateCategories[$t['category']][] = $key;
}

// Build a JS-safe map of all template data for the live preview
$tpl_json = json_encode(array_map(function($t) {
    return [
        'label'       => $t['label'],
        'description' => $t['description'],
        'category'    => $t['category'],
        'primary'     => $t['primary'],
        'secondary'   => $t['secondary'],
        'accent'      => $t['accent'],
        'text'        => $t['text'],
        'font'        => $t['font'],
        'font_url'    => $t['font_url'] ?? null,
        'radius'      => $t['radius'],
        'dark'        => $t['dark'] ?? false,
        'hero_style'  => $t['hero_style'] ?? 'centered',
    ];
}, $all_templates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$pageTitle = 'Generate Website — Utiligo';
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
/* Full preview modal */
#fullPreviewModal {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0,0,0,.88);
  backdrop-filter: blur(8px);
  align-items: flex-start;
  justify-content: center;
  padding: 16px;
  overflow-y: auto;
}
#fullPreviewModal.open { display: flex; }
#fullPreviewInner {
  width: 100%;
  max-width: 1000px;
  margin: auto;
  display: flex;
  flex-direction: column;
  gap: 0;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(0,0,0,.8);
}
#previewTopBar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  background: #0F172A;
  border-bottom: 1px solid rgba(255,255,255,.08);
  flex-wrap: wrap;
}
#previewPageTabs {
  display: flex;
  gap: 4px;
  flex: 1;
  flex-wrap: wrap;
}
.preview-tab {
  padding: 5px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  color: rgba(255,255,255,.5);
  cursor: pointer;
  background: transparent;
  border: none;
  transition: background .15s, color .15s;
}
.preview-tab:hover  { background: rgba(255,255,255,.08); color: #fff; }
.preview-tab.active { background: rgba(255,255,255,.15); color: #fff; }
#previewModalLabel {
  font-size: 13px;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
}
#previewCloseBtn, #previewSelectBtn {
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  border: none;
  cursor: pointer;
  white-space: nowrap;
}
#previewCloseBtn  { background: rgba(255,255,255,.1); color: #fff; }
#previewCloseBtn:hover { background: rgba(255,255,255,.18); }
#previewSelectBtn { background: #fff; color: #000; }
#previewSelectBtn:hover { background: #e2e8f0; }
#previewFrame {
  width: 100%;
  height: 75vh;
  border: none;
  display: block;
  background: #fff;
}
</style>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Generate a Website</h1>
  <p class="text-slate-400 text-sm mt-1">Fill in the business details, pick a template, and get a full 5-page site in ~60 seconds.</p>
</div>

<?php if (!$is_paid): ?>
<div class="glass rounded-2xl p-5 mb-8 border border-white/5">
  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
    <div class="flex items-center gap-2">
      <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center">
        <i class="fa-solid fa-bolt text-slate-300 text-xs"></i>
      </div>
      <div>
        <p class="text-sm font-semibold">Daily Generation Quota</p>
        <p class="text-xs text-slate-400">Resets every 24 hours</p>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <div class="flex items-center gap-1.5 <?= $gen_remaining===0 ? 'bg-red-500/10 border border-red-500/20 text-red-400' : 'bg-white/8 border border-white/10 text-slate-300' ?> rounded-full px-3 py-1 text-xs font-bold">
        <i class="fa-solid fa-<?= $gen_remaining===0 ? 'ban' : 'circle-check' ?>"></i>
        <?= $gen_remaining===0 ? 'Generation used today' : $gen_remaining.' site left today' ?>
      </div>
      <a href="/portal/billing.php?upgrade=1" class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold">
        <i class="fa-solid fa-crown mr-1"></i>Upgrade
      </a>
    </div>
  </div>
  <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
    <div class="h-2 rounded-full transition-all <?= $gen_pct>=100 ? 'bg-red-500' : 'bg-white/60' ?>" style="width:<?= $gen_pct ?>%"></div>
  </div>
  <div class="flex justify-between text-xs text-slate-500 mt-1.5">
    <span><?= $gen_used ?> of <?= $gen_limit ?> site<?= $gen_limit!==1?'s':'' ?> generated today</span>
    <?php if($gen_resets_at): ?>
      <span>Resets at <?= date('g:i A', $gen_resets_at) ?></span>
    <?php else: ?>
      <span>Resets 24h after first generation</span>
    <?php endif; ?>
  </div>
  <?php if($gen_locked): ?>
  <div class="mt-3 bg-red-500/10 border border-red-500/20 rounded-xl p-3 text-xs text-red-300 flex items-start gap-2">
    <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
    <span>Daily generation limit reached.
      <?php if($gen_resets_at): ?> Resets at <strong><?= date('g:i A', $gen_resets_at) ?></strong>.<?php endif; ?>
      <a href="/portal/billing.php?upgrade=1" class="text-white underline ml-1">Upgrade for unlimited.</a>
    </span>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($site_limit_hit): ?>
<div class="glass rounded-2xl p-5 mb-8 border border-red-500/20">
  <div class="flex items-center gap-3">
    <div class="w-8 h-8 rounded-xl bg-red-500/15 flex items-center justify-center shrink-0">
      <i class="fa-solid fa-triangle-exclamation text-red-400 text-xs"></i>
    </div>
    <div class="flex-1">
      <p class="text-sm font-bold text-white">Active Site Limit Reached</p>
      <p class="text-xs text-slate-400 mt-0.5">
        You have <strong class="text-white"><?= $active_site_count ?></strong> of
        <strong class="text-white"><?= $site_limit ?></strong> active sites on the
        <strong class="text-white"><?= plan_label($plan) ?></strong> plan.
        Delete or deactivate a site to generate a new one.
      </p>
    </div>
    <?php if ($plan === 'pro'): ?>
    <a href="/portal/billing.php?upgrade=1" class="shrink-0 text-xs bg-white hover:bg-slate-200 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap">
      <i class="fa-solid fa-arrow-up mr-1"></i>Upgrade
    </a>
    <?php endif; ?>
  </div>
  <?php $sl_pct = $site_limit > 0 ? min(100, round(($active_site_count/$site_limit)*100)) : 100; ?>
  <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden mt-3">
    <div class="h-1.5 rounded-full bg-red-500" style="width:<?= $sl_pct ?>%"></div>
  </div>
</div>

<?php else: ?>
<?php
  $sl_pct    = ($site_limit > 0) ? min(100, round(($active_site_count/$site_limit)*100)) : 0;
  $sl_colour = $sl_pct >= 90 ? 'bg-red-500' : ($sl_pct >= 70 ? 'bg-amber-500' : 'bg-white/60');
?>
<div class="glass rounded-2xl p-5 mb-8 border border-white/5">
  <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
    <div class="flex items-center gap-2">
      <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center">
        <i class="fa-solid fa-globe text-slate-300 text-xs"></i>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Active Sites</p>
        <p class="text-xs text-slate-400"><?= plan_label($plan) ?> Plan &mdash; <?= $active_site_count ?> / <?= $site_limit ?> used</p>
      </div>
    </div>
    <?php if ($sl_pct >= 80 && $plan === 'pro'): ?>
    <a href="/portal/billing.php?upgrade=1" class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold">
      <i class="fa-solid fa-arrow-up mr-1"></i>Upgrade
    </a>
    <?php endif; ?>
  </div>
  <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
    <div class="h-1.5 rounded-full transition-all <?= $sl_colour ?>" style="width:<?= $sl_pct ?>%"></div>
  </div>
</div>
<?php endif; ?>

<?php if ($gen_locked): ?>
<div class="glass rounded-2xl p-12 text-center border border-red-500/20 mb-8">
  <div class="w-16 h-16 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-4">
    <i class="fa-solid fa-lock text-red-400 text-2xl"></i>
  </div>
  <?php if ($site_limit_hit): ?>
    <p class="font-bold text-lg mb-2">Active Site Limit Reached</p>
    <p class="text-slate-400 text-sm mb-5 max-w-sm mx-auto">Free up a slot by deleting or deactivating an existing site, or upgrade your plan.</p>
  <?php else: ?>
    <p class="font-bold text-lg mb-2">Come back tomorrow</p>
    <p class="text-slate-400 text-sm mb-5 max-w-sm mx-auto">
      You&rsquo;ve used your free generation for today.
      <?php if($gen_resets_at): ?>Resets at <strong class="text-white"><?= date('g:i A', $gen_resets_at) ?></strong>.<?php endif; ?>
    </p>
  <?php endif; ?>
  <a href="/portal/billing.php?upgrade=1"
     class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-8 py-3 rounded-xl font-bold">
    <i class="fa-solid fa-crown"></i> Upgrade Plan
  </a>
</div>

<?php else: ?>
<div id="generateSkeleton" aria-hidden="true" class="space-y-4 mb-6">
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="skeleton h-3 w-32 mb-5"></div>
    <div class="grid md:grid-cols-2 gap-4">
      <div class="skeleton h-10 rounded-xl"></div>
      <div class="skeleton h-10 rounded-xl"></div>
      <div class="skeleton h-10 rounded-xl"></div>
      <div class="skeleton h-10 rounded-xl"></div>
      <div class="md:col-span-2 skeleton h-10 rounded-xl"></div>
    </div>
  </div>
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="skeleton h-3 w-40 mb-5"></div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <?php for($i=0;$i<8;$i++): ?>
        <div class="skeleton h-28 rounded-xl"></div>
      <?php endfor; ?>
    </div>
  </div>
  <div class="skeleton h-14 rounded-xl w-full"></div>
</div>

<form id="generateForm" class="space-y-6 hidden">
  <input type="hidden" name="lead_id"       value="<?= htmlspecialchars($_GET['lead_id'] ?? '') ?>">
  <input type="hidden" name="template_name" id="selectedTemplateInput" value="modern">

  <!-- Business Details -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Business Details</p>
    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Business Name</label>
        <input type="text" name="business_name" required value="<?= htmlspecialchars($prefill['business_name']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-white/40 transition">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Category / Industry</label>
        <input type="text" name="business_category" required value="<?= htmlspecialchars($prefill['business_category']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-white/40 transition">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">City</label>
        <input type="text" name="business_city" required value="<?= htmlspecialchars($prefill['business_city']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-white/40 transition">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Phone</label>
        <input type="text" name="business_phone" value="<?= htmlspecialchars($prefill['business_phone']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-white/40 transition">
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Contact Email</label>
        <input type="email" name="business_email" value="<?= htmlspecialchars($prefill['business_email']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-white/40 transition">
      </div>
    </div>
  </div>

  <!-- Template chooser -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest">Choose Template</p>
        <p class="text-xs text-slate-500 mt-0.5">
          <?php if (!$is_paid): ?>
            <span class="text-white font-semibold"><?= $free_template_limit ?> free</span> &mdash;
            <?= count($all_templates) - $free_template_limit ?> more with Pro
          <?php else: ?>
            <?= count($all_templates) ?> templates available
          <?php endif; ?>
        </p>
      </div>
      <span id="selectedTemplateLabel" class="text-xs px-3 py-1 rounded-full bg-white/10 text-white hidden"></span>
    </div>

    <?php foreach ($templateCategories as $categoryName => $keys): ?>
    <p class="text-xs uppercase tracking-wider text-slate-600 mt-5 mb-3"><?= htmlspecialchars($categoryName) ?></p>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <?php foreach ($keys as $key):
        $tpl         = $all_templates[$key];
        $is_free_tpl = in_array($key, $free_keys, true);
        $locked      = !$is_paid && !$is_free_tpl;
      ?>
      <div role="button" tabindex="0"
           class="template-card relative text-left rounded-xl overflow-hidden border-2 <?= $locked ? 'border-white/5 opacity-60 cursor-not-allowed' : 'border-transparent hover:border-white/40 cursor-pointer' ?> transition group"
           data-template="<?= $key ?>"
           data-label="<?= htmlspecialchars($tpl['label']) ?>"
           data-primary="<?= htmlspecialchars($tpl['primary']) ?>"
           data-secondary="<?= htmlspecialchars($tpl['secondary']) ?>"
           data-accent="<?= htmlspecialchars($tpl['accent']) ?>"
           data-font="<?= htmlspecialchars($tpl['label']) ?>"
           data-description="<?= htmlspecialchars($tpl['description']) ?>"
           data-radius="<?= htmlspecialchars($tpl['radius']) ?>"
           data-dark="<?= ($tpl['dark'] ?? false) ? '1' : '0' ?>"
           <?= $locked ? 'data-locked="1"' : '' ?>>

        <?php if ($locked): ?>
        <div class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-slate-900/70 backdrop-blur-sm pointer-events-none">
          <div class="w-7 h-7 rounded-full bg-white/10 flex items-center justify-center mb-1">
            <i class="fa-solid fa-crown text-white text-[10px]"></i>
          </div>
          <span class="text-[10px] font-bold text-white">Pro Only</span>
        </div>
        <?php else: ?>
        <button type="button"
                class="preview-tpl-btn absolute top-2 right-2 z-20 w-6 h-6 rounded-full bg-black/40 hover:bg-black/70 text-white/80 hover:text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                data-tpl-key="<?= $key ?>"
                title="Full preview">
          <i class="fa-solid fa-eye text-[9px]"></i>
        </button>
        <?php endif; ?>

        <!-- Gradient thumbnail — no glass/backdrop so true colours show -->
        <div class="template-thumb h-20 flex flex-col justify-center px-4"
             style="background:linear-gradient(135deg,<?= $tpl['secondary'] ?> 0%,<?= $tpl['primary'] ?> 100%);">
          <div class="w-10 h-2 rounded-full mb-2" style="background:<?= $tpl['primary'] ?>;opacity:0.6;"></div>
          <div class="w-full h-1.5 rounded-full mb-1" style="background:rgba(255,255,255,0.25);"></div>
          <div class="w-2/3 h-1.5 rounded-full" style="background:rgba(255,255,255,0.18);"></div>
        </div>
        <div class="p-3" style="background:#0f172a;">
          <p class="font-semibold text-xs flex items-center gap-1.5">
            <?= htmlspecialchars($tpl['label']) ?>
            <?php if (!$locked && $is_free_tpl): ?>
              <span class="text-[10px] bg-white/10 text-white px-1.5 py-0.5 rounded-full">Free</span>
            <?php endif; ?>
          </p>
          <p class="text-[10px] text-slate-400 mt-0.5 leading-snug"><?= htmlspecialchars($tpl['description']) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!$is_paid): ?>
    <div class="mt-5 p-4 rounded-xl border border-white/10 bg-white/5 flex items-center gap-3">
      <i class="fa-solid fa-crown text-white"></i>
      <div class="flex-1 text-sm">
        <span class="font-semibold text-white">Unlock all <?= count($all_templates) ?> templates</span>
        <span class="text-slate-400"> plus unlimited generations with Pro.</span>
      </div>
      <a href="/portal/billing.php?upgrade=1" class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-2 rounded-xl font-bold whitespace-nowrap">Upgrade</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Custom images -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-1">Custom Images <span class="text-slate-600 normal-case font-normal">(optional)</span></p>
    <p class="text-slate-400 text-xs mb-5">Drag &amp; drop your own photos, or leave blank to use stock images.</p>
    <div class="grid md:grid-cols-3 gap-4">
      <div class="upload-slot" data-slot="hero">
        <p class="text-xs text-slate-500 mb-2">Hero Image</p>
        <div class="dropzone rounded-xl border border-dashed border-slate-600 hover:border-white/40 p-4 text-center cursor-pointer relative h-28 flex flex-col items-center justify-center overflow-hidden transition">
          <img class="upload-preview hidden absolute inset-0 w-full h-full object-cover" alt="">
          <div class="upload-placeholder"><i class="fa-solid fa-cloud-arrow-up text-slate-500 text-xl mb-1"></i><p class="text-xs text-slate-500">Drop or click</p></div>
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="upload-input hidden">
        </div>
        <input type="hidden" name="custom_image_hero" class="upload-result-input">
      </div>
      <div class="upload-slot" data-slot="about">
        <p class="text-xs text-slate-500 mb-2">About Image</p>
        <div class="dropzone rounded-xl border border-dashed border-slate-600 hover:border-white/40 p-4 text-center cursor-pointer relative h-28 flex flex-col items-center justify-center overflow-hidden transition">
          <img class="upload-preview hidden absolute inset-0 w-full h-full object-cover" alt="">
          <div class="upload-placeholder"><i class="fa-solid fa-cloud-arrow-up text-slate-500 text-xl mb-1"></i><p class="text-xs text-slate-500">Drop or click</p></div>
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="upload-input hidden">
        </div>
        <input type="hidden" name="custom_image_about" class="upload-result-input">
      </div>
      <div class="upload-slot" data-slot="gallery">
        <p class="text-xs text-slate-500 mb-2">Gallery (up to 6)</p>
        <div class="dropzone rounded-xl border border-dashed border-slate-600 hover:border-white/40 p-4 text-center cursor-pointer relative h-28 flex flex-col items-center justify-center overflow-hidden transition" data-multi="1">
          <div class="gallery-preview-grid hidden absolute inset-0 grid grid-cols-3 gap-0.5"></div>
          <div class="upload-placeholder"><i class="fa-solid fa-images text-slate-500 text-xl mb-1"></i><p class="text-xs text-slate-500">Multiple OK</p></div>
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="upload-input hidden">
        </div>
        <input type="hidden" name="custom_images_gallery" class="upload-result-input">
      </div>
    </div>
  </div>

  <button type="submit"
          class="w-full bg-white hover:bg-slate-200 active:scale-[.98] text-black py-4 rounded-xl font-bold text-base transition-all shadow-lg">
    <i class="fa-solid fa-bolt mr-2"></i>Generate Website
  </button>
</form>
<?php endif; /* gen_locked */ ?>

<!-- FULL LIVE PREVIEW MODAL -->
<div id="fullPreviewModal" role="dialog" aria-modal="true" aria-label="Template preview">
  <div id="fullPreviewInner">
    <div id="previewTopBar">
      <span id="previewModalLabel">Template Preview</span>
      <div id="previewPageTabs">
        <button class="preview-tab active" data-section="home">Home</button>
        <button class="preview-tab" data-section="about">About</button>
        <button class="preview-tab" data-section="services">Services</button>
        <button class="preview-tab" data-section="gallery">Gallery</button>
        <button class="preview-tab" data-section="contact">Contact</button>
      </div>
      <button id="previewSelectBtn"><i class="fa-solid fa-check" style="margin-right:5px"></i>Use Template</button>
      <button id="previewCloseBtn"><i class="fa-solid fa-xmark" style="margin-right:5px"></i>Close</button>
    </div>
    <iframe id="previewFrame" title="Template preview" sandbox="allow-same-origin"></iframe>
  </div>
</div>

<!-- Progress / Download panels -->
<div id="genProgressWrap" class="hidden glass rounded-2xl p-10 text-center border border-white/5 mt-6">
  <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-white/10 mb-4">
    <i class="fa-solid fa-spinner fa-spin text-white text-2xl"></i>
  </div>
  <p id="genProgressLabel" class="text-slate-300 font-semibold mb-4">Starting&hellip;</p>
  <div class="gen-progress-bar max-w-sm mx-auto">
    <div id="genProgressFill" class="gen-progress-fill" style="width:0%"></div>
  </div>
</div>

<div id="genDownloadWrap" class="hidden glass rounded-2xl p-10 text-center border border-white/10 mt-6">
  <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-white/10 mb-4">
    <i class="fa-solid fa-circle-check text-white text-3xl"></i>
  </div>
  <h3 class="text-xl font-bold mb-2">Your website is ready!</h3>
  <p class="text-slate-400 text-sm mb-6">5 pages generated: Home, About, Services, Gallery, Contact.</p>
  <div class="flex gap-3 justify-center mb-6 flex-wrap">
    <a id="genEditLink" href="#"
       class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/15 text-white px-5 py-2.5 rounded-xl font-semibold">
      <i class="fa-solid fa-pen"></i>Edit Site
    </a>
    <a id="genPreviewLink" href="#" target="_blank"
       class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/15 text-white px-5 py-2.5 rounded-xl font-semibold">
      <i class="fa-solid fa-eye"></i>Preview
    </a>
    <a id="genDownloadLink" href="#"
       data-download
       class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-5 py-2.5 rounded-xl font-bold">
       <i class="fa-solid fa-download"></i>Download ZIP
    </a>
  </div>
  <div id="genShareLinkWrap" class="hidden bg-white/5 rounded-xl p-4 max-w-md mx-auto">
    <p class="text-xs text-slate-400 mb-2"><i class="fa-solid fa-link mr-1"></i>Shareable link (expires in 7 days)</p>
    <div class="flex gap-2">
      <input id="genShareLinkInput" type="text" readonly
             class="flex-1 bg-slate-800 border border-slate-600 text-white text-sm rounded-xl px-3 py-2">
      <button id="genShareLinkCopy" type="button"
              class="bg-white/10 hover:bg-white/20 text-white text-sm px-4 py-2 rounded-xl font-semibold">Copy</button>
    </div>
    <a href="/portal/my_sites.php" class="text-xs text-white/60 hover:text-white mt-2 inline-block">Manage all sites &rarr;</a>
  </div>
</div>

<div id="genErrorBoundary" class="hidden glass rounded-2xl p-10 text-center border border-red-500/20 mt-6">
  <div class="w-14 h-14 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-4">
    <i class="fa-solid fa-triangle-exclamation text-red-400 text-2xl"></i>
  </div>
  <h3 class="text-lg font-bold mb-2 text-white">Generation failed</h3>
  <p id="genErrorMsg" class="text-slate-400 text-sm mb-6 max-w-sm mx-auto">Something went wrong while building your site.</p>
  <div class="flex gap-3 justify-center flex-wrap">
    <button type="button" id="genRetryBtn"
            class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-6 py-2.5 rounded-xl font-bold">
      <i class="fa-solid fa-rotate-right"></i> Try Again
    </button>
    <a href="/portal/generate.php"
       class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/15 text-white px-6 py-2.5 rounded-xl font-semibold">
      <i class="fa-solid fa-refresh"></i> Reset Form
    </a>
  </div>
</div>

<script>
const TEMPLATES = <?= $tpl_json ?>;

document.addEventListener('DOMContentLoaded', function () {
  const skeleton = document.getElementById('generateSkeleton');
  const form     = document.getElementById('generateForm');
  if (skeleton) skeleton.remove();
  if (form)     form.classList.remove('hidden');

  const errBoundary  = document.getElementById('genErrorBoundary');
  const errMsg       = document.getElementById('genErrorMsg');
  const retryBtn     = document.getElementById('genRetryBtn');
  const progressWrap = document.getElementById('genProgressWrap');
  const downloadWrap = document.getElementById('genDownloadWrap');

  function showError(msg) {
    if (progressWrap) progressWrap.classList.add('hidden');
    if (downloadWrap) downloadWrap.classList.add('hidden');
    if (errMsg)       errMsg.textContent = msg || 'Something went wrong. Please try again.';
    if (errBoundary)  errBoundary.classList.remove('hidden');
  }
  window.addEventListener('genError', e => showError(e.detail?.message));
  window.addEventListener('error', e => {
    if (!progressWrap || progressWrap.classList.contains('hidden')) return;
    showError('A script error interrupted the generation. Please try again.');
  });
  if (retryBtn) retryBtn.addEventListener('click', () => {
    if (errBoundary) errBoundary.classList.add('hidden');
    if (form)        form.classList.remove('hidden');
  });

  const cards    = document.querySelectorAll('.template-card');
  const tplInput = document.getElementById('selectedTemplateInput');
  const tplLabel = document.getElementById('selectedTemplateLabel');

  function selectCard(card) {
    if (card.dataset.locked === '1') { window.location.href = '/portal/billing.php?upgrade=1'; return; }
    cards.forEach(c => c.classList.remove('border-white', '!border-white'));
    card.classList.add('border-white');
    if (tplInput) tplInput.value = card.dataset.template;
    if (tplLabel) { tplLabel.textContent = card.dataset.label; tplLabel.classList.remove('hidden'); }
  }

  cards.forEach(card => {
    card.addEventListener('click', e => {
      if (e.target.closest('.preview-tpl-btn')) return;
      selectCard(card);
    });
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectCard(card); }
    });
  });
  const first = document.querySelector('.template-card:not([data-locked])');
  if (first) selectCard(first);

  // FULL LIVE PREVIEW MODAL
  const fullModal     = document.getElementById('fullPreviewModal');
  const previewFrame  = document.getElementById('previewFrame');
  const previewLabel  = document.getElementById('previewModalLabel');
  const previewSelBtn = document.getElementById('previewSelectBtn');
  const previewClsBtn = document.getElementById('previewCloseBtn');
  const pageTabs      = document.querySelectorAll('.preview-tab');
  // single declaration — no duplicate below
  let currentPreviewKey = null;

  function buildPreviewHTML(key) {
    const t = TEMPLATES[key];
    if (!t) return '<p>Template not found.</p>';
    const isDark    = t.dark;
    const bg        = isDark ? t.secondary : (t.accent || '#ffffff');
    const fg        = t.text;
    const primary   = t.primary;
    const secondary = t.secondary;
    const radius    = t.radius;
    const fontFamily = t.font;
    const fontLink  = t.font_url ? `<link rel="stylesheet" href="${t.font_url}">` : '';
    const btnBg     = primary;
    const btnFg     = '#ffffff';
    const cardBg    = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)';
    const borderC   = isDark ? 'rgba(255,255,255,0.1)'  : 'rgba(0,0,0,0.08)';
    const mutedFg   = isDark ? 'rgba(255,255,255,0.55)' : 'rgba(0,0,0,0.5)';
    const heroGrad  = `linear-gradient(135deg, ${secondary} 0%, ${primary} 100%)`;
    const pill = (txt) => `<span style="display:inline-block;background:${isDark?'rgba(255,255,255,0.12)':'rgba(0,0,0,0.07)'};color:${fg};padding:3px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">${txt}</span>`;
    const btn  = (txt, outline=false) => outline
      ? `<button style="background:transparent;border:2px solid ${primary};color:${primary};padding:11px 28px;border-radius:${radius};font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;">${txt}</button>`
      : `<button style="background:${btnBg};color:${btnFg};border:none;padding:12px 32px;border-radius:${radius};font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;box-shadow:0 4px 16px rgba(0,0,0,.2);">${txt}</button>`;
    const sec  = (id, bgO, content) => `<section id="${id}" style="background:${bgO||bg};padding:80px 40px;">${content}</section>`;
    const h2   = (txt) => `<h2 style="font-size:clamp(24px,4vw,38px);font-weight:800;color:${fg};margin:0 0 12px;line-height:1.15;">${txt}</h2>`;
    const h3   = (txt) => `<h3 style="font-size:18px;font-weight:700;color:${fg};margin:0 0 8px;">${txt}</h3>`;
    const p    = (txt) => `<p style="color:${mutedFg};font-size:15px;line-height:1.7;margin:0 0 16px;">${txt}</p>`;
    const svcCard = (icon, title, desc) =>
      `<div style="background:${cardBg};border:1px solid ${borderC};border-radius:${radius};padding:28px 24px;"><div style="font-size:28px;margin-bottom:12px;">${icon}</div>${h3(title)}<p style="color:${mutedFg};font-size:14px;margin:0;line-height:1.6;">${desc}</p></div>`;
    const galTile = () =>
      `<div style="aspect-ratio:1;border-radius:${radius};background:linear-gradient(135deg,${primary}66,${secondary}aa);display:flex;align-items:center;justify-content:center;font-size:28px;">&#128247;</div>`;
    const stat = (num, lbl) =>
      `<div style="text-align:center;"><div style="font-size:32px;font-weight:900;color:${primary};">${num}</div><div style="font-size:12px;font-weight:600;color:${mutedFg};text-transform:uppercase;letter-spacing:.06em;margin-top:4px;">${lbl}</div></div>`;
    const testimonial = (quote, author) =>
      `<div style="background:${cardBg};border:1px solid ${borderC};border-radius:${radius};padding:24px;"><p style="color:${fg};font-size:14px;font-style:italic;margin:0 0 12px;line-height:1.7;">&ldquo;${quote}&rdquo;</p><div style="font-size:13px;font-weight:700;color:${primary};">&mdash; ${author}</div></div>`;
    const heroSection = `<section id="home" style="background:${heroGrad};padding:100px 40px 80px;text-align:center;position:relative;"><nav style="position:absolute;top:0;left:0;right:0;display:flex;align-items:center;justify-content:space-between;padding:16px 40px;background:rgba(0,0,0,0.15);"><span style="font-weight:900;font-size:18px;color:#fff;">YourBusiness</span><div style="display:flex;gap:20px;">${['Home','About','Services','Gallery','Contact'].map(pg=>`<a href="#${pg.toLowerCase()}" style="color:rgba(255,255,255,0.85);text-decoration:none;font-size:13px;font-weight:600;">${pg}</a>`).join('')}</div>${btn('Get Quote')}</nav><div style="max-width:680px;margin:60px auto 0;">${pill(t.category)}<h1 style="font-size:clamp(32px,6vw,60px);font-weight:900;color:#fff;margin:20px 0;line-height:1.1;">We Build Things<br>That Last</h1><p style="color:rgba(255,255,255,0.75);font-size:17px;line-height:1.7;margin:0 auto 32px;max-width:520px;">Professional services tailored to your needs.</p><div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">${btn('Get Started')}${btn('See Our Work',true)}</div></div></section>`;
    const whyUsSection = sec('why-us', bg, `<div style="max-width:900px;margin:0 auto;text-align:center;">${pill('Why Us')}<div style="margin-top:12px;">${h2('Why Clients Choose Us')}</div>${p('We deliver quality, reliability, and results every single time.')}<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:24px;margin-top:40px;">${[['&#9889;','Fast Turnaround','On time, every time.'],['&#127941;','Quality First','No shortcuts.'],['&#128179;','Clear Pricing','Zero hidden fees.'],['&#127775;','5-Star Rated','Hundreds of happy clients.']].map(([i,t2,d])=>svcCard(i,t2,d)).join('')}</div><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:24px;margin-top:48px;padding:32px;background:${cardBg};border-radius:${radius};border:1px solid ${borderC};">${stat('500+','Projects')}${stat('12+','Years')}${stat('98%','Satisfaction')}${stat('24/7','Support')}</div></div>`);
    const aboutSection = sec('about', isDark?'rgba(255,255,255,0.03)':'rgba(0,0,0,0.02)', `<div style="max-width:900px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center;"><div>${pill('Our Story')}<div style="margin-top:12px;">${h2('About Our Business')}</div>${p('Founded with a passion for quality, serving our community for over a decade.')}${p('We build long-term relationships — every client is treated like family.')}<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:24px;">${['Licensed & Insured','Free Estimates','Locally Owned','Award Winning'].map(f=>`<div style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:${fg};"><span style="color:${primary};font-size:18px;">&#10003;</span>${f}</div>`).join('')}</div><div style="margin-top:32px;">${btn('Meet the Team')}</div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div style="grid-column:1/-1;aspect-ratio:16/9;border-radius:${radius};background:linear-gradient(135deg,${primary}88,${secondary}cc);display:flex;align-items:center;justify-content:center;font-size:48px;">&#127968;</div><div style="aspect-ratio:1;border-radius:${radius};background:${cardBg};border:1px solid ${borderC};display:flex;align-items:center;justify-content:center;font-size:32px;">&#127775;</div><div style="aspect-ratio:1;border-radius:${radius};background:${cardBg};border:1px solid ${borderC};display:flex;align-items:center;justify-content:center;font-size:32px;">&#128205;</div></div></div>`);
    const servicesSection = sec('services', bg, `<div style="max-width:900px;margin:0 auto;text-align:center;">${pill('What We Do')}<div style="margin-top:12px;">${h2('Our Services')}</div>${p('Everything you need, handled by experts.')}<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;margin-top:40px;">${[['&#128295;','Core Service','Our flagship offering.'],['&#128640;','Premium Package','The complete solution.'],['&#128200;','Consultation','Expert advice.'],['&#128274;','Maintenance','Ongoing support.'],['&#128241;','Emergency','24/7 rapid response.'],['&#127881;','Custom','Bespoke solutions.']].map(([i,t2,d])=>svcCard(i,t2,d)).join('')}</div><div style="margin-top:40px;">${btn('View All Services')}</div></div>`);
    const gallerySection = sec('gallery', isDark?'rgba(255,255,255,0.02)':'rgba(0,0,0,0.02)', `<div style="max-width:900px;margin:0 auto;"><div style="text-align:center;">${pill('Portfolio')}<div style="margin-top:12px;">${h2('Our Work')}</div>${p('A sample of our proudest projects.')}</div><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:40px;">${[1,2,3,4,5,6].map(()=>galTile()).join('')}</div><div style="margin-top:32px;text-align:center;"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:32px;">${[testimonial('Absolutely incredible work!','Sarah M.'),testimonial('Professional and fast.','James K.'),testimonial('A seamless experience.','Linda R.')].join('')}</div>${btn('See Full Portfolio')}</div></div>`);
    const contactSection = `<section id="contact" style="background:${heroGrad};padding:80px 40px;"><div style="max-width:800px;margin:0 auto;"><div style="text-align:center;margin-bottom:48px;">${pill('Get In Touch')}<h2 style="font-size:clamp(24px,4vw,38px);font-weight:800;color:#fff;margin:12px 0;">Ready to Get Started?</h2><p style="color:rgba(255,255,255,0.7);font-size:16px;max-width:480px;margin:0 auto;">Fill in the form and we’ll get back to you within 24 hours.</p></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;"><div style="background:rgba(255,255,255,0.1);border-radius:${radius};padding:32px;">${[['Your Name','text'],['Email Address','email'],['Phone Number','tel']].map(([lbl,type])=>`<div style="margin-bottom:16px;"><label style="display:block;font-size:12px;font-weight:700;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">${lbl}</label><input type="${type}" placeholder="${lbl}" style="width:100%;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:#fff;padding:10px 14px;border-radius:${radius};font-size:14px;box-sizing:border-box;"/></div>`).join('')}<div style="margin-bottom:20px;"><label style="display:block;font-size:12px;font-weight:700;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Message</label><textarea rows="4" style="width:100%;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:#fff;padding:10px 14px;border-radius:${radius};font-size:14px;box-sizing:border-box;resize:vertical;"></textarea></div><button style="width:100%;background:#fff;color:${primary};border:none;padding:13px;border-radius:${radius};font-weight:800;font-size:15px;cursor:pointer;">Send Message &#8594;</button></div><div style="display:flex;flex-direction:column;gap:20px;justify-content:center;">${[['&#128205;','Address','123 Main Street, Your City'],['&#128222;','Phone','(555) 000-0000'],['&#128231;','Email','hello@yourbusiness.com'],['&#128336;','Hours','Mon-Fri: 8am-6pm']].map(([icon,lbl,val])=>`<div style="display:flex;gap:14px;align-items:flex-start;"><div style="width:42px;height:42px;border-radius:${radius};background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">${icon}</div><div><div style="font-size:11px;font-weight:700;color:rgba(255,255,255,0.6);text-transform:uppercase;">${lbl}</div><div style="font-size:14px;color:#fff;margin-top:3px;">${val}</div></div></div>`).join('')}</div></div></div></section>`;
    const footer = `<footer style="background:${secondary};padding:32px 40px;text-align:center;border-top:1px solid ${borderC};"><p style="color:${mutedFg};font-size:13px;margin:0;">&#169; 2025 YourBusiness. All rights reserved. &nbsp;|&nbsp; Built with Utiligo</p></footer>`;
    return `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">${fontLink}<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}html{scroll-behavior:smooth;}body{font-family:${fontFamily};color:${fg};background:${bg};}input,textarea,button{font-family:${fontFamily};}a{color:inherit;}</style></head><body>${heroSection}${whyUsSection}${aboutSection}${servicesSection}${gallerySection}${contactSection}${footer}</body></html>`;
  }

  function openFullPreview(key) {
    const t = TEMPLATES[key];
    if (!t) return;
    currentPreviewKey = key;
    previewLabel.textContent = t.label + ' — Full Preview';
    pageTabs.forEach(tb => tb.classList.toggle('active', tb.dataset.section === 'home'));
    previewFrame.srcdoc = buildPreviewHTML(key);
    fullModal.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeFullPreview() {
    fullModal.classList.remove('open');
    document.body.style.overflow = '';
    previewFrame.srcdoc = '';
    currentPreviewKey = null;
  }

  pageTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      pageTabs.forEach(tb => tb.classList.remove('active'));
      tab.classList.add('active');
      try {
        const iDoc = previewFrame.contentDocument || previewFrame.contentWindow?.document;
        if (iDoc) { const el = iDoc.getElementById(tab.dataset.section); if (el) el.scrollIntoView({ behavior: 'smooth' }); }
      } catch(e) {}
    });
  });

  previewSelBtn.addEventListener('click', () => {
    if (!currentPreviewKey) return;
    const card = document.querySelector(`.template-card[data-template="${currentPreviewKey}"]`);
    if (card) selectCard(card);
    closeFullPreview();
  });
  previewClsBtn.addEventListener('click', closeFullPreview);
  fullModal.addEventListener('click', e => { if (e.target === fullModal) closeFullPreview(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFullPreview(); });
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.preview-tpl-btn');
    if (!btn) return;
    e.stopPropagation();
    const card = btn.closest('.template-card');
    if (card) openFullPreview(card.dataset.template);
  });
});
</script>

<script src="/assets/js/image_uploader.js?v=v210"></script>
<script src="/assets/js/generator.js?v=v301"></script>
