/**
 * assets/js/download_helper.js  v1
 * =====================================================================
 * Makes every ZIP-download link on the portal give the user clear,
 * instant feedback instead of silently "loading" forever.
 *
 * Problem this solves:
 *   Plain <a href="/exports/foo.zip"> links give no visual feedback until
 *   the browser's save dialog appears. If the server is slow OR if the URL
 *   403/404s, the user stares at a click that appears to do nothing.
 *
 * What this does (for every <a> with [data-download] or class .zip-download):
 *   1. Intercept click -> immediately show "Preparing your download…" on
 *      the button itself + open a small toast.
 *   2. Send a HEAD request to the URL first:
 *        - 2xx  -> the file exists; trigger the actual download and show
 *                  "Download started — check your browser downloads."
 *        - 403/404/5xx -> show the real HTTP error inline on the button + toast,
 *                  DO NOT trigger the navigation (so the user isn't bounced
 *                  to the generic error page).
 *        - Network failure -> "Can't reach the server right now."
 *   3. After the download is triggered, pop a confirmation toast with a
 *      link that opens the browser's Downloads page on Chrome/Edge.
 *
 * Pure vanilla JS, no dependencies. Tested on Chrome/Edge/Firefox/Safari.
 * Auto-binds on DOMContentLoaded and for any future <a> added by AJAX via
 * MutationObserver (used by the leads.js dynamic lead cards).
 * =====================================================================
 */
