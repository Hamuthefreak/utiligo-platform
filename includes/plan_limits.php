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

// ── Leads-workspace gating ────────────────────────────────────────────────
// Sources a plan can pull from (a comma string for easy admin editing).
// 'google_places' is always available; 'osm' (OpenStreetMap / Overpass) is
// the keyless free second source unlocked on Pro+. See includes/lead_sources/.
if (!defined('FREE_LEAD_SOURCES'))  define('FREE_LEAD_SOURCES',  'google_places');
if (!defined('PRO_LEAD_SOURCES'))   define('PRO_LEAD_SOURCES',   'google_places,osm,yelp,tomtom,wikidata');
if (!defined('ENT_LEAD_SOURCES'))   define('ENT_LEAD_SOURCES',   'google_places,osm,yelp,tomtom,wikidata');

// Export formats available by plan. CSV is the lightest; XLSX is hand-rolled
// (no external lib); vCard + JSON are cheap; PDF degrades to print-friendly
// HTML when DOMPDF is not present (InfinityFree case).
if (!defined('FREE_EXPORT_FORMATS')) define('FREE_EXPORT_FORMATS', '');               // Free exports NOTHING
if (!defined('PRO_EXPORT_FORMATS'))  define('PRO_EXPORT_FORMATS',  'csv,xlsx,vcard,json');
if (!defined('ENT_EXPORT_FORMATS'))  define('ENT_EXPORT_FORMATS',  'csv,xlsx,vcard,json,pdf');

// Export jobs per day by plan. 0 = no exports at all (free).
if (!defined('FREE_EXPORT_DAILY'))   define('FREE_EXPORT_DAILY',  0);
if (!defined('PRO_EXPORT_DAILY'))    define('PRO_EXPORT_DAILY',   5);
if (!defined('ENT_EXPORT_DAILY'))    define('ENT_EXPORT_DAILY',  50);

// Enrichment providers available by plan (comma string).
// website_finder  — try common domain patterns, HEAD-confirm.
// email_pattern   — fetch homepage, regex mailto:/addr.
// email_verifier  — brute MX+RCPT (Ent only).
// social_profiles — scrape homepage footer for FB/IG/LI/Yelp (Ent only).
if (!defined('FREE_ENRICH_PROVIDERS')) define('FREE_ENRICH_PROVIDERS', '');
if (!defined('PRO_ENRICH_PROVIDERS'))  define('PRO_ENRICH_PROVIDERS',  'website_finder,email_pattern');
if (!defined('ENT_ENRICH_PROVIDERS'))  define('ENT_ENRICH_PROVIDERS',  'website_finder,email_pattern,email_verifier,social_profiles');

// Max rows an export can hold (memory backstop; chunked-iter on read).
if (!defined('PRO_EXPORT_MAX_ROWS'))   define('PRO_EXPORT_MAX_ROWS',  5000);
if (!defined('ENT_EXPORT_MAX_ROWS'))   define('ENT_EXPORT_MAX_ROWS', 50000);

// Rate limits for the new endpoints (per-minute, per user).
if (!defined('RATE_LIMIT_EXPORT_LEADS')) define('RATE_LIMIT_EXPORT_LEADS',  6);
if (!defined('RATE_LIMIT_ENRICH_LEAD'))  define('RATE_LIMIT_ENRICH_LEAD',  20);
if (!defined('RATE_LIMIT_SAVED_SEARCH')) define('RATE_LIMIT_SAVED_SEARCH', 20);
if (!defined('RATE_LIMIT_LEAD_TAG'))     define('RATE_LIMIT_LEAD_TAG',    40);
if (!defined('RATE_LIMIT_LEAD_NOTE'))    define('RATE_LIMIT_LEAD_NOTE',   30);
if (!defined('RATE_LIMIT_LEAD_BULK'))    define('RATE_LIMIT_LEAD_BULK',   10);

