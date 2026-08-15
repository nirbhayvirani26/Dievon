-- ============================================================
--  Dievon - TEST men's products for the size-guide preview
--
--  Two products (Shirt + Trousers) so the full men's size
--  guide - garment table plus the chest body ladder - can be
--  seen on the live storefront before the launch.
--
--  NOTE: these are TEST products with GENERIC placeholder
--  names/prices/photos. Replace them (or delete and re-add
--  via Admin -> Products) before real menswear goes live.
--
--  Idempotent-ish: INSERTs are guarded by name so re-running
--  never duplicates. Apply via phpMyAdmin on Hostinger.
-- ============================================================

INSERT INTO products
    (name, description, price, mrp_price, cost_price, category, category_id, emoji, color, badge,
     available, track_stock, total_stock, stock_qty, brand, fabric, sleeve, neck, pattern, occasion,
     image, image_alt, tags, meta_title, is_deleted, use_category_tax, price_includes_gst)
SELECT 'Oxford Structured Shirt — Navy',
       'A crisp, structured cotton shirt in deep navy. Regular fit with a classic point collar and single cuff. Cut to a clean, office-ready silhouette — team it with the matching Dievon trousers or a pair of chinos.',
       1499.00, 2299.00, 700.00, 'Shirts', 17, '👔', 'Navy', 'New',
       1, 1, 40, 40, 'Dievon', 'Cotton Poplin', 'Full Sleeve', 'Point Collar', 'Solid', 'Formal',
       NULL, 'Oxford Structured Shirt in navy — men''s formal cotton shirt by Dievon',
       'men, shirt, formal, navy, cotton', 'Oxford Structured Shirt — Buy Men''s Formal Shirts Online | Dievon', 0, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Oxford Structured Shirt — Navy');

INSERT INTO products
    (name, description, price, mrp_price, cost_price, category, category_id, emoji, color, badge,
     available, track_stock, total_stock, stock_qty, brand, fabric, sleeve, neck, pattern, occasion,
     image, image_alt, tags, meta_title, is_deleted, use_category_tax, price_includes_gst)
SELECT 'Tailored Pleat Trousers — Charcoal',
       'Sharp, tailored trousers in charcoal with a single pleat and straight leg. Mid-rise with a hook-and-bar fastening and side adjusters — the office staple that moves from desk to dinner.',
       1899.00, 2799.00, 900.00, 'Trousers', 18, '👖', 'Charcoal', 'New',
       1, 1, 36, 36, 'Dievon', 'Polywool Blend', NULL, NULL, 'Solid', 'Formal',
       NULL, 'Tailored Pleat Trousers in charcoal — men''s formal trousers by Dievon',
       'men, trousers, formal, charcoal, pleat', 'Tailored Pleat Trousers — Buy Men''s Formal Trousers Online | Dievon', 0, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Tailored Pleat Trousers — Charcoal');

-- Size variants (only for products that don't have any yet).
INSERT INTO product_variants (product_id, name, price, sort_order, available, size_code, stock_qty)
SELECT p.id, CONCAT('Size: ', v.size_code), v.price, v.sort_order, 1, v.size_code, v.qty
FROM (
  SELECT 'Oxford Structured Shirt — Navy' AS pname, 1499.00 AS price,
         'S' AS size_code, 0 AS sort_order, 8 AS qty UNION ALL
  SELECT 'Oxford Structured Shirt — Navy', 1499.00, 'M', 1, 12 UNION ALL
  SELECT 'Oxford Structured Shirt — Navy', 1499.00, 'L', 2, 10 UNION ALL
  SELECT 'Oxford Structured Shirt — Navy', 1499.00, 'XL', 3, 6 UNION ALL
  SELECT 'Oxford Structured Shirt — Navy', 1499.00, '2XL', 4, 4 UNION ALL
  SELECT 'Tailored Pleat Trousers — Charcoal', 1899.00, 'S', 0, 8 UNION ALL
  SELECT 'Tailored Pleat Trousers — Charcoal', 1899.00, 'M', 1, 12 UNION ALL
  SELECT 'Tailored Pleat Trousers — Charcoal', 1899.00, 'L', 2, 10 UNION ALL
  SELECT 'Tailored Pleat Trousers — Charcoal', 1899.00, 'XL', 3, 6 UNION ALL
  SELECT 'Tailored Pleat Trousers — Charcoal', 1899.00, '2XL', 4, 4
) v
JOIN products p ON p.name = v.pname AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
WHERE NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id);

