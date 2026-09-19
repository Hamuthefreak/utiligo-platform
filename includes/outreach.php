<?php
/**
 * includes/outreach.php
 *
 * Turning a delivered lead into something sendable.
 *
 * WHY THIS EXISTS
 * ──────────────
 * A customer pays for a list of businesses, opens one, and then has to write the
 * email themselves — which is the part most people are actually bad at, and the
 * part that decides whether the lead was worth anything. The workspace could
 * already answer "who should I contact"; nothing answered "what do I say".
 *
 * The draft rules, in order of importance:
 *
 *   1. IT INVENTS NOTHING. Every claim in the body comes from a column on the
 *      lead row — a missing website, a rating, a review count. There is no
 *      model here and no inference. If the row carries no usable evidence the
 *      draft says so plainly and reads as an introduction instead, because a
 *      business owner can tell a fake observation instantly and will not reply
 *      to one.
 *
 *   2. IT IS SENDABLE AS-IS. Subject, greeting, one observation, one offer, one
 *      low-pressure ask, signature. A draft that needs rewriting is a draft the
 *      customer never sends.
 *
 *   3. IT KNOWS HOW IT WOULD BE DELIVERED. Plenty of these businesses have no
 *      email address on their listing, and a Workspace whose "compose" button
 *      does nothing is worse than useless. The draft reports its own channel —
 *      email, phone, contact form or Maps — so the UI can offer the action that
 *      actually works.
 *
 * The angle is picked, not generated: outreach_angles() is a ranked table of
 * evidence the row actually supports, so "why this angle" is answerable and
 * testable. The whole table is asserted without a database.
 */

/** Longest a field may be once saved. Keeps a pasted novel out of the signature. */
if (!defined('OUTREACH_MAX_FIELD')) define('OUTREACH_MAX_FIELD', 200);

/**
 * The values the draft falls back to when the customer has not filled anything in.
 *
 * Defaults rather than an empty string, because a draft with no signature is a
 * draft nobody sends. They are deliberately generic and mention no facts about
 * the lead.
 */
function outreach_profile_defaults(array $user = []): array
{
    return [
        'sender_name'   => trim((string)($user['full_name'] ?? '')),
        'business_name' => '',
        'offer'         => '',
        'website'       => '',
        'phone'         => '',
    ];
}

/**
 * The stored profile merged over the defaults.
 *
 * $user is a utiligo_users row (or anything with full_name + outreach_profile).
 * A profile stored before a key existed still yields every key.
 */
function outreach_profile_from_user(array $user): array
{
    $defaults = outreach_profile_defaults($user);
    $stored   = $user['outreach_profile'] ?? null;

    if (is_string($stored) && $stored !== '') {
        $decoded = json_decode($stored, true);
        $stored  = is_array($decoded) ? $decoded : null;
    }

    if (!is_array($stored)) {
        return $defaults;
    }

    // Only the keys this module knows, so a hand-edited blob cannot smuggle
    // anything into the draft.
    $profile = $defaults;
    foreach (array_keys($defaults) as $key) {
        if (isset($stored[$key]) && is_scalar($stored[$key])) {
            $profile[$key] = trim((string)$stored[$key]);
        }
    }

    // A stored sender_name of '' must not override the account's own name.
    if ($profile['sender_name'] === '') {
        $profile['sender_name'] = $defaults['sender_name'];
    }

    return $profile;
}

/** Validate and trim a profile submitted by the customer. */
function outreach_profile_clean(array $input): array
{
    $clean = [];
    foreach (['sender_name', 'business_name', 'offer', 'website', 'phone'] as $key) {
        $value = isset($input[$key]) && is_scalar($input[$key]) ? (string)$input[$key] : '';
        // Newlines would break out of a signature block, and a JSON column does
        // not want them either.
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $clean[$key] = mb_substr($value, 0, OUTREACH_MAX_FIELD);
    }
    return $clean;
}

