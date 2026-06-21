CREATE TABLE flight_booking_passengers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  traveler_id INT UNSIGNED NULL,
  passenger_type ENUM('adult','child','infant') NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  gender ENUM('male','female') NOT NULL,
  date_of_birth DATE NOT NULL,
  nationality CHAR(2) NOT NULL,
  passport_number VARCHAR(30) NULL,
  passport_expiry DATE NULL,
  ticket_number VARCHAR(20) NULL,
  provider_passenger_id VARCHAR(100) NULL,
  PRIMARY KEY (id),
  KEY idx_booking (booking_id),
  FOREIGN KEY (booking_id) REFERENCES flight_bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (traveler_id) REFERENCES travelers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
