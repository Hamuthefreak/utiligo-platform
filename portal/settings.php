<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_pro();
$user = current_user();
$pdo = get_platform_db();

$stmt = $pdo->prepare('SELECT * FROM utiligo_whitelabel WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$wl = $stmt->fetch() ?: [
    'brand_name' => 'Utiligo', 'logo_path' => null, 'primary_color' => '#10B981',
    'secondary_color' => '#1E293B', 'show_powered_by' => 1,
];

$saved = false;
$twoFaSaved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_2fa'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) die('Invalid CSRF token.');
    $enable2fa = isset($_POST['two_factor_enabled']) ? 1 : 0;
    $userdb = get_user_db();
    $userdb->prepare('UPDATE utiligo_users SET two_factor_enabled = ? WHERE id = ?')->execute([$enable2fa, $user['id']]);
    $user['two_factor_enabled'] = $enable2fa;
    $twoFaSaved = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_2fa'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) die('Invalid CSRF token.');

    $brandName = sanitize($_POST['brand_name'] ?? 'Utiligo');
    $primaryColor = sanitize($_POST['primary_color'] ?? '#10B981');
    $secondaryColor = sanitize($_POST['secondary_color'] ?? '#1E293B');
    $showPoweredBy = isset($_POST['show_powered_by']) ? 1 : 0;
    $logoPath = $wl['logo_path'];

    if (!empty($_FILES['logo']['name'])) {
        $file = $_FILES['logo'];
        if ($file['size'] > MAX_LOGO_UPLOAD_BYTES) {
            $error = 'Logo file too large (max 2MB).';
        } elseif (!in_array($file['type'], ALLOWED_LOGO_TYPES, true)) {
            $error = 'Invalid file type. Use PNG, JPG, or SVG.';
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . $user['id'] . '_' . time() . '.' . $ext;
            $dest = __DIR__ . '/../assets/uploads/logos/' . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $logoPath = '/assets/uploads/logos/' . $filename;
            }
        }
    }

    $exists = $pdo->prepare('SELECT id FROM utiligo_whitelabel WHERE user_id = ?');
    $exists->execute([$user['id']]);
    if ($exists->fetch()) {
        $pdo->prepare('UPDATE utiligo_whitelabel SET brand_name=?, logo_path=?, primary_color=?, secondary_color=?, show_powered_by=? WHERE user_id=?')
            ->execute([$brandName, $logoPath, $primaryColor, $secondaryColor, $showPoweredBy, $user['id']]);
    } else {
        $pdo->prepare('INSERT INTO utiligo_whitelabel (user_id, brand_name, logo_path, primary_color, secondary_color, show_powered_by) VALUES (?,?,?,?,?,?)')
            ->execute([$user['id'], $brandName, $logoPath, $primaryColor, $secondaryColor, $showPoweredBy]);
    }

    $stmt = $pdo->prepare('SELECT * FROM utiligo_whitelabel WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $wl = $stmt->fetch();
    $saved = true;
}

