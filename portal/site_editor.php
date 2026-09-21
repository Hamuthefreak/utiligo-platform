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

// Inline wordmark: see includes/brand.php — a logo file cannot follow the theme.
require_once __DIR__ . '/../includes/brand.php';
// The design layer, before the <html> tag below — glass_attr() is written there.
require_once __DIR__ . '/../includes/appearance.php';
$_has_logo = brand_logo_exists();
?>
<!DOCTYPE html>
<?php /* The refraction filters for this page: see includes/glass.php. */ ?>
<html lang="en" <?= glass_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg">
<link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
<link rel="mask-icon" href="/assets/images/logo-mark.svg" color="#020817">
<title>Site Editor &mdash; <?= htmlspecialchars($site['business_name']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
<?php /* The design layer. The editor is a full-screen app shell rather than a
         page inside portal_layout.php, so it loads theme.css itself — without
         it the editor's chrome would be the one surface in the product still
         on the old palette. */ ?>
<?php /* Loaded above, before the <html> tag that glass_attr() writes. */ ?>
<?= appearance_bootstrap() ?>
<link rel="stylesheet" href="<?= asset_url('/assets/css/theme.css') ?>">
<script defer src="<?= asset_url('/assets/js/ui-theme.js') ?>"></script>
<style>
* { box-sizing: border-box; }
html, body {
  height: 100%; margin: 0;
  background: var(--canvas, var(--canvas)); color: var(--ink, #e9edf5);
  font-family: 'Inter', system-ui, sans-serif;
  overflow: hidden;
}

/* ===================== SHELL ===================== */
#editorShell {
  display: flex;
  height: 100dvh;
  width: 100vw;
  overflow: hidden;
  position: relative;
}

/* ===================== LEFT SIDEBAR (desktop) ===================== */
#editorSidebar {
  width: 220px;
  flex-shrink: 0;
  background: #0d1117;
  border-right: 1px solid var(--hair);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: transform .25s ease;
  z-index: 40;
}
#sbTop { padding: 14px 16px 12px; border-bottom: 1px solid var(--hair); display: flex; flex-direction: column; gap: 8px; }
#sbBrand { display: flex; align-items: center; gap: 8px; }
#sbBrand .brand-logo { height: 22px; width: auto; }
#sbBrand span { font-size: 13px; font-weight: 800; letter-spacing: -.01em; color: var(--pure); }
#sbSiteName   { font-size: 11px; color: var(--ink-3); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Pages */
#sbPages { padding: 12px 10px 6px; }
.section-label { font-size: 9px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-4); padding: 0 6px; margin-bottom: 6px; }
.page-item { display: flex; align-items: center; gap: 8px; padding: 9px 10px; border-radius: 9px; font-size: 12px; font-weight: 500; color: var(--ink-3); cursor: pointer; transition: background .13s, color .13s; text-decoration: none; margin-bottom: 1px; }
.page-item:hover  { background: var(--fill-2); color: var(--ink); }
.page-item.active { background: var(--fill-3); color: var(--pure); font-weight: 700; }
.page-item i { width: 14px; text-align: center; font-size: 11px; }
.page-item .page-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: .4; flex-shrink: 0; }
.page-item.active .page-dot { opacity: 1; }

/* Tools */
#sbTools { padding: 6px 10px; border-top: 1px solid var(--hair); }
.tool-btn { display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 10px; border-radius: 9px; border: none; background: transparent; font-size: 12px; font-weight: 500; color: var(--ink-3); cursor: pointer; transition: background .13s, color .13s; text-align: left; margin-bottom: 1px; }
.tool-btn:hover { background: var(--fill-2); color: var(--ink); }
.tool-btn i { width: 14px; text-align: center; font-size: 11px; }
.tool-btn .tool-hint { font-size: 10px; color: var(--ink-4); margin-left: auto; }

