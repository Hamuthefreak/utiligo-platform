<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user    = current_user();
$is_pro  = ($user['plan'] ?? 'free') === 'pro';

$pageTitle = 'Find Leads — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto px-6 py-10">
  <a href="/portal/index.php" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white mb-6">
    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
  </a>

  <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
    <div>
      <h1 class="text-2xl font-bold">Find Leads</h1>
      <p class="text-slate-400 text-sm mt-1">Discover local businesses with no website — your next clients.</p>
    </div>
    <?php if (!$is_pro): ?>
    <div class="flex items-center gap-2 bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-2 text-sm">
      <i class="fa-solid fa-crown text-amber-400"></i>
      <span class="text-slate-300">Free plan: <strong class="text-white">3 leads</strong> per search</span>
      <a href="/portal/billing.php?upgrade=1" class="ml-2 text-xs bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-3 py-1 rounded-full font-semibold">Upgrade</a>
    </div>
    <?php endif; ?>
  </div>

  <form id="leadSearchForm" class="glass rounded-xl p-6 flex gap-4 mb-8 flex-wrap">
    <input type="text" name="city" placeholder="City, e.g. Calgary" required
           class="flex-1 min-w-[200px] bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500">
    <input type="text" name="industry" placeholder="Industry, e.g. Plumber" required
           class="flex-1 min-w-[200px] bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500">
    <button type="submit" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-full font-semibold whitespace-nowrap">
      <i class="fa-solid fa-magnifying-glass mr-2"></i>Search
    </button>
  </form>

  <div id="leadsLoading" class="hidden text-center py-10 text-slate-400">
    <i class="fa-solid fa-spinner fa-spin mr-2"></i>Searching Google Places for leads...
  </div>

  <div id="leadsResultsWrap" class="hidden">
    <div id="leadsList" class="space-y-3"></div>

    <div id="lockedWrap" class="hidden mt-4">

      <!-- Blurred locked rows -->
      <div id="lockedList" class="space-y-3"></div>

      <!-- Upgrade wall -->
      <div class="mt-6 rounded-2xl overflow-hidden border border-emerald-500/20" style="background: linear-gradient(135deg, #0f1f18 0%, #0d1a24 100%)">
        <div class="px-6 pt-6 pb-2 text-center">
          <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-500/15 border border-emerald-500/30 mb-4">
            <i class="fa-solid fa-lock-open text-emerald-400 text-2xl"></i>
          </div>
          <h3 class="text-lg font-bold text-white mb-1">Unlock All Leads</h3>
          <p class="text-slate-400 text-sm max-w-sm mx-auto">
            You’re seeing <strong class="text-white">3 of <?php echo defined('FREE_LEAD_LIMIT') ? FREE_LEAD_LIMIT : 3; ?>+</strong> leads found.
            Upgrade to <strong class="text-emerald-400">Pro</strong> to unlock every result, get phone numbers, and generate websites instantly.
          </p>
        </div>

        <!-- Value props -->
        <div class="grid grid-cols-3 gap-3 px-6 py-4">
          <div class="text-center">
            <div class="text-2xl font-bold text-emerald-400">&#8734;</div>
            <div class="text-xs text-slate-400 mt-1">Unlimited leads</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold text-emerald-400"><i class="fa-solid fa-phone text-lg"></i></div>
            <div class="text-xs text-slate-400 mt-1">Phone numbers</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold text-emerald-400"><i class="fa-solid fa-bolt text-lg"></i></div>
            <div class="text-xs text-slate-400 mt-1">Website generator</div>
          </div>
        </div>

        <div class="px-6 pb-6 flex flex-col sm:flex-row items-center justify-center gap-3">
          <a href="/portal/billing.php?upgrade=1"
             class="w-full sm:w-auto bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-8 py-3 rounded-full font-bold text-sm text-center">
            <i class="fa-solid fa-crown mr-2"></i>Upgrade to Pro &mdash; Unlock Everything
          </a>
          <span class="text-xs text-slate-500">Cancel anytime &bull; Instant access</span>
        </div>
      </div>

    </div><!-- /lockedWrap -->
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/leads.js?v=v163"></script>
