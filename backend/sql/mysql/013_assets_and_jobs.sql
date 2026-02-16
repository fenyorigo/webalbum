CREATE TABLE IF NOT EXISTS wa_assets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rel_path VARCHAR(1024) NOT NULL,
  type ENUM('doc','audio') NOT NULL,
  ext VARCHAR(16) NOT NULL,
  mime VARCHAR(128) NOT NULL,
  size BIGINT NOT NULL DEFAULT 0,
  mtime BIGINT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rel_path (rel_path),
  INDEX idx_type_ext (type, ext),
  INDEX idx_updated_at (updated_at)
);

CREATE TABLE IF NOT EXISTS wa_asset_meta (
  asset_id BIGINT PRIMARY KEY,
  title VARCHAR(255) NULL,
  description TEXT NULL,
  tags_json JSON NULL,
  notes TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_asset_meta_asset FOREIGN KEY (asset_id) REFERENCES wa_assets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wa_asset_derivatives (
  asset_id BIGINT NOT NULL,
  kind ENUM('thumb','pdf_preview') NOT NULL,
  path VARCHAR(2048) NULL,
  status ENUM('ready','pending','error') NOT NULL DEFAULT 'pending',
  error_text TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_id, kind),
  CONSTRAINT fk_asset_derivative_asset FOREIGN KEY (asset_id) REFERENCES wa_assets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wa_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_type VARCHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  locked_by VARCHAR(128) NULL,
  locked_at DATETIME NULL,
  attempts INT NOT NULL DEFAULT 0,
  run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jobs_status_run_after (status, run_after),
  INDEX idx_jobs_locked_at (locked_at)
);
