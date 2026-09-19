<?php
/**
 * Outreach drafts — includes/outreach.php and api/lead-outreach.php.
 *
 * Two things are being defended here, and the second one is not about features at
 * all.
 *
 * The first is honesty. A draft is only worth sending if every claim in it is
 * something the lead row actually says: a business owner can spot a generic
 * mailshot instantly, and a draft that invents an observation is worse than no
 * draft. So the angle table is asserted row by row, including the cases where it
 * must NOT claim anything.
 *
 * The second is the paywall. The lead pool is shared and its rows carry no owner,
 * so "which leads may this account read" is only answerable from the grant table.
 * api/lead-enrichments.php used to answer to login + CSRF alone and return the
 * whole row — business_phone and business_email included — for any id at all,
 * which is exactly the field the free tier masks and Pro pays to unlock. Both
 * endpoints are asserted against that here.
 */

if (empty($context['app_url']) || empty($context['db_ready'])) {
    throw new T_Skip('the application server and the database are required for this file');
}

$app = $context['app_url'];
$pdo = t_platform_db();

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. The sender profile
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The profile a draft signs with');

$defaults = outreach_profile_defaults(['full_name' => 'Dana Reid']);
t_is($defaults['sender_name'], 'Dana Reid', 'an empty profile signs as the account holder');
t_is($defaults['offer'], '', 'and has no offer yet');

$merged = outreach_profile_from_user([
    'full_name'        => 'Dana Reid',
    'outreach_profile' => json_encode(['offer' => 'websites for dentists', 'business_name' => 'Reid Digital']),
]);
t_is($merged['offer'], 'websites for dentists', 'a stored offer is used');
t_is($merged['business_name'], 'Reid Digital', 'and a stored business name');
t_is($merged['sender_name'], 'Dana Reid', 'while an unset key still falls back to the account name');
t_is($merged['website'], '', 'every key exists even when the stored blob predates it');

$blank = outreach_profile_from_user([
    'full_name'        => 'Dana Reid',
    'outreach_profile' => json_encode(['sender_name' => '', 'offer' => 'x']),
]);
t_is($blank['sender_name'], 'Dana Reid', 'a stored empty name does not blank out the account name');

$junk = outreach_profile_from_user(['full_name' => 'Dana', 'outreach_profile' => '{"nope":"x","offer":123}']);
t_is($junk['offer'], '123', 'only the keys this module knows are read from a stored blob');
t_is(outreach_profile_from_user(['full_name' => 'Dana', 'outreach_profile' => 'not json'])['sender_name'], 'Dana',
    'an unreadable blob falls back rather than throwing');

t_is(outreach_profile_clean(['offer' => "  websites\nfor   dentists "])['offer'],
    'websites for dentists', 'a pasted multi-line offer is flattened to one line');
t_is(strlen(outreach_profile_clean(['offer' => str_repeat('x', 500)])['offer']), OUTREACH_MAX_FIELD,
    'and is length-capped, so a pasted novel cannot become the signature');

t_ok(!outreach_profile_complete(['sender_name' => 'Dana', 'offer' => '']), 'a profile with no offer is not complete');
t_ok(outreach_profile_complete(['sender_name' => 'Dana', 'offer' => 'websites']), 'one with both is');
t_is(outreach_offer_line(['offer' => 'websites for dentists']), 'That is what I do: websites for dentists.',
    'a statement of what the customer sells is written as a promise about them');
t_is(outreach_offer_line(['offer' => 'websites for dentists.']), 'That is what I do: websites for dentists.',
    'and a trailing full stop is not doubled up');
t_is(outreach_offer_line([]), 'I help local businesses get found online.',
    'with nothing stored the draft still reads as a person, not as a blank');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. Which angle, and only on evidence
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The angle is chosen from what the row actually says');

$bare = ['business_name' => 'Cafe Alpha', 'business_category' => 'Cafe', 'business_city' => 'Burnaby',
         'rating' => null, 'total_ratings' => 0];

