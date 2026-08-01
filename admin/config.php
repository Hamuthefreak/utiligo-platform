<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

// Admin-only guard
if (!is_logged_in() || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: /admin/'); exit;
}

$overrides_file = __DIR__ . '/../storage/config_overrides.php';
$saved   = false;
$save_error = '';

// ---------------------------------------------------------------
// All editable keys: [constant, label, type, section, hint]
// type: int | float | bool | string | intm1 (int, -1 = unlimited)
// ---------------------------------------------------------------
$fields = [
    // --- Plan Limits: Free ---
    ['FREE_LEAD_LIMIT',           'Free lead limit',                'int',   'Free Plan Limits',    'Max leads a free user can access'],
    ['FREE_SEARCH_DAILY_LIMIT',   'Free daily search limit',        'int',   'Free Plan Limits',    'Searches per day on free plan'],
    ['FREE_SITE_LIMIT',           'Free site limit',                'int',   'Free Plan Limits',    'Max active sites on free plan'],
    ['FREE_GENERATE_DAILY_LIMIT', 'Free daily site-generate limit', 'int',   'Free Plan Limits',    'Site generations per day on free plan'],
    ['FREE_TEMPLATE_LIMIT',       'Free template limit',            'int',   'Free Plan Limits',    'Max templates on free plan'],

    // --- Plan Limits: Pro ---
    ['PRO_LEAD_LIMIT',            'Pro lead limit',                 'intm1', 'Pro Plan Limits',     '-1 = unlimited'],
    ['PRO_SITE_LIMIT',            'Pro site limit',                 'intm1', 'Pro Plan Limits',     '-1 = unlimited'],
    ['PRO_GENERATE_DAILY_LIMIT',  'Pro daily generate limit',       'intm1', 'Pro Plan Limits',     '-1 = unlimited'],
    ['PRO_TEMPLATE_LIMIT',        'Pro template limit',             'intm1', 'Pro Plan Limits',     '-1 = unlimited'],

    // --- Plan Limits: Entrepreneur ---
    ['ENT_LEAD_LIMIT',            'Entrepreneur lead limit',        'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_SITE_LIMIT',            'Entrepreneur site limit',        'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_GENERATE_DAILY_LIMIT',  'Entrepreneur daily generate',    'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_TEMPLATE_LIMIT',        'Entrepreneur template limit',    'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_TEAM_SEATS',            'Entrepreneur team seats',        'int',   'Entrepreneur Limits', 'Number of team member seats'],
    ['ENT_CUSTOM_DOMAIN_LIMIT',   'Custom domain limit',            'intm1', 'Entrepreneur Limits', '-1 = unlimited'],

    // --- Pricing ---
    ['PRO_PLAN_PRICE',            'Pro plan price ($/mo)',          'float', 'Pricing',             'Shown on billing page and plan cards'],
    ['ENTREPRENEUR_PLAN_PRICE',   'Entrepreneur price ($/mo)',      'float', 'Pricing',             'Shown on billing page and plan cards'],

    // --- Feature Flags ---
    ['ENABLE_BOOKING',            'Enable booking module',          'bool',  'Feature Flags',       ''],
    ['ENABLE_ECOMMERCE',          'Enable e-commerce module',       'bool',  'Feature Flags',       ''],
    ['ENABLE_BLOG',               'Enable blog module',             'bool',  'Feature Flags',       ''],
    ['ENABLE_CUSTOM_DOMAINS',     'Enable custom domains',          'bool',  'Feature Flags',       ''],
    ['TEST_PAYMENT_MODE',         'Test payment mode',              'bool',  'Feature Flags',       'When ON, any 12-digit card number works'],
    ['EMAIL_VERIFICATION_REQUIRED','Require email verification',    'bool',  'Feature Flags',       ''],

    // --- Rate Limits ---
    ['RATE_LIMIT_FIND_LEADS',     'Rate limit: find leads',         'int',   'Rate Limits',         'Requests per window'],
    ['RATE_LIMIT_GENERATE_SITE',  'Rate limit: generate site',      'int',   'Rate Limits',         ''],
    ['RATE_LIMIT_UPLOAD_IMAGE',   'Rate limit: upload image',       'int',   'Rate Limits',         ''],
    ['RATE_LIMIT_SAVE_SITE_PAGE', 'Rate limit: save site page',     'int',   'Rate Limits',         ''],
    ['RATE_LIMIT_MANAGE_SITE',    'Rate limit: manage site',        'int',   'Rate Limits',         ''],

    // --- Security ---
    ['LOGIN_MAX_ATTEMPTS',        'Login max attempts',             'int',   'Security',            'Before lockout'],
    ['LOGIN_LOCKOUT_MINUTES',     'Login lockout (minutes)',        'int',   'Security',            ''],
    ['TWO_FA_CODE_EXPIRY_MINUTES','2FA code expiry (minutes)',      'int',   'Security',            ''],
    ['PASSWORD_RESET_EXPIRY_MINUTES','Password reset expiry (min)', 'int',   'Security',            ''],
    ['RESEND_VERIFY_MAX',         'Resend verify max attempts',     'int',   'Security',            ''],
    ['RESEND_VERIFY_WINDOW',      'Resend verify window (min)',     'int',   'Security',            ''],
    ['MAX_LOGO_UPLOAD_BYTES',     'Max logo upload (bytes)',        'int',   'Security',            '2097152 = 2 MB'],

    // --- Google API ---
    ['MAX_PLACES_DETAILS_LOOKUPS','Max Places detail lookups',      'int',   'Google API',          'Per search request'],
    ['LEAD_SEARCH_CACHE_HOURS',   'Lead search cache (hours)',      'int',   'Google API',          ''],

    // --- Brevo ---
    ['BREVO_LIST_ALL_USERS',      'Brevo list ID: all users',       'int',   'Brevo',               ''],
    ['BREVO_LIST_PRO_USERS',      'Brevo list ID: pro users',       'int',   'Brevo',               ''],
    ['BREVO_LIST_FREE_USERS',     'Brevo list ID: free users',      'int',   'Brevo',               ''],
];

