-- Migration 019: Monthly Google Places API call counter.
-- Used to enforce a platform-wide monthly cap so we never exceed Google's
-- free tier (default 5000 calls/month, configurable via
-- PLACES_API_MONTHLY_LIMIT in config.php / storage/config_overrides.php).
-- Lives in the platform DB. CREATE IF NOT EXISTS so it's a no-op on user DB.

CREATE TABLE IF NOT EXISTS `places_api_usage` (
    `year_month`  CHAR(7)     NOT NULL,             -- 'YYYY-MM' UTC
    `calls_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
