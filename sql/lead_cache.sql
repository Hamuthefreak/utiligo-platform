-- lead_cache table — run once on utiligo_platform database.
-- Caches Google Places results per city+industry for LEAD_SEARCH_CACHE_HOURS
-- to avoid burning Places API quota on repeat identical searches.

CREATE TABLE IF NOT EXISTS `lead_cache` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `cache_key`  VARCHAR(255) NOT NULL,
  `leads_json` MEDIUMTEXT   NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cache_key` (`cache_key`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
