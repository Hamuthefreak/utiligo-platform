<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';

require_login();
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);

$message = '';
$error   = '';
$tab     = $_GET['tab'] ?? 'profile';

// ── Check DB columns exist (graceful degradation) ──
$userdb = get_user_db();

$twofa_enabled = false;
$has_2fa_cols  = false;
try {
    $chk = $userdb->query("SHOW COLUMNS FROM utiligo_users LIKE 'two_factor_enabled'");
    if ($chk && $chk->rowCount() > 0) {
        $has_2fa_cols = true;
        $stmt = $userdb->prepare('SELECT two_factor_enabled FROM utiligo_users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $twofa_enabled = (bool)$stmt->fetchColumn();
    }
} catch (\Throwable $e) {}

$notif_prefs = ['email_sites' => 1, 'email_leads' => 1, 'email_billing' => 1, 'email_tips' => 0];
$has_notif_col = false;
try {
    $chkN = $userdb->query("SHOW COLUMNS FROM utiligo_users LIKE 'notif_prefs'");
    if ($chkN && $chkN->rowCount() > 0) {
        $has_notif_col = true;
        $stmt2 = $userdb->prepare('SELECT notif_prefs FROM utiligo_users WHERE id = ?');
        $stmt2->execute([$user['id']]);
        $raw = $stmt2->fetchColumn();
        if ($raw) $notif_prefs = array_merge($notif_prefs, json_decode($raw, true) ?: []);
    }
} catch (\Throwable $e) {}

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh and try again.';
    }

    // ─ Profile update ─
    elseif (($_POST['action'] ?? '') === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        if (!$full_name)                                    { $error = 'Name cannot be empty.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please enter a valid email.'; }
        else {
            $chk = $userdb->prepare('SELECT id FROM utiligo_users WHERE email = ? AND id != ?');
            $chk->execute([$email, $user['id']]);
            if ($chk->fetch()) {
                $error = 'That email is already in use by another account.';
            } else {
                $userdb->prepare('UPDATE utiligo_users SET full_name = ?, email = ? WHERE id = ?')
                    ->execute([$full_name, $email, $user['id']]);
                $message = 'Profile updated successfully.';
                $user['full_name'] = $full_name;
                $user['email']     = $email;
            }
        }
    }

    // ─ Password change ─
    elseif (($_POST['action'] ?? '') === 'change_password') {
        $tab     = 'password';
        $current = $_POST['current_password'] ?? '';
        $new_pw  = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $row     = $userdb->prepare('SELECT password_hash FROM utiligo_users WHERE id = ?');
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
    }

    // ─ Enable 2FA ─
    elseif (($_POST['action'] ?? '') === 'enable_2fa') {
        $tab    = 'security';
        $code   = preg_replace('/\s+/', '', $_POST['totp_code']   ?? '');
        $secret = strtoupper(trim($_POST['totp_secret'] ?? ''));
        if (!$has_2fa_cols) {
            $error = 'Run migrations/005_add_2fa_and_notif_prefs.sql first to enable 2FA.';
        } elseif (strlen($secret) < 16) {
            $error = 'Invalid secret. Please reload the page and try again.';
        } elseif (!totp_verify($secret, $code)) {
            $error = 'That code is incorrect or has expired. Please wait for a new code and try again.';
        } else {
            $userdb->prepare('UPDATE utiligo_users SET two_factor_secret = ?, two_factor_enabled = 1 WHERE id = ?')
                ->execute([$secret, $user['id']]);
            $twofa_enabled = true;
            // Store secret in session so verify-2fa.php can use it immediately
            $_SESSION['2fa_verified'] = true;
            $message = 'Two-factor authentication is now enabled on your account.';
        }
    }

    // ─ Disable 2FA ─
    elseif (($_POST['action'] ?? '') === 'disable_2fa') {
        $tab = 'security';
        $pw  = $_POST['confirm_password_2fa'] ?? '';
        $row = $userdb->prepare('SELECT password_hash FROM utiligo_users WHERE id = ?');
        $row->execute([$user['id']]);
        $hash = $row->fetchColumn();
        if (!password_verify($pw, $hash ?? '')) {
            $error = 'Incorrect password. 2FA was not disabled.';
        } elseif (!$has_2fa_cols) {
            $error = '2FA columns not found. Nothing to disable.';
        } else {
            $userdb->prepare('UPDATE utiligo_users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE id = ?')
                ->execute([$user['id']]);
            $twofa_enabled = false;
            $message = 'Two-factor authentication has been disabled.';
        }
    }

    // ─ Save notifications ─
    elseif (($_POST['action'] ?? '') === 'save_notifications') {
        $tab   = 'notifications';
        $prefs = [
            'email_sites'   => isset($_POST['email_sites'])   ? 1 : 0,
            'email_leads'   => isset($_POST['email_leads'])   ? 1 : 0,
            'email_billing' => isset($_POST['email_billing']) ? 1 : 0,
            'email_tips'    => isset($_POST['email_tips'])    ? 1 : 0,
        ];
        if ($has_notif_col) {
            $userdb->prepare('UPDATE utiligo_users SET notif_prefs = ? WHERE id = ?')
                ->execute([json_encode($prefs), $user['id']]);
            $notif_prefs = $prefs;
            $message = 'Notification preferences saved.';
        } else {
            $notif_prefs = $prefs;
            $message = 'Saved! (Tip: run migration 005 to persist these across sessions.)';
        }
    }

    // ─ Delete account ─
    elseif (($_POST['action'] ?? '') === 'delete_account') {
        $tab          = 'danger';
        $confirm_word = trim($_POST['confirm_word'] ?? '');
        if (strtoupper($confirm_word) !== 'DELETE') {
            $error = 'Type DELETE (all caps) to confirm account deletion.';
        } else {
            try {
                $userdb->prepare("UPDATE utiligo_users SET plan='free', subscription_status='cancelled', email=CONCAT('deleted_',id,'_',email), full_name='Deleted Account', two_factor_secret=NULL, two_factor_enabled=0 WHERE id=?")
                    ->execute([$user['id']]);
            } catch (\Throwable $e) {
                // Fallback without 2FA columns
                $userdb->prepare("UPDATE utiligo_users SET plan='free', subscription_status='cancelled', email=CONCAT('deleted_',id,'_',email), full_name='Deleted Account' WHERE id=?")
                    ->execute([$user['id']]);
            }
            session_destroy();
            header('Location: /?deleted=1'); exit;
        }
    }
}

