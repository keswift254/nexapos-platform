CREATE DATABASE IF NOT EXISTS nexapos_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nexapos_platform;

-- Settlement (Paystack subaccount) lives here, not on clients - a shop
-- has exactly one bank account regardless of which device fills the
-- settlement form in, so every device sharing this shop sees the same
-- settled status immediately (see save_settlement_details/client_status
-- in public/index.php, and Auth::client()'s JOIN). A device that
-- creates its OWN settlement independently of its shop's would silently
-- split one shop's sales across two bank accounts.
CREATE TABLE IF NOT EXISTS shops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Server-issued shared secret for authenticated native-only LAN sync.
    -- Browsers are never returned this value; native clients keep it in
    -- OS secure storage and use it only as an AES-256-GCM key.
    lan_sync_secret BINARY(32) NULL,
    business_name VARCHAR(160) NULL,
    settlement_type ENUM('bank', 'mpesa') NULL,
    bank_code VARCHAR(20) NULL,
    account_number VARCHAR(40) NULL,
    account_name VARCHAR(160) NULL,
    subaccount_code VARCHAR(60) NULL UNIQUE,
    percentage_charge DECIMAL(5,2) NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    -- IntaSend WORKING wallet id for this shop's collected funds - unlike
    -- subaccount_code above, created lazily on first IntaSend collection
    -- attempt (see ensureIntaSendWallet in public/index.php), not gated
    -- behind a settlement form: collecting into a wallet needs no bank
    -- details, only disbursing OUT of it does, and that step doesn't
    -- exist yet.
    intasend_wallet_id VARCHAR(40) NULL,
    -- The state of the shop's NexaPOS license, as its main (owner) device last
    -- reported it (report_shop_license) - or 'revoked' when the license server
    -- revoked that device's license. NULL = never reported. Joined devices read it
    -- from client_status so they follow the main device's license even when they
    -- are not on its network. license_valid_until is UTC (NULL with 'active' =
    -- never expires); license_checked_at is the moment the license server last
    -- vouched for that state, in ms since 1970 - the newest one wins.
    license_state VARCHAR(16) NULL,
    license_valid_until DATETIME NULL,
    license_checked_at BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL UNIQUE,
    device_label VARCHAR(160) NOT NULL,
    api_key_hash CHAR(64) NOT NULL UNIQUE,
    -- Proof-of-possession for the 10-min re-registration grace window
    -- below: device_id alone used to be sufficient to rotate an
    -- existing device's api_key, which meant anyone who merely learned
    -- a freshly-created device's device_id (not otherwise secret - it's
    -- a plain client-generated UUID) could hijack it. The client now
    -- generates this alongside device_id, on first registration only,
    -- and must present the same value again to use the grace window -
    -- an attacker who only knows device_id can no longer complete a
    -- re-registration. Never needed again once the grace window closes.
    registration_secret_hash CHAR(64) NULL,
    -- TRUE only for the device that originally created this shop (see
    -- register_device: set on the brand-new-shop INSERT path, never
    -- touched on the re-registration UPDATE path). Every device joined
    -- later via join_shop's invite code is an equal peer for sync
    -- purposes but NOT for settlement - see save_settlement_details,
    -- which now requires this. Without it, any device that ever joined
    -- a shop (including via a leaked/shared invite code) could redirect
    -- where that shop's real money gets paid out, with zero owner
    -- notification - flagged in a security review, fixed here.
    -- Deliberately not a bigger "roles" system: this is the one
    -- specific action that needed gating, not a general permissions
    -- model the product doesn't otherwise have.
    is_owner TINYINT(1) NOT NULL DEFAULT 0,
    -- Stamped once at register_device time from what the client itself
    -- claims to be, never touched again (same immutable-after-creation
    -- pattern as is_owner above) - the browser build always sends
    -- 'browser', native always sends 'native' or omits this entirely
    -- (defaults to 'native'). Settlement/payout endpoints refuse a
    -- 'browser' channel outright regardless of is_owner or role - see
    -- save_settlement_details/client_status/list_banks in public/
    -- index.php. Defense-in-depth, not cryptographically unbeatable: a
    -- browser build's client-side code is inherently inspectable/
    -- patchable in a way a compiled native binary isn't, so this stops
    -- casual/accidental exposure, not a determined attacker willing to
    -- rebuild the web client - an accepted, deliberate trade-off, see
    -- the browser-POS plan.
    channel ENUM('native', 'browser') NOT NULL DEFAULT 'native',
    shop_id INT NOT NULL,
    -- Client-scoped, unlike settlement above: only means "still within
    -- its 10-min re-registration grace window" or "admin-disabled" - not
    -- "settled" (that's shops.subaccount_code being non-empty).
    status ENUM('pending_settlement', 'active', 'disabled') NOT NULL DEFAULT 'pending_settlement',
    -- Stamped by Auth::requireClient on every authenticated call this
    -- device makes - not a dedicated heartbeat, just piggybacking on
    -- calls that already happen (sync runs every 2 minutes while the
    -- app is open). Powers the admin device dashboard's online/offline
    -- column: "online" is a client-side threshold against this, not a
    -- stored boolean, so the definition of "recent" can change without
    -- a migration.
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (shop_id),
    FOREIGN KEY (shop_id) REFERENCES shops(id)
);

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    reference VARCHAR(60) NOT NULL UNIQUE,
    amount_minor INT NOT NULL,
    currency VARCHAR(10) NOT NULL,
    subaccount_code VARCHAR(60) NOT NULL,
    status ENUM('initialized', 'verified_success', 'verified_failed') NOT NULL DEFAULT 'initialized',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    INDEX (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

-- Idempotency ledger for Paystack callbacks. Only hashes and the small
-- amount of routing metadata needed for reconciliation are retained;
-- full webhook payloads can contain customer/payment details and are
-- deliberately not copied into this database.
CREATE TABLE IF NOT EXISTS paystack_webhook_events (
    event_key VARCHAR(190) NOT NULL PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    reference VARCHAR(60) NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    status ENUM('received', 'processing', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'received',
    attempts INT NOT NULL DEFAULT 1,
    last_error VARCHAR(500) NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX (reference),
    INDEX (status, updated_at)
);

-- IntaSend's sibling of transactions above - a separate table (not a
-- shared one keyed by provider) because subaccount_code is NOT NULL
-- there and has no IntaSend equivalent; wallet_id plays that role here.
CREATE TABLE IF NOT EXISTS intasend_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    reference VARCHAR(60) NOT NULL UNIQUE,
    invoice_id VARCHAR(60) NOT NULL,
    amount_minor INT NOT NULL,
    currency VARCHAR(10) NOT NULL,
    wallet_id VARCHAR(40) NOT NULL,
    status ENUM('initialized', 'verified_success', 'verified_failed') NOT NULL DEFAULT 'initialized',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    INDEX (client_id),
    INDEX (invoice_id),
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

-- IntaSend's sibling of paystack_webhook_events above - same idempotency
-- shape, keyed on invoice_id + state instead of Paystack's event id.
CREATE TABLE IF NOT EXISTS intasend_webhook_events (
    event_key VARCHAR(190) NOT NULL PRIMARY KEY,
    state VARCHAR(40) NOT NULL,
    reference VARCHAR(60) NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    status ENUM('received', 'processing', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'received',
    attempts INT NOT NULL DEFAULT 1,
    last_error VARCHAR(500) NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX (reference),
    INDEX (status, updated_at)
);

-- Phase 2 sync: a shop is the grouping that lets multiple devices (phones
-- + the counter PC) share one dataset. shop_invites is how a second
-- device joins an existing shop (a time-limited code, see join_shop in
-- public/index.php) rather than each device silently becoming its own
-- isolated shop of one.
CREATE TABLE IF NOT EXISTS shop_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    code VARCHAR(8) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (shop_id),
    FOREIGN KEY (shop_id) REFERENCES shops(id)
);

-- Tracks FAILED join_shop guesses only (successes never insert here) -
-- rate limits brute-forcing InviteCode's ~40-bit codes (see join_shop's
-- own comment). Checked by both client_id and ip_address, since
-- register_device is free/unlimited so client_id alone resets on
-- request. Unbounded growth accepted at this project's real volume,
-- same tradeoff already made for sync_changes below.
CREATE TABLE IF NOT EXISTS join_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (client_id, attempted_at),
    INDEX (ip_address, attempted_at)
);

