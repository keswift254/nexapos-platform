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
--
-- Do not use a self-join over sync_changes here. Production reached this
-- statement without a supporting (shop_id, device_id, local_rev) index, so
-- the self-join could run for long enough to hold the migration advisory lock
-- while every Render health probe timed out. Stage only duplicate keys in a
-- temporary table, index that small set, then make one pass over sync_changes.
-- Define the primary key at creation time: Aiven's MySQL requires every
-- table, including temporary tables, to have a primary key immediately.
-- Temporary tables are connection-scoped, so this remains safe after a
-- process is killed midway through the migration and the file is retried.
DROP TEMPORARY TABLE IF EXISTS nexapos_duplicate_sync_revisions;
CREATE TEMPORARY TABLE nexapos_duplicate_sync_revisions (
    shop_id INT NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    local_rev INT NOT NULL,
    keep_id BIGINT NOT NULL,
    PRIMARY KEY (shop_id, device_id, local_rev)
) ENGINE=InnoDB;
INSERT INTO nexapos_duplicate_sync_revisions (shop_id, device_id, local_rev, keep_id)
SELECT shop_id, device_id, local_rev, MIN(id)
FROM sync_changes
GROUP BY shop_id, device_id, local_rev
HAVING COUNT(*) > 1;

DELETE duplicate_row FROM sync_changes duplicate_row
JOIN nexapos_duplicate_sync_revisions duplicate_key
  ON duplicate_key.shop_id = duplicate_row.shop_id
 AND duplicate_key.device_id = duplicate_row.device_id
 AND duplicate_key.local_rev = duplicate_row.local_rev
WHERE duplicate_row.id <> duplicate_key.keep_id;

DROP TEMPORARY TABLE nexapos_duplicate_sync_revisions;

ALTER TABLE sync_changes
    ADD UNIQUE KEY uq_sync_source_revision (shop_id, device_id, local_rev);