/* Hint box */
#sbHint { margin: 10px; padding: 10px 12px; border-radius: 12px; background: var(--fill-1); border: 1px solid var(--hair); font-size: 10px; color: var(--ink-4); line-height: 1.7; transition: all .2s; }
#sbHint strong { color: var(--ink-2); }
#sbHint.collapsed { display: none; }

/* Bottom actions */
#sbBottom { padding: 10px; border-top: 1px solid var(--hair); display: flex; flex-direction: column; gap: 6px; }
.sb-action { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .13s; text-decoration: none; border: none; }
.sb-action.primary   { background: var(--pure); color: #000; }
.sb-action.primary:hover { background: #e2e8f0; }
.sb-action.secondary { background: var(--fill-2); color: var(--ink-2); }
.sb-action.secondary:hover { background: var(--fill-3); color: var(--pure); }

/* ===================== MAIN PANEL ===================== */
#editorMain { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #0d1117; min-width: 0; }

/* Top bar */
#editorTopBar {
  height: 48px;
  flex-shrink: 0;
  background: rgb(var(--canvas-rgb) / 98%);
  border-bottom: 1px solid var(--hair);
  display: flex;
  align-items: center;
  padding: 0 10px;
  gap: 6px;
}
#menuToggleBtn {
  display: none;
  width: 36px; height: 36px;
  border-radius: 9px;
  background: var(--fill-2);
  border: 1px solid var(--hair);
  color: var(--ink-2);
  align-items: center; justify-content: center;
  cursor: pointer; font-size: 14px;
  flex-shrink: 0;
}
#editorTopBar .site-label { font-size: 12px; font-weight: 700; color: var(--pure); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px; }
#editorTopBar .page-label { font-size: 11px; color: var(--ink-4); white-space: nowrap; }
.top-icon-btn {
  width: 32px; height: 32px;
  border-radius: 8px;
  background: var(--fill-2);
  border: 1px solid var(--hair);
  color: var(--ink-3);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background .12s, color .12s;
  font-size: 11px;
  flex-shrink: 0;
}
.top-icon-btn:hover:not(:disabled) { background: var(--fill-3); color: var(--ink); }
.top-icon-btn:disabled { opacity: .3; cursor: not-allowed; }
#saveStatusTop { font-size: 10px; color: var(--ink-4); white-space: nowrap; }

/* Done button in top bar (mobile only) */
#topDoneBtn {
  display: none;
  padding: 7px 14px;
  border-radius: 8px;
  background: var(--pure);
  color: #000;
  font-size: 12px;
  font-weight: 700;
  border: none;
  cursor: pointer;
  white-space: nowrap;
  text-decoration: none;
  flex-shrink: 0;
}

/* ===================== VIEWPORT ===================== */
#editorViewport { flex: 1; overflow: hidden; position: relative; display: flex; align-items: stretch; }
/* Neutral mid-grey — not pitch-black, so the iframe doesn't look dimmed */
#iframeWrap {
  flex: 1;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  overflow: auto;
  padding: 12px;
  background: #1e2533;
  -webkit-overflow-scrolling: touch;
}
#iframeInner {
  width: 100%; max-width: 100%;
  height: calc(100dvh - 48px - 24px);
  border-radius: 10px; overflow: hidden;
  /* subtle border only — no heavy dark shadow that bleeds over the content */
  box-shadow: 0 0 0 1px rgba(255,255,255,.08);
  background: var(--pure);
  transition: max-width .25s ease;
}
#siteFrame { width: 100%; height: 100%; border: 0; }
#iframeInner.vp-desktop { max-width: 100%; }
#iframeInner.vp-tablet  { max-width: 768px; margin: 0 auto; }
#iframeInner.vp-mobile  { max-width: 390px; margin: 0 auto; }

