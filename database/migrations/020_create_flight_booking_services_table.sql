CREATE TABLE flight_booking_services (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  passenger_id INT UNSIGNED NOT NULL,
  service_type ENUM('bag','seat','meal','other') NOT NULL,
  service_detail VARCHAR(200) NULL,
  provider_service_id VARCHAR(100) NULL,
  amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'GBP',
  PRIMARY KEY (id),
  KEY idx_booking (booking_id),
  FOREIGN KEY (booking_id) REFERENCES flight_bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (passenger_id) REFERENCES flight_booking_passengers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
