ALTER TABLE support_tickets
    ADD COLUMN customer_email VARCHAR(254) NULL AFTER subject;
