<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
http_response_code(404);
$pageTitle = '404 — Page Not Found — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="min-h-[70vh] flex items-center justify-center px-6 py-20">
  <div class="text-center max-w-lg mx-auto">

    <!-- Glowing 404 -->
    <div class="relative inline-block mb-8">
      <div class="absolute inset-0 blur-3xl opacity-30 bg-emerald-500 rounded-full scale-150"></div>
      <p class="relative text-[9rem] font-black leading-none tracking-tighter text-transparent bg-clip-text"
         style="background-image: linear-gradient(135deg, #10b981 0%, #34d399 50%, #6ee7b7 100%);">
        404
      </p>
    </div>

    <h1 class="text-2xl font-bold mb-3">This page doesn&rsquo;t exist</h1>
    <p class="text-slate-400 mb-8 leading-relaxed">
      The page you&rsquo;re looking for may have been moved, deleted,
      or you might have typed the URL wrong.
    </p>

    <div class="flex flex-col sm:flex-row gap-3 justify-center">
      <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <a href="/portal/index.php"
           class="inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-3 rounded-xl font-bold transition-all hover:scale-105 shadow-lg shadow-emerald-500/20">
          <i class="fa-solid fa-house"></i> Go to Dashboard
        </a>
      <?php else: ?>
        <a href="/"
           class="inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-3 rounded-xl font-bold transition-all hover:scale-105 shadow-lg shadow-emerald-500/20">
          <i class="fa-solid fa-house"></i> Go Home
        </a>
      <?php endif; ?>
      <button onclick="history.back()"
              class="inline-flex items-center justify-center gap-2 bg-white/8 hover:bg-white/15 text-white px-6 py-3 rounded-xl font-semibold transition">
        <i class="fa-solid fa-arrow-left"></i> Go Back
      </button>
    </div>

    <!-- Subtle suggestion links -->
    <div class="mt-10 pt-8 border-t border-white/5">
      <p class="text-xs text-slate-500 uppercase tracking-widest mb-4">Maybe you were looking for</p>
      <div class="flex flex-wrap gap-2 justify-center">
        <a href="/" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Home</a>
        <a href="/login.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Login</a>
        <a href="/register.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Register</a>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <a href="/portal/leads.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Find Leads</a>
        <a href="/portal/generate.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Generate Site</a>
        <a href="/portal/my_sites.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">My Sites</a>
        <?php endif; ?>
      </div>
    </div>

  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
