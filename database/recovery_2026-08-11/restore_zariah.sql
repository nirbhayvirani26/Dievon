-- restore Zariah (product 2)
UPDATE product_variants SET stock_qty=0 WHERE id=6;
UPDATE product_variants SET stock_qty=13 WHERE id=7;
UPDATE product_variants SET stock_qty=13 WHERE id=8;
UPDATE products SET total_stock=33, sold_online=7 WHERE id=2;
