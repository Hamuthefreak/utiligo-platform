<?php
/**
 * includes/auto_search.php
 *
 * Saved searches that run themselves.
 *
 * WHY THIS EXISTS
 * ──────────────
 * `saved_searches.notify_email` was an Entrepreneur-only toggle that promised
 * "email me new leads on this search". Behind it, cron/scheduled_searches.php
 * only looked for leads that OTHER people's searches had already dropped into the
 * shared pool, and emailed the difference. So the promise held only when somebody
 * else happened to search the same city that day: a customer watching a quiet
 * town got an email that said nothing, or no email at all, forever. The one
 * feature only the top plan has could not find anything on its own.
 *
 * This module is the missing half. A saved search that is due gets a real search
 * enqueued (into the same lead_search_jobs queue the interactive search uses, so
 * it runs in a worker rather than in anybody's request), and the digest the
 * customer receives lists what THAT run found.
 *
 * WHY THE QUEUE AND NOT AN INLINE SEARCH
 * ─────────────────────────────────────
 * A lead search sleeps ~3s between Google Places page tokens and then fans out to
 * four more source engines. Running that inside a cron request is exactly the
 * mistake api/find-leads.php used to make. Enqueueing means the automation is
 * bounded by the same worker budget, the same reaper and the same retry
 * behaviour as a search a customer started by hand — one code path, not two.
 *
 * WHY THE CADENCE IS THE ONLY RATE LIMIT
 * ─────────────────────────────────────
 * Every automated run can cost Google Places quota, and no cron job has a natural
 * limit. So the gate is `last_enqueued_at + run_every_hours`, checked before any
 * enqueue: one run per saved search per window, whatever happened to the last one.
 * A failing run cannot loop, because the anchor moves when the run STARTS, not
 * when it succeeds.
 *
 * Two smaller decisions worth knowing:
 *
 *   • `force_refresh` is dropped from an automated run. A scheduled search wants
 *     whatever the shared cache holds; if it lands inside LEAD_SEARCH_CACHE_HOURS
 *     the run costs no Places quota at all, and a customer whose search runs every
 *     morning is not paying for the same city twice.
 *
 *   • An empty digest sends nothing. A daily email that says "0 new leads" is how
 *     a useful notification gets filtered out of somebody's inbox, and the drawer
 *     already shows when a search last ran.
 */

require_once __DIR__ . '/lead_search_jobs.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/outreach.php';

/** How many leads one automated run asks for. */
if (!defined('AUTO_SEARCH_LEAD_COUNT'))      define('AUTO_SEARCH_LEAD_COUNT', 20);
/** Saved searches one cron invocation will start. Bounds a bad afternoon. */
if (!defined('AUTO_SEARCH_MAX_ENQUEUE'))     define('AUTO_SEARCH_MAX_ENQUEUE', 25);
/** Saved searches one cron invocation will report on. */
if (!defined('AUTO_SEARCH_MAX_DELIVER'))     define('AUTO_SEARCH_MAX_DELIVER', 50);
/** An automated run may hold the account's only search slot only briefly; see
 *  the concurrency check in the cron. */
if (!defined('AUTO_SEARCH_DEFAULT_CADENCE')) define('AUTO_SEARCH_DEFAULT_CADENCE', 24);

/**
 * The cadences a customer may choose, in hours.
 *
 * Deliberately a fixed menu rather than a free number: six hours is the floor
 * because it is roughly the point where Places quota starts costing more than the
 * feature is worth, and there is no "every 5 minutes" option to talk somebody out
 * of later.
 *
 * @return array<int, string> hours => label
 */
function auto_search_cadences(): array
{
    return [
        6   => 'Every 6 hours',
        12  => 'Twice a day',
        24  => 'Every morning',
        72  => 'Every 3 days',
        168 => 'Weekly',
    ];
}

function auto_search_default_cadence(): int
{
    return (int)AUTO_SEARCH_DEFAULT_CADENCE;
}

/** Clamp a requested cadence to the menu. Anything unrecognised means "default". */
function auto_search_normalize_cadence($hours): int
{
    $hours = (int)$hours;
    return array_key_exists($hours, auto_search_cadences()) ? $hours : auto_search_default_cadence();
}

function auto_search_cadence_label($hours): string
{
    $hours = auto_search_normalize_cadence($hours);
    return auto_search_cadences()[$hours];
}

