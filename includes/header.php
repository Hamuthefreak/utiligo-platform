<?php
// The <body> tag below renders a data-plan-info attribute (limits + prices)
// that the marketing/onboarding JS reads instead of hardcoding plan copy.
// Pages like index.php don't otherwise need plans.php, so load it on demand.
if (!function_exists('plan_info_data_attr')) {
    require_once __DIR__ . '/plans.php';
}
// asset_url() lives in functions.php; load it on demand for the same reason.
if (!function_exists('asset_url')) {
    require_once __DIR__ . '/functions.php';
}

if (!isset($pageTitle)) { $pageTitle = 'Utiligo — Find Clients. Build Websites. Get Paid.'; }
$loggedIn = function_exists('is_logged_in') && is_logged_in();
// The wordmark is drawn INTO the page rather than loaded from a file, because a
// file with its colours baked in cannot follow the theme — a white logo on the
// light theme is a logo that is not there. See includes/brand.php.
require_once __DIR__ . '/brand.php';
// The design layer, loaded here rather than in <head> with the tags it feeds:
// glass_attr() is called on the <html> line below, and a require that ran after
// that output would be a fatal on every page in the product.
require_once __DIR__ . '/appearance.php';
$_has_logo = brand_logo_exists();
?>
<!DOCTYPE html>
<?php /* glass_attr() switches the refraction filters on for this page. It is the
         attribute the stylesheet looks for before it reaches for url(#utl-refract)
         — see includes/glass.php for why a page that never emitted the filters
         must not reference them. */ ?>
<html lang="en" <?= glass_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
// ── SEO meta layer (per-page defaults; pages override before including this) ──
$seoTitle       = $seoTitle       ?? $pageTitle;
$seoDescription = $seoDescription ?? 'Find local businesses without a website, generate a professional website in 60 seconds, and close more clients. Utiligo is the lead generation and website builder for freelancers and agencies.';
$seoType        = $seoType        ?? 'website';
$_seo_base      = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : 'https://utiligo.ca', '/');
$_seo_path      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($_seo_path === false) $_seo_path = '/';
$_seo_path      = preg_replace('/\.php$/i', '', $_seo_path);
if ($_seo_path !== '/' && $_seo_path !== '') $_seo_path = rtrim($_seo_path, '/');
if ($_seo_path === '') $_seo_path = '/';
$_seo_canonical = $seoCanonical ?? ($_seo_base . $_seo_path);
$seoImage       = $seoImage    ?? ($_seo_base . '/assets/images/og-cover.png');
if ($seoImage && strpos($seoImage, 'http') !== 0) $seoImage = $_seo_base . $seoImage;
?>
<title><?= htmlspecialchars($seoTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<meta name="theme-color" content="#020817">
<link rel="canonical" href="<?= htmlspecialchars($_seo_canonical, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="<?= htmlspecialchars($seoType) ?>">
<meta property="og:site_name" content="Utiligo">
<meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($_seo_canonical) ?>">
<meta property="og:image" content="<?= htmlspecialchars($seoImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Utiligo — Lead Generation & Website Builder">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($seoTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($seoImage) ?>">
<link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg">
<link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
<link rel="mask-icon" href="/assets/images/logo-mark.svg" color="#020817">
<?php
// ── JSON-LD structured data: Organization + WebSite always, plus any
//    page-specific graph (e.g. homepage adds FAQPage/Product) via $seo_json_ld. ──
$_seo_social = defined('SEO_SOCIAL_URLS') && is_array(SEO_SOCIAL_URLS)
    ? array_values(array_filter(SEO_SOCIAL_URLS, fn($u) => is_string($u) && strpos($u, 'http') === 0))
    : [];
$_seo_org = ['@type' => 'Organization', '@id' => $_seo_base . '/#organization', 'name' => 'Utiligo', 'url' => $_seo_base, 'logo' => ['@type' => 'ImageObject', 'url' => $seoImage]];
if ($_seo_social) $_seo_org['sameAs'] = $_seo_social;
$_seo_ld = array_filter(array_merge([
    $_seo_org,
    ['@type' => 'WebSite', '@id' => $_seo_base . '/#website', 'url' => $_seo_base, 'name' => 'Utiligo', 'publisher' => ['@id' => $_seo_base . '/#organization']],
], isset($seo_json_ld) && is_array($seo_json_ld) ? $seo_json_ld : []));
?>
<script type="application/ld+json"><?= json_encode($_seo_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php if (defined('ANALYTICS_GTAG_ID') && ANALYTICS_GTAG_ID): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars(ANALYTICS_GTAG_ID) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= htmlspecialchars(ANALYTICS_GTAG_ID) ?>',{anonymize_ip:true});</script>
<?php endif; ?>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
<style>
  .logo-wordmark {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 800;
    font-size: 1.15rem;
    letter-spacing: -0.03em;
    line-height: 1;
  }

  /* ─── Page Transition Loader ───────────────────────────────── */
  #utl-loader {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 20px;
    /* var() rather than a literal, so the canvas is declared in exactly one
       place (theme.css) even though this style block loads first — a custom
       property resolves at computed-value time, not in file order. */
    background: var(--canvas, var(--canvas));
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.18s ease;
  }
  #utl-loader.visible {
    opacity: 1;
    pointer-events: all;
  }

  #utl-progress-track {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 2px;
    background: var(--fill-2);
  }
  #utl-progress-bar {
    height: 100%;
    width: 0%;
    background: var(--accent, #7fe3a8);
    border-radius: 0 2px 2px 0;
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  }

  .utl-ring {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 2.5px solid var(--hair);
    border-top-color: var(--accent, #7fe3a8);
    animation: utl-spin 0.7s linear infinite;
  }
  @keyframes utl-spin {
    to { transform: rotate(360deg); }
  }

  #utl-loader .utl-brand {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 800;
    font-size: 1.1rem;
    letter-spacing: -0.03em;
    color: var(--ink-3);
  }

  body.page-ready > *:not(#utl-loader) {
    animation: utl-fadein 0.22s ease forwards;
  }
  @keyframes utl-fadein {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0);   }
  }
