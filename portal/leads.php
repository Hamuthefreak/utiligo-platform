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

$FREE_LEAD_LIMIT    = defined('FREE_LEAD_LIMIT')           ? (int)FREE_LEAD_LIMIT           : 3;
$FREE_SEARCH_LIMIT  = defined('FREE_SEARCH_DAILY_LIMIT')   ? (int)FREE_SEARCH_DAILY_LIMIT   : 2;
$FREE_SITE_LIMIT    = defined('FREE_SITE_LIMIT')           ? (int)FREE_SITE_LIMIT           : 1;
$FREE_GEN_LIMIT     = defined('FREE_GENERATE_DAILY_LIMIT') ? (int)FREE_GENERATE_DAILY_LIMIT : 1;
$FREE_TMPL_LIMIT    = defined('FREE_TEMPLATE_LIMIT')       ? (int)FREE_TEMPLATE_LIMIT       : 2;
$PRO_LEAD_LIMIT     = defined('PRO_LEAD_LIMIT')            ? (int)PRO_LEAD_LIMIT            : 120;
$PRO_SITE_LIMIT     = defined('PRO_SITE_LIMIT')            ? (int)PRO_SITE_LIMIT            : 200;
$ENT_SITE_LIMIT     = defined('ENT_SITE_LIMIT')            ? (int)ENT_SITE_LIMIT            : 500;

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
    } catch (\Throwable $e) { $pro_lead_count = 0; }
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

<style>
/* ── Leads page overrides ─────────────────────────────────────── */
.search-input {
  width: 100%;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  color: #fff;
  font-size: .875rem;
  padding: .625rem .75rem .625rem 2.25rem;
  border-radius: 6px;
  outline: none;
  transition: border-color .15s, background .15s;
}
.search-input::placeholder { color: #475569; }
.search-input:focus {
  border-color: rgba(255,255,255,0.25);
  background: rgba(255,255,255,0.07);
}
.search-input:focus + .input-glow { opacity: 1; }
.field-icon {
  position: absolute; left: .7rem; top: 50%; transform: translateY(-50%);
  color: #475569; font-size: .75rem; pointer-events: none;
  transition: color .15s;
}
.search-input:focus ~ .field-icon { color: #94a3b8; }
/* Skeleton shimmer */
@keyframes shimmer {
  0%   { background-position: -600px 0; }
  100% { background-position:  600px 0; }
}
.skeleton {
  background: linear-gradient(90deg, rgba(255,255,255,.04) 25%, rgba(255,255,255,.09) 50%, rgba(255,255,255,.04) 75%);
  background-size: 600px 100%;
  animation: shimmer 1.4s infinite linear;
  border-radius: 4px;
}
/* Lead card entry animation */
@keyframes leadIn {
  from { opacity:0; transform: translateY(8px); }
  to   { opacity:1; transform: translateY(0); }
}
.lead-card-enter { animation: leadIn .22s ease forwards; }
/* Range slider */
#leadCountSlider {
  -webkit-appearance: none;
  appearance: none;
  height: 3px;
  border-radius: 2px;
  background: rgba(255,255,255,0.1);
  outline: none;
  cursor: pointer;
  width: 100%;
}
#leadCountSlider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 14px; height: 14px;
  border-radius: 50%;
  background: #fff;
  cursor: pointer;
  transition: transform .1s;
}
#leadCountSlider::-webkit-slider-thumb:hover { transform: scale(1.2); }
</style>

<div class="flex gap-6 items-start">

<!-- ====== MAIN COLUMN ====== -->
<div class="flex-1 min-w-0">

<!-- Page header -->
<div class="mb-7">
  <h1 class="text-2xl font-bold tracking-tight">Find Leads</h1>
  <p class="text-slate-500 text-sm mt-1">Discover local businesses with no website &mdash; your next paying clients.</p>
</div>