/**
 * Is this saved search due for a run right now?
 *
 * Three conditions, each of which is a bug the moment it is dropped:
 *
 *   notify_email          the customer asked for this. An automated search on a
 *                         saved search nobody subscribed to is quota spent on
 *                         nobody's behalf.
 *   job_token empty       one run per saved search at a time. Without it, a slow
 *                         run and a fast cron produce a second search for the same
 *                         city, and the digest reports whichever finished last.
 *   the window elapsed    last_enqueued_at + cadence. Stamped when a run STARTS,
 *                         so a failure cannot be retried in a tight loop.
 *
 * $now is passed in rather than read from the clock so the whole table is
 * assertable without sleeping.
 */
function auto_search_is_due(array $row, int $now): bool
{
    if (empty($row['notify_email'])) {
        return false;
    }

    if (trim((string)($row['job_token'] ?? '')) !== '') {
        return false;
    }

    $last = trim((string)($row['last_enqueued_at'] ?? ''));
    if ($last === '') {
        return true;
    }

    $at = strtotime($last);
    if ($at === false) {
        // An unreadable timestamp must not mean "never runs again".
        return true;
    }

    return $at + (auto_search_normalize_cadence($row['run_every_hours'] ?? 0) * 3600) <= $now;
}

/**
 * The search params an automated run sends.
 *
 * Pure, so what the automation asks for is assertable without a queue.
 *
 * @param array $params the saved search's decoded params
 */
function auto_search_run_params(array $params): array
{
    $out = [
        'city'          => substr(trim((string)($params['city'] ?? '')), 0, 100),
        'industry'      => substr(trim((string)($params['industry'] ?? '')), 0, 100),
        'keywords'      => substr(trim((string)($params['keywords'] ?? '')), 0, 255),
        'lead_count'    => (int)AUTO_SEARCH_LEAD_COUNT,
        // Never. See the header: a cached run is the difference between an
        // affordable daily automation and a Places bill.
        'force_refresh' => false,
        'auto'          => true,
    ];

    $sources = [];
    foreach ((array)($params['sources'] ?? []) as $source) {
        $source = trim((string)$source);
        if ($source !== '') {
            $sources[$source] = true;
        }
    }
    if ($sources) {
        $out['sources'] = array_keys($sources);
    }

    return $out;
}

