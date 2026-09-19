<?php
/**
 * includes/plans.php
 * 3-plan system: free | pro | entrepreneur
 *
 * NOTE: auth.php is intentionally NOT required here at the top level.
 * plans.php is included by portal pages that already load auth.php
 * before plans.php. A top-level require_once auth.php here creates a
 * circular boot chain (plans->auth->config->bootstrap->...->plans)
 * that causes silent HTTP 500 on InfinityFree.
 */
require_once __DIR__ . '/../config.php';

/**
 * True when $plan is any paid tier (Pro or Entrepreneur).
 *
 * USE THIS — not `$plan === 'pro'` — for every "is this a paying customer?"
 * decision.  The two paid tiers are a ladder, not a set: Entrepreneur is the
 * TOP tier and inherits every Pro capability.  Several endpoints historically
 * tested `=== 'pro'`, which silently locked Entrepreneur (the $49.99 plan)
 * out of image uploads, site-editor saves and share-link extensions that Pro
 * users got.  Anything gated on "paid" must accept both.
 *
 * @param string|null $plan
 */
function is_paid_plan(?string $plan): bool {
    return in_array((string)$plan, ['pro', 'entrepreneur'], true);
}

/**
 * True when $plan unlocks Pro-tier features.  Today that is exactly the paid
 * set (Entrepreneur is a superset of Pro), but keeping this name separate
 * from is_paid_plan() lets "paid" and "has Pro features" diverge later
 * without hunting down every call site.
 *
 * @param string|null $plan
 */
function plan_has_pro_features(?string $plan): bool {
    return is_paid_plan($plan);
}

/**
 * Look up a per-admin-set limit override for one user+key.  Returns the
 * override value when present, otherwise the $default passed in.  Never
 * throws — on any DB error / missing table / unavailable connection, the
 * plan default is returned silently so a partial DB outage never blocks
 * lead search or site generation.
 *
 * Memoized per-request via a static cache keyed by user_id+key. A single
 * request that needs both lead_limit and site_limit for the same user
 * only hits the DB once.
 */
function user_limit_override(int $user_id, string $limit_key, int $default): int
{
    static $cache = [];
    if ($user_id <= 0) return $default;

    $cacheKey = $user_id . '|' . $limit_key;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    $val = _load_user_limit_overrides($user_id)[$limit_key] ?? $default;
    return $cache[$cacheKey] = $val;
}

/**
 * Internal: SELECT every override row for one user and stash them in the
 * per-request cache as a key=>value map, so callers of user_limit_override
 * afterwards see them without an extra round-trip.  Falls back to an
 * empty array on any error (table missing, DB down, etc.).
 */
function _load_user_limit_overrides(int $user_id): array
{
    static $loaded = [];
    if (isset($loaded[$user_id])) return $loaded[$user_id];

    $map = [];
    try {
        if (function_exists('get_user_db')) {
            $udb = get_user_db();
            $stmt = $udb->prepare(
                'SELECT limit_key, limit_value FROM `user_limit_overrides` WHERE user_id = ?'
            );
            $stmt->execute([$user_id]);
            foreach ($stmt->fetchAll() as $r) {
                $map[(string)$r['limit_key']] = (int)$r['limit_value'];
            }
        }
    } catch (\Throwable $e) {
        // Migration 020 not applied yet, or user DB unreachable — fall
        // through silently with an empty map (so user_limit_override()
        // returns its $default).  Logged so the operator can see it.
        if (function_exists('error_log')) {
            error_log('[user_limit_override] ' . $e->getMessage());
        }
    }
    return $loaded[$user_id] = $map;
}

/**
 * Public alias used by portal/api pages to prime the cache once at the
 * top of the request so the first call to user_limit_override() doesn't
 * trigger a SELECT.  Safe no-op if the table or DB is unavailable.
 */
function preload_user_limit_overrides(int $user_id): void
{
    if ($user_id > 0) _load_user_limit_overrides($user_id);
}

// ── Plan limit constants live in ONE place ────────────────────────────────
// Every FREE_*/PRO_*/ENT_* constant is defined in includes/plan_limits.php,
// which config.php loads before this file.  This file used to redeclare them
// behind `if (!defined(...))` guards, which made those copies dead code that
// silently drifted from the real values (PRO_SITE_LIMIT was 50 here and 20 in
// plan_limits.php).  Do NOT reintroduce local copies — and never hardcode a
// plan limit or price in a page or script; read it from these constants (or
// the plan_*() helpers below) so the Admin > Config Editor keeps working.
require_once __DIR__ . '/plan_limits.php';

