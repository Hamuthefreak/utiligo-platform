<?php
/**
 * Migration 016 — Create utiligo_remember_tokens table.
 *
 * Stores hashed persistent login tokens for the "Remember me" feature.
 * selector   — public half sent in the cookie (used to look up the row)
 * token_hash — SHA-256 of the private validator half (never stored raw)
 * expires_at — rolling 30-day expiry, rotated on each use
 */
return function (PDO $pdo): void {
    // This migration runs against the USER database, not the platform DB.
    // The migration runner must pass the correct PDO instance.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utiligo_remember_tokens (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            selector   VARCHAR(128) NOT NULL,
            token_hash VARCHAR(128) NOT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME     NOT NULL,
            UNIQUE KEY uq_selector (selector),
            KEY idx_user   (user_id),
            KEY idx_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
