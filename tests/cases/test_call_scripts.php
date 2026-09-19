<?php
/**
 * Call scripts — includes/call_scripts.php and api/call-scripts.php.
 *
 * Three things are being defended, in order of how much they would hurt.
 *
 * 1. THE SEED HAPPENS ONCE. The panel's worst first impression is an empty box
 *    asking you to write a cold-call script, so the three starters are created
 *    on the first list. The far worse failure is the opposite: seeding again
 *    after a customer has deliberately deleted them, which turns a helpful
 *    default into something that keeps coming back. That is the assertion the
 *    marker in lead_activity_log exists for, and it is tested by deleting all
 *    three and listing again.
 *
 * 2. A SCRIPT IS THE CUSTOMER'S OWN WORDS. Bodies keep their blank lines and
 *    bullets (they are read aloud), names are forced onto one line (they are
 *    drawn in a header), and a placeholder with no value is left standing and
 *    reported rather than silently blanked — because a script that quietly says
 *    "Hi ," is discovered mid-call.
 *
 * 3. IT IS SOMEBODY'S PRIVATE PITCH. Every op is scoped to the caller, including
 *    reorder, which takes a list of ids and would otherwise be an obvious way to
 *    rewrite another account's ordering. There is a test per op.
 *
 * 4. IT IS BOTH PAID TIERS. It was briefly scoped to Pro alone, which made
 *    upgrading a downgrade — an Entrepreneur customer lost the dock the moment
 *    they paid more. The assertions below therefore pin BOTH directions: an
 *    Entrepreneur account is served the dock, and it still has every other paid
 *    feature too, so a future narrowing of the shared plan_has_pro_features()
 *    helper is caught here as well as in test_plan_ladder.php.
 */

$haveApp = !empty($context['app_url']) && !empty($context['db_ready']);

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. Names are labels
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A name is a label, not a paragraph');

t_is(call_script_name_clean("No website\n— first call"), 'No website — first call',
    'a name pasted across two lines is forced onto one');
t_is(call_script_name_clean('  Follow up   call  '), 'Follow up call',
    'runs of spaces collapse, so it cannot leave ragged gaps in a header');
t_is(call_script_name_clean("\t\n  "), '', 'a name with nothing printable in it is empty, not a space');
t_is(strlen(call_script_name_clean(str_repeat('x', 500))), CALL_SCRIPT_MAX_NAME,
    'and it is capped, because it is drawn in a header bar');
t_is(call_script_name_clean('Ünïcode — café'), 'Ünïcode — café',
    'multi-byte characters survive the cap rather than being cut mid-character');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. Bodies are verbatim
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A body keeps its shape, because it is read aloud');

t_is(call_script_body_clean("line one\r\nline two"), "line one\nline two",
    'CRLF line endings are canonicalised');
t_is(call_script_body_clean("line one\rline two"), "line one\nline two",
    'and so are old Mac endings');
t_is(call_script_body_clean("Hi there,   \n[wait]   \n"), "Hi there,\n[wait]",
    'trailing spaces are stripped and a trailing blank line is dropped');
t_is(call_script_body_clean("\n\nOne\n\nTwo\n\n"), "One\n\nTwo",
    'leading and trailing blank lines go, but the interior ones stay exactly');
t_is(call_script_body_clean("One\n\n\n\nTwo"), "One\n\n\n\nTwo",
    'and several blank lines in a row are not quietly collapsed into one');
t_is(strlen(call_script_body_clean(str_repeat('y', 20000))), CALL_SCRIPT_MAX_BODY,
    'a body is capped so one paste cannot bloat every page load');

t_section('Validation names the problem instead of storing something useless');

$ok = call_script_validate(['name' => ' Opener ', 'body' => ' Hello ']);
t_ok($ok['ok'], 'a name and a body is a script');
t_is($ok['name'], 'Opener', 'and both come back cleaned');
t_is($ok['body'], 'Hello', 'so the caller stores exactly what will be shown');