/**
 * Machine-readable plan facts (limits + prices) for the front-end.
 *
 * Marketing/onboarding copy used to hardcode these ("50 generated sites",
 * "120 leads • 200 active sites", "$21.99") which silently rotted as the real
 * limit in includes/plan_limits.php changed.  Pages render this as a single
 * `data-plan-info` JSON attribute on <body> and JS reads it, so the Config
 * Editor stays the only place anyone edits a limit or a price.
 *
 * -1 means unlimited.  Sentinels are preserved so callers can format them.
 */
function plan_info(): array {
    $int = static fn(string $k, int $d): int     => defined($k) ? (int)constant($k)   : $d;
    $flt = static fn(string $k, float $d): float => defined($k) ? (float)constant($k) : $d;

    return [
        'free' => [
            'leads'     => $int('FREE_LEAD_LIMIT', 3),
            'sites'     => $int('FREE_SITE_LIMIT', 1),
            'searches'  => $int('FREE_SEARCH_DAILY_LIMIT', 2),
            'templates' => $int('FREE_TEMPLATE_LIMIT', 2),
            'price'     => 0.0,
        ],
        'pro' => [
            'leads'     => $int('PRO_LEAD_LIMIT', 700),
            'sites'     => $int('PRO_SITE_LIMIT', 20),
            'templates' => $int('PRO_TEMPLATE_LIMIT', -1),
            'price'     => $flt('PRO_PLAN_PRICE', 21.99),
        ],
        'entrepreneur' => [
            'leads'     => $int('ENT_LEAD_LIMIT', -1),
            'sites'     => $int('ENT_SITE_LIMIT', 500),
            'seats'     => $int('ENT_TEAM_SEATS', 5),
            'templates' => $int('ENT_TEMPLATE_LIMIT', -1),
            'price'     => $flt('ENTREPRENEUR_PLAN_PRICE', 49.99),
        ],
    ];
}

/**
 * Ready-to-echo `data-plan-info` attribute (quoted JSON) for a <body> tag.
 * Returns '' if encoding fails so a caller can never emit broken HTML.
 */
function plan_info_data_attr(): string {
    $json = json_encode(plan_info(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return '';
    return 'data-plan-info="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"';
}

function plan_config(): array {
    return [
        'free' => [
            'label'               => 'Free',
            'price'               => 0,
            'lead_limit'          => FREE_LEAD_LIMIT,
            'site_limit'          => FREE_SITE_LIMIT,
            'search_daily'        => FREE_SEARCH_DAILY_LIMIT,
            'generate_daily'      => FREE_GENERATE_DAILY_LIMIT,
            'template_limit'      => FREE_TEMPLATE_LIMIT,
            'team_seats'          => 0,
            'custom_domain_limit' => 0,
            // New leads-workspace gates (Phase 0). Empty arrays = none.
            'lead_sources'        => FREE_LEAD_SOURCES ?? '',
            'export_formats'      => FREE_EXPORT_FORMATS ?? '',
            'export_daily'        => FREE_EXPORT_DAILY ?? 0,
            'enrich_providers'    => FREE_ENRICH_PROVIDERS ?? '',
            'export_max_rows'     => 0,
            'features'            => ['basic_dashboard'],
        ],
        'pro' => [
            'label'               => 'Pro',
            'price'               => PRO_PLAN_PRICE,
            'lead_limit'          => PRO_LEAD_LIMIT,
            'site_limit'          => PRO_SITE_LIMIT,
            'search_daily'        => -1,
            'generate_daily'      => PRO_GENERATE_DAILY_LIMIT,
            'template_limit'      => PRO_TEMPLATE_LIMIT,
            'team_seats'          => 0,
            'custom_domain_limit' => 0,
            'lead_sources'        => PRO_LEAD_SOURCES ?? '',
            'export_formats'      => PRO_EXPORT_FORMATS ?? '',
            'export_daily'        => PRO_EXPORT_DAILY ?? 5,
            'enrich_providers'    => PRO_ENRICH_PROVIDERS ?? '',
            'export_max_rows'     => PRO_EXPORT_MAX_ROWS ?? 5000,
            'features'            => [
                'basic_dashboard','website_generation','zip_export',
                'revenue_dashboard','priority_support',
                'lead_workspace','lead_export','lead_enrich_basic','saved_searches',
                'call_scripts',
            ],
        ],
        'entrepreneur' => [
            'label'               => 'Entrepreneur',
            'price'               => ENTREPRENEUR_PLAN_PRICE,
            'lead_limit'          => ENT_LEAD_LIMIT,
            'site_limit'          => ENT_SITE_LIMIT,
            'search_daily'        => -1,
            'generate_daily'      => ENT_GENERATE_DAILY_LIMIT,
            'template_limit'      => ENT_TEMPLATE_LIMIT,
            'team_seats'          => ENT_TEAM_SEATS,
            'custom_domain_limit' => ENT_CUSTOM_DOMAIN_LIMIT,
            'lead_sources'        => ENT_LEAD_SOURCES ?? '',
            'export_formats'      => ENT_EXPORT_FORMATS ?? '',
            'export_daily'        => ENT_EXPORT_DAILY ?? 50,
            'enrich_providers'    => ENT_ENRICH_PROVIDERS ?? '',
            'export_max_rows'     => ENT_EXPORT_MAX_ROWS ?? 50000,
            'features'            => [
                'basic_dashboard','website_generation','zip_export',
                'revenue_dashboard','priority_support',
                'custom_domains','client_reports','team_seats',
                // lead_enrich_basic AND _full: Entrepreneur does have basic
                // enrichment, because "full" is basic plus more. Listing only the
                // fuller name broke the ladder on paper — Pro's set was not a
                // subset of Entrepreneur's — which is the kind of drift that is
                // invisible until somebody finally reads this array. See
                // test_plan_ladder.php, which now fails if it happens again.
                'lead_workspace','lead_export','lead_enrich_basic','lead_enrich_full',
                'saved_searches','scheduled_searches','bulk_unlock',
                'call_scripts',
            ],
        ],
    ];
}

/**
 * Tiny helper that turns a comma-separated string from plan_limits.php
 * into a cleaned array. Lives outside plan_config() so it is callable as
 * a static (PHP 8.1+ allows first-class callable on static methods, but
 * older support targets need this to be a top-level helper).
 */
function _plan_split_csv(string $csv): array {
    $csv = trim($csv);
    if ($csv === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $csv))));
}

