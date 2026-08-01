<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];
$udb   = get_user_db();

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify('users', $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'CSRF failure on users action');
        die('Invalid CSRF token.');
    }
    $action   = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);
    if ($targetId === (int)$admin['id'] && in_array($action, ['ban','demote'])) {
        $error = 'You cannot ban or demote your own account.';
    } elseif ($action === 'set_plan' && $targetId > 0) {
        $plan = in_array($_POST['plan'] ?? '', ['free','pro','entrepreneur']) ? $_POST['plan'] : 'free';
        $udb->prepare('UPDATE utiligo_users SET plan=? WHERE id=?')->execute([$plan, $targetId]);
        _admin_log('INFO', "Set plan={$plan} for user_id={$targetId}");
        $success = "Plan updated to <strong>" . htmlspecialchars($plan) . "</strong>.";
    } elseif ($action === 'ban' && $targetId > 0) {
        $udb->prepare("UPDATE utiligo_users SET subscription_status='banned' WHERE id=?")->execute([$targetId]);
        _admin_log('WARN', "Banned user_id={$targetId}");
        $success = 'User banned.';
    } elseif ($action === 'unban' && $targetId > 0) {
        $udb->prepare("UPDATE utiligo_users SET subscription_status='active' WHERE id=?")->execute([$targetId]);
        _admin_log('INFO', "Unbanned user_id={$targetId}");
        $success = 'User unbanned.';
    } elseif ($action === 'promote_admin' && $targetId > 0) {
        $udb->prepare('UPDATE utiligo_users SET is_admin=1 WHERE id=?')->execute([$targetId]);
        _admin_log('WARN', "Promoted user_id={$targetId} to admin");
        $success = 'User promoted to admin.';
    } elseif ($action === 'demote' && $targetId > 0) {
        $udb->prepare('UPDATE utiligo_users SET is_admin=0 WHERE id=?')->execute([$targetId]);
        _admin_log('WARN', "Demoted user_id={$targetId}");
        $success = 'Admin rights removed.';
    }
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$data    = admin_get_all_users($page, $perPage, $search);
$users   = $data['users'];
$total   = $data['total'];
$pages   = max(1, (int)ceil($total / $perPage));

$pageTitle = 'Users — Admin — Utiligo';
$adminPage = 'users';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="mb-8">
  <h1 class="text-3xl font-bold tracking-tight">Users <span class="text-slate-500 text-lg font-normal">(<?= number_format($total) ?>)</span></h1>
</div>

<?php if (!empty($success)): ?>
  <div class="mb-6 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 px-4 py-3 rounded-xl text-sm"><?= $success ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="mb-6 bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl text-sm"><?= htmlspecialchars($error) ?></div>
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
      <tr class="hover:bg-white/[.02] transition">
        <td class="px-5 py-3 font-medium text-white">
          <?= htmlspecialchars($u['full_name']) ?>
          <?php if (!empty($u['is_admin'])): ?>
            <span class="ml-1 text-[10px] bg-purple-500/20 text-purple-300 border border-purple-500/30 px-2 py-0.5 rounded-full font-semibold">ADMIN</span>
          <?php endif; ?>
        </td>
        <td class="px-5 py-3 text-slate-400"><?= htmlspecialchars($u['email']) ?></td>
        <td class="px-5 py-3">
          <?php if ($u['plan']==='entrepreneur'): ?>
            <span class="text-[10px] bg-amber-500/15 text-amber-400 border border-amber-500/30 px-2 py-0.5 rounded-full font-bold">&#x1F680; ENT</span>
          <?php elseif ($u['plan']==='pro'): ?>
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
          <?php $csrf = admin_csrf_token('users'); ?>
          <div class="flex flex-wrap gap-1.5 items-center">

            <?php
            // ── Plan ladder ───────────────────────────────────────────────────
            // Each direction is its own mini-form so buttons stay independent.
            $plan = $u['plan'] ?? 'free';
            ?>

            <?php if ($plan === 'free'): ?>
              <!-- FREE → PRO -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="pro">
                <button onclick="return confirm('Upgrade to Pro?')"
                        class="bg-emerald-500/10 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-circle-up text-[10px] mr-1"></i>Pro
                </button>
              </form>

            <?php elseif ($plan === 'pro'): ?>
              <!-- PRO → FREE (downgrade) -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="free">
                <button onclick="return confirm('Downgrade to Free?')"
                        class="bg-slate-500/10 hover:bg-slate-500/25 text-slate-400 border border-slate-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-circle-down text-[10px] mr-1"></i>Free
                </button>
              </form>
              <!-- PRO → ENT (upgrade) -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="entrepreneur">
                <button onclick="return confirm('Upgrade to Entrepreneur?')"
                        class="bg-amber-500/10 hover:bg-amber-500/25 text-amber-400 border border-amber-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-rocket text-[10px] mr-1"></i>ENT
                </button>
              </form>

            <?php elseif ($plan === 'entrepreneur'): ?>
              <!-- ENT → PRO (downgrade) -->
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="action"     value="set_plan">
                <input type="hidden" name="plan"       value="pro">
                <button onclick="return confirm('Downgrade to Pro?')"
                        class="bg-slate-500/10 hover:bg-slate-500/25 text-slate-400 border border-slate-500/20 px-2.5 py-1 rounded-lg text-xs transition">
                  <i class="fa-solid fa-circle-down text-[10px] mr-1"></i>Pro
                </button>
              </form>
            <?php endif; ?>

            <?php // ── Ban / Unban ──────────────────────────────────────────── ?>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
              <?php if (($u['subscription_status'] ?? '') === 'banned'): ?>
                <button name="action" value="unban"
                        class="bg-green-500/10 hover:bg-green-500/25 text-green-400 border border-green-500/20 px-2.5 py-1 rounded-lg text-xs transition">Unban</button>
              <?php elseif ((int)$u['id'] !== (int)$admin['id']): ?>
                <button name="action" value="ban" onclick="return confirm('Ban this user?')"
                        class="bg-red-500/10 hover:bg-red-500/25 text-red-400 border border-red-500/20 px-2.5 py-1 rounded-lg text-xs transition">Ban</button>
              <?php endif; ?>
            </form>

            <?php // ── Promote / Demote admin ───────────────────────────────── ?>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
              <?php if (empty($u['is_admin'])): ?>
                <button name="action" value="promote_admin" onclick="return confirm('Promote to admin?')"
                        class="bg-purple-500/10 hover:bg-purple-500/25 text-purple-400 border border-purple-500/20 px-2.5 py-1 rounded-lg text-xs transition">+Admin</button>
              <?php elseif ((int)$u['id'] !== (int)$admin['id']): ?>
                <button name="action" value="demote" onclick="return confirm('Remove admin rights?')"
                        class="bg-yellow-500/10 hover:bg-yellow-500/25 text-yellow-400 border border-yellow-500/20 px-2.5 py-1 rounded-lg text-xs transition">Demote</button>
              <?php endif; ?>
            </form>

          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pagination -->
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
