<?php
/**
 * includes/appearance.php — the theme system's server half.
 *
 * THREE THEMES, FOUR ACCENTS, ONE ATTRIBUTE
 * ─────────────────────────────────────────
 * The whole feature is two attributes on <html>:
 *
 *   <html data-theme="midnight|graphite|paper" data-accent="green|blue|violet|amber">
 *
 * and assets/css/theme.css does the rest — every token the product draws with is
 * re-stated per theme, so no page markup knows a theme exists. This file holds the
 * parts that cannot live in CSS:
 *
 *   1. the catalogue (names, blurbs and swatches the picker renders),
 *   2. the PRE-PAINT bootstrap, which has to be an inline line in <head> — see
 *      appearance_bootstrap() — and
 *   3. the settings panel markup.
 *
 * WHY THE CHOICE IS IN localStorage AND NOT ON THE ACCOUNT
 * ────────────────────────────────────────────────────────
 * Deliberately: it is a property of the device as much as the person — a bright
 * office monitor wants a different theme from the same account at midnight — and
 * localStorage means the choice is applied before first paint with no round trip
 * and no flash. It also covers the marketing pages, which have no session. A
 * server-side copy is a reasonable next step; it would be a bonus, not the source
 * of truth.
 *
 * The keys are shared with assets/js/ui-theme.js. If you rename one, rename both;
 * tests/cases/test_theme.php asserts that the two lists agree.
 *
 * THE OTHER HALF OF THE DESIGN LAYER
 * ──────────────────────────────────
 * The material — Liquid Glass, the surface every panel in the product is made of
 * — lives in includes/glass.php, and it ships with this file so a shell has one
 * include to remember rather than two. It is a separate file because its subject
 * is different: this one is the *choice*, that one is the *material*, and the
 * refraction filters in it are markup rather than a setting.
 */
require_once __DIR__ . '/glass.php';

/** The themes, in the order the picker shows them. Midnight is the default. */
function appearance_themes(): array
{
    return [
        'midnight' => [
            'name'   => 'Midnight',
            'blurb'  => 'Near-black with a blue cast, one light accent, and light that drifts slowly across the background.',
            'note'   => 'The default. Built for long sessions in the dashboard.',
            'swatch' => ['#0a0f1e', '#1a2340', '#7fe3a8'],
        ],
        'graphite' => [
            'name'   => 'Graphite',
            'blurb'  => 'Black, white and grey, with no hue anywhere and the most glass — surfaces blur over what is behind them.',
            'note'   => 'Monochrome by design, so the accent picker does not apply.',
            'swatch' => ['#0b0b0c', '#26262a', '#ffffff'],
        ],
        'paper' => [
            'name'   => 'Paper',
            'blurb'  => 'Black ink on white. Crisp hairlines, soft shadows, and your accent deepened so it reads on a bright screen.',
            'note'   => 'The accent you pick is darkened for white.',
            'swatch' => ['#ffffff', '#dcdcdd', '#0f7a45'],
        ],
    ];
}

/** The accents. `hex` is the dark-theme value; Paper uses its own deepened set. */
function appearance_accents(): array
{
    return [
        'green'  => ['name' => 'Green',  'hex' => '#7fe3a8'],
        'blue'   => ['name' => 'Blue',   'hex' => '#6aa6ff'],
        'violet' => ['name' => 'Violet', 'hex' => '#b39cff'],
        'amber'  => ['name' => 'Amber',  'hex' => '#f0c674'],
    ];
}

function appearance_default_theme(): string
{
    return 'midnight';
}

function appearance_default_accent(): string
{
    return 'green';
}

