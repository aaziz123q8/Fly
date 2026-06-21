CREATE TABLE IF NOT EXISTS currencies (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         CHAR(3) NOT NULL,
  name         VARCHAR(100) NOT NULL,
  symbol       VARCHAR(10) NULL,
  rate_to_gbp  DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  updated_at   TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default currencies
INSERT IGNORE INTO currencies (code, name, symbol, rate_to_gbp, is_active) VALUES
  ('GBP', 'الجنيه الإسترليني',  '£',   1.000000, 1),
  ('USD', 'الدولار الأمريكي',    '$',   1.270000, 1),
  ('KWD', 'الدينار الكويتي',     'ك.د', 0.391000, 1),
  ('SAR', 'الريال السعودي',      'ر.س', 4.765000, 1),
  ('AED', 'الدرهم الإماراتي',    'د.إ', 4.665000, 1),
  ('EUR', 'اليورو',              '€',   1.175000, 1);
