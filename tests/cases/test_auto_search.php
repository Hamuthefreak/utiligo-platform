<?php
/**
 * Saved searches that run themselves — includes/auto_search.php and
 * cron/scheduled_searches.php.
 *
 * The bug this covers: `notify_email` promised "email me new leads on this
 * search" and delivered only whatever OTHER people's searches happened to drop
 * into the shared pool. A customer watching a quiet city got nothing, or an email
 * saying nothing, for as long as they stayed subscribed — on the one feature only
 * the Entrepreneur plan has.
 *
 * Two halves. The pure scheduling table is asserted directly, because "should
 * this run" is the decision that decides how much Google Places quota the business
 * spends. The automation itself is then run for real: the real cron over HTTP,
 * against the real database, with the email landing in tests/lib/mail_stub.php.
 */

if (empty($context['app_url']) || empty($context['db_ready'])) {
    throw new T_Skip('the application server and the database are required for this file');
}
if (empty($context['mail_stub_url'])) {
    throw new T_Skip('the mail stub server is not running (is MAIL_API_BASE reachable?)');
}

$app  = $context['app_url'];
$pdo  = t_platform_db();
$cron = $app . '/cron/scheduled_searches.php?secret='
      . urlencode(defined('CRON_SECRET') ? CRON_SECRET : 'cron_test_secret');

t_reset_mail_stub();

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. When a saved search runs
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The cadence menu');

$cadences = auto_search_cadences();
t_is(auto_search_normalize_cadence(6), 6, 'six hours is a real choice');
t_is(auto_search_normalize_cadence('24'), 24, 'and so is a string from a form');
t_is(auto_search_normalize_cadence(1), auto_search_default_cadence(),
    'anything under the floor falls back to the default rather than being honoured');
t_is(auto_search_normalize_cadence(0), auto_search_default_cadence(), 'zero means default, not "every tick"');
t_is(auto_search_normalize_cadence(9999), auto_search_default_cadence(), 'and so does a value that is not on the menu');
t_is(auto_search_cadence_label(9999), $cadences[auto_search_default_cadence()], 'an off-menu cadence still renders');

t_section('When a saved search is due');

$now = 1_800_000_000;
$at  = static fn(int $secondsAgo): string => date('Y-m-d H:i:s', $now - $secondsAgo);

t_ok(!auto_search_is_due(['notify_email' => 0, 'last_enqueued_at' => null], $now),
    'a saved search nobody asked to be emailed about never runs itself');
t_ok(auto_search_is_due(['notify_email' => 1, 'last_enqueued_at' => null], $now),
    'one that was just switched on runs at the next pass');
t_ok(!auto_search_is_due(['notify_email' => 1, 'last_enqueued_at' => $at(86400 * 30), 'job_token' => 'abc'],
        $now),
    'a run already in flight blocks a second one, however overdue');

$daily = ['notify_email' => 1, 'run_every_hours' => 24];
t_ok(!auto_search_is_due($daily + ['last_enqueued_at' => $at(3600 * 23)], $now),
    '23 hours into a 24-hour cadence it is not due');
t_ok(auto_search_is_due($daily + ['last_enqueued_at' => $at(3600 * 24)], $now),
    'exactly at the cadence it is due');
t_ok(auto_search_is_due($daily + ['last_enqueued_at' => $at(3600 * 25)], $now),
    'and after a cron outage it catches up rather than waiting another day');

t_ok(!auto_search_is_due(['notify_email' => 1, 'run_every_hours' => 6, 'last_enqueued_at' => $at(3600 * 5)],
        $now),
    'the customer\'s chosen cadence is what is measured, not the default');
t_ok(auto_search_is_due(['notify_email' => 1, 'run_every_hours' => 6, 'last_enqueued_at' => $at(3600 * 6)],
        $now),
    'and it runs on the hour they chose');
t_ok(!auto_search_is_due(['notify_email' => 1, 'last_enqueued_at' => $at(3600 * 23)], $now),
    'a row from before migration 025 (no cadence yet) is treated as daily');
t_ok(auto_search_is_due(['notify_email' => 1, 'last_enqueued_at' => 'not a date'],
        $now),
    'an unreadable timestamp cannot mean "never runs again"');

t_section('What an automated run asks for');