t_is(call_script_validate(['name' => '   ', 'body' => 'text'])['error'], 'invalid_name',
    'a nameless script is refused — an unnamed row in a switcher is unusable');
t_is(call_script_validate(['name' => 'Name', 'body' => "  \n \n "])['error'], 'invalid_body',
    'and so is an empty one');
t_is(call_script_validate(['name' => 'N', 'body' => 'b'])['name'], 'N',
    'the refusal carries what it salvaged, so a form need not be re-typed');

/* ─────────────────────────────────────────────────────────────────────────────
 * 3. Starters
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The panel is never an empty box on first open');

$starters = call_script_starters();
t_is(count($starters), 3, 'three starter scripts');
t_ok(count(array_unique(array_column($starters, 'name'))) === 3, 'with distinct names');
foreach ($starters as $i => $s) {
    t_ok(call_script_name_clean($s['name']) === $s['name'] && $s['name'] !== '',
        'starter ' . ($i + 1) . ' has a usable name');
    t_ok(call_script_body_clean($s['body']) === $s['body'] && $s['body'] !== '',
        'starter ' . ($i + 1) . ' is already clean, so it cannot trip its own validator');
    $v = call_script_validate($s);
    t_ok($v['ok'], 'and starter ' . ($i + 1) . ' passes validation as written');
    t_like($s['body'], '{{business_name}}',
        'starter ' . ($i + 1) . ' names the business, which is what shows the feature off on first use');
}

/* ─────────────────────────────────────────────────────────────────────────────
 * 4. Bulk import
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Pasting several scripts at once');

$blob = "No website\n\nHi, is this the owner?\n\n---\n\nFollow-up\n\nHi, it is me again.\n";
$parsed = call_script_parse_import($blob);
t_is(count($parsed['scripts']), 2, 'a line of dashes separates one script from the next');
t_is($parsed['scripts'][0]['name'], 'No website', 'the first line of each block is its name');
t_is($parsed['scripts'][0]['body'], 'Hi, is this the owner?', 'and the rest is the script');
t_is($parsed['scripts'][1]['name'], 'Follow-up', 'the second block is a script of its own');
t_is($parsed['skipped'], 0, 'nothing was skipped');

$fancy = call_script_parse_import("  -----  \n# No website:\n\nHi.\n\n----------\nSecond\n\nBye.");
t_is(count($fancy['scripts']), 2, 'longer separators and surrounding spaces still split');
t_is($fancy['scripts'][0]['name'], 'No website',
    'a leading # and a trailing colon are tolerated — that is how people type a heading');

$partial = call_script_parse_import("Has a name but nothing else\n\n---\n\nReal script\n\nBody here");
t_is(count($partial['scripts']), 1, 'a heading with no text under it is not a script');
t_is($partial['skipped'], 1, 'and is reported as skipped rather than silently dropped');

t_is(count(call_script_parse_import("Just one script\n\nand its text")['scripts']), 1,
    'a single script with no separators at all is still imported');
t_is(call_script_parse_import("Just one script\n\nand its text")['scripts'][0]['name'], 'Just one script',
    'taking its name from the first line, same as any other block');
// One line on its own is a heading with no text under it, which is the same
// mistake as a stray separator — reported, not stored as a nameless script.
t_is(count(call_script_parse_import('One line and nothing else')['scripts']), 0,
    'a pasted line with no script under it is not imported');
t_is(call_script_parse_import('One line and nothing else')['skipped'], 1,
    'and is counted as skipped, so the count adds up for the customer');
t_is(count(call_script_parse_import("   \n\n  ")['scripts']), 0, 'blank input imports nothing');
t_is(count(call_script_parse_import("---\n---\n---")['scripts']), 0,
    'nothing but separators imports nothing');

/* ─────────────────────────────────────────────────────────────────────────────
 * 5. Ordering
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The customer decides the order, and it never shuffles on its own');

$shuffled = [
    ['id' => 3, 'sort_order' => 30],
    ['id' => 1, 'sort_order' => 10],
    ['id' => 2, 'sort_order' => 20],
];
t_same_list(array_column(call_script_order($shuffled), 'id'), [1, 2, 3],
    'scripts come back in the customer order, not the storage order');

$tied = [
    ['id' => 9, 'sort_order' => 10],
    ['id' => 4, 'sort_order' => 10],
    ['id' => 7, 'sort_order' => 10],
];
t_same_list(array_column(call_script_order($tied), 'id'), [4, 7, 9],
    'equal positions fall back to oldest-first, so two page loads cannot disagree');

t_is(call_script_next_order($shuffled), 40, 'a new script goes on after the last one');
t_is(call_script_next_order([]), CALL_SCRIPT_ORDER_STEP,
    'a first script starts at one step, so there is always room to insert before it');
t_ok(call_script_next_order($shuffled) - 30 >= 2,
    'and it lands with a gap, so reordering two neighbours can write one row instead of all of them');

/* ─────────────────────────────────────────────────────────────────────────────
 * 6. Placeholders
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Placeholders fill from the lead, and are never guessed');

$lead = ['business_name' => 'Bright Smile Dental', 'business_city' => 'Vancouver',
         'business_category' => 'Dentist', 'business_phone' => '604-555-0134'];

$f = call_script_fill('Hi {{business_name}}, is now a good time?', $lead);
t_is($f['body'], 'Hi Bright Smile Dental, is now a good time?', 'a placeholder becomes the real value');
t_same_list($f['filled'], ['business_name'], 'and the panel is told what it filled');
t_same_list($f['missing'], [], 'with nothing left unfilled');

$f2 = call_script_fill('Hi {{ business_name }}, and {{business_name}} again', $lead);
t_is($f2['body'], 'Hi Bright Smile Dental, and Bright Smile Dental again',
    'spaces inside the braces are tolerated');
t_same_list($f2['filled'], ['business_name'], 'and a repeated token is reported once, not once per use');

$f3 = call_script_fill('Are you the owner of {{business_name}}?', []);
t_is($f3['body'], 'Are you the owner of {{business_name}}?',
    'with no lead open the token is left standing, not blanked');
t_same_list($f3['missing'], ['business_name'], 'and it is reported as unfilled');
t_unlike($f3['body'], 'Are you the owner of ?', 'the sentence never degrades into a gap mid-call');

$f4 = call_script_fill('Hi {{business_name}}, from {{sender_name}}', $lead, ['sender_name' => 'Dana']);
t_is($f4['body'], 'Hi Bright Smile Dental, from Dana', 'the account name fills from its own source');
t_ok($f4['fromLead'], 'and a lead was in play');

$f5 = call_script_fill('From {{sender_name}}', [], ['sender_name' => 'Dana']);
t_is($f5['body'], 'From Dana', 'a script that only needs the account name still fills');
t_ok(!$f5['fromLead'], 'without pretending a lead was involved');

$f6 = call_script_fill('Hello {{businessname}}', $lead);
t_same_list($f6['missing'], ['businessname'],
    'a misspelled token is reported, not silently swallowed');
t_is($f6['body'], 'Hello {{businessname}}', 'and is left visible to be fixed');

t_same_list(call_script_tokens('a {{one}} b {{two}} c {{one}}'), ['one', 'two'],
    'the token list is de-duplicated');
t_same_list(call_script_tokens('no tokens here'), [], 'and empty when there are none');

/* ─────────────────────────────────────────────────────────────────────────────
 * 7. The feature registry
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Call scripts are a paid feature, and the paid tiers are a ladder');

t_ok(has_feature('call_scripts', 'pro'), 'Pro has call scripts');
t_ok(has_feature('call_scripts', 'entrepreneur'),
    'and so does Entrepreneur — the tiers are a ladder, not a set');
t_ok(!has_feature('call_scripts', 'free'), 'Free does not');

t_ok(can_use_call_scripts('pro'), 'the gate says Pro');
t_ok(can_use_call_scripts('entrepreneur'),
    'and Entrepreneur, so upgrading never takes the dock away from the customer who paid more');
t_ok(!can_use_call_scripts('free'), 'and refuses Free');
t_ok(!can_use_call_scripts(null), 'and a missing plan, rather than erroring on it');
t_ok(!can_use_call_scripts(''), 'and an empty one');

// Guards on the shared helper, because the wrong way to scope this feature is to
// narrow plan_has_pro_features() — which would take lead search, export and
// enrichment from every Entrepreneur customer as a side effect.
t_ok(plan_has_pro_features('entrepreneur'), 'Entrepreneur still has every paid feature');
t_ok(can_use_lead_workspace('entrepreneur'), 'including the lead workspace they pay for');

/* ─────────────────────────────────────────────────────────────────────────────
 * 8. The endpoint
 * ──────────────────────────────────────────────────────────────────────────── */

