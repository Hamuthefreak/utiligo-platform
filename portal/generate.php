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

// Safe constant fallbacks — in case config.php on the live server hasn't updated yet
if (!defined('FREE_GENERATE_DAILY_LIMIT')) define('FREE_GENERATE_DAILY_LIMIT', 1);
if (!defined('FREE_TEMPLATE_LIMIT'))       define('FREE_TEMPLATE_LIMIT', 2);

// ---- Daily generation quota (free users only) ----
$gen_limit     = (int)FREE_GENERATE_DAILY_LIMIT;
$gen_used      = 0;
$gen_resets_at = null;
if (!$is_pro) {
    try {
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt   = $pdo->prepare(
            "SELECT COUNT(*) AS c, MIN(created_at) AS first_at
             FROM utiligo_generated_sites
             WHERE user_id = ? AND created_at > ?"
        );
        $stmt->execute([$user['id'], $cutoff]);
        $row       = $stmt->fetch(PDO::FETCH_ASSOC);
        $gen_used  = (int)($row['c'] ?? 0);
        if ($row['first_at']) $gen_resets_at = strtotime($row['first_at']) + 86400;
    } catch (\Throwable $e) {}
}
$gen_remaining = $is_pro ? PHP_INT_MAX : max(0, $gen_limit - $gen_used);
$gen_pct       = ($gen_limit > 0 && !$is_pro) ? min(100, round(($gen_used / $gen_limit) * 100)) : 0;
$gen_locked    = !$is_pro && $gen_remaining === 0;

// ---- Template access ----
$free_template_limit = (int)FREE_TEMPLATE_LIMIT;
$all_templates       = get_all_site_templates();
$template_keys       = array_keys($all_templates);
$free_keys           = array_slice($template_keys, 0, $free_template_limit);

// ---- Lead prefill ----
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

<?php if (!$is_pro): ?>
<div class="glass rounded-2xl p-5 mb-8 border border-white/5">
  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
    <div class="flex items-center gap-2">
      <div class="w-8 h-8 rounded-xl bg-blue-500/15 flex items-center justify-center">
        <i class="fa-solid fa-bolt text-blue-400 text-xs"></i>
      </div>
      <div>
        <p class="text-sm font-semibold">Daily Generation Quota</p>
        <p class="text-xs text-slate-400">Resets every 24 hours</p>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <div class="flex items-center gap-1.5 <?= $gen_remaining===0 ? 'bg-red-500/10 border border-red-500/20 text-red-400' : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' ?> rounded-full px-3 py-1 text-xs font-bold">
        <i class="fa-solid fa-<?= $gen_remaining===0 ? 'ban' : 'circle-check' ?>"></i>
        <?= $gen_remaining===0 ? 'Generation used today' : $gen_remaining.' site left today' ?>
      </div>
      <a href="/portal/billing.php?upgrade=1" class="text-xs bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-4 py-1.5 rounded-full font-bold">
        <i class="fa-solid fa-crown mr-1"></i>Go Pro
      </a>
    </div>
  </div>
  <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
    <div class="h-2 rounded-full transition-all <?= $gen_pct>=100 ? 'bg-red-500' : 'bg-blue-500' ?>" style="width:<?= $gen_pct ?>%"></div>
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
    <a href="/portal/billing.php?upgrade=1" class="text-emerald-400 underline ml-1">Upgrade for unlimited.</a></span>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="flex items-center gap-2 bg-emerald-500/10 border border-emerald-500/20 rounded-full px-4 py-2 text-sm mb-8 w-fit">
  <i class="fa-solid fa-crown text-emerald-400"></i>
  <span class="text-emerald-300 font-semibold">Pro Plan &mdash; Unlimited generations</span>
</div>
<?php endif; ?>

