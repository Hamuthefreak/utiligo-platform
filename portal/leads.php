<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lead_sources/_registry.php';

require_login();
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);
$uid     = (int)$user['id'];

// Phase 1: surfaced as the source multi-select chip on the UI. The user
// can toggle individual sources ON unless their plan doesn't include
// them, in which case the chip renders disabled with an Upgrade tooltip.
$lead_sources_allowed = plan_lead_sources($plan);
$lead_sources_all     = lead_source_registry();

// Preload this user's admin overrides once, then read every limit through
// the plans.php helpers.  This is the SAME path folders/api/find-leads.php,
// api/bar-status.php and api/lead-count.php use, so the value the user sees on
// the bar always matches the value the server actually enforces.  Reading the
// raw *constants* here (the old pattern) silently ignored per-user overrides
// set via Admin > Users.
preload_user_limit_overrides($uid);
$FREE_LEAD_LIMIT   = plan_lead_limit($plan, $uid);          // results-per-search on free (default 3)
$FREE_SEARCH_LIMIT = plan_search_daily_limit($plan, $uid);   // free searches per 24h (default 2)
$FREE_GEN_LIMIT    = (int)(defined('FREE_GENERATE_DAILY_LIMIT') ? FREE_GENERATE_DAILY_LIMIT : 1);
$FREE_TMPL_LIMIT   = (int)(defined('FREE_TEMPLATE_LIMIT')       ? FREE_TEMPLATE_LIMIT       : 2);
$_raw_pro_lead     = plan_lead_limit($plan, $uid);            // -1 = unlimited
$PRO_LEAD_LIMIT    = $_raw_pro_lead === -1 ? 0           : $_raw_pro_lead;   // 0 bars hide as unlimited
// PRO_SITE_LIMIT / ENT_SITE_LIMIT are shown BOTH to the user as their own cap
// (when they're already on that plan) AND as "upgrade to..." marketing copy
// to free users.  Only the user's *own* plan limit should honor their own
// per-user overrides — marketing copy for a plan the user is NOT on must
// show that plan's global default.
$PRO_SITE_LIMIT = $plan === 'pro'          ? plan_site_limit('pro', $uid)          : (int)(defined('PRO_SITE_LIMIT') ? PRO_SITE_LIMIT : 50);
$ENT_SITE_LIMIT = $plan === 'entrepreneur' ? plan_site_limit('entrepreneur', $uid) : (int)(defined('ENT_SITE_LIMIT') ? ENT_SITE_LIMIT : 500);

$lead_limit_js = match($plan) {
    'entrepreneur' => 0,
    'pro'          => $PRO_LEAD_LIMIT,
    default        => 0,
};
$site_limit_js = match($plan) {
    'entrepreneur' => $ENT_SITE_LIMIT,
    'pro'          => $PRO_SITE_LIMIT,
    default        => 0,
};

