<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site_templates.php';

require_login();
$user  = current_user();
$plan  = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);
$pdo   = get_platform_db();

$gen_limit     = (int) FREE_GENERATE_DAILY_LIMIT;
$gen_used      = 0;
$gen_resets_at = null;

if (!$is_paid) {
    try {
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt   = $pdo->prepare("SELECT COUNT(*) AS c, MIN(created_at) AS first_at FROM utiligo_generated_sites WHERE user_id = ? AND created_at > ?");
        $stmt->execute([$user['id'], $cutoff]);
        $row       = $stmt->fetch(PDO::FETCH_ASSOC);
        $gen_used  = (int)($row['c'] ?? 0);
        if ($row['first_at']) $gen_resets_at = strtotime($row['first_at']) + 86400;
    } catch (\Throwable $e) {}
}

// Active site limit enforcement for paid plans
$site_limit        = plan_site_limit($plan); // 200 pro, 500 ent, 1 free
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
$gen_locked    = (!$is_paid && max(0, $gen_limit - $gen_used) === 0) || $site_limit_hit;

$free_template_limit = (int) FREE_TEMPLATE_LIMIT;
$all_templates       = get_all_site_templates();
$template_keys       = array_keys($all_templates);
$free_keys           = array_slice($template_keys, 0, $free_template_limit);

