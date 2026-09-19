<?php
/**
 * The signup plan modal — assets/js/onboarding-signup.js.
 *
 * THE BUG THIS EXISTS FOR
 * ───────────────────────
 * The modal's primary CTA navigated to `selectedPlan.url`, but no card in the
 * PLANS array defined a `url` at all. Choosing a plan on register.php therefore
 * sent the browser to the literal string "undefined": the one thing the modal
 * exists to do — carry the chosen plan into signup — silently never happened, on
 * the busiest page in the funnel, and every card looked fine in isolation.
 *
 * So this file pins both halves of the promise:
 *
 *   - every card NAMES a target, and the CTA reads that field; and
 *   - the target RESOLVES (a 200 from the real register.php) and CARRIES the
 *     plan (the page it returns already has that plan selected).
 *
 * WHY A PARSE, NOT A BROWSER
 * ──────────────────────────
 * The plan list is a JS literal and this suite is PHP with no JS engine, so the
 * key/url pairs are read from the source in file order. Each card contributes
 * exactly one `key:` and one `url:`, and the equal-count assertion is what keeps
 * that zip honest — a card that gains one without the other fails here.
 */

$modalJs = dirname(__DIR__, 2) . '/assets/js/onboarding-signup.js';

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. Every card names a target
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Every plan card names the page it goes to');

t_ok(is_file($modalJs), 'the modal source is where the app serves it from');
$src = is_file($modalJs) ? (string)file_get_contents($modalJs) : '';

preg_match_all("/\bkey:\s*'([a-z_]+)'/", $src, $keyHits);
preg_match_all("/\burl:\s*'([^']*)'/", $src, $urlHits);
$keys = $keyHits[1];
$urls = $urlHits[1];

t_is(count($keys), 3, 'there are three plan cards');
t_is(count($urls), count($keys),
    'and every one defines a url — the field the CTA reads, which used to be missing entirely');

$cards = [];
foreach ($keys as $i => $key) {
    $cards[$key] = $urls[$i] ?? null;
}

t_same_list(array_keys($cards), ['free', 'pro', 'entrepreneur'],
    'the cards are the three real plans, in order');

// The exact targets — the same ones the #pricing CTAs in index.php use.
foreach ([
    'free'         => '/register.php',
    'pro'          => '/register.php?plan=pro',
    'entrepreneur' => '/register.php?plan=entrepreneur',
] as $key => $expected) {
    t_is($cards[$key] ?? null, $expected, $key . ' goes to ' . $expected);
}

// A blank, a bare string and the historical "undefined" must all be impossible.
foreach ($cards as $key => $url) {
    t_ok(is_string($url) && $url !== '' && str_starts_with($url, '/'),
        $key . ': the target is a rooted path — not empty, and not "undefined"');
}

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. The button is wired to that field
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The primary button navigates by that field');

t_like($src, 'window.location.href = selectedPlan.url',
    'the CTA reads selectedPlan.url, so a missing url is a dead button');
t_like($src, 'let selectedPlan = PLANS[0];',
    'a plan is selected from the start, so the CTA is never pointing at nothing');

/* ─────────────────────────────────────────────────────────────────────────────
 * 3. Every target resolves — and carries the plan
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Every target resolves and carries its plan');

$haveApp = !empty($context['app_url']) && !empty($context['db_ready']);
if (!$haveApp) {
    t_skip('the plan targets over HTTP', 'requires the application server and the database');
    return;
}

$app = $context['app_url'];

foreach ($cards as $key => $url) {
    $res = t_http('GET', $app . $url);
    t_is($res['status'], 200, $key . ': ' . $url . ' answers 200 rather than a 404');
}

// Resolving is not enough. register.php has to actually receive the plan, or the
// customer lands on a fresh signup that has forgotten what they just chose.
$proPage = t_http('GET', $app . '/register.php?plan=pro');
t_like($proPage['body'], 'name="plan" value="pro"',
    'register.php carries the chosen plan into the signup form');
t_like($proPage['body'], 'Starting on',
    'and tells the customer which plan they are about to buy');

$entPage = t_http('GET', $app . '/register.php?plan=entrepreneur');
t_like($entPage['body'], 'name="plan" value="entrepreneur"',
    'the Entrepreneur target carries its plan too');

$freePage = t_http('GET', $app . '/register.php');
t_like($freePage['body'], 'name="plan" value="free"',
    'and the free target stays on the free plan');
