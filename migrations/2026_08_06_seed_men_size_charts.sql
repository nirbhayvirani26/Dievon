-- ============================================================
--  Dievon – Men's size charts (Shirts + Trousers) + global default
--  Generated 2026-08-06 from the local database. Idempotent: safe to
--  run more than once (each chart is only inserted if absent).
--  Import via phpMyAdmin on the LIVE database.
-- ============================================================

-- ── Chart: Shirts ──
INSERT INTO size_guide_charts (category_id, product_id, unit, tolerance,
    instructions_shoulder, instructions_bust, instructions_waist, instructions_hips,
    instructions_length, instructions_sleeve)
SELECT (SELECT id FROM categories WHERE name = 'Shirts'), NULL, 'in', '± 0.5 in',
    'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the chest, just under the arms, keeping it parallel to the floor.',
    'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.',
    'Measure from the highest point of the shoulder straight down the front to the hem.', 'Measure from the shoulder seam down the outside of the arm to the cuff.'
WHERE (SELECT id FROM categories WHERE name = 'Shirts') IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM size_guide_charts x
    WHERE x.category_id = (SELECT id FROM categories WHERE name = 'Shirts'));

INSERT INTO size_guide_content (chart_id, measurement_type, size_label, numeric_size,
    bust, waist, hips, shoulder, sleeve, length, sort_order)
SELECT c.id, v.type, v.label, v.num, v.bust, v.waist, v.hips, v.shoulder, v.sleeve, v.length, v.sort
FROM size_guide_charts c
CROSS JOIN (
    SELECT 'body', 'XS', 36, 36, 28, 34, 16.5, NULL, NULL, 0
    UNION ALL
    SELECT 'body', 'S', 38, 38, 30, 36, 17, NULL, NULL, 1
    UNION ALL
    SELECT 'body', 'M', 40, 40, 32, 38, 17.5, NULL, NULL, 2
    UNION ALL
    SELECT 'body', 'L', 42, 42, 34, 40, 18, NULL, NULL, 3
    UNION ALL
    SELECT 'body', 'XL', 44, 44, 36, 42, 18.5, NULL, NULL, 4
    UNION ALL
    SELECT 'body', '2XL', 46, 46, 38, 44, 19, NULL, NULL, 5
    UNION ALL
    SELECT 'body', '3XL', 48, 48, 40, 46, 19.5, NULL, NULL, 6
    UNION ALL
    SELECT 'body', '4XL', 50, 50, 42, 48, 20, NULL, NULL, 7
    UNION ALL
    SELECT 'body', '5XL', 52, 52, 44, 50, 20.5, NULL, NULL, 8
    UNION ALL
    SELECT 'garment', 'XS', 36, 36, 28, NULL, 16.5, 23.5, 28, 9
    UNION ALL
    SELECT 'garment', 'S', 38, 38, 30, NULL, 17, 24, 28.5, 10
    UNION ALL
    SELECT 'garment', 'M', 40, 40, 32, NULL, 17.5, 24.5, 29, 11
    UNION ALL
    SELECT 'garment', 'L', 42, 42, 34, NULL, 18, 25, 29.5, 12
    UNION ALL
    SELECT 'garment', 'XL', 44, 44, 36, NULL, 18.5, 25.5, 30, 13
    UNION ALL
    SELECT 'garment', '2XL', 46, 46, 38, NULL, 19, 26, 30.5, 14
    UNION ALL
    SELECT 'garment', '3XL', 48, 48, 40, NULL, 19.5, 26.5, 31, 15
    UNION ALL
    SELECT 'garment', '4XL', 50, 50, 42, NULL, 20, 27, 31.5, 16
    UNION ALL
    SELECT 'garment', '5XL', 52, 52, 44, NULL, 20.5, 27.5, 32, 17
) v
WHERE c.category_id = (SELECT id FROM categories WHERE name = 'Shirts') AND c.id = (SELECT MIN(id) FROM size_guide_charts x WHERE x.category_id = (SELECT id FROM categories WHERE name = 'Shirts'))
  AND NOT EXISTS (SELECT 1 FROM size_guide_content sc WHERE sc.chart_id = c.id);

-- ── Chart: Trousers ──
INSERT INTO size_guide_charts (category_id, product_id, unit, tolerance,
    instructions_shoulder, instructions_bust, instructions_waist, instructions_hips,
    instructions_length, instructions_sleeve)
SELECT (SELECT id FROM categories WHERE name = 'Trousers'), NULL, 'in', '± 0.5 in',
    'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the chest, just under the arms, keeping it parallel to the floor.',
    'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.',
    'Measure from the top of the waistband down the outside of the leg to the hem.', NULL
WHERE (SELECT id FROM categories WHERE name = 'Trousers') IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM size_guide_charts x
    WHERE x.category_id = (SELECT id FROM categories WHERE name = 'Trousers'));

INSERT INTO size_guide_content (chart_id, measurement_type, size_label, numeric_size,
    bust, waist, hips, shoulder, sleeve, length, sort_order)
