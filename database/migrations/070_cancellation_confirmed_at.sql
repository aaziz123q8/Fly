-- 070: Store Duffel order_cancellation.confirmed_at
-- Uses INFORMATION_SCHEMA (MySQL 8.0-compatible, no MariaDB-only syntax).

DROP PROCEDURE IF EXISTS sp_add_cancellation_confirmed_at;

DELIMITER $$

CREATE PROCEDURE sp_add_cancellation_confirmed_at()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'flight_bookings'
          AND COLUMN_NAME  = 'cancellation_confirmed_at'
    ) THEN
        ALTER TABLE flight_bookings
            ADD COLUMN cancellation_confirmed_at DATETIME NULL
            COMMENT 'Duffel order_cancellation.confirmed_at — when the cancellation was confirmed by airline';
    END IF;
END$$

DELIMITER ;

CALL sp_add_cancellation_confirmed_at();
DROP PROCEDURE IF EXISTS sp_add_cancellation_confirmed_at;
