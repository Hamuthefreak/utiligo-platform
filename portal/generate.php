<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site_templates.php';

require_pro();
$user = current_user();
$pdo = get_platform_db();

$prefill = ['business_name' => '', 'business_category' => '', 'business_city' => '', 'business_phone' => '', 'business_email' => ''];
if (!empty($_GET['lead_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM utiligo_leads WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_GET['lead_id']]);
    $lead = $stmt->fetch();
    if ($lead) {
        $prefill['business_name'] = $lead['business_name'] ?? '';
        $prefill['business_category'] = $lead['business_category'] ?? '';
        $prefill['business_phone'] = $lead['business_phone'] ?? '';
    }
}

$templates = get_all_site_templates();
$templateCategories = [];
foreach ($templates as $key => $t) {
    $templateCategories[$t['category']][] = $key;
}

$pageTitle = 'Generate Website — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-5xl mx-auto px-6 py-10">
  <a href="/portal/index.php" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white mb-6">
    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
  </a>
  <h1 class="text-2xl font-bold mb-2">Generate a Website</h1>
  <p class="text-slate-400 text-sm mb-8">Enter the business details, pick a template, and we'll build a complete 5-page site (Home, About, Services, Gallery, Contact) in about a minute.</p>

  <form id="generateForm" class="space-y-8">
    <input type="hidden" name="lead_id" value="<?= htmlspecialchars($_GET['lead_id'] ?? '') ?>">
    <input type="hidden" name="template_name" id="selectedTemplateInput" value="modern">

    <div class="glass rounded-xl p-6 space-y-4">
      <h2 class="font-semibold text-lg mb-2">Business Details</h2>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm mb-2">Business Name</label>
          <input type="text" name="business_name" required value="<?= htmlspecialchars($prefill['business_name']) ?>" class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        </div>
        <div>
          <label class="block text-sm mb-2">Category / Industry</label>
          <input type="text" name="business_category" required value="<?= htmlspecialchars($prefill['business_category']) ?>" class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        </div>
        <div>
          <label class="block text-sm mb-2">City</label>
          <input type="text" name="business_city" required value="<?= htmlspecialchars($prefill['business_city']) ?>" class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        </div>
        <div>
          <label class="block text-sm mb-2">Phone</label>
          <input type="text" name="business_phone" value="<?= htmlspecialchars($prefill['business_phone']) ?>" class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm mb-2">Contact Email</label>
          <input type="email" name="business_email" value="<?= htmlspecialchars($prefill['business_email']) ?>" class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        </div>
      </div>
    </div>

    <div>
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold text-lg">Choose a Template <span class="text-slate-500 font-normal text-sm">(<?= count($templates) ?> available)</span></h2>
        <span id="selectedTemplateLabel" class="text-xs px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-400"></span>
      </div>

      <?php foreach ($templateCategories as $categoryName => $keys): ?>
        <p class="text-xs uppercase tracking-wider text-slate-500 mt-6 mb-3"><?= htmlspecialchars($categoryName) ?></p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <?php foreach ($keys as $key): $tpl = $templates[$key]; ?>
            <button type="button" class="template-card text-left rounded-xl overflow-hidden border-2 border-transparent hover:border-emerald-400/60 transition glass"
                    data-template="<?= $key ?>" data-label="<?= htmlspecialchars($tpl['label']) ?>">
              <div class="h-24 flex flex-col justify-center px-4"
                   style="background:<?= $tpl['dark'] ?? false ? $tpl['secondary'] : $tpl['accent'] ?>;">
                <div class="w-10 h-2 rounded-full mb-2" style="background:<?= $tpl['primary'] ?>;"></div>
                <div class="w-full h-1.5 rounded-full mb-1" style="background:<?= $tpl['dark'] ?? false ? '#ffffff33' : '#00000014' ?>;"></div>
                <div class="w-2/3 h-1.5 rounded-full" style="background:<?= $tpl['dark'] ?? false ? '#ffffff33' : '#00000014' ?>;"></div>
              </div>
              <div class="p-3">
                <p class="font-semibold text-sm"><?= htmlspecialchars($tpl['label']) ?></p>
                <p class="text-xs text-slate-400 mt-1 leading-snug"><?= htmlspecialchars($tpl['description']) ?></p>
              </div>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="glass rounded-xl p-6 space-y-4">
      <h2 class="font-semibold text-lg mb-1">Custom Images <span class="text-slate-500 font-normal text-sm">(optional)</span></h2>
      <p class="text-slate-400 text-sm mb-2">Drag &amp; drop your own photos, or leave blank to use stock images matching the business category.</p>
      <div class="grid md:grid-cols-3 gap-4">
        <div class="upload-slot" data-slot="hero">
          <p class="text-xs uppercase tracking-wider text-slate-500 mb-2">Hero Image</p>
          <div class="dropzone rounded-xl p-4 text-center cursor-pointer relative h-32 flex flex-col items-center justify-center overflow-hidden">
            <img class="upload-preview hidden absolute inset-0 w-full h-full object-cover" alt="">
            <div class="upload-placeholder">
              <i class="fa-solid fa-cloud-arrow-up text-slate-500 text-xl mb-1"></i>
              <p class="text-xs text-slate-500">Drop or click</p>
            </div>
            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="upload-input hidden">
          </div>
          <input type="hidden" name="custom_image_hero" class="upload-result-input">
        </div>
        <div class="upload-slot" data-slot="about">
          <p class="text-xs uppercase tracking-wider text-slate-500 mb-2">About Image</p>
          <div class="dropzone rounded-xl p-4 text-center cursor-pointer relative h-32 flex flex-col items-center justify-center overflow-hidden">
            <img class="upload-preview hidden absolute inset-0 w-full h-full object-cover" alt="">
            <div class="upload-placeholder">
              <i class="fa-solid fa-cloud-arrow-up text-slate-500 text-xl mb-1"></i>
              <p class="text-xs text-slate-500">Drop or click</p>
            </div>
            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="upload-input hidden">
          </div>
          <input type="hidden" name="custom_image_about" class="upload-result-input">
        </div>
        <div class="upload-slot" data-slot="gallery">
          <p class="text-xs uppercase tracking-wider text-slate-500 mb-2">Gallery (up to 6)</p>
          <div class="dropzone rounded-xl p-4 text-center cursor-pointer relative h-32 flex flex-col items-center justify-center overflow-hidden" data-multi="1">
            <div class="gallery-preview-grid hidden absolute inset-0 grid grid-cols-3 gap-0.5"></div>
            <div class="upload-placeholder">
              <i class="fa-solid fa-images text-slate-500 text-xl mb-1"></i>
              <p class="text-xs text-slate-500">Drop or click (multiple)</p>
            </div>
            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="upload-input hidden">
          </div>
          <input type="hidden" name="custom_images_gallery" class="upload-result-input">
        </div>
      </div>
      <p id="uploadStatus" class="text-xs text-slate-500"></p>
    </div>

    <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">
      <i class="fa-solid fa-bolt mr-2"></i>Generate Website
    </button>
  </form>

  <div id="genProgressWrap" class="hidden glass rounded-xl p-8 text-center">
    <p id="genProgressLabel" class="text-sm text-slate-400 mb-4">Starting...</p>
    <div class="gen-progress-bar">
      <div id="genProgressFill" class="gen-progress-fill" style="width:0%"></div>
    </div>
  </div>

  <div id="genDownloadWrap" class="hidden glass rounded-xl p-8 text-center">
    <div class="text-4xl mb-4">✅</div>
    <h3 class="text-xl font-bold mb-2">Your website is ready!</h3>
    <p class="text-slate-400 text-sm mb-6">5 pages generated: Home, About, Services, Gallery, Contact.</p>
    <div class="flex gap-3 justify-center mb-5 flex-wrap">
      <a id="genEditLink" href="#" class="inline-block bg-white/10 hover:bg-white/20 text-white px-6 py-3 rounded-full font-semibold">
        <i class="fa-solid fa-pen mr-2"></i>Edit Site
      </a>
      <a id="genPreviewLink" href="#" target="_blank" class="inline-block bg-white/10 hover:bg-white/20 text-white px-6 py-3 rounded-full font-semibold">
        <i class="fa-solid fa-eye mr-2"></i>Preview Site
      </a>
      <a id="genDownloadLink" href="#" class="inline-block bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-3 rounded-full font-semibold">
        <i class="fa-solid fa-download mr-2"></i>Download ZIP
      </a>
    </div>
    <div id="genShareLinkWrap" class="hidden bg-white/5 rounded-lg p-4 max-w-md mx-auto">
      <p class="text-xs text-slate-400 mb-2"><i class="fa-solid fa-link mr-1"></i>Shareable link (expires in 7 days)</p>
      <div class="flex gap-2">
        <input id="genShareLinkInput" type="text" readonly class="flex-1 bg-slate-800 border border-slate-600 text-white text-sm rounded-lg px-3 py-2">
        <button id="genShareLinkCopy" type="button" class="bg-white/10 hover:bg-white/20 text-white text-sm px-4 py-2 rounded-lg font-semibold whitespace-nowrap">Copy</button>
      </div>
      <a href="/portal/my_sites.php" class="text-xs text-emerald-400 hover:text-emerald-300 mt-2 inline-block">Manage all sites &amp; links &rarr;</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/image_uploader.js?v=v162"></script>
<script src="/assets/js/generator.js?v=v162"></script>