/* ===================== PREVIEW BAR ===================== */
#previewBar {
  position: absolute;
  bottom: 12px; left: 50%; transform: translateX(-50%);
  z-index: 10;
  display: flex; align-items: center; gap: 4px;
  background: rgb(var(--canvas-rgb) / 90%);
  backdrop-filter: blur(12px);
  border: 1px solid var(--hair);
  border-radius: 999px;
  padding: 4px 8px;
}
.preview-btn { width: 32px; height: 32px; border-radius: 50%; border: none; background: transparent; color: var(--ink-4); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; transition: background .12s, color .12s; }
.preview-btn.active { background: var(--fill-3); color: var(--pure); }
.preview-btn:hover   { color: var(--ink); }

/* ===================== MOBILE BOTTOM NAV ===================== */
#mobileBottomNav {
  display: none;
  height: 56px;
  flex-shrink: 0;
  background: rgb(var(--canvas-rgb) / 98%);
  border-top: 1px solid var(--hair);
  align-items: stretch;
  padding-bottom: env(safe-area-inset-bottom);
  z-index: 30;
}
.mob-nav-btn {
  flex: 1;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 3px;
  background: transparent;
  border: none;
  color: var(--ink-4);
  font-size: 10px;
  font-weight: 600;
  cursor: pointer;
  transition: color .15s;
  text-decoration: none;
  -webkit-tap-highlight-color: transparent;
}
.mob-nav-btn i { font-size: 16px; }
.mob-nav-btn:active, .mob-nav-btn.active { color: var(--pure); }
.mob-nav-btn.save-btn { color: var(--accent, #7fe3a8); }
.mob-nav-btn.done-btn { color: var(--pure); background: var(--fill-2); border-radius: 0; }

/* ===================== MOBILE DRAWER ===================== */
#drawerOverlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.6);
  backdrop-filter: blur(2px);
  z-index: 39;
}
#drawerOverlay.open { display: block; }

/* ===================== FLOATING TOOLBARS ===================== */
.editor-popup { position:fixed; z-index:9999; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.08); backdrop-filter:blur(18px); padding:5px 7px; display:flex; align-items:center; gap:3px; max-width:calc(100vw - 16px); flex-wrap:wrap; }
.editor-popup.hidden { display:none; }
.editor-popup { background:rgba(13,17,23,.95); color:var(--ink); border:1px solid var(--hair); }
.tb-btn { width:32px; height:32px; border-radius:7px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:13px; transition:background .12s,color .12s; color:var(--ink); }
.tb-btn:hover  { background:var(--fill-3); color:var(--pure); }
.tb-btn.active { background:var(--fill-4);  color:var(--pure); }
.tb-sep { width:1px; height:18px; background:var(--fill-3); margin:0 2px; flex-shrink:0; }
.tb-color { width:22px; height:22px; border-radius:5px; border:2px solid var(--hair-2); padding:0; cursor:pointer; background:transparent; overflow:hidden; }
#imageToolbar { flex-direction:column; align-items:stretch; gap:8px; padding:10px; width:210px; }
#bgToolbar    { flex-direction:column; align-items:stretch; gap:8px; padding:10px; min-width:165px; }
.pop-label { font-size:10px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; opacity:.5; }
.dropzone  { border:2px dashed var(--hair-2); border-radius:9px; padding:12px; text-align:center; cursor:pointer; font-size:11px; color:inherit; opacity:.7; transition:border-color .15s,background .15s; }
.dropzone:hover, .dropzone.dragover { border-color:#fff; background:var(--fill-2); opacity:1; }

/* ===================== RESPONSIVE BREAKPOINTS ===================== */
@media (max-width: 768px) {
  #editorSidebar {
    position: fixed;
    top: 0; left: 0; bottom: 0;
    width: 260px;
    transform: translateX(-100%);
    z-index: 40;
    box-shadow: 4px 0 32px rgba(0,0,0,.6);
  }
  #editorSidebar.drawer-open { transform: translateX(0); }
  #menuToggleBtn { display: flex; }
  #topDoneBtn    { display: flex; align-items: center; }
  #editorTopBar .site-label { max-width: 90px; font-size: 11px; }
  #editorTopBar .page-label { display: none; }
  #mobileBottomNav { display: flex; }
  #iframeWrap { padding: 6px; }
  #iframeInner {
    border-radius: 6px;
    height: calc(100dvh - 48px - 56px - 12px);
  }
  #previewBar { bottom: 66px; }
  #imageToolbar { width: min(210px, calc(100vw - 32px)); }
  #bgToolbar    { min-width: min(165px, calc(100vw - 32px)); }
  .tb-btn { width: 36px; height: 36px; font-size: 14px; }
}

