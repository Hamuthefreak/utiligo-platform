<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$allowed_tables = [
    'users'         => ['Users (userdb)',          'user',     'utiligo_users',           ['id','email','full_name','plan','subscription_status','email_verified','is_admin','created_at']],
    'sites'         => ['Sites (platformdb)',       'platform', 'utiligo_generated_sites', ['id','user_id','business_name','subdomain','link_active','created_at']],
    'leads'         => ['Leads log (platformdb)',   'platform', 'utiligo_lead_searches',   ['id','user_id','query','results_count','created_at']],
    'migrations_u'  => ['Migrations (userdb)',      'user',     'utiligo_migrations',      ['id','migration','ran_at']],
    'migrations_p'  => ['Migrations (platformdb)',  'platform', 'utiligo_migrations',      ['id','migration','ran_at']],
];

$tab    = $_GET['t'] ?? 'users';
if (!array_key_exists($tab, $allowed_tables)) $tab = 'users';
[$tab_label, $db_key, $table, $cols] = $allowed_tables[$tab];

$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;

try {
    $pdo = $db_key === 'user' ? get_user_db() : get_platform_db();

    $exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
    if (!$exists) {
        $rows = []; $total = 0; $db_error = null;
    } else {
        $actual_cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $cols        = array_values(array_intersect($cols, $actual_cols));
        if (empty($cols)) $cols = array_slice($actual_cols, 0, 8);

        $select = implode(',', array_map(fn($c) => "`$c`", $cols));

        if ($search !== '') {
            $text_cols   = array_filter($actual_cols, fn($c) => in_array($c, $cols));
            $where_parts = array_map(fn($c) => "`$c` LIKE ?", $text_cols);
            $where  = 'WHERE ' . implode(' OR ', $where_parts);
            $params = array_fill(0, count($text_cols), '%' . $search . '%');
        } else {
            $where = ''; $params = [];
        }

        $offset = ($page - 1) * $perPage;
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $where");
        $count_stmt->execute($params);
        $total = (int)$count_stmt->fetchColumn();

        $data_stmt = $pdo->prepare("SELECT $select FROM `$table` $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
        $data_stmt->execute($params);
        $rows = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
        $db_error = null;
    }
} catch (Throwable $e) {
    $rows = []; $total = 0; $db_error = $e->getMessage();
    $cols = [];
}

$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

$pageTitle = 'Database — Admin — Utiligo';
$adminPage = 'database';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="mb-6 flex items-start justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5">Read-only live view</p>
    <h1 class="text-3xl font-bold tracking-tight">Database Viewer</h1>
  </div>
  <span class="text-xs text-slate-600 mt-2"><?= number_format($total) ?> rows in <code class="text-purple-400"><?= htmlspecialchars($table) ?></code></span>
</div>

<div class="flex flex-wrap gap-2 mb-5">
  <?php foreach ($allowed_tables as $key => [$lbl]): ?>
  <a href="?t=<?= $key ?>"
     class="px-4 py-1.5 rounded-xl text-xs font-semibold border transition <?= $tab === $key ? 'bg-purple-600 border-purple-500 text-white' : 'bg-white/5 border-white/10 text-slate-400 hover:text-white hover:bg-white/10' ?>">
    <?= htmlspecialchars($lbl) ?>
  </a>
  <?php endforeach; ?>
</div>

<form method="GET" class="flex gap-2 mb-5">
  <input type="hidden" name="t" value="<?= htmlspecialchars($tab) ?>">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Search visible columns…"
         class="flex-1 bg-slate-900/70 border border-white/10 focus:border-purple-500/50 text-white rounded-xl px-4 py-2.5 text-sm outline-none transition">
  <button type="submit" class="bg-purple-600 hover:bg-purple-500 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
    <i class="fa-solid fa-magnifying-glass mr-1"></i>Search
  </button>
  <?php if ($search): ?>
  <a href="?t=<?= htmlspecialchars($tab) ?>" class="bg-white/10 hover:bg-white/20 text-slate-300 px-4 py-2.5 rounded-xl text-sm transition">Clear</a>
  <?php endif; ?>
</form>

<?php if ($db_error): ?>
<div class="bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 text-sm mb-5">
  <i class="fa-solid fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($db_error) ?>
</div>
<?php elseif (empty($rows)): ?>
<div class="bg-white/[0.03] border border-white/5 rounded-2xl px-6 py-12 text-center text-slate-500 text-sm">
  <?= $search ? 'No results for “' . htmlspecialchars($search) . '”' : 'Table is empty or does not exist yet.' ?>
</div>
<?php else: ?>
<div class="bg-white/[0.03] border border-white/5 rounded-2xl overflow-hidden mb-5">
  <div class="overflow-x-auto">
    <table class="w-full text-xs">
      <thead>
        <tr class="border-b border-white/5">
          <?php foreach ($cols as $col): ?>
          <th class="px-4 py-3 text-left text-slate-400 font-semibold uppercase tracking-wider whitespace-nowrap">
            <?= htmlspecialchars($col) ?>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-white/[0.04]">
        <?php foreach ($rows as $row): ?>
        <tr class="hover:bg-white/[0.025] transition">
          <?php foreach ($cols as $col): ?>
          <?php
            $val = $row[$col] ?? null;
            if ($col === 'is_admin') {
                $cell = $val ? '<span class="text-purple-400 font-bold">YES</span>' : '<span class="text-slate-600">no</span>';
            } elseif ($col === 'link_active' || $col === 'email_verified') {
                $cell = $val ? '<span class="text-emerald-400">✓</span>' : '<span class="text-slate-600">–</span>';
            } elseif ($col === 'plan') {
                $colors = ['entrepreneur'=>'text-indigo-400','pro'=>'text-emerald-400','free'=>'text-slate-500'];
                $cell = '<span class="font-semibold ' . ($colors[$val] ?? 'text-slate-400') . '">' . htmlspecialchars((string)$val) . '</span>';
            } elseif ($col === 'subscription_status') {
                $colors = ['active'=>'text-emerald-400','cancelled'=>'text-yellow-400','banned'=>'text-red-400'];
                $cell = '<span class="' . ($colors[$val] ?? 'text-slate-400') . '">' . htmlspecialchars((string)$val) . '</span>';
            } elseif (is_null($val)) {
                $cell = '<span class="text-slate-700 italic">null</span>';
            } else {
                $s    = (string)$val;
                $cell = htmlspecialchars(strlen($s) > 60 ? substr($s, 0, 60) . '…' : $s);
            }
          ?>
          <td class="px-4 py-2.5 text-slate-300 whitespace-nowrap"><?= $cell ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between text-xs text-slate-500">
  <span>Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($total) ?> total rows</span>
  <div class="flex gap-1">
    <?php if ($page > 1): ?>
      <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&p=<?= $page-1 ?>"
         class="px-3 py-1.5 bg-white/5 hover:bg-white/10 rounded-lg transition">&#8592; Prev</a>
    <?php endif; ?>
    <?php
    $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++):
    ?>
      <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&p=<?= $i ?>"
         class="px-3 py-1.5 rounded-lg transition <?= $i === $page ? 'bg-purple-600 text-white' : 'bg-white/5 hover:bg-white/10' ?>">
        <?= $i ?>
      </a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&p=<?= $page+1 ?>"
         class="px-3 py-1.5 bg-white/5 hover:bg-white/10 rounded-lg transition">Next &#8594;</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
