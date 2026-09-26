<?php
/**
 * The design layer — assets/css/theme.css, assets/js/ui-theme.js, and the shells
 * that load them.
 *
 * WHAT THIS FILE IS FOR
 * ─────────────────────
 * A palette pass is the easiest kind of work to undo by accident. Nothing here
 * fails loudly: a page simply ships one more violet badge, one more gradient
 * headline, one more copy of the error page with its own glow — and six months
 * later the product looks like five products again. Every assertion below is one
 * of the specific decisions the pass made, written down where a later change has
 * to trip over it.
 *
 * It is all static. None of it needs a database or a server, and none of it is a
 * pixel comparison: these are the invariants a person cannot see in a screenshot
 * until it is too late — load ORDER, a single definition of the canvas, one
 * rendering path for the error pages, and the two names the reveal animation is
 * spelled with.
 */

$root = dirname(__DIR__, 2);
$read = static function (string $rel) use ($root): string {
    $p = $root . '/' . $rel;
    return is_file($p) ? (string)file_get_contents($p) : '';
};
$has = static function (string $rel) use ($root): bool {
    return is_file($root . '/' . $rel);
};

$theme   = $read('assets/css/theme.css');
$motion  = $read('assets/js/ui-theme.js');

require_once $root . '/includes/appearance.php';

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. The design layer exists, and it is LAST in <head>
 *
 * This is the load-bearing one. theme.css settles arguments between style.css,
 * Tailwind's play CDN (which injects its stylesheet at runtime, into <head>, as
 * the last thing there) and each page's own <style>. Move the link up one line
 * and every override in it either loses on order or has to shout !important
 * twice — which is exactly the mess the file exists to prevent.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The design layer is loaded, and loaded last');

t_ok($theme !== '', 'assets/css/theme.css exists');
t_ok($motion !== '', 'assets/js/ui-theme.js exists');

$shells = [
    'includes/header.php'       => true,   // public site: has its own <style> first
    'includes/portal_layout.php' => true,
    'includes/admin_layout.php'  => true,
    'portal/site_editor.php'     => false, // app shell: no inline <style> before it
];

foreach ($shells as $shell => $hasInlineStyleBefore) {
    $src = $read($shell);
    t_ok($src !== '', "{$shell} is present");

    $cssAt   = strrpos($src, 'assets/css/theme.css');
    $styleAt = strrpos($src, 'assets/css/style.css');

    t_ok($cssAt !== false, "{$shell} loads theme.css");
    t_ok($styleAt !== false, "{$shell} loads style.css");
    t_ok($cssAt !== false && $styleAt !== false && $cssAt > $styleAt,
        "{$shell}: theme.css comes after style.css, so it can settle their differences");

    if ($hasInlineStyleBefore) {
        $closeAt = strrpos(substr($src, 0, $cssAt), '</style>');
        t_ok($closeAt !== false,
            "{$shell}: theme.css comes after the page's own <style> block, which is the whole point");
    }

    // The reveal gate and the stored theme both have to be applied by one inline
    // line in <head>, before the stylesheet that hides [data-reveal] elements — the
    // gate is emitted by appearance_bootstrap() (see includes/appearance.php), not
    // spelled out in the shell. So what the shell has to prove is that it calls the
    // bootstrap, in <head>, above the theme.css link. Set from the deferred script
    // instead and a slow connection paints the hero, then blinks it out to animate
    // it back in; set below the stylesheet and the same thing happens.
    $bootAt = strrpos($src, 'appearance_bootstrap()');
    t_ok($bootAt !== false, "{$shell} applies the pre-paint theme + reveal bootstrap");
    t_ok($bootAt !== false && $bootAt < $cssAt,
        "{$shell}: and applies it before theme.css, so nothing paints at opacity 0 and stays there");
    // The require path differs by one level for the app shell (site_editor.php is not
    // in the same directory as the layouts), so only the file and the call are pinned.
    t_like($src, "require_once __DIR__ . '/", "{$shell} requires the bootstrap");
    t_like($src, 'appearance.php', "{$shell} pulls it in from its one home");
    t_ok(strpos($src, 'defer src="<?= asset_url(\'/assets/js/ui-theme.js\') ?>"') !== false,
        "{$shell} loads ui-theme.js deferred, so it never blocks the page");
}

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. The reveal animation's TWO names agree
 *
 * The bug this pins: the stylesheet animates `.utl-reveal`, while the markup the
 * author writes is `[data-reveal]`. An earlier version of ui-theme.js queried only
 * the attribute and never added the class, so every element that used the class the
 * CSS actually reads was left hidden with no observer — the hero rendered as an
 * empty glass box on every page that used it. One line reconciles the two names,
 * and this is the assertion that keeps it there.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The reveal hook and the class it animates cannot drift apart');

