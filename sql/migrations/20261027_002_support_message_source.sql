-- Which network address (a keyed hash, never the address itself) a website visitor's message
-- came from, so the public chat can limit how much any one address may send.
ALTER TABLE support_messages
    ADD COLUMN source_ip_hash CHAR(64) NULL AFTER read_at,
    ADD INDEX idx_support_messages_source (source_ip_hash, created_at);