t_is(outreach_pick_angle($bare)['key'], 'no_website', 'a listing with no website is the reason to write');
t_is(outreach_pick_angle($bare)['facts'], ['there is no website on your Google listing'],
    'and the draft may only claim that one thing');

// NOTE: array_merge, not `+`. `$bare` already carries rating/total_ratings/website
// as null/0/absent, and `+` keeps the left-hand value for a key that exists — so
// every "what if it were rated 4.8" case below would silently have stayed unrated
// and passed for the wrong reason.
$withSite = array_merge($bare, ['website' => 'https://cafe-alpha.test']);
t_is(outreach_pick_angle($withSite)['key'], 'no_reviews', 'with a website, no reviews is the next best reason');

$rated = array_merge($bare, ['website' => 'https://cafe-alpha.test', 'rating' => 4.8, 'total_ratings' => 120]);
$angle = outreach_pick_angle($rated);
t_not($angle['key'], 'weak_rating', 'a well-rated business is never told its rating is a problem');
t_not($angle['key'], 'no_reviews', 'and a business with 120 reviews is not told it has none');
t_is($angle['key'], 'strong_rating', 'instead the thing it does well is the reason to write');
t_is($angle['facts'], ['you are showing 4.8 stars from 120 reviews'],
    'stated with the numbers the row actually carries, and no spin');

$weak = array_merge($withSite, ['rating' => 3.4, 'total_ratings' => 40]);
t_is(outreach_pick_angle($weak)['key'], 'weak_rating', 'a genuine rating problem is raised');
t_like(outreach_pick_angle($weak)['facts'][0], '3.4 stars from 40 reviews', 'with the numbers as they are');

$thin = array_merge($withSite, ['rating' => 4.0, 'total_ratings' => 1]);
t_not(outreach_pick_angle($thin)['key'], 'weak_rating',
    '"4.0 from one review" is not a reason to email anybody');
t_is(outreach_pick_angle($thin)['key'], 'few_reviews', 'though a single review is worth mentioning as a gap');

$noPhone = array_merge($bare, ['website' => 'https://x.test', 'rating' => 4.9, 'total_ratings' => 50]);
t_is(outreach_pick_angle($noPhone)['key'], 'strong_rating',
    'a missing phone number is never the opening argument, however thin the row is');
t_not(in_array('no_phone', array_column(outreach_angles($noPhone), 'key'), true), true,
    'and is not an angle at all — it belongs to how we reach them, not to why we write');

$angles = outreach_angles($bare);
t_is(array_slice(array_column($angles, 'key'), 0, 3), ['no_website', 'no_reviews', 'general'],
    'the table is ranked, so the strongest reason is always the one used');
t_is(array_column(outreach_angles($rated), 'key'), ['strong_rating', 'general'],
    'and a real gap always outranks a compliment');

