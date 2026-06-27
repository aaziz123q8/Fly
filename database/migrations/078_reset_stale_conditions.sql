-- Reset stale refund/change conditions so next auto-sync re-fetches from Duffel
-- Only clears bookings that have provider_order_id (can be re-synced)
UPDATE flight_bookings
SET refund_conditions = NULL,
    change_conditions = NULL
WHERE provider_order_id IS NOT NULL
  AND provider_order_id != '';
