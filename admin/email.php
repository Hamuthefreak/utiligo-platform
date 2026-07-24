<?php
/**
 * admin/email.php — Email blast + drag-and-drop builder.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/mailer.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$sent = 0; $errors = []; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_blast') {
    if (!admin_csrf_verify('email_blast', $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'CSRF failure on email blast');
        die('Invalid CSRF token.');
    }
    $subject   = trim(strip_tags($_POST['subject'] ?? ''));
    $bodyHtml  = trim($_POST['body_html'] ?? '');
    $audience  = $_POST['audience'] ?? 'all';
    $customRaw = trim($_POST['custom_emails'] ?? '');

    if (!$subject || !$bodyHtml) {
        $errors[] = 'Subject and body are required.';
    } else {
        $udb = get_user_db();
        $recipients = [];
        if ($audience === 'all') {
            $recipients = $udb->query('SELECT email,full_name FROM utiligo_users WHERE email_verified=1')->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'pro') {
            $recipients = $udb->query("SELECT email,full_name FROM utiligo_users WHERE plan='pro' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'entrepreneur') {
            $recipients = $udb->query("SELECT email,full_name FROM utiligo_users WHERE plan='entrepreneur' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'free') {
            $recipients = $udb->query("SELECT email,full_name FROM utiligo_users WHERE plan='free' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            foreach (preg_split('/[\r\n,;]+/', $customRaw) as $line) {
                $e = strtolower(trim($line));
                if (filter_var($e, FILTER_VALIDATE_EMAIL))
                    $recipients[] = ['email' => $e, 'full_name' => $e];
            }
        }
        $footer = '<p style="font-size:11px;color:#64748b;margin:12px 0 0;text-align:center;"><a href="https://utiligo.ca/unsubscribe" style="color:#94a3b8;">Unsubscribe</a></p>';
        _admin_log('INFO', "Blast start: subject='{$subject}' aud={$audience} to=".count($recipients));
        foreach ($recipients as $r) {
            $html = email_wrapper('Message from Utiligo', "<p>Hi {$r['full_name']},</p>{$bodyHtml}", $footer);
            $ok = send_email($r['email'], $subject, $html, '', $r['full_name']);
            if ($ok) $sent++; else $errors[] = 'Failed: ' . $r['email'];
            if ($sent % 10 === 0 && $sent > 0) usleep(300000);
        }
        _admin_log('INFO', "Blast done: sent={$sent} errors=".count($errors));
        $success = "Sent to {$sent} recipient(s).";
    }
}

$csrf = admin_csrf_token('email_blast');
$pageTitle = 'Email Blast — Admin — Utiligo';
$adminPage = 'email';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
/* ── Layout ── */
#eb-wrap          { display:grid; grid-template-columns:200px 1fr 260px; gap:16px; align-items:start; }
#eb-canvas-col    { min-width:0; }
#eb-props-col     { position:sticky; top:80px; }
@media(max-width:1279px){ #eb-wrap{ grid-template-columns:180px 1fr; } #eb-props-col{ display:none; } }
@media(max-width:767px) { #eb-wrap{ grid-template-columns:1fr; } #eb-blocks-col{ order:2; } #eb-canvas-col{ order:1; } }

/* ── Block palette items ── */
.pb-item {
  display:flex; align-items:center; gap:8px; padding:9px 12px;
  border-radius:10px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07);
  cursor:grab; font-size:.78rem; font-weight:500; color:#94a3b8;
  transition:all .15s; user-select:none; touch-action:none;
}
.pb-item:hover,.pb-item.dragging{ background:rgba(255,255,255,.1); color:#fff; border-color:rgba(255,255,255,.2); }
.pb-item i{ width:14px; text-align:center; font-size:.75rem; }

/* ── Email canvas shell ── */
#eb-canvas-outer {
  background:#fff; border-radius:14px; overflow:hidden;
  box-shadow:0 4px 40px rgba(0,0,0,.4);
  min-height:400px;
}
#eb-canvas-outer.dov { outline:2px dashed rgba(255,255,255,.4); outline-offset:2px; }

/* ── Email blocks ── */
.ebl {
  position:relative; cursor:pointer;
  outline:2px solid transparent; transition:outline-color .1s;
}
.ebl:hover { outline-color:rgba(255,255,255,.35); }
.ebl.sel   { outline-color:#ffffff !important; }

/* floating toolbar */
.ebl-bar {
  display:none; position:absolute; top:0; right:0; z-index:20;
  background:rgba(15,23,42,.92); border-radius:0 0 0 10px;
  backdrop-filter:blur(8px); border-left:1px solid rgba(255,255,255,.1); border-bottom:1px solid rgba(255,255,255,.1);
}
.ebl:hover .ebl-bar, .ebl.sel .ebl-bar { display:flex; }
.ebl-bar button {
  border:none; background:transparent; color:#94a3b8;
  padding:6px 9px; cursor:pointer; font-size:.7rem; transition:color .1s,background .1s;
}
.ebl-bar button:hover { color:#fff; background:rgba(255,255,255,.1); }

/* drop indicator */
.ebl.drop-above::before { content:''; display:block; height:3px; background:#ffffff; position:absolute; top:-2px; left:0; right:0; z-index:30; }
.ebl.drop-below::after  { content:''; display:block; height:3px; background:#ffffff; position:absolute; bottom:-2px; left:0; right:0; z-index:30; }

/* ── Props panel ── */
.pp-section { margin-bottom:1.1rem; }
.pp-label   { font-size:.67rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.3rem; display:block; }
.pp-input   {
  width:100%; padding:7px 10px; border-radius:9px;
  background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
  color:#e2e8f0; font-size:.8rem; outline:none; transition:border-color .15s;
}
.pp-input:focus { border-color:rgba(255,255,255,.4); }
.pp-color   { width:34px; height:28px; border-radius:8px; border:1px solid rgba(255,255,255,.12); cursor:pointer; padding:2px; background:transparent; }
.pp-row     { display:flex; align-items:center; gap:6px; }
.pp-btn     {
  flex:1; padding:5px 4px; border-radius:7px; border:1px solid rgba(255,255,255,.1);
  background:rgba(255,255,255,.04); color:#94a3b8; cursor:pointer; font-size:.72rem;
  transition:all .12s; text-align:center;
}
.pp-btn:hover,.pp-btn.act { background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3); }

/* ── Bottom image uploader ── */
#img-uploader {
  border:2px dashed rgba(255,255,255,.15); border-radius:14px;
  padding:20px; text-align:center; transition:all .2s; cursor:pointer;
}
#img-uploader:hover,#img-uploader.ov { border-color:rgba(255,255,255,.5); background:rgba(255,255,255,.03); }
#img-thumb-grid { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
.img-thumb {
  position:relative; width:80px; height:60px; border-radius:8px; overflow:hidden;
  border:1px solid rgba(255,255,255,.1); cursor:pointer; flex-shrink:0;
  transition:border-color .15s;
}
.img-thumb:hover { border-color:#fff; }
.img-thumb img   { width:100%; height:100%; object-fit:cover; }
.img-thumb .copy-tag {
  position:absolute; inset:0; background:rgba(0,0,0,.6); opacity:0;
  display:flex; align-items:center; justify-content:center;
  font-size:.65rem; font-weight:700; color:#fff; transition:opacity .15s;
}
.img-thumb:hover .copy-tag { opacity:1; }

/* ── Mobile props drawer ── */
#mob-drawer {
  position:fixed; inset:0; z-index:60; pointer-events:none;
}
#mob-drawer-bg    { position:absolute; inset:0; background:rgba(0,0,0,.65); opacity:0; transition:opacity .2s; }
#mob-drawer-panel {
  position:absolute; bottom:0; left:0; right:0;
  background:#0f172a; border-top:1px solid rgba(255,255,255,.1);
  border-radius:20px 20px 0 0; padding:20px 20px 32px;
  transform:translateY(100%); transition:transform .28s cubic-bezier(.4,0,.2,1);
  max-height:72vh; overflow-y:auto;
}
#mob-drawer.open { pointer-events:auto; }
#mob-drawer.open #mob-drawer-bg    { opacity:1; }
#mob-drawer.open #mob-drawer-panel { transform:translateY(0); }

/* ── Audience pills ── */
.aud-pill {
  padding:5px 14px; border-radius:999px; font-size:.75rem; font-weight:600;
  border:1px solid rgba(255,255,255,.1); cursor:pointer; transition:all .15s;
  color:#64748b; background:transparent;
}
.aud-pill:hover { color:#fff; border-color:rgba(255,255,255,.3); }
.aud-pill.on    { background:#fff; color:#000; border-color:#fff; }
</style>

<!-- ░░░ PAGE HEADER ░░░ -->
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">Email Blast</h1>
    <p class="text-slate-400 text-sm mt-1">Build your email visually, then send it to your audience.</p>
  </div>
</div>

<?php if ($success): ?>
  <div class="mb-5 flex items-center gap-3 bg-white/5 border border-white/15 text-white px-5 py-3.5 rounded-xl text-sm">
    <i class="fa-solid fa-circle-check text-green-400"></i> <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>
<?php foreach ($errors as $er): ?>
  <div class="mb-3 bg-red-900/30 border border-red-500/30 text-red-300 px-5 py-3 rounded-xl text-sm"><?= htmlspecialchars($er) ?></div>
<?php endforeach; ?>

<!-- ░░░ SEND SETTINGS BAR ░░░ -->
<div class="glass rounded-2xl p-5 mb-5">
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
    <!-- Audience -->
    <div>
      <label class="pp-label" style="margin-bottom:.5rem">Audience</label>
      <div class="flex flex-wrap gap-1.5" id="aud-btns">
        <button type="button" class="aud-pill on" data-v="all">All</button>
        <button type="button" class="aud-pill" data-v="pro">Pro</button>
        <button type="button" class="aud-pill" data-v="entrepreneur">ENT</button>
        <button type="button" class="aud-pill" data-v="free">Free</button>
        <button type="button" class="aud-pill" data-v="custom">Custom</button>
      </div>
    </div>
    <!-- Subject -->
    <div class="sm:col-span-2">
      <label class="pp-label" for="s-subject" style="margin-bottom:.4rem">Subject Line</label>
      <input id="s-subject" type="text" placeholder="e.g. Big news from Utiligo!"
             class="pp-input" style="padding:9px 14px;font-size:.875rem;">
    </div>
    <!-- Send -->
    <div class="flex items-end">
      <button type="button" onclick="doSend()"
        class="w-full flex items-center justify-center gap-2 bg-white hover:bg-slate-200 text-black py-2.5 px-5 rounded-xl font-bold text-sm transition">
        <i class="fa-solid fa-paper-plane"></i> Send Blast
      </button>
    </div>
  </div>
  <!-- Custom emails -->
  <div id="custom-box" class="hidden mt-4">
    <label class="pp-label">Custom addresses <span class="normal-case font-normal text-slate-500">(comma or newline separated)</span></label>
    <textarea id="custom-emails" rows="3" placeholder="alice@example.com, bob@example.com"
      class="pp-input" style="resize:vertical;"></textarea>
  </div>
</div>

<!-- hidden form for actual POST -->
<form id="blast-form" method="POST" style="display:none">
  <input type="hidden" name="action"        value="send_blast">
  <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
  <input type="hidden" name="audience"      id="f-audience">
  <input type="hidden" name="subject"       id="f-subject">
  <input type="hidden" name="body_html"     id="f-body">
  <input type="hidden" name="custom_emails" id="f-custom">
</form>

<!-- ░░░ BUILDER ░░░ -->
<div id="eb-wrap">

  <!-- LEFT: block palette -->
  <div id="eb-blocks-col">
    <div class="glass rounded-2xl p-4 sticky top-20">
      <p class="pp-label mb-3">Add Blocks</p>
      <div class="space-y-1.5" id="palette">
        <div class="pb-item" data-t="heading"><i class="fa-solid fa-h"></i>Heading</div>
        <div class="pb-item" data-t="text"><i class="fa-solid fa-align-left"></i>Paragraph</div>
        <div class="pb-item" data-t="image"><i class="fa-solid fa-image"></i>Image</div>
        <div class="pb-item" data-t="button"><i class="fa-solid fa-arrow-pointer"></i>Button</div>
        <div class="pb-item" data-t="divider"><i class="fa-solid fa-minus"></i>Divider</div>
        <div class="pb-item" data-t="spacer"><i class="fa-solid fa-up-down"></i>Spacer</div>
        <div class="pb-item" data-t="columns"><i class="fa-solid fa-table-columns"></i>2 Columns</div>
        <div class="pb-item" data-t="html"><i class="fa-solid fa-code"></i>Raw HTML</div>
      </div>
      <div class="mt-4 pt-4 border-t border-white/5 space-y-2">
        <button onclick="prevModal()" type="button"
          class="w-full text-xs py-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 border border-white/10 transition font-semibold">
          <i class="fa-solid fa-eye mr-1"></i> Preview
        </button>
        <button onclick="clearAll()" type="button"
          class="w-full text-xs py-2 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition font-semibold">
          <i class="fa-solid fa-trash mr-1"></i> Clear
        </button>
      </div>
      <!-- Undo/Redo -->
      <div class="mt-2 flex gap-1.5">
        <button onclick="undo()" type="button" title="Undo (Ctrl+Z)"
          class="flex-1 text-xs py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition">
          <i class="fa-solid fa-rotate-left"></i>
        </button>
        <button onclick="redo()" type="button" title="Redo"
          class="flex-1 text-xs py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition">
          <i class="fa-solid fa-rotate-right"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- CENTER: canvas -->
  <div id="eb-canvas-col">
    <!-- canvas toolbar -->
    <div class="glass rounded-2xl px-4 py-3 mb-3 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <span class="text-xs text-slate-500">Email BG</span>
        <input type="color" id="canvasBgPicker" value="#ffffff" title="Canvas background"
               class="pp-color" oninput="setBg(this.value)">
        <span class="text-xs text-slate-500">Max width</span>
        <select id="canvasWidthSel" class="pp-input" style="width:auto;padding:5px 8px;"
                onchange="setWidth(this.value)">
          <option value="600">600px</option>
          <option value="500">500px</option>
          <option value="100%">Full</option>
        </select>
      </div>
      <!-- mobile props trigger -->
      <button type="button" onclick="openDrawer()" id="edit-props-btn"
        class="xl:hidden text-xs px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white font-semibold border border-white/15 hidden">
        <i class="fa-solid fa-sliders mr-1"></i> Edit Block
      </button>
    </div>

    <!-- the white email canvas -->
    <div id="eb-canvas-outer" class="mx-auto" style="max-width:600px;">
      <div id="eb-canvas" style="background:#ffffff;min-height:300px;">
        <div id="canvas-hint" class="flex flex-col items-center justify-center py-20 gap-3" style="color:#cbd5e1;">
          <i class="fa-solid fa-envelope-open-text" style="font-size:2rem;color:#e2e8f0;"></i>
          <span style="font-size:.875rem;">Drag a block here or click one in the palette</span>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: props panel -->
  <div id="eb-props-col">
    <div class="glass rounded-2xl p-4 sticky top-20">
      <p class="pp-label mb-3">Properties</p>
      <div id="pp-content">
        <p class="text-xs text-slate-600 text-center py-10">Click a block to edit it</p>
      </div>
    </div>
  </div>

</div><!-- /eb-wrap -->

<!-- ░░░ IMAGE UPLOAD TOOL ░░░ -->
<div class="glass rounded-2xl p-5 mt-6">
  <div class="flex items-center gap-2 mb-4">
    <i class="fa-solid fa-images text-slate-400"></i>
    <h3 class="font-semibold text-sm">Image Library</h3>
    <span class="text-xs text-slate-500 ml-1">— Upload images and copy their URL into any Image block</span>
  </div>
  <div id="img-uploader"
       onclick="document.getElementById('img-file-inp').click()"
       ondragover="event.preventDefault();this.classList.add('ov')"
       ondragleave="this.classList.remove('ov')"
       ondrop="handleImgDrop(event)">
    <i class="fa-solid fa-cloud-arrow-up text-slate-500 text-2xl mb-2"></i>
    <p class="text-sm text-slate-400">Drop images here or <span class="text-white underline cursor-pointer">click to upload</span></p>
    <p class="text-xs text-slate-600 mt-1">PNG, JPG, GIF, WebP — stored as base64 URLs you can paste directly into Image blocks</p>
  </div>
  <input type="file" id="img-file-inp" accept="image/*" multiple class="hidden" onchange="handleImgFiles(this.files)">
  <div id="img-grid"></div>
</div>

<!-- ░░░ MOBILE PROPS DRAWER ░░░ -->
<div id="mob-drawer">
  <div id="mob-drawer-bg" onclick="closeDrawer()"></div>
  <div id="mob-drawer-panel">
    <div class="flex items-center justify-between mb-4">
      <span class="font-semibold text-sm">Edit Block</span>
      <button onclick="closeDrawer()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="mob-pp-content"><p class="text-xs text-slate-500 text-center py-6">Click a block to edit</p></div>
  </div>
</div>

<!-- ░░░ PREVIEW MODAL ░░░ -->
<div id="prev-modal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center p-3">
  <div class="bg-slate-900 border border-white/10 rounded-2xl w-full max-w-3xl max-h-[92vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-white/10 gap-3">
      <span class="font-semibold text-sm">Preview</span>
      <div class="flex gap-2">
        <button onclick="setPrevMode('desktop')" id="pv-d" class="pv-btn on"><i class="fa-solid fa-desktop"></i> Desktop</button>
        <button onclick="setPrevMode('mobile')"  id="pv-m" class="pv-btn"><i class="fa-solid fa-mobile-screen"></i> Mobile</button>
      </div>
      <button onclick="prevModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="flex-1 overflow-auto p-4 flex justify-center">
      <div id="pv-frame" class="transition-all duration-300 bg-white rounded-xl overflow-hidden" style="width:100%;max-width:700px;">
        <iframe id="pv-iframe" class="w-full border-0" style="height:550px;display:block;"></iframe>
      </div>
    </div>
  </div>
</div>
<style>
.pv-btn { padding:5px 12px; border-radius:9px; font-size:.75rem; font-weight:600; border:1px solid rgba(255,255,255,.1); cursor:pointer; color:#64748b; background:transparent; transition:all .15s; }
.pv-btn.on { background:#fff; color:#000; border-color:#fff; }
</style>

<script>
'use strict';
// ═══════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════
let blocks   = [];
let selId    = null;
let hist     = [];
let fut      = [];
let canvasBg = '#ffffff';
let dragPal  = null;   // type string from palette
let dragId   = null;   // block id being reordered
let dropTarget = null; // {id, pos: 'above'|'below'}

const CANVAS = document.getElementById('eb-canvas');
const HINT   = document.getElementById('canvas-hint');

// ── Default block data ──
const DEF = {
  heading : {text:'Your Heading',   level:'h2', color:'#111827', align:'left',   size:'26', weight:'700'},
  text    : {text:'Your paragraph text goes here. Click to edit inline.',  color:'#374151', align:'left',   size:'15', lh:'1.7'},
  image   : {src:'',  alt:'',   align:'center', width:'100', radius:'6'},
  button  : {text:'Click Here', href:'https://utiligo.ca', bg:'#111827', fg:'#ffffff', align:'center', size:'15', radius:'8', bold:'1'},
  divider : {color:'#e5e7eb', thick:'1', my:'20'},
  spacer  : {h:'32'},
  columns : {l:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;">Left column content here.</p>',
              r:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;">Right column content here.</p>', gap:'20'},
  html    : {code:'<p style="color:#374151;font-family:Arial,sans-serif;">Custom HTML block</p>'},
};

// ═══════════════════════════════════════════════════
//  UTILS
// ═══════════════════════════════════════════════════
const uid = () => Math.random().toString(36).slice(2,9);
const clone = v => JSON.parse(JSON.stringify(v));

function snap() { hist.push(clone(blocks)); if(hist.length>50) hist.shift(); fut=[]; }
function undo()  { if(!hist.length) return; fut.push(clone(blocks)); blocks=hist.pop(); selId=null; render(); }
function redo()  { if(!fut.length)  return; hist.push(clone(blocks)); blocks=fut.pop();  selId=null; render(); }

document.addEventListener('keydown', e=>{
  if ((e.ctrlKey||e.metaKey) && e.key==='z') { e.preventDefault(); undo(); }
  if ((e.ctrlKey||e.metaKey) && e.key==='y') { e.preventDefault(); redo(); }
  if (e.key==='Escape') { selId=null; render(); closeDrawer(); }
});

// ═══════════════════════════════════════════════════
//  HTML RENDERERS  (produce inline-CSS for email)
// ═══════════════════════════════════════════════════
function blockHtml(b) {
  const d = b.data;
  const pad = 'padding:16px 28px;';
  const bgStyle = d.blockBg ? `background:${d.blockBg};` : '';
  let inner = '';

  switch(b.type) {
    case 'heading':
      inner = `<${d.level} style="margin:0;font-family:Arial,sans-serif;font-size:${d.size}px;font-weight:${d.weight};color:${d.color};text-align:${d.align};line-height:1.25;">${d.text}</${d.level}>`;
      break;
    case 'text':
      inner = `<p style="margin:0;font-family:Arial,sans-serif;font-size:${d.size}px;color:${d.color};text-align:${d.align};line-height:${d.lh};">${d.text}</p>`;
      break;
    case 'image':
      const isrc = d.src || 'https://placehold.co/600x200/e2e8f0/94a3b8?text=Add+Image+URL';
      inner = `<div style="text-align:${d.align};"><img src="${escAttr(isrc)}" alt="${escAttr(d.alt)}" style="max-width:100%;width:${d.width}%;border-radius:${d.radius}px;display:inline-block;"></div>`;
      break;
    case 'button':
      const fw = d.bold==='1' ? '700' : '400';
      inner = `<div style="text-align:${d.align};"><a href="${escAttr(d.href)}" style="display:inline-block;padding:12px 28px;background:${d.bg};color:${d.fg};font-family:Arial,sans-serif;font-size:${d.size}px;font-weight:${fw};text-decoration:none;border-radius:${d.radius}px;">${d.text}</a></div>`;
      break;
    case 'divider':
      inner = `<div style="padding:${d.my}px 0;"><hr style="border:none;border-top:${d.thick}px solid ${d.color};margin:0;"></div>`;
      break;
    case 'spacer':
      inner = `<div style="height:${d.h}px;"></div>`;
      break;
    case 'columns':
      inner = `<!--[if mso]><table width="100%"><tr><td width="50%"><![endif]--><table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="width:50%;padding-right:${Math.round(d.gap/2)}px;vertical-align:top;">${d.l}</td><td style="width:50%;padding-left:${Math.round(d.gap/2)}px;vertical-align:top;">${d.r}</td></tr></table><!--[if mso]></td></tr></table><![endif]-->`;
      break;
    case 'html':
      inner = d.code;
      break;
  }
  return `<div style="${pad}${bgStyle}">${inner}</div>`;
}

function escAttr(s) { return String(s).replace(/"/g,'&quot;'); }

// full email html for sending
function buildEmail() {
  const mw = document.getElementById('canvasWidthSel').value;
  const w  = mw === '100%' ? '100%' : mw+'px';
  let out  = `<div style="max-width:${w};margin:0 auto;background:${canvasBg};">`;
  blocks.forEach(b => out += blockHtml(b));
  out += '</div>';
  return out;
}

// ═══════════════════════════════════════════════════
//  RENDER
// ═══════════════════════════════════════════════════
function render() {
  HINT.style.display = blocks.length ? 'none' : 'flex';
  // remove old blocks
  Array.from(CANVAS.querySelectorAll('.ebl')).forEach(e=>e.remove());

  blocks.forEach((b,idx) => {
    const wrap = document.createElement('div');
    wrap.className = 'ebl' + (b.id===selId?' sel':'');
    wrap.dataset.id = b.id;
    wrap.draggable  = true;

    // toolbar
    const bar = document.createElement('div');
    bar.className = 'ebl-bar';
    bar.innerHTML = `
      <button title="Up"        onclick="mv('${b.id}',-1)"><i class="fa-solid fa-chevron-up"></i></button>
      <button title="Down"      onclick="mv('${b.id}',1)"><i class="fa-solid fa-chevron-down"></i></button>
      <button title="Duplicate" onclick="dupe('${b.id}')"><i class="fa-solid fa-copy"></i></button>
      <button title="Delete"    onclick="del('${b.id}')"><i class="fa-solid fa-trash"></i></button>`;

    // content
    const content = document.createElement('div');
    content.innerHTML = blockHtml(b);

    // make editable for text-based blocks
    if (b.type === 'heading' || b.type === 'text') {
      const el = content.querySelector('h1,h2,h3,h4,p');
      if (el) {
        el.contentEditable = 'true';
        el.style.outline   = 'none';
        el.addEventListener('focus', ()=>{ selId=b.id; selectBlock(b.id); });
        el.addEventListener('blur',  ()=>{ b.data.text = el.innerHTML; updatePP(); });
        el.addEventListener('keydown', e=>{ if(e.key==='Enter'&&b.type==='heading'){e.preventDefault();} });
      }
    }
    if (b.type === 'columns') {
      const tds = content.querySelectorAll('td');
      tds.forEach((td,i)=>{
        td.contentEditable = 'true';
        td.style.outline   = 'none';
        td.addEventListener('blur', ()=>{ if(i===0) b.data.l=td.innerHTML; else b.data.r=td.innerHTML; });
      });
    }

    wrap.appendChild(bar);
    wrap.appendChild(content);

    // click to select (not when clicking toolbar or contenteditable)
    wrap.addEventListener('click', e=>{
      if (e.target.closest('.ebl-bar')) return;
      if (e.target.contentEditable === 'true') return;
      selectBlock(b.id);
    });

    // drag reorder
    wrap.addEventListener('dragstart', e=>{ dragId=b.id; dragPal=null; e.dataTransfer.effectAllowed='move'; e.stopPropagation(); });
    wrap.addEventListener('dragend',   ()=>{ dragId=null; clearDI(); });
    wrap.addEventListener('dragover',  e=>{ e.preventDefault(); e.stopPropagation(); showDI(b.id, e); });
    wrap.addEventListener('dragleave', e=>{ if(!wrap.contains(e.relatedTarget)) clearDI(); });
    wrap.addEventListener('drop',      e=>{ e.preventDefault(); e.stopPropagation(); commitDrop(b.id); });

    CANVAS.appendChild(wrap);
  });
  updatePP();
}

function selectBlock(id) {
  selId = id;
  Array.from(CANVAS.querySelectorAll('.ebl')).forEach(e=>e.classList.toggle('sel', e.dataset.id===id));
  updatePP();
  // show mobile edit button
  const btn = document.getElementById('edit-props-btn');
  if (btn) { btn.classList.remove('hidden'); }
}

// ═══════════════════════════════════════════════════
//  DROP INDICATOR
// ═══════════════════════════════════════════════════
function showDI(id, e) {
  const wrap = CANVAS.querySelector(`[data-id="${id}"]`);
  if (!wrap) return;
  const r   = wrap.getBoundingClientRect();
  const mid = r.top + r.height/2;
  const pos = e.clientY < mid ? 'above' : 'below';
  if (dropTarget?.id===id && dropTarget?.pos===pos) return;
  clearDI();
  dropTarget = {id,pos};
  wrap.classList.add('drop-' + pos);
}
function clearDI() {
  CANVAS.querySelectorAll('.drop-above,.drop-below').forEach(e=>{ e.classList.remove('drop-above','drop-below'); });
  dropTarget = null;
}

// ═══════════════════════════════════════════════════
//  CANVAS DRAG/DROP  (from palette)
// ═══════════════════════════════════════════════════
document.querySelectorAll('.pb-item').forEach(el=>{
  el.addEventListener('dragstart', e=>{ dragPal=el.dataset.t; dragId=null; e.dataTransfer.effectAllowed='copy'; });
  el.addEventListener('dragend',   ()=>{ dragPal=null; });
  // click to add at end
  el.addEventListener('click', ()=>{ snap(); addBlock(el.dataset.t); });
});

CANVAS.addEventListener('dragover',  e=>{ e.preventDefault(); document.getElementById('eb-canvas-outer').classList.add('dov'); });
CANVAS.addEventListener('dragleave', e=>{ if(!CANVAS.contains(e.relatedTarget)) document.getElementById('eb-canvas-outer').classList.remove('dov'); });
CANVAS.addEventListener('drop', e=>{
  e.preventDefault();
  document.getElementById('eb-canvas-outer').classList.remove('dov');
  if (dragPal) {
    snap();
    const b = {id:uid(), type:dragPal, data:clone(DEF[dragPal])};
    if (dropTarget) {
      const idx = blocks.findIndex(x=>x.id===dropTarget.id);
      blocks.splice(dropTarget.pos==='above'?idx:idx+1, 0, b);
    } else {
      blocks.push(b);
    }
    selId = b.id;
    dragPal = null;
    clearDI();
    render();
  }
});

function commitDrop(targetId) {
  document.getElementById('eb-canvas-outer').classList.remove('dov');
  if (!dragId || dragId === targetId || !dropTarget) { clearDI(); return; }
  snap();
  const fi = blocks.findIndex(x=>x.id===dragId);
  let   ti = blocks.findIndex(x=>x.id===dropTarget.id);
  const [mv_] = blocks.splice(fi,1);
  ti = blocks.findIndex(x=>x.id===dropTarget.id);
  blocks.splice(dropTarget.pos==='above'?ti:ti+1, 0, mv_);
  dragId=null; clearDI(); render();
}

// ═══════════════════════════════════════════════════
//  BLOCK OPERATIONS
// ═══════════════════════════════════════════════════
function addBlock(type, afterId=null) {
  const b = {id:uid(), type, data:clone(DEF[type])};
  if (afterId) {
    const i = blocks.findIndex(x=>x.id===afterId);
    blocks.splice(i+1,0,b);
  } else { blocks.push(b); }
  selId = b.id; render();
  setTimeout(()=>{ const el=CANVAS.querySelector(`[data-id="${b.id}"]`); if(el) el.scrollIntoView({behavior:'smooth',block:'nearest'}); },50);
}
function del(id)  { snap(); blocks=blocks.filter(x=>x.id!==id); if(selId===id) selId=null; render(); }
function mv(id,d) {
  snap();
  const i=blocks.findIndex(x=>x.id===id), j=i+d;
  if(j<0||j>=blocks.length) return;
  [blocks[i],blocks[j]]=[blocks[j],blocks[i]]; render();
}
function dupe(id) {
  snap();
  const src=blocks.find(x=>x.id===id);
  if(!src) return;
  const cp={id:uid(),type:src.type,data:clone(src.data)};
  blocks.splice(blocks.findIndex(x=>x.id===id)+1,0,cp);
  selId=cp.id; render();
}
function clearAll() {
  if(!blocks.length) return;
  if(!confirm('Clear the canvas?')) return;
  snap(); blocks=[]; selId=null; render();
}
function setBg(v)    { canvasBg=v; CANVAS.style.background=v; }
function setWidth(v) { const o=document.getElementById('eb-canvas-outer'); o.style.maxWidth=v==='100%'?'100%':v+'px'; }

// ═══════════════════════════════════════════════════
//  PROPERTIES PANEL
// ═══════════════════════════════════════════════════
function updatePP() {
  const html = selId ? ppHtml(blocks.find(x=>x.id===selId)) : '<p class="text-xs text-slate-600 text-center py-10">Click a block to edit it</p>';
  document.getElementById('pp-content').innerHTML     = html;
  document.getElementById('mob-pp-content').innerHTML = html;
  wireProps('pp-content');
  wireProps('mob-pp-content');
}

function wireProps(containerId) {
  document.getElementById(containerId).querySelectorAll('[data-p]').forEach(el=>{
    el.addEventListener('input',  ()=>applyProp(el.dataset.p, el.value));
    el.addEventListener('change', ()=>applyProp(el.dataset.p, el.value));
  });
}

function applyProp(key, val) {
  if (!selId) return;
  const b = blocks.find(x=>x.id===selId);
  if (!b) return;
  b.data[key] = val;
  // re-render only the single block's content, not everything (prevents losing focus)
  const wrap = CANVAS.querySelector(`[data-id="${selId}"]`);
  if (!wrap) return;
  const content = wrap.querySelector(':not(.ebl-bar)');
  if (!content) return;
  content.innerHTML = blockHtml(b);
  // re-wire contenteditable
  if (b.type==='heading'||b.type==='text') {
    const el=content.querySelector('h1,h2,h3,h4,p');
    if(el){ el.contentEditable='true'; el.style.outline='none';
      el.addEventListener('blur',()=>{ b.data.text=el.innerHTML; }); }
  }
  // sync the other panel
  ['pp-content','mob-pp-content'].forEach(pid=>{
    const other=document.getElementById(pid).querySelector(`[data-p="${key}"]`);
    if(other && other.value !== val) other.value=val;
  });
}

function ppAlignBtns(key, cur) {
  return ['left','center','right'].map(a=>
    `<button type="button" class="pp-btn ${cur===a?'act':''}" data-align-key="${key}" data-align-val="${a}"
      onclick="applyProp('${key}','${a}');rerenderPP()">
      <i class="fa-solid fa-align-${a}"></i>
    </button>`
  ).join('');
}

function rerenderPP() {
  if(!selId) return;
  const b=blocks.find(x=>x.id===selId);
  if(!b) return;
  const html=ppHtml(b);
  document.getElementById('pp-content').innerHTML=html;
  document.getElementById('mob-pp-content').innerHTML=html;
  wireProps('pp-content'); wireProps('mob-pp-content');
}

function ppHtml(b) {
  if (!b) return '';
  const d = b.data;
  const row  = (label,input) => `<div class="pp-section"><label class="pp-label">${label}</label>${input}</div>`;
  const text = (key,val,ph='',type='text') => `<input type="${type}" class="pp-input" data-p="${key}" value="${escHtml(val)}" placeholder="${ph}">`;
  const num  = (key,val,min,max) => `<input type="number" class="pp-input" data-p="${key}" value="${val}" min="${min}" max="${max}">`;
  const color = (key,val) => `<input type="color" class="pp-color" data-p="${key}" value="${val||'#000000'}">`;
  const sel  = (key,val,opts) => `<select class="pp-input" data-p="${key}">${opts.map(([v,l])=>`<option value="${v}"${v===val?' selected':''}>${l}</option>`).join('')}</select>`;
  const aligns = (key,cur) => `<div class="pp-row">${ppAlignBtns(key,cur)}</div>`;

  let h = '';
  switch(b.type) {
    case 'heading':
      h += row('Text', `<div style="position:relative;"><input type="text" class="pp-input" data-p="text" value="${escHtml(d.text)}"></div>`);
      h += row('Level', sel('level',d.level,[['h1','H1 — Largest'],['h2','H2'],['h3','H3'],['h4','H4 — Smallest']]));
      h += row('Font Size', num('size',d.size,10,80));
      h += row('Weight', sel('weight',d.weight,[['400','Normal'],['600','Semi-bold'],['700','Bold'],['800','Extra-bold']]));
      h += row('Color', `<div class="pp-row">${color('color',d.color)}<input type="text" class="pp-input" data-p="color" value="${d.color}" style="flex:1;"></div>`);
      h += row('Align', aligns('align',d.align));
      break;
    case 'text':
      h += row('Font Size', num('size',d.size,10,40));
      h += row('Line Height', `<input type="number" class="pp-input" data-p="lh" value="${d.lh}" min="1" max="3" step="0.1">`);
      h += row('Color', `<div class="pp-row">${color('color',d.color)}<input type="text" class="pp-input" data-p="color" value="${d.color}" style="flex:1;"></div>`);
      h += row('Align', aligns('align',d.align));
      break;
    case 'image':
      h += row('Image URL', `<input type="text" class="pp-input" data-p="src" value="${escHtml(d.src)}" placeholder="https://... or paste from library below">`);
      h += row('Alt Text', text('alt',d.alt,'Describe the image'));
      h += row('Width %', num('width',d.width,10,100));
      h += row('Corner Radius', num('radius',d.radius,0,50));
      h += row('Align', aligns('align',d.align));
      break;
    case 'button':
      h += row('Button Text', text('text',d.text));
      h += row('Link URL', text('href',d.href,'https://'));
      h += row('Background', `<div class="pp-row">${color('bg',d.bg)}<input type="text" class="pp-input" data-p="bg" value="${d.bg}" style="flex:1;"></div>`);
      h += row('Text Color', `<div class="pp-row">${color('fg',d.fg)}<input type="text" class="pp-input" data-p="fg" value="${d.fg}" style="flex:1;"></div>`);
      h += row('Font Size', num('size',d.size,10,28));
      h += row('Bold', sel('bold',d.bold,[['1','Yes'],['0','No']]));
      h += row('Border Radius', num('radius',d.radius,0,50));
      h += row('Align', aligns('align',d.align));
      break;
    case 'divider':
      h += row('Color', `<div class="pp-row">${color('color',d.color)}<input type="text" class="pp-input" data-p="color" value="${d.color}" style="flex:1;"></div>`);
      h += row('Thickness px', num('thick',d.thick,1,12));
      h += row('Spacing px', num('my',d.my,0,80));
      break;
    case 'spacer':
      h += row('Height px', num('h',d.h,4,200));
      break;
    case 'columns':
      h += row('Column Gap px', num('gap',d.gap,0,60));
      h += `<p class="text-xs text-slate-500 mt-1">Click either column directly on the canvas to edit text inline.</p>`;
      break;
    case 'html':
      h += row('HTML Code', `<textarea class="pp-input" data-p="code" rows="6" style="font-family:monospace;font-size:.72rem;resize:vertical;">${escHtml(d.code)}</textarea>`);
      break;
  }
  // block background for all
  h += row('Block Background', `<div class="pp-row">${color('blockBg',d.blockBg||'#ffffff')}<input type="text" class="pp-input" data-p="blockBg" value="${d.blockBg||'#ffffff'}" style="flex:1;"></div>`);
  return h;
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════════════════════════════════════════
//  AUDIENCE
// ═══════════════════════════════════════════════════
document.getElementById('aud-btns').addEventListener('click', e=>{
  const btn = e.target.closest('.aud-pill');
  if (!btn) return;
  document.querySelectorAll('.aud-pill').forEach(b=>b.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById('custom-box').classList.toggle('hidden', btn.dataset.v!=='custom');
});

// ═══════════════════════════════════════════════════
//  SEND
// ═══════════════════════════════════════════════════
function doSend() {
  if (!blocks.length) { alert('Please add at least one block to your email.'); return; }
  const subj = document.getElementById('s-subject').value.trim();
  if (!subj) { alert('Please enter a subject line.'); return; }
  const aud  = document.querySelector('.aud-pill.on')?.dataset.v || 'all';
  const body = buildEmail();
  const conf = confirm(`Send "${subj}" to ${aud} users?\n\nThis cannot be undone.`);
  if (!conf) return;
  document.getElementById('f-audience').value = aud;
  document.getElementById('f-subject').value  = subj;
  document.getElementById('f-body').value     = body;
  document.getElementById('f-custom').value   = document.getElementById('custom-emails').value;
  document.getElementById('blast-form').submit();
}

// ═══════════════════════════════════════════════════
//  PREVIEW MODAL
// ═══════════════════════════════════════════════════
function prevModal() {
  const m = document.getElementById('prev-modal');
  const showing = m.classList.contains('flex');
  if (showing) { m.classList.replace('flex','hidden'); return; }
  const html = buildEmail();
  document.getElementById('pv-iframe').srcdoc =
    `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>body{margin:0;padding:20px;background:#f1f5f9;}</style>
    </head><body>${html}</body></html>`;
  m.classList.replace('hidden','flex');
}
function setPrevMode(mode) {
  const frame=document.getElementById('pv-frame');
  const d=document.getElementById('pv-d'), mb=document.getElementById('pv-m');
  if(mode==='mobile')  { frame.style.maxWidth='390px'; frame.style.boxShadow='0 0 0 8px #1e293b,0 0 0 10px #475569'; d.classList.remove('on'); mb.classList.add('on'); }
  else                 { frame.style.maxWidth='700px'; frame.style.boxShadow='none'; mb.classList.remove('on'); d.classList.add('on'); }
}
// close on backdrop click
document.getElementById('prev-modal').addEventListener('click', e=>{ if(e.target===e.currentTarget) prevModal(); });

// ═══════════════════════════════════════════════════
//  MOBILE DRAWER
// ═══════════════════════════════════════════════════
function openDrawer()  { document.getElementById('mob-drawer').classList.add('open'); }
function closeDrawer() { document.getElementById('mob-drawer').classList.remove('open'); }

// ═══════════════════════════════════════════════════
//  IMAGE LIBRARY
// ═══════════════════════════════════════════════════
let imgLib = [];
try { const s=localStorage.getItem('eb_img_lib'); if(s) imgLib=JSON.parse(s); } catch(e){}

function handleImgFiles(files) {
  Array.from(files).forEach(f=>{
    if (!f.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = e => addToLib(e.target.result, f.name);
    reader.readAsDataURL(f);
  });
}
function handleImgDrop(e) {
  e.preventDefault();
  document.getElementById('img-uploader').classList.remove('ov');
  handleImgFiles(e.dataTransfer.files);
}
function addToLib(dataUrl, name) {
  imgLib.unshift({url:dataUrl, name});
  if (imgLib.length > 20) imgLib.pop();
  try { localStorage.setItem('eb_img_lib', JSON.stringify(imgLib)); } catch(e){}
  renderLib();
}
function renderLib() {
  const grid = document.getElementById('img-grid');
  if (!imgLib.length) { grid.innerHTML=''; return; }
  grid.innerHTML = `<div class="img-thumb-grid">${imgLib.map((img,i)=>
    `<div class="img-thumb" onclick="copyImgUrl(${i})" title="Click to copy URL">
      <img src="${img.url}" alt="">
      <div class="copy-tag"><i class="fa-solid fa-copy"></i> Copy URL</div>
    </div>`
  ).join('')}</div>
  <p class="text-xs text-slate-600 mt-2">Click any image to copy its URL. Paste into an Image block's URL field.</p>`;
}
function copyImgUrl(i) {
  const url = imgLib[i].url;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(()=>showToast('URL copied to clipboard!'));
  } else {
    const ta=document.createElement('textarea'); ta.value=url;
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta); showToast('URL copied!');
  }
}
function showToast(msg) {
  let t=document.getElementById('eb-toast');
  if(!t){ t=document.createElement('div'); t.id='eb-toast';
    t.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#fff;color:#000;padding:10px 20px;border-radius:12px;font-size:.8rem;font-weight:600;z-index:9999;opacity:0;transition:opacity .2s;pointer-events:none;';
    document.body.appendChild(t); }
  t.textContent=msg; t.style.opacity='1';
  clearTimeout(t._t); t._t=setTimeout(()=>t.style.opacity='0',2000);
}

renderLib();
render();
</script>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
