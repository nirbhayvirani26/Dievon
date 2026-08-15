-- ============================================================
--  Dievon – COD rules + order code hardening
--
--  1. orders.cod_fee
--     Cash on Delivery orders are charged a handling fee (Store Setting
--     `cod_fee`, default ₹49). The amount is stored on the order so
--     invoices, order confirmation, the tracking page and the admin
--     panel can show it as its own line.
--
--  2. Order codes
--     No schema change: codes grew from 6 to 8 random digits and
--     generateOrderCode() now checks the orders table before returning,
--     with a duplicate-key retry at the INSERT (config/config.php).
--
--  config/db.php applies the column idempotently on every request, so
--  running this file manually is only needed to keep the migration
--  history complete.
-- ============================================================

ALTER TABLE `orders`
    ADD COLUMN `cod_fee` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `delivery_charge`;
