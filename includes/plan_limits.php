<?php
// includes/plan_limits.php
// THE ONE FILE TO EDIT FOR PLAN LIMITS.
// Values here are defaults. To override without editing code, use
// the Admin > Config Editor which writes to storage/config_overrides.php.
// That file is loaded first, so its defines() win everywhere.

// Load admin overrides first (written by admin/config.php)
$_overrides_file = __DIR__ . '/../storage/config_overrides.php';
if (file_exists($_overrides_file)) {
    require_once $_overrides_file;
}
unset($_overrides_file);

// FREE plan
if (!defined('FREE_LEAD_LIMIT'))           define('FREE_LEAD_LIMIT',            3);
if (!defined('FREE_SEARCH_DAILY_LIMIT'))   define('FREE_SEARCH_DAILY_LIMIT',    2);
if (!defined('FREE_SITE_LIMIT'))           define('FREE_SITE_LIMIT',            1);
if (!defined('FREE_GENERATE_DAILY_LIMIT')) define('FREE_GENERATE_DAILY_LIMIT',  1);
if (!defined('FREE_TEMPLATE_LIMIT'))       define('FREE_TEMPLATE_LIMIT',        2);

// PRO plan
if (!defined('PRO_LEAD_LIMIT'))            define('PRO_LEAD_LIMIT',           700);
if (!defined('PRO_SITE_LIMIT'))            define('PRO_SITE_LIMIT',            20);
if (!defined('PRO_GENERATE_DAILY_LIMIT'))  define('PRO_GENERATE_DAILY_LIMIT',  -1);
if (!defined('PRO_TEMPLATE_LIMIT'))        define('PRO_TEMPLATE_LIMIT',        -1);
if (!defined('PRO_CLIENT_LIMIT'))          define('PRO_CLIENT_LIMIT',          50); // CRM client cap; -1 = unlimited

// ENTREPRENEUR plan
if (!defined('ENT_LEAD_LIMIT'))            define('ENT_LEAD_LIMIT',            -1); // unlimited
if (!defined('ENT_SITE_LIMIT'))            define('ENT_SITE_LIMIT',           500);
if (!defined('ENT_GENERATE_DAILY_LIMIT'))  define('ENT_GENERATE_DAILY_LIMIT',  -1);
if (!defined('ENT_TEMPLATE_LIMIT'))       define('ENT_TEMPLATE_LIMIT',        -1);
if (!defined('ENT_TEAM_SEATS'))            define('ENT_TEAM_SEATS',             5);
if (!defined('ENT_CUSTOM_DOMAIN_LIMIT'))   define('ENT_CUSTOM_DOMAIN_LIMIT',   -1); // unlimited
if (!defined('ENT_CLIENT_LIMIT'))          define('ENT_CLIENT_LIMIT',          -1); // CRM: unlimited

// Pricing
if (!defined('PRO_PLAN_PRICE'))            define('PRO_PLAN_PRICE',          21.99);
if (!defined('ENTREPRENEUR_PLAN_PRICE'))   define('ENTREPRENEUR_PLAN_PRICE', 49.99);