t_like($theme, '.js-reveal .utl-reveal', 'the stylesheet gates the animation on .utl-reveal');
t_like($theme, 'body::before', 'and the canvas is still a lit layer');
t_like($motion, "querySelectorAll('[data-reveal]')", 'the script reads the data-reveal attribute');
t_like($motion, "classList.add('utl-reveal')", 'and adds the class the stylesheet reads');
t_like($motion, 'IntersectionObserver', 'the reveal is driven by an observer, not a scroll handler');
t_like($motion, 'window.UtligoMotion', 'the tween the revenue slider uses is exported');
t_like($read('assets/js/revenue_calc.js'), 'UtligoMotion', 'and the slider reads it rather than duplicating it');

// A reveal that can never be cancelled is the failure mode that leaves a blank
// page, so the motion file has to honour the OS setting and the stylesheet has to
// show everything outright when it is set.
t_like($motion, 'prefers-reduced-motion', 'ui-theme.js asks about reduced motion');
t_like($theme, 'prefers-reduced-motion', 'and theme.css has the matching escape hatch');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2b. Nothing loops, and a touch device is shown the page and not the show
 *
 * WHY THIS IS A TEST AND NOT A PREFERENCE. The first version of this layer ran a
 * specular sweep on every glass surface for as long as the page stayed open — 79
 * of them, 13 seconds each — and drifted a full-viewport layer underneath them,
 * which re-blurs every backdrop-filter on the page on every frame, forever. It
 * also staggered a grid's children by 70ms apiece and hid every section until an
 * observer fired, on a phone exactly as much as on a desktop. That is a slideshow
 * running behind a scroll, and it was reported from the one place that matters:
 * real devices dropping frames. The assertions below are the shape of the fix.
 * ──────────────────────────────────────────────────────────────────────────── */

// Comments are stripped before the "nothing loops" checks: the file explains what it
// used to do and why, and a test that cannot tell a comment from a rule would forbid
// documenting the reason. Section 8 applies the same rule to the @supports note.
$theme_rules = (string)preg_replace('#/\*.*?\*/#s', '', $theme);

t_section('Motion is one-shot or hover-bound — never a loop');

t_unlike($theme_rules, 'infinite', 'no rule in theme.css animates forever');
t_unlike($theme_rules, 'animation: utl-drift',
    'and the canvas no longer drifts — a moving layer re-blurs every panel above it, every frame');
t_like($theme, '@keyframes utl-spec', 'the specular band keeps its keyframes');
t_like($theme, '(hover: hover) and (pointer: fine)', 'but only a pointer can start it');
t_like($theme, 'animation: utl-spec 1.5s var(--ease-in-out, ease) 1;', 'and it crosses the surface once');
t_like($theme, 'prefers-reduced-motion: no-preference', 'a system asking for less motion never starts it at all');
t_unlike($theme_rules, '--spec-dur', 'the loop duration token went with the loop');
t_like($theme, '--spec-op', 'the band still has a per-theme opacity, so Paper can turn it down');
t_unlike($theme_rules, 'will-change',
    'and no standing will-change, which would promote every card to a layer of its own');

t_section('A touch device gets the page, with no entrance animation');

// One condition, three copies, and all three have to agree: the pre-paint gate
// (nothing may be hidden before the script has even parsed), ui-theme.js (no
// observer, no counter), and the stylesheet (for a viewport resized afterwards).
$boot = $read('includes/appearance.php');
t_like($boot, '(pointer: coarse)', 'the pre-paint gate asks whether this is a touch device');
t_like($boot, 'js-reveal', 'and only then sets the class that would hide a section');
t_like($motion, 'liteMotion', 'ui-theme.js makes the same judgement before it wires anything');
t_like($theme, '@media (pointer: coarse), (max-width: 640px)', 'and theme.css carries the same pair of conditions');
t_like($theme, 'transition: none !important', 'where the reveal is switched off outright');

