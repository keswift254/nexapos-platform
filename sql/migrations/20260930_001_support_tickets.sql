CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    opened_by_client_id INT NOT NULL,
    subject VARCHAR(160) NOT NULL,
    status ENUM('open','pending','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_support_tickets_shop_updated (shop_id, updated_at),
    FOREIGN KEY (shop_id) REFERENCES shops(id),
    FOREIGN KEY (opened_by_client_id) REFERENCES clients(id)
);

CREATE TABLE IF NOT EXISTS support_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT NOT NULL,
    client_id INT NULL,
    sender ENUM('customer','support') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_messages_ticket (ticket_id, id),
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id)
);
