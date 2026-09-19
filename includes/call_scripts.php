<?php
/**
 * includes/call_scripts.php
 *
 * Call scripts: the thing a customer reads while the phone is ringing.
 *
 * WHY THIS EXISTS
 * ──────────────
 * The workspace hands over a lead — a name, a number, a rating — and then stops.
 * What the person is actually holding when they place the call is a notes app on
 * a second screen, or nothing. Every script here exists so that the words are
 * already open, in the same window, on whatever page they are looking at.
 *
 * Everything in this file is deliberately pure: no database, no session, no
 * HTML. Validation, ordering, seeding, bulk-import parsing and placeholder
 * substitution are the parts with rules worth arguing about, so the rules live
 * where they can be asserted directly instead of through an HTTP round-trip.
 *
 * THREE DECISIONS THAT SHAPE THE REST
 * ───────────────────────────────────
 * 1. A script body is stored VERBATIM. It is read aloud, so its line breaks,
 *    blank lines and bullets are meaning, not formatting. Only trailing
 *    whitespace is normalised (pasted from a word processor) and the line
 *    endings are canonicalised to LF. It is escaped once, on the way out.
 *
 * 2. A name is a LABEL, not prose. It is drawn in a header bar and in a
 *    switcher list, so it is forced onto one line: any run of whitespace,
 *    newlines included, collapses to a single space.
 *
 * 3. Placeholders are OPT-IN and never guessed. `{{business_name}}` and friends
 *    are substituted only when the page actually knows which lead is open; when
 *    it does not, the token is left standing in the text and reported as unfilled
 *    rather than silently blanked. A script that quietly says "Hi ," mid-call is
 *    far worse than one that shows you the gap.
 */

/** Longest a script name may be. It has to fit a header bar and a list row. */
if (!defined('CALL_SCRIPT_MAX_NAME')) define('CALL_SCRIPT_MAX_NAME', 120);

/**
 * Longest a script body may be. Generous on purpose — a full discovery call
 * script with branches runs long — but not unbounded, since the whole list is
 * sent to the browser on every page load.
 */
if (!defined('CALL_SCRIPT_MAX_BODY')) define('CALL_SCRIPT_MAX_BODY', 12000);

/**
 * Hard ceiling on scripts per account. The switcher is searchable and the panel
 * scrolls, so a few hundred is usable; this is the backstop against a script
 * bomb, not a product limit anyone should meet.
 */
if (!defined('CALL_SCRIPTS_PER_USER_CAP')) define('CALL_SCRIPTS_PER_USER_CAP', 200);

/**
 * Ordering is sparse: new scripts go on in steps of 10, so reordering two
 * neighbours only has to write the moved row rather than the whole list.
 */
if (!defined('CALL_SCRIPT_ORDER_STEP')) define('CALL_SCRIPT_ORDER_STEP', 10);

/** The fields a script body may interpolate from the open lead. */
function call_script_fields(): array
{
    return [
        'business_name'     => 'Business name',
        'business_city'     => 'City',
        'business_category' => 'Category',
        'business_phone'    => 'Phone',
        'sender_name'       => 'Your name',
    ];
}

/**
 * A name forced onto one line, trimmed and capped.
 *
 * Returns '' when nothing printable is left, which the callers treat as
 * "invalid" rather than substituting a placeholder title — an unnamed script in
 * a switcher is unusable, so refusing it up front is the kinder failure.
 */
function call_script_name_clean(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);
    return mb_substr($name, 0, CALL_SCRIPT_MAX_NAME);
}

/**
 * A body with its line endings canonicalised and its trailing whitespace
 * stripped, but with every meaningful line break preserved.
 *
 * A leading or trailing blank line is dropped: those are paste artefacts, and a
 * script that opens with an empty line wastes the first thing you see. Interior
 * blank lines are kept exactly, because they are how a script separates what you
 * say from what you do next.
 */
function call_script_body_clean(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    // Trailing spaces on a line are invisible while editing and become ragged
    // gaps when the text wraps in the panel.
    $lines = array_map(static fn(string $l): string => rtrim($l), explode("\n", $body));

    // Trimmed of ALL whitespace at the two ends, not just newlines: a pasted body
    // routinely arrives with a leading space or two, and indentation on the first
    // line of a script is a paste artefact rather than intent. Interior lines keep
    // theirs, so anything deliberately indented stays put.
    return mb_substr(trim(implode("\n", $lines)), 0, CALL_SCRIPT_MAX_BODY);
}

