-- ============================================================
--  Dievon — Migration: Colour Variants + Per-Colour Size Stock + Size Guide
--  Generated: 2026-07-29
--
--  Safe to run multiple times (idempotent). Purely additive:
--  no existing column is renamed or dropped, no existing row is
--  touched. Existing products / variants / orders keep working
--  exactly as before (color_id stays NULL on legacy rows).
--
--  This same migration also runs automatically on every request
--  via config/db.php, so applying this file by hand is optional —
--  it is provided for manual deployment via phpMyAdmin/CLI import.
--
--  Target: MySQL 5.7+ (tested against 5.7.44). MySQL 5.7 does not
--  support `ADD COLUMN IF NOT EXISTS`, so column additions below
--  use a small procedure that checks INFORMATION_SCHEMA first.
-- ============================================================

-- ---- 1. Colour variants -------------------------------------

CREATE TABLE IF NOT EXISTS `product_colors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `color_name` VARCHAR(80) NOT NULL,
    `sku` VARCHAR(100) DEFAULT NULL,
    `thumbnail` VARCHAR(255) DEFAULT NULL,
    `price_override` DECIMAL(10,2) DEFAULT NULL,
    `mrp_price_override` DECIMAL(10,2) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_color_images` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `color_id` INT NOT NULL,
    `image` VARCHAR(255) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_color_id` (`color_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- 2. Additive columns on product_variants (colour + per-size stock) ----

DROP PROCEDURE IF EXISTS `dievon_add_column_if_missing`;

DELIMITER $$
CREATE PROCEDURE `dievon_add_column_if_missing`(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL dievon_add_column_if_missing('product_variants', 'color_id', 'INT DEFAULT NULL');
CALL dievon_add_column_if_missing('product_variants', 'size_code', 'VARCHAR(10) DEFAULT NULL');
CALL dievon_add_column_if_missing('product_variants', 'stock_qty', 'INT DEFAULT NULL');

DROP PROCEDURE IF EXISTS `dievon_add_column_if_missing`;

-- ---- 3. Size guide (category default + optional per-product override) ----

CREATE TABLE IF NOT EXISTS `size_guide_charts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT DEFAULT NULL,
    `product_id` INT DEFAULT NULL,
    `unit` ENUM('in','cm') NOT NULL DEFAULT 'in',
    `instructions_shoulder` TEXT DEFAULT NULL,
    `instructions_bust` TEXT DEFAULT NULL,
    `instructions_waist` TEXT DEFAULT NULL,
    `instructions_hips` TEXT DEFAULT NULL,
    `instructions_length` TEXT DEFAULT NULL,
    `illustration_image` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_category_id` (`category_id`),
    KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `size_guide_content` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `chart_id` INT NOT NULL,
    `measurement_type` ENUM('body','garment') NOT NULL DEFAULT 'body',
    `size_label` VARCHAR(20) NOT NULL,
    `numeric_size` VARCHAR(20) DEFAULT NULL,
    `bust` DECIMAL(6,2) DEFAULT NULL,
    `waist` DECIMAL(6,2) DEFAULT NULL,
    `hips` DECIMAL(6,2) DEFAULT NULL,
    `shoulder` DECIMAL(6,2) DEFAULT NULL,
    `length` DECIMAL(6,2) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    KEY `idx_chart_id` (`chart_id`),
    UNIQUE KEY `uniq_chart_type_size` (`chart_id`,`measurement_type`,`size_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
