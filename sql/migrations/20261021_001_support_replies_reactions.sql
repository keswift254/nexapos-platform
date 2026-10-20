-- Support chat: a message can quote an earlier one (reply_to_id), and either side can
-- react to a message with one emoji (like iMessage tapbacks). Keep sql/schema.sql in sync.
ALTER TABLE support_messages
    ADD COLUMN reply_to_id BIGINT NULL AFTER body;

CREATE TABLE IF NOT EXISTS support_reactions (
    message_id BIGINT NOT NULL,
    sender ENUM('customer','support') NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, sender),
    FOREIGN KEY (message_id) REFERENCES support_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
