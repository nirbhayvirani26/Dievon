-- ============================================================
--  Dievon – Per-product tax settings
--  use_category_tax   : 1 = inherit HSN/GST from the category's
--                        defaults (default), 0 = this product
--                        carries its own HSN code & GST rate
--  price_includes_gst : 1 = selling price already includes GST
--                        (standard Indian retail, default),
--                        0 = GST is added on top of the price
-- ============================================================

ALTER TABLE `products`
    ADD COLUMN `use_category_tax` TINYINT(1) NOT NULL DEFAULT 1 AFTER `gst_rate`,
    ADD COLUMN `price_includes_gst` TINYINT(1) NOT NULL DEFAULT 1 AFTER `use_category_tax`;
