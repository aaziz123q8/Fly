-- 066: Add cancellation and change tracking fields to flight_bookings
ALTER TABLE flight_bookings
  ADD COLUMN IF NOT EXISTS pending_cancellation_id VARCHAR(100) NULL AFTER provider_order_id,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER updated_at,
  ADD COLUMN IF NOT EXISTS cancellation_refund_amount DECIMAL(12,2) NULL AFTER cancelled_at;
