CREATE TABLE traveler_frequent_flyers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  traveler_id INT UNSIGNED NOT NULL,
  airline_code CHAR(2) NOT NULL,
  ff_number VARCHAR(30) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  KEY idx_traveler (traveler_id),
  FOREIGN KEY (traveler_id) REFERENCES travelers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
