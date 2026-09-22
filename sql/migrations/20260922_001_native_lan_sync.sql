-- Conditional ADD COLUMN via prepared-statement: an earlier deploy attempt
-- was killed off between this statement succeeding and the whole file being
-- recorded as applied (an unrelated database outage interrupted it
-- mid-migration), so a plain ADD COLUMN here fails with "Duplicate column
-- name" forever on the environment that actually hit that (confirmed in
-- production). Real MySQL - unlike MariaDB - has no ADD COLUMN IF NOT
-- EXISTS (confirmed the hard way: 1064 syntax error against Aiven's MySQL
-- 8.4.8), so this checks INFORMATION_SCHEMA and only builds the ALTER
-- statement when the column is actually missing - portable to any MySQL
-- version, and to MariaDB too.
SET @nexapos_lan_secret_missing = (
    SELECT COUNT(*) = 0 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shops' AND COLUMN_NAME = 'lan_sync_secret'
);
-- 'DO 0', not 'SELECT 1': a SELECT executed this way (via PDO::exec, not a
-- stored procedure) leaves a result set on the connection that the next
-- statement then fails against ("Cannot execute queries while other
-- unbuffered queries are active") - confirmed locally. DO evaluates and
-- discards its argument, producing no result set at all.
SET @nexapos_add_lan_secret = IF(
    @nexapos_lan_secret_missing,
    'ALTER TABLE shops ADD COLUMN lan_sync_secret BINARY(32) NULL AFTER id',
    'DO 0'
);
PREPARE nexapos_add_lan_secret_stmt FROM @nexapos_add_lan_secret;
EXECUTE nexapos_add_lan_secret_stmt;
DEALLOCATE PREPARE nexapos_add_lan_secret_stmt;

-- Older clients could retry an already-accepted revision after losing the
-- HTTP response. Keep the earliest copy before enforcing source/revision
-- idempotency for native LAN relays.
DELETE newer FROM sync_changes newer
JOIN sync_changes older
  ON older.shop_id = newer.shop_id
 AND older.device_id = newer.device_id
 AND older.local_rev = newer.local_rev
 AND older.id < newer.id;

ALTER TABLE sync_changes
    ADD UNIQUE KEY uq_sync_source_revision (shop_id, device_id, local_rev);