@media (max-width: 480px) {
  #editorTopBar .site-label { max-width: 60px; }
  #undoBtnTop, #redoBtnTop, #saveStatusTop { display: none; }
}
</style>
</head>
<body>

<?= glass_defs() ?>

<div id="editorRoot" data-site-id="<?= (int)$site['id'] ?>">
<div id="editorShell">

  <!-- DRAWER OVERLAY (mobile) -->
  <div id="drawerOverlay"></div>

  <!-- LEFT SIDEBAR / DRAWER -->
  <div id="editorSidebar">
    <div id="sbTop">
      <div id="sbBrand">
        <?php if ($_has_logo): ?>
          <?= brand_logo('brand-sm', 'Logo') ?>
        <?php else: ?>
          <div style="width:22px;height:22px;border-radius:5px;background:var(--pure);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-bolt" style="color:#000;font-size:10px;"></i>
          </div>
        <?php endif; ?>
        <?php if (!$_has_logo): ?><span>Utiligo</span><?php endif; ?>
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
           class="page-item <?= $key === $currentPage ? 'active' : '' ?>"
           onclick="closeDrawer()">
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
        <span class="tool-hint" id="hintToggleArrow">&dtrif;</span>
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
      <strong>&#128161; Tap any text</strong> in the preview to edit it inline.<br>
      <strong>&#128444;&#65039; Tap an image</strong> to open the replace panel.<br>
      <strong>&#127912; Tap a section background</strong> to change its colour.<br>
      Changes save automatically.
    </div>

    <div id="sbBottom">
      <span id="saveStatus" style="font-size:10px;color:var(--ink-4);text-align:center;display:block;"></span>
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
    <!-- Top bar -->
    <div id="editorTopBar">
      <button id="menuToggleBtn" type="button" aria-label="Menu" onclick="openDrawer()">
        <i class="fa-solid fa-bars"></i>
      </button>
      <span class="site-label"><?= htmlspecialchars($site['business_name']) ?></span>
      <span class="page-label">&mdash; <?= htmlspecialchars($pages[$currentPage]) ?></span>
      <div style="flex:1;"></div>
      <button id="undoBtnTop" type="button" title="Undo" disabled class="top-icon-btn"><i class="fa-solid fa-rotate-left"></i></button>
      <button id="redoBtnTop" type="button" title="Redo" disabled class="top-icon-btn"><i class="fa-solid fa-rotate-right"></i></button>
      <span id="saveStatusTop" style="font-size:10px;color:var(--ink-4);"></span>
      <a href="/portal/my_sites.php" id="topDoneBtn">
        <i class="fa-solid fa-check" style="margin-right:5px;font-size:11px;"></i>Done
      </a>
    </div>

    <!-- Iframe viewport -->
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

    <!-- Mobile bottom nav -->
    <div id="mobileBottomNav">
      <button type="button" class="mob-nav-btn" onclick="openDrawer()">
        <i class="fa-solid fa-bars"></i>
        <span>Menu</span>
      </button>
      <button type="button" class="mob-nav-btn" id="mobUndoBtn" disabled>
        <i class="fa-solid fa-rotate-left"></i>
        <span>Undo</span>
      </button>
      <button type="button" class="mob-nav-btn" id="mobRedoBtn" disabled>
        <i class="fa-solid fa-rotate-right"></i>
        <span>Redo</span>
      </button>
      <button type="button" class="mob-nav-btn save-btn" id="mobSaveBtn">
        <i class="fa-solid fa-floppy-disk"></i>
        <span>Save</span>
      </button>
      <a href="/portal/my_sites.php" class="mob-nav-btn done-btn">
        <i class="fa-solid fa-check"></i>
        <span>Done</span>
      </a>
    </div>

  </div>
  <!-- end main -->

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
  <div id="imageDropzone" class="dropzone"><i class="fa-solid fa-cloud-arrow-up block mb-1"></i>Drop or tap to upload</div>
  <input type="file" id="imageFileInput" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden">
