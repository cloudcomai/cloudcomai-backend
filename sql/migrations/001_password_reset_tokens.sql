CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  INDEX(user_id),
  INDEX(expires_at),
  INDEX(user_id, used_at)
) ENGINE=InnoDB;