<?php if($gen_locked): ?>
<div class="glass rounded-2xl p-12 text-center border border-red-500/20 mb-8">
  <div class="w-16 h-16 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-4">
    <i class="fa-solid fa-lock text-red-400 text-2xl"></i>
  </div>
  <p class="font-bold text-lg mb-2">Come back tomorrow</p>
  <p class="text-slate-400 text-sm mb-5 max-w-sm mx-auto">
    You&rsquo;ve used your free generation for today.
    <?php if($gen_resets_at): ?>Resets at <strong class="text-white"><?= date('g:i A', $gen_resets_at) ?></strong>.<?php endif; ?>
  </p>
  <a href="/portal/billing.php?upgrade=1"
     class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-8 py-3 rounded-xl font-bold">
    <i class="fa-solid fa-crown"></i> Upgrade for Unlimited
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
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-emerald-500 transition">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Category / Industry</label>
        <input type="text" name="business_category" required value="<?= htmlspecialchars($prefill['business_category']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-emerald-500 transition">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">City</label>
        <input type="text" name="business_city" required value="<?= htmlspecialchars($prefill['business_city']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-emerald-500 transition">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Phone</label>
        <input type="text" name="business_phone" value="<?= htmlspecialchars($prefill['business_phone']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-emerald-500 transition">
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Contact Email</label>
        <input type="email" name="business_email" value="<?= htmlspecialchars($prefill['business_email']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-emerald-500 transition">
      </div>
    </div>
  </div>

  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest">Choose Template</p>
        <p class="text-xs text-slate-500 mt-0.5">
          <?php if(!$is_pro): ?>
            <span class="text-amber-400 font-semibold"><?= $free_template_limit ?> free</span> &mdash;
            <?= count($all_templates) - $free_template_limit ?> more unlocked with Pro
          <?php else: ?>
            <?= count($all_templates) ?> templates available
          <?php endif; ?>
        </p>
      </div>
      <span id="selectedTemplateLabel" class="text-xs px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-400 hidden"></span>
    </div>

    <?php foreach ($templateCategories as $categoryName => $keys): ?>
    <p class="text-xs uppercase tracking-wider text-slate-600 mt-5 mb-3"><?= htmlspecialchars($categoryName) ?></p>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <?php foreach ($keys as $key):
        $tpl         = $all_templates[$key];
        $is_free_tpl = in_array($key, $free_keys, true);
        $locked      = !$is_pro && !$is_free_tpl;
      ?>
      <button type="button"
              class="template-card relative text-left rounded-xl overflow-hidden border-2 <?= $locked ? 'border-white/5 opacity-60 cursor-not-allowed' : 'border-transparent hover:border-emerald-400/60' ?> transition glass"
              data-template="<?= $key ?>"
              data-label="<?= htmlspecialchars($tpl['label']) ?>"
              <?= $locked ? 'data-locked="1"' : '' ?>>
        <?php if($locked): ?>
        <div class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-slate-900/70 backdrop-blur-sm">
          <div class="w-8 h-8 rounded-full bg-amber-500/20 flex items-center justify-center mb-1.5">
            <i class="fa-solid fa-crown text-amber-400 text-xs"></i>
          </div>
          <span class="text-xs font-bold text-amber-300">Pro Only</span>
        </div>
        <?php endif; ?>
        <div class="h-20 flex flex-col justify-center px-4"
             style="background:<?= $tpl['dark'] ?? false ? $tpl['secondary'] : $tpl['accent'] ?>;">
          <div class="w-10 h-2 rounded-full mb-2" style="background:<?= $tpl['primary'] ?>;"></div>
          <div class="w-full h-1.5 rounded-full mb-1" style="background:<?= $tpl['dark'] ?? false ? '#ffffff22' : '#00000012' ?>;"></div>
          <div class="w-2/3 h-1.5 rounded-full" style="background:<?= $tpl['dark'] ?? false ? '#ffffff22' : '#00000012' ?>;"></div>
        </div>
        <div class="p-3">
          <p class="font-semibold text-xs flex items-center gap-1.5">
            <?= htmlspecialchars($tpl['label']) ?>
            <?php if(!$locked && $is_free_tpl): ?>
              <span class="text-[10px] bg-emerald-500/20 text-emerald-400 px-1.5 py-0.5 rounded-full">Free</span>
            <?php endif; ?>
          </p>
          <p class="text-[11px] text-slate-400 mt-1 leading-snug"><?= htmlspecialchars($tpl['description']) ?></p>
        </div>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php if(!$is_pro): ?>
    <div class="mt-5 p-4 rounded-xl border border-amber-500/20 bg-amber-500/5 flex items-center gap-3">
      <i class="fa-solid fa-crown text-amber-400"></i>
      <div class="flex-1 text-sm">
        <span class="font-semibold text-amber-300">Unlock all <?= count($all_templates) ?> templates</span>
        <span class="text-slate-400"> plus unlimited generations with Pro.</span>
      </div>
      <a href="/portal/billing.php?upgrade=1" class="text-xs bg-amber-500 hover:bg-amber-400 text-slate-950 px-4 py-2 rounded-xl font-bold whitespace-nowrap">Upgrade</a>
    </div>
    <?php endif; ?>
  </div>

  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-1">Custom Images <span class="text-slate-600 normal-case font-normal">(optional)</span></p>
    <p class="text-slate-400 text-xs mb-5">Drag &amp; drop your own photos, or leave blank to use stock images.</p>
    <div class="grid md:grid-cols-3 gap-4">
      <div class="upload-slot" data-slot="hero">
        <p class="text-xs text-slate-500 mb-2">Hero Image</p>
        <div class="dropzone rounded-xl border border-dashed border-slate-600 hover:border-emerald-500 p-4 text-center cursor-pointer relative h-28 flex flex-col items-center justify-center overflow-hidden transition">
          <img class="upload-preview hidden absolute inset-0 w-full h-full object-cover" alt="">
          <div class="upload-placeholder"><i class="fa-solid fa-cloud-arrow-up text-slate-500 text-xl mb-1"></i><p class="text-xs text-slate-500">Drop or click</p></div>
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="upload-input hidden">
        </div>
        <input type="hidden" name="custom_image_hero" class="upload-result-input">
      </div>
      <div class="upload-slot" data-slot="about">
        <p class="text-xs text-slate-500 mb-2">About Image</p>
        <div class="dropzone rounded-xl border border-dashed border-slate-600 hover:border-emerald-500 p-4 text-center cursor-pointer relative h-28 flex flex-col items-center justify-center overflow-hidden transition">
          <img class="upload-preview hidden absolute inset-0 w-full h-full object-cover" alt="">
          <div class="upload-placeholder"><i class="fa-solid fa-cloud-arrow-up text-slate-500 text-xl mb-1"></i><p class="text-xs text-slate-500">Drop or click</p></div>
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="upload-input hidden">
        </div>
        <input type="hidden" name="custom_image_about" class="upload-result-input">
      </div>
      <div class="upload-slot" data-slot="gallery">
        <p class="text-xs text-slate-500 mb-2">Gallery (up to 6)</p>
        <div class="dropzone rounded-xl border border-dashed border-slate-600 hover:border-emerald-500 p-4 text-center cursor-pointer relative h-28 flex flex-col items-center justify-center overflow-hidden transition" data-multi="1">
          <div class="gallery-preview-grid hidden absolute inset-0 grid grid-cols-3 gap-0.5"></div>
          <div class="upload-placeholder"><i class="fa-solid fa-images text-slate-500 text-xl mb-1"></i><p class="text-xs text-slate-500">Multiple OK</p></div>
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="upload-input hidden">
        </div>
        <input type="hidden" name="custom_images_gallery" class="upload-result-input">
      </div>
    </div>
  </div>

  <button type="submit"
          class="w-full bg-emerald-500 hover:bg-emerald-400 active:scale-[.98] text-slate-950 py-4 rounded-xl font-bold text-base transition-all shadow-lg shadow-emerald-500/20">
    <i class="fa-solid fa-bolt mr-2"></i>Generate Website
  </button>
