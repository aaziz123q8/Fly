-- Sprint 2: Payment hardening columns
-- Safe to run multiple times (uses ADD COLUMN IF NOT EXISTS — MariaDB / MySQL 8+ via strict DDL check)

-- flight_bookings: awaiting_payment flag, Duffel failure reason, cancellation refund currency
ALTER TABLE flight_bookings
  ADD COLUMN IF NOT EXISTS awaiting_payment             TINYINT(1)    NOT NULL DEFAULT 0      COMMENT 'Set when Duffel flags order.payment_status.awaiting_payment',
  ADD COLUMN IF NOT EXISTS duffel_payment_failure       VARCHAR(500)  NULL                    COMMENT 'Duffel failure_reason or order creation error',
  ADD COLUMN IF NOT EXISTS cancellation_refund_currency CHAR(3)       NULL                    COMMENT 'Currency of Duffel refund_amount (may differ from charge currency)',
  ADD COLUMN IF NOT EXISTS auto_refunded_at             DATETIME      NULL                    COMMENT 'Timestamp of automatic Stripe refund on Duffel order failure';

-- payments: Duffel payment ID, explicit payment type, stripe refund ID, auto-refund metadata
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS duffel_payment_id       VARCHAR(100)                                                NOT NULL DEFAULT '' COMMENT 'Duffel payment.id if applicable',
  ADD COLUMN IF NOT EXISTS payment_type            ENUM('stripe','duffel_balance','duffel_card')              NOT NULL DEFAULT 'stripe',
  ADD COLUMN IF NOT EXISTS stripe_refund_id        VARCHAR(200)                                               NULL,
  ADD COLUMN IF NOT EXISTS duffel_payment_failure  VARCHAR(500)                                               NULL     COMMENT 'Duffel order/payment error captured before auto-refund',
  ADD COLUMN IF NOT EXISTS auto_refund_reason      VARCHAR(255)                                               NULL,
  ADD COLUMN IF NOT EXISTS updated_at              TIMESTAMP                                                  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Index on duffel_payment_id for reconciliation queries
ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_duffel_payment_id (duffel_payment_id);
