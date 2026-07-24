<?php
/**
 * admin/email.php — Drag-and-drop email blast builder.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/mailer.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$sent = 0;
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify('email_blast', $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'CSRF failure on email blast');
        die('Invalid CSRF token.');
    }
    $subject  = trim(strip_tags($_POST['subject'] ?? ''));
    $bodyHtml = trim($_POST['body_html'] ?? '');
    $audience = $_POST['audience'] ?? 'all';
    $customRaw= trim($_POST['custom_emails'] ?? '');

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
        $footer = "<p style=\"font-size:11px;color:#334155;margin:12px 0 0;text-align:center;\"><a href=\"https://utiligo.ca/unsubscribe\" style=\"color:#475569;\">Unsubscribe</a></p>";
        _admin_log('INFO', "Email blast: subject='{$subject}' audience={$audience} to=".count($recipients));
        foreach ($recipients as $r) {
            $html = email_wrapper('Message from Utiligo', "<p>Hi {$r['full_name']},</p>{$bodyHtml}", $footer);
            $ok   = send_email($r['email'], $subject, $html, '', $r['full_name']);
            if ($ok) $sent++; else $errors[] = 'Failed: '.$r['email'];
            if ($sent % 10 === 0 && $sent > 0) usleep(300000);
        }
        _admin_log('INFO', "Blast done: sent={$sent} errors=".count($errors));
        $success = "Blast sent to {$sent} recipient(s).";
    }
}

$csrf      = admin_csrf_token('email_blast');
$pageTitle = 'Email Blast — Admin — Utiligo';
$adminPage = 'email';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
/* ===== BUILDER LAYOUT ===== */
.builder-wrap   { display:flex; gap:1.25rem; align-items:flex-start; }
.builder-blocks { width:200px; shrink:0; }
.builder-canvas-wrap { flex:1; min-width:0; }
.builder-props  { width:240px; shrink:0; }

@media(max-width:1199px) {
  .builder-props { display:none; }
}
@media(max-width:899px) {
  .builder-wrap   { flex-direction:column; }
  .builder-blocks { width:100%; }
  .builder-blocks .block-list { display:flex; flex-wrap:wrap; gap:.5rem; }
  .block-item     { flex:1 1 calc(50% - .25rem) !important; }
}

/* ===== BLOCK PALETTE ===== */
.block-item {
  display:flex; align-items:center; gap:8px;
  padding:10px 12px; border-radius:12px;
  background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07);
  cursor:grab; color:#94a3b8; font-size:.8rem; font-weight:500;
  transition:all .15s; user-select:none;
}
.block-item:hover { background:rgba(139,92,246,.12); color:#ddd6fe; border-color:rgba(139,92,246,.3); }
.block-item i { width:16px; text-align:center; color:#a78bfa; }

/* ===== CANVAS ===== */
#canvas {
  min-height:480px;
  background:#fff;
  border-radius:16px;
  overflow:hidden;
  cursor:default;
  font-family:'Inter',Arial,sans-serif;
}
#canvas.drag-over { outline:2px dashed #a78bfa; outline-offset:3px; }
#canvas .empty-hint {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  height:320px; color:#cbd5e1; font-size:.875rem; gap:12px;
}
#canvas .empty-hint i { font-size:2.5rem; color:#e2e8f0; }

