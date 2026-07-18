-- Migration 004: Add missing columns to utiligo_generated_sites
-- Run in phpMyAdmin on if0_40011051_db (platform DB)
-- Each ALTER is safe to run even if the column already exists — just ignore duplicate column errors.

ALTER TABLE utiligo_generated_sites ADD COLUMN business_category VARCHAR(120) NULL AFTER business_name;
ALTER TABLE utiligo_generated_sites ADD COLUMN business_city     VARCHAR(100) NULL AFTER business_category;
ALTER TABLE utiligo_generated_sites ADD COLUMN business_phone    VARCHAR(30)  NULL AFTER business_city;
ALTER TABLE utiligo_generated_sites ADD COLUMN business_email    VARCHAR(191) NULL AFTER business_phone;
