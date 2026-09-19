<?php
/**
 * tests/cases/test_lead_search_queue.php
 *
 * The async lead search: api/find-leads.php enqueues, cron/lead_search_worker.php
 * runs, api/lead-search-status.php reports.
 *
 * This exists because the change it covers moves the slowest, most
 * network-dependent code in the product (Google Places pagination, with a 3s
 * sleep per page token) out of the user's request — and the ways to get that
 * wrong are a paying customer staring at a spinner forever, or being charged
 * twice for one search.
 *
 * Google is never called. The committed path is exercised by seeding the shared
 * lead_cache row the runner looks for, so a real search completes offline end to
 * end; the failure path uses the placeholder API key, which the runner refuses
 * before it opens a socket.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/lead_search_jobs.php';

if (empty($context['db_ready'])) {
    throw new T_Skip('the database is not available');
}

$platform = t_platform_db();
lead_search_jobs_ensure_tables($platform);

/**
 * The cache key the runner derives for a search.
 *
 * Mirrors the key computation in includes/lead_search_runner.php. If that ever
 * changes, the "completes from cache" assertions below fail loudly rather than
 * quietly passing against a key nothing writes any more.
 */
function t_ls_cache_key(string $city, string $industry, int $count, array $sources): string
{
    sort($sources);
    return strtolower(preg_replace('/\s+/', ' ', $city . '|' . $industry . '|' . $count . '|' . implode(',', $sources)));
}

/**
 * Wait for a job to reach a terminal state.
 *
 * The worker hands its HTTP connection back before it starts searching — that
 * is the whole point of detaching — so the response arriving does NOT mean the
 * job is finished. The browser resumes polling the status endpoint here for
 * exactly the same reason; the test polls the row directly instead.
 */
function t_ls_await_job(PDO $pdo, int $uid, string $token, float $timeoutSeconds = 30): ?array
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $row = lead_search_job_get($pdo, $uid, $token);
        if ($row && in_array($row['status'], ['done', 'error'], true)) {
            return $row;
        }
        usleep(150000);
    }
    return lead_search_job_get($pdo, $uid, $token);
}

/** Remove every row these tests could have created. */
function t_ls_cleanup(array $userIds, array $cachePrefixes): void
{
    try {
        if ($userIds) {
            $ph = implode(',', array_fill(0, count($userIds), '?'));
            t_platform_db()->prepare("DELETE FROM lead_search_jobs WHERE user_id IN ($ph)")->execute($userIds);
            t_platform_db()->prepare("DELETE FROM unlocked_leads WHERE user_id IN ($ph)")->execute($userIds);
        }
        foreach ($cachePrefixes as $prefix) {
            t_platform_db()->prepare('DELETE FROM lead_cache WHERE cache_key LIKE ?')->execute([$prefix . '%']);
        }
        t_db()->exec("DELETE FROM lead_search_quota WHERE fingerprint LIKE 'uid_%'");
    } catch (Throwable $e) {
        // Best effort — the fixture accounts themselves are removed by the suite.
    }
}

$tag   = bin2hex(random_bytes(4));
$users = [];

