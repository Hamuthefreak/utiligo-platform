-- lead_cache table
-- Run this once on your utiligo_platform database.
-- Stores Google Places search results keyed by city+industry
-- so repeat searches within LEAD_SEARCH_CACHE_HOURS don't burn API quota.

CREATE TABLE IF NOT EXISTS `lead_cache` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `cache_key`  VARCHAR(255) NOT NULL,
  `leads_json` MEDIUMTEXT   NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cache_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