if (!$haveApp) {
    t_skip('the call-script endpoint', 'requires the application server and the database');
    return;
}

$app = $context['app_url'];
$pdo = t_platform_db();

/** POST to the endpoint as a user, with a fresh valid CSRF pair. */
$post = function (int $userId, array $body, ?string $csrf = null) use ($app): array {
    $token = $csrf ?? bin2hex(random_bytes(32));
    $res = t_http('POST', $app . '/api/call-scripts.php', [
        'json'   => $body + ['csrf_token' => $token],
        'cookie' => t_login($userId, ['csrf_token' => $token]),
    ]);
    return [
        'status' => $res['status'],
        'json'   => json_decode($res['body'], true) ?: [],
        'body'   => $res['body'],
    ];
};

// full_name is set on the ROW, not passed to t_login: the layout reads the name
// from the account record, so a session override would assert nothing.
$freeUser = t_fixture(['plan' => 'free']);
$proUser  = t_fixture(['plan' => 'pro', 'full_name' => 'Dana Reid']);
$entUser  = t_fixture(['plan' => 'entrepreneur', 'full_name' => 'Ada Ent']);
$other    = t_fixture(['plan' => 'pro']);

/** Wipe one account's scripts and seed marker, so a test starts from nothing. */
/** Wipe one account's scripts and clear its seed stamp, so a test starts fresh. */
$reset = function (int $uid) use ($pdo): void {
    $pdo->prepare('DELETE FROM call_scripts WHERE user_id = ?')->execute([$uid]);
    t_db()->prepare('UPDATE utiligo_users SET call_script_seeded_at = NULL WHERE id = ?')->execute([$uid]);
};