/* ===== EMAIL BLOCKS ===== */
.eblock {
  position:relative;
  cursor:pointer;
  transition:outline .1s;
}
.eblock:hover  { outline:2px solid rgba(139,92,246,.4); }
.eblock.selected { outline:2px solid #a78bfa !important; }
.eblock .block-toolbar {
  display:none;
  position:absolute; top:-1px; right:0;
  background:#1e1b4b; border-radius:0 0 0 10px;
  overflow:hidden; z-index:10;
}
.eblock:hover .block-toolbar,
.eblock.selected .block-toolbar { display:flex; }
.block-toolbar button {
  padding:5px 8px; background:transparent; border:none;
  color:#c4b5fd; cursor:pointer; font-size:.7rem; transition:background .1s;
}
.block-toolbar button:hover { background:rgba(255,255,255,.1); }

/* ===== PROPS PANEL ===== */
.prop-group { margin-bottom:1rem; }
.prop-label { font-size:.7rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.35rem; }
.prop-input {
  width:100%; padding:7px 10px; border-radius:9px;
  background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1);
  color:#e2e8f0; font-size:.8rem; outline:none;
  transition:border-color .15s;
}
.prop-input:focus { border-color:#a78bfa; }
.prop-color { width:36px; height:28px; border-radius:7px; border:1px solid rgba(255,255,255,.1); cursor:pointer; padding:2px; background:transparent; }
.prop-row { display:flex; align-items:center; gap:.5rem; }
</style>

<!-- Page header -->
<div class="mb-6">
  <h1 class="text-3xl font-bold tracking-tight">Email Blast</h1>
  <p class="text-slate-400 text-sm mt-1">Design your email, set your audience, and send.</p>
</div>

<?php if ($success): ?>
  <div class="mb-5 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 px-5 py-3.5 rounded-xl flex items-center gap-3 text-sm">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="mb-3 bg-red-500/10 border border-red-500/30 text-red-400 px-5 py-3 rounded-xl text-sm"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<form method="POST" id="blastForm" onsubmit="return prepareAndConfirm(this)">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="audience" id="audience_val" value="all">
  <input type="hidden" name="body_html" id="body_html_hidden">

  <!-- ===== TOP SETTINGS STRIP ===== -->
  <div class="glass rounded-2xl border border-white/5 p-5 mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

    <!-- Audience -->
    <div>
      <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Audience</label>
      <div class="flex flex-wrap gap-1.5" id="audience_btns">
        <?php
        $aud_opts = ['all'=>'All','pro'=>'Pro','entrepreneur'=>'ENT','free'=>'Free','custom'=>'Custom'];
        foreach ($aud_opts as $v => $l):
        ?>
        <button type="button"
          class="audience-pill <?= $v==='all'?'pill-active':'' ?>"
          data-val="<?= $v ?>" onclick="setAudience(this)">
          <?= $l ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Subject -->
    <div class="sm:col-span-2">
      <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Subject Line</label>
      <input type="text" name="subject" id="subject_input" required
             placeholder="e.g. New features just dropped!"
             value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
             class="w-full bg-white/5 border border-white/10 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-purple-500 transition">
    </div>

    <!-- Send button -->
    <div class="flex items-end">
      <button type="submit"
        class="w-full bg-purple-600 hover:bg-purple-500 text-white py-2.5 px-6 rounded-xl font-bold text-sm transition flex items-center justify-center gap-2">
        <i class="fa-solid fa-paper-plane"></i> Send Blast
      </button>
    </div>
  </div>

  <!-- Custom emails box -->
  <div id="custom_box" class="hidden glass rounded-2xl border border-white/5 p-5 mb-5">
    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Custom Email Addresses <span class="normal-case font-normal text-slate-500">(one per line or comma-separated)</span></label>
    <textarea name="custom_emails" rows="3"
      class="w-full bg-white/5 border border-white/10 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-purple-500 transition"
      placeholder="alice@example.com, bob@example.com"></textarea>
  </div>

  <!-- ===== BUILDER ===== -->
  <div class="builder-wrap">

    <!-- Block palette -->
    <div class="builder-blocks">
      <div class="glass rounded-2xl border border-white/5 p-4">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Blocks</p>
        <div class="block-list space-y-2">
          <div class="block-item" draggable="true" data-type="heading"><i class="fa-solid fa-heading"></i> Heading</div>
          <div class="block-item" draggable="true" data-type="text"><i class="fa-solid fa-align-left"></i> Text</div>
          <div class="block-item" draggable="true" data-type="image"><i class="fa-solid fa-image"></i> Image</div>
          <div class="block-item" draggable="true" data-type="button"><i class="fa-solid fa-rectangle-ad"></i> Button</div>
          <div class="block-item" draggable="true" data-type="divider"><i class="fa-solid fa-minus"></i> Divider</div>
          <div class="block-item" draggable="true" data-type="spacer"><i class="fa-solid fa-arrows-up-down"></i> Spacer</div>
          <div class="block-item" draggable="true" data-type="columns"><i class="fa-solid fa-table-columns"></i> 2 Columns</div>
          <div class="block-item" draggable="true" data-type="social"><i class="fa-solid fa-share-nodes"></i> Social</div>
        </div>
        <div class="mt-4 pt-4 border-t border-white/5 space-y-2">
          <button type="button" onclick="clearCanvas()"
            class="w-full text-xs py-2 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition font-semibold">
            <i class="fa-solid fa-trash-can mr-1"></i> Clear All
          </button>
          <button type="button" onclick="togglePreviewModal()"
            class="w-full text-xs py-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 border border-white/10 transition font-semibold">
            <i class="fa-solid fa-eye mr-1"></i> Preview
          </button>
        </div>
      </div>
    </div>

    <!-- Canvas -->
    <div class="builder-canvas-wrap">
      <div class="glass rounded-2xl border border-white/5 p-3">
        <!-- Canvas toolbar -->
        <div class="flex items-center justify-between mb-3 px-1">
          <div class="flex items-center gap-2">
            <span class="text-xs text-slate-500">Background</span>
            <input type="color" id="canvasBg" value="#ffffff" onchange="setCanvasBg(this.value)"
                   class="prop-color" title="Canvas background">
          </div>
          <div class="flex gap-2">
            <button type="button" onclick="undoAction()" title="Undo"
              class="text-xs px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition">
              <i class="fa-solid fa-rotate-left"></i>
            </button>
            <button type="button" onclick="redoAction()" title="Redo"
              class="text-xs px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition">
              <i class="fa-solid fa-rotate-right"></i>
            </button>
          </div>
        </div>
        <!-- The email canvas -->
        <div id="canvas"
             ondragover="onCanvasDragOver(event)"
             ondragleave="onCanvasDragLeave(event)"
             ondrop="onCanvasDrop(event)"
             onclick="onCanvasClick(event)">
          <div class="empty-hint" id="emptyHint">
            <i class="fa-solid fa-envelope-open-text"></i>
            <span>Drag blocks here to build your email</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Properties panel (desktop) -->
    <div class="builder-props">
      <div class="glass rounded-2xl border border-white/5 p-4 sticky top-6">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4">Properties</p>
        <div id="propsPanel">
          <p class="text-xs text-slate-600 text-center py-8">Select a block to edit its properties</p>
        </div>
      </div>
    </div>

  </div><!-- /builder-wrap -->

</form>

<!-- ===== MOBILE PROPS DRAWER ===== -->
<div id="propsDrawer" class="fixed inset-0 z-50 flex flex-col justify-end pointer-events-none">
  <div id="propsDrawerBg" class="absolute inset-0 bg-black/60 opacity-0 transition-opacity duration-200 pointer-events-none" onclick="closePropsDrawer()"></div>
  <div id="propsDrawerPanel"
       class="relative bg-slate-900 border-t border-white/10 rounded-t-2xl p-5 pointer-events-auto
              translate-y-full transition-transform duration-300 ease-in-out max-h-[70vh] overflow-y-auto">
    <div class="flex items-center justify-between mb-4">
      <p class="text-sm font-semibold text-white">Block Properties</p>
      <button onclick="closePropsDrawer()" class="text-slate-400 hover:text-white text-lg">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <div id="propsPanelMobile">
      <p class="text-xs text-slate-500 text-center py-4">Select a block to edit</p>
    </div>
  </div>
</div>

<!-- ===== PREVIEW MODAL ===== -->
<div id="previewModal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center p-4">
  <div class="bg-slate-900 border border-white/10 rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-white/10">
      <span class="font-semibold text-sm">Email Preview</span>
      <div class="flex items-center gap-2">
        <button onclick="setPreviewMode('desktop')" id="previewDesktopBtn"
          class="text-xs px-3 py-1.5 rounded-lg bg-purple-500/20 text-purple-300 border border-purple-500/30 font-semibold">
          <i class="fa-solid fa-desktop"></i> Desktop
        </button>
        <button onclick="setPreviewMode('mobile')" id="previewMobileBtn"
          class="text-xs px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 border border-white/10">
          <i class="fa-solid fa-mobile-screen"></i> Mobile
        </button>
        <button onclick="togglePreviewModal()" class="text-slate-400 hover:text-white ml-1">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </div>
    <div class="flex-1 overflow-auto p-4">
      <div id="previewFrame" class="mx-auto transition-all duration-300 bg-white rounded-xl overflow-hidden" style="width:100%">
        <iframe id="previewIframe" class="w-full border-0" style="min-height:500px;display:block;"></iframe>
      </div>
    </div>
  </div>
</div>

<style>
.audience-pill {
  padding:5px 14px; border-radius:999px; font-size:.75rem; font-weight:600;
  border:1px solid rgba(255,255,255,.1); cursor:pointer; transition:all .15s;
  color:#94a3b8; background:transparent;
}
.audience-pill:hover { background:rgba(255,255,255,.06); color:#fff; }
.audience-pill.pill-active { background:#7c3aed; color:#fff; border-color:#7c3aed; }
</style>

<script>
// ============================================================
//  STATE
// ============================================================
let blocks      = [];   // [{id, type, data:{...}}, ...]
let selectedId  = null;
let dragType    = null; // type being dragged from palette
let dragBlockId = null; // id of block being reordered
let history     = [];   // undo stack (snapshots of blocks[])
let future      = [];   // redo stack
const CANVAS    = document.getElementById('canvas');
const HINT      = document.getElementById('emptyHint');

const DEFAULT_DATA = {
  heading : { text:'Your Heading Here', level:'h2', color:'#111827', align:'left', fontSize:'24' },
  text    : { text:'Your text content here. Click to edit.', color:'#374151', align:'left', fontSize:'15', lineHeight:'1.7' },
  image   : { src:'https://via.placeholder.com/600x200/e2e8f0/94a3b8?text=Image', alt:'Image', align:'center', width:'100' },
  button  : { text:'Click Here', href:'https://utiligo.ca', bgColor:'#7c3aed', textColor:'#ffffff', align:'center', radius:'8', fontSize:'15' },
  divider : { color:'#e5e7eb', thickness:'1', marginY:'16' },
  spacer  : { height:'32' },
  columns : { col1:'<p style="color:#374151">Left column</p>', col2:'<p style="color:#374151">Right column</p>', gap:'16' },
  social  : { links:JSON.stringify([{icon:'fa-twitter',url:'https://twitter.com'},{icon:'fa-linkedin',url:'https://linkedin.com'},{icon:'fa-instagram',url:'https://instagram.com'}]), color:'#7c3aed', size:'28' },
};

// ============================================================
//  UTILS
// ============================================================
function uid() { return Math.random().toString(36).slice(2,10); }
function snapshot() { history.push(JSON.stringify(blocks)); future=[]; }
function undoAction()  { if (!history.length) return; future.push(JSON.stringify(blocks)); blocks=JSON.parse(history.pop()); selectedId=null; renderAll(); }
function redoAction()  { if (!future.length)  return; history.push(JSON.stringify(blocks)); blocks=JSON.parse(future.pop()); selectedId=null; renderAll(); }

// ============================================================
//  BLOCK RENDERING
// ============================================================
function renderBlock(b) {
  const d = b.data;
  const padStyle = `padding:16px 24px;`;
  let inner = '';
  switch (b.type) {
    case 'heading':
      inner = `<${d.level} contenteditable="true" style="margin:0;font-family:Inter,Arial,sans-serif;font-size:${d.fontSize}px;color:${d.color};text-align:${d.align};line-height:1.3;">${d.text}</${d.level}>`;
      break;
    case 'text':
      inner = `<p contenteditable="true" style="margin:0;font-family:Inter,Arial,sans-serif;font-size:${d.fontSize}px;color:${d.color};text-align:${d.align};line-height:${d.lineHeight};">${d.text}</p>`;
      break;
    case 'image':
      inner = `<div style="text-align:${d.align};"><img src="${d.src}" alt="${d.alt}" style="max-width:100%;width:${d.width}%;border-radius:6px;display:inline-block;"></div>`;
      break;
    case 'button':
      inner = `<div style="text-align:${d.align};"><a href="${d.href}" style="display:inline-block;padding:12px 28px;background:${d.bgColor};color:${d.textColor};font-family:Inter,Arial,sans-serif;font-size:${d.fontSize}px;font-weight:600;text-decoration:none;border-radius:${d.radius}px;">${d.text}</a></div>`;
      break;
    case 'divider':
      inner = `<div style="padding:${d.marginY}px 0;"><hr style="border:none;border-top:${d.thickness}px solid ${d.color};margin:0;"></div>`;
      break;
    case 'spacer':
      inner = `<div style="height:${d.height}px;"></div>`;
      break;
    case 'columns':
      inner = `<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>
        <td width="50%" style="padding-right:${d.gap/2}px;vertical-align:top;" contenteditable="true">${d.col1}</td>
        <td width="50%" style="padding-left:${d.gap/2}px;vertical-align:top;" contenteditable="true">${d.col2}</td>
      </tr></table>`;
      break;
    case 'social':
      let links = [];
      try { links = JSON.parse(d.links); } catch(e) {}
      inner = `<div style="text-align:center;">${links.map(l=>`<a href="${l.url}" style="display:inline-flex;align-items:center;justify-content:center;width:${d.size}px;height:${d.size}px;background:${d.color};color:#fff;border-radius:50%;margin:0 5px;text-decoration:none;font-size:${Math.round(d.size*.45)}px;"><i class="fa-brands ${l.icon}"></i></a>`).join('')}</div>`;
      break;
  }
  const bgStyle = b.data.bgColor && b.type !== 'button' ? `background:${b.data.bgColor};` : '';
  return `<div style="${padStyle}${bgStyle}">${inner}</div>`;
}

function renderAll() {
  HINT.style.display = blocks.length ? 'none' : 'flex';
  // remove existing eblocks
  Array.from(CANVAS.querySelectorAll('.eblock')).forEach(el => el.remove());
  blocks.forEach(b => {
    const wrap = document.createElement('div');
    wrap.className = 'eblock' + (b.id === selectedId ? ' selected' : '');
    wrap.dataset.id = b.id;
    wrap.draggable = true;
    wrap.innerHTML = `
      <div class="block-toolbar">
        <button type="button" title="Move up" onclick="moveBlock('${b.id}',-1)"><i class="fa-solid fa-chevron-up"></i></button>
        <button type="button" title="Move down" onclick="moveBlock('${b.id}',1)"><i class="fa-solid fa-chevron-down"></i></button>
        <button type="button" title="Duplicate" onclick="dupeBlock('${b.id}')"><i class="fa-solid fa-copy"></i></button>
        <button type="button" title="Delete" onclick="deleteBlock('${b.id}')"><i class="fa-solid fa-trash"></i></button>
      </div>
      ${renderBlock(b)}
    `;
    // drag reorder
    wrap.addEventListener('dragstart', e => { dragBlockId=b.id; dragType=null; e.dataTransfer.effectAllowed='move'; });
    wrap.addEventListener('dragend',   () => { dragBlockId=null; });
    // contenteditable change
    wrap.querySelectorAll('[contenteditable]').forEach(el => {
      el.addEventListener('input', () => saveContentEditable(b.id, wrap));
    });
    CANVAS.appendChild(wrap);
  });
  updatePropsPanel();
}

function saveContentEditable(id, wrap) {
  const b = blocks.find(x=>x.id===id);
  if (!b) return;
  if (b.type==='heading') {
    const el = wrap.querySelector('[contenteditable]');
    if (el) b.data.text = el.innerHTML;
  } else if (b.type==='text') {
    const el = wrap.querySelector('[contenteditable]');
    if (el) b.data.text = el.innerHTML;
  } else if (b.type==='columns') {
    const cells = wrap.querySelectorAll('[contenteditable]');
    if (cells[0]) b.data.col1 = cells[0].innerHTML;
    if (cells[1]) b.data.col2 = cells[1].innerHTML;
  }
}

// ============================================================
//  CANVAS DRAG/DROP
// ============================================================
document.querySelectorAll('.block-item[draggable]').forEach(el => {
  el.addEventListener('dragstart', e => { dragType=el.dataset.type; dragBlockId=null; e.dataTransfer.effectAllowed='copy'; });
});

function onCanvasDragOver(e)  { e.preventDefault(); CANVAS.classList.add('drag-over'); }
function onCanvasDragLeave(e) { if (!CANVAS.contains(e.relatedTarget)) CANVAS.classList.remove('drag-over'); }

function onCanvasDrop(e) {
  e.preventDefault();
  CANVAS.classList.remove('drag-over');
  const target = e.target.closest('.eblock');
  if (dragType) {
    // new block from palette
    snapshot();
    const b = { id:uid(), type:dragType, data: JSON.parse(JSON.stringify(DEFAULT_DATA[dragType])) };
    if (target) {
      const idx = blocks.findIndex(x=>x.id===target.dataset.id);
      blocks.splice(idx+1,0,b);
    } else {
      blocks.push(b);
    }
    selectedId = b.id;
    dragType   = null;
    renderAll();
  } else if (dragBlockId && target && target.dataset.id !== dragBlockId) {
    // reorder
    snapshot();
    const fromIdx = blocks.findIndex(x=>x.id===dragBlockId);
    const toIdx   = blocks.findIndex(x=>x.id===target.dataset.id);
    const [moved] = blocks.splice(fromIdx,1);
    blocks.splice(toIdx,0,moved);
    dragBlockId = null;
    renderAll();
  }
}

function onCanvasClick(e) {
  const wrap = e.target.closest('.eblock');
  if (!wrap) { selectedId=null; renderAll(); return; }
  if (e.target.closest('.block-toolbar')) return;
  const id = wrap.dataset.id;
  selectedId = id;
  renderAll();
  // open mobile drawer on small screens
  if (window.innerWidth < 1200) openPropsDrawer();
}

// ============================================================
//  BLOCK OPERATIONS
// ============================================================
function addBlock(type) {
  snapshot();
  const b = { id:uid(), type, data: JSON.parse(JSON.stringify(DEFAULT_DATA[type])) };
  blocks.push(b); selectedId=b.id; renderAll();
}
function deleteBlock(id) {
  snapshot();
  blocks = blocks.filter(b=>b.id!==id);
  if (selectedId===id) selectedId=null;
  renderAll();
}
function moveBlock(id, dir) {
  snapshot();
  const idx = blocks.findIndex(b=>b.id===id);
  const to  = idx+dir;
  if (to<0||to>=blocks.length) return;
  [blocks[idx],blocks[to]] = [blocks[to],blocks[idx]];
  renderAll();
}
function dupeBlock(id) {
  snapshot();
  const src = blocks.find(b=>b.id===id);
  if (!src) return;
  const copy = { id:uid(), type:src.type, data:JSON.parse(JSON.stringify(src.data)) };
  const idx  = blocks.findIndex(b=>b.id===id);
  blocks.splice(idx+1,0,copy);
  selectedId = copy.id;
  renderAll();
}
function clearCanvas() {
  if (!blocks.length) return;
  if (!confirm('Clear the entire email canvas?')) return;
  snapshot(); blocks=[]; selectedId=null; renderAll();
}
function setCanvasBg(v) {
  CANVAS.style.backgroundColor = v;
}

// ============================================================
//  PROPERTIES PANEL
// ============================================================
function updatePropsPanel() {
  const html = selectedId ? buildPropsHTML(blocks.find(b=>b.id===selectedId)) : '<p class="text-xs text-slate-600 text-center py-8">Select a block to edit its properties</p>';
  document.getElementById('propsPanel').innerHTML = html;
  document.getElementById('propsPanelMobile').innerHTML = html;
  // wire up listeners for both panels
  ['propsPanel','propsPanelMobile'].forEach(pid => {
    const panel = document.getElementById(pid);
    panel.querySelectorAll('[data-prop]').forEach(el => {
      el.addEventListener('input',  () => applyProp(el));
      el.addEventListener('change', () => applyProp(el));
    });
  });
}

function buildPropsHTML(b) {
  if (!b) return '';
  const d = b.data;
  let rows = '';
  const inp = (label, prop, val, type='text', extra='') =>
    `<div class="prop-group">
       <div class="prop-label">${label}</div>
       <input type="${type}" class="${type==='color'?'prop-color':'prop-input'}" data-prop="${prop}" value="${val}" ${extra}>
     </div>`;
  const sel = (label, prop, val, opts) =>
    `<div class="prop-group">
       <div class="prop-label">${label}</div>
       <select class="prop-input" data-prop="${prop}">${opts.map(([v,l])=>`<option value="${v}"${v===val?' selected':''}>${l}</option>`).join('')}</select>
     </div>`;
  const alignBtns = (label, prop, val) =>
    `<div class="prop-group">
       <div class="prop-label">${label}</div>
       <div style="display:flex;gap:4px;">
         ${['left','center','right'].map(a=>`<button type="button" data-prop="${prop}" data-val="${a}" onclick="applyPropDirect('${b.id}','${prop}','${a}')" style="flex:1;padding:5px;border-radius:7px;border:1px solid rgba(255,255,255,.1);background:${val===a?'rgba(124,58,237,.4)':'rgba(255,255,255,.05)'};color:#e2e8f0;cursor:pointer;font-size:11px;"><i class='fa-solid fa-align-${a}'></i></button>`).join('')}
       </div>
     </div>`;

  switch (b.type) {
    case 'heading':
      rows += inp('Text Color','color',d.color,'color');
      rows += inp('Font Size (px)','fontSize',d.fontSize,'number','min="10" max="72"');
      rows += sel('Level','level',d.level,[['h1','H1'],['h2','H2'],['h3','H3']]);
      rows += alignBtns('Align','align',d.align);
      break;
    case 'text':
      rows += inp('Text Color','color',d.color,'color');
      rows += inp('Font Size (px)','fontSize',d.fontSize,'number','min="10" max="40"');
      rows += inp('Line Height','lineHeight',d.lineHeight,'number','min="1" max="3" step="0.1"');
      rows += alignBtns('Align','align',d.align);
      break;
    case 'image':
      rows += `<div class="prop-group"><div class="prop-label">Image URL</div><input type="text" class="prop-input" data-prop="src" value="${d.src}"></div>`;
      rows += inp('Alt Text','alt',d.alt);
      rows += inp('Width %','width',d.width,'number','min="10" max="100"');
      rows += alignBtns('Align','align',d.align);
      break;
    case 'button':
      rows += `<div class="prop-group"><div class="prop-label">Button Text</div><input type="text" class="prop-input" data-prop="text" value="${d.text}"></div>`;
      rows += `<div class="prop-group"><div class="prop-label">Link URL</div><input type="text" class="prop-input" data-prop="href" value="${d.href}"></div>`;
      rows += `<div class="prop-group"><div class="prop-label">Colors</div><div class="prop-row"><input type="color" class="prop-color" data-prop="bgColor" value="${d.bgColor}" title="Background"><input type="color" class="prop-color" data-prop="textColor" value="${d.textColor}" title="Text"></div></div>`;
      rows += inp('Radius (px)','radius',d.radius,'number','min="0" max="50"');
      rows += inp('Font Size','fontSize',d.fontSize,'number','min="10" max="28"');
      rows += alignBtns('Align','align',d.align);
      break;
    case 'divider':
      rows += inp('Color','color',d.color,'color');
      rows += inp('Thickness (px)','thickness',d.thickness,'number','min="1" max="10"');
      rows += inp('Spacing (px)','marginY',d.marginY,'number','min="0" max="80"');
      break;
    case 'spacer':
      rows += inp('Height (px)','height',d.height,'number','min="4" max="200"');
      break;
    case 'columns':
      rows += inp('Column Gap (px)','gap',d.gap,'number','min="0" max="60"');
      break;
    case 'social':
      rows += inp('Icon Color','color',d.color,'color');
      rows += inp('Icon Size (px)','size',d.size,'number','min="20" max="60"');
      rows += `<div class="prop-group"><div class="prop-label">Links JSON</div><textarea class="prop-input" data-prop="links" rows="4" style="resize:vertical;">${d.links}</textarea></div>`;
      break;
  }
  // Block background
  if (!['divider','spacer'].includes(b.type)) {
    rows += inp('Block BG','bgColor', d.bgColor||'#ffffff','color');
  }
  return rows;
}

function applyProp(el) {
  if (!selectedId) return;
  const b = blocks.find(x=>x.id===selectedId);
  if (!b) return;
  b.data[el.dataset.prop] = el.value;
  const wrap = CANVAS.querySelector(`[data-id="${selectedId}"]`);
  if (wrap) {
    wrap.querySelector(':not(.block-toolbar)').outerHTML = renderBlock(b);
    wrap.querySelectorAll('[contenteditable]').forEach(ce => {
      ce.addEventListener('input', () => saveContentEditable(b.id, wrap));
    });
    // keep props in sync
    ['propsPanel','propsPanelMobile'].forEach(pid => {
      const other = document.getElementById(pid).querySelector(`[data-prop="${el.dataset.prop}"]`);
      if (other && other !== el) other.value = el.value;
    });
  }
}

function applyPropDirect(id, prop, val) {
  selectedId = id;
  const b = blocks.find(x=>x.id===id);
  if (!b) return;
  b.data[prop] = val;
  renderAll();
}

// ============================================================
//  AUDIENCE
// ============================================================
function setAudience(btn) {
  document.querySelectorAll('.audience-pill').forEach(b=>b.classList.remove('pill-active'));
  btn.classList.add('pill-active');
  document.getElementById('audience_val').value = btn.dataset.val;
  document.getElementById('custom_box').classList.toggle('hidden', btn.dataset.val!=='custom');
}

// ============================================================
//  EXPORT HTML
// ============================================================
function buildEmailHtml() {
  let html = `<div style="max-width:600px;margin:0 auto;font-family:Inter,Arial,sans-serif;background:${CANVAS.style.backgroundColor||'#ffffff'};">`;
  blocks.forEach(b => { html += renderBlock(b); });
  html += `</div>`;
  return html;
}

function prepareAndConfirm(form) {
  if (!blocks.length) { alert('Please add at least one block to the email.'); return false; }
  const subj = document.getElementById('subject_input').value.trim();
  if (!subj)          { alert('Please enter a subject line.'); return false; }
  const aud = document.getElementById('audience_val').value;
  document.getElementById('body_html_hidden').value = buildEmailHtml();
  return confirm(`Send "${subj}" to ${aud} users?\n\nThis cannot be undone.`);
}

// ============================================================
//  PREVIEW MODAL
// ============================================================
function togglePreviewModal() {
  const modal = document.getElementById('previewModal');
  const showing = !modal.classList.contains('hidden');
  if (showing) {
    modal.classList.add('hidden'); modal.classList.remove('flex');
  } else {
    const html = buildEmailHtml();
    const iframe = document.getElementById('previewIframe');
    iframe.srcdoc = `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></head><body style="margin:0;padding:16px;background:#f1f5f9;">${html}</body></html>`;
    modal.classList.remove('hidden'); modal.classList.add('flex');
  }
}
function setPreviewMode(mode) {
  const frame = document.getElementById('previewFrame');
  const db = document.getElementById('previewDesktopBtn');
  const mb = document.getElementById('previewMobileBtn');
  if (mode==='mobile') {
    frame.style.width='390px'; frame.style.boxShadow='0 0 0 8px #1e293b,0 0 0 10px #334155';
    mb.className='text-xs px-3 py-1.5 rounded-lg bg-purple-500/20 text-purple-300 border border-purple-500/30 font-semibold';
    db.className='text-xs px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 border border-white/10';
  } else {
    frame.style.width='100%'; frame.style.boxShadow='none';
    db.className='text-xs px-3 py-1.5 rounded-lg bg-purple-500/20 text-purple-300 border border-purple-500/30 font-semibold';
    mb.className='text-xs px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 border border-white/10';
  }
}

// ============================================================
//  MOBILE PROPS DRAWER
// ============================================================
function openPropsDrawer() {
  const d = document.getElementById('propsDrawer');
  const p = document.getElementById('propsDrawerPanel');
  const bg= document.getElementById('propsDrawerBg');
  d.classList.remove('pointer-events-none');
  bg.style.opacity='1'; bg.classList.add('pointer-events-auto');
  p.style.transform='translateY(0)';
}
function closePropsDrawer() {
  const d = document.getElementById('propsDrawer');
  const p = document.getElementById('propsDrawerPanel');
  const bg= document.getElementById('propsDrawerBg');
  p.style.transform='translateY(100%)';
  bg.style.opacity='0';
  setTimeout(()=>{ d.classList.add('pointer-events-none'); bg.classList.remove('pointer-events-auto'); },300);
}

// ============================================================
//  INIT
// ============================================================
renderAll();
</script>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
