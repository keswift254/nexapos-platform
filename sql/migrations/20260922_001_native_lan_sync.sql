ALTER TABLE shops
    ADD COLUMN lan_sync_secret BINARY(32) NULL AFTER id;

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
