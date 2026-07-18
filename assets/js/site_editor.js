document.addEventListener('DOMContentLoaded', function () {
  const root       = document.getElementById('editorRoot');
  const siteId     = root ? root.dataset.siteId : null;
  const csrfToken  = document.body.dataset.csrf;
  const iframe     = document.getElementById('siteFrame');
  const fmtBar     = document.getElementById('formatToolbar');
  const imgBar     = document.getElementById('imageToolbar');
  const bgBar      = document.getElementById('bgToolbar');
  const textColor  = document.getElementById('textColorPicker');
  const bgColor    = document.getElementById('bgColorPicker');
  const imgDrop    = document.getElementById('imageDropzone');
  const imgInput   = document.getElementById('imageFileInput');
  const clearFmt   = document.getElementById('clearFormatBtn');
  const saveStatus = document.getElementById('saveStatus');
  const undoBtn    = document.getElementById('undoBtn');
  const redoBtn    = document.getElementById('redoBtn');

  if (!iframe || !siteId) return;

  const currentPage   = new URLSearchParams(window.location.search).get('page') || 'index';
  let activeTextEl    = null;
  let activeImageEl   = null;
  let activeBgEl      = null;
  const pendingEdits  = new Map();
  let saveTimer       = null;

  // ── History ──────────────────────────────────────────────────────────────
  const undoStack = [], redoStack = [];
  let isHistory = false;

  function captureState(el, type) {
    if (!el) return null;
    if (type === 'text')     return { type, editId: el.dataset.editId, html: el.innerHTML };
    if (type === 'bg_color') return { type, editId: el.dataset.editId, bg: el.style.background };
    if (type === 'image')    return { type, editId: el.dataset.editId, src: el.getAttribute('src') };
  }

  function restoreState(state) {
    if (!state) return;
    const el = iframe.contentDocument.querySelector('[data-edit-id="' + state.editId + '"]');
    if (!el) return;
    isHistory = true;
    if (state.type === 'text')     { el.innerHTML = state.html; queueEdit(state.editId, 'text', { html: state.html, text: el.textContent }); }
    if (state.type === 'bg_color') { el.style.background = state.bg; const m = (state.bg||'').match(/#[0-9a-fA-F]{6}/); queueEdit(state.editId, 'bg_color', { color: m ? m[0] : '#ffffff' }); }
    if (state.type === 'image')    { el.setAttribute('src', state.src); queueEdit(state.editId, 'image', { url: state.src }); }
    isHistory = false;
  }

  function pushHistory(before) {
    if (isHistory || !before) return;
    undoStack.push(before);
    if (undoStack.length > 100) undoStack.shift();
    redoStack.length = 0;
    syncHistoryBtns();
  }

  function syncHistoryBtns() {
    undoBtn.disabled = undoStack.length === 0;
    redoBtn.disabled = redoStack.length === 0;
  }

  function undo() {
    if (!undoStack.length) return;
    const prev = undoStack.pop();
    const el   = iframe.contentDocument.querySelector('[data-edit-id="' + prev.editId + '"]');
    const cur  = captureState(el, prev.type);
    if (cur) redoStack.push(cur);
    restoreState(prev); syncHistoryBtns(); setSaveStatus('Undone');
  }

  function redo() {
    if (!redoStack.length) return;
    const next = redoStack.pop();
    const el   = iframe.contentDocument.querySelector('[data-edit-id="' + next.editId + '"]');
    const cur  = captureState(el, next.type);
    if (cur) undoStack.push(cur);
    restoreState(next); syncHistoryBtns(); setSaveStatus('Redone');
  }

  undoBtn.addEventListener('click', undo);
  redoBtn.addEventListener('click', redo);
  document.addEventListener('keydown', e => {
    if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'z') return;
    e.preventDefault(); e.shiftKey ? redo() : undo();
  });

  // ── Save ─────────────────────────────────────────────────────────────────
  function setSaveStatus(msg, type = 'idle') {
    const el = saveStatus;
    el.textContent = msg;
    el.className = 'text-xs ml-1 transition-colors duration-200 ';
    if (type === 'saving')  el.className += 'text-amber-400';
    else if (type === 'ok') el.className += 'text-emerald-400';
    else if (type === 'err') el.className += 'text-red-400';
    else el.className += 'text-slate-500';
    if (type === 'ok') setTimeout(() => { if (saveStatus.textContent === msg) { saveStatus.textContent = ''; } }, 2500);
  }

  function queueEdit(editId, type, payload) {
    pendingEdits.set(editId, Object.assign({ edit_id: editId, type }, payload));
    setSaveStatus('Unsaved changes…', 'idle');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flushEdits, 900);
  }

  function flushEdits() {
    if (!pendingEdits.size) return;
    const edits = Array.from(pendingEdits.values());
    pendingEdits.clear();
    setSaveStatus('Saving…', 'saving');
    fetch('/api/save-site-edit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site_id: siteId, page: currentPage, edits, csrf_token: csrfToken }),
    }).then(r => r.json())
      .then(d => setSaveStatus(d.success ? 'Saved ✓' : (d.error || 'Save failed'), d.success ? 'ok' : 'err'))
      .catch(() => setSaveStatus('Save failed', 'err'));
  }

  window.addEventListener('beforeunload', flushEdits);

  // ── Toolbar positioning ───────────────────────────────────────────────────
  // Places toolbar above or below the target, and clamps it inside viewport.
  // Also detects if the area it would sit on is dark/light and flips theme.
  function positionToolbar(bar, targetRect, ifRect) {
    bar.style.visibility = 'hidden';
    bar.classList.remove('hidden');
    const bw = bar.offsetWidth;
    const bh = bar.offsetHeight;

    // Preferred: above the element
    let top  = ifRect.top  + targetRect.top  - bh - 10;
    let left = ifRect.left + targetRect.left;

    // If it would go above viewport, place below instead
    if (top < 8) top = ifRect.top + targetRect.bottom + 10;

    // Clamp horizontally
    const maxLeft = window.innerWidth - bw - 8;
    left = Math.max(8, Math.min(left, maxLeft));
    // Clamp vertically (in case below also clips)
    const maxTop = window.innerHeight - bh - 8;
    top  = Math.max(8, Math.min(top, maxTop));

    bar.style.top  = top  + 'px';
    bar.style.left = left + 'px';
    bar.style.visibility = '';

    applyContrastTheme(bar, ifRect, targetRect);
  }

  // Sample background colour at the target position in the iframe and
  // switch the popover between its light / dark variant accordingly.
  function applyContrastTheme(bar, ifRect, targetRect) {
    try {
      const doc  = iframe.contentDocument;
      const cx   = targetRect.left + targetRect.width  / 2;
      const cy   = targetRect.top  + targetRect.height / 2;
      const el   = doc.elementFromPoint(cx, cy);
      if (!el) return;
      const bg   = getComputedStyleRecursive(el, doc);
      const lum  = luminance(bg);
      // Light background → use dark popover; dark bg → use light popover
      if (lum > 0.35) {
        bar.classList.add('theme-light-bg');
        bar.classList.remove('theme-dark-bg');
      } else {
        bar.classList.add('theme-dark-bg');
        bar.classList.remove('theme-light-bg');
      }
    } catch (e) {}
  }

  function getComputedStyleRecursive(el, doc) {
    let node = el;
    while (node && node !== doc.body) {
      const bg = doc.defaultView.getComputedStyle(node).backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') return bg;
      node = node.parentElement;
    }
    return 'rgb(255,255,255)';
  }

  function luminance(rgb) {
    const m = rgb.match(/\d+/g);
    if (!m) return 1;
    const [r, g, b] = m.map(v => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  }

  function rgbToHex(rgb) {
    const m = rgb.match(/\d+/g);
    if (!m) return null;
    return '#' + m.slice(0, 3).map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
  }

  // ── Hide toolbars ─────────────────────────────────────────────────────────
  // Only hide if focus truly left the toolbar (not just moved inside it).
  let hideTimer = null;

  function scheduleHide() {
    hideTimer = setTimeout(() => {
      // Check if focus is now inside any toolbar
      const active = document.activeElement;
      if (fmtBar.contains(active) || imgBar.contains(active) || bgBar.contains(active)) return;
      hideAll();
    }, 180);
  }

  function cancelHide() { clearTimeout(hideTimer); }

  function hideAll() {
    fmtBar.classList.add('hidden');
    imgBar.classList.add('hidden');
    bgBar.classList.add('hidden');
  }

  // Keep toolbar alive while cursor is inside it
  [fmtBar, imgBar, bgBar].forEach(bar => {
    bar.addEventListener('mouseenter', cancelHide);
    bar.addEventListener('focusin',    cancelHide);
    bar.addEventListener('mouseleave', () => {});
  });

  // ── iframe init ───────────────────────────────────────────────────────────
  iframe.addEventListener('load', () => {
    const doc = iframe.contentDocument;
    if (!doc) return;

    const style = doc.createElement('style');
    style.textContent = `
      [data-edit-type="text"]:hover  { outline: 2px dashed rgba(16,185,129,.55); outline-offset:2px; cursor:text; border-radius:2px; }
      [data-edit-type="text"]:focus  { outline: 2px solid #10B981; outline-offset:2px; cursor:text; border-radius:2px; }
      [data-edit-type="image"]:hover { outline: 2px dashed rgba(99,102,241,.7); outline-offset:2px; cursor:pointer; border-radius:4px; }
      [data-edit-bg="1"]:hover       { box-shadow: inset 0 0 0 3px rgba(16,185,129,.3); cursor:pointer; }
      [data-sortable-section="1"]    { position:relative; transition:transform .15s,opacity .15s; }
      [data-sortable-section="1"].section-dragging { opacity:.35; }
      [data-sortable-section="1"].section-drop-target { box-shadow: inset 0 4px 0 0 #10B981; }
      .section-drag-handle {
        position:absolute; top:10px; right:10px; z-index:99;
        width:32px; height:32px; background:rgba(16,185,129,.9);
        border-radius:8px; display:flex; align-items:center; justify-content:center;
        cursor:grab; color:#052e1f; font-size:15px;
        box-shadow:0 2px 10px rgba(0,0,0,.3);
        opacity:0; transition:opacity .15s;
      }
      [data-sortable-section="1"]:hover .section-drag-handle { opacity:1; }
    `;
    doc.head.appendChild(style);

    initSectionReorder(doc);

    doc.querySelectorAll('[data-edit-type="text"]').forEach(el => {
      el.setAttribute('contenteditable', 'true');
      el.addEventListener('focus', () => { showFmt(el); });
      el.addEventListener('beforeinput', () => {
        if (!isHistory && !el.dataset.snap) { el.dataset.snap = el.innerHTML; }
      });
      el.addEventListener('input', () => {
        if (el.dataset.snap !== undefined) { pushHistory({ type:'text', editId:el.dataset.editId, html:el.dataset.snap }); delete el.dataset.snap; }
        queueEdit(el.dataset.editId, 'text', { html: el.innerHTML, text: el.textContent });
      });
      el.addEventListener('blur', scheduleHide);
    });

    doc.querySelectorAll('[data-edit-type="image"]').forEach(el => {
      el.addEventListener('click', e => { e.preventDefault(); showImg(el); });
    });

    doc.querySelectorAll('[data-edit-bg="1"]').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('[data-edit-type="text"],[data-edit-type="image"],a,.section-drag-handle')) return;
        showBg(el);
      });
    });

    // Click on blank area → hide
    doc.addEventListener('mousedown', e => {
      if (!e.target.closest('[data-edit-id]')) scheduleHide();
    });
  });

  // ── Show toolbars ─────────────────────────────────────────────────────────
  function showFmt(el) {
    cancelHide(); hideAll();
    activeTextEl = el;
    const ifRect  = iframe.getBoundingClientRect();
    const tgtRect = el.getBoundingClientRect();
    positionToolbar(fmtBar, tgtRect, ifRect);
  }

  function showImg(el) {
    cancelHide(); hideAll();
    activeImageEl = el;
    const ifRect  = iframe.getBoundingClientRect();
    const tgtRect = el.getBoundingClientRect();
    positionToolbar(imgBar, tgtRect, ifRect);
  }

  function showBg(el) {
    cancelHide(); hideAll();
    activeBgEl = el;
    const ifRect  = iframe.getBoundingClientRect();
    const tgtRect = el.getBoundingClientRect();
    positionToolbar(bgBar, tgtRect, ifRect);
    bgColor.value = rgbToHex(iframe.contentWindow.getComputedStyle(el).backgroundColor) || '#ffffff';
  }

  // ── Format toolbar actions ────────────────────────────────────────────────
  fmtBar.querySelectorAll('.toolbar-btn[data-cmd]').forEach(btn => {
    btn.addEventListener('mousedown', e => e.preventDefault()); // keep focus in iframe
    btn.addEventListener('click', () => {
      if (!activeTextEl) return;
      pushHistory(captureState(activeTextEl, 'text'));
      iframe.contentDocument.execCommand(btn.dataset.cmd, false, null);
      queueEdit(activeTextEl.dataset.editId, 'text', { html: activeTextEl.innerHTML, text: activeTextEl.textContent });
    });
  });

  textColor.addEventListener('input', () => {
    if (!activeTextEl) return;
    if (!textColor.dataset.snap) { textColor.dataset.snap = '1'; pushHistory(captureState(activeTextEl, 'text')); }
    iframe.contentDocument.execCommand('foreColor', false, textColor.value);
    queueEdit(activeTextEl.dataset.editId, 'text', { html: activeTextEl.innerHTML, text: activeTextEl.textContent });
  });
  textColor.addEventListener('change', () => { delete textColor.dataset.snap; });

  clearFmt.addEventListener('mousedown', e => e.preventDefault());
  clearFmt.addEventListener('click', () => {
    if (!activeTextEl) return;
    pushHistory(captureState(activeTextEl, 'text'));
    iframe.contentDocument.execCommand('removeFormat', false, null);
    queueEdit(activeTextEl.dataset.editId, 'text', { html: activeTextEl.innerHTML, text: activeTextEl.textContent });
  });

  bgColor.addEventListener('input', () => {
    if (!activeBgEl) return;
    if (!bgColor.dataset.snap) { bgColor.dataset.snap = '1'; pushHistory(captureState(activeBgEl, 'bg_color')); }
    activeBgEl.style.background = bgColor.value;
    queueEdit(activeBgEl.dataset.editId, 'bg_color', { color: bgColor.value });
  });
  bgColor.addEventListener('change', () => { delete bgColor.dataset.snap; });

  // ── Image upload ──────────────────────────────────────────────────────────
  function uploadImage(file) {
    if (!activeImageEl) return;
    const fd = new FormData();
    fd.append('image', file); fd.append('slot', 'edit');
    fd.append('site_id', siteId); fd.append('csrf_token', csrfToken);
    setSaveStatus('Uploading…', 'saving');
    fetch('/api/upload-image.php', { method:'POST', body:fd })
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          pushHistory(captureState(activeImageEl, 'image'));
          activeImageEl.setAttribute('src', d.url);
          queueEdit(activeImageEl.dataset.editId, 'image', { url: d.url });
          hideAll();
        } else { setSaveStatus(d.error || 'Upload failed', 'err'); }
      })
      .catch(() => setSaveStatus('Upload failed', 'err'));
  }

  imgDrop.addEventListener('click', () => imgInput.click());
  imgInput.addEventListener('change', e => { if (e.target.files[0]) uploadImage(e.target.files[0]); });
  ['dragenter','dragover'].forEach(ev => imgDrop.addEventListener(ev, e => { e.preventDefault(); imgDrop.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(ev => imgDrop.addEventListener(ev, e => { e.preventDefault(); imgDrop.classList.remove('dragover'); }));
  imgDrop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) uploadImage(e.dataTransfer.files[0]); });

  // ── Section reordering ────────────────────────────────────────────────────
  function initSectionReorder(doc) {
    const sections = Array.from(doc.querySelectorAll('[data-sortable-section="1"]'));
    let dragged = null;

    sections.forEach(sec => {
      sec.setAttribute('draggable', 'false');
      const handle = doc.createElement('div');
      handle.className = 'section-drag-handle';
      handle.innerHTML = '&#9776;';
      handle.setAttribute('draggable', 'true');
      sec.style.position = sec.style.position || 'relative';
      sec.appendChild(handle);

      handle.addEventListener('dragstart', e => {
        dragged = sec; sec.classList.add('section-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', sec.dataset.editId); } catch(_) {}
      });
      handle.addEventListener('dragend', () => {
        sec.classList.remove('section-dragging');
        sections.forEach(s => s.classList.remove('section-drop-target'));
        saveSectionOrder(doc);
      });
      sec.addEventListener('dragover', e => {
        if (!dragged || dragged === sec) return;
        e.preventDefault(); sec.classList.add('section-drop-target');
      });
      sec.addEventListener('dragleave', () => sec.classList.remove('section-drop-target'));
      sec.addEventListener('drop', e => {
        e.preventDefault(); sec.classList.remove('section-drop-target');
        if (!dragged || dragged === sec) return;
        const after = (e.clientY - sec.getBoundingClientRect().top) > sec.offsetHeight / 2;
        sec.parentNode.insertBefore(dragged, after ? sec.nextSibling : sec);
      });
    });
  }

  function saveSectionOrder(doc) {
    const order = Array.from(doc.querySelectorAll('[data-sortable-section="1"]')).map(s => s.dataset.editId);
    setSaveStatus('Saving order…', 'saving');
    fetch('/api/reorder-site-sections.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ site_id: siteId, page: currentPage, order, csrf_token: csrfToken }),
    }).then(r => r.json())
      .then(d => setSaveStatus(d.success ? 'Saved ✓' : (d.error||'Save failed'), d.success ? 'ok' : 'err'))
      .catch(() => setSaveStatus('Save failed', 'err'));
  }

});
