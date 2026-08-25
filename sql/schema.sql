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
    business_name VARCHAR(160) NULL,
    settlement_type ENUM('bank', 'mpesa') NULL,
    bank_code VARCHAR(20) NULL,
    account_number VARCHAR(40) NULL,
    account_name VARCHAR(160) NULL,
    subaccount_code VARCHAR(60) NULL UNIQUE,
    percentage_charge DECIMAL(5,2) NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
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
    shop_id INT NOT NULL,
    -- Client-scoped, unlike settlement above: only means "still within
    -- its 10-min re-registration grace window" or "admin-disabled" - not
    -- "settled" (that's shops.subaccount_code being non-empty).
    status ENUM('pending_settlement', 'active', 'disabled') NOT NULL DEFAULT 'pending_settlement',
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
    FOREIGN KEY (shop_id) REFERENCES shops(id)
);
