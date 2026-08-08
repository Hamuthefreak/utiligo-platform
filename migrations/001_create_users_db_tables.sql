-- Migration 001: Base schema for the USER accounts database.
-- ============================================================
-- Creates the four core tables required by includes/auth.php and the
-- signup/login flow.  These previously only existed if installed
-- manually via phpMyAdmin, which caused HTTP 500 on every register/
-- login attempt for fresh deployments (PDOException -> fatal).
--
-- All statements use CREATE TABLE IF NOT EXISTS so this is safe to
-- re-run on a DB that already has partial schema.  Subsequent ALTER
-- TABLE migrations (002, 003, 005, 008, 016) target columns that
-- this migration pre-declares, so they will harmlessly fail with
-- SQLSTATE 42S21 (duplicate column) which run_migrations.php treats
-- as ignorable.
--
-- Database: USER database (utiligo_users_db via get_user_db()).

CREATE TABLE IF NOT EXISTS `utiligo_users` (
    `id`                       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `email`                    VARCHAR(255)    NOT NULL,
    `password_hash`            VARCHAR(255)    NOT NULL,
    `full_name`                VARCHAR(120)    NOT NULL DEFAULT '',
    `plan`                     VARCHAR(20)     NOT NULL DEFAULT 'free',
    `subscription_status`      VARCHAR(20)     NOT NULL DEFAULT 'none',
    `subscription_started_at`  DATETIME        NULL DEFAULT NULL,
    `stripe_customer_id`       VARCHAR(255)    NULL DEFAULT NULL,
    `is_admin`                 TINYINT(1)      NOT NULL DEFAULT 0,
    `email_verified`           TINYINT(1)      NOT NULL DEFAULT 0,
    `two_factor_enabled`       TINYINT(1)      NOT NULL DEFAULT 0,
    `two_factor_secret`        VARCHAR(64)     NULL DEFAULT NULL,
    `notif_prefs`              JSON            NULL DEFAULT NULL,
    `created_at`               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utiligo_email_verifications` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    NOT NULL,
    `token`       VARCHAR(64)     NOT NULL,
    `used`        TINYINT(1)      NOT NULL DEFAULT 0,
    `expires_at`  DATETIME        NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utiligo_password_resets` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    NOT NULL,
    `token`       VARCHAR(64)     NOT NULL,
    `used`        TINYINT(1)      NOT NULL DEFAULT 0,
    `expires_at`  DATETIME        NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utiligo_2fa_codes` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    NOT NULL,
    `code`        VARCHAR(6)      NOT NULL,
    `used`        TINYINT(1)      NOT NULL DEFAULT 0,
    `expires_at`  DATETIME        NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_code` (`user_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
