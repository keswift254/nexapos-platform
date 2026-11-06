-- The old-iPad "lite" till (plain JavaScript, no WebAssembly) registers as its own channel. It has the
-- browser restrictions (no settlement/payout data, no LAN relay) and, on top, every change it pushes is
-- vetted by SyncGuard before the shop's other devices can pull it. Existing rows are unaffected.
ALTER TABLE clients
    MODIFY COLUMN channel ENUM('native', 'browser', 'lite') NOT NULL DEFAULT 'native';
