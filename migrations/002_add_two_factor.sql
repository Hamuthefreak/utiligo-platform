-- Migration 002: Add two_factor_enabled column
-- Run in phpMyAdmin -> SQL tab on your utiligo_users_db (if0_40011051_db on InfinityFree)
-- Safe to run if column already exists — just ignore the error.

ALTER TABLE utiligo_users
  ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0
  AFTER email_verified;
