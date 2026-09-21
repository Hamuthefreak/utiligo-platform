/**
 * assets/js/ui-theme.js — the motion half of theme.css.
 *
 * Four jobs, all of them things CSS cannot do on its own:
 *
 *   1. Fade-and-rise the sections that carry [data-reveal] as they scroll in.
 *   2. Count a [data-count] figure up when it first enters the viewport.
 *   3. Keep the sticky nav's appearance in step with the scroll position.
 *   4. Hand revenue_calc.js a `utlTween()` so a slider drag counts rather than
 *      snapping between values.
 *
 * Loaded deferred from every layout, next to theme.css. It is written to be
 * additive: if it never runs, nothing is hidden (the reveal gate lives in the
 * inline script in <head>, not here) and every number is already in the markup
 * at its final value. Nothing on any page depends on this file having loaded.
 */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── 1 + 2. Reveal and count-up ─────────────────────────────────────
     One observer for both. IntersectionObserver is used rather than a
     scroll listener because the reveal has to fire the moment an element
     is *where the eye is*, not on the next scroll tick — and because a
     scroll handler that measures 40 sections per frame is the classic way
     a marketing page ends up feeling heavy.

     Everything unobserves itself once it has fired: these are one-shot
     entrances, and re-animating on scroll-up is the thing that makes a
     site feel like a slideshow instead of a page. */
  function observeAll() {
    /* Groups stagger their own children (a card grid, a feature row) so a
       three-up does not pop as one block. Each child's delay is written
       straight into --d, which is what the reveal transition reads — no
       nth-child bookkeeping in the stylesheet, and it survives the markup
       being reordered.

       This runs BEFORE the target query below, because a child that is only
       given [data-reveal] here would otherwise never be observed and would
       stay at opacity 0 forever. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-reveal-group]'), function (group) {
      Array.prototype.forEach.call(group.children, function (child, i) {
        child.classList.add('utl-reveal');
        if (!child.style.getPropertyValue('--d')) {
          child.style.setProperty('--d', (i * 70) + 'ms');
        }
      });
    });

    /* Both hooks are honoured. `utl-reveal` is the class the stylesheet
       animates; `data-reveal` is the markup-friendly alias for it, and it is
       normalised to the class here. Selecting only the attribute — which is
       what this did first — left every element that used the class the
       *stylesheet* reads sitting at opacity 0 with nothing observing it: the
       hero rendered as an empty glass box. If you add a third spelling of this
       later, add it here too, because this line is the single place the two
       names are reconciled. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-reveal]'), function (el) {
      el.classList.add('utl-reveal');
    });

    var targets = document.querySelectorAll('.utl-reveal, [data-count]');
    if (!targets.length) return;

    if (!('IntersectionObserver' in window)) {
      // No observer (or an ancient browser): show everything immediately.
      Array.prototype.forEach.call(targets, function (el) {
        el.classList.add('is-in');
        finishCounts(el);
      });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;

        if (el.classList.contains('utl-reveal')) {
          el.classList.add('is-in');
        }
        if (el.hasAttribute('data-count')) {
          runCount(el);
        }
        io.unobserve(el);
      });
    /* The bottom margin pulls the trigger line up off the very bottom of
       the screen, so a section animates in while it is still entering
       rather than once it has already been read. */
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.08 });

    Array.prototype.forEach.call(targets, function (el) { io.observe(el); });

    /* Fail-safe. An entrance animation is never worth a blank page, and the
       failure is silent: the observer simply never fires for an element it
       can't see (a container with `overflow:hidden` and zero height, a
       threshold it can't reach, a browser quirk). Anything that is within the
       viewport once the page has finished loading, but still has no is-in, is
       shown outright. Anything below the fold is left to the observer, so the
       animation still happens where it can be seen. */
    window.addEventListener('load', function () {
      setTimeout(function () {
        Array.prototype.forEach.call(document.querySelectorAll('.utl-reveal:not(.is-in)'), function (el) {
          if (el.getBoundingClientRect().top < window.innerHeight) {
            el.classList.add('is-in');
            finishCounts(el);
          }
        });
      }, 900);
    });
  }

  function finishCounts(el) {
    if (el.hasAttribute('data-count')) {
      el.textContent = formatCount(Number(el.getAttribute('data-count')), el);
    }
  }

  function formatCount(value, el) {
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
    var prefix   = el.getAttribute('data-prefix') || '';
    var suffix   = el.getAttribute('data-suffix') || '';
    return prefix + value.toLocaleString(undefined, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    }) + suffix;
  }

  /* Counting from zero is only right for a figure you have never seen. A
     number that is being *replaced* (the revenue slider) tweens from its
     current value instead — see utlTween below — which is what makes a
     drag feel like turning a dial. */
  function runCount(el) {
    var target = Number(el.getAttribute('data-count'));
    if (!isFinite(target)) return;

    if (reduceMotion) {
      el.textContent = formatCount(target, el);
      return;
    }

    var duration = parseInt(el.getAttribute('data-count-duration') || '1100', 10);
    // A price has cents and must keep them for the whole count — rounding
    // every frame would show $22 on the way to $21.99.
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
    var step10 = Math.pow(10, decimals);
    var from = 0;
    var start = null;

    function step(now) {
      if (start === null) start = now;
      var t = Math.min(1, (now - start) / duration);
      // easeOutCubic: quick off the line, gentle at the end. A linear count
      // looks like a spinner; this looks like a number arriving.
      var eased = 1 - Math.pow(1 - t, 3);
      var shown = Math.round((from + (target - from) * eased) * step10) / step10;
      el.textContent = formatCount(shown, el);
      if (t < 1) requestAnimationFrame(step);
      else el.textContent = formatCount(target, el);
    }
    requestAnimationFrame(step);
  }

  /* ── Shared tween ───────────────────────────────────────────────────
     Used by revenue_calc.js: move a displayed figure from wherever it is
     now to a new value over ~420ms, and pulse the element on the way. If
     a drag arrives mid-tween the running one is cancelled, so the number
     always follows the thumb instead of queuing up behind it. */
  var running = new WeakMap();

  function utlTween(el, to, opts) {
    if (!el) return;
    opts = opts || {};
    var decimals = opts.decimals !== undefined
      ? opts.decimals
      : parseInt(el.getAttribute('data-decimals') || '0', 10);
    var prefix = opts.prefix !== undefined ? opts.prefix : (el.getAttribute('data-prefix') || '');
    var suffix = opts.suffix !== undefined ? opts.suffix : (el.getAttribute('data-suffix') || '');
    var fmt = function (v) {
      return prefix + v.toLocaleString(undefined, {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      }) + suffix;
    };

    var previous = running.get(el);
    if (previous && previous.raf) cancelAnimationFrame(previous.raf);

    var current = previous ? previous.value : parseFloat(String(el.textContent).replace(/[^0-9.\-]/g, '')) || 0;

    if (reduceMotion) { el.textContent = fmt(to); running.delete(el); return; }

    var duration = opts.duration || 420;
    var start = null;
    var state = { value: current, raf: 0 };

    function step(now) {
      if (start === null) start = now;
      var t = Math.min(1, (now - start) / duration);
      var eased = 1 - Math.pow(1 - t, 3);
      state.value = current + (to - current) * eased;
      el.textContent = fmt(state.value);
      if (t < 1) { state.raf = requestAnimationFrame(step); }
      else { el.textContent = fmt(to); running.delete(el); }
    }
    state.raf = requestAnimationFrame(step);
    running.set(el, state);

    // The pulse is restarted by removing and re-adding the class in a
    // forced-reflow gap; without it the browser coalesces the two writes
    // and a second drag in quick succession shows nothing at all.
    if (opts.pulse !== false) {
      el.classList.remove('utl-pulse');
      void el.offsetWidth;
      el.classList.add('utl-pulse');
      setTimeout(function () { el.classList.remove('utl-pulse'); }, 700);
    }
  }

  /* ── 2b. Themes ─────────────────────────────────────────────────────
     The attributes are applied by the inline bootstrap in <head>, before the
     first paint, because a theme that arrives after paint is a flash of the
     wrong palette on every navigation. Everything here is the *afterwards*:
     reading the current choice, changing it, and keeping the settings panel in
     step. The catalogue lives in includes/appearance.php and reaches this file
     through the panel's data-keys attribute, so there is one list of theme names
     in the product rather than two that drift. */
  var THEME_KEY = 'utiligo_theme';
  var ACCENT_KEY = 'utiligo_accent';

  var readPref = function (key, allowed) {
    try {
      var v = window.localStorage.getItem(key);
      return allowed.indexOf(v) > -1 ? v : null;
    } catch (e) { return null; }        // private mode, or storage disabled
  };

  function currentTheme() {
    return document.documentElement.getAttribute('data-theme') || 'midnight';
  }
  function currentAccent() {
    return document.documentElement.getAttribute('data-accent') || 'green';
  }

  /**
   * Apply a choice: attribute on <html>, remember it, tell anyone listening.
   * `midnight`/`green` are stored as real values rather than removed, so a
   * deliberate choice of the default is still a choice and survives a later
   * change of the defaults.
   */
  function applyTheme(theme, accent, keys) {
    keys = keys || { themes: ['midnight', 'graphite', 'paper'], accents: ['green', 'blue', 'violet', 'amber'] };
    var root = document.documentElement;

    if (keys.themes.indexOf(theme) > -1) {
      if (theme === 'midnight') root.removeAttribute('data-theme');
      else root.setAttribute('data-theme', theme);
    }
    if (keys.accents.indexOf(accent) > -1) {
      if (accent === 'green') root.removeAttribute('data-accent');
      else root.setAttribute('data-accent', accent);
    }

    try {
      window.localStorage.setItem(THEME_KEY, theme);
      window.localStorage.setItem(ACCENT_KEY, accent);
    } catch (e) { /* a preference we cannot save is still one we can honour */ }

    try {
      window.dispatchEvent(new CustomEvent('utligo:theme', { detail: { theme: theme, accent: accent } }));
    } catch (e) { /* CustomEvent is missing only on engines that do not need this */ }
  }

  /* ── The picker(s) ──────────────────────────────────────────────────
     There are two of these in the product and they are deliberately different
     sizes: the settings panel, which each theme card explains itself in, and the
     row of chips in the public footer (see appearance_switcher_html in
     includes/appearance.php). They share every attribute and this one wiring —
     `[data-appearance]` is the contract, and the optional bits (the status line,
     the reset button, the visible theme names) are looked up rather than
     required. A second copy of this logic for the small one is how the two would
     end up disagreeing about what aria-checked means. */
  function initAppearance() {
    var panels = document.querySelectorAll('[data-appearance]');
    Array.prototype.forEach.call(panels, wireAppearance);
  }

  function wireAppearance(panel) {
    var keys = { themes: ['midnight', 'graphite', 'paper'], accents: ['green', 'blue', 'violet', 'amber'] };
    try { keys = JSON.parse(panel.getAttribute('data-keys')) || keys; } catch (e) {}

    var themeBtns  = Array.prototype.slice.call(panel.querySelectorAll('[data-theme-option]'));
    var accentBtns = Array.prototype.slice.call(panel.querySelectorAll('[data-accent-option]'));
    var status     = panel.querySelector('[data-appearance-status]');
    var names      = {};
    themeBtns.forEach(function (b) {
      // The full card names the theme in .app-name; the compact chip in
      // .utl-switch__name, which is hidden on a phone and so must not be relied on
      // for anything but the *name* — each button also carries a title. */
      var label = b.querySelector('.app-name') || b.querySelector('.utl-switch__name');
      names[b.getAttribute('data-theme-option')] = label ? label.textContent.trim() : '';
    });

    /**
     * Paint the panel to match the document.
     *
     * The accent group is switched OFF under Graphite rather than hidden: the
     * theme is monochrome by design (a rule in theme.css enforces it even if a
     * stored accent survives), and a picker that silently does nothing is worse
     * than one that says why. */
    function paint() {
      var theme = currentTheme(), accent = currentAccent();
      var mono = theme === 'graphite';

      themeBtns.forEach(function (b) {
        b.setAttribute('aria-checked', String(b.getAttribute('data-theme-option') === theme));
      });
      accentBtns.forEach(function (b) {
        b.setAttribute('aria-checked', String(!mono && b.getAttribute('data-accent-option') === accent));
        b.setAttribute('aria-disabled', String(mono));
        b.classList.toggle('app-off', mono);
      });

      if (status) {
        status.textContent = mono
          ? names[theme] + ' is monochrome — the accent does not apply to it.'
          : names[theme] + ' with a ' + accent + ' accent.';
      }
      if (panel.hasAttribute('data-appearance-compact')) {
        panel.setAttribute('data-current', theme);
      }
    }

    function choose(theme, accent) {
      applyTheme(theme, accent, keys);
      paint();
    }

    themeBtns.forEach(function (b) {
      b.setAttribute('tabindex', b.getAttribute('aria-checked') === 'true' ? '0' : '-1');
    });

    themeBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        choose(b.getAttribute('data-theme-option'), currentAccent());
      });
      // A radio group is one tab stop, and the arrows move within it. Anything
      // less and this is three tab stops that announce themselves as radios.
      b.addEventListener('keydown', function (e) {
        var step = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1
                 : e.key === 'ArrowLeft'  || e.key === 'ArrowUp'   ? -1 : 0;
        if (!step) return;
        e.preventDefault();
        var i = themeBtns.indexOf(b);
        var next = themeBtns[(i + step + themeBtns.length) % themeBtns.length];
        themeBtns.forEach(function (o) { o.setAttribute('tabindex', '-1'); });
        next.setAttribute('tabindex', '0');
        next.focus();
        choose(next.getAttribute('data-theme-option'), currentAccent());
      });
    });

    accentBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.getAttribute('aria-disabled') === 'true') return;
        choose(currentTheme(), b.getAttribute('data-accent-option'));
      });
    });

    var reset = panel.querySelector('[data-appearance-reset]');
    if (reset) {
      reset.addEventListener('click', function () { choose('midnight', 'green'); });
    }

    paint();
  }

  /* ── 2c. Resolved tokens, for the two surfaces CSS cannot paint ──────
     A canvas is not a stylesheet. `ctx.strokeStyle = 'var(--ink-3)'` is an
     invalid colour, and the assignment is silently IGNORED rather than throwing
     — so a chart drawn from tokens would render in whatever fillStyle happened
     to be there before, which is black. The charts therefore ask for the same
     tokens the rest of the page uses, resolved to a real colour, and re-read
     them when the theme changes.

     `alpha()` exists because a resolved token is an opaque colour and the
     charts need the same ink at 12% for a grid line and 22% for a gradient.
     Anything the browser cannot parse comes back as the caller's fallback, so a
     chart drawn before theme.css has loaded is still a chart. */
  function token(name, fallback) {
    try {
      var v = window.getComputedStyle(document.documentElement).getPropertyValue(name);
      return (v && v.trim()) || fallback || '';
    } catch (e) { return fallback || ''; }
  }

  function rgba(name, a, fallback) {
    var parts = token(name, fallback).match(/\d*\.?\d+/g);
    if (!parts || parts.length < 3) return token(name, fallback);
    return 'rgba(' + parts[0] + ',' + parts[1] + ',' + parts[2] + ',' + a + ')';
  }

  /** The handful of tokens a canvas needs, in one object, re-read on demand. */
  function palette() {
    return {
      ink:    token('--ink',      '#e9edf5'),
      ink2:   token('--ink-2',    '#a9b0bd'),
      ink3:   token('--ink-3',    '#7d8493'),
      ink4:   token('--ink-4',    '#5c6370'),
      hair:   token('--hair',     'rgba(255,255,255,.10)'),
      panel:  token('--panel-2',  '#141c31'),
      accent: token('--accent',   '#7fe3a8'),
      wash:   rgba('--ink', 0.22, '#e9edf5'),
      grid:   rgba('--ink', 0.06, '#e9edf5'),
      bar:    rgba('--ink', 0.55, '#e9edf5')
    };
  }

  /* ── The light on the material ──────────────────────────────────────
     The rim in theme.css lights up where the pointer is, which it can only do if
     something tells it where that is: this writes --mx/--my as the pointer
     crosses a glass surface, and clears them when it leaves. The band itself (the
     specular) is pure CSS; this is the *reflection* half — the part that makes a
     panel look like it is aware of the light rather than merely shaded.

     Delegated from the document rather than bound per element: there are 79 glass
     surfaces in the product and most of them are built by JS after boot (a lead
     card, a support ticket), so a per-element binding would have to be redone on
     every list render. One listener, one rAF per frame at most, and no work at all
     while the pointer is over something that is not glass.

     A finger is not a light source, so touch pointers are ignored: on a phone the
     rim falls back to its own static top-left highlight, which is correct — there
     is no hovering hand to reflect. */
  var GLASS = '.glass, .utl-card, .utl-glass, .sp-panel, .sp-launcher';

  function initSpecular() {
    var active = null, queued = false, x = 0, y = 0;

    function place() {
      queued = false;
      if (!active) return;
      var r = active.getBoundingClientRect();
      if (!r.width || !r.height) return;      // hidden, or a detached node
      active.style.setProperty('--mx', (((x - r.left) / r.width) * 100).toFixed(1) + '%');
      active.style.setProperty('--my', (((y - r.top) / r.height) * 100).toFixed(1) + '%');
    }

    function release() {
      if (!active) return;
      active.style.removeProperty('--mx');
      active.style.removeProperty('--my');
      active = null;
    }

    document.addEventListener('pointermove', function (e) {
      if (e.pointerType === 'touch') return;
      var el = e.target && e.target.closest ? e.target.closest(GLASS) : null;
      if (el !== active) { release(); active = el; }
      if (!active) return;
      x = e.clientX; y = e.clientY;
      if (!queued) { queued = true; window.requestAnimationFrame(place); }
    }, { passive: true });

    // The pointer leaving the window would otherwise leave a highlight burning in
    // the last place it was.
    window.addEventListener('blur', release);
    document.addEventListener('pointerleave', release, { passive: true });
  }

  /* ── 3. Nav state ───────────────────────────────────────────────────
     Passive listener plus a read of scrollY, and the class is only
     touched when the state actually changes — so this costs nothing on
     the thousands of scroll events that do not flip it. */
  function initNav() {
    var nav = document.querySelector('.utl-nav');
    if (!nav) return;
    var scrolled = false;

    function update() {
      var next = window.scrollY > 8;
      if (next === scrolled) return;
      scrolled = next;
      nav.classList.toggle('is-scrolled', scrolled);
    }
    window.addEventListener('scroll', update, { passive: true });
    update();
  }

  /* ── Range fill ─────────────────────────────────────────────────────
     Webkit cannot style the filled portion of a track, so the accent
     gradient is drawn from a --pct custom property kept in step here.
     Re-run on input; it is a single string write. */
  function paintRange(el) {
    var min = parseFloat(el.min || '0');
    var max = parseFloat(el.max || '100');
    var val = parseFloat(el.value || '0');
    var pct = max === min ? 0 : ((val - min) / (max - min)) * 100;
    el.style.setProperty('--pct', pct.toFixed(2) + '%');
  }
  function initRanges(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('.utl-range'), function (el) {
      paintRange(el);
      if (el.dataset.utlRangeBound) return;
      el.dataset.utlRangeBound = '1';
      el.addEventListener('input', function () { paintRange(el); });
    });
  }
  function refresh() { initRanges(document); }

  /* ── Boot ───────────────────────────────────────────────────────────
     Deferred scripts run after the DOM is parsed, so there is no need to
     wait for DOMContentLoaded — except on pages that inject markup later
     (the support panel, a lead drawer), which call UtligoMotion.refresh(). */
  function boot() {
    observeAll();
    initNav();
    initRanges(document);
    initAppearance();
    initSpecular();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.UtligoMotion = {
    tween: utlTween,
    paintRange: paintRange,
    refresh: function () { refresh(); observeAll(); initAppearance(); },
    /* Exposed so a page can light a surface it just revealed without waiting for
       the pointer to move (the call-script dock does this on open). */
    focusLight: function (el, px, py) {
      if (!el) return;
      var r = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      el.style.setProperty('--mx', (((px - r.left) / r.width) * 100).toFixed(1) + '%');
      el.style.setProperty('--my', (((py - r.top) / r.height) * 100).toFixed(1) + '%');
    },
    reduced: !!reduceMotion,
    token: token,
    alpha: rgba,
    palette: palette
  };

  /* The theme controller, for anything that wants to offer the choice somewhere
     other than the settings panel — a command palette, a footer toggle, the
     welcome screen. The attributes themselves are set by the inline bootstrap in
     <head>; this is the same thing, on demand. */
  window.UtligoTheme = {
    current: function () { return { theme: currentTheme(), accent: currentAccent() }; },
    apply: function (theme, accent) { applyTheme(theme, accent); },
    reset: function () { applyTheme('midnight', 'green'); },
    /* Midnight and green are stored as the literal defaults, so a page that wants to
       know whether the visitor ever made a choice asks this. */
    isDefault: function () { return currentTheme() === 'midnight' && currentAccent() === 'green'; }
  };
})();
