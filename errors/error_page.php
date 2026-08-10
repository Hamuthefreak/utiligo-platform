<?php
/**
 * errors/error_page.php
 * Shared error page template — uses the same design as 404.php.
 * Requires: $pageTitle, $_err_code, $_err_title, $_err_desc
 */
if (!isset($_err_code))  { $_err_code  = '???'; }
if (!isset($_err_title)) { $_err_title = 'Unexpected Error'; }
if (!isset($_err_desc))  { $_err_desc  = 'Something went wrong. Please try again.'; }

// Load header only when we safely can (500 may be called when config is broken)
$_header_ok = function_exists('is_logged_in');
if ($_header_ok) {
    $pageTitle = $pageTitle ?? ($_err_code . ' \u2014 Utiligo');
    require_once __DIR__ . '/../includes/header.php';
}
?>
<?php if (!$_header_ok): ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/png" href="/assets/images/sitelogo.png">
<link rel="apple-touch-icon" href="/assets/images/sitelogo.png">
<title><?= htmlspecialchars($pageTitle ?? $_err_code) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head><body class="antialiased bg-slate-950 text-white">
<?php endif; ?>

<section class="min-h-[80vh] flex items-center justify-center px-6 py-20">
  <div class="text-center max-w-lg mx-auto">

    <!-- Glowing error code -->
    <div class="relative inline-block mb-8 select-none">
      <div class="absolute inset-0 blur-3xl opacity-25 bg-emerald-500 rounded-full scale-150 pointer-events-none"></div>
      <p class="relative font-black leading-none tracking-tighter text-transparent bg-clip-text"
         style="font-size:clamp(5rem,20vw,9rem);background-image:linear-gradient(135deg,#10b981 0%,#34d399 50%,#6ee7b7 100%);">
        <?= htmlspecialchars($_err_code) ?>
      </p>
    </div>

    <h1 class="text-2xl font-bold mb-3"><?= htmlspecialchars($_err_title) ?></h1>
    <p class="text-slate-400 mb-8 leading-relaxed"><?= htmlspecialchars($_err_desc) ?></p>

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

    <!-- Suggestion links -->
    <div class="mt-10 pt-8 border-t border-white/5">
      <p class="text-xs text-slate-500 uppercase tracking-widest mb-4">Maybe you were looking for</p>
      <div class="flex flex-wrap gap-2 justify-center">
        <a href="/"            class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Home</a>
        <a href="/login.php"   class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Login</a>
        <a href="/register.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Register</a>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <a href="/portal/leads.php"    class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Find Leads</a>
        <a href="/portal/generate.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Generate Site</a>
        <a href="/portal/my_sites.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">My Sites</a>
        <a href="/portal/settings.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Settings</a>
        <?php endif; ?>
        <a href="/contact.php" class="text-xs bg-white/5 hover:bg-white/10 px-3 py-1.5 rounded-full text-slate-400 hover:text-white transition">Contact</a>
      </div>
    </div>

  </div>
</section>

<?php
if ($_header_ok) {
    require_once __DIR__ . '/../includes/footer.php';
} else {
    echo '</body></html>';
}
?>
