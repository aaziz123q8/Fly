CREATE TABLE offer_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_request_id VARCHAR(100) NOT NULL,
  offer_id VARCHAR(100) NOT NULL,
  provider_id TINYINT UNSIGNED NOT NULL,
  search_hash VARCHAR(64) NOT NULL,
  offer_data JSON NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_offer_id (offer_id),
  KEY idx_search_expires (search_hash, expires_at),
  KEY idx_expires_id (expires_at, id),
  FOREIGN KEY (provider_id) REFERENCES providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
