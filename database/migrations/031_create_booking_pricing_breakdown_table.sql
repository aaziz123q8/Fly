CREATE TABLE booking_pricing_breakdown (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_type ENUM('flight','hotel') NOT NULL,
  booking_id INT UNSIGNED NOT NULL,
  item_type VARCHAR(50) NOT NULL,
  description_en VARCHAR(200) NULL,
  description_ar VARCHAR(200) NULL,
  quantity TINYINT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  total_price DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'GBP',
  PRIMARY KEY (id),
  KEY idx_booking (booking_type, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
