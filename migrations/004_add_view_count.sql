-- Migration 004: add view_count to utiligo_generated_sites
-- Run once on your database.
ALTER TABLE utiligo_generated_sites
  ADD COLUMN IF NOT EXISTS view_count INT UNSIGNED NOT NULL DEFAULT 0;

-- Optional: index for sorting by popularity
CREATE INDEX IF NOT EXISTS idx_sites_view_count
  ON utiligo_generated_sites (view_count DESC);
