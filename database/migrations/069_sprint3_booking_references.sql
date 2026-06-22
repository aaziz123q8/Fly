-- Sprint 3: Store Duffel order.booking_references[] (per-airline PNR array)
-- MySQL 8.0 and MariaDB compatible via INFORMATION_SCHEMA stored procedure.
--
-- booking_references is an array of objects from Duffel:
--   [{"id":"...", "value":"ABCDEF", "source":"airline", "airline_iata_code":"EK"}, ...]
-- Stored as JSON. The single duffel_booking_reference column (from migration 067)
-- remains the primary airline PNR for display. booking_references covers
-- multi-carrier itineraries where each operating airline issues its own PNR.

DROP PROCEDURE IF EXISTS sp_sprint3_booking_references;

DELIMITER $$

CREATE PROCEDURE sp_sprint3_booking_references()
BEGIN

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'booking_references'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN booking_references JSON NULL
            COMMENT 'order.booking_references[] from Duffel — per-airline PNR array';
    END IF;

END$$

DELIMITER ;

CALL sp_sprint3_booking_references();

DROP PROCEDURE IF EXISTS sp_sprint3_booking_references;
