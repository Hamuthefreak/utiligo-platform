<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
$admin = $GLOBALS['admin_user'];
$udb   = get_user_db();
$pdb   = get_platform_db();

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify('users', $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'CSRF failure on users action');
        die('Invalid CSRF token.');
    }
    $action   = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);
    $q        = urlencode(trim($_GET['q'] ?? ''));
    $pg       = max(1, (int)($_GET['page'] ?? 1));
    $base     = '/admin/users.php?page=' . $pg . ($q !== '' ? '&q=' . $q : '');

    if ($targetId === (int)$admin['id'] && in_array($action, ['ban','demote','delete'])) {
        header('Location: ' . $base . '&flash_error=' . urlencode('You cannot ban, demote, or delete your own account.'));
        exit;
    }

    if ($action === 'set_plan' && $targetId > 0) {
        $plan   = in_array($_POST['plan'] ?? '', ['free','pro','entrepreneur']) ? $_POST['plan'] : 'free';
        $status = in_array($plan, ['pro','entrepreneur']) ? 'active' : 'none';
        $udb->prepare('UPDATE utiligo_users SET plan=?, subscription_status=? WHERE id=?')
            ->execute([$plan, $status, $targetId]);
        _admin_log('INFO', "Set plan={$plan} status={$status} for user_id={$targetId}");
        if ($targetId === (int)$admin['id']) {
            $_SESSION['user_id']                          = $targetId;
            $GLOBALS['admin_user']['plan']                = $plan;
            $GLOBALS['admin_user']['subscription_status'] = $status;
        }
        header('Location: ' . $base . '&flash=' . urlencode('Plan updated to ' . $plan . '.'));
        exit;
    }
    if ($action === 'ban' && $targetId > 0) {
        $udb->prepare("UPDATE utiligo_users SET subscription_status='banned' WHERE id=?")->execute([$targetId]);
        _admin_log('WARN', "Banned user_id={$targetId}");
        header('Location: ' . $base . '&flash=' . urlencode('User banned.'));
        exit;
    }
    if ($action === 'unban' && $targetId > 0) {
        $udb->prepare("UPDATE utiligo_users SET subscription_status='active' WHERE id=?")->execute([$targetId]);
        _admin_log('INFO', "Unbanned user_id={$targetId}");
        header('Location: ' . $base . '&flash=' . urlencode('User unbanned.'));
        exit;
    }
    if ($action === 'promote_admin' && $targetId > 0) {
        $udb->prepare('UPDATE utiligo_users SET is_admin=1 WHERE id=?')->execute([$targetId]);
        _admin_log('WARN', "Promoted user_id={$targetId} to admin");
        header('Location: ' . $base . '&flash=' . urlencode('User promoted to admin.'));
        exit;
    }
    if ($action === 'demote' && $targetId > 0) {
        $udb->prepare('UPDATE utiligo_users SET is_admin=0 WHERE id=?')->execute([$targetId]);
        _admin_log('WARN', "Demoted user_id={$targetId}");
        header('Location: ' . $base . '&flash=' . urlencode('Admin rights removed.'));
        exit;
    }

    // ── Delete account (irreversible) ──────────────────────────────────────────
    // HARD-delete the user across BOTH databases and their server-side files:
    //   User DB:    remember_tokens, email_verifications, password_resets,
    //               2fa_codes, lead_search_quota, then the user row itself.
    //   Platform DB: site_uploads (per-site), generated_sites (incl. on-disk
    //               site folders + ZIP files), whitelabel, unlocked_leads,
    //               lead_search_history.
    //   Files:       /assets/uploads/user_images/{user_id}/
    // Self-delete is blocked above (would log the admin out mid-request).
    if ($action === 'delete' && $targetId > 0) {
        $deletedEmail = '';
        try {
            $u = $udb->prepare('SELECT email FROM utiligo_users WHERE id = ? LIMIT 1');
            $u->execute([$targetId]);
            $row = $u->fetch();
            $deletedEmail = $row['email'] ?? '';
        } catch (\Throwable $e) {}

        // ── Platform DB: clean generated site files + uploads, then rows ──
        try {
            $siteStmt = $pdb->prepare('SELECT id, public_slug, business_name, zip_file_path FROM utiligo_generated_sites WHERE user_id = ?');
            $siteStmt->execute([$targetId]);
            $sites = $siteStmt->fetchAll();

            foreach ($sites as $s) {
                $slugDir = !empty($s['public_slug'])
                    ? $s['public_slug']
                    : (slugify($s['business_name'] ?? 'site') . '-' . $s['id']);
                $siteDir = __DIR__ . '/../assets/uploads/generated_sites/' . $slugDir;
                if (is_dir($siteDir)) {
                    if (!recursive_delete_directory($siteDir) && function_exists('log_error')) {
                        log_error('admin.delete_user', "Could not fully remove: {$siteDir}", ['user_id' => $targetId, 'site_id' => $s['id']]);
                    }
                }
                if (!empty($s['zip_file_path'])) {
                    $zipPath = __DIR__ . '/../' . ltrim($s['zip_file_path'], '/');
                    if (file_exists($zipPath)) @unlink($zipPath);
                }
                // Per-site uploads (if the table/column exists)
                if (db_table_has_column($pdb, 'utiligo_site_uploads', 'site_id')) {
                    try {
                        $pdb->prepare('DELETE FROM utiligo_site_uploads WHERE site_id = ?')->execute([$s['id']]);
                    } catch (\Throwable $e) {}
                }
            }
            $pdb->prepare('DELETE FROM utiligo_generated_sites WHERE user_id = ?')->execute([$targetId]);
        } catch (\Throwable $e) {}

        // ── Platform DB: other user-scoped tables ──
        foreach (['utiligo_whitelabel', 'unlocked_leads', 'utiligo_lead_search_history',
                  'crm_clients', 'crm_tasks', 'crm_notes', 'crm_activities'] as $tbl) {
            try {
                $pdb->prepare("DELETE FROM `{$tbl}` WHERE user_id = ?")->execute([$targetId]);
            } catch (\Throwable $e) {}
        }

        // ── User DB: remember tokens, tokens, 2fa, quota, then the row ──
        $userDbTables = [
            'utiligo_remember_tokens',
            'utiligo_email_verifications',
            'utiligo_password_resets',
            'utiligo_2fa_codes',
            'lead_search_quota',
        ];
        foreach ($userDbTables as $tbl) {
            try {
                $stmt = $udb->prepare("DELETE FROM `{$tbl}` WHERE user_id = ?");
                $stmt->execute([$targetId]);
            } catch (\Throwable $e) {}
        }
        $ok = false;
        try {
            $ok = (bool)$udb->prepare('DELETE FROM utiligo_users WHERE id = ?')->execute([$targetId]);
        } catch (\Throwable $e) {
            if (function_exists('log_error')) log_error('admin.delete_user', $e, ['user_id' => $targetId]);
        }

        // ── User-owned image directory ──
        if ($ok) {
            $userImgDir = __DIR__ . '/../assets/uploads/user_images/' . $targetId;
            if (is_dir($userImgDir)) {
                recursive_delete_directory($userImgDir);
            }
        }

        _admin_log('WARN', "Hard-deleted user_id={$targetId} email=" . ($deletedEmail ?: '<unknown>') . " ok=" . ($ok ? '1' : '0'));
        $msg = $ok
            ? 'Account permanently deleted (email: ' . htmlspecialchars($deletedEmail ?: 'unknown') . ').'
            : 'Could not fully delete the user row (other data cleared). See error log.';
        header('Location: ' . $base . '&flash=' . urlencode($msg));
        exit;
    }
    header('Location: ' . $base);
    exit;
}

