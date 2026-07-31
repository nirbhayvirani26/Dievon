-- ============================================================
--  Support Tickets: photo attachment + advisor reply
--
--  Before this, customer_tickets could only hold the customer's original
--  message and a status enum. There was no way to attach a photo of a product
--  issue, and no way for an advisor to write back — admin could only flip the
--  status, which the customer had to log in to notice.
-- ============================================================

ALTER TABLE customer_tickets
    ADD COLUMN attachment VARCHAR(255) NULL DEFAULT NULL AFTER message,
    ADD COLUMN admin_reply TEXT NULL DEFAULT NULL AFTER status,
    ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL AFTER admin_reply,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL
        ON UPDATE CURRENT_TIMESTAMP AFTER replied_at;
