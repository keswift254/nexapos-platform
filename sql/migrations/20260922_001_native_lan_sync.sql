-- IF NOT EXISTS: an earlier deploy attempt was killed off between this
-- statement succeeding and the whole file being recorded as applied (an
-- unrelated database outage interrupted it mid-migration), so a plain
-- ADD COLUMN here would fail with "Duplicate column name" forever on the
-- environment that actually hit that - this makes re-running the file safe.
ALTER TABLE shops
    ADD COLUMN IF NOT EXISTS lan_sync_secret BINARY(32) NULL AFTER id;

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
