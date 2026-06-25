-- 075 Verification Script
-- Run this in phpMyAdmin after 075_hostinger_schema_fix.sql
-- Every query should return exactly ONE row. If a query returns empty, that column is still missing.

-- flight_bookings
SHOW COLUMNS FROM flight_bookings LIKE 'provider_offer_id';
SHOW COLUMNS FROM flight_bookings LIKE 'pending_cancellation_id';
SHOW COLUMNS FROM flight_bookings LIKE 'cancellation_refund_amount';
SHOW COLUMNS FROM flight_bookings LIKE 'duffel_booking_reference';
SHOW COLUMNS FROM flight_bookings LIKE 'paid_at';
SHOW COLUMNS FROM flight_bookings LIKE 'payment_required_by';
SHOW COLUMNS FROM flight_bookings LIKE 'price_guarantee_expires_at';
SHOW COLUMNS FROM flight_bookings LIKE 'void_window_ends_at';
SHOW COLUMNS FROM flight_bookings LIKE 'available_actions';
SHOW COLUMNS FROM flight_bookings LIKE 'synced_at';
SHOW COLUMNS FROM flight_bookings LIKE 'live_mode';
SHOW COLUMNS FROM flight_bookings LIKE 'cancellation_refund_to';
SHOW COLUMNS FROM flight_bookings LIKE 'cancellation_expires_at';
SHOW COLUMNS FROM flight_bookings LIKE 'refund_conditions';
SHOW COLUMNS FROM flight_bookings LIKE 'change_conditions';
SHOW COLUMNS FROM flight_bookings LIKE 'awaiting_payment';
SHOW COLUMNS FROM flight_bookings LIKE 'duffel_payment_failure';
SHOW COLUMNS FROM flight_bookings LIKE 'cancellation_refund_currency';
SHOW COLUMNS FROM flight_bookings LIKE 'auto_refunded_at';
SHOW COLUMNS FROM flight_bookings LIKE 'booking_references';
SHOW COLUMNS FROM flight_bookings LIKE 'cancellation_confirmed_at';

-- payments
SHOW COLUMNS FROM payments LIKE 'stripe_refund_id';
SHOW COLUMNS FROM payments LIKE 'duffel_payment_id';
SHOW COLUMNS FROM payments LIKE 'payment_type';
SHOW COLUMNS FROM payments LIKE 'duffel_payment_failure';
SHOW COLUMNS FROM payments LIKE 'auto_refund_reason';
SHOW COLUMNS FROM payments LIKE 'auto_refunded_at';
SHOW COLUMNS FROM payments LIKE 'updated_at';

-- booking_sessions
SHOW COLUMNS FROM booking_sessions LIKE 'device_ip';
SHOW COLUMNS FROM booking_sessions LIKE 'device_user_agent';
SHOW COLUMNS FROM booking_sessions LIKE 'total_amount';

-- flight_booking_documents table
SHOW TABLES LIKE 'flight_booking_documents';

-- ENUM check — should show 'awaiting_payment' and 'failed' in the Type column
SHOW COLUMNS FROM flight_bookings LIKE 'status';

-- ENUM check — should show 'refund_pending', 'refund_failed', 'refund_pending_manual' in the Type column
SHOW COLUMNS FROM payments LIKE 'status';