/** Both lists, as JS-ready arrays. Shared with the panel and asserted in tests. */
function appearance_keys_js(): string
{
    return json_encode([
        'themes'  => array_keys(appearance_themes()),
        'accents' => array_keys(appearance_accents()),
        'store'   => ['theme' => 'utiligo_theme', 'accent' => 'utiligo_accent'],
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * The same choice, small enough for a footer.
 *
 * The full picker (appearance_panel_html) is a settings page: three cards tall
 * enough to explain themselves, and an accent row. That is right where someone
 * has deliberately gone looking for it, and far too much to put on every public
 * page. This is the other end of the same feature: one row of three names and
 * three accent dots, for a visitor deciding whether the product looks like
 * something they want to use — and for anyone who wants to change it without
 * opening a settings tab.
 *
 * It carries `data-appearance` as well, so ui-theme.js wires it with the same
 * code path as the panel rather than a second one that can drift: the elements
 * below wear the same `data-theme-option` / `data-accent-option` attributes and
 * the same aria-checked contract.
 */
function appearance_switcher_html(): string
{
    $keys = appearance_keys_js();

    $h  = '<div class="utl-switch utl-glass utl-glass--sm" data-appearance data-appearance-compact data-keys=\'' . $keys . '\'>';
    $h .= '<span class="utl-switch__label">Theme</span>';
    $h .= '<div class="utl-switch__row" role="radiogroup" aria-label="Theme">';
    foreach (appearance_themes() as $key => $t) {
        // The swatch is the theme's own three colours as a tiny diagonal, which
        // is the one preview that fits in a row 26px tall.
        $c = $t['swatch'];
        $h .= '<button type="button" class="utl-switch__opt" role="radio" aria-checked="false"'
            . ' data-theme-option="' . htmlspecialchars($key) . '"'
            . ' title="' . htmlspecialchars($t['name'] . ' — ' . $t['blurb']) . '">'
            . '<span class="utl-switch__chip" aria-hidden="true" style="background-image:linear-gradient(135deg, '
            . htmlspecialchars($c[0]) . ' 0 34%, ' . htmlspecialchars($c[1]) . ' 34% 67%, ' . htmlspecialchars($c[2]) . ' 67% 100%)"></span>'
            . '<span class="utl-switch__name">' . htmlspecialchars($t['name']) . '</span>'
            . '</button>';
    }
    $h .= '</div>';

    $h .= '<div class="utl-switch__row utl-switch__row--accent" role="radiogroup" aria-label="Accent colour">';
    foreach (appearance_accents() as $key => $a) {
        $h .= '<button type="button" class="utl-switch__dot" role="radio" aria-checked="false"'
            . ' data-accent-option="' . htmlspecialchars($key) . '"'
            . ' style="background:' . htmlspecialchars($a['hex']) . '"'
            . ' title="' . htmlspecialchars($a['name']) . ' accent"'
            . ' aria-label="' . htmlspecialchars($a['name']) . ' accent"></button>';
    }
    $h .= '<button type="button" class="utl-switch__reset" data-appearance-reset aria-label="Reset to the default theme">'
        . '<i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>';
    $h .= '</div>';
    $h .= '</div>';

    return $h;
}

/**
 * The script that has to run before the first paint.
 *
 * It is inline, and it is the reason the theme does not flash: a deferred file
 * runs after the page has already been painted once in the wrong palette, which
 * looks like a bug on every single navigation. It is also written to fail closed —
 * a browser with localStorage disabled by policy gets the default theme, not a
 * broken page — and it validates what it reads, because the value it applies goes
 * straight into a CSS attribute selector.
 *
 * It also sets the `js-reveal` gate, which has to happen pre-paint for the same
 * reason: theme.css hides [data-reveal] elements under that class, and a gate set
 * after the first paint hides content that was already visible.
 *
 * AND IT DECLINES TO SET THAT GATE ON A PHONE. A touch device gets the page with
 * no entrance animation at all: hiding content first so it can be faded in is work
 * done for nobody, on the device least able to spend it, and it is the one place
 * where a wrong judgement leaves a blank screen. ui-theme.js makes the same test
 * before it wires anything up, and the `LITE MOTION` block in theme.css is the
 * third copy, for a viewport that changes size afterwards. The failure mode of
 * them disagreeing is content stuck at opacity 0, which is why it fails closed:
 * if we cannot ask the browser, the gate is not set and everything renders.
 */
function appearance_bootstrap(): string
{
    $keys = appearance_keys_js();
    return '<script>(function(){var d=document.documentElement,K=' . $keys . ';'
        . 'try{var t=localStorage.getItem(K.store.theme),a=localStorage.getItem(K.store.accent);'
        . 'if(K.themes.indexOf(t)>-1)d.setAttribute("data-theme",t);'
        . 'if(K.accents.indexOf(a)>-1)d.setAttribute("data-accent",a);}catch(e){}'
        . 'try{var m=window.matchMedia;'
        . 'if(!m||!(m("(pointer: coarse)").matches||m("(max-width: 640px)").matches))d.classList.add("js-reveal");'
        . '}catch(e){}'
        . '})();</script>';
}

/**
 * The picker, for Settings → Appearance.
 *
 * Server-rendered like the rest of the panel: the three cards and the swatches are
 * real buttons with real labels, the current choice is marked with aria-checked,
 * and ui-theme.js only wires the clicks and records the result. A picker built by
 * JS would be an empty box until the script ran — on the very page whose job is to
 * let someone who dislikes the current look change it.
 */
function appearance_panel_html(): string
{
    $themes  = appearance_themes();
    $accents = appearance_accents();
    $keys    = appearance_keys_js();

    $h = '';
    $h .= '<div class="app-panel" data-appearance data-keys=\'' . $keys . '\'>';
    $h .= '<style>'
        . '.app-panel{max-width:44rem}'
        . '.app-sec{font-size:.65rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--ink-3);margin:0 0 .9rem}'
        . '.app-grid{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));margin-bottom:2.25rem}'
        . '.app-card{position:relative;text-align:left;padding:1rem;border:1px solid var(--hair);border-radius:12px;'
        . 'background:var(--fill-1);cursor:pointer;transition:border-color 200ms var(--ease),background-color 200ms var(--ease),transform 200ms var(--ease);font:inherit;color:inherit}'
        . '.app-card:hover{transform:translateY(-2px);border-color:var(--hair-2)}'
        . '.app-card[aria-checked="true"]{border-color:var(--accent-line);background:var(--accent-soft)}'
        . '.app-card:focus-visible{outline:2px solid var(--accent-line);outline-offset:2px}'
        . '.app-swatch{display:flex;height:34px;border-radius:7px;overflow:hidden;border:1px solid var(--hair);margin-bottom:.7rem}'
        . '.app-swatch i{flex:1}'
        . '.app-name{display:block;font-weight:700;font-size:.9375rem;margin-bottom:.3rem}'
        . '.app-blurb{display:block;font-size:.75rem;line-height:1.5;color:var(--ink-2)}'
        . '.app-note{display:block;font-size:.68rem;color:var(--ink-3);margin-top:.5rem}'
        . '.app-tick{position:absolute;top:.8rem;right:.85rem;font-size:.7rem;color:var(--accent);opacity:0;transition:opacity 160ms var(--ease)}'
        . '.app-card[aria-checked="true"] .app-tick{opacity:1}'
        . '.app-accents{display:flex;flex-wrap:wrap;gap:.6rem}'
        . '.app-dot{width:38px;height:38px;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0;'
        . 'box-shadow:inset 0 0 0 1px rgba(0,0,0,.25);transition:transform 160ms var(--ease),box-shadow 160ms var(--ease)}'
        . '.app-dot:hover{transform:scale(1.06)}'
        . '.app-dot[aria-checked="true"]{box-shadow:inset 0 0 0 1px rgba(0,0,0,.25),0 0 0 2px var(--canvas),0 0 0 4px var(--accent)}'
        . '.app-dot:focus-visible{outline:2px solid var(--accent-line);outline-offset:3px}'
        . '.app-off{opacity:.4;pointer-events:none}'
        . '.app-status{font-size:.75rem;color:var(--ink-3);margin-top:1rem;min-height:1.1rem}'
        . '.app-reset{background:none;border:0;color:var(--ink-3);font:inherit;font-size:.75rem;text-decoration:underline;cursor:pointer;padding:0;margin-top:.5rem}'
        . '.app-reset:hover{color:var(--ink)}'
        . '</style>';

    $h .= '<p class="app-sec">Theme</p>';
    $h .= '<div class="app-grid" role="radiogroup" aria-label="Theme">';
    foreach ($themes as $key => $t) {
        $h .= '<button type="button" class="app-card" role="radio" aria-checked="false" data-theme-option="' . htmlspecialchars($key) . '">'
            . '<span class="app-swatch" aria-hidden="true">';
        foreach ($t['swatch'] as $c) {
            $h .= '<i style="background:' . htmlspecialchars($c) . '"></i>';
        }
        $h .= '</span>'
            . '<i class="fa-solid fa-check app-tick" aria-hidden="true"></i>'
            . '<span class="app-name">' . htmlspecialchars($t['name']) . '</span>'
            . '<span class="app-blurb">' . htmlspecialchars($t['blurb']) . '</span>'
            . '<span class="app-note">' . htmlspecialchars($t['note']) . '</span>'
            . '</button>';
    }
    $h .= '</div>';

    $h .= '<p class="app-sec">Accent colour</p>';
    $h .= '<div class="app-accents" role="radiogroup" aria-label="Accent colour">';
    foreach ($accents as $key => $a) {
        $h .= '<button type="button" class="app-dot" role="radio" aria-checked="false"'
            . ' data-accent-option="' . htmlspecialchars($key) . '"'
            . ' style="background:' . htmlspecialchars($a['hex']) . '"'
            . ' title="' . htmlspecialchars($a['name']) . '"'
            . ' aria-label="' . htmlspecialchars($a['name']) . '"></button>';
    }
    $h .= '</div>';
    $h .= '<p class="app-blurb" style="margin-top:.9rem">The accent tints the buttons, the active row, the focus ring and the shadows.</p>';
    $h .= '<p class="app-status" data-appearance-status aria-live="polite"></p>';
    $h .= '<button type="button" class="app-reset" data-appearance-reset>Reset to the default theme</button>';
    $h .= '</div>';

    return $h;
}