/* ─────────────────────────────────────────────────────────────────────────────
 * The queue itself
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A job can be claimed exactly once');

$uid     = t_fixture(['plan' => 'pro']);
$users[] = $uid;

$job = lead_search_job_enqueue($platform, $uid, ['city' => 'Queueville' . $tag, 'industry' => 'Bakery', 'lead_count' => 5, 'sources' => ['google_places']]);
t_is($job['status'], 'queued', 'a new job starts queued');
t_ok((bool)preg_match('/^[0-9a-f]{32}$/', (string)$job['token']), 'and carries an unpredictable 32-hex token');

$row = lead_search_job_get($platform, $uid, (string)$job['token']);
t_is((int)$row['progress'], 0, 'a fresh job reports 0% progress');
$queuedView = json_encode(lead_search_job_view($row));
t_unlike($queuedView, '"result"', 'a queued job exposes no result');
t_unlike($queuedView, '"error"', 'and no error');

$stranger = t_fixture(['plan' => 'pro']);
$users[]  = $stranger;
t_is(lead_search_job_get($platform, $stranger, (string)$job['token']), null, 'another account cannot read the job');
t_is(lead_search_job_get($platform, $uid, 'not-a-token'), null, 'a malformed token is refused');
t_is(lead_search_job_get($platform, $uid, str_repeat('a', 32)), null, 'an unknown token is refused');

$claimed = lead_search_job_claim($platform, lead_search_worker_id(), (string)$job['token']);
t_not($claimed, null, 'a worker claims the job');
t_is($claimed['status'], 'running', 'and marks it running');
t_is((int)$claimed['attempts'], 1, 'and counts the attempt');

t_is(
    lead_search_job_claim($platform, lead_search_worker_id(), (string)$job['token']),
    null,
    'a second worker racing the same job is refused — the claim is atomic'
);

t_section('Progress and completion reach the poller');

lead_search_job_progress($platform, (int)$claimed['id'], 42, 'Fetching Google Places page 2');
$row = lead_search_job_get($platform, $uid, (string)$job['token']);
t_is((int)$row['progress'], 42, 'progress is stored where the poll reads it');
t_is($row['stage'], 'Fetching Google Places page 2', 'along with the stage label');

lead_search_job_finish($platform, (int)$claimed['id'], [
    'success' => true,
    'leads'   => [['id' => 7, 'business_name' => 'Queue Cafe', 'opportunity_score' => 80]],
]);
$doneView = lead_search_job_view(lead_search_job_get($platform, $uid, (string)$job['token']));
t_is($doneView['status'], 'done', 'the job is done');
t_is($doneView['progress'], 100, 'and reads 100% without a manual progress write');
t_is($doneView['result']['leads'][0]['business_name'], 'Queue Cafe', 'and carries the finished payload');

t_is(
    lead_search_job_claim($platform, lead_search_worker_id(), (string)$job['token']),
    null,
    'a finished job is never claimed again'
);

t_section('A failure carries the flags the UI needs to explain it');

$failJob   = lead_search_job_enqueue($platform, $uid, ['city' => 'Queueville' . $tag, 'industry' => 'Bakery']);
$failClaim = lead_search_job_claim($platform, lead_search_worker_id(), (string)$failJob['token']);
lead_search_job_fail($platform, (int)$failClaim['id'], 'limit_reached', 'You have reached your 3 lead limit.', [
    'limit_reached' => true, 'lead_count' => 3, 'lead_limit' => 3,
]);
$failView = lead_search_job_view(lead_search_job_get($platform, $uid, (string)$failJob['token']));
t_is($failView['status'], 'error', 'the job is failed, not left running');
t_is($failView['error_code'], 'limit_reached', 'the error code is preserved');
t_is($failView['error'], 'You have reached your 3 lead limit.', 'and the message the user reads');
t_is($failView['limit_reached'], true, 'and the limit_reached flag survives the round trip');
t_is($failView['lead_limit'], 3, 'with the numbers the bar needs');

t_section("A dead worker's job is recovered, not lost");

$staleJob   = lead_search_job_enqueue($platform, $uid, ['city' => 'Queueville' . $tag, 'industry' => 'Bakery']);
$staleClaim = lead_search_job_claim($platform, lead_search_worker_id(), (string)$staleJob['token']);
$platform->prepare('UPDATE lead_search_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE id = ?')
    ->execute([(int)$staleClaim['id']]);

t_ok(lead_search_job_reap($platform) >= 1, 'the reaper notices a running job whose heartbeat stopped');
$staleRow = lead_search_job_get($platform, $uid, (string)$staleJob['token']);
t_is($staleRow['status'], 'queued', 'and puts it back in the queue');
t_is((int)$staleRow['attempts'], 1, 'without forgetting the attempt that was made');

$retry = lead_search_job_claim($platform, lead_search_worker_id(), (string)$staleJob['token']);
t_is((int)$retry['attempts'], 2, 'the retry is counted');

$platform->prepare("UPDATE lead_search_jobs SET status = 'running', attempts = ?, heartbeat_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE id = ?")
    ->execute([LEAD_SEARCH_JOB_MAX_ATTEMPTS, (int)$retry['id']]);
lead_search_job_reap($platform);
$exhausted = lead_search_job_view(lead_search_job_get($platform, $uid, (string)$staleJob['token']));
t_is($exhausted['status'], 'error', 'a job that keeps dying is failed instead of retried forever');
t_is($exhausted['error_code'], 'worker_lost', 'with a code that says what happened');
t_ok(($exhausted['error'] ?? '') !== '', 'and a message the user can act on');

$freshJob = lead_search_job_enqueue($platform, $uid, ['city' => 'Queueville' . $tag, 'industry' => 'Bakery']);
lead_search_job_claim($platform, lead_search_worker_id(), (string)$freshJob['token']);
lead_search_job_reap($platform);
t_is(
    lead_search_job_get($platform, $uid, (string)$freshJob['token'])['status'],
    'running',
    'while a healthy running job is left strictly alone'
);

t_section('Finished jobs are purged and the queue head is oldest-first');

$oldJob   = lead_search_job_enqueue($platform, $uid, ['city' => 'Queueville' . $tag, 'industry' => 'Bakery']);
$oldClaim = lead_search_job_claim($platform, lead_search_worker_id(), (string)$oldJob['token']);
lead_search_job_finish($platform, (int)$oldClaim['id'], ['success' => true, 'leads' => []]);
$platform->prepare('UPDATE lead_search_jobs SET finished_at = DATE_SUB(NOW(), INTERVAL 48 HOUR) WHERE id = ?')
    ->execute([(int)$oldClaim['id']]);
t_ok(lead_search_job_purge($platform, 24) >= 1, 'a finished job past retention is purged');
t_is(lead_search_job_get($platform, $uid, (string)$oldJob['token']), null, 'and is gone');

$headFirst  = lead_search_job_enqueue($platform, $uid, ['city' => 'Queueville' . $tag, 'industry' => 'Bakery']);
$headSecond = lead_search_job_enqueue($platform, $uid, ['city' => 'Queueville' . $tag, 'industry' => 'Bakery']);
$headClaim  = lead_search_job_claim($platform, lead_search_worker_id(), null);
t_is($headClaim['token'], $headFirst['token'], 'cron mode claims the oldest queued job first');
t_ok(lead_search_job_active_count($platform, $uid) >= 2, 'in-flight jobs are counted for the per-user cap');

/* ─────────────────────────────────────────────────────────────────────────────
 * The endpoints
 * ──────────────────────────────────────────────────────────────────────────── */

