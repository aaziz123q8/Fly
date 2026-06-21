CREATE TABLE hotel_booking_guests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  traveler_id INT UNSIGNED NULL,
  is_lead BOOLEAN NOT NULL DEFAULT 0,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(180) NULL,
  phone VARCHAR(30) NULL,
  PRIMARY KEY (id),
  KEY idx_booking (booking_id),
  FOREIGN KEY (booking_id) REFERENCES hotel_bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (traveler_id) REFERENCES travelers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