t_section('The dock is closed to the free tier');

$refused = $post($freeUser, ['op' => 'list']);
t_is($refused['status'], 403, 'a free account cannot list scripts');
t_is($refused['json']['error'] ?? '', 'plan_required', 'and is told it is a plan limit');
t_is($post($freeUser, ['op' => 'create', 'name' => 'x', 'body' => 'y'])['status'], 403,
    'nor create one');

// Deliberately NOT the $post helper: it gives the session and the payload the
// same token, so asking it for a "wrong" one produces a request where the two
// agree and the check passes. The mismatch has to be built by hand.
$badCsrf = t_http('POST', $app . '/api/call-scripts.php', [
    'json'   => ['op' => 'list', 'csrf_token' => 'not-the-right-token'],
    'cookie' => t_login($proUser, ['csrf_token' => bin2hex(random_bytes(32))]),
]);
t_is($badCsrf['status'], 403, 'a CSRF token that does not match the session is refused');
t_is(json_decode($badCsrf['body'], true)['error'] ?? '', 'invalid_csrf', 'and named as such');

$anon = t_http('POST', $app . '/api/call-scripts.php', ['json' => ['op' => 'list']]);
t_is($anon['status'], 302, 'a signed-out request is redirected to login, not served');

t_section('Every op is scoped to the account that asked');

