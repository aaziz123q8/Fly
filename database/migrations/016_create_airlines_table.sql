CREATE TABLE airlines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  iata_code CHAR(2) NOT NULL,
  icao_code CHAR(3) NULL,
  name_en VARCHAR(150) NOT NULL,
  name_ar VARCHAR(150) NULL,
  country_id SMALLINT UNSIGNED NULL,
  logo_url VARCHAR(300) NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_iata (iata_code),
  KEY idx_country (country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
