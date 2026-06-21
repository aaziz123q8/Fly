CREATE TABLE IF NOT EXISTS api_settings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider      VARCHAR(50) NOT NULL,
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  is_secret     TINYINT(1) NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_key (provider, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
