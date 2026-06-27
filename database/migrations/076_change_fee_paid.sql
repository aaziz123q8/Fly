-- Migration 076: add change_fee_paid to track cumulative flight change fees
ALTER TABLE flight_bookings
  ADD COLUMN IF NOT EXISTS change_fee_paid DECIMAL(10,2) DEFAULT NULL COMMENT 'Total change fees paid by customer (cumulative)';
