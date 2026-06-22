-- 067: Sprint 1 — Duffel Orders production fields
-- Adds e-ticket, payment deadline, conditions, void window, available_actions, sync fields

ALTER TABLE flight_bookings
  ADD COLUMN IF NOT EXISTS duffel_booking_reference    VARCHAR(30)   NULL          COMMENT 'Airline PNR from Duffel order.booking_reference'    AFTER provider_order_id,
  ADD COLUMN IF NOT EXISTS paid_at                     DATETIME      NULL          COMMENT 'payment_status.paid_at from Duffel'                   AFTER cancellation_refund_amount,
  ADD COLUMN IF NOT EXISTS payment_required_by         DATETIME      NULL          COMMENT 'payment_status.payment_required_by — booking expires'  AFTER paid_at,
  ADD COLUMN IF NOT EXISTS price_guarantee_expires_at  DATETIME      NULL          COMMENT 'payment_status.price_guarantee_expires_at'             AFTER payment_required_by,
  ADD COLUMN IF NOT EXISTS void_window_ends_at         DATETIME      NULL          COMMENT 'order.void_window_ends_at — free-cancel deadline'      AFTER price_guarantee_expires_at,
  ADD COLUMN IF NOT EXISTS available_actions           JSON          NULL          COMMENT 'order.available_actions array'                         AFTER void_window_ends_at,
  ADD COLUMN IF NOT EXISTS synced_at                   DATETIME      NULL          COMMENT 'Last Duffel getOrder() refresh timestamp'              AFTER available_actions,
  ADD COLUMN IF NOT EXISTS live_mode                   TINYINT(1)    NOT NULL DEFAULT 1 COMMENT 'order.live_mode (0=test, 1=live)'               AFTER synced_at,
  ADD COLUMN IF NOT EXISTS cancellation_refund_to      VARCHAR(60)   NULL          COMMENT 'cancellation.refund_to field from Duffel'              AFTER live_mode,
  ADD COLUMN IF NOT EXISTS cancellation_expires_at     DATETIME      NULL          COMMENT 'cancellation.expires_at — quote validity deadline'     AFTER cancellation_refund_to,
  ADD COLUMN IF NOT EXISTS refund_conditions           JSON          NULL          COMMENT 'conditions.refund_before_departure from order/offer'    AFTER cancellation_expires_at,
  ADD COLUMN IF NOT EXISTS change_conditions           JSON          NULL          COMMENT 'conditions.change_before_departure from order/offer'    AFTER refund_conditions;

-- Index for payment deadline enforcement query
ALTER TABLE flight_bookings
  ADD INDEX IF NOT EXISTS idx_payment_deadline (payment_required_by),
  ADD INDEX IF NOT EXISTS idx_duffel_booking_ref (duffel_booking_reference);

-- Store electronic tickets and other travel documents per booking
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
