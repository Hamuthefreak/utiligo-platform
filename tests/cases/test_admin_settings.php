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
 *      still editable. §2 pins down that there is one writer, and §3 does what a
 *      static check cannot: saves the form for real.
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
    t_is($probe['status'], 200, 'a form posted back unchanged is accepted');
    t_like($probe['body'], 'Settings saved and opcache flushed', 'and really saves');

    $res = $saveThrough(['cfg[WHOP_WEBHOOK_SECRET]' => $secret]);
    t_is($res['status'], 200, 'saving the form is accepted');
    t_like($res['body'], 'Settings saved and opcache flushed', 'and says so');
    t_unlike($res['body'], $secret, 'without echoing the secret back');

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
    t_is($res['status'], 200, 'saving again with the secret field left blank is accepted');
    t_like($res['body'], 'Settings saved and opcache flushed', 'and really saves');
    $rewritten = (string)file_get_contents($overrides);
    preg_match("/^define\('WHOP_WEBHOOK_SECRET',.*\);$/m", $written, $wasLine);
    preg_match("/^define\('WHOP_WEBHOOK_SECRET',.*\);$/m", $rewritten, $nowLine);
    t_is($nowLine[0] ?? '', $wasLine[0] ?? '', 'and re-emits the stored secret line byte for byte');
    t_unlike($rewritten, "define('WHOP_WEBHOOK_SECRET', '');", 'never an empty secret over a working one');

    // A pasted value brings its own newline; the signature it would produce is the
    // one nobody can verify, so the whitespace goes.
    $res = $saveThrough(['cfg[WHOP_WEBHOOK_SECRET]' => "  ws_typed_secret\r\n"]);
    t_like($res['body'], 'Settings saved and opcache flushed', 'a third save is accepted too');
    $typed = (string)file_get_contents($overrides);
    t_like($typed, "define('WHOP_WEBHOOK_SECRET', 'ws_typed_secret');",
        'a pasted secret is stripped of the whitespace a paste adds');
} finally {
    $restore();
}

t_is(is_file($overrides), $before !== null,
    'the run leaves storage/config_overrides.php the way it found it');
