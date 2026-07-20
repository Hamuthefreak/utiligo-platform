<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);

$FREE_LEAD_LIMIT    = defined('FREE_LEAD_LIMIT')          ? (int)FREE_LEAD_LIMIT          : 3;
$FREE_SEARCH_LIMIT  = defined('FREE_SEARCH_DAILY_LIMIT')  ? (int)FREE_SEARCH_DAILY_LIMIT  : 2;
$FREE_SITE_LIMIT    = defined('FREE_SITE_LIMIT')          ? (int)FREE_SITE_LIMIT          : 1;
$FREE_GEN_LIMIT     = defined('FREE_GENERATE_DAILY_LIMIT')? (int)FREE_GENERATE_DAILY_LIMIT: 1;
$FREE_TMPL_LIMIT    = defined('FREE_TEMPLATE_LIMIT')      ? (int)FREE_TEMPLATE_LIMIT      : 2;
$PRO_LEAD_LIMIT     = defined('PRO_LEAD_LIMIT')           ? (int)PRO_LEAD_LIMIT           : 120;
$PRO_SITE_LIMIT     = defined('PRO_SITE_LIMIT')           ? (int)PRO_SITE_LIMIT           : 200;
$ENT_SITE_LIMIT     = defined('ENT_SITE_LIMIT')           ? (int)ENT_SITE_LIMIT           : 500;

