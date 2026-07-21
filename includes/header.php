<?php
if (!isset($pageTitle)) { $pageTitle = 'Utiligo — Find Clients. Build Websites. Get Paid.'; }
$loggedIn = function_exists('is_logged_in') && is_logged_in();
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
  .logo-wordmark {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 800;
    font-size: 1.15rem;
    letter-spacing: -0.03em;
    line-height: 1;
  }

  /* ─── Page Transition Loader ─────────────────────────────── */
  #utl-loader {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 20px;
    background: #020817; /* slate-950 */
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.18s ease;
  }
  #utl-loader.visible {
    opacity: 1;
    pointer-events: all;
  }

  /* Progress bar at top */
  #utl-progress-track {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 2px;
    background: rgba(255,255,255,0.06);
  }
  #utl-progress-bar {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #10b981, #34d399);
    border-radius: 0 2px 2px 0;
    box-shadow: 0 0 10px #10b98166;
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  }

  /* Spinning ring */
  .utl-ring {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 2.5px solid rgba(255,255,255,0.08);
    border-top-color: #10b981;
    animation: utl-spin 0.7s linear infinite;
  }
  @keyframes utl-spin {
    to { transform: rotate(360deg); }
  }

  /* Logo wordmark fade */
  #utl-loader .utl-brand {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 800;
    font-size: 1.1rem;
    letter-spacing: -0.03em;
    color: rgba(255,255,255,0.35);
  }

  /* Page body fade-in on arrival */
  body.page-ready > *:not(#utl-loader) {
    animation: utl-fadein 0.22s ease forwards;
  }
  @keyframes utl-fadein {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0);   }
  }
</style>
</head>
<body class="antialiased bg-slate-950 text-white" data-csrf="<?= function_exists('csrf_token') ? csrf_token() : '' ?>">

<!-- ─── Transition Loader Overlay ─────────────────────────── -->
<div id="utl-loader" role="status" aria-label="Loading" aria-live="polite">
  <div id="utl-progress-track"><div id="utl-progress-bar"></div></div>
  <div class="utl-ring"></div>
  <span class="utl-brand">Utiligo</span>
</div>

<nav class="sticky top-0 z-50 backdrop-blur-lg bg-slate-950/80 border-b border-white/10">
  <div class="max-w-6xl mx-auto px-6 py-4 flex justify-between items-center">
    <a href="/" class="flex items-center gap-2">
      <?php if ($_has_logo): ?>
        <img src="<?= $_logo_url ?>" alt="Utiligo" class="h-8 w-auto">
      <?php else: ?>
        <i class="fa-solid fa-bolt text-white text-xl"></i>
      <?php endif; ?>
      <span class="logo-wordmark text-white">Utiligo</span>
    </a>
    <div class="hidden md:flex gap-8 text-sm font-medium text-slate-300">
      <a href="/#how-it-works" class="hover:text-white">How It Works</a>
      <a href="/#features"     class="hover:text-white">Features</a>
      <a href="/#pricing"      class="hover:text-white">Pricing</a>
      <a href="/#faq"          class="hover:text-white">FAQ</a>
    </div>
    <div class="flex items-center gap-3">
      <?php if ($loggedIn): ?>
        <a href="/portal/index.php" class="text-sm font-semibold px-5 py-2 rounded-full bg-white/10 hover:bg-white/20 transition">Dashboard</a>
        <a href="/includes/auth.php?action=logout" class="text-sm text-slate-400 hover:text-white">Logout</a>
      <?php else: ?>
        <a href="/login.php"    class="text-sm text-slate-300 hover:text-white">Log In</a>
        <a href="/register.php" class="text-sm font-semibold px-5 py-2 rounded-full bg-white hover:bg-slate-200 text-black transition">Start Free</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