/* ─────────────────────────────────────────────────────────────────────────────
 * 3. The draft
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A draft that can be sent as it stands');

$lead = ['id' => 5, 'business_name' => 'Bright Smile Dental', 'business_category' => 'Dentist',
         'business_city' => 'Vancouver', 'business_email' => 'hello@brightsmile.test',
         'business_phone' => '604-555-0134', 'website' => '', 'rating' => null, 'total_ratings' => 0];

$profile = ['sender_name' => 'Dana Reid', 'business_name' => 'Reid Digital',
            'offer' => 'websites for local clinics', 'website' => 'https://reiddigital.test', 'phone' => '604-555-0199'];

$draft = outreach_draft($lead, $profile);

t_is($draft['subject'], 'A website for Bright Smile Dental?', 'the subject names the reason');
t_like($draft['body'], 'Hi Bright Smile Dental team,', 'it greets the business by name rather than "Hi there"');
t_like($draft['body'], 'I came across Bright Smile Dental while looking at Dentist businesses in Vancouver.',
    'it says where the lead came from, so it does not read as a blast');
t_like($draft['body'], 'no website on your Google listing', 'and makes exactly the observation the row supports');
t_like($draft['body'], 'websites for local clinics.', 'then what the customer sells');
t_like($draft['body'], 'Would a short call this week be worth it?', 'and one low-pressure ask');
t_like($draft['body'], 'Dana Reid', 'signed by a person');
t_like($draft['body'], 'Reid Digital', 'with their business');
t_like($draft['body'], 'reiddigital.test', 'and a way to check them out');
t_ok($draft['signed'], 'the signature is non-empty');
t_is($draft['opening'], 'I came across Bright Smile Dental while looking at Dentist businesses in Vancouver. '
    . 'One thing I noticed: there is no website on your Google listing.',
    'the opening sentence is available on its own, for the digest to print');

// The website matters: $lead has an empty one, so without overriding it this case
// would be answered by the no_website angle and would never reach the compliment.
$unrated = outreach_draft(
    array_merge($lead, ['website' => 'https://brightsmile.test', 'rating' => 4.7, 'total_ratings' => 90]),
    $profile
);
t_is($unrated['angle'], 'strong_rating', 'a well-rated business gets the compliment angle, not the complaint one');
t_is($unrated['evidence'], ['you are showing 4.7 stars from 90 reviews'],
    'and the draft says only what the row says');

// The only shape that reaches `general`: a listing with reviews but no rating
// value, a website, and nothing else worth mentioning. Everything narrower has a
// more specific angle, which is the point — the fallback should be rare.
$nothing = outreach_draft(['id' => 6, 'business_name' => '', 'business_category' => '', 'business_city' => '',
                           'website' => 'https://quiet.test', 'rating' => null, 'total_ratings' => 12], $profile);
t_is($nothing['angle'], 'general', 'with nothing specific to say the draft falls back');
t_like($nothing['body'], 'I did not find anything specific in your listing',
    'and admits it instead of inventing an observation');
t_unlike($nothing['body'], 'One thing I noticed', 'making no claim at all');
t_like($nothing['body'], 'Hi there,', 'with no business name it does not address a made-up one');
t_like($nothing['body'], 'I have been looking at local businesses in your area.',
    'falling back to the least specific true thing it can say');

$unsigned = outreach_draft($lead, ['sender_name' => '', 'business_name' => '', 'offer' => '', 'website' => '', 'phone' => '']);
t_ok(!$unsigned['signed'], 'a draft with no profile is reported as unsigned rather than signed "—"');
t_ok(!$unsigned['profile_complete'], 'and the caller is told the profile is incomplete');
t_like($unsigned['body'], 'I help local businesses get found online.', 'while still reading as a person');

t_section('How the draft would actually be delivered');

$chEmail = outreach_channel($lead);
t_is($chEmail['channel'], 'email', 'an email address wins');
t_ok(outreach_mailto($lead, $draft) !== '', 'and produces a mailto link');
// rawurlencode, so a space is %20 and never '+': asserting on a '+' needle here
// would have passed on a bug in the encoder rather than on its correctness.
t_like(outreach_mailto($lead, $draft), 'Bright%20Smile', 'with the subject encoded into it');

$noEmail = outreach_channel(array_merge($lead, ['business_email' => '']));
t_is($noEmail['channel'], 'phone', 'with no email address the draft reports a phone');
t_is(outreach_mailto(array_merge($lead, ['business_email' => '']), $draft), '',
    'and offers no mailto link at all');
t_is(outreach_channel(array_merge($lead, ['business_email' => 'not-an-email']))['channel'], 'phone',
    'a malformed address is not treated as an address');
t_is(outreach_mailto(array_merge($lead, ['business_email' => 'not-an-email']), $draft), '',
    'and produces no link either — the channel and the link agree');

t_is(outreach_channel(array_merge($lead, ['business_email' => '', 'business_phone' => '',
                                          'international_phone' => '', 'website' => 'https://brightsmile.test']))['channel'],
    'website', 'with neither, their website is the route');
t_is(outreach_channel(array_merge($lead, ['business_email' => '', 'business_phone' => '',
                                          'international_phone' => '', 'website' => '']))['channel'],
    'maps', 'and with nothing else, Maps is, rather than a dead end');

t_like(outreach_tel($lead), 'tel:6045550134', 'a phone number is normalised for dialling');
t_is(outreach_tel(array_merge($lead, ['business_phone' => '555'])), '',
    'and a fragment is not offered as a phone number');

/* ─────────────────────────────────────────────────────────────────────────────
 * 4. The endpoint, and the entitlement behind it
 * ──────────────────────────────────────────────────────────────────────────── */

