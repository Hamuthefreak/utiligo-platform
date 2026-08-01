<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$overrides_file = __DIR__ . '/../storage/config_overrides.php';
$saved      = false;
$save_error = '';

// ---------------------------------------------------------------
// All editable keys: [constant, label, type, section, hint]
// type: int | float | bool | intm1 (int, -1 = unlimited)
// ---------------------------------------------------------------
$fields = [
    ['FREE_LEAD_LIMIT',              'Free lead limit',                 'int',   'Free Plan Limits',    'Max leads a free user can access'],
    ['FREE_SEARCH_DAILY_LIMIT',      'Free daily search limit',         'int',   'Free Plan Limits',    'Searches per day on free plan'],
    ['FREE_SITE_LIMIT',              'Free site limit',                 'int',   'Free Plan Limits',    'Max active sites on free plan'],
    ['FREE_GENERATE_DAILY_LIMIT',    'Free daily site-generate limit',  'int',   'Free Plan Limits',    'Site generations per day on free plan'],
    ['FREE_TEMPLATE_LIMIT',          'Free template limit',             'int',   'Free Plan Limits',    'Max templates on free plan'],

    ['PRO_LEAD_LIMIT',               'Pro lead limit',                  'intm1', 'Pro Plan Limits',     '-1 = unlimited'],
    ['PRO_SITE_LIMIT',               'Pro site limit',                  'intm1', 'Pro Plan Limits',     '-1 = unlimited'],
    ['PRO_GENERATE_DAILY_LIMIT',     'Pro daily generate limit',        'intm1', 'Pro Plan Limits',     '-1 = unlimited'],
    ['PRO_TEMPLATE_LIMIT',           'Pro template limit',              'intm1', 'Pro Plan Limits',     '-1 = unlimited'],

    ['ENT_LEAD_LIMIT',               'Entrepreneur lead limit',         'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_SITE_LIMIT',               'Entrepreneur site limit',         'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_GENERATE_DAILY_LIMIT',     'Entrepreneur daily generate',     'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_TEMPLATE_LIMIT',           'Entrepreneur template limit',     'intm1', 'Entrepreneur Limits', '-1 = unlimited'],
    ['ENT_TEAM_SEATS',               'Entrepreneur team seats',         'int',   'Entrepreneur Limits', 'Number of team member seats'],
    ['ENT_CUSTOM_DOMAIN_LIMIT',      'Custom domain limit',             'intm1', 'Entrepreneur Limits', '-1 = unlimited'],

    ['PRO_PLAN_PRICE',               'Pro plan price ($/mo)',           'float', 'Pricing',             'Shown on billing page and plan cards'],
    ['ENTREPRENEUR_PLAN_PRICE',      'Entrepreneur price ($/mo)',       'float', 'Pricing',             'Shown on billing page and plan cards'],

    ['ENABLE_BOOKING',               'Enable booking module',           'bool',  'Feature Flags',       ''],
    ['ENABLE_ECOMMERCE',             'Enable e-commerce module',        'bool',  'Feature Flags',       ''],
    ['ENABLE_BLOG',                  'Enable blog module',              'bool',  'Feature Flags',       ''],
    ['ENABLE_CUSTOM_DOMAINS',        'Enable custom domains',           'bool',  'Feature Flags',       ''],
    ['TEST_PAYMENT_MODE',            'Test payment mode',               'bool',  'Feature Flags',       'When ON, any 12-digit card number works'],
    ['EMAIL_VERIFICATION_REQUIRED',  'Require email verification',      'bool',  'Feature Flags',       ''],

    ['RATE_LIMIT_FIND_LEADS',        'Rate limit: find leads',          'int',   'Rate Limits',         'Requests per window'],
    ['RATE_LIMIT_GENERATE_SITE',     'Rate limit: generate site',       'int',   'Rate Limits',         ''],
    ['RATE_LIMIT_UPLOAD_IMAGE',      'Rate limit: upload image',        'int',   'Rate Limits',         ''],
    ['RATE_LIMIT_SAVE_SITE_PAGE',    'Rate limit: save site page',      'int',   'Rate Limits',         ''],
    ['RATE_LIMIT_MANAGE_SITE',       'Rate limit: manage site',         'int',   'Rate Limits',         ''],

    ['LOGIN_MAX_ATTEMPTS',           'Login max attempts',              'int',   'Security',            'Before lockout'],
    ['LOGIN_LOCKOUT_MINUTES',        'Login lockout (minutes)',         'int',   'Security',            ''],
    ['TWO_FA_CODE_EXPIRY_MINUTES',   '2FA code expiry (minutes)',       'int',   'Security',            ''],
    ['PASSWORD_RESET_EXPIRY_MINUTES','Password reset expiry (min)',     'int',   'Security',            ''],
    ['RESEND_VERIFY_MAX',            'Resend verify max attempts',      'int',   'Security',            ''],
    ['RESEND_VERIFY_WINDOW',         'Resend verify window (min)',      'int',   'Security',            ''],
    ['MAX_LOGO_UPLOAD_BYTES',        'Max logo upload (bytes)',         'int',   'Security',            '2097152 = 2 MB'],

    ['MAX_PLACES_DETAILS_LOOKUPS',   'Max Places detail lookups',       'int',   'Google API',          'Per search request'],
    ['LEAD_SEARCH_CACHE_HOURS',      'Lead search cache (hours)',       'int',   'Google API',          ''],

    ['BREVO_LIST_ALL_USERS',         'Brevo list ID: all users',        'int',   'Brevo',               ''],
    ['BREVO_LIST_PRO_USERS',         'Brevo list ID: pro users',        'int',   'Brevo',               ''],
    ['BREVO_LIST_FREE_USERS',        'Brevo list ID: free users',       'int',   'Brevo',               ''],
];

