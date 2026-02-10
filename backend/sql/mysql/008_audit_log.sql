CREATE TABLE IF NOT EXISTS wa_audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT NULL,
  target_user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  source VARCHAR(32) NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  details JSON NULL,
  INDEX idx_actor (actor_user_id),
  INDEX idx_target (target_user_id),
  INDEX idx_action (action),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES wa_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_target FOREIGN KEY (target_user_id) REFERENCES wa_users(id) ON DELETE SET NULL
);
