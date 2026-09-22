-- On 2026-09-22, production's sync_changes table (423 MB, ~552,900 rows) was
-- compacted to keep only the newest revision of every synced item plus the
-- last 7 days of full history (see 20260922_001_native_lan_sync.sql's own
-- history for why - a DELETE-based cleanup exhausted this database's 1 GB
-- free-tier disk and caused MySQL to restart mid-cleanup). The swap renamed
-- the original table aside (kept for rollback verification) and put the
-- compacted copy in its place as sync_changes. The rollback copy has since
-- been verified (row counts, zero missing current/recent revisions, zero
-- payload mismatches) and is no longer needed - keeping a ~423 MB table
-- around on a database with a 1 GB ceiling, already sitting near it once,
-- is a real risk on its own.
--
-- The exact backup name is not recorded anywhere in this repo (the rename
-- was done directly against the database, not through a migration file),
-- so this discovers it rather than guessing: any table whose name starts
-- with "sync_changes" but is not the real, in-use "sync_changes" table
-- itself. On an environment with no such leftover table (a fresh install,
-- or one where this already ran), this does nothing.
SET @nexapos_sync_changes_backup = (
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME LIKE 'sync\_changes%'
      AND TABLE_NAME <> 'sync_changes'
    ORDER BY TABLE_NAME
    LIMIT 1
);
SET @nexapos_drop_sync_changes_backup = IF(
    @nexapos_sync_changes_backup IS NOT NULL,
    CONCAT('DROP TABLE `', @nexapos_sync_changes_backup, '`'),
    'DO 0'
);
PREPARE nexapos_drop_sync_changes_backup_stmt FROM @nexapos_drop_sync_changes_backup;
EXECUTE nexapos_drop_sync_changes_backup_stmt;
DEALLOCATE PREPARE nexapos_drop_sync_changes_backup_stmt;
