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
function plan_lead_limit(string $plan): int {
    return (int) get_plan_config($plan)['lead_limit'];
}
function plan_site_limit(string $plan): int {
    return (int) get_plan_config($plan)['site_limit'];
}
function plan_search_daily_limit(string $plan): int {
    return (int) get_plan_config($plan)['search_daily'];
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