// ── Generate a fresh TOTP secret server-side for the enable-2FA form ──
// Only needed when 2FA is not yet enabled
$totp_setup_secret = '';
$totp_setup_uri    = '';
if (!$twofa_enabled && $tab === 'security') {
    // Keep the same secret for the session so page refreshes don't break the QR
    if (empty($_SESSION['totp_pending_secret'])) {
        $_SESSION['totp_pending_secret'] = totp_generate_secret();
    }
    $totp_setup_secret = $_SESSION['totp_pending_secret'];
    $totp_setup_uri    = totp_uri($totp_setup_secret, $user['email'] ?? 'user@utiligo.com');
}
// Clear pending secret once 2FA is successfully enabled
if ($twofa_enabled) {
    unset($_SESSION['totp_pending_secret']);
}

$initials = strtoupper(implode('', array_map(fn($p)=>substr($p,0,1), explode(' ', trim($user['full_name'] ?? 'U')))));
$initials = substr($initials, 0, 2);
$joined   = !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : 'Unknown';

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
<div class="flex gap-1 mb-7 border-b border-white/5 overflow-x-auto">
  <?php
  $tabs = [
    'profile'       => ['icon'=>'user',                 'label'=>'Profile'],
    'password'      => ['icon'=>'lock',                 'label'=>'Password'],
    'security'      => ['icon'=>'shield-halved',        'label'=>'Security'],
    'notifications' => ['icon'=>'bell',                 'label'=>'Notifications'],
    'danger'        => ['icon'=>'triangle-exclamation', 'label'=>'Danger Zone'],
  ];
  foreach ($tabs as $t => $meta): ?>
  <a href="?tab=<?= $t ?>"
     class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 transition -mb-px whitespace-nowrap
            <?= $tab===$t ? 'border-white text-white' : 'border-transparent text-slate-500 hover:text-slate-300' ?>">
    <i class="fa-solid fa-<?= $meta['icon'] ?> text-xs"></i><?= $meta['label'] ?>
  </a>
  <?php endforeach; ?>
