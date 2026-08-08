-- Migration 017: Create utiligo_marketing_sends in the USER database.
-- Audit log of admin email blasts. Written by admin/email.php after a blast.
-- Uses CREATE IF NOT EXISTS so it is a no-op on the platform DB.
CREATE TABLE IF NOT EXISTS `utiligo_marketing_sends` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sent_by_admin`     VARCHAR(255) NOT NULL,
    `subject`           VARCHAR(255) NOT NULL,
    `segment`           VARCHAR(32)  NOT NULL,
    `recipients_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_admin`       (`sent_by_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
