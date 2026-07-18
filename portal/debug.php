<?php
/**
 * portal/debug.php — Developer debug panel.
 * Protected: only accessible when DEBUG_MODE is true in config.php
 * AND the logged-in user is an admin (admin_flag = 1 in utiligo_users).
 * Shows: debug toggle, recent error log, lead cache browser, system checks.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

require_login();
$user = current_user();

// ── Access control ────────────────────────────────────────────────────────────
$is_admin  = !empty($user['admin_flag']) && (int)$user['admin_flag'] === 1;
$debug_on  = defined('DEBUG_MODE') && DEBUG_MODE === true;

if (!$debug_on || !$is_admin) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:monospace;background:#0f172a;color:#f87171;padding:2rem">' .
        '<h2>403 — Debug panel is disabled.</h2>' .
        '<p>To enable: set <code>define(\'DEBUG_MODE\', true);</code> in config.php AND set <code>admin_flag = 1</code> on your user row.</p>' .
        '<a href="/portal/index.php" style="color:#34d399">← Back to dashboard</a></body></html>');
}

// ── Handle actions ────────────────────────────────────────────────────────────
$action  = $_POST['action'] ?? '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $message = '<span class="text-red-400">CSRF check failed.</span>';
    } elseif ($action === 'clear_error_log') {
        @file_put_contents(ERROR_LOG_PATH, '');
        $message = '<span class="text-emerald-400">Error log cleared.</span>';
    } elseif ($action === 'clear_lead_cache') {
        try {
            $pdo = get_platform_db();
            $pdo->exec('DELETE FROM lead_cache');
            $message = '<span class="text-emerald-400">Lead cache cleared (' . $pdo->query('SELECT ROW_COUNT()') . ' rows deleted).</span>';
        } catch (\Throwable $e) {
            $message = '<span class="text-red-400">Cache clear failed: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }
    } elseif ($action === 'test_places_api') {
        $apiKey = GOOGLE_PLACES_API_KEY;
        if (empty($apiKey) || $apiKey === 'YOUR_GOOGLE_PLACES_API_KEY') {
            $message = '<span class="text-red-400">GOOGLE_PLACES_API_KEY is not set in config.php!</span>';
        } else {
            $url  = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=plumber+in+Montreal&key=' . urlencode($apiKey);
            $ctx  = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                $message = '<span class="text-red-400">HTTP request to Google Places failed. Check server outbound access.</span>';
            } else {
                $data   = json_decode($resp, true);
                $status = $data['status'] ?? 'UNKNOWN';
                $color  = $status === 'OK' ? 'text-emerald-400' : 'text-red-400';
                $message = '<span class="' . $color . '">Google Places API status: <strong>' . htmlspecialchars($status) . '</strong>. '
                         . ($status === 'OK' ? count($data['results'] ?? []) . ' results returned.' : ($data['error_message'] ?? '')) . '</span>';
            }
        }
    } elseif ($action === 'test_db') {
        try {
            $pdo   = get_platform_db();
            $count = $pdo->query('SELECT COUNT(*) FROM lead_cache')->fetchColumn();
            $message = '<span class="text-emerald-400">Platform DB connected. lead_cache has <strong>' . (int)$count . '</strong> entries.</span>';
        } catch (\Throwable $e) {
            $message = '<span class="text-red-400">Platform DB error: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$errors       = get_recent_errors(100);
$cache_rows   = [];
$cache_total  = 0;
try {
    $pdo         = get_platform_db();
    $cache_rows  = $pdo->query('SELECT cache_key, created_at, LENGTH(leads_json) as json_bytes, (LENGTH(leads_json) - LENGTH(REPLACE(leads_json, \'business_name\', \'\'))) DIV LENGTH(\'business_name\') as approx_count FROM lead_cache ORDER BY created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $cache_total = (int)$pdo->query('SELECT COUNT(*) FROM lead_cache')->fetchColumn();
} catch (\Throwable $e) { /* table may not exist yet */ }