/**
 * Is the profile filled in enough to produce a draft worth sending?
 *
 * Only two things are required: who it is from, and what they sell. A draft with
 * neither is not a draft, and telling the customer that up front is better than
 * handing them a letter signed "—".
 */
function outreach_profile_complete(array $profile): bool
{
    return trim((string)($profile['sender_name'] ?? '')) !== ''
        && trim((string)($profile['offer'] ?? '')) !== '';
}

/** The one-line offer used in the body, with a safe generic fallback. */
function outreach_offer_line(array $profile): string
{
    $offer = trim((string)($profile['offer'] ?? ''));
    if ($offer === '') {
        return 'I help local businesses get found online.';
    }

    // Tolerate an offer typed as a sentence fragment and as a full sentence,
    // without ever producing "I do X..".
    $offer = rtrim($offer, ". \t");
    return 'That is what I do: ' . $offer . '.';
}

/**
 * The evidence-based angles for this lead, best first.
 *
 * Each entry is ['key', 'label', 'score', 'facts'] where `facts` are the exact
 * observations the body is allowed to make about the business. Keeping them as
 * data means outreach_draft() cannot accidentally claim something the row does
 * not support, and means "why did it choose this angle" is a test, not a guess.
 *
 * Scores order the table: a listing with no website at all is the best reason to
 * write (and the easiest to help with); `general` is always available as the
 * fallback.
 *
 * There is deliberately no "no phone number" angle. A missing phone is not a
 * reason to write to anybody, it is frequently Google's omission rather than the
 * business's, and it is already reported as a *delivery* fact by
 * outreach_channel() — where it belongs, since "we cannot call them" is a fact
 * about us, not an accusation to open an email with.
 */
function outreach_angles(array $lead): array
{
    $angles = [];

    $website = trim((string)($lead['website'] ?? ''));

    $rating  = (isset($lead['rating']) && $lead['rating'] !== null && $lead['rating'] !== '')
        ? (float)$lead['rating'] : null;
    $reviews = (int)($lead['total_ratings'] ?? 0);

    if ($website === '') {
        $angles[] = [
            'key'   => 'no_website',
            'label' => 'No website on their listing',
            'score' => 90,
            'facts' => ['there is no website on your Google listing'],
        ];
    }

    // A rating is only evidence if enough people gave one — "4.0 from one review"
    // is not a reason to email anybody.
    if ($rating !== null && $rating > 0 && $reviews >= 5 && $rating < 4.0) {
        $angles[] = [
            'key'   => 'weak_rating',
            'label' => 'Rating under 4.0 with real volume behind it',
            'score' => 80,
            'facts' => ['you are showing ' . number_format($rating, 1) . ' stars from ' . $reviews . ' reviews'],
        ];
    }

    if ($reviews > 0 && $reviews < 5) {
        $angles[] = [
            'key'   => 'few_reviews',
            'label' => 'Only a handful of reviews',
            'score' => 60,
            'facts' => ['you only have ' . $reviews . ' review' . ($reviews === 1 ? '' : 's') . ' on Google so far'],
        ];
    }

    if ($reviews === 0) {
        $angles[] = [
            'key'   => 'no_reviews',
            'label' => 'No reviews yet',
            'score' => 55,
            'facts' => ['you have no Google reviews yet'],
        ];
    }

    // The positive counterpart of weak_rating, and not decoration: an established
    // business with a website and a strong rating is the most common lead shape,
    // and without this it fell through to `general` — whose text used to admit it
    // could find nothing — while the row was in fact sitting on a specific,
    // verifiable, flattering fact. Ranked below every gap so a real problem always
    // wins, and above `general` so an accurate observation always beats a shrug.
    if ($rating !== null && $rating > 0 && $reviews >= 5 && $rating >= 4.0) {
        $angles[] = [
            'key'   => 'strong_rating',
            'label' => 'A well-reviewed business',
            'score' => 30,
            'facts' => ['you are showing ' . number_format($rating, 1) . ' stars from ' . $reviews . ' reviews'],
        ];
    }

    $angles[] = [
        'key'   => 'general',
        'label' => 'Introduction — the listing gave me nothing specific',
        'score' => 10,
        'facts' => [],
    ];

    usort($angles, static fn($a, $b) => $b['score'] <=> $a['score']);

    return $angles;
}

