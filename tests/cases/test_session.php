<?php
/**
 * Sessions — the two ways a signed-in visitor gets turned away.
 *
 * WHY THIS FILE EXISTS
 * ────────────────────
 * Both of these were live 500s in front of a customer, found by driving the
 * portal in a browser rather than by reading code:
 *
 * 1. A session that names an account which can no longer be read. require_login()
 *    is right to log that visitor out — but logout_user() passed the session
 *    cookie's path straight back to setcookie(), and config.php sets that path to
 *    "/; SameSite=Lax" (a trick for adding SameSite on PHP < 7.3). Since PHP 7.3
 *    setcookie() THROWS a ValueError for a path containing ";", so the cleanup
 *    fatally unwound and the customer saw "Something went wrong" instead of a
 *    sign-in page. The redirect is the observable part; the Set-Cookie that
 *    accompanies it is the part that must be a path a browser can match, or the
 *    stale cookie is never actually cleared and the visitor is bounced forever.
 *
 * 2. An anonymous visitor asking for a portal page. That path must stay a plain
 *    302 to the login page, so the fix above cannot have been bought by making
 *    the ordinary case worse.
 */

$haveApp = !empty($context['app_url']) && !empty($context['db_ready']);
if (!$haveApp) {
    t_skip('sessions', 'requires the application server and the database');
    return;
}

$app = $context['app_url'];

/** The Set-Cookie lines in a response, for asserting what the browser is told. */;
$setCookies = static function (array $res): string {
    $out = [];
    foreach ($res['headers'] as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            $out[] = $h;
        }
    }
    return implode("\n", $out);
};

t_section('A session pointing at an account that is gone');

// A live session cookie whose user row does not exist. This is what an account
// deleted by an administrator leaves behind on the visitor's machine.
$ghost = t_http('GET', $app . '/portal/index.php', ['cookie' => t_login(9876543210)]);

t_is($ghost['status'], 302, 'the portal sends the visitor back to sign in');
t_not($ghost['status'], 500, 'and does so without a server error');
t_like((string)$ghost['location'], '/login.php', 'the redirect names the login page');
t_like((string)$ghost['location'], 'expired=1',
    'and says why, so the login page can explain itself instead of looking random');
t_unlike($ghost['body'], 'Something went wrong', 'no error page is rendered');

$cleared = $setCookies($ghost);
t_like($cleared, 'PHPSESSID', 'the stale session cookie is cleared in the same response');
t_like($cleared, 'path=/;', 'and it is cleared at the path a browser stored it under');
t_unlike($cleared, 'path=/; SameSite', 'not the raw "/; SameSite=Lax" config.php uses');
t_like($cleared, 'SameSite=Lax', 'while still asking for SameSite on the clearing cookie');

t_section('The page it lands on is usable');

$landing = t_http('GET', $app . '/login.php?expired=1');
t_is($landing['status'], 200, 'the login page renders');
t_like($landing['body'], 'csrf_token', 'with a working form');

t_section('An anonymous visitor is still just asked to sign in');

$anon = t_http('GET', $app . '/portal/index.php');
t_is($anon['status'], 302, 'the portal redirects');
t_is($anon['location'], '/login.php', 'to the login page, with no expiry story to tell');
t_is($anon['status'], t_http('GET', $app . '/portal/billing.php')['status'],
    'and every portal page behaves the same way');

t_section('A real session is not disturbed by any of this');

$uid = t_fixture(['full_name' => 'Session Tester', 'plan' => 'pro', 'subscription_status' => 'active']);
$live = t_http('GET', $app . '/portal/index.php', ['cookie' => t_login($uid)]);
t_is($live['status'], 200, 'a valid session loads the dashboard');
t_unlike($setCookies($live), 'PHPSESSID=deleted',
    'and is not logged out as a side effect of loading it');

try {
    t_db()->prepare('DELETE FROM utiligo_users WHERE id = ?')->execute([$uid]);
} catch (\Throwable $e) {
    // The suite's own cleanup removes fixtures anyway.
}