$params = auto_search_run_params([
    'city'          => 'Vancouver',
    'industry'      => 'Dentist',
    'keywords'      => 'implants',
    'lead_count'    => 40,
    'force_refresh' => true,
    'sources'       => ['google_places', 'osm', 'osm', ''],
]);

t_is($params['force_refresh'], false,
    'it never forces a refresh — a cached run is the difference between an affordable daily automation and a Places bill');
t_is($params['city'], 'Vancouver', 'the saved city carries over');
t_is($params['industry'], 'Dentist', 'and the industry');
t_is($params['keywords'], 'implants', 'and the keywords');
t_is($params['lead_count'], (int)AUTO_SEARCH_LEAD_COUNT, 'the run is bounded to the automation\'s own lead count');
t_not($params['lead_count'], 40, 'not whatever the browser once asked for');
t_same_list($params['sources'], ['google_places', 'osm'], 'the source list is de-duplicated and blank entries dropped');
t_is($params['auto'], true, 'the job is marked as automated');

t_ok(auto_search_is_searchable(['city' => 'Burnaby']), 'a city alone is enough to search');
t_ok(auto_search_is_searchable(['keywords' => 'roofing']), 'so is a keyword');
t_ok(!auto_search_is_searchable(['city' => '', 'industry' => '  ', 'keywords' => '']),
    'a saved search with nothing in it is not searched');

t_section('What the digest reports');

$digest = auto_search_digest([
    'leads' => [
        ['id' => 11, 'business_name' => 'Acme Dental', 'business_city' => 'Vancouver', 'business_phone' => '555-1'],
        ['id' => 12, 'business_name' => 'Bright Smile', 'business_city' => 'Burnaby'],
    ],
    'locked_leads' => [
        ['id' => 99, 'business_name' => '', 'business_phone' => '', '_locked' => true],
    ],
    'from_cache' => true,
    'is_free_tier' => true,
]);

t_is($digest['count'], 2, 'it counts the leads the run returned');
t_is($digest['leads'][0]['business_name'], 'Acme Dental', 'and carries the names through');
t_ok($digest['from_cache'], 'whether it came from the cache is kept for the audit row');
t_is(count($digest['leads']), 2,
    'locked leads are NOT included — an account downgraded while its job queued must not be emailed contacts it no longer pays for');
t_is(auto_search_subject('Vancouver dentists', 2), '2 new leads for: Vancouver dentists', 'the subject says what it found');
t_is(auto_search_subject('Vancouver dentists', 1), '1 new lead for: Vancouver dentists', 'and gets the singular right');

t_is(auto_search_digest([])['count'], 0, 'a malformed result payload reports nothing rather than throwing');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. The real cron, the real database, the real email
 * ──────────────────────────────────────────────────────────────────────────── */

/** Seed a saved search owned by $uid. */
$seed = function (int $uid, array $overrides = []) use ($pdo): int {
    $row = $overrides + [
        'user_id'      => $uid,
        'name'         => 'Test search ' . bin2hex(random_bytes(3)),
        'params'       => json_encode(['city' => 'Escalade', 'industry' => 'Cafe']),
        'notify_email' => 1,
    ];

    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO saved_searches (`' . implode('`, `', $cols) . '`) VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));

    return (int)$pdo->lastInsertId();
};

$savedSearch = function (int $id) use ($pdo): array {
    $s = $pdo->prepare('SELECT * FROM saved_searches WHERE id = ? LIMIT 1');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
};

