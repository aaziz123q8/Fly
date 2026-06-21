CREATE TABLE pricing_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  rule_type ENUM('commission','markup','service_fee','tax') NOT NULL,
  applies_to ENUM('flight','hotel','both') NOT NULL DEFAULT 'both',
  value_type ENUM('fixed','percentage') NOT NULL,
  value DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NULL,
  min_booking_amount DECIMAL(10,2) NULL,
  max_booking_amount DECIMAL(10,2) NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  priority TINYINT NOT NULL DEFAULT 0,
  valid_from DATE NULL,
  valid_until DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  KEY idx_type_active (rule_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