</div>

<!-- BG toolbar -->
<div id="bgToolbar" class="editor-popup hidden">
  <p class="pop-label"><i class="fa-solid fa-fill-drip mr-1"></i> Section background</p>
  <input type="color" id="bgColorPicker" class="w-full rounded-lg cursor-pointer" style="height:36px;border:none;padding:2px;background:transparent;">
</div>

<script>
function openDrawer() {
  document.getElementById('editorSidebar').classList.add('drawer-open');
  document.getElementById('drawerOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDrawer() {
  document.getElementById('editorSidebar').classList.remove('drawer-open');
  document.getElementById('drawerOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.getElementById('drawerOverlay').addEventListener('click', closeDrawer);

document.querySelectorAll('.preview-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.preview-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    document.getElementById('iframeInner').className = 'vp-' + this.dataset.vp;
  });
});

const hintBox   = document.getElementById('sbHint');
const hintBtn   = document.getElementById('hintToggleBtn');
const hintArrow = document.getElementById('hintToggleArrow');
const HINT_KEY  = 'utiligo_editor_hint_open';
if (hintBtn && hintBox) {
  // Default to minimized; only show if the user previously expanded it.
  const saved     = window.localStorage.getItem(HINT_KEY);
  const collapsed = saved === null || saved !== '1';
  hintBox.classList.toggle('collapsed', collapsed);
  if (hintArrow) hintArrow.textContent = collapsed ? '\u25b8' : '\u25be';
  hintBtn.addEventListener('click', () => {
    const hidden = hintBox.classList.toggle('collapsed');
    window.localStorage.setItem(HINT_KEY, hidden ? '0' : '1');
    if (hintArrow) hintArrow.textContent = hidden ? '\u25b8' : '\u25be';
  });
}

const undoSb  = document.getElementById('undoBtn');
const redoSb  = document.getElementById('redoBtn');
const undoTop = document.getElementById('undoBtnTop');
const redoTop = document.getElementById('redoBtnTop');
const mobUndo = document.getElementById('mobUndoBtn');
const mobRedo = document.getElementById('mobRedoBtn');
const mobSave = document.getElementById('mobSaveBtn');
const ssSb    = document.getElementById('saveStatus');
const ssTop   = document.getElementById('saveStatusTop');

function mirrorAttr(source, target, attr) {
  if (!source || !target) return;
  new MutationObserver(() => { target[attr] = source[attr]; }).observe(source, { attributes: true });
}
mirrorAttr(undoSb, undoTop, 'disabled');
mirrorAttr(undoSb, mobUndo, 'disabled');
mirrorAttr(redoSb, redoTop, 'disabled');
mirrorAttr(redoSb, mobRedo, 'disabled');

if (undoTop) undoTop.addEventListener('click', () => undoSb?.click());
if (mobUndo) mobUndo.addEventListener('click', () => undoSb?.click());
if (redoTop) redoTop.addEventListener('click', () => redoSb?.click());
if (mobRedo) mobRedo.addEventListener('click', () => redoSb?.click());

if (mobSave) {
  mobSave.addEventListener('click', () => {
    if (typeof window.editorSave === 'function') window.editorSave();
  });
}

if (ssSb && ssTop) {
  new MutationObserver(() => { ssTop.textContent = ssSb.textContent; })
    .observe(ssSb, { childList:true, characterData:true, subtree:true });
}
</script>

<script src="<?= asset_url('/assets/js/site_editor.js') ?>"></script>
</body>
</html>
