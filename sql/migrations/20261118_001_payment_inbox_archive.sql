-- "Archive" a payment the shop phone passed on: it is no longer suggested at checkout on any till of the shop, but stays in
-- the list of payments received (Settings > M-Pesa Till Phone), where it can be suggested again. Its code can still be typed.
ALTER TABLE payment_inbox
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by_client_id INT NULL;
