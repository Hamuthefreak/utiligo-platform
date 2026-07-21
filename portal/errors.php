<?php
/**
 * portal/errors.php — Error log viewer
 * Accessible to any logged-in user.
 * Handles ?action=clear to truncate the log file.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/error_logger.php';

if (!is_logged_in()) { header('Location: /login.php'); exit; }

// ── Handle clear action ──────────────────────────────────────────────────────
$clearMsg = null;
if (($_POST['action'] ?? '') === 'clear' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $storageDir = dirname(ERROR_LOG_PATH);
    if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);
    if (@file_put_contents(ERROR_LOG_PATH, '') !== false) {
        $clearMsg = 'success';
    } else {
        $clearMsg = 'fail';
    }
}

// ── Check log writability ────────────────────────────────────────────────────
$storageDir   = dirname(ERROR_LOG_PATH);
$dirExists    = is_dir($storageDir);
$dirWritable  = $dirExists && is_writable($storageDir);
$logExists    = file_exists(ERROR_LOG_PATH);
$logWritable  = $logExists ? is_writable(ERROR_LOG_PATH) : $dirWritable;
$canLog       = $dirWritable && $logWritable;

// ── Load entries ─────────────────────────────────────────────────────────────
$limit   = min(500, max(10, (int)($_GET['limit'] ?? 100)));
$errors  = get_recent_errors($limit);
$total   = count($errors);
$logSize = $logExists ? filesize(ERROR_LOG_PATH) : 0;
$csrf    = function_exists('csrf_token') ? csrf_token() : '';

$pageTitle = 'Error Log';
require_once __DIR__ . '/../includes/portal_layout.php';
?>

<?php if ($clearMsg === 'success'): ?>
<div class="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm">
  <i class="fa-solid fa-circle-check"></i> Log cleared successfully.
</div>
<?php elseif ($clearMsg === 'fail'): ?>
<div class="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
  <i class="fa-solid fa-triangle-exclamation"></i> Could not clear log — check file permissions on <code><?= htmlspecialchars(ERROR_LOG_PATH) ?></code>.
</div>
<?php endif; ?>

<?php if (!$canLog): ?>
<div class="mb-4 px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-sm space-y-1">
  <p class="font-bold flex items-center gap-2"><i class="fa-solid fa-triangle-exclamation"></i> Logging is broken — errors will NOT be recorded!</p>
  <p class="text-amber-400/80">
    <?php if (!$dirExists): ?>
      <code><?= htmlspecialchars($storageDir) ?></code> directory does not exist. PHP needs to create it (check <code>open_basedir</code> / permissions).
    <?php elseif (!$dirWritable): ?>
      <code><?= htmlspecialchars($storageDir) ?></code> exists but is <strong>not writable</strong>. Run <code>chmod 775 storage</code> on the server.
    <?php elseif (!$logWritable): ?>
      <code><?= htmlspecialchars(ERROR_LOG_PATH) ?></code> exists but is <strong>not writable</strong>. Run <code>chmod 664 storage/error_log.txt</code>.
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
  <div>
    <h1 class="text-xl font-black text-white">Error Log</h1>
    <p class="text-xs text-slate-500 mt-0.5">
      <?= $total ?> most recent entries
      &middot; Log size: <span class="text-slate-400"><?= number_format($logSize / 1024, 1) ?> KB</span>
      &middot; Path: <span class="text-slate-600 font-mono text-[10px]"><?= htmlspecialchars(ERROR_LOG_PATH) ?></span>
    </p>
  </div>
  <div class="flex items-center gap-2 flex-wrap">
    <input id="errSearch" type="search" placeholder="Filter by context or message…"
      class="text-sm bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-white placeholder-slate-600 w-48 focus:outline-none focus:border-white/20">
    <select id="errLimit" onchange="window.location='?limit='+this.value"
      class="text-sm bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-slate-300 focus:outline-none">
      <?php foreach ([50,100,200,500] as $n): ?>
        <option value="<?= $n ?>" <?= $n === $limit ? 'selected' : '' ?>><?= $n ?> entries</option>
      <?php endforeach; ?>
    </select>
    <a href="/portal/errors.php?limit=<?= $limit ?>" class="text-xs text-slate-500 hover:text-white transition px-3 py-2 rounded-xl bg-white/5 border border-white/10">
      <i class="fa-solid fa-rotate-right mr-1"></i>Refresh
    </a>
    <?php if ($logExists && $logSize > 0): ?>
    <form method="POST" action="/portal/errors.php" onsubmit="return confirm('Clear all logged errors? This cannot be undone.')">
      <input type="hidden" name="action" value="clear">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" class="text-xs text-red-400 hover:text-red-300 transition px-3 py-2 rounded-xl bg-red-500/10 border border-red-500/20 hover:bg-red-500/15">
        <i class="fa-solid fa-trash mr-1"></i>Clear Log
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($errors)): ?>
<div class="glass rounded-2xl p-10 text-center">
  <i class="fa-solid fa-circle-check text-3xl text-emerald-400 block mb-3"></i>
  <p class="text-white font-semibold">No errors logged.</p>
  <p class="text-slate-500 text-sm mt-1">
    <?= $canLog ? "Log file is empty — that's a good sign!" : 'Logging is not working — see the warning above.' ?>
  </p>
</div>
<?php else: ?>

<div id="errList" class="space-y-2">
<?php foreach ($errors as $i => $e):
  $ctx     = htmlspecialchars($e['context']  ?? 'unknown');
  $msg     = htmlspecialchars($e['message']  ?? '');
  $time    = htmlspecialchars($e['time']     ?? '');
  $uri     = htmlspecialchars($e['request_uri'] ?? '');
  $method  = htmlspecialchars($e['method']   ?? '');
  $uid     = htmlspecialchars((string)($e['user_id'] ?? ''));
  $trace   = htmlspecialchars($e['trace']    ?? '');
  $extra   = !empty($e['extra']) ? htmlspecialchars(json_encode($e['extra'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) : '';
  $isFatal = str_contains(strtolower($ctx), 'fatal') || str_contains(strtolower($msg), 'fatal');
  $isWarn  = str_contains(strtolower($ctx), 'csrf')  || str_contains(strtolower($ctx), 'quota');
  $border  = $isFatal ? 'border-red-500/30'   : ($isWarn ? 'border-amber-500/20' : 'border-white/5');
  $badge   = $isFatal ? 'bg-red-500/15 text-red-400' : ($isWarn ? 'bg-amber-500/15 text-amber-400' : 'bg-white/5 text-slate-500');
?>
<div class="err-row glass rounded-2xl border <?= $border ?> overflow-hidden"
     data-search="<?= strtolower($ctx . ' ' . $msg) ?>">
  <button type="button" onclick="toggleErr(<?= $i ?>)"
    class="w-full flex items-start gap-3 px-4 py-3 text-left hover:bg-white/[.03] transition">
    <span class="shrink-0 mt-0.5 text-[10px] font-bold px-2 py-0.5 rounded <?= $badge ?>"><?= $ctx ?></span>
    <span class="flex-1 min-w-0">
      <span class="block text-sm text-white font-medium truncate"><?= $msg ?></span>
      <span class="block text-xs text-slate-600 mt-0.5">
        <?= $time ?>
        <?= $uri  ? ' &middot; ' . $method . ' ' . $uri : '' ?>
        <?= $uid  ? ' &middot; user #' . $uid : '' ?>
      </span>
    </span>
    <i id="err-chevron-<?= $i ?>" class="fa-solid fa-chevron-down text-slate-700 text-xs mt-1 shrink-0 transition-transform"></i>
  </button>
  <div id="err-detail-<?= $i ?>" class="hidden border-t border-white/5 px-4 py-3 space-y-2">
    <?php if ($trace): ?>
    <div>
      <p class="text-[10px] font-bold text-slate-600 uppercase tracking-widest mb-1">Stack Trace</p>
      <pre class="text-[11px] text-slate-400 bg-black/30 rounded-xl p-3 overflow-x-auto whitespace-pre-wrap break-all"><?= $trace ?></pre>
    </div>
    <?php endif; ?>
    <?php if ($extra): ?>
    <div>
      <p class="text-[10px] font-bold text-slate-600 uppercase tracking-widest mb-1">Extra Context</p>
      <pre class="text-[11px] text-slate-400 bg-black/30 rounded-xl p-3 overflow-x-auto whitespace-pre-wrap break-all"><?= $extra ?></pre>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<p class="text-xs text-slate-700 text-center mt-4">Showing <?= $total ?> of the most recent entries (newest first).</p>

<?php endif; ?>

<script>
function toggleErr(i) {
  const d = document.getElementById('err-detail-' + i);
  const c = document.getElementById('err-chevron-' + i);
  d.classList.toggle('hidden');
  c.style.transform = d.classList.contains('hidden') ? '' : 'rotate(180deg)';
}
document.getElementById('errSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.err-row').forEach(row => {
    row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
  });
});
</script>

<?php require_once __DIR__ . '/../includes/portal_layout_end.php'; ?>
