<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();
$step = (int)($_GET['step'] ?? 1);
$step = max(1, min(4, $step));

$pdo = get_platform_db();
$stmt = $pdo->prepare('SELECT * FROM utiligo_whitelabel WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$wl = $stmt->fetch() ?: ['brand_name' => 'Utiligo', 'primary_color' => '#10B981', 'secondary_color' => '#1E293B'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) die('Invalid CSRF token.');
    $brandName = sanitize($_POST['brand_name'] ?? 'Utiligo');
    $primaryColor = sanitize($_POST['primary_color'] ?? '#10B981');
    $exists = $pdo->prepare('SELECT id FROM utiligo_whitelabel WHERE user_id = ?');
    $exists->execute([$user['id']]);
    if ($exists->fetch()) {
        $pdo->prepare('UPDATE utiligo_whitelabel SET brand_name=?, primary_color=? WHERE user_id=?')->execute([$brandName, $primaryColor, $user['id']]);
    } else {
        $pdo->prepare('INSERT INTO utiligo_whitelabel (user_id, brand_name, primary_color) VALUES (?,?,?)')->execute([$user['id'], $brandName, $primaryColor]);
    }
    header('Location: /portal/onboarding.php?step=3');
    exit;
}

$pageTitle = 'Welcome to Pro — Utiligo';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-2xl mx-auto px-6 py-16">
  <div class="flex justify-center gap-2 mb-10">
    <?php for ($i = 1; $i <= 4; $i++): ?>
      <div class="h-1.5 w-16 rounded-full <?= $i <= $step ? 'bg-emerald-400' : 'bg-white/10' ?>"></div>
    <?php endfor; ?>
  </div>

  <?php if ($step === 1): ?>
    <div class="glass rounded-2xl p-10 text-center">
      <div class="text-5xl mb-4">🎉</div>
      <h1 class="text-3xl font-bold mb-3">Welcome to Utiligo Pro!</h1>
      <p class="text-slate-400 mb-8">Let's get you set up in under 2 minutes.</p>
      <a href="/portal/onboarding.php?step=2" class="inline-block bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-8 py-3 rounded-full font-semibold">Get Started &rarr;</a>
    </div>
    <script>setTimeout(function(){ window.location.href = '/portal/onboarding.php?step=2'; }, 3000);</script>

  <?php elseif ($step === 2): ?>
    <div class="glass rounded-2xl p-10">
      <h1 class="text-2xl font-bold mb-2">Set Up Your Brand</h1>
      <p class="text-slate-400 text-sm mb-8">Clients will see your logo and colors — not ours.</p>
      <form method="POST" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div>
          <label class="block text-sm mb-2">Brand Name</label>
          <input type="text" name="brand_name" value="<?= htmlspecialchars($wl['brand_name']) ?>" class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        </div>
        <div>
          <label class="block text-sm mb-2">Primary Color</label>
          <input type="color" name="primary_color" value="<?= htmlspecialchars($wl['primary_color']) ?>" class="w-16 h-10 rounded-lg">
        </div>
        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">Continue &rarr;</button>
      </form>
    </div>

  <?php elseif ($step === 3): ?>
    <div class="glass rounded-2xl p-10 text-center">
      <h1 class="text-2xl font-bold mb-2">Find Your First Leads</h1>
      <p class="text-slate-400 text-sm mb-8">Search a city and industry to see businesses without a website.</p>
      <form action="/portal/leads.php" method="GET" class="space-y-4 text-left">
        <input type="hidden" name="autorun" value="1">
        <input type="text" name="city" placeholder="e.g. Calgary" required class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        <input type="text" name="industry" placeholder="e.g. Plumber" required class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">Search Now &rarr;</button>
      </form>
      <a href="/portal/onboarding.php?step=4" class="inline-block mt-4 text-sm text-slate-400 hover:text-white">Skip for now</a>
    </div>

  <?php else: ?>
    <div class="glass rounded-2xl p-10 text-center">
      <div class="text-5xl mb-4">🚀</div>
      <h1 class="text-2xl font-bold mb-3">You're All Set!</h1>
      <p class="text-slate-400 mb-8">Time to start finding clients and building websites.</p>
      <a href="/portal/index.php" class="inline-block bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-8 py-3 rounded-full font-semibold">Go to Dashboard</a>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