// ---------------------------------------------------------------
// SAVE
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    if (!admin_csrf_verify('config', $_POST['csrf_token'] ?? null)) {
        $save_error = 'Invalid or expired security token. Please refresh and try again.';
    } else {
        $lines = [
            '<?php',
            '// Admin-managed config overrides.',
            '// Generated by admin/config.php — do not edit by hand.',
            '// define() calls here win over defaults in includes/plan_limits.php and config.php.',
            '',
        ];
        foreach ($fields as [$key, $label, $type]) {
            $raw = $_POST['cfg'][$key] ?? '';
            switch ($type) {
                case 'bool':
                    $val  = !empty($raw) ? 'true' : 'false';
                    $lines[] = "define('$key', $val);";
                    break;
                case 'float':
                    $fval    = round((float)$raw, 4);
                    $lines[] = "define('$key', $fval);";
                    break;
                case 'intm1':
                    $ival    = (int)$raw;
                    $lines[] = "define('$key', $ival);";
                    break;
                default: // int
                    $ival    = max(0, (int)$raw);
                    $lines[] = "define('$key', $ival);";
                    break;
            }
        }
        $php = implode("\n", $lines) . "\n";

        // Note: overrides file uses plain define() (no if(!defined()) guard)
        // because it is loaded BEFORE all defaults, so it always wins.
        if (@file_put_contents($overrides_file, $php) !== false) {
            $saved = true;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($overrides_file, true);
                opcache_invalidate(__DIR__ . '/../includes/plan_limits.php', true);
                opcache_invalidate(__DIR__ . '/../config.php', true);
            }
        } else {
            $save_error = 'Could not write storage/config_overrides.php — check directory permissions (chmod 775 storage/ or 664 on the file).';
        }
    }
}

function _cfg_val(string $key, string $type): mixed {
    if (!defined($key)) return ($type === 'bool' ? false : '');
    $v = constant($key);
    return $type === 'bool' ? (bool)$v : $v;
}

$pageTitle = 'Config Editor — Admin — Utiligo';
$adminPage = 'config';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- Page header -->
<div class="mb-8 flex items-start justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5">Platform-wide settings</p>
    <h1 class="text-3xl font-bold tracking-tight">Config Editor</h1>
    <p class="text-slate-500 text-xs mt-1">
      Changes are written to
      <code class="text-purple-400 bg-purple-500/10 px-1.5 py-0.5 rounded">storage/config_overrides.php</code>
      and take effect immediately — no redeploy needed.
    </p>
  </div>
  <div class="text-right text-xs text-slate-600 mt-1 space-y-0.5">
    <p>Overrides file:
      <?= file_exists($overrides_file)
          ? '<span class="text-emerald-400 font-semibold">exists ✓</span>'
          : '<span class="text-red-400 font-semibold">missing ✗</span>' ?>
    </p>
    <p>Writable:
      <?= (is_writable($overrides_file) || is_writable(dirname($overrides_file)))
          ? '<span class="text-emerald-400 font-semibold">yes ✓</span>'
          : '<span class="text-red-400 font-semibold">no — chmod 664 storage/</span>' ?>
    </p>
  </div>
