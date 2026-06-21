CREATE TABLE IF NOT EXISTS commissions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type             ENUM('flight','hotel','all') NOT NULL DEFAULT 'flight',
  name             VARCHAR(200) NOT NULL,
  commission_type  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  commission_value DECIMAL(10,4) NOT NULL DEFAULT 0,
  applies_to       VARCHAR(50) NOT NULL DEFAULT 'all',
  condition_value  VARCHAR(200) NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at       TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