</style>
<?php /* ── The design layer ───────────────────────────────────────────────────
         theme.css must be the LAST stylesheet in <head>: it settles the
         palette, radius and motion arguments between style.css, Tailwind's
         CDN output and each page's own <style>, and it can only do that from
         the end of the queue. It is also the only thing this overhaul added
         to the product — remove these three tags and the site is exactly what
         it was.

         The inline line before it sets the `js-reveal` gate on <html>. It has
         to run here, during parsing and before the first paint, because the
         stylesheet uses that class to keep [data-reveal] elements hidden. If
         it were set by the deferred script instead, a slow connection would
         paint the hero fully, then blink it out to animating it back in. And
         if JS never runs at all, the class is simply absent and every section
         renders in its final state — the failure mode is "no animation",
         never "no content". */ ?>
<?php /* Already loaded above, before the <html> tag that glass_attr() writes. */ ?>
<?= appearance_bootstrap() ?>
<link rel="stylesheet" href="<?= asset_url('/assets/css/theme.css') ?>">
<script defer src="<?= asset_url('/assets/js/ui-theme.js') ?>"></script>
</head>
<body class="antialiased bg-slate-950 text-white" data-csrf="<?= function_exists('csrf_token') ? csrf_token() : '' ?>" <?= function_exists('plan_info_data_attr') ? plan_info_data_attr() : '' ?>>

<?= glass_defs() ?>

<!-- ─── Transition Loader Overlay ───────────────────────────── -->
<div id="utl-loader" role="status" aria-label="Loading" aria-live="polite">
  <div id="utl-progress-track"><div id="utl-progress-bar"></div></div>
  <div class="utl-ring"></div>
  <span class="utl-brand">Utiligo</span>
</div>

<nav class="sticky top-0 z-50 utl-nav">
  <div class="max-w-6xl mx-auto px-6 py-4 flex justify-between items-center">
    <a href="/" class="flex items-center gap-2">
      <?php if ($_has_logo): ?>
        <?= brand_logo('h-8 w-auto') ?>
      <?php else: ?>
        <i class="fa-solid fa-bolt text-white text-xl"></i>
      <?php endif; ?>
      <?php if (!$_has_logo): ?><span class="logo-wordmark text-white">Utiligo</span><?php endif; ?>
    </a>
    <div class="hidden md:flex gap-8 text-sm font-medium">
      <a href="/#how-it-works" class="utl-nav-link">How It Works</a>
      <a href="/#features"     class="utl-nav-link">Features</a>
      <a href="/#pricing"      class="utl-nav-link">Pricing</a>
      <a href="/#faq"          class="utl-nav-link">FAQ</a>
    </div>
    <div class="flex items-center gap-3">
      <?php if ($loggedIn): ?>
        <a href="/portal/index.php" class="utl-btn utl-btn--ghost utl-btn--sm">Dashboard</a>
        <a href="/logout.php" class="utl-nav-link text-sm">Logout</a>
      <?php else: ?>
        <a href="/login.php"    class="utl-nav-link text-sm">Log In</a>
        <a href="/register.php" class="utl-btn utl-btn--primary utl-btn--sm">Start Free</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
