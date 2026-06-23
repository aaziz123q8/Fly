-- 072: Ensure canonical payments schema and fix duplicate cancelled_at
--
-- FK verification (all correct, no changes needed):
--   hotel_booking_rooms.booking_id  → hotel_bookings.id  ✓
--   hotel_booking_guests.booking_id → hotel_bookings.id  ✓
--   flight_booking_documents.booking_id → flight_bookings.id  ✓
--
-- This migration is safe to run on both fresh and existing databases.
-- Uses INFORMATION_SCHEMA (MySQL 8.0-compatible, no MariaDB-only syntax).

DROP PROCEDURE IF EXISTS sp_fix_payments_schema;

DELIMITER $$

CREATE PROCEDURE sp_fix_payments_schema()
BEGIN
    -- payment_method column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_method'
    ) THEN
        ALTER TABLE payments ADD COLUMN payment_method VARCHAR(50) NULL AFTER currency;
    END IF;

    -- idempotency_key column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'idempotency_key'
    ) THEN
        ALTER TABLE payments ADD COLUMN idempotency_key VARCHAR(128) NULL;
    END IF;

    -- stripe_charge_id column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'stripe_charge_id'
    ) THEN
        ALTER TABLE payments ADD COLUMN stripe_charge_id VARCHAR(100) NULL;
    END IF;

    -- failure_reason column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'failure_reason'
    ) THEN
        ALTER TABLE payments ADD COLUMN failure_reason TEXT NULL;
    END IF;

    -- duffel_payment_id column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'duffel_payment_id'
    ) THEN
        ALTER TABLE payments ADD COLUMN duffel_payment_id VARCHAR(100) NULL;
    END IF;

    -- payment_type column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_type'
    ) THEN
        ALTER TABLE payments ADD COLUMN payment_type VARCHAR(50) NULL;
    END IF;

    -- stripe_refund_id column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'stripe_refund_id'
    ) THEN
        ALTER TABLE payments ADD COLUMN stripe_refund_id VARCHAR(100) NULL;
    END IF;

    -- duffel_payment_failure column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'duffel_payment_failure'
    ) THEN
        ALTER TABLE payments ADD COLUMN duffel_payment_failure TEXT NULL;
    END IF;

    -- auto_refund_reason column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'auto_refund_reason'
    ) THEN
        ALTER TABLE payments ADD COLUMN auto_refund_reason VARCHAR(255) NULL;
    END IF;

    -- auto_refunded_at column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'auto_refunded_at'
    ) THEN
        ALTER TABLE payments ADD COLUMN auto_refunded_at DATETIME NULL;
    END IF;

    -- paid_at column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'paid_at'
    ) THEN
        ALTER TABLE payments ADD COLUMN paid_at DATETIME NULL;
    END IF;

    -- updated_at column
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'updated_at'
    ) THEN
        ALTER TABLE payments ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW();
    END IF;

    -- status ENUM: ensure 'auto_refunded' and 'cancellation_pending_refund' values are supported.
    -- We use a VARCHAR expansion to avoid ENUM alteration complexity.
    -- (Migration 028 uses ENUM; migration 064 uses ENUM with more values.
    --  This widens status to VARCHAR(50) only if it is currently an ENUM without the needed values.)
    BEGIN
        DECLARE v_col_type VARCHAR(255);
        SELECT COLUMN_TYPE INTO v_col_type
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'status';

        IF v_col_type NOT LIKE '%auto_refunded%' THEN
            ALTER TABLE payments MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending';
        END IF;
    END;

    -- Ensure cancelled_at exists on flight_bookings (migration 017 adds it; 066 tries to re-add it).
    -- This is a safety net in case migration 066 failed on a fresh install where 017 already had the column.
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings' AND COLUMN_NAME = 'cancelled_at'
    ) THEN
        ALTER TABLE flight_bookings ADD COLUMN cancelled_at DATETIME NULL;
    END IF;

END$$

DELIMITER ;

CALL sp_fix_payments_schema();
DROP PROCEDURE IF EXISTS sp_fix_payments_schema;
