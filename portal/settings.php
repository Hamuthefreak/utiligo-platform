<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);

$message = '';
$error   = '';
$tab     = $_GET['tab'] ?? 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        if (!$full_name)                                    { $error = 'Name cannot be empty.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please enter a valid email.'; }
        else {
            $userdb = get_user_db();
            $chk = $userdb->prepare('SELECT id FROM utiligo_users WHERE email = ? AND id != ?');
            $chk->execute([$email, $user['id']]);
            if ($chk->fetch()) {
                $error = 'That email is already used by another account.';
            } else {
                $userdb->prepare('UPDATE utiligo_users SET full_name = ?, email = ? WHERE id = ?')
                    ->execute([$full_name, $email, $user['id']]);
                $message = 'Profile updated successfully.';
                $user['full_name'] = $full_name;
                $user['email']     = $email;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pw   = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $userdb   = get_user_db();
        $row      = $userdb->prepare('SELECT password_hash FROM utiligo_users WHERE id = ?');
        $row->execute([$user['id']]);
        $hash = $row->fetchColumn();
        if (!password_verify($current, $hash ?? '')) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new_pw) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new_pw !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $userdb->prepare('UPDATE utiligo_users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new_pw, PASSWORD_BCRYPT), $user['id']]);
            $message = 'Password changed successfully.';
        }
        $tab = 'password';
    } elseif (($_POST['action'] ?? '') === 'delete_account') {
        $confirm_word = trim($_POST['confirm_word'] ?? '');
        if (strtoupper($confirm_word) !== 'DELETE') {
            $error = 'Type DELETE (all caps) to confirm account deletion.';
            $tab   = 'danger';
        } else {
            $userdb = get_user_db();
            try {
                $userdb->prepare("UPDATE utiligo_users SET plan='free', subscription_status='cancelled', email=CONCAT('deleted_',id,'_',email), full_name='Deleted Account' WHERE id=?")
                    ->execute([$user['id']]);
            } catch (\Throwable $e) {}
            session_destroy();
            header('Location: /?deleted=1'); exit;
        }
    }
}

// Avatar initials helper
$initials = strtoupper(implode('', array_map(fn($p)=>substr($p,0,1), explode(' ', trim($user['full_name'] ?? 'U')))));
$initials = substr($initials, 0, 2);

// Joined date
$joined = !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : 'Unknown';

$pageTitle = 'Settings — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<!-- Page header -->
<div class="flex items-center gap-5 mb-8">
  <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-white/20 to-white/5 border border-white/10 flex items-center justify-center shrink-0">
    <span class="text-xl font-extrabold text-white tracking-tight"><?= htmlspecialchars($initials) ?></span>
  </div>
  <div>
    <h1 class="text-3xl font-bold tracking-tight"><?= htmlspecialchars($user['full_name'] ?? 'Your Account') ?></h1>
    <div class="flex items-center gap-3 mt-1 flex-wrap">
      <span class="text-slate-400 text-sm"><?= htmlspecialchars($user['email'] ?? '') ?></span>
      <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2.5 py-1 rounded-full
        <?= $plan==='entrepreneur'?'bg-purple-500/15 text-purple-300 border border-purple-500/20':
           ($plan==='pro'?'bg-amber-500/15 text-amber-300 border border-amber-500/20':
           'bg-white/8 text-slate-400 border border-white/10') ?>">
        <i class="fa-solid fa-<?= $plan==='entrepreneur'?'rocket':($plan==='pro'?'crown':'user') ?> mr-1"></i>
        <?= plan_label($plan) ?>
      </span>
      <span class="text-[11px] text-slate-600">Joined <?= $joined ?></span>
    </div>
  </div>
</div>