/**
 * Validate a create/update payload.
 *
 * @return array{ok: bool, error: string, name: string, body: string}
 *         error is '' when ok. Bodies are required: a nameless empty script is
 *         not worth a row, and every caller here is either saving a visible
 *         form or importing text that has already been written.
 */
function call_script_validate(array $in): array
{
    $rawName = isset($in['name']) && is_scalar($in['name']) ? (string)$in['name'] : '';
    $rawBody = isset($in['body']) && is_scalar($in['body']) ? (string)$in['body'] : '';

    $name = call_script_name_clean($rawName);
    $body = call_script_body_clean($rawBody);

    if ($name === '') {
        return ['ok' => false, 'error' => 'invalid_name', 'name' => '', 'body' => $body];
    }
    if ($body === '') {
        return ['ok' => false, 'error' => 'invalid_body', 'name' => $name, 'body' => ''];
    }

    return ['ok' => true, 'error' => '', 'name' => $name, 'body' => $body];
}

/**
 * The scripts a new account starts with.
 *
 * Not decoration. The panel's real failure mode is opening on an empty box and
 * asking the customer to write a cold-call script from scratch — which is the
 * exact thing they came here to avoid. These three cover the three calls that
 * actually happen on this data: a business with no website, a first call to a
 * business that already looks fine, and the follow-up nobody sends.
 *
 * They interpolate {{business_name}} on purpose: the first time the panel opens
 * on a lead, the script says the customer's name, which is what makes the point
 * of the feature land immediately.
 */
function call_script_starters(): array
{
    return [
        [
            'name' => 'No website — first call',
            'body' => "Hi, is this the owner of {{business_name}}?\n\n"
                . "My name is {{sender_name}}. I had a look for you online and I could not find a website — just your Google listing.\n\n"
                . "Is that right? Have you got a site somewhere I missed?\n\n"
                . "[Wait. Let them answer — this is the whole call.]\n\n"
                . "The reason I ask: most people looking for a {{business_category}} in {{business_city}} check a website before they call. Without one you are only visible to people who already know you.\n\n"
                . "I build simple sites for local businesses — one page, your services, your number, live in a few days. Could I send you an example?\n\n"
                . "[If yes: get the best email or number to send to, and say when they should expect it. If no: ask when is better to check back.]",
        ],
        [
            'name' => 'Already has a website',
            'body' => "Hi, is this the owner of {{business_name}}?\n\n"
                . "My name is {{sender_name}} — I work with {{business_category}} businesses around {{business_city}}.\n\n"
                . "I had a quick look at your site. Nothing wrong with it — I am not calling to tell you it is broken.\n\n"
                . "One thing I notice a lot: the site does not make it obvious how to get a quote or book you, so people who are ready to buy leave and call whoever is easier.\n\n"
                . "Would it be worth me taking five minutes to show you what that one change looks like on your page?\n\n"
                . "[If they ask for a price: give a range, do not guess a number. If they say they have someone: ask who, and offer to be a second opinion. No pressure either way.]",
        ],
        [
            'name' => 'Follow-up',
            'body' => "Hi, it is {{sender_name}} — I spoke with you about {{business_name}} and the website.\n\n"
                . "Not chasing you, I just said I would come back to it.\n\n"
                . "[Give them a beat to remember. If they have forgotten, do not make them say so — recap in one sentence.]\n\n"
                . "The thing I wanted to check is just whether it is worth picking up now, or whether it is a next-quarter thing.\n\n"
                . "Either answer is fine. If it is a no I will stop calling, honestly.",
        ],
    ];
}

/**
 * Parse a bulk paste into individual scripts.
 *
 * The format is one line of at least three dashes between scripts, the first
 * line of each block being its name. It is a deliberately boring format —
 * something that can be explained in one sentence and produced by hand from a
 * Google Doc without a converter — because the alternative is a file-upload
 * flow for what is usually three paragraphs of text.
 *
 * A leading '#' or a trailing ':' on the name line is tolerated, since that is
 * how people mark a heading when they type one.
 *
 * @return array{scripts: array<int, array{name: string, body: string}>, skipped: int}
 */
function call_script_parse_import(string $blob): array
{
    $blob = str_replace(["\r\n", "\r"], "\n", $blob);

    $blocks = preg_split('/^[ \t]*-{3,}[ \t]*$/m', $blob) ?: [];

    $scripts = [];
    $skipped = 0;

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue; // stray separators, leading/trailing blank space
        }

        $lines = explode("\n", $block);
        $name  = call_script_name_clean(ltrim(trim($lines[0]), '#'));
        $name  = rtrim($name, ':');
        $name  = call_script_name_clean($name);

        $body = call_script_body_clean(implode("\n", array_slice($lines, 1)));

        // A block with a heading but no text is a separator typo, not a script.
        if ($name === '' || $body === '') {
            $skipped++;
            continue;
        }

        $scripts[] = ['name' => $name, 'body' => $body];
    }

    return ['scripts' => $scripts, 'skipped' => $skipped];
}

