<?php
/**
 * includes/portal_layout.php
 */
// Plan helpers feed the data-plan-info attribute on <body> and the paid-plan
// predicates used below. Portal pages normally load plans.php already; this
// on-demand load keeps the layout safe to include on its own.
if (!function_exists('plan_info_data_attr')) {
    require_once __DIR__ . '/plans.php';
}
// asset_url() lives in functions.php; load it on demand for the same reason.
if (!function_exists('asset_url')) {
    require_once __DIR__ . '/functions.php';
}

if (!isset($pageTitle)) { $pageTitle = 'Utiligo Portal'; }
$loggedIn  = function_exists('is_logged_in') && is_logged_in();
$_user     = function_exists('current_user')  ? current_user()  : [];
$_plan     = $_user['plan'] ?? 'free';
$_is_pro   = $_plan === 'pro';
$_is_ent   = $_plan === 'entrepreneur';
$_is_paid  = is_paid_plan($_plan);
$_name     = htmlspecialchars(trim($_user['full_name'] ?? 'User'));
$_initials = strtoupper(substr($_name, 0, 1));
$_path     = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$_is_admin = !empty($_user['is_admin']);

// Inline wordmark: see includes/brand.php — a logo file cannot follow the theme.
require_once __DIR__ . '/brand.php';
// The design layer, before the <html> tag below — glass_attr() is written there
// and a require that ran after it would be a fatal on every portal page.
require_once __DIR__ . '/appearance.php';
$_has_logo = brand_logo_exists();

$_plan_label = $_is_ent ? 'Entrepreneur' : ($_is_pro ? 'Pro' : 'Free');

if (!function_exists('_nav_active')) {
    function _nav_active(string $href, string $current): string {
        $clean_href    = preg_replace('/\.php$/', '', $href);
        $clean_current = preg_replace('/\.php$/', '', $current);
        return (rtrim($clean_current, '/') === rtrim($clean_href, '/')) ? 'active' : '';
    }
}
?>
<!DOCTYPE html>
<?php /* The refraction filters for this page: see includes/glass.php. */ ?>
<html lang="en" <?= glass_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg">
<link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
<link rel="mask-icon" href="/assets/images/logo-mark.svg" color="#020817">
<title><?= htmlspecialchars($pageTitle) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
<?php /* Call-script dock.
         Loaded here, from the one layout every portal page includes, because the
         feature's entire promise is that it is on the page you happen to be on.
         Pro only (can_use_call_scripts, NOT plan_has_pro_features): free and
         Entrepreneur accounts are never sent the stylesheet, the script or the
         config, so there is nothing to unlock with devtools either.
         The script is `defer`red so it runs after the document is parsed without
         blocking the page, which is also why it needs no per-page include. */
if (can_use_call_scripts($_plan)): ?>
<link rel="stylesheet" href="<?= asset_url('/assets/css/call_scripts.css') ?>">
<?php endif; ?>
<?php if (isset($_GET['welcome']) || !empty($_SESSION['purchase_animation_plan'])): ?>
<?php /* Onboarding splash stylesheet — loaded here (not at the end of the body)
         so the overlay is already styled by the time its deferred script runs.
         portal/index.php is the only page that includes the matching script. */ ?>
