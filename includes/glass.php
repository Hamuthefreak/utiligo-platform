<?php
/**
 * includes/glass.php — the Liquid Glass material's server half.
 *
 * WHAT LIQUID GLASS IS MADE OF
 * ────────────────────────────
 * A translucent panel is only glass if four things are true at once, and three of
 * them cannot be done in one CSS declaration:
 *
 *   1. THE BODY      — a strong backdrop blur (>20px) with saturation and a
 *                      brightness boost, so the material is lit rather than grey.
 *                      That is CSS, and it is in assets/css/theme.css.
 *
 *   2. THE REFRACTION — the warp. What is behind the panel bends as it passes
 *                      through, which is the difference between glass and a blur
 *                      filter. No CSS function displaces a backdrop, so the body
 *                      is distorted by an SVG filter: feTurbulence paints a smooth
 *                      noise field, and feDisplacementMap pushes every backdrop
 *                      pixel along it. That lives here, because it is a document
 *                      resource rather than a style.
 *
 *   3. THE SPECULAR   — the light that travels across the surface. Pure CSS
 *                      (see .utl-glass::before): a wide, low-opacity diagonal
 *                      band that drifts ambiently and snaps to the pointer on
 *                      hover.
 *
 *   4. THE RIM        — a luminous 1px edge that is brighter where the light
 *                      source is, never a flat stroke. Also CSS, on ::after.
 *
 * WHY THE FILTERS ARE SHIPPED AS MARKUP AND NOT AS A FILE
 * ─────────────────────────────────────────────────────
 * A `backdrop-filter: url(#id)` reference resolves inside the document. An
 * external .svg would need a fragment identifier, an extra request, and a CORS
 * header — and it would still be re-fetched by nothing but the first paint. This
 * is a few hundred bytes of static markup, emitted once per page from the shell.
 *
 * WHY THERE IS AN ATTRIBUTE AS WELL AS A FUNCTION
 * ──────────────────────────────────────────────
 * `backdrop-filter: url(#utl-refract)` on a page that never emitted the markup
 * below is not a harmless no-op: a filter reference that does not resolve makes
 * the whole declaration invalid, which would take the blur with it and leave a
 * flat grey rectangle. So the refraction is opt-in per page — glass_attr() goes on
 * <html>, glass_defs() after <body>, and the CSS only reaches for the filter when
 * both are present. A page that forgets both still gets real glass (blur, fill,
 * rim, specular); it just does not get the warp.
 *
 * WHERE IT IS USED
 * ────────────────
 * Every shell that emits a themed <body>: includes/header.php,
 * includes/portal_layout.php, includes/admin_layout.php, portal/site_editor.php,
 * portal/onboarding.php, portal/call-scripts-window.php, purchase-success.php.
 * The generated customer sites and the mail templates deliberately do not — they
 * are not our UI and must not inherit our design layer.
 */

/**
 * The `data-glass` attribute for the <html> tag.
 *
 * Returning the attribute rather than echoing a bare string keeps the shells
 * honest: `<?= glass_attr() ?>` is visibly a switch, and it is greppable, which is
 * how tests/cases/test_theme.php knows which shells opted in.
 */
function glass_attr(): string
{
    return 'data-glass="refract"';
}

/**
 * The two refraction filters, as a hidden SVG.
 *
 * The numbers are the whole effect and they are tuned per size, which is why
 * there are two:
 *
 *   utl-refract     big panels (a card, the hero, the support panel). Low
 *                   baseFrequency — roughly one smooth swell every 120px — and a
 *                   displacement of 18px, which is enough to bend a line behind a
 *                   panel without turning text that scrolls under it into soup.
 *
 *   utl-refract-sm  chips, buttons, the launcher, anything under ~120px tall. Same
 *                   noise, half the displacement: on a small surface a big warp
 *                   reads as a rendering bug rather than as thick glass.
 *
 * `feGaussianBlur` sits between the noise and the displacement on purpose. Raw
 * fractal noise has a fine grain, and displacing by it produces the speckled,
 * "boiling" look that gives cheap glass effects away. Blurring the field first
 * leaves the large shapes and drops the grain, so the surface reads as one liquid
 * body. The blur costs nothing measurable: it is applied to the noise field, not
 * to the backdrop.
 *
 * `seed` differs between the two so the swell does not line up when a small chip
 * happens to sit on top of a large panel.
 *
 * The `x/y/width/height` are the filter region. The default (-10% … 120%) clips
 * the displacement at the edges, and a clip on a backdrop filter shows up as a
 * hard seam along the panel's rim — the one thing this material cannot have.
 */
function glass_defs(): string
{
    return <<<'HTML'
<svg class="utl-glass-defs" aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden">
  <defs>
    <filter id="utl-refract" x="-25%" y="-25%" width="150%" height="150%" color-interpolation-filters="sRGB">
      <feTurbulence type="fractalNoise" baseFrequency="0.0075 0.012" numOctaves="2" seed="19" stitchTiles="stitch" result="field"/>
      <feGaussianBlur in="field" stdDeviation="1.4" result="smooth"/>
      <feDisplacementMap in="SourceGraphic" in2="smooth" scale="18" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
    <filter id="utl-refract-sm" x="-30%" y="-30%" width="160%" height="160%" color-interpolation-filters="sRGB">
      <feTurbulence type="fractalNoise" baseFrequency="0.012 0.018" numOctaves="2" seed="7" stitchTiles="stitch" result="field"/>
      <feGaussianBlur in="field" stdDeviation="1.1" result="smooth"/>
      <feDisplacementMap in="SourceGraphic" in2="smooth" scale="9" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
  </defs>
</svg>
HTML;
}

/**
 * Both halves, for a shell that wants the pair on one line.
 *
 * Not used by the shells themselves — they put glass_attr() on <html> and
 * glass_defs() inside <body>, which are different places in the file — but it is
 * what a page rendered *outside* a shell (a standalone report, an embed) needs,
 * and it is what the tests assert against.
 */
function glass_head(): string
{
    return glass_defs();
}