/** Is there anything for an automated run to look for? */
function auto_search_is_searchable(array $params): bool
{
    foreach (['city', 'industry', 'keywords'] as $key) {
        if (trim((string)($params[$key] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * What to tell the customer about a finished run.
 *
 * Reads the job's stored result payload defensively: it was written by a worker
 * that may be a version behind, and a digest renderer that throws is a digest
 * nobody receives.
 *
 * Only `leads` is read, never `locked_leads`. A free-tier run returns its leads
 * masked and the rest as locked stubs; an account that was downgraded while its
 * job sat in the queue would otherwise be emailed the very contacts the plan it
 * no longer has was paying to unlock.
 *
 * @return array{count: int, leads: array, from_cache: bool, is_free_tier: bool}
 */
function auto_search_digest(array $result): array
{
    $leads = [];
    foreach ((array)($result['leads'] ?? []) as $lead) {
        if (!is_array($lead)) {
            continue;
        }
        $leads[] = [
            'id'                => (int)($lead['id'] ?? 0),
            'business_name'     => (string)($lead['business_name'] ?? ''),
            'business_category' => (string)($lead['business_category'] ?? ''),
            'business_city'     => (string)($lead['business_city'] ?? ''),
            'business_phone'    => (string)($lead['business_phone'] ?? ''),
            'business_email'    => (string)($lead['business_email'] ?? ''),
            'website'           => (string)($lead['website'] ?? ''),
            // Kept because the outreach draft reasons about them: "4.8 stars from
            // 120 reviews" must not be reported as a problem, and "4.0 from one
            // review" is not a reason to email anybody.
            'rating'            => $lead['rating'] ?? null,
            'total_ratings'     => (int)($lead['total_ratings'] ?? 0),
        ];
    }

    return [
        'count'        => count($leads),
        'leads'        => $leads,
        'from_cache'   => !empty($result['from_cache']),
        'is_free_tier' => !empty($result['is_free_tier']),
    ];
}

/** Subject line for a digest. Kept short so it survives a phone lock screen. */
function auto_search_subject(string $name, int $count): string
{
    return $count . ' new lead' . ($count === 1 ? '' : 's') . ' for: ' . $name;
}

/**
 * Send one digest. Returns true when the email was accepted for delivery.
 *
 * $row is the saved_searches row; $digest is auto_search_digest()'s output.
 *
 * $profile is the owner's outreach profile. When it is supplied, each lead in the
 * digest carries the opening sentence of the email the workspace would draft for
 * it — the digest is what the customer reads first, and "here is who to contact"
 * is worth much less than "here is what to say".
 */
function auto_search_send_digest(string $email, array $row, array $digest, string $baseUrl, array $profile = []): bool
{
    $name     = (string)($row['name'] ?? 'Saved search');
    $baseUrl  = rtrim($baseUrl, '/');
    $leadsUrl = $baseUrl . '/portal/leads.php';
    $count    = (int)($digest['count'] ?? 0);
    $leads    = (array)($digest['leads'] ?? []);

    $html  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;">';
    $html .= '<h2 style="margin:0 0 4px;color:#0f172a;font-size:18px;">' . _auto_search_h($count)
           . ' new lead' . ($count === 1 ? '' : 's') . '</h2>';
    $html .= '<p style="margin:0 0 16px;font-size:13px;color:#475569;">Your saved search '
           . '<strong>' . _auto_search_h($name) . '</strong> ran and found these.</p>';
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';

    foreach ($leads as $lead) {
        $link  = $baseUrl . '/portal/leads.php?lead=' . (int)$lead['id'];
        $html .= '<tr style="border-bottom:1px solid #f1f5f9;">';
        $html .= '<td style="padding:10px 4px;vertical-align:top;">'
               . '<a href="' . _auto_search_h($link) . '" style="color:#0f172a;font-weight:600;text-decoration:none;">'
               . _auto_search_h($lead['business_name'] !== '' ? $lead['business_name'] : '—') . '</a>';

        foreach (['business_category' => '#64748b', 'business_city' => '#94a3b8'] as $key => $colour) {
            if ($lead[$key] !== '') {
                $html .= '<br><span style="color:' . $colour . ';font-size:11px;">' . _auto_search_h($lead[$key]) . '</span>';
            }
        }

        // The opening line of the draft. One sentence, not the whole email: the
        // digest has to stay short enough to actually be read, and the rest is one
        // click away.
        $opening = '';
        if ($profile) {
            try {
                $opening = (string)(outreach_draft($lead, $profile)['opening'] ?? '');
            } catch (\Throwable $e) {
                $opening = '';
            }
        }
        if ($opening !== '') {
            $html .= '<br><span style="color:#64748b;font-size:11px;font-style:italic;">“'
                   . _auto_search_h($opening) . '”</span>';
        }

        $html .= '</td><td style="padding:10px 4px;vertical-align:top;text-align:right;">';
        if ($lead['business_phone'] !== '') {
            $html .= '<span style="display:block;color:#475569;">' . _auto_search_h($lead['business_phone']) . '</span>';
        }
        if ($lead['business_email'] !== '') {
            $html .= '<span style="display:block;"><a href="mailto:' . _auto_search_h($lead['business_email'])
                   . '" style="color:#2563eb;">' . _auto_search_h($lead['business_email']) . '</a></span>';
        }
        if ($lead['website'] !== '') {
            $html .= '<span style="display:block;"><a href="' . _auto_search_h($lead['website'])
                   . '" style="color:#2563eb;">website</a></span>';
        }
        $html .= '</td></tr>';
    }

    $html .= '</table>';
    $html .= '<p style="margin:18px 0 0;font-size:11px;color:#94a3b8;">Sent by your Utiligo scheduled search · '
           . '<a href="' . _auto_search_h($leadsUrl) . '" style="color:#2563eb;">Open the lead workspace</a> '
           . 'for the full email and to change how often this runs.</p>';
    $html .= '</div>';

    $text = $count . " new lead" . ($count === 1 ? '' : 's') . " from your saved search: " . $name . "\n\n"
          . "Open the lead workspace to view them: " . $leadsUrl . "\n";

    try {
        return send_email($email, auto_search_subject($name, $count), $html, $text);
    } catch (\Throwable $e) {
        return false;
    }
}

function _auto_search_h(?string $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}
