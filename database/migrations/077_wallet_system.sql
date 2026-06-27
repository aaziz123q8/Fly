-- Migration 077: wallet system
CREATE TABLE IF NOT EXISTS user_wallets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL UNIQUE,
    balance     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency    CHAR(3)  NOT NULL DEFAULT 'GBP',
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    type          ENUM('credit','debit') NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    currency      CHAR(3) NOT NULL DEFAULT 'GBP',
    balance_after DECIMAL(12,2) NOT NULL,
    description   VARCHAR(255) NOT NULL DEFAULT '',
    reference     VARCHAR(100) DEFAULT NULL COMMENT 'booking ref, payment id, etc.',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wallet_tx_user (user_id),
    CONSTRAINT fk_wallet_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Column to store refund preference chosen by customer at cancellation time
ALTER TABLE flight_bookings
    ADD COLUMN IF NOT EXISTS refund_preference ENUM('wallet','original_payment') DEFAULT NULL;
