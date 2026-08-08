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

if (!defined('FREE_LEAD_LIMIT'))            define('FREE_LEAD_LIMIT',            3);
if (!defined('FREE_SITE_LIMIT'))            define('FREE_SITE_LIMIT',            1);
if (!defined('FREE_SEARCH_DAILY_LIMIT'))    define('FREE_SEARCH_DAILY_LIMIT',    2);
if (!defined('FREE_GENERATE_DAILY_LIMIT'))  define('FREE_GENERATE_DAILY_LIMIT',  1);
if (!defined('FREE_TEMPLATE_LIMIT'))        define('FREE_TEMPLATE_LIMIT',        2);
if (!defined('PRO_LEAD_LIMIT'))             define('PRO_LEAD_LIMIT',           700);
if (!defined('PRO_SITE_LIMIT'))             define('PRO_SITE_LIMIT',            50);
if (!defined('PRO_GENERATE_DAILY_LIMIT'))   define('PRO_GENERATE_DAILY_LIMIT',  -1);
if (!defined('PRO_TEMPLATE_LIMIT'))         define('PRO_TEMPLATE_LIMIT',        -1);
if (!defined('ENT_LEAD_LIMIT'))             define('ENT_LEAD_LIMIT',            -1);
if (!defined('ENT_SITE_LIMIT'))             define('ENT_SITE_LIMIT',           500);
if (!defined('ENT_GENERATE_DAILY_LIMIT'))   define('ENT_GENERATE_DAILY_LIMIT',  -1);
if (!defined('ENT_TEMPLATE_LIMIT'))         define('ENT_TEMPLATE_LIMIT',        -1);
if (!defined('ENT_TEAM_SEATS'))             define('ENT_TEAM_SEATS',             5);
if (!defined('ENT_CUSTOM_DOMAIN_LIMIT'))    define('ENT_CUSTOM_DOMAIN_LIMIT',   -1);
if (!defined('PRO_PLAN_PRICE'))             define('PRO_PLAN_PRICE',          21.99);
if (!defined('ENTREPRENEUR_PLAN_PRICE'))    define('ENTREPRENEUR_PLAN_PRICE', 49.99);

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
            'features'            => [
                'basic_dashboard','website_generation','zip_export',
                'revenue_dashboard','priority_support',
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
            'features'            => [
                'basic_dashboard','website_generation','zip_export',
                'revenue_dashboard','priority_support',
                'custom_domains','client_reports','team_seats',
            ],
        ],
    ];
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
    if (!in_array($user['plan'] ?? 'free', ['pro', 'entrepreneur'], true)) {
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