$autoJobFor = function (int $uid) use ($pdo): ?array {
    $s = $pdo->prepare("SELECT * FROM lead_search_jobs WHERE user_id = ? AND auto = 1 ORDER BY id DESC LIMIT 1");
    $s->execute([$uid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};

$autoJobCount = function (int $uid) use ($pdo): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM lead_search_jobs WHERE user_id = ? AND auto = 1');
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
};

/** Finish a job the way the worker would. */
$complete = function (string $token, array $result) use ($pdo) {
    $pdo->prepare("UPDATE lead_search_jobs SET status = 'done', progress = 100, stage = 'Done',
                          result_json = ?, finished_at = NOW() WHERE token = ?")
        ->execute([json_encode($result), $token]);
};

$pass = function () use ($cron): array {
    $res = t_http('GET', $cron);
    return ['status' => $res['status'], 'body' => trim($res['body'])];
};

$lead = static fn(int $id, string $name): array => [
    'id' => $id, 'business_name' => $name, 'business_category' => 'Cafe',
    'business_city' => 'Escalade', 'business_phone' => '555-0100',
    'business_email' => '', 'website' => '',
];

t_section('A due saved search starts a real search');

$runEmail = 'auto' . bin2hex(random_bytes(4)) . '@example.test';
$runUser  = t_fixture(['plan' => 'entrepreneur', 'email' => $runEmail]);
$runSs    = $seed($runUser);

t_reset_mail_stub();
$first = $pass();

t_is($first['status'], 200, 'the cron answers');
t_like($first['body'], 'started=1', 'and starts the one search that is due');

$row = $savedSearch($runSs);
t_ok(trim((string)$row['job_token']) !== '', 'the saved search remembers which run it is waiting on');
t_ok(trim((string)$row['last_enqueued_at']) !== '', 'and when that run started, which is what bounds the next one');

$job = $autoJobFor($runUser);
t_ok($job !== null, 'a job was enqueued for it');
t_is((int)($job['auto'] ?? 0), 1, 'marked as automated');
t_is((string)($job['token'] ?? ''), (string)$row['job_token'], 'and it is the job the saved search names');
$jobParams = json_decode((string)($job['params_json'] ?? ''), true) ?: [];
t_is($jobParams['city'] ?? '', 'Escalade', 'the job carries the saved search\'s city');
t_is($jobParams['force_refresh'] ?? true, false, 'and does not force a fresh crawl');

t_section('It does not start a second run while one is outstanding');

$second = $pass();
t_like($second['body'], 'started=0', 'the next pass starts nothing');
t_is($autoJobCount($runUser), 1, 'and there is still exactly one automated job for this account');

t_section('A finished run is emailed, once');

$complete((string)$row['job_token'], [
    'success' => true,
    'leads'   => [$lead(1, 'Cafe Alpha'), $lead(2, 'Cafe Beta')],
    'from_cache' => true,
]);

$mails = t_mail_sent_to($runEmail);
t_is(count($mails), 0, 'nothing has been sent before the cron reports the run');

$third = $pass();
t_like($third['body'], 'emails=1', 'the pass sends one email');

$mails = t_mail_sent_to($runEmail);
t_is(count($mails), 1, 'exactly one email reached the customer');
t_is($mails[0]['subject'], '2 new leads for: ' . $savedSearch($runSs)['name'], 'with a subject naming what was found');
t_like($mails[0]['html'], 'Cafe Alpha', 'and a body listing the leads');
t_like($mails[0]['html'], 'Cafe Beta', 'all of them');
t_like($mails[0]['text'], '/portal/leads.php', 'and a plain-text fallback pointing at the workspace');

// The digest is what the customer reads first, and "here is who to contact" is
// worth much less than "here is what to say". Each lead carries the opening line
// of the email the workspace would draft for it.
t_like($mails[0]['html'], 'I came across Cafe Alpha',
    'and the opening line of the outreach draft for each lead');
t_like($mails[0]['html'], 'no website on your Google listing',
    'built from the evidence on the row');

$row = $savedSearch($runSs);
t_is((int)$row['last_count'], 2, 'the saved search records how many were found');
t_is(trim((string)$row['job_token']), '', 'and is released, so it can run again');
t_is((string)$row['last_error'], '', 'with no error left behind');
t_ok(trim((string)$row['last_run_at']) !== '', 'and a last-run stamp for the drawer');

t_section('It is not reported a second time');

$fourth = $pass();
t_like($fourth['body'], 'reported=0', 'the next pass has nothing to report');
t_is(count(t_mail_sent_to($runEmail)), 1, 'and the customer is not emailed twice for one run');

t_section('A quiet run sends nothing');

$quietEmail = 'quiet' . bin2hex(random_bytes(4)) . '@example.test';
$quietUser  = t_fixture(['plan' => 'entrepreneur', 'email' => $quietEmail]);
$quietSs    = $seed($quietUser);

t_reset_mail_stub();
$pass();
$quietJob = $autoJobFor($quietUser);
$complete((string)($quietJob['token'] ?? ''), ['success' => true, 'leads' => [], 'from_cache' => false]);
$pass();

t_is(count(t_mail_sent_to($quietEmail)), 0,
    'a run that found nothing sends no email — a daily "0 new leads" is how a useful notification gets filtered out');
$quietRow = $savedSearch($quietSs);
t_is((int)$quietRow['last_count'], 0, 'but the run is still recorded');
t_is(trim((string)$quietRow['job_token']), '', 'and the saved search is released rather than stuck');
t_ok(trim((string)$quietRow['last_run_at']) !== '', 'so the drawer can show that it ran');

t_section('A failed run is recorded, not emailed');

$failUser = t_fixture(['plan' => 'entrepreneur']);
$failSs   = $seed($failUser);

t_reset_mail_stub();
$pass();
$failJob = $autoJobFor($failUser);
$pdo->prepare("UPDATE lead_search_jobs SET status = 'error', error_code = 'places_unavailable',
                      error_message = 'The lead source could not be reached.', finished_at = NOW()
                WHERE token = ?")->execute([(string)($failJob['token'] ?? '')]);

$pass();
t_is(count(t_mail_sent()), 0,
    'a failure is not emailed — the customer cannot fix a missing API key, and one alarm would have them turning the feature off');
$failRow = $savedSearch($failSs);
t_like((string)$failRow['last_error'], 'The lead source could not be reached', 'the reason is kept for the drawer and the log');
t_is(trim((string)$failRow['job_token']), '', 'and the saved search is released so it is not wedged by one bad run');

t_section('A run whose job has been purged is released, not stranded');

$lostUser = t_fixture(['plan' => 'entrepreneur']);
$lostSs   = $seed($lostUser);

$pass();
$lostRow = $savedSearch($lostSs);
$pdo->prepare('DELETE FROM lead_search_jobs WHERE token = ?')->execute([(string)$lostRow['job_token']]);

t_reset_mail_stub();
$pass();
$lostRow = $savedSearch($lostSs);
t_is(trim((string)$lostRow['job_token']), '', 'the token is cleared when the queue no longer has the job');
t_like((string)$lostRow['last_error'], 'could not be found', 'and says why');
t_is(count(t_mail_sent()), 0, 'with nothing emailed');

t_section('Only the top plan runs searches on its own');

$proUser = t_fixture(['plan' => 'pro']);
$proSs   = $seed($proUser);
t_reset_mail_stub();
$pass();
t_is($autoJobFor($proUser), null, 'a Pro account with notify set is not enqueued — the automation is part of the Entrepreneur plan');
t_is(trim((string)$savedSearch($proSs)['job_token']), '', 'and its saved search is not left holding a token');

t_section('The cadence is what decides, not the passing of time');

$slowUser = t_fixture(['plan' => 'entrepreneur']);
$slowSs   = $seed($slowUser, ['run_every_hours' => 6, 'last_enqueued_at' => date('Y-m-d H:i:s', time() - 3600 * 7)]);
$freshUser = t_fixture(['plan' => 'entrepreneur']);
$freshSs   = $seed($freshUser, ['run_every_hours' => 6, 'last_enqueued_at' => date('Y-m-d H:i:s', time() - 3600)]);

$pass();
t_ok($autoJobFor($slowUser) !== null, 'a search seven hours into a six-hour cadence runs');
t_is($autoJobFor($freshUser), null, 'one an hour into a six-hour cadence waits');

t_section('The automation yields to the person at the screen');

// An automated job must not look like a duplicate of the customer's own search.
// If it did, their click would be answered with the automation's job — its
// progress, its results, and none of the params they just chose.
$humanUser = t_fixture(['plan' => 'free']);
$pdo->prepare("INSERT INTO lead_search_jobs (user_id, auto, token, status, params_json, stage)
               VALUES (?, 1, ?, 'queued', '{}', 'Queued')")
    ->execute([$humanUser, bin2hex(random_bytes(16))]);

t_is(lead_search_job_active_count($pdo, $humanUser), 1, 'the account does have a job in flight');
t_is(lead_search_job_active_count($pdo, $humanUser, false), 0,
    'but not one the customer started, so their own search is not treated as a resubmit');

$csrf = bin2hex(random_bytes(32));
$search = t_http('POST', $app . '/api/find-leads.php', [
    'json'   => ['city' => 'Escalade', 'industry' => 'Cafe', 'lead_count' => 5, 'csrf_token' => $csrf],
    'cookie' => t_login($humanUser, ['csrf_token' => $csrf]),
]);
$searchJson = json_decode($search['body'], true) ?: [];

t_ok(!empty($searchJson['success']), 'their search is accepted');
t_ok(empty($searchJson['resumed']), 'and is NOT answered with the automation\'s job');
t_is($autoJobCount($humanUser), 1, 'the automation still holds its one job');
t_is(lead_search_job_active_count($pdo, $humanUser, false), 1, 'and the customer now has one of their own');

t_section('A saved search with nothing to look for is not searched every half hour');

$emptyUser = t_fixture(['plan' => 'entrepreneur']);
$emptySs   = $seed($emptyUser, ['params' => json_encode(['city' => '', 'industry' => ''])]);

$pass();
t_is($autoJobFor($emptyUser), null, 'nothing is enqueued');
$emptyRow = $savedSearch($emptySs);
t_is(trim((string)$emptyRow['job_token']), '', 'and no run is claimed');
t_ok(trim((string)$emptyRow['last_enqueued_at']) !== '',
    'but the window advances, so it is not reconsidered every thirty minutes forever');

t_section('The drawer drives the schedule, and cannot set an unsafe one');

// The API is where a cadence a customer picks becomes the cron's rate limit, so
// the clamping has to happen here and not only in the UI.
$uiUser = t_fixture(['plan' => 'entrepreneur']);
$uiSs   = $seed($uiUser, ['notify_email' => 0, 'run_every_hours' => 24]);
$uiName = (string)$savedSearch($uiSs)['name'];

$uiCsrf   = bin2hex(random_bytes(32));
$uiCookie = t_login($uiUser, ['csrf_token' => $uiCsrf]);

$api = function (array $body) use ($app, $uiCookie, $uiCsrf): array {
    $res = t_http('POST', $app . '/api/saved-searches.php', [
        'json'   => $body + ['csrf_token' => $uiCsrf],
        'cookie' => $uiCookie,
    ]);
    return json_decode($res['body'], true) ?: [];
};

$listJson = $api(['op' => 'list']);
t_ok(!empty($listJson['success']), 'the saved-search list loads');
t_same_list(
    array_map(static fn($c) => (int)$c['hours'], (array)($listJson['cadences'] ?? [])),
    array_keys(auto_search_cadences()),
    'the cadence menu travels with the list, so the drawer cannot drift from what the cron validates'
);
t_is($listJson['saved_searches'][0]['run_every_hours'] ?? null, 24,
    'and each saved search reports how often it runs');

$updated = $api(['op' => 'update', 'id' => $uiSs, 'name' => $uiName, 'notify_email' => true, 'run_every_hours' => 3]);
t_is($updated['run_every_hours'] ?? 0, auto_search_default_cadence(),
    'an off-menu cadence is refused and replaced with the default rather than honoured');
t_is((int)$savedSearch($uiSs)['run_every_hours'], auto_search_default_cadence(), 'and that is what is stored');

$resaved = $api(['op' => 'update', 'id' => $uiSs, 'name' => $uiName, 'notify_email' => true,
                 'run_every_hours' => auto_search_default_cadence()]);
t_ok(!empty($resaved['success']),
    're-saving identical values succeeds — an UPDATE that changes nothing reports 0 affected rows, which used to answer this with a 404');

$proUi = t_fixture(['plan' => 'pro']);
$proUiSs = $seed($proUi, ['notify_email' => 0]);
$proRes  = t_http('POST', $app . '/api/saved-searches.php', [
    'json' => ['op' => 'update', 'id' => $proUiSs, 'name' => 'Pro search', 'notify_email' => true,
               'csrf_token' => $uiCsrf],
    'cookie' => t_login($proUi, ['csrf_token' => $uiCsrf]),
]);
$proJson = json_decode($proRes['body'], true) ?: [];
t_is($proRes['status'], 403, 'a Pro account cannot switch the automation on');
t_is($proJson['error'] ?? '', 'notify_email_requires_ent', 'and is told why');

/* ── Clean up the fixtures this file added ─────────────────────────────── */

foreach ([$runUser, $quietUser, $failUser, $lostUser, $proUser, $slowUser, $freshUser, $humanUser,
          $emptyUser, $uiUser, $proUi] as $uid) {
    try {
        $pdo->prepare('DELETE FROM saved_searches WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM lead_search_jobs WHERE user_id = ?')->execute([$uid]);
    } catch (Throwable $e) { /* best effort */ }
}

t_reset_mail_stub();