/** The angle the draft will use. */
function outreach_pick_angle(array $lead): array
{
    $angles = outreach_angles($lead);
    return $angles[0];
}

/**
 * How the draft can actually be delivered, given what the listing carries.
 *
 * This is not cosmetic. A lead with no email address is common, and a Compose
 * button that opens an empty mail client is the kind of thing that makes a
 * customer stop trusting the whole workspace.
 *
 * @return array{channel: string, send_to: string, label: string}
 */
function outreach_channel(array $lead): array
{
    $email   = trim((string)($lead['business_email'] ?? ''));
    $phone   = trim((string)($lead['business_phone'] ?? ($lead['international_phone'] ?? '')));
    $website = trim((string)($lead['website'] ?? ''));

    // Validated, not just non-empty: outreach_mailto() refuses a malformed
    // address, and a channel that says "Email them" next to a mailto link the
    // builder will not produce is worse than one that falls through to the phone.
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['channel' => 'email', 'send_to' => $email, 'label' => 'Email them'];
    }
    if ($phone !== '') {
        return ['channel' => 'phone', 'send_to' => $phone, 'label' => 'Call them instead'];
    }
    if ($website !== '') {
        return ['channel' => 'website', 'send_to' => $website, 'label' => 'No email address — use their site'];
    }
    return ['channel' => 'maps', 'send_to' => (string)($lead['maps_url'] ?? ''), 'label' => 'No contact details — find them on Maps'];
}

/** The subject line for one angle. */
function outreach_subject(array $lead, string $angleKey): string
{
    $name = trim((string)($lead['business_name'] ?? ''));
    $name = $name !== '' ? $name : 'your business';

    switch ($angleKey) {
        case 'no_website':  return 'A website for ' . $name . '?';
        case 'weak_rating': return 'About the reviews for ' . $name;
        case 'few_reviews':
        case 'no_reviews':  return 'Getting ' . $name . ' more reviews';
        default:            return $name . ' — quick question';
    }
}

/**
 * The draft.
 *
 * Returns:
 *   subject, body, angle, angle_label, evidence[], channel, send_to,
 *   channel_label, profile_complete, signed (bool), greeting_name
 *
 * @param array $lead    a utiligo_leads row
 * @param array $profile outreach_profile_from_user() output
 */
