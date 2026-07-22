-- Migration 007: create lead_search_quota table used by free-tier
-- search rate-limiting in find-leads.php and portal/leads.php.
-- Uses CREATE TABLE IF NOT EXISTS so it is safe to run on live DBs
-- that already had the table auto-created by find-leads.php.

CREATE TABLE IF NOT EXISTS `lead_search_quota` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fingerprint`  VARCHAR(80)  NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `count`        INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fp` (`fingerprint`),
  KEY `idx_uid` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