// ── Flash messages ────────────────────────────────────────────────────────────
$success = isset($_GET['flash'])       ? htmlspecialchars($_GET['flash'])       : '';
$error   = isset($_GET['flash_error']) ? htmlspecialchars($_GET['flash_error']) : '';

// ── Fetch ─────────────────────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$data    = admin_get_all_users($page, $perPage, $search);
$users   = $data['users'];
$total   = $data['total'];
$pages   = max(1, (int)ceil($total / $perPage));

$csrf = admin_csrf_token('users');

$pageTitle = 'Users — Admin — Utiligo';
$adminPage = 'users';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Users <span class="text-slate-500 text-lg font-normal">(<?= number_format($total) ?>)</span></h1>
</div>

<?php if ($success): ?>
  <div class="mb-6 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 px-4 py-3 rounded-xl text-sm"><?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="mb-6 bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl text-sm"><?= $error ?></div>
<?php endif; ?>

<!-- Search -->
<form method="GET" class="flex gap-3 mb-6">
  <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email…"
         class="flex-1 bg-white/5 border border-white/10 text-white placeholder-slate-500 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-500 transition">
  <button class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-6 py-2.5 rounded-xl font-bold text-sm transition">Search</button>
  <?php if ($search): ?>
    <a href="/admin/users.php" class="bg-white/10 hover:bg-white/20 text-white px-4 py-2.5 rounded-xl text-sm flex items-center transition">Clear</a>
  <?php endif; ?>
