-- Migration 006: ensure utiligo_leads has every column the app expects.
-- Each statement is wrapped so duplicates (column already exists) are
-- silently ignored on MySQL 5.7 which lacks ADD COLUMN IF NOT EXISTS.
-- The migration runner executes statements one-by-one and skips
-- SQLSTATE 42S21 (duplicate column) errors automatically.

ALTER TABLE `utiligo_leads` ADD COLUMN `business_address`  VARCHAR(500) NOT NULL DEFAULT '' AFTER `business_name`;
ALTER TABLE `utiligo_leads` ADD COLUMN `business_phone`    VARCHAR(80)  NOT NULL DEFAULT '' AFTER `business_address`;
ALTER TABLE `utiligo_leads` ADD COLUMN `business_email`    VARCHAR(255) NOT NULL DEFAULT '' AFTER `business_phone`;
ALTER TABLE `utiligo_leads` ADD COLUMN `business_category` VARCHAR(150) NOT NULL DEFAULT '' AFTER `business_email`;
ALTER TABLE `utiligo_leads` ADD COLUMN `business_city`     VARCHAR(100) NOT NULL DEFAULT '' AFTER `business_category`;
ALTER TABLE `utiligo_leads` ADD COLUMN `rating`            DECIMAL(3,1) NULL AFTER `business_city`;
ALTER TABLE `utiligo_leads` ADD COLUMN `total_ratings`     INT UNSIGNED NOT NULL DEFAULT 0 AFTER `rating`;
ALTER TABLE `utiligo_leads` ADD COLUMN `maps_url`          VARCHAR(500) NOT NULL DEFAULT '' AFTER `total_ratings`;
ALTER TABLE `utiligo_leads` ADD COLUMN `opportunity_score` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `maps_url`;
ALTER TABLE `utiligo_leads` ADD COLUMN `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `opportunity_score`;

-- Ensure unlocked_leads table exists
CREATE TABLE IF NOT EXISTS `unlocked_leads` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `lead_id`     INT UNSIGNED NOT NULL,
  `unlocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_lead` (`user_id`, `lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure lead_cache table exists
CREATE TABLE IF NOT EXISTS `lead_cache` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key`  VARCHAR(255) NOT NULL,
  `leads_json` MEDIUMTEXT   NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cache_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure lead search history table exists
CREATE TABLE IF NOT EXISTS `utiligo_lead_search_history` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `city`         VARCHAR(100) NOT NULL,
  `industry`     VARCHAR(100) NOT NULL,
  `keywords`     VARCHAR(255) NOT NULL DEFAULT '',
  `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_search` (`user_id`,`city`,`industry`,`keywords`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
