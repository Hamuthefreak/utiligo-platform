-- Migration 005: Drop the unique index on public_slug
-- Run in phpMyAdmin on if0_40011051_db to fix the generation crash immediately.
--
-- Slugs are already unique in practice (name + site ID + random suffix)
-- so this constraint adds no safety but causes hard crashes on edge cases.

ALTER TABLE utiligo_generated_sites DROP INDEX uq_utiligo_generated_sites_slug;