</form>

<?php endif; // !gen_locked ?>

<div id="genProgressWrap" class="hidden glass rounded-2xl p-10 text-center border border-white/5">
  <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-blue-500/10 mb-4">
    <i class="fa-solid fa-spinner fa-spin text-blue-400 text-2xl"></i>
  </div>
  <p id="genProgressLabel" class="text-slate-300 font-semibold mb-4">Starting&hellip;</p>
  <div class="gen-progress-bar max-w-sm mx-auto">
    <div id="genProgressFill" class="gen-progress-fill" style="width:0%"></div>
  </div>
</div>

<div id="genDownloadWrap" class="hidden glass rounded-2xl p-10 text-center border border-emerald-500/20">
  <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-500/15 mb-4">
    <i class="fa-solid fa-circle-check text-emerald-400 text-3xl"></i>
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
    <a id="genDownloadLink" href="#" class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-5 py-2.5 rounded-xl font-bold">
      <i class="fa-solid fa-download"></i>Download ZIP
    </a>
  </div>
  <div id="genShareLinkWrap" class="hidden bg-white/5 rounded-xl p-4 max-w-md mx-auto">
    <p class="text-xs text-slate-400 mb-2"><i class="fa-solid fa-link mr-1"></i>Shareable link (expires in 7 days)</p>
    <div class="flex gap-2">
      <input id="genShareLinkInput" type="text" readonly class="flex-1 bg-slate-800 border border-slate-600 text-white text-sm rounded-xl px-3 py-2">
      <button id="genShareLinkCopy" type="button" class="bg-white/10 hover:bg-white/20 text-white text-sm px-4 py-2 rounded-xl font-semibold">Copy</button>
    </div>
    <a href="/portal/my_sites.php" class="text-xs text-emerald-400 hover:text-emerald-300 mt-2 inline-block">Manage all sites &rarr;</a>
  </div>
</div>

<script>
document.addEventListener('click', function(e) {
  const card = e.target.closest('.template-card');
  if (!card) return;
  if (card.dataset.locked === '1') { window.location.href = '/portal/billing.php?upgrade=1'; return; }
  document.querySelectorAll('.template-card').forEach(c => c.classList.remove('border-emerald-400'));
  card.classList.add('border-emerald-400');
  document.getElementById('selectedTemplateInput').value = card.dataset.template;
  const lbl = document.getElementById('selectedTemplateLabel');
  lbl.textContent = card.dataset.label;
  lbl.classList.remove('hidden');
});
window.addEventListener('DOMContentLoaded', function() {
  const first = document.querySelector('.template-card:not([data-locked])');
  if (first) first.click();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/image_uploader.js?v=v166"></script>
<script src="/assets/js/generator.js?v=v166"></script>