function get_plan_config(string $plan): array {
    return plan_config()[$plan] ?? plan_config()['free'];
}
function plan_label(string $plan): string {
    return get_plan_config($plan)['label'];
}
function has_feature(string $feature, string $plan): bool {
    return in_array($feature, get_plan_config($plan)['features'], true);
}
function plan_lead_limit(string $plan, ?int $user_id = null): int {
    $base = (int) get_plan_config($plan)['lead_limit'];
    if ($user_id === null) return $base;
    return user_limit_override($user_id, 'lead_limit', $base);
}
function plan_site_limit(string $plan, ?int $user_id = null): int {
    $base = (int) get_plan_config($plan)['site_limit'];
    if ($user_id === null) return $base;
    return user_limit_override($user_id, 'site_limit', $base);
}
function plan_search_daily_limit(string $plan, ?int $user_id = null): int {
    $base = (int) get_plan_config($plan)['search_daily'];
    if ($user_id === null) return $base;
    return user_limit_override($user_id, 'search_daily', $base);
}
/**
 * CRM client cap (Pro plan only; ENT is unlimited by default). Defined
 * in includes/plan_limits.php as PRO_CLIENT_LIMIT / ENT_CLIENT_LIMIT.
 * Honors a per-user override (limit_key='client_limit').
 */
function plan_client_limit(string $plan, ?int $user_id = null): int {
    if ($plan === 'entrepreneur') {
        $base = defined('ENT_CLIENT_LIMIT') ? (int)ENT_CLIENT_LIMIT : -1;
    } elseif ($plan === 'pro') {
        $base = defined('PRO_CLIENT_LIMIT') ? (int)PRO_CLIENT_LIMIT : 50;
    } else {
        $base = 0; // free plan has no CRM access
    }
    if ($user_id === null) return $base;
    return user_limit_override($user_id, 'client_limit', $base);
}
function plan_team_seats(string $plan): int {
    return (int) (get_plan_config($plan)['team_seats'] ?? 0);
}
function plan_custom_domain_limit(string $plan): int {
    return (int) (get_plan_config($plan)['custom_domain_limit'] ?? 0);
}
function free_lead_limit(): int {
    return FREE_LEAD_LIMIT;
}
function can_generate_site(string $plan, int $current_active): bool {
    $limit = plan_site_limit($plan);
    if ($limit === -1) return true;
    return $current_active < $limit;
}
function can_unlock_lead(string $plan, int $unlocked_count): bool {
    $limit = plan_lead_limit($plan);
    if ($limit === -1) return true;
    if ($plan === 'free') return false;
    return $unlocked_count < $limit;
}
function require_paid(): void {
    if (!function_exists('require_login')) require_once __DIR__ . '/auth.php';
    require_login();
    $user = current_user();
    if (!is_paid_plan($user['plan'] ?? 'free')) {
        header('Location: /portal/billing?upgrade=1');
        exit;
    }
}
function require_entrepreneur(): void {
    if (!function_exists('require_login')) require_once __DIR__ . '/auth.php';
    require_login();
    $user = current_user();
    if (($user['plan'] ?? 'free') !== 'entrepreneur') {
        header('Location: /portal/billing?upgrade=1&plan=entrepreneur');
        exit;
    }
}
/** @deprecated use require_paid() */
function require_pro(): void { require_paid(); }

