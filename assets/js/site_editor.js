document.addEventListener('DOMContentLoaded', function () {
  const root = document.getElementById('editorRoot');
  const siteId = root ? root.dataset.siteId : null;
  const csrfToken = document.body.dataset.csrf;
  const iframe = document.getElementById('siteFrame');
  const formatToolbar = document.getElementById('formatToolbar');
  const imageToolbar = document.getElementById('imageToolbar');
  const bgToolbar = document.getElementById('bgToolbar');
  const textColorPicker = document.getElementById('textColorPicker');
  const bgColorPicker = document.getElementById('bgColorPicker');
  const imageDropzone = document.getElementById('imageDropzone');
  const imageFileInput = document.getElementById('imageFileInput');
  const clearFormatBtn = document.getElementById('clearFormatBtn');
  const saveStatus = document.getElementById('saveStatus');

  if (!iframe || !siteId) return;

  const undoBtn = document.getElementById('undoBtn');
  const redoBtn = document.getElementById('redoBtn');

  const currentPage = new URLSearchParams(window.location.search).get('page') || 'index';
  let activeTextEl = null;
  let activeImageEl = null;
  let activeBgEl = null;
  const pendingEdits = new Map();
  let saveTimer = null;

  // ---- Undo / redo history ----
  // Each history entry captures the state of one edit_id BEFORE a change
  // was applied, so undo can restore it and redo can re-apply the change
  // that was undone.
  const undoStack = [];
  const redoStack = [];
  const MAX_HISTORY = 100;
  let isApplyingHistory = false;

  function captureState(el, type) {
    if (!el) return null;
    if (type === 'text') return { type, editId: el.dataset.editId, html: el.innerHTML };
    if (type === 'bg_color') return { type, editId: el.dataset.editId, bg: el.style.background };
    if (type === 'image') return { type, editId: el.dataset.editId, src: el.getAttribute('src') };
    return null;
  }

  function restoreState(state) {
    if (!state) return;
    const doc = iframe.contentDocument;
    const el = doc.querySelector('[data-edit-id="' + state.editId.replace(/"/g, '\\"') + '"]');
    if (!el) return;
    isApplyingHistory = true;
    if (state.type === 'text') {
      el.innerHTML = state.html;
      queueEdit(state.editId, 'text', { html: el.innerHTML, text: el.textContent });
    } else if (state.type === 'bg_color') {
      el.style.background = state.bg;
      const m = (state.bg || '').match(/#([0-9a-fA-F]{6})/);
      queueEdit(state.editId, 'bg_color', { color: m ? ('#' + m[1]) : (state.bg || '#ffffff') });
    } else if (state.type === 'image') {
      el.setAttribute('src', state.src);
      queueEdit(state.editId, 'image', { url: state.src });
    }
    isApplyingHistory = false;
  }

  function pushHistory(beforeState) {
    if (isApplyingHistory || !beforeState) return;
    undoStack.push(beforeState);
    if (undoStack.length > MAX_HISTORY) undoStack.shift();
    redoStack.length = 0;
    updateHistoryButtons();
  }

  function updateHistoryButtons() {
    if (undoBtn) undoBtn.disabled = undoStack.length === 0;
    if (redoBtn) redoBtn.disabled = redoStack.length === 0;
  }

  function undo() {
    if (undoStack.length === 0) return;
    const doc = iframe.contentDocument;
    const prev = undoStack.pop();
    const el = doc.querySelector('[data-edit-id="' + prev.editId.replace(/"/g, '\\"') + '"]');
    const currentState = captureState(el, prev.type);
    if (currentState) redoStack.push(currentState);
    restoreState(prev);
    updateHistoryButtons();
    setSaveStatus('Undone');
  }

  function redo() {
    if (redoStack.length === 0) return;
    const doc = iframe.contentDocument;
    const next = redoStack.pop();
    const el = doc.querySelector('[data-edit-id="' + next.editId.replace(/"/g, '\\"') + '"]');
    const currentState = captureState(el, next.type);
    if (currentState) undoStack.push(currentState);
    restoreState(next);
    updateHistoryButtons();
    setSaveStatus('Redone');
  }

  if (undoBtn) undoBtn.addEventListener('click', undo);
  if (redoBtn) redoBtn.addEventListener('click', redo);

  document.addEventListener('keydown', (e) => {
    const isMod = e.ctrlKey || e.metaKey;
    if (!isMod || e.key.toLowerCase() !== 'z') return;
    e.preventDefault();
    if (e.shiftKey) redo(); else undo();
  });

  function hideAllToolbars() {
    formatToolbar.classList.add('hidden');
    imageToolbar.classList.add('hidden');
    bgToolbar.classList.add('hidden');
  }

  function positionToolbar(toolbarEl, targetRect, iframeRect) {
    const top = iframeRect.top + targetRect.top - toolbarEl.offsetHeight - 8;
    const left = iframeRect.left + targetRect.left;
    toolbarEl.style.top = Math.max(8, top) + 'px';
    toolbarEl.style.left = Math.max(8, left) + 'px';
  }

  function setSaveStatus(msg) {
    saveStatus.textContent = msg;
    if (msg === 'Saved') {
      setTimeout(() => { if (saveStatus.textContent === 'Saved') saveStatus.textContent = ''; }, 2000);
    }
  }

  function queueEdit(editId, type, payload) {
    pendingEdits.set(editId, Object.assign({ edit_id: editId, type }, payload));
    setSaveStatus('Unsaved changes...');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flushEdits, 900);
  }

  function flushEdits() {
    if (pendingEdits.size === 0) return;
    const edits = Array.from(pendingEdits.values());
    pendingEdits.clear();
    setSaveStatus('Saving...');
    fetch('/api/save-site-edit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site_id: siteId, page: currentPage, edits, csrf_token: csrfToken }),
    })
      .then((r) => r.json())
      .then((data) => setSaveStatus(data.success ? 'Saved' : (data.error || 'Save failed')))
      .catch(() => setSaveStatus('Save failed'));
  }

  window.addEventListener('beforeunload', flushEdits);

  iframe.addEventListener('load', function () {
    const doc = iframe.contentDocument;
    if (!doc) return;

    const style = doc.createElement('style');
    style.textContent = `
      [data-edit-type="text"]:hover{outline:2px dashed rgba(16,185,129,0.5);cursor:text;}
      [data-edit-type="text"]:focus{outline:2px solid #10B981;cursor:text;}
      [data-edit-type="image"]:hover{outline:2px dashed rgba(16,185,129,0.6);cursor:pointer;}
      [data-edit-bg="1"]:hover{box-shadow:inset 0 0 0 3px rgba(16,185,129,0.35);cursor:pointer;}
      [data-sortable-section="1"]{position:relative;transition:transform 0.15s, opacity 0.15s;}
      [data-sortable-section="1"].section-dragging{opacity:0.4;}
      [data-sortable-section="1"].section-drop-target{box-shadow:inset 0 4px 0 0 #10B981;}
      .section-drag-handle{position:absolute;top:8px;right:8px;z-index:20;width:34px;height:34px;
        background:#10B981;border-radius:8px;display:flex;align-items:center;justify-content:center;
        cursor:grab;color:#052e1f;font-size:16px;box-shadow:0 2px 8px rgba(0,0,0,0.25);opacity:0;transition:opacity 0.15s;}
      [data-sortable-section="1"]:hover .section-drag-handle{opacity:1;}
    `;
    doc.head.appendChild(style);

    initSectionReordering(doc);

    doc.querySelectorAll('[data-edit-type="text"]').forEach((el) => {
      el.setAttribute('contenteditable', 'true');
      el.addEventListener('focus', () => showFormatToolbar(el));
      el.addEventListener('beforeinput', () => {
        if (!isApplyingHistory && !el.dataset.historyCaptured) {
          el.dataset.historyCaptured = '1';
          el.dataset.historySnapshot = el.innerHTML;
        }
      });
      el.addEventListener('input', () => {
        if (el.dataset.historyCaptured) {
          pushHistory({ type: 'text', editId: el.dataset.editId, html: el.dataset.historySnapshot });
          delete el.dataset.historyCaptured;
          delete el.dataset.historySnapshot;
        }
        queueEdit(el.dataset.editId, 'text', { html: el.innerHTML, text: el.textContent });
      });
      el.addEventListener('blur', () => {
        setTimeout(() => { if (!formatToolbar.contains(document.activeElement)) hideAllToolbars(); }, 150);
      });
    });

    doc.querySelectorAll('[data-edit-type="image"]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        showImageToolbar(el);
      });
    });

    doc.querySelectorAll('[data-edit-bg="1"]').forEach((el) => {
      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-edit-type="text"]') || e.target.closest('a') || e.target.closest('[data-edit-type="image"]') || e.target.closest('.section-drag-handle')) return;
        showBgToolbar(el);
      });
    });

    doc.addEventListener('click', (e) => {
      if (!e.target.closest('[data-edit-id]')) hideAllToolbars();
    });
  });

  function showFormatToolbar(el) {
    hideAllToolbars();
    activeTextEl = el;
    formatToolbar.classList.remove('hidden');
    const iframeRect = iframe.getBoundingClientRect();
    const targetRect = el.getBoundingClientRect();
    positionToolbar(formatToolbar, targetRect, iframeRect);
  }

  function showImageToolbar(el) {
    hideAllToolbars();
    activeImageEl = el;
    imageToolbar.classList.remove('hidden');
    const iframeRect = iframe.getBoundingClientRect();
    const targetRect = el.getBoundingClientRect();
    positionToolbar(imageToolbar, targetRect, iframeRect);
  }

  function showBgToolbar(el) {
    hideAllToolbars();
    activeBgEl = el;
    bgToolbar.classList.remove('hidden');
    const iframeRect = iframe.getBoundingClientRect();
    const targetRect = el.getBoundingClientRect();
    positionToolbar(bgToolbar, targetRect, iframeRect);
    const currentBg = iframe.contentWindow.getComputedStyle(el).backgroundColor;
    bgColorPicker.value = rgbToHex(currentBg) || '#ffffff';
  }

  function rgbToHex(rgb) {
    const m = rgb.match(/\d+/g);
    if (!m) return null;
    return '#' + m.slice(0, 3).map((x) => parseInt(x).toString(16).padStart(2, '0')).join('');
  }

  formatToolbar.querySelectorAll('.toolbar-btn[data-cmd]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!activeTextEl) return;
      pushHistory(captureState(activeTextEl, 'text'));
      iframe.contentDocument.execCommand(btn.dataset.cmd, false, null);
      queueEdit(activeTextEl.dataset.editId, 'text', { html: activeTextEl.innerHTML, text: activeTextEl.textContent });
    });
  });

  textColorPicker.addEventListener('input', () => {
    if (!activeTextEl) return;
    if (!textColorPicker.dataset.historyCaptured) {
      textColorPicker.dataset.historyCaptured = '1';
      pushHistory(captureState(activeTextEl, 'text'));
    }
    iframe.contentDocument.execCommand('foreColor', false, textColorPicker.value);
    queueEdit(activeTextEl.dataset.editId, 'text', { html: activeTextEl.innerHTML, text: activeTextEl.textContent });
  });
  textColorPicker.addEventListener('change', () => { delete textColorPicker.dataset.historyCaptured; });

  clearFormatBtn.addEventListener('click', () => {
    if (!activeTextEl) return;
    pushHistory(captureState(activeTextEl, 'text'));
    iframe.contentDocument.execCommand('removeFormat', false, null);
    queueEdit(activeTextEl.dataset.editId, 'text', { html: activeTextEl.innerHTML, text: activeTextEl.textContent });
  });

  bgColorPicker.addEventListener('input', () => {
    if (!activeBgEl) return;
    if (!bgColorPicker.dataset.historyCaptured) {
      bgColorPicker.dataset.historyCaptured = '1';
      pushHistory(captureState(activeBgEl, 'bg_color'));
    }
    activeBgEl.style.background = bgColorPicker.value;
    queueEdit(activeBgEl.dataset.editId, 'bg_color', { color: bgColorPicker.value });
  });
  bgColorPicker.addEventListener('change', () => { delete bgColorPicker.dataset.historyCaptured; });

  function uploadReplacementImage(file) {
    if (!activeImageEl) return;
    const formData = new FormData();
    formData.append('image', file);
    formData.append('slot', 'edit');
    formData.append('site_id', siteId);
    formData.append('csrf_token', csrfToken);
    setSaveStatus('Uploading image...');
    fetch('/api/upload-image.php', { method: 'POST', body: formData })
      .then((r) => r.json())
      .then((data) => {
        if (data.success) {
          pushHistory(captureState(activeImageEl, 'image'));
          activeImageEl.setAttribute('src', data.url);
          queueEdit(activeImageEl.dataset.editId, 'image', { url: data.url });
          hideAllToolbars();
        } else {
          setSaveStatus(data.error || 'Upload failed');
        }
      })
      .catch(() => setSaveStatus('Upload failed'));
  }

  imageDropzone.addEventListener('click', () => imageFileInput.click());
  imageFileInput.addEventListener('change', (e) => {
    if (e.target.files[0]) uploadReplacementImage(e.target.files[0]);
  });
  ['dragenter', 'dragover'].forEach((evt) => {
    imageDropzone.addEventListener(evt, (e) => { e.preventDefault(); imageDropzone.classList.add('dragover'); });
  });
  ['dragleave', 'drop'].forEach((evt) => {
    imageDropzone.addEventListener(evt, (e) => { e.preventDefault(); imageDropzone.classList.remove('dragover'); });
  });
  imageDropzone.addEventListener('drop', (e) => {
    if (e.dataTransfer.files[0]) uploadReplacementImage(e.dataTransfer.files[0]);
  });

  function initSectionReordering(doc) {
    const sections = Array.from(doc.querySelectorAll('[data-sortable-section="1"]'));
    let draggedEl = null;

    sections.forEach((section) => {
      section.setAttribute('draggable', 'false');

      const handle = doc.createElement('div');
      handle.className = 'section-drag-handle';
      handle.innerHTML = '&#9776;';
      handle.setAttribute('draggable', 'true');
      section.style.position = section.style.position || 'relative';
      section.appendChild(handle);

      handle.addEventListener('dragstart', (e) => {
        draggedEl = section;
        section.classList.add('section-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', section.dataset.editId); } catch (err) {}
      });
      handle.addEventListener('dragend', () => {
        section.classList.remove('section-dragging');
        sections.forEach((s) => s.classList.remove('section-drop-target'));
        saveSectionOrder(doc);
      });

      section.addEventListener('dragover', (e) => {
        if (!draggedEl || draggedEl === section) return;
        e.preventDefault();
        section.classList.add('section-drop-target');
      });
      section.addEventListener('dragleave', () => {
        section.classList.remove('section-drop-target');
      });
      section.addEventListener('drop', (e) => {
        e.preventDefault();
        section.classList.remove('section-drop-target');
        if (!draggedEl || draggedEl === section) return;
        const parent = section.parentNode;
        const rect = section.getBoundingClientRect();
        const insertAfter = (e.clientY - rect.top) > rect.height / 2;
        if (insertAfter) {
          parent.insertBefore(draggedEl, section.nextSibling);
        } else {
          parent.insertBefore(draggedEl, section);
        }
      });
    });
  }

  function saveSectionOrder(doc) {
    const sections = Array.from(doc.querySelectorAll('[data-sortable-section="1"]'));
    const order = sections.map((s) => s.dataset.editId);
    setSaveStatus('Saving order...');
    fetch('/api/reorder-site-sections.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ site_id: siteId, page: currentPage, order, csrf_token: csrfToken }),
    })
      .then((r) => r.json())
      .then((data) => setSaveStatus(data.success ? 'Saved' : (data.error || 'Save failed')))
      .catch(() => setSaveStatus('Save failed'));
  }

});
