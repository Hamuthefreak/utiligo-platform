<?php
/**
 * includes/admin_layout.php
 */
if (!isset($pageTitle))  { $pageTitle  = 'Admin — Utiligo'; }
if (!isset($adminPage))  { $adminPage  = ''; }
// asset_url() lives in functions.php, which admin pages don't all load.
if (!function_exists('asset_url')) {
    require_once __DIR__ . '/functions.php';
}

$_name     = htmlspecialchars(trim($admin['full_name'] ?? $admin['email'] ?? 'Admin'));
$_initials = strtoupper(substr($_name, 0, 1));
// Inline wordmark: see includes/brand.php — a logo file cannot follow the theme.
require_once __DIR__ . '/brand.php';
// The design layer, before the <html> tag below — glass_attr() is written there
// and a require that ran after it would be a fatal on every admin page.
require_once __DIR__ . '/appearance.php';
$_has_logo = brand_logo_exists();

// How many customer messages are waiting for a reply, for the nav badge.
// Best-effort: support_unread_admin() swallows its own errors and answers 0, so an
// unreachable database under-reports the badge instead of breaking every admin page.
if (!function_exists('support_unread_admin')) {
    require_once __DIR__ . '/support.php';
}
$_supportUnread = support_unread_admin();
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
<?php if ($adminPage === 'support'): ?>
<?php /* The support inbox belongs to the support channel, so it uses that
         channel's face and tag styles rather than a second, near-identical set. */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap">
<link rel="stylesheet" href="<?= asset_url('/assets/css/support.css') ?>">
<?php endif; ?>
<style>
  .nav-link { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:var(--r-ctl,10px); font-size:.875rem; font-weight:500; color:var(--ink-3); transition:background-color .2s var(--ease), color .2s var(--ease); white-space:nowrap; }
  .nav-link:hover  { background:var(--fill-2); color:var(--ink); }
  .nav-link.active { background:var(--accent-soft); color:var(--accent); }
  .nav-link.active i { color:var(--accent); }
  .nav-link i { width:16px; text-align:center; font-size:.85rem; color:var(--ink-4); transition:color .2s var(--ease); }
  .nav-link:hover i { color:var(--ink-2); }
  /* Admin items used to be violet, which made the whole sidebar a second accent
     competing with the page's own status colours. The section is already marked
     by its heading and the panel's own chrome. */
  .nav-link.admin-item { color:var(--ink-2); }
  .nav-link.admin-item i { color:var(--ink-4); }
  .nav-link.admin-item:hover { background:var(--fill-2); color:var(--ink); }
  .nav-link.admin-item.active { background:var(--accent-soft); color:var(--accent); }
  .nav-link.back-link { color:var(--ink-4); }
  .nav-link.back-link:hover { color:var(--ink-2); }
  #sidebar { transition: transform .25s cubic-bezier(.4,0,.2,1); }
  @media (max-width: 1023px) {
    #sidebar { position:fixed; top:0; left:0; height:100vh; z-index:50; transform:translateX(-100%); }
    #sidebar.open { transform:translateX(0); }
  }
  ::-webkit-scrollbar { width:6px; } ::-webkit-scrollbar-track { background:transparent; } ::-webkit-scrollbar-thumb { background:var(--fill-3); border-radius:2px; }
</style>
<?php /* The design layer, last in <head>. See includes/header.php for why it has
         to be last and why the reveal gate is an inline line of script. */ ?>
<?php require_once __DIR__ . '/appearance.php'; ?>
<?= appearance_bootstrap() ?>
<link rel="stylesheet" href="<?= asset_url('/assets/css/theme.css') ?>">
<script defer src="<?= asset_url('/assets/js/ui-theme.js') ?>"></script>
</head>
<body class="antialiased bg-slate-950 text-white">

<?= glass_defs() ?>

<div id="sidebarOverlay" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden" onclick="closeSidebar()"></div>

