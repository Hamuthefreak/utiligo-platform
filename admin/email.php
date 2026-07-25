<?php
/**
 * admin/email.php — Email blast + drag-and-drop builder v3.
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
        $footer = '<p style="font-size:11px;color:#64748b;margin:24px 0 0;text-align:center;"><a href="https://utiligo.ca/unsubscribe" style="color:#94a3b8;">Unsubscribe</a> &middot; <a href="https://utiligo.ca" style="color:#94a3b8;">Utiligo.ca</a></p>';
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
/* ─── Layout ─── */
#eb-wrap { display:grid; grid-template-columns:210px 1fr 270px; gap:16px; align-items:start; }
#eb-canvas-col { min-width:0; }
#eb-props-col  { position:sticky; top:80px; max-height:calc(100vh - 100px); overflow-y:auto; }
@media(max-width:1279px){ #eb-wrap{ grid-template-columns:190px 1fr; } #eb-props-col{ display:none; } }
@media(max-width:767px)  { #eb-wrap{ grid-template-columns:1fr; } #eb-blocks-col{ order:2; } #eb-canvas-col{ order:1; } }

/* ─── Palette items ─── */
.pb-item {
  display:flex; align-items:center; gap:8px; padding:9px 12px;
  border-radius:10px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07);
  cursor:grab; font-size:.78rem; font-weight:500; color:#94a3b8;
  transition:all .15s; user-select:none; touch-action:none;
}
.pb-item:hover { background:rgba(255,255,255,.1); color:#fff; border-color:rgba(255,255,255,.2); }
.pb-item i { width:14px; text-align:center; font-size:.75rem; }

/* ─── Canvas wrapper — looks like an email client ─── */
#eb-client-shell {
  background:#e8edf2;
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 8px 48px rgba(0,0,0,.5);
}
/* top bar mimicking an email client chrome */
#eb-client-topbar {
  background:#d1d8e0;
  padding:10px 16px 0;
  display:flex;
  align-items:center;
  gap:7px;
}
#eb-client-topbar span { width:12px; height:12px; border-radius:50%; display:inline-block; }
#eb-client-topbar .dot-r { background:#ff5f57; }
#eb-client-topbar .dot-y { background:#febc2e; }
#eb-client-topbar .dot-g { background:#28c840; }
#eb-client-topbar-title {
  flex:1; text-align:center; font-size:.7rem; font-weight:600;
  color:#6b7280; padding-bottom:8px; letter-spacing:.02em;
}
/* email header meta (From / To / Subject) */
#eb-email-meta {
  background:#f3f4f6;
  border-bottom:1px solid #e5e7eb;
  padding:14px 20px;
  font-family:Arial,sans-serif;
  font-size:.8rem;
  color:#374151;
}
#eb-email-meta .meta-row {
  display:flex; align-items:baseline; gap:8px;
  padding:3px 0;
  border-bottom:1px solid #e9ecef;
}
#eb-email-meta .meta-row:last-child { border-bottom:none; }
#eb-email-meta .meta-label {
  font-weight:700; color:#9ca3af; min-width:52px; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em;
}
#eb-email-meta .meta-val {
  color:#111827; flex:1;
}
#eb-email-meta .meta-val.subject { font-weight:700; font-size:.9rem; }
/* scrollable canvas area */
#eb-canvas-scroll {
  background:#e8edf2;
  padding:24px 0 32px;
  min-height:500px;
  max-height:75vh;
  overflow-y:auto;
}
/* the actual email white card */
#eb-canvas-outer {
  margin:0 auto;
  background:#ffffff;
  max-width:600px;
  box-shadow:0 2px 20px rgba(0,0,0,.15);
  min-height:200px;
  transition:max-width .2s;
}
#eb-canvas-outer.dov { outline:3px dashed #6366f1; outline-offset:3px; }

/* ─── Empty canvas hint ─── */
#canvas-hint {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  padding:60px 20px; gap:10px; color:#9ca3af;
  border:2px dashed #d1d5db;
  margin:24px;
  border-radius:12px;
}