</div>


<?php if ($tab === 'profile'): ?>
<!-- ════════════════════════════════ PROFILE ════════════════════════════════ -->
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
        <button type="submit" class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">Save Changes</button>
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

  <!-- Quick links -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Quick Links</p>
    <div class="space-y-1">
      <?php
      $quickLinks = [
        ['/portal/billing.php',   'fa-credit-card',   'Billing & Plan',    'Manage subscription and invoices'],
        ['/portal/leads.php',     'fa-magnifying-glass','Find Leads',       'Search for local businesses'],
        ['/portal/my_sites.php',  'fa-folder-open',   'My Sites',          'View and manage generated sites'],
        ['?tab=security',         'fa-shield-halved', 'Security',          'Two-factor auth & session info'],
        ['?tab=notifications',    'fa-bell',          'Notifications',     'Email preference settings'],
      ];
      foreach ($quickLinks as [$href, $icon, $title, $desc]): ?>
      <a href="<?= $href ?>" class="flex items-center gap-3 p-3 rounded-xl hover:bg-white/5 transition group">
        <div class="w-8 h-8 rounded-lg bg-white/8 flex items-center justify-center shrink-0 group-hover:bg-white/12 transition">
          <i class="fa-solid fa-<?= $icon ?> text-slate-400 text-xs group-hover:text-white transition"></i>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-white"><?= $title ?></p>
          <p class="text-xs text-slate-500"><?= $desc ?></p>
        </div>
        <i class="fa-solid fa-chevron-right text-slate-600 text-xs group-hover:text-slate-400 transition"></i>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

</div>


<?php elseif ($tab === 'password'): ?>
<!-- ════════════════════════════════ PASSWORD ════════════════════════════════ -->
<div class="glass rounded-2xl p-6 border border-white/5 max-w-lg">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Change Password</p>
  <form method="POST" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="change_password">
    <?php
    $pwFields = [
      ['current_password', 'Current Password',     'current-password'],
      ['new_password',     'New Password',          'new-password'],
      ['confirm_password', 'Confirm New Password', 'new-password'],
    ];
    foreach ($pwFields as [$name, $label, $ac]): ?>
    <div>
      <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2"><?= $label ?></label>
      <div class="relative">
        <input type="password" name="<?= $name ?>" required id="pw_<?= $name ?>"
               <?= $name==='new_password'?'minlength="8"':'' ?> autocomplete="<?= $ac ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 pr-11 focus:outline-none focus:border-white/40 transition">
        <button type="button" onclick="togglePw('pw_<?= $name ?>',this)"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition">
          <i class="fa-solid fa-eye text-sm"></i></button>
      </div>
      <?php if ($name==='new_password'): ?>
        <p class="text-xs text-slate-500 mt-1">Minimum 8 characters</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div id="pwStrengthWrap" class="hidden">
      <div class="flex gap-1 mb-1"><?php for($i=1;$i<=4;$i++): ?><div class="h-1.5 flex-1 rounded-full bg-white/5" id="pwS<?= $i ?>"></div><?php endfor; ?></div>
      <p class="text-[11px] text-slate-500" id="pwStrengthLabel"></p>
    </div>
    <div class="pt-2">
      <button type="submit" class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">Update Password</button>
    </div>
  </form>
