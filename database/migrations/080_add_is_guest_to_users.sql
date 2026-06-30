-- Migration 080: guest checkout support.
-- Flags users created during guest checkout. On their first confirmed booking
-- the account is upgraded to a real one (password generated + emailed) and the
-- flag is cleared. See FlightController::maybeCreateGuestAccount.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_guest` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Account created via guest checkout, not yet upgraded';