<link rel="stylesheet" href="<?= asset_url('/assets/css/onboarding.css') ?>">
<?php endif; ?>
<style>
  /* ── Nav ──
     Bound to the theme tokens rather than to literals: the active row is the
     accent, and everything else is ink. The admin entry used to be violet, on
     the theory that a different section needs a different colour — it does
     not, it needs a different icon, which it already has. */
  .nav-link { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:var(--r-ctl,10px); font-size:.875rem; font-weight:500; color:var(--ink-3); transition:background-color .2s var(--ease), color .2s var(--ease); white-space:nowrap; }
  .nav-link:hover  { background:var(--fill-2); color:var(--ink); }
  .nav-link.active { background:var(--accent-soft); color:var(--accent); }
  .nav-link.active i { color:var(--accent); }
  .nav-link i { width:16px; text-align:center; font-size:.85rem; color:var(--ink-4); transition:color .2s var(--ease); }
  .nav-link:hover i { color:var(--ink-2); }
  .nav-link.admin-link { color:var(--ink-3); }
  .nav-link.admin-link i { color:var(--ink-4); }
  .nav-link.admin-link:hover { background:var(--fill-2); color:var(--ink); }
  #sidebar { transition: transform .25s cubic-bezier(.4,0,.2,1); }
  @media (max-width: 1023px) {
    #sidebar { position:fixed; top:0; left:0; height:100vh; z-index:50; transform:translateX(-100%); }
    #sidebar.open { transform:translateX(0); }
  }
  #sidebar::before { content:''; position:absolute; top:30%; left:50%; transform:translate(-50%,-50%); width:200px; height:200px; background:radial-gradient(circle,var(--accent-a05, rgba(127,227,168,.05)) 0%,transparent 70%); border-radius:50%; pointer-events:none; }
  ::-webkit-scrollbar { width:6px; } ::-webkit-scrollbar-track { background:transparent; } ::-webkit-scrollbar-thumb { background:var(--fill-3); border-radius:2px; }

  /* ── Portal Page Transition Loader ── */
  #utl-loader {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
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
  /* The loading bar and spinner are a hairline and a ring, not a coloured
     gradient with a glow. They appear on every navigation, so they are the last
     place to spend colour — a green comet on every page load is decoration, and
     it competes with whatever the page is actually saying. */  #utl-progress-bar {
    height: 100%;
    width: 0%;
    background: var(--accent, #7fe3a8);
    border-radius: 0;
    transition: width 0.38s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .utl-ring {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 2.5px solid var(--hair);
    border-top-color: var(--accent, #7fe3a8);
    animation: utl-spin 0.65s linear infinite;
  }
  @keyframes utl-spin { to { transform: rotate(360deg); } }
  /* Hidden by opacity, not by removal, and an opacity of 0 does not stop an
     animation — so this spinner only runs while the loader is on screen. */
  #utl-loader:not(.visible) .utl-ring { animation: none; }
  .utl-brand {
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink-4);
  }
  /* Removed the utl-fadein animation that started at opacity:0 —
     it caused the entire page content to be invisible if page-ready
     was delayed or never applied (slow load / JS error). */
  .nav-link-loading {
    opacity: 0.5;
    pointer-events: none;
  }
</style>
<?php if (can_use_call_scripts($_plan)): ?>
<script>
  /* sender_name is the only placeholder the panel cannot get from the open lead,
     so it is handed over here rather than costing a request. */
  window.UTILIGO_CALL_SCRIPTS = {
    api: '/api/call-scripts.php',
    senderName: <?= json_encode(trim((string)($_user['full_name'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
</script>
<script defer src="<?= asset_url('/assets/js/call_scripts.js') ?>"></script>
<?php endif; ?>
<?php /* Support bubble — on every portal page, on every plan.
         Support is deliberately NOT a paid feature: a free customer who cannot
         report what is broken is a customer who leaves, and the least we can do
         for them is listen. Loaded from this one layout for the same reason the
         dock is — it has to be on the page you are already on.
         The script is `defer`red so it never blocks the page.
         Space Grotesk is the bubble's own face (see support.css): it is already
         this project's display font for the public site's headings, so the panel
         reads as a distinct surface instead of more of the dashboard's Inter.
         Two weights, display=swap: the panel is secondary, and a webfont must
         never be the reason a page's text is invisible. */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap">
<link rel="stylesheet" href="<?= asset_url('/assets/css/support.css') ?>">
<script>
  window.UTILIGO_SUPPORT = { api: '/api/support.php' };
</script>
<script defer src="<?= asset_url('/assets/js/support.js') ?>"></script>
<?php /* The design layer, last in <head> for the same reason as on the public
         site: it has to outrank style.css, Tailwind's CDN output and this
         page's own <style>. See includes/header.php for the full note, and
         theme.css for why the palette lives in one file. */ ?>
<?php require_once __DIR__ . '/appearance.php'; ?>
<?= appearance_bootstrap() ?>
<link rel="stylesheet" href="<?= asset_url('/assets/css/theme.css') ?>">
<script defer src="<?= asset_url('/assets/js/ui-theme.js') ?>"></script>
</head>
<body class="antialiased bg-slate-950 text-white"
      data-csrf="<?= function_exists('csrf_token') ? csrf_token() : '' ?>"
      <?= function_exists('plan_info_data_attr') ? plan_info_data_attr() : '' ?>
      data-ob-plan="<?= htmlspecialchars((string)($_SESSION['purchase_animation_plan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
      data-ob-name="<?= htmlspecialchars(trim((string)($_user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">

<?= glass_defs() ?>

<!-- Transition Loader Overlay -->
<div id="utl-loader" role="status" aria-label="Loading">
  <div id="utl-progress-track"><div id="utl-progress-bar"></div></div>
  <div class="utl-ring"></div>
  <span class="utl-brand">Utiligo</span>
</div>

<div id="sidebarOverlay" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden" onclick="closeSidebar()"></div>

<aside id="sidebar" class="w-64 h-screen bg-slate-900/95 border-r border-white/5 flex flex-col lg:fixed lg:top-0 lg:left-0 backdrop-blur-xl">

  <!-- Logo (never scrolls away; never gets compressed) -->
  <div class="px-5 py-5 border-b border-white/5 shrink-0">
    <a href="/" class="flex items-center gap-2.5 group">
      <?php if ($_has_logo): ?>
        <?= brand_logo('h-8 w-auto') ?>
      <?php else: ?>
        <div class="w-8 h-8 rounded-lg bg-white flex items-center justify-center shrink-0">
          <i class="fa-solid fa-bolt text-black text-sm"></i>
        </div>
      <?php endif; ?>
      <?php if (!$_has_logo): ?><span class="text-lg font-black tracking-tight group-hover:text-slate-300 transition">Utiligo</span><?php endif; ?>
    </a>
  </div>

  <!-- Nav (scrolls independently; min-h-0 is the flexbox key to allow shrink) -->
  <nav class="flex-1 min-h-0 overflow-y-auto px-3 py-4 space-y-1">
    <p class="text-xs font-semibold text-slate-600 uppercase tracking-widest px-3 mb-2">Main</p>
    <a href="/portal/index"    class="nav-link <?= _nav_active('/portal/index',    $_path) ?>"><i class="fa-solid fa-house"></i> Dashboard</a>
    <a href="/portal/leads"    class="nav-link <?= _nav_active('/portal/leads',    $_path) ?>"><i class="fa-solid fa-magnifying-glass"></i> Find Leads</a>
    <a href="/portal/generate" class="nav-link <?= _nav_active('/portal/generate', $_path) ?>"><i class="fa-solid fa-bolt"></i> Generate Site</a>
    <a href="/portal/my_sites" class="nav-link <?= _nav_active('/portal/my_sites', $_path) ?>"><i class="fa-solid fa-folder-open"></i> My Sites</a>
    <a href="/portal/crm"      class="nav-link <?= _nav_active('/portal/crm',      $_path) ?>">
      <i class="fa-solid fa-address-book"></i> Client CRM
    </a>

    <p class="text-xs font-semibold text-slate-600 uppercase tracking-widest px-3 mt-5 mb-2">Account</p>
    <a href="/portal/billing"  class="nav-link <?= _nav_active('/portal/billing',  $_path) ?>"><i class="fa-solid fa-credit-card"></i> Billing</a>
    <a href="/portal/settings" class="nav-link <?= _nav_active('/portal/settings', $_path) ?>"><i class="fa-solid fa-gear"></i> Settings</a>

    <?php if ($_is_admin): ?>
    <p class="text-xs font-semibold text-purple-700 uppercase tracking-widest px-3 mt-5 mb-2">Admin</p>
    <a href="/admin/index" class="nav-link admin-link <?= str_starts_with($_path, '/admin/') ? 'active' : '' ?>">
      <i class="fa-solid fa-shield-halved"></i> Admin Panel
    </a>
    <?php endif; ?>

    <div class="pt-3 mt-3 border-t border-white/5">
      <a href="/" class="nav-link text-slate-500 hover:text-white">
        <i class="fa-solid fa-arrow-up-right-from-square"></i>
        Back to Site
      </a>
    </div>
  </nav>

  <!-- Plan badge. Told apart by weight and border, not by hue: this card used to
       carry a coloured pill, and a sidebar is no place for a second palette. -->
  <?php if (!$_is_paid): ?>
  <div class="mx-3 mb-3 p-3.5 rounded-xl bg-white/[.03] border border-white/10 shrink-0">
    <p class="text-xs font-bold text-white mb-0.5">Free Plan</p>
    <p class="text-[11px] text-slate-500 mb-3">Unlock more leads &amp; sites</p>
    <a href="/portal/billing?upgrade=1"
       class="utl-btn utl-btn--primary utl-btn--sm w-full">
      <i class="fa-solid fa-crown mr-1"></i> Upgrade Plan
    </a>
  </div>
  <?php elseif ($_is_pro): ?>
  <div class="mx-3 mb-3 p-3.5 rounded-xl bg-white/[.03] border border-white/10 shrink-0">
    <p class="text-xs font-bold text-white mb-0.5">Pro Plan</p>
    <p class="text-[11px] text-slate-500 mb-3">Unlock unlimited leads &amp; <?= (int)(defined('ENT_SITE_LIMIT') ? ENT_SITE_LIMIT : 500) ?> sites</p>
    <a href="/portal/billing?plan=entrepreneur"
       class="utl-btn utl-btn--ghost utl-btn--sm w-full">
      <i class="fa-solid fa-rocket mr-1"></i> Go Entrepreneur
    </a>
  </div>
  <?php endif; ?>

  <!-- User footer (always visible at bottom; never compressed) -->
  <div class="px-4 py-4 border-t border-white/5 flex items-center gap-3 shrink-0">
    <div class="w-8 h-8 rounded-lg bg-white/10 border border-white/20 flex items-center justify-center shrink-0 text-sm font-bold text-white">
      <?= $_initials ?>
    </div>
    <div class="flex-1 min-w-0">
      <p class="text-xs font-semibold text-white truncate"><?= $_name ?></p>
      <p class="text-xs text-slate-500"><?= $_plan_label ?> Plan</p>
    </div>
    <a href="/logout" title="Logout" class="w-9 h-9 rounded-lg flex items-center justify-center text-slate-500 hover:text-red-400 hover:bg-white/5 transition text-sm shrink-0">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
    </a>
  </div>

</aside>

<!-- Mobile top bar -->
<header class="lg:hidden sticky top-0 z-30 bg-slate-950/90 backdrop-blur border-b border-white/5 px-4 py-3 flex items-center justify-between">
  <button onclick="openSidebar()" class="text-slate-400 hover:text-white">
    <i class="fa-solid fa-bars text-lg"></i>
  </button>
  <a href="/" class="flex items-center gap-2">
    <?php if ($_has_logo): ?>
      <?= brand_logo('h-7 w-auto') ?>
    <?php else: ?>
      <div class="w-6 h-6 rounded-md bg-white flex items-center justify-center">
        <i class="fa-solid fa-bolt text-black text-xs"></i>
      </div>
    <?php endif; ?>
    <?php if (!$_has_logo): ?><span class="font-black text-base">Utiligo</span><?php endif; ?>
  </a>
  <a href="/logout" class="w-9 h-9 rounded-lg flex items-center justify-center text-slate-400 hover:text-red-400 hover:bg-white/5 transition">
    <i class="fa-solid fa-arrow-right-from-bracket"></i>
  </a>
</header>

<main class="lg:ml-64 min-h-screen">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 py-6 sm:py-8">

<script>
function openSidebar()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.add('hidden'); }

// ── Utiligo Portal Transition System ────────────────────────────────────
(function () {
  // Settings page opts out so tab switching (Profile / Password / Security…)
  // stays instant — set $disable_transition_loader = true before including
  // portal_layout.php. Everywhere else the loader keeps working as before.
  var disabled = <?= (!empty($disable_transition_loader) ? 'true' : 'false') ?>;
  if (disabled) return;

  var loader = document.getElementById('utl-loader');
  var bar    = document.getElementById('utl-progress-bar');
  if (!loader || !bar) return;

  function showLoader() {
    bar.style.width = '0%';
    loader.classList.add('visible');
    requestAnimationFrame(function () {
      bar.style.transition = 'width 0.38s cubic-bezier(0.4,0,0.2,1)';
      bar.style.width = '70%';
    });
  }

  function hideLoader() {
    bar.style.transition = 'width 0.14s ease';
    bar.style.width = '100%';
    setTimeout(function () {
      loader.classList.remove('visible');
    }, 150);
  }

  // Show on page arrival
  showLoader();
  var done = false;
  function finish() { if (done) return; done = true; hideLoader(); }

  // Hide once page is ready — 600ms hard cap so it never gets stuck
  window.addEventListener('load', finish);
  document.addEventListener('DOMContentLoaded', function () { setTimeout(finish, 60); });
  setTimeout(finish, 600);

  // Only trigger on real anchor-click navigations
  document.addEventListener('click', function (e) {
    var anchor = e.target.closest('a');
    if (!anchor) return;
    var href = anchor.getAttribute('href');
    if (!href) return;
    if (
      anchor.target === '_blank' ||
      anchor.hasAttribute('download') ||
      href.startsWith('#') ||
      href.startsWith('javascript') ||
      href.startsWith('mailto') ||
      href.startsWith('tel') ||
      (href.startsWith('http') && !href.includes(location.hostname))
    ) return;
    var navLink = anchor.closest('.nav-link');
    if (navLink) navLink.classList.add('nav-link-loading');
    e.preventDefault();
    showLoader();
    setTimeout(function () { location.href = href; }, 200);
  });

  // bfcache restore
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) hideLoader();
  });
})();
</script>
