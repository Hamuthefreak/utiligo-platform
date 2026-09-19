/* ==========================================================================
   Call script dock
   --------------------------------------------------------------------------
   One panel, every portal page. The point of the feature is that the words are
   already open while the phone is ringing, so this file is mostly about two
   things: SURVIVING NAVIGATION and getting out of the way.

   SURVIVING NAVIGATION
     Every portal page re-renders the panel, and every page load is a full
     document load — there is no client-side router here to keep a widget alive.
     So "persistent" is achieved by remembering everything that makes the panel
     itself: open/closed, position, size, which script was active, the font size,
     where each script was scrolled to, whether it is dimmed, and which window it
     lives in. A customer who drags it to the right edge, picks their opening
     script and scrolls to the pricing paragraph gets exactly that back after
     clicking through to Leads.

     A real separate window is offered as well, for the second-monitor case: that
     one genuinely survives navigation, and the two stay in step over
     BroadcastChannel (with a localStorage 'storage' listener as the fallback).

   GETTING OUT OF THE WAY
     Dim until hovered, collapse to a bar, close to a launcher, and a bottom
     sheet on a phone instead of a box you cannot reach across.

   No framework, no dependencies, and it never throws: a storage or network
   failure has to degrade to a panel that still shows the script text, because
   the customer may be mid-call.
   ========================================================================== */

