CREATE TABLE error_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  level ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'error',
  message TEXT NOT NULL,
  context JSON NULL,
  file VARCHAR(300) NULL,
  line INT NULL,
  user_id INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  KEY idx_level_date (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