/**
 * Give every script the shape the browser expects, and nothing else.
 *
 * Explicit rather than `SELECT *` shaped, so a column added later cannot leak
 * into the response by accident — the panel renders this object directly.
 */
function call_script_public(array $row): array
{
    return [
        'id'           => (int)($row['id'] ?? 0),
        'name'         => (string)($row['name'] ?? ''),
        'body'         => (string)($row['body'] ?? ''),
        'sort_order'   => (int)($row['sort_order'] ?? 0),
        'source'       => (string)($row['source'] ?? 'manual'),
        'times_used'   => (int)($row['times_used'] ?? 0),
        'last_used_at' => $row['last_used_at'] ?? null,
        'created_at'   => $row['created_at'] ?? null,
    ];
}

/**
 * The customer's ordering, with a stable tie-break.
 *
 * Sorted in PHP rather than SQL so the rule is assertable without a database,
 * and because the input comes from a query that may already be ordered.
 * Equal sort_order values fall back to oldest-first: two scripts created in the
 * same millisecond must not swap places between two page loads, or a customer
 * watching the list would see it shuffle for no reason.
 */
function call_script_order(array $rows): array
{
    $rows = array_values($rows);
    usort($rows, static function (array $a, array $b): int {
        return [ (int)($a['sort_order'] ?? 0), (int)($a['id'] ?? 0) ]
           <=> [ (int)($b['sort_order'] ?? 0), (int)($b['id'] ?? 0) ];
    });
    return $rows;
}

/** The sort_order for a script appended to this list, leaving a gap to insert into. */
function call_script_next_order(array $rows): int
{
    $max = 0;
    foreach ($rows as $r) {
        $max = max($max, (int)($r['sort_order'] ?? 0));
    }
    return $max + CALL_SCRIPT_ORDER_STEP;
}

/**
 * Fill a script's placeholders from a lead row.
 *
 * @return array{body: string, filled: array<int,string>, missing: array<int,string>}
 *         `missing` lists tokens that were present but had no value — the panel
 *         warns on those instead of printing a blank, and `filled` lets it say
 *         which lead it used. A token that is not in call_script_fields() is
 *         left alone entirely and reported as missing, so a typo'd
 *         {{businessname}} shows up as a warning rather than disappearing.
 */
function call_script_fill(string $body, array $lead, array $extra = []): array
{
    $values   = [];
    $fromLead = [];   // which keys got their value from an actual lead row

    foreach (array_keys(call_script_fields()) as $key) {
        $direct = $lead[$key] ?? '';
        $direct = is_scalar($direct) ? trim((string)$direct) : '';
        if ($direct !== '') {
            $values[$key]   = $direct;
            $fromLead[$key] = true;
            continue;
        }
        $fallback = $extra[$key] ?? '';
        $fallback = is_scalar($fallback) ? trim((string)$fallback) : '';
        if ($fallback !== '') {
            $values[$key] = $fallback;
        }
    }

    $filled     = [];
    $missing    = [];
    $leadUsed   = false;

    $out = preg_replace_callback(
        '/\{\{\s*([a-z_]+)\s*\}\}/i',
        static function (array $m) use ($values, $fromLead, &$filled, &$missing, &$leadUsed): string {
            $key = strtolower($m[1]);
            if (array_key_exists($key, $values)) {
                // De-duplicated below: one entry per token, not per occurrence.
                $filled[] = $key;
                if (isset($fromLead[$key])) $leadUsed = true;
                return $values[$key];
            }
            $missing[] = $key;
            return $m[0]; // left standing, on purpose — see the file docblock
        },
        $body
    ) ?? $body;

    return [
        'body'    => $out,
        'filled'  => array_values(array_unique($filled)),
        'missing' => array_values(array_unique($missing)),
        // Whether anything came from an actual lead, so the panel only claims
        // "filled from <business>" when a business was really involved. A script
        // that only needs {{sender_name}} must not be announced as lead-aware.
        'fromLead' => $leadUsed,
    ];
}

/** Every placeholder token a body mentions, whether or not it can be filled. */
function call_script_tokens(string $body): array
{
    $found = [];
    if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $body, $m)) {
        foreach ($m[1] as $key) {
            $found[strtolower($key)] = true;
        }
    }
    return array_keys($found);
}