$app = $context['app_url'] ?? null;
if (!$app) {
    t_skip('HTTP enqueue and worker tests', 'no application server');
    t_ls_cleanup($users, ['queueville' . $tag]);
    return;
}

t_section('The enqueue endpoint refuses what it should');

$csrfFree    = bin2hex(random_bytes(32));
$freeUid     = t_fixture(['plan' => 'free']);
$users[]     = $freeUid;
$cookieFree  = t_login($freeUid, ['csrf_token' => $csrfFree]);
$enqueueBody = ['city' => 'Escalade' . $tag, 'industry' => 'Cafe', 'lead_count' => 5, 'csrf_token' => $csrfFree];

$res = t_http('POST', $app . '/api/find-leads.php', ['json' => ['city' => 'X', 'industry' => 'Y']]);
t_is($res['status'], 401, 'an unauthenticated enqueue is refused');

$res = t_http('POST', $app . '/api/find-leads.php', [
    'json'   => ['city' => 'X', 'industry' => 'Y', 'csrf_token' => 'not-the-token'],
    'cookie' => $cookieFree,
]);
t_is($res['status'], 403, 'a wrong CSRF token is refused');

t_section('A free plan cannot widen its own source list');

$res = t_http('POST', $app . '/api/find-leads.php', [
    'json'   => $enqueueBody + ['sources' => ['osm', 'yelp', 'google_places']],
    'cookie' => $cookieFree,
]);
$accepted = json_decode($res['body'], true);
t_is($accepted['success'] ?? null, true, 'the search is accepted');
t_is($accepted['async'] ?? null, true, 'and answered asynchronously');
t_ok(!empty($accepted['job']['token']), 'with a job token to poll');

$freeToken = (string)$accepted['job']['token'];
$stored    = lead_search_job_get($platform, $freeUid, $freeToken);
t_not($stored, null, 'the job is really in the queue');
$params = json_decode((string)$stored['params_json'], true);
t_is($params['city'], 'Escalade' . $tag, 'the search parameters are stored server-side');
t_is($params['sources'], ['google_places'], 'and the source list is the plan intersection, not what the client asked for');

