-- 071: Add hotel_name and provider_hotel_id to hotel_bookings
-- Uses INFORMATION_SCHEMA (MySQL 8.0-compatible, no MariaDB-only syntax).

DROP PROCEDURE IF EXISTS sp_add_hotel_booking_name_columns;

DELIMITER $$

CREATE PROCEDURE sp_add_hotel_booking_name_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'hotel_bookings'
          AND COLUMN_NAME  = 'hotel_name'
    ) THEN
        ALTER TABLE hotel_bookings
            ADD COLUMN hotel_name VARCHAR(255) NULL AFTER hotel_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'hotel_bookings'
          AND COLUMN_NAME  = 'provider_hotel_id'
    ) THEN
        ALTER TABLE hotel_bookings
            ADD COLUMN provider_hotel_id VARCHAR(150) NULL AFTER hotel_name;
    END IF;
END$$

DELIMITER ;

CALL sp_add_hotel_booking_name_columns();
DROP PROCEDURE IF EXISTS sp_add_hotel_booking_name_columns;
