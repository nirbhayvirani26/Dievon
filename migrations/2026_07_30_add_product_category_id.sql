-- ============================================================
--  Products -> Categories: real relational link + filter indexes
--
--  Before this, products were tied to categories by NAME only
--  (products.category VARCHAR(50), no key, no index). That meant:
--    * renaming a category silently orphaned every product in it;
--    * subcategory browsing was faked in PHP with hardcoded
--      `category LIKE '%Kurti%'` rules, so "Short Kurtis" returned
--      Long Kurtis products and any new category family (Sarees,
--      Lehenga, ...) returned nothing at all until someone edited PHP;
--    * every category page was a full table scan (EXPLAIN type=ALL),
--      ~70ms at 5k products and growing linearly.
--
--  products.category (the name) is deliberately KEPT and kept in sync,
--  because the storefront reads it directly in several places
--  (size-guide lookup, product page, home page). category_id is the
--  authoritative link; the name is a denormalised convenience copy.
-- ============================================================

ALTER TABLE products
    ADD COLUMN category_id INT NULL DEFAULT NULL AFTER category;

-- Backfill from the existing names (case/whitespace insensitive).
UPDATE products p
    JOIN categories c
      ON LOWER(TRIM(c.name)) = LOWER(TRIM(p.category))
SET p.category_id = c.id;

-- Indexes. The category filter and the shop facets were all unindexed.
ALTER TABLE products
    ADD INDEX idx_products_category_id (category_id),
    ADD INDEX idx_products_available (available),
    ADD INDEX idx_products_avail_cat (available, category_id),
    ADD INDEX idx_products_brand (brand),
    ADD INDEX idx_products_fabric (fabric),
    ADD INDEX idx_products_price (price);

ALTER TABLE categories
    ADD INDEX idx_categories_parent (parent_id);

-- ON DELETE SET NULL rather than CASCADE: deleting a category must never
-- delete the products in it. They fall back to the retained name column
-- and can be reassigned in admin.
ALTER TABLE products
    ADD CONSTRAINT fk_products_category
    FOREIGN KEY (category_id) REFERENCES categories(id)
    ON DELETE SET NULL ON UPDATE CASCADE;
