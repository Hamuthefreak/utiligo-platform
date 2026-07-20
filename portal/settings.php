<?php
/**
 * portal/settings.php
 * Full settings hub: profile, password, 2FA (email + TOTP), notifications, danger zone.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/mailer.php';

require_login();
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);

$message = '';
$error   = '';
$tab     = $_GET['tab'] ?? 'profile';

// ======================================================================
// Graceful column-existence checks
// ======================================================================
$userdb = get_user_db();

// TOTP columns (migration 005)
$has_totp_cols = db_table_has_column($userdb, 'utiligo_users', 'two_factor_secret');
$totp_enabled  = false;
if ($has_totp_cols) {
    $st = $userdb->prepare('SELECT two_factor_enabled FROM utiligo_users WHERE id = ?');
    $st->execute([$user['id']]);
    $totp_enabled = (bool)$st->fetchColumn();
}

// Email 2FA — uses utiligo_2fa_codes table which exists from the start
// We expose this as a separate toggle: "Use email codes on login"
// Stored via two_factor_enabled column in the pre-TOTP sense (migration 002)
$has_email2fa_col = db_table_has_column($userdb, 'utiligo_users', 'two_factor_enabled');
// Read current email-2FA preference (only meaningful when totp NOT enabled)
$email2fa_enabled = false;
if ($has_email2fa_col && !$totp_enabled) {
    $st2 = $userdb->prepare('SELECT two_factor_enabled FROM utiligo_users WHERE id = ?');
    $st2->execute([$user['id']]);
    $email2fa_enabled = (bool)$st2->fetchColumn();
}

// Notification prefs (migration 005)
$notif_prefs = ['email_sites'=>1,'email_leads'=>1,'email_billing'=>1,'email_tips'=>0];
$has_notif_col = db_table_has_column($userdb, 'utiligo_users', 'notif_prefs');
if ($has_notif_col) {
    $st3 = $userdb->prepare('SELECT notif_prefs FROM utiligo_users WHERE id = ?');
    $st3->execute([$user['id']]);
    $raw = $st3->fetchColumn();
    if ($raw) $notif_prefs = array_merge($notif_prefs, json_decode($raw, true) ?: []);
}

// ======================================================================
// POST HANDLERS
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh and try again.';
    }

    // ── Profile ──
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

    // ── Password ──
    elseif (($_POST['action'] ?? '') === 'change_password') {
        $tab     = 'password';
        $current = $_POST['current_password'] ?? '';
        $new_pw  = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $row     = $userdb->prepare('SELECT password_hash FROM utiligo_users WHERE id = ?');
        $row->execute([$user['id']]);
        $hash = $row->fetchColumn();
        if (!password_verify($current, $hash ?? '')) {
            $error = 'Current password is incorrect.'; $tab='password';
        } elseif (strlen($new_pw) < 8) {
            $error = 'New password must be at least 8 characters.'; $tab='password';
        } elseif ($new_pw !== $confirm) {
            $error = 'Passwords do not match.'; $tab='password';
        } else {
            $userdb->prepare('UPDATE utiligo_users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new_pw, PASSWORD_BCRYPT), $user['id']]);
            $message = 'Password changed successfully.'; $tab='password';
        }
    }

    // ── Enable email 2FA ──
    elseif (($_POST['action'] ?? '') === 'enable_email_2fa') {
        $tab = 'security';
        if (!$has_email2fa_col) {
            $error = 'Run migration 002 first.';
        } else {
            $userdb->prepare('UPDATE utiligo_users SET two_factor_enabled = 1 WHERE id = ?')->execute([$user['id']]);
            $email2fa_enabled = true;
            $message = 'Email verification codes will now be required at each login.';
        }
    }

    // ── Disable email 2FA ──
    elseif (($_POST['action'] ?? '') === 'disable_email_2fa') {
        $tab = 'security';
        $pw  = $_POST['confirm_password_e2fa'] ?? '';
        $row = $userdb->prepare('SELECT password_hash FROM utiligo_users WHERE id = ?');
        $row->execute([$user['id']]);
        $hash = $row->fetchColumn();
        if (!password_verify($pw, $hash ?? '')) {
            $error = 'Incorrect password. Email 2FA was not disabled.';
        } else {
            $userdb->prepare('UPDATE utiligo_users SET two_factor_enabled = 0 WHERE id = ?')->execute([$user['id']]);
            $email2fa_enabled = false;
            $message = 'Email two-factor authentication disabled.';
        }
    }

    // ── Enable TOTP 2FA ──
    elseif (($_POST['action'] ?? '') === 'enable_2fa') {
        $tab    = 'security';
        $code   = preg_replace('/\s+/', '', $_POST['totp_code']   ?? '');
        $secret = strtoupper(trim($_POST['totp_secret'] ?? ''));
        if (!$has_totp_cols) {
            $error = 'Run migrations/005_add_2fa_and_notif_prefs.sql first.';
        } elseif (strlen($secret) < 16) {
            $error = 'Invalid secret. Reload the page and try again.';
        } elseif (!totp_verify($secret, $code)) {
            $error = 'That code is wrong or expired — wait for a new code in your app and try again.';
        } else {
            // Enabling TOTP also turns off email 2FA to avoid double-challenge
            if ($has_email2fa_col) {
                $userdb->prepare('UPDATE utiligo_users SET two_factor_secret = ?, two_factor_enabled = 0 WHERE id = ?')
                    ->execute([$secret, $user['id']]);
            } else {
                $userdb->prepare('UPDATE utiligo_users SET two_factor_secret = ? WHERE id = ?')
                    ->execute([$secret, $user['id']]);
            }
            // Write the enabled flag
            $userdb->prepare('UPDATE utiligo_users SET two_factor_enabled = 1 WHERE id = ?')->execute([$user['id']]);
            unset($_SESSION['totp_pending_secret']);
            $totp_enabled    = true;
            $email2fa_enabled = false;
            $_SESSION['2fa_verified'] = true;
            $message = 'Authenticator app 2FA is now enabled. Email codes are no longer required.';
        }
    }

    // ── Disable TOTP 2FA ──
    elseif (($_POST['action'] ?? '') === 'disable_2fa') {
        $tab = 'security';
        $pw  = $_POST['confirm_password_2fa'] ?? '';
        $row = $userdb->prepare('SELECT password_hash FROM utiligo_users WHERE id = ?');
        $row->execute([$user['id']]);
        $hash = $row->fetchColumn();
        if (!password_verify($pw, $hash ?? '')) {
            $error = 'Incorrect password. Authenticator 2FA was not disabled.';
        } elseif (!$has_totp_cols) {
            $error = 'Nothing to disable.';
        } else {
            $userdb->prepare('UPDATE utiligo_users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE id = ?')
                ->execute([$user['id']]);
            $totp_enabled = false;
            $message = 'Authenticator 2FA disabled. You can still enable email codes if you want.';
        }
    }

    // ── Notifications ──
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
        }
        $notif_prefs = $prefs;
        $message = 'Notification preferences saved.';
    }

    // ── Delete account ──
    elseif (($_POST['action'] ?? '') === 'delete_account') {
        $tab          = 'danger';
        $confirm_word = trim($_POST['confirm_word'] ?? '');
        if (strtoupper($confirm_word) !== 'DELETE') {
            $error = 'Type DELETE (all caps) to confirm account deletion.';
        } else {
            try {
                $userdb->prepare("UPDATE utiligo_users SET plan='free', subscription_status='cancelled',
                    email=CONCAT('deleted_',id,'_',email), full_name='Deleted Account',
                    two_factor_secret=NULL, two_factor_enabled=0 WHERE id=?")
                    ->execute([$user['id']]);
            } catch (\Throwable $e) {
                $userdb->prepare("UPDATE utiligo_users SET plan='free', subscription_status='cancelled',
                    email=CONCAT('deleted_',id,'_',email), full_name='Deleted Account' WHERE id=?")
                    ->execute([$user['id']]);
            }
            session_destroy();
            header('Location: /?deleted=1'); exit;
        }
    }
}

// ── Generate TOTP setup secret for the Security tab ──
$totp_setup_secret = '';
$totp_setup_uri    = '';
if (!$totp_enabled && $tab === 'security') {
    if (empty($_SESSION['totp_pending_secret'])) {
        $_SESSION['totp_pending_secret'] = totp_generate_secret();
    }
    $totp_setup_secret = $_SESSION['totp_pending_secret'];
    $totp_setup_uri    = totp_uri($totp_setup_secret, $user['email'] ?? 'user@utiligo.com');
}
if ($totp_enabled) unset($_SESSION['totp_pending_secret']);

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
<!-- ════════════════════════════════════════ PROFILE ═══════════════════════════════════════════ -->
<div class="space-y-5 max-w-lg">

  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Personal Info</p>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_profile">
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Full Name</label>
        <input type="text" name="full_name" required value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
      </div>
      <div>
        <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Email Address</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($user['email'] ?? '') ?>"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
        <p class="text-[11px] text-slate-500 mt-1.5"><i class="fa-solid fa-circle-info mr-1"></i>Changing your email will require you to log in again on other devices.</p>
      </div>
      <div class="pt-2">
        <button type="submit" class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">Save Changes</button>
      </div>
    </form>
  </div>

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
            <?= $plan==='entrepreneur' ? 'Unlimited leads &bull; 500 active sites' : ($plan==='pro' ? '120 leads &bull; 200 active sites' : '3 leads &bull; 1 site/day') ?>
          </p>
        </div>
      </div>
      <a href="/portal/billing.php" class="text-xs bg-white/10 hover:bg-white/15 text-white px-4 py-2 rounded-xl font-semibold transition">Manage</a>
    </div>
  </div>

  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Quick Links</p>
    <div class="space-y-1">
      <?php foreach ([
        ['/portal/billing.php',  'fa-credit-card',   'Billing & Plan',  'Manage subscription and invoices'],
        ['/portal/leads.php',    'fa-magnifying-glass','Find Leads',     'Search for local businesses'],
        ['/portal/my_sites.php', 'fa-folder-open',   'My Sites',        'View and manage generated sites'],
        ['?tab=security',        'fa-shield-halved', 'Security',        'Two-factor auth & session info'],
        ['?tab=notifications',   'fa-bell',          'Notifications',   'Email preference settings'],
      ] as [$href,$icon,$title,$desc]): ?>
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
<!-- ════════════════════════════════════════ PASSWORD ══════════════════════════════════════════ -->
<div class="glass rounded-2xl p-6 border border-white/5 max-w-lg">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Change Password</p>
  <form method="POST" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="change_password">
    <?php foreach ([
      ['current_password','Current Password','current-password'],
      ['new_password','New Password','new-password'],
      ['confirm_password','Confirm New Password','new-password'],
    ] as [$name,$label,$ac]): ?>
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
      <?php if($name==='new_password'):?><p class="text-xs text-slate-500 mt-1">Minimum 8 characters</p><?php endif;?>
    </div>
    <?php endforeach; ?>
    <div id="pwStrengthWrap" class="hidden">
      <div class="flex gap-1 mb-1"><?php for($i=1;$i<=4;$i++):?><div class="h-1.5 flex-1 rounded-full bg-white/5" id="pwS<?=$i?>"></div><?php endfor;?></div>
      <p class="text-[11px] text-slate-500" id="pwStrengthLabel"></p>
    </div>
    <div class="pt-2">
      <button type="submit" class="bg-white hover:bg-slate-200 active:scale-95 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">Update Password</button>
    </div>
  </form>
</div>
<script>
function togglePw(id,btn){const i=document.getElementById(id);if(!i)return;const s=i.type==='password';i.type=s?'text':'password';btn.innerHTML=s?'<i class="fa-solid fa-eye-slash text-sm"></i>':'<i class="fa-solid fa-eye text-sm"></i>';}
(function(){const inp=document.getElementById('pw_new_password');const wrap=document.getElementById('pwStrengthWrap');const bars=[1,2,3,4].map(n=>document.getElementById('pwS'+n));const lbl=document.getElementById('pwStrengthLabel');if(!inp)return;inp.addEventListener('input',()=>{const v=inp.value;wrap.classList.toggle('hidden',v.length===0);let s=0;if(v.length>=8)s++;if(v.length>=12)s++;if(/[A-Z]/.test(v)&&/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;const c=['bg-red-500','bg-amber-500','bg-yellow-400','bg-green-500'];const l=['Weak','Fair','Good','Strong'];bars.forEach((b,i)=>{b.className='h-1.5 flex-1 rounded-full '+(i<s?c[s-1]:'bg-white/5');});lbl.textContent=s>0?l[s-1]:'';});})();
</script>


<?php elseif ($tab === 'security'): ?>
<!-- ════════════════════════════════════════ SECURITY ══════════════════════════════════════════ -->
<div class="space-y-5 max-w-lg">

  <!-- ─── EMAIL 2FA CARD ─── -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-start gap-3 mb-5">
      <div class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center shrink-0 mt-0.5">
        <i class="fa-solid fa-envelope text-blue-400 text-sm"></i>
      </div>
      <div class="flex-1">
        <div class="flex items-center gap-2 flex-wrap">
          <p class="font-semibold text-white">Email Verification Codes</p>
          <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full <?= ($email2fa_enabled&&!$totp_enabled)?'bg-green-500/15 text-green-300 border border-green-500/20':'bg-slate-700/60 text-slate-500 border border-white/8' ?>">
            <i class="fa-solid fa-<?= ($email2fa_enabled&&!$totp_enabled)?'circle-check':'circle-xmark' ?>"></i>
            <?= ($email2fa_enabled&&!$totp_enabled)?'Active':'Off' ?>
          </span>
        </div>
        <p class="text-sm text-slate-400 mt-0.5">A 6-digit code is emailed to you each time you log in. Simple, no app needed.</p>
      </div>
    </div>

    <?php if ($totp_enabled): ?>
    <div class="flex items-center gap-2 bg-slate-700/40 border border-white/8 rounded-xl px-4 py-3 text-xs text-slate-400">
      <i class="fa-solid fa-circle-info shrink-0"></i>
      Authenticator app 2FA is active — email codes are replaced by your app. Disable the authenticator to switch back.
    </div>

    <?php elseif (!$has_email2fa_col): ?>
    <div class="flex items-start gap-2 bg-amber-500/8 border border-amber-500/20 rounded-xl px-4 py-3">
      <i class="fa-solid fa-triangle-exclamation text-amber-400 mt-0.5 shrink-0"></i>
      <p class="text-xs text-amber-300">Run <code class="font-mono bg-black/30 px-1 rounded">migrations/002_add_two_factor.sql</code> to enable this feature.</p>
    </div>

    <?php elseif (!$email2fa_enabled): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="enable_email_2fa">
      <button type="submit"
              class="inline-flex items-center gap-2 text-sm bg-blue-500/15 hover:bg-blue-500/25 text-blue-300 border border-blue-500/20 px-5 py-2.5 rounded-xl font-semibold transition">
        <i class="fa-solid fa-envelope-circle-check text-xs"></i>Enable Email Codes
      </button>
    </form>

    <?php else: ?>
    <form method="POST" class="flex gap-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="disable_email_2fa">
      <div class="relative flex-1">
        <input type="password" name="confirm_password_e2fa" id="pw_e2fa" required placeholder="Confirm password"
               autocomplete="current-password"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-2.5 pr-11 focus:outline-none focus:border-white/40 transition text-sm">
        <button type="button" onclick="togglePw('pw_e2fa',this)"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition">
          <i class="fa-solid fa-eye text-sm"></i></button>
      </div>
      <button type="submit"
              class="inline-flex items-center gap-2 text-sm bg-red-500/15 hover:bg-red-500/25 text-red-400 border border-red-500/20 px-4 py-2.5 rounded-xl font-semibold transition whitespace-nowrap">
        <i class="fa-solid fa-envelope-open text-xs"></i>Disable
      </button>
    </form>
    <?php endif; ?>
  </div>

  <!-- ─── TOTP AUTHENTICATOR CARD ─── -->
  <div class="glass rounded-2xl p-6 border border-white/5">
    <div class="flex items-start gap-3 mb-5">
      <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0 mt-0.5">
        <i class="fa-solid fa-mobile-screen-button text-emerald-400 text-sm"></i>
      </div>
      <div class="flex-1">
        <div class="flex items-center gap-2 flex-wrap">
          <p class="font-semibold text-white">Authenticator App</p>
          <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full <?= $totp_enabled?'bg-green-500/15 text-green-300 border border-green-500/20':'bg-slate-700/60 text-slate-500 border border-white/8' ?>">
            <i class="fa-solid fa-<?= $totp_enabled?'circle-check':'circle-xmark' ?>"></i>
            <?= $totp_enabled?'Active':'Off' ?>
          </span>
          <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/8 text-emerald-400 border border-emerald-500/15">Recommended</span>
        </div>
        <p class="text-sm text-slate-400 mt-0.5">Use Google Authenticator, Authy, or 1Password. No internet needed for codes.</p>
      </div>
    </div>

    <?php if (!$has_totp_cols): ?>
    <div class="flex items-start gap-2 bg-amber-500/8 border border-amber-500/20 rounded-xl px-4 py-3">
      <i class="fa-solid fa-triangle-exclamation text-amber-400 mt-0.5 shrink-0"></i>
      <p class="text-xs text-amber-300">Run <code class="font-mono bg-black/30 px-1 rounded">migrations/005_add_2fa_and_notif_prefs.sql</code> to enable authenticator support.</p>
    </div>

    <?php elseif (!$totp_enabled): ?>
    <!-- Enable TOTP flow -->
    <div class="bg-white/3 border border-white/8 rounded-xl px-4 py-3 mb-5 text-xs text-slate-400">
      <i class="fa-solid fa-circle-info mr-1.5 text-slate-500"></i>
      Enabling the authenticator app will replace email codes — you'll use your app at every login.
    </div>

    <form method="POST" class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="enable_2fa">
      <input type="hidden" name="totp_secret" value="<?= htmlspecialchars($totp_setup_secret) ?>">

      <!-- Step 1 -->
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">
          <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/10 text-[9px] font-bold mr-1.5">1</span>Scan this QR code
        </p>
        <div class="flex items-start gap-4">
          <div class="w-32 h-32 bg-white rounded-xl flex items-center justify-center shrink-0 overflow-hidden p-1">
            <!-- api.qrserver.com — free, reliable, no API key needed (replaces deprecated Google Charts) -->
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&format=png&ecc=M&data=<?= urlencode($totp_setup_uri) ?>"
                 alt="Authenticator QR Code" width="120" height="120" class="w-full h-full" loading="eager">
          </div>
          <div class="flex-1">
            <p class="text-xs text-slate-400 mb-2">Can&rsquo;t scan? Enter this key manually:</p>
            <div class="flex items-center gap-2 bg-slate-800/80 border border-slate-600 rounded-xl px-4 py-2.5 mb-2">
              <code class="text-xs text-white tracking-widest font-mono flex-1 break-all select-all" id="tfaSecretDisplay"><?= htmlspecialchars(implode(' ', str_split($totp_setup_secret, 4))) ?></code>
              <button type="button" onclick="copySecret()"
                      class="text-slate-500 hover:text-white transition shrink-0" title="Copy">
                <i class="fa-regular fa-copy text-sm" id="tfaCopyIcon"></i></button>
            </div>
            <p class="text-[11px] text-slate-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Never share this key with anyone.</p>
          </div>
        </div>
      </div>

      <!-- Step 2 -->
      <div>
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">
          <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/10 text-[9px] font-bold mr-1.5">2</span>Enter the 6-digit code from your app
        </p>
        <div class="flex gap-2">
          <input type="text" name="totp_code" maxlength="6" inputmode="numeric"
                 placeholder="000 000" autocomplete="one-time-code" required
                 class="flex-1 bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 font-mono tracking-[.35em] text-center text-xl focus:outline-none focus:border-emerald-400/60 transition"
                 oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)">
          <button type="submit"
                  class="bg-emerald-500 hover:bg-emerald-400 active:scale-95 text-black px-5 py-3 rounded-xl font-bold text-sm transition whitespace-nowrap">
            <i class="fa-solid fa-shield-check mr-1.5"></i>Enable
          </button>
        </div>
        <p class="text-[11px] text-slate-500 mt-2">The code rotates every 30 seconds — enter it before it changes. If it fails, wait for the next code.</p>
      </div>
    </form>

    <?php else: ?>
    <!-- Disable TOTP flow -->
    <div class="flex items-center gap-3 bg-green-500/5 border border-green-500/15 rounded-xl px-4 py-3 mb-5">
      <i class="fa-solid fa-shield-check text-green-400"></i>
      <p class="text-sm text-green-300">Authenticator app 2FA is active on this account.</p>
    </div>
    <form method="POST" class="flex gap-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="disable_2fa">
      <div class="relative flex-1">
        <input type="password" name="confirm_password_2fa" id="pw_dis2fa" required
               placeholder="Confirm password" autocomplete="current-password"
               class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-2.5 pr-11 focus:outline-none focus:border-white/40 transition text-sm">
        <button type="button" onclick="togglePw('pw_dis2fa',this)"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white transition">
          <i class="fa-solid fa-eye text-sm"></i></button>
      </div>
      <button type="submit"
              class="inline-flex items-center gap-2 text-sm bg-red-500/15 hover:bg-red-500/25 text-red-400 border border-red-500/20 px-4 py-2.5 rounded-xl font-semibold transition whitespace-nowrap">
        <i class="fa-solid fa-shield-xmark text-xs"></i>Disable
      </button>
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
function togglePw(id,btn){const i=document.getElementById(id);if(!i)return;const s=i.type==='password';i.type=s?'text':'password';btn.innerHTML=s?'<i class="fa-solid fa-eye-slash text-sm"></i>':'<i class="fa-solid fa-eye text-sm"></i>';}
function copySecret(){const el=document.getElementById('tfaSecretDisplay');if(!el)return;navigator.clipboard.writeText(el.textContent.replace(/\s/g,'')).then(()=>{const ic=document.getElementById('tfaCopyIcon');if(ic){ic.className='fa-solid fa-check text-sm text-green-400';setTimeout(()=>ic.className='fa-regular fa-copy text-sm',1800);}});}
</script>


<?php elseif ($tab === 'notifications'): ?>
<!-- ════════════════════════════════════════ NOTIFICATIONS ═════════════════════════════════════ -->
<div class="space-y-5 max-w-lg">
  <div class="glass rounded-2xl p-6 border border-white/5">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-1">Email Notifications</p>
    <p class="text-sm text-slate-400 mb-6">Choose what Utiligo sends to <strong class="text-white"><?= htmlspecialchars($user['email'] ?? '') ?></strong>.</p>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save_notifications">
      <?php
      $notifOptions = [
        [
          'email_sites',
          'globe',
          'bg-blue-500/10 border-blue-500/20',
          'text-blue-400',
          'Site Activity',
          'Link activations, view milestones &amp; expiry reminders',
          'When a shared site hits a view milestone or is close to expiring',
        ],
        [
          'email_leads',
          'users',
          'bg-emerald-500/10 border-emerald-500/20',
          'text-emerald-400',
          'Lead Alerts',
          'New lead recommendations &amp; search updates',
          'When Utiligo finds strong new leads matching your saved searches',
        ],
        [
          'email_billing',
          'credit-card',
          'bg-amber-500/10 border-amber-500/20',
          'text-amber-400',
          'Billing &amp; Invoices',
          'Payment receipts, renewal notices &amp; plan changes',
          'Receipts, upcoming renewals, failed payments — always recommended',
        ],
        [
          'email_tips',
          'lightbulb',
          'bg-purple-500/10 border-purple-500/20',
          'text-purple-400',
          'Tips &amp; Updates',
          'Product news, feature announcements &amp; growth tips',
          'Occasional tips and new feature announcements — low volume',
        ],
      ];
      foreach ($notifOptions as [$key,$icon,$iconBg,$iconColor,$title,$subtitle,$hint]): $on=($notif_prefs[$key]??0); ?>
      <label class="flex items-start gap-4 p-4 rounded-xl border cursor-pointer transition select-none
                    <?= $on ? 'border-white/10 bg-white/4 hover:bg-white/6' : 'border-white/5 hover:bg-white/3' ?>">
        <!-- Icon -->
        <div class="w-10 h-10 rounded-xl border flex items-center justify-center shrink-0 mt-0.5 <?= $iconBg ?>">
          <i class="fa-solid fa-<?= $icon ?> text-sm <?= $iconColor ?>"></i>
        </div>
        <!-- Text -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-white"><?= $title ?></p>
          <p class="text-xs text-slate-400 mt-0.5"><?= $subtitle ?></p>
          <p class="text-[11px] text-slate-600 mt-1"><?= $hint ?></p>
        </div>
        <!-- Toggle -->
        <div class="relative shrink-0 mt-1">
          <input type="checkbox" name="<?= $key ?>" value="1" id="notif_<?= $key ?>" <?= $on?'checked':'' ?> class="sr-only peer">
          <div class="w-9 h-5 rounded-full border transition
                      bg-slate-700 border-slate-600
                      peer-checked:bg-white peer-checked:border-white cursor-pointer"
               onclick="document.getElementById('notif_<?= $key ?>').click()"></div>
          <div class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-slate-400 transition
                      peer-checked:translate-x-4 peer-checked:bg-slate-900
                      pointer-events-none"></div>
        </div>
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
      Security emails (password resets, login codes) are always sent regardless of these settings.
      <?php if(!$is_paid):?><a href="/portal/billing.php?upgrade=1" class="text-white underline hover:text-slate-300 ml-0.5">Upgrade to Pro</a> for priority support.<?php endif;?>
    </p>
  </div>
</div>
<style>
label:has(input[type=checkbox]:checked) .peer-indicator { transform: translateX(1rem); background:#0f172a; }
</style>
<script>
document.querySelectorAll('.sr-only').forEach(cb=>{
  const track=cb.nextElementSibling;
  const dot=track?.nextElementSibling;
  if(!track||!dot)return;
  function sync(){track.style.background=cb.checked?'#fff':'';track.style.borderColor=cb.checked?'#fff':'';dot.style.transform=cb.checked?'translateX(1rem)':'';dot.style.background=cb.checked?'#0f172a':'';}
  sync();
  cb.addEventListener('change',sync);
  track.addEventListener('click',()=>{ cb.checked=!cb.checked; cb.dispatchEvent(new Event('change')); });
});
</script>


<?php elseif ($tab === 'danger'): ?>
<!-- ════════════════════════════════════════ DANGER ZONE ═══════════════════════════════════════ -->
<div class="space-y-5 max-w-lg">

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

  <div class="glass rounded-2xl p-6 border border-red-500/15 bg-red-500/3">
    <div class="flex items-start gap-4 mb-5">
      <div class="w-10 h-10 rounded-xl bg-red-500/15 flex items-center justify-center shrink-0">
        <i class="fa-solid fa-trash text-red-400 text-sm"></i>
      </div>
      <div>
        <p class="font-semibold text-white mb-1">Delete Account</p>
        <p class="text-sm text-slate-400">Permanently delete your account and cancel your subscription. <strong class="text-red-400">This cannot be undone.</strong></p>
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
          <li>This cannot be reversed</li>
        </ul>
      </div>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="delete_account">
        <div>
          <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Type <span class="text-red-400 font-mono">DELETE</span> to confirm</label>
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
