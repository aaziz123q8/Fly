CREATE TABLE traveler_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  traveler_id INT UNSIGNED NOT NULL,
  doc_type ENUM('passport','national_id','residence') NOT NULL,
  doc_number VARCHAR(30) NOT NULL,
  issuing_country CHAR(2) NOT NULL,
  expiry_date DATE NOT NULL,
  is_primary BOOLEAN NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  KEY idx_traveler (traveler_id),
  FOREIGN KEY (traveler_id) REFERENCES travelers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