</div>

<?php if ($saved): ?>
<div class="flex items-center gap-3 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check"></i>
  Config saved and opcache flushed — all changes are live across the whole platform.
</div>
<?php endif; ?>
<?php if ($save_error): ?>
<div class="flex items-center gap-3 bg-red-500/10 border border-red-400/20 text-red-400 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <?= htmlspecialchars($save_error) ?>
</div>
<?php endif; ?>

<form method="POST" action="/admin/config.php">
  <input type="hidden" name="csrf_token" value="<?= admin_csrf_token('config') ?>">
  <input type="hidden" name="save_config" value="1">

  <?php
  // Group fields by section
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
  <div class="group relative bg-white/[0.03] hover:bg-white/[0.045] border border-white/5 hover:border-white/10 rounded-2xl p-6 mb-4 transition-all">
    <!-- Section header -->
    <div class="flex items-center gap-2 mb-5">
      <div class="w-7 h-7 rounded-lg bg-purple-500/15 flex items-center justify-center shrink-0">
        <i class="fa-solid fa-<?= $icon ?> text-purple-400 text-xs"></i>
      </div>
      <h2 class="text-xs font-bold uppercase tracking-widest text-purple-400"><?= htmlspecialchars($section) ?></h2>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
      <?php foreach ($section_fields as [$key, $label, $type, , $hint]):
          $cur = _cfg_val($key, $type);
      ?>
      <div>
        <label class="block text-xs font-semibold text-slate-300 mb-1"><?= htmlspecialchars($label) ?></label>
        <?php if ($hint): ?>
          <p class="text-[11px] text-slate-600 mb-1.5"><?= htmlspecialchars($hint) ?></p>
        <?php endif; ?>

        <?php if ($type === 'bool'): ?>
          <label class="inline-flex items-center gap-3 cursor-pointer mt-1">
            <div class="relative">
              <input type="checkbox" name="cfg[<?= $key ?>]" value="1" <?= $cur ? 'checked' : '' ?>
                     class="sr-only peer">
              <div class="w-11 h-6 bg-white/10 peer-checked:bg-purple-600 rounded-full transition-colors"></div>
              <div class="absolute top-0.5 left-0.5 w-5 h-5 bg-slate-400 peer-checked:bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
            </div>
            <span class="text-xs text-slate-400 peer-checked:text-white transition-colors"><?= $cur ? 'Enabled' : 'Disabled' ?></span>
          </label>

        <?php elseif ($type === 'float'): ?>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm">$</span>
            <input type="number" step="0.01" min="0"
                   name="cfg[<?= $key ?>]" value="<?= htmlspecialchars((string)$cur) ?>"
                   class="w-full bg-slate-900/70 border border-white/10 focus:border-purple-500/50 focus:ring-2 focus:ring-purple-500/10 text-white rounded-xl pl-7 pr-3 py-2.5 text-sm outline-none transition">
          </div>

        <?php else: /* int / intm1 */ ?>
          <input type="number" step="1" <?= $type === 'int' ? 'min="0"' : 'min="-1"' ?>
                 name="cfg[<?= $key ?>]" value="<?= htmlspecialchars((string)$cur) ?>"
                 class="w-full bg-slate-900/70 border border-white/10 focus:border-purple-500/50 focus:ring-2 focus:ring-purple-500/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none transition">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Sticky save bar -->
  <div class="sticky bottom-4 z-20 flex items-center justify-between bg-slate-900/90 backdrop-blur border border-white/10 rounded-2xl px-6 py-4 mt-2 shadow-xl">
    <p class="text-xs text-slate-500 hidden sm:block">Saved values override all code defaults — billing page, portal limits, plan cards update instantly.</p>
    <button type="submit"
            class="ml-auto inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-500 active:scale-95 text-white px-7 py-2.5 rounded-xl font-bold text-sm transition-all shadow-lg shadow-purple-900/30">
      <i class="fa-solid fa-floppy-disk"></i> Save All Changes
    </button>
  </div>
</form>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
