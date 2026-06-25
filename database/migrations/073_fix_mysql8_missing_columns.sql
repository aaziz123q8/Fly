-- 073: Fix MySQL 8.0 incompatible migrations (066 + 067)
-- ============================================================
-- Migrations 066 and 067 used MariaDB-only syntax
-- (ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS) which
-- fails silently on MySQL 8.0 (Hostinger).  This migration
-- adds every column those files intended, plus idempotently
-- covers 068-070 in case those also did not run.
--
-- Safe to run multiple times.  Uses INFORMATION_SCHEMA checks
-- inside a stored procedure — no MariaDB-specific syntax.
--
-- Run via phpMyAdmin → SQL tab, or:
--   mysql -u USER -p DB_NAME < 073_fix_mysql8_missing_columns.sql

DROP PROCEDURE IF EXISTS sp_073_fix_missing_columns;

DELIMITER $$

CREATE PROCEDURE sp_073_fix_missing_columns()
BEGIN

    -- ── From migration 066 ──────────────────────────────────────────────────

    -- pending_cancellation_id (066)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'pending_cancellation_id'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN pending_cancellation_id VARCHAR(100) NULL
            COMMENT 'Duffel order_cancellation.id awaiting confirmation'
            AFTER provider_order_id;
    END IF;

    -- cancellation_refund_amount (066) — cancelled_at already in base table 017
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'cancellation_refund_amount'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_refund_amount DECIMAL(12,2) NULL
            COMMENT 'Refund amount quoted by Duffel for this cancellation';
    END IF;

    -- ── From migration 067 ──────────────────────────────────────────────────

    -- duffel_booking_reference (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'duffel_booking_reference'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN duffel_booking_reference VARCHAR(30) NULL
            COMMENT 'Airline PNR from Duffel order.booking_reference'
            AFTER provider_order_id;
    END IF;

    -- paid_at (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'paid_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN paid_at DATETIME NULL
            COMMENT 'payment_status.paid_at from Duffel';
    END IF;

    -- payment_required_by (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'payment_required_by'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN payment_required_by DATETIME NULL
            COMMENT 'payment_status.payment_required_by — booking expires';
    END IF;

    -- price_guarantee_expires_at (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'price_guarantee_expires_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN price_guarantee_expires_at DATETIME NULL
            COMMENT 'payment_status.price_guarantee_expires_at';
    END IF;

    -- void_window_ends_at (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'void_window_ends_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN void_window_ends_at DATETIME NULL
            COMMENT 'order.void_window_ends_at — free-cancel deadline';
    END IF;

    -- available_actions (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'available_actions'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN available_actions JSON NULL
            COMMENT 'order.available_actions array';
    END IF;

    -- synced_at (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'synced_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN synced_at DATETIME NULL
            COMMENT 'Last Duffel getOrder() refresh timestamp';
    END IF;

    -- live_mode (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'live_mode'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN live_mode TINYINT(1) NOT NULL DEFAULT 1
            COMMENT 'order.live_mode (0=test, 1=live)';
    END IF;

    -- cancellation_refund_to (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'cancellation_refund_to'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_refund_to VARCHAR(60) NULL
            COMMENT 'cancellation.refund_to field from Duffel';
    END IF;

    -- cancellation_expires_at (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'cancellation_expires_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_expires_at DATETIME NULL
            COMMENT 'cancellation.expires_at — quote validity deadline';
    END IF;

    -- refund_conditions (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'refund_conditions'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN refund_conditions JSON NULL
            COMMENT 'conditions.refund_before_departure from order/offer';
    END IF;

    -- change_conditions (067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'change_conditions'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN change_conditions JSON NULL
            COMMENT 'conditions.change_before_departure from order/offer';
    END IF;

    -- ── From migration 068 ──────────────────────────────────────────────────

    -- awaiting_payment (068)
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

    -- duffel_payment_failure (068)
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

    -- cancellation_refund_currency (068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'cancellation_refund_currency'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_refund_currency CHAR(3) NULL
            COMMENT 'Currency of Duffel refund_amount';
    END IF;

    -- auto_refunded_at (068)
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

    -- ── From migration 069 ──────────────────────────────────────────────────

    -- booking_references (069)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'booking_references'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN booking_references JSON NULL
            COMMENT 'order.booking_references[] from Duffel — per-airline PNR array';
    END IF;

    -- ── From migration 070 ──────────────────────────────────────────────────

    -- cancellation_confirmed_at (070)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'cancellation_confirmed_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_confirmed_at DATETIME NULL
            COMMENT 'Duffel order_cancellation.confirmed_at';
    END IF;

    -- ── Indexes (check INFORMATION_SCHEMA.STATISTICS) ───────────────────────

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND INDEX_NAME   = 'idx_payment_deadline'
    ) THEN
        ALTER TABLE flight_bookings
            ADD INDEX idx_payment_deadline (payment_required_by);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND INDEX_NAME   = 'idx_duffel_booking_ref'
    ) THEN
        ALTER TABLE flight_bookings
            ADD INDEX idx_duffel_booking_ref (duffel_booking_reference);
    END IF;

    -- ── flight_booking_documents table (from 067) ───────────────────────────

    CREATE TABLE IF NOT EXISTS flight_booking_documents (
        id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        booking_id        INT UNSIGNED  NOT NULL,
        document_type     VARCHAR(50)   NOT NULL   COMMENT 'electronic_ticket | boarding_pass | itinerary',
        unique_identifier VARCHAR(50)   NOT NULL   COMMENT 'Ticket number / document unique ID from Duffel',
        passenger_ids     JSON          NULL       COMMENT 'Array of Duffel passenger IDs this document covers',
        created_at        TIMESTAMP     NOT NULL DEFAULT NOW(),
        PRIMARY KEY (id),
        UNIQUE KEY uq_booking_doc (booking_id, unique_identifier),
        KEY idx_booking (booking_id),
        FOREIGN KEY (booking_id) REFERENCES flight_bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

END$$

DELIMITER ;

CALL sp_073_fix_missing_columns();

DROP PROCEDURE IF EXISTS sp_073_fix_missing_columns;

-- Verify result — run this SELECT to confirm all columns now exist:
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'flight_bookings'
ORDER BY ORDINAL_POSITION;