SELECT c.id, v.type, v.label, v.num, v.bust, v.waist, v.hips, v.shoulder, v.sleeve, v.length, v.sort
FROM size_guide_charts c
CROSS JOIN (
    SELECT 'body', 'XS', 36, 36, 28, 34, 16.5, NULL, NULL, 0
    UNION ALL
    SELECT 'body', 'S', 38, 38, 30, 36, 17, NULL, NULL, 1
    UNION ALL
    SELECT 'body', 'M', 40, 40, 32, 38, 17.5, NULL, NULL, 2
    UNION ALL
    SELECT 'body', 'L', 42, 42, 34, 40, 18, NULL, NULL, 3
    UNION ALL
    SELECT 'body', 'XL', 44, 44, 36, 42, 18.5, NULL, NULL, 4
    UNION ALL
    SELECT 'body', '2XL', 46, 46, 38, 44, 19, NULL, NULL, 5
    UNION ALL
    SELECT 'body', '3XL', 48, 48, 40, 46, 19.5, NULL, NULL, 6
    UNION ALL
    SELECT 'body', '4XL', 50, 50, 42, 48, 20, NULL, NULL, 7
    UNION ALL
    SELECT 'body', '5XL', 52, 52, 44, 50, 20.5, NULL, NULL, 8
    UNION ALL
    SELECT 'garment', 'XS', 36, NULL, 28, 36, NULL, NULL, 40, 9
    UNION ALL
    SELECT 'garment', 'S', 38, NULL, 30, 38, NULL, NULL, 40, 10
    UNION ALL
    SELECT 'garment', 'M', 40, NULL, 32, 40, NULL, NULL, 40, 11
    UNION ALL
    SELECT 'garment', 'L', 42, NULL, 34, 42, NULL, NULL, 40, 12
    UNION ALL
    SELECT 'garment', 'XL', 44, NULL, 36, 44, NULL, NULL, 40, 13
    UNION ALL
    SELECT 'garment', '2XL', 46, NULL, 38, 46, NULL, NULL, 40, 14
    UNION ALL
    SELECT 'garment', '3XL', 48, NULL, 40, 48, NULL, NULL, 40, 15
    UNION ALL
    SELECT 'garment', '4XL', 50, NULL, 42, 50, NULL, NULL, 40, 16
    UNION ALL
    SELECT 'garment', '5XL', 52, NULL, 44, 52, NULL, NULL, 40, 17
) v
WHERE c.category_id = (SELECT id FROM categories WHERE name = 'Trousers') AND c.id = (SELECT MIN(id) FROM size_guide_charts x WHERE x.category_id = (SELECT id FROM categories WHERE name = 'Trousers'))
  AND NOT EXISTS (SELECT 1 FROM size_guide_content sc WHERE sc.chart_id = c.id);

-- ── Chart: Global default ──
INSERT INTO size_guide_charts (category_id, product_id, unit, tolerance,
    instructions_shoulder, instructions_bust, instructions_waist, instructions_hips,
    instructions_length, instructions_sleeve)
SELECT NULL, NULL, 'in', '± 0.5 in',
    'Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.', 'Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.',
    'Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.', 'Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.',
    'Measure from the highest point of the shoulder straight down to where the hem should fall.', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM size_guide_charts x
    WHERE x.category_id IS NULL AND x.product_id IS NULL);

INSERT INTO size_guide_content (chart_id, measurement_type, size_label, numeric_size,
    bust, waist, hips, shoulder, sleeve, length, sort_order)
SELECT c.id, v.type, v.label, v.num, v.bust, v.waist, v.hips, v.shoulder, v.sleeve, v.length, v.sort
FROM size_guide_charts c
CROSS JOIN (
    SELECT 'body', 'XS', 30, 30, 24, 33, 13.5, NULL, NULL, 0
    UNION ALL
    SELECT 'body', 'S', 32, 32, 26, 35, 14, NULL, NULL, 1
    UNION ALL
    SELECT 'body', 'M', 34, 34, 28, 37, 14.5, NULL, NULL, 2
    UNION ALL
    SELECT 'body', 'L', 36, 36, 30, 39, 15, NULL, NULL, 3
    UNION ALL
    SELECT 'body', 'XL', 38, 38, 32, 41, 15.5, NULL, NULL, 4
    UNION ALL
    SELECT 'body', '2XL', 40, 40, 34, 43, 16, NULL, NULL, 5
    UNION ALL
    SELECT 'body', '3XL', 42, 42, 36, 45, 16.5, NULL, NULL, 6
    UNION ALL
    SELECT 'body', '4XL', 44, 44, 38, 47, 17, NULL, NULL, 7
    UNION ALL
    SELECT 'body', '5XL', 46, 46, 40, 49, 17.5, NULL, NULL, 8
    UNION ALL
    SELECT 'garment', 'XS', 30, 33, 27, 35, 14, 21, 42, 9
    UNION ALL
    SELECT 'garment', 'S', 32, 35, 29, 37, 14.5, 21.5, 42, 10
    UNION ALL
    SELECT 'garment', 'M', 34, 37, 31, 39, 15, 21.5, 43, 11
    UNION ALL
    SELECT 'garment', 'L', 36, 39, 33, 41, 15.5, 21.5, 43, 12
    UNION ALL
    SELECT 'garment', 'XL', 38, 41, 35, 43, 16, 22, 44, 13
    UNION ALL
    SELECT 'garment', '2XL', 40, 43, 37, 45, 16.5, 22, 44, 14
    UNION ALL
    SELECT 'garment', '3XL', 42, 45, 39, 47, 17, 22, 45, 15
    UNION ALL
    SELECT 'garment', '4XL', 44, 47, 41, 49, 17.5, 22.5, 45, 16
    UNION ALL
    SELECT 'garment', '5XL', 46, 49, 43, 51, 18, 22.5, 46, 17
) v
WHERE c.category_id IS NULL AND c.product_id IS NULL AND c.id = (SELECT MIN(id) FROM size_guide_charts x WHERE x.category_id IS NULL AND x.product_id IS NULL)
  AND NOT EXISTS (SELECT 1 FROM size_guide_content sc WHERE sc.chart_id = c.id);