<?php if ($message): ?>
<div class="flex items-center gap-3 bg-white/5 border border-white/10 text-white rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check text-green-400 shrink-0"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Tab bar -->
<div class="flex gap-1 mb-7 border-b border-white/5 pb-0 overflow-x-auto">
  <?php
  $tabs = [
    'profile'  => ['icon'=>'user',            'label'=>'Profile'],
    'password' => ['icon'=>'lock',             'label'=>'Password'],
    'security' => ['icon'=>'shield-halved',    'label'=>'Security'],
    'notifications' => ['icon'=>'bell',        'label'=>'Notifications'],
    'danger'   => ['icon'=>'triangle-exclamation','label'=>'Danger Zone'],
  ];
  foreach ($tabs as $t => $meta):
  ?>
  <a href="?tab=<?= $t ?>"
     class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 transition -mb-px whitespace-nowrap
            <?= $tab===$t ? 'border-white text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
    <i class="fa-solid fa-<?= $meta['icon'] ?> text-xs"></i><?= $meta['label'] ?>
  </a>
  <?php endforeach; ?>
</div>


<?php if ($tab === 'profile'): ?>
<!-- ═══ PROFILE TAB ═══ -->
<div class="space-y-5 max-w-lg">

  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Personal Info</p>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_profile">
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Full Name</label>
        <input type="text" name="full_name" required
               value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
      </div>
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Email Address</label>
        <input type="email" name="email" required
               value="<?= htmlspecialchars($user['email'] ?? '') ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
        <p class="text-[11px] text-slate-500 mt-1.5"><i class="fa-solid fa-circle-info mr-1"></i>Changing your email will require you to log in again on other devices.</p>
      </div>
      <div class="pt-2">
        <button type="submit" class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">
          Save Changes
        </button>
      </div>
    </form>
  </div>

  <!-- Plan card -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Current Plan</p>
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center">
          <i class="fa-solid fa-<?= $plan==='entrepreneur'?'rocket':($plan==='pro'?'crown':'user') ?> text-white text-sm"></i>
        </div>
        <div>
          <p class="font-bold text-sm"><?= plan_label($plan) ?> Plan</p>
          <p class="text-xs text-slate-500">
            <?php
              if ($plan==='entrepreneur') echo 'Unlimited leads &bull; 500 active sites';
              elseif ($plan==='pro')     echo '120 leads &bull; 200 active sites';
              else                        echo '3 leads &bull; 1 site/day';
            ?>
          </p>
        </div>
      </div>
      <a href="/portal/billing.php" class="text-xs bg-white/10 hover:bg-white/15 text-white px-4 py-2 rounded-xl font-semibold transition">Manage</a>
    </div>
  </div>
</div>


<?php elseif ($tab === 'password'): ?>
<!-- ═══ PASSWORD TAB ═══ -->
<div class="glass rounded-2xl p-6 border border-white/5 max-w-lg">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Change Password</p>
  <form method="POST" class="space-y-4" id="pwForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="change_password">

    <?php
    $pwFields = [
      ['current_password',  'Current Password',      'current-password'],
      ['new_password',      'New Password',           'new-password'],
      ['confirm_password',  'Confirm New Password',  'new-password'],
    ];
    foreach ($pwFields as [$name, $label, $autocomplete]):
    ?>
    <div>
      <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2"><?= $label ?></label>
      <div class="relative">
        <input type="password" name="<?= $name ?>" required
               <?= $name==='new_password'?'minlength="8"':'' ?>
               autocomplete="<?= $autocomplete ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 pr-11 focus:outline-none focus:border-white/40 transition"
               id="pw_<?= $name ?>">
        <button type="button" onclick="togglePw('pw_<?= $name ?>', this)"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition">
          <i class="fa-solid fa-eye text-sm"></i>
        </button>
      </div>
      <?php if ($name==='new_password'): ?>
        <p class="text-xs text-slate-500 mt-1">Minimum 8 characters</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Strength meter -->
    <div id="pwStrengthWrap" class="hidden">
      <div class="flex gap-1 mb-1">
        <div class="h-1.5 flex-1 rounded-full bg-white/5" id="pwS1"></div>
        <div class="h-1.5 flex-1 rounded-full bg-white/5" id="pwS2"></div>
        <div class="h-1.5 flex-1 rounded-full bg-white/5" id="pwS3"></div>
        <div class="h-1.5 flex-1 rounded-full bg-white/5" id="pwS4"></div>
      </div>
      <p class="text-[11px] text-slate-500" id="pwStrengthLabel"></p>
    </div>

    <div class="pt-2">
      <button type="submit" class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">
        Update Password
      </button>
    </div>
  </form>