(function () {
  'use strict';

  if (window.__utiligoCallScriptsLoaded) return;
  window.__utiligoCallScriptsLoaded = true;

  var CFG      = window.UTILIGO_CALL_SCRIPTS || {};
  var API      = CFG.api || '/api/call-scripts.php';
  var STANDALONE = !!CFG.standalone;   // rendered inside the pop-out window
  var KEY      = 'utiligo.callScripts.v1';
  var CHANNEL  = 'utiligo_call_scripts';

  var FIELDS = {};      // token key -> human label, from the API
  var KNOWN  = {};      // token key -> true, the set the API can fill
  var scripts = [];
  var cap = 200;
  var maxName = 120;
  var maxBody = 12000;

  var mode = 'view';        // view | switch | edit | new | import
  var editingId = null;
  var query = '';
  var cursor = -1;          // highlighted row while switching
  var busy = false;
  var blocked = false;      // API said plan_required
  var status = { msg: '', kind: '' };
  var statusTimer = null;
  var els = {};
  var drag = null;
  var resize = null;
  var applying = false;     // we are writing state received from elsewhere
  var bc = null;

  /* ── State ─────────────────────────────────────────────────────────────── */

  var DEFAULTS = {
    open: false, collapsed: false, dim: false,
    x: null, y: null, w: 380, h: 470,
    activeId: null, font: 13,
    scroll: {},                 // script id -> scrollTop, restored on switch
    popped: false
  };

  function loadState() {
    var s = {};
    for (var k in DEFAULTS) if (Object.prototype.hasOwnProperty.call(DEFAULTS, k)) s[k] = DEFAULTS[k];
    try {
      var raw = window.localStorage.getItem(KEY);
      if (raw) {
        var got = JSON.parse(raw);
        if (got && typeof got === 'object') {
          for (var k2 in DEFAULTS) {
            if (Object.prototype.hasOwnProperty.call(got, k2) && got[k2] !== null && got[k2] !== undefined) {
              s[k2] = got[k2];
            }
          }
        }
      }
    } catch (e) { /* private mode, quota, corrupt JSON: defaults are fine */ }
    return s;
  }

  var S = loadState();

  function saveState(broadcastIt) {
    try { window.localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {}
    if (broadcastIt !== false) post({ type: 'state', data: { activeId: S.activeId, font: S.font } });
  }

  function post(msg) {
    if (bc) { try { bc.postMessage(msg); } catch (e) {} }
  }

  /* ── Small helpers ─────────────────────────────────────────────────────── */

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function isMobile() {
    try { return window.matchMedia('(max-width: 767px)').matches; } catch (e) { return false; }
  }

  function activeScript() {
    for (var i = 0; i < scripts.length; i++) {
      if (String(scripts[i].id) === String(S.activeId)) return scripts[i];
    }
    return scripts.length ? scripts[0] : null;
  }

  function setStatus(msg, kind) {
    status = { msg: msg || '', kind: kind || '' };
    if (statusTimer) { clearTimeout(statusTimer); statusTimer = null; }
    paintStatus();
    if (msg && kind !== 'warn') {
      statusTimer = setTimeout(function () { status = { msg: '', kind: '' }; paintStatus(); }, 4000);
    }
  }

  function paintStatus() {
    if (!els.status) return;
    els.status.textContent = status.msg;
    els.status.className = 'cs-status' + (status.kind ? ' is-' + status.kind : '');
  }

  /**
   * The lead the page currently has open, if any.
   *
   * Only the leads workspace publishes this, and only while a lead's panel is
   * open. Absent is the normal case: the dock spends most of its life on pages
   * that have no idea which lead you are about to call, and it must not guess.
   */
  function currentLead() {
    var l = window.UTILIGO_CURRENT_LEAD;
    return (l && typeof l === 'object') ? l : null;
  }

  /**
   * Substitute {{placeholders}}, exactly as includes/call_scripts.php does.
   *
   * A token with no value is left standing rather than blanked — the panel
   * highlights it so the gap is visible before the call, not during it.
   */
  function fill(text) {
    var lead = currentLead() || {};
    var extra = { sender_name: String(CFG.senderName || '') };
    var leadOnly = false;
    var filled = [], missing = [];

    var out = String(text || '').replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, function (m, rawKey) {
      var key = String(rawKey).toLowerCase();
      if (!KNOWN[key]) { if (missing.indexOf(key) < 0) missing.push(key); return m; }

      var v = '';
      if (lead[key] !== undefined && lead[key] !== null) v = String(lead[key]).trim();
      if (!v && extra[key] !== undefined) v = String(extra[key]).trim();
      // Anything filled from the account rather than the lead is not evidence
      // that a lead is open, so it must not be described as one.
      if (key !== 'sender_name') leadOnly = true;

      if (v) { if (filled.indexOf(key) < 0) filled.push(key); return v; }
      if (missing.indexOf(key) < 0) missing.push(key);
      return m;
    });

    return { text: out, filled: filled, missing: missing, fromLead: leadOnly };
  }

  /** Filled text with the surviving tokens marked up. Escapes everything else. */
  function scriptHtml(filledText) {
    var out = '', re = /\{\{\s*([a-z_]+)\s*\}\}/gi, last = 0, m;
    while ((m = re.exec(filledText))) {
      out += esc(filledText.slice(last, m.index));
      out += '<span class="cs-slot" title="No value available for this — check your profile or open the lead first">'
           + esc(m[0]) + '</span>';
      last = m.index + m[0].length;
    }
    out += esc(filledText.slice(last));
    return out;
  }

  function relTime(value) {
    if (!value) return '';
    var t = Date.parse(String(value).replace(' ', 'T'));
    if (isNaN(t)) return '';
    var days = Math.floor((Date.now() - t) / 86400000);
    if (days <= 0) return 'today';
    if (days === 1) return 'yesterday';
    if (days < 30) return days + 'd ago';
    return Math.floor(days / 30) + 'mo ago';
  }

  /* ── API ───────────────────────────────────────────────────────────────── */

  function api(op, payload, onDone) {
    if (busy) return;
    if (blocked) { if (onDone) onDone({ success: false, error: 'plan_required' }); return; }
    busy = true;

    // A request that never settles would leave `busy` true and silently disable
    // every later action for the life of the page — on a panel the customer may
    // be using mid-call, so it gets a deadline.
    var ctl = null;
    var timedOut = false;
    try {
      ctl = new AbortController();
      setTimeout(function () { timedOut = true; try { ctl.abort(); } catch (e) {} }, 15000);
    } catch (e) { ctl = null; }

    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ op: op, csrf_token: CSRF() }, payload || {})),
      signal: ctl ? ctl.signal : undefined
    })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: 'bad_response' }; }); })
      .then(function (j) {
        busy = false;
        if (j && j.error === 'plan_required') {
          blocked = true;
          setStatus('Call scripts are a Pro feature.', 'warn');
          render();
          if (onDone) onDone(j);
          return;
        }
        if (onDone) onDone(j || { success: false, error: 'bad_response' });
      })
      .catch(function () {
        busy = false;
        setStatus(timedOut
          ? 'That took too long. Your scripts are still here — try again.'
          : 'Could not reach the server. Your scripts are still here.', 'warn');
        if (onDone) onDone({ success: false, error: timedOut ? 'timeout' : 'network' });
      });
  }

  function CSRF() {
    var b = document.body;
    var t = (b && (b.getAttribute('data-csrf') || (b.dataset ? b.dataset.csrf : ''))) || '';
    return String(t);
  }

  var ERROR_COPY = {
    plan_required:   'Call scripts are part of the Pro plan.',
    cap_reached:     'You have reached the script limit.',
    invalid_name:    'Give the script a name.',
    invalid_body:    'The script needs some text.',
    invalid_format:  'No scripts found. Separate each one with a line of --- and put its name on the first line.',
    invalid_blob:    'Paste something first.',
    not_found:       'That script no longer exists.',
    rate_limited:    'Too many changes at once. Try again in a moment.',
    network:         'Could not reach the server.',
    timeout:         'The server took too long to answer.',
    invalid_csrf:    'Your session expired. Reload the page and try again.'
  };

  function errCopy(code) {
    return ERROR_COPY[code] || 'That did not work. Please try again.';
  }

  /* ── Build ─────────────────────────────────────────────────────────────── */

  function build() {
    if (document.getElementById('csDock')) return;

    var launcher = document.createElement('button');
    launcher.type = 'button';
    launcher.id = 'csLauncher';
    launcher.className = 'cs-launcher';
    launcher.setAttribute('aria-label', 'Open call scripts');
    launcher.innerHTML = '<i class="fa-solid fa-phone-volume" aria-hidden="true"></i>'
      + '<span>Call scripts</span><kbd>Alt S</kbd>';
    launcher.addEventListener('click', function () { setOpen(true); });

    var dock = document.createElement('div');
    dock.id = 'csDock';
    dock.className = 'cs-dock';
    // Non-modal on purpose: the whole point is that it sits alongside the page
    // you are working in, so it must never trap focus or block clicks through.
    dock.setAttribute('role', 'dialog');
    dock.setAttribute('aria-modal', 'false');
    dock.setAttribute('aria-label', 'Call scripts');
    dock.innerHTML =
      '<div class="cs-head" id="csHead">'
        + '<span class="cs-dot" aria-hidden="true"></span>'
        + '<button type="button" class="cs-name-btn" id="csName" aria-haspopup="listbox" aria-expanded="false"></button>'
        + '<button type="button" class="cs-icon-btn" data-act="prev" aria-label="Previous script" title="Previous script (Alt ↑)"><i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>'
        + '<button type="button" class="cs-icon-btn" data-act="next" aria-label="Next script" title="Next script (Alt ↓)"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>'
        + '<button type="button" class="cs-icon-btn" data-act="collapse" aria-label="Collapse" aria-expanded="true" title="Collapse to a bar"><i class="fa-solid fa-window-minimize" aria-hidden="true"></i></button>'
        + '<button type="button" class="cs-icon-btn" data-act="close" aria-label="Close call scripts" title="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>'
      + '</div>'
      + '<div class="cs-body" id="csBody"></div>'
      + '<div class="cs-foot" id="csFoot">'
        + '<span class="cs-status" id="csStatus" role="status" aria-live="polite"></span>'
        + '<span class="cs-spacer"></span>'
      + '</div>'
      + '<div class="cs-resize" id="csResize" aria-hidden="true" title="Drag to resize"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></div>';

    els.launcher = launcher;
    els.dock     = dock;
    els.head     = dock.querySelector('#csHead');
    els.name     = dock.querySelector('#csName');
    els.body     = dock.querySelector('#csBody');
    els.foot     = dock.querySelector('#csFoot');
    els.status   = dock.querySelector('#csStatus');
    els.resize   = dock.querySelector('#csResize');

    if (STANDALONE) {
      // Inside the pop-out window the panel IS the page: no launcher, no drag,
      // no collapse, no close — closing the window is the close.
      dock.classList.add('is-full');
      document.body.appendChild(dock);
      els.head.querySelector('[data-act="collapse"]').style.display = 'none';
      els.head.querySelector('[data-act="close"]').style.display = 'none';
      els.resize.style.display = 'none';
    } else {
      document.body.appendChild(launcher);
      document.body.appendChild(dock);
    }

    els.head.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-act]') : null;
      if (btn) { onHeadAction(btn.getAttribute('data-act')); return; }
      if (e.target.closest && e.target.closest('#csName')) { openSwitch(); }
    });

    els.foot.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-act]') : null;
      if (btn) onFootAction(btn.getAttribute('data-act'));
    });

    // Delegated: the body is re-rendered wholesale, so listeners on it would be
    // thrown away with the children.
    els.body.addEventListener('click', onBodyClick);

    wireDrag();
    wireResize();
    wireKeys();
    wireSync();
    wireLeadContext();

    window.addEventListener('resize', function () { place(); });
    document.addEventListener('visibilitychange', function () {
      // Coming back to the tab is the moment a change made in the pop-out window
      // most needs to appear here.
      if (!document.hidden && S.open && !busy && (mode === 'view' || mode === 'switch')) refresh();
    });
  }

  function onHeadAction(act) {
    if (act === 'prev') goto(-1);
    else if (act === 'next') goto(1);
    else if (act === 'collapse') setCollapsed(!S.collapsed);
    else if (act === 'close') setOpen(false);
  }

  function onFootAction(act) {
    switch (act) {
      case 'copy':     copyActive(); break;
      case 'font-down': setFont(S.font - 1); break;
      case 'font-up':   setFont(S.font + 1); break;
      case 'dim':       S.dim = !S.dim; saveState(); render(); break;
      case 'pop':       popOut(); break;
      case 'edit':      startEdit(activeScript()); break;
      case 'new':       mode = 'new'; editingId = null; render(); focusFirst(); break;
      case 'import':    mode = 'import'; render(); focusFirst(); break;
      case 'back':      mode = 'view'; render(); break;
      case 'switch':    openSwitch(); break;
      case 'save':      saveForm(); break;
      case 'delete':    deleteActive(); break;
      case 'refresh':   refresh(); break;
    }
  }

  function onBodyClick(e) {
    var t = e.target;
    var row = t.closest ? t.closest('[data-row-id]') : null;
    if (!row) return;
    if (t.closest('[data-row-edit]')) {
      var id = row.getAttribute('data-row-id');
      for (var i = 0; i < scripts.length; i++) {
        if (String(scripts[i].id) === String(id)) { startEdit(scripts[i]); return; }
      }
      return;
    }
    selectScript(row.getAttribute('data-row-id'));
  }

  /* ── Open / collapse / placement ───────────────────────────────────────── */

  function setOpen(open) {
    S.open = !!open;
    if (S.open && !scripts.length && !blocked) refresh();
    // render() BEFORE saveState(): place() is what computes a first-run position,
    // so saving first would persist x/y as null and the panel would recompute its
    // corner on every page load instead of remembering where it was put.
    render();
    saveState();
    if (S.open) {
      // Move focus to the panel so Escape and the arrows work immediately.
      try { els.name.focus({ preventScroll: true }); } catch (e) { els.name.focus(); }
    }
  }

  function setCollapsed(collapsed) {
    S.collapsed = !!collapsed;
    // Same order as setOpen, and for the same reason — the collapsed bar and the
    // open panel are different heights, so place() re-clamps y here too.
    render();
    saveState();
  }

  function setFont(px) {
    S.font = Math.max(11, Math.min(22, px));
    render();
    saveState();
  }

  function place() {
    if (!els.dock) return;
    // In the pop-out window the panel fills the viewport; size and position are
    // the window's business, and writing them inline would fight the stylesheet.
    if (STANDALONE || isMobile()) return;

    var w = Math.max(300, Math.min(S.w, window.innerWidth - 16));
    var h = Math.max(200, Math.min(S.h, window.innerHeight - 16));

    els.dock.style.width = w + 'px';
    // A collapsed panel is a bar whose height belongs to its content. An inline
    // height here would pin open an empty box — which is exactly what it did:
    // the class was applied, the body was emptied, and the panel stayed 470px
    // tall on desktop and 62vh on a phone.
    els.dock.style.height = S.collapsed ? '' : h + 'px';

    // Measured after the write, so the clamp uses the height the browser is
    // actually rendering (the bar and the panel are very different).
    var rect = els.dock.getBoundingClientRect();
    var rh = rect.height > 0 ? rect.height : h;

    if (S.x === null || S.y === null) {
      // First ever open: right-hand side, vertically centred. Deliberately NOT
      // the bottom-right corner, which several portal pages already use for a
      // call-to-action button — landing the panel on top of one on the very
      // first open is a bad first impression of a panel you can drag anywhere.
      S.x = window.innerWidth - w - 18;
      S.y = Math.round((window.innerHeight - rh) / 2);
    }
    S.x = Math.max(8, Math.min(S.x, Math.max(8, window.innerWidth - w - 8)));
    S.y = Math.max(8, Math.min(S.y, Math.max(8, window.innerHeight - rh - 8)));

    els.dock.style.left = S.x + 'px';
    els.dock.style.top  = S.y + 'px';
  }

  /* ── Drag and resize ───────────────────────────────────────────────────── */

  function wireDrag() {
    els.head.addEventListener('pointerdown', function (e) {
      if (STANDALONE || isMobile() || S.collapsed) return;
      if (e.target.closest && e.target.closest('button')) return; // buttons stay buttons
      var r = els.dock.getBoundingClientRect();
      drag = { dx: e.clientX - r.left, dy: e.clientY - r.top, id: e.pointerId };
      els.dock.classList.add('is-dragging');
      try { els.head.setPointerCapture(e.pointerId); } catch (err) {}
    });

    els.head.addEventListener('pointermove', function (e) {
      if (!drag) return;
      S.x = e.clientX - drag.dx;
      S.y = e.clientY - drag.dy;
      place();
    });

    function end(e) {
      if (!drag) return;
      drag = null;
      els.dock.classList.remove('is-dragging');
      try { els.head.releasePointerCapture(e.pointerId); } catch (err) {}
      saveState();
    }
    els.head.addEventListener('pointerup', end);
    els.head.addEventListener('pointercancel', end);
  }

  function wireResize() {
    els.resize.addEventListener('pointerdown', function (e) {
      if (STANDALONE || isMobile()) return;
      var r = els.dock.getBoundingClientRect();
      resize = { x: e.clientX, y: e.clientY, w: r.width, h: r.height, id: e.pointerId };
      els.dock.classList.add('is-dragging');
      try { els.resize.setPointerCapture(e.pointerId); } catch (err) {}
      e.preventDefault();
    });

    els.resize.addEventListener('pointermove', function (e) {
      if (!resize) return;
      S.w = Math.round(Math.max(300, Math.min(resize.w + (e.clientX - resize.x), window.innerWidth - 16)));
      S.h = Math.round(Math.max(200, Math.min(resize.h + (e.clientY - resize.y), window.innerHeight - 16)));
      place();
    });

    function end(e) {
      if (!resize) return;
      resize = null;
      els.dock.classList.remove('is-dragging');
      try { els.resize.releasePointerCapture(e.pointerId); } catch (err) {}
      saveState();
    }
    els.resize.addEventListener('pointerup', end);
    els.resize.addEventListener('pointercancel', end);
  }

  /* ── Keyboard ──────────────────────────────────────────────────────────── */

  function wireKeys() {
    document.addEventListener('keydown', function (e) {
      if (!e.altKey || e.ctrlKey || e.metaKey) {
        // Escape belongs to whatever is focused inside the panel.
        if (e.key === 'Escape' && S.open && els.dock && els.dock.contains(document.activeElement)) {
          if (mode !== 'view') { mode = 'view'; render(); }
          else if (!S.collapsed) { setCollapsed(true); }
          else { setOpen(false); }
        }
        return;
      }

      // A <select> uses Alt+Arrow to open itself.
      if (e.target && e.target.tagName === 'SELECT') return;

      // While editing, the arrows are editing keys.
      var editing = (mode === 'edit' || mode === 'new' || mode === 'import');

      if (e.key === 'ArrowDown' && !editing) { e.preventDefault(); goto(1); return; }
      if (e.key === 'ArrowUp' && !editing)   { e.preventDefault(); goto(-1); return; }
      if ((e.key === 's' || e.key === 'S' || e.key === '`') && !editing) { e.preventDefault(); setOpen(!S.open); return; }
      if ((e.key === 'c' || e.key === 'C') && !editing) { e.preventDefault(); copyActive(); }
    });
  }

  /* ── Sync with the pop-out window ──────────────────────────────────────── */

  function wireSync() {
    try {
      if (typeof BroadcastChannel === 'function') {
        bc = new BroadcastChannel(CHANNEL);
        bc.onmessage = function (ev) { onSync(ev.data); };
      }
    } catch (e) { bc = null; }

    // Fallback and backstop: another window writing the same key fires here.
    window.addEventListener('storage', function (ev) {
      if (!ev || ev.key !== KEY) return;
      var wasOpen = S.open;
      S = loadState();
      if (!STANDALONE) S.open = wasOpen || S.open;
      render();
    });
  }

  function onSync(msg) {
    if (!msg || msg.type !== 'state') return;
    if (msg.data && msg.data.activeId !== undefined && String(msg.data.activeId) !== String(S.activeId)) {
      S.activeId = msg.data.activeId;
    }
    if (msg.data && msg.data.font !== undefined) S.font = msg.data.font;
    applying = true;
    render();
    applying = false;
  }

  function popOut() {
    var w = null;
    try {
      w = window.open('/portal/call-scripts-window.php', 'utiligo_call_scripts',
                      'width=430,height=660,menubar=no,toolbar=no,location=no,status=no');
    } catch (e) { w = null; }

    if (!w) { setStatus('Your browser blocked the pop-out window.', 'warn'); return; }

    S.popped = true;
    saveState();
    render();

    // The pop-out is a separate document with its own copy of this script, so
    // there is no onunload to rely on — the only honest signal that it is gone
    // is that the handle reports closed.
    var t = setInterval(function () {
      if (!w || w.closed) { clearInterval(t); S.popped = false; saveState(); render(); }
    }, 1500);
  }

  /**
   * Re-render when the lead the workspace has open changes.
   *
   * Only the leads page publishes a lead, and only while its panel is open. The
   * moment one opens, every placeholder in the visible script has a real value,
   * so the panel is repainted immediately rather than at the next switch — the
   * whole point is that the words are right the instant you reach for them.
   */
  function wireLeadContext() {
    function repaint() {
      if (S.open && !S.collapsed && mode === 'view') render();
    }
    window.addEventListener('utiligo:lead-open', repaint);
    window.addEventListener('utiligo:lead-close', repaint);
  }

  /* ── Data ──────────────────────────────────────────────────────────────── */

  function refresh(onDone) {
    api('list', {}, function (j) {
      if (!j || !j.success) {
        if (j && j.error) setStatus(errCopy(j.error), 'warn');
        if (onDone) onDone(false);
        return;
      }
      scripts = Array.isArray(j.scripts) ? j.scripts : [];
      FIELDS  = j.fields && typeof j.fields === 'object' ? j.fields : {};
      KNOWN   = {};
      for (var k in FIELDS) if (Object.prototype.hasOwnProperty.call(FIELDS, k)) KNOWN[k] = true;
      cap     = j.cap || cap;
      maxName = j.max_name || maxName;
      maxBody = j.max_body || maxBody;

      // Keep the same script in front across a refresh; fall back to the first.
      if (!activeScript()) S.activeId = scripts.length ? scripts[0].id : null;

      if (j.seeded) setStatus('Three starter scripts added — edit them to sound like you.');
      render();
      if (onDone) onDone(true);
    });
  }

  function indexOfScript(id) {
    for (var i = 0; i < scripts.length; i++) {
      if (String(scripts[i].id) === String(id)) return i;
    }
    return -1;
  }

  function goto(delta) {
    if (!scripts.length) return;
    var open = S.open;
    if (!open) { setOpen(true); return; }

    rememberScroll();
    var i = indexOfScript(S.activeId);
    if (i < 0) i = 0;
    i = (i + delta + scripts.length) % scripts.length;
    S.activeId = scripts[i].id;
    if (mode !== 'view') mode = 'view';
    saveState();
    render();
    setStatus('');
  }

  function selectScript(id) {
    rememberScroll();
    S.activeId = id;
    mode = 'view';
    saveState();
    render();
  }

  function rememberScroll() {
    if (!els.scroll || !S.activeId) return;
    S.scroll[String(S.activeId)] = els.scroll.scrollTop;
    // Bounded so a long-lived session cannot grow the blob forever.
    var keys = Object.keys(S.scroll);
    if (keys.length > 60) delete S.scroll[keys[0]];
    saveState(false);
  }

  function restoreScroll() {
    if (!els.scroll) return;
    var top = S.scroll[String(S.activeId)] || 0;
    els.scroll.scrollTop = top;
  }

  /* ── Actions ───────────────────────────────────────────────────────────── */

  function copyActive() {
    var s = activeScript();
    if (!s) { setStatus('Add a script first.', 'warn'); return; }

    var text = fill(s.body).text;
    copyText(text).then(function (ok) {
      if (!ok) { setStatus('Could not copy — select the text and copy manually.', 'warn'); return; }
      s.times_used = (s.times_used || 0) + 1;
      setStatus('Copied. ' + (s.times_used > 1 ? 'Used ' + s.times_used + ' times.' : ''), 'good');
      api('touch', { id: s.id });   // fire and forget; the copy already happened
    });
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
    }
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', 'readonly');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      document.body.appendChild(ta);
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return Promise.resolve(!!ok);
    } catch (e) {
      return Promise.resolve(false);
    }
  }

  function startEdit(script) {
    if (!script) { setStatus('Nothing to edit yet.', 'warn'); return; }
    editingId = script.id;
    mode = 'edit';
    render();
    focusFirst();
  }

  function focusFirst() {
    var el = els.body.querySelector('input, textarea');
    if (el) { try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } }
  }

  function saveForm() {
    var nameEl = els.body.querySelector('#csFormName');
    var bodyEl = els.body.querySelector('#csFormBody');
    var blobEl = els.body.querySelector('#csFormBlob');

    if (mode === 'import') {
      var blob = blobEl ? blobEl.value : '';
      if (!blob.trim()) { setStatus(errCopy('invalid_blob'), 'warn'); return; }
      api('import', { blob: blob }, function (j) {
        if (!j || !j.success) { setStatus(errCopy(j && j.error), 'warn'); return; }
        mode = 'view';
        refresh(function () {
          var msg = j.created + (j.created === 1 ? ' script added.' : ' scripts added.');
          if (j.dropped) msg += ' ' + j.dropped + ' did not fit the limit.';
          if (j.skipped) msg += ' ' + j.skipped + ' block' + (j.skipped === 1 ? '' : 's') + ' skipped (no text).';
          setStatus(msg, j.dropped ? 'warn' : 'good');
        });
      });
      return;
    }

    var name = nameEl ? nameEl.value : '';
    var body = bodyEl ? bodyEl.value : '';

    var payload = { name: name, body: body };
    var op = 'create';
    if (mode === 'edit' && editingId) { op = 'update'; payload.id = editingId; }

    api(op, payload, function (j) {
      if (!j || !j.success) { setStatus(errCopy(j && j.error), 'warn'); return; }
      if (op === 'create') S.activeId = j.id;
      mode = 'view';
      refresh(function () { setStatus(op === 'create' ? 'Script added.' : 'Saved.', 'good'); });
    });
  }

  function deleteActive() {
    var s = null;
    if (mode === 'edit' && editingId) {
      for (var i = 0; i < scripts.length; i++) if (String(scripts[i].id) === String(editingId)) s = scripts[i];
    } else {
      s = activeScript();
    }
    if (!s) return;

    // Destructive and irreversible, and it is one click away from Copy, so it
    // asks — naming the script, because "are you sure" about an unnamed thing
    // is how people delete the wrong one.
    if (!window.confirm('Delete "' + s.name + '"?\n\nThis cannot be undone.')) return;

    api('delete', { id: s.id }, function (j) {
      if (!j || !j.success) { setStatus(errCopy(j && j.error), 'warn'); return; }
      if (String(S.activeId) === String(s.id)) S.activeId = null;
      delete S.scroll[String(s.id)];
      mode = 'view';
      saveState();
      refresh(function () { setStatus('Deleted.', 'good'); });
    });
  }

  /** The scripts the current filter lets through, in order. */
  function visibleScripts() {
    var q = query.trim().toLowerCase();
    if (!q) return scripts.slice();
    return scripts.filter(function (s) {
      return String(s.name).toLowerCase().indexOf(q) >= 0
          || String(s.body).toLowerCase().indexOf(q) >= 0;
    });
  }

  function openSwitch() {
    mode = 'switch';
    query = '';
    cursor = cursorOfActive();
    render();
    var s = els.body.querySelector('#csSearch');
    if (s) { try { s.focus({ preventScroll: true }); } catch (e) { s.focus(); } }
  }

  /* ── Render ────────────────────────────────────────────────────────────── */

  function render() {
    if (!els.dock) return;

    var open = STANDALONE ? true : S.open;
    // No launcher exists inside the pop-out window: the panel IS the window.
    if (els.launcher) els.launcher.style.display = (!open) ? '' : 'none';
    els.dock.style.display = open ? '' : 'none';
    if (!open) return;

    els.dock.classList.toggle('is-collapsed', !!S.collapsed);
    els.dock.classList.toggle('is-dim', !!S.dim);

    var s = activeScript();

    if (S.collapsed) {
      els.name.textContent = '';
      els.name.innerHTML = '<i class="fa-regular fa-paper-plane" aria-hidden="true"></i> '
        + esc(s ? s.name : 'Call scripts');
      els.name.setAttribute('aria-expanded', 'false');
      els.body.innerHTML = '';
      els.foot.innerHTML = '<span class="cs-status" id="csStatus" role="status" aria-live="polite"></span>';
      els.status = els.foot.querySelector('#csStatus');
      paintStatus();
      place();
      return;
    }

    els.name.innerHTML = esc(s ? s.name : (blocked ? 'Call scripts' : 'Loading…'))
      + ' <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
    els.name.setAttribute('aria-expanded', mode === 'switch' ? 'true' : 'false');

    renderBody(s);
    renderFoot(s);
    place();
  }

  function renderBody(s) {
    if (blocked) {
      els.body.innerHTML = '<div class="cs-scroll"><p class="cs-empty">'
        + 'Call scripts are part of the Pro plan.<br><br>'
        + '<a class="cs-btn is-primary" href="/portal/billing?upgrade=1&feature=call_scripts">See the plans</a>'
        + '</p></div>';
      return;
    }

    if (mode === 'switch')  { els.body.innerHTML = switchHtml(); wireSwitch(); return; }
    if (mode === 'edit' || mode === 'new') { els.body.innerHTML = formHtml(s); return; }
    if (mode === 'import')  { els.body.innerHTML = importHtml(); return; }

    els.body.innerHTML = viewHtml(s);
    els.scroll = els.body.querySelector('#csScroll');
    restoreScroll();
  }

  function viewHtml(s) {
    if (!s) {
      return '<div class="cs-scroll"><p class="cs-empty">'
        + 'No scripts yet.<br>Add one, or paste several at once.'
        + '</p></div>';
    }

    var f = fill(s.body);
    var lead = currentLead();
    var note = '';

    if (f.missing.length) {
      // Loud, and before the call: an unfilled slot read aloud is worse than a
      // sentence the customer knows they have to improvise.
      note = '<p class="cs-note is-warn"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>'
        + '<span>' + f.missing.length + ' placeholder' + (f.missing.length === 1 ? '' : 's')
        + ' could not be filled: ' + esc(f.missing.join(', '))
        + (lead ? '' : ' — open a lead, or fill in your profile') + '</span></p>';
    } else if (lead && f.filled.length) {
      note = '<p class="cs-note"><i class="fa-solid fa-user-check" aria-hidden="true"></i>'
        + '<span>Filled from ' + esc(lead.business_name || 'the open lead') + '</span></p>';
    } else if (s.times_used > 0) {
      note = '<p class="cs-note"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>'
        + '<span>Used ' + s.times_used + ' time' + (s.times_used === 1 ? '' : 's')
        + (s.last_used_at ? ' — last ' + esc(relTime(s.last_used_at)) : '') + '</span></p>';
    }

    return '<div class="cs-scroll" id="csScroll" tabindex="0" aria-label="Script text">'
      + '<div class="cs-script" style="font-size:' + S.font + 'px">' + scriptHtml(f.text) + '</div>'
      + note
      + '</div>';
  }

  /** Where the active script sits among the rows currently on screen. */
  function cursorOfActive() {
    var vis = visibleScripts();
    for (var i = 0; i < vis.length; i++) {
      if (String(vis[i].id) === String(S.activeId)) return i;
    }
    return vis.length ? 0 : -1;
  }

  function switchHtml() {
    var vis  = visibleScripts();
    var rows = '';

    for (var i = 0; i < vis.length; i++) {
      var s = vis[i];
      var isActive = String(s.id) === String(S.activeId);
      var meta = s.times_used > 0 ? s.times_used + '×' : (relTime(s.last_used_at) || '');
      rows += '<div class="cs-row' + (isActive ? ' is-active' : '') + (i === cursor ? ' is-cursor' : '') + '"'
        + ' data-row-id="' + esc(s.id) + '" role="option" tabindex="-1"'
        + (isActive ? ' aria-selected="true"' : ' aria-selected="false"') + '>'
        + '<span class="cs-row-name">' + esc(s.name) + '</span>'
        + (meta ? '<span class="cs-row-meta">' + esc(meta) + '</span>' : '')
        + '<button type="button" class="cs-row-edit" data-row-edit aria-label="Edit ' + esc(s.name) + '" title="Edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>'
        + '</div>';
    }

    if (!vis.length) {
      rows = '<p class="cs-empty">' + (query.trim() ? 'No script matches that.' : 'No scripts yet.') + '</p>';
    }

    // The count is only shown once the list is long enough that the limit is a
    // real thing to know about — a "1 of 200" on a single script is noise.
    var footer = '';
    if (scripts.length >= 20) {
      footer = '<p class="cs-hint">' + scripts.length + ' of ' + cap + ' scripts</p>';
    }

    return '<div class="cs-scroll">'
      + '<input type="search" id="csSearch" class="cs-search" placeholder="Filter scripts…" '
      + 'aria-label="Filter scripts" value="' + esc(query) + '" autocomplete="off">'
      + '<div class="cs-list" id="csList" role="listbox" aria-label="Your scripts">' + rows + '</div>'
      + footer
      + '</div>';
  }

  function wireSwitch() {
    var input = els.body.querySelector('#csSearch');
    var list  = els.body.querySelector('#csList');
    if (!input || !list) return;

    input.addEventListener('input', function () {
      query = input.value;
      cursor = cursorOfActive();
      // Only the list is re-rendered: replacing the input would take the caret
      // with it, and filtering a list is exactly when you are typing.
      var tmp = document.createElement('div');
      tmp.innerHTML = switchHtml();
      var fresh = tmp.querySelector('#csList');
      list.innerHTML = fresh ? fresh.innerHTML : '';
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveCursor(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveCursor(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        var vis  = visibleScripts();
        var pick = vis[cursor] || vis[0];
        if (pick) selectScript(pick.id);
      }
    });
  }

  // The cursor indexes the *visible* rows, not the whole list — otherwise
  // filtering to two matches and pressing Enter selects whatever happened to
  // sit at that position in the unfiltered set.
  function moveCursor(delta) {
    var vis = visibleScripts();
    if (!vis.length) return;
    cursor = Math.max(0, Math.min(vis.length - 1, cursor + delta));

    var list = els.body.querySelector('#csList');
    if (!list) return;
    var rows = list.querySelectorAll('.cs-row');
    for (var i = 0; i < rows.length; i++) rows[i].classList.remove('is-cursor');
    if (rows[cursor]) {
      rows[cursor].classList.add('is-cursor');
      try { rows[cursor].scrollIntoView({ block: 'nearest' }); } catch (e) {}
    }
  }

  function formHtml(s) {
    var isNew = (mode === 'new') || !s;
    var name  = isNew ? '' : String(s.name);
    var body  = isNew ? '' : String(s.body);

    var hint = '';
    if (Object.keys(FIELDS).length) {
      var tokens = Object.keys(FIELDS).map(function (k) { return '<code>{{' + esc(k) + '}}</code>'; }).join(' ');
      hint = '<p class="cs-hint">Paste-in details: ' + tokens
        + '. They fill in from the lead you have open, and are left visible if there is nothing to fill them with.</p>';
    }

    return '<div class="cs-scroll">'
      + '<div class="cs-field"><label class="cs-label" for="csFormName">Name</label>'
      + '<input type="text" id="csFormName" class="cs-input" maxlength="' + maxName + '" '
      + 'value="' + esc(name) + '" placeholder="e.g. No website — first call"></div>'
      + '<div class="cs-field"><label class="cs-label" for="csFormBody">What you say</label>'
      + '<textarea id="csFormBody" class="cs-textarea" rows="14" maxlength="' + maxBody + '" '
      + 'placeholder="Hi, is this the owner of {{business_name}}?">' + esc(body) + '</textarea>'
      + hint + '</div>'
      + '</div>';
  }

  function importHtml() {
    return '<div class="cs-scroll">'
      + '<div class="cs-field"><label class="cs-label" for="csFormBlob">Paste several scripts</label>'
      + '<textarea id="csFormBlob" class="cs-textarea" rows="14" '
      + 'placeholder="No website&#10;&#10;Hi, is this the owner of {{business_name}}?…&#10;&#10;---&#10;&#10;Follow-up&#10;&#10;Hi, it is {{sender_name}}…"></textarea>'
      + '<p class="cs-hint">Separate each script with a line of <code>---</code>. The first line of each one is its name; everything after it is the script.</p>'
      + '</div>'
      + '</div>';
  }

  function renderFoot(s) {
    var html = '<span class="cs-status" id="csStatus" role="status" aria-live="polite"></span><span class="cs-spacer"></span>';

    if (mode === 'edit' || mode === 'new') {
      html += '<button type="button" class="cs-btn is-primary" data-act="save"><i class="fa-solid fa-check" aria-hidden="true"></i>Save</button>'
            + '<button type="button" class="cs-btn" data-act="back">Cancel</button>';
      if (mode === 'edit') {
        html += '<button type="button" class="cs-btn" data-act="delete" aria-label="Delete this script"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>';
      }
    } else if (mode === 'import') {
      html += '<button type="button" class="cs-btn is-primary" data-act="save"><i class="fa-solid fa-check" aria-hidden="true"></i>Add them</button>'
            + '<button type="button" class="cs-btn" data-act="back">Cancel</button>';
    } else if (mode === 'switch') {
      html += '<button type="button" class="cs-btn" data-act="new"><i class="fa-solid fa-plus" aria-hidden="true"></i>New</button>'
            + '<button type="button" class="cs-btn" data-act="import"><i class="fa-solid fa-paste" aria-hidden="true"></i>Paste several</button>'
            + '<button type="button" class="cs-btn" data-act="back">Done</button>';
    } else if (s) {
      html += '<button type="button" class="cs-btn is-primary" data-act="copy" title="Copy (Alt C)"><i class="fa-regular fa-copy" aria-hidden="true"></i>Copy</button>'
            + '<button type="button" class="cs-btn" data-act="new"><i class="fa-solid fa-plus" aria-hidden="true"></i>New</button>'
            + '<button type="button" class="cs-btn" data-act="switch">All scripts</button>'
            + '<span class="cs-spacer"></span>'
            + '<button type="button" class="cs-icon-btn" data-act="font-down" aria-label="Smaller text" title="Smaller text"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>'
            + '<button type="button" class="cs-icon-btn" data-act="font-up" aria-label="Larger text" title="Larger text"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>'
            + '<button type="button" class="cs-icon-btn' + (S.dim ? ' is-on' : '') + '" data-act="dim" aria-pressed="' + (S.dim ? 'true' : 'false') + '" aria-label="Fade the panel until you look at it" title="Fade until hovered"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>'
            + '<button type="button" class="cs-icon-btn' + (S.popped ? ' is-on' : '') + '" data-act="pop" aria-label="Open in its own window" title="Pop out into its own window"><i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i></button>';
    } else {
      html += '<button type="button" class="cs-btn is-primary" data-act="new"><i class="fa-solid fa-plus" aria-hidden="true"></i>New script</button>'
            + '<button type="button" class="cs-btn" data-act="import"><i class="fa-solid fa-paste" aria-hidden="true"></i>Paste several</button>'
            + '<button type="button" class="cs-btn" data-act="refresh">Reload</button>';
    }

    els.foot.innerHTML = html;
    els.status = els.foot.querySelector('#csStatus');
    paintStatus();
  }

  /* ── Boot ──────────────────────────────────────────────────────────────── */

  function boot() {
    if (!document.body) return;
    build();

    if (STANDALONE) {
      S.open = true;
      S.collapsed = false;
      render();
      refresh();
      return;
    }

    render();
    // Load before the first open so opening is instant and the panel is already
    // populated — one small request, and it is the difference between the panel
    // appearing and the panel appearing empty.
    if (CFG.enabled !== false) refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
