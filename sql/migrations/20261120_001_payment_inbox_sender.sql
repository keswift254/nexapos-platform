-- When a phone first passed on an SMS together with who sent it (its Shortcut's "Sender"). From then on that phone's
-- messages must come with a sender, and one from a person's number or email is never a payment (PaymentInbox::ingest).
ALTER TABLE payment_inbox_sources
    ADD COLUMN sender_seen_at DATETIME NULL;
