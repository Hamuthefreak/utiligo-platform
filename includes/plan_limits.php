<?php
// includes/plan_limits.php
// THE ONE FILE TO EDIT FOR PLAN LIMITS.
// Edit numbers here - they update everywhere automatically.
// All defines are guarded with if(!defined()) so this file is safe to load multiple times.

// FREE plan
if (!defined('FREE_LEAD_LIMIT'))           define('FREE_LEAD_LIMIT',            3);
if (!defined('FREE_SEARCH_DAILY_LIMIT'))   define('FREE_SEARCH_DAILY_LIMIT',    2);
if (!defined('FREE_SITE_LIMIT'))           define('FREE_SITE_LIMIT',            1);
if (!defined('FREE_GENERATE_DAILY_LIMIT')) define('FREE_GENERATE_DAILY_LIMIT',  1);
if (!defined('FREE_TEMPLATE_LIMIT'))       define('FREE_TEMPLATE_LIMIT',        2);

// PRO plan
if (!defined('PRO_LEAD_LIMIT'))            define('PRO_LEAD_LIMIT',           700);
if (!defined('PRO_SITE_LIMIT'))            define('PRO_SITE_LIMIT',            50);
if (!defined('PRO_GENERATE_DAILY_LIMIT'))  define('PRO_GENERATE_DAILY_LIMIT',  -1);
if (!defined('PRO_TEMPLATE_LIMIT'))        define('PRO_TEMPLATE_LIMIT',        -1);

// ENTREPRENEUR plan
if (!defined('ENT_LEAD_LIMIT'))            define('ENT_LEAD_LIMIT',            -1);
if (!defined('ENT_SITE_LIMIT'))            define('ENT_SITE_LIMIT',           500);
if (!defined('ENT_GENERATE_DAILY_LIMIT'))  define('ENT_GENERATE_DAILY_LIMIT',  -1);
if (!defined('ENT_TEMPLATE_LIMIT'))        define('ENT_TEMPLATE_LIMIT',        -1);

// Pricing
if (!defined('PRO_PLAN_PRICE'))            define('PRO_PLAN_PRICE',          21.99);
if (!defined('ENTREPRENEUR_PLAN_PRICE'))   define('ENTREPRENEUR_PLAN_PRICE', 49.99);