<?php /* ====== QUOTA / PLAN BARS ====== */ ?>
<?php if (!$is_paid): ?>
<div class="space-y-3 mb-7">
  <!-- Quota card -->
  <div class="border border-white/6 bg-white/[.03] rounded-lg p-4">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
      <div class="flex items-center gap-2.5">
        <i class="fa-solid fa-magnifying-glass text-slate-500 text-xs"></i>
        <div>
          <p class="text-sm font-semibold text-white leading-none">Daily Search Quota</p>
          <p class="text-xs text-slate-600 mt-0.5">Resets every 24 hours</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <span id="quotaBadge" class="text-xs font-bold px-2.5 py-1 rounded border <?= $quota_remaining===0?'bg-red-500/10 border-red-500/20 text-red-400':($quota_remaining===1?'bg-amber-500/10 border-amber-500/20 text-amber-400':'bg-white/5 border-white/8 text-slate-300') ?>">
          <?= $quota_remaining===0 ? 'No searches left' : $quota_remaining.' search'.($quota_remaining!==1?'es':'').' left' ?>
        </span>
        <a href="/portal/billing" class="text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1 rounded transition">
          <i class="fa-solid fa-crown mr-1"></i>Upgrade
        </a>
      </div>
    </div>
    <div class="w-full h-[2px] bg-white/5 rounded-full overflow-hidden">
      <div id="quotaBar" class="h-full transition-all duration-500 <?= $quota_pct>=100?'bg-red-500':($quota_pct>=50?'bg-amber-400':'bg-emerald-500') ?>" style="width:<?= $quota_pct ?>%"></div>
    </div>
    <div class="flex justify-between text-[11px] text-slate-600 mt-1.5">
      <span id="quotaText"><?= $quota_used ?> of <?= $FREE_SEARCH_LIMIT ?> used</span>
      <?php if($quota_resets_at): ?>
        <span>Resets at <strong class="text-slate-400"><?= date('g:i A',$quota_resets_at) ?></strong></span>
      <?php else: ?>
        <span>Resets 24 h after first search</span>
      <?php endif; ?>
    </div>
  </div>
  <!-- Limit chips -->
  <div class="grid grid-cols-4 gap-2">
    <?php foreach([
      ['Leads','per search',$FREE_LEAD_LIMIT],
      ['Searches','per day',$FREE_SEARCH_LIMIT],
      ['Sites','per day',$FREE_GEN_LIMIT],
      ['Templates','available',$FREE_TMPL_LIMIT],
    ] as [$lbl,$sub,$val]): ?>
    <div class="border border-white/6 bg-white/[.02] rounded-lg p-3 text-center">
      <p class="text-xl font-extrabold text-white"><?= $val ?></p>
      <p class="text-[10px] text-slate-500 mt-0.5 uppercase tracking-wide"><?= $lbl ?></p>
      <p class="text-[10px] text-slate-700"><?= $sub ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <!-- Upsell strip -->
  <div class="flex items-center gap-3 border border-white/6 bg-white/[.02] rounded-lg px-4 py-3">
    <i class="fa-solid fa-arrow-trend-up text-slate-400 text-sm shrink-0"></i>
    <p class="text-xs text-slate-400 flex-1">Upgrade to <strong class="text-white">Pro</strong> for <?= $PRO_LEAD_LIMIT ?> lead unlocks, <?= $PRO_SITE_LIMIT ?> active sites &amp; unlimited searches.</p>
    <a href="/portal/billing?upgrade=1" class="text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1.5 rounded whitespace-nowrap transition">Go Pro</a>
  </div>
</div>

<?php elseif ($plan === 'pro'): ?>
<?php
  $ll_pct = $PRO_LEAD_LIMIT > 0 ? min(100, round(($pro_lead_count/$PRO_LEAD_LIMIT)*100)) : 0;
  $sl_pct = $PRO_SITE_LIMIT > 0 ? min(100, round(($active_site_count/$PRO_SITE_LIMIT)*100)) : 0;
