-- lead_search_quota table
-- Tracks how many lead searches a free-plan user has made in the last 24 hours.
-- Fingerprint = 'u{user_id}_{first16charsOfIpHash}'
-- Tying to user_id ensures changing IP alone does not reset the counter.
-- Auto-created by api/find-leads.php on first use, but you can also run this manually.

CREATE TABLE IF NOT EXISTS `lead_search_quota` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fingerprint`  VARCHAR(80)  NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `count`        INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fingerprint` (`fingerprint`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
