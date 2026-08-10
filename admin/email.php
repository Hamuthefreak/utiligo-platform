<?php
/**
 * admin/email.php — Email blast + drag-and-drop builder v4.
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

    // Whitelist audience to valid segments (plan is enum('free','pro','entrepreneur'))
    $validAudiences = ['all', 'paid', 'pro', 'free', 'entrepreneur', 'custom'];
    if (!in_array($audience, $validAudiences)) $audience = 'all';

    if (!$subject || !$bodyHtml) {
        $errors[] = 'Subject and body are required.';
    } else {
        $udb = get_user_db();
        $recipients = [];
        if ($audience === 'all') {
            $recipients = $udb->query('SELECT email,full_name FROM utiligo_users WHERE email_verified=1')->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'paid') {
            $recipients = $udb->query("SELECT email,full_name FROM utiligo_users WHERE plan IN ('pro','entrepreneur') AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'pro') {
            $recipients = $udb->query("SELECT email,full_name FROM utiligo_users WHERE plan='pro' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'entrepreneur') {
            $recipients = $udb->query("SELECT email,full_name FROM utiligo_users WHERE plan='entrepreneur' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'free') {
            $recipients = $udb->query("SELECT email,full_name FROM utiligo_users WHERE plan='free' AND email_verified=1")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // custom list
            foreach (preg_split('/[\r\n,;]+/', $customRaw) as $line) {
                $e = strtolower(trim($line));
                if (filter_var($e, FILTER_VALIDATE_EMAIL))
                    $recipients[] = ['email' => $e, 'full_name' => $e];
            }
        }
        $footer = '<p style="font-size:11px;color:#64748b;margin:24px 0 0;text-align:center;"><a href="https://utiligo.ca/unsubscribe" style="color:#94a3b8;">Unsubscribe</a> &middot; <a href="https://utiligo.ca" style="color:#94a3b8;">Utiligo.ca</a></p>';

        // ── Make the body Gmail-safe BEFORE the send loop ─────────────────
        // 1) Host any base64 (data:) images uploaded from the builder's image
        //    library onto the server — Gmail does not reliably load data: URIs.
        // 2) Rewrite root-relative URLs ("/assets/...", "/exports/...") to
        //    absolute HTTPS URLs, which email clients need to load images.
        $assetLog = '';
        $bodyHtml = email_host_data_images($bodyHtml, $assetLog);
        $bodyHtml = email_absolutize_assets($bodyHtml);
        if ($assetLog !== '') {
            _admin_log('WARN', 'email image hosting: ' . rtrim($assetLog, '; '));
        }

        _admin_log('INFO', "Blast start: subject='{$subject}' aud={$audience} to=".count($recipients));
        foreach ($recipients as $r) {
            $html = email_wrapper('Message from Utiligo', "<p>Hi {$r['full_name']},</p>{$bodyHtml}", $footer);
            $ok = send_email($r['email'], $subject, $html, '', $r['full_name']);
            if ($ok) $sent++; else $errors[] = 'Failed: ' . $r['email'];
            if ($sent % 10 === 0 && $sent > 0) usleep(300000);
        }

        // FIX 2: Log the blast to utiligo_marketing_sends (user DB).
        // Previously called get_db() which does not exist anywhere in the
        // codebase (only get_user_db() / get_platform_db()), raising a
        // fatal \Error AFTER every email had already been sent, and the
        // \Exception catch below didn't catch \Error on PHP 7+. This left
        // the page broken while still delivering all the emails.
        try {
            // Reuse the $udb connection opened at line 32 (same user DB
            // where utiligo_users lives). utiligo_marketing_sends is created
            // by migrations/017_marketing_sends.sql.
            $logStmt = $udb->prepare(
                'INSERT INTO utiligo_marketing_sends (sent_by_admin, subject, segment, recipients_count)
                 VALUES (:admin, :subject, :segment, :count)'
            );
            $logStmt->execute([
                ':admin'   => $admin['email'] ?? 'admin',
                ':subject' => $subject,
                ':segment' => $audience,
                ':count'   => $sent,
            ]);
        } catch (\Throwable $e) {
            // Catch \Throwable (not \Exception) so missing-table or
            // connection errors are caught instead of fatalitying the
            // request. The blast itself already succeeded — only the
            // audit-log row failed, so we just record it and continue.
            _admin_log('ERROR', 'Failed to log blast to utiligo_marketing_sends: ' . $e->getMessage());
        }

        // FIX 3: Email-blast per-recipient failures are part of the user's
        // requested "email blast priority" — surface every failed recipient
        // in the global error log with this file's tag, with email-priority
        // alerting so the admin learns about blast failures immediately.
        if ($errors) {
            _admin_log('ERROR', 'email blast recipients failed: ' . implode(' | ', $errors));
            _utiligo_write_error_log('ERROR', 'email blast had ' . count($errors) . ' failed recipient(s): ' . implode(' | ', array_slice($errors, 0, 20)), __FILE__, __LINE__);
            utiligo_alert('ERROR', 'Email blast had ' . count($errors) . ' failed recipient(s) [blast subject: ' . $subject . ']', __FILE__, __LINE__);
        }
        _admin_log('INFO', "Blast done: sent={$sent} errors=".count($errors));
        $success = "Sent to {$sent} recipient(s).";
    }
}

$csrf = admin_csrf_token('email_blast');

// Full wrapper chrome used by the JS Preview modal so the on-screen preview
// is pixel-identical to the email that actually gets sent (dark card, header
// logo, footer). Tokens are swapped client-side before rendering.
$_blastFooter = '<p style="font-size:11px;color:#64748b;margin:24px 0 0;text-align:center;"><a href="https://utiligo.ca/unsubscribe" style="color:#94a3b8;">Unsubscribe</a> &middot; <a href="https://utiligo.ca" style="color:#94a3b8;">Utiligo.ca</a></p>';
$_ebChrome    = email_wrapper('__EB_SUBJECT__', '<p style="margin:0 0 8px;color:#CBD5E1;">Hi there,</p>' . '__EB_BODY__', $_blastFooter);
$ebChromeJson = json_encode($_ebChrome);
unset($_blastFooter, $_ebChrome);

$pageTitle = 'Email Blast — Admin — Utiligo';
$adminPage = 'email';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
/* ── Layout ── */
#eb-wrap { display:grid; grid-template-columns:220px 1fr 280px; gap:16px; align-items:start; }
#eb-props-col { position:sticky; top:80px; max-height:calc(100vh - 100px); overflow-y:auto; }
@media(max-width:1279px){ #eb-wrap{ grid-template-columns:200px 1fr; } #eb-props-col{ display:none; } }
@media(max-width:767px)  { #eb-wrap{ grid-template-columns:1fr; } #eb-blocks-col{ order:2; } #eb-canvas-col{ order:1; } }

/* ── Palette ── */
.pb-item {
  display:flex; align-items:center; gap:8px; padding:8px 11px;
  border-radius:10px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07);
  cursor:grab; font-size:.76rem; font-weight:500; color:#94a3b8;
  transition:all .15s; user-select:none; touch-action:none;
}
.pb-item:hover { background:rgba(99,102,241,.15); color:#fff; border-color:rgba(99,102,241,.4); }
.pb-item i { width:13px; text-align:center; font-size:.72rem; color:#6366f1; }
.pb-item .pb-badge { margin-left:auto; font-size:.58rem; padding:1px 5px; border-radius:4px; background:rgba(99,102,241,.2); color:#a5b4fc; font-weight:700; }
#palette-search { width:100%; margin-bottom:8px; }

/* ── Email client shell ── */
#eb-client-shell { background:#e8edf2; border-radius:16px; overflow:hidden; box-shadow:0 8px 48px rgba(0,0,0,.5); }
#eb-client-topbar { background:#d1d8e0; padding:10px 16px 0; display:flex; align-items:center; gap:7px; }
#eb-client-topbar span { width:12px; height:12px; border-radius:50%; display:inline-block; }
.dot-r{background:#ff5f57;} .dot-y{background:#febc2e;} .dot-g{background:#28c840;}
#eb-client-topbar-title { flex:1; text-align:center; font-size:.7rem; font-weight:600; color:#6b7280; padding-bottom:8px; letter-spacing:.02em; }
#eb-email-meta { background:#f3f4f6; border-bottom:1px solid #e5e7eb; padding:14px 20px; font-family:Arial,sans-serif; font-size:.8rem; color:#374151; }
.meta-row { display:flex; align-items:baseline; gap:8px; padding:3px 0; border-bottom:1px solid #e9ecef; }
.meta-row:last-child { border-bottom:none; }
.meta-label { font-weight:700; color:#9ca3af; min-width:56px; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; }
.meta-val { color:#111827; flex:1; }
.meta-val.subject { font-weight:700; font-size:.9rem; }
#eb-canvas-scroll { background:#e8edf2; padding:24px 0 40px; min-height:500px; max-height:75vh; overflow-y:auto; }
#eb-canvas-outer { margin:0 auto; background:#ffffff; max-width:600px; box-shadow:0 2px 24px rgba(0,0,0,.18); min-height:200px; transition:max-width .2s; }
#eb-canvas-outer.dov { outline:3px dashed #6366f1; outline-offset:3px; }

/* ── canvas hint ── */
#canvas-hint { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px; gap:10px; color:#9ca3af; border:2px dashed #d1d5db; margin:24px; border-radius:12px; }

/* ── Email blocks ── */
.ebl { position:relative; cursor:pointer; outline:2px solid transparent; outline-offset:-2px; transition:outline-color .1s; }
.ebl:hover  { outline-color:rgba(99,102,241,.5); }
.ebl.sel    { outline-color:#6366f1 !important; }
.ebl-type-badge { display:none; position:absolute; bottom:4px; left:4px; z-index:15; background:rgba(99,102,241,.85); color:#fff; font-size:.55rem; font-weight:700; padding:2px 6px; border-radius:4px; text-transform:uppercase; letter-spacing:.04em; pointer-events:none; }
.ebl:hover .ebl-type-badge { display:block; }

/* floating toolbar */
.ebl-bar { display:none; position:absolute; top:0; right:0; z-index:20; background:rgba(15,23,42,.92); border-radius:0 0 0 10px; backdrop-filter:blur(8px); border-left:1px solid rgba(255,255,255,.1); border-bottom:1px solid rgba(255,255,255,.1); }
.ebl:hover .ebl-bar, .ebl.sel .ebl-bar { display:flex; }
.ebl-bar button { border:none; background:transparent; color:#94a3b8; padding:6px 9px; cursor:pointer; font-size:.7rem; transition:color .1s,background .1s; }
.ebl-bar button:hover { color:#fff; background:rgba(255,255,255,.1); }

/* drop indicators */
.ebl.drop-above::before { content:''; display:block; height:3px; background:#6366f1; position:absolute; top:-2px; left:0; right:0; z-index:30; border-radius:2px; }
.ebl.drop-below::after  { content:''; display:block; height:3px; background:#6366f1; position:absolute; bottom:-2px; left:0; right:0; z-index:30; border-radius:2px; }

/* ── Props panel ── */
.pp-section { margin-bottom:1rem; }
.pp-label { font-size:.65rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.3rem; display:block; }
.pp-input { width:100%; padding:7px 10px; border-radius:9px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); color:#e2e8f0; font-size:.8rem; outline:none; transition:border-color .15s; box-sizing:border-box; }
.pp-input:focus { border-color:rgba(99,102,241,.6); }
.pp-color  { width:34px; height:30px; border-radius:8px; border:1px solid rgba(255,255,255,.12); cursor:pointer; padding:2px; background:transparent; flex-shrink:0; }
.pp-row    { display:flex; align-items:center; gap:6px; }
.pp-btn    { flex:1; padding:5px 4px; border-radius:7px; border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04); color:#94a3b8; cursor:pointer; font-size:.72rem; transition:all .12s; text-align:center; }
.pp-btn:hover,.pp-btn.act { background:rgba(99,102,241,.25); color:#fff; border-color:rgba(99,102,241,.5); }
.pp-sep { border:none; border-top:1px solid rgba(255,255,255,.06); margin:.6rem 0; }

/* ── Image library ── */
#img-uploader { border:2px dashed rgba(255,255,255,.15); border-radius:14px; padding:20px; text-align:center; transition:all .2s; cursor:pointer; }
#img-uploader:hover,#img-uploader.ov { border-color:#6366f1; background:rgba(99,102,241,.05); }
.img-thumb-grid { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
.img-thumb { position:relative; width:80px; height:60px; border-radius:8px; overflow:hidden; border:1px solid rgba(255,255,255,.1); cursor:pointer; flex-shrink:0; transition:border-color .15s; }
.img-thumb:hover { border-color:#6366f1; }
.img-thumb img { width:100%; height:100%; object-fit:cover; }
.img-thumb .copy-tag { position:absolute; inset:0; background:rgba(0,0,0,.65); opacity:0; display:flex; align-items:center; justify-content:center; font-size:.6rem; font-weight:700; color:#fff; transition:opacity .15s; text-align:center; padding:4px; }
.img-thumb:hover .copy-tag { opacity:1; }
.img-thumb .del-btn { position:absolute; top:2px; right:2px; background:rgba(220,38,38,.8); border:none; color:#fff; border-radius:4px; width:16px; height:16px; font-size:.55rem; cursor:pointer; display:none; align-items:center; justify-content:center; }
.img-thumb:hover .del-btn { display:flex; }

/* ── Mobile drawer ── */
#mob-drawer { position:fixed; inset:0; z-index:60; pointer-events:none; }
#mob-drawer-bg { position:absolute; inset:0; background:rgba(0,0,0,.65); opacity:0; transition:opacity .2s; }
#mob-drawer-panel { position:absolute; bottom:0; left:0; right:0; background:#0f172a; border-top:1px solid rgba(255,255,255,.1); border-radius:20px 20px 0 0; padding:20px 20px 32px; transform:translateY(100%); transition:transform .28s cubic-bezier(.4,0,.2,1); max-height:78vh; overflow-y:auto; }
#mob-drawer.open { pointer-events:auto; }
#mob-drawer.open #mob-drawer-bg { opacity:1; }
#mob-drawer.open #mob-drawer-panel { transform:translateY(0); }

/* misc */
.aud-pill { padding:5px 14px; border-radius:999px; font-size:.75rem; font-weight:600; border:1px solid rgba(255,255,255,.1); cursor:pointer; transition:all .15s; color:#64748b; background:transparent; }
.aud-pill:hover { color:#fff; border-color:rgba(255,255,255,.3); }
.aud-pill.on    { background:#fff; color:#000; border-color:#fff; }
.pv-btn { padding:5px 12px; border-radius:9px; font-size:.75rem; font-weight:600; border:1px solid rgba(255,255,255,.1); cursor:pointer; color:#64748b; background:transparent; transition:all .15s; }
.pv-btn.on { background:#fff; color:#000; border-color:#fff; }
#draft-indicator { font-size:.7rem; color:#64748b; transition:color .3s; }
#draft-indicator.saved { color:#34d399; }
#char-counter { font-size:.7rem; color:#64748b; }
</style>

<!-- PAGE HEADER -->
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">Email Blast</h1>
    <p class="text-slate-400 text-sm mt-1">Build your email visually, then send it to your audience.</p>
  </div>
  <div class="flex items-center gap-3">
    <span id="draft-indicator"><i class="fa-solid fa-circle-dot"></i> Draft</span>
    <button type="button" onclick="saveDraft()" class="text-xs px-4 py-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-slate-300 font-semibold transition">
      <i class="fa-solid fa-floppy-disk mr-1"></i> Save Draft
    </button>
    <button type="button" onclick="loadDraft()" class="text-xs px-4 py-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-slate-300 font-semibold transition">
      <i class="fa-solid fa-folder-open mr-1"></i> Load Draft
    </button>
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
        <button type="button" class="aud-pill" data-v="paid">Paid</button>
        <button type="button" class="aud-pill" data-v="pro">Pro</button>
        <button type="button" class="aud-pill" data-v="entrepreneur">Entrepreneur</button>
        <button type="button" class="aud-pill" data-v="free">Free</button>
        <button type="button" class="aud-pill" data-v="custom">Custom</button>
      </div>
    </div>
    <div class="sm:col-span-2">
      <label class="pp-label" for="s-subject" style="margin-bottom:.4rem">Subject Line</label>
      <input id="s-subject" type="text" placeholder="e.g. Big news from Utiligo!"
             class="pp-input" style="padding:9px 14px;font-size:.875rem;" oninput="syncSubjectPreview();markDirty()">
    </div>
    <div class="flex items-end">
      <button type="button" onclick="doSend()"
        class="w-full flex items-center justify-center gap-2 bg-white hover:bg-slate-200 text-black py-2.5 px-5 rounded-xl font-bold text-sm transition">
        <i class="fa-solid fa-paper-plane"></i> Send Blast
      </button>
    </div>
  </div>
  <div id="custom-box" class="hidden mt-4">
    <label class="pp-label">Custom addresses <span class="normal-case font-normal text-slate-500">(comma or newline)</span></label>
    <textarea id="custom-emails" rows="3" placeholder="alice@example.com, bob@example.com" class="pp-input" style="resize:vertical;"></textarea>
  </div>
  <!-- char counter row -->
  <div class="flex items-center gap-4 mt-3">
    <span id="char-counter">Subject: 0 chars</span>
    <span class="text-xs text-slate-600">|</span>
    <span id="block-counter" class="text-xs text-slate-500">0 blocks</span>
  </div>
</div>

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
    <div class="glass rounded-2xl p-4" style="position:sticky;top:80px;max-height:calc(100vh - 110px);overflow-y:auto;">
      <p class="pp-label mb-2">Blocks</p>
      <input type="search" id="palette-search" class="pp-input" placeholder="Search blocks…" oninput="filterPalette(this.value)">
      <div class="space-y-1" id="palette">
        <!-- Layout -->
        <p class="text-xs text-slate-600 font-semibold mt-2 mb-1 px-1">Layout</p>
        <div class="pb-item" data-t="logo"     data-cat="layout"><i class="fa-solid fa-star"></i>Logo<span class="pb-badge">NEW</span></div>
        <div class="pb-item" data-t="heading"  data-cat="layout"><i class="fa-solid fa-h"></i>Heading</div>
        <div class="pb-item" data-t="text"     data-cat="layout"><i class="fa-solid fa-align-left"></i>Paragraph</div>
        <div class="pb-item" data-t="columns"  data-cat="layout"><i class="fa-solid fa-table-columns"></i>2 Columns</div>
        <div class="pb-item" data-t="columns3" data-cat="layout"><i class="fa-solid fa-table-cells"></i>3 Columns</div>
        <div class="pb-item" data-t="divider"  data-cat="layout"><i class="fa-solid fa-minus"></i>Divider</div>
        <div class="pb-item" data-t="spacer"   data-cat="layout"><i class="fa-solid fa-up-down"></i>Spacer</div>
        <!-- Media -->
        <p class="text-xs text-slate-600 font-semibold mt-3 mb-1 px-1">Media</p>
        <div class="pb-item" data-t="image"  data-cat="media"><i class="fa-solid fa-image"></i>Image</div>
        <div class="pb-item" data-t="video"  data-cat="media"><i class="fa-brands fa-youtube"></i>Video Thumb</div>
        <!-- Content -->
        <p class="text-xs text-slate-600 font-semibold mt-3 mb-1 px-1">Content</p>
        <div class="pb-item" data-t="button"    data-cat="content"><i class="fa-solid fa-arrow-pointer"></i>Button</div>
        <div class="pb-item" data-t="list"      data-cat="content"><i class="fa-solid fa-list-ul"></i>Bullet List</div>
        <div class="pb-item" data-t="alert"     data-cat="content"><i class="fa-solid fa-circle-info"></i>Alert Banner</div>
        <div class="pb-item" data-t="badge"     data-cat="content"><i class="fa-solid fa-tag"></i>Badge / Label</div>
        <div class="pb-item" data-t="quote"     data-cat="content"><i class="fa-solid fa-quote-left"></i>Quote</div>
        <div class="pb-item" data-t="countdown" data-cat="content"><i class="fa-solid fa-clock"></i>Countdown</div>
        <div class="pb-item" data-t="social"    data-cat="content"><i class="fa-solid fa-share-nodes"></i>Social Links</div>
        <!-- Advanced -->
        <p class="text-xs text-slate-600 font-semibold mt-3 mb-1 px-1">Advanced</p>
        <div class="pb-item" data-t="footer" data-cat="advanced"><i class="fa-solid fa-bars-staggered"></i>Footer</div>
        <div class="pb-item" data-t="html"   data-cat="advanced"><i class="fa-solid fa-code"></i>Raw HTML</div>
      </div>
      <hr class="pp-sep mt-3">
      <div class="space-y-2">
        <button onclick="prevModal()" type="button"
          class="w-full text-xs py-2 rounded-xl bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/20 transition font-semibold">
          <i class="fa-solid fa-eye mr-1"></i> Preview
        </button>
        <button onclick="clearAll()" type="button"
          class="w-full text-xs py-2 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition font-semibold">
          <i class="fa-solid fa-trash mr-1"></i> Clear Canvas
        </button>
      </div>
      <div class="mt-2 flex gap-1.5">
        <button onclick="undo()" type="button" title="Undo (Ctrl+Z)" class="flex-1 text-xs py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition"><i class="fa-solid fa-rotate-left"></i></button>
        <button onclick="redo()" type="button" title="Redo (Ctrl+Y)" class="flex-1 text-xs py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 border border-white/10 transition"><i class="fa-solid fa-rotate-right"></i></button>
      </div>
    </div>
  </div>

  <!-- CENTER: canvas -->
  <div id="eb-canvas-col">
    <div class="glass rounded-2xl px-4 py-3 mb-3 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3 flex-wrap">
        <span class="text-xs text-slate-500">Email BG</span>
        <input type="color" id="canvasBgPicker" value="#ffffff" class="pp-color" oninput="setBg(this.value)">
        <input type="text"  id="canvasBgHex"    value="#ffffff" class="pp-input" style="width:80px;padding:5px 8px;" oninput="syncBgFromHex(this.value)">
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

    <!-- email-client chrome -->
    <div id="eb-client-shell">
      <div id="eb-client-topbar">
        <span class="dot-r"></span><span class="dot-y"></span><span class="dot-g"></span>
        <div id="eb-client-topbar-title">New Message — Utiligo Mail</div>
      </div>
      <div id="eb-email-meta">
        <div class="meta-row"><span class="meta-label">From</span><span class="meta-val">Utiligo &lt;noreply@utiligo.ca&gt;</span></div>
        <div class="meta-row"><span class="meta-label">To</span><span class="meta-val" id="pv-to-label">All verified users</span></div>
        <div class="meta-row"><span class="meta-label">Subject</span><span class="meta-val subject" id="pv-subject-label">— no subject —</span></div>
      </div>
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
      <p class="pp-label mb-3">Block Properties</p>
      <div id="pp-content"><p class="text-xs text-slate-600 text-center py-10">Click a block<br>to edit it</p></div>
    </div>
  </div>

</div>

<!-- IMAGE LIBRARY -->
<div class="glass rounded-2xl p-5 mt-6">
  <div class="flex items-center gap-2 mb-4">
    <i class="fa-solid fa-images text-slate-400"></i>
    <h3 class="font-semibold text-sm">Image Library</h3>
    <span class="text-xs text-slate-500 ml-1">— upload images &amp; paste URLs into blocks</span>
  </div>
  <div id="img-uploader" onclick="document.getElementById('img-file-inp').click()"
       ondragover="event.preventDefault();this.classList.add('ov')" ondragleave="this.classList.remove('ov')" ondrop="handleImgDrop(event)">
    <i class="fa-solid fa-cloud-arrow-up text-slate-500 text-2xl mb-2"></i>
    <p class="text-sm text-slate-400">Drop images here or <span class="text-indigo-400 underline cursor-pointer">click to upload</span></p>
    <p class="text-xs text-slate-600 mt-1">PNG, JPG, GIF, WebP — stored in browser, copy URL into any Image / Logo / Video block</p>
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

// ═══ STATE ═══════════════════════════════════
let blocks     = [];
let selId      = null;
let hist       = [], fut = [];
let canvasBg   = '#ffffff';
let dragPal    = null, dragId = null, dropTarget = null;
let isDirty    = false;

const CANVAS = document.getElementById('eb-canvas');
const HINT   = document.getElementById('canvas-hint');

// ═══ DEFAULTS ════════════════════════════════
const LOGO_URL = <?= json_encode(rtrim(APP_BASE_URL, '/') . '/assets/images/logo.png') ?>;
const EB_CHROME = <?= $ebChromeJson ?>;

const DEF = {
  logo     : { src: LOGO_URL, alt:'Utiligo', align:'center', width:'180', radius:'0', blockBg:'#111827', padV:'20', padH:'28' },
  heading  : { text:'Your Heading', level:'h2', color:'#111827', align:'left', size:'26', weight:'700', blockBg:'', padV:'16', padH:'28' },
  text     : { text:'Your paragraph text goes here. Click to edit inline.', color:'#374151', align:'left', size:'15', lh:'1.7', blockBg:'', padV:'16', padH:'28' },
  image    : { src:'', alt:'', align:'center', width:'100', radius:'0', blockBg:'', padV:'0', padH:'0' },
  video    : { thumb:'', videoUrl:'https://youtube.com/watch?v=dQw4w9WgXcQ', alt:'Watch video', align:'center', blockBg:'', padV:'0', padH:'0' },
  button   : { text:'Click Here', href:'https://utiligo.ca', bg:'#6366f1', fg:'#ffffff', align:'center', size:'15', radius:'8', bold:'1', blockBg:'', padV:'20', padH:'28' },
  divider  : { color:'#e5e7eb', thick:'1', my:'20', blockBg:'' },
  spacer   : { h:'32', blockBg:'' },
  list     : { items:'First item\nSecond item\nThird item', color:'#374151', size:'15', style:'ul', blockBg:'', padV:'16', padH:'28' },
  alert    : { text:'This is an important announcement.', type:'info', blockBg:'', padV:'14', padH:'24' },
  badge    : { text:'NEW FEATURE', bg:'#6366f1', fg:'#ffffff', align:'center', radius:'6', size:'12', blockBg:'', padV:'12', padH:'28' },
  quote    : { text:'This tool changed everything for us.', author:'Jane Doe, CEO', color:'#374151', borderColor:'#6366f1', blockBg:'#f8f9ff', padV:'20', padH:'28' },
  countdown: { label:'Offer ends in:', endDate:'', color:'#111827', bg:'#f3f4f6', blockBg:'', padV:'20', padH:'28' },
  social   : { twitter:'https://twitter.com/utiligo', instagram:'', linkedin:'https://linkedin.com/company/utiligo', facebook:'', youtube:'', color:'#6366f1', align:'center', blockBg:'', padV:'16', padH:'28' },
  columns  : { l:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;margin:0;">Left column.</p>', r:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;margin:0;">Right column.</p>', gap:'20', blockBg:'', padV:'16', padH:'28' },
  columns3 : { a:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:14px;margin:0;">Column 1</p>', b:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:14px;margin:0;">Column 2</p>', c:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:14px;margin:0;">Column 3</p>', gap:'16', blockBg:'', padV:'16', padH:'28' },
  footer   : { company:'Utiligo Inc.', address:'Montreal, QC, Canada', unsubUrl:'https://utiligo.ca/unsubscribe', color:'#9ca3af', blockBg:'#1e293b', padV:'28', padH:'28' },
  html     : { code:'<p style="color:#374151;font-family:Arial,sans-serif;font-size:15px;margin:0;">Custom HTML here</p>', blockBg:'', padV:'16', padH:'28' },
};

// ═══ UTILS ═══════════════════════════════════
const uid   = () => Math.random().toString(36).slice(2,9);
const clone = v  => JSON.parse(JSON.stringify(v));

function snap() { hist.push(clone(blocks)); if (hist.length > 80) hist.shift(); fut = []; }
function undo() { if (!hist.length) return; fut.push(clone(blocks)); blocks = hist.pop(); selId = null; render(); }
function redo() { if (!fut.length)  return; hist.push(clone(blocks)); blocks = fut.pop();  selId = null; render(); }

function markDirty() {
  isDirty = true;
  document.getElementById('draft-indicator').textContent = '● Unsaved';
  document.getElementById('draft-indicator').classList.remove('saved');
  updateCounters();
}

function updateCounters() {
  const subj = document.getElementById('s-subject').value;
  document.getElementById('char-counter').textContent = 'Subject: ' + subj.length + ' chars';
  document.getElementById('block-counter').textContent = blocks.length + ' block' + (blocks.length !== 1 ? 's' : '');
}

document.addEventListener('keydown', e => {
  const tag = document.activeElement.tagName;
  const ce  = document.activeElement.isContentEditable;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || ce) return;
  if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); undo(); }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'z'))) { e.preventDefault(); redo(); }
  if (e.key === 'Escape') { selId = null; render(); closeDrawer(); }
  if ((e.key === 'Delete' || e.key === 'Backspace') && selId) { snap(); del(selId); }
  if (e.key === 'ArrowUp'   && selId) { mv(selId, -1); }
  if (e.key === 'ArrowDown' && selId) { mv(selId,  1); }
});

function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ═══ BLOCK HTML (inline-CSS, email-safe) ══════
function blockHtml(b) {
  const d   = b.data;
  const pV  = d.padV  !== undefined ? d.padV  : '16';
  const pH  = d.padH  !== undefined ? d.padH  : '28';
  const pad = 'padding:' + pV + 'px ' + pH + 'px;';
  const bg  = d.blockBg ? 'background:' + d.blockBg + ';' : '';
  let inner = '';

  if (b.type === 'logo') {
    const src = d.src || LOGO_URL;
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
            '<img src="' + escAttr(src) + '" alt="' + escAttr(d.alt) + '" style="max-width:100%;width:' + d.width + '%;border-radius:' + d.radius + 'px;display:inline-block;"></div>';

  } else if (b.type === 'video') {
    const thumb = d.thumb || 'https://placehold.co/600x340/1e1e2e/a5b4fc?text=Video+Thumbnail';
    inner = '<div style="text-align:' + d.align + ';position:relative;">' +
            '<a href="' + escAttr(d.videoUrl) + '" style="display:inline-block;position:relative;">' +
            '<img src="' + escAttr(thumb) + '" alt="' + escAttr(d.alt) + '" style="max-width:100%;display:block;border-radius:8px;">' +
            '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:64px;height:64px;background:rgba(0,0,0,.65);border-radius:50%;display:flex;align-items:center;justify-content:center;">' +
            '<div style="width:0;height:0;border-style:solid;border-width:12px 0 12px 22px;border-color:transparent transparent transparent #ffffff;margin-left:4px;"></div>' +
            '</div></a></div>';

  } else if (b.type === 'button') {
    const fw = d.bold === '1' ? '700' : '400';
    inner = '<div style="text-align:' + d.align + ';">' +
            '<a href="' + escAttr(d.href) + '" style="display:inline-block;padding:13px 32px;background:' + d.bg + ';color:' + d.fg + ';font-family:Arial,sans-serif;font-size:' + d.size + 'px;font-weight:' + fw + ';text-decoration:none;border-radius:' + d.radius + 'px;">' + d.text + '</a></div>';

  } else if (b.type === 'list') {
    const tag  = d.style === 'ol' ? 'ol' : 'ul';
    const liSt = d.style === 'check'
      ? 'list-style:none;padding-left:0;'
      : '';
    const items = String(d.items).split('\n').filter(x => x.trim());
    const lis = items.map(item => {
      const prefix = d.style === 'check' ? '✅ ' : '';
      return '<li style="margin:4px 0;font-family:Arial,sans-serif;font-size:' + d.size + 'px;color:' + d.color + ';">' + prefix + item.trim() + '</li>';
    }).join('');
    inner = '<' + tag + ' style="margin:0;padding-left:' + (d.style==='check'?'0':'20') + 'px;' + liSt + '">' + lis + '</' + tag + '>';

  } else if (b.type === 'alert') {
    const styles = {
      info:    { bg:'#eff6ff', border:'#3b82f6', color:'#1e40af', icon:'ℹ️' },
      success: { bg:'#f0fdf4', border:'#22c55e', color:'#15803d', icon:'✅' },
      warning: { bg:'#fffbeb', border:'#f59e0b', color:'#92400e', icon:'⚠️' },
      danger:  { bg:'#fef2f2', border:'#ef4444', color:'#991b1b', icon:'🚨' },
    };
    const s = styles[d.type] || styles.info;
    inner = '<div style="background:' + s.bg + ';border-left:4px solid ' + s.border + ';padding:12px 16px;border-radius:0 8px 8px 0;">' +
            '<p style="margin:0;font-family:Arial,sans-serif;font-size:14px;color:' + s.color + ';">' + s.icon + ' ' + d.text + '</p></div>';

  } else if (b.type === 'badge') {
    inner = '<div style="text-align:' + d.align + ';">' +
            '<span style="display:inline-block;background:' + d.bg + ';color:' + d.fg + ';font-family:Arial,sans-serif;font-size:' + d.size + 'px;font-weight:700;padding:4px 14px;border-radius:' + d.radius + 'px;letter-spacing:.06em;">' + d.text + '</span></div>';

  } else if (b.type === 'quote') {
    inner = '<div style="border-left:4px solid ' + d.borderColor + ';padding:16px 20px;background:' + (d.blockBg||'#f8f9ff') + ';">' +
            '<p style="margin:0 0 8px;font-family:Georgia,serif;font-size:17px;color:' + d.color + ';font-style:italic;line-height:1.6;">&ldquo;' + d.text + '&rdquo;</p>' +
            '<p style="margin:0;font-family:Arial,sans-serif;font-size:13px;color:#9ca3af;font-weight:600;">— ' + d.author + '</p></div>';

  } else if (b.type === 'countdown') {
    inner = '<div style="text-align:center;background:' + d.bg + ';border-radius:8px;padding:16px;">' +
            '<p style="margin:0 0 10px;font-family:Arial,sans-serif;font-size:14px;color:' + d.color + ';font-weight:600;">' + d.label + '</p>' +
            '<table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>' +
            ['Days','Hours','Mins','Secs'].map(u =>
              '<td style="text-align:center;padding:0 10px;"><div style="font-family:Arial,sans-serif;font-size:32px;font-weight:800;color:' + d.color + ';line-height:1;">00</div><div style="font-family:Arial,sans-serif;font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em;margin-top:2px;">' + u + '</div></td>'
            ).join('') +
            '</tr></table></div>';

  } else if (b.type === 'social') {
    const networks = [
      { key:'twitter',   icon:'𝕏',  label:'Twitter'   },
      { key:'instagram', icon:'📸', label:'Instagram' },
      { key:'linkedin',  icon:'in', label:'LinkedIn'  },
      { key:'facebook',  icon:'f',  label:'Facebook'  },
      { key:'youtube',   icon:'▶',  label:'YouTube'   },
    ];
    const links = networks.filter(n => d[n.key]).map(n =>
      '<a href="' + escAttr(d[n.key]) + '" style="display:inline-block;margin:0 6px;padding:8px 14px;background:' + d.color + ';color:#fff;font-family:Arial,sans-serif;font-size:13px;font-weight:700;text-decoration:none;border-radius:8px;">' + n.icon + ' ' + n.label + '</a>'
    ).join('');
    inner = '<div style="text-align:' + d.align + ';">' + (links || '<span style="color:#9ca3af;font-size:13px;font-family:Arial,sans-serif;">Add social URLs in the properties panel</span>') + '</div>';

  } else if (b.type === 'columns') {
    const half = Math.round(d.gap / 2);
    inner = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>' +
            '<td style="width:50%;padding-right:' + half + 'px;vertical-align:top;">' + d.l + '</td>' +
            '<td style="width:50%;padding-left:' + half + 'px;vertical-align:top;">' + d.r + '</td>' +
            '</tr></table>';

  } else if (b.type === 'columns3') {
    const g = Math.round(d.gap / 2);
    inner = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>' +
            '<td style="width:33.33%;padding-right:' + g + 'px;vertical-align:top;">' + d.a + '</td>' +
            '<td style="width:33.33%;padding:0 ' + g + 'px;vertical-align:top;">' + d.b + '</td>' +
            '<td style="width:33.33%;padding-left:' + g + 'px;vertical-align:top;">' + d.c + '</td>' +
            '</tr></table>';

  } else if (b.type === 'footer') {
    inner = '<div style="text-align:center;">' +
            '<p style="margin:0 0 4px;font-family:Arial,sans-serif;font-size:13px;color:' + d.color + ';font-weight:600;">' + d.company + '</p>' +
            '<p style="margin:0 0 8px;font-family:Arial,sans-serif;font-size:12px;color:' + d.color + ';opacity:.7;">' + d.address + '</p>' +
            '<p style="margin:0;font-family:Arial,sans-serif;font-size:11px;"><a href="' + escAttr(d.unsubUrl) + '" style="color:' + d.color + ';opacity:.7;">Unsubscribe</a> &middot; <a href="https://utiligo.ca" style="color:' + d.color + ';opacity:.7;">Utiligo.ca</a></p>' +
            '</div>';

  } else if (b.type === 'html') {
    inner = d.code;
  }

  return '<div style="' + pad + bg + '">' + inner + '</div>';
}

function buildEmail() {
  const mw = document.getElementById('canvasWidthSel').value;
  const w  = mw === '100%' ? '100%' : mw + 'px';
  let out  = '<div style="max-width:' + w + ';margin:0 auto;background:' + canvasBg + ';">';
  blocks.forEach(b => { out += blockHtml(b); });
  out += '</div>';
  return out;
}

// ═══ RENDER ══════════════════════════════════
function render() {
  HINT.style.display = blocks.length ? 'none' : 'flex';
  Array.from(CANVAS.querySelectorAll('.ebl')).forEach(e => e.remove());

  blocks.forEach(b => {
    const wrap = document.createElement('div');
    wrap.className  = 'ebl' + (b.id === selId ? ' sel' : '');
    wrap.dataset.id = b.id;
    wrap.draggable  = true;

    // type badge
    const badge = document.createElement('div');
    badge.className   = 'ebl-type-badge';
    badge.textContent = b.type.replace('columns3','3-col').replace('columns','2-col');

    // toolbar
    const bar = document.createElement('div');
    bar.className = 'ebl-bar';
    bar.innerHTML =
      '<button title="Move Up"   onclick="mv(\'' + b.id + '\',-1)"><i class="fa-solid fa-chevron-up"></i></button>' +
      '<button title="Move Down" onclick="mv(\'' + b.id + '\',1)"><i class="fa-solid fa-chevron-down"></i></button>' +
      '<button title="Duplicate" onclick="dupe(\'' + b.id + '\')" ><i class="fa-solid fa-copy"></i></button>' +
      '<button title="Delete"    onclick="del(\'' + b.id + '\')"  style="color:#f87171;"><i class="fa-solid fa-trash"></i></button>';

    // content
    const content = document.createElement('div');
    content.className = 'ebl-content';
    content.innerHTML = blockHtml(b);

    // inline editing
    if (b.type === 'heading' || b.type === 'text') {
      const el = content.querySelector('h1,h2,h3,h4,p');
      if (el) {
        el.contentEditable = 'true'; el.style.outline = 'none'; el.style.cursor = 'text';
        el.addEventListener('focus', () => { selId = b.id; selectBlock(b.id, false); });
        el.addEventListener('blur',  () => { b.data.text = el.innerHTML; syncPP(); markDirty(); });
        el.addEventListener('keydown', ev => { if (ev.key === 'Enter' && b.type === 'heading') ev.preventDefault(); });
      }
    }
    if (b.type === 'columns' || b.type === 'columns3') {
      const keys = b.type === 'columns' ? ['l','r'] : ['a','b','c'];
      content.querySelectorAll('td').forEach((td, i) => {
        td.contentEditable = 'true'; td.style.outline = 'none'; td.style.cursor = 'text';
        td.addEventListener('blur', () => { b.data[keys[i]] = td.innerHTML; markDirty(); });
      });
    }

    wrap.appendChild(badge);
    wrap.appendChild(bar);
    wrap.appendChild(content);

    wrap.addEventListener('click', e => {
      if (e.target.closest('.ebl-bar')) return;
      if (e.target.isContentEditable || e.target.closest('[contenteditable]')) return;
      selectBlock(b.id, true);
    });

    wrap.addEventListener('dragstart', e => { dragId = b.id; dragPal = null; e.dataTransfer.effectAllowed = 'move'; e.stopPropagation(); });
    wrap.addEventListener('dragend',   () => { dragId = null; clearDI(); });
    wrap.addEventListener('dragover',  e => { e.preventDefault(); e.stopPropagation(); showDI(b.id, e); });
    wrap.addEventListener('dragleave', e => { if (!wrap.contains(e.relatedTarget)) clearDI(); });
    wrap.addEventListener('drop',      e => { e.preventDefault(); e.stopPropagation(); commitDrop(b.id); });

    CANVAS.appendChild(wrap);
  });

  syncPP();
  updateCounters();
}

function selectBlock(id, scroll) {
  selId = id;
  CANVAS.querySelectorAll('.ebl').forEach(e => e.classList.toggle('sel', e.dataset.id === id));
  syncPP();
  document.getElementById('edit-props-btn').classList.remove('hidden');
  if (scroll) {
    const el = CANVAS.querySelector('[data-id="' + id + '"]');
    if (el) el.scrollIntoView({ behavior:'smooth', block:'nearest' });
  }
}

// ═══ DROP INDICATORS ════════════════════════
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
  CANVAS.querySelectorAll('.drop-above,.drop-below').forEach(e => e.classList.remove('drop-above','drop-below'));
  dropTarget = null;
}

// ═══ PALETTE ════════════════════════════════
document.querySelectorAll('.pb-item').forEach(el => {
  el.addEventListener('dragstart', e => { dragPal = el.dataset.t; dragId = null; e.dataTransfer.effectAllowed = 'copy'; });
  el.addEventListener('dragend',   () => { dragPal = null; });
  el.addEventListener('click',     () => { snap(); addBlock(el.dataset.t); });
});

function filterPalette(q) {
  const lq = q.toLowerCase();
  document.querySelectorAll('.pb-item').forEach(el => {
    el.style.display = el.dataset.t.includes(lq) || el.textContent.toLowerCase().includes(lq) ? '' : 'none';
  });
  document.querySelectorAll('#palette p').forEach(p => { p.style.display = q ? 'none' : ''; });
}

CANVAS.addEventListener('dragover',  e => { e.preventDefault(); document.getElementById('eb-canvas-outer').classList.add('dov'); });
CANVAS.addEventListener('dragleave', e => { if (!CANVAS.contains(e.relatedTarget)) document.getElementById('eb-canvas-outer').classList.remove('dov'); });
CANVAS.addEventListener('drop', e => {
  e.preventDefault();
  document.getElementById('eb-canvas-outer').classList.remove('dov');
  if (!dragPal) return;
  snap();
  const b = { id:uid(), type:dragPal, data:clone(DEF[dragPal]) };
  if (dropTarget) {
    const idx = blocks.findIndex(x => x.id === dropTarget.id);
    blocks.splice(dropTarget.pos === 'above' ? idx : idx+1, 0, b);
  } else { blocks.push(b); }
  selId = b.id; dragPal = null; clearDI(); render();
});

function commitDrop(targetId) {
  document.getElementById('eb-canvas-outer').classList.remove('dov');
  if (!dragId || dragId === targetId || !dropTarget) { clearDI(); return; }
  snap();
  const fi = blocks.findIndex(x => x.id === dragId);
  const [moved] = blocks.splice(fi, 1);
  const ti = blocks.findIndex(x => x.id === dropTarget.id);
  blocks.splice(dropTarget.pos === 'above' ? ti : ti+1, 0, moved);
  dragId = null; clearDI(); render();
}

// ═══ BLOCK OPS ══════════════════════════════
function addBlock(type) {
  const b = { id:uid(), type, data:clone(DEF[type]) };
  if (selId) { const i = blocks.findIndex(x => x.id === selId); blocks.splice(i+1, 0, b); }
  else blocks.push(b);
  selId = b.id; render(); markDirty();
  setTimeout(() => { const el = CANVAS.querySelector('[data-id="' + b.id + '"]'); if (el) el.scrollIntoView({behavior:'smooth',block:'nearest'}); }, 60);
}
function del(id)  { snap(); blocks = blocks.filter(x => x.id !== id); if (selId===id) selId=null; render(); markDirty(); }
function mv(id,d) {
  snap();
  const i = blocks.findIndex(x => x.id === id), j = i+d;
  if (j < 0 || j >= blocks.length) return;
  [blocks[i],blocks[j]] = [blocks[j],blocks[i]]; render(); markDirty();
}
function dupe(id) {
  snap();
  const src = blocks.find(x => x.id === id); if (!src) return;
  const cp  = { id:uid(), type:src.type, data:clone(src.data) };
  blocks.splice(blocks.findIndex(x => x.id===id)+1, 0, cp);
  selId = cp.id; render(); markDirty();
}
function clearAll() {
  if (!blocks.length) return;
  if (!confirm('Clear the entire canvas?')) return;
  snap(); blocks=[]; selId=null; render(); markDirty();
}
function setBg(v) {
  canvasBg = v;
  CANVAS.style.background = v;
  document.getElementById('eb-canvas-outer').style.background = v;
  const hex = document.getElementById('canvasBgHex');
  if (hex && hex.value !== v) hex.value = v;
  markDirty();
}
function syncBgFromHex(v) {
  if (/^#[0-9a-fA-F]{6}$/.test(v)) {
    canvasBg = v; CANVAS.style.background = v;
    document.getElementById('eb-canvas-outer').style.background = v;
    const p = document.getElementById('canvasBgPicker'); if (p) p.value = v;
    markDirty();
  }
}
function setWidth(v) { document.getElementById('eb-canvas-outer').style.maxWidth = v==='100%' ? '100%' : v+'px'; }

// ═══ PROPERTIES PANEL ═══════════════════════
function syncPP() {
  const html = selId ? buildPpHtml(blocks.find(x => x.id===selId))
                     : '<p class="text-xs text-slate-600 text-center py-10">Click a block<br>to edit it</p>';
  document.getElementById('pp-content').innerHTML     = html;
  document.getElementById('mob-pp-content').innerHTML = html;
  wirePP('pp-content');
  wirePP('mob-pp-content');
}

function wirePP(cid) {
  document.getElementById(cid).querySelectorAll('[data-p]').forEach(el => {
    if (el.type === 'color') {
      el.addEventListener('input', () => {
        applyProp(el.dataset.p, el.value);
        const row = el.closest('.pp-row');
        if (row) { const t = row.querySelector('input[type="text"][data-p="'+el.dataset.p+'"]'); if (t && t.value!==el.value) t.value=el.value; }
      });
    } else {
      el.addEventListener('input',  () => applyProp(el.dataset.p, el.value));
      el.addEventListener('change', () => applyProp(el.dataset.p, el.value));
      if (el.type === 'text') {
        el.addEventListener('input', () => {
          if (/^#[0-9a-fA-F]{6}$/.test(el.value)) {
            const row = el.closest('.pp-row');
            if (row) { const sw = row.querySelector('input[type="color"][data-p="'+el.dataset.p+'"]'); if (sw && sw.value!==el.value) sw.value=el.value; }
          }
        });
      }
    }
  });
}

function applyProp(key, val) {
  if (!selId) return;
  const b = blocks.find(x => x.id===selId); if (!b) return;
  b.data[key] = val;
  const wrap    = CANVAS.querySelector('[data-id="'+selId+'"]'); if (!wrap) return;
  const content = wrap.querySelector('.ebl-content'); if (!content) return;
  content.innerHTML = blockHtml(b);
  // re-attach editing
  if (b.type==='heading' || b.type==='text') {
    const el = content.querySelector('h1,h2,h3,h4,p');
    if (el) { el.contentEditable='true'; el.style.outline='none'; el.style.cursor='text';
      el.addEventListener('blur',    () => { b.data.text=el.innerHTML; syncPP(); markDirty(); });
      el.addEventListener('keydown', ev => { if (ev.key==='Enter'&&b.type==='heading') ev.preventDefault(); }); }
  }
  if (b.type==='columns'||b.type==='columns3') {
    const keys = b.type==='columns' ? ['l','r'] : ['a','b','c'];
    content.querySelectorAll('td').forEach((td,i) => {
      td.contentEditable='true'; td.style.outline='none';
      td.addEventListener('blur', () => { b.data[keys[i]]=td.innerHTML; markDirty(); });
    });
  }
  markDirty();
}

function ppAlignBtns(key, cur) {
  return ['left','center','right'].map(a =>
    '<button type="button" class="pp-btn '+(cur===a?'act':'')
    +'" onclick="applyProp(\'' + key + '\',\'' + a + '\');syncPP()">'
    +'<i class="fa-solid fa-align-' + a + '"></i></button>'
  ).join('');
}

function buildPpHtml(b) {
  if (!b) return '';
  const d = b.data;
  const row    = (lbl, inp) => '<div class="pp-section"><label class="pp-label">' + lbl + '</label>' + inp + '</div>';
  const txt    = (key, val, ph) => '<input type="text" class="pp-input" data-p="' + key + '" value="' + escHtml(val||'') + '" placeholder="' + (ph||'') + '">';
  const num    = (key, val, mn, mx, st) => '<input type="number" class="pp-input" data-p="' + key + '" value="' + val + '" min="' + mn + '" max="' + mx + '"' + (st?' step="'+st+'"':'') + '>';
  const clr    = (key, val) => '<div class="pp-row"><input type="color" class="pp-color" data-p="' + key + '" value="' + (val||'#000000') + '"><input type="text" class="pp-input" data-p="' + key + '" value="' + escHtml(val||'') + '" style="flex:1;" placeholder="#rrggbb"></div>';
  const sel    = (key, val, opts) => '<select class="pp-input" data-p="' + key + '">' + opts.map(([v,l]) => '<option value="'+v+'"'+(v===val?' selected':'')+'>'+l+'</option>').join('') + '</select>';
  const aligns = (key, cur) => '<div class="pp-row">' + ppAlignBtns(key, cur) + '</div>';
  const pad    = () => '<div class="pp-row">' + num('padV', d.padV||'16', 0, 80) + '<span class="text-xs text-slate-500">V</span>' + num('padH', d.padH||'28', 0, 80) + '<span class="text-xs text-slate-500">H</span></div>';

  let h = '';

  if (b.type==='logo') {
    h += row('Logo URL', txt('src', d.src, 'https://... or paste from library'));
    h += row('Alt Text', txt('alt', d.alt, 'Company name'));
    h += row('Width px', num('width', d.width, 40, 400));
    h += row('Corner Radius', num('radius', d.radius, 0, 50));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='heading') {
    h += row('Text', txt('text', d.text));
    h += row('Level', sel('level', d.level, [['h1','H1'],['h2','H2'],['h3','H3'],['h4','H4']]));
    h += row('Size', num('size', d.size, 10, 80));
    h += row('Weight', sel('weight', d.weight, [['400','Normal'],['600','Semi-bold'],['700','Bold'],['800','Extra-bold']]));
    h += row('Color', clr('color', d.color));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='text') {
    h += row('Size', num('size', d.size, 10, 40));
    h += row('Line Height', num('lh', d.lh, 1, 3, 0.1));
    h += row('Color', clr('color', d.color));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='image') {
    h += row('Image URL', txt('src', d.src, 'https://... or paste from library'));
    h += row('Alt Text', txt('alt', d.alt, 'Describe the image'));
    h += row('Width %', num('width', d.width, 10, 100));
    h += row('Corner Radius', num('radius', d.radius, 0, 50));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='video') {
    h += row('Thumbnail URL', txt('thumb', d.thumb, 'https://... thumbnail image'));
    h += row('Video URL', txt('videoUrl', d.videoUrl, 'https://youtube.com/...'));
    h += row('Alt Text', txt('alt', d.alt));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='button') {
    h += row('Button Text', txt('text', d.text));
    h += row('Link URL', txt('href', d.href, 'https://'));
    h += row('Background', clr('bg', d.bg));
    h += row('Text Color', clr('fg', d.fg));
    h += row('Size', num('size', d.size, 10, 28));
    h += row('Bold', sel('bold', d.bold, [['1','Yes'],['0','No']]));
    h += row('Border Radius', num('radius', d.radius, 0, 50));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='list') {
    h += row('Items (one per line)', '<textarea class="pp-input" data-p="items" rows="5" style="resize:vertical;font-size:.78rem;">' + escHtml(d.items) + '</textarea>');
    h += row('Style', sel('style', d.style, [['ul','Bullet'],['ol','Numbered'],['check','Checkmarks ✅']]));
    h += row('Color', clr('color', d.color));
    h += row('Size', num('size', d.size, 10, 30));
  } else if (b.type==='alert') {
    h += row('Message', txt('text', d.text));
    h += row('Type', sel('type', d.type, [['info','ℹ️ Info'],['success','✅ Success'],['warning','⚠️ Warning'],['danger','🚨 Danger']]));
  } else if (b.type==='badge') {
    h += row('Label Text', txt('text', d.text));
    h += row('Background', clr('bg', d.bg));
    h += row('Text Color', clr('fg', d.fg));
    h += row('Size', num('size', d.size, 8, 24));
    h += row('Border Radius', num('radius', d.radius, 0, 50));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='quote') {
    h += row('Quote Text', '<textarea class="pp-input" data-p="text" rows="3" style="resize:vertical;">' + escHtml(d.text) + '</textarea>');
    h += row('Author', txt('author', d.author, 'Name, Title'));
    h += row('Text Color', clr('color', d.color));
    h += row('Border Color', clr('borderColor', d.borderColor));
  } else if (b.type==='countdown') {
    h += row('Label', txt('label', d.label));
    h += row('End Date', '<input type="datetime-local" class="pp-input" data-p="endDate" value="' + escHtml(d.endDate||'') + '">');
    h += row('Number Color', clr('color', d.color));
    h += row('BG Color', clr('bg', d.bg));
  } else if (b.type==='social') {
    h += row('Twitter / X',  txt('twitter',   d.twitter,   'https://twitter.com/...'));
    h += row('Instagram',    txt('instagram', d.instagram, 'https://instagram.com/...'));
    h += row('LinkedIn',     txt('linkedin',  d.linkedin,  'https://linkedin.com/...'));
    h += row('Facebook',     txt('facebook',  d.facebook,  'https://facebook.com/...'));
    h += row('YouTube',      txt('youtube',   d.youtube,   'https://youtube.com/...'));
    h += row('Button Color', clr('color', d.color));
    h += row('Align', aligns('align', d.align));
  } else if (b.type==='columns') {
    h += row('Column Gap px', num('gap', d.gap, 0, 60));
    h += '<p class="text-xs text-slate-500 mt-1">Click either column on the canvas to edit inline.</p>';
  } else if (b.type==='columns3') {
    h += row('Column Gap px', num('gap', d.gap, 0, 60));
    h += '<p class="text-xs text-slate-500 mt-1">Click any column on the canvas to edit inline.</p>';
  } else if (b.type==='footer') {
    h += row('Company Name', txt('company', d.company));
    h += row('Address', txt('address', d.address));
    h += row('Unsubscribe URL', txt('unsubUrl', d.unsubUrl, 'https://'));
    h += row('Text Color', clr('color', d.color));
  } else if (b.type==='html') {
    h += row('HTML Code', '<textarea class="pp-input" data-p="code" rows="8" style="font-family:monospace;font-size:.72rem;resize:vertical;">' + escHtml(d.code) + '</textarea>');
  } else if (b.type==='divider') {
    h += row('Color', clr('color', d.color));
    h += row('Thickness px', num('thick', d.thick, 1, 12));
    h += row('Spacing px', num('my', d.my, 0, 80));
  } else if (b.type==='spacer') {
    h += row('Height px', num('h', d.h, 4, 200));
  }

  // padding + block BG — always
  if (!['divider','spacer'].includes(b.type)) {
    h += '<hr class="pp-sep">';
    h += row('Padding (V / H)', pad());
  }
  h += '<div class="pp-section" style="border-top:1px solid rgba(255,255,255,.06);padding-top:.8rem;margin-top:.4rem;">';
  h += '<label class="pp-label">Block Background</label>';
  h += '<div class="pp-row">';
  h += '<input type="color" class="pp-color" data-p="blockBg" value="' + (d.blockBg||'#ffffff') + '">';
  h += '<input type="text"  class="pp-input" data-p="blockBg" value="' + escHtml(d.blockBg||'') + '" style="flex:1;" placeholder="transparent">';
  h += '<button type="button" class="pp-btn" style="flex:0 0 auto;padding:5px 8px;" onclick="applyProp(\'blockBg\',\'\');syncPP()" title="Clear"><i class="fa-solid fa-xmark"></i></button>';
  h += '</div></div>';

  return h;
}

// ═══ AUDIENCE ════════════════════════════════
document.getElementById('aud-btns').addEventListener('click', e => {
  const btn = e.target.closest('.aud-pill'); if (!btn) return;
  document.querySelectorAll('.aud-pill').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById('custom-box').classList.toggle('hidden', btn.dataset.v !== 'custom');
  const labels = { all:'All verified users', paid:'Pro + Entrepreneur users', pro:'Pro plan users', entrepreneur:'Entrepreneur plan users', free:'Free plan users', custom:'Custom list' };
  document.getElementById('pv-to-label').textContent = labels[btn.dataset.v] || 'All verified users';
});

function syncSubjectPreview() {
  const v = document.getElementById('s-subject').value.trim();
  document.getElementById('pv-subject-label').textContent = v || '— no subject —';
}

// ═══ SEND ════════════════════════════════════
function doSend() {
  if (!blocks.length) { alert('Add at least one block first.'); return; }
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

// ═══ PREVIEW ════════════════════════════════
function prevModal() {
  const m = document.getElementById('prev-modal');
  if (m.classList.contains('flex')) { closePrevModal(); return; }
  const subj = document.getElementById('s-subject').value.trim() || '(no subject)';
  // Render through the exact same email_wrapper template used at send time —
  // dark card, header logo, footer, real subject — so the preview is
  // pixel-identical to what lands in Gmail.
  const greeting = '<p style="margin:0 0 8px;color:#CBD5E1;">Hi there,</p>';
  const chrome = EB_CHROME
    .split('__EB_SUBJECT__').join(escAttr(subj))
    .split('__EB_BODY__').join(greeting + buildEmail());
  document.getElementById('pv-iframe').srcdoc = chrome;
  m.classList.replace('hidden','flex');
}
function closePrevModal() { document.getElementById('prev-modal').classList.replace('flex','hidden'); }
function setPrevMode(mode) {
  const f=document.getElementById('pv-frame'), d=document.getElementById('pv-d'), mb=document.getElementById('pv-m');
  if (mode==='mobile') { f.style.maxWidth='390px'; f.style.boxShadow='0 0 0 10px #1e293b,0 0 0 12px #475569'; f.style.borderRadius='20px'; d.classList.remove('on'); mb.classList.add('on'); }
  else                 { f.style.maxWidth='700px'; f.style.boxShadow='none'; f.style.borderRadius='12px'; mb.classList.remove('on'); d.classList.add('on'); }
}
document.getElementById('prev-modal').addEventListener('click', e => { if (e.target===e.currentTarget) closePrevModal(); });

// ═══ DRAWER ══════════════════════════════════
function openDrawer()  { document.getElementById('mob-drawer').classList.add('open'); }
function closeDrawer() { document.getElementById('mob-drawer').classList.remove('open'); }

// ═══ DRAFT ═══════════════════════════════════
const DRAFT_KEY = 'eb_draft_v4';
function saveDraft() {
  const draft = {
    blocks,
    canvasBg,
    subject: document.getElementById('s-subject').value,
    audience: document.querySelector('.aud-pill.on')?.dataset.v || 'all',
    width: document.getElementById('canvasWidthSel').value,
    ts: Date.now()
  };
  try { localStorage.setItem(DRAFT_KEY, JSON.stringify(draft)); } catch(e) { showToast('Draft too large for localStorage!'); return; }
  isDirty = false;
  const ind = document.getElementById('draft-indicator');
  ind.textContent = '✓ Saved ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
  ind.classList.add('saved');
  showToast('Draft saved!');
}
function loadDraft() {
  let draft;
  try { const s = localStorage.getItem(DRAFT_KEY); if (!s) { showToast('No draft found.'); return; } draft = JSON.parse(s); } catch(e) { showToast('Could not load draft.'); return; }
  const age = Math.round((Date.now() - draft.ts) / 60000);
  if (!confirm('Load draft from ' + age + ' minute(s) ago? Current canvas will be replaced.')) return;
  blocks   = draft.blocks || [];
  canvasBg = draft.canvasBg || '#ffffff';
  document.getElementById('canvasBgPicker').value = canvasBg;
  document.getElementById('canvasBgHex').value    = canvasBg;
  CANVAS.style.background = canvasBg;
  document.getElementById('eb-canvas-outer').style.background = canvasBg;
  if (draft.subject)  document.getElementById('s-subject').value = draft.subject;
  if (draft.width)    { document.getElementById('canvasWidthSel').value = draft.width; setWidth(draft.width); }
  if (draft.audience) {
    document.querySelectorAll('.aud-pill').forEach(p => p.classList.toggle('on', p.dataset.v===draft.audience));
  }
  selId = null; hist = []; fut = [];
  syncSubjectPreview(); render(); showToast('Draft loaded!');
}

// ═══ IMAGE LIBRARY ════════════════════════════
let imgLib = [];
try { const s = localStorage.getItem('eb_img_lib_v2'); if (s) imgLib = JSON.parse(s); } catch(e) {}

function handleImgFiles(files) {
  Array.from(files).forEach(f => {
    if (!f.type.startsWith('image/')) return;
    const r = new FileReader();
    r.onload = ev => addToLib(ev.target.result, f.name);
    r.readAsDataURL(f);
  });
}
function handleImgDrop(e) { e.preventDefault(); document.getElementById('img-uploader').classList.remove('ov'); handleImgFiles(e.dataTransfer.files); }
function addToLib(url, name) {
  imgLib.unshift({url, name});
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
  if (!imgLib.length) { grid.innerHTML=''; return; }
  let html = '<div class="img-thumb-grid">';
  imgLib.forEach((img,i) => {
    html += '<div class="img-thumb" onclick="copyImgUrl('+i+')" title="'+escAttr(img.name)+'">' +
            '<img src="'+img.url+'" alt="">' +
            '<div class="copy-tag"><i class="fa-solid fa-copy"></i><br>Copy URL</div>' +
            '<button class="del-btn" onclick="event.stopPropagation();removeFromLib('+i+')" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
            '</div>';
  });
  html += '</div><p class="text-xs text-slate-600 mt-3">Click any thumbnail to copy its URL &mdash; paste into any Image, Logo, or Video block.</p>';
  grid.innerHTML = html;
}
function copyImgUrl(i) {
  const url = imgLib[i].url;
  const fb  = () => { const ta=document.createElement('textarea'); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); showToast('URL copied!'); };
  if (navigator.clipboard) navigator.clipboard.writeText(url).then(()=>showToast('URL copied!')).catch(fb);
  else fb();
}
function showToast(msg) {
  let t = document.getElementById('eb-toast');
  if (!t) {
    t = document.createElement('div'); t.id='eb-toast';
    t.style.cssText = 'position:fixed;bottom:28px;left:50%;transform:translateX(-50%);background:#4f46e5;color:#fff;padding:10px 22px;border-radius:14px;font-size:.82rem;font-weight:600;z-index:9999;opacity:0;transition:opacity .2s;pointer-events:none;box-shadow:0 4px 20px rgba(79,70,229,.4);white-space:nowrap;';
    document.body.appendChild(t);
  }
  t.textContent = msg; t.style.opacity = '1';
  clearTimeout(t._t); t._t = setTimeout(()=>{ t.style.opacity='0'; }, 2500);
}

// ═══ INIT ════════════════════════════════════
(function init() {
  blocks = [
    { id:uid(), type:'logo',    data: clone(DEF.logo) },
    { id:uid(), type:'heading', data: { ...clone(DEF.heading), text:'Welcome to Utiligo 👋', color:'#111827', align:'center' } },
    { id:uid(), type:'text',    data: { ...clone(DEF.text),    text:'Thanks for being part of our community. Here\'s what\'s new.', align:'center' } },
    { id:uid(), type:'button',  data: clone(DEF.button) },
    { id:uid(), type:'divider', data: clone(DEF.divider) },
    { id:uid(), type:'footer',  data: clone(DEF.footer) },
  ];
  render();
  renderLib();
  updateCounters();
})();
</script>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
