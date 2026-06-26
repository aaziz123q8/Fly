-- 075: Hostinger-compatible schema reconciliation
-- MySQL 8.0 plain DDL only.
-- No INFORMATION_SCHEMA. No stored procedures. No DELIMITER. No CREATE INDEX IF NOT EXISTS.
-- No AFTER clauses — column position is cosmetic; omitting AFTER makes every statement
-- fully independent and eliminates cascade failures if any single statement is retried.
--
-- HOW TO RUN IN phpMyAdmin:
--   1. Open phpMyAdmin → select your database → SQL tab
--   2. Check "Continue on error" (also shown as "Go to next statement even if there is an error")
--   3. Paste the entire file and click Go
--   4. After completion, run 075_verify.sql to confirm all columns exist
--
-- "Duplicate column name" (1060) and "Duplicate key name" (1061) are safe to ignore —
-- they mean the column or index already exists.

-- ============================================================
-- SECTION 1: flight_bookings — add missing columns
-- ============================================================

ALTER TABLE flight_bookings ADD COLUMN provider_offer_id            VARCHAR(100)  NULL          COMMENT 'Duffel offer ID used to create this order';
ALTER TABLE flight_bookings ADD COLUMN pending_cancellation_id      VARCHAR(100)  NULL          COMMENT 'Duffel order cancellation ID pending confirmation';
ALTER TABLE flight_bookings ADD COLUMN cancellation_refund_amount   DECIMAL(12,2) NULL          COMMENT 'Refund amount quoted by Duffel cancellation';
ALTER TABLE flight_bookings ADD COLUMN duffel_booking_reference     VARCHAR(30)   NULL          COMMENT 'Airline PNR from Duffel order.booking_reference';
ALTER TABLE flight_bookings ADD COLUMN paid_at                      DATETIME      NULL          COMMENT 'payment_status.paid_at from Duffel';
ALTER TABLE flight_bookings ADD COLUMN payment_required_by          DATETIME      NULL          COMMENT 'payment_status.payment_required_by — booking expires';
ALTER TABLE flight_bookings ADD COLUMN price_guarantee_expires_at   DATETIME      NULL          COMMENT 'payment_status.price_guarantee_expires_at';
ALTER TABLE flight_bookings ADD COLUMN void_window_ends_at          DATETIME      NULL          COMMENT 'order.void_window_ends_at — free-cancel deadline';
ALTER TABLE flight_bookings ADD COLUMN available_actions            JSON          NULL          COMMENT 'order.available_actions array';
ALTER TABLE flight_bookings ADD COLUMN synced_at                    DATETIME      NULL          COMMENT 'Last Duffel getOrder() refresh timestamp';
ALTER TABLE flight_bookings ADD COLUMN live_mode                    TINYINT(1)    NOT NULL DEFAULT 1 COMMENT 'order.live_mode (0=test, 1=live)';
ALTER TABLE flight_bookings ADD COLUMN cancellation_refund_to       VARCHAR(60)   NULL          COMMENT 'cancellation.refund_to field from Duffel';
ALTER TABLE flight_bookings ADD COLUMN cancellation_expires_at      DATETIME      NULL          COMMENT 'cancellation.expires_at — quote validity deadline';
ALTER TABLE flight_bookings ADD COLUMN refund_conditions            JSON          NULL          COMMENT 'conditions.refund_before_departure from order/offer';
ALTER TABLE flight_bookings ADD COLUMN change_conditions            JSON          NULL          COMMENT 'conditions.change_before_departure from order/offer';
ALTER TABLE flight_bookings ADD COLUMN awaiting_payment             TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 while Stripe payment has not yet completed';
ALTER TABLE flight_bookings ADD COLUMN duffel_payment_failure       VARCHAR(500)  NULL          COMMENT 'Last Duffel payment failure message';
ALTER TABLE flight_bookings ADD COLUMN cancellation_refund_currency CHAR(3)       NULL          COMMENT 'Currency of cancellation_refund_amount';
ALTER TABLE flight_bookings ADD COLUMN auto_refunded_at             DATETIME      NULL          COMMENT 'Timestamp when automatic refund was issued';
ALTER TABLE flight_bookings ADD COLUMN booking_references           JSON          NULL          COMMENT 'All booking references from Duffel order (PNRs per segment)';
ALTER TABLE flight_bookings ADD COLUMN cancellation_confirmed_at    DATETIME      NULL          COMMENT 'Timestamp when Duffel confirmed cancellation';

-- ============================================================
-- SECTION 2: flight_bookings — extend status ENUM
-- ============================================================

ALTER TABLE flight_bookings
  MODIFY COLUMN status ENUM('pending','confirmed','cancelled','changed','awaiting_payment','failed')
    NOT NULL DEFAULT 'pending';

-- ============================================================
-- SECTION 3: flight_bookings — indexes
-- (plain CREATE INDEX — safe because these columns did not exist until the statements above)
-- ============================================================

CREATE INDEX idx_payment_deadline   ON flight_bookings (payment_required_by);
CREATE INDEX idx_duffel_booking_ref ON flight_bookings (duffel_booking_reference);
CREATE INDEX idx_synced_at          ON flight_bookings (synced_at);

-- ============================================================
-- SECTION 4: payments — add missing columns
-- ============================================================

ALTER TABLE payments ADD COLUMN stripe_refund_id         VARCHAR(200)  NULL              COMMENT 'Stripe refund ID (re_...)';
ALTER TABLE payments ADD COLUMN duffel_payment_id        VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Duffel payment ID when paid via Duffel balance';
ALTER TABLE payments ADD COLUMN payment_type             ENUM('stripe','duffel_balance','duffel_card') NOT NULL DEFAULT 'stripe' COMMENT 'Payment processor used';
ALTER TABLE payments ADD COLUMN duffel_payment_failure   VARCHAR(500)  NULL              COMMENT 'Last Duffel payment failure message';
ALTER TABLE payments ADD COLUMN auto_refund_reason       VARCHAR(255)  NULL              COMMENT 'Reason for automatic refund (e.g. booking_failed)';
ALTER TABLE payments ADD COLUMN auto_refunded_at         DATETIME      NULL              COMMENT 'Timestamp when auto-refund was triggered';
ALTER TABLE payments ADD COLUMN updated_at               TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp';

-- ============================================================
-- SECTION 5: payments — extend status ENUM
-- ============================================================

ALTER TABLE payments
  MODIFY COLUMN status ENUM('pending','succeeded','failed','refunded','cancelled','refund_pending','refund_failed','refund_pending_manual')
    NOT NULL DEFAULT 'pending';

-- ============================================================
-- SECTION 6: payments — index
-- ============================================================

CREATE INDEX idx_payments_booking_status ON payments (booking_type, booking_id, status);

-- ============================================================
-- SECTION 7: booking_sessions — add missing columns
-- ============================================================

ALTER TABLE booking_sessions ADD COLUMN device_ip          VARCHAR(45)   NULL COMMENT 'Client IP at session creation';
ALTER TABLE booking_sessions ADD COLUMN device_user_agent  VARCHAR(500)  NULL COMMENT 'Client User-Agent at session creation';
ALTER TABLE booking_sessions ADD COLUMN total_amount       DECIMAL(10,2) NULL COMMENT 'Total price including services at time of session';

-- ============================================================
-- SECTION 8: flight_booking_documents — create table
-- ============================================================

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