// What the entrance is, on the device that still gets one.
t_like($theme, 'transition: opacity 220ms var(--ease-out);', 'the reveal is one short fade');
t_unlike($theme_rules, 'translateY(16px)', 'that no longer moves the content it fades in');
t_unlike($theme_rules, 'transition-delay', 'and no longer queues a grid child behind the one before it');
t_unlike($motion, "'--d'", 'the stagger machinery is gone from the script too');
t_unlike($motion, "querySelectorAll('[data-reveal-group]')", 'and nothing reads the group attribute any more');

/* ─────────────────────────────────────────────────────────────────────────────
 * 3. ONE canvas, ONE accent
 * ───────────────────────────────────────────────────────────────────────────── */

t_section('One canvas and one accent, defined once');

// One canvas PER THEME, and not one line more: three restatements of the same token
// name is the whole mechanism (a fourth would be a fourth theme, or a page painting
// its own). The list is derived from the stylesheet rather than typed out, so adding
// a theme without its surfaces fails here instead of shipping a half-themed one.
$themes = appearance_themes();
t_is(substr_count($theme, '--canvas:'), count($themes),
    'the canvas is declared exactly once per theme');
foreach (array_keys($themes) as $key) {
    $blockAt = $key === 'midnight' ? strpos($theme, ':root {') : strpos($theme, ':root[data-theme="' . $key . '"] {');
    t_ok($blockAt !== false, "theme.css states a block for {$key}");
    if ($blockAt === false) { continue; }
    $block = substr($theme, $blockAt, strpos($theme, '}', $blockAt) - $blockAt);
    foreach (['--canvas:', '--panel:', '--ink:', '--hair:', '--accent:'] as $token) {
        t_like($block, $token, "{$key} states {$token}");
    }
}

// Every accent is stated twice — once for the dark themes, once deeper for Paper —
// because a light tint that reads on near-black is illegible on white.
foreach (array_keys(appearance_accents()) as $key) {
    t_like($theme, ':root[data-accent="' . $key . '"]', "the {$key} accent has a dark value");
    t_like($theme, ':root[data-theme="paper"][data-accent="' . $key . '"]', "and a deeper one for Paper");
}

// Graphite is monochrome by design: the picker hides itself, and this rule is why a
// stored accent cannot leak a hue back in.
t_like($theme, ':root[data-theme="graphite"][data-accent]', 'Graphite overrides any accent with white');

// The surfaces that carry their own stylesheet used to hard-code their own
// near-black (#020817 in three places, #080c14 in a fourth) — four canvases that
// were all slightly different, which is what a page reads as "not the same site".
foreach (['assets/css/style.css', 'assets/css/onboarding.css', 'assets/css/call_scripts.css'] as $sheet) {
    $src = $read($sheet);
    t_unlike($src, '#020817', "{$sheet} no longer paints its own canvas");
    t_unlike($src, '#080c14', "{$sheet} has no second near-black either");
}

// The indigo this pass retired from the busiest page in the product.
$indigoLeft = [];
foreach (['portal/leads.php', 'portal/index.php', 'portal/billing.php', 'assets/css/theme.css'] as $f) {
    if (strpos($read($f), '129,140,248') !== false) {
        $indigoLeft[] = $f;
    }
}
t_is($indigoLeft, [], 'the retired indigo (129,140,248) appears in no shipped stylesheet or page');

// The accent has to reach the primary button, or every page keeps its own idea of
// what "the main action" looks like. This project's convention is
// `bg-white hover:bg-slate-200 text-black`, written about sixty times.
t_like($theme, 'a.bg-white', 'theme.css catches the project\'s primary-button convention');
t_like($theme, 'background-color: var(--accent) !important', 'and fills it with the accent');
t_like($theme, '--accent:', 'the accent itself is a token');

// Pills: a lozenge-shaped *control* is the loudest template tell on a dark UI.
t_like($theme, 'button.rounded-full', 'theme.css squares the pill-shaped controls');
t_like($theme, 'a.rounded-full', 'including the anchor ones the footers are full of');

