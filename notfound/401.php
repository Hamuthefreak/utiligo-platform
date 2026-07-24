<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
http_response_code(401);
$pageTitle = '401 — Unauthorised — Utiligo';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="min-h-[80vh] flex items-center justify-center px-6 py-20">
  <div class="text-center max-w-lg mx-auto">

    <div class="relative inline-block mb-8 select-none">
      <div class="absolute inset-0 blur-3xl opacity-25 bg-emerald-500 rounded-full scale-150 pointer-events-none"></div>
      <p class="relative text-[9rem] font-black leading-none tracking-tighter text-transparent bg-clip-text"
         style="background-image:linear-gradient(135deg,#10b981 0%,#34d399 50%,#6ee7b7 100%);">401</p>
    </div>

    <h1 class="text-2xl font-bold mb-3">Unauthorised</h1>
    <p class="text-slate-400 mb-8 leading-relaxed">
      You need to be signed in to view this page.
      Log in and try again &mdash; it&rsquo;ll only take a second.
    </p>

    <div class="flex flex-col sm:flex-row gap-3 justify-center">
      <a href="/login.php" class="inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-3 rounded-xl font-bold transition-all hover:scale-105 shadow-lg shadow-emerald-500/20">
        <i class="fa-solid fa-right-to-bracket"></i> Sign In
      </a>
      <button onclick="history.back()" class="inline-flex items-center justify-center gap-2 bg-white/8 hover:bg-white/15 text-white px-6 py-3 rounded-xl font-semibold transition">
        <i class="fa-solid fa-arrow-left"></i> Go Back
      </button>
    </div>

    <div class="mt-10 pt-8 border-t border-white/5">
      <p class="text-xs text-slate-500 uppercase tracking-widest mb-4">Maybe you were looking for</p>
      <div class="flex flex-wrap gap-2 justify-center">
        <a href="/" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Home</a>
        <a href="/login.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Login</a>
        <a href="/register.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Register</a>
        <a href="/contact.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Contact</a>
      </div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
