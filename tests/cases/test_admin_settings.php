<?php
/**
 * The admin settings page — one writer, and a page that actually opens.
 *
 * WHY THIS FILE EXISTS
 * ────────────────────
 * Two failures that nothing else in the suite could see, because both are about
 * a route rather than a function:
 *
 *   1. `/admin/config.php` answered **403** to everybody. The root `.htaccess`
 *      denied `<FilesMatch "^(config|db|userdb)\.php$">`, and a FilesMatch tests
 *      the base name in EVERY directory — so the "Config Editor", the only page
 *      that could set the Whop keys and the alert address, was unreachable while
 *      `/config.php` at the root stayed protected. §1 pulls the replacement
 *      pattern out of the file and runs it against the paths that broke, because
 *      "the pattern looks right" is exactly the claim that was wrong.
 *   2. Two pages wrote `storage/config_overrides.php` with two different lists of
 *      fields and one CSRF slot, so whatever saved last decided which keys were
 *      still editable. §2 pins down that there is one writer, and §4 does what a
 *      static check cannot: saves the form for real.
 *   3. A save answered with a RENDER of the state it had read before writing —
 *      constants come from config.php at the top of a request and cannot be
 *      replaced inside it — so an operator was told "saved" next to a banner still
 *      saying payments were not configured. That reads as "the settings page is not
 *      editing the config", which is how it was reported. §4 now asserts the page
 *      you land on shows what you just saved, and §3 the check for the other way
 *      the same complaint happens: a file that is written and never loaded.
 *
 * The built-in server the suite runs on ignores `.htaccess` entirely, so §3's
 * fetch of the old page proves the redirect and never could have proved the 403.
 * That is why §1 reads the rule itself.
 */

$root = dirname(__DIR__, 2);
$read = static function (string $rel) use ($root): string {
    $p = $root . '/' . $rel;
    return is_file($p) ? (string)file_get_contents($p) : '';
};

// The check this file asserts in §3, and the one the page runs on itself.
require_once $root . '/includes/config_overrides.php';

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. The credential guard refuses the root files, not every file with that name
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The credential guard is scoped to the root, so the admin pages open');

$htaccess = $read('.htaccess');
t_ok($htaccess !== '', '.htaccess is present');

// The rule that broke is named in a comment in the file (it is why the rule moved),
// so this reads directives rather than the file's prose.
$directives = (string)preg_replace('/^\s*#.*$/m', '', $htaccess);
t_unlike($directives, '<FilesMatch "^(config|db|userdb)',
    'no active directive denies these names by base name — that also matched /admin/config.php');

$guard = '';
if (preg_match('/^RewriteRule\s+(\S*(?:config|db|userdb)\S*)\s+-\s+\[F,L\]\s*$/mi', $htaccess, $m)) {
    $guard = $m[1];
}
t_ok($guard !== '', 'the credential files are refused by a RewriteRule on the request URI');