$pageTitle = 'Settings — Utiligo Portal';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="max-w-5xl mx-auto px-6 py-10">
  <a href="/portal/index.php" class="inline-flex items-center gap-2 text-xs text-slate-400 hover:text-white mb-6">
    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
  </a>
  <h1 class="text-2xl font-bold mb-2">White-Label Settings</h1>
  <p class="text-slate-400 text-sm mb-8">Clients see your brand, not ours.</p>

  <?php if ($saved): ?>
    <div class="bg-emerald-500/10 border border-emerald-400/30 text-emerald-400 rounded-lg px-4 py-3 mb-6 text-sm">Settings saved successfully!</div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-8">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="glass rounded-xl p-6 space-y-5">
      <div>
        <label class="block text-sm mb-2">Brand Name</label>
        <input type="text" id="brandNameField" name="brand_name" value="<?= htmlspecialchars($wl['brand_name']) ?>" class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5">
      </div>
      <div>
        <label class="block text-sm mb-2">Logo</label>
        <div id="logoDropzone" class="dropzone p-6 text-center cursor-pointer">
          <i class="fa-solid fa-cloud-arrow-up text-2xl text-slate-400 mb-2"></i>
          <p class="text-sm text-slate-400">Click or drag a logo here (PNG, JPG, SVG — max 2MB)</p>
          <input type="file" id="logoFileInput" name="logo" accept="image/png,image/jpeg,image/svg+xml" class="hidden">
        </div>
        <?php if (!empty($wl['logo_path'])): ?>
          <img src="<?= htmlspecialchars($wl['logo_path']) ?>" class="h-10 mt-3" alt="Current logo">
        <?php endif; ?>
      </div>
      <div class="flex gap-4">
        <div>
          <label class="block text-sm mb-2">Primary Color</label>
          <input type="color" id="primaryColorField" name="primary_color" value="<?= htmlspecialchars($wl['primary_color']) ?>" class="w-16 h-10 rounded-lg">
        </div>
        <div>
          <label class="block text-sm mb-2">Secondary Color</label>
          <input type="color" id="secondaryColorField" name="secondary_color" value="<?= htmlspecialchars($wl['secondary_color']) ?>" class="w-16 h-10 rounded-lg">
        </div>
      </div>
      <div class="flex items-center justify-between">
        <label class="text-sm">"Powered by Utiligo" badge</label>
        <input type="checkbox" name="show_powered_by" <?= $wl['show_powered_by'] ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500">
      </div>
      <p class="text-xs text-slate-500">We'd love it if you kept this on — it helps us grow! But we respect your choice.</p>
      <div class="flex items-center justify-between opacity-50">
        <label class="text-sm">Custom subdomain</label>
        <span class="text-xs bg-white/10 px-2 py-1 rounded-full">Coming Soon</span>
      </div>
      <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">Save Changes</button>
    </div>

    <div>
      <p class="text-sm text-slate-400 mb-2">Live preview</p>
      <div id="livePreview" class="rounded-xl border border-white/10 p-6" style="background:<?= htmlspecialchars($wl['secondary_color']) ?>;">
        <div id="previewBrandName" class="flex items-center gap-2 font-bold mb-4"><i class="fa-solid fa-bolt"></i> <?= htmlspecialchars($wl['brand_name']) ?></div>
        <div id="previewBtn" class="inline-block px-5 py-2 rounded-full text-slate-950 font-semibold mb-4" style="background:<?= htmlspecialchars($wl['primary_color']) ?>;">Sample Button</div>
        <p class="text-xs text-slate-400" id="previewPoweredBy"><?= $wl['show_powered_by'] ? 'Powered by Utiligo' : '' ?></p>
      </div>
    </div>
  </form>

  <div class="glass rounded-xl p-8 mt-8 max-w-2xl">
    <h2 class="text-lg font-bold mb-4"><i class="fa-solid fa-shield-halved mr-2 text-emerald-400"></i>Account Security</h2>
    <?php if ($twoFaSaved): ?>
      <div class="bg-emerald-500/10 border border-emerald-400/30 text-emerald-400 rounded-lg px-4 py-3 mb-4 text-sm">Two-factor authentication settings updated.</div>
    <?php endif; ?>
    <form method="POST" class="flex items-center justify-between">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="toggle_2fa" value="1">
      <div>
        <p class="text-sm font-medium">Two-factor authentication (email code)</p>
        <p class="text-xs text-slate-500">We'll email you a 6-digit code at every login.</p>
      </div>
      <label class="inline-flex items-center cursor-pointer">
        <input type="checkbox" name="two_factor_enabled" onchange="this.form.submit()" <?= !empty($user['two_factor_enabled']) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500">
      </label>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const brandField = document.getElementById('brandNameField');
  const primaryField = document.getElementById('primaryColorField');
  const secondaryField = document.getElementById('secondaryColorField');
  const previewBrand = document.getElementById('previewBrandName');
  const previewBtn = document.getElementById('previewBtn');
  const livePreview = document.getElementById('livePreview');

  brandField.addEventListener('input', function () {
    previewBrand.innerHTML = '<i class="fa-solid fa-bolt"></i> ' + (this.value || 'Utiligo');
  });
  primaryField.addEventListener('input', function () { previewBtn.style.background = this.value; });
  secondaryField.addEventListener('input', function () { livePreview.style.background = this.value; });

  const dz = document.getElementById('logoDropzone');
  const fileInput = document.getElementById('logoFileInput');
  dz.addEventListener('click', function () { fileInput.click(); });
  ['dragover','dragleave','drop'].forEach(function (evt) {
    dz.addEventListener(evt, function (e) {
      e.preventDefault();
      dz.classList.toggle('dragover', evt === 'dragover');
    });
  });
  dz.addEventListener('drop', function (e) {
    if (e.dataTransfer.files.length) fileInput.files = e.dataTransfer.files;
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