</div>
<script>
function togglePw(id, btn) {
  const inp = document.getElementById(id);
  if (!inp) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.innerHTML = show ? '<i class="fa-solid fa-eye-slash text-sm"></i>' : '<i class="fa-solid fa-eye text-sm"></i>';
}
// Password strength meter
(function(){
  const inp = document.getElementById('pw_new_password');
  const wrap = document.getElementById('pwStrengthWrap');
  const bars = ['pwS1','pwS2','pwS3','pwS4'].map(id=>document.getElementById(id));
  const lbl  = document.getElementById('pwStrengthLabel');
  if (!inp) return;
  inp.addEventListener('input', ()=>{
    const v = inp.value;
    wrap.classList.toggle('hidden', v.length === 0);
    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v) && /[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const colors = ['bg-red-500','bg-amber-500','bg-yellow-400','bg-green-500'];
    const labels = ['Weak','Fair','Good','Strong'];
    bars.forEach((b,i) => {
      b.className = 'h-1.5 flex-1 rounded-full ' + (i < score ? colors[score-1] : 'bg-white/5');
    });
    lbl.textContent = labels[score-1] || '';
  });
})();
</script>


<?php elseif ($tab === 'security'): ?>
<!-- ═══ SECURITY / 2FA TAB ═══ -->
<div class="space-y-5 max-w-lg">

  <!-- 2FA setup card -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-start justify-between mb-5">
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-1">Two-Factor Authentication</p>
        <p class="text-sm text-slate-300">Add an extra layer of security to your account using an authenticator app.</p>
      </div>
      <span id="twofa_badge"
            class="inline-flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-1 rounded-full
                   bg-slate-700/60 text-slate-400 border border-white/8 shrink-0 ml-4">
        <i class="fa-solid fa-circle-xmark"></i> Not Enabled
      </span>
    </div>

    <!-- Step 1: Install app note -->
    <div class="bg-white/3 border border-white/8 rounded-xl px-4 py-3 mb-4">
      <p class="text-xs font-semibold text-slate-400 mb-1"><i class="fa-solid fa-circle-info mr-1.5"></i>Before you begin</p>
      <p class="text-xs text-slate-500">Install an authenticator app such as <strong class="text-slate-300">Google Authenticator</strong>, <strong class="text-slate-300">Authy</strong>, or <strong class="text-slate-300">1Password</strong> on your phone.</p>
    </div>

    <!-- Step 2: QR code -->
    <div id="tfaSetupArea">
      <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">Step 1 &mdash; Scan QR Code</p>
      <div class="flex items-start gap-5 mb-4">
        <!-- QR placeholder (real implementation: use a TOTP library to generate) -->
        <div class="w-28 h-28 bg-white rounded-xl flex items-center justify-center shrink-0">
          <div class="text-[10px] text-slate-700 text-center px-2 leading-snug">
            <i class="fa-solid fa-qrcode text-3xl text-slate-800 mb-1 block"></i>
            QR Code<br>appears here
          </div>
        </div>
        <div class="flex-1">
          <p class="text-xs text-slate-400 mb-2">Can&rsquo;t scan? Enter this secret manually:</p>
          <div class="flex items-center gap-2 bg-slate-800/80 border border-slate-600 rounded-xl px-4 py-2.5">
            <code class="text-xs text-white tracking-widest font-mono flex-1" id="totpSecret">XXXX XXXX XXXX XXXX</code>
            <button type="button" onclick="copySecret()