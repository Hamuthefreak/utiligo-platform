-- Migration 021: Add last_login_at to utiligo_users (USER database).
-- Records the most recent successful login for display on the Settings →
-- Security page. Safe to re-run; the migration runner silently skips the
-- duplicate-column error (SQLSTATE 42S21).
ALTER TABLE `utiligo_users`
  ADD COLUMN `last_login_at` DATETIME NULL DEFAULT NULL;