/* ─── Email blocks ─── */
.ebl {
  position:relative; cursor:pointer;
  outline:2px solid transparent; outline-offset:-2px;
  transition:outline-color .1s;
}
.ebl:hover  { outline-color:rgba(99,102,241,.5); }
.ebl.sel    { outline-color:#6366f1 !important; }

/* floating block toolbar */
.ebl-bar {
  display:none; position:absolute; top:0; right:0; z-index:20;
  background:rgba(15,23,42,.92); border-radius:0 0 0 10px;
  backdrop-filter:blur(8px);
  border-left:1px solid rgba(255,255,255,.1); border-bottom:1px solid rgba(255,255,255,.1);
}
.ebl:hover .ebl-bar, .ebl.sel .ebl-bar { display:flex; }
.ebl-bar button {
  border:none; background:transparent; color:#94a3b8;
  padding:6px 9px; cursor:pointer; font-size:.7rem; transition:color .1s,background .1s;
}
.ebl-bar button:hover { color:#fff; background:rgba(255,255,255,.1); }

/* drop indicator */
.ebl.drop-above::before {
  content:''; display:block; height:3px; background:#6366f1;
  position:absolute; top:-2px; left:0; right:0; z-index:30; border-radius:2px;
}
.ebl.drop-below::after {
  content:''; display:block; height:3px; background:#6366f1;
  position:absolute; bottom:-2px; left:0; right:0; z-index:30; border-radius:2px;
}

/* ─── Props panel ─── */
.pp-section { margin-bottom:1rem; }
.pp-label   { font-size:.67rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.3rem; display:block; }
.pp-input   {
  width:100%; padding:7px 10px; border-radius:9px;
  background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
  color:#e2e8f0; font-size:.8rem; outline:none; transition:border-color .15s;
  box-sizing:border-box;
}
.pp-input:focus { border-color:rgba(255,255,255,.4); }
.pp-color  { width:34px; height:30px; border-radius:8px; border:1px solid rgba(255,255,255,.12); cursor:pointer; padding:2px; background:transparent; flex-shrink:0; }
.pp-row    { display:flex; align-items:center; gap:6px; }
.pp-btn    {
  flex:1; padding:5px 4px; border-radius:7px; border:1px solid rgba(255,255,255,.1);
  background:rgba(255,255,255,.04); color:#94a3b8; cursor:pointer; font-size:.72rem;
  transition:all .12s; text-align:center;
}
.pp-btn:hover,.pp-btn.act { background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3); }

/* ─── Image library ─── */
#img-uploader {
  border:2px dashed rgba(255,255,255,.15); border-radius:14px;
  padding:20px; text-align:center; transition:all .2s; cursor:pointer;
}
#img-uploader:hover,#img-uploader.ov { border-color:rgba(255,255,255,.5); background:rgba(255,255,255,.03); }
.img-thumb-grid { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
.img-thumb {
  position:relative; width:80px; height:60px; border-radius:8px; overflow:hidden;
  border:1px solid rgba(255,255,255,.1); cursor:pointer; flex-shrink:0; transition:border-color .15s;
}
.img-thumb:hover { border-color:#6366f1; }
.img-thumb img   { width:100%; height:100%; object-fit:cover; }
.img-thumb .copy-tag {
  position:absolute; inset:0; background:rgba(0,0,0,.65); opacity:0;
  display:flex; align-items:center; justify-content:center;
  font-size:.62rem; font-weight:700; color:#fff; transition:opacity .15s; text-align:center; padding:4px;
}
.img-thumb:hover .copy-tag { opacity:1; }
.img-thumb .del-btn {
  position:absolute; top:2px; right:2px; background:rgba(220,38,38,.8);
  border:none; color:#fff; border-radius:4px; width:16px; height:16px;
  font-size:.55rem; cursor:pointer; display:none; align-items:center; justify-content:center;
}
.img-thumb:hover .del-btn { display:flex; }

/* ─── Mobile drawer ─── */
#mob-drawer { position:fixed; inset:0; z-index:60; pointer-events:none; }
#mob-drawer-bg    { position:absolute; inset:0; background:rgba(0,0,0,.65); opacity:0; transition:opacity .2s; }
#mob-drawer-panel {
  position:absolute; bottom:0; left:0; right:0;
  background:#0f172a; border-top:1px solid rgba(255,255,255,.1);
  border-radius:20px 20px 0 0; padding:20px 20px 32px;
  transform:translateY(100%); transition:transform .28s cubic-bezier(.4,0,.2,1);
  max-height:75vh; overflow-y:auto;
}
#mob-drawer.open { pointer-events:auto; }
#mob-drawer.open #mob-drawer-bg    { opacity:1; }
#mob-drawer.open #mob-drawer-panel { transform:translateY(0); }

/* ─── Audience pills ─── */
.aud-pill {
  padding:5px 14px; border-radius:999px; font-size:.75rem; font-weight:600;
  border:1px solid rgba(255,255,255,.1); cursor:pointer; transition:all .15s; color:#64748b; background:transparent;
}
.aud-pill:hover { color:#fff; border-color:rgba(255,255,255,.3); }
.aud-pill.on    { background:#fff; color:#000; border-color:#fff; }

/* ─── Preview modal ─── */
.pv-btn { padding:5px 12px; border-radius:9px; font-size:.75rem; font-weight:600; border:1px solid rgba(255,255,255,.1); cursor:pointer; color:#64748b; background:transparent; transition:all .15s; }
.pv-btn.on { background:#fff; color:#000; border-color:#fff; }
</style>

<!-- PAGE HEADER -->
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

<!-- SEND SETTINGS BAR -->
<div class="glass rounded-2xl p-5 mb-5">
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
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
    <div class="sm:col-span-2">
      <label class="pp-label" for="s-subject" style="margin-bottom:.4rem">Subject Line</label>
      <input id="s-subject" type="text" placeholder="e.g. Big news from Utiligo!"
             class="pp-input" style="padding:9px 14px;font-size:.875rem;" oninput="syncSubjectPreview()">
    </div>
    <div class="flex items-end">
      <button type="button" onclick="doSend()"
        class="w-full flex items-center justify-center gap-2 bg-white hover:bg-slate-200 text-black py-2.5 px-5 rounded-xl font-bold text-sm transition">
        <i class="fa-solid fa-paper-plane"></i> Send Blast
      </button>
    </div>
  </div>
  <div id="custom-box" class="hidden mt-4">
    <label class="pp-label">Custom addresses <span class="normal-case font-normal text-slate-500">(comma or newline separated)</span></label>
    <textarea id="custom-emails" rows="3" placeholder="alice@example.com, bob@example.com" class="pp-input" style="resize:vertical;"></textarea>
  </div>
</div>

<!-- hidden POST form -->
<form id="blast-form" method="POST" style="display:none">
  <input type="hidden" name="action"        value="send_blast">
  <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
  <input type="hidden" name="audience"      id="f-audience">
  <input type="hidden" name="subject"       id="f-subject">
  <input type="hidden" name="body_html"     id="f-body">
  <input type="hidden" name="custom_emails" id="f-custom">
</form>

<!-- BUILDER -->
<div id="eb-wrap">

  <!-- LEFT: palette -->
  <div id="eb-blocks-col">
    <div class="glass rounded-2xl p-4" style="position:sticky;top:80px;">
      <p class="pp-label mb-3">Blocks</p>
      <div class="space-y-1.5" id="palette">
        <div class="pb-item" data-t="logo"><i class="fa-solid fa-star"></i>Logo</div>
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
      <div class="mt-2 flex gap-1.5">
        <button onclick="undo()" type="button" title="Undo (Ctrl+Z)"
          class="flex-1 text-xs py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition">
          <i class="fa-solid fa-rotate-left"></i>
        </button>
        <button onclick="redo()" type="button" title="Redo (Ctrl+Y)"
          class="flex-1 text-xs py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition">
          <i class="fa-solid fa-rotate-right"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- CENTER: canvas -->
  <div id="eb-canvas-col">
    <!-- toolbar above canvas -->
    <div class="glass rounded-2xl px-4 py-3 mb-3 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3 flex-wrap">
        <span class="text-xs text-slate-500">Email BG</span>
        <input type="color" id="canvasBgPicker" value="#ffffff" title="Email background color"
               class="pp-color" oninput="setBg(this.value)">
        <input type="text" id="canvasBgHex" value="#ffffff" placeholder="#ffffff"
               class="pp-input" style="width:80px;padding:5px 8px;"
               oninput="syncBgFromHex(this.value)">
        <span class="text-xs text-slate-500">Width</span>
        <select id="canvasWidthSel" class="pp-input" style="width:auto;padding:5px 8px;" onchange="setWidth(this.value)">
          <option value="600">600px</option>
          <option value="500">500px</option>
          <option value="700">700px</option>
          <option value="100%">Full</option>
        </select>
      </div>
      <button type="button" onclick="openDrawer()" id="edit-props-btn"
        class="xl:hidden text-xs px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white font-semibold border border-white/15 hidden">
        <i class="fa-solid fa-sliders mr-1"></i> Edit Block
      </button>
    </div>

    <!-- email client shell -->
    <div id="eb-client-shell">
      <!-- traffic-light bar -->
      <div id="eb-client-topbar">
        <span class="dot-r"></span><span class="dot-y"></span><span class="dot-g"></span>
        <div id="eb-client-topbar-title">New Message — Utiligo Mail</div>
      </div>
      <!-- email meta header -->
      <div id="eb-email-meta">
        <div class="meta-row">
          <span class="meta-label">From</span>
          <span class="meta-val">Utiligo &lt;noreply@utiligo.ca&gt;</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">To</span>
          <span class="meta-val" id="pv-to-label">All verified users</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Subject</span>
          <span class="meta-val subject" id="pv-subject-label">— no subject —</span>
        </div>
      </div>
      <!-- scrollable canvas area -->
      <div id="eb-canvas-scroll">
        <div id="eb-canvas-outer">
          <div id="eb-canvas" style="background:#ffffff;">
            <div id="canvas-hint">
              <i class="fa-solid fa-envelope-open-text" style="font-size:2rem;color:#d1d5db;"></i>
              <span style="font-size:.875rem;">Click a block in the palette to add it, or drag it here</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: props -->
  <div id="eb-props-col">
    <div class="glass rounded-2xl p-4">
      <p class="pp-label mb-3">Properties</p>
      <div id="pp-content">
        <p class="text-xs text-slate-600 text-center py-10">Click a block<br>to edit it</p>
      </div>
    </div>
  </div>

</div><!-- /eb-wrap -->

<!-- IMAGE LIBRARY -->
<div class="glass rounded-2xl p-5 mt-6">
  <div class="flex items-center gap-2 mb-4">
    <i class="fa-solid fa-images text-slate-400"></i>
    <h3 class="font-semibold text-sm">Image Library</h3>
    <span class="text-xs text-slate-500 ml-1">— upload images and paste their URL into any Image or Logo block</span>
  </div>
  <div id="img-uploader"
       onclick="document.getElementById('img-file-inp').click()"
       ondragover="event.preventDefault();this.classList.add('ov')"
       ondragleave="this.classList.remove('ov')"
       ondrop="handleImgDrop(event)">
    <i class="fa-solid fa-cloud-arrow-up text-slate-500 text-2xl mb-2"></i>
    <p class="text-sm text-slate-400">Drop images here or <span class="text-white underline cursor-pointer">click to upload</span></p>
    <p class="text-xs text-slate-600 mt-1">PNG, JPG, GIF, WebP — stored locally, copy URL and paste into any block</p>
  </div>
  <input type="file" id="img-file-inp" accept="image/*" multiple class="hidden" onchange="handleImgFiles(this.files)">
  <div id="img-grid"></div>
</div>

<!-- MOBILE DRAWER -->
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

<!-- PREVIEW MODAL -->
<div id="prev-modal" class="fixed inset-0 z-50 bg-black/80 hidden items-center justify-center p-3">
  <div class="bg-slate-900 border border-white/10 rounded-2xl w-full max-w-3xl max-h-[94vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-white/10 gap-3">
      <span class="font-semibold text-sm">Preview</span>
      <div class="flex gap-2">
        <button onclick="setPrevMode('desktop')" id="pv-d" class="pv-btn on"><i class="fa-solid fa-desktop"></i> Desktop</button>
        <button onclick="setPrevMode('mobile')"  id="pv-m" class="pv-btn"><i class="fa-solid fa-mobile-screen"></i> Mobile</button>
      </div>
      <button onclick="closePrevModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="flex-1 overflow-auto p-4 flex justify-center">
      <div id="pv-frame" class="transition-all duration-300 bg-white rounded-xl overflow-hidden" style="width:100%;max-width:700px;">
        <iframe id="pv-iframe" class="w-full border-0" style="height:580px;display:block;"></iframe>
      </div>
    </div>
  </div>
</div>

<script>
'use strict';

// ═══════════════════════
//  STATE
// ═══════════════════════
let blocks     = [];
let selId      = null;
let hist       = [];
let fut        = [];
let canvasBg   = '#ffffff';
let dragPal    = null;
let dragId     = null;
let dropTarget = null;

const CANVAS = document.getElementById('eb-canvas');
const HINT   = document.getElementById('canvas-hint');

// ═══════════════════════
//  DEFAULT BLOCK DATA
// ═══════════════════════
const DEF = {
  logo    : { src:'', alt:'Utiligo', align:'center', width:'160', radius:'0' },
  heading : { text:'Your Heading', level:'h2', color:'#111827', align:'left', size:'26', weight:'700', blockBg:'' },
  text    : { text:'Your paragraph text goes here. Click to edit inline.', color:'#374151', align:'left', size:'15', lh:'1.7', blockBg:'' },
  image   : { src:'', alt:'', align:'center', width:'100', radius:'0', blockBg:'' },
  button  : { text:'Click Here', href:'https://utiligo.ca', bg:'#111827', fg:'#ffffff', align:'center', size:'15', radius:'8', bold:'1', blockBg:'' },
  divider : { color:'#e5e7eb', thick:'1', my:'20', blockBg:'' },
  spacer  : { h:'32', blockBg:'' },
  columns : {
    l:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;margin:0;">Left column content.</p>',
    r:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;margin:0;">Right column content.</p>',
    gap:'20', blockBg:''
  },
  html : { code:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;margin:0;">Custom HTML here</p>', blockBg:'' },
};

// ═══════════════════════
//  UTILS
// ═══════════════════════
const uid   = () => Math.random().toString(36).slice(2,9);
const clone = v  => JSON.parse(JSON.stringify(v));

function snap() { hist.push(clone(blocks)); if (hist.length > 60) hist.shift(); fut = []; }
function undo() { if (!hist.length) return; fut.push(clone(blocks)); blocks = hist.pop(); selId = null; render(); }
function redo() { if (!fut.length)  return; hist.push(clone(blocks)); blocks = fut.pop();  selId = null; render(); }

document.addEventListener('keydown', e => {
  const tag = document.activeElement.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || document.activeElement.isContentEditable) return;
  if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); undo(); }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'z'))) { e.preventDefault(); redo(); }
  if (e.key === 'Escape') { selId = null; render(); closeDrawer(); }
  if ((e.key === 'Delete' || e.key === 'Backspace') && selId) { snap(); del(selId); }
});

