<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];

// ── Table registry ────────────────────────────────────────────────────────────
$allowed_tables = [
    'users'        => ['Users',           'user',     'utiligo_users',           ['id','email','full_name','plan','subscription_status','email_verified','is_admin','created_at']],
    'sites'        => ['Sites',           'platform', 'utiligo_generated_sites', ['id','user_id','business_name','subdomain','link_active','created_at']],
    'leads'        => ['Lead searches',   'platform', 'utiligo_lead_searches',   ['id','user_id','query','results_count','created_at']],
    'migrations_u' => ['Migrations (UDB)','user',     'utiligo_migrations',      ['id','migration','ran_at']],
    'migrations_p' => ['Migrations (PDB)','platform', 'utiligo_migrations',      ['id','migration','ran_at']],
];

// Columns that must never be edited/written
$readonly_cols = ['id','created_at','updated_at','password_hash','password','remember_token','token','two_fa_code'];

$tab = $_GET['t'] ?? 'users';
if (!array_key_exists($tab, $allowed_tables)) $tab = 'users';
[$tab_label, $db_key, $table, $display_cols] = $allowed_tables[$tab];

$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = in_array((int)($_GET['pp'] ?? 25), [10,25,50,100]) ? (int)($_GET['pp'] ?? 25) : 25;
$sortCol = $_GET['s'] ?? 'id';
$sortDir = strtoupper($_GET['d'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$flash_ok  = '';
$flash_err = '';

$pdo = $db_key === 'user' ? get_user_db() : get_platform_db();

// ── Helpers ───────────────────────────────────────────────────────────────────
function db_csrf(): string {
    if (empty($_SESSION['db_csrf'])) $_SESSION['db_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['db_csrf'];
}
function db_csrf_ok(): bool {
    return isset($_POST['_csrf']) && hash_equals($_SESSION['db_csrf'] ?? '', $_POST['_csrf']);
}

// ── Mutations (POST) ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_csrf_ok()) {
    $action   = $_POST['_action'] ?? '';
    $post_tab = $_POST['_tab']    ?? $tab;
    if (!array_key_exists($post_tab, $allowed_tables)) { $flash_err = 'Invalid table.'; goto render; }
    [$_unused, $post_db_key, $post_table] = $allowed_tables[$post_tab];
    $post_pdo = $post_db_key === 'user' ? get_user_db() : get_platform_db();
    $actual   = $post_pdo->query("DESCRIBE `$post_table`")->fetchAll(PDO::FETCH_ASSOC);
    $col_map  = [];
    foreach ($actual as $r) $col_map[$r['Field']] = $r['Type'];

    if ($action === 'edit') {
        $row_id = (int)($_POST['_id'] ?? 0);
        $col    = $_POST['_col'] ?? '';
        $val    = $_POST['_val'] ?? '';
        if (!$row_id || !$col || !array_key_exists($col, $col_map) || in_array($col, $readonly_cols)) {
            $flash_err = 'Invalid edit request.';
        } else {
            try {
                $post_pdo->prepare("UPDATE `$post_table` SET `$col`=? WHERE id=?")->execute([$val, $row_id]);
                $flash_ok = "Row #$row_id &rarr; <code>$col</code> updated.";
            } catch (Throwable $e) { $flash_err = $e->getMessage(); }
        }

    } elseif ($action === 'delete') {
        $row_id = (int)($_POST['_id'] ?? 0);
        if (!$row_id) { $flash_err = 'Invalid ID.'; }
        else {
            try {
                $post_pdo->prepare("DELETE FROM `$post_table` WHERE id=?")->execute([$row_id]);
                $flash_ok = "Row #$row_id deleted.";
            } catch (Throwable $e) { $flash_err = $e->getMessage(); }
        }

    } elseif ($action === 'add') {
        $data = [];
        foreach ($col_map as $col => $type) {
            if (in_array($col, $readonly_cols)) continue;
            if (!isset($_POST['new'][$col])) continue;
            $v = $_POST['new'][$col];
            if ($col === 'password_hash' && $v !== '') $v = password_hash($v, PASSWORD_BCRYPT);
            $data[$col] = $v === '' ? null : $v;
        }
        if (empty($data)) { $flash_err = 'No data provided.'; }
        else {
            $cols_sql     = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
            $placeholders = implode(',', array_fill(0, count($data), '?'));
            try {
                $post_pdo->prepare("INSERT INTO `$post_table` ($cols_sql) VALUES ($placeholders)")->execute(array_values($data));
                $flash_ok = 'New row inserted (ID ' . $post_pdo->lastInsertId() . ').';
            } catch (Throwable $e) { $flash_err = $e->getMessage(); }
        }
    }
    $_SESSION['db_flash_ok']  = $flash_ok;
    $_SESSION['db_flash_err'] = $flash_err;
    header("Location: /admin/database.php?t=$post_tab&p=$page&pp=$perPage&q=" . urlencode($search) . "&s=$sortCol&d=$sortDir");
    exit;
}

// Pick up flash from redirect
if (!empty($_SESSION['db_flash_ok']))  { $flash_ok  = $_SESSION['db_flash_ok'];  unset($_SESSION['db_flash_ok']); }
if (!empty($_SESSION['db_flash_err'])) { $flash_err = $_SESSION['db_flash_err']; unset($_SESSION['db_flash_err']); }

render:
// ── Read data ─────────────────────────────────────────────────────────────────
$rows = []; $total = 0; $db_error = null; $all_cols = []; $col_meta = [];
try {
    $exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
    if ($exists) {
        $described = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($described as $r) {
            $all_cols[]            = $r['Field'];
            $col_meta[$r['Field']] = $r;
        }
        $cols = array_values(array_intersect($display_cols, $all_cols));
        if (empty($cols)) $cols = array_slice($all_cols, 0, 10);

        if (!in_array($sortCol, $all_cols)) $sortCol = 'id';

        $select = implode(',', array_map(fn($c) => "`$c`", $cols));

        $where = ''; $params = [];
        if ($search !== '') {
            $parts  = array_map(fn($c) => "`$c` LIKE ?", $cols);
            $where  = 'WHERE ' . implode(' OR ', $parts);
            $params = array_fill(0, count($cols), '%' . $search . '%');
        }

        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $where");
        $count_stmt->execute($params);
        $total = (int)$count_stmt->fetchColumn();

        $offset    = ($page - 1) * $perPage;
        $data_stmt = $pdo->prepare("SELECT $select FROM `$table` $where ORDER BY `$sortCol` $sortDir LIMIT $perPage OFFSET $offset");
        $data_stmt->execute($params);
        $rows = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $db_error = $e->getMessage(); $cols = $display_cols; }

$totalPages = max(1, (int)ceil($total / $perPage));

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($rows)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $table . '_export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $cols);
    foreach ($rows as $row) fputcsv($out, array_map(fn($c) => $row[$c] ?? '', $cols));
    fclose($out);
    exit;
}

$csrf = db_csrf();
$pageTitle = 'Database — Admin — Utiligo';
$adminPage = 'database';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<?php
$editable_cols = array_values(array_filter($all_cols ?? [], fn($c) => !in_array($c, $readonly_cols)));
$add_cols      = $editable_cols;
?>

<!-- Page header -->
<div class="mb-6 flex items-start justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5">Live read/write view</p>
    <h1 class="text-3xl font-bold tracking-tight">Database Manager</h1>
  </div>
  <div class="flex items-center gap-2 mt-1 flex-wrap">
    <span class="text-xs text-slate-500 bg-white/5 border border-white/10 rounded-xl px-3 py-1.5">
      <i class="fa-solid fa-table-cells text-purple-400 mr-1"></i>
      <code class="text-purple-300"><?= htmlspecialchars($table) ?></code>
      &mdash; <?= number_format($total) ?> rows
    </span>
    <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&s=<?= $sortCol ?>&d=<?= $sortDir ?>&pp=<?= $perPage ?>&export=csv"
       class="inline-flex items-center gap-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-slate-300 px-3 py-1.5 rounded-xl text-xs font-semibold transition">
      <i class="fa-solid fa-file-csv text-emerald-400"></i> Export CSV
    </a>
    <?php if (!empty($add_cols)): ?>
    <button onclick="openAddModal()"
            class="inline-flex items-center gap-1.5 bg-purple-600 hover:bg-purple-500 text-white px-3 py-1.5 rounded-xl text-xs font-semibold transition">
      <i class="fa-solid fa-plus"></i> Add Row
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Flash messages -->
<?php if ($flash_ok): ?>
<div class="flex items-center gap-3 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-2xl px-5 py-3 mb-5 text-sm">
  <i class="fa-solid fa-circle-check shrink-0"></i> <?= $flash_ok ?>
</div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-3 mb-5 text-sm">
  <i class="fa-solid fa-triangle-exclamation shrink-0"></i> <?= htmlspecialchars($flash_err) ?>
</div>
<?php endif; ?>

<!-- Table tabs -->
<div class="flex flex-wrap gap-2 mb-5">
  <?php foreach ($allowed_tables as $key => [$lbl]): ?>
  <a href="?t=<?= $key ?>&pp=<?= $perPage ?>"
     class="px-4 py-1.5 rounded-xl text-xs font-semibold border transition <?= $tab === $key ? 'bg-purple-600 border-purple-500 text-white' : 'bg-white/5 border-white/10 text-slate-400 hover:text-white hover:bg-white/10' ?>">
    <?= htmlspecialchars($lbl) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Search + per-page -->
<div class="flex flex-wrap gap-2 mb-5">
  <form method="GET" class="flex gap-2 flex-1 min-w-0">
    <input type="hidden" name="t"  value="<?= htmlspecialchars($tab) ?>">
    <input type="hidden" name="pp" value="<?= $perPage ?>">
    <input type="hidden" name="s"  value="<?= htmlspecialchars($sortCol) ?>">
    <input type="hidden" name="d"  value="<?= $sortDir ?>">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
           placeholder="Search visible columns…"
           class="flex-1 bg-slate-900/70 border border-white/10 focus:border-purple-500/50 text-white rounded-xl px-4 py-2.5 text-sm outline-none transition">
    <button class="bg-purple-600 hover:bg-purple-500 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
      <i class="fa-solid fa-magnifying-glass"></i>
    </button>
    <?php if ($search): ?>
    <a href="?t=<?= htmlspecialchars($tab) ?>&pp=<?= $perPage ?>" class="bg-white/10 hover:bg-white/20 text-slate-300 px-4 py-2.5 rounded-xl text-sm transition">Clear</a>
    <?php endif; ?>
  </form>
  <form method="GET">
    <input type="hidden" name="t" value="<?= htmlspecialchars($tab) ?>">
    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="s" value="<?= htmlspecialchars($sortCol) ?>">
    <input type="hidden" name="d" value="<?= $sortDir ?>">
    <select name="pp" onchange="this.form.submit()"
            class="bg-slate-900/70 border border-white/10 text-slate-300 rounded-xl px-3 py-2.5 text-sm outline-none cursor-pointer">
      <?php foreach ([10,25,50,100] as $n): ?>
        <option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?> / page</option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($db_error): ?>
<div class="bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 text-sm mb-5">
  <i class="fa-solid fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($db_error) ?>
</div>
<?php elseif (empty($rows)): ?>
<div class="bg-white/[0.03] border border-white/5 rounded-2xl px-6 py-12 text-center text-slate-500 text-sm">
  <?= $search ? 'No results for "'.htmlspecialchars($search).'".' : 'Table is empty or does not exist yet.' ?>
</div>
<?php else: ?>

<!-- Table -->
<div class="bg-white/[0.03] border border-white/5 rounded-2xl overflow-hidden mb-5">
  <div class="overflow-x-auto">
    <table class="w-full text-xs" id="dbTable">
      <thead>
        <tr class="border-b border-white/5">
          <?php foreach ($cols as $col):
            $nextDir  = ($sortCol === $col && $sortDir === 'ASC') ? 'DESC' : 'ASC';
            $isSorted = $sortCol === $col;
          ?>
          <th class="px-4 py-3 text-left whitespace-nowrap">
            <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&pp=<?= $perPage ?>&p=1&s=<?= urlencode($col) ?>&d=<?= $nextDir ?>"
               class="inline-flex items-center gap-1 text-slate-400 font-semibold uppercase tracking-wider hover:text-white transition">
              <?= htmlspecialchars($col) ?>
              <?php if ($isSorted): ?>
                <i class="fa-solid fa-caret-<?= $sortDir==='ASC'?'up':'down' ?> text-purple-400"></i>
              <?php else: ?>
                <i class="fa-solid fa-sort text-slate-700"></i>
              <?php endif; ?>
            </a>
          </th>
          <?php endforeach; ?>
          <th class="px-4 py-3 text-left text-slate-400 font-semibold uppercase tracking-wider whitespace-nowrap">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-white/[0.04]">
        <?php foreach ($rows as $row): ?>
        <tr class="hover:bg-white/[0.025] transition group" data-id="<?= (int)($row['id'] ?? 0) ?>">
          <?php foreach ($cols as $col):
            $val   = $row[$col] ?? null;
            $is_ro = in_array($col, $readonly_cols);

            if ($col === 'is_admin') {
                $display = $val ? '<span class="text-purple-400 font-bold">YES</span>' : '<span class="text-slate-600">no</span>';
            } elseif (in_array($col, ['link_active','email_verified'])) {
                $display = $val ? '<span class="text-emerald-400 font-bold">✓</span>' : '<span class="text-slate-600">–</span>';
            } elseif ($col === 'plan') {
                $pc = ['entrepreneur'=>'text-indigo-400','pro'=>'text-emerald-400','free'=>'text-slate-500'];
                $display = '<span class="font-semibold '.($pc[$val]??'text-slate-400').'">'.htmlspecialchars((string)$val).'</span>';
            } elseif ($col === 'subscription_status') {
                $sc = ['active'=>'text-emerald-400','cancelled'=>'text-yellow-400','banned'=>'text-red-400'];
                $display = '<span class="'.($sc[$val]??'text-slate-400').'">'.htmlspecialchars((string)$val).'</span>';
            } elseif (is_null($val)) {
                $display = '<span class="text-slate-700 italic">null</span>';
            } else {
                $s = (string)$val;
                $display = htmlspecialchars(strlen($s) > 55 ? substr($s,0,55).'…' : $s);
            }
          ?>
          <td class="px-4 py-2.5 text-slate-300 whitespace-nowrap <?= !$is_ro ? 'cursor-pointer hover:bg-purple-500/10 hover:text-white' : '' ?>"
              <?php if (!$is_ro && ($row['id'] ?? 0)): ?>
                onclick="inlineEdit(this, <?= (int)$row['id'] ?>, '<?= htmlspecialchars($col, ENT_QUOTES) ?>', <?= json_encode((string)($val ?? '')) ?>)"
                title="Click to edit"
              <?php endif; ?>>
            <?= $display ?>
          </td>
          <?php endforeach; ?>
          <td class="px-4 py-2.5 whitespace-nowrap">
            <?php if ($row['id'] ?? 0): ?>
            <button onclick="confirmDelete(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($tab, ENT_QUOTES) ?>')"
                    class="text-red-500 hover:text-red-400 transition text-xs px-2 py-1 rounded-lg hover:bg-red-500/10">
              <i class="fa-solid fa-trash-can"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between text-xs text-slate-500 mb-6">
  <span>Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($total) ?> rows</span>
  <div class="flex gap-1">
    <?php if ($page > 1): ?>
      <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&p=<?= $page-1 ?>&pp=<?= $perPage ?>&s=<?= urlencode($sortCol) ?>&d=<?= $sortDir ?>"
         class="px-3 py-1.5 bg-white/5 hover:bg-white/10 rounded-lg transition">&#8592;</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
      <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&p=<?= $i ?>&pp=<?= $perPage ?>&s=<?= urlencode($sortCol) ?>&d=<?= $sortDir ?>"
         class="px-3 py-1.5 rounded-lg transition <?= $i===$page?'bg-purple-600 text-white':'bg-white/5 hover:bg-white/10' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a href="?t=<?= $tab ?>&q=<?= urlencode($search) ?>&p=<?= $page+1 ?>&pp=<?= $perPage ?>&s=<?= urlencode($sortCol) ?>&d=<?= $sortDir ?>"
         class="px-3 py-1.5 bg-white/5 hover:bg-white/10 rounded-lg transition">&#8594;</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- DELETE MODAL -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/70" onclick="closeDeleteModal()"></div>
  <div class="relative bg-slate-900 border border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl">
    <h3 class="text-lg font-bold mb-2">Delete row?</h3>
    <p class="text-slate-400 text-sm mb-5">Row <strong id="deleteRowId" class="text-white"></strong> will be permanently deleted.</p>
    <form method="POST">
      <input type="hidden" name="_csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="_action" value="delete">
      <input type="hidden" name="_tab"    id="deleteTabInput">
      <input type="hidden" name="_id"     id="deleteIdInput">
      <div class="flex gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 bg-white/10 hover:bg-white/20 text-white py-2.5 rounded-xl font-semibold text-sm transition">Cancel</button>
        <button type="submit"
                class="flex-1 bg-red-600 hover:bg-red-500 text-white py-2.5 rounded-xl font-bold text-sm transition">
          <i class="fa-solid fa-trash-can mr-1"></i> Delete
        </button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/70" onclick="closeEditModal()"></div>
  <div class="relative bg-slate-900 border border-white/10 rounded-2xl p-6 w-full max-w-md shadow-2xl">
    <h3 class="text-lg font-bold mb-1">Edit field</h3>
    <p class="text-slate-400 text-xs mb-4">
      Row #<span id="editRowId" class="text-white font-semibold"></span>
      &rarr; <code id="editColName" class="text-purple-300 bg-purple-500/10 px-1.5 py-0.5 rounded"></code>
    </p>
    <form method="POST">
      <input type="hidden" name="_csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="_action" value="edit">
      <input type="hidden" name="_tab"    value="<?= htmlspecialchars($tab) ?>">
      <input type="hidden" name="_id"     id="editIdInput">
      <input type="hidden" name="_col"    id="editColInput">
      <textarea name="_val" id="editValInput" rows="3"
                class="w-full bg-slate-800 border border-white/10 focus:border-purple-500/50 text-white rounded-xl px-4 py-3 text-sm outline-none transition resize-y mb-4"></textarea>
      <div class="flex gap-3">
        <button type="button" onclick="closeEditModal()"
                class="flex-1 bg-white/10 hover:bg-white/20 text-white py-2.5 rounded-xl font-semibold text-sm transition">Cancel</button>
        <button type="submit"
                class="flex-1 bg-purple-600 hover:bg-purple-500 text-white py-2.5 rounded-xl font-bold text-sm transition">
          <i class="fa-solid fa-floppy-disk mr-1"></i> Save
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ADD ROW MODAL -->
<div id="addModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/70" onclick="closeAddModal()"></div>
  <div class="relative bg-slate-900 border border-white/10 rounded-2xl p-6 w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
    <h3 class="text-lg font-bold mb-1">Add row</h3>
    <p class="text-slate-400 text-xs mb-5">Insert into <code class="text-purple-300"><?= htmlspecialchars($table) ?></code>. Leave blank for NULL.</p>
    <form method="POST">
      <input type="hidden" name="_csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="_action" value="add">
      <input type="hidden" name="_tab"    value="<?= htmlspecialchars($tab) ?>">
      <div class="space-y-3 mb-5">
        <?php foreach ($add_cols as $ac): ?>
        <div>
          <label class="block text-xs font-semibold text-slate-400 mb-1"><?= htmlspecialchars($ac) ?></label>
          <?php $mt = strtolower($col_meta[$ac]['Type'] ?? '');
                if (str_contains($mt,'text') || str_contains($mt,'json')): ?>
            <textarea name="new[<?= htmlspecialchars($ac) ?>]" rows="2"
                      class="w-full bg-slate-800 border border-white/10 focus:border-purple-500/50 text-white rounded-xl px-3 py-2 text-sm outline-none transition resize-y"></textarea>
          <?php elseif ($ac === 'plan'): ?>
            <select name="new[<?= htmlspecialchars($ac) ?>]"
                    class="w-full bg-slate-800 border border-white/10 text-white rounded-xl px-3 py-2 text-sm outline-none">
              <option value="free">free</option>
              <option value="pro">pro</option>
              <option value="entrepreneur">entrepreneur</option>
            </select>
          <?php elseif (in_array($ac, ['email_verified','is_admin','link_active'])): ?>
            <select name="new[<?= htmlspecialchars($ac) ?>]"
                    class="w-full bg-slate-800 border border-white/10 text-white rounded-xl px-3 py-2 text-sm outline-none">
              <option value="0">0 — No</option>
              <option value="1">1 — Yes</option>
            </select>
          <?php elseif ($ac === 'subscription_status'): ?>
            <select name="new[<?= htmlspecialchars($ac) ?>]"
                    class="w-full bg-slate-800 border border-white/10 text-white rounded-xl px-3 py-2 text-sm outline-none">
              <option value="active">active</option>
              <option value="cancelled">cancelled</option>
              <option value="banned">banned</option>
            </select>
          <?php else: ?>
            <input type="text" name="new[<?= htmlspecialchars($ac) ?>]"
                   class="w-full bg-slate-800 border border-white/10 focus:border-purple-500/50 text-white rounded-xl px-3 py-2 text-sm outline-none transition">
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="closeAddModal()"
                class="flex-1 bg-white/10 hover:bg-white/20 text-white py-2.5 rounded-xl font-semibold text-sm transition">Cancel</button>
        <button type="submit"
                class="flex-1 bg-purple-600 hover:bg-purple-500 text-white py-2.5 rounded-xl font-bold text-sm transition">
          <i class="fa-solid fa-plus mr-1"></i> Insert Row
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function confirmDelete(id, tab) {
  document.getElementById('deleteRowId').textContent = '#' + id;
  document.getElementById('deleteIdInput').value     = id;
  document.getElementById('deleteTabInput').value    = tab;
  document.getElementById('deleteModal').classList.remove('hidden');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.add('hidden'); }

function inlineEdit(cell, id, col, currentVal) {
  document.getElementById('editRowId').textContent   = id;
  document.getElementById('editColName').textContent = col;
  document.getElementById('editIdInput').value       = id;
  document.getElementById('editColInput').value      = col;
  document.getElementById('editValInput').value      = currentVal;
  document.getElementById('editModal').classList.remove('hidden');
  setTimeout(() => document.getElementById('editValInput').focus(), 50);
}
function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }

function openAddModal()  { document.getElementById('addModal').classList.remove('hidden'); }
function closeAddModal() { document.getElementById('addModal').classList.add('hidden'); }

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeDeleteModal(); closeEditModal(); closeAddModal(); }
});
</script>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
