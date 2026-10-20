-- An admin reply is read only after the shop opens the conversation in NexaPOS.
ALTER TABLE support_messages
    ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER reply_to_id;