?>
<div class="grid sm:grid-cols-2 gap-3 mb-7">
  <div class="border border-white/6 bg-white/[.02] rounded-lg p-4">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <i class="fa-solid fa-users text-slate-500 text-xs"></i>
        <p class="text-sm font-semibold text-white">Lead Unlocks</p>
      </div>
      <span id="leadUpgradeBtn" class="<?= $ll_pct<80?'hidden':'' ?>">
        <a href="/portal/billing?upgrade=1&plan=entrepreneur" class="text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1 rounded transition"><i class="fa-solid fa-rocket mr-1"></i>Upgrade</a>
      </span>
    </div>
    <div class="w-full h-[2px] bg-white/5 rounded-full overflow-hidden">
      <div id="leadLimitBar" class="h-full transition-all <?= $ll_pct>=100?'bg-red-500':($ll_pct>=80?'bg-amber-400':'bg-emerald-500') ?>" style="width:<?= $ll_pct ?>%" data-used="<?= $pro_lead_count ?>" data-limit="<?= $PRO_LEAD_LIMIT ?>"></div>
    </div>
    <div class="flex justify-between text-[11px] text-slate-500 mt-1.5">
      <span id="leadLimitSubtitle"><?= $pro_lead_count ?> of <?= $PRO_LEAD_LIMIT ?> used</span>
      <span id="leadLimitNote"><?= max(0,$PRO_LEAD_LIMIT-$pro_lead_count) ?> remaining</span>
    </div>
  </div>
  <div class="border border-white/6 bg-white/[.02] rounded-lg p-4">
    <div class="flex items-center gap-2 mb-3">
      <i class="fa-solid fa-globe text-slate-500 text-xs"></i>
      <p class="text-sm font-semibold text-white">Active Sites</p>
    </div>
    <div class="w-full h-[2px] bg-white/5 rounded-full overflow-hidden">
      <div class="h-full transition-all <?= $sl_pct>=100?'bg-red-500':($sl_pct>=80?'bg-amber-400':'bg-emerald-500') ?>" style="width:<?= $sl_pct ?>%"></div>
    </div>
    <div class="flex justify-between text-[11px] text-slate-500 mt-1.5">
      <span><?= $active_site_count ?> of <?= $PRO_SITE_LIMIT ?> used</span>
      <span><?= max(0,$PRO_SITE_LIMIT-$active_site_count) ?> remaining</span>
    </div>
  </div>
</div>

<?php else: ?>
<?php $sl_pct=$ENT_SITE_LIMIT>0?min(100,round(($active_site_count/$ENT_SITE_LIMIT)*100)):0; ?>
<div class="grid sm:grid-cols-2 gap-3 mb-7">
  <div class="flex items-center gap-3 border border-white/6 bg-white/[.02] rounded-lg px-4 py-4">
    <i class="fa-solid fa-infinity text-white text-xl"></i>
    <div><p class="text-sm font-semibold text-white">Unlimited Lead Searches</p><p class="text-xs text-slate-500">Entrepreneur plan &mdash; no cap</p></div>
  </div>
  <div class="border border-white/6 bg-white/[.02] rounded-lg p-4">
    <div class="flex items-center gap-2 mb-3"><i class="fa-solid fa-globe text-slate-500 text-xs"></i><p class="text-sm font-semibold text-white">Active Sites</p></div>
    <div class="w-full h-[2px] bg-white/5 rounded-full overflow-hidden"><div class="h-full transition-all <?= $sl_pct>=90?'bg-red-500':($sl_pct>=70?'bg-amber-400':'bg-emerald-500') ?>" style="width:<?= $sl_pct ?>%"></div></div>
    <div class="flex justify-between text-[11px] text-slate-500 mt-1.5"><span><?= $active_site_count ?> of <?= $ENT_SITE_LIMIT ?> used</span><span><?= max(0,$ENT_SITE_LIMIT-$active_site_count) ?> remaining</span></div>
  </div>
</div>
<?php endif; ?>

