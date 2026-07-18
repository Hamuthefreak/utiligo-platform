<?php
/**
 * config.php — Central configuration for the Utiligo platform.
 * =====================================================================
 * TO CHANGE PLAN LIMITS: edit the "PLAN LIMITS" section below.
 * Every constant has a plain-English comment describing what it does.
 * =====================================================================
 */

error_reporting(E_ALL);
define('APP_ENV', 'development');
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
}

// ---- Platform database ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'utiligo_platform');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');

// ---- User accounts database ----
define('USERDB_HOST', 'localhost');
define('USERDB_NAME', 'utiligo_users_db');
define('USERDB_USER', 'CHANGE_ME');
define('USERDB_PASS', 'CHANGE_ME');

// ---- Google Places API ----
define('GOOGLE_PLACES_API_KEY', 'YOUR_GOOGLE_PLACES_API_KEY');
define('MAX_PLACES_DETAILS_LOOKUPS', 20);

// ---- Stripe ----
define('STRIPE_SECRET_KEY',      'YOUR_STRIPE_SECRET_KEY');
define('STRIPE_PUBLISHABLE_KEY', 'YOUR_STRIPE_PUBLISHABLE_KEY');
define('STRIPE_WEBHOOK_SECRET',  'YOUR_STRIPE_WEBHOOK_SECRET');

// ---- Payment testing mode ----
define('TEST_PAYMENT_MODE', true);

// ---- Pricing ----
define('PRO_PLAN_PRICE',          21.99);  // monthly price shown on billing page
define('ENTREPRENEUR_PLAN_PRICE', 49.99);  // monthly price shown on billing page

// =====================================================================
//  PLAN LIMITS  — edit anything in this block to change quotas
// =====================================================================

// -- FREE plan --
define('FREE_LEAD_LIMIT',           3);   // max leads shown per search result
define('FREE_SEARCH_DAILY_LIMIT',   2);   // lead searches allowed per 24 h
define('FREE_SITE_LIMIT',           1);   // max active generated sites at once
define('FREE_GENERATE_DAILY_LIMIT', 1);   // site generations allowed per 24 h
define('FREE_TEMPLATE_LIMIT',       2);   // number of templates unlocked on free

// -- PRO plan --
define('PRO_LEAD_LIMIT',            120); // max lifetime lead unlocks (-1 = unlimited)
define('PRO_SITE_LIMIT',            200); // max active generated sites at once
define('PRO_GENERATE_DAILY_LIMIT',  -1);  // site generations per 24 h (-1 = unlimited)
define('PRO_TEMPLATE_LIMIT',        -1);  // templates available (-1 = all)

// -- ENTREPRENEUR plan --
define('ENT_LEAD_LIMIT',            -1);  // max lifetime lead unlocks (-1 = unlimited)
define('ENT_SITE_LIMIT',           500);  // max active generated sites at once
define('ENT_GENERATE_DAILY_LIMIT',  -1);  // site generations per 24 h (-1 = unlimited)
define('ENT_TEMPLATE_LIMIT',        -1);  // templates available (-1 = all)

// =====================================================================

// ---- Search cache ----
define('LEAD_SEARCH_CACHE_HOURS', 24);

// ---- Mailer (Brevo) ----
define('BREVO_API_KEY', 'YOUR_BREVO_API_KEY');
define('SMTP_FROM_EMAIL', 'noreply@utiligo.ca');
define('SMTP_FROM_NAME', 'Utiligo');
define('BREVO_LIST_ALL_USERS',  1);
define('BREVO_LIST_PRO_USERS',  2);
define('BREVO_LIST_FREE_USERS', 3);

// ---- Security ----
define('EMAIL_VERIFICATION_REQUIRED', true);
define('TWO_FA_CODE_EXPIRY_MINUTES', 10);
define('PASSWORD_RESET_EXPIRY_MINUTES', 60);
define('APP_BASE_URL', 'https://utiligo.ca');
define('CRON_SECRET', 'CHANGE_ME_TO_RANDOM_STRING');

// ---- File uploads ----
define('MAX_LOGO_UPLOAD_BYTES', 2 * 1024 * 1024);
define('ALLOWED_LOGO_TYPES', ['image/png', 'image/jpeg', 'image/svg+xml']);

// ---- Rate limiting ----
define('RATE_LIMIT_FIND_LEADS',     10);
define('RATE_LIMIT_GENERATE_SITE',   5);
define('RATE_LIMIT_UPLOAD_IMAGE',   30);
define('RATE_LIMIT_SAVE_SITE_PAGE', 60);
define('RATE_LIMIT_MANAGE_SITE',    30);
define('SITE_EDITOR_SECRET', 'change-me-to-a-random-string-in-production');

// ---- Feature flags ----
define('ENABLE_BOOKING',        false);
define('ENABLE_ECOMMERCE',      false);
define('ENABLE_BLOG',           false);
define('ENABLE_CUSTOM_DOMAINS', false);

// ---- Defensive fallbacks (keep last — only fire if a constant was missed above) ----
$_cfg_defaults = [
    'MAX_PLACES_DETAILS_LOOKUPS'  => 20,
    'LEAD_SEARCH_CACHE_HOURS'     => 24,
    'FREE_SEARCH_DAILY_LIMIT'     => 2,
    'FREE_GENERATE_DAILY_LIMIT'   => 1,
    'FREE_TEMPLATE_LIMIT'         => 2,
    'FREE_SITE_LIMIT'             => 1,
    'FREE_LEAD_LIMIT'             => 3,
    'PRO_LEAD_LIMIT'              => 120,
    'PRO_SITE_LIMIT'              => 200,
    'PRO_GENERATE_DAILY_LIMIT'    => -1,
    'PRO_TEMPLATE_LIMIT'          => -1,
    'ENT_LEAD_LIMIT'              => -1,
    'ENT_SITE_LIMIT'              => 500,
    'ENT_GENERATE_DAILY_LIMIT'    => -1,
    'ENT_TEMPLATE_LIMIT'          => -1,
    'RATE_LIMIT_UPLOAD_IMAGE'     => 30,
    'RATE_LIMIT_SAVE_SITE_PAGE'   => 60,
    'RATE_LIMIT_MANAGE_SITE'      => 30,
    'SITE_EDITOR_SECRET'          => 'change-me-to-a-random-string-in-production',
    'ENTREPRENEUR_PLAN_PRICE'     => 49.99,
];
foreach ($_cfg_defaults as $_name => $_value) {
    if (!defined($_name)) define($_name, $_value);
}
unset($_cfg_defaults, $_name, $_value);

if (session_status() === PHP_SESSION_NONE) session_start();
