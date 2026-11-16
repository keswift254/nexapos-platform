-- "Manual Till/Paybill": the customer pays the shop's own M-Pesa till or paybill, and the phone that gets Safaricom's
-- "payment received" SMS passes it on to the shop's payment inbox, so any till of the shop can check a code against it.
-- See app/Services/PaymentInbox.php. Every DATETIME here is UTC (written with UTC_TIMESTAMP()).

-- A phone allowed to pass SMS on. It holds a long random key; only the key's hash is stored.
CREATE TABLE IF NOT EXISTS payment_inbox_sources (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(60) NOT NULL,
    created_by_client_id INT NULL,
    created_by_user VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    window_started_at DATETIME NULL,
    window_count INT NOT NULL DEFAULT 0,
    UNIQUE INDEX uq_payment_inbox_source_token (token_hash),
    INDEX idx_payment_inbox_sources_shop (shop_id),
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per M-Pesa code a shop has seen: from the SMS (received_at set), from a till that used the code on a sale
-- (claimed_* set), or both. A code can be used on ONE sale only. A row with a claim and no SMS is a code a cashier
-- typed that has not been confirmed by a message (yet).
CREATE TABLE IF NOT EXISTS payment_inbox (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    mpesa_code VARCHAR(16) NOT NULL,
    source_id BIGINT NULL,
    amount_cents BIGINT NULL,
    payer_name VARCHAR(120) NULL,
    payer_phone VARCHAR(20) NULL,
    account_ref VARCHAR(60) NULL,
    paid_at_text VARCHAR(40) NULL,
    message TEXT NULL,
    received_at DATETIME NULL,
    claimed_sale_id CHAR(36) NULL,
    claimed_sale_number VARCHAR(60) NULL,
    claimed_amount_cents BIGINT NULL,
    claimed_by_client_id INT NULL,
    claimed_at DATETIME NULL,
    UNIQUE INDEX uq_payment_inbox_code (shop_id, mpesa_code),
    INDEX idx_payment_inbox_received (shop_id, received_at),
    INDEX idx_payment_inbox_claimed (shop_id, claimed_at),
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES payment_inbox_sources(id) ON DELETE SET NULL,
    FOREIGN KEY (claimed_by_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wrong passwords given when adding or removing a phone, to stop guessing (5 in 10 minutes locks the shop out for a while).
CREATE TABLE IF NOT EXISTS payment_inbox_auth_failures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    failed_at DATETIME NOT NULL,
    INDEX idx_payment_inbox_auth_failures (shop_id, failed_at),
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
