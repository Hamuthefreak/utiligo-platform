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
 *
 * NOTE: All define() calls use if(!defined()) guards so this file
 * is safe to load multiple times (OPcache, require_once loops, etc.)
 * ============================================================
 */

// ---- FREE plan ----
if (!defined('FREE_LEAD_LIMIT'))            define('FREE_LEAD_LIMIT',            3);   // leads shown per search (rest locked)
if (!defined('FREE_SEARCH_DAILY_LIMIT'))    define('FREE_SEARCH_DAILY_LIMIT',    2);   // searches allowed per 24h window
if (!defined('FREE_SITE_LIMIT'))            define('FREE_SITE_LIMIT',            1);   // site generations per day
if (!defined('FREE_GENERATE_DAILY_LIMIT')) define('FREE_GENERATE_DAILY_LIMIT',  1);   // alias for FREE_SITE_LIMIT
if (!defined('FREE_TEMPLATE_LIMIT'))        define('FREE_TEMPLATE_LIMIT',        2);   // number of templates available

// ---- PRO plan ----
if (!defined('PRO_LEAD_LIMIT'))             define('PRO_LEAD_LIMIT',           700);   // total leads user can unlock per period
if (!defined('PRO_SITE_LIMIT'))             define('PRO_SITE_LIMIT',            50);   // max active websites at once
if (!defined('PRO_GENERATE_DAILY_LIMIT'))   define('PRO_GENERATE_DAILY_LIMIT',  -1);   // -1 = unlimited
if (!defined('PRO_TEMPLATE_LIMIT'))         define('PRO_TEMPLATE_LIMIT',        -1);   // -1 = unlimited (all templates)

// ---- ENTREPRENEUR plan ----
if (!defined('ENT_LEAD_LIMIT'))             define('ENT_LEAD_LIMIT',            -1);   // -1 = unlimited
if (!defined('ENT_SITE_LIMIT'))             define('ENT_SITE_LIMIT',           500);   // max active websites at once
if (!defined('ENT_GENERATE_DAILY_LIMIT'))   define('ENT_GENERATE_DAILY_LIMIT',  -1);   // -1 = unlimited
if (!defined('ENT_TEMPLATE_LIMIT'))         define('ENT_TEMPLATE_LIMIT',        -1);   // -1 = unlimited

// ---- Pricing ----
if (!defined('PRO_PLAN_PRICE'))             define('PRO_PLAN_PRICE',          21.99);
if (!defined('ENTREPRENEUR_PLAN_PRICE'))    define('ENTREPRENEUR_PLAN_PRICE', 49.99);