</div>
<script>
function togglePw(id,btn){
  const i=document.getElementById(id);if(!i)return;
  const s=i.type==='password';i.type=s?'text':'password';
  btn.innerHTML=s?'<i class="fa-solid fa-eye-slash text-sm"></i>':'<i class="fa-solid fa-eye text-sm"></i>';
}
(function(){
  const inp=document.getElementById('pw_new_password');
  const wrap=document.getElementById('pwStrengthWrap');
  const bars=[1,2,3,4].map(n=>document.getElementById('pwS'+n));
  const lbl=document.getElementById('pwStrengthLabel');
  if(!inp)return;
  inp.addEventListener('input',()=>{
    const v=inp.value;
    wrap.classList.toggle('hidden',v.length===0);
    let s=0;
    if(v.length>=8)s++;if(v.length>=12)s++;
    if(/[A-Z]/.test(v)&&/[0-9]/.test(v))s++;
    if(/[^A-Za-z0-9]/.test(v))s++;
    const c=['bg-red-500','bg-amber-500','bg-yellow-400','bg-green-500'];
    const l=['Weak','Fair','Good','Strong'];
    bars.forEach((b,i)=>{ b.className='h-1.5 flex-1 rounded-full '+(i<s?c[s-1]:'bg-white/5'); });
    lbl.textContent=s>0?l[s-1]:'';
  });
})();
</script>


