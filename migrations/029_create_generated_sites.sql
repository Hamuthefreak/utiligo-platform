-- Migration 029: the base utiligo_generated_sites table
-- ============================================================
-- THE TABLE THAT WAS NEVER CREATED
-- --------------------------------
-- Every other table in this project is created by exactly one of migrations 001
-- (the account DB) or 006/018/019/022+ (the workspace tables). This one is not:
-- migrations 004 and 005 ALTER it and 004 adds an index to it, but nothing ever
-- CREATEs it. It exists in production because it was made by hand in phpMyAdmin
-- before this repository had a migration runner — and the consequence is that a
-- fresh environment (a new deployment, a new contributor, the CI database) has
-- no such table at all.
--
-- What that looks like from the outside, before this migration: /portal/my_sites.php
-- is an uncaught PDOException (HTTP 500 — "Table 'utiligo_test_platform.utiligo_generated_sites'
-- doesn't exist"), and the site generator, which is the product's whole premise,
-- cannot record a site it just built. The counts that survive do so because
-- admin/index.php wraps them in safe_count() and quietly reports zero, so the
-- admin dashboard says "0 sites" rather than failing — the failure is silent
-- exactly where it is most expensive.
--
-- WHY IF NOT EXISTS, AND WHY THAT MAKES THIS SAFE ON PRODUCTION
-- ------------------------------------------------------------
-- On the production database this statement does nothing at all: the table is
-- there, IF NOT EXISTS short-circuits, and the migration is recorded as applied.
-- On a fresh database it creates the table with the columns the code actually
-- reads and writes — collected by grepping for `$site['…']` access, the INSERT
-- column list in api/generate-site.php ($INSERT_ONLY_COLS plus the DEFERRED_COLS
-- it updates afterwards) and the UPDATE statements in api/manage-site.php. Extra
-- columns the production table may carry are not invented here.
--
-- The code is defensive about optional columns (`db_table_has_column()` guards
-- every insert and update), so a column that a future feature adds elsewhere
-- degrades to "not set" rather than to a crash.
--
-- NOT UNIQUE ON public_slug, deliberately: migration 005 dropped
-- uq_utiligo_generated_sites_slug because slugs are already unique in practice
-- (name + site id + a random suffix) and the constraint turned an edge case into
-- a crash. The plain index below is for s.php's lookup, which is a read.
--
-- Runs against both databases, like every other migration (see
-- includes/bootstrap_migrations.php).

CREATE TABLE IF NOT EXISTS `utiligo_generated_sites` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`              INT UNSIGNED NOT NULL,
    `lead_id`              INT UNSIGNED NULL DEFAULT NULL,
    `client_id`            INT UNSIGNED NULL DEFAULT NULL,
    `business_name`        VARCHAR(191)  NOT NULL,
    `business_category`    VARCHAR(120)  NULL DEFAULT NULL,
    `business_city`        VARCHAR(100)  NULL DEFAULT NULL,
    `business_phone`       VARCHAR(30)   NULL DEFAULT NULL,
    `business_email`       VARCHAR(191)  NULL DEFAULT NULL,
    `template_name`        VARCHAR(120)  NULL DEFAULT NULL,
    `status`               VARCHAR(20)   NOT NULL DEFAULT 'pending',
    `builder_content`      LONGTEXT      NULL DEFAULT NULL,
    `public_slug`          VARCHAR(191)  NULL DEFAULT NULL,
    `share_token`          VARCHAR(64)   NULL DEFAULT NULL,
    `share_links_enabled`  TINYINT(1)    NOT NULL DEFAULT 1,
    `link_active`          TINYINT(1)    NOT NULL DEFAULT 0,
    `link_expires_at`      DATETIME      NULL DEFAULT NULL,
    `zip_file_path`        VARCHAR(255)  NULL DEFAULT NULL,
    `view_count`           INT UNSIGNED  NOT NULL DEFAULT 0,
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sites_user_created` (`user_id`, `created_at`),
    KEY `idx_sites_slug` (`public_slug`),
    KEY `idx_sites_link` (`link_active`, `link_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