<!-- ====== SEARCH FORM ====== -->
<div class="border border-white/8 bg-white/[.025] rounded-lg mb-6" id="searchBox">

  <!-- Form header -->
  <div class="flex items-center justify-between px-5 py-3.5 border-b border-white/6">
    <div class="flex items-center gap-2">
      <i class="fa-solid fa-crosshairs text-slate-400 text-xs"></i>
      <span class="text-xs font-semibold text-slate-300 uppercase tracking-widest">Search Parameters</span>
    </div>
    <span id="searchStatusChip" class="hidden text-[10px] font-bold px-2 py-0.5 rounded border border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
      <i class="fa-solid fa-circle-check mr-1"></i>Results ready
    </span>
  </div>

  <form id="leadSearchForm" class="p-5">
    <!-- Inputs row -->
    <div class="grid sm:grid-cols-3 gap-3 mb-5">
      <!-- City -->
      <div>
        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-widest mb-1.5">City <span class="text-red-500">*</span></label>
        <div class="relative">
          <i class="fa-solid fa-city field-icon"></i>
          <input type="text" name="city" id="fieldCity" placeholder="e.g. Calgary" required autocomplete="off" class="search-input">
        </div>
      </div>
      <!-- Industry -->
      <div>
        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-widest mb-1.5">Industry <span class="text-red-500">*</span></label>
        <div class="relative">
          <i class="fa-solid fa-briefcase field-icon"></i>
          <input type="text" name="industry" id="fieldIndustry" placeholder="e.g. Plumber, Dentist" required autocomplete="off" class="search-input">
        </div>
      </div>
      <!-- Keywords -->
      <div>
        <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-widest mb-1.5">Keywords <span class="text-slate-700 normal-case font-normal">(optional)</span></label>
        <div class="relative">
          <i class="fa-solid fa-tags field-icon"></i>
          <input type="text" name="keywords" id="fieldKeywords" placeholder="e.g. family-owned" class="search-input">
        </div>
      </div>
    </div>

    <!-- Bottom row: slider + checkbox + button -->
    <div class="flex flex-col sm:flex-row sm:items-center gap-5">

      <!-- Slider -->
      <div class="flex-1">
        <div class="flex items-center justify-between mb-2">
          <label class="text-[10px] font-bold text-slate-600 uppercase tracking-widest">Lead count</label>
          <span class="text-sm font-extrabold text-white tabular-nums" id="leadCountDisplay"><?= $slider_default ?></span>
        </div>
        <input type="range" id="leadCountSlider" name="lead_count_slider" min="1" max="<?= $slider_max ?>" value="<?= $slider_default ?>">
        <div class="flex justify-between text-[10px] text-slate-700 mt-1"><span>1</span><span><?= $slider_max ?></span></div>
      </div>

      <!-- Include seen -->
      <label class="flex items-center gap-2 cursor-pointer select-none shrink-0">
        <div class="relative">
          <input type="checkbox" id="includeSeenLeads" name="include_seen" class="sr-only peer">
          <div class="w-8 h-4 bg-white/8 border border-white/10 rounded-sm peer-checked:bg-emerald-600 peer-checked:border-emerald-500 transition-all"></div>
          <div class="absolute top-0.5 left-0.5 w-3 h-3 bg-white rounded-sm transition-all peer-checked:translate-x-4"></div>
        </div>
        <span class="text-xs text-slate-500">Include seen leads</span>
      </label>

      <input type="hidden" id="leadCountHidden" name="lead_count" value="<?= $slider_default ?>">

      <!-- Submit -->
      <button type="submit" id="searchBtn"
        class="inline-flex items-center gap-2 bg-white hover:bg-slate-100 active:scale-95 text-black px-6 py-2.5 rounded-md font-bold text-sm transition-all shrink-0 whitespace-nowrap">
        <i class="fa-solid fa-magnifying-glass text-xs"></i>
        <span id="searchBtnLabel">Find Leads</span>
      </button>
    </div>
  </form>
</div>

