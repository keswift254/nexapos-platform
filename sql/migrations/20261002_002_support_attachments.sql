CREATE TABLE IF NOT EXISTS support_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT NOT NULL,
    message_id BIGINT NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    INDEX idx_support_attachment_ticket (ticket_id),
    INDEX idx_support_attachment_message (message_id),
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES support_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
