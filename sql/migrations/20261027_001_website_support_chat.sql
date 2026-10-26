-- Support chat from the public website: a visitor has no shop and no device, so a ticket no
-- longer always belongs to one. App tickets keep shop_id and opened_by_client_id set (every
-- shop-scoped route still filters on shop_id, so a website ticket can never show up in an app).
-- visitor_token_hash is the SHA-256 of the secret the visitor's browser holds for this chat.
ALTER TABLE support_tickets
    MODIFY shop_id INT NULL,
    MODIFY opened_by_client_id INT NULL,
    ADD COLUMN channel ENUM('app','website') NOT NULL DEFAULT 'app' AFTER status,
    ADD COLUMN visitor_name VARCHAR(80) NULL AFTER channel,
    ADD COLUMN visitor_token_hash CHAR(64) NULL AFTER visitor_name,
    ADD UNIQUE INDEX uq_support_visitor_token (visitor_token_hash);
