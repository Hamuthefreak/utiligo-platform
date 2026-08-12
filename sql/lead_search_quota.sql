-- lead_search_quota table
-- Tracks how many lead searches a free-plan user has made in the last 24 hours.
-- Fingerprint format written by api/find-leads.php is 'uid_{user_id}'
-- (NOT 'u{user_id}_{ip_hash}' — that older shape was retired when the
-- counter was tied to the authenticated user so a changed IP alone can no
-- longer reset it).  Keeping the user_id in the fingerprint means an admin
-- can find & reset a specific user's bucket by id.
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
