<?php
/**
 * config.php — Central configuration for the Utiligo platform.
 * =====================================================================
 * PLAN LIMITS: Edit includes/plan_limits.php — that is the ONLY file
 * you need to change.
 *
 * Google Places API optimisation constants:
 *   PLACES_FIELDS_BASIC   — only charged at SKU "Basic Data" ($0.002/req)
 *   PLACES_FIELDS_CONTACT — charged at "Contact Data" ($0.003/req)
 *   PLACES_FIELDS_ATMOSPHERE — charged at "Atmosphere Data" ($0.005/req)
 *   Set GOOGLE_FIELDS_TIER to 'basic', 'contact', or 'full' in env.
 *   Use 'basic' by default; only bump to 'contact' when phone is needed.
 * =====================================================================
 */

error_reporting(E_ALL);

if (!defined('APP_ENV')) define('APP_ENV', getenv('APP_ENV') ?: 'production');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    // Try a few paths — InfinityFree may restrict open_basedir
    $logCandidates = [
        __DIR__ . '/storage/php_errors.log',
        sys_get_temp_dir() . '/utiligo_errors.log',
        '/tmp/utiligo_errors.log',
    ];
    foreach ($logCandidates as $_lc) {
        if (@file_put_contents($_lc, '', FILE_APPEND) !== false) {
            ini_set('error_log', $_lc);
            break;
        }
    }
    unset($logCandidates, $_lc);
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

// ============================================================
//  GOOGLE PLACES API — Cost Optimisation
// ============================================================
if (!defined('GOOGLE_PLACES_API_KEY'))
    define('GOOGLE_PLACES_API_KEY', getenv('GOOGLE_PLACES_API_KEY') ?: 'YOUR_GOOGLE_PLACES_API_KEY');

if (!defined('MAX_PLACES_DETAILS_LOOKUPS'))
    define('MAX_PLACES_DETAILS_LOOKUPS', 10);

if (!defined('GOOGLE_FIELDS_TIER'))
    define('GOOGLE_FIELDS_TIER', getenv('GOOGLE_FIELDS_TIER') ?: 'contact');

if (!defined('GOOGLE_FIELDS_BASIC'))
    define('GOOGLE_FIELDS_BASIC',
        'name,formatted_address,geometry,place_id,types,rating,user_ratings_total,business_status,photos');

if (!defined('GOOGLE_FIELDS_CONTACT'))
    define('GOOGLE_FIELDS_CONTACT',
        GOOGLE_FIELDS_BASIC . ',formatted_phone_number,international_phone_number,website,opening_hours');

if (!defined('GOOGLE_FIELDS_FULL'))
    define('GOOGLE_FIELDS_FULL',
        GOOGLE_FIELDS_CONTACT . ',reviews,price_level,plus_code,url,vicinity');

function google_fields_mask(): string {
    return match(GOOGLE_FIELDS_TIER) {
        'full'    => GOOGLE_FIELDS_FULL,
        'contact' => GOOGLE_FIELDS_CONTACT,
        default   => GOOGLE_FIELDS_BASIC,
    };
}

if (!defined('GOOGLE_TEXT_SEARCH_FREE_FIELDS'))
    define('GOOGLE_TEXT_SEARCH_FREE_FIELDS',
        'name,formatted_address,geometry,place_id,types,rating,user_ratings_total,business_status,photos');

if (!defined('LEAD_SEARCH_CACHE_HOURS')) define('LEAD_SEARCH_CACHE_HOURS', 48);

// ---- Stripe ----
if (!defined('STRIPE_SECRET_KEY'))      define('STRIPE_SECRET_KEY',      getenv('STRIPE_SECRET_KEY')      ?: 'YOUR_STRIPE_SECRET_KEY');
if (!defined('STRIPE_PUBLISHABLE_KEY')) define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_PUBLISHABLE_KEY') ?: 'YOUR_STRIPE_PUBLISHABLE_KEY');
if (!defined('STRIPE_WEBHOOK_SECRET'))  define('STRIPE_WEBHOOK_SECRET',  getenv('STRIPE_WEBHOOK_SECRET')  ?: 'YOUR_STRIPE_WEBHOOK_SECRET');

if (!defined('STRIPE_PRO_PRICE_ID')) define('STRIPE_PRO_PRICE_ID', getenv('STRIPE_PRO_PRICE_ID') ?: 'YOUR_STRIPE_PRO_PRICE_ID');
if (!defined('STRIPE_ENT_PRICE_ID')) define('STRIPE_ENT_PRICE_ID', getenv('STRIPE_ENT_PRICE_ID') ?: 'YOUR_STRIPE_ENT_PRICE_ID');

if (!defined('TEST_PAYMENT_MODE')) define('TEST_PAYMENT_MODE', (bool)(getenv('TEST_PAYMENT_MODE') ?: true));

require_once __DIR__ . '/includes/plan_limits.php';

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

if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', getenv('UTILIGO_ADMIN_EMAIL') ?: '');

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

// ============================================================
//  SESSION BOOTSTRAP
//  Use the classic 6-argument form of session_set_cookie_params()
//  which works on PHP 5.2+ (the array form requires PHP 7.3+ and
//  the 'samesite' key within it requires PHP 7.3.0 exactly —
//  on older InfinityFree builds it throws a warning that, combined
//  with error_reporting(E_ALL), is promoted to a fatal and leaves
//  session_status() === PHP_SESSION_DISABLED for the whole request).
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    try {
        $__secure  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                     || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
        // 6-arg form: (lifetime, path, domain, secure, httponly)
        // SameSite is set via header() below because the 6-arg form
        // has no samesite parameter on PHP < 7.3.
        session_set_cookie_params(0, '/; SameSite=Lax', '', $__secure, true);
        unset($__secure);
        session_start();
    } catch (\Throwable $__se) {
        // Session start failed — log and continue without sessions.
        // Pages that require login will redirect to /login.php via
        // require_login() -> is_logged_in() -> $_SESSION check.
        error_log('[config] session_start failed: ' . $__se->getMessage());
        unset($__se);
    }
}

require_once __DIR__ . '/includes/run_migrations.php';
require_once __DIR__ . '/includes/bootstrap_migrations.php';

// ---- Remember-me bootstrap ----
if (!function_exists('check_remember_me_cookie')) {
    require_once __DIR__ . '/includes/auth.php';
}
if (function_exists('check_remember_me_cookie') && session_status() === PHP_SESSION_ACTIVE) {
    check_remember_me_cookie();
}