$reset($proUser); $reset($other); $reset($entUser);

$mine = $post($proUser, ['op' => 'create', 'name' => 'My opener', 'body' => 'Hello there.']);
t_is($mine['status'], 200, 'a Pro account can create a script');
$mineId = (int)($mine['json']['id'] ?? 0);
t_ok($mineId > 0, 'and gets its id back');

t_is($post($other, ['op' => 'update', 'id' => $mineId, 'name' => 'Stolen', 'body' => 'x'])['status'],
    404, 'another account cannot rename it');
t_is($post($other, ['op' => 'delete', 'id' => $mineId])['status'],
    404, 'nor delete it');
t_is($post($other, ['op' => 'touch', 'id' => $mineId])['status'],
    404, 'nor count a use against it');

$theirList = $post($other, ['op' => 'list']);
$theirNames = array_column($theirList['json']['scripts'] ?? [], 'name');
t_unlike(implode('|', $theirNames), 'My opener', 'and it is not in their list');

// reorder is the op most likely to be written unguarded: it takes a list of ids
// and rewrites positions. Foreign ids must be ignored, not applied — which is
// asserted by the position NOT moving, rather than by the value it holds, since
// a lone script legitimately sits at exactly CALL_SCRIPT_ORDER_STEP.
$readOrder = $pdo->prepare('SELECT sort_order FROM call_scripts WHERE id = ?');
$readOrder->execute([$mineId]);
$beforeOrder = (int)$readOrder->fetchColumn();

$reorder = $post($other, ['op' => 'reorder', 'ids' => [$mineId]]);
t_is($reorder['status'], 200, 'a reorder carrying a foreign id is not an error');
t_is($reorder['json']['applied'] ?? -1, 0, 'but it applies nothing');

$readOrder->execute([$mineId]);
t_is((int)$readOrder->fetchColumn(), $beforeOrder,
    'and the other account\'s script was not moved at all');

t_is($post($proUser, ['op' => 'update', 'id' => $mineId, 'name' => 'My opener', 'body' => 'Hello.'])['status'],
    200, 're-saving a script with unchanged values succeeds');
t_is($post($proUser, ['op' => 'update', 'id' => $mineId, 'name' => '', 'body' => 'x'])['status'],
    400, 'but a save that would leave it unnamed is refused');

t_section('The starters appear once, and only once');

$reset($proUser);
$first = $post($proUser, ['op' => 'list']);
t_is(count($first['json']['scripts'] ?? []), 3, 'the first list seeds three starter scripts');
t_ok($first['json']['seeded'] ?? false, 'and says that it did');
t_is($first['json']['cap'] ?? 0, CALL_SCRIPTS_PER_USER_CAP, 'the response carries the hard cap');
t_ok(isset($first['json']['fields']['business_name']),
    'and the placeholder catalogue the panel prints as hints');

$second = $post($proUser, ['op' => 'list']);
t_is(count($second['json']['scripts'] ?? []), 3, 'a second list does not add any more');
t_ok(!($second['json']['seeded'] ?? true), 'and reports that it did not seed');

// The real failure mode being prevented: deleting the defaults and having them
// come back on the next page load, forever.
$starterIds = array_column($first['json']['scripts'], 'id');
foreach ($starterIds as $sid) {
    $post($proUser, ['op' => 'delete', 'id' => $sid]);
}
$afterDelete = $post($proUser, ['op' => 'list']);
t_is(count($afterDelete['json']['scripts'] ?? []), 0,
    'deleting every starter leaves an empty panel — they do not come back');
t_ok(!($afterDelete['json']['seeded'] ?? true), 'and the response does not claim to have seeded');
t_ok(!empty(t_user($proUser)['call_script_seeded_at']),
    'with the account stamped, so no later visit can seed them again');

