<?php
/**
 * config.php — Central configuration for the Utiligo platform.
 * Replace all CHANGE_ME / YOUR_* placeholders with real credentials before
 * going to production. Never commit this file with real secrets to a public repo.
 */

error_reporting(E_ALL);
define('APP_ENV', 'development'); // set to 'production' to suppress error display
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
}

// ---- Platform database (leads, sites, whitelabel, revenue, audit log) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'utiligo_platform');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');

// ---- User accounts database ----
define('USERDB_HOST', 'localhost');
define('USERDB_NAME', 'utiligo_users_db');
define('USERDB_USER', 'CHANGE_ME');
define('USERDB_PASS', 'CHANGE_ME');

// ---- Google Places API (lead finding) ----
define('GOOGLE_PLACES_API_KEY', 'YOUR_GOOGLE_PLACES_API_KEY');
define('MAX_PLACES_DETAILS_LOOKUPS', 20); // cap per-result phone/website lookups per search

// ---- Stripe (production billing — not required while TEST_PAYMENT_MODE is true) ----
define('STRIPE_SECRET_KEY', 'YOUR_STRIPE_SECRET_KEY');
define('STRIPE_PUBLISHABLE_KEY', 'YOUR_STRIPE_PUBLISHABLE_KEY');
define('STRIPE_WEBHOOK_SECRET', 'YOUR_STRIPE_WEBHOOK_SECRET');

// ---- Payment testing mode ----
// When true, billing.php simulates a checkout with a fake card form
// instead of redirecting to real Stripe Checkout. No bank account or
// live Stripe keys are required. Flip to false once Stripe is wired up.
define('TEST_PAYMENT_MODE', true);

// ---- Pricing ----
define('PRO_PLAN_PRICE', 21.99);
define('FREE_LEAD_LIMIT', 3);
define('LEAD_SEARCH_CACHE_HOURS', 24); // reuse saved leads for repeat city+industry searches within this window

// ---- Mailer (Brevo REST API) ----
// Get this from Brevo dashboard -> SMTP & API -> API Keys.
// Using the REST API (not SMTP) avoids needing Composer/PHPMailer on InfinityFree.
define('BREVO_API_KEY', 'YOUR_BREVO_API_KEY');
define('SMTP_FROM_EMAIL', 'noreply@utiligo.ca');
define('SMTP_FROM_NAME', 'Utiligo');

// Brevo contact list IDs for marketing segmentation (create these in Brevo dashboard -> Contacts -> Lists)
define('BREVO_LIST_ALL_USERS', 1);
define('BREVO_LIST_PRO_USERS', 2);
define('BREVO_LIST_FREE_USERS', 3);

// ---- Security features ----
define('EMAIL_VERIFICATION_REQUIRED', true);
define('TWO_FA_CODE_EXPIRY_MINUTES', 10);
define('PASSWORD_RESET_EXPIRY_MINUTES', 60);
define('APP_BASE_URL', 'https://utiligo.ca');

// Protects api/cron-reminders.php from being triggered by randoms.
// Generate your own random string and use it in your external cron service URL.
define('CRON_SECRET', 'CHANGE_ME_TO_RANDOM_STRING');

// ---- File upload limits ----
define('MAX_LOGO_UPLOAD_BYTES', 2 * 1024 * 1024); // 2MB
define('ALLOWED_LOGO_TYPES', ['image/png', 'image/jpeg', 'image/svg+xml']);

// ---- Rate limiting (requests per minute per session) ----
define('RATE_LIMIT_FIND_LEADS', 10);
define('RATE_LIMIT_GENERATE_SITE', 5);
define('RATE_LIMIT_UPLOAD_IMAGE', 30);
define('RATE_LIMIT_SAVE_SITE_PAGE', 60);
define('RATE_LIMIT_MANAGE_SITE', 30);
define('SITE_EDITOR_SECRET', 'change-me-to-a-random-string-in-production');

// ---- Feature flags (future-proofing — flip to true as features ship) ----
define('ENABLE_BOOKING', false);
define('ENABLE_ECOMMERCE', false);
define('ENABLE_BLOG', false);
define('ENABLE_CUSTOM_DOMAINS', false);

// Defensive: define any constants added in later platform updates that may
// be missing from an older deployed config.php, so updates never hard-crash.
$defaults = [
    'MAX_PLACES_DETAILS_LOOKUPS' => 20,
    'LEAD_SEARCH_CACHE_HOURS' => 24,
    'RATE_LIMIT_UPLOAD_IMAGE' => 30,
    'RATE_LIMIT_SAVE_SITE_PAGE' => 60,
    'RATE_LIMIT_MANAGE_SITE' => 30,
    'SITE_EDITOR_SECRET' => 'change-me-to-a-random-string-in-production',
];
foreach ($defaults as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