/* ─────────────────────────────────────────────────────────────────────────────
 * 4. The hues that mean nothing are retired everywhere, not shade by shade
 *
 * Enumerating Tailwind shades is a losing game — `bg-purple-500/25` and
 * `text-blue-300` were both live in the product and both missing from the list.
 * The catch-all selectors are what makes the palette CLOSED, so this asserts the
 * three directions exist rather than that any particular shade is handled.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The no-meaning hues are retired in one place');

foreach (['text-purple-', 'text-indigo-', 'text-blue-', 'text-cyan-', 'text-violet-'] as $hue) {
    t_like($theme, '[class*="' . $hue . '"]', "a text rule covers {$hue}");
}
foreach (['bg-purple-', 'bg-indigo-', 'bg-blue-', 'bg-violet-'] as $hue) {
    t_like($theme, '[class*="' . $hue . '"]', "a background rule covers {$hue}");
}
foreach (['border-purple-', 'border-indigo-', 'border-blue-'] as $hue) {
    t_like($theme, '[class*="' . $hue . '"]', "a border rule covers {$hue}");
}

// …and the hover states that would otherwise be dead, because the catch-all catches
// the element at rest as well as on hover.
t_like($theme, '[class*="hover:bg-purple-"]:hover', 'hover fills the catch-all would have flattened are restored');

/* ─────────────────────────────────────────────────────────────────────────────
 * 5. One error page, not seven
 *
 * errors/404.php, root 404.php, notfound/{400,401,403,404,503}.php and
 * errors/error_page.php were seven hand-written copies of the same block, each
 * with its own gradient and its own blurred blob — and they had already drifted
 * apart before this pass touched them. .htaccess serves the notfound/ ones, so
 * those are the copies that matter.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Every error page renders through one template');

foreach (['notfound/400.php', 'notfound/401.php', 'notfound/403.php', 'notfound/404.php', 'notfound/503.php', 'errors/400.php', 'errors/404.php'] as $page) {
    $src = $read($page);
    t_ok($src !== '', "{$page} is present");
    t_like($src, 'error_page.php', "{$page} delegates to the shared template");
    t_unlike($src, 'bg-clip-text', "{$page} carries no gradient headline of its own");
}

t_ok($has('errors/error_page.php'), 'the shared template is where the others point');
t_ok(!$has('errors/error_styles.css.php'),
    'and the second stylesheet it used to pull in is gone, so there is no second palette');

// 500 is the one page that must not require config.php — a 500 can be the request
// where config is the thing that is broken.
$fallback = $read('notfound/500.php');
t_unlike($fallback, "require_once", 'notfound/500.php depends on nothing, on purpose');
t_unlike($fallback, 'linear-gradient', 'and its number is a flat plate rather than a gradient fill');
t_like($fallback, '#0a0f1e', 'painted on the product canvas');

/* ─────────────────────────────────────────────────────────────────────────────
 * 6. The "generated UI" tells are gone from the shipped chrome
 *
 * Gradient-clipped headlines and blurred colour blobs are the two things the pass
 * removed everywhere. site_builder.php and site_templates.php are excluded: those
 * render a CUSTOMER'S website, which is their brand, not ours.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The gradient headline and the glow blob are gone');

$chrome = [];
foreach (array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/portal/*.php') ?: [],
    glob($root . '/admin/*.php') ?: [],
    glob($root . '/includes/*.php') ?: [],
    glob($root . '/notfound/*.php') ?: [],
    glob($root . '/errors/*.php') ?: [],
    glob($root . '/assets/css/*.css') ?: []
) as $abs) {
    $rel = str_replace('\\', '/', substr($abs, strlen($root) + 1));
    if (in_array($rel, ['includes/site_builder.php', 'includes/site_templates.php', 'includes/mailer.php'], true)) {
        continue;   // customer sites and email templates: not our chrome
    }
    $chrome[$rel] = (string)file_get_contents($abs);
}

foreach (['bg-clip-text', 'blur-3xl', 'hover:scale-105'] as $tell) {
    $offenders = array_keys(array_filter($chrome, static fn($src) => strpos($src, $tell) !== false));
    t_is($offenders, [], "no app page or stylesheet still uses {$tell}");
}

t_ok(count($chrome) > 40, 'and the sweep actually read the pages it claims to have checked');

/* ─────────────────────────────────────────────────────────────────────────────
 * 7. The support bubble reads as the same product
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The support channel is a floating card on its own face, never the screen');

$support = $read('assets/css/support.css');

t_like($support, '--sp-font', 'the channel sets its own typeface in one variable');
t_like($support, "'Space Grotesk'", 'which is the display face the public site already loads');
t_like($support, 'backdrop-filter', 'and it is glass, like the nav and the hero');
t_like($support, 'max-height: min(560px', 'the panel grows with its content up to a ceiling');
// `max-height:` legitimately contains `height:`, so the assertion has to look for
// the fixed height at the START of a declaration rather than anywhere in the rule.
t_unlike($support, "\n  height: min(560px", 'rather than being a fixed 560px box with a line of text lost in the middle of it');

// On a phone it used to become a full-screen sheet, which throws away the page the
// customer was reading — the opposite of what a bubble is for.
t_like($support, '@media (max-width: 767px)', 'the phone rules are still here');
t_unlike($support, 'height: 100vh;', 'but a phone no longer gets a full-screen sheet');

/* ─────────────────────────────────────────────────────────────────────────────
 * 8. The material — Liquid Glass, in four layers
 *
 * A surface is glass only if all four are present, and three of them were added
 * in one pass: the lit body, the luminous rim, the travelling specular and the
 * refraction. Each is asserted separately, because dropping any one of them
 * leaves something that still looks like a panel and has stopped being glass —
 * which is exactly the kind of change that gets made by accident.
 * ──────────────────────────────────────────────────────────────────────────── */