/** Seed a lead row in the shared pool. */
$seedLead = function (array $over = []) use ($pdo): int {
    $row = $over + [
        'place_id'          => 'test_' . bin2hex(random_bytes(8)),
        'business_name'     => 'Outreach Test Cafe',
        'business_category' => 'Cafe',
        'business_city'     => 'Escalade',
        'business_phone'    => '604-555-0100',
        'business_email'    => 'owner@outreach.test',
        'website'           => '',
        'rating'            => null,
        'total_ratings'     => 0,
        'source'            => 'google_places',
    ];

    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO utiligo_leads (`' . implode('`, `', $cols) . '`) VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));

    return (int)$pdo->lastInsertId();
};

$post = function (int $userId, array $body, ?string $csrf = null) use ($app): array {
    $csrf = $csrf ?? bin2hex(random_bytes(32));
    $res = t_http('POST', $app . '/api/lead-outreach.php', [
        'json'   => $body + ['csrf_token' => $csrf],
        'cookie' => t_login($userId, ['csrf_token' => $csrf]),
    ]);
    return ['status' => $res['status'], 'json' => json_decode($res['body'], true) ?: []];
};

t_section('A draft is only produced for a lead the account was delivered');

$paidUser  = t_fixture(['plan' => 'pro']);
$freeUser  = t_fixture(['plan' => 'free']);
$otherPaid = t_fixture(['plan' => 'entrepreneur']);

$mine   = $seedLead(['business_name' => 'My Lead Ltd']);
$theirs = $seedLead(['business_name' => 'Somebody Elses Lead']);

$pdo->prepare('INSERT IGNORE INTO unlocked_leads (user_id, lead_id) VALUES (?, ?)')->execute([$paidUser, $mine]);

$refused = $post($paidUser, ['op' => 'draft', 'lead_id' => $theirs]);
t_is($refused['status'], 403, 'a lead this account was never delivered is refused');
t_is($refused['json']['error'] ?? '', 'lead_not_unlocked', 'and says so rather than pretending it is missing');
t_unlike($refused['json']['error'] ?? '', 'Somebody Elses',
    'the refusal carries none of that lead\'s details');

$wrongCsrf = t_http('POST', $app . '/api/lead-outreach.php', [
    'json'   => ['op' => 'draft', 'lead_id' => $mine, 'csrf_token' => 'not-the-token'],
    'cookie' => t_login($paidUser, ['csrf_token' => bin2hex(random_bytes(32))]),
]);
t_is($wrongCsrf['status'], 403, 'a bad CSRF token is refused');

$freeRes = $post($freeUser, ['op' => 'draft', 'lead_id' => $mine]);
t_is($freeRes['status'], 403, 'the free tier is refused');
t_is($freeRes['json']['error'] ?? '', 'plan_required', 'because outreach is part of the workspace plans');

