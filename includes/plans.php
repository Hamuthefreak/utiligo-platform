<?php
/**
 * includes/plans.php
 * 3-plan system: free | pro | entrepreneur
 *
 * Limits come from includes/plan_limits.php (loaded via config.php).
 * Edit plan_limits.php — changes flow here automatically.
 *
 * Fallbacks below only fire if the server somehow skips plan_limits.php
 * (e.g. a broken partial deploy). They mirror plan_limits.php exactly.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

// ---- Last-resort fallbacks (values must match plan_limits.php) ----
if (!defined('FREE_LEAD_LIMIT'))            define('FREE_LEAD_LIMIT',            3);
if (!defined('FREE_SITE_LIMIT'))            define('FREE_SITE_LIMIT',            1);
if (!defined('FREE_SEARCH_DAILY_LIMIT'))    define('FREE_SEARCH_DAILY_LIMIT',    2);
if (!defined('FREE_GENERATE_DAILY_LIMIT'))  define('FREE_GENERATE_DAILY_LIMIT',  1);
if (!defined('FREE_TEMPLATE_LIMIT'))        define('FREE_TEMPLATE_LIMIT',        2);
if (!defined('PRO_LEAD_LIMIT'))             define('PRO_LEAD_LIMIT',           700);  // keep in sync with plan_limits.php
if (!defined('PRO_SITE_LIMIT'))             define('PRO_SITE_LIMIT',            50);  // keep in sync with plan_limits.php
if (!defined('PRO_GENERATE_DAILY_LIMIT'))   define('PRO_GENERATE_DAILY_LIMIT',  -1);
if (!defined('PRO_TEMPLATE_LIMIT'))         define('PRO_TEMPLATE_LIMIT',        -1);
if (!defined('ENT_LEAD_LIMIT'))             define('ENT_LEAD_LIMIT',            -1);
if (!defined('ENT_SITE_LIMIT'))             define('ENT_SITE_LIMIT',           500);
if (!defined('ENT_GENERATE_DAILY_LIMIT'))   define('ENT_GENERATE_DAILY_LIMIT',  -1);
if (!defined('ENT_TEMPLATE_LIMIT'))         define('ENT_TEMPLATE_LIMIT',        -1);
if (!defined('PRO_PLAN_PRICE'))             define('PRO_PLAN_PRICE',          21.99);
if (!defined('ENTREPRENEUR_PLAN_PRICE'))    define('ENTREPRENEUR_PLAN_PRICE', 49.99);

// ---- Plan definitions — all values come from constants above ----
function plan_config(): array {
    return [
        'free' => [
            'label'          => 'Free',
            'price'          => 0,
            'lead_limit'     => FREE_LEAD_LIMIT,
            'site_limit'     => FREE_SITE_LIMIT,
            'search_daily'   => FREE_SEARCH_DAILY_LIMIT,
            'generate_daily' => FREE_GENERATE_DAILY_LIMIT,
            'template_limit' => FREE_TEMPLATE_LIMIT,
            'features'       => [
                'basic_dashboard',
            ],
        ],
        'pro' => [
            'label'          => 'Pro',
            'price'          => PRO_PLAN_PRICE,
            'lead_limit'     => PRO_LEAD_LIMIT,
            'site_limit'     => PRO_SITE_LIMIT,
            'search_daily'   => -1,
            'generate_daily' => PRO_GENERATE_DAILY_LIMIT,
            'template_limit' => PRO_TEMPLATE_LIMIT,
            'features'       => [
                'basic_dashboard',
                'website_generation',
                'zip_export',
                'revenue_dashboard',
                'priority_support',
            ],
        ],
        'entrepreneur' => [
            'label'          => 'Entrepreneur',
            'price'          => ENTREPRENEUR_PLAN_PRICE,
            'lead_limit'     => ENT_LEAD_LIMIT,
            'site_limit'     => ENT_SITE_LIMIT,
            'search_daily'   => -1,
            'generate_daily' => ENT_GENERATE_DAILY_LIMIT,
            'template_limit' => ENT_TEMPLATE_LIMIT,
            'features'       => [
                'basic_dashboard',
                'website_generation',
                'zip_export',
                'revenue_dashboard',
                'priority_support',
                'custom_domains',
                'client_reports',
                'team_seats',
            ],
        ],
    ];
}

// ---- Helpers ----

function get_plan_config(string $plan): array {
    return plan_config()[$plan] ?? plan_config()['free'];
}

function plan_label(string $plan): string {
    return get_plan_config($plan)['label'];
}

function has_feature(string $feature, string $plan): bool {
    return in_array($feature, get_plan_config($plan)['features'], true);
}

/** Returns the lead unlock limit for the plan. -1 = unlimited. */
function plan_lead_limit(string $plan): int {
    return (int) get_plan_config($plan)['lead_limit'];
}

/** Returns the active-site limit for the plan. -1 = unlimited. */
function plan_site_limit(string $plan): int {
    return (int) get_plan_config($plan)['site_limit'];
}

/** Returns the daily search limit for the plan. -1 = unlimited. */
function plan_search_daily_limit(string $plan): int {
    return (int) get_plan_config($plan)['search_daily'];
}

function free_lead_limit(): int {
    return FREE_LEAD_LIMIT;
}

/**
 * Returns true if the user can generate/activate another site.
 */
function can_generate_site(string $plan, int $current_active): bool {
    $limit = plan_site_limit($plan);
    if ($limit === -1) return true;
    return $current_active < $limit;
}

/**
 * Returns true if the user is within their lead unlock limit.
 */
function can_unlock_lead(string $plan, int $unlocked_count): bool {
    $limit = plan_lead_limit($plan);
    if ($limit === -1) return true;
    if ($plan === 'free') return false;
    return $unlocked_count < $limit;
}

function require_paid(): void {
    require_login();
    $user = current_user();
    if (!in_array($user['plan'] ?? 'free', ['pro', 'entrepreneur'], true)) {
        header('Location: /portal/billing.php?upgrade=1');
        exit;
    }
}

/** @deprecated use require_paid() */
function require_pro(): void { require_paid(); }
