-- Utiligo CRM tables
-- Run once against the platform DB (same DB as utiligo_generated_sites)

CREATE TABLE IF NOT EXISTS crm_clients (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  name          VARCHAR(120) NOT NULL,
  business      VARCHAR(160),
  email         VARCHAR(180),
  phone         VARCHAR(40),
  city          VARCHAR(80),
  industry      VARCHAR(80),
  stage         ENUM('lead','contacted','proposal','negotiation','won','lost') NOT NULL DEFAULT 'lead',
  deal_value    DECIMAL(10,2) DEFAULT 0.00,
  probability   TINYINT DEFAULT 50,
  source        VARCHAR(80) DEFAULT 'utiligo_lead',
  avatar_color  VARCHAR(12) DEFAULT '#3b82f6',
  created_at    DATETIME DEFAULT NOW(),
  updated_at    DATETIME DEFAULT NOW() ON UPDATE NOW(),
  INDEX idx_user (user_id),
  INDEX idx_stage (stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_deals (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  client_id     INT NOT NULL,
  title         VARCHAR(200) NOT NULL,
  value         DECIMAL(10,2) DEFAULT 0.00,
  stage         ENUM('lead','contacted','proposal','negotiation','won','lost') NOT NULL DEFAULT 'lead',
  closed_at     DATE,
  notes         TEXT,
  created_at    DATETIME DEFAULT NOW(),
  INDEX idx_user (user_id),
  FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_tasks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  client_id     INT,
  title         VARCHAR(200) NOT NULL,
  due_date      DATE,
  priority      ENUM('low','medium','high') DEFAULT 'medium',
  done          TINYINT(1) DEFAULT 0,
  created_at    DATETIME DEFAULT NOW(),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_notes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  client_id     INT,
  body          TEXT NOT NULL,
  pinned        TINYINT(1) DEFAULT 0,
  created_at    DATETIME DEFAULT NOW(),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
