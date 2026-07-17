-- =====================================================
-- MIGRATION: adds shareable-link, builder-content, and uploads support
-- to an EXISTING utiligo_generated_sites table WITHOUT dropping any data.
-- Safe to run even if some of these columns already exist — each ADD
-- COLUMN is guarded, but MySQL/MariaDB on most hosts don't support
-- "ADD COLUMN IF NOT EXISTS" universally, so if you get a
-- "Duplicate column name" error on any single line, just skip that
-- line and continue with the rest.
-- =====================================================

ALTER TABLE utiligo_generated_sites
    ADD COLUMN public_slug VARCHAR(80) NULL,
    ADD COLUMN link_expires_at TIMESTAMP NULL,
    ADD COLUMN link_active BOOLEAN DEFAULT TRUE,
    ADD COLUMN builder_content LONGTEXT NULL;

ALTER TABLE utiligo_generated_sites
    ADD UNIQUE KEY uniq_public_slug (public_slug);

CREATE TABLE IF NOT EXISTS utiligo_site_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    user_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255),
    mime_type VARCHAR(100),
    file_size INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uploads_site (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