if ($guard !== '') {
    // Apache matches a per-directory RewriteRule against the path with the leading
    // slash removed, so the samples are written the way mod_rewrite sees them.
    foreach (['config.php', 'config', 'db.php', 'db', 'userdb.php', 'userdb'] as $path) {
        t_ok(preg_match('#^' . $guard . '$#', $path) === 1, "the guard still refuses /{$path}");
    }
    foreach (['admin/config.php', 'admin/config', 'admin/db.php', 'admin/settings.php',
              'admin/payments.php', 'portal/billing.php', 'config-help.php'] as $path) {
        t_ok(preg_match('#^' . $guard . '$#', $path) !== 1, "and no longer refuses /{$path}");
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. One page writes the overrides file
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('One page writes storage/config_overrides.php');

$settings = $read('admin/settings.php');
$legacy   = $read('admin/config.php');

t_ok($settings !== '', 'admin/settings.php exists');

// The sections that used to live only on the page nobody could open.
t_like($settings, "'Payments (Whop)'", 'the settings page carries the payments section');
t_like($settings, "'Alerts'", 'and the alerts section');
foreach (['WHOP_API_KEY', 'WHOP_WEBHOOK_SECRET', 'WHOP_ACCOUNT_ID', 'WHOP_PRO_PLAN_ID',
          'WHOP_ENT_PLAN_ID', 'ADMIN_EMAIL', 'WHOP_ALERT_MAX_PER_HOUR', 'PLACES_API_MONTHLY_LIMIT'] as $key) {
    t_like($settings, "'{$key}'", "{$key} is editable from the settings page");
}

// The block an operator reads while filling the form in: what to register in
// Whop's dashboard, next to the field the signing secret goes into.
t_like($settings, 'id="whopEndpoint"', 'the page shows the endpoint URL to register');
t_like($settings, 'whop_webhook_url()', 'built rather than hardcoded, so staging registers its own');
t_like($settings, 'payment.succeeded', 'and the two event names, starting with payment.succeeded');
t_like($settings, 'membership.deactivated', 'and membership.deactivated');
t_like($settings, 'whop-checkout.php', 'and says what is refused until a delivery can be verified');
t_ok(str_contains($settings, "require_once __DIR__ . '/../includes/whop.php'"),
    'the page loads the Whop boundary, so the readiness strip reports the real state');

// A secret is written by preserving, not by echoing: the type, and the one
// variable that makes a blank field mean "keep".
t_ok(str_contains($settings, "case 'secret':"), 'a secret field type exists for the write-only keys');
t_ok(str_contains($settings, '$existing_managed'), 'and the save path remembers the stored line for a blank field');

// One form, one slot.
t_ok(str_contains($settings, "admin_csrf_token('settings')") && str_contains($settings, "admin_csrf_verify('settings'"),
    'render and verify agree on one CSRF slot');

t_ok(str_contains($legacy, "header('Location: /admin/settings.php'"),
    'the old Config Editor is a redirect to the settings page');
t_unlike($legacy, 'file_put_contents', 'and writes nothing itself');
t_unlike($legacy, 'save_config', 'and handles no form, which is what makes the settings page the only writer');

$linked = [];
foreach ([$root . '/admin/*.php', $root . '/includes/*.php', $root . '/portal/*.php'] as $pattern) {
    foreach (glob($pattern) ?: [] as $file) {
        if (str_contains((string)file_get_contents($file), 'href="/admin/config.php"')) {
            $linked[] = basename($file);
        }
    }
}
t_is($linked, [], 'no page links to the retired URL any more');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2b. Saved, and loaded — the other way "it is not editing the config" happens
 *
 * The settings page cannot make a value take effect on its own. The only thing
 * that does is a `require_once` at the top of config.php, and config.php is
 * EXCLUDED from the FTP deploy: the copy on a live server is edited by hand, so it
 * can be an older one that never learned about the overrides file. Then every save
 * writes a file nothing reads — "saved" on one screen and "not set" on the next.
 *
 * config_overrides_mismatch() is how the app tells those apart: the file states a
 * value, and the running process has a different one (or none). The unit below
 * pins the parser (the shapes the writer emits, including the two characters a
 * hand-rolled one gets wrong) and both answers it can give.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The page can tell "saved" from "saved and actually loaded"');

t_like($settings, 'config_overrides_mismatch', 'the settings page asks whether the file it writes is being loaded');
t_like($settings, 'Loaded by config.php', 'and says so next to the file and writability facts, not in a log');
// The fix is one line, and it is one definition of that line: the page prints it and
// the repair inserts it, so the sentence an operator copies and the bytes that get
// written cannot drift apart.
t_is(config_overrides_loader_line(), "require_once __DIR__ . '/storage/config_overrides.php';",
    'the line config.php has to have is defined once');
t_like($settings, 'config_overrides_loader_line()',
    'and the page prints that line rather than a second copy of it, because that line is the fix');
t_like($settings, 'config_overrides_file()', 'the path comes from one definition rather than two files spelling it out');
t_like($read('includes/whop.php'), 'is not being loaded by config.php',
    'the payments page reports it as a blocker — that is the page an operator reads when it says "not set"');

t_ok(function_exists('config_overrides_mismatch'), 'the check is a function, so both pages and this test use one implementation');

$probeFile = t_tmp_dir() . '/overrides_probe.php';
file_put_contents($probeFile, <<<'PHP'
<?php
// Admin-managed config overrides.
define('APP_ENV', 'test');
define('UTILIGO_PROBE_QUOTE', 'it\'s; a test');
define('UTILIGO_PROBE_BOOL', true);
define('UTILIGO_PROBE_INT', -1);
define('UTILIGO_PROBE_FLOAT', 0.5);
PHP
);

$defines = config_overrides_defines($probeFile);
t_is($defines['APP_ENV'] ?? null, 'test', 'a define in the overrides file is read back as the value PHP would load');
t_is($defines['UTILIGO_PROBE_QUOTE'] ?? null, "it's; a test",
    'including a value holding a quote and a semicolon — the two characters a hand-rolled parser gets wrong');
t_is(config_overrides_canonical($defines['UTILIGO_PROBE_BOOL'] ?? null), 'true', 'a bare true is the boolean, not the string');
t_is(config_overrides_canonical($defines['UTILIGO_PROBE_INT'] ?? null), '-1', 'and -1 stays -1 rather than becoming 0');
t_is(config_overrides_canonical($defines['UTILIGO_PROBE_FLOAT'] ?? null), '0.5', 'and a float keeps its point');

$state = config_overrides_mismatch($probeFile);
t_is($state['stated'], 5, 'the file is reported as read, with what it states counted');
t_is($state['mismatched'], ['UTILIGO_PROBE_QUOTE', 'UTILIGO_PROBE_BOOL', 'UTILIGO_PROBE_INT', 'UTILIGO_PROBE_FLOAT'],
    'and every key this process never loaded is named as ignored — names only, never values');
t_unlike(implode(' ', $state['mismatched']), 'test', 'the check reports keys, so a value cannot leak through it');

$probeOk = t_tmp_dir() . '/overrides_probe_ok.php';
file_put_contents($probeOk, "<?php\ndefine('APP_ENV', 'test');\n");
t_is(config_overrides_mismatch($probeOk)['mismatched'], [],
    'a file this process agrees with reports nothing — a check that always fires is not a check');

$probeDrift = t_tmp_dir() . '/overrides_probe_drift.php';
file_put_contents($probeDrift, "<?php\ndefine('APP_ENV', 'production');\n");
t_is(config_overrides_mismatch($probeDrift)['mismatched'], ['APP_ENV'],
    'and a key something defines ABOVE the overrides file reads as the same failure, because it is');

t_is(config_overrides_mismatch(t_tmp_dir() . '/does_not_exist.php')['read'], false,
    'a machine with no overrides file yet is not reported as a broken one');

@unlink($probeFile);
@unlink($probeOk);
@unlink($probeDrift);

/* ─────────────────────────────────────────────────────────────────────────────
 * 2c. Which file, can it be written, and repairing the reader
 *
 * §2b answers "is what the file states actually in effect". The three questions an
 * operator asks after that, all of which used to be unanswerable from the page:
 *
 *   1. WHERE is the file? A shared host keeps one htdocs/ per domain, so a file
 *      manager can be open on a second copy of the site — and then the saved values
 *      are really in the file, and really not in the listing being looked at. The
 *      strip prints the absolute path, and a canary written into the same folder
 *      settles it in one glance.
 *   2. CAN this server write at all? A storage/ that refuses the write is the other
 *      way "I saved it and it is not there" happens, and it has its own fix.
 *   3. WHAT does config.php say? Not the running constants — the file itself. The
 *      comparison in §2b needs something saved to compare against; this one works on
 *      the first day, when nothing has been saved yet.
 *
 * And the repair, which is the part that has to be careful: it edits the one file on
 * the server that is excluded from the FTP deploy, so it refuses more than it does.
 * Each refusal below is asserted, because a repair that "usually works" on config.php
 * is a repair that can take the whole site down.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The page says which file it writes, proves it can, and can repair the reader');

t_like($settings, 'config_overrides_reader_status', 'the page asks the installation what its own config.php says');
t_like($settings, 'config_overrides_write_probe', 'and can write a canary next to the overrides file');
t_like($settings, 'config_overrides_repair_reader', 'and can insert the missing require into config.php');
t_like($settings, 'Where this page writes', 'and explains the test on the page itself, not in a wiki');
t_like($settings, "header('Cache-Control: no-store')",
    'and is never cached, so a stale form cannot post the field list it had last month');
t_like($settings, "htmlspecialchars(\$overrides_file)", 'the absolute path it writes to is printed, not just the file name');
t_like($settings, "htmlspecialchars(\$reader['file'])", 'and so is the config.php that has to load it, so the two can be compared in FTP');
t_ok(str_contains($settings, 'name="test_write"') && str_contains($settings, 'name="repair_reader"'),
    'both actions are their own POST, so neither can happen by loading a page');
t_is(substr_count($settings, "admin_csrf_verify('settings'"), 3,
    'and both check the same CSRF slot as the save — one verify per action, no shortcuts');
// The trap this page walked into: admin_csrf_token() rotates the slot, so a page with
// two forms that each call it holds two tokens and one valid one. The small action
// button is then refused while the big Save beside it works — a bug that looks like
// "the button does nothing". One call per render, reused by every form.
t_is(substr_count($settings, "admin_csrf_token('settings')"), 1,
    'and the page issues exactly one token per render, which all of its forms reuse');
t_ok(strpos($settings, "admin_csrf_token('settings')") > strpos($settings, "admin_csrf_verify('settings'"),
    'issued after the POST handling rather than before it, which would rotate the token being verified');

// The reader's status, and the shape that makes it worth having: a loader BELOW the
// first define() is the same as no loader, because the first definition wins.
$cfgDir = t_tmp_dir() . '/reader_probe';
@mkdir($cfgDir, 0777, true);
$cfgFile = $cfgDir . '/config.php';

file_put_contents($cfgFile, "<?php\n\ndefine('DB_HOST', 'sql1.example');\n");
$reader = config_overrides_reader_status($cfgFile);
t_is($reader['exists'], true, 'a config.php that never learned about the overrides is found');
t_is($reader['mentions'], false, 'and reported as not mentioning them');
t_is($reader['first_define_line'], 3, 'with the line of its first define(), so a loader that comes too late can be named');

file_put_contents($cfgFile, "<?php\n\ndefine('DB_HOST', 'sql1.example');\nrequire_once __DIR__ . '/storage/config_overrides.php';\n");
$reader = config_overrides_reader_status($cfgFile);
t_is($reader['mentions'], true, 'a config.php that mentions them is seen to');
t_is($reader['above_defines'], false, 'but a mention below the first define() does not count as loading them');

$refused = config_overrides_repair_reader($cfgFile);
t_is($refused['ok'], false, 'and the repair refuses a file that already mentions them rather than adding a second loader');
t_like($refused['message'], 'already mentions', 'saying why, so nobody retries it wondering if it worked');

// The case it is for: a config.php from before the overrides existed.
$original = "<?php\n\nerror_reporting(E_ALL);\n\ndefine('DB_HOST', 'sql1.example');\n\ndefine('DB_PASS', 'p@ss;word');\n";
$body     = substr($original, 6);   // everything after the opening tag
@mkdir($cfgDir . '/storage', 0777, true);   // where the repair keeps its backup
file_put_contents($cfgFile, $original);
$repaired = config_overrides_repair_reader($cfgFile);
t_is($repaired['ok'], true, 'a config.php that cannot load anything is repaired');
$after = (string)file_get_contents($cfgFile);
t_like($after, "require_once __DIR__ . '/storage/config_overrides.php';", 'the missing require is now in it');
t_ok(strpos($after, 'config_overrides') < strpos($after, "define('DB_HOST'"),
    'and it sits above every definition, which is the only place it works');
t_is(substr($after, -strlen($body)), $body, 'the rest of the file is still there byte for byte — a repair, not a rewrite');
t_is(substr_count($after, "define('DB_PASS', 'p@ss;word');"), 1,
    'including the database password, which is why this file is excluded from the deploy');
t_is(config_overrides_reader_status($cfgFile)['above_defines'], true, 'and the reader now reports it as loading them');
t_is(is_file((string)$repaired['backup']), true, 'with a backup kept before anything was replaced');
t_is((string)file_get_contents((string)$repaired['backup']), $original, 'holding exactly the file that was there before');
if (!empty($repaired['backup'])) {
    @unlink((string)$repaired['backup']);
}

$noRoom = t_tmp_dir() . '/reader_no_backup/config.php';
@mkdir(dirname($noRoom), 0777, true);
file_put_contents($noRoom, "<?php\n\ndefine('DB_HOST', 'sql1.example');\n");
$noBackup = config_overrides_repair_reader($noRoom);
t_is($noBackup['ok'], false, 'with nowhere to keep a backup the repair refuses rather than editing without one');
t_is((string)file_get_contents($noRoom), "<?php\n\ndefine('DB_HOST', 'sql1.example');\n", 'and config.php is byte for byte as it was');

$missing = config_overrides_repair_reader(t_tmp_dir() . '/no_such_config.php');
t_is($missing['ok'], false, 'a server with no config.php gets an explanation rather than a new file');
t_like($missing['message'], 'was not found', 'saying which path it looked at');
t_is(config_overrides_reader_status(t_tmp_dir() . '/no_such_config.php')['mentions'], null,
    'and the reader reports a missing config.php as missing, rather than guessing about it');

@unlink($cfgFile);
@unlink($noRoom);

// The canary: it has to land next to the overrides file and say how many bytes it
// wrote, because "the file is not in my listing" is the finding it exists to produce.
$probeDir = t_tmp_dir() . '/write_probe';
@mkdir($probeDir, 0777, true);
$probe = config_overrides_write_probe($probeDir);
t_ok($probe['bytes'] !== false && $probe['bytes'] > 0, 'the write test reports the bytes it wrote');
t_is(basename((string)$probe['file']), '_utiligo_write_test.php',
    'into a file beside the overrides, named so it cannot be mistaken for one');
t_is(is_file((string)$probe['file']), true, 'and it is really there afterwards, which is what the operator is asked to check');
t_is($probe['error'], '', 'with nothing to report');
@unlink((string)$probe['file']);

/* ─────────────────────────────────────────────────────────────────────────────
 * 3. Saving the page — the secret round trip
 *
 * Everything posted is read back out of the rendered form, so the save changes
 * nothing but the secret and the assertions below stay true for the rest of the
 * run. The overrides file is put back afterwards: it is git-ignored, it is the
 * file that decides plan limits and the webhook secret for every later test, and
 * the harness refuses to run with a stray one present.
 * ──────────────────────────────────────────────────────────────────────────── */

if (empty($context['app_url']) || empty($context['db_ready'])) {
    t_skip('the settings page for real', 'requires the application server and the database');
    return;
}

t_section('Saving the settings page writes the secret, and a blank field keeps it');

$app = $context['app_url'];

$admin   = t_fixture(['is_admin' => 1, 'email' => 'settings-admin@example.test']);
$cookie  = t_login($admin);
$secret  = (string)(getenv('WHOP_WEBHOOK_SECRET') ?: '');
$page    = t_http('GET', $app . '/admin/settings.php', ['cookie' => $cookie]);
t_is($page['status'], 200, 'an admin can open the settings page');

$body = $page['body'];
t_like($body, 'Payments (Whop)', 'it renders the payments section');
t_like($body, 'The webhook, as Whop needs it', 'and the webhook block');
t_like($body, 'Alerts', 'and the alerts section');
if ($secret !== '') {
    t_like($body, 'saved (' . strlen($secret) . ' characters)',
        'a stored secret is reported as saved and by length only');
    t_unlike($body, $secret, 'the secret itself never reaches the page');
}
if (getenv('UTILIGO_ADMIN_EMAIL')) {
    t_like($body, 'Alerts are emailed to', 'and the alert address says where an alert would go');
}

// The redirect that replaces the 403. (The built-in server ignores .htaccess, so
// this pins the signpost, not the guard — §1 pins the guard.)
$old = t_http('GET', $app . '/admin/config.php', ['cookie' => $cookie]);
t_is($old['status'], 302, '/admin/config.php answers with a redirect rather than a 403');
t_is($old['location'], '/admin/settings.php', 'and points at the settings page');

// Every field, exactly as rendered.
preg_match_all('/<input[^>]*name="cfg\[([A-Z0-9_]+)\]"[^>]*>/', $body, $inputs, PREG_SET_ORDER);
$form = [];
foreach ($inputs as $tag) {
    $html = $tag[0];
    preg_match('/value="([^"]*)"/', $html, $v);
    if (str_contains($html, 'type="checkbox"')) {
        if (!str_contains($html, 'checked')) continue;   // an unchecked box is absent, not empty
        $v[1] = '1';
    }
    $form['cfg[' . $tag[1] . ']'] = html_entity_decode($v[1] ?? '', ENT_QUOTES);
}
t_is($form['cfg[WHOP_API_KEY]'] ?? 'MISSING', '',
    'the API key field renders empty even though a key is configured');
t_ok(isset($form['cfg[WHOP_WEBHOOK_SECRET]']), 'and so does the webhook secret field');

/**
 * Save the form the way a browser does: GET it, post every field back with the
 * given ones changed, and do it with the token THAT render issued — a token is
 * consumed by the save that uses it, so a borrowed one is refused and a test
 * that reuses it asserts nothing at all.
 */
$saveThrough = static function (array $overrides) use ($app, $cookie): array {
    $page = t_http('GET', $app . '/admin/settings.php', ['cookie' => $cookie]);
    preg_match_all('/<input[^>]*name="cfg\[([A-Z0-9_]+)\]"[^>]*>/', $page['body'], $tags, PREG_SET_ORDER);
    $fields = [];
    foreach ($tags as $tag) {
        $html = $tag[0];
        preg_match('/value="([^"]*)"/', $html, $v);
        if (str_contains($html, 'type="checkbox"')) {
            if (!str_contains($html, 'checked')) continue;
            $v[1] = '1';
        }
        $fields['cfg[' . $tag[1] . ']'] = html_entity_decode($v[1] ?? '', ENT_QUOTES);
    }
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $tok);

    return t_http('POST', $app . '/admin/settings.php', [
        'form'   => $overrides + $fields + ['csrf_token' => $tok[1] ?? '', 'save_config' => '1'],
        'cookie' => $cookie,
    ]);
};

$overrides = $root . '/storage/config_overrides.php';
$before    = is_file($overrides) ? (string)file_get_contents($overrides) : null;
$restore   = static function () use ($overrides, $before): void {
    if ($before === null) {
        @unlink($overrides);
    } else {
        @file_put_contents($overrides, $before);
    }
};
register_shutdown_function($restore);

try {
    // First save the form exactly as rendered, so the two assertions that follow
    // cannot pass on a refused token: this one fails loudly if the form itself
    // does not round-trip.
    $probe = $saveThrough([]);
    t_is($probe['status'], 302, 'a form posted back unchanged is accepted');
    t_is($probe['location'], '/admin/settings.php?saved=1',
        'and answers with a redirect rather than a rendered page');
    t_unlike($probe['body'], 'Settings saved and opcache flushed',
        'so the POST itself renders no page of its own');
    $landed = t_http('GET', $app . '/admin/settings.php?saved=1', ['cookie' => $cookie]);
    t_is($landed['status'], 200, 'the redirect target opens');
    t_like($landed['body'], 'Settings saved and opcache flushed', 'and carries the confirmation');

    // WHY THE REDIRECT IS NOT A STYLE CHOICE. Everything the page displays — the
    // values in the inputs, the "saved (n characters)" lines, the readiness banner —
    // is read from constants that config.php defined at the top of THIS request, and
    // a constant cannot be replaced inside the process that defined it. So a POST
    // used to answer with a render of the state it had read BEFORE the write:
    // "Settings saved" above a banner still saying payments were not configured,
    // which an operator reads as "this page is not editing the config at all".
    $probeEmail = 'roundtrip-probe@example.test';
    $res = $saveThrough(['cfg[ADMIN_EMAIL]' => $probeEmail]);
    t_is($res['status'], 302, 'a save that changes a plain string is accepted');
    $landed = t_http('GET', $app . '/admin/settings.php', ['cookie' => $cookie]);
    t_like($landed['body'], $probeEmail, 'and the page you land on shows the value you just saved');

    $res = $saveThrough(['cfg[WHOP_WEBHOOK_SECRET]' => $secret]);
    t_is($res['status'], 302, 'saving the form is accepted');
    $landed = t_http('GET', $app . '/admin/settings.php?saved=1', ['cookie' => $cookie]);
    t_like($landed['body'], 'saved (' . strlen($secret) . ' characters)',
        'and the page you land on reports the secret as stored, by length only');
    t_unlike($landed['body'], $secret, 'without echoing the secret back');

    $written = is_file($overrides) ? (string)file_get_contents($overrides) : '';
    t_ok($written !== '', 'the overrides file was written');
    t_like($written, "define('WHOP_WEBHOOK_SECRET', '{$secret}');", 'the pasted secret is in it');
    t_unlike($written, "define('WHOP_API_KEY'",
        'a secret that only ever came from the environment is left alone, not written as empty');
    // The sections that were already on this page still save through the merge.
    t_like($written, "define('FREE_LEAD_LIMIT', " . (int)($form['cfg[FREE_LEAD_LIMIT]'] ?? -1) . ');',
        'the pre-existing plan limits are still written');
    t_like($written, "define('TEST_PAYMENT_MODE', true);", 'and a checked feature flag saves as true');

    // Save again with the field blank: the stored line has to survive, byte for
    // byte. Writing an empty define() here is the quietest way to break payments —
    // save the page for a plan limit and the secret is gone.
    $res = $saveThrough(['cfg[WHOP_WEBHOOK_SECRET]' => '']);
    t_is($res['status'], 302, 'saving again with the secret field left blank is accepted');
    $landed = t_http('GET', $app . '/admin/settings.php?saved=1', ['cookie' => $cookie]);
    t_like($landed['body'], 'Settings saved and opcache flushed', 'and really saves');
    $rewritten = (string)file_get_contents($overrides);
    preg_match("/^define\('WHOP_WEBHOOK_SECRET',.*\);$/m", $written, $wasLine);
    preg_match("/^define\('WHOP_WEBHOOK_SECRET',.*\);$/m", $rewritten, $nowLine);
    t_is($nowLine[0] ?? '', $wasLine[0] ?? '', 'and re-emits the stored secret line byte for byte');
    t_unlike($rewritten, "define('WHOP_WEBHOOK_SECRET', '');", 'never an empty secret over a working one');

    // A pasted value brings its own newline; the signature it would produce is the
    // one nobody can verify, so the whitespace goes.
    $res = $saveThrough(['cfg[WHOP_WEBHOOK_SECRET]' => "  ws_typed_secret\r\n"]);
    t_is($res['status'], 302, 'a third save is accepted too');
    $typed = (string)file_get_contents($overrides);
    t_like($typed, "define('WHOP_WEBHOOK_SECRET', 'ws_typed_secret');",
        'a pasted secret is stripped of the whitespace a paste adds');

    // And the file that now exists is being LOADED: this install's config.php is the
    // repository's copy, which requires it first, so the strip has to say yes and
    // the red "saved — and not loaded" card has to be absent. The other branch is
    // pinned in §2b, where the unit can produce it on demand; proving the good
    // branch here is what keeps the check from being a warning nobody has seen work.
    $strip = t_http('GET', $app . '/admin/settings.php', ['cookie' => $cookie]);
    preg_match('/Loaded by config\.php:.{0,160}?yes ✓ \d+ setting/s', $strip['body'], $ovOk);
    t_ok($ovOk !== [], 'and the page reports the overrides file as loaded, with a count of what it states');
    t_unlike($strip['body'], 'Saved — and not loaded', 'rather than written and ignored');

    // And it names WHERE, which is the half of "I saved it and it is not there" that
    // the page used to leave the operator to guess: an addon domain's own htdocs/
    // next to the primary one looks exactly like a failed save from a file manager.
    preg_match('/File: <code>(.*?)<\/code>/s', $strip['body'], $shownPath);
    t_ok($shownPath !== [] && str_ends_with(str_replace('\\', '/', $shownPath[1]), '/storage/config_overrides.php'),
        'and prints the absolute path it writes to — the path an operator has to open in FTP');
    t_ok($shownPath !== [] && strlen($shownPath[1]) > strlen('storage/config_overrides.php') + 8,
        'as a full path, because the file name alone is the same on every copy of the site');
    t_like($strip['body'], 'loads it ✓',
        'and reports this installation\'s config.php as loading it, which is why the saved values are in effect');
    t_ok(preg_match('/Last written:.{0,140}?[\d,]+ bytes/s', $strip['body']) === 1,
        'and how large the file is and when it was last written');
} finally {
    $restore();
}

t_is(is_file($overrides), $before !== null,
    'the run leaves storage/config_overrides.php the way it found it');
