CREATE TABLE provider_credentials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id TINYINT UNSIGNED NOT NULL,
  key_name VARCHAR(50) NOT NULL,
  key_value_encrypted TEXT NOT NULL,
  environment ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_key (provider_id, key_name, environment),
  FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
