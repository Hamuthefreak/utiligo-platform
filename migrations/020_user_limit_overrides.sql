-- Migration 020: Per-user plan-limit overrides.
-- Allows an admin to bump (or shrink) lead / site / search-daily / client
-- limits for a SPECIFIC user without changing the plan-wide cap.
-- Override row's value (when present) wins over the plan default returned
-- by plan_lead_limit() / plan_site_limit() / plan_search_daily_limit() /
-- plan_client_limit()  in includes/plans.php.
--
-- Stored in the USER database alongside utiligo_users, since it's keyed by
-- user_id and read on every portal/api page hit alongside the user row.
-- On the platform DB this CREATE is a no-op (IF NOT EXISTS) — the runner
-- tolerates the duplicate-key/table-exists reason so a re-run is safe.
--
-- `limit_key` is one of: 'lead_limit','site_limit','search_daily',
-- 'generate_daily','client_limit'  (reflects the keys in plan_config()).
-- -1 means unlimited. NULL means "use plan default" (treated as not-set).

CREATE TABLE IF NOT EXISTS `user_limit_overrides` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT NOT NULL,
    `limit_key`   VARCHAR(40) NOT NULL,
    `limit_value` INT NOT NULL,
    `set_by`      INT NULL,                       -- admin user_id who set it
    `set_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_key` (`user_id`, `limit_key`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