<!-- ====== SKELETON LOADER ====== -->
<div id="leadsLoading" class="hidden space-y-3">
  <div class="border border-white/6 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <div class="flex-1 space-y-2">
        <div class="skeleton h-4 w-48"></div>
        <div class="skeleton h-3 w-64"></div>
        <div class="skeleton h-3 w-32"></div>
      </div>
      <div class="skeleton h-8 w-20 rounded-md"></div>
    </div>
    <div class="mt-3 pt-3 border-t border-white/5 flex gap-2">
      <div class="skeleton h-7 w-28 rounded-md"></div>
      <div class="skeleton h-7 w-24 rounded-md"></div>
    </div>
  </div>
  <div class="border border-white/6 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <div class="flex-1 space-y-2">
        <div class="skeleton h-4 w-56"></div>
        <div class="skeleton h-3 w-44"></div>
        <div class="skeleton h-3 w-36"></div>
      </div>
      <div class="skeleton h-8 w-20 rounded-md"></div>
    </div>
    <div class="mt-3 pt-3 border-t border-white/5 flex gap-2">
      <div class="skeleton h-7 w-28 rounded-md"></div>
    </div>
  </div>
  <div class="border border-white/6 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <div class="flex-1 space-y-2">
        <div class="skeleton h-4 w-40"></div>
        <div class="skeleton h-3 w-52"></div>
        <div class="skeleton h-3 w-28"></div>
      </div>
      <div class="skeleton h-8 w-20 rounded-md"></div>
    </div>
    <div class="mt-3 pt-3 border-t border-white/5 flex gap-2">
      <div class="skeleton h-7 w-28 rounded-md"></div>
      <div class="skeleton h-7 w-24 rounded-md"></div>
    </div>
  </div>
  <p class="text-xs text-slate-600 text-center pt-1"><i class="fa-solid fa-satellite-dish mr-1 animate-pulse"></i>Scanning Google Places&hellip;</p>
</div>

<!-- ====== RESULTS ====== -->
<div id="leadsResultsWrap" class="hidden">
  <div id="leadsList" class="space-y-2.5"></div>
  <div id="lockedWrap" class="hidden mt-3">
    <div id="lockedList" class="space-y-2.5"></div>
    <div class="mt-5 border border-white/8 bg-white/[.02] rounded-lg overflow-hidden">
      <div class="px-6 pt-6 pb-3 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white/6 border border-white/10 mb-4">
          <i class="fa-solid fa-lock text-white text-lg"></i>
        </div>
        <h3 class="text-base font-bold text-white mb-1">More Leads Are Waiting</h3>
        <p class="text-slate-400 text-sm max-w-xs mx-auto">You're seeing <strong class="text-white"><?= $FREE_LEAD_LIMIT ?> of the top results.</strong> Upgrade to unlock every lead.</p>
      </div>
      <div class="px-6 pb-5 flex flex-col sm:flex-row items-center justify-center gap-3">
        <a href="/portal/billing?upgrade=1" class="w-full sm:w-auto bg-white hover:bg-slate-200 text-black px-7 py-2.5 rounded-md font-bold text-sm text-center transition">
          <i class="fa-solid fa-crown mr-2"></i>Upgrade Plan
        </a>
        <p class="text-xs text-slate-600">Cancel anytime &bull; Instant access</p>
      </div>
    </div>
  </div>
</div>

</div><!-- /main column -->

<!-- ====== HISTORY SIDEBAR ====== -->
<aside class="hidden lg:flex w-64 shrink-0 flex-col gap-0">
  <div class="sticky top-6 flex flex-col gap-3">
    <div class="border border-white/6 bg-slate-900/80 rounded-lg flex flex-col overflow-hidden backdrop-blur-xl" style="max-height:calc(100vh - 3rem);">

      <div class="flex items-center justify-between px-4 py-3.5 border-b border-white/6 shrink-0">
        <div class="flex items-center gap-2">
          <i class="fa-solid fa-clock-rotate-left text-slate-500 text-xs"></i>
          <span class="text-xs font-bold text-slate-300 uppercase tracking-widest">Recent Searches</span>
        </div>
        <span id="historyCount" class="text-[10px] font-bold text-slate-600 bg-white/5 px-1.5 py-0.5 rounded hidden"></span>
      </div>

      <nav id="searchHistoryList" class="flex-1 overflow-y-auto px-2 py-2 space-y-0.5"
           style="scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.06) transparent;"></nav>

      <div id="searchHistoryEmpty" class="flex flex-col items-center justify-center px-5 py-8 text-center">
        <i class="fa-solid fa-magnifying-glass text-slate-700 text-xl mb-3"></i>
        <p class="text-xs font-semibold text-slate-600">No searches yet</p>
        <p class="text-[11px] text-slate-700 mt-0.5 leading-relaxed">Run a search to see<br>your history here.</p>
      </div>

      <div class="px-4 py-2.5 border-t border-white/5 shrink-0">
        <p class="text-[10px] text-slate-700 text-center">Click any search to re-run it</p>
      </div>
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
<script src="/assets/js/leads.js?v=v1300"></script>
