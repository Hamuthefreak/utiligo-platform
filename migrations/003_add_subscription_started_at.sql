-- Migration 003: Add subscription_started_at column to utiligo_users
-- Run in phpMyAdmin on your users DB (if0_40011051_db)
-- Safe to ignore if column already exists.

ALTER TABLE utiligo_users
  ADD COLUMN subscription_started_at DATETIME NULL DEFAULT NULL
  AFTER subscription_status;
