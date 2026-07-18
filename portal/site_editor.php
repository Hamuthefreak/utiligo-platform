<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_paid();
$user = current_user();
$pdo  = get_platform_db();

$siteId = (int)($_GET['site_id'] ?? 0);
$stmt   = $pdo->prepare('SELECT * FROM utiligo_generated_sites WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$siteId, $user['id']]);
$site = $stmt->fetch();

if (!$site) { header('Location: /portal/my_sites.php'); exit; }

$pages       = ['index'=>'Home','about'=>'About','services'=>'Services','gallery'=>'Gallery','contact'=>'Contact'];
$currentPage = $_GET['page'] ?? 'index';
if (!array_key_exists($currentPage, $pages)) $currentPage = 'index';

$slugDir = $site['public_slug'] ?: (slugify($site['business_name']) . '-' . $site['id']);

$_logo_path = __DIR__ . '/../assets/images/logo.png';
$_logo_url  = '/assets/images/logo.png';
$_has_logo  = file_exists($_logo_path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Site Editor — <?= htmlspecialchars($site['business_name']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
* { box-sizing: border-box; }
html, body { height: 100%; margin: 0; overflow: hidden; background: #080c14; color: #fff; font-family: 'Inter', system-ui, sans-serif; }
#editorShell { display: flex; height: 100vh; width: 100vw; overflow: hidden; }

/* Left sidebar */
#editorSidebar {
  width: 220px; flex-shrink: 0;
  background: #0d1117;
  border-right: 1px solid rgba(255,255,255,.06);
  display: flex; flex-direction: column; overflow: hidden;
}
#sbTop { padding: 14px 16px 12px; border-bottom: 1px solid rgba(255,255,255,.06); display: flex; flex-direction: column; gap: 8px; }
#sbBrand { display: flex; align-items: center; gap: 8px; }
#sbBrand img  { height: 22px; width: auto; }
#sbBrand span { font-size: 13px; font-weight: 800; letter-spacing: -.01em; color: #fff; }
#sbSiteName   { font-size: 11px; color: #64748b; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Pages */
#sbPages { padding: 12px 10px 6px; }
.section-label { font-size: 9px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #334155; padding: 0 6px; margin-bottom: 6px; }
.page-item { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 9px; font-size: 12px; font-weight: 500; color: #64748b; cursor: pointer; transition: background .13s, color .13s; text-decoration: none; margin-bottom: 1px; }
.page-item:hover  { background: rgba(255,255,255,.05); color: #e2e8f0; }
.page-item.active { background: rgba(255,255,255,.1); color: #ffffff; font-weight: 700; }
.page-item i { width: 14px; text-align: center; font-size: 11px; }
.page-item .page-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: .4; flex-shrink: 0; }
.page-item.active .page-dot { opacity: 1; }

/* Tools */
#sbTools { padding: 6px 10px; border-top: 1px solid rgba(255,255,255,.05); }
.tool-btn { display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 10px; border-radius: 9px; border: none; background: transparent; font-size: 12px; font-weight: 500; color: #64748b; cursor: pointer; transition: background .13s, color .13s; text-align: left; margin-bottom: 1px; }
.tool-btn:hover { background: rgba(255,255,255,.05); color: #e2e8f0; }
.tool-btn i { width: 14px; text-align: center; font-size: 11px; }
.tool-btn .tool-hint { font-size: 10px; color: #334155; margin-left: auto; }

/* Hint box */
#sbHint { margin: 10px; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06); font-size: 10px; color: #475569; line-height: 1.7; transition: all .2s; }
#sbHint strong { color: #94a3b8; }
#sbHint.collapsed { display: none; }

/* Bottom actions */
#sbBottom { padding: 10px; border-top: 1px solid rgba(255,255,255,.06); display: flex; flex-direction: column; gap: 6px; }
.sb-action { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .13s; text-decoration: none; border: none; }
.sb-action.primary   { background: #fff; color: #000; }
.sb-action.primary:hover { background: #e2e8f0; }
.sb-action.secondary { background: rgba(255,255,255,.07); color: #94a3b8; }
.sb-action.secondary:hover { background: rgba(255,255,255,.12); color: #fff; }

/* Main panel */
#editorMain { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #111827; }
#editorTopBar { height: 40px; flex-shrink: 0; background: rgba(8,12,20,.98); border-bottom: 1px solid rgba(255,255,255,.05); display: flex; align-items: center; padding: 0 12px; gap: 8px; }
#editorTopBar .site-label { font-size: 11px; font-weight: 700; color: #fff; }
#editorTopBar .page-label { font-size: 11px; color: #334155; }
.top-icon-btn { width: 26px; height: 26px; border-radius: 7px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.07); color: #64748b; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background .12s, color .12s; font-size: 10px; }
.top-icon-btn:hover:not(:disabled) { background: rgba(255,255,255,.12); color: #e2e8f0; }
.top-icon-btn:disabled { opacity: .3; cursor: not-allowed; }

/* Viewport */
#editorViewport { flex: 1; overflow: hidden; position: relative; display: flex; align-items: stretch; }
#iframeWrap { flex: 1; display: flex; justify-content: center; align-items: flex-start; overflow: auto; padding: 16px; background: #111827; }
#iframeInner { width: 100%; max-width: 100%; height: calc(100vh - 40px - 32px); border-radius: 10px; overflow: hidden; box-shadow: 0 0 0 1px rgba(255,255,255,.05), 0 24px 64px rgba(0,0,0,.6); background: #fff; transition: max-width .25s ease; }
#siteFrame { width: 100%; height: 100%; border: 0; }
#iframeInner.vp-desktop { max-width: 100%; }
#iframeInner.vp-tablet  { max-width: 768px; margin: 0 auto; }
#iframeInner.vp-mobile  { max-width: 390px; margin: 0 auto; }

/* Preview bar */
#previewBar { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); z-index: 10; display: flex; align-items: center; gap: 4px; background: rgba(8,12,20,.9); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.08); border-radius: 999px; padding: 4px 8px; }
.preview-btn { width: 28px; height: 28px; border-radius: 50%; border: none; background: transparent; color: #475569; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; transition: background .12s, color .12s; }
.preview-btn.active { background: rgba(255,255,255,.12); color: #fff; }
.preview-btn:hover   { color: #e2e8f0; }

/* Floating toolbars */
.editor-popup { position:fixed; z-index:9999; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.08); backdrop-filter:blur(18px); padding:5px 7px; display:flex; align-items:center; gap:3px; }
.editor-popup.hidden { display:none; }
.editor-popup { background:rgba(13,17,23,.95); color:#f1f5f9; border:1px solid rgba(255,255,255,.1); }
.tb-btn { width:28px; height:28px; border-radius:7px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px; transition:background .12s,color .12s; color:#f1f5f9; }
.tb-btn:hover  { background:rgba(255,255,255,.15); color:#fff; }
.tb-btn.active { background:rgba(255,255,255,.2);  color:#fff; }
.tb-sep { width:1px; height:18px; background:rgba(255,255,255,.1); margin:0 2px; flex-shrink:0; }
.tb-color { width:22px; height:22px; border-radius:5px; border:2px solid rgba(255,255,255,.2); padding:0; cursor:pointer; background:transparent; overflow:hidden; }
#imageToolbar { flex-direction:column; align-items:stretch; gap:8px; padding:10px; width:210px; }
#bgToolbar    { flex-direction:column; align-items:stretch; gap:8px; padding:10px; min-width:165px; }
.pop-label { font-size:10px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; opacity:.5; }
.dropzone  { border:2px dashed rgba(255,255,255,.3); border-radius:9px; padding:10px; text-align:center; cursor:pointer; font-size:11px; color:inherit; opacity:.7; transition:border-color .15s,background .15s; }
.dropzone:hover, .dropzone.dragover { border-color:#fff; background:rgba(255,255,255,.05); opacity:1; }
</style>
</head>
<body>
<div id="editorRoot" data-site-id="<?= (int)$site['id'] ?>">
<div id="editorShell">

  <!-- LEFT SIDEBAR -->
  <div id="editorSidebar">
    <div id="sbTop">
      <div id="sbBrand">
        <?php if ($_has_logo): ?>
          <img src="<?= $_logo_url ?>" alt="Logo">
        <?php else: ?>
          <div style="width:22px;height:22px;border-radius:5px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-bolt" style="color:#000;font-size:10px;"></i>
          </div>
        <?php endif; ?>
        <span>Utiligo</span>
      </div>
      <div id="sbSiteName"><?= htmlspecialchars($site['business_name']) ?></div>
    </div>

    <div id="sbPages" style="flex:1;overflow-y:auto;">
      <div class="section-label">Pages</div>
      <?php
        $pageIcons = ['index'=>'fa-house','about'=>'fa-circle-info','services'=>'fa-briefcase','gallery'=>'fa-images','contact'=>'fa-envelope'];
        foreach ($pages as $key => $label):
      ?>
        <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>&page=<?= $key ?>"
           class="page-item <?= $key === $currentPage ? 'active' : '' ?>">
          <span class="page-dot"></span>
          <i class="fa-solid <?= $pageIcons[$key] ?>"></i>
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div id="sbTools">
      <div class="section-label" style="margin-top:10px;">Tools</div>
      <button type="button" class="tool-btn" id="hintToggleBtn">
        <i class="fa-regular fa-circle-question"></i> How to edit
        <span class="tool-hint" id="hintToggleArrow">▾</span>
      </button>
      <button type="button" class="tool-btn" id="undoBtn" disabled>
        <i class="fa-solid fa-rotate-left"></i> Undo
        <span class="tool-hint">Ctrl+Z</span>
      </button>
      <button type="button" class="tool-btn" id="redoBtn" disabled>
        <i class="fa-solid fa-rotate-right"></i> Redo
        <span class="tool-hint">Ctrl+Y</span>
      </button>
    </div>

    <div id="sbHint">
      <strong>💡 Click any text</strong> in the preview to edit it inline.<br>
      <strong>🖼️ Click an image</strong> to open the replace panel.<br>
      <strong>🎨 Click a section background</strong> to change its colour.<br>
      <strong>☰ Drag the handle</strong> on any section to reorder.<br>
      Changes save automatically.
    </div>

    <div id="sbBottom">
      <span id="saveStatus" style="font-size:10px;color:#334155;text-align:center;display:block;"></span>
      <a href="/portal/my_sites.php" class="sb-action primary">
        <i class="fa-solid fa-check text-xs"></i> Done
      </a>
      <a href="/portal/my_sites.php" class="sb-action secondary">
        <i class="fa-solid fa-arrow-left text-xs"></i> Back to Sites
      </a>
    </div>
  </div>
  <!-- end sidebar -->

  <!-- MAIN PANEL -->
  <div id="editorMain">
    <div id="editorTopBar">
      <span class="site-label"><?= htmlspecialchars($site['business_name']) ?></span>
      <span class="page-label">&mdash; <?= htmlspecialchars($pages[$currentPage]) ?></span>
      <div style="flex:1;"></div>
      <button id="undoBtnTop" type="button" title="Undo" disabled class="top-icon-btn"><i class="fa-solid fa-rotate-left"></i></button>
      <button id="redoBtnTop" type="button" title="Redo" disabled class="top-icon-btn"><i class="fa-solid fa-rotate-right"></i></button>
      <span id="saveStatusTop" style="font-size:10px;color:#334155;"></span>
    </div>

    <div id="editorViewport">
      <div id="iframeWrap">
        <div id="iframeInner" class="vp-desktop">
          <iframe id="siteFrame"
                  src="/assets/uploads/generated_sites/<?= htmlspecialchars($slugDir) ?>/<?= $currentPage ?>.html?edit=1&t=<?= time() ?>"
                  class="w-full h-full border-0"></iframe>
        </div>
      </div>
      <div id="previewBar">
        <button class="preview-btn active" data-vp="desktop" title="Desktop"><i class="fa-solid fa-desktop"></i></button>
        <button class="preview-btn" data-vp="tablet"  title="Tablet"><i class="fa-solid fa-tablet-screen-button"></i></button>
        <button class="preview-btn" data-vp="mobile"  title="Mobile"><i class="fa-solid fa-mobile-screen"></i></button>
      </div>
    </div>
  </div>

</div>
</div>

<!-- Format toolbar -->
<div id="formatToolbar" class="editor-popup hidden">
  <button type="button" class="tb-btn toolbar-btn" data-cmd="bold"      title="Bold"><b>B</b></button>
  <button type="button" class="tb-btn toolbar-btn" data-cmd="italic"    title="Italic"><i>I</i></button>
  <button type="button" class="tb-btn toolbar-btn" data-cmd="underline" title="Underline"><u>U</u></button>
  <div class="tb-sep"></div>
  <label title="Text colour" style="display:flex;align-items:center;cursor:pointer;">
    <span class="tb-btn" style="pointer-events:none;font-size:10px;"><i class="fa-solid fa-palette"></i></span>
    <input type="color" id="textColorPicker" class="tb-color" style="width:0;height:0;opacity:0;position:absolute;">
  </label>
  <div class="tb-sep"></div>
  <button type="button" id="clearFormatBtn" class="tb-btn" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
</div>

<!-- Image toolbar -->
<div id="imageToolbar" class="editor-popup hidden">
  <p class="pop-label"><i class="fa-regular fa-image mr-1"></i> Replace image</p>
  <div id="imageDropzone" class="dropzone"><i class="fa-solid fa-cloud-arrow-up block mb-1"></i>Drop or click to upload</div>
  <input type="file" id="imageFileInput" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden">
</div>

<!-- BG toolbar -->
<div id="bgToolbar" class="editor-popup hidden">
  <p class="pop-label"><i class="fa-solid fa-fill-drip mr-1"></i> Section background</p>
  <input type="color" id="bgColorPicker" class="w-full rounded-lg cursor-pointer" style="height:34px;border:none;padding:2px;background:transparent;">
</div>

<script>
// Viewport switcher
document.querySelectorAll('.preview-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.preview-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    const inner = document.getElementById('iframeInner');
    inner.className = 'vp-' + this.dataset.vp;
  });
});

// How-to-edit hint toggle
const hintBox    = document.getElementById('sbHint');
const hintBtn    = document.getElementById('hintToggleBtn');
const hintArrow  = document.getElementById('hintToggleArrow');
if (hintBtn && hintBox) {
  hintBtn.addEventListener('click', () => {
    const hidden = hintBox.classList.toggle('collapsed');
    hintArrow.textContent = hidden ? '\u25b8' : '\u25be';
  });
}

// Mirror undo/redo/saveStatus to top bar
const undoSb  = document.getElementById('undoBtn');
const redoSb  = document.getElementById('redoBtn');
const undoTop = document.getElementById('undoBtnTop');
const redoTop = document.getElementById('redoBtnTop');
const ssSb    = document.getElementById('saveStatus');
const ssTop   = document.getElementById('saveStatusTop');
function mirrorAttr(source, target, attr) {
  new MutationObserver(() => { target[attr] = source[attr]; }).observe(source, { attributes: true });
}
if (undoSb && undoTop) mirrorAttr(undoSb, undoTop, 'disabled');
if (redoSb && redoTop) mirrorAttr(redoSb, redoTop, 'disabled');
if (undoTop) undoTop.addEventListener('click', () => undoSb && undoSb.click());
if (redoTop) redoTop.addEventListener('click', () => redoSb && redoSb.click());
if (ssSb && ssTop) {
  new MutationObserver(() => { ssTop.textContent = ssSb.textContent; }).observe(ssSb, { childList:true, characterData:true, subtree:true });
}
</script>

<script src="/assets/js/site_editor.js?v=v203"></script>
</body>
</html>
