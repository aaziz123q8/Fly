-- 075: Hostinger-compatible schema reconciliation
-- Plain MySQL 8.0 DDL only. No INFORMATION_SCHEMA. No stored procedures. No DELIMITER.
-- Safe to run multiple times: "Duplicate column name" (1060) and "Duplicate key name" (1061) are harmless.
-- Run each ALTER TABLE block separately in phpMyAdmin if the full script errors.

-- ============================================================
-- SECTION 1: flight_bookings — add missing columns
-- ============================================================

ALTER TABLE flight_bookings
  ADD COLUMN provider_offer_id            VARCHAR(100)    NULL          COMMENT 'Duffel offer ID used to create this order'                      AFTER provider_order_id;

ALTER TABLE flight_bookings
  ADD COLUMN pending_cancellation_id      VARCHAR(100)    NULL          COMMENT 'Duffel order cancellation ID pending confirmation'               AFTER provider_offer_id;

ALTER TABLE flight_bookings
  ADD COLUMN cancellation_refund_amount   DECIMAL(12,2)   NULL          COMMENT 'Refund amount quoted by Duffel cancellation'                     AFTER pending_cancellation_id;

ALTER TABLE flight_bookings
  ADD COLUMN duffel_booking_reference     VARCHAR(30)     NULL          COMMENT 'Airline PNR from Duffel order.booking_reference'                 AFTER cancellation_refund_amount;

ALTER TABLE flight_bookings
  ADD COLUMN paid_at                      DATETIME        NULL          COMMENT 'payment_status.paid_at from Duffel'                              AFTER duffel_booking_reference;

ALTER TABLE flight_bookings
  ADD COLUMN payment_required_by          DATETIME        NULL          COMMENT 'payment_status.payment_required_by — booking expires'             AFTER paid_at;

ALTER TABLE flight_bookings
  ADD COLUMN price_guarantee_expires_at   DATETIME        NULL          COMMENT 'payment_status.price_guarantee_expires_at'                       AFTER payment_required_by;

ALTER TABLE flight_bookings
  ADD COLUMN void_window_ends_at          DATETIME        NULL          COMMENT 'order.void_window_ends_at — free-cancel deadline'                AFTER price_guarantee_expires_at;

ALTER TABLE flight_bookings
  ADD COLUMN available_actions            JSON            NULL          COMMENT 'order.available_actions array'                                   AFTER void_window_ends_at;

ALTER TABLE flight_bookings
  ADD COLUMN synced_at                    DATETIME        NULL          COMMENT 'Last Duffel getOrder() refresh timestamp'                        AFTER available_actions;

ALTER TABLE flight_bookings
  ADD COLUMN live_mode                    TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'order.live_mode (0=test, 1=live)'                          AFTER synced_at;

ALTER TABLE flight_bookings
  ADD COLUMN cancellation_refund_to       VARCHAR(60)     NULL          COMMENT 'cancellation.refund_to field from Duffel'                        AFTER live_mode;

ALTER TABLE flight_bookings
  ADD COLUMN cancellation_expires_at      DATETIME        NULL          COMMENT 'cancellation.expires_at — quote validity deadline'               AFTER cancellation_refund_to;

ALTER TABLE flight_bookings
  ADD COLUMN refund_conditions            JSON            NULL          COMMENT 'conditions.refund_before_departure from order/offer'             AFTER cancellation_expires_at;

ALTER TABLE flight_bookings
  ADD COLUMN change_conditions            JSON            NULL          COMMENT 'conditions.change_before_departure from order/offer'             AFTER refund_conditions;

ALTER TABLE flight_bookings
  ADD COLUMN awaiting_payment             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 while Stripe payment has not yet completed'              AFTER change_conditions;

ALTER TABLE flight_bookings
  ADD COLUMN duffel_payment_failure       VARCHAR(500)    NULL          COMMENT 'Last Duffel payment failure message'                             AFTER awaiting_payment;

ALTER TABLE flight_bookings
  ADD COLUMN cancellation_refund_currency CHAR(3)         NULL          COMMENT 'Currency of cancellation_refund_amount'                         AFTER duffel_payment_failure;