// ---------------------------------------------------------------
// SAVE
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $save_error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $lines = ["<?php", "// Admin-managed config overrides.", "// Generated by admin/config.php — do not edit by hand.", ""];

        foreach ($fields as [$key, $label, $type]) {
            $raw = $_POST['cfg'][$key] ?? '';
            switch ($type) {
                case 'bool':
                    $val  = !empty($raw) ? 'true' : 'false';
                    $line = "if (!defined('$key')) define('$key', $val);";
                    break;
                case 'float':
                    $fval = (float) $raw;
                    $line = "if (!defined('$key')) define('$key', $fval);";
                    break;
                case 'intm1':
                    $ival = (int) $raw;
                    $line = "if (!defined('$key')) define('$key', $ival);"; // -1 allowed
                    break;
                case 'int':
                default:
                    $ival = max(0, (int) $raw);
                    $line = "if (!defined('$key')) define('$key', $ival);";
                    break;
            }
            $lines[] = $line;
        }

        $php = implode("\n", $lines) . "\n";

        if (@file_put_contents($overrides_file, $php) !== false) {
            $saved = true;
            // Bust opcache so the new file is picked up immediately
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($overrides_file, true);
                opcache_invalidate(__DIR__ . '/../includes/plan_limits.php', true);
                opcache_invalidate(__DIR__ . '/../config.php', true);
            }
        } else {
            $save_error = 'Could not write to storage/config_overrides.php — check directory permissions (needs to be writable by the web server).';
        }
    }
}

// ---------------------------------------------------------------
// Current values: read from the live define() values (post-load)
// ---------------------------------------------------------------
function _cfg_current(string $key, string $type): mixed {
    if (!defined($key)) return '';
    $v = constant($key);
    return $type === 'bool' ? (bool)$v : $v;
}

$pageTitle = 'Config Editor — Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{background:#0a0a0f;color:#e2e8f0;font-family:'Inter',system-ui,sans-serif}
.glass{background:rgba(255,255,255,.04);backdrop-filter:blur(12px)}
.cfg-input{width:100%;background:rgba(15,23,42,.7);border:1.5px solid rgba(255,255,255,.1);color:#fff;
  border-radius:.625rem;padding:.55rem .85rem;font-size:.875rem;outline:none;
  transition:border-color .15s,box-shadow .15s}
.cfg-input:focus{border-color:rgba(99,102,241,.6);box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.section-pill{display:inline-flex;align-items:center;gap:.4rem;
  background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);
  color:#a5b4fc;border-radius:9999px;padding:.25rem .85rem;font-size:.68rem;font-weight:700;
  letter-spacing:.07em;text-transform:uppercase;margin-bottom:1rem}
