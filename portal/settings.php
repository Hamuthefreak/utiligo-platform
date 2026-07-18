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
        if (!$full_name)                                   { $error = 'Name cannot be empty.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please enter a valid email.'; }
        else {
            $userdb = get_user_db();
            // check email uniqueness (exclude self)
            $chk = $userdb->prepare('SELECT id FROM utiligo_users WHERE email = ? AND id != ?');
            $chk->execute([$email, $user['id']]);
            if ($chk->fetch()) {
                $error = 'That email is already used by another account.';
            } else {
                $userdb->prepare('UPDATE utiligo_users SET full_name = ?, email = ? WHERE id = ?')
                    ->execute([$full_name, $email, $user['id']]);
                $message = 'Profile updated successfully.';
                // Refresh session user data
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
            // Soft-delete: mark account deleted, log out
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

$pageTitle = 'Settings — Utiligo';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Account Settings</h1>
  <p class="text-slate-400 text-sm mt-1">Manage your profile, password, and account preferences.</p>
</div>

<?php if ($message): ?>
<div class="flex items-center gap-3 bg-white/5 border border-white/10 text-white rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check shrink-0"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Tab bar -->
<div class="flex gap-2 mb-7 border-b border-white/5 pb-0">
  <?php foreach (['profile'=>'Profile','password'=>'Password','danger'=>'Danger Zone'] as $t => $label): ?>
  <a href="?tab=<?= $t ?>"
     class="px-4 py-2.5 text-sm font-semibold border-b-2 transition -mb-px <?= $tab===$t ? 'border-white text-white' : 'border-transparent text-slate-500 hover:text-white' ?>">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'profile'): ?>
<!-- Profile tab -->
<div class="glass rounded-2xl p-6 border border-white/5 max-w-lg">
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
    </div>
    <div class="pt-2">
      <button type="submit" class="bg-white hover:bg-slate-200 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">
        Save Changes
      </button>
    </div>
  </form>
</div>

<!-- Plan info -->
<div class="glass rounded-2xl p-6 border border-white/5 max-w-lg mt-5">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Current Plan</p>
  <div class="flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center">
        <i class="fa-solid fa-<?= $plan === 'entrepreneur' ? 'rocket' : ($plan === 'pro' ? 'crown' : 'user') ?> text-white text-sm"></i>
      </div>
      <div>
        <p class="font-bold text-sm"><?= plan_label($plan) ?> Plan</p>
        <p class="text-xs text-slate-500">
          <?php
            if ($plan === 'entrepreneur') echo 'Unlimited leads &bull; 500 active sites';
            elseif ($plan === 'pro')      echo '120 leads &bull; 200 active sites';
            else                          echo '3 leads &bull; 1 site/day';
          ?>
        </p>
      </div>
    </div>
    <a href="/portal/billing.php" class="text-xs bg-white/10 hover:bg-white/15 text-white px-4 py-2 rounded-xl font-semibold transition">Manage</a>
  </div>
</div>

<?php elseif ($tab === 'password'): ?>
<!-- Password tab -->
<div class="glass rounded-2xl p-6 border border-white/5 max-w-lg">
  <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-5">Change Password</p>
  <form method="POST" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="change_password">
    <div>
      <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Current Password</label>
      <input type="password" name="current_password" required autocomplete="current-password"
             class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
    </div>
    <div>
      <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">New Password</label>
      <input type="password" name="new_password" required minlength="8" autocomplete="new-password"
             class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
      <p class="text-xs text-slate-500 mt-1">Minimum 8 characters</p>
    </div>
    <div>
      <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Confirm New Password</label>
      <input type="password" name="confirm_password" required autocomplete="new-password"
             class="w-full bg-slate-800/80 border border-slate-600 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-white/40 transition">
    </div>
    <div class="pt-2">
      <button type="submit" class="bg-white hover:bg-slate-200 text-black px-6 py-2.5 rounded-xl font-bold text-sm transition">
        Update Password
      </button>
    </div>
  </form>
</div>

<?php elseif ($tab === 'danger'): ?>
<!-- Danger zone -->
<div class="glass rounded-2xl p-6 border border-red-500/20 max-w-lg">
  <div class="flex items-center gap-3 mb-5">
    <div class="w-9 h-9 rounded-xl bg-red-500/15 flex items-center justify-center shrink-0">
      <i class="fa-solid fa-triangle-exclamation text-red-400 text-sm"></i>
    </div>
    <div>
      <p class="font-bold text-white">Delete Account</p>
      <p class="text-xs text-slate-400 mt-0.5">This is permanent and cannot be undone. All your sites and leads will be removed.</p>
    </div>
  </div>
  <form method="POST" onsubmit="return confirm('This will permanently delete your account. Are you absolutely sure?')" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="delete_account">
    <div>
      <label class="block text-xs text-slate-400 font-semibold uppercase tracking-wider mb-2">Type <code class="text-red-400">DELETE</code> to confirm</label>
      <input type="text" name="confirm_word" placeholder="DELETE" autocomplete="off"
             class="w-full bg-slate-800/80 border border-red-500/30 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-red-500/60 transition">
    </div>
    <button type="submit" class="bg-red-500 hover:bg-red-400 text-white px-6 py-2.5 rounded-xl font-bold text-sm transition">
      <i class="fa-solid fa-trash mr-2"></i>Permanently Delete Account
    </button>
  </form>
</div>
<?php endif; ?>
