/**
 * assets/js/support.js
 *
 * The support bubble. Injected on every portal page from includes/portal_layout.php
 * so a customer who hits a wall can say so from wherever they are, without leaving
 * the page that broke.
 *
 * THREE VIEWS, ONE PANEL
 *   list     the customer's own tickets, newest activity first
 *   thread   one conversation, with a reply box
 *   compose  a new request
 *
 * THE DETAILS THAT DECIDE WHETHER IT IS USABLE
 * ───────────────────────────────────────────
 * • Server-rendered message bodies arrive as `body_html`, already escaped and
 *   linkified, so links inside a ticket are clickable while a pasted <script> is
 *   inert. This file never linkifies anything itself.
 * • Attachments are validated on the CLIENT for size, count and type before a byte
 *   is sent, so the common mistake is caught instantly — and validated again on the
 *   server, because the client is not a security boundary.
 * • A failed request keeps whatever the customer typed. Losing a carefully written
 *   bug report to a network blip is how someone decides not to report the next one.
 * • A ticket that is closed offers Reopen rather than a disabled box, because a
 *   customer who writes again has something to say.
 */
(function () {
  'use strict';

  var CFG = window.UTILIGO_SUPPORT || {};
  var API = CFG.api || '/api/support.php';

  var state = {
    open: false,
    view: 'list',       // list | thread | compose
    tickets: [],
    ticket: null,
    messages: [],
    files: [],          // File objects staged for the next send
    draft: '',          // text of the box being typed in
    subject: '',
    busy: false,
    error: '',
    // Placeholders only, replaced by the real numbers from the server on every
    // loadList(). They deliberately OVER-state the limit: the effective ceiling
    // depends on the host's upload_max_filesize, and a fallback that guessed low
    // would refuse a customer's screenshot in the browser before the server ever
    // saw it. Guessing high only ever costs a clear server-side refusal.
    limits: { max_attachments: 3, max_attachment_bytes: 5 * 1024 * 1024, max_attachment_label: '' }
  };

  function csrf() { return document.body.dataset.csrf || ''; }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function when(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(s);
    var diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function humanSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1048576).toFixed(1).replace('.0', '') + ' MB';
  }

  /* ── Transport ───────────────────────────────────────────────────────────── */

  function request(op, payload, files) {
    if (files && files.length) {
      var fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('op', op);
      Object.keys(payload || {}).forEach(function (k) { fd.append(k, payload[k]); });
      files.forEach(function (f) { fd.append('files[]', f, f.name); });
      return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
    }
    return fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: csrf(), op: op }, payload || {}))
    });
  }

  function call(op, payload, files) {
    return request(op, payload, files).then(function (r) {
      return r.json().catch(function () { return { success: false, error: 'bad_response' }; });
    });
  }

  /* ── Shell ───────────────────────────────────────────────────────────────── */

  var launcher = document.createElement('button');
  launcher.type = 'button';
  launcher.className = 'sp-launcher';
  launcher.setAttribute('aria-haspopup', 'dialog');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.innerHTML = '<i class="fa-solid fa-headset" aria-hidden="true"></i><span>Support</span>'
    + '<span class="sp-unread" hidden></span>';
  launcher.title = 'Talk to support';

  var panel = document.createElement('div');
  panel.className = 'sp-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Support');
  panel.hidden = true;

  panel.innerHTML =
    '<div class="sp-head">'
    +   '<i class="fa-solid fa-headset" aria-hidden="true"></i>'
    +   '<div>'
    +     '<div class="sp-title">Support</div>'
    +     '<div class="sp-sub" id="sp-sub">Usually a reply within a day</div>'
    +   '</div>'
    +   '<div class="sp-actions">'
    +     '<button type="button" class="sp-icon-btn" id="sp-new" title="New request"><i class="fa-solid fa-plus"></i></button>'
    +     '<button type="button" class="sp-icon-btn" id="sp-back" title="Back to list" hidden><i class="fa-solid fa-arrow-left"></i></button>'
    +     '<button type="button" class="sp-icon-btn" id="sp-close" title="Close"><i class="fa-solid fa-xmark"></i></button>'
    +   '</div>'
    + '</div>'
    + '<div class="sp-body" id="sp-body"></div>'
    + '<div class="sp-foot" id="sp-foot" hidden></div>';

  document.body.appendChild(launcher);
  document.body.appendChild(panel);

  var $body = panel.querySelector('#sp-body');
  var $foot = panel.querySelector('#sp-foot');
  var $sub  = panel.querySelector('#sp-sub');
  var $new  = panel.querySelector('#sp-new');
  var $back = panel.querySelector('#sp-back');
  var $badge = launcher.querySelector('.sp-unread');

  /* ── Badge ───────────────────────────────────────────────────────────────── */

  function setBadge(n) {
    n = Number(n) || 0;
    if (n > 0) {
      $badge.textContent = n > 99 ? '99+' : String(n);
      $badge.hidden = false;
    } else {
      $badge.hidden = true;
    }
  }

  function refreshUnread() {
    call('unread').then(function (r) {
      if (r && r.success) setBadge(r.unread);
    }).catch(function () { /* a badge is not worth surfacing a failure for */ });
  }

  /* ── Views ───────────────────────────────────────────────────────────────── */

  function render() {
    panel.querySelector('#sp-back').hidden = state.view === 'list';
    $foot.hidden = state.view === 'list';

    if (state.view === 'list') renderList();
    else if (state.view === 'thread') renderThread();
    else renderCompose();

    renderFoot();
  }

  function renderList() {
    $sub.textContent = 'Usually a reply within a day';

    if (state.busy && !state.tickets.length) {
      $body.innerHTML = '<div class="sp-loading">Loading…</div>';
      return;
    }

    var err = state.error ? '<div class="sp-empty" style="color:#fca5a5">' + esc(state.error) + '</div>' : '';

    if (!state.tickets.length) {
      $body.innerHTML = err
        + '<div class="sp-empty">'
        +   'No conversations yet.<br>Something not working the way you expect — or just a question?'
        + '</div>';
      return;
    }

    var html = '<div class="sp-list">';
    state.tickets.forEach(function (t) {
      var pillClass = t.status === 'open' ? 'is-open' : (t.status === 'pending' ? 'is-pending' : '');
      html += '<button type="button" class="sp-ticket" data-ticket="' + t.id + '">'
        + '<div class="sp-ticket-top">'
        +   '<span class="sp-ticket-subject">' + esc(t.subject) + '</span>'
        +   (t.unread > 0 ? '<span class="sp-unread">' + t.unread + '</span>' : '')
        +   '<span class="sp-pill ' + pillClass + '">' + esc(t.status_label) + '</span>'
        + '</div>'
        + '<div class="sp-ticket-meta">' + esc(when(t.last_message_at))
        +   ' · ' + t.message_count + (t.message_count === 1 ? ' message' : ' messages')
        + '</div>'
        + '</button>';
    });
    html += '</div>' + err;
    $body.innerHTML = html;

    $body.querySelectorAll('[data-ticket]').forEach(function (el) {
      el.addEventListener('click', function () { openTicket(Number(el.dataset.ticket)); });
    });
  }

  function renderThread() {
    var t = state.ticket;
    if (!t) { $body.innerHTML = '<div class="sp-loading">Loading…</div>'; return; }

    $sub.textContent = t.status_label + ' · #' + t.id;

    var html = '<div class="sp-thread">';
    state.messages.forEach(function (m) {
      var side = m.author_type === 'admin' ? 'them' : 'me';
      html += '<div class="sp-msg ' + side + '">'
        + '<div class="sp-msg-who">' + esc(m.author_type === 'admin' ? 'Utiligo Support' : 'You')
        +   ' · ' + esc(when(m.created_at)) + '</div>'
        + '<div class="sp-bubble">'
        +   (String(m.body || '').trim() !== '' ? m.body_html : '<span style="opacity:.6">(attachment only)</span>')
        +   renderAttachments(m.attachments)
        + '</div>'
        + '</div>';
    });
    html += '</div>';

    if (!t.can_reply) {
      html += '<div class="sp-empty">This ticket is closed. Reopen it to reply.</div>';
    }
    if (state.error) {
      html += '<div class="sp-empty" style="color:#fca5a5">' + esc(state.error) + '</div>';
    }
    $body.innerHTML = html;
    $body.scrollTop = $body.scrollHeight;
  }

  function renderCompose() {
    $sub.textContent = 'Tell us what happened';
    $body.innerHTML =
      '<div class="sp-field">'
      + '<label class="sp-label" for="sp-subject">Subject</label>'
      + '<input id="sp-subject" class="sp-input" maxlength="160" placeholder="Short summary" value="' + esc(state.subject) + '">'
      + '</div>'
      + '<div class="sp-field">'
      + '<label class="sp-label" for="sp-text">What happened?</label>'
      + '<textarea id="sp-text" class="sp-textarea" maxlength="8000" placeholder="What you were doing, what you expected, what you saw. Links are clickable automatically.">' + esc(state.draft) + '</textarea>'
      + '</div>'
      + (state.error ? '<div class="sp-empty" style="color:#fca5a5">' + esc(state.error) + '</div>' : '');

    var s = $body.querySelector('#sp-subject');
    var t = $body.querySelector('#sp-text');
    s.addEventListener('input', function () { state.subject = s.value; });
    t.addEventListener('input', function () { state.draft = t.value; });
    setTimeout(function () { s.focus(); }, 30);
  }

  function renderAttachments(list) {
    if (!list || !list.length) return '';
    var html = '<div class="sp-att">';
    list.forEach(function (a) {
      if (a.is_image) {
        html += '<a href="' + esc(a.url) + '" target="_blank" rel="noopener noreferrer" title="' + esc(a.name) + '">'
          + '<img src="' + esc(a.url) + '" alt="' + esc(a.name) + '" loading="lazy">'
          + '</a>';
      } else {
        html += '<a class="sp-att-file" href="' + esc(a.url) + '" target="_blank" rel="noopener noreferrer">'
          + '<i class="fa-solid fa-paperclip"></i>' + esc(a.name)
          + '<span style="opacity:.6">' + esc(a.size_label) + '</span>'
          + '</a>';
      }
    });
    return html + '</div>';
  }

  function renderFoot() {
    var t = state.ticket;
    var canSend = state.view === 'compose'
      || (state.view === 'thread' && t && t.can_reply);

    if (!canSend) {
      // Closed thread: the only action left is reopening it.
      if (state.view === 'thread' && t && !t.can_reply) {
        $foot.innerHTML = '<div class="sp-foot-row">'
          + '<span class="sp-hint">Closed tickets can be reopened.</span>'
          + '<button type="button" class="sp-btn sp-btn-primary" id="sp-reopen">Reopen</button>'
          + '</div>';
        $foot.querySelector('#sp-reopen').addEventListener('click', function () { setStatus('reopen'); });
      } else {
        $foot.innerHTML = '';
      }
      return;
    }

    var primary = state.view === 'compose' ? 'Send request' : 'Send reply';

    $foot.innerHTML =
      '<div class="sp-files" id="sp-files"></div>'
      + '<div class="sp-foot-row">'
      +   '<input type="file" id="sp-file" class="sp-file" multiple accept="image/*,application/pdf">'
      +   '<span class="sp-hint" id="sp-hint"></span>'
      + '</div>'
      + '<div class="sp-foot-row">'
      +   (state.view === 'thread' ? '<button type="button" class="sp-btn" id="sp-close-ticket">Close ticket</button>' : '')
      +   '<button type="button" class="sp-btn sp-btn-primary" id="sp-send" style="margin-left:auto">' + primary + '</button>'
      + '</div>';

    $foot.querySelector('#sp-file').addEventListener('change', onPickFiles);
    $foot.querySelector('#sp-send').addEventListener('click', onSend);
    var ct = $foot.querySelector('#sp-close-ticket');
    if (ct) ct.addEventListener('click', function () { setStatus('close'); });

    renderChips();
  }

  function renderChips() {
    var box = $foot.querySelector('#sp-files');
    var hint = $foot.querySelector('#sp-hint');
    if (!box) return;

    box.innerHTML = state.files.map(function (f, i) {
      return '<div class="sp-chip">'
        + '<i class="fa-solid ' + (f.type === 'application/pdf' ? 'fa-file-pdf' : 'fa-image') + '"></i>'
        + '<span class="sp-chip-name">' + esc(f.name) + '</span>'
        + '<span class="sp-chip-size">' + humanSize(f.size) + '</span>'
        + '<button type="button" data-remove="' + i + '" aria-label="Remove ' + esc(f.name) + '">&times;</button>'
        + '</div>';
    }).join('');

    box.querySelectorAll('[data-remove]').forEach(function (b) {
      b.addEventListener('click', function () {
        state.files.splice(Number(b.dataset.remove), 1);
        renderChips();
      });
    });

    if (hint) {
      hint.classList.remove('is-warn');
      // The size phrase is dropped rather than printed empty when the limits
      // request has not answered yet — "up to 3 files,  each" is worse than saying
      // only what is known.
      hint.textContent = 'Up to ' + state.limits.max_attachments + ' files'
        + (state.limits.max_attachment_label ? ', ' + state.limits.max_attachment_label + ' each' : '');
    }
  }

  /* ── Files ───────────────────────────────────────────────────────────────── */

  function onPickFiles(e) {
    var chosen = Array.prototype.slice.call(e.target.files || []);
    var hint = $foot.querySelector('#sp-hint');

    for (var i = 0; i < chosen.length; i++) {
      var f = chosen[i];

      if (state.files.length >= state.limits.max_attachments) {
        warn('Attachment limit is ' + state.limits.max_attachments + ' files.');
        break;
      }
      var okType = f.type.indexOf('image/') === 0 || f.type === 'application/pdf';
      if (!okType) {
        warn('"' + f.name + '" is not an image or PDF.');
        continue;
      }
      if (f.size > state.limits.max_attachment_bytes) {
        warn('"' + f.name + '" is larger than ' + state.limits.max_attachment_label + '.');
        continue;
      }
      if (f.size === 0) {
        warn('"' + f.name + '" is empty.');
        continue;
      }
      state.files.push(f);
    }

    e.target.value = '';
    renderChips();

    function warn(msg) {
      if (hint) {
        hint.textContent = msg;
        hint.classList.add('is-warn');
      }
    }
  }

  /* ── Actions ─────────────────────────────────────────────────────────────── */

  function busy(on) {
    state.busy = on;
    var send = $foot.querySelector('#sp-send');
    if (send) send.disabled = on;
  }

  function onSend() {
    if (state.busy) return;
    state.error = '';

    if (state.view === 'compose') {
      if (!state.subject.trim()) { state.error = 'Give it a subject so we can find it.'; renderCompose(); return; }
      if (!state.draft.trim() && !state.files.length) { state.error = 'Write something or attach a file.'; renderCompose(); return; }
    } else {
      if (!state.draft.trim() && !state.files.length) { state.error = 'Write something or attach a file.'; render(); return; }
    }

    var files = state.files.slice();
    busy(true);

    var p;
    if (state.view === 'compose') {
      p = call('create', { subject: state.subject.trim(), body: state.draft }, files);
    } else {
      p = call('reply', { ticket_id: state.ticket.id, body: state.draft }, files);
    }

    p.then(function (r) {
      busy(false);
      if (!r || !r.success) {
        // The draft and the files are deliberately kept: a failed send must not
        // cost the customer the report they just wrote.
        state.error = describe(r && r.error);
        render();
        return;
      }
      state.files = [];
      state.draft = '';
      state.subject = '';
      if (state.view === 'compose') {
        openTicket(r.ticket_id);
      } else {
        loadTicket(state.ticket.id);
      }
    }).catch(function () {
      busy(false);
      state.error = 'Could not reach the server. Your message is still here — try again.';
      render();
    });
  }

  function setStatus(op) {
    if (state.busy || !state.ticket) return;
    state.busy = true;
    state.error = '';
    call(op, { ticket_id: state.ticket.id }).then(function (r) {
      state.busy = false;
      if (!r || !r.success) { state.error = describe(r && r.error); render(); return; }
      loadTicket(state.ticket.id);
    }).catch(function () {
      state.busy = false;
      state.error = 'Could not reach the server.';
      render();
    });
  }

  function openTicket(id) {
    state.view = 'thread';
    state.ticket = null;
    state.messages = [];
    state.files = [];
    state.draft = '';
    state.error = '';
    render();
    loadTicket(id);
  }

  function loadTicket(id) {
    call('get', { ticket_id: id }).then(function (r) {
      if (!r || !r.success) { state.error = describe(r && r.error); render(); return; }
      state.ticket = r.ticket;
      state.messages = r.messages || [];
      render();
      refreshUnread();
    }).catch(function () {
      state.error = 'Could not load that ticket.';
      render();
    });
  }

  function loadList() {
    state.busy = true;
    state.error = '';
    render();
    call('list').then(function (r) {
      state.busy = false;
      if (!r || !r.success) { state.error = describe(r && r.error); render(); return; }
      state.tickets = r.tickets || [];
      if (r.max_attachments) state.limits.max_attachments = r.max_attachments;
      if (r.max_attachment_bytes) state.limits.max_attachment_bytes = r.max_attachment_bytes;
      if (r.max_attachment_label) state.limits.max_attachment_label = r.max_attachment_label;
      setBadge(r.unread);
      render();
    }).catch(function () {
      state.busy = false;
      state.error = 'Could not load your tickets.';
      render();
    });
  }

  function describe(code) {
    return {
      invalid_subject: 'Give it a subject so we can find it.',
      empty_message: 'Write something or attach a file.',
      too_many_open: 'You already have several open requests — reply on one of those, or close one first.',
      ticket_closed: 'That ticket is closed. Reopen it to reply.',
      too_many_files: 'That is more files than we can take at once.',
      file_too_large: 'That file is larger than ' + state.limits.max_attachment_label + '.',
      file_type_not_allowed: 'Only images (JPG, PNG, WEBP, GIF) and PDFs can be attached.',
      rate_limited: 'Too many requests just now — give it a moment.',
      not_found: 'That ticket no longer exists.',
      invalid_csrf: 'Your session expired. Reload the page and try again.'
    }[code] || 'Something went wrong. Please try again.';
  }

  /* ── Open / close ────────────────────────────────────────────────────────── */

  function open() {
    state.open = true;
    panel.hidden = false;
    launcher.setAttribute('aria-expanded', 'true');
    if (state.view === 'list') loadList();
    else render();
    var focusable = panel.querySelector('input, textarea, button');
    if (focusable) setTimeout(function () { focusable.focus(); }, 40);
  }

  function close() {
    state.open = false;
    panel.hidden = true;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.focus();
  }

  launcher.addEventListener('click', function () { state.open ? close() : open(); });
  panel.querySelector('#sp-close').addEventListener('click', close);
  $new.addEventListener('click', function () {
    state.view = 'compose';
    state.error = '';
    state.files = [];
    render();
  });
  $back.addEventListener('click', function () {
    state.view = 'list';
    state.ticket = null;
    state.error = '';
    state.files = [];
    state.draft = '';
    loadList();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && state.open) close();
  });

  refreshUnread();
  setInterval(refreshUnread, 60000);
}());
