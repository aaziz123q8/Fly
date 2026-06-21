CREATE TABLE cities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  country_id SMALLINT UNSIGNED NOT NULL,
  name_en VARCHAR(150) NOT NULL,
  name_ar VARCHAR(150) NULL,
  iata_city_code CHAR(3) NULL,
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_country (country_id),
  FOREIGN KEY (country_id) REFERENCES countries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
