-- M-Pesa codes of payments the shop's phone itself MADE (it paid a till or a paybill, or sent money), kept for 7 days.
-- An M-PESA Jumbe alert carries no word that says which way the money went, so a Jumbe alert whose code this phone paid
-- out is never taken as a customer's payment - whichever of the two messages arrives first. Only the code is kept.
CREATE TABLE IF NOT EXISTS payment_inbox_spent (
    shop_id INT NOT NULL,
    mpesa_code VARCHAR(16) NOT NULL,
    seen_at DATETIME NOT NULL,
    PRIMARY KEY (shop_id, mpesa_code),
    INDEX idx_payment_inbox_spent_seen (seen_at),
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
