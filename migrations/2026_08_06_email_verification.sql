-- ============================================================
--  Dievon – Email verification (guest-order linking gate)
--
--  Registration used to claim every guest order placed with the typed
--  email (and phone, if given) with no proof the address belonged to
--  the person typing it. An attacker who knew a guest's email could
--  register with it and instantly take over that guest's order
--  history, name, address and invoices.
--
--  New registrations now start UNVERIFIED: guest orders are linked only
--  after the customer clicks a link emailed to the address. Phone
--  numbers are never used to link or reveal orders (a phone is not
--  proof of identity).
--
--  config/db.php applies these columns idempotently on every request,
--  so running this file manually is only needed to keep the migration
--  history complete. The grandfathering UPDATE below marks every
--  account that existed before verification as verified, so nobody
--  loses access to the history they already had.
-- ============================================================

ALTER TABLE `customers`
    ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL,
    ADD COLUMN `email_verify_token` VARCHAR(64) DEFAULT NULL,
    ADD COLUMN `email_verify_token_expires` DATETIME DEFAULT NULL,
    ADD COLUMN `email_verify_sent_at` DATETIME DEFAULT NULL;

-- Existing accounts registered before verification existed keep working.
UPDATE `customers` SET `email_verified_at` = `created_at` WHERE `email_verified_at` IS NULL;
