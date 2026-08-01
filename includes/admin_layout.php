<?php
/**
 * includes/admin_layout.php
 */
if (!isset($pageTitle))  { $pageTitle  = 'Admin — Utiligo'; }
if (!isset($adminPage))  { $adminPage  = ''; }

$_name     = htmlspecialchars(trim($admin['full_name'] ?? $admin['email'] ?? 'Admin'));
$_initials = strtoupper(substr($_name, 0, 1));
$_logo_path = __DIR__ . '/../assets/images/logo.png';
$_logo_url  = '/assets/images/logo.png';
$_has_logo  = file_exists($_logo_path);
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
  .nav-link.admin-item { color:#c4b5fd; }
  .nav-link.admin-item i { color:#a78bfa; }
  .nav-link.admin-item:hover { background:rgba(139,92,246,.12); color:#ddd6fe; }
  .nav-link.admin-item.active { background:rgba(139,92,246,.18); color:#ddd6fe; }
  .nav-link.back-link { color:#64748b; }
  .nav-link.back-link:hover { color:#94a3b8; }
  #sidebar { transition: transform .25s cubic-bezier(.4,0,.2,1); }
  @media (max-width: 1023px) {
    #sidebar { position:fixed; top:0; left:0; height:100vh; z-index:50; transform:translateX(-100%); }
    #sidebar.open { transform:translateX(0); }
  }
  ::-webkit-scrollbar { width:4px; } ::-webkit-scrollbar-track { background:transparent; } ::-webkit-scrollbar-thumb { background:#334155; border-radius:2px; }
</style>
</head>
<body class="antialiased bg-slate-950 text-white">

<div id="sidebarOverlay" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden" onclick="closeSidebar()"></div>

<aside id="sidebar" class="w-64 h-screen bg-slate-900/95 border-r border-white/5 flex flex-col lg:fixed lg:top-0 lg:left-0 backdrop-blur-xl">
  <div class="px-5 py-5 border-b border-white/5">
    <a href="/admin/index.php" class="flex items-center gap-2.5 group">
      <?php if ($_has_logo): ?>
        <img src="<?= $_logo_url ?>" alt="Utiligo" class="h-8 w-auto">
      <?php else: ?>
        <div class="w-8 h-8 rounded-lg bg-white flex items-center justify-center shrink-0">
          <i class="fa-solid fa-bolt text-black text-sm"></i>
        </div>
      <?php endif; ?>
      <div>
        <span class="text-lg font-black tracking-tight group-hover:text-slate-300 transition">Utiligo</span>
        <div class="text-[10px] font-semibold text-purple-400 leading-none mt-0.5">Admin Panel</div>
      </div>
    </a>
  </div>

  <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
    <p class="text-xs font-semibold text-purple-800 uppercase tracking-widest px-3 mb-2">Admin</p>
    <a href="/admin/index.php" class="nav-link admin-item <?= $adminPage==='dashboard' ? 'active' : '' ?>">
      <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>
    <a href="/admin/users.php" class="nav-link admin-item <?= $adminPage==='users' ? 'active' : '' ?>">
      <i class="fa-solid fa-users"></i> Users
    </a>
    <a href="/admin/email.php" class="nav-link admin-item <?= $adminPage==='email' ? 'active' : '' ?>">
      <i class="fa-solid fa-envelope"></i> Email Blast
    </a>
    <a href="/admin/settings.php" class="nav-link admin-item <?= $adminPage==='settings' ? 'active' : '' ?>">
      <i class="fa-solid fa-sliders"></i> Settings
    </a>
    <a href="/admin/db.php" class="nav-link admin-item <?= $adminPage==='db' ? 'active' : '' ?>">
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
    <div class="w-8 h-8 rounded-full bg-purple-500/20 border border-purple-500/30 flex items-center justify-center shrink-0 text-sm font-bold text-purple-300">
      <?= $_initials ?>
    </div>
    <div class="flex-1 min-w-0">
      <p class="text-xs font-semibold text-white truncate"><?= $_name ?></p>
      <p class="text-xs text-purple-400">Administrator</p>
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
      <img src="<?= $_logo_url ?>" alt="Utiligo" class="h-7 w-auto">
    <?php else: ?>
      <div class="w-6 h-6 rounded-md bg-white flex items-center justify-center">
        <i class="fa-solid fa-bolt text-black text-xs"></i>
      </div>
    <?php endif; ?>
    <span class="font-black text-base">Utiligo <span class="text-purple-400 text-xs font-semibold">Admin</span></span>
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