<aside id="sidebar" class="w-64 h-screen bg-slate-900/95 border-r border-white/5 flex flex-col lg:fixed lg:top-0 lg:left-0 backdrop-blur-xl">
  <div class="px-5 py-5 border-b border-white/5">
    <a href="/admin/index.php" class="flex items-center gap-2.5 group">
      <?php if ($_has_logo): ?>
        <?= brand_logo('h-8 w-auto') ?>
      <?php else: ?>
        <div class="w-8 h-8 rounded-lg bg-white flex items-center justify-center shrink-0">
          <i class="fa-solid fa-bolt text-black text-sm"></i>
        </div>
      <?php endif; ?>
      <?php if (!$_has_logo): ?>
      <div>
        <span class="text-lg font-black tracking-tight group-hover:text-slate-300 transition">Utiligo</span>
        <div class="text-[10px] font-semibold text-slate-500 leading-none mt-0.5">Admin Panel</div>
      </div>
      <?php endif; ?>
    </a>
  </div>

  <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
    <p class="text-xs font-semibold text-slate-600 uppercase tracking-widest px-3 mb-2">Admin</p>
    <a href="/admin/index.php" class="nav-link admin-item <?= $adminPage==='dashboard' ? 'active' : '' ?>">
      <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>
    <a href="/admin/users.php" class="nav-link admin-item <?= $adminPage==='users' ? 'active' : '' ?>">
      <i class="fa-solid fa-users"></i> Users
    </a>
    <a href="/admin/crm.php" class="nav-link admin-item <?= $adminPage==='crm' ? 'active' : '' ?>">
      <i class="fa-solid fa-address-book"></i> CRM
    </a>
    <a href="/admin/support.php" class="nav-link admin-item <?= $adminPage==='support' ? 'active' : '' ?>">
      <i class="fa-solid fa-headset"></i> Support
      <?php if ($_supportUnread > 0): ?>
        <span class="ml-auto text-[10px] bg-[#f0a83c] text-[#1c1917] px-1.5 py-0.5 rounded-[5px] font-bold utl-num"><?= (int)$_supportUnread ?></span>
      <?php endif; ?>
    </a>
    <a href="/admin/email.php" class="nav-link admin-item <?= $adminPage==='email' ? 'active' : '' ?>">
      <i class="fa-solid fa-envelope"></i> Email Blast
    </a>
    <?php /* Money sits next to the people it belongs to: this is where an operator
             looks after a paying customer whose plan did not land. */ ?>
    <a href="/admin/payments.php" class="nav-link admin-item <?= $adminPage==='payments' ? 'active' : '' ?>">
      <i class="fa-solid fa-credit-card"></i> Payments
    </a>
    <a href="/admin/settings.php" class="nav-link admin-item <?= $adminPage==='settings' ? 'active' : '' ?>">
      <i class="fa-solid fa-sliders"></i> Settings
    </a>
    <a href="/admin/database.php" class="nav-link admin-item <?= $adminPage==='database' ? 'active' : '' ?>">
      <i class="fa-solid fa-database"></i> Database
    </a>

    <div class="pt-3 mt-3 border-t border-white/5">
      <a href="/portal/index.php" class="nav-link back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to Portal
      </a>
      <a href="/" class="nav-link back-link">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> Back to Site
      </a>
    </div>
  </nav>

  <div class="px-4 py-4 border-t border-white/5 flex items-center gap-3">
    <div class="w-8 h-8 rounded-lg bg-white/10 border border-white/20 flex items-center justify-center shrink-0 text-sm font-bold text-white">
      <?= $_initials ?>
    </div>
    <div class="flex-1 min-w-0">
      <p class="text-xs font-semibold text-white truncate"><?= $_name ?></p>
      <p class="text-xs text-slate-500">Administrator</p>
    </div>
    <a href="/logout.php" title="Logout" class="text-slate-500 hover:text-red-400 transition text-sm">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
    </a>
  </div>
</aside>

<header class="lg:hidden sticky top-0 z-30 bg-slate-950/90 backdrop-blur border-b border-white/5 px-4 py-3 flex items-center justify-between">
  <button onclick="openSidebar()" class="text-slate-400 hover:text-white">
    <i class="fa-solid fa-bars text-lg"></i>
  </button>
  <a href="/admin/index.php" class="flex items-center gap-2">
    <?php if ($_has_logo): ?>
      <?= brand_logo('h-7 w-auto') ?>
    <?php else: ?>
      <div class="w-6 h-6 rounded-md bg-white flex items-center justify-center">
        <i class="fa-solid fa-bolt text-black text-xs"></i>
      </div>
    <?php endif; ?>
    <?php if (!$_has_logo): ?><span class="font-black text-base">Utiligo <span class="text-slate-400 text-xs font-semibold">Admin</span></span><?php endif; ?>
  </a>
  <a href="/logout.php" class="text-slate-400 hover:text-white text-sm">
    <i class="fa-solid fa-arrow-right-from-bracket"></i>
  </a>
</header>

<main class="lg:ml-64 min-h-screen">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8">

<script>
function openSidebar()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.add('hidden'); }
</script>
