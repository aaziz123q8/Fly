CREATE TABLE login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  email VARCHAR(180) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT NOW(),
  succeeded BOOLEAN NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ip_email (ip_address, email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
