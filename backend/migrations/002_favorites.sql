CREATE TABLE IF NOT EXISTS wa_favorites (
  user_id INT NOT NULL,
  file_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, file_id),
  FOREIGN KEY (user_id) REFERENCES wa_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_wa_favorites_file ON wa_favorites (file_id);
