-- Migration 008: add is_admin column to utiligo_users
-- The old 009_add_is_admin_column.php was never executed because
-- run_migrations.php only scans for *.sql files.

ALTER TABLE `utiligo_users`
  ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0
  AFTER `plan`;
