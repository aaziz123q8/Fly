CREATE TABLE booking_audit_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_type ENUM('flight','hotel') NOT NULL,
  booking_id INT UNSIGNED NOT NULL,
  action VARCHAR(50) NOT NULL,
  changed_by INT UNSIGNED NULL,
  changed_by_type ENUM('user','admin','system','webhook') NOT NULL DEFAULT 'system',
  before_data JSON NULL,
  after_data JSON NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  KEY idx_booking (booking_type, booking_id),
  KEY idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
