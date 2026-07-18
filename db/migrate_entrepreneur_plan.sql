-- ============================================================
-- Migration: Add 'entrepreneur' plan support
-- Run once against your utiligo_users database
-- ============================================================

-- 1. Widen the plan ENUM (safe to run even if already done)
ALTER TABLE utiligo_users
  MODIFY COLUMN plan ENUM('free','pro','entrepreneur') NOT NULL DEFAULT 'free';

-- 2. Widen subscription_status if it uses ENUM
--    (skip if it's already VARCHAR)
-- ALTER TABLE utiligo_users
--   MODIFY COLUMN subscription_status ENUM('none','active','cancelled','past_due') NOT NULL DEFAULT 'none';

-- 3. Ensure subscription_started_at column exists
ALTER TABLE utiligo_users
  ADD COLUMN IF NOT EXISTS subscription_started_at DATETIME NULL DEFAULT NULL;

-- 4. Verify
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'utiligo_users'
  AND COLUMN_NAME IN ('plan','subscription_status','subscription_started_at')
ORDER BY COLUMN_NAME;
