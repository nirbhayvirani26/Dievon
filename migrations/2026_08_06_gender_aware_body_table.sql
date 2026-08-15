-- ============================================================
--  Dievon – Gender-aware body measurement table (2026-08-06)
--
--  size_guide_body_global held ONE women's ladder (bust 30-46).
--  Men's products inherited those numbers in the "Your
--  measurements" panel of the size guide. This adds a gender
--  column, widens the unique key to (gender, size_label) so
--  both genders can have an XS..5XL, and seeds the men's ladder
--  (chest 36-52, waist 28-44, hips 36-52, shoulder 16.5-20.5).
--
--  Existing women's rows become gender='women' untouched.
--
--  NOTE on re-runs: MySQL has no ADD COLUMN IF NOT EXISTS / DROP INDEX
--  IF EXISTS, so running this file a second time errors harmlessly on the
--  first ALTER (the column and index already exist) and the INSERT IGNORE
--  simply skips. The truly idempotent path is admin/update_new_database.php,
--  which checks each step before running it.
--
--  Apply via phpMyAdmin on the live database (Hostinger), or by
--  visiting admin/update_new_database.php.
-- ============================================================

-- 1) The gender column. Existing rows take the default 'women'.
ALTER TABLE `size_guide_body_global`
    ADD COLUMN `gender` ENUM('women','men')
    NOT NULL DEFAULT 'women' AFTER `id`;

-- 2) The unique key must cover gender — women's and men's XS
--    would otherwise collide on the old single-column key.
ALTER TABLE `size_guide_body_global`
    DROP INDEX `uniq_size_label`,
    ADD UNIQUE KEY `uniq_gender_size` (`gender`, `size_label`);

-- 3) The men's ladder (only when no men's rows exist yet).
INSERT IGNORE INTO `size_guide_body_global`
    (gender, size_label, numeric_size, bust, waist, hips, shoulder, sort_order)
VALUES
    ('men','XS','36', 36.00, 28.00, 36.00, 16.50, 0),
    ('men','S','38',  38.00, 30.00, 38.00, 17.00, 1),
    ('men','M','40',  40.00, 32.00, 40.00, 17.50, 2),
    ('men','L','42',  42.00, 34.00, 42.00, 18.00, 3),
    ('men','XL','44', 44.00, 36.00, 44.00, 18.50, 4),
    ('men','2XL','46',46.00, 38.00, 46.00, 19.00, 5),
    ('men','3XL','48',48.00, 40.00, 48.00, 19.50, 6),
    ('men','4XL','50',50.00, 42.00, 50.00, 20.00, 7),
    ('men','5XL','52',52.00, 44.00, 52.00, 20.50, 8);