t_is(count($post($entUser, ['op' => 'list'])['json']['scripts'] ?? []), 3,
    'an Entrepreneur account is seeded too — the feature is theirs as much as Pro\'s');
t_ok(!empty(t_user($entUser)['call_script_seeded_at']),
    'and stamped as seeded like any other account');

t_section('A delimited paste becomes several scripts');

$reset($proUser);
$imported = $post($proUser, ['op' => 'import', 'blob' =>
    "Opener\n\nHi, is this the owner of {{business_name}}?\n\n---\n\nFollow-up\n\nHi again.\n\n---\n\nNameless block"]);
t_is($imported['status'], 200, 'an import succeeds');
t_is($imported['json']['created'] ?? 0, 2, 'and creates both scripts that had text under a name');
t_is($imported['json']['skipped'] ?? -1, 1, 'reporting the block that had a heading and nothing else');
t_is($imported['json']['dropped'] ?? -1, 0, 'with nothing dropped, because there was room');

$list = $post($proUser, ['op' => 'list']);
$names = array_column($list['json']['scripts'] ?? [], 'name');
t_same_list($names, ['Opener', 'Follow-up'], 'they arrive in the order they were pasted');

t_is($post($proUser, ['op' => 'import', 'blob' => "Only script\n\nwith no separators"])['json']['created'] ?? 0,
    1, 'a paste with one script and no separators still imports it');
t_is($post($proUser, ['op' => 'import', 'blob' => "---\n---"])['status'], 400,
    'and a paste with nothing in it is refused rather than half-imported');
t_is($post($proUser, ['op' => 'import', 'blob' => "---\n---"])['json']['error'] ?? '', 'invalid_format',
    'with the error naming the format, which is the only thing they can act on');

t_section('The cap refuses rather than silently truncating a paste');