$quota_used = 0; $quota_resets_at = null;
if (!$is_paid) {
    try {
        $pdo         = get_platform_db();
        $ip_hash     = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $fingerprint = 'u'.$user['id'].'_'.substr($ip_hash,0,16);
        $cutoff      = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt = $pdo->prepare('SELECT count, window_start FROM lead_search_quota WHERE fingerprint = ? AND window_start > ? LIMIT 1');
        $stmt->execute([$fingerprint, $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $quota_used = (int)$row['count']; $quota_resets_at = strtotime($row['window_start'])+86400; }
    } catch (\Throwable $e) {}
}
$quota_remaining = max(0, $FREE_SEARCH_LIMIT - $quota_used);
$quota_pct       = $FREE_SEARCH_LIMIT > 0 ? min(100, round(($quota_used/$FREE_SEARCH_LIMIT)*100)) : 0;

$pro_lead_count = 0;
if ($plan === 'pro') {
    try {
        $pdo  = get_platform_db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM unlocked_leads WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $pro_lead_count = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {}
}

$active_site_count = 0;
if ($is_paid) {
    try {
        $pdo  = get_platform_db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id = ? AND link_active = 1');
        $stmt->execute([$user['id']]);
        $active_site_count = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {}
}

$slider_max     = match($plan) { 'entrepreneur' => 40, 'pro' => 30, default => 10 };
$slider_default = match($plan) { 'entrepreneur' => 20, 'pro' => 10, default => 5 };

$pageTitle = 'Find Leads — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<?php /* ====== PAGE LAYOUT: main content + history sidebar ====== */ ?>
<div class="flex gap-6 items-start">

<!-- ====== MAIN COLUMN ====== -->
<div class="flex-1 min-w-0">

<div class="mb-6">
  <h1 class="text-3xl font-bold tracking-tight">Find Leads</h1>
  <p class="text-slate-400 text-sm mt-1">Discover local businesses with no website &mdash; your next paying clients.</p>
</div>

<?php if (!$is_paid): ?>
<div class="space-y-3 mb-8">
  <div class="glass rounded-2xl p-5 border border-white/5">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center"><i class="fa-solid fa-magnifying-glass text-slate-300 text-xs"></i></div>
        <div>
          <p class="text-sm font-semibold text-white">Daily Search Quota</p>
          <p class="text-xs text-slate-400">Resets every 24 hours</p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <div id="quotaBadge" class="flex items-center gap-1.5 <?= $quota_remaining===0?'bg-red-500/10 border border-red-500/20 text-red-400':($quota_remaining===1?'bg-amber-500/10 border border-amber-500/20 text-amber-400':'bg-white/8 border border-white/10 text-slate-300') ?> rounded-full px-3 py-1 text-xs font-bold">
          <?= $quota_remaining===0 ? 'No searches left today' : $quota_remaining.' search'.($quota_remaining!==1?'es':'').' left' ?>
        </div>
        <a href="/portal/billing.php?upgrade=1" class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold">
          <i class="fa-solid fa-crown mr-1"></i>Upgrade
        </a>
      </div>
    </div>
    <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
      <div id="quotaBar" class="h-2 rounded-full transition-all duration-500 <?= $quota_pct>=100?'bg-red-500':($quota_pct>=50?'bg-amber-500':'bg-white/60') ?>" style="width:<?= $quota_pct ?>%"></div>
    </div>
    <div class="flex justify-between text-xs text-slate-500 mt-1.5">
      <span id="quotaText"><?= $quota_used ?> of <?= $FREE_SEARCH_LIMIT ?> searches used</span>
      <?php if($quota_resets_at): ?><span>Resets at <?= date('g:i A',$quota_resets_at) ?></span><?php else: ?><span>Resets 24h after first search</span><?php endif; ?>
    </div>
  </div>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <div class="glass rounded-xl p-3 border border-white/5 text-center">
      <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Leads / search</p>
      <p class="text-2xl font-extrabold text-white"><?= $FREE_LEAD_LIMIT ?></p>
    </div>
    <div class="glass rounded-xl p-3 border border-white/5 text-center">
      <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Searches / day</p>
      <p class="text-2xl font-extrabold text-white"><?= $FREE_SEARCH_LIMIT ?></p>
    </div>
    <div class="glass rounded-xl p-3 border border-white/5 text-center">
      <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Sites / day</p>
      <p class="text-2xl font-extrabold text-white"><?= $FREE_GEN_LIMIT ?></p>
    </div>
    <div class="glass rounded-xl p-3 border border-white/5 text-center">
      <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Templates</p>
      <p class="text-2xl font-extrabold text-white"><?= $FREE_TMPL_LIMIT ?></p>
    </div>
  </div>

  <div class="flex items-center gap-3 bg-white/3 border border-white/8 rounded-xl px-4 py-3">
    <i class="fa-solid fa-arrow-up text-white text-sm"></i>
    <p class="text-xs text-slate-400 flex-1">
      Upgrade to <strong class="text-white">Pro</strong> for <?= $PRO_LEAD_LIMIT ?> lead unlocks,
      <?= $PRO_SITE_LIMIT ?> active sites &amp; unlimited searches.
    </p>
    <a href="/portal/billing.php?upgrade=1" class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold whitespace-nowrap">Go Pro</a>
  </div>
</div>

<?php elseif ($plan === 'pro'): ?>
<?php
  $ll_pct  = $PRO_LEAD_LIMIT > 0 ? min(100, round(($pro_lead_count/$PRO_LEAD_LIMIT)*100)) : 0;
  $ll_col  = $ll_pct>=100?'bg-red-500':($ll_pct>=80?'bg-amber-500':'bg-white/60');
  $sl_pct  = $PRO_SITE_LIMIT > 0 ? min(100, round(($active_site_count/$PRO_SITE_LIMIT)*100)) : 0;
  $sl_col  = $sl_pct>=100?'bg-red-500':($sl_pct>=80?'bg-amber-500':'bg-white/60');
?>
<div class="grid sm:grid-cols-2 gap-3 mb-8">

  <div class="glass rounded-2xl p-5 border border-white/5">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center"><i class="fa-solid fa-users text-slate-300 text-xs"></i></div>
        <div>
          <p class="text-sm font-semibold text-white">Lead Unlocks</p>
          <p class="text-xs text-slate-400" id="leadLimitSubtitle"><?= $pro_lead_count ?> of <?= $PRO_LEAD_LIMIT ?> used</p>
        </div>
      </div>
      <?php if($ll_pct>=80): ?>
      <a href="/portal/billing.php?upgrade=1&plan=entrepreneur" id="leadUpgradeBtn" class="text-xs bg-white hover:bg-slate-200 text-black px-3 py-1.5 rounded-full font-bold">
        <i class="fa-solid fa-rocket mr-1"></i>Upgrade
      </a>
      <?php else: ?>
      <span id="leadUpgradeBtn" class="hidden"></span>
      <?php endif; ?>
    </div>
    <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
      <div id="leadLimitBar" class="h-2 rounded-full transition-all <?= $ll_col ?>" style="width:<?= $ll_pct ?>%"
           data-used="<?= $pro_lead_count ?>" data-limit="<?= $PRO_LEAD_LIMIT ?>"></div>
    </div>
    <div class="flex items-center justify-between mt-1.5">
      <p class="text-xs text-slate-500" id="leadLimitNote"><?= max(0,$PRO_LEAD_LIMIT-$pro_lead_count) ?> remaining</p>
      <p class="text-xs text-slate-600" id="leadLimitCount"><?= $pro_lead_count ?> / <?= $PRO_LEAD_LIMIT ?></p>
    </div>
  </div>

  <div class="glass rounded-2xl p-5 border border-white/5">
    <div class="flex items-center gap-2 mb-3">
      <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center"><i class="fa-solid fa-globe text-slate-300 text-xs"></i></div>
      <div>
        <p class="text-sm font-semibold text-white">Active Sites</p>
        <p class="text-xs text-slate-400"><?= $active_site_count ?> of <?= $PRO_SITE_LIMIT ?> used</p>
      </div>
    </div>
    <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
      <div class="h-2 rounded-full transition-all <?= $sl_col ?>" style="width:<?= $sl_pct ?>%"></div>
    </div>
    <p class="text-xs text-slate-500 mt-1.5"><?= max(0,$PRO_SITE_LIMIT-$active_site_count) ?> slots remaining</p>
  </div>

</div>

<?php else: ?>
<?php
  $sl_pct = $ENT_SITE_LIMIT > 0 ? min(100, round(($active_site_count/$ENT_SITE_LIMIT)*100)) : 0;
  $sl_col = $sl_pct>=90?'bg-red-500':($sl_pct>=70?'bg-amber-500':'bg-white/60');
?>
<div class="grid sm:grid-cols-2 gap-3 mb-8">
  <div class="flex items-center gap-3 glass rounded-2xl px-5 py-4 border border-white/5">
    <i class="fa-solid fa-infinity text-white text-xl"></i>
    <div>
      <p class="text-sm font-semibold text-white">Unlimited Lead Searches</p>
      <p class="text-xs text-slate-400">Entrepreneur plan &mdash; no cap</p>
    </div>
  </div>
  <div class="glass rounded-2xl p-5 border border-white/5">
    <div class="flex items-center gap-2 mb-3">
      <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center"><i class="fa-solid fa-globe text-slate-300 text-xs"></i></div>
      <div>
        <p class="text-sm font-semibold text-white">Active Sites</p>
        <p class="text-xs text-slate-400"><?= $active_site_count ?> of <?= $ENT_SITE_LIMIT ?> used</p>
      </div>
    </div>
    <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
      <div class="h-2 rounded-full transition-all <?= $sl_col ?>" style="width:<?= $sl_pct ?>%"></div>
    </div>
    <p class="text-xs text-slate-500 mt-1.5"><?= max(0,$ENT_SITE_LIMIT-$active_site_count) ?> slots remaining</p>
  </div>
</div>
<?php endif; ?>

<!-- ====== SEARCH FORM ====== -->
<div class="glass rounded-2xl p-6 mb-6 border border-white/5">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Search Parameters</p>
  <form id="leadSearchForm">
    <div class="grid sm:grid-cols-3 gap-3 mb-5">
      <div>
        <label class="block text-xs text-slate-500 font-semibold uppercase tracking-wider mb-1.5">City <span class="text-red-400">*</span></label>
        <div class="relative">
          <i class="fa-solid fa-city absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
          <input type="text" name="city" id="fieldCity" placeholder="e.g. Calgary" required
                 class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-8 pr-4 py-2.5 text-sm focus:outline-none focus:border-white/40 transition-colors">
        </div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 font-semibold uppercase tracking-wider mb-1.5">Industry <span class="text-red-400">*</span></label>
        <div class="relative">
          <i class="fa-solid fa-briefcase absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
          <input type="text" name="industry" id="fieldIndustry" placeholder="e.g. Plumber, Dentist" required
                 class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-8 pr-4 py-2.5 text-sm focus:outline-none focus:border-white/40 transition-colors">
        </div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 font-semibold uppercase tracking-wider mb-1.5">
          Keywords <span class="text-slate-600 normal-case font-normal text-[10px]">(optional)</span>
        </label>
        <div class="relative">
          <i class="fa-solid fa-tags absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
          <input type="text" name="keywords" id="fieldKeywords" placeholder="e.g. no website, family-owned"
                 class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-8 pr-4 py-2.5 text-sm focus:outline-none focus:border-white/40 transition-colors">
        </div>
      </div>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-end gap-4">
      <div class="flex-1">
        <label class="flex justify-between text-xs text-slate-500 font-semibold uppercase tracking-wider mb-2">
          <span>How many leads?</span>
          <span class="text-white font-bold" id="leadCountDisplay"><?= $slider_default ?></span>
        </label>
        <input type="range" id="leadCountSlider" name="lead_count_slider"
               min="1" max="<?= $slider_max ?>" value="<?= $slider_default ?>"
               class="w-full accent-white h-2 cursor-pointer">
        <div class="flex justify-between text-[10px] text-slate-600 mt-1"><span>1</span><span><?= $slider_max ?></span></div>
      </div>
      <input type="hidden" id="leadCountHidden" name="lead_count" value="<?= $slider_default ?>">

      <div class="flex flex-col gap-1 pb-0.5">
        <label class="flex items-center gap-2.5 cursor-pointer group">
          <input type="checkbox" id="includeSeenLeads" name="include_seen"
                 class="w-4 h-4 rounded border-slate-600 bg-slate-800 accent-white cursor-pointer">
          <span class="text-xs text-slate-400 group-hover:text-white transition">Include already-seen leads</span>
        </label>
        <p class="text-[10px] text-slate-600 pl-6">
          <i class="fa-solid fa-circle-info mr-1"></i>Stored locally &mdash; never counts against your limit
        </p>
      </div>

      <button type="submit"
              class="inline-flex items-center gap-2 bg-white hover:bg-slate-200 active:scale-95 text-black px-7 py-2.5 rounded-xl font-bold text-sm transition-all whitespace-nowrap">
        <i class="fa-solid fa-magnifying-glass"></i> Find Leads
      </button>
    </div>
  </form>
</div>

<div id="leadsLoading" class="hidden">
  <div class="glass rounded-2xl p-10 text-center border border-white/5">
    <i class="fa-solid fa-spinner fa-spin text-white text-2xl mb-4 block"></i>
    <p class="text-slate-300 font-semibold">Searching Google Places&hellip;</p>
    <p class="text-slate-500 text-xs mt-1">Finding businesses without a website</p>
  </div>
</div>

<div id="leadsResultsWrap" class="hidden">
  <div id="leadsList" class="space-y-3"></div>
  <div id="lockedWrap" class="hidden mt-4">
    <div id="lockedList" class="space-y-3"></div>
    <div class="mt-6 rounded-2xl overflow-hidden border border-white/10 bg-white/3">
      <div class="px-6 pt-7 pb-3 text-center">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-white/8 border border-white/15 mb-4">
          <i class="fa-solid fa-lock text-white text-2xl"></i>
        </div>
        <h3 class="text-lg font-bold text-white mb-2">More Leads Are Waiting</h3>
        <p class="text-slate-400 text-sm max-w-xs mx-auto">
          You&rsquo;re seeing <strong class="text-white"><?= $FREE_LEAD_LIMIT ?> of the top results.</strong> Upgrade to unlock every lead.
        </p>
      </div>
      <div class="px-6 pb-6 flex flex-col sm:flex-row items-center justify-center gap-3 mt-3">
        <a href="/portal/billing.php?upgrade=1" class="w-full sm:w-auto bg-white hover:bg-slate-200 text-black px-8 py-3 rounded-xl font-bold text-sm text-center transition-all">
          <i class="fa-solid fa-crown mr-2"></i>Upgrade Plan
        </a>
        <p class="text-xs text-slate-500">Cancel anytime &bull; Instant access</p>
      </div>
    </div>
  </div>
</div>

</div><!-- /main column -->

<!-- ====== HISTORY SIDEBAR ====== -->
<aside class="w-64 shrink-0 hidden lg:flex flex-col gap-3">

  <div class="bg-slate-900/95 border border-white/5 rounded-2xl overflow-hidden backdrop-blur-xl sticky top-6">

    <!-- Header -->
    <div class="px-4 py-3.5 border-b border-white/5 flex items-center gap-2.5">
      <div class="w-7 h-7 rounded-lg bg-white/8 flex items-center justify-center shrink-0">
        <i class="fa-solid fa-clock-rotate-left text-slate-300 text-xs"></i>
      </div>
      <p class="text-sm font-semibold text-white">Recent Searches</p>
    </div>

    <!-- List -->
    <div id="searchHistoryList" class="py-1.5 max-h-[65vh] overflow-y-auto"></div>

    <!-- Empty state -->
    <div id="searchHistoryEmpty" class="px-5 py-8 text-center">
      <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center mx-auto mb-3">
        <i class="fa-solid fa-magnifying-glass text-slate-600 text-sm"></i>
      </div>
      <p class="text-xs text-slate-500 leading-relaxed">No searches yet.<br>Run one to see history here.</p>
    </div>

  </div>

</aside>

</div><!-- /page flex -->

<script
  id="leadsPageConfig"
  data-plan="<?= htmlspecialchars($plan) ?>"
  data-lead-used="<?= $pro_lead_count ?>"
  data-lead-limit="<?= $PRO_LEAD_LIMIT ?>"
  data-quota-used="<?= $quota_used ?>"
  data-quota-limit="<?= $FREE_SEARCH_LIMIT ?>"
  data-slider-max="<?= $slider_max ?>"
></script>
<script src="/assets/js/leads.js?v=v1000"></script>