-- Generic append-only relay for every SyncedColumns table on the phone
-- (products, sales, expenses, ...) - deliberately NOT an upsert keyed on
-- (shop_id, table_name, row_id): MySQL's ON DUPLICATE KEY UPDATE would
-- not advance a row's existing auto-increment id when it changes again,
-- which would let a device that already pulled past that id silently
-- miss the update. Append-only keeps `id` a correct, ever-advancing
-- cursor for pull_changes at the cost of unbounded growth, acceptable
-- at a small shop's real volume.
CREATE TABLE IF NOT EXISTS sync_changes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    table_name VARCHAR(40) NOT NULL,
    row_id VARCHAR(40) NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    local_rev INT NOT NULL,
    -- Opaque passthrough of Dart's ISO8601 string (6-digit microsecond
    -- precision) - never TIMESTAMP/DATETIME, which would silently
    -- truncate the precision the phone's last-write-wins compare relies on.
    updated_at VARCHAR(40) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (shop_id, id),
    INDEX (shop_id, table_name, row_id),
    UNIQUE KEY uq_sync_source_revision (shop_id, device_id, local_rev),
    FOREIGN KEY (shop_id) REFERENCES shops(id)
);

CREATE TABLE IF NOT EXISTS sync_snapshots (
    id CHAR(32) PRIMARY KEY,
    client_id INT NOT NULL,
    shop_id INT NOT NULL,
    high_water BIGINT NOT NULL,
    row_count INT NOT NULL DEFAULT 0,
    -- NULL until SyncSnapshot::start()'s (possibly slow) row-population
    -- pass finishes - see this column's own migration comment. A
    -- snapshot row existing at all no longer means it's safe to page
    -- through; only a non-null ready_at does.
    ready_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    INDEX (client_id, expires_at),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (shop_id) REFERENCES shops(id)
);

CREATE TABLE IF NOT EXISTS sync_snapshot_rows (
    snapshot_id CHAR(32) NOT NULL,
    change_id BIGINT NOT NULL,
    PRIMARY KEY (snapshot_id, change_id),
    FOREIGN KEY (snapshot_id) REFERENCES sync_snapshots(id) ON DELETE CASCADE,
    FOREIGN KEY (change_id) REFERENCES sync_changes(id)
);