</form>

<!-- Table -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden mb-6">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="border-b border-white/5 text-slate-500 text-xs uppercase">
        <th class="px-5 py-3 text-left">Name</th>
        <th class="px-5 py-3 text-left">Email</th>
        <th class="px-5 py-3 text-left">Plan</th>
        <th class="px-5 py-3 text-left">Status</th>
        <th class="px-5 py-3 text-left">Joined</th>
        <th class="px-5 py-3 text-left">Actions</th>
      </tr></thead>
      <tbody class="divide-y divide-white/5">
      <?php if (!$users): ?>
        <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">No users found.</td></tr>
      <?php endif; ?>
      <?php foreach ($users as $u): ?>
      <?php
        // trim + lowercase so whitespace or case differences never break button logic
        $uplan = strtolower(trim($u['plan'] ?? 'free'));
        if (!in_array($uplan, ['free','pro','entrepreneur'])) $uplan = 'free';
      ?>
      <tr class="hover:bg-white/[.02] transition">
        <td class="px-5 py-3 font-medium text-white">
          <?= htmlspecialchars($u['full_name']) ?>
          <?php if (!empty($u['is_admin'])): ?>
            <span class="ml-1 text-[10px] bg-purple-500/20 text-purple-300 border border-purple-500/30 px-2 py-0.5 rounded-full font-semibold">ADMIN</span>
          <?php endif; ?>
        </td>
        <td class="px-5 py-3 text-slate-400"><?= htmlspecialchars($u['email']) ?></td>
        <td class="px-5 py-3">
          <?php if ($uplan==='entrepreneur'): ?>
            <span class="text-[10px] bg-amber-500/15 text-amber-400 border border-amber-500/30 px-2 py-0.5 rounded-full font-bold">🚀 ENT</span>
          <?php elseif ($uplan==='pro'): ?>
            <span class="text-[10px] bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 px-2 py-0.5 rounded-full font-semibold">PRO</span>
          <?php else: ?>
            <span class="text-[10px] bg-white/5 text-slate-400 border border-white/10 px-2 py-0.5 rounded-full font-semibold">FREE</span>
          <?php endif; ?>
        </td>
        <td class="px-5 py-3">
          <?php if (($u['subscription_status'] ?? '') === 'banned'): ?>
            <span class="text-[10px] bg-red-500/20 text-red-300 border border-red-500/30 px-2 py-0.5 rounded-full font-semibold">BANNED</span>
          <?php else: ?>
            <span class="text-xs text-slate-500"><?= htmlspecialchars($u['subscription_status'] ?? '—') ?></span>
          <?php endif; ?>
        </td>
        <td class="px-5 py-3 text-slate-500"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>

        <!-- Actions -->
        <td class="px-5 py-3">
          <div class="flex flex-wrap gap-1.5 items-center">

            <?php if ($uplan === 'free'): ?>
              <!-- Free → Pro -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="pro">
                <button onclick="return confirm('Upgrade to Pro?')"
                        class="bg-emerald-500/10 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-circle-up text-[10px] mr-1"></i>Pro
                </button>
              </form>
              <!-- Free → ENT -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="entrepreneur">
                <button onclick="return confirm('Upgrade directly to Entrepreneur?')"
                        class="bg-amber-500/10 hover:bg-amber-500/25 text-amber-400 border border-amber-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-rocket text-[10px] mr-1"></i>ENT
                </button>
              </form>

            <?php elseif ($uplan === 'pro'): ?>
              <!-- Pro → Free -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="free">
                <button onclick="return confirm('Downgrade to Free?')"
                        class="bg-slate-500/10 hover:bg-slate-500/25 text-slate-400 border border-slate-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-circle-down text-[10px] mr-1"></i>Free
                </button>
              </form>
              <!-- Pro → ENT -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="entrepreneur">
                <button onclick="return confirm('Upgrade to Entrepreneur?')"
                        class="bg-amber-500/10 hover:bg-amber-500/25 text-amber-400 border border-amber-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-rocket text-[10px] mr-1"></i>ENT
                </button>
              </form>

            <?php elseif ($uplan === 'entrepreneur'): ?>
              <!-- ENT → Pro -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="pro">
                <button onclick="return confirm('Downgrade to Pro?')"
                        class="bg-slate-500/10 hover:bg-slate-500/25 text-slate-400 border border-slate-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-circle-down text-[10px] mr-1"></i>Pro
                </button>
              </form>
              <!-- ENT → Free -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="free">
                <button onclick="return confirm('Downgrade to Free?')"
                        class="bg-red-500/10 hover:bg-red-500/25 text-red-400 border border-red-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-circle-down text-[10px] mr-1"></i>Free
                </button>
              </form>
            <?php endif; ?>

            <!-- Ban / Unban -->
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
              <?php if (($u['subscription_status'] ?? '') === 'banned'): ?>
                <button name="action" value="unban"
                        class="bg-green-500/10 hover:bg-green-500/25 text-green-400 border border-green-500/20 px-2.5 py-1 rounded-lg text-xs transition">Unban</button>
              <?php elseif ((int)$u['id'] !== (int)$admin['id']): ?>
                <button name="action" value="ban" onclick="return confirm('Ban this user?')"
                        class="bg-red-500/10 hover:bg-red-500/25 text-red-400 border border-red-500/20 px-2.5 py-1 rounded-lg text-xs transition">Ban</button>
              <?php endif; ?>
            </form>

            <!-- Promote / Demote admin -->
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
              <?php if (empty($u['is_admin'])): ?>
                <button name="action" value="promote_admin" onclick="return confirm('Promote to admin?')"
                        class="bg-purple-500/10 hover:bg-purple-500/25 text-purple-400 border border-purple-500/20 px-2.5 py-1 rounded-lg text-xs transition">+Admin</button>
              <?php elseif ((int)$u['id'] !== (int)$admin['id']): ?>
                <button name="action" value="demote" onclick="return confirm('Remove admin rights?')"
                        class="bg-yellow-500/10 hover:bg-yellow-500/25 text-yellow-400 border border-yellow-500/20 px-2.5 py-1 rounded-lg text-xs transition">Demote</button>
              <?php endif; ?>
            </form>

            <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
            <!-- Hard-delete account (irreversible; wipes both DBs + files) -->
            <form method="POST" onsubmit="return confirmDestroy('<?= htmlspecialchars($u['email'] ?? 'this user', ENT_QUOTES) ?>');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
              <input type="hidden" name="action"     value="delete">
              <button class="bg-red-600/15 hover:bg-red-600/40 text-red-300 border border-red-500/40 px-2.5 py-1 rounded-lg text-xs font-semibold transition">
                <i class="fa-solid fa-trash text-[10px] mr-1"></i>Delete
              </button>
            </form>
            <?php endif; ?>

          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
     </table>
   </div>
 </div>

<script>
// Confirmation for irreversible hard-delete. Asks twice so a misclick
// can't permanently wipe a user across both DBs and the filesystem.
function confirmDestroy(email) {
  if (!confirm('This will PERMANENTLY DELETE this account and all related\n' +
               'data across both databases, including generated site files.\n' +
               'This action CANNOT be undone.\n\n' +
               'Email: ' + email + '\n\n' +
               'Click OK to confirm the FIRST step.')) {
    return false;
  }
  return prompt('To confirm, type the email address shown above exactly:') === email;
}
</script>

<?php if ($pages > 1): ?>
<div class="flex gap-2">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
  <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>"
     class="w-9 h-9 flex items-center justify-center rounded-xl text-sm font-medium transition
            <?= $i === $page ? 'bg-emerald-500 text-slate-950 font-bold' : 'bg-white/5 text-slate-400 hover:bg-white/10' ?>">
    <?= $i ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