$reset($other);
// Filled directly so the test does not make two hundred HTTP calls to prove a
// boundary that only the boundary itself is interesting for.
$fill = $pdo->prepare('INSERT INTO call_scripts (user_id, name, body, sort_order, source, created_at)
                       VALUES (?, ?, ?, ?, "manual", NOW())');
$pdo->beginTransaction();
for ($i = 1; $i <= CALL_SCRIPTS_PER_USER_CAP - 1; $i++) {
    $fill->execute([$other, 'Script ' . $i, 'Body ' . $i, $i * CALL_SCRIPT_ORDER_STEP]);
}
$pdo->commit();

$oneMore = $post($other, ['op' => 'create', 'name' => 'The last one', 'body' => 'Body']);
t_is($oneMore['status'], 200, 'the last slot under the cap is usable');
t_is($post($other, ['op' => 'create', 'name' => 'Over', 'body' => 'x'])['status'], 409,
    'and the next one is refused');
t_is($post($other, ['op' => 'create', 'name' => 'Over', 'body' => 'x'])['json']['error'] ?? '', 'cap_reached',
    'with an error the panel can explain');

// A paste that does not fit is reported, never quietly trimmed.
$overflow = $post($other, ['op' => 'import', 'blob' => "A\n\nbody\n\n---\n\nB\n\nbody\n\n---\n\nC\n\nbody"]);
t_is($overflow['json']['created'] ?? -1, 0, 'a paste with no room creates nothing');
t_is($overflow['json']['dropped'] ?? -1, 3, 'and says how many did not fit, instead of dropping them silently');

t_section('Using a script records that it was used');

$reset($other);
$made = $post($other, ['op' => 'create', 'name' => 'Opener', 'body' => 'Body']);
$madeId = (int)($made['json']['id'] ?? 0);

$post($other, ['op' => 'touch', 'id' => $madeId]);
$post($other, ['op' => 'touch', 'id' => $madeId]);
$row = $pdo->query('SELECT times_used, last_used_at FROM call_scripts WHERE id = ' . $madeId)->fetch(PDO::FETCH_ASSOC);
t_is((int)($row['times_used'] ?? 0), 2, 'the use counter climbs');
t_ok(!empty($row['last_used_at']), 'and the last-used stamp is set, so the switcher can float it');

$ordered = $post($other, ['op' => 'reorder', 'ids' => [$madeId]]);
t_is($ordered['json']['applied'] ?? -1, 1, 'a reorder of a script this account owns applies');

t_section('The dock is delivered to both paid tiers, and to nobody else');

$freeDash = t_http('GET', $app . '/portal/index.php', ['cookie' => t_login($freeUser)]);
t_is($freeDash['status'], 200, 'a free account can still load the dashboard');
t_unlike($freeDash['body'], 'call_scripts.js', 'but is not sent the dock script at all');
t_unlike($freeDash['body'], 'call_scripts.css', 'nor its stylesheet');
t_unlike($freeDash['body'], 'UTILIGO_CALL_SCRIPTS', 'nor the config that would enable it');

// The gate is applied at the door — the stylesheet and the script are what an
// account is entitled to, so the layout must decide, not the endpoint.
$entDash = t_http('GET', $app . '/portal/index.php', ['cookie' => t_login($entUser)]);
t_is($entDash['status'], 200, 'an Entrepreneur account loads the dashboard');
t_like($entDash['body'], 'call_scripts.js', 'and IS sent the dock script, like Pro');
t_like($entDash['body'], 'call_scripts.css', 'with its stylesheet');
t_like($entDash['body'], 'UTILIGO_CALL_SCRIPTS', 'and the config');
t_like($entDash['body'], 'Ada Ent', 'signed with their own name, not somebody else\'s');

$proDash = t_http('GET', $app . '/portal/index.php', ['cookie' => t_login($proUser, ['full_name' => 'Dana Reid'])]);
t_is($proDash['status'], 200, 'a Pro account loads the dashboard');
t_like($proDash['body'], 'call_scripts.js', 'and is sent the dock script');
t_like($proDash['body'], 'call_scripts.css', 'with its stylesheet, so it is styled before it runs');
t_like($proDash['body'], 'UTILIGO_CALL_SCRIPTS', 'and the config it needs');
t_like($proDash['body'], 'senderName', 'including the name it signs scripts with');
t_like($proDash['body'], 'Dana Reid', 'and that name is the account holder\'s');

t_section('The pop-out window is behind the same gate');

$freePop = t_http('GET', $app . '/portal/call-scripts-window.php', ['cookie' => t_login($freeUser)]);
t_is($freePop['status'], 302, 'a free account is redirected away from the pop-out');
t_like((string)$freePop['location'], 'billing', 'to the page that sells it');

$entPop = t_http('GET', $app . '/portal/call-scripts-window.php', ['cookie' => t_login($entUser)]);
t_is($entPop['status'], 200, 'an Entrepreneur account gets the window too');
t_like($entPop['body'], 'standalone: true', 'in the same standalone shape');

$proPop = t_http('GET', $app . '/portal/call-scripts-window.php', ['cookie' => t_login($proUser)]);
t_is($proPop['status'], 200, 'a Pro account gets the window');
t_like($proPop['body'], 'standalone: true', 'told to fill the window rather than float in a page');
t_like($proPop['body'], 'data-csrf', 'with a CSRF token, so its saves are accepted');
t_unlike($proPop['body'], 'leadsRail', 'and no portal chrome — it is a panel, not a page');

/* ── Clean up ──────────────────────────────────────────────────────────── */

foreach ([$freeUser, $proUser, $entUser, $other] as $uid) {
    try {
        $pdo->prepare('DELETE FROM call_scripts WHERE user_id = ?')->execute([$uid]);
        t_db()->prepare('UPDATE utiligo_users SET call_script_seeded_at = NULL WHERE id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM lead_activity_log WHERE user_id = ?')->execute([$uid]);
    } catch (Throwable $e) { /* best effort */ }
}