// ── System checks ─────────────────────────────────────────────────────────────
$checks = [
    'PHP version >= 8.0'       => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO MySQL extension'       => extension_loaded('pdo_mysql'),
    'JSON extension'            => extension_loaded('json'),
    'allow_url_fopen (for Places API)' => ini_get('allow_url_fopen') == '1',
    'storage/ writable'         => is_writable(__DIR__ . '/../storage'),
    'GOOGLE_PLACES_API_KEY set' => defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== 'YOUR_GOOGLE_PLACES_API_KEY' && GOOGLE_PLACES_API_KEY !== '',
    'DB credentials set'        => defined('DB_USER') && DB_USER !== 'CHANGE_ME',
    'DEBUG_MODE is ON'          => defined('DEBUG_MODE') && DEBUG_MODE === true,
    'APP_ENV'                   => defined('APP_ENV') ? APP_ENV : 'undefined',
];

$pageTitle = 'Debug Panel — Utiligo';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-5xl mx-auto px-6 py-10">
  <div class="flex items-center gap-3 mb-8">
    <a href="/portal/index.php" class="text-xs text-slate-400 hover:text-white"><i class="fa-solid fa-arrow-left mr-1"></i>Dashboard</a>
    <span class="text-slate-600">/</span>
    <h1 class="text-2xl font-bold flex items-center gap-2">
      <i class="fa-solid fa-bug text-emerald-400"></i> Debug Panel
    </h1>
    <span class="text-xs bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">DEBUG ON</span>
  </div>

  <?php if ($message): ?>
    <div class="glass rounded-xl p-4 mb-6 text-sm"><?= $message ?></div>
  <?php endif; ?>

  <!-- How to toggle debug mode -->
  <div class="glass rounded-xl p-5 mb-6 text-sm">
    <h2 class="font-bold mb-2 text-emerald-400"><i class="fa-solid fa-toggle-on mr-2"></i>Debug Mode Toggle</h2>
    <p class="text-slate-300 mb-3">To <strong>turn ON</strong>: add this to <code class="bg-white/10 px-1 rounded">config.php</code>:</p>
    <pre class="bg-slate-900 border border-white/10 rounded p-3 text-emerald-300 text-xs overflow-auto">define('DEBUG_MODE', true);</pre>
    <p class="text-slate-300 mt-3 mb-2">To <strong>turn OFF</strong> (before going live):</p>
    <pre class="bg-slate-900 border border-white/10 rounded p-3 text-emerald-300 text-xs overflow-auto">define('DEBUG_MODE', false);</pre>
    <p class="text-amber-400 text-xs mt-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Always set <code>DEBUG_MODE = false</code> and <code>APP_ENV = 'production'</code> before going live. Debug responses include internal stack traces.</p>
  </div>

  <!-- System checks -->
  <div class="glass rounded-xl p-5 mb-6">
    <h2 class="font-bold mb-4 text-emerald-400"><i class="fa-solid fa-list-check mr-2"></i>System Checks</h2>
    <div class="space-y-2 text-sm">
      <?php foreach ($checks as $label => $result): ?>
        <div class="flex justify-between border-b border-white/5 pb-2">
          <span class="text-slate-300"><?= htmlspecialchars($label) ?></span>
          <?php if (is_bool($result)): ?>
            <span class="<?= $result ? 'text-emerald-400' : 'text-red-400' ?> font-semibold"><?= $result ? '✓ OK' : '✗ FAIL' ?></span>
          <?php else: ?>
            <span class="text-slate-400"><?= htmlspecialchars($result) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Quick action buttons -->
  <div class="glass rounded-xl p-5 mb-6">
    <h2 class="font-bold mb-4 text-emerald-400"><i class="fa-solid fa-flask mr-2"></i>Quick Tests & Actions</h2>
    <div class="flex flex-wrap gap-3">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="test_places_api">
        <button class="bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 px-4 py-2 rounded-full text-sm font-semibold">
          <i class="fa-solid fa-satellite-dish mr-1"></i>Test Google Places API
        </button>
      </form>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="test_db">
        <button class="bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 px-4 py-2 rounded-full text-sm font-semibold">
          <i class="fa-solid fa-database mr-1"></i>Test Platform DB
        </button>
      </form>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="clear_lead_cache">
        <button class="bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 px-4 py-2 rounded-full text-sm font-semibold"
          onclick="return confirm('Clear all cached lead searches?')">
          <i class="fa-solid fa-trash mr-1"></i>Clear Lead Cache
        </button>
      </form>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="clear_error_log">
        <button class="bg-red-500/20 hover:bg-red-500/30 text-red-400 px-4 py-2 rounded-full text-sm font-semibold"
          onclick="return confirm('Clear the entire error log?')">
          <i class="fa-solid fa-fire mr-1"></i>Clear Error Log
        </button>
      </form>
    </div>
  </div>

  <!-- Lead cache browser -->
  <div class="glass rounded-xl p-5 mb-6">
    <h2 class="font-bold mb-1 text-emerald-400"><i class="fa-solid fa-magnifying-glass mr-2"></i>Lead Cache Browser</h2>
    <p class="text-slate-500 text-xs mb-4"><?= $cache_total ?> total cached searches</p>
    <?php if (empty($cache_rows)): ?>
      <p class="text-slate-500 text-sm">No cache entries yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-xs text-slate-300">
          <thead><tr class="text-slate-500 border-b border-white/10">
            <th class="text-left pb-2 pr-4">Cache Key (city|industry)</th>
            <th class="text-left pb-2 pr-4">Cached At</th>
            <th class="text-left pb-2 pr-4">~Leads</th>
            <th class="text-left pb-2">JSON Size</th>
          </tr></thead>
          <tbody>
          <?php foreach ($cache_rows as $row): ?>
            <tr class="border-b border-white/5">
              <td class="py-2 pr-4 font-mono"><?= htmlspecialchars($row['cache_key']) ?></td>
              <td class="py-2 pr-4"><?= htmlspecialchars($row['created_at']) ?></td>
              <td class="py-2 pr-4"><?= (int)$row['approx_count'] ?></td>
              <td class="py-2"><?= number_format((int)$row['json_bytes'] / 1024, 1) ?> KB</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Error log -->
  <div class="glass rounded-xl p-5">
    <h2 class="font-bold mb-1 text-emerald-400"><i class="fa-solid fa-terminal mr-2"></i>Recent Error Log</h2>
    <p class="text-slate-500 text-xs mb-4">Last <?= count($errors) ?> entries (newest first)</p>
    <?php if (empty($errors)): ?>
      <p class="text-emerald-400 text-sm"><i class="fa-solid fa-check mr-1"></i>No errors logged. Looking good!</p>
    <?php else: ?>
      <div class="space-y-3 max-h-[600px] overflow-y-auto">
        <?php foreach ($errors as $err): ?>
          <div class="bg-slate-900/60 border border-white/5 rounded-lg p-3 text-xs font-mono">
            <div class="flex justify-between mb-1">
              <span class="text-red-400 font-bold"><?= htmlspecialchars($err['context'] ?? '') ?></span>
              <span class="text-slate-500"><?= htmlspecialchars($err['time'] ?? '') ?></span>
            </div>
            <p class="text-slate-300 mb-1"><?= htmlspecialchars($err['message'] ?? '') ?></p>
            <?php if (!empty($err['extra'])): ?>
              <p class="text-slate-500">extra: <?= htmlspecialchars(json_encode($err['extra'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($err['trace'])): ?>
              <details class="mt-1">
                <summary class="cursor-pointer text-slate-500 hover:text-white">Stack trace</summary>
                <pre class="text-slate-400 mt-1 text-xs overflow-auto whitespace-pre-wrap"><?= htmlspecialchars($err['trace']) ?></pre>
              </details>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
