-- Migration 079: extend enums that application code already writes but the
-- live schema rejected. Each of these values is produced by existing code
-- paths; without them the corresponding INSERT/UPDATE fails (strict mode) or
-- silently truncates, breaking wallet checkout, auto-refunds, cancellation
-- bookkeeping, support-ticket status, and admin booking status changes.
--
-- Adding values to an ENUM is backward-compatible (existing rows/queries are
-- unaffected), so this migration is safe to run on production.

-- payments.payment_method — code writes 'wallet' (full wallet checkout),
-- 'mixed' (wallet + card) and 'duffel_card' (Duffel card payments).
ALTER TABLE `payments`
  MODIFY COLUMN `payment_method`
  ENUM('stripe','duffel_balance','wallet','mixed','duffel_card') NOT NULL;

-- payments.status — code writes 'refunded_to_wallet' (cancellation refunded to
-- internal wallet), 'auto_refunded' (RateHawk auto-refund) and
-- 'cancellation_pending_refund' (refund attempt failed, awaiting manual action).
ALTER TABLE `payments`
  MODIFY COLUMN `status`
  ENUM('pending','succeeded','failed','refunded','cancelled',
       'refund_pending','refund_failed','refund_pending_manual',
       'refunded_to_wallet','auto_refunded','cancellation_pending_refund')
  NOT NULL DEFAULT 'pending' COMMENT 'Payment lifecycle status';

-- flight_bookings.status — cancellation flow writes
-- 'cancellation_pending_refund' / 'cancellation_pending_manual_refund' when a
-- refund could not be completed automatically; admin panel writes 'completed'.
ALTER TABLE `flight_bookings`
  MODIFY COLUMN `status`
  ENUM('pending','confirmed','cancelled','changed','awaiting_payment','failed',
       'cancellation_pending_refund','cancellation_pending_manual_refund','completed')
  NOT NULL DEFAULT 'pending' COMMENT 'Booking lifecycle status';

-- hotel_bookings.status — admin panel writes 'completed'.
ALTER TABLE `hotel_bookings`
  MODIFY COLUMN `status`
  ENUM('pending','confirmed','cancelled','no_show','completed')
  NOT NULL DEFAULT 'pending';

-- support_tickets.status — admin UI and AdminSupportController use 'in_progress'.
ALTER TABLE `support_tickets`
  MODIFY COLUMN `status`
  ENUM('open','pending','in_progress','resolved','closed')
  NOT NULL DEFAULT 'open';
