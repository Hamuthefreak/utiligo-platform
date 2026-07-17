<?php
if (!isset($pageTitle)) { $pageTitle = 'Utiligo — Find Clients. Build Websites. Get Paid.'; }
$loggedIn = function_exists('is_logged_in') && is_logged_in();
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
</head>
<body class="antialiased bg-slate-950 text-white" data-csrf="<?= function_exists('csrf_token') ? csrf_token() : '' ?>">
<nav class="sticky top-0 z-50 backdrop-blur-lg bg-slate-950/80 border-b border-white/10">
  <div class="max-w-6xl mx-auto px-6 py-4 flex justify-between items-center">
    <a href="/" class="text-xl font-bold flex items-center gap-2"><i class="fa-solid fa-bolt text-emerald-400"></i>Utiligo</a>
    <div class="hidden md:flex gap-8 text-sm font-medium text-slate-300">
      <a href="/#how-it-works" class="hover:text-white">How It Works</a>
      <a href="/#features" class="hover:text-white">Features</a>
      <a href="/#pricing" class="hover:text-white">Pricing</a>
      <a href="/#faq" class="hover:text-white">FAQ</a>
    </div>
    <div class="flex items-center gap-3">
      <?php if ($loggedIn): ?>
        <a href="/portal/index.php" class="text-sm font-semibold px-5 py-2 rounded-full bg-white/10 hover:bg-white/20 transition">Dashboard</a>
        <a href="/includes/auth.php?action=logout" class="text-sm text-slate-400 hover:text-white">Logout</a>
      <?php else: ?>
        <a href="/login.php" class="text-sm text-slate-300 hover:text-white">Log In</a>
        <a href="/register.php" class="text-sm font-semibold px-5 py-2 rounded-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 transition">Start Free</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
