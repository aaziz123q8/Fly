CREATE TABLE webhook_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source ENUM('stripe','duffel','ratehawk') NOT NULL,
  event_id VARCHAR(100) NOT NULL,
  event_type VARCHAR(100) NULL,
  signature_valid BOOLEAN NOT NULL DEFAULT 0,
  processed BOOLEAN NOT NULL DEFAULT 0,
  processing_result VARCHAR(100) NULL,
  payload JSON NULL,
  processed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_source_event (source, event_id),
  KEY idx_processed (processed, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