function escAttr(s) {
  return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════════════
//  BLOCK HTML RENDERERS
//  (all inline CSS — email-safe)
// ═══════════════════════
function blockHtml(b) {
  const d   = b.data;
  const pad = 'padding:16px 28px;';
  const bg  = d.blockBg ? 'background:' + d.blockBg + ';' : '';
  let inner = '';

  if (b.type === 'logo') {
    const src = d.src || 'https://placehold.co/160x50/e2e8f0/94a3b8?text=Logo';
    inner = '<div style="text-align:' + d.align + ';">' +
            '<img src="' + escAttr(src) + '" alt="' + escAttr(d.alt) + '" ' +
            'style="max-width:100%;width:' + d.width + 'px;border-radius:' + d.radius + 'px;display:inline-block;"></div>';

  } else if (b.type === 'heading') {
    inner = '<' + d.level + ' style="margin:0;font-family:Arial,sans-serif;font-size:' + d.size + 'px;font-weight:' + d.weight + ';color:' + d.color + ';text-align:' + d.align + ';line-height:1.25;">' + d.text + '</' + d.level + '>';

  } else if (b.type === 'text') {
    inner = '<p style="margin:0;font-family:Arial,sans-serif;font-size:' + d.size + 'px;color:' + d.color + ';text-align:' + d.align + ';line-height:' + d.lh + ';">' + d.text + '</p>';

  } else if (b.type === 'image') {
    const src = d.src || 'https://placehold.co/600x220/e2e8f0/94a3b8?text=Add+Image+URL';
    inner = '<div style="text-align:' + d.align + ';">' +
            '<img src="' + escAttr(src) + '" alt="' + escAttr(d.alt) + '" ' +
            'style="max-width:100%;width:' + d.width + '%;border-radius:' + d.radius + 'px;display:inline-block;"></div>';

  } else if (b.type === 'button') {
    const fw = d.bold === '1' ? '700' : '400';
    inner = '<div style="text-align:' + d.align + ';">' +
            '<a href="' + escAttr(d.href) + '" style="display:inline-block;padding:12px 28px;background:' + d.bg + ';color:' + d.fg + ';font-family:Arial,sans-serif;font-size:' + d.size + 'px;font-weight:' + fw + ';text-decoration:none;border-radius:' + d.radius + 'px;">' + d.text + '</a></div>';

  } else if (b.type === 'divider') {
    inner = '<div style="padding:' + d.my + 'px 0;"><hr style="border:none;border-top:' + d.thick + 'px solid ' + d.color + ';margin:0;"></div>';

  } else if (b.type === 'spacer') {
    inner = '<div style="height:' + d.h + 'px;line-height:' + d.h + 'px;font-size:1px;">&nbsp;</div>';

  } else if (b.type === 'columns') {
    const half = Math.round(d.gap / 2);
    inner = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' +
            '<tr><td style="width:50%;padding-right:' + half + 'px;vertical-align:top;">' + d.l + '</td>' +
            '<td style="width:50%;padding-left:' + half + 'px;vertical-align:top;">' + d.r + '</td></tr></table>';

  } else if (b.type === 'html') {
    inner = d.code;
  }

  return '<div style="' + pad + bg + '">' + inner + '</div>';
}

// full wrapped email HTML for sending
function buildEmail() {
  const mw  = document.getElementById('canvasWidthSel').value;
  const w   = mw === '100%' ? '100%' : mw + 'px';
  let out   = '<div style="max-width:' + w + ';margin:0 auto;background:' + canvasBg + ';">';
  blocks.forEach(b => { out += blockHtml(b); });
  out += '</div>';
  return out;
}

// ═══════════════════════
//  RENDER
// ═══════════════════════
function render() {
  HINT.style.display = blocks.length ? 'none' : 'flex';
  Array.from(CANVAS.querySelectorAll('.ebl')).forEach(e => e.remove());

  blocks.forEach(b => {
    const wrap = document.createElement('div');
    wrap.className  = 'ebl' + (b.id === selId ? ' sel' : '');
    wrap.dataset.id = b.id;
    wrap.draggable  = true;

    // toolbar
    const bar = document.createElement('div');
    bar.className = 'ebl-bar';
    bar.innerHTML =
      '<button title="Move Up"    onclick="mv(\'' + b.id + '\',-1)"><i class="fa-solid fa-chevron-up"></i></button>' +
      '<button title="Move Down"  onclick="mv(\'' + b.id + '\',1)"><i class="fa-solid fa-chevron-down"></i></button>' +
      '<button title="Duplicate"  onclick="dupe(\'' + b.id + '\')" ><i class="fa-solid fa-copy"></i></button>' +
      '<button title="Delete"     onclick="del(\'' + b.id + '\')" style="color:#f87171;"><i class="fa-solid fa-trash"></i></button>';

    // content div
    const content = document.createElement('div');
    content.className = 'ebl-content';
    content.innerHTML = blockHtml(b);

    // inline editing
    if (b.type === 'heading' || b.type === 'text') {
      const el = content.querySelector('h1,h2,h3,h4,p');
      if (el) {
        el.contentEditable = 'true';
        el.style.outline   = 'none';
        el.style.cursor    = 'text';
        el.addEventListener('focus', () => { selId = b.id; selectBlock(b.id, false); });
        el.addEventListener('blur',  () => { b.data.text = el.innerHTML; syncPP(); });
        el.addEventListener('keydown', ev => { if (ev.key === 'Enter' && b.type === 'heading') ev.preventDefault(); });
      }
    }
    if (b.type === 'columns') {
      content.querySelectorAll('td').forEach((td, i) => {
        td.contentEditable = 'true';
        td.style.outline   = 'none';
        td.style.cursor    = 'text';
        td.addEventListener('blur', () => { if (i === 0) b.data.l = td.innerHTML; else b.data.r = td.innerHTML; });
      });
    }

    wrap.appendChild(bar);
    wrap.appendChild(content);

    wrap.addEventListener('click', e => {
      if (e.target.closest('.ebl-bar')) return;
      if (e.target.isContentEditable || e.target.closest('[contenteditable]')) return;
      selectBlock(b.id, true);
    });

    // drag reorder
    wrap.addEventListener('dragstart', e => { dragId = b.id; dragPal = null; e.dataTransfer.effectAllowed = 'move'; e.stopPropagation(); });
    wrap.addEventListener('dragend',   () => { dragId = null; clearDI(); });
    wrap.addEventListener('dragover',  e => { e.preventDefault(); e.stopPropagation(); showDI(b.id, e); });
    wrap.addEventListener('dragleave', e => { if (!wrap.contains(e.relatedTarget)) clearDI(); });
    wrap.addEventListener('drop',      e => { e.preventDefault(); e.stopPropagation(); commitDrop(b.id); });

    CANVAS.appendChild(wrap);
  });

  syncPP();
}

function selectBlock(id, doScrollIntoView) {
  selId = id;
  CANVAS.querySelectorAll('.ebl').forEach(e => e.classList.toggle('sel', e.dataset.id === id));
  syncPP();
  const btn = document.getElementById('edit-props-btn');
  if (btn) btn.classList.remove('hidden');
  if (doScrollIntoView) {
    const el = CANVAS.querySelector('[data-id="' + id + '"]');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

// ═══════════════════════
//  DROP INDICATORS
// ═══════════════════════
function showDI(id, e) {
  const wrap = CANVAS.querySelector('[data-id="' + id + '"]');
  if (!wrap) return;
  const r   = wrap.getBoundingClientRect();
  const pos = e.clientY < r.top + r.height / 2 ? 'above' : 'below';
  if (dropTarget && dropTarget.id === id && dropTarget.pos === pos) return;
  clearDI();
  dropTarget = { id, pos };
  wrap.classList.add('drop-' + pos);
}
function clearDI() {
  CANVAS.querySelectorAll('.drop-above,.drop-below').forEach(e => {
    e.classList.remove('drop-above', 'drop-below');
  });
  dropTarget = null;
}

// ═══════════════════════
//  DRAG/DROP FROM PALETTE
// ═══════════════════════
document.querySelectorAll('.pb-item').forEach(el => {
  el.addEventListener('dragstart', e => { dragPal = el.dataset.t; dragId = null; e.dataTransfer.effectAllowed = 'copy'; });
  el.addEventListener('dragend',   () => { dragPal = null; });
  el.addEventListener('click',     () => { snap(); addBlock(el.dataset.t); });
});

CANVAS.addEventListener('dragover',  e => { e.preventDefault(); document.getElementById('eb-canvas-outer').classList.add('dov'); });
CANVAS.addEventListener('dragleave', e => { if (!CANVAS.contains(e.relatedTarget)) document.getElementById('eb-canvas-outer').classList.remove('dov'); });
CANVAS.addEventListener('drop', e => {
  e.preventDefault();
  document.getElementById('eb-canvas-outer').classList.remove('dov');
  if (!dragPal) return;
  snap();
  const b = { id: uid(), type: dragPal, data: clone(DEF[dragPal]) };
  if (dropTarget) {
    const idx = blocks.findIndex(x => x.id === dropTarget.id);
    blocks.splice(dropTarget.pos === 'above' ? idx : idx + 1, 0, b);
  } else {
    blocks.push(b);
  }
  selId   = b.id;
  dragPal = null;
  clearDI();
  render();
});

function commitDrop(targetId) {
  document.getElementById('eb-canvas-outer').classList.remove('dov');
  if (!dragId || dragId === targetId || !dropTarget) { clearDI(); return; }
  snap();
  const fi = blocks.findIndex(x => x.id === dragId);
  const [moved] = blocks.splice(fi, 1);
  const ti = blocks.findIndex(x => x.id === dropTarget.id);
  blocks.splice(dropTarget.pos === 'above' ? ti : ti + 1, 0, moved);
  dragId = null;
  clearDI();
  render();
}

// ═══════════════════════
//  BLOCK OPERATIONS
// ═══════════════════════
function addBlock(type) {
  const b = { id: uid(), type, data: clone(DEF[type]) };
  if (selId) {
    const i = blocks.findIndex(x => x.id === selId);
    blocks.splice(i + 1, 0, b);
  } else {
    blocks.push(b);
  }
  selId = b.id;
  render();
  setTimeout(() => {
    const el = CANVAS.querySelector('[data-id="' + b.id + '"]');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, 60);
}

function del(id) {
  snap();
  blocks = blocks.filter(x => x.id !== id);
  if (selId === id) selId = null;
  render();
}

function mv(id, dir) {
  snap();
  const i = blocks.findIndex(x => x.id === id);
  const j = i + dir;
  if (j < 0 || j >= blocks.length) return;
  [blocks[i], blocks[j]] = [blocks[j], blocks[i]];
  render();
}

function dupe(id) {
  snap();
  const src = blocks.find(x => x.id === id);
  if (!src) return;
  const cp = { id: uid(), type: src.type, data: clone(src.data) };
  blocks.splice(blocks.findIndex(x => x.id === id) + 1, 0, cp);
  selId = cp.id;
  render();
}

function clearAll() {
  if (!blocks.length) return;
  if (!confirm('Clear the entire canvas? This cannot be undone.')) return;
  snap(); blocks = []; selId = null; render();
}

function setBg(v) {
  canvasBg = v;
  CANVAS.style.background = v;
  document.getElementById('eb-canvas-outer').style.background = v;
  const hex = document.getElementById('canvasBgHex');
  if (hex && hex.value !== v) hex.value = v;
}

function syncBgFromHex(v) {
  if (/^#[0-9a-fA-F]{6}$/.test(v)) {
    canvasBg = v;
    CANVAS.style.background = v;
    document.getElementById('eb-canvas-outer').style.background = v;
    const picker = document.getElementById('canvasBgPicker');
    if (picker) picker.value = v;
  }
}

function setWidth(v) {
  document.getElementById('eb-canvas-outer').style.maxWidth = v === '100%' ? '100%' : v + 'px';
}

// ═══════════════════════
//  PROPERTIES PANEL
// ═══════════════════════
function syncPP() {
  const html = selId ? buildPpHtml(blocks.find(x => x.id === selId))
                     : '<p class="text-xs text-slate-600 text-center py-10">Click a block<br>to edit it</p>';
  document.getElementById('pp-content').innerHTML     = html;
  document.getElementById('mob-pp-content').innerHTML = html;
  wirePP('pp-content');
  wirePP('mob-pp-content');
}

function wirePP(cid) {
  const con = document.getElementById(cid);
  con.querySelectorAll('[data-p]').forEach(el => {
    // color picker syncs its paired text input
    if (el.type === 'color') {
      el.addEventListener('input', () => {
        applyProp(el.dataset.p, el.value);
        // find sibling text input with same data-p
        const row = el.closest('.pp-row');
        if (row) {
          const txt = row.querySelector('input[type="text"][data-p="' + el.dataset.p + '"]');
          if (txt && txt.value !== el.value) txt.value = el.value;
        }
      });
    } else {
      el.addEventListener('input',  () => applyProp(el.dataset.p, el.value));
      el.addEventListener('change', () => applyProp(el.dataset.p, el.value));
      // hex text → sync color swatch
      if (el.type === 'text' && el.dataset.p) {
        el.addEventListener('input', () => {
          if (/^#[0-9a-fA-F]{6}$/.test(el.value)) {
            const row = el.closest('.pp-row');
            if (row) {
              const swatch = row.querySelector('input[type="color"][data-p="' + el.dataset.p + '"]');
              if (swatch && swatch.value !== el.value) swatch.value = el.value;
            }
          }
        });
      }
    }
  });
}

function applyProp(key, val) {
  if (!selId) return;
  const b = blocks.find(x => x.id === selId);
  if (!b) return;
  b.data[key] = val;
  // update only this block's content child (never re-renders the whole canvas)
  const wrap    = CANVAS.querySelector('[data-id="' + selId + '"]');
  if (!wrap) return;
  const content = wrap.querySelector('.ebl-content');
  if (!content) return;
  content.innerHTML = blockHtml(b);
  // re-attach inline editing listeners
  if (b.type === 'heading' || b.type === 'text') {
    const el = content.querySelector('h1,h2,h3,h4,p');
    if (el) {
      el.contentEditable = 'true';
      el.style.outline   = 'none';
      el.style.cursor    = 'text';
      el.addEventListener('blur', () => { b.data.text = el.innerHTML; syncPP(); });
      el.addEventListener('keydown', ev => { if (ev.key === 'Enter' && b.type === 'heading') ev.preventDefault(); });
    }
  }
  if (b.type === 'columns') {
    content.querySelectorAll('td').forEach((td, i) => {
      td.contentEditable = 'true'; td.style.outline = 'none';
      td.addEventListener('blur', () => { if (i === 0) b.data.l = td.innerHTML; else b.data.r = td.innerHTML; });
    });
  }
}

// align buttons helper
function ppAlignBtns(key, cur) {
  return ['left','center','right'].map(a =>
    '<button type="button" class="pp-btn ' + (cur === a ? 'act' : '') + '" ' +
    'onclick="applyProp(\'' + key + '\',\'' + a + '\');syncPP()">' +
    '<i class="fa-solid fa-align-' + a + '"></i></button>'
  ).join('');
}

function buildPpHtml(b) {
  if (!b) return '';
  const d = b.data;

  const row   = (lbl, inp) => '<div class="pp-section"><label class="pp-label">' + lbl + '</label>' + inp + '</div>';
  const txt   = (key, val, ph) => '<input type="text" class="pp-input" data-p="' + key + '" value="' + escHtml(val || '') + '" placeholder="' + (ph||'') + '">';
  const num   = (key, val, mn, mx, step) => '<input type="number" class="pp-input" data-p="' + key + '" value="' + val + '" min="' + mn + '" max="' + mx + '"' + (step?' step="'+step+'"':'') + '>';
  const clrRow= (key, val) => '<div class="pp-row"><input type="color" class="pp-color" data-p="' + key + '" value="' + (val||'#000000') + '"><input type="text" class="pp-input" data-p="' + key + '" value="' + escHtml(val||'') + '" style="flex:1;" placeholder="#rrggbb"></div>';
  const sel   = (key, val, opts) => '<select class="pp-input" data-p="' + key + '">' + opts.map(([v,l]) => '<option value="' + v + '"' + (v === val ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>';
  const aligns= (key, cur) => '<div class="pp-row">' + ppAlignBtns(key, cur) + '</div>';

  let h = '';

  if (b.type === 'logo') {
    h += row('Logo URL', txt('src', d.src, 'https://... paste from library below'));
    h += row('Alt Text', txt('alt', d.alt, 'Company name'));
    h += row('Width px', num('width', d.width, 40, 400));
    h += row('Corner Radius', num('radius', d.radius, 0, 50));
    h += row('Align', aligns('align', d.align));

  } else if (b.type === 'heading') {
    h += row('Text', txt('text', d.text));
    h += row('Level', sel('level', d.level, [['h1','H1 — Largest'],['h2','H2'],['h3','H3'],['h4','H4 — Smallest']]));
    h += row('Font Size', num('size', d.size, 10, 80));
    h += row('Weight', sel('weight', d.weight, [['400','Normal'],['600','Semi-bold'],['700','Bold'],['800','Extra-bold']]));
    h += row('Color', clrRow('color', d.color));
    h += row('Align', aligns('align', d.align));

  } else if (b.type === 'text') {
    h += row('Font Size', num('size', d.size, 10, 40));
    h += row('Line Height', num('lh', d.lh, 1, 3, 0.1));
    h += row('Color', clrRow('color', d.color));
    h += row('Align', aligns('align', d.align));

  } else if (b.type === 'image') {
    h += row('Image URL', txt('src', d.src, 'https://... or paste from library below'));
    h += row('Alt Text', txt('alt', d.alt, 'Describe the image'));
    h += row('Width %', num('width', d.width, 10, 100));
    h += row('Corner Radius', num('radius', d.radius, 0, 50));
    h += row('Align', aligns('align', d.align));

  } else if (b.type === 'button') {
    h += row('Button Text', txt('text', d.text));
    h += row('Link URL', txt('href', d.href, 'https://'));
    h += row('Background', clrRow('bg', d.bg));
    h += row('Text Color', clrRow('fg', d.fg));
    h += row('Font Size', num('size', d.size, 10, 28));
    h += row('Bold', sel('bold', d.bold, [['1','Yes'],['0','No']]));
    h += row('Border Radius', num('radius', d.radius, 0, 50));
    h += row('Align', aligns('align', d.align));

  } else if (b.type === 'divider') {
    h += row('Color', clrRow('color', d.color));
    h += row('Thickness px', num('thick', d.thick, 1, 12));
    h += row('Spacing px', num('my', d.my, 0, 80));

  } else if (b.type === 'spacer') {
    h += row('Height px', num('h', d.h, 4, 200));

  } else if (b.type === 'columns') {
    h += row('Column Gap px', num('gap', d.gap, 0, 60));
    h += '<p class="text-xs text-slate-500 mt-1">Click either column on the canvas to edit its text inline.</p>';

  } else if (b.type === 'html') {
    h += row('HTML Code', '<textarea class="pp-input" data-p="code" rows="7" style="font-family:monospace;font-size:.72rem;resize:vertical;">' + escHtml(d.code) + '</textarea>');
  }

  // block background — always shown, transparent by default
  h += '<div class="pp-section" style="border-top:1px solid rgba(255,255,255,.06);padding-top:.8rem;margin-top:.4rem;">';
  h += '<label class="pp-label">Block Background</label>';
  h += '<div class="pp-row">';
  h += '<input type="color" class="pp-color" data-p="blockBg" value="' + (d.blockBg || '#ffffff') + '">';
  h += '<input type="text" class="pp-input" data-p="blockBg" value="' + escHtml(d.blockBg || '') + '" style="flex:1;" placeholder="transparent">';
  h += '<button type="button" class="pp-btn" style="flex:0 0 auto;padding:5px 8px;" onclick="applyProp(\'blockBg\',\'\');syncPP()" title="Remove block background"><i class="fa-solid fa-xmark"></i></button>';
  h += '</div></div>';

  return h;
}

// ═══════════════════════
//  AUDIENCE
// ═══════════════════════
document.getElementById('aud-btns').addEventListener('click', e => {
  const btn = e.target.closest('.aud-pill');
  if (!btn) return;
  document.querySelectorAll('.aud-pill').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById('custom-box').classList.toggle('hidden', btn.dataset.v !== 'custom');
  // update preview "To" label
  const labels = { all:'All verified users', pro:'Pro plan users', entrepreneur:'Entrepreneur plan users', free:'Free plan users', custom:'Custom list' };
  document.getElementById('pv-to-label').textContent = labels[btn.dataset.v] || 'All verified users';
});

// ═══════════════════════
//  SUBJECT SYNC
// ═══════════════════════
function syncSubjectPreview() {
  const v = document.getElementById('s-subject').value.trim();
  document.getElementById('pv-subject-label').textContent = v || '— no subject —';
}

// ═══════════════════════
//  SEND
// ═══════════════════════
function doSend() {
  if (!blocks.length) { alert('Please add at least one block to your email.'); return; }
  const subj = document.getElementById('s-subject').value.trim();
  if (!subj) { alert('Please enter a subject line.'); return; }
  const aud  = document.querySelector('.aud-pill.on')?.dataset.v || 'all';
  const body = buildEmail();
  if (!confirm('Send "' + subj + '" to ' + aud + ' users?\n\nThis cannot be undone.')) return;
  document.getElementById('f-audience').value = aud;
  document.getElementById('f-subject').value  = subj;
  document.getElementById('f-body').value     = body;
  document.getElementById('f-custom').value   = document.getElementById('custom-emails').value;
  document.getElementById('blast-form').submit();
}

// ═══════════════════════
//  PREVIEW MODAL
// ═══════════════════════
function prevModal() {
  const m = document.getElementById('prev-modal');
  if (m.classList.contains('flex')) { closePrevModal(); return; }
  const subj = document.getElementById('s-subject').value.trim() || '(no subject)';
  const body = buildEmail();
  document.getElementById('pv-iframe').srcdoc =
    '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<style>*{box-sizing:border-box;}body{margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;}</style>' +
    '</head><body>' +
    '<div style="background:#ffffff;border-bottom:1px solid #e5e7eb;padding:12px 20px;font-size:13px;color:#6b7280;">' +
    '<div><strong style="color:#111;">Subject:</strong> ' + escAttr(subj) + '</div>' +
    '<div><strong style="color:#111;">From:</strong> Utiligo &lt;noreply@utiligo.ca&gt;</div>' +
    '</div>' +
    '<div style="padding:24px 0;">' + body + '</div>' +
    '</body></html>';
  m.classList.replace('hidden', 'flex');
}
function closePrevModal() {
  document.getElementById('prev-modal').classList.replace('flex', 'hidden');
}
function setPrevMode(mode) {
  const frame = document.getElementById('pv-frame');
  const d = document.getElementById('pv-d');
  const mb = document.getElementById('pv-m');
  if (mode === 'mobile') {
    frame.style.maxWidth   = '390px';
    frame.style.boxShadow  = '0 0 0 10px #1e293b, 0 0 0 12px #475569';
    frame.style.borderRadius = '20px';
    d.classList.remove('on'); mb.classList.add('on');
  } else {
    frame.style.maxWidth   = '700px';
    frame.style.boxShadow  = 'none';
    frame.style.borderRadius = '12px';
    mb.classList.remove('on'); d.classList.add('on');
  }
}
document.getElementById('prev-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closePrevModal(); });

// ═══════════════════════
//  MOBILE DRAWER
// ═══════════════════════
function openDrawer()  { document.getElementById('mob-drawer').classList.add('open'); }
function closeDrawer() { document.getElementById('mob-drawer').classList.remove('open'); }

// ═══════════════════════
//  IMAGE LIBRARY
// ═══════════════════════
let imgLib = [];
try { const s = localStorage.getItem('eb_img_lib_v2'); if (s) imgLib = JSON.parse(s); } catch(e) {}

function handleImgFiles(files) {
  Array.from(files).forEach(f => {
    if (!f.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = ev => addToLib(ev.target.result, f.name);
    reader.readAsDataURL(f);
  });
}
function handleImgDrop(e) {
  e.preventDefault();
  document.getElementById('img-uploader').classList.remove('ov');
  handleImgFiles(e.dataTransfer.files);
}
function addToLib(dataUrl, name) {
  imgLib.unshift({ url: dataUrl, name });
  if (imgLib.length > 30) imgLib.pop();
  try { localStorage.setItem('eb_img_lib_v2', JSON.stringify(imgLib)); } catch(e) {}
  renderLib();
}
function removeFromLib(i) {
  imgLib.splice(i, 1);
  try { localStorage.setItem('eb_img_lib_v2', JSON.stringify(imgLib)); } catch(e) {}
  renderLib();
}
function renderLib() {
  const grid = document.getElementById('img-grid');
  if (!imgLib.length) { grid.innerHTML = ''; return; }
  let html = '<div class="img-thumb-grid">';
  imgLib.forEach((img, i) => {
    html += '<div class="img-thumb" onclick="copyImgUrl(' + i + ')" title="' + escAttr(img.name) + '">' +
            '<img src="' + img.url + '" alt="">' +
            '<div class="copy-tag"><i class="fa-solid fa-copy"></i><br>Copy URL</div>' +
            '<button class="del-btn" onclick="event.stopPropagation();removeFromLib(' + i + ')" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
            '</div>';
  });
  html += '</div><p class="text-xs text-slate-600 mt-3">Click any image to copy its URL &mdash; then paste into an Image or Logo block\'s URL field.</p>';
  grid.innerHTML = html;
}
function copyImgUrl(i) {
  const url = imgLib[i].url;
  const fallback = () => {
    const ta = document.createElement('textarea');
    ta.value = url; document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); document.body.removeChild(ta);
    showToast('URL copied!');
  };
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => showToast('URL copied to clipboard!')).catch(fallback);
  } else { fallback(); }
}
function showToast(msg) {
  let t = document.getElementById('eb-toast');
  if (!t) {
    t = document.createElement('div'); t.id = 'eb-toast';
    t.style.cssText = 'position:fixed;bottom:28px;left:50%;transform:translateX(-50%);background:#4f46e5;color:#fff;padding:10px 22px;border-radius:14px;font-size:.82rem;font-weight:600;z-index:9999;opacity:0;transition:opacity .2s;pointer-events:none;box-shadow:0 4px 20px rgba(79,70,229,.4);';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.opacity = '0'; }, 2200);
}

// ═══════════════════════
//  INIT — starter template
// ═══════════════════════
(function loadStarter() {
  blocks = [
    { id: uid(), type: 'logo',    data: { ...clone(DEF.logo),    blockBg:'#111827', align:'center' } },
    { id: uid(), type: 'heading', data: { ...clone(DEF.heading), text:'Welcome to Utiligo 👋', color:'#111827', align:'center', blockBg:'' } },
    { id: uid(), type: 'text',    data: { ...clone(DEF.text),    text:'Thanks for being part of our community. Here\'s what\'s new this week.', align:'center', blockBg:'' } },
    { id: uid(), type: 'button',  data: { ...clone(DEF.button),  text:'Explore Now →', blockBg:'', align:'center' } },
    { id: uid(), type: 'divider', data: clone(DEF.divider) },
    { id: uid(), type: 'spacer',  data: clone(DEF.spacer) },
  ];
  render();
})();

renderLib();
</script>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
