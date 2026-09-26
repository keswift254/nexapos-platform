-- The state of a shop's NexaPOS license, reported by its main device, so devices
-- that joined the shop can follow it over the internet as well as over the LAN.
-- Conditional ADD COLUMN (see 20260922_001_native_lan_sync.sql for why it is
-- built this way: portable across MySQL and MariaDB, and safe to re-run).
SET @nexapos_license_state_missing = (
    SELECT COUNT(*) = 0 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shops' AND COLUMN_NAME = 'license_state'
);
SET @nexapos_add_license_state = IF(
    @nexapos_license_state_missing,
    'ALTER TABLE shops ADD COLUMN license_state VARCHAR(16) NULL, ADD COLUMN license_valid_until DATETIME NULL, ADD COLUMN license_checked_at BIGINT NULL',
    'DO 0'
);
PREPARE nexapos_add_license_state_stmt FROM @nexapos_add_license_state;
EXECUTE nexapos_add_license_state_stmt;
DEALLOCATE PREPARE nexapos_add_license_state_stmt;
