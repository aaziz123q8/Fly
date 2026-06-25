-- ============================================================
-- 074: Complete schema reconciliation — MySQL 8.0 / Hostinger
-- ============================================================
-- Idempotent. Safe to run multiple times.
-- Fixes all column/table/ENUM gaps found in the full audit.
--
-- Root causes:
--   • Migrations 066-067 used MariaDB-only syntax (ADD COLUMN IF NOT EXISTS)
--     which fails silently on MySQL 8.0, leaving 20+ columns absent.
--   • Migration 068 stored-procedure columns may not have been applied.
--   • base table 017 missing provider_offer_id.
--   • flight_bookings.status / payments.status ENUMs missing values.
--   • payments table missing auto_refunded_at, updated_at.
--   • flight_booking_documents table may not exist (from 067).
--   • booking_sessions missing device_ip / device_user_agent.
--
-- Run via phpMyAdmin → SQL tab, or:
--   mysql -u USER -p DB_NAME < 074_schema_reconciliation.sql
-- ============================================================

DROP PROCEDURE IF EXISTS sp_074_schema_reconciliation;

DELIMITER $$

CREATE PROCEDURE sp_074_schema_reconciliation()
BEGIN

    -- =========================================================================
    -- TABLE: flight_bookings
    -- =========================================================================

    -- provider_offer_id (missing from base migration 017)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'provider_offer_id'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN provider_offer_id VARCHAR(100) NULL
            COMMENT 'Duffel offer_id used to create this order'
            AFTER provider_order_id;
    END IF;

    -- pending_cancellation_id (from 066, failed on MySQL 8)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'pending_cancellation_id'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN pending_cancellation_id VARCHAR(100) NULL
            COMMENT 'Duffel order_cancellation.id awaiting confirmation';
    END IF;

    -- cancellation_refund_amount (from 066, failed on MySQL 8)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'cancellation_refund_amount'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_refund_amount DECIMAL(12,2) NULL
            COMMENT 'Refund amount quoted by Duffel for this cancellation';
    END IF;

    -- duffel_booking_reference (from 067, failed on MySQL 8)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'duffel_booking_reference'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN duffel_booking_reference VARCHAR(30) NULL
            COMMENT 'Airline PNR from Duffel order.booking_reference';
    END IF;

    -- paid_at (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'paid_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN paid_at DATETIME NULL
            COMMENT 'payment_status.paid_at from Duffel';
    END IF;

    -- payment_required_by (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'payment_required_by'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN payment_required_by DATETIME NULL
            COMMENT 'payment_status.payment_required_by — booking expires';
    END IF;

    -- price_guarantee_expires_at (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'price_guarantee_expires_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN price_guarantee_expires_at DATETIME NULL
            COMMENT 'payment_status.price_guarantee_expires_at';
    END IF;

    -- void_window_ends_at (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'void_window_ends_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN void_window_ends_at DATETIME NULL
            COMMENT 'order.void_window_ends_at — free-cancel deadline';
    END IF;

    -- available_actions (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'available_actions'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN available_actions JSON NULL
            COMMENT 'order.available_actions array';
    END IF;

    -- synced_at (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'synced_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN synced_at DATETIME NULL
            COMMENT 'Last Duffel getOrder() refresh timestamp';
    END IF;

    -- live_mode (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'live_mode'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN live_mode TINYINT(1) NOT NULL DEFAULT 1
            COMMENT 'order.live_mode (0=test, 1=live)';
    END IF;

    -- cancellation_refund_to (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'cancellation_refund_to'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_refund_to VARCHAR(60) NULL
            COMMENT 'cancellation.refund_to field from Duffel';
    END IF;

    -- cancellation_expires_at (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'cancellation_expires_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_expires_at DATETIME NULL
            COMMENT 'cancellation.expires_at — refund quote validity deadline';
    END IF;

    -- refund_conditions (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'refund_conditions'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN refund_conditions JSON NULL
            COMMENT 'conditions.refund_before_departure from Duffel order';
    END IF;

    -- change_conditions (from 067)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'change_conditions'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN change_conditions JSON NULL
            COMMENT 'conditions.change_before_departure from Duffel order';
    END IF;

    -- awaiting_payment (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'awaiting_payment'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN awaiting_payment TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Set when Duffel flags order.payment_status.awaiting_payment';
    END IF;

    -- duffel_payment_failure on flight_bookings (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'duffel_payment_failure'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN duffel_payment_failure VARCHAR(500) NULL
            COMMENT 'Duffel failure_reason or order creation error';
    END IF;

    -- cancellation_refund_currency (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'cancellation_refund_currency'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_refund_currency CHAR(3) NULL
            COMMENT 'Currency of Duffel refund_amount (may differ from charge currency)';
    END IF;

    -- auto_refunded_at on flight_bookings (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'auto_refunded_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN auto_refunded_at DATETIME NULL
            COMMENT 'Timestamp of automatic Stripe refund on Duffel order failure';
    END IF;

    -- booking_references (from 069)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'booking_references'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN booking_references JSON NULL
            COMMENT 'order.booking_references[] from Duffel — per-airline PNR array';
    END IF;

    -- cancellation_confirmed_at (from 070)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'cancellation_confirmed_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_confirmed_at DATETIME NULL
            COMMENT 'Duffel order_cancellation.confirmed_at';
    END IF;

    -- Extend flight_bookings.status ENUM to include awaiting_payment and failed
    -- (used by webhook onDuffelPaymentStatusUpdated and onDuffelOrderCreationFailed)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND COLUMN_NAME = 'status'
          AND COLUMN_TYPE LIKE '%awaiting_payment%'
    ) THEN
        ALTER TABLE flight_bookings
            MODIFY COLUMN status
            ENUM('pending','confirmed','cancelled','changed','awaiting_payment','failed')
            NOT NULL DEFAULT 'pending'
            COMMENT 'Booking lifecycle status';
    END IF;

    -- Indexes (use INFORMATION_SCHEMA.STATISTICS — no IF NOT EXISTS syntax)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND INDEX_NAME = 'idx_payment_deadline'
    ) THEN
        ALTER TABLE flight_bookings
            ADD INDEX idx_payment_deadline (payment_required_by);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND INDEX_NAME = 'idx_duffel_booking_ref'
    ) THEN
        ALTER TABLE flight_bookings
            ADD INDEX idx_duffel_booking_ref (duffel_booking_reference);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flight_bookings'
          AND INDEX_NAME = 'idx_provider_offer'
    ) THEN
        ALTER TABLE flight_bookings
            ADD INDEX idx_provider_offer (provider_offer_id);
    END IF;

    -- =========================================================================
    -- TABLE: payments
    -- =========================================================================

    -- stripe_refund_id (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'stripe_refund_id'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN stripe_refund_id VARCHAR(200) NULL
            COMMENT 'Stripe refund.id if a refund was issued';
    END IF;

    -- duffel_payment_id (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'duffel_payment_id'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN duffel_payment_id VARCHAR(100) NOT NULL DEFAULT ''
            COMMENT 'Duffel payment.id if applicable';
    END IF;

    -- payment_type (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'payment_type'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN payment_type
            ENUM('stripe','duffel_balance','duffel_card') NOT NULL DEFAULT 'stripe'
            COMMENT 'Payment processor and method used';
    END IF;

    -- duffel_payment_failure on payments (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'duffel_payment_failure'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN duffel_payment_failure VARCHAR(500) NULL
            COMMENT 'Duffel order/payment error captured before auto-refund';
    END IF;

    -- auto_refund_reason on payments (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'auto_refund_reason'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN auto_refund_reason VARCHAR(255) NULL
            COMMENT 'Reason code for automatic refund';
    END IF;

    -- auto_refunded_at on payments (used in PHP autoRefundStripeOnDuffelFailure,
    -- was only added to flight_bookings in 068 — missing from payments)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'auto_refunded_at'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN auto_refunded_at DATETIME NULL
            COMMENT 'Timestamp when auto-refund was issued';
    END IF;

    -- updated_at on payments (from 068; needed for UPDATE SET updated_at = NOW())
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'updated_at'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN updated_at TIMESTAMP NOT NULL
            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            COMMENT 'Row update timestamp';
    END IF;

    -- Extend payments.status ENUM to include refund lifecycle values
    -- used by autoRefundStripeOnDuffelFailure and cancelBooking flow
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND COLUMN_NAME = 'status'
          AND COLUMN_TYPE LIKE '%refund_pending%'
    ) THEN
        ALTER TABLE payments
            MODIFY COLUMN status
            ENUM('pending','succeeded','failed','refunded','cancelled',
                 'refund_pending','refund_failed','refund_pending_manual')
            NOT NULL DEFAULT 'pending'
            COMMENT 'Payment lifecycle status';
    END IF;

    -- Index on duffel_payment_id (from 068)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
          AND INDEX_NAME = 'idx_duffel_payment_id'
    ) THEN
        ALTER TABLE payments
            ADD INDEX idx_duffel_payment_id (duffel_payment_id);
    END IF;

    -- =========================================================================
    -- TABLE: booking_sessions
    -- =========================================================================

    -- device_ip (read by completeBooking for Duffel fraud-detection headers)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_sessions'
          AND COLUMN_NAME = 'device_ip'
    ) THEN
        ALTER TABLE booking_sessions
            ADD COLUMN device_ip VARCHAR(45) NULL
            COMMENT 'Client IP forwarded to Duffel for fraud detection';
    END IF;

    -- device_user_agent (read by completeBooking for Duffel fraud-detection headers)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_sessions'
          AND COLUMN_NAME = 'device_user_agent'
    ) THEN
        ALTER TABLE booking_sessions
            ADD COLUMN device_user_agent VARCHAR(500) NULL
            COMMENT 'Client User-Agent forwarded to Duffel for fraud detection';
    END IF;

    -- total_amount (read in getReview for services cost calculation)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_sessions'
          AND COLUMN_NAME = 'total_amount'
    ) THEN
        ALTER TABLE booking_sessions
            ADD COLUMN total_amount DECIMAL(10,2) NULL
            COMMENT 'Cached total including services, used for services cost display';
    END IF;

    -- =========================================================================
    -- TABLE: flight_booking_documents  (was in 067 CREATE TABLE IF NOT EXISTS,
    --         but that statement was inside the failed ALTER TABLE block)
    -- =========================================================================

    CREATE TABLE IF NOT EXISTS flight_booking_documents (
        id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        booking_id        INT UNSIGNED  NOT NULL,
        document_type     VARCHAR(50)   NOT NULL
            COMMENT 'electronic_ticket | boarding_pass | itinerary',
        unique_identifier VARCHAR(50)   NOT NULL
            COMMENT 'Ticket number or unique ID from Duffel',
        passenger_ids     JSON          NULL
            COMMENT 'Array of Duffel passenger IDs this document covers',
        created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_booking_doc (booking_id, unique_identifier),
        KEY idx_booking (booking_id),
        FOREIGN KEY (booking_id) REFERENCES flight_bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

END$$

DELIMITER ;

CALL sp_074_schema_reconciliation();

DROP PROCEDURE IF EXISTS sp_074_schema_reconciliation;

-- ============================================================
-- VERIFICATION QUERY
-- Run this after migration to confirm all columns exist.
-- Expected: 42+ rows for flight_bookings, 8+ for payments.
-- ============================================================

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'flight_bookings',
      'flight_booking_passengers',
      'flight_booking_segments',
      'flight_booking_documents',
      'booking_sessions',
      'payments'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;
