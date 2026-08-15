-- restore store_countries + product_country_prices
UPDATE store_countries SET is_enabled=1, cod_allowed=1, shipping_fee=99.00, free_shipping_min=2000.00 WHERE id=1;
UPDATE store_countries SET is_enabled=0, cod_allowed=0, shipping_fee=0.00, free_shipping_min=400.00 WHERE id=2;
UPDATE store_countries SET is_enabled=0, cod_allowed=0, shipping_fee=30.00, free_shipping_min=300.00 WHERE id=3;
UPDATE store_countries SET is_enabled=0, cod_allowed=0, shipping_fee=90.00, free_shipping_min=900.00 WHERE id=4;
DELETE FROM product_country_prices;
INSERT INTO product_country_prices (id,product_id,country_code,price,sale_price,updated_at) VALUES ('4','43','GB','50.00',NULL,'2026-08-10 21:51:52');
INSERT INTO product_country_prices (id,product_id,country_code,price,sale_price,updated_at) VALUES ('5','43','US','70.00',NULL,'2026-08-10 21:51:52');
