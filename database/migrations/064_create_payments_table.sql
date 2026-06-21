CREATE TABLE IF NOT EXISTS payments (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                   BIGINT UNSIGNED NULL,
  stripe_payment_intent_id  VARCHAR(200) NULL,
  amount                    DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency                  CHAR(3) NOT NULL DEFAULT 'GBP',
  status                    ENUM('pending','processing','succeeded','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
  booking_type              ENUM('flight','hotel') NULL,
  booking_id                BIGINT UNSIGNED NULL,
  metadata                  JSON NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at                TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_intent (stripe_payment_intent_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
