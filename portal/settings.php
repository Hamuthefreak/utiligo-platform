<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

// All users can VIEW settings; only Pro can save white-label branding
require_login();
$user   = current_user();
$is_pro = ($user['plan'] ?? 'free') === 'pro';
$pdo    = get_platform_db();

$wl = ['brand_name'=>'Utiligo','logo_path'=>null,'primary_color'=>'#10B981','secondary_color'=>'#1E293B','show_powered_by'=>1];
if ($is_pro) {
    $stmt = $pdo->prepare('SELECT * FROM utiligo_whitelabel WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $wl = $stmt->fetch() ?: $wl;
}

$saved = false; $twoFaSaved = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_2fa'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) die('Invalid CSRF token.');
    $enable2fa = isset($_POST['two_factor_enabled']) ? 1 : 0;
    $userdb    = get_user_db();
    try {
        $userdb->prepare('UPDATE utiligo_users SET two_factor_enabled=? WHERE id=?')->execute([$enable2fa,$user['id']]);
    } catch (\PDOException $e) {} // column may not exist yet
    $user['two_factor_enabled'] = $enable2fa;
    $twoFaSaved = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_2fa'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) die('Invalid CSRF token.');
    if (!$is_pro) {
        $error = 'White-label branding requires a Pro plan.';
    } else {
        $brandName      = sanitize($_POST['brand_name'] ?? 'Utiligo');
        $primaryColor   = sanitize($_POST['primary_color'] ?? '#10B981');
        $secondaryColor = sanitize($_POST['secondary_color'] ?? '#1E293B');
        $showPoweredBy  = isset($_POST['show_powered_by']) ? 1 : 0;
        $logoPath       = $wl['logo_path'];
        if (!empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            if ($file['size'] > MAX_LOGO_UPLOAD_BYTES)             { $error = 'Logo too large (max 2MB).'; }
            elseif (!in_array($file['type'], ALLOWED_LOGO_TYPES, true)) { $error = 'Invalid file type.'; }
            else {
                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . $user['id'] . '_' . time() . '.' . $ext;
                $dest     = __DIR__ . '/../assets/uploads/logos/' . $filename;
                if (move_uploaded_file($file['tmp_name'], $dest)) $logoPath = '/assets/uploads/logos/' . $filename;
            }
        }
        if (!$error) {
            $exists = $pdo->prepare('SELECT id FROM utiligo_whitelabel WHERE user_id=?');
            $exists->execute([$user['id']]);
            if ($exists->fetch()) {
                $pdo->prepare('UPDATE utiligo_whitelabel SET brand_name=?,logo_path=?,primary_color=?,secondary_color=?,show_powered_by=? WHERE user_id=?')
                    ->execute([$brandName,$logoPath,$primaryColor,$secondaryColor,$showPoweredBy,$user['id']]);
            } else {
                $pdo->prepare('INSERT INTO utiligo_whitelabel (user_id,brand_name,logo_path,primary_color,secondary_color,show_powered_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$user['id'],$brandName,$logoPath,$primaryColor,$secondaryColor,$showPoweredBy]);
            }
            $stmt = $pdo->prepare('SELECT * FROM utiligo_whitelabel WHERE user_id=? LIMIT 1');
            $stmt->execute([$user['id']]); $wl = $stmt->fetch(); $saved = true;
        }
    }
}

$pageTitle = 'White-Label — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">White-Label Settings</h1>
  <p class="text-slate-400 text-sm mt-1">Your clients see your brand, not ours.</p>
</div>

<?php if ($saved): ?>
<div class="flex items-center gap-3 bg-emerald-500/10 border border-emerald-400/20 text-emerald-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check shrink-0"></i> Settings saved successfully!
</div>
<?php endif; ?>
<?php if ($twoFaSaved): ?>
<div class="flex items-center gap-3 bg-emerald-500/10 border border-emerald-400/20 text-emerald-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check shrink-0"></i> Two-factor authentication settings updated.
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (!$is_pro): ?>
<!-- Free user upgrade prompt -->
<div class="glass rounded-2xl p-8 border border-amber-500/20 mb-6 text-center" style="background:linear-gradient(135deg,#1c1400,#0d1520)">
  <div class="w-14 h-14 rounded-full bg-amber-500/15 flex items-center justify-center mx-auto mb-4">
    <i class="fa-solid fa-crown text-amber-400 text-2xl"></i>
  </div>
  <h2 class="text-xl font-bold mb-2">White-Label is a Pro Feature</h2>
  <p class="text-slate-400 text-sm mb-6 max-w-md mx-auto">
    Remove &ldquo;Powered by Utiligo&rdquo;, add your own logo and brand colours,
    and deliver a fully branded experience to your clients.
  </p>
  <a href="/portal/billing.php?upgrade=1"
     class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-400 text-slate-950 px-8 py-3 rounded-xl font-bold transition">
    <i class="fa-solid fa-crown"></i> Upgrade to Pro
  </a>
</div>
<?php endif; ?>

<div class="grid md:grid-cols-2 gap-6 mb-6 <?= !$is_pro ? 'opacity-40 pointer-events-none select-none' : '' ?>">
  <!-- Branding form -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Branding</p>
    <form method="POST" enctype="multipart/form-data" class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div>
        <label class="block text-sm font-medium mb-2">Brand Name</label>
        <input type="text" id="brandNameField" name="brand_name" value="<?= htmlspecialchars($wl['brand_name']) ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 focus:outline-none focus:border-emerald-500 transition">
      </div>
      <div>
        <label class="block text-sm font-medium mb-2">Logo</label>
        <div id="logoDropzone" class="dropzone p-6 text-center cursor-pointer rounded-xl border border-dashed border-slate-600 hover:border-emerald-500 transition">
          <i class="fa-solid fa-cloud-arrow-up text-2xl text-slate-400 mb-2"></i>
          <p class="text-sm text-slate-400">Click or drag logo here (PNG, JPG, SVG &mdash; max 2MB)</p>
          <input type="file" id="logoFileInput" name="logo" accept="image/png,image/jpeg,image/svg+xml" class="hidden">
        </div>
        <?php if (!empty($wl['logo_path'])): ?>
          <img src="<?= htmlspecialchars($wl['logo_path']) ?>" class="h-10 mt-3 rounded" alt="Current logo">
        <?php endif; ?>
      </div>
      <div class="flex gap-4">
        <div>
          <label class="block text-sm font-medium mb-2">Primary</label>
          <input type="color" id="primaryColorField" name="primary_color" value="<?= htmlspecialchars($wl['primary_color']) ?>" class="w-16 h-10 rounded-lg cursor-pointer">
        </div>
        <div>
          <label class="block text-sm font-medium mb-2">Secondary</label>
          <input type="color" id="secondaryColorField" name="secondary_color" value="<?= htmlspecialchars($wl['secondary_color']) ?>" class="w-16 h-10 rounded-lg cursor-pointer">
        </div>
      </div>
      <div class="flex items-center justify-between p-4 bg-white/3 rounded-xl">
        <div>
          <p class="text-sm font-medium">&ldquo;Powered by Utiligo&rdquo; badge</p>
          <p class="text-xs text-slate-500 mt-0.5">We&rsquo;d love it if you kept this on!</p>
        </div>
        <input type="checkbox" name="show_powered_by" <?= $wl['show_powered_by'] ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 cursor-pointer">
      </div>
      <div class="flex items-center justify-between p-4 bg-white/3 rounded-xl opacity-50">
        <div>
          <p class="text-sm font-medium">Custom subdomain</p>
          <p class="text-xs text-slate-500">yourname.utiligo.ca</p>
        </div>
        <span class="text-xs bg-white/10 px-2 py-1 rounded-full">Coming Soon</span>
      </div>
      <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-slate-950 py-3 rounded-xl font-bold transition-all">
        Save Changes
      </button>
    </form>
  </div>

  <!-- Live preview -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Live Preview</p>
    <div id="livePreview" class="rounded-xl p-6 border border-white/10" style="background:<?= htmlspecialchars($wl['secondary_color']) ?>">
      <div id="previewBrandName" class="flex items-center gap-2 font-bold mb-4 text-white">
        <i class="fa-solid fa-bolt"></i> <?= htmlspecialchars($wl['brand_name']) ?>
      </div>
      <div id="previewBtn" class="inline-block px-5 py-2 rounded-full text-slate-950 font-semibold mb-4" style="background:<?= htmlspecialchars($wl['primary_color']) ?>">Sample Button</div>
      <p class="text-xs text-slate-400" id="previewPoweredBy"><?= $wl['show_powered_by'] ? 'Powered by Utiligo' : '' ?></p>
    </div>
    <p class="text-xs text-slate-600 mt-3">This is how your brand appears on generated client sites.</p>
  </div>
</div>

<!-- Security (available to all users) -->
<div class="glass rounded-2xl p-6 border border-white/5">
  <div class="flex items-center gap-2 mb-5">
    <div class="w-8 h-8 rounded-xl bg-violet-500/15 flex items-center justify-center">
      <i class="fa-solid fa-shield-halved text-violet-400 text-sm"></i>
    </div>
    <div>
      <p class="font-semibold text-sm">Account Security</p>
      <p class="text-xs text-slate-500">Protect your account with two-factor authentication</p>
    </div>
  </div>
  <form method="POST" class="flex items-center justify-between p-4 bg-white/3 rounded-xl">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="toggle_2fa" value="1">
    <div>
      <p class="text-sm font-medium">Email two-factor authentication</p>
      <p class="text-xs text-slate-500 mt-0.5">We&rsquo;ll email you a 6-digit code at every login.</p>
    </div>
    <label class="inline-flex items-center cursor-pointer">
      <input type="checkbox" name="two_factor_enabled" onchange="this.form.submit()" <?= !empty($user['two_factor_enabled']) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 cursor-pointer">
    </label>
  </form>
</div>

</div></main>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const brandField     = document.getElementById('brandNameField');
  const primaryField   = document.getElementById('primaryColorField');
  const secondaryField = document.getElementById('secondaryColorField');
  const previewBrand   = document.getElementById('previewBrandName');
  const previewBtn     = document.getElementById('previewBtn');
  const livePreview    = document.getElementById('livePreview');
  if (brandField)     brandField.addEventListener('input',     function () { previewBrand.innerHTML = '<i class="fa-solid fa-bolt"></i> ' + (this.value || 'Utiligo'); });
  if (primaryField)   primaryField.addEventListener('input',   function () { previewBtn.style.background = this.value; });
  if (secondaryField) secondaryField.addEventListener('input', function () { livePreview.style.background = this.value; });
  const dz = document.getElementById('logoDropzone');
  const fi = document.getElementById('logoFileInput');
  if (dz && fi) {
    dz.addEventListener('click', function () { fi.click(); });
    ['dragover','dragleave','drop'].forEach(function (evt) {
      dz.addEventListener(evt, function (e) { e.preventDefault(); dz.classList.toggle('dragover', evt === 'dragover'); });
    });
    dz.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) fi.files = e.dataTransfer.files; });
  }
});
</script>
</body></html>