$glass = $read('includes/glass.php');

t_section('The glass body is lit, not merely blurred');

// The single declaration that separates glass from a grey sheet: a blur that is
// saturated and brightened. Without the brightness the material reads as flat grey
// the moment it sits over something dark, which is where half this product lives.
t_like($theme, 'blur(var(--glass-blur)) saturate(var(--glass-sat)) brightness(var(--glass-bright))',
    'one declaration carries the blur, the saturation and the brightness');
t_like($theme, '--glass-fill:', 'the fill is a token, not a literal');
t_like($theme, 'backdrop-filter', 'and the whole thing is a backdrop filter');

// Named per theme, which is what makes the material a *choice* rather than a
// palette: Graphite leans on it and Paper nearly turns it off. The block is
// sliced the same way section 3 does it — from the selector to the next closing
// brace at column 0 — rather than re-read, so the two cannot disagree.
$material_block = static function (string $css, string $selector): string {
    $at = strpos($css, $selector . ' {');
    if ($at === false) { return ''; }
    $end = strpos($css, "\n}", $at);
    return $end === false ? '' : substr($css, $at, $end - $at);
};
foreach (['graphite', 'paper'] as $th) {
    $block = $material_block($theme, ':root[data-theme="' . $th . '"]');
    t_like($block, '--glass-blur:', "the {$th} theme states its own blur");
    t_like($block, '--glass-fill:', 'and its own fill');
}
t_ok(substr_count($theme, '--glass-fill:') >= 3, 'every theme states the material, not just the default');

t_section('The rim, the specular and the warp are all present');

// The rim is a 1px gradient ring, painted with a mask rather than with a border:
// a single-colour stroke is what makes a card read as a rectangle.
t_like($theme, 'mask-composite: exclude', 'the rim is a masked ring rather than an outline');
t_like($theme, 'padding: 1px;', 'exactly one pixel of it');
t_like($theme, 'border-color: transparent !important', 'and the utility borders are retired so the box does not move');

// The specular band: the one cue that tells an eye which has seen real glass that
// this is a surface rather than a translucent colour. It is a hover response rather
// than a loop now (see 2b), which is the only change the material itself has had.
t_like($theme, '@keyframes utl-spec', 'the specular band has its own keyframes');
t_like($theme, 'animation: utl-spec', 'bound to the surface the pointer is on');
t_like($theme, '--spec-op', 'at an opacity the theme controls, so Paper can switch it off');

// The warp: an SVG displacement of the backdrop, which no CSS function can do.
t_like($glass, 'feTurbulence', 'the refraction field is fractal noise');
t_like($glass, 'feDisplacementMap', 'and the backdrop is displaced along it');
t_like($glass, 'function glass_attr', 'a page opts in with an attribute');
t_like($glass, 'function glass_defs', 'and emits the filters it names');
t_like($theme, 'url(#utl-refract)', 'theme.css reaches for the filter');
t_like($theme, ':root[data-glass="refract"]', 'only on a page that emitted it');
// The assertion is on the CONDITION + brace, not on the words: the file explains
// this decision in a comment that names the at-rule, and a test that cannot tell
// a comment from a rule would forbid documenting the reason.
t_unlike($theme, '@supports (backdrop-filter: url(#utl-refract)) {',
    'and NOT behind an @supports test — Chromium reports url() as supported there and skips the block anyway');

