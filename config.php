<?php
/**
 * config.php — Central configuration for the Utiligo platform.
 * =====================================================================
 * Secrets (CRON_SECRET, SITE_EDITOR_SECRET) are loaded from environment
 * variables so they are never hardcoded in source control.
 * Set them on your server:
 *   export UTILIGO_CRON_SECRET="your-random-string"
 *   export UTILIGO_EDITOR_SECRET="your-random-string"
 * =====================================================================
 */

error_reporting(E_ALL);

// Change to 'development' locally only.
define('APP_ENV', getenv('APP_ENV') ?: 'production');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/storage/php_errors.log');
} else {
    ini_set('display_errors', '1');
}

// ---- Platform database ----
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'utiligo_platform');
define('DB_USER', getenv('DB_USER') ?: 'CHANGE_ME');
define('DB_PASS', getenv('DB_PASS') ?: 'CHANGE_ME');

// ---- User accounts database ----
define('USERDB_HOST', getenv('USERDB_HOST') ?: 'localhost');
define('USERDB_NAME', getenv('USERDB_NAME') ?: 'utiligo_users_db');
define('USERDB_USER', getenv('USERDB_USER') ?: 'CHANGE_ME');
define('USERDB_PASS', getenv('USERDB_PASS') ?: 'CHANGE_ME');

// ---- Google Places API ----
define('GOOGLE_PLACES_API_KEY', getenv('GOOGLE_PLACES_API_KEY') ?: 'YOUR_GOOGLE_PLACES_API_KEY');
define('MAX_PLACES_DETAILS_LOOKUPS', 20);

// ---- Stripe ----
define('STRIPE_SECRET_KEY',      getenv('STRIPE_SECRET_KEY')      ?: 'YOUR_STRIPE_SECRET_KEY');
define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_PUBLISHABLE_KEY') ?: 'YOUR_STRIPE_PUBLISHABLE_KEY');
define('STRIPE_WEBHOOK_SECRET',  getenv('STRIPE_WEBHOOK_SECRET')  ?: 'YOUR_STRIPE_WEBHOOK_SECRET');

// ---- Payment testing mode ----
define('TEST_PAYMENT_MODE', (bool)(getenv('TEST_PAYMENT_MODE') ?: true));

// ---- Pricing ----
define('PRO_PLAN_PRICE',          21.99);
define('ENTREPRENEUR_PLAN_PRICE', 49.99);

// =====================================================================
//  PLAN LIMITS
// =====================================================================
define('FREE_LEAD_LIMIT',           3);
define('FREE_SEARCH_DAILY_LIMIT',   2);
define('FREE_SITE_LIMIT',           1);
define('FREE_GENERATE_DAILY_LIMIT', 1);
define('FREE_TEMPLATE_LIMIT',       2);

define('PRO_LEAD_LIMIT',            120);
define('PRO_SITE_LIMIT',            200);
define('PRO_GENERATE_DAILY_LIMIT',  -1);
define('PRO_TEMPLATE_LIMIT',        -1);

define('ENT_LEAD_LIMIT',            -1);
define('ENT_SITE_LIMIT',           500);
define('ENT_GENERATE_DAILY_LIMIT',  -1);
define('ENT_TEMPLATE_LIMIT',        -1);

// ---- Search cache ----
define('LEAD_SEARCH_CACHE_HOURS', 24);

// ---- Mailer (Brevo) ----
define('BREVO_API_KEY',       getenv('BREVO_API_KEY') ?: 'YOUR_BREVO_API_KEY');
define('SMTP_FROM_EMAIL',     'noreply@utiligo.ca');
define('SMTP_FROM_NAME',      'Utiligo');
define('BREVO_LIST_ALL_USERS',  1);
define('BREVO_LIST_PRO_USERS',  2);
define('BREVO_LIST_FREE_USERS', 3);

// ---- Security ----
define('EMAIL_VERIFICATION_REQUIRED', true);
define('TWO_FA_CODE_EXPIRY_MINUTES',  10);
define('PASSWORD_RESET_EXPIRY_MINUTES', 60);
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'https://utiligo.ca');

// Secrets loaded from environment — NEVER hardcode these.
// Set UTILIGO_CRON_SECRET and UTILIGO_EDITOR_SECRET as env vars on your server.
define('CRON_SECRET',        getenv('UTILIGO_CRON_SECRET')   ?: bin2hex(random_bytes(16)));
define('SITE_EDITOR_SECRET', getenv('UTILIGO_EDITOR_SECRET') ?: bin2hex(random_bytes(16)));

// ---- Login brute-force lockout ----
define('LOGIN_MAX_ATTEMPTS',    5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// ---- Resend verification rate limit ----
define('RESEND_VERIFY_MAX',    3);
define('RESEND_VERIFY_WINDOW', 60);

// ---- File uploads ----
define('MAX_LOGO_UPLOAD_BYTES', 2 * 1024 * 1024);
define('ALLOWED_LOGO_TYPES', ['image/png', 'image/jpeg', 'image/svg+xml']);
define('ALLOWED_IMAGE_MAGIC', [
    "\xFF\xD8\xFF" => 'image/jpeg',
    "\x89PNG"      => 'image/png',
    'GIF8'         => 'image/gif',
    'RIFF'         => 'image/webp',
]);

// ---- Rate limiting ----
define('RATE_LIMIT_FIND_LEADS',     10);
define('RATE_LIMIT_GENERATE_SITE',   5);
define('RATE_LIMIT_UPLOAD_IMAGE',   30);
define('RATE_LIMIT_SAVE_SITE_PAGE', 60);
define('RATE_LIMIT_MANAGE_SITE',    30);

// ---- Feature flags ----
define('ENABLE_BOOKING',        false);
define('ENABLE_ECOMMERCE',      false);
define('ENABLE_BLOG',           false);
define('ENABLE_CUSTOM_DOMAINS', false);

// ---- Defensive fallbacks ----
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
    'ENTREPRENEUR_PLAN_PRICE'     => 49.99,
    'LOGIN_MAX_ATTEMPTS'          => 5,
    'LOGIN_LOCKOUT_MINUTES'       => 15,
    'RESEND_VERIFY_MAX'           => 3,
    'RESEND_VERIFY_WINDOW'        => 60,
];
foreach ($_cfg_defaults as $_name => $_value) {
    if (!defined($_name)) define($_name, $_value);
}
unset($_cfg_defaults, $_name, $_value);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