// ── Leads-workspace accessors (Phase 0) ─────────────────────────────────────
// Numeric gates (export daily count) honor the existing per-user override
// table because user_limit_override() is int-typed.  String-typed gates
// (sources / formats / enrich providers) come from plan_limits.php
// constants (editable via Admin > Config Editor under the new keys) but
// are NOT routed through per-user overrides for now: the override table
// stores ints only (see line ~57 cast) and admins are unlikely to want
// per-user source lists.  Per-row scanning still happens; the table can
// be widened later if per-user string overrides become needed.

/** Allowed lead sources (e.g. google_places, osm) for this user's plan. */
function plan_lead_sources(string $plan): array {
    return _plan_split_csv(get_plan_config($plan)['lead_sources'] ?? '');
}

/** Allowed export formats (csv,xlsx,vcard,json,pdf) for this user's plan. */
function plan_export_formats(string $plan): array {
    return _plan_split_csv(get_plan_config($plan)['export_formats'] ?? '');
}

/** Allowed export jobs per 24h for this user (0 = none). Honors per-user override. */
function plan_export_daily_limit(string $plan, ?int $user_id = null): int {
    $base = (int) (get_plan_config($plan)['export_daily'] ?? 0);
    if ($user_id === null) return $base;
    return user_limit_override($user_id, 'export_daily', $base);
}

/** Allowed enrichment providers (e.g. website_finder, email_pattern) per plan. */
function plan_enrich_providers(string $plan): array {
    return _plan_split_csv(get_plan_config($plan)['enrich_providers'] ?? '');
}

/** Max rows allowed in an export file (memory / abuse backstop). */
function plan_export_max_rows(string $plan): int {
    return (int) (get_plan_config($plan)['export_max_rows'] ?? 0);
}

/**
 * Whether a user can use the lead workspace at all (Pro+).
 *
 * `?string` deliberately, like every other predicate in this file: a missing plan
 * is a user we cannot identify, and the answer to that is NO, not a TypeError.
 * This one used to require a string, so a caller that forgot the `?? 'free'`
 * coalesce got a fatal error — during a page render, in front of the customer —
 * where it should simply have been refused.
 */
function can_use_lead_workspace(?string $plan): bool {
    return plan_has_pro_features($plan);
}

/** Whether a user is allowed to schedule recurring searches (Ent only). */
function can_schedule_searches(?string $plan): bool {
    return (string)$plan === 'entrepreneur';
}

/**
 * Whether a user gets the call-script dock.
 *
 * Both paid tiers, like every other "Pro feature" in this product: the tiers are
 * a LADDER, not a set, so Entrepreneur has everything Pro has.  This was briefly
 * scoped to Pro alone, which made upgrading a DOWNGRADE — an Entrepreneur
 * customer lost the dock the moment they paid more, and the only honest way to
 * describe that on a pricing page is a row with a cross in the expensive column.
 * Nobody buys their way into losing a feature, so it is a superset again.
 *
 * Kept as its own named predicate rather than inlined, for the same reason
 * can_use_lead_workspace() is: if the tiers ever really do need to diverge for
 * this feature, there is one place to change and one place to look.  Until then
 * it is deliberately an alias, and it must NOT be narrowed without re-checking
 * the four doors that call it — the API, the pop-out page, and the stylesheet and
 * script tags in includes/portal_layout.php — because a gate applied at the door
 * is what stops a locked-out account from being sent the assets at all.
 */
function can_use_call_scripts(?string $plan): bool {
    return plan_has_pro_features($plan);
}
