CREATE TABLE airports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  iata_code CHAR(3) NOT NULL,
  icao_code CHAR(4) NULL,
  name_en VARCHAR(200) NOT NULL,
  name_ar VARCHAR(200) NULL,
  city_id INT UNSIGNED NOT NULL,
  country_id SMALLINT UNSIGNED NOT NULL,
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  timezone VARCHAR(50) NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_iata (iata_code),
  KEY idx_city (city_id),
  FOREIGN KEY (city_id) REFERENCES cities(id),
  FOREIGN KEY (country_id) REFERENCES countries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