$prefill = ['business_name'=>'','business_category'=>'','business_city'=>'','business_phone'=>'','business_email'=>''];
if (!empty($_GET['lead_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM utiligo_leads WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_GET['lead_id']]);
    $lead = $stmt->fetch();
    if ($lead) {
        $prefill['business_name']     = $lead['business_name'] ?? '';
        $prefill['business_category'] = $lead['business_category'] ?? '';
        $prefill['business_phone']    = $lead['business_phone'] ?? '';
    }
}

$templateCategories = [];
foreach ($all_templates as $key => $t) {
    $templateCategories[$t['category']][] = $key;
}

$pageTitle = 'Generate Website — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Generate a Website</h1>
  <p class="text-slate-400 text-sm mt-1">Fill in the business details, pick a template, and get a full 5-page site in ~60 seconds.</p>
</div>

<?php if (!$is_paid): ?>
<!-- Free daily quota -->
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
    <?php if($gen_resets_at): ?><span>Resets at <?= date('g:i A', $gen_resets_at) ?></span>
    <?php else: ?><span>Resets 24h after first generation</span><?php endif; ?>
  </div>
  <?php if($gen_locked): ?>
  <div class="mt-3 bg-red-500/10 border border-red-500/20 rounded-xl p-3 text-xs text-red-300 flex items-start gap-2">
    <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
    <span>Daily generation limit reached.<?php if($gen_resets_at): ?> Resets at <strong><?= date('g:i A', $gen_resets_at) ?></strong>.<?php endif; ?>
    <a href="/portal/billing.php?upgrade=1" class="text-white underline ml-1">Upgrade for unlimited.</a></span>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($site_limit_hit): ?>
<!-- Paid plan site limit reached -->
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
      <i class="fa-solid fa-arrow-up mr-1"></i>Upgrade to Entrepreneur
    </a>
    <?php endif; ?>
  </div>
  <?php $sl_pct = $site_limit > 0 ? min(100, round(($active_site_count/$site_limit)*100)) : 100; ?>
  <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden mt-3">
    <div class="h-1.5 rounded-full bg-red-500" style="width:<?= $sl_pct ?>%"></div>
  </div>
</div>

<?php else: ?>
<!-- Paid plan — show usage bar -->
<?php
  $sl_pct = ($site_limit > 0) ? min(100, round(($active_site_count/$site_limit)*100)) : 0;
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
      <i class="fa-solid fa-arrow-up mr-1"></i>Upgrade to Entrepreneur
    </a>
    <?php endif; ?>
  </div>
  <div class="w-full bg-white/5 rounded-full h-1.5 overflow-hidden">
    <div class="h-1.5 rounded-full transition-all <?= $sl_colour ?>" style="width:<?= $sl_pct ?>%"></div>
  </div>
</div>
<?php endif; ?>

<?php if($gen_locked): ?>
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

<form id="generateForm" class="space-y-6">
  <input type="hidden" name="lead_id" value="<?= htmlspecialchars($_GET['lead_id'] ?? '') ?>">
  <input type="hidden" name="template_name" id="selectedTemplateInput" value="modern">

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

  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest">Choose Template</p>
        <p class="text-xs text-slate-500 mt-0.5">
          <?php if(!$is_paid): ?>
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
      <button type="button"
              class="template-card relative text-left rounded-xl overflow-hidden border-2 <?= $locked ? 'border-white/5 opacity-60 cursor-not-allowed' : 'border-transparent hover:border-white/40' ?> transition glass group"
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
        <?php if($locked): ?>
        <div class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-slate-900/70 backdrop-blur-sm">
          <div class="w-7 h-7 rounded-full bg-white/10 flex items-center justify-center mb-1">
            <i class="fa-solid fa-crown text-white text-[10px]"></i>
          </div>
          <span class="text-[10px] font-bold text-white">Pro Only</span>
        </div>
        <?php endif; ?>
        <?php if (!$locked): ?>
        <button type="button"
                class="preview-tpl-btn absolute top-2 right-2 z-20 w-6 h-6 rounded-full bg-black/40 hover:bg-black/70 text-white/80 hover:text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                data-tpl-key="<?= $key ?>"
                title="Preview template">
          <i class="fa-solid fa-eye text-[9px]"></i>
        </button>
        <?php endif; ?>
        <div class="h-20 flex flex-col justify-center px-4"
             style="background:linear-gradient(135deg,<?= $tpl['secondary'] ?> 0%,<?= $tpl['primary'] ?> 100%);">
          <div class="w-10 h-2 rounded-full mb-2" style="background:<?= $tpl['primary'] ?>;opacity:0.6;"></div>
          <div class="w-full h-1.5 rounded-full mb-1" style="background:rgba(255,255,255,0.15);"></div>
          <div class="w-2/3 h-1.5 rounded-full" style="background:rgba(255,255,255,0.1);"></div>
        </div>
        <div class="p-3">
          <p class="font-semibold text-xs flex items-center gap-1.5">
            <?= htmlspecialchars($tpl['label']) ?>
            <?php if(!$locked && $is_free_tpl): ?>
              <span class="text-[10px] bg-white/10 text-white px-1.5 py-0.5 rounded-full">Free</span>
            <?php endif; ?>
          </p>
          <p class="text-[10px] text-slate-400 mt-0.5 leading-snug"><?= htmlspecialchars($tpl['description']) ?></p>
        </div>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if(!$is_paid): ?>
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

<?php endif; ?>

<!-- Template Preview Modal -->
<div id="tplPreviewModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4" style="background:rgba(0,0,0,.7);backdrop-filter:blur(6px);">
  <div class="glass rounded-2xl border border-white/10 shadow-2xl w-full max-w-sm overflow-hidden">
    <div id="tplPreviewBanner" class="h-28 flex flex-col justify-end px-5 pb-4 relative">
      <button type="button" id="tplPreviewClose"
              class="absolute top-3 right-3 w-7 h-7 rounded-full bg-black/40 hover:bg-black/70 text-white flex items-center justify-center">
        <i class="fa-solid fa-xmark text-xs"></i>
      </button>
      <p id="tplPreviewName" class="text-white font-black text-lg leading-tight"></p>
      <p id="tplPreviewCat"  class="text-white/60 text-xs font-semibold"></p>
    </div>
    <div class="p-5 space-y-3">
      <p id="tplPreviewDesc" class="text-sm text-slate-300 leading-relaxed"></p>
      <div class="flex items-center gap-2">
        <div id="tplSwatch1" class="w-7 h-7 rounded-full border-2 border-white/20" title="Primary"></div>
        <div id="tplSwatch2" class="w-7 h-7 rounded-full border-2 border-white/20" title="Secondary"></div>
        <div id="tplSwatch3" class="w-7 h-7 rounded-full border-2 border-white/10" title="Accent"></div>
        <span id="tplPreviewFont" class="text-[11px] text-slate-500 ml-2 italic"></span>
      </div>
      <div class="flex items-center gap-2">
        <div id="tplRadiusDemo" class="h-7 px-4 bg-white/10 text-xs text-white flex items-center font-semibold">Button</div>
        <span class="text-[10px] text-slate-600">border-radius style</span>
      </div>
      <div class="flex gap-1.5 flex-wrap">
        <?php foreach (['Home','About','Services','Gallery','Contact'] as $pg): ?>
          <span class="text-[10px] bg-white/8 text-slate-400 px-2 py-0.5 rounded-full"><?= $pg ?></span>
        <?php endforeach; ?>
      </div>
      <button type="button" id="tplPreviewSelect"
              class="w-full bg-white hover:bg-slate-200 text-black py-2.5 rounded-xl font-bold text-sm transition">
        Use This Template
      </button>
    </div>
  </div>
</div>

<div id="genProgressWrap" class="hidden glass rounded-2xl p-10 text-center border border-white/5">
  <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-white/10 mb-4">
    <i class="fa-solid fa-spinner fa-spin text-white text-2xl"></i>
  </div>
  <p id="genProgressLabel" class="text-slate-300 font-semibold mb-4">Starting&hellip;</p>
  <div class="gen-progress-bar max-w-sm mx-auto">
    <div id="genProgressFill" class="gen-progress-fill" style="width:0%"></div>
  </div>
</div>

<div id="genDownloadWrap" class="hidden glass rounded-2xl p-10 text-center border border-white/10">
  <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-white/10 mb-4">
    <i class="fa-solid fa-circle-check text-white text-3xl"></i>
  </div>
  <h3 class="text-xl font-bold mb-2">Your website is ready!</h3>
  <p class="text-slate-400 text-sm mb-6">5 pages generated: Home, About, Services, Gallery, Contact.</p>
  <div class="flex gap-3 justify-center mb-6 flex-wrap">
    <a id="genEditLink" href="#" class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/15 text-white px-5 py-2.5 rounded-xl font-semibold">
      <i class="fa-solid fa-pen"></i>Edit Site
    </a>
    <a id="genPreviewLink" href="#" target="_blank" class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/15 text-white px-5 py-2.5 rounded-xl font-semibold">
      <i class="fa-solid fa-eye"></i>Preview
    </a>
    <a id="genDownloadLink" href="#" class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 text-black px-5 py-2.5 rounded-xl font-bold">
      <i class="fa-solid fa-download"></i>Download ZIP
    </a>
  </div>
  <div id="genShareLinkWrap" class="hidden bg-white/5 rounded-xl p-4 max-w-md mx-auto">
    <p class="text-xs text-slate-400 mb-2"><i class="fa-solid fa-link mr-1"></i>Shareable link (expires in 7 days)</p>
    <div class="flex gap-2">
      <input id="genShareLinkInput" type="text" readonly class="flex-1 bg-slate-800 border border-slate-600 text-white text-sm rounded-xl px-3 py-2">
      <button id="genShareLinkCopy" type="button" class="bg-white/10 hover:bg-white/20 text-white text-sm px-4 py-2 rounded-xl font-semibold">Copy</button>
    </div>
    <a href="/portal/my_sites.php" class="text-xs text-white/60 hover:text-white mt-2 inline-block">Manage all sites &rarr;</a>
  </div>
</div>

<script>
document.addEventListener('click', function(e) {
  const card = e.target.closest('.template-card');
  if (!card) return;
  if (card.dataset.locked === '1') { window.location.href = '/portal/billing.php?upgrade=1'; return; }
  if (e.target.closest('.preview-tpl-btn')) return;
  document.querySelectorAll('.template-card').forEach(c => c.classList.remove('border-white'));
  card.classList.add('border-white');
  document.getElementById('selectedTemplateInput').value = card.dataset.template;
  const lbl = document.getElementById('selectedTemplateLabel');
  lbl.textContent = card.dataset.label;
  lbl.classList.remove('hidden');
});
window.addEventListener('DOMContentLoaded', function() {
  const first = document.querySelector('.template-card:not([data-locked])');
  if (first) first.click();
});

const modal      = document.getElementById('tplPreviewModal');
const mBanner    = document.getElementById('tplPreviewBanner');
const mName      = document.getElementById('tplPreviewName');
const mCat       = document.getElementById('tplPreviewCat');
const mDesc      = document.getElementById('tplPreviewDesc');
const mSwatch1   = document.getElementById('tplSwatch1');
const mSwatch2   = document.getElementById('tplSwatch2');
const mSwatch3   = document.getElementById('tplSwatch3');
const mFont      = document.getElementById('tplPreviewFont');
const mRadius    = document.getElementById('tplRadiusDemo');
const mSelectBtn = document.getElementById('tplPreviewSelect');
let   previewKey = null;

document.addEventListener('click', function(e) {
  const btn = e.target.closest('.preview-tpl-btn');
  if (!btn) return;
  e.stopPropagation();
  const card = btn.closest('.template-card');
  previewKey = card.dataset.template;
  mBanner.style.background = `linear-gradient(135deg,${card.dataset.secondary} 0%,${card.dataset.primary} 100%)`;
  mName.textContent  = card.dataset.label;
  mCat.textContent   = card.dataset.font;
  mDesc.textContent  = card.dataset.description;
  mSwatch1.style.background = card.dataset.primary;
  mSwatch2.style.background = card.dataset.secondary;
  mSwatch3.style.background = card.dataset.accent;
  mFont.textContent  = card.dataset.font;
  mRadius.style.borderRadius = card.dataset.radius;
  modal.classList.remove('hidden');
});
document.getElementById('tplPreviewClose').addEventListener('click', () => modal.classList.add('hidden'));
modal.addEventListener('click', e => { if (e.target === modal) modal.classList.add('hidden'); });
mSelectBtn.addEventListener('click', function() {
  if (!previewKey) return;
  const card = document.querySelector(`.template-card[data-template="${previewKey}"]`);
  if (card) card.click();
  modal.classList.add('hidden');
});
</script>

</div></main>
<script src="/assets/js/image_uploader.js?v=v210"></script>
<script src="/assets/js/generator.js?v=v210"></script>
</body></html>
