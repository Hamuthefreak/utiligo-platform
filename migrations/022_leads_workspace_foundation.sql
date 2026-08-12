-- Migration 022: leads-workspace foundation
-- Adds the columns and tables that the new lead-generation workspace needs:
--   1. Extend utiligo_leads with extra Places / OSM fields and a raw payload
--      (so an enrichment pass can re-read upstream data without re-fetching).
--   2. lead_enrichments       — multiple enrichment passes per lead.
--   3. saved_searches         — user's reusable queries + scheduled notify.
--   4. lead_tags + lead_tag_map — user-scoped labels on leads.
--   5. lead_notes             — per-user private notes on a lead.
--   6. lead_exports           — audit + cleanup of generated exports.
--   7. lead_activity_log      — every search / unlock / export / enrich event.
--
-- All statements are idempotent: re-runnable on MySQL 5.7 (InfinityFree)
-- because the migration runner swallows 42S21 (dup column) / 42S01 (table
-- exists) / 42000 (dup index) per includes/run_migrations.php.

-- ── 1. Extend utiligo_leads ──────────────────────────────────────────────
ALTER TABLE `utiligo_leads` ADD COLUMN `website`            VARCHAR(500) NOT NULL DEFAULT '' AFTER `maps_url`;
ALTER TABLE `utiligo_leads` ADD COLUMN `source`             VARCHAR(24)  NOT NULL DEFAULT 'google_places' AFTER `website`;
ALTER TABLE `utiligo_leads` ADD COLUMN `country`            VARCHAR(80)  NOT NULL DEFAULT '' AFTER `business_city`;
ALTER TABLE `utiligo_leads` ADD COLUMN `lat`                DECIMAL(10,7) NULL AFTER `country`;
ALTER TABLE `utiligo_leads` ADD COLUMN `lng`                DECIMAL(10,7) NULL AFTER `lat`;
ALTER TABLE `utiligo_leads` ADD COLUMN `business_hours`     TEXT          NULL AFTER `lng`;
ALTER TABLE `utiligo_leads` ADD COLUMN `price_level`        TINYINT       NULL AFTER `business_hours`;
ALTER TABLE `utiligo_leads` ADD COLUMN `international_phone` VARCHAR(80)  NOT NULL DEFAULT '' AFTER `price_level`;
ALTER TABLE `utiligo_leads` ADD COLUMN `raw_payload`        JSON          NULL AFTER `international_phone`;
ALTER TABLE `utiligo_leads` ADD COLUMN `enriched_at`        DATETIME      NULL AFTER `raw_payload`;
-- Index for the enrich cron's "WHERE enriched_at IS NULL" sweep.
ALTER TABLE `utiligo_leads` ADD INDEX `idx_enrich_pending` (`enriched_at`);
-- Index for source-filtered queries used by the new advanced filters.
ALTER TABLE `utiligo_leads` ADD INDEX `idx_source_city` (`source`, `business_city`);

-- ── 2. lead_enrichments ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_enrichments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`    INT UNSIGNED NOT NULL,
  `provider`   VARCHAR(40)  NOT NULL,
  `field`      VARCHAR(40)  NOT NULL,
  `value`      VARCHAR(500) NOT NULL,
  `confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  `found_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_provider` (`provider`),
  UNIQUE KEY `uq_lead_field_provider` (`lead_id`, `field`, `provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. saved_searches ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `saved_searches` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `params`       JSON         NOT NULL,
  `last_run_at`  DATETIME     NULL,
  `last_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `notify_email` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_notify` (`user_id`, `notify_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. lead_tags + lead_tag_map ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_tags` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `name`       VARCHAR(60)  NOT NULL,
  `color`      VARCHAR(12)  NOT NULL DEFAULT '#6366f1',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_name` (`user_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `lead_tag_map` (
  `lead_id` INT UNSIGNED NOT NULL,
  `tag_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`lead_id`, `tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. lead_notes ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `lead_id`    INT UNSIGNED NOT NULL,
  `body`       TEXT         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_lead` (`user_id`, `lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 6. lead_exports ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_exports` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `format`      VARCHAR(12)  NOT NULL,
  `scope`       VARCHAR(16)  NOT NULL,
  `filter_hash`  CHAR(64)     NOT NULL DEFAULT '',
  `row_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `file_path`   VARCHAR(500) NOT NULL DEFAULT '',
  `status`      ENUM('pending','ready','failed','expired') NOT NULL DEFAULT 'pending',
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `token`       CHAR(64)     NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 7. lead_activity_log ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_activity_log` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`   INT UNSIGNED    NOT NULL,
  `action`    VARCHAR(40)     NOT NULL,
  `target_id` INT UNSIGNED    NULL,
  `meta`      JSON            NULL,
  `at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_at` (`user_id`, `at`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
