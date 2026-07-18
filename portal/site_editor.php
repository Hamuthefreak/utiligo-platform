<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_pro();
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

$pageTitle = 'Edit Site — Utiligo';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Toolbar base ── */
.editor-popup{position:fixed;z-index:9999;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.08);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);padding:6px 8px;display:flex;align-items:center;gap:3px;transition:opacity .12s,transform .12s;transform-origin:top left;}
.editor-popup.hidden{display:none;}
.editor-popup,.editor-popup.theme-dark-bg{background:rgba(15,20,30,.92);color:#f1f5f9;border:1px solid rgba(255,255,255,.12);}
.editor-popup.theme-light-bg{background:rgba(255,255,255,.96);color:#0f172a;border:1px solid rgba(0,0,0,.1);}
.tb-btn{width:30px;height:30px;border-radius:8px;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;transition:background .12s,color .12s;}
.editor-popup.theme-light-bg .tb-btn{color:#0f172a;}
.editor-popup.theme-dark-bg .tb-btn,.editor-popup .tb-btn{color:#f1f5f9;}
.tb-btn:hover{background:rgba(16,185,129,.18);color:#10b981;}
.tb-btn.active{background:rgba(16,185,129,.25);color:#10b981;}
.tb-sep{width:1px;height:20px;background:rgba(255,255,255,.12);margin:0 2px;flex-shrink:0;}
.editor-popup.theme-light-bg .tb-sep{background:rgba(0,0,0,.12);}
.tb-color{width:24px;height:24px;border-radius:6px;border:2px solid rgba(255,255,255,.25);padding:0;cursor:pointer;background:transparent;overflow:hidden;}
.editor-popup.theme-light-bg .tb-color{border-color:rgba(0,0,0,.2);}
#imageToolbar{width:220px;flex-direction:column;align-items:stretch;gap:8px;padding:12px;}
#bgToolbar{flex-direction:column;align-items:stretch;gap:8px;padding:12px;min-width:175px;}
.pop-label{font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;opacity:.55;}
.dropzone{border:2px dashed rgba(99,102,241,.45);border-radius:10px;padding:12px;text-align:center;cursor:pointer;font-size:11px;transition:border-color .15s,background .15s;color:inherit;opacity:.75;}
.dropzone:hover,.dropzone.dragover{border-color:#6366f1;background:rgba(99,102,241,.08);opacity:1;}

/* ── Compact page tabs ── */
.page-tab{font-size:11px;font-weight:600;padding:4px 12px;border-radius:999px;cursor:pointer;transition:background .15s,color .15s;text-decoration:none;white-space:nowrap;}
.page-tab.active{background:#10b981;color:#052e1f;}
.page-tab.inactive{background:rgba(255,255,255,.06);color:#94a3b8;}
.page-tab.inactive:hover{background:rgba(255,255,255,.1);color:#e2e8f0;}

/* ── Compact top chrome ── */
#editorChrome{position:sticky;top:0;z-index:100;background:rgba(8,12,20,.95);backdrop-filter:blur(14px);border-bottom:1px solid rgba(255,255,255,.06);padding:6px 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

.icon-btn{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#94a3b8;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .12s,color .12s;font-size:11px;}
.icon-btn:hover:not(:disabled){background:rgba(255,255,255,.1);color:#e2e8f0;}
.icon-btn:disabled{opacity:.3;cursor:not-allowed;}
</style>

<div id="editorRoot" data-site-id="<?= (int)$site['id'] ?>">

  <!-- ── Compact chrome ── -->
  <div id="editorChrome">
    <a href="/portal/my_sites.php"
       class="inline-flex items-center gap-1 text-[11px] text-slate-500 hover:text-white shrink-0">
      <i class="fa-solid fa-arrow-left text-[10px]"></i>
      <span class="hidden sm:inline">Sites</span>
    </a>

    <span class="text-xs font-bold text-white truncate max-w-[140px] shrink-0"><?= htmlspecialchars($site['business_name']) ?></span>
    <span class="text-slate-700 text-[10px] hidden md:inline shrink-0">Editor</span>

    <!-- page tabs -->
    <div class="flex items-center gap-1 flex-wrap flex-1 min-w-0">
      <?php foreach ($pages as $key => $label): ?>
        <a href="/portal/site_editor.php?site_id=<?= (int)$site['id'] ?>&page=<?= $key ?>"
           class="page-tab <?= $key === $currentPage ? 'active' : 'inactive' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <!-- right controls -->
    <div class="flex items-center gap-1.5 shrink-0 ml-auto">
      <button id="undoBtn" type="button" title="Undo (Ctrl+Z)" disabled class="icon-btn"><i class="fa-solid fa-rotate-left"></i></button>
      <button id="redoBtn" type="button" title="Redo" disabled class="icon-btn"><i class="fa-solid fa-rotate-right"></i></button>
      <span id="saveStatus" class="text-[10px] text-slate-600 hidden sm:inline"></span>
      <a href="/portal/my_sites.php"
         class="text-[11px] bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/30 px-3 py-1 rounded-full font-bold transition">
        Done
      </a>
    </div>
  </div>

  <!-- ── Hint ── -->
  <p class="text-[10px] text-slate-700 px-3 pt-1.5 pb-1 select-none">
    <i class="fa-regular fa-circle-question mr-1"></i>
    Click text to edit &middot; Click image to replace &middot; Click section bg to recolor &middot; Drag <i class="fa-solid fa-grip-lines mx-0.5"></i> to reorder
  </p>

  <!-- ── iframe ── -->
  <div class="px-3 pb-3">
    <div class="rounded-xl overflow-hidden ring-1 ring-white/5 shadow-2xl" style="height:calc(100vh - 110px);">
      <iframe id="siteFrame"
              src="/assets/uploads/generated_sites/<?= htmlspecialchars($slugDir) ?>/<?= $currentPage ?>.html?edit=1&t=<?= time() ?>"
              class="w-full h-full border-0 bg-white"></iframe>
    </div>
  </div>
</div>

<!-- ── Format toolbar ── -->
<div id="formatToolbar" class="editor-popup hidden">
  <button type="button" class="tb-btn toolbar-btn" data-cmd="bold" title="Bold"><b>B</b></button>
  <button type="button" class="tb-btn toolbar-btn" data-cmd="italic" title="Italic"><i>I</i></button>
  <button type="button" class="tb-btn toolbar-btn" data-cmd="underline" title="Underline"><u>U</u></button>
  <div class="tb-sep"></div>
  <label title="Text colour" style="display:flex;align-items:center;cursor:pointer;">
    <span class="tb-btn" style="pointer-events:none;font-size:10px;"><i class="fa-solid fa-palette"></i></span>
    <input type="color" id="textColorPicker" class="tb-color" style="width:0;height:0;opacity:0;position:absolute;">
  </label>
  <div class="tb-sep"></div>
  <button type="button" id="clearFormatBtn" class="tb-btn" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
</div>

<!-- ── Image toolbar ── -->
<div id="imageToolbar" class="editor-popup hidden">
  <p class="pop-label"><i class="fa-regular fa-image mr-1"></i> Replace image</p>
  <div id="imageDropzone" class="dropzone"><i class="fa-solid fa-cloud-arrow-up block mb-1 text-sm"></i>Drop or click to upload</div>
  <input type="file" id="imageFileInput" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden">
</div>

<!-- ── BG colour toolbar ── -->
<div id="bgToolbar" class="editor-popup hidden">
  <p class="pop-label"><i class="fa-solid fa-fill-drip mr-1"></i> Section background</p>
  <input type="color" id="bgColorPicker" class="w-full rounded-lg cursor-pointer" style="height:36px;border:none;padding:2px;background:transparent;">
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/site_editor.js?v=v201"></script>