<?php elseif ($tab === 'security'): ?>
<!-- ════════════════════════════════ SECURITY ════════════════════════════════ -->
<div class="space-y-5 max-w-lg">

  <!-- 2FA Card -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-start justify-between mb-5 gap-3">
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-1">Two-Factor Authentication</p>
        <p class="text-sm text-slate-300">Protect your account with a time-based one-time code from an authenticator app.</p>
      </div>
      <span class="inline-flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-1 rounded-full shrink-0
                   <?= $twofa_enabled
                       ? 'bg-green-500/15 text-green-300 border border-green-500/20'
                       : 'bg-slate-700/60 text-slate-400 border border-white/8' ?>">
        <i class="fa-solid fa-<?= $twofa_enabled?'circle-check':'circle-xmark' ?>"></i>
        <?= $twofa_enabled ? 'Enabled' : 'Not Enabled' ?>
      </span>
    </div>

    <?php if (!$has_2fa_cols): ?>
    <div class="flex items-start gap-3 bg-amber-500/8 border border-amber-500/20 rounded-xl px-4 py-3">
      <i class="fa-solid fa-triangle-exclamation text-amber-400 mt-0.5 shrink-0"></i>
      <p class="text-xs text-amber-300">Run <code class="font-mono bg-black/30 px-1 rounded">migrations/005_add_2fa_and_notif_prefs.sql</code> to enable 2FA support.</p>
    </div>

    <?php elseif (!$twofa_enabled): ?>
    <!-- Enable 2FA flow -->
    <div class="bg-white/3 border border-white/8 rounded-xl px-4 py-3 mb-5">
      <p class="text-xs font-semibold text-slate-400 mb-1"><i class="fa-solid fa-circle-info mr-1.5"></i>Before you begin</p>
      <p class="text-xs text-slate-500">Install <strong class="text-slate-300">Google Authenticator</strong>, <strong class="text-slate-300">Authy</strong>, or <strong class="text-slate-300">1Password</strong> on your phone.</p>
    </div>

    <form method="POST" class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="enable_2fa">
      <input type="hidden" name="totp_secret" value="<?= htmlspecialchars($totp_setup_secret) ?>">

      <!-- Step 1: Scan QR -->
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">
          <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/10 text-white text-[9px] font-bold mr-1.5">1</span>Scan QR Code
        </p>
        <div class="flex items-start gap-4">
          <!-- Server-generated QR via Google Charts API (no JS dependency) -->
          <div class="w-32 h-32 bg-white rounded-xl flex items-center justify-center shrink-0 overflow-hidden p-1">
            <img src="https://chart.googleapis.com/chart?chs=120x120&cht=qr&chl=<?= urlencode($totp_setup_uri) ?>&choe=UTF-8"
                 alt="QR Code" class="w-full h-full" loading="lazy">
          </div>
          <div class="flex-1">
            <p class="text-xs text-slate-400 mb-2">Can&rsquo;t scan? Enter this secret manually:</p>
            <div class="flex items-center gap-2 bg-slate-800/80 border border-slate-600 rounded-xl px-4 py-2.5 mb-2">
              <code class="text-xs text-white tracking-widest font-mono flex-1 break-all" id="tfaSecretDisplay"><?= htmlspecialchars(implode(' ', str_split($totp_setup_secret, 4))) ?></code>
              <button type="button" onclick="copySecret()"
                      class="text-slate-500 hover:text-white transition shrink-0" title="Copy">
                <i class="fa-regular fa-copy text-sm" id="tfaCopyIcon"></i></button>
            </div>
            <p class="text-[11px] text-slate-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Keep this secret safe. Never share it.</p>
          </div>
        </div>
      </div>

      <!-- Step 2: Verify code -->
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">
          <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/10 text-white text-[9px] font-bold mr-1.5">2</span>Enter the Code from Your App
        </p>
        <div class="flex gap-2">
          <input type="text" name="totp_code" maxlength="6" inputmode="numeric"
                 placeholder="6-digit code" autocomplete="one-time-code" required
                 class="flex-1 bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 font-mono tracking-[.35em] text-center text-lg focus:outline-none focus:border-white/40 transition"
                 oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)">
          <button type="submit"
                  class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-3 rounded-xl font-bold text-sm transition whitespace-nowrap">
            <i class="fa-solid fa-shield-check mr-1.5"></i>Enable 2FA
          </button>
        </div>
        <p class="text-[11px] text-slate-500 mt-2">Open your authenticator app and enter the 6-digit code shown for Utiligo.</p>
      </div>
    </form>

    <?php else: ?>
    <!-- Disable 2FA flow -->
    <div class="flex items-center gap-3 bg-green-500/5 border border-green-500/15 rounded-xl px-4 py-3 mb-5">
      <i class="fa-solid fa-shield-check text-green-400"></i>
      <p class="text-sm text-green-300">Your account is protected with two-factor authentication.</p>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="disable_2fa">
      <p class="text-xs text-slate-400 mb-3">To disable 2FA, confirm your current password:</p>
      <div class="flex gap-2">
        <div class="relative flex-1">
          <input type="password" name="confirm_password_2fa" required
                 placeholder="Current password" autocomplete="current-password" id="pw_dis2fa"
                 class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 pr-11 focus:outline-none focus:border-white/40 transition">
          <button type="button" onclick="togglePw('pw_dis2fa',this)"
                  class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition">
            <i class="fa-solid fa-eye text-sm"></i></button>
        </div>
        <button type="submit"
                class="bg-red-500/80 hover:bg-red-500 active:scale-95 text-white px-5 py-3 rounded-xl font-bold text-sm transition whitespace-nowrap">
          <i class="fa-solid fa-shield-xmark mr-1.5"></i>Disable 2FA
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- Session info -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Current Session</p>
    <div class="space-y-3">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-white/8 flex items-center justify-center shrink-0">
          <i class="fa-solid fa-display text-slate-400 text-xs"></i>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm text-white font-medium">This device</p>
          <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 70)) ?></p>
        </div>
        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-full bg-green-500/10 text-green-400 border border-green-500/15 shrink-0">
          <span class="w-1.5 h-1.5 rounded-full bg-green-400 inline-block"></span> Active
        </span>
      </div>
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-white/8 flex items-center justify-center shrink-0">
          <i class="fa-solid fa-location-dot text-slate-400 text-xs"></i>
        </div>
        <div>
          <p class="text-sm text-white font-medium">IP Address</p>
          <p class="text-xs text-slate-500"><?= htmlspecialchars($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?></p>
        </div>
      </div>
    </div>
    <div class="mt-5 pt-4 border-t border-white/5">
      <a href="/logout.php" class="inline-flex items-center gap-2 text-sm text-red-400 hover:text-red-300 font-semibold transition">
        <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>Sign out of all devices
      </a>
      <p class="text-[11px] text-slate-600 mt-1">This will end your current session immediately.</p>
    </div>
  </div>

  <!-- Password shortcut -->
  <div class="glass rounded-2xl p-5 border border-white/5">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-white/8 flex items-center justify-center shrink-0">
          <i class="fa-solid fa-lock text-slate-400 text-xs"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-white">Password</p>
          <p class="text-xs text-slate-500">Change your login password</p>
        </div>
      </div>
      <a href="?tab=password" class="text-xs bg-white/10 hover:bg-white/15 text-white px-4 py-2 rounded-xl font-semibold transition">Change</a>
    </div>
  </div>

</div>
<script>
function togglePw(id,btn){
  const i=document.getElementById(id);if(!i)return;
  const s=i.type==='password';i.type=s?'text':'password';
  btn.innerHTML=s?'<i class="fa-solid fa-eye-slash text-sm"></i>':'<i class="fa-solid fa-eye text-sm"></i>';
}
function copySecret(){
  const el=document.getElementById('tfaSecretDisplay');
  if(!el)return;
  navigator.clipboard.writeText(el.textContent.replace(/\s/g,'')).then(()=>{
    const ic=document.getElementById('tfaCopyIcon');
    if(ic){ic.className='fa-solid fa-check text-sm text-green-400';setTimeout(()=>ic.className='fa-regular fa-copy text-sm',1800);}
  });
}
</script>


<?php elseif ($tab === 'notifications'): ?>
<!-- ════════════════════════════════ NOTIFICATIONS ════════════════════════════════ -->
<div class="space-y-5 max-w-lg">
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-1">Email Notifications</p>
    <p class="text-sm text-slate-400 mb-6">Choose which emails Utiligo sends to <strong class="text-white"><?= htmlspecialchars($user['email'] ?? '') ?></strong>.</p>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save_notifications">
      <?php
      $notifOptions = [
        ['email_sites',   'fa-globe',       'Site Activity',      'Link activations, view milestones, and expiry reminders'],
        ['email_leads',   'fa-users',       'Lead Alerts',        'New lead recommendations and search updates'],
        ['email_billing', 'fa-credit-card', 'Billing & Invoices', 'Payment receipts, renewal notices, plan changes'],
        ['email_tips',    'fa-lightbulb',   'Tips & Updates',     'Product news, feature announcements, growth tips'],
      ];
      foreach ($notifOptions as [$key,$icon,$title,$desc]): $on=($notif_prefs[$key]??0); ?>
      <label class="flex items-start gap-4 p-4 rounded-xl border cursor-pointer transition
                    <?= $on ? 'border-white/12 bg-white/4 hover:bg-white/6' : 'border-white/5 hover:bg-white/3' ?>">
        <div class="w-9 h-9 rounded-xl bg-white/8 flex items-center justify-center shrink-0 mt-0.5">
          <i class="fa-solid fa-<?= $icon ?> text-slate-300 text-xs"></i>
        </div>
        <div class="flex-1">
          <p class="text-sm font-semibold text-white"><?= $title ?></p>
          <p class="text-xs text-slate-500 mt-0.5"><?= $desc ?></p>
        </div>
        <input type="checkbox" name="<?= $key ?>" value="1" <?= $on?'checked':'' ?>
               class="w-4 h-4 mt-1 rounded border-slate-600 bg-slate-800 accent-white cursor-pointer shrink-0">
      </label>
      <?php endforeach; ?>
      <div class="pt-2">
        <button type="submit" class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">Save Preferences</button>
      </div>
    </form>
  </div>
  <div class="flex items-start gap-3 bg-white/3 border border-white/8 rounded-xl px-4 py-3">
    <i class="fa-solid fa-circle-info text-slate-500 mt-0.5 shrink-0"></i>
    <p class="text-xs text-slate-500">
      Transactional emails (password resets, security alerts) are always sent regardless of your preferences.
      <?php if(!$is_paid): ?>
      <a href="/portal/billing.php?upgrade=1" class="text-white underline hover:text-slate-300">Upgrade to Pro</a> to unlock priority support.
      <?php endif; ?>
    </p>
  </div>
</div>


<?php elseif ($tab === 'danger'): ?>
<!-- ════════════════════════════════ DANGER ZONE ════════════════════════════════ -->
<div class="space-y-5 max-w-lg">

  <!-- Export data -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-start gap-4">
      <div class="w-10 h-10 rounded-xl bg-white/8 flex items-center justify-center shrink-0">
        <i class="fa-solid fa-download text-slate-300 text-sm"></i>
      </div>
      <div class="flex-1">
        <p class="font-semibold text-white mb-1">Export Your Data</p>
        <p class="text-sm text-slate-400 mb-4">Download a copy of your profile, generated sites list, and lead history as a JSON file.</p>
        <a href="/api/export_data.php"
           class="inline-flex items-center gap-2 text-sm bg-white/10 hover:bg-white/15 text-white px-5 py-2.5 rounded-xl font-semibold transition">
          <i class="fa-solid fa-file-arrow-down text-xs"></i>Download My Data
        </a>
      </div>
    </div>
  </div>

  <!-- Delete account -->
  <div class="glass rounded-2xl p-6 border border-red-500/15 bg-red-500/3">
    <div class="flex items-start gap-4 mb-5">
      <div class="w-10 h-10 rounded-xl bg-red-500/15 flex items-center justify-center shrink-0">
        <i class="fa-solid fa-trash text-red-400 text-sm"></i>
      </div>
      <div>
        <p class="font-semibold text-white mb-1">Delete Account</p>
        <p class="text-sm text-slate-400">Permanently delete your account. Your email will be anonymised and your subscription cancelled immediately. <strong class="text-red-400">This cannot be undone.</strong></p>
      </div>
    </div>

    <div id="deleteToggleWrap">
      <button type="button"
              onclick="this.closest('#deleteToggleWrap').classList.add('hidden');document.getElementById('deleteFormWrap').classList.remove('hidden');"
              class="inline-flex items-center gap-2 text-sm bg-red-500/15 hover:bg-red-500/25 text-red-400 border border-red-500/20 px-5 py-2.5 rounded-xl font-semibold transition">
        <i class="fa-solid fa-trash text-xs"></i>I want to delete my account
      </button>
    </div>

    <div id="deleteFormWrap" class="hidden">
      <div class="bg-red-500/8 border border-red-500/20 rounded-xl px-4 py-3 mb-4">
        <p class="text-xs text-red-300 font-semibold mb-1"><i class="fa-solid fa-triangle-exclamation mr-1.5"></i>Final warning</p>
        <ul class="text-xs text-slate-400 space-y-1 list-disc list-inside">
          <li>All generated sites and links will be deactivated</li>
          <li>Your lead history will be erased</li>
          <li>Active subscriptions will be cancelled</li>
          <li>This action cannot be reversed</li>
        </ul>
      </div>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="delete_account">
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">
            Type <span class="text-red-400 font-mono">DELETE</span> to confirm
          </label>
          <input type="text" name="confirm_word" placeholder="DELETE" required autocomplete="off"
                 class="w-full bg-slate-800/80 border border-red-500/30 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-red-400/60 transition"
                 oninput="document.getElementById('deleteBtn').disabled=this.value.trim().toUpperCase()!=='DELETE';">
        </div>
        <button id="deleteBtn" type="submit" disabled
                class="w-full bg-red-600 hover:bg-red-500 disabled:opacity-40 disabled:cursor-not-allowed active:scale-95 text-white px-6 py-3 rounded-xl font-bold text-sm transition">
          <i class="fa-solid fa-trash mr-2"></i>Permanently Delete My Account
        </button>
        <button type="button"
                onclick="document.getElementById('deleteToggleWrap').classList.remove('hidden');document.getElementById('deleteFormWrap').classList.add('hidden');"
                class="w-full text-slate-500 hover:text-white text-sm font-semibold py-2 transition">Cancel</button>
      </form>
    </div>
  </div>

</div>
<?php endif; ?>

</div></main>
