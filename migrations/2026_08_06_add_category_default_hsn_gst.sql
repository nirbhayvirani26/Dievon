-- ============================================================
--  Dievon – Per-category tax defaults
--  Products inherit their HSN code and GST rate from the
--  category they are filed under (set in admin → Categories →
--  the collection's edit panel). The product form shows them
--  read-only and writes them onto the product on save.
-- ============================================================

ALTER TABLE `categories`
    ADD COLUMN `default_hsn_code` VARCHAR(50) DEFAULT NULL AFTER `gender`,
    ADD COLUMN `default_gst_rate` DECIMAL(5,2) DEFAULT 0.00 AFTER `default_hsn_code`;
