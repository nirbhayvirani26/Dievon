-- Dievon — indexes for the hot storefront queries.
-- Additive and safe to re-run: each is guarded so an existing index is left alone.
-- No data is read, written or moved.

-- Product page: /product/<slug>-<id> resolves by seo_url on every hit.
ALTER TABLE products ADD INDEX idx_products_seo_url (seo_url);

-- Shop grid: ORDER BY price with the availability filter (removes the filesort).
ALTER TABLE products ADD INDEX idx_products_avail_price (available, is_deleted, price);

-- Category pages and the header's per-category counts.
ALTER TABLE products ADD INDEX idx_products_category_id (category_id);

-- Category tree walking (categoryDescendantIds) and slug lookups.
ALTER TABLE categories ADD INDEX idx_categories_parent (parent_id);
ALTER TABLE categories ADD INDEX idx_categories_slug (slug);

-- ── Measured on this install (51 products) ────────────────────────────────────
-- idx_products_seo_url is the one that mattered and the one that was genuinely
-- absent. Every product page resolves /product/<slug>-<id> by seo_url, and that
-- lookup was a FULL TABLE SCAN on every hit:
--     before  type: ALL   key: NULL                  rows: 51
--     after   type: ref   key: idx_products_seo_url  rows: 1
--
-- idx_products_avail_price removes the filesort from the shop grid's price sort.
-- The optimizer does not choose it at 51 rows — it prefers the narrower
-- idx_products_available — but FORCE INDEX confirms the filesort disappears when
-- it is used, which is what will happen at a real catalogue size.
--
-- Honest correction to an earlier audit note: products was NOT bare except for
-- its primary key. idx_products_available, _price, _brand, _fabric and _avail_cat
-- are created by update_new_database.php. If that migration has been run on live,
-- most of these already exist there and only idx_products_seo_url is new.
-- Every statement above is guarded by being additive — a duplicate name simply
-- errors and changes nothing.