// ── Quota read: MUST match find-leads.php exactly ────────────────────────
// find-leads.php writes: fingerprint='uid_{uid}' on get_user_db()
// This read must use the same DB and the same fingerprint format.
$quota_used = 0; $quota_resets_at = null;
if (!$is_paid) {
    try {
        $udb         = get_user_db();                        // same DB as find-leads.php
        $fingerprint = 'uid_' . (int)$user['id'];           // same format as find-leads.php
        $cutoff      = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt = $udb->prepare('SELECT count, window_start FROM lead_search_quota WHERE fingerprint = ? AND window_start > ? LIMIT 1');
        $stmt->execute([$fingerprint, $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $quota_used = (int)$row['count']; $quota_resets_at = strtotime($row['window_start'])+86400; }
    } catch (\Throwable $e) {}
}
$quota_remaining = max(0, $FREE_SEARCH_LIMIT - $quota_used);
$quota_pct       = $FREE_SEARCH_LIMIT > 0 ? min(100, round(($quota_used/$FREE_SEARCH_LIMIT)*100)) : 0;

$pro_lead_count    = 0;
$active_site_count = 0;
if ($is_paid) {
    try {
        $pdo = get_platform_db();
        $s   = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id = ?');
        $s->execute([$user['id']]);
        $pro_lead_count = (int)$s->fetchColumn();
    } catch (\Throwable $e) {}
    try {
        $pdo = get_platform_db();
        $s   = $pdo->prepare('SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id = ? AND link_active = 1');
        $s->execute([$user['id']]);
        $active_site_count = (int)$s->fetchColumn();
    } catch (\Throwable $e) {}
}

$ll_pct     = ($plan === 'pro' && $PRO_LEAD_LIMIT > 0)
    ? min(100, (int)round($pro_lead_count / $PRO_LEAD_LIMIT * 100)) : 0;
$sl_pct_pro = ($PRO_SITE_LIMIT > 0)
    ? min(100, (int)round($active_site_count / $PRO_SITE_LIMIT * 100)) : 0;
$sl_pct_ent = ($ENT_SITE_LIMIT > 0)
    ? min(100, (int)round($active_site_count / $ENT_SITE_LIMIT * 100)) : 0;

$slider_max     = match($plan) { 'entrepreneur' => 40, 'pro' => 30, default => 10 };
$slider_default = match($plan) { 'entrepreneur' => 20, 'pro' => 10, default => 5 };

$pageTitle = 'Find Leads — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>
<style>
/* ---- Rail (desktop xl+) ---- */
#leadsRail {
  position:fixed; top:0; right:0; width:256px; height:100vh;
  display:none; flex-direction:column;
  background:rgba(15,23,42,.95);
  border-left:1px solid rgba(255,255,255,.05);
  backdrop-filter:blur(24px); -webkit-backdrop-filter:blur(24px);
  z-index:20; overflow:hidden;
}
@media (min-width:1280px) { #leadsRail { display:flex; } .lg\:ml-64 { padding-right:256px; } }

/* ---- Shared input styles ---- */
.hist-item {
  display:flex; flex-direction:column; gap:2px;
  width:100%; padding:10px 14px; border-radius:12px;
  border:1px solid transparent; text-align:left;
  font-size:.875rem; color:#94a3b8;
  transition:background .15s,color .15s,border-color .15s;
  cursor:pointer; background:none;
}
.hist-item:hover  { background:rgba(255,255,255,.06); color:#fff; border-color:rgba(255,255,255,.06); }
.hist-item:active { background:rgba(255,255,255,.1); }
.hist-item .hi-title { font-size:.8rem; font-weight:600; color:#e2e8f0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hist-item .hi-meta  { font-size:.7rem; color:#475569; }
.leads-input {
  width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07);
  color:#f1f5f9; font-size:.875rem; padding:.65rem .75rem .65rem 2.2rem;
  border-radius:10px; outline:none;
  transition:border-color .15s,background .15s;
  -webkit-appearance:none;
}
.leads-input::placeholder { color:#475569; }
.leads-input:focus { border-color:rgba(255,255,255,.22); background:rgba(255,255,255,.06); }
.leads-icon {
  position:absolute; left:.75rem; top:50%; transform:translateY(-50%);
  color:#475569; font-size:.7rem; pointer-events:none;
}
.q-track { height:4px; background:rgba(255,255,255,.06); border-radius:4px; overflow:hidden; }
.q-fill  { height:100%; border-radius:4px; transition:width .6s cubic-bezier(.4,0,.2,1); }

/* ---- Slider ---- */
.leads-slider { -webkit-appearance:none; appearance:none; width:100%; height:4px; background:rgba(255,255,255,.1); border-radius:4px; outline:none; cursor:pointer; }
.leads-slider::-webkit-slider-thumb { -webkit-appearance:none; width:20px; height:20px; border-radius:50%; background:#fff; cursor:pointer; box-shadow:0 0 0 3px rgba(255,255,255,.08); transition:transform .1s; }
.leads-slider::-webkit-slider-thumb:hover { transform:scale(1.15); }
.leads-slider::-moz-range-thumb { width:20px; height:20px; border-radius:50%; background:#fff; border:none; cursor:pointer; }

/* ---- Toggle ---- */
.tog-track { width:36px; height:20px; background:rgba(255,255,255,.08); border-radius:10px; position:relative; transition:background .2s; flex-shrink:0; }
.tog-track.on { background:rgba(255,255,255,.28); }
.tog-thumb { position:absolute; top:3px; left:3px; width:14px; height:14px; border-radius:50%; background:#475569; transition:transform .18s,background .18s; }
.tog-track.on .tog-thumb { transform:translateX(16px); background:#fff; }

/* ---- Skeleton ---- */
@keyframes leads-shimmer { 0%{background-position:-500px 0} 100%{background-position:500px 0} }
.skel { background:linear-gradient(90deg,rgba(255,255,255,.03) 25%,rgba(255,255,255,.07) 50%,rgba(255,255,255,.03) 75%); background-size:500px 100%; animation:leads-shimmer 1.5s infinite linear; border-radius:6px; }
@keyframes lead-in { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
.lead-in { animation:lead-in .22s ease both; }

/* ---- History drawer (mobile) ---- */
#historyDrawer {
  position:fixed; bottom:0; left:0; right:0; z-index:60;
  transform:translateY(100%); transition:transform .28s cubic-bezier(.4,0,.2,1);
  max-height:72vh; background:rgba(15,23,42,.98);
  border-top:1px solid rgba(255,255,255,.08); border-radius:20px 20px 0 0;
  backdrop-filter:blur(24px); display:flex; flex-direction:column;
}
#historyDrawer.open { transform:translateY(0); }
#historyDrawerOverlay { position:fixed; inset:0; z-index:59; background:rgba(0,0,0,.6); opacity:0; pointer-events:none; transition:opacity .25s; }
#historyDrawerOverlay.open { opacity:1; pointer-events:all; }

/* ---- Mobile search form stacks ---- */
@media (max-width:639px) {
  .search-actions {
    flex-direction:column;
    align-items:stretch;
  }
  .search-actions .tog-row {
    flex-direction:column;
    align-items:stretch;
    gap:12px;
  }
  .search-actions .tog-row > label {
    justify-content:space-between;
    align-self:stretch;
  }
  #searchBtn {
    width:100%;
    justify-content:center;
    padding-top:.85rem;
    padding-bottom:.85rem;
    margin-left:0;          /* override ml-auto */
  }
}

/* ---- Phase 1/3: source multi-select chip + view toggle + bulk + slide-over ---- */
.source-chip {
  position: relative; display:inline-flex; align-items:center; cursor:pointer;
  user-select:none; padding:0; border:none; background:none;
}
.source-chip-box {
  display:inline-flex; align-items:center; gap:.4rem;
  padding:.4rem .7rem;
  border-radius:9999px;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);
  color:#94a3b8;
  font-size:.75rem; font-weight:600;
  transition: background .15s, border-color .15s, color .15s;
}
.source-chip:hover .source-chip-box { background:rgba(255,255,255,.06); color:#e2e8f0; }
.source-chip-cb:checked + .source-chip-box {
  background:rgba(124,186,52,.10);
  border-color:rgba(124,186,52,.4);
  color:#e2e8f0;
}
.source-chip-cb:checked + .source-chip-box .source-chip-check { color:#7CBA34; }
.source-chip-check { color:#475569; font-size:9px; margin-left:.05rem; }
.source-chip[data-locked="1"] .source-chip-box { cursor:not-allowed; }

/* View toggle (card/table) */
.view-toggle { display:inline-flex; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:10px; padding:2px; gap:2px; }
.view-toggle button { padding:.35rem .65rem; border-radius:8px; font-size:.72rem; font-weight:600; color:#94a3b8; transition:all .15s; cursor:pointer; background:none; border:none; }
.view-toggle button:hover { color:#fff; }
.view-toggle button.active { background:rgba(255,255,255,.08); color:#fff; }

/* Bulk-select checkbox column */
.bulk-cb { width:16px; height:16px; cursor:pointer; accent-color:#7CBA34; }
.leads-table { width:100%; border-collapse:collapse; }
.leads-table th { text-align:left; padding:.5rem .65rem; color:#94a3b8; font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid rgba(255,255,255,.06); }
.leads-table td { padding:.6rem .65rem; border-bottom:1px solid rgba(255,255,255,.04); font-size:.8rem; }
.leads-table tr:hover td { background:rgba(255,255,255,.025); }
.leads-table tr.is-selected td { background:rgba(124,186,52,.06); }

/* Bulk action bar */
#bulkActionBar {
  position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(120%);
  transition:transform .25s cubic-bezier(.4,0,.2,1);
  background:rgba(15,23,42,.98); border:1px solid rgba(255,255,255,.1);
  padding:.7rem 1rem; border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,.5);
  z-index:90; display:flex; align-items:center; gap:.7rem;
  backdrop-filter:blur(24px); -webkit-backdrop-filter:blur(24px);
}
#bulkActionBar.show { transform:translateX(-50%) translateY(0); }

/* Slide-over detail panel */
#leadSlideOver {
  position:fixed; top:0; right:0; bottom:0; width:min(420px, 88vw);
  background:rgba(15,23,42,.98);
  border-left:1px solid rgba(255,255,255,.1);
  transform:translateX(100%);
  transition:transform .28s cubic-bezier(.4,0,.2,1);
  z-index:80; overflow-y:auto;
  backdrop-filter:blur(24px); -webkit-backdrop-filter:blur(24px);
  box-shadow:-12px 0 40px rgba(0,0,0,.4);
}
#leadSlideOver.open { transform:translateX(0); }
#leadSlideOverOverlay {
  position:fixed; inset:0; background:rgba(0,0,0,.5);
  z-index:79; opacity:0; pointer-events:none; transition:opacity .25s;
}
#leadSlideOverOverlay.open { opacity:1; pointer-events:auto; }
</style>

<!-- Rail (desktop) -->
<aside id="leadsRail">
  <div class="px-5 py-5 border-b border-white/5 shrink-0">
    <div class="flex items-center gap-2">
      <i class="fa-solid fa-clock-rotate-left text-slate-600 text-xs"></i>
      <span class="text-sm font-bold text-white tracking-tight">Recent Searches</span>
      <span id="historyCount" class="ml-auto text-[10px] font-bold text-slate-600 bg-white/5 px-1.5 py-0.5 rounded-full hidden"></span>
    </div>
  </div>
  <nav id="searchHistoryList" class="flex-1 overflow-y-auto px-3 py-3 space-y-0.5" style="scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.05) transparent;"></nav>
  <div id="searchHistoryEmpty" class="flex-1 flex flex-col items-center justify-center px-6 pb-8 text-center">
    <div class="w-10 h-10 rounded-2xl bg-white/[.03] border border-white/5 flex items-center justify-center mb-4">
      <i class="fa-solid fa-magnifying-glass text-slate-700 text-sm"></i>
    </div>
    <p class="text-xs font-semibold text-slate-500">No searches yet</p>
    <p class="text-[11px] text-slate-700 mt-1 leading-relaxed">Run your first search<br>and it'll show up here.</p>
  </div>
  <div class="px-4 py-4 border-t border-white/5 shrink-0">
    <p class="text-[10px] text-slate-700 text-center"><i class="fa-solid fa-hand-pointer mr-1"></i>Click any entry to re-run it</p>
  </div>
</aside>
<script>document.addEventListener('DOMContentLoaded',function(){var r=document.getElementById('leadsRail');if(r)document.body.appendChild(r);});</script>

<!-- History drawer (mobile) -->
<div id="historyDrawerOverlay" onclick="closeHistoryDrawer()"></div>
<div id="historyDrawer">
  <div class="flex justify-center pt-3 pb-1 shrink-0"><div class="w-8 h-1 rounded-full bg-white/10"></div></div>
  <div class="flex items-center justify-between px-5 py-3 border-b border-white/5 shrink-0">
    <div class="flex items-center gap-2">
      <i class="fa-solid fa-clock-rotate-left text-slate-500 text-xs"></i>
      <span class="text-sm font-bold text-white">Recent Searches</span>
    </div>
    <button onclick="closeHistoryDrawer()" class="w-7 h-7 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-slate-400 hover:text-white transition">
      <i class="fa-solid fa-xmark text-xs"></i>
    </button>
  </div>
  <div id="historyDrawerList" class="flex-1 overflow-y-auto px-3 py-2 space-y-0.5" style="scrollbar-width:thin;"></div>
  <div id="historyDrawerEmpty" class="flex flex-col items-center justify-center py-12 text-center px-6">
    <i class="fa-solid fa-magnifying-glass text-slate-700 text-2xl mb-3"></i>
    <p class="text-sm font-semibold text-slate-600">Nothing here yet</p>
    <p class="text-xs text-slate-700 mt-1">Search for a city &amp; industry first.</p>
  </div>
  <div class="px-4 py-4 border-t border-white/5 shrink-0">
    <p class="text-[11px] text-slate-700 text-center">Tap any entry to re-run it</p>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded',function(){
    ['historyDrawer','historyDrawerOverlay'].forEach(function(id){
      var el=document.getElementById(id); if(el) document.body.appendChild(el);
    });
  });
</script>

<!-- History FAB (mobile) -->
<button id="historyFab"
  class="xl:hidden fixed bottom-6 right-6 z-50 h-12 px-5 rounded-full
         bg-slate-800 border border-white/10 shadow-xl
         flex items-center gap-2 text-slate-300 text-sm font-semibold
         hover:bg-slate-700 active:scale-95 transition-all"
  onclick="openHistoryDrawer()">
  <i class="fa-solid fa-clock-rotate-left"></i>
  <span>History</span>
</button>


<!-- PAGE HEADER -->
<div class="mb-5 sm:mb-6">
  <div class="flex items-start justify-between flex-wrap gap-3">
    <div class="min-w-0">
      <h1 class="text-xl sm:text-2xl font-bold tracking-tight">Find Leads</h1>
      <p class="text-slate-500 text-xs sm:text-sm mt-1">Discover local businesses with no website — your next paying clients.</p>
    </div>
    <button onclick="openHistoryDrawer()"
      class="xl:hidden flex items-center gap-1.5 text-xs text-slate-500 hover:text-white
             bg-white/5 border border-white/8 px-3 py-2 rounded-xl transition shrink-0">
      <i class="fa-solid fa-clock-rotate-left text-[10px]"></i> History
    </button>
  </div>
</div>


<!-- FREE PLAN STATS -->
<?php if (!$is_paid): ?>
<div class="space-y-3 mb-7">
  <div class="glass rounded-2xl p-4 sm:p-5">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-xl bg-white/5 flex items-center justify-center shrink-0">
          <i class="fa-solid fa-magnifying-glass text-slate-400 text-xs"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-white leading-none">Daily Search Quota</p>
          <p class="text-xs text-slate-600 mt-0.5">Resets every 24 hours</p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <span id="quotaBadge" class="text-xs font-bold px-2.5 py-1 rounded-full
          <?= $quota_remaining===0 ? 'bg-red-500/10 text-red-400' : ($quota_remaining===1 ? 'bg-amber-500/10 text-amber-400' : 'bg-white/5 text-slate-400') ?>">
          <?= $quota_remaining===0 ? 'No searches left' : $quota_remaining.' search'.($quota_remaining!==1?'es':'').' left' ?>
        </span>
        <a href="/portal/billing.php?upgrade=1" class="text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1.5 rounded-full transition">
          <i class="fa-solid fa-crown mr-1"></i>Upgrade
        </a>
      </div>
    </div>
    <div class="q-track"><div id="quotaBar" class="q-fill <?= $quota_pct>=100?'bg-red-400':($quota_pct>=50?'bg-amber-400':'bg-white/40') ?>" style="width:<?=$quota_pct?>%"></div></div>
    <div class="flex justify-between text-[11px] text-slate-600 mt-2">
      <span id="quotaText"><?=$quota_used?> of <?=$FREE_SEARCH_LIMIT?> used</span>
      <span><?php if($quota_resets_at):?>Resets at <span class="text-slate-500"><?=date('g:i A',$quota_resets_at)?></span><?php else:?>Resets 24h after first search<?php endif;?></span>
    </div>
  </div>

  <!-- Free plan stat tiles: 2-col on mobile, 4-col on sm+ -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
    <?php foreach([
      ['fa-users','Leads','per search',$FREE_LEAD_LIMIT],
      ['fa-magnifying-glass','Searches','per day',$FREE_SEARCH_LIMIT],
      ['fa-bolt','Sites','per day',$FREE_GEN_LIMIT],
      ['fa-layer-group','Templates','available',$FREE_TMPL_LIMIT],
    ] as [$ic,$lbl,$sub,$val]):?>
    <div class="glass rounded-xl p-3 text-center">
      <p class="text-xl font-extrabold text-white"><?=$val?></p>
      <p class="text-[10px] font-semibold text-slate-500 mt-0.5 uppercase tracking-wide"><?=$lbl?></p>
      <p class="text-[10px] text-slate-700"><?=$sub?></p>
    </div>
    <?php endforeach;?>
  </div>

  <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 glass rounded-xl px-4 py-3">
    <i class="fa-solid fa-arrow-trend-up text-slate-400 text-sm shrink-0 mt-0.5 sm:mt-0"></i>
    <p class="text-xs text-slate-400 flex-1">Upgrade to <strong class="text-white">Pro</strong> for <?=$PRO_LEAD_LIMIT?> lead unlocks, <?=$PRO_SITE_LIMIT?> active sites &amp; unlimited searches.</p>
    <a href="/portal/billing.php?upgrade=1" class="w-full sm:w-auto text-center text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1.5 rounded-full whitespace-nowrap transition">Go Pro</a>
  </div>
</div>

<?php else: ?>
<!-- PAID PLAN STAT BARS -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-7">

  <!-- Lead Unlocks -->
  <div class="glass rounded-2xl p-4 sm:p-5">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
      <div class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-xl bg-white/5 flex items-center justify-center shrink-0">
          <i class="fa-solid fa-users text-slate-400 text-xs"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-white leading-none">Lead Unlocks</p>
          <p class="text-xs text-slate-600 mt-0.5" id="leadBarSubtitle">
            <?php if ($plan === 'entrepreneur'): ?>
              <?=$pro_lead_count?> unlocked &mdash; unlimited
            <?php else: ?>
              <?=$pro_lead_count?> of <?=$PRO_LEAD_LIMIT?> used
            <?php endif; ?>
          </p>
        </div>
      </div>
      <?php if ($plan === 'pro'): ?>
      <span id="leadUpgradeBtn" class="<?=$ll_pct<80?'hidden':''?>">
        <a href="/portal/billing.php?upgrade=1&plan=entrepreneur"
           class="text-xs font-bold bg-white hover:bg-slate-200 text-black px-3 py-1.5 rounded-full transition">
          <i class="fa-solid fa-rocket mr-1"></i>Upgrade
        </a>
      </span>
      <?php endif; ?>
    </div>
    <div class="q-track">
      <div id="leadBar"
           class="q-fill <?=$ll_pct>=100?'bg-red-400':($ll_pct>=80?'bg-amber-400':'bg-white/40')?>"
           style="width:<?=$ll_pct?>%"></div>
    </div>
    <div class="flex justify-between text-[11px] text-slate-600 mt-2">
      <span id="leadBarNote">
        <?php if ($plan === 'entrepreneur'): ?>
          No cap &mdash; Entrepreneur plan
        <?php else: ?>
          <?=max(0,$PRO_LEAD_LIMIT-$pro_lead_count)?> remaining
        <?php endif; ?>
      </span>
      <span id="leadBarCount">
        <?php if ($plan === 'entrepreneur'): ?>
          <?=$pro_lead_count?> / &infin;
        <?php else: ?>
          <?=$pro_lead_count?> / <?=$PRO_LEAD_LIMIT?>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- Active Sites -->
  <div class="glass rounded-2xl p-4 sm:p-5">
    <div class="flex items-center gap-2.5 mb-3">
      <div class="w-8 h-8 rounded-xl bg-white/5 flex items-center justify-center shrink-0">
        <i class="fa-solid fa-globe text-slate-400 text-xs"></i>
      </div>
      <div>
        <p class="text-sm font-semibold text-white leading-none">Active Sites</p>
        <p class="text-xs text-slate-600 mt-0.5" id="siteBarSubtitle">
          <?php if ($plan === 'entrepreneur'): ?>
            <?=$active_site_count?> of <?=$ENT_SITE_LIMIT?> used
          <?php else: ?>
            <?=$active_site_count?> of <?=$PRO_SITE_LIMIT?> used
          <?php endif; ?>
        </p>
      </div>
    </div>
    <?php
      $sl_pct = ($plan === 'entrepreneur') ? $sl_pct_ent : $sl_pct_pro;
      $sl_col = $sl_pct >= 100 ? 'bg-red-400' : ($sl_pct >= 80 ? 'bg-amber-400' : 'bg-white/40');
    ?>
    <div class="q-track">
      <div id="siteBar" class="q-fill <?=$sl_col?>" style="width:<?=$sl_pct?>%"></div>
    </div>
    <div class="flex justify-between text-[11px] text-slate-600 mt-2">
      <span id="siteBarNote">
        <?php
          $site_cap = ($plan === 'entrepreneur') ? $ENT_SITE_LIMIT : $PRO_SITE_LIMIT;
          echo max(0, $site_cap - $active_site_count).' remaining';
        ?>
      </span>
      <span id="siteBarCount">
        <?= $active_site_count.' / '.(($plan==='entrepreneur') ? $ENT_SITE_LIMIT : $PRO_SITE_LIMIT) ?>
      </span>
    </div>
  </div>

</div>
<?php endif; ?>


<!-- SEARCH CARD -->
<div class="glass rounded-2xl mb-6" id="searchBox">
  <div class="flex items-center justify-between px-4 sm:px-5 pt-4 pb-3 border-b border-white/5">
    <div class="flex items-center gap-2">
      <i class="fa-solid fa-crosshairs text-slate-600 text-xs"></i>
      <span class="text-xs font-semibold text-slate-500 uppercase tracking-widest">Search Parameters</span>
    </div>
    <span id="searchStatusChip" class="hidden text-[11px] font-semibold text-slate-500 bg-white/5 px-2.5 py-1 rounded-full"></span>
  </div>
  <form id="leadSearchForm" class="p-4 sm:p-5">
    <!-- Fields: stack on mobile, 3-col on sm+ -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-widest mb-1.5">City <span class="text-slate-700">*</span></label>
        <div class="relative"><i class="fa-solid fa-city leads-icon"></i>
          <input type="text" name="city" id="fieldCity" placeholder="e.g. Calgary" required autocomplete="off" class="leads-input">
        </div>
      </div>
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-widest mb-1.5">Industry <span class="text-slate-700">*</span></label>
        <div class="relative"><i class="fa-solid fa-briefcase leads-icon"></i>
          <input type="text" name="industry" id="fieldIndustry" placeholder="e.g. Plumber, Dentist" required autocomplete="off" class="leads-input">
        </div>
      </div>
      <div>
        <label class="block text-[10px] font-semibold text-slate-600 uppercase tracking-widest mb-1.5">Keywords <span class="text-slate-700 normal-case font-normal text-[9px]">(optional)</span></label>
        <div class="relative"><i class="fa-solid fa-tags leads-icon"></i>
          <input type="text" name="keywords" id="fieldKeywords" placeholder="e.g. family-owned" class="leads-input">
        </div>
      </div>
    </div>

    <!-- Sources: Phase 1 multi-select chip strip -->
    <?php
      // Only render the strip if the plan has more than one source available
      // (free plans have only Google — they get a single disabled chip plus
      // an Upgrade tooltip; pro/ent get toggleable chips).
      $all_sources  = $lead_sources_all;        // registry: every key/label/icon
      $allowed_keys = array_flip($lead_sources_allowed); // plan-allowed keys
    ?>
    <div class="mb-5">
      <div class="flex items-center gap-2 mb-2">
        <i class="fa-solid fa-database text-slate-600 text-xs"></i>
        <span class="text-[10px] font-semibold text-slate-600 uppercase tracking-widest">Sources</span>
        <span class="text-[10px] text-slate-700 font-normal ml-1">(toggle which data providers your search draws from)</span>
      </div>
      <div class="flex flex-wrap gap-2" id="sourceChipStrip">
        <?php foreach ($all_sources as $key => $meta): ?>
          <?php
            $enabled = isset($allowed_keys[$key]);
            // Default state: enabled sources start checked; the rest display
            // disabled with a lock + Upgrade tooltip.
            $checked = $enabled ? 'checked' : '';
            $cls     = $enabled ? '' : 'opacity-50 cursor-not-allowed';
            $icon    = $meta['icon'] ?? 'fa-database';
            $label   = $meta['label'] ?? $key;
            $color   = $meta['color'] ?? '#94a3b8';
          ?>
          <label class="source-chip <?= htmlspecialchars($cls) ?>" data-source="<?= htmlspecialchars($key) ?>" <?= $enabled ? '' : 'title="Available on Pro and Entrepreneur plans" data-locked="1"' ?>>
            <input type="checkbox" class="sr-only source-chip-cb" <?= $checked ?> data-source="<?= htmlspecialchars($key) ?>" <?= $enabled ? '' : 'disabled' ?>>
            <span class="source-chip-box">
              <i class="fa-solid <?= htmlspecialchars($icon) ?> source-chip-icon" style="color:<?= htmlspecialchars($color) ?>"></i>
              <span class="source-chip-label"><?= htmlspecialchars($label) ?></span>
              <?php if (!$enabled): ?>
                <i class="fa-solid fa-lock text-[9px] text-slate-600 ml-1"></i>
              <?php else: ?>
                <i class="fa-solid fa-check source-chip-check"></i>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Slider + toggle + button: stacked on mobile -->
    <div class="flex flex-col gap-4 search-actions">
      <!-- Slider -->
      <div class="flex-1">
        <div class="flex items-center justify-between mb-2">
          <span class="text-[10px] font-semibold text-slate-600 uppercase tracking-widest">Lead count</span>
          <span class="text-sm font-extrabold text-white tabular-nums" id="leadCountDisplay"><?=$slider_default?></span>
        </div>
        <input type="range" id="leadCountSlider" min="1" max="<?=$slider_max?>" value="<?=$slider_default?>" class="leads-slider">
        <div class="flex justify-between text-[10px] text-slate-700 mt-1"><span>1</span><span><?=$slider_max?></span></div>
      </div>
      <!-- Toggle + submit row -->
      <div class="flex items-center gap-3 tog-row">
        <label class="flex items-center gap-2.5 cursor-pointer select-none">
          <div class="tog-track" id="togTrack"><div class="tog-thumb"></div></div>
          <span class="text-sm text-slate-400">Include seen</span>
          <input type="checkbox" id="includeSeenLeads" class="sr-only">
        </label>
        <input type="hidden" id="leadCountHidden" name="lead_count" value="<?=$slider_default?>">
        <button type="submit" id="searchBtn"
          class="ml-auto inline-flex items-center gap-2 bg-white hover:bg-slate-200 active:scale-95 text-black
                 px-6 py-3 rounded-xl font-bold text-sm transition-all shrink-0 whitespace-nowrap">
          <i class="fa-solid fa-magnifying-glass text-xs"></i>
          <span id="searchBtnLabel">Find Leads</span>
        </button>
      </div>
    </div>
  </form>
</div>


<!-- Phase 3: view toggle + bulk-select controls (appears above results once
     a search runs; hidden until then) -->
<div id="resultsToolbar" class="hidden glass rounded-2xl mb-4 px-3 py-2 flex items-center gap-3 flex-wrap">
  <div class="view-toggle" role="group" aria-label="View mode">
    <button type="button" id="viewCardBtn" class="active" data-view="card">
      <i class="fa-solid fa-table-cells-large mr-1"></i>Cards
    </button>
    <button type="button" id="viewTableBtn" data-view="table">
      <i class="fa-solid fa-table mr-1"></i>Table
    </button>
  </div>
  <div class="ml-auto flex items-center gap-3">
    <label class="flex items-center gap-2 text-xs text-slate-400 cursor-pointer select-none">
      <input type="checkbox" id="bulkModeToggle" class="bulk-cb">
      <span>Select leads</span>
    </label>
    <button type="button" id="exportLaunchBtn" class="text-xs font-semibold text-slate-300 hover:text-white px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 transition">
      <i class="fa-solid fa-file-export mr-1"></i>Export
    </button>
    <button type="button" id="saveSearchBtn" class="text-xs font-semibold text-slate-300 hover:text-white px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 transition">
      <i class="fa-solid fa-star mr-1"></i>Save search
    </button>
  </div>
</div>


<!-- Skeleton -->
<div id="leadsLoading" class="hidden space-y-3">
  <?php for($i=0;$i<3;$i++):?>
  <div class="glass rounded-2xl p-4 sm:p-5">
    <div class="flex items-start gap-3">
      <div class="flex-1 space-y-2.5">
        <div class="skel h-4" style="width:<?=[52,60,44][$i]?>%"></div>
        <div class="skel h-3" style="width:<?=[70,55,65][$i]?>%"></div>
        <div class="skel h-3" style="width:<?=[38,45,30][$i]?>%"></div>
      </div>
      <div class="skel h-8 w-20 rounded-xl"></div>
    </div>
    <div class="mt-4 pt-4 border-t border-white/5 flex gap-2">
      <div class="skel h-7 w-28 rounded-lg"></div>
      <?php if($i!==1):?><div class="skel h-7 w-20 rounded-lg"></div><?php endif;?>
    </div>
  </div>
  <?php endfor;?>
  <p class="text-xs text-slate-700 text-center pt-1 animate-pulse"><i class="fa-solid fa-satellite-dish mr-1"></i>Scanning Google Places&hellip;</p>
</div>


<!-- Results -->
<div id="leadsResultsWrap" class="hidden">
  <div id="leadsList" class="space-y-2.5"></div>
  <div id="lockedWrap" class="hidden mt-3">
    <div id="lockedList" class="space-y-2.5"></div>
    <div class="mt-5 glass rounded-2xl overflow-hidden">
      <div class="px-5 sm:px-6 pt-6 pb-3 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white/5 mb-4">
          <i class="fa-solid fa-lock text-white text-lg"></i>
        </div>
        <h3 class="text-base font-bold text-white mb-1">More Leads Are Waiting</h3>
        <p class="text-slate-400 text-sm max-w-xs mx-auto">You&rsquo;re seeing <strong class="text-white"><?=$FREE_LEAD_LIMIT?> of the top results.</strong> Upgrade to unlock every lead.</p>
      </div>
      <div class="px-5 sm:px-6 pb-6 flex flex-col sm:flex-row items-center justify-center gap-3">
        <a href="/portal/billing.php?upgrade=1" class="w-full sm:w-auto bg-white hover:bg-slate-200 text-black px-7 py-2.5 rounded-xl font-bold text-sm text-center transition">
          <i class="fa-solid fa-crown mr-2"></i>Upgrade Plan
        </a>
        <p class="text-xs text-slate-600">Cancel anytime &bull; Instant access</p>
      </div>
    </div>
  </div>
</div>

<!-- Extra bottom padding so FAB doesn't overlap last result on mobile -->
<div class="xl:hidden h-20"></div>


<!-- Phase 3: slide-over detail panel + overlay + bulk action bar + export sheet -->
<div id="leadSlideOverOverlay" onclick="closeLeadSlideOver()"></div>
<aside id="leadSlideOver" aria-hidden="true" aria-labelledby="slideOverTitle">
  <div class="flex items-center justify-between px-5 py-4 border-b border-white/5 sticky top-0 bg-slate-950/80 backdrop-blur z-10" style="background:rgba(15,23,42,.92)">
    <div>
      <p class="text-[10px] text-slate-600 uppercase tracking-widest font-semibold">Lead Detail</p>
      <h3 id="slideOverTitle" class="text-base font-bold text-white leading-tight mt-0.5">—</h3>
    </div>
    <button type="button" onclick="closeLeadSlideOver()" class="w-8 h-8 rounded-lg hover:bg-white/5 flex items-center justify-center text-slate-400 hover:text-white transition" aria-label="Close">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>
  <div id="slideOverBody" class="p-5 space-y-5">
    <p class="text-slate-600 text-xs">No lead selected.</p>
  </div>
</aside>

<div id="bulkActionBar">
  <span class="text-sm font-semibold text-white"><span id="bulkCount">0</span> selected</span>
  <button type="button" id="bulkUnlockBtn" class="text-xs font-semibold text-white bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-400/30 px-3 py-1.5 rounded-lg transition">
    <i class="fa-solid fa-unlock mr-1"></i>Add to CRM
  </button>
  <button type="button" id="bulkExportBtn" class="text-xs font-semibold text-white bg-indigo-500/15 hover:bg-indigo-500/25 border border-indigo-400/30 px-3 py-1.5 rounded-lg transition">
    <i class="fa-solid fa-download mr-1"></i>Export
  </button>
  <button type="button" id="bulkClearBtn" class="text-xs text-slate-400 hover:text-white px-2 py-1.5 transition">
    <i class="fa-solid fa-xmark"></i>
  </button>
</div>

<!-- Export launcher sheet (Phase 4 UI) -->
<div id="exportSheetOverlay" class="fixed inset-0 bg-black/50 z-[81] opacity-0 pointer-events-none transition-opacity duration-200"></div>
<div id="exportSheet" class="fixed top-0 right-0 bottom-0 w-[440px] max-w-[90vw] bg-slate-950 z-[82] transform translate-x-full transition-transform duration-300 border-l border-white/10 overflow-y-auto">
  <div class="flex items-center justify-between px-5 py-4 border-b border-white/5 sticky top-0 bg-slate-950 z-10">
    <div>
      <p class="text-[10px] text-slate-600 uppercase tracking-widest font-semibold">Export LEADS</p>
      <h3 class="text-base font-bold text-white leading-tight mt-0.5">Build a file</h3>
    </div>
    <button type="button" onclick="closeExportSheet()" class="w-8 h-8 rounded-lg hover:bg-white/5 flex items-center justify-center text-slate-400 hover:text-white transition" aria-label="Close">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>
  <div class="p-5 space-y-5">
    <?php if (empty(plan_export_formats($plan))): ?>
      <div class="text-center py-8">
        <div class="w-12 h-12 rounded-2xl bg-white/5 flex items-center justify-center mx-auto mb-3">
          <i class="fa-solid fa-lock text-slate-600"></i>
        </div>
        <p class="text-sm text-slate-400 font-semibold mb-1">Exports are a Pro feature</p>
        <p class="text-xs text-slate-600 mb-4">Upgrade to download your leads as CSV, XLSX, vCard, or PDF.</p>
        <a href="/portal/billing.php?upgrade=1" class="inline-block text-xs font-bold text-black bg-white px-4 py-2 rounded-lg hover:bg-slate-200">
          <i class="fa-solid fa-crown mr-1"></i>Upgrade now
        </a>
      </div>
    <?php else: ?>
      <div>
        <p class="text-[10px] font-semibold text-slate-600 uppercase tracking-widest mb-2">Format</p>
        <div class="grid grid-cols-3 gap-2" id="exportFormatGrid">
          <?php foreach (plan_export_formats($plan) as $fmt): ?>
            <button type="button" data-format="<?= htmlspecialchars($fmt) ?>" class="export-format-btn text-xs font-semibold text-slate-300 hover:text-white px-2 py-2.5 rounded-lg border border-white/10 hover:border-white/20 hover:bg-white/5 transition flex flex-col items-center gap-1.5">
              <i class="fa-solid <?= [
                  'csv'   => 'fa-file-csv',
                  'xlsx'  => 'fa-file-excel',
                  'vcard' => 'fa-address-card',
                  'json'  => 'fa-file-code',
                  'pdf'   => 'fa-file-pdf',
              ][$fmt] ?? 'fa-file' ?>"></i>
              <span><?= strtoupper($fmt) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <p class="text-[10px] font-semibold text-slate-600 uppercase tracking-widest mb-2">Scope</p>
        <select id="exportScopeSelect" class="leads-input" style="padding-left:.75rem">
          <?php if (!empty($lead_sources_allowed)): ?>
            <option value="unlocked">My unlocked leads</option>
          <?php endif; ?>
          <option value="<?= !empty($lead_sources_allowed) ? 'search' : 'all' ?>">Current search results</option>
          <option value="all">All matching leads (entire table)</option>
        </select>
      </div>
      <div>
        <p class="text-[10px] font-semibold text-slate-600 uppercase tracking-widest mb-2">Columns</p>
        <div class="grid grid-cols-2 gap-1.5">
          <?php
            $all_cols = ['business_name','business_category','business_city','business_address','business_phone','business_email','rating','total_ratings','maps_url','website','source','country','lat','lng','business_hours','price_level','international_phone','opportunity_score'];
            $defaults = ['business_name','business_category','business_city','business_phone','business_email','website','opportunity_score'];
            foreach ($all_cols as $c):
          ?>
            <label class="flex items-center gap-2 text-xs text-slate-400 cursor-pointer">
              <input type="checkbox" class="bulk-cb export-col-cb" value="<?= htmlspecialchars($c) ?>" <?= in_array($c,$defaults,true) ? 'checked' : '' ?>>
              <span><?= htmlspecialchars(str_replace('_',' ',ucfirst($c))) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="button" id="exportBuildBtn" class="w-full text-sm font-bold text-black bg-white hover:bg-slate-200 px-4 py-2.5 rounded-xl transition disabled:opacity-50 disabled:cursor-not-allowed">
        <i class="fa-solid fa-file-export mr-2"></i>Build export
      </button>
      <div id="exportProgress" class="hidden text-center py-6">
        <i class="fa-solid fa-spinner fa-spin text-slate-400 mb-2"></i>
        <p class="text-xs text-slate-400">Building…</p>
      </div>
      <div id="exportResult" class="hidden"></div>
      <p class="text-[10px] text-slate-600 leading-relaxed">Your daily export quota: <span id="exportDailyCount" class="text-slate-400 font-semibold">—</span> / <?= plan_export_daily_limit($plan) ?> exports.</p>
    <?php endif; ?>
  </div>
</div>

<script>
function openLeadSlideOver(leadData) {
  if (!leadData) return;
  var panel = document.getElementById('leadSlideOver');
  var overlay = document.getElementById('leadSlideOverOverlay');
  var title = document.getElementById('slideOverTitle');
  var body = document.getElementById('slideOverBody');
  if (!panel) return;
  // Body content rendered client-side by leads.js after Phase 3 init.
  try { window._leadsRenderSlideOver(body, leadData); } catch(e) {
    title.textContent = leadData.business_name || 'Lead';
    body.innerHTML = '<p class="text-xs text-slate-500">Detail unavailable.</p>';
  }
  panel.classList.add('open');
  panel.setAttribute('aria-hidden', 'false');
  if (overlay) overlay.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLeadSlideOver() {
  var panel = document.getElementById('leadSlideOver');
  var overlay = document.getElementById('leadSlideOverOverlay');
  if (panel) { panel.classList.remove('open'); panel.setAttribute('aria-hidden','true'); }
  if (overlay) overlay.classList.remove('open');
  document.body.style.overflow = '';
}
function openExportSheet() {
  var sheet = document.getElementById('exportSheet');
  var overlay = document.getElementById('exportSheetOverlay');
  if (sheet) sheet.style.transform = 'translateX(0)';
  if (overlay) { overlay.style.opacity = '1'; overlay.style.pointerEvents = 'auto'; }
  document.body.style.overflow = 'hidden';
}
function closeExportSheet() {
  var sheet = document.getElementById('exportSheet');
  var overlay = document.getElementById('exportSheetOverlay');
  if (sheet) sheet.style.transform = 'translateX(100%)';
  if (overlay) { overlay.style.opacity = '0'; overlay.style.pointerEvents = 'none'; }
  document.body.style.overflow = '';
}
document.addEventListener('DOMContentLoaded', function() {
  var overlay = document.getElementById('exportSheetOverlay');
  if (overlay) overlay.addEventListener('click', closeExportSheet);
  var launch = document.getElementById('exportLaunchBtn');
  if (launch) launch.addEventListener('click', openExportSheet);
});
</script>


<!-- PHP-baked config for JS -->
<script id="leadsPageConfig"
  data-plan="<?=htmlspecialchars($plan,ENT_QUOTES)?>"
  data-lead-count="<?=$pro_lead_count?>"
  data-lead-limit="<?=$lead_limit_js?>"
  data-site-count="<?=$active_site_count?>"
  data-site-limit="<?=$site_limit_js?>"
  data-quota-used="<?=$quota_used?>"
  data-quota-limit="<?=$FREE_SEARCH_LIMIT?>"
  data-sources="<?=htmlspecialchars(implode(',', $lead_sources_allowed), ENT_QUOTES)?>"
  data-all-sources="<?=htmlspecialchars(implode(',', array_keys($lead_sources_all)), ENT_QUOTES)?>"
  data-export-formats="<?=htmlspecialchars(implode(',', plan_export_formats($plan)), ENT_QUOTES)?>"
  data-export-q-limit="<?=plan_export_daily_limit($plan)?>"
></script>
<script src="/assets/js/leads.js?v=2102"></script>

<script>
function openHistoryDrawer() {
  var drawer=document.getElementById('historyDrawer'),
      overlay=document.getElementById('historyDrawerOverlay'),
      src=document.getElementById('searchHistoryList'),
      dest=document.getElementById('historyDrawerList'),
      emptyD=document.getElementById('historyDrawerEmpty');
  if(src&&dest){
    dest.innerHTML=src.innerHTML;
    var has=dest.querySelector('button')!==null;
    if(emptyD) emptyD.style.display=has?'none':'';
    dest.querySelectorAll('button[data-city]').forEach(function(btn){
      btn.addEventListener('click',function(){
        document.getElementById('fieldCity').value=this.dataset.city||'';
        document.getElementById('fieldIndustry').value=this.dataset.industry||'';
        document.getElementById('fieldKeywords').value=this.dataset.keywords||'';
        closeHistoryDrawer();
        setTimeout(function(){document.getElementById('searchBox').scrollIntoView({behavior:'smooth',block:'start'});},160);
      });
    });
  }
  if(drawer) drawer.classList.add('open');
  if(overlay) overlay.classList.add('open');
  document.body.style.overflow='hidden';
}
function closeHistoryDrawer(){
  var d=document.getElementById('historyDrawer'),o=document.getElementById('historyDrawerOverlay');
  if(d) d.classList.remove('open');
  if(o) o.classList.remove('open');
  document.body.style.overflow='';
}
</script>
