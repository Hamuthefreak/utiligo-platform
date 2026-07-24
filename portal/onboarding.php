<?php
/**
 * portal/onboarding.php — Fluid animated 4-step onboarding.
 * Steps: Welcome → Brand Setup → First Lead Search → Done
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();
$step = max(1, min(4, (int)($_GET['step'] ?? 1)));

$pdo  = get_platform_db();
$stmt = $pdo->prepare('SELECT * FROM utiligo_whitelabel WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$wl = $stmt->fetch() ?: ['brand_name' => 'Utiligo', 'primary_color' => '#10B981', 'secondary_color' => '#1E293B'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) die('Invalid CSRF token.');
    $brandName     = sanitize($_POST['brand_name'] ?? 'Utiligo');
    $primaryColor  = sanitize($_POST['primary_color'] ?? '#10B981');
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

$firstName = htmlspecialchars(explode(' ', trim($user['full_name']))[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Welcome to Utiligo</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">
<style>
  *,::before,::after{box-sizing:border-box;}
  html,body{margin:0;padding:0;height:100%;}
  body{
    font-family:'Inter',sans-serif;
    background:#080D18;
    color:#E2E8F0;
    overflow-x:hidden;
    min-height:100vh;
  }

  /* ── Animated background ── */
  .bg-anim{
    position:fixed;inset:0;z-index:0;
    background:radial-gradient(ellipse 80% 60% at 50% 0%, rgba(16,185,129,.15) 0%, transparent 70%),
               radial-gradient(ellipse 60% 50% at 80% 80%, rgba(99,102,241,.1) 0%, transparent 60%),
               #080D18;
  }
  .bg-anim::after{
    content:'';position:absolute;inset:0;
    background:url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='1' cy='1' r='1' fill='rgba(255,255,255,.03)'/%3E%3C/svg%3E");
  }

  /* ── Card ── */
  .onb-card{
    position:relative;z-index:1;
    background:rgba(15,23,42,.85);
    border:1px solid rgba(255,255,255,.09);
    border-radius:24px;
    backdrop-filter:blur(20px);
    box-shadow:0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(16,185,129,.07) inset;
    max-width:520px;
    width:100%;
    padding:0;
    overflow:hidden;
  }

  /* ── Progress bar ── */
  .progress-rail{height:3px;background:rgba(255,255,255,.06);}
  .progress-fill{
    height:3px;
    background:linear-gradient(90deg,#059669,#10B981);
    transition:width .6s cubic-bezier(.4,0,.2,1);
    border-radius:0 3px 3px 0;
  }

  /* ── Step indicator dots ── */
  .step-dot{
    width:28px;height:28px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:.7rem;font-weight:700;
    transition:all .35s cubic-bezier(.4,0,.2,1);
  }
  .step-dot.done{background:#10B981;color:#0F172A;}
  .step-dot.active{background:rgba(16,185,129,.2);color:#10B981;border:2px solid #10B981;}
  .step-dot.todo{background:rgba(255,255,255,.06);color:#475569;}
  .step-connector{height:1px;flex:1;background:rgba(255,255,255,.08);transition:background .4s;}
  .step-connector.done{background:#10B981;}

  /* ── Input / form ── */
  .inp{
    background:rgba(30,41,59,.8);
    border:1px solid rgba(255,255,255,.1);
    border-radius:14px;
    padding:13px 16px;
    color:#E2E8F0;
    font-family:'Inter',sans-serif;
    font-size:.9rem;
    width:100%;
    transition:border-color .2s,box-shadow .2s;
  }
  .inp:focus{outline:none;border-color:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.15);}
  .inp::placeholder{color:#475569;}

  /* ── Buttons ── */
  .btn-primary{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    background:#10B981;
    color:#0A0F1E;
    font-weight:700;
    font-size:.9rem;
    padding:14px 32px;
    border-radius:999px;
    border:none;
    cursor:pointer;
    transition:background .2s,transform .15s,box-shadow .2s;
    box-shadow:0 4px 20px rgba(16,185,129,.35);
    text-decoration:none;
  }
  .btn-primary:hover{background:#0ea271;transform:translateY(-1px);box-shadow:0 8px 30px rgba(16,185,129,.45);}
  .btn-primary:active{transform:translateY(0);}
  .btn-ghost{
    display:inline-flex;align-items:center;justify-content:center;
    background:transparent;
    color:#64748B;
    font-size:.8rem;
    padding:8px 20px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.08);
    cursor:pointer;
    transition:color .2s,border-color .2s;
    text-decoration:none;
  }
  .btn-ghost:hover{color:#CBD5E1;border-color:rgba(255,255,255,.2);}

  /* ── Feature checklist ── */
  .feature-row{
    display:flex;align-items:center;gap:12px;
    padding:12px 0;
    border-bottom:1px solid rgba(255,255,255,.05);
    animation:fadeSlideIn .4s ease both;
  }
  .feature-row:last-child{border-bottom:none;}
  .feature-icon{width:36px;height:36px;border-radius:10px;background:rgba(16,185,129,.12);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}

  /* ── Confetti canvas ── */
  #confetti{position:fixed;inset:0;pointer-events:none;z-index:99;}

  /* ── Animations ── */
  @keyframes fadeSlideIn{
    from{opacity:0;transform:translateY(18px)}
    to  {opacity:1;transform:translateY(0)}
  }
  @keyframes fadeIn{
    from{opacity:0} to{opacity:1}
  }
  @keyframes pulse-ring{
    0%{transform:scale(1);opacity:.7}
    70%{transform:scale(1.3);opacity:0}
    100%{transform:scale(1.3);opacity:0}
  }
  .anim-in{animation:fadeSlideIn .55s cubic-bezier(.4,0,.2,1) both;}
  .anim-in-delay-1{animation-delay:.1s;}
  .anim-in-delay-2{animation-delay:.2s;}
  .anim-in-delay-3{animation-delay:.3s;}
  .anim-in-delay-4{animation-delay:.4s;}

  .pulse-ring::before{
    content:'';position:absolute;inset:-8px;border-radius:50%;
    border:2px solid rgba(16,185,129,.4);
    animation:pulse-ring 2s ease-out infinite;
  }

  /* Color preview swatch */
  .color-swatch{
    width:44px;height:44px;border-radius:12px;border:2px solid rgba(255,255,255,.1);
    cursor:pointer;transition:transform .15s;
    flex-shrink:0;
  }
  .color-swatch:hover{transform:scale(1.08);}
</style>
</head>
<body>

<div class="bg-anim"></div>
<canvas id="confetti"></canvas>

<!-- Centered layout -->
<div class="relative z-10 flex flex-col items-center justify-center min-h-screen px-4 py-12">

  <!-- Logo -->
  <a href="/" class="mb-8 text-2xl font-black tracking-tight text-white anim-in">
    utili<span class="text-emerald-400">go</span>
  </a>

  <div class="onb-card">

    <!-- Progress bar -->
    <div class="progress-rail">
      <div class="progress-fill" style="width:<?= (($step-1)/3)*100 ?>%"></div>
    </div>

    <!-- Step indicators -->
    <div class="flex items-center px-8 pt-6 pb-2">
      <?php
      $labels = ['Welcome','Brand','Leads','Done'];
      for ($i=1;$i<=4;$i++):
        $cls = $i < $step ? 'done' : ($i === $step ? 'active' : 'todo');
      ?>
      <div class="flex flex-col items-center gap-1">
        <div class="step-dot <?= $cls ?>">
          <?= $i < $step ? '&#10003;' : $i ?>
        </div>
        <span class="text-[10px] <?= $i===$step?'text-emerald-400':'text-slate-600' ?> font-medium"><?= $labels[$i-1] ?></span>
      </div>
      <?php if ($i < 4): ?>
        <div class="step-connector <?= $i < $step ? 'done' : '' ?> mx-2 mb-4"></div>
      <?php endif; ?>
      <?php endfor; ?>
    </div>

    <!-- Body -->
    <div class="px-8 pb-10 pt-4">

    <?php if ($step === 1): ?>
    <!-- ─────────────── STEP 1: Welcome ─────────────── -->
      <div class="text-center">
        <div class="relative inline-block mb-6 anim-in">
          <div class="pulse-ring relative w-20 h-20 mx-auto rounded-full bg-emerald-500/10 flex items-center justify-center text-4xl">
            🎉
          </div>
        </div>
        <h1 class="text-3xl font-black text-white mb-2 anim-in anim-in-delay-1">
          Welcome, <?= $firstName ?>!
        </h1>
        <p class="text-slate-400 text-sm mb-8 anim-in anim-in-delay-2">
          You're 2 minutes away from finding your first client.<br>Let's set things up.
        </p>
        <div class="space-y-3 text-left mb-8">
          <?php
          $features = [
            ['🔍','Find businesses with no website','across any city & industry'],
            ['⚡','Build stunning AI websites','in seconds, not hours'],
            ['🏠','White-label everything','your brand, your clients'],
          ];
          foreach ($features as $i => [$icon,$title,$sub]):
          ?>
          <div class="feature-row anim-in" style="animation-delay:<?= .3+$i*.1 ?>s">
            <div class="feature-icon"><?= $icon ?></div>
            <div>
              <div class="text-sm font-semibold text-white"><?= $title ?></div>
              <div class="text-xs text-slate-500"><?= $sub ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <a href="/portal/onboarding.php?step=2" class="btn-primary w-full anim-in anim-in-delay-4">
          Let's go &rarr;
        </a>
      </div>

    <?php elseif ($step === 2): ?>
    <!-- ─────────────── STEP 2: Brand Setup ─────────────── -->
      <h2 class="text-2xl font-black text-white mb-1 anim-in">🎨 Set Up Your Brand</h2>
      <p class="text-slate-400 text-sm mb-7 anim-in anim-in-delay-1">Clients see your brand — not ours.</p>
      <form method="POST" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="anim-in anim-in-delay-1">
          <label class="block text-sm font-semibold text-slate-300 mb-2">Brand Name</label>
          <input type="text" name="brand_name" class="inp" value="<?= htmlspecialchars($wl['brand_name']) ?>" placeholder="e.g. Apex Digital" required>
        </div>
        <div class="anim-in anim-in-delay-2">
          <label class="block text-sm font-semibold text-slate-300 mb-2">Brand Color</label>
          <div class="flex items-center gap-3">
            <input type="color" id="color_picker" name="primary_color" value="<?= htmlspecialchars($wl['primary_color']) ?>"
                   class="color-swatch" oninput="updateSwatch(this.value)">
            <div class="flex-1">
              <div class="text-sm text-white" id="color_hex"><?= htmlspecialchars($wl['primary_color']) ?></div>
              <div class="text-xs text-slate-500">Used on your client portal buttons &amp; accents</div>
            </div>
            <div id="swatch_preview" class="w-10 h-10 rounded-xl border border-white/10 transition-colors"
                 style="background:<?= htmlspecialchars($wl['primary_color']) ?>"></div>
          </div>
        </div>
        <button type="submit" class="btn-primary w-full mt-2 anim-in anim-in-delay-3">
          Save &amp; Continue &rarr;
        </button>
      </form>

    <?php elseif ($step === 3): ?>
    <!-- ─────────────── STEP 3: First Lead Search ─────────────── -->
      <h2 class="text-2xl font-black text-white mb-1 anim-in">🔍 Find Your First Lead</h2>
      <p class="text-slate-400 text-sm mb-7 anim-in anim-in-delay-1">Search any city &amp; industry to see businesses with no website.</p>
      <form action="/portal/leads.php" method="GET" class="space-y-4">
        <input type="hidden" name="autorun" value="1">
        <div class="anim-in anim-in-delay-1">
          <label class="block text-sm font-semibold text-slate-300 mb-2">City</label>
          <input type="text" name="city" class="inp" placeholder="e.g. Toronto" required>
        </div>
        <div class="anim-in anim-in-delay-2">
          <label class="block text-sm font-semibold text-slate-300 mb-2">Industry</label>
          <input type="text" name="industry" class="inp" placeholder="e.g. Plumber" required>
        </div>
        <!-- Suggestion chips -->
        <div class="flex flex-wrap gap-2 anim-in anim-in-delay-3">
          <?php foreach(['Plumber','Electrician','Roofer','Landscaper','Cleaner','Carpenter'] as $hint): ?>
          <button type="button" onclick="document.querySelector('[name=industry]').value='<?= $hint ?>'" 
                  class="text-xs bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white px-3 py-1.5 rounded-full border border-white/8 transition">
            <?= $hint ?>
          </button>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn-primary w-full anim-in anim-in-delay-4">
          🔍 Search Now
        </button>
      </form>
      <div class="text-center mt-4 anim-in anim-in-delay-4">
        <a href="/portal/onboarding.php?step=4" class="btn-ghost">Skip for now</a>
      </div>

    <?php else: ?>
    <!-- ─────────────── STEP 4: Done ─────────────── -->
      <div class="text-center">
        <div class="relative inline-block mb-5 anim-in">
          <div class="w-20 h-20 mx-auto rounded-full bg-emerald-500/10 flex items-center justify-center text-4xl">
            🚀
          </div>
        </div>
        <h1 class="text-3xl font-black text-white mb-2 anim-in anim-in-delay-1">You're all set!</h1>
        <p class="text-slate-400 text-sm mb-8 anim-in anim-in-delay-2">
          Your account is ready. Start finding leads and building websites right now.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center anim-in anim-in-delay-3">
          <a href="/portal/leads.php" class="btn-primary">🔍 Find Leads</a>
          <a href="/portal/index.php" class="btn-ghost">Dashboard &rarr;</a>
        </div>
      </div>
    <?php endif; ?>

    </div><!-- /body -->
  </div><!-- /card -->

  <!-- Step label below card -->
  <p class="mt-5 text-xs text-slate-600 anim-in">Step <?= $step ?> of 4</p>

</div>

<script>
// Color picker sync
function updateSwatch(val) {
  document.getElementById('color_hex').textContent = val;
  document.getElementById('swatch_preview').style.background = val;
}

// Confetti on step 1 + step 4
<?php if ($step === 1 || $step === 4): ?>
(function(){
  const canvas = document.getElementById('confetti');
  const ctx    = canvas.getContext('2d');
  canvas.width = window.innerWidth;
  canvas.height= window.innerHeight;
  const colors = ['#10B981','#34D399','#6EE7B7','#A7F3D0','#FFFFFF','#6366F1','#818CF8'];
  const pieces = Array.from({length:<?= $step===4?'120':'60' ?>}, () => ({
    x: Math.random()*canvas.width,
    y: Math.random()*-canvas.height,
    r: Math.random()*6+3,
    d: Math.random()*4+1,
    color: colors[Math.floor(Math.random()*colors.length)],
    tilt: Math.random()*10-5,
    tiltAngle: 0,
    tiltAngleInc: Math.random()*.07+.05,
  }));
  let frame;
  function draw() {
    ctx.clearRect(0,0,canvas.width,canvas.height);
    pieces.forEach(p => {
      ctx.beginPath();
      ctx.ellipse(p.x,p.y,p.r,p.r*1.4,p.tilt*Math.PI/20,0,2*Math.PI);
      ctx.fillStyle = p.color;
      ctx.globalAlpha = .85;
      ctx.fill();
    });
    pieces.forEach(p => {
      p.tiltAngle += p.tiltAngleInc;
      p.y += p.d;
      p.tilt = Math.sin(p.tiltAngle)*12;
      if (p.y > canvas.height) { p.y=-20; p.x=Math.random()*canvas.width; }
    });
    frame = requestAnimationFrame(draw);
  }
  draw();
  setTimeout(() => cancelAnimationFrame(frame), <?= $step===4?'5000':'2500' ?>);
  window.addEventListener('resize', () => { canvas.width=window.innerWidth; canvas.height=window.innerHeight; });
}());
<?php endif; ?>

// Auto-advance step 1 after 3 s
<?php if ($step === 1): ?>
setTimeout(() => { window.location.href = '/portal/onboarding.php?step=2'; }, 3200);
<?php endif; ?>
</script>

</body>
</html>
