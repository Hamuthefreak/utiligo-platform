-- Migration 005: 2FA columns + notification prefs
-- Run on: utiligo_users_db (the user accounts database)
-- Safe to run multiple times (uses IF NOT EXISTS / column checks)

ALTER TABLE utiligo_users
  ADD COLUMN IF NOT EXISTS two_factor_secret  VARCHAR(64)  DEFAULT NULL        COMMENT 'Base32 TOTP secret',
  ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '1 = 2FA active',
  ADD COLUMN IF NOT EXISTS notif_prefs        JSON         DEFAULT NULL        COMMENT 'JSON email notification preferences';