function outreach_draft(array $lead, array $profile): array
{
    $angle   = outreach_pick_angle($lead);
    $channel = outreach_channel($lead);

    $name     = trim((string)($lead['business_name'] ?? ''));
    $category = trim((string)($lead['business_category'] ?? ''));
    $city     = trim((string)($lead['business_city'] ?? ''));
    $sender   = trim((string)($profile['sender_name'] ?? ''));

    $paragraphs = [];

    /* ── Opening ─────────────────────────────────────────────────────────── */
    // The business name is the only name the row has, and "Hi <business name>"
    // reads better than "Hi there" without pretending to know a person.
    $greeting = $name !== '' ? 'Hi ' . $name . ' team,' : 'Hi there,';

    $where = $category !== '' && $city !== ''
        ? $category . ' businesses in ' . $city
        : ($category !== '' ? $category . ' businesses' : ($city !== '' ? 'businesses in ' . $city : 'local businesses'));

    $bridge = $name !== ''
        ? 'I came across ' . $name . ' while looking at ' . $where . '.'
        : 'I have been looking at ' . $where . ' in your area.';

    /* ── Observation — only if the row supports one ──────────────────────── */
    $facts          = (array)($angle['facts'] ?? []);
    $evidenceSaid   = '';

    if ($facts) {
        $evidenceSaid = 'One thing I noticed: ' . implode(', and ', $facts) . '.';
    } else {
        // Nothing specific was found — which is not the same as finding nothing. The
        // old wording ("I could not tell much from your listing") was false for a
        // listing with a website and years of reviews; this version is true whatever
        // the row looks like, because it describes our search rather than their
        // business.
        $evidenceSaid = 'I did not find anything specific in your listing, so I will just introduce myself.';
    }

    /* ── Offer ───────────────────────────────────────────────────────────── */
    $offerLine = outreach_offer_line($profile);

    /* ── Ask ─────────────────────────────────────────────────────────────── */
    $ask = 'Would a short call this week be worth it? If the timing is wrong, just reply and tell me so — I will not chase you.';

    $paragraphs[] = $greeting;
    $paragraphs[] = $bridge . ' ' . $evidenceSaid;
    $paragraphs[] = $offerLine;
    $paragraphs[] = $ask;

    /* ── Signature ───────────────────────────────────────────────────────── */
    $signature = [];
    if ($sender !== '')                       $signature[] = $sender;
    if (trim((string)$profile['business_name']) !== '') $signature[] = trim((string)$profile['business_name']);
    if (trim((string)$profile['website']) !== '')       $signature[] = trim((string)$profile['website']);
    if (trim((string)$profile['phone']) !== '')         $signature[] = trim((string)$profile['phone']);

    $body = implode("\n\n", $paragraphs);
    if ($signature) {
        $body .= "\n\n" . implode("\n", $signature);
    }

    return [
        'subject'          => outreach_subject($lead, (string)$angle['key']),
        'body'             => $body,
        // The one sentence that does the work, on its own. The scheduled-search
        // digest prints it under each new lead, so a customer reading the email
        // sees what they would actually say without opening the workspace first.
        'opening'          => $bridge . ' ' . $evidenceSaid,
        'angle'            => (string)$angle['key'],
        'angle_label'      => (string)$angle['label'],
        'evidence'         => array_values($facts),
        'channel'          => $channel['channel'],
        'channel_label'    => $channel['label'],
        'send_to'          => $channel['send_to'],
        'profile_complete' => outreach_profile_complete($profile),
        'signed'           => (bool)$signature,
    ];
}

/**
 * A mailto: URL for the draft, when the lead has an email address.
 *
 * Newlines are normalised to CRLF because that is what a mail client expects,
 * and the whole thing is length-capped: a mailto URL that runs past a couple of
 * thousand characters is silently truncated by some clients, which would send a
 * half-written email. Over the cap, the subject goes and the body is kept — the
 * body is the part that was expensive to produce.
 */
function outreach_mailto(array $lead, array $draft): string
{
    $to = trim((string)($lead['business_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    $body = str_replace(["\r\n", "\r"], "\n", (string)$draft['body']);
    $body = str_replace("\n", "\r\n", $body);

    $url = 'mailto:' . rawurlencode($to) . '?subject=' . rawurlencode((string)$draft['subject'])
         . '&body=' . rawurlencode($body);

    if (strlen($url) > 1800) {
        $url = 'mailto:' . rawurlencode($to) . '?body=' . rawurlencode($body);
    }

    return $url;
}

/** A tel: URL, when the listing carries a usable number. */
function outreach_tel(array $lead): string
{
    $raw = trim((string)($lead['business_phone'] ?? ''));
    if ($raw === '') {
        $raw = trim((string)($lead['international_phone'] ?? ''));
    }

    $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';
    // Fewer than seven digits is a fragment, not a phone number.
    if (strlen(preg_replace('/\D/', '', $digits) ?? '') < 7) {
        return '';
    }

    return 'tel:' . $digits;
}