t_is($accepted['searches_used'], 1, 'the free search is charged at enqueue time');
t_is($accepted['searches_remaining'], 1, 'and the remaining count is returned');

t_section('A duplicate submit resumes the running search instead of charging twice');

$res   = t_http('POST', $app . '/api/find-leads.php', ['json' => $enqueueBody, 'cookie' => $cookieFree]);
$again = json_decode($res['body'], true);
t_is($again['success'] ?? null, true, 'the duplicate submit is accepted');
t_is($again['resumed'] ?? null, true, 'and answered as a resume');
t_is($again['job']['token'], $freeToken, 'handing back the same job token');
t_is($again['searches_used'], null, 'and reporting no quota movement');

$charged = (int)t_db()->query("SELECT count FROM lead_search_quota WHERE fingerprint = 'uid_" . $freeUid . "'")->fetchColumn();
t_is($charged, 1, 'so the free daily allowance was spent exactly once');
t_is(
    (int)$platform->query('SELECT COUNT(*) FROM lead_search_jobs WHERE user_id = ' . $freeUid)->fetchColumn(),
    1,
    'and only one job exists for the double submit'
);

t_section('The poll endpoint is scoped to the owner');

$res    = t_http('GET', $app . '/api/lead-search-status.php?token=' . urlencode($freeToken), ['cookie' => $cookieFree]);
$polled = json_decode($res['body'], true);
t_is($res['status'], 200, 'the owner can poll their job');
t_is($polled['job']['status'], 'queued', 'which is still queued — the worker has not run yet');

$res = t_http('GET', $app . '/api/lead-search-status.php?token=' . str_repeat('b', 32), ['cookie' => $cookieFree]);
t_is($res['status'], 404, 'an unknown token is a 404');

$res = t_http('GET', $app . '/api/lead-search-status.php?token=' . urlencode($freeToken));
t_is($res['status'], 401, 'an unauthenticated poll is refused');

t_section('A queued search completes end to end through the worker');

// The worker drains the queue once it finishes the job it was named for (that
// is what cron mode is for), so clear the fixture jobs this file left queued.
// Otherwise an unrelated search gets picked up first and the assertions below
// would be reading someone else's outcome.
$platform->prepare('DELETE FROM lead_search_jobs WHERE user_id = ?')->execute([$uid]);
$platform->prepare('DELETE FROM lead_search_jobs WHERE user_id = ?')->execute([$freeUid]);

// A Pro account, because Pro is the plan that actually unlocks leads. Only
// google_places is requested, and the cache hit below means the runner never
// reaches the Google block or the other source engines.
$csrfPro   = bin2hex(random_bytes(32));
$proUid    = t_fixture(['plan' => 'pro']);
$users[]   = $proUid;
$cookiePro = t_login($proUid, ['csrf_token' => $csrfPro]);
$poolCity  = 'Worker' . $tag;

$res = t_http('POST', $app . '/api/find-leads.php', [
    'json'   => ['city' => $poolCity, 'industry' => 'Cafe', 'lead_count' => 5, 'sources' => ['google_places'], 'csrf_token' => $csrfPro],
    'cookie' => $cookiePro,
]);
$accepted  = json_decode($res['body'], true);
$proToken  = (string)($accepted['job']['token'] ?? '');
t_ok($proToken !== '', 'the Pro search is queued');

// Seed the shared cache row the runner looks for, so a real search completes
// with no Google call: the lead is returned, written to the pool and unlocked.
$cachedLead = [
    'place_id'          => 'test_queue_' . $tag,
    'business_name'     => 'Cache Cafe',
    'business_address'  => '1 Cached Street',
    'business_city'     => $poolCity,
    'business_phone'    => '555-0100',
    'business_email'    => '',
    'business_category' => 'Cafe',
    'rating'            => 4.6,
    'total_ratings'     => 120,
    'maps_url'          => 'https://maps.google.test/?q=test_queue_' . $tag,
    'opportunity_score' => 85,
    'no_website'        => true,
];
$cacheKey = t_ls_cache_key($poolCity, 'Cafe', 5, ['google_places']);
$platform->prepare('INSERT INTO lead_cache (cache_key, leads_json, created_at) VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE leads_json = VALUES(leads_json), created_at = NOW()')
    ->execute([$cacheKey, json_encode([$cachedLead])]);