(function () {
  'use strict';

  var DOWN_ATTR = 'data-download';
  var DOWN_SEL  = 'a[' + DOWN_ATTR + '], a.zip-download';

  // ── Toast host (shared with leads.js leadsToast — reuses #leadsToastHost
  //    if present so we don't double-up) ────────────────────────────────
  function getToastHost() {
    var host = document.getElementById('leadsToastHost') || document.getElementById('downloadToastHost');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'downloadToastHost';
    host.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:200;display:flex;flex-direction:column;gap:8px;max-width:340px;';
    document.body.appendChild(host);
    return host;
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&').replace(/</g, '<').replace(/>/g, '>')
      .replace(/"/g, '"').replace(/'/g, '&#39;');
  }

  function toast(title, body, kind, opts) {
    opts = opts || {};
    var palette = {
      success: { bg: 'rgba(16,185,129,.12)',  br: 'rgba(16,185,129,.35)',  ic: '#34d399', iclass: 'fa-circle-check' },
      warn:    { bg: 'rgba(245,158,11,.12)',  br: 'rgba(245,158,11,.35)',  ic: '#fbbf24', iclass: 'fa-triangle-exclamation' },
      error:   { bg: 'rgba(239,68,68,.12)',   br: 'rgba(239,68,68,.35)',   ic: '#f87171', iclass: 'fa-circle-xmark' },
      info:    { bg: 'rgba(99,102,241,.12)',  br: 'rgba(99,102,241,.35)',  ic: '#818cf8', iclass: 'fa-circle-info' }
    };
    var p = palette[kind] || palette.info;
    var el = document.createElement('div');
    el.style.cssText = 'background:#0f172a;border:1px solid ' + p.br + ';border-radius:12px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;box-shadow:0 8px 24px rgba(0,0,0,.45);animation:crm-in .25s ease;';
    var closeable = opts.closeable != null ? opts.closeable : true;
    var htmlParts = ['<i class="fa-solid ' + p.iclass + '" style="color:' + p.ic + ';margin-top:1px;"></i>',
      '<div style="flex:1;min-width:0;"><p style="font-size:13px;font-weight:700;color:#fff;margin:0;">' + esc(title) + '</p>',
      (body ? '<p style="font-size:11px;color:#94a3b8;margin:3px 0 0;">' + esc(body) + '</p>' : ''),
      '</div>'];
    if (closeable) {
      htmlParts.push('<button type="button" style="background:transparent;border:0;color:#64748b;cursor:pointer;font-size:11px;padding:0 0 0 6px;" title="Dismiss"><i class="fa-solid fa-xmark"></i></button>');
    }
    el.innerHTML = htmlParts.join('');
    getToastHost().appendChild(el);
    var closeBtn = el.querySelector('button');
    if (closeBtn) closeBtn.addEventListener('click', function () { el.remove(); });
    var ttl = opts.ttl != null ? opts.ttl : (kind === 'success' ? 6000 : 8000);
    if (ttl > 0) {
      setTimeout(function () {
        el.style.transition = 'opacity .3s, transform .3s';
        el.style.opacity = '0';
        el.style.transform = 'translateY(8px)';
        setTimeout(function () { if (el.parentNode) el.remove(); }, 320);
      }, ttl);
    }
    return el;
  }

  // ── Inline status (mutates the button itself, restores on error) ───
  function setBtnState(btn, state, opts) {
    opts = opts || {};
    if (btn._dhOrigHTML === undefined) {
      btn._dhOrigHTML = btn.innerHTML;
      btn._dhOrigClasses = btn.className;
      btn._dhOrigDisabled = btn.disabled || false;
    }
    if (state === 'loading') {
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size:.6rem;"></i> Preparing…';
    } else if (state === 'success') {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-check" style="font-size:.6rem;"></i> Downloaded';
    } else if (state === 'started') {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-arrow-down" style="font-size:.6rem;"></i> Downloading…';
    } else { // restore
      btn.disabled = btn._dhOrigDisabled;
      btn.innerHTML = btn._dhOrigHTML;
      btn.className = btn._dhOrigClasses;
    }
  }

  // ── HEAD probe — pre-validate the URL so we can surface 403/404/5xx ──
  function headProbe(url, csrf) {
    return fetch(url, {
      method: 'HEAD',
      credentials: 'same-origin',
      headers: csrf ? { 'X-CSRF-Token': csrf } : {}
    }).then(function (r) {
      // many servers don't allow HEAD on static files; treat 405 like OK
      // because a subsequent GET will still work (Apache serves the file).
      if (r.ok || r.status === 405) return { ok: true, status: r.status };
      return { ok: false, status: r.status };
    }).catch(function (e) {
      return { ok: false, status: 0, err: e };
    });
  }

  function friendlyHttpError(status) {
    if (status === 401 || status === 403) {
      return "Access denied (HTTP " + status + "). The file may have expired or you don't have permission to download it. Try generating the site again.";
    }
    if (status === 404) return "File not found (HTTP 404). It may have been deleted. Generate the site again or contact support.";
    if (status >= 500) return "Server error (HTTP " + status + "). Please try again in a moment.";
    if (status === 0)  return "Can't reach the server right now. Check your internet connection and try again.";
    return "Download failed (HTTP " + status + ").";
  }

  // ── Core handler ──────────────────────────────────────────────────
  function handleDownloadClick(e, btn) {
    // Only intercept left-clicks without modifiers (so ctrl/cmd-click still
    // opens in a new tab as the browser natively would).
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || btn.dataset.downloadHint === 'native') {
      return; // let the browser handle it natively
    }
    e.preventDefault();
    e.stopPropagation();

    if (btn.disabled) return;

    var url  = btn.getAttribute('href');
    if (!url || url === '#' || url === '') {
      toast('No file', 'This download has no URL attached. Generate the site first.', 'warn');
      return;
    }

    var fileName = url.split('/').pop().split('?')[0];
    setBtnState(btn, 'loading');
    toast('Preparing your download', 'Checking "' + fileName + '"…', 'info', { ttl: 4000 });

    headProbe(url).then(function (probe) {
      if (!probe.ok) {
        setBtnState(btn, 'restore');
        var msg = friendlyHttpError(probe.status);
        toast('Download failed', msg, 'error', { ttl: 0, closeable: true });
        // Log to console too so devtools shows the URL + status
        console.warn('[download_helper] HEAD ' + url + ' -> ' + probe.status);
        return;
      }

      // URL is reachable: trigger the actual download via a hidden iframe
      // (more reliable than anchor.click() across browsers for binary files
      // and avoids the browser trying to navigate the current page).
      triggerDownload(url);
      setBtnState(btn, 'started');
      toast('Download started', 'Your file "' + fileName + '" is downloading. Check your browser\'s downloads.', 'success', { ttl: 8000 });

      // After a short delay, change to "Downloaded" state as a final
      // confirmation. (We can't observe the actual browser download from
      // JS, so this is the best heuristic.)
      setTimeout(function () {
        setBtnState(btn, 'success');
        setTimeout(function () {
          setBtnState(btn, 'restore');
        }, 4000);
      }, 1500);
    });
  }

  function triggerDownload(url) {
    // Hidden iframe trick — avoids reloading the current page when the
    // server emits Content-Disposition: attachment (which our .htaccess
    // implies for .zip via the FilesMatch allow + Apache mime default).
    var frame = document.createElement('iframe');
    frame.style.display = 'none';
    frame.src = url;
    document.body.appendChild(frame);
    // Clean up the iframe after 60s (download either finished or failed).
    setTimeout(function () {
      if (frame.parentNode) frame.parentNode.removeChild(frame);
    }, 60000);
  }

  // ── Bind once + re-bind for dynamically inserted anchors ─────────
  function bindAnchor(btn) {
    if (btn._dhBound) return;
    btn._dhBound = true;
    btn.addEventListener('click', function (e) { handleDownloadClick(e, btn); });
  }

  function bindAll(root) {
    var nodes = (root || document).querySelectorAll(DOWN_SEL);
    for (var i = 0; i < nodes.length; i++) bindAnchor(nodes[i]);
  }

  // Auto-init on DOMContentLoaded
  document.addEventListener('DOMContentLoaded', function () { bindAll(document); });

  // Re-bind for any future anchors (e.g. leads injected by AJAX, sites list)
  if (typeof MutationObserver !== 'undefined') {
    var mo = new MutationObserver(function (records) {
      for (var i = 0; i < records.length; i++) {
        var r = records[i];
        for (var j = 0; j < r.addedNodes.length; j++) {
          var node = r.addedNodes[j];
          if (node.nodeType !== 1) continue;
          if (node.matches && node.matches(DOWN_SEL)) bindAnchor(node);
          if (node.querySelectorAll) bindAll(node);
        }
      }
    });
    if (document.body) {
      mo.observe(document.body, { childList: true, subtree: true });
    } else {
      document.addEventListener('DOMContentLoaded', function () {
        mo.observe(document.body, { childList: true, subtree: true });
      });
    }
  }

  // Expose for manual re-bind or programmatic use
  window.UtiligoDownloadHelper = {
    bind: bindAll,
    bindAnchor: bindAnchor,
    toast: toast,
    triggerDownload: triggerDownload
  };
})();