$drafted = $post($paidUser, ['op' => 'draft', 'lead_id' => $mine]);
t_is($drafted['status'], 200, 'the lead this account holds produces a draft');
t_is($drafted['json']['draft']['angle'] ?? '', 'no_website', 'using the evidence on the row');
t_is($drafted['json']['draft']['channel'] ?? '', 'email', 'and reporting how it would be sent');
t_like((string)($drafted['json']['mailto'] ?? ''), 'owner%40outreach.test', 'with a mailto link for the customer to use');
t_ok(!($drafted['json']['draft']['profile_complete'] ?? true), 'and an honest note that their profile is empty');
t_ok(!array_key_exists('raw_payload', $drafted['json']['draft']), 'the provider blob is not part of the response');

$saved = $post($paidUser, [
    'op' => 'save_profile', 'sender_name' => 'Dana Reid',
    'offer' => 'websites for cafes', 'business_name' => 'Reid Digital', 'phone' => '604-555-0199',
]);
t_is($saved['status'], 200, 'their details save');
t_ok($saved['json']['complete'] ?? false, 'and the profile is reported complete');

$again = $post($paidUser, ['op' => 'draft', 'lead_id' => $mine]);
t_like((string)($again['json']['draft']['body'] ?? ''), 'websites for cafes.', 'the next draft uses them');
t_like((string)($again['json']['draft']['body'] ?? ''), 'Dana Reid', 'signed by the person who will send it');
t_ok($again['json']['draft']['profile_complete'] ?? false, 'and no longer asks for them');

$nameless = $post($paidUser, ['op' => 'save_profile', 'sender_name' => '', 'offer' => 'websites']);
t_is($nameless['status'], 400, 'a profile with no name is refused rather than stored');

$stored = t_user($paidUser)['outreach_profile'] ?? '';
t_like((string)$stored, 'Reid Digital', 'and what was saved is on the account');

t_section('The lead detail endpoint is behind the same gate');

// The finding this pins down: this endpoint used to require only login + CSRF and
// return the whole lead row, so any signed-in account could read the phone number
// and email address that the free tier masks and Pro pays to unlock — one
// sequential id at a time.
$enrich = function (int $userId, int $leadId) use ($app): array {
    $csrf = bin2hex(random_bytes(32));
    $res  = t_http('POST', $app . '/api/lead-enrichments.php', [
        'json'   => ['lead_id' => $leadId, 'csrf_token' => $csrf],
        'cookie' => t_login($userId, ['csrf_token' => $csrf]),
    ]);
    return ['status' => $res['status'], 'json' => json_decode($res['body'], true) ?: [], 'body' => $res['body']];
};

$leakFree = $enrich($freeUser, $mine);
t_is($leakFree['status'], 403, 'the free tier cannot read a lead\'s contact record');
t_is($leakFree['json']['error'] ?? '', 'plan_required', 'and is told it is a plan limit');
t_unlike($leakFree['body'], '604-555-0100', 'with no phone number in the refusal');

$leakOther = $enrich($otherPaid, $mine);
t_is($leakOther['status'], 403, 'nor can another paying account read a lead it was not delivered');
t_is($leakOther['json']['error'] ?? '', 'lead_not_unlocked', 'which is a different refusal, and a different fix');
t_unlike($leakOther['body'], 'owner@outreach.test', 'and that refusal leaks no email address either');

$ok = $enrich($paidUser, $mine);
t_is($ok['status'], 200, 'the account that holds the lead still gets it');
t_is($ok['json']['lead']['business_phone'] ?? '', '604-555-0100', 'with its contact details intact');

/* ── Clean up ──────────────────────────────────────────────────────────── */

foreach ([$paidUser, $freeUser, $otherPaid] as $uid) {
    try {
        $pdo->prepare('DELETE FROM unlocked_leads WHERE user_id = ?')->execute([$uid]);
    } catch (Throwable $e) { /* best effort */ }
}
foreach ([$mine, $theirs] as $lid) {
    try {
        $pdo->prepare('DELETE FROM utiligo_leads WHERE id = ?')->execute([$lid]);
    } catch (Throwable $e) { /* best effort */ }
}