$platform->prepare('DELETE FROM unlocked_leads WHERE user_id = ?')->execute([$proUid]);

$res = t_http('GET', $app . '/cron/lead_search_worker.php?secret=' . urlencode(CRON_SECRET) . '&job=' . urlencode($proToken));
t_is($res['status'], 200, 'the worker accepts the cron secret');

$finished = t_ls_await_job($platform, $proUid, $proToken);
t_is($finished['status'], 'done', 'the worker completes the queued job');
t_is((int)$finished['progress'], 100, 'and leaves it at 100%');
$result = json_decode((string)$finished['result_json'], true);
t_is($result['from_cache'], true, 'the search came from the shared cache, with no Google call');
t_is($result['leads'][0]['business_name'], 'Cache Cafe', 'and the lead is returned to the browser');
t_ok(!empty($result['leads'][0]['id']), 'with a database id, so the card can build a site from it');

$pooled = $platform->query("SELECT business_name FROM utiligo_leads WHERE place_id = 'test_queue_" . $tag . "' LIMIT 1")->fetchColumn();
t_is($pooled, 'Cache Cafe', 'the lead is written to the shared lead pool');
t_is(
    (int)$platform->query('SELECT COUNT(*) FROM unlocked_leads WHERE user_id = ' . $proUid)->fetchColumn(),
    1,
    'and the returned lead is unlocked for the paying account'
);

$res    = t_http('GET', $app . '/api/lead-search-status.php?token=' . urlencode($proToken), ['cookie' => $cookiePro]);
$polled = json_decode($res['body'], true);
t_is($polled['job']['status'], 'done', 'the poll endpoint reports the job done');
t_is($polled['job']['result']['leads'][0]['business_name'], 'Cache Cafe', 'and hands the browser the finished payload');

$res = t_http('GET', $app . '/api/lead-search-status.php?token=' . urlencode($proToken), ['cookie' => t_login($stranger)]);
t_is($res['status'], 404, 'another account cannot poll a job it does not own');

t_section('A search that cannot run fails the job instead of hanging the poller');

$csrfFail   = bin2hex(random_bytes(32));
$failUid    = t_fixture(['plan' => 'free']);
$users[]    = $failUid;
$cookieFail = t_login($failUid, ['csrf_token' => $csrfFail]);

$res = t_http('POST', $app . '/api/find-leads.php', [
    'json'   => ['city' => 'Nowhere' . $tag, 'industry' => 'Cafe', 'lead_count' => 5, 'force_refresh' => true, 'csrf_token' => $csrfFail],
    'cookie' => $cookieFail,
]);
$accepted = json_decode($res['body'], true);
t_is($accepted['success'] ?? null, true, 'the enqueue is accepted even though the search will fail');

$failToken = (string)$accepted['job']['token'];
$res = t_http('GET', $app . '/cron/lead_search_worker.php?secret=' . urlencode(CRON_SECRET) . '&job=' . urlencode($failToken));
t_is($res['status'], 200, 'the worker runs');

$failed = t_ls_await_job($platform, $failUid, $failToken);
t_is($failed['status'], 'error', 'the job is terminalized, not left running');
t_is($failed['error_code'], 'not_configured', 'with the code for a missing Places key');

$res    = t_http('GET', $app . '/api/lead-search-status.php?token=' . urlencode($failToken), ['cookie' => $cookieFail]);
$polled = json_decode($res['body'], true);
t_is($polled['job']['status'], 'error', 'and the browser is told, so the spinner stops');
t_ok(($polled['job']['error'] ?? '') !== '', 'with a message to show');

t_section('The worker refuses a bad secret');

$res = t_http('GET', $app . '/cron/lead_search_worker.php?secret=wrong&job=' . urlencode($failToken));
t_is($res['status'], 403, 'a wrong cron secret is denied');

t_ls_cleanup($users, ['escalade' . $tag, 'nowhere' . $tag, 'queueville' . $tag, 'worker' . $tag]);
