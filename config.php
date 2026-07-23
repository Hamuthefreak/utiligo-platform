<?php
/**
 * config.php — Central configuration for the Utiligo platform.
 * =====================================================================
 * PLAN LIMITS: Edit includes/plan_limits.php — that is the ONLY file
 * you need to change. It is loaded below and defines every limit.
 *
 * Secrets (CRON_SECRET, SITE_EDITOR_SECRET) are loaded from environment
 * variables so they are never hardcoded in source control.
 *   export UTILIGO_CRON_SECRET="your-random-string"
 *   export UTILIGO_EDITOR_SECRET="your-random-string"
 * =====================================================================
 */

error_reporting(E_ALL);

// Change to 'development' locally only.
if (!defined('APP_ENV')) define('APP_ENV', getenv('APP_ENV') ?: 'production');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/storage/php_errors.log');
} else {
    ini_set('display_errors', '1');
}

// ---- Platform database ----
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'utiligo_platform');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'CHANGE_ME');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: 'CHANGE_ME');

// ---- User accounts database ----
if (!defined('USERDB_HOST')) define('USERDB_HOST', getenv('USERDB_HOST') ?: 'localhost');
if (!defined('USERDB_NAME')) define('USERDB_NAME', getenv('USERDB_NAME') ?: 'utiligo_users_db');
if (!defined('USERDB_USER')) define('USERDB_USER', getenv('USERDB_USER') ?: 'CHANGE_ME');
if (!defined('USERDB_PASS')) define('USERDB_PASS', getenv('USERDB_PASS') ?: 'CHANGE_ME');

// ---- Google Places API ----
if (!defined('GOOGLE_PLACES_API_KEY'))       define('GOOGLE_PLACES_API_KEY',       getenv('GOOGLE_PLACES_API_KEY') ?: 'YOUR_GOOGLE_PLACES_API_KEY');
if (!defined('MAX_PLACES_DETAILS_LOOKUPS'))  define('MAX_PLACES_DETAILS_LOOKUPS',  20);

// ---- Stripe ----
if (!defined('STRIPE_SECRET_KEY'))      define('STRIPE_SECRET_KEY',      getenv('STRIPE_SECRET_KEY')      ?: 'YOUR_STRIPE_SECRET_KEY');
if (!defined('STRIPE_PUBLISHABLE_KEY')) define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_PUBLISHABLE_KEY') ?: 'YOUR_STRIPE_PUBLISHABLE_KEY');
if (!defined('STRIPE_WEBHOOK_SECRET'))  define('STRIPE_WEBHOOK_SECRET',  getenv('STRIPE_WEBHOOK_SECRET')  ?: 'YOUR_STRIPE_WEBHOOK_SECRET');

// ---- Payment testing mode ----
if (!defined('TEST_PAYMENT_MODE')) define('TEST_PAYMENT_MODE', (bool)(getenv('TEST_PAYMENT_MODE') ?: true));

// =====================================================================
//  PLAN LIMITS — defined in includes/plan_limits.php
//  Edit THAT file. Do not add limit defines here.
// =====================================================================
require_once __DIR__ . '/includes/plan_limits.php';

// ---- Search cache ----
if (!defined('LEAD_SEARCH_CACHE_HOURS')) define('LEAD_SEARCH_CACHE_HOURS', 24);

// ---- Mailer (Brevo) ----
if (!defined('BREVO_API_KEY'))        define('BREVO_API_KEY',        getenv('BREVO_API_KEY') ?: 'YOUR_BREVO_API_KEY');
if (!defined('SMTP_FROM_EMAIL'))      define('SMTP_FROM_EMAIL',      'noreply@utiligo.ca');
if (!defined('SMTP_FROM_NAME'))       define('SMTP_FROM_NAME',       'Utiligo');
if (!defined('BREVO_LIST_ALL_USERS')) define('BREVO_LIST_ALL_USERS', 1);
if (!defined('BREVO_LIST_PRO_USERS')) define('BREVO_LIST_PRO_USERS', 2);
if (!defined('BREVO_LIST_FREE_USERS'))define('BREVO_LIST_FREE_USERS',3);

