<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_pro();
$user = current_user();
$pdo = get_platform_db();

$siteId = (int)($_GET['site_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM utiligo_generated_sites WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$siteId, $user['id']]);
$site = $stmt->fetch();

if (!$site) {
    header('Location: /portal/my_sites.php');
    exit;
}

$pages = ['index' => 'Home', 'about' => 'About', 'services' => 'Services', 'gallery' => 'Gallery', 'contact' => 'Contact'];
$currentPage = $_GET['page'] ?? 'index';
if (!array_key_exists($currentPage, $pages)) $currentPage = 'index';

// Use stored public_slug as folder name — it includes the uniqid suffix
// used during generation, so it always points to the real folder on disk.
$slugDir = $site['public_slug'] ?: (slugify($site['business_name']) . '-' . $site['id']);

$pageTitle = 'Edit Site — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-full px-4 py-6" data-site-id="<?= (int)$site['id'] ?>" id="editorRoot">
  <a href="/portal/my_sites.php" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white mb-4">
    <i class="fa-solid fa-arrow-left"></i> Back to My Sites
  </a>
  <div class="flex items-center justify-between gap-4 flex-wrap mb-4">
    <div>
      <h1 class="text-xl font-bold"><?= htmlspecialchars($site['business_name']) ?> <span class="text-slate-500 font-normal text-sm">— Site Editor</span></h1>
      <p class="text-slate-400 text-xs">Click any text to edit it, click any image to replace it, use the toolbar for formatting/colors, and drag the <i class="fa-solid fa-grip-lines mx-0.5"></i> handle on a section to reorder it.</p>
    </div>
    <div class="flex items-center gap-2">
      <button type="button" id="undoBtn" title="Undo (Ctrl+Z)" disabled
        class="text-xs bg-white/5 hover:bg-white/10 disabled:opacity-30 disabled:cursor-not-allowed text-slate-300 w-9 h-9 rounded-full font-semibold flex items-center justify-center">
        <i class="fa-solid fa-rotate-left"></i>
      </button>
      <button type="button" id="redoBtn" title="Redo (Ctrl+Shift+Z)" disabled
        class="text-xs bg-white/5 hover:bg-white/10 disabled:opacity-30 disabled:cursor-not-allowed text-slate-300 w-9 h-9 rounded-full font-semibold flex items-center justify-center">
        <i class="fa-solid fa-rotate-right"></i>
      </button>
      <span id="saveStatus" class="text-xs text-slate-500 ml-1"></span>
      <a href="/portal/my_sites.php" class="text-xs bg-white/5 hover:bg-white/10 text-slate-300 px-4 py-2 rounded-full font-semibold">Done Editing</a>
    </div>
  </div>

  <div class="flex gap-2 mb-4 flex-wrap">
    <?php foreach ($pages as $key => $label): ?>
      <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>&page=<?= $key ?>"
         class="text-xs px-4 py-2 rounded-full font-semibold <?= $key === $currentPage ? 'bg-emerald-500 text-slate-950' : 'bg-white/5 text-slate-300 hover:bg-white/10' ?>">
        <?= $label ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Floating formatting toolbar, shown when a text block is focused -->
  <div id="formatToolbar" class="hidden fixed z-50 glass rounded-xl p-2 flex items-center gap-1 shadow-2xl">
    <button type="button" data-cmd="bold" class="toolbar-btn w-8 h-8 rounded hover:bg-white/10 font-bold text-sm">B</button>
    <button type="button" data-cmd="italic" class="toolbar-btn w-8 h-8 rounded hover:bg-white/10 italic text-sm">I</button>
    <button type="button" data-cmd="underline" class="toolbar-btn w-8 h-8 rounded hover:bg-white/10 underline text-sm">U</button>
    <div class="w-px h-6 bg-white/10 mx-1"></div>
    <input type="color" id="textColorPicker" class="w-8 h-8 rounded cursor-pointer bg-transparent" title="Text color">
    <div class="w-px h-6 bg-white/10 mx-1"></div>
    <button type="button" id="clearFormatBtn" class="toolbar-btn w-8 h-8 rounded hover:bg-white/10 text-xs" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
  </div>

  <!-- Image replace popover -->
  <div id="imageToolbar" class="hidden fixed z-50 glass rounded-xl p-3 shadow-2xl w-64">
    <p class="text-xs text-slate-400 mb-2">Replace this image</p>
    <div id="imageDropzone" class="dropzone rounded-lg p-4 text-center cursor-pointer text-xs text-slate-500">
      <i class="fa-solid fa-cloud-arrow-up mb-1 block"></i>Drop or click to upload
    </div>
    <input type="file" id="imageFileInput" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden">
  </div>

  <!-- Background color popover (for sections) -->
  <div id="bgToolbar" class="hidden fixed z-50 glass rounded-xl p-3 shadow-2xl">
    <p class="text-xs text-slate-400 mb-2">Section background color</p>
    <input type="color" id="bgColorPicker" class="w-full h-10 rounded cursor-pointer bg-transparent">
  </div>

  <div class="glass rounded-xl overflow-hidden" style="height:calc(100vh - 220px);">
    <iframe id="siteFrame"
            src="/assets/uploads/generated_sites/<?= htmlspecialchars($slugDir) ?>/<?= $currentPage ?>.html?edit=1&t=<?= time() ?>"
            class="w-full h-full border-0 bg-white"></iframe>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/site_editor.js?v=v162"></script>
