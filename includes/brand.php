<?php
/**
 * includes/brand.php — the wordmark, rendered inline so it can follow the theme.
 *
 * THE BUG THIS EXISTS TO KILL
 * ───────────────────────────
 * assets/images/logo.svg is flat WHITE ink plus a blue dot, because every surface
 * it was drawn against was slate-950. That is fine until the product has a light
 * theme — and then the logo is white on white and simply is not there. It was
 * caught in the browser on the Paper theme, and it is invisible in a screenshot
 * review of the dark themes because the dark themes are exactly where a white
 * logo works.
 *
 * A stylesheet cannot fix an <img>. The file has its colours baked in and a page
 * cannot reach inside it, so the three options were: a second file plus a swap,
 * a filter (which would turn the blue dot orange), or this — put the paths in the
 * document, where `currentColor` and a CSS variable mean something.
 *
 * So the ink path is `currentColor` (white on the dark themes, black on Paper —
 * see .brand-logo in theme.css, which sets that one colour per theme) and the dot
 * after "Utiligo" is `var(--accent)`, which makes the full stop the one place the
 * visitor's accent lives in every page's chrome.
 *
 * The SVG file is still the source of truth: this reads it and rewrites the two
 * fills. Nothing is duplicated, and if the drawing is ever retraced the new paths
 * arrive here for free. The two fills it rewrites are pinned by
 * tests/cases/test_theme.php — if a retrace changes them, a test says so rather
 * than the logo silently going monochrome.
 */

/** The two fills in logo.svg, and what they become in the document. */
const BRAND_LOGO_FILLS = [
    '#ffffff' => 'currentColor',      // the wordmark
    '#3b82f6' => 'var(--accent)',     // the full stop
];

/** Whether there is a wordmark to draw. The layouts keep a fallback for when there is not. */
function brand_logo_exists(): bool
{
    return is_file(__DIR__ . '/../assets/images/logo.svg');
}

/**
 * The wordmark as inline SVG.
 *
 * $class is the sizing hook — every call site used a height utility on the <img>
 * (`h-8 w-auto`), and an inline SVG is sized the same way, so the existing layout
 * is untouched. The width/height attributes are dropped so the stylesheet decides;
 * that is also why the file's `role="img"` is replaced with a label, since a
 * decorative-but-labelled element inside a link should announce the link's name.
 */
function brand_logo(string $class = 'h-8 w-auto', string $label = 'Utiligo'): string
{
    static $markup = null;

    if ($markup === null) {
        $markup = '';
        $path = __DIR__ . '/../assets/images/logo.svg';
        $svg  = @file_get_contents($path);

        if ($svg !== false && $svg !== '') {
            // Strip the prolog and the comment, then hand the attributes over to
            // the class: this is a fragment being inlined, not a standalone file.
            $svg = preg_replace('/<\?xml.*?\?>/s', '', $svg);
            $svg = preg_replace('/<!--.*?-->/s', '', $svg);
            $svg = preg_replace('/\s(width|height)="\d+"/', '', $svg);
            $svg = preg_replace('/\srole="img"/', '', $svg);
            $svg = str_replace(array_keys(BRAND_LOGO_FILLS), array_values(BRAND_LOGO_FILLS), $svg);

            // A retrace that renames a fill would leave a white-on-white logo in the
            // light theme — the exact silent failure this file was written to end —
            // so refuse to inline it if the rewrite did not take, and let the call
            // site fall back to the icon instead.
            if (strpos($svg, 'currentColor') !== false) {
                $markup = trim($svg);
            }
        }
    }

    if ($markup === '') {
        return '';
    }

    return str_replace(
        '<svg ',
        '<svg class="brand-logo ' . htmlspecialchars($class, ENT_QUOTES) . '" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES) . '" focusable="false" ',
        $markup
    );
}