// ---- Security ----
if (!defined('EMAIL_VERIFICATION_REQUIRED'))  define('EMAIL_VERIFICATION_REQUIRED',  true);
if (!defined('TWO_FA_CODE_EXPIRY_MINUTES'))    define('TWO_FA_CODE_EXPIRY_MINUTES',    10);
if (!defined('PASSWORD_RESET_EXPIRY_MINUTES')) define('PASSWORD_RESET_EXPIRY_MINUTES', 60);
if (!defined('APP_BASE_URL'))                  define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'https://utiligo.ca');

// Secrets loaded from environment — NEVER hardcode these.
if (!defined('CRON_SECRET'))        define('CRON_SECRET',        getenv('UTILIGO_CRON_SECRET')   ?: bin2hex(random_bytes(16)));
if (!defined('SITE_EDITOR_SECRET')) define('SITE_EDITOR_SECRET', getenv('UTILIGO_EDITOR_SECRET') ?: bin2hex(random_bytes(16)));

// ---- Login brute-force lockout ----
if (!defined('LOGIN_MAX_ATTEMPTS'))    define('LOGIN_MAX_ATTEMPTS',    5);
if (!defined('LOGIN_LOCKOUT_MINUTES')) define('LOGIN_LOCKOUT_MINUTES', 15);

// ---- Resend verification rate limit ----
if (!defined('RESEND_VERIFY_MAX'))    define('RESEND_VERIFY_MAX',    3);
if (!defined('RESEND_VERIFY_WINDOW')) define('RESEND_VERIFY_WINDOW', 60);

// ---- File uploads ----
if (!defined('MAX_LOGO_UPLOAD_BYTES')) define('MAX_LOGO_UPLOAD_BYTES', 2 * 1024 * 1024);
if (!defined('ALLOWED_LOGO_TYPES'))    define('ALLOWED_LOGO_TYPES', ['image/png', 'image/jpeg', 'image/svg+xml']);
if (!defined('ALLOWED_IMAGE_MAGIC'))   define('ALLOWED_IMAGE_MAGIC', [
    "\xFF\xD8\xFF" => 'image/jpeg',
    "\x89PNG"      => 'image/png',
    'GIF8'         => 'image/gif',
    'RIFF'         => 'image/webp',
]);

// ---- Rate limiting ----
if (!defined('RATE_LIMIT_FIND_LEADS'))      define('RATE_LIMIT_FIND_LEADS',     10);
if (!defined('RATE_LIMIT_GENERATE_SITE'))   define('RATE_LIMIT_GENERATE_SITE',   5);
if (!defined('RATE_LIMIT_UPLOAD_IMAGE'))    define('RATE_LIMIT_UPLOAD_IMAGE',   30);
if (!defined('RATE_LIMIT_SAVE_SITE_PAGE'))  define('RATE_LIMIT_SAVE_SITE_PAGE', 60);
if (!defined('RATE_LIMIT_MANAGE_SITE'))     define('RATE_LIMIT_MANAGE_SITE',    30);

// ---- Feature flags ----
if (!defined('ENABLE_BOOKING'))        define('ENABLE_BOOKING',        false);
if (!defined('ENABLE_ECOMMERCE'))      define('ENABLE_ECOMMERCE',      false);
if (!defined('ENABLE_BLOG'))           define('ENABLE_BLOG',           false);
if (!defined('ENABLE_CUSTOM_DOMAINS')) define('ENABLE_CUSTOM_DOMAINS', false);

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

// =====================================================================
//  AUTO MIGRATION RUNNER
// =====================================================================
require_once __DIR__ . '/includes/run_migrations.php';
require_once __DIR__ . '/includes/bootstrap_migrations.php';
