CREATE TABLE saved_searches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  search_type ENUM('flight','hotel') NOT NULL DEFAULT 'flight',
  label VARCHAR(100) NULL,
  search_params JSON NOT NULL,
  alert_on_price_drop BOOLEAN NOT NULL DEFAULT 0,
  last_checked_price DECIMAL(10,2) NULL,
  last_checked_at TIMESTAMP NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  KEY idx_user_active (user_id, is_active),
  KEY idx_price_alerts (alert_on_price_drop, is_active),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
