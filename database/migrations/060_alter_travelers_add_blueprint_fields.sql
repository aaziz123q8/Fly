-- Safe migration: add all blueprint-required fields to travelers table.
-- Uses stored procedures to avoid errors if columns already exist.

DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col(p_table VARCHAR(64), p_col VARCHAR(64), p_def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL _add_col('travelers', 'country',          "VARCHAR(80) NULL AFTER nationality");
CALL _add_col('travelers', 'phone',             "VARCHAR(30) NULL AFTER phone_number");
CALL _add_col('travelers', 'document_type',     "ENUM('passport','civil_id','national_id') NULL DEFAULT 'passport' AFTER email");
CALL _add_col('travelers', 'document_number',   "VARCHAR(40) NULL AFTER document_type");
CALL _add_col('travelers', 'issue_date',        "DATE NULL AFTER document_number");
CALL _add_col('travelers', 'expiry_date',       "DATE NULL AFTER issue_date");
CALL _add_col('travelers', 'document_issue',    "DATE NULL AFTER expiry_date");
CALL _add_col('travelers', 'document_expiry',   "DATE NULL AFTER document_issue");
CALL _add_col('travelers', 'document_country',  "VARCHAR(80) NULL AFTER document_expiry");

DROP PROCEDURE IF EXISTS _add_col;
