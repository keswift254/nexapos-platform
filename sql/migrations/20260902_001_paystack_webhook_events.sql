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