// The pointer half of the material: the rim lights where the pointer is.
t_like($motion, '--mx', 'the surface is told where the light is');
t_like($motion, "e.pointerType === 'touch'", 'a finger is not a light source, so touch is ignored');
t_like($motion, 'requestAnimationFrame', 'and it costs at most one write per frame');

// Two fields, two sizes: an 18px bend across a 26px chip is a rendering bug.
t_like($glass, 'id="utl-refract-sm"', 'there is a smaller field for small surfaces');
t_like($theme, 'url(#utl-refract-sm)', 'which the chips and the launcher use');
t_like($theme, '.sp-panel', 'and the support panel refracted with the big one');

// Cost control, all three of them, because the failure mode is a fan spinning.
t_like($theme, '@media (pointer: coarse)', 'touch devices skip the warp');
t_like($theme, 'backdrop-filter: none;', 'glass inside glass drops its second blur');
t_like($theme, 'prefers-reduced-transparency', 'and the platform switch to reduce transparency is honoured');

/* ── 9. Every shell opts in, and loads the layer BEFORE the tag that uses it ── */

t_section('The material is switched on per page, and the include comes first');

// The regression this guards is real and was made while writing the material:
// glass_attr() is called on the <html> line, and a require that ran further down
// the file (where the design layer used to be loaded, in <head>) is a fatal error
// on every page in the product the first time anyone visits it.
foreach ([
    'includes/header.php',
    'includes/portal_layout.php',
    'includes/admin_layout.php',
    'portal/site_editor.php',
    'portal/call-scripts-window.php',
] as $shell) {
    $src = $read($shell);
    t_like($src, 'glass_attr()', "{$shell} opts into the refraction");
    t_like($src, 'glass_defs()', "{$shell} emits the filters it names");

    // `<html lang` and not `<html`: the comment above the tag explains itself in
    // prose that mentions <html>, and a substring search would count that.
    $reqAt  = strpos($src, 'appearance.php');
    $htmlAt = strpos($src, '<html lang');
    t_ok($reqAt !== false && $htmlAt !== false && $reqAt < $htmlAt,
        "{$shell} loads the design layer before the <html> tag that calls glass_attr()");
}

// The error page draws its own <html> only on the branch where the header is
// unavailable, and it must not emit the filters twice on the other one.
$err = $read('errors/error_page.php');
t_like($err, "glass_attr() : ''", 'the error page opts in only on the config-broken branch');
t_like($err, 'is_file(__DIR__', 'and checks the include exists before requiring it');

/* ── 10. The theme is reachable without an account ── */

t_section('All three themes are offered, and the same wiring drives both pickers');

t_like($read('includes/appearance.php'), 'function appearance_switcher_html', 'there is a compact switcher beside the settings panel');
t_like($read('includes/footer.php'), 'appearance_switcher_html', 'the public footer renders it');

// One code path, two sizes. If the compact version grows its own wiring they will
// disagree about what aria-checked means, and a picker that lies is worse than none.
t_like($motion, "querySelectorAll('[data-appearance]')", 'ui-theme.js wires every picker on the page');
t_like($read('includes/appearance.php'), 'data-theme-option', 'the compact switcher speaks the panel\'s attribute language');
t_like($read('includes/appearance.php'), 'data-accent-option', 'including the accent dots');
t_like($motion, 'localStorage.setItem', 'a choice is remembered');
t_like($motion, 'utligo:theme', 'and announced, so the charts can repaint without a reload');
t_like($theme, 'utl-switch__chip', 'and the compact switcher previews a theme in three colours');

// The names the picker offers are the names the stylesheet implements.
$themes  = array_keys(appearance_themes());
$accents = array_keys(appearance_accents());
t_same_list($themes, ['midnight', 'graphite', 'paper'], 'three themes, in the order the picker shows them');
foreach ($themes as $key) {
    if ($key !== 'midnight') {
        t_like($theme, ':root[data-theme="' . $key . '"]', "theme.css implements {$key}");
    }
}
foreach ($accents as $key) {
    t_like($theme, ':root[data-accent="' . $key . '"]', "theme.css implements the {$key} accent");
}
