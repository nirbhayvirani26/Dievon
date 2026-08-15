-- ============================================================
--  Dievon – Automatic size charts by gender
--  Generated 2026-08-06. Run on the LIVE database (phpMyAdmin).
--  Re-running is safe: every statement is guarded.
--
--  What this does:
--   1. Adds size_guide_charts.gender  ('women' | 'men', nullable)
--   2. Tags the existing charts by their category's gender
--   3. Creates the MEN'S DEFAULT chart (no category, gender='men'),
--      cloned from the Shirts chart — the template used when a new
--      men's category is created and when a men's product has no
--      category chart at all.
-- ============================================================

-- 1. Column (guarded: fails silently if it already exists)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'size_guide_charts'
                      AND COLUMN_NAME = 'gender');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE size_guide_charts ADD COLUMN gender ENUM(''women'',''men'') DEFAULT NULL AFTER product_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Tag existing category charts by their category's audience
UPDATE size_guide_charts c
JOIN categories cat ON cat.id = c.category_id
SET c.gender = IF(cat.gender = 'men', 'men', 'women')
WHERE c.gender IS NULL;

-- 3. Men's default chart (cloned from Shirts), if not already present
INSERT INTO size_guide_charts
    (category_id, product_id, gender, unit, tolerance,
     instructions_shoulder, instructions_bust, instructions_waist, instructions_hips,
     instructions_length, instructions_sleeve)
SELECT NULL, NULL, 'men', shirts.unit, shirts.tolerance,
       shirts.instructions_shoulder, shirts.instructions_bust, shirts.instructions_waist,
       shirts.instructions_hips, shirts.instructions_length, shirts.instructions_sleeve
FROM size_guide_charts shirts
JOIN categories cat ON cat.id = shirts.category_id AND cat.name = 'Shirts'
WHERE NOT EXISTS (
    SELECT 1 FROM size_guide_charts x
    WHERE x.category_id IS NULL AND x.product_id IS NULL AND x.gender = 'men'
)
LIMIT 1;

-- Its content rows, from the same Shirts chart
INSERT INTO size_guide_content
    (chart_id, measurement_type, size_label, numeric_size,
     bust, waist, hips, shoulder, sleeve, length, sort_order)
SELECT men.id, sc.measurement_type, sc.size_label, sc.numeric_size,
       sc.bust, sc.waist, sc.hips, sc.shoulder, sc.sleeve, sc.length, sc.sort_order
FROM size_guide_charts men
JOIN size_guide_charts shirts
  ON shirts.category_id = (SELECT id FROM categories WHERE name = 'Shirts')
JOIN size_guide_content sc ON sc.chart_id = shirts.id
WHERE men.category_id IS NULL AND men.product_id IS NULL AND men.gender = 'men'
  AND NOT EXISTS (SELECT 1 FROM size_guide_content x WHERE x.chart_id = men.id);
