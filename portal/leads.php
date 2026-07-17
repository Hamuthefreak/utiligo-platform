<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();

$pageTitle = 'Find Leads — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto px-6 py-10">
  <a href="/portal/index.php" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white mb-6">
    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
  </a>
  <h1 class="text-2xl font-bold mb-2">Find Leads</h1>
  <p class="text-slate-400 text-sm mb-8">Search any city and industry to find businesses without a website.</p>

  <form id="leadSearchForm" class="glass rounded-xl p-6 flex gap-4 mb-8 flex-wrap">
    <input type="text" name="city" placeholder="City, e.g. Calgary" required class="flex-1 min-w-[200px] bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
    <input type="text" name="industry" placeholder="Industry, e.g. Plumber" required class="flex-1 min-w-[200px] bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
    <button type="submit" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-full font-semibold">
      <i class="fa-solid fa-magnifying-glass mr-2"></i>Search
    </button>
  </form>

  <div id="leadsLoading" class="hidden text-center py-10 text-slate-400">
    <i class="fa-solid fa-spinner fa-spin mr-2"></i>Searching for leads...
  </div>

  <div id="leadsResultsWrap" class="hidden">
    <div id="leadsList" class="space-y-3"></div>

    <div id="lockedWrap" class="hidden relative mt-4">
      <div id="lockedList" class="space-y-3"></div>
      <div class="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-slate-950 via-slate-950/95 to-transparent pointer-events-none"></div>
      <div class="relative -mt-16 flex flex-col items-center text-center pb-2">
        <div class="w-12 h-12 rounded-full bg-emerald-500/20 flex items-center justify-center mb-3">
          <i class="fa-solid fa-lock text-emerald-400 text-lg"></i>
        </div>
        <p class="font-semibold mb-1">More leads are waiting</p>
        <p class="text-slate-400 text-sm mb-4 max-w-sm">Upgrade to Pro to unlock unlimited leads, full contact details, and website generation.</p>
        <a href="/portal/billing.php?upgrade=1" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-full text-sm font-semibold">
          <i class="fa-solid fa-crown mr-2"></i>Upgrade to Pro
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/leads.js?v=v162"></script>

