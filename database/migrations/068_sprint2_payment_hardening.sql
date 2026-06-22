-- Sprint 2: Payment hardening columns
-- MySQL 8.0 and MariaDB compatible. Uses INFORMATION_SCHEMA instead of MariaDB-only DDL guards.
-- Uses INFORMATION_SCHEMA checks inside a stored procedure so the migration is
-- re-runnable and non-destructive on both engines.
--
-- Run via: mysql -u user -p db_name < 068_sprint2_payment_hardening.sql
--          or phpMyAdmin / Hostinger Database Manager.
--
-- The procedure is created, executed, then immediately dropped so it leaves
-- no permanent objects in the schema.

DROP PROCEDURE IF EXISTS sp_sprint2_payment_hardening;

DELIMITER $$

CREATE PROCEDURE sp_sprint2_payment_hardening()
BEGIN

    -- ── flight_bookings ──────────────────────────────────────────────────────

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'awaiting_payment'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN awaiting_payment TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Set when Duffel flags order.payment_status.awaiting_payment';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'duffel_payment_failure'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN duffel_payment_failure VARCHAR(500) NULL
            COMMENT 'Duffel failure_reason or order creation error';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'cancellation_refund_currency'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_refund_currency CHAR(3) NULL
            COMMENT 'Currency of Duffel refund_amount (may differ from charge currency)';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'auto_refunded_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN auto_refunded_at DATETIME NULL
            COMMENT 'Timestamp of automatic Stripe refund on Duffel order failure';
    END IF;

    -- ── payments ─────────────────────────────────────────────────────────────

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'duffel_payment_id'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN duffel_payment_id VARCHAR(100) NOT NULL DEFAULT ''
            COMMENT 'Duffel payment.id if applicable';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'payment_type'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN payment_type ENUM('stripe','duffel_balance','duffel_card')
                NOT NULL DEFAULT 'stripe';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'stripe_refund_id'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN stripe_refund_id VARCHAR(200) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'duffel_payment_failure'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN duffel_payment_failure VARCHAR(500) NULL
            COMMENT 'Duffel order/payment error captured before auto-refund';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'auto_refund_reason'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN auto_refund_reason VARCHAR(255) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'updated_at'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN updated_at TIMESTAMP NOT NULL
                DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
    END IF;

    -- ── Index on duffel_payment_id ───────────────────────────────────────────

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payments'
          AND INDEX_NAME   = 'idx_duffel_payment_id'
    ) THEN
        ALTER TABLE payments
            ADD INDEX idx_duffel_payment_id (duffel_payment_id);
    END IF;

END$$

DELIMITER ;

CALL sp_sprint2_payment_hardening();

DROP PROCEDURE IF EXISTS sp_sprint2_payment_hardening;
