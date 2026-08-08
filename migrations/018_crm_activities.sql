-- Migration 018: CRM upgrades + bug fixes (MySQL 5.7-safe).
-- Idempotent: uses CREATE IF NOT EXISTS, and plain ALTER ... ADD COLUMN /
-- ADD INDEX (MySQL 5.7 does not support "ADD ... IF NOT EXISTS"). On a DB
-- that's already been patched these re-throw SQLSTATE 42S21 / "Duplicate
-- key name", which run_migrations.php tolerates. On the user DB (where
-- crm_* and utiligo_leads may not exist) the ALTERs throw 42S02 / 42S22,
-- also tolerated by the runner. So this single file is safe on both DBs.

-- ── 1. crm_activities: timeline of every CRM event for a client ────────────
CREATE TABLE IF NOT EXISTS `crm_activities` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT NOT NULL,
    `client_id`      INT NOT NULL,
    `activity_type`  VARCHAR(40) NOT NULL,
    `title`          VARCHAR(255) NOT NULL,
    `meta`           TEXT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_client`  (`client_id`),
    KEY `idx_user`    (`user_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. crm_clients: lead_id + composite index on (user_id, stage) ──────────
ALTER TABLE `crm_clients` ADD COLUMN `lead_id` INT NULL AFTER `source`;
ALTER TABLE `crm_clients` ADD INDEX `idx_user_stage` (`user_id`, `stage`);
ALTER TABLE `crm_clients` ADD INDEX `idx_lead` (`lead_id`);

-- ── 3. crm_tasks: indexes + remind_email toggle + done_at + description ───
ALTER TABLE `crm_tasks` ADD COLUMN `remind_email` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `crm_tasks` ADD COLUMN `done_at`      DATETIME NULL;
ALTER TABLE `crm_tasks` ADD COLUMN `description`  TEXT NULL;
ALTER TABLE `crm_tasks` ADD INDEX `idx_user_due`     (`user_id`, `due_date`);
ALTER TABLE `crm_tasks` ADD INDEX `idx_remind_email`  (`remind_email`, `due_date`);

-- ── 4. crm_notes: index ─────────────────────────────────────────────────────
ALTER TABLE `crm_notes` ADD INDEX `idx_user_client` (`user_id`, `client_id`);

-- ── 5. utiligo_leads: add status column (fixes generate-site.php:190 fatal)
-- generate-site.php tries to UPDATE utiligo_leads SET status='contacted'
-- but the column never existed, throwing SQLSTATE[42S22] and aborting the
-- site-generation API response. We add the column so the UPDATE is valid.
ALTER TABLE `utiligo_leads` ADD COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'new';
ALTER TABLE `utiligo_leads` ADD INDEX `idx_status` (`status`);
