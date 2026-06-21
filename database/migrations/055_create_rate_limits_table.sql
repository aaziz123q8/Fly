CREATE TABLE rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(45) NOT NULL,
  identifier_type ENUM('ip','user','api_key') NOT NULL DEFAULT 'ip',
  endpoint VARCHAR(100) NOT NULL,
  window_start TIMESTAMP NOT NULL DEFAULT NOW(),
  request_count SMALLINT NOT NULL DEFAULT 1,
  blocked_until TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_identifier_window (identifier, identifier_type, endpoint, window_start),
  KEY idx_identifier_endpoint (identifier, endpoint, window_start),
  KEY idx_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
