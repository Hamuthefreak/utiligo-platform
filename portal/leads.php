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

// Daily search quota (free only)
$quota_used = 0; $quota_limit = (int)FREE_SEARCH_DAILY_LIMIT; $quota_resets_at = null;
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
$quota_remaining = max(0, $quota_limit - $quota_used);
$quota_pct       = $quota_limit > 0 ? round(($quota_used/$quota_limit)*100) : 0;

// Pro/Ent lead counts
$pro_lead_count = 0;
$pro_lead_limit = plan_lead_limit($plan);
if ($plan === 'pro') {
    try {
        $pdo  = get_platform_db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM unlocked_leads WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $pro_lead_count = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {}
}

// Max slider values per plan
$slider_max = match($plan) { 'entrepreneur' => 40, 'pro' => 30, default => 10 };
$slider_default = match($plan) { 'entrepreneur' => 20, 'pro' => 10, default => 5 };

$pageTitle = 'Find Leads — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-6">
  <h1 class="text-3xl font-bold tracking-tight">Find Leads</h1>
  <p class="text-slate-400 text-sm mt-1">Discover local businesses with no website &mdash; your next paying clients.</p>
</div>

<?php if (!$is_paid): ?>
<!-- Free daily quota -->
<div id="quotaBanner" class="glass rounded-2xl p-5 mb-8 border border-white/5">
  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
    <div class="flex items-center gap-2">
      <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center"><i class="fa-solid fa-magnifying-glass text-slate-300 text-xs"></i></div>
      <div><p class="text-sm font-semibold text-white">Daily Search Quota</p><p class="text-xs text-slate-400">Resets every 24 hours</p></div>
    </div>
    <div class="flex items-center gap-3">
      <div id="quotaBadge" class="flex items-center gap-1.5 <?= $quota_remaining===0?'bg-red-500/10 border border-red-500/20 text-red-400':($quota_remaining===1?'bg-amber-500/10 border border-amber-500/20 text-amber-400':'bg-white/8 border border-white/10 text-slate-300') ?> rounded-full px-3 py-1 text-xs font-bold">
        <?= $quota_remaining===0 ? 'No searches left today' : $quota_remaining.' search'.($quota_remaining!==1?'es':'').' left' ?>
      </div>
      <a href="/portal/billing.php?upgrade=1" class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold"><i class="fa-solid fa-crown mr-1"></i>Upgrade</a>
    </div>
  </div>
  <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
    <div id="quotaBar" class="h-2 rounded-full transition-all duration-500 <?= $quota_pct>=100?'bg-red-500':($quota_pct>=50?'bg-amber-500':'bg-white/60') ?>" style="width:<?= $quota_pct ?>%"></div>
  </div>
  <div class="flex justify-between text-xs text-slate-500 mt-1.5">
    <span id="quotaText"><?= $quota_used ?> of <?= $quota_limit ?> searches used</span>
    <?php if($quota_resets_at): ?><span>Resets at <?= date('g:i A',$quota_resets_at) ?></span><?php else: ?><span>Resets 24h after first search</span><?php endif; ?>
  </div>
</div>

<?php elseif ($plan === 'pro' && $pro_lead_limit > 0): ?>
<?php $ll_pct = min(100, round(($pro_lead_count/$pro_lead_limit)*100)); ?>
<div class="glass rounded-2xl p-5 mb-8 border border-white/5" id="leadLimitBanner">
  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
    <div class="flex items-center gap-2">
      <div class="w-8 h-8 rounded-xl bg-white/8 flex items-center justify-center"><i class="fa-solid fa-users text-slate-300 text-xs"></i></div>
      <div>
        <p class="text-sm font-semibold text-white">Lead Unlock Limit</p>
        <p class="text-xs text-slate-400" id="leadLimitSubtitle"><?= $pro_lead_count ?> of <?= $pro_lead_limit ?> leads unlocked</p>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <span class="text-xs font-bold" id="leadLimitCount"><?= $pro_lead_count ?> / <?= $pro_lead_limit ?></span>
      <?php if ($ll_pct >= 80): ?>
      <a href="/portal/billing.php?upgrade=1&plan=entrepreneur" id="leadUpgradeBtn"
         class="text-xs bg-white hover:bg-slate-200 text-black px-4 py-1.5 rounded-full font-bold hidden">
        <i class="fa-solid fa-rocket mr-1"></i>Upgrade
      </a>
      <?php endif; ?>
    </div>
  </div>
  <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
    <div id="leadLimitBar" class="h-2 rounded-full transition-all duration-700
      <?= $ll_pct>=100?'bg-red-500':($ll_pct>=80?'bg-amber-500':'bg-white/60') ?>"
      style="width:<?= $ll_pct ?>%"
      data-used="<?= $pro_lead_count ?>"
      data-limit="<?= $pro_lead_limit ?>">
    </div>
  </div>
  <p class="text-xs text-slate-500 mt-1.5" id="leadLimitNote">
    <?= $pro_lead_limit - $pro_lead_count ?> leads remaining on your Pro plan
  </p>
</div>
<?php else: ?>
<div class="flex items-center gap-2 bg-white/5 border border-white/10 rounded-full px-4 py-2 text-sm mb-8 w-fit">
  <i class="fa-solid fa-infinity text-white"></i>
  <span class="text-slate-300 font-semibold">Entrepreneur &mdash; Unlimited leads</span>
</div>
<?php endif; ?>

<!-- Search form -->
<div class="glass rounded-2xl p-6 mb-6 border border-white/5">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Search Parameters</p>
  <form id="leadSearchForm">
    <!-- Row 1: City + Industry + Keywords -->
    <div class="grid sm:grid-cols-3 gap-3 mb-5">
      <div>
        <label class="block text-xs text-slate-500 font-semibold uppercase tracking-wider mb-1.5">City <span class="text-red-400">*</span></label>
        <div class="relative">
          <i class="fa-solid fa-city absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
          <input type="text" name="city" placeholder="e.g. Calgary" required
                 class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-8 pr-4 py-2.5 text-sm focus:outline-none focus:border-white/40 transition-colors">
        </div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 font-semibold uppercase tracking-wider mb-1.5">Industry <span class="text-red-400">*</span></label>
        <div class="relative">
          <i class="fa-solid fa-briefcase absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
          <input type="text" name="industry" placeholder="e.g. Plumber, Dentist" required
                 class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-8 pr-4 py-2.5 text-sm focus:outline-none focus:border-white/40 transition-colors">
        </div>
      </div>
      <div>
        <label class="block text-xs text-slate-500 font-semibold uppercase tracking-wider mb-1.5">
          Keywords <span class="text-slate-600 normal-case font-normal text-[10px]">(optional)</span>
        </label>
        <div class="relative">
          <i class="fa-solid fa-tags absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
          <input type="text" name="keywords" placeholder="e.g. no website, family-owned"
                 class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl pl-8 pr-4 py-2.5 text-sm focus:outline-none focus:border-white/40 transition-colors">
        </div>
      </div>
    </div>

    <!-- Row 2: Slider + seen-leads checkbox + submit -->
    <div class="flex flex-col sm:flex-row sm:items-end gap-4">

      <!-- Lead count slider -->
      <div class="flex-1">
        <label class="flex justify-between text-xs text-slate-500 font-semibold uppercase tracking-wider mb-2">
          <span>How many leads?</span>
          <span class="text-white font-bold" id="leadCountDisplay"><?= $slider_default ?></span>
        </label>
        <input type="range" id="leadCountSlider" name="lead_count_slider"
               min="1" max="<?= $slider_max ?>" value="<?= $slider_default ?>"
               class="w-full accent-white h-2 cursor-pointer">
        <div class="flex justify-between text-[10px] text-slate-600 mt-1">
          <span>1</span>
          <span><?= $slider_max ?></span>
        </div>
      </div>
      <input type="hidden" id="leadCountHidden" name="lead_count" value="<?= $slider_default ?>">

      <!-- Seen leads checkbox -->
      <div class="flex flex-col gap-1 pb-0.5">
        <label class="flex items-center gap-2.5 cursor-pointer group">
          <div class="relative">
            <input type="checkbox" id="includeSeenLeads" name="include_seen" class="sr-only peer">
            <div class="w-4 h-4 rounded border border-slate-600 bg-slate-800 peer-checked:bg-white peer-checked:border-white transition-all flex items-center justify-center">
              <i class="fa-solid fa-check text-black text-[8px] hidden peer-checked-show"></i>
            </div>
          </div>
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

<!-- Loading -->
<div id="leadsLoading" class="hidden">
  <div class="glass rounded-2xl p-10 text-center border border-white/5">
    <i class="fa-solid fa-spinner fa-spin text-white text-2xl mb-4 block"></i>
    <p class="text-slate-300 font-semibold">Searching Google Places&hellip;</p>
    <p class="text-slate-500 text-xs mt-1">Finding businesses without a website</p>
  </div>
</div>

<!-- Results -->
<div id="leadsResultsWrap" class="hidden">

  <!-- Dot map -->
  <div id="leadMapWrap" class="hidden glass rounded-2xl border border-white/5 mb-5 overflow-hidden">
    <div class="flex items-center justify-between px-5 py-3 border-b border-white/5">
      <div class="flex items-center gap-2">
        <i class="fa-solid fa-map-location-dot text-slate-400 text-sm"></i>
        <span class="text-sm font-semibold">Lead Locations</span>
        <span class="text-xs text-slate-500" id="mapSubtitle"></span>
      </div>
      <div class="flex items-center gap-3 text-[10px] text-slate-500">
        <span><span class="inline-block w-2 h-2 rounded-full bg-white mr-1"></span>No website</span>
        <span><span class="inline-block w-2 h-2 rounded-full bg-amber-400 mr-1"></span>Has website</span>
      </div>
    </div>
    <div id="leadMap" class="relative bg-slate-900 w-full" style="height:220px;">
      <!-- dots injected by JS -->
    </div>
  </div>

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
          You&rsquo;re seeing <strong class="text-white"><?= (int)FREE_LEAD_LIMIT ?> of the top results.</strong> Upgrade to unlock every lead.
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

<style>
/* Custom checkbox check icon visibility */
#includeSeenLeads:checked ~ div .peer-checked-show { display: inline !important; }
/* Slider thumb */
input[type=range] { height: 6px; border-radius: 99px; }
</style>

<script
  id="leadsPageConfig"
  data-plan="<?= htmlspecialchars($plan) ?>"
  data-lead-used="<?= $pro_lead_count ?>"
  data-lead-limit="<?= $pro_lead_limit ?>"
  data-quota-used="<?= $quota_used ?>"
  data-quota-limit="<?= $quota_limit ?>"
  data-slider-max="<?= $slider_max ?>"
></script>
<script src="/assets/js/leads.js?v=v700"></script>