.toggle-wrap{display:flex;align-items:center;gap:.75rem}
.toggle{position:relative;width:44px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.slider{position:absolute;inset:0;border-radius:9999px;background:rgba(255,255,255,.12);
  cursor:pointer;transition:.2s}
.slider:before{content:"";position:absolute;height:18px;width:18px;left:3px;bottom:3px;
  background:#94a3b8;border-radius:50%;transition:.2s}
input:checked+.slider{background:#6366f1}
input:checked+.slider:before{transform:translateX(20px);background:#fff}
.save-btn{background:linear-gradient(135deg,#6366f1,#818cf8);color:#fff;
  padding:.75rem 2.5rem;border-radius:.875rem;font-weight:800;font-size:.95rem;
  transition:all .2s;border:none;cursor:pointer}
.save-btn:hover{box-shadow:0 6px 28px rgba(99,102,241,.45);transform:translateY(-1px)}
.save-btn:active{transform:scale(.97)}
.back-link{color:#6366f1;text-decoration:none;font-size:.8rem;font-weight:600}
.back-link:hover{color:#a5b4fc}
</style>
</head>
<body class="min-h-screen p-6">

<div class="max-w-4xl mx-auto">
  <div class="flex items-center justify-between mb-8">
    <div>
      <a href="/admin/" class="back-link"><i class="fa-solid fa-arrow-left mr-1.5"></i>Admin Dashboard</a>
      <h1 class="text-2xl font-bold mt-2">Config Editor</h1>
      <p class="text-slate-400 text-sm mt-0.5">Changes save to <code class="text-indigo-400">storage/config_overrides.php</code> and take effect immediately — no deploy needed.</p>
    </div>
    <div class="text-right text-xs text-slate-600">
      <p>Overrides file:</p>
      <p class="font-mono text-slate-500"><?= file_exists($overrides_file) ? '<span class="text-emerald-500">exists ✓</span>' : '<span class="text-red-500">missing ✗</span>' ?></p>
      <p class="mt-1">Writable: <?= is_writable($overrides_file) || is_writable(dirname($overrides_file)) ? '<span class="text-emerald-500">yes ✓</span>' : '<span class="text-red-500">no — chmod 664 storage/</span>' ?></p>
    </div>
  </div>

  <?php if ($saved): ?>
  <div class="flex items-center gap-3 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-2xl px-5 py-4 mb-6 text-sm">
    <i class="fa-solid fa-circle-check"></i>
    Config saved and opcache flushed — changes are live across the whole platform.
  </div>
  <?php endif; ?>
  <?php if ($save_error): ?>
  <div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
    <i class="fa-solid fa-triangle-exclamation"></i><?= htmlspecialchars($save_error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="/admin/config">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="save_config" value="1">

    <?php
    $sections = [];
    foreach ($fields as $f) $sections[$f[3]][] = $f;

    $section_icons = [
        'Free Plan Limits'    => 'user',
        'Pro Plan Limits'     => 'crown',
        'Entrepreneur Limits' => 'rocket',
        'Pricing'             => 'tag',
        'Feature Flags'       => 'toggle-on',
        'Rate Limits'         => 'gauge-high',
        'Security'            => 'shield-halved',
        'Google API'          => 'map-location-dot',
        'Brevo'               => 'envelope-open-text',
    ];

    foreach ($sections as $section => $section_fields):
        $icon = $section_icons[$section] ?? 'sliders';
    ?>
    <div class="glass rounded-2xl border border-white/5 p-6 mb-5">
      <div class="section-pill"><i class="fa-solid fa-<?= $icon ?>"></i><?= htmlspecialchars($section) ?></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
        <?php foreach ($section_fields as [$key, $label, $type, , $hint]):
            $cur = _cfg_current($key, $type);
        ?>
        <div class="<?= $type === 'bool' ? 'flex items-center justify-between' : '' ?>">
          <div class="<?= $type !== 'bool' ? 'mb-1.5' : '' ?>">
            <label class="block text-xs font-bold uppercase tracking-widest text-slate-400 mb-0.5"><?= htmlspecialchars($label) ?></label>
            <?php if ($hint): ?>
            <p class="text-[11px] text-slate-600"><?= htmlspecialchars($hint) ?></p>
            <?php endif; ?>
          </div>
          <?php if ($type === 'bool'): ?>
          <label class="toggle">
            <input type="checkbox" name="cfg[<?= $key ?>]" value="1" <?= $cur ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
          <?php elseif ($type === 'float'): ?>
          <input type="number" step="0.01" min="0" name="cfg[<?= $key ?>]" value="<?= htmlspecialchars((string)$cur) ?>" class="cfg-input">
          <?php else: ?> <!-- int / intm1 -->
          <input type="number" step="1" <?= $type === 'int' ? 'min="0"' : 'min="-1"' ?> name="cfg[<?= $key ?>]" value="<?= htmlspecialchars((string)$cur) ?>" class="cfg-input">
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="flex items-center justify-between glass rounded-2xl border border-white/5 px-6 py-5 sticky bottom-4">
      <p class="text-xs text-slate-500">Saved values override code defaults everywhere — billing page, portal limits, plan cards.</p>
      <button type="submit" class="save-btn">
        <i class="fa-solid fa-floppy-disk mr-2"></i>Save All Changes
      </button>
    </div>
  </form>
</div>

</body>
</html>
