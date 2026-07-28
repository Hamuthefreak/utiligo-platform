<?php
/**
 * portal/index.php — Main user dashboard.
 * Bootstraps onboarding animation sessions.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plan_limits.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';

if (!is_logged_in()) {
    header('Location: /login.php?expired=1');
    exit;
}

// ── Fetch user ──────────────────────────────────────────────────────────
$userdb = get_user_db();
$stmt   = $userdb->prepare('SELECT * FROM utiligo_users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$user   = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: /login.php');
    exit;
}

$plan = $user['plan'] ?? 'free';

// ── Onboarding animation signals ─────────────────────────────────────────
$show_login_ob    = '';
$purchase_ob_plan = '';

if (!empty($_SESSION['show_login_onboarding'])) {
    $show_login_ob = $_SESSION['show_login_onboarding'];
    unset($_SESSION['show_login_onboarding']);
}
if (!empty($_SESSION['purchase_animation_plan'])) {
    $purchase_ob_plan = $_SESSION['purchase_animation_plan'];
    unset($_SESSION['purchase_animation_plan']);
}

$pageTitle = 'Dashboard — Utiligo';
require_once __DIR__ . '/../includes/header.php';
?>

<?php /* ── Onboarding animation injection ────────────────────────────── */ ?>
<link rel="stylesheet" href="/assets/css/onboarding.css">
<?php if ($show_login_ob): ?>
<script>
  sessionStorage.setItem('utl_show_login_ob', '1');
</script>
<script defer src="/assets/js/onboarding-login.js"></script>
<?php endif; ?>
<?php if ($purchase_ob_plan): ?>
<script defer src="/assets/js/onboarding-purchase.js"></script>
<?php endif; ?>

<?php /* Expose name + plan to animation scripts via body data attrs */ ?>
<?php /* We append to the existing body tag via a tiny inline script */ ?>
<script>
  (function(){
    var b = document.body;
    b.dataset.obName = <?= json_encode($user['full_name'] ?? '') ?>;
    b.dataset.obPlan = <?= json_encode($purchase_ob_plan ?: $plan) ?>;
  })();
</script>

<!-- ================================================================
     DASHBOARD CONTENT
     ============================================================= -->
<div class="max-w-6xl mx-auto px-6 py-10">

  <!-- Welcome header -->
  <div class="mb-10">
    <h1 class="text-3xl font-bold mb-1">
      Hey, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?> 👋
    </h1>
    <p class="text-slate-400 text-sm">
      <?php
        $planLabel = match($plan) {
          'pro'          => 'Pro Plan',
          'entrepreneur' => 'Entrepreneur Plan',
          default        => 'Free Plan',
        };
        echo htmlspecialchars($planLabel) . ' — ' . htmlspecialchars($user['email']);
      ?>
    </p>
  </div>

  <!-- Stat cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
    <?php
      $leadLimit = match($plan) {
        'pro'          => PRO_LEAD_LIMIT,
        'entrepreneur' => ENT_LEAD_LIMIT,
        default        => FREE_LEAD_LIMIT,
      };
      $siteLimit = match($plan) {
        'pro'          => PRO_SITE_LIMIT,
        'entrepreneur' => ENT_SITE_LIMIT,
        default        => FREE_SITE_LIMIT,
      };
      $cards = [
        ['icon' => 'fa-magnifying-glass', 'label' => 'Lead Limit',   'val' => $leadLimit === -1 ? 'Unlimited' : $leadLimit],
        ['icon' => 'fa-bolt',             'label' => 'Site Limit',   'val' => $siteLimit === -1 ? 'Unlimited' : $siteLimit],
        ['icon' => 'fa-palette',          'label' => 'Templates',    'val' => ($plan === 'free') ? FREE_TEMPLATE_LIMIT : 'All'],
        ['icon' => 'fa-users',            'label' => 'Team Seats',   'val' => ($plan === 'entrepreneur') ? ENT_TEAM_SEATS : '1'],
      ];
    ?>
    <?php foreach ($cards as $card): ?>
    <div class="bg-white/4 border border-white/8 rounded-2xl p-5">
      <i class="fa-solid <?= $card['icon'] ?> text-emerald-400 mb-3 text-lg"></i>
      <div class="text-2xl font-bold"><?= htmlspecialchars((string)$card['val']) ?></div>
      <div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($card['label']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Quick actions -->
  <div class="grid md:grid-cols-3 gap-4">
    <a href="/portal/leads.php"
       class="group bg-white/4 hover:bg-white/8 border border-white/8 rounded-2xl p-6 transition">
      <i class="fa-solid fa-magnifying-glass text-emerald-400 text-2xl mb-4"></i>
      <div class="font-bold mb-1">Find Leads</div>
      <div class="text-xs text-slate-500">Search local businesses needing a website</div>
    </a>
    <a href="/portal/sites.php"
       class="group bg-white/4 hover:bg-white/8 border border-white/8 rounded-2xl p-6 transition">
      <i class="fa-solid fa-bolt text-emerald-400 text-2xl mb-4"></i>
      <div class="font-bold mb-1">Build a Site</div>
      <div class="text-xs text-slate-500">Generate a ready-to-deliver website in seconds</div>
    </a>
    <a href="/portal/revenue.php"
       class="group bg-white/4 hover:bg-white/8 border border-white/8 rounded-2xl p-6 transition">
      <i class="fa-solid fa-chart-line text-emerald-400 text-2xl mb-4"></i>
      <div class="font-bold mb-1">Revenue</div>
      <div class="text-xs text-slate-500">Track what you've earned and what's coming in</div>
    </a>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
