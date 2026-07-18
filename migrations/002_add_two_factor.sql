-- Migration 002: Add two_factor_enabled column to utiligo_users
-- Run this once in your database (phpMyAdmin or MySQL CLI).
-- Safe to run multiple times — uses IF NOT EXISTS logic via a stored procedure.

DROP PROCEDURE IF EXISTS add_two_factor_column;
DELIMITER //
CREATE PROCEDURE add_two_factor_column()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'utiligo_users'
          AND COLUMN_NAME  = 'two_factor_enabled'
    ) THEN
        ALTER TABLE utiligo_users
            ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0
            AFTER email_verified;
    END IF;
END //
DELIMITER ;
CALL add_two_factor_column();
DROP PROCEDURE IF EXISTS add_two_factor_column;
