-- Migration 081: seed the providers table.
-- hotel_bookings.provider_id and flight_bookings.provider_id are FOREIGN KEYs to
-- providers(id). The table was never seeded, so creating a hotel booking
-- (provider_id = 2 / RateHawk) fails the FK and the whole booking INSERT is
-- rolled back. Seed the two providers idempotently.
INSERT INTO `providers` (`id`, `name`, `type`, `is_active`) VALUES
  (1, 'Duffel',   'flight', 1),
  (2, 'RateHawk', 'hotel',  1)
ON DUPLICATE KEY UPDATE `type` = VALUES(`type`), `is_active` = VALUES(`is_active`);
