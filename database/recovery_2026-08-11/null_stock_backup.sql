-- variants that had NULL stock_qty, 2026-08-10 22:04
UPDATE product_variants SET stock_qty = NULL WHERE id = 68;
UPDATE product_variants SET stock_qty = NULL WHERE id = 69;
