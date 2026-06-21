CREATE TABLE hotel_booking_rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  room_type VARCHAR(150) NOT NULL,
  meal_plan VARCHAR(50) NULL,
  provider_room_id VARCHAR(100) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'GBP',
  PRIMARY KEY (id),
  KEY idx_booking (booking_id),
  FOREIGN KEY (booking_id) REFERENCES hotel_bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
