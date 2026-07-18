<?php
/**
 * includes/portal_layout.php
 */
if (!isset($pageTitle)) { $pageTitle = 'Utiligo Portal'; }
$loggedIn  = function_exists('is_logged_in') && is_logged_in();
$_user     = function_exists('current_user')  ? current_user()  : [];
$_plan     = $_user['plan'] ?? 'free';
$_is_pro   = $_plan === 'pro';
$_name     = htmlspecialchars(trim($_user['full_name'] ?? 'User'));
$_initials = strtoupper(substr($_name, 0, 1));
$_path     = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$_logo_path = __DIR__ . '/../assets/images/logo.png';
$_logo_url  = '/assets/images/logo.png';
$_has_logo  = file_exists($_logo_path);

function _nav_active(string $href, string $current): string {
    return (rtrim($current, '/') === rtrim($href, '/')) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
  .nav-link { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:12px; font-size:.875rem; font-weight:500; color:#94a3b8; transition:all .15s; white-space:nowrap; }
  .nav-link:hover  { background:rgba(255,255,255,.06); color:#fff; }
  .nav-link.active { background:rgba(255,255,255,.1); color:#ffffff; }
  .nav-link.active i { color:#ffffff; }
  .nav-link i { width:16px; text-align:center; font-size:.85rem; color:#64748b; transition:color .15s; }
  .nav-link:hover i { color:#e2e8f0; }
  #sidebar { transition: transform .25s cubic-bezier(.4,0,.2,1); }
  @media (max-width: 1023px) {
    #sidebar { position:fixed; top:0; left:0; height:100vh; z-index:50; transform:translateX(-100%); }
    #sidebar.open { transform:translateX(0); }
  }
  #sidebar::before { content:''; position:absolute; top:30%; left:50%; transform:translate(-50%,-50%); width:200px; height:200px; background:radial-gradient(circle,rgba(255,255,255,.03) 0%,transparent 70%); border-radius:50%; pointer-events:none; }
  ::-webkit-scrollbar { width:4px; } ::-webkit-scrollbar-track { background:transparent; } ::-webkit-scrollbar-thumb { background:#334155; border-radius:2px; }
</style>
</head>
<body class="antialiased bg-slate-950 text-white" data-csrf="<?= function_exists('csrf_token') ? csrf_token() : '' ?>">

<div id="sidebarOverlay" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden" onclick="closeSidebar()"></div>

<aside id="sidebar" class="w-64 h-screen bg-slate-900/95 border-r border-white/5 flex flex-col lg:fixed lg:top-0 lg:left-0 backdrop-blur-xl">

  <!-- Logo + wordmark -->
  <div class="px-5 py-5 border-b border-white/5">
    <a href="/portal/index.php" class="flex items-center gap-2.5">
      <?php if ($_has_logo): ?>
        <img src="<?= $_logo_url ?>" alt="Utiligo" class="h-8 w-auto">
      <?php else: ?>
        <div class="w-8 h-8 rounded-lg bg-white flex items-center justify-center shrink-0">
          <i class="fa-solid fa-bolt text-black text-sm"></i>
        </div>
      <?php endif; ?>
      <span class="text-lg font-black tracking-tight">Utiligo</span>
    </a>
  </div>

  <!-- Nav -->
  <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
    <p class="text-xs font-semibold text-slate-600 uppercase tracking-widest px-3 mb-2">Main</p>
    <a href="/portal/index.php"    class="nav-link <?= _nav_active('/portal/index.php',    $_path) ?>"><i class="fa-solid fa-house"></i> Dashboard</a>
    <a href="/portal/leads.php"    class="nav-link <?= _nav_active('/portal/leads.php',    $_path) ?>"><i class="fa-solid fa-magnifying-glass"></i> Find Leads</a>
    <a href="/portal/generate.php" class="nav-link <?= _nav_active('/portal/generate.php', $_path) ?>"><i class="fa-solid fa-bolt"></i> Generate Site</a>
    <a href="/portal/my_sites.php" class="nav-link <?= _nav_active('/portal/my_sites.php', $_path) ?>"><i class="fa-solid fa-folder-open"></i> My Sites</a>
    <p class="text-xs font-semibold text-slate-600 uppercase tracking-widest px-3 mt-5 mb-2">Account</p>
    <a href="/portal/settings.php" class="nav-link <?= _nav_active('/portal/settings.php', $_path) ?>"><i class="fa-solid fa-paintbrush"></i> White-Label</a>
    <a href="/portal/billing.php"  class="nav-link <?= _nav_active('/portal/billing.php',  $_path) ?>"><i class="fa-solid fa-credit-card"></i> Billing</a>
    <?php if (($_user['admin_flag'] ?? 0) && (defined('DEBUG_MODE') && DEBUG_MODE)): ?>
    <a href="/portal/debug.php" class="nav-link <?= _nav_active('/portal/debug.php', $_path) ?>"><i class="fa-solid fa-bug"></i> Debug Panel</a>
    <?php endif; ?>
  </nav>

  <!-- Plan badge -->
  <?php if (!$_is_pro): ?>
  <div class="mx-3 mb-3 p-3 rounded-2xl bg-white/5 border border-white/8">
    <p class="text-xs font-bold text-white mb-0.5">Free Plan</p>
    <p class="text-xs text-slate-400 mb-3">Unlock unlimited leads &amp; sites</p>
    <a href="/portal/billing.php?upgrade=1"
       class="block w-full text-center bg-white hover:bg-slate-200 text-black py-2 rounded-xl text-xs font-bold transition">
      <i class="fa-solid fa-crown mr-1"></i> Upgrade to Pro
    </a>
  </div>
  <?php endif; ?>

  <!-- User footer -->
  <div class="px-4 py-4 border-t border-white/5 flex items-center gap-3">
    <div class="w-8 h-8 rounded-full bg-white/10 border border-white/20 flex items-center justify-center shrink-0 text-sm font-bold text-white">
      <?= $_initials ?>
    </div>
    <div class="flex-1 min-w-0">
      <p class="text-xs font-semibold text-white truncate"><?= $_name ?></p>
      <p class="text-xs text-slate-500"><?= $_is_pro ? 'Pro' : 'Free' ?> Plan</p>
    </div>
    <a href="/includes/auth.php?action=logout" title="Logout" class="text-slate-500 hover:text-red-400 transition text-sm">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
    </a>
  </div>

</aside>

<!-- Mobile top bar -->
<header class="lg:hidden sticky top-0 z-30 bg-slate-950/90 backdrop-blur border-b border-white/5 px-4 py-3 flex items-center justify-between">
  <button onclick="openSidebar()" class="text-slate-400 hover:text-white">
    <i class="fa-solid fa-bars text-lg"></i>
  </button>
  <a href="/portal/index.php" class="flex items-center gap-2">
    <?php if ($_has_logo): ?>
      <img src="<?= $_logo_url ?>" alt="Utiligo" class="h-7 w-auto">
    <?php else: ?>
      <div class="w-6 h-6 rounded-md bg-white flex items-center justify-center">
        <i class="fa-solid fa-bolt text-black text-xs"></i>
      </div>
    <?php endif; ?>
    <span class="font-black text-base">Utiligo</span>
  </a>
  <a href="/includes/auth.php?action=logout" class="text-slate-400 hover:text-white text-sm">
    <i class="fa-solid fa-arrow-right-from-bracket"></i>
  </a>
</header>

<main class="lg:ml-64 min-h-screen">
  <div class="max-w-5xl mx-auto px-6 py-8">

<script>
function openSidebar()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.add('hidden'); }
</script>
