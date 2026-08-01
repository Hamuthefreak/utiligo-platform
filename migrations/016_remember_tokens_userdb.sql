-- Migration 016: Create utiligo_remember_tokens in the USER database.
-- Stores hashed persistent login tokens for the "Remember me" feature.
-- This file is intentionally a no-op on the platform DB (CREATE IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS `utiligo_remember_tokens` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `selector`   VARCHAR(128) NOT NULL,
    `token_hash` VARCHAR(128) NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_selector` (`selector`),
    KEY `idx_user`   (`user_id`),
    KEY `idx_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