ALTER TABLE flight_bookings
  ADD COLUMN auto_refunded_at             DATETIME        NULL          COMMENT 'Timestamp when automatic refund was issued'                      AFTER cancellation_refund_currency;

ALTER TABLE flight_bookings
  ADD COLUMN booking_references           JSON            NULL          COMMENT 'All booking references from Duffel order (PNRs per segment)'    AFTER auto_refunded_at;

ALTER TABLE flight_bookings
  ADD COLUMN cancellation_confirmed_at    DATETIME        NULL          COMMENT 'Timestamp when Duffel confirmed cancellation'                    AFTER booking_references;

-- ============================================================
-- SECTION 2: flight_bookings — extend status ENUM
-- ============================================================

ALTER TABLE flight_bookings
  MODIFY COLUMN status ENUM('pending','confirmed','cancelled','changed','awaiting_payment','failed')
    NOT NULL DEFAULT 'pending';

-- ============================================================
-- SECTION 3: flight_bookings — indexes
-- ============================================================

CREATE INDEX IF NOT EXISTS idx_payment_deadline   ON flight_bookings (payment_required_by);
CREATE INDEX IF NOT EXISTS idx_duffel_booking_ref ON flight_bookings (duffel_booking_reference);
CREATE INDEX IF NOT EXISTS idx_synced_at          ON flight_bookings (synced_at);

-- ============================================================
-- SECTION 4: payments — add missing columns
-- ============================================================

ALTER TABLE payments
  ADD COLUMN stripe_refund_id         VARCHAR(200)    NULL          COMMENT 'Stripe refund ID (re_...)'                                      AFTER stripe_charge_id;

ALTER TABLE payments
  ADD COLUMN duffel_payment_id        VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'Duffel payment ID when paid via Duffel balance'            AFTER stripe_refund_id;

ALTER TABLE payments
  ADD COLUMN payment_type             ENUM('stripe','duffel_balance','duffel_card') NOT NULL DEFAULT 'stripe'
                                                      COMMENT 'Payment processor used'                                                        AFTER duffel_payment_id;

ALTER TABLE payments
  ADD COLUMN duffel_payment_failure   VARCHAR(500)    NULL          COMMENT 'Last Duffel payment failure message'                             AFTER payment_type;

ALTER TABLE payments
  ADD COLUMN auto_refund_reason       VARCHAR(255)    NULL          COMMENT 'Reason for automatic refund (e.g. booking_failed)'              AFTER duffel_payment_failure;

ALTER TABLE payments
  ADD COLUMN auto_refunded_at         DATETIME        NULL          COMMENT 'Timestamp when auto-refund was triggered'                       AFTER auto_refund_reason;

ALTER TABLE payments
  ADD COLUMN updated_at               TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                                                      COMMENT 'Last update timestamp'                                                         AFTER auto_refunded_at;

-- ============================================================
-- SECTION 5: payments — extend status ENUM
-- ============================================================

ALTER TABLE payments
  MODIFY COLUMN status ENUM('pending','succeeded','failed','refunded','cancelled','refund_pending','refund_failed','refund_pending_manual')
    NOT NULL DEFAULT 'pending';

-- ============================================================
-- SECTION 6: payments — indexes
-- ============================================================

CREATE INDEX IF NOT EXISTS idx_payments_booking_status ON payments (booking_type, booking_id, status);

-- ============================================================
-- SECTION 7: booking_sessions — add missing columns
-- ============================================================

ALTER TABLE booking_sessions
  ADD COLUMN device_ip          VARCHAR(45)     NULL          COMMENT 'Client IP at session creation'                                      AFTER session_data;

ALTER TABLE booking_sessions
  ADD COLUMN device_user_agent  VARCHAR(500)    NULL          COMMENT 'Client User-Agent at session creation'                              AFTER device_ip;

ALTER TABLE booking_sessions
  ADD COLUMN total_amount       DECIMAL(10,2)   NULL          COMMENT 'Total price including services at time of session'                  AFTER device_user_agent;

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
