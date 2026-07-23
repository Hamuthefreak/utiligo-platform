<?php
/**
 * includes/plan_limits.php
 * ============================================================
 * THE ONE FILE TO EDIT WHEN YOU WANT TO CHANGE PLAN LIMITS.
 *
 * Change a number here and it updates EVERYWHERE:
 *   - Homepage pricing section (index.php)
 *   - Homepage FAQ section (index.php)
 *   - Leads page stat bars (portal/leads.php)
 *   - Dashboard stat bars (portal/index.php)
 *   - Billing page plan cards (portal/billing.php)
 *   - Lead search enforcement (api/find-leads.php)
 *   - Bar-status API (api/bar-status.php)
 * ============================================================
 *
 * HOW TO USE:
 *   1. Edit the numbers below.
 *   2. Save and push. Auto-deploy does the rest.
 *   3. Done. No other file needs touching.
 * ============================================================
 */

// ---- FREE plan ----
define('FREE_LEAD_LIMIT',           3);   // leads shown per search (rest are locked)
define('FREE_SEARCH_DAILY_LIMIT',   2);   // searches allowed per 24h window
define('FREE_SITE_LIMIT',           1);   // site generations per day
define('FREE_GENERATE_DAILY_LIMIT', 1);   // alias for FREE_SITE_LIMIT
define('FREE_TEMPLATE_LIMIT',       2);   // number of templates available

// ---- PRO plan ----
define('PRO_LEAD_LIMIT',            120); // total leads user can unlock per period
define('PRO_SITE_LIMIT',            200); // max active websites at once
define('PRO_GENERATE_DAILY_LIMIT',  -1);  // -1 = unlimited
define('PRO_TEMPLATE_LIMIT',        -1);  // -1 = unlimited (all templates)

// ---- ENTREPRENEUR plan ----
define('ENT_LEAD_LIMIT',            -1);  // -1 = unlimited
define('ENT_SITE_LIMIT',           500);  // max active websites at once
define('ENT_GENERATE_DAILY_LIMIT',  -1);  // -1 = unlimited
define('ENT_TEMPLATE_LIMIT',        -1);  // -1 = unlimited

// ---- Pricing ----
define('PRO_PLAN_PRICE',          21.99);
define('ENTREPRENEUR_PLAN_PRICE', 49.99);
