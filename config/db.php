<?php
// ============================================================
//  Dievon – Database Layer (MySQL via PDO)
//  Tables are created manually in phpMyAdmin using setup.sql
// ============================================================
require_once __DIR__  . '/config.php';

try {
    if (!empty($isLocal)) {
        // Local MAMP attempts (port 8889 first, port 3306, unix socket)
        $dsnList = [
            'mysql:host=localhost;port=8889;dbname=' . DB_NAME . ';charset=utf8mb4',
            'mysql:host=127.0.0.1;port=8889;dbname=' . DB_NAME . ';charset=utf8mb4',
            'mysql:host=localhost;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4',
            'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=' . DB_NAME . ';charset=utf8mb4'
        ];

        $pdo = null;
        $lastErr = null;
        foreach ($dsnList as $curDsn) {
            foreach ([DB_PASS, ''] as $curPass) {
                try {
                    $pdo = new PDO($curDsn, DB_USER, $curPass);
                    break 2;
                } catch (PDOException $ex) {
                    $lastErr = $ex;
                }
            }
        }
        if (!$pdo && $lastErr) {
            throw $lastErr;
        }
    } else {
        // Live Hostinger PDO Connection (try standard host first, then with port, then auto-create)
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
        } catch (PDOException $ex1) {
            try {
                $dsnWithPort = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                $pdo = new PDO($dsnWithPort, DB_USER, DB_PASS);
            } catch (PDOException $ex2) {
                try {
                    // Try connecting to MySQL server root to auto-create missing database
                    $dsnNoDb = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
                    $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                    $pdo->exec("USE `" . DB_NAME . "`;");
                } catch (PDOException $ex3) {
                    // Friendly diagnostic page instead of a generic HTTP 500. The real
                    // exception (driver, host, path) goes to the server error log for
                    // the owner — never printed to a visitor.
                    error_log('DB connect failure (live): ' . $ex3->getMessage());
                    die("<div style='font-family:sans-serif; background:#0f172a; color:#f87171; padding:40px; text-align:center; min-height:100vh;'>
                        <h2 style='color:#c59b4b;'>Database Connection Error</h2>
                        <p style='color:#cbd5e1; max-width:600px; margin:0 auto 20px; line-height:1.6;'>We couldn't reach the database. The site owner has been notified — please try again shortly.</p>
                        <p style='color:#94a3b8; font-size:13px; margin-top:20px;'>If you are the owner, check the database credentials in <code>.env</code> (DB_HOST / DB_NAME / DB_USER / DB_PASS) and the server error log for details.</p>
                    </div>");
                }
            }
        }
    }

    if ($pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);
    }

    // Auto-ensure required tables exist (customers, etc.)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `customers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `email` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
            `password` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `phone` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `address` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `email_verified_at` DATETIME DEFAULT NULL,
            `email_verify_token` VARCHAR(64) DEFAULT NULL,
            `email_verify_token_expires` DATETIME DEFAULT NULL,
            `email_verify_sent_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (PDOException $exTable) {}

    try {
        $pdo->exec("ALTER TABLE `customers`
            MODIFY `name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            MODIFY `email` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            MODIFY `password` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            MODIFY `phone` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            MODIFY `address` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL
        ;");
    } catch (PDOException $exCustCollation) {}

    // ── Email verification (the guest-order linking gate) ─────────────────────
    // New registrations must prove they own the address before any guest order
    // placed with it is linked to their account (see the EMAIL VERIFICATION
    // section in config.php). When the column is first added, every EXISTING
    // account is grandfathered as verified — those people registered before
    // verification existed and are not to be locked out of their own history.
    // The UPDATE runs exactly once, guarded by the SHOW COLUMNS check.
    try {
        $verifCol = $pdo->query("SHOW COLUMNS FROM `customers` LIKE 'email_verified_at'")->fetch();
        if (!$verifCol) {
            $pdo->exec("ALTER TABLE `customers` ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL");
            $pdo->exec("UPDATE `customers` SET `email_verified_at` = `created_at` WHERE `email_verified_at` IS NULL");
        }
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `customers` ADD COLUMN `email_verify_token` VARCHAR(64) DEFAULT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `customers` ADD COLUMN `email_verify_token_expires` DATETIME DEFAULT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `customers` ADD COLUMN `email_verify_sent_at` DATETIME DEFAULT NULL");
    } catch (PDOException $e) {}

    /* Color Way holds a LIST now, so 100 characters is no longer enough.
       ────────────────────────────────────────────────────────────────────────
       A garment that IS two or three colours stores them comma separated, and
       varchar(100) fitted only eight of the shop's own colour names before MySQL
       cut the value — mid-name, producing a colour that matches nothing and no
       error to say so. 255 is the same storage cost in InnoDB for the values
       actually held; only the declared ceiling moves.

       Guarded by SHOW COLUMNS so it runs once rather than on every request, and
       widened only — never narrowed, which could truncate real data. */
    try {
        $cwCol = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'color_way'")->fetch();
        if ($cwCol && preg_match('/varchar\((\d+)\)/i', (string)($cwCol['Type'] ?? ''), $m)
            && (int)$m[1] < 255) {
            $pdo->exec("ALTER TABLE `products` MODIFY `color_way` VARCHAR(255) DEFAULT NULL");
        }
    } catch (PDOException $e) {}

    // Auto-ensure product_images table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_images` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `image` VARCHAR(255) NOT NULL,
            `sort_order` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $exImg) {}

    // Auto-ensure orders table and order_items exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_code` VARCHAR(50) NOT NULL UNIQUE,
            `customer_id` INT DEFAULT NULL,
            `customer_name` VARCHAR(150) NOT NULL,
            `customer_email` VARCHAR(150) NOT NULL DEFAULT '',
            `phone` VARCHAR(30) NOT NULL DEFAULT '',
            `address` TEXT NOT NULL,
            `postcode` VARCHAR(10) NOT NULL DEFAULT '',
            `notes` TEXT DEFAULT NULL,
            `items_json` LONGTEXT NOT NULL,
            `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `promo_code` VARCHAR(50) DEFAULT NULL,
            `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `delivery_charge` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `cod_fee` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
            `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₹',
            `payment_method` VARCHAR(20) NOT NULL DEFAULT 'later',
            `payment_status` VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
            `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
            `tracking_number` VARCHAR(100) DEFAULT NULL,
            `carrier` VARCHAR(100) DEFAULT NULL,
            `stock_deducted` TINYINT(1) NOT NULL DEFAULT 0,
            `last_notified_status` VARCHAR(50) DEFAULT '',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_customer_id` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            // COD handling fee, charged on Cash on Delivery orders and stored so
            // invoices and the admin panel can show it as its own line.
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `cod_fee` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `delivery_charge`");
        } catch (PDOException $e) {}

        try {
            // GST invoice serial (e.g. DI/2026-27/00014), assigned once per order
            // and frozen — see nextInvoiceNumber() in config.php. Old invoices used
            // to rewrite their tax when a product's HSN/rate changed because nothing
            // was stored on the order; the serial is part of the same fix, so an
            // invoice can be identified forever.
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `invoice_number` VARCHAR(40) DEFAULT NULL AFTER `order_code`");
        } catch (PDOException $e) {}

        try {
            // The per-financial-year counter behind every invoice serial. Its own
            // table (rather than a store_settings row) so the increment is a single
            // atomic UPDATE — see nextInvoiceNumber().
            $pdo->exec("CREATE TABLE IF NOT EXISTS `invoice_sequences` (
                `financial_year` VARCHAR(9) NOT NULL,
                `last_number` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`financial_year`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (PDOException $e) {}

        try {
            // Stamped the first time an order is marked Delivered/Completed, so a
            // review-request email goes out exactly once per order (see
            // sendReviewRequestEmail() in services/EmailService.php).
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `review_requested_at` DATETIME DEFAULT NULL");
        } catch (PDOException $e) {}

        try {
            // COD remittance: delivery marks the cash as handed over (payment_status
            // 'Cash'); REMITTANCE is the courier actually paying the shop, usually
            // days later. Recording it separately is what lets the books show how
            // much courier cash is still outstanding (see the COD report).
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `remitted_at` DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `remitted_amount` DECIMAL(10,2) DEFAULT NULL");
        } catch (PDOException $e) {}

        try {
            // The refund ledger. Guaranteed present here (it used to live only in
            // update_new_database.php, so fresh installs could miss it and every
            // refund would fail). payout_channel records HOW a manual (non-Razorpay)
            // refund was handed back — cash / UPI / bank — so the books say where
            // the money went.
            $pdo->exec("CREATE TABLE IF NOT EXISTS `order_refunds` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `order_id` INT NOT NULL,
                `order_code` VARCHAR(30) NOT NULL DEFAULT '',
                `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
                `method` VARCHAR(20) NOT NULL DEFAULT 'manual',
                `payout_channel` VARCHAR(20) DEFAULT NULL,
                `razorpay_payment_id` VARCHAR(100) DEFAULT NULL,
                `razorpay_refund_id` VARCHAR(100) DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `reason` VARCHAR(255) DEFAULT NULL,
                `notes` TEXT DEFAULT NULL,
                `created_by` INT DEFAULT NULL,
                `processed_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            $pdo->exec("ALTER TABLE `order_refunds` ADD COLUMN `payout_channel` VARCHAR(20) DEFAULT NULL AFTER `method`");
        } catch (PDOException $e) {}

        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `customer_id` INT DEFAULT NULL AFTER `order_code`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `customer_email` VARCHAR(150) NOT NULL DEFAULT '' AFTER `customer_name`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `promo_code` VARCHAR(50) DEFAULT NULL AFTER `total_price`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `promo_code`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `delivery_charge` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'INR' AFTER `delivery_charge`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₹' AFTER `currency`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `payment_method` VARCHAR(20) NOT NULL DEFAULT 'later' AFTER `currency_symbol`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `payment_status` VARCHAR(20) NOT NULL DEFAULT 'Unpaid' AFTER `payment_method`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `stock_deducted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `carrier`");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `last_notified_status` VARCHAR(50) DEFAULT '' AFTER `status`");
        } catch (PDOException $e) {}
        try {
            // So a paid order can always be traced back to its actual Razorpay transaction
            // for reconciliation, disputes, or refunds — never derivable after the fact otherwise.
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `razorpay_payment_id` VARCHAR(100) DEFAULT NULL AFTER `payment_status`");
        } catch (PDOException $e) {}
        try {
            // Legacy installs have `status` as a narrow ENUM('Pending','Processing','Delivered','Cancelled'),
            // which silently rejects/truncates every other status the admin UI offers (Shipped, Packed,
            // Confirmed, Returned, Refunded, etc). Widen it to a plain VARCHAR that fits them all.
            $statusCol = $pdo->query("SHOW COLUMNS FROM `orders` WHERE Field = 'status'")->fetch();
            if ($statusCol && stripos($statusCol['Type'], 'enum') === 0) {
                $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Pending'");
            }
        } catch (PDOException $e) {}
    } catch (PDOException $exOrders) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `order_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `product_id` INT DEFAULT NULL,
            `product_name` VARCHAR(255) NOT NULL,
            `variant_name` VARCHAR(100) DEFAULT NULL,
            `quantity` INT NOT NULL DEFAULT 1,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_order_item_order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $exOrderItems) {}

    // Auto-ensure products table has fashion columns
    try {
        $cols = [
            'atelier_code VARCHAR(100) DEFAULT NULL', 
            'color_way VARCHAR(100) DEFAULT NULL', 
            'sourcing VARCHAR(255) DEFAULT NULL', 
            'sourcing VARCHAR(255) DEFAULT NULL', 
            'composition VARCHAR(255) DEFAULT NULL', 
            'brand VARCHAR(100) DEFAULT NULL', 
            'color VARCHAR(100) DEFAULT NULL', 
            'fabric VARCHAR(100) DEFAULT NULL', 
            'sleeve VARCHAR(100) DEFAULT NULL', 
            'neck VARCHAR(100) DEFAULT NULL', 
            'pattern VARCHAR(100) DEFAULT NULL', 
            'occasion VARCHAR(100) DEFAULT NULL',
            'sku VARCHAR(100) DEFAULT NULL',
            'barcode VARCHAR(100) DEFAULT NULL',
            'weight VARCHAR(100) DEFAULT NULL',
            'dimensions VARCHAR(100) DEFAULT NULL',
            'tags TEXT DEFAULT NULL',
            'video_url VARCHAR(255) DEFAULT NULL',
            'seo_url VARCHAR(255) DEFAULT NULL',
            'meta_title VARCHAR(255) DEFAULT NULL',
            'meta_description TEXT DEFAULT NULL',
            'related_ids TEXT DEFAULT NULL',
            'cost_price DECIMAL(10,2) DEFAULT 0.00',
            'mrp_price DECIMAL(10,2) DEFAULT 0.00',
            'hsn_code VARCHAR(50) DEFAULT NULL',
            'gst_rate DECIMAL(5,2) DEFAULT 0.00',
            'use_category_tax TINYINT(1) NOT NULL DEFAULT 1',
            'price_includes_gst TINYINT(1) NOT NULL DEFAULT 1',
            'is_deleted TINYINT(1) NOT NULL DEFAULT 0'
        ];
        foreach ($cols as $colDef) {
            $colName = explode(' ', $colDef)[0];
            try {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN $colDef;");
            } catch (PDOException $e) {}
        }
    } catch (PDOException $exCols) {}

    // Auto-ensure customer portal tables exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(191) NOT NULL,
            `token` VARCHAR(255) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `email` (`email`),
            KEY `token` (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_addresses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `address_type` ENUM('shipping', 'billing', 'both') DEFAULT 'shipping',
            `full_name` VARCHAR(191) NOT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `address_line1` VARCHAR(255) NOT NULL,
            `address_line2` VARCHAR(255) DEFAULT NULL,
            `city` VARCHAR(100) NOT NULL,
            `state` VARCHAR(100) NOT NULL,
            `postcode` VARCHAR(20) NOT NULL,
            `country` VARCHAR(100) DEFAULT 'India',
            `is_default` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `customer_id` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_returns` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `return_code` VARCHAR(50) NOT NULL UNIQUE,
            `order_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `request_type` ENUM('return', 'exchange') NOT NULL,
            `reason` VARCHAR(255) NOT NULL,
            `exchange_size` VARCHAR(50) DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `photo_path` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('Pending', 'Approved', 'Rejected', 'Pickup Scheduled', 'Quality Check', 'Completed') DEFAULT 'Pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `customer_id` (`customer_id`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_tickets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ticket_code` VARCHAR(50) NOT NULL UNIQUE,
            `customer_id` INT NOT NULL,
            `order_id` INT DEFAULT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('Open', 'In Progress', 'Resolved', 'Closed') DEFAULT 'Open',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `customer_id` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT DEFAULT NULL,
            `action` VARCHAR(255) NOT NULL,
            `ip_address` VARCHAR(50) DEFAULT NULL,
            `user_agent` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $exPortal) {}

    // Auto-ensure product colour-variant system exists (additive; existing
    // products/variants without a colour keep working exactly as before —
    // color_id stays NULL for every legacy row).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_colors` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_color_images` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `color_id` INT NOT NULL,
            `image` VARCHAR(255) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_color_id` (`color_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $exColors) {}

    // Auto-ensure categories carry the per-category tax defaults.
    //
    // migrations/2026_08_06_add_category_default_hsn_gst.sql adds these, but this
    // file already tops up orders, products, product_variants and size_guide_charts
    // by itself and NOTHING did it for categories — so a deployment where that one
    // .sql was not run by hand kept the columns missing. admin/product_form.php
    // reads them at two places inside try/catch, so nothing crashes: the per-category
    // HSN and GST defaults simply never apply, silently, and every product falls back
    // to whatever is typed by hand. A quiet wrong answer about tax is the worst kind,
    // so the schema now heals itself here like everything else.
    //
    // Additive and idempotent: SHOW COLUMNS first, so re-running changes nothing and
    // a database that already has them via the .sql is untouched.
    try {
        $categoryTaxCols = [
            'default_hsn_code VARCHAR(50) DEFAULT NULL',
            'default_gst_rate DECIMAL(5,2) DEFAULT 0.00',
        ];
        foreach ($categoryTaxCols as $colDef) {
            $colName = explode(' ', $colDef)[0];
            try {
                $exists = $pdo->query("SHOW COLUMNS FROM `categories` LIKE '$colName'")->fetch();
                if (!$exists) {
                    $pdo->exec("ALTER TABLE `categories` ADD COLUMN $colDef;");
                }
            } catch (PDOException $e) {}
        }
    } catch (PDOException $exCatTax) {}

    // Auto-ensure product_variants supports colour + per-size stock (additive columns)
    try {
        $variantCols = [
            'color_id INT DEFAULT NULL',
            'size_code VARCHAR(10) DEFAULT NULL',
            'stock_qty INT DEFAULT NULL',
        ];
        foreach ($variantCols as $colDef) {
            $colName = explode(' ', $colDef)[0];
            try {
                $exists = $pdo->query("SHOW COLUMNS FROM `product_variants` LIKE '$colName'")->fetch();
                if (!$exists) {
                    $pdo->exec("ALTER TABLE `product_variants` ADD COLUMN $colDef;");
                }
            } catch (PDOException $e) {}
        }
        try {
            $pdo->exec("ALTER TABLE `product_variants` ADD KEY `idx_color_id` (`color_id`)");
        } catch (PDOException $e) {}
    } catch (PDOException $exVariantCols) {}

    // Auto-ensure size-guide system exists (category default + optional per-product override).
    // Fields are left empty by admin until real measurements are entered — never fabricated.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `size_guide_charts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `category_id` INT DEFAULT NULL,
            `product_id` INT DEFAULT NULL,
            `gender` ENUM('women','men') DEFAULT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `size_guide_content` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $exSizeGuide) {}

    // Overlay line positions (% of image width/height) for real-photo illustrations —
    // nullable, so a chart with an uploaded photo but no positioning yet still falls
    // back to sensible on-page defaults rather than breaking.
    foreach ([
        'pos_shoulder_top', 'pos_shoulder_width',
        'pos_bust_top', 'pos_bust_width',
        'pos_waist_top', 'pos_waist_width',
        'pos_hips_top', 'pos_hips_width',
        'pos_length_top', 'pos_length_bottom',
    ] as $posCol) {
        try {
            $pdo->exec("ALTER TABLE `size_guide_charts` ADD COLUMN `$posCol` DECIMAL(5,2) DEFAULT NULL");
        } catch (PDOException $e) {}
    }

    // Gender tag on charts: lets a category with no chart of its own fall back to
    // the DEFAULT for its own audience (men's products must not inherit women's
    // measurements), and lets category creation auto-clone the right template.
    try {
        $pdo->exec("ALTER TABLE `size_guide_charts` ADD COLUMN `gender` ENUM('women','men') DEFAULT NULL AFTER `product_id`");
    } catch (PDOException $e) {}

    // Auto-ensure the dynamic product specifications system exists.
    // Purely additive: products with no rows here simply show no dynamic
    // specification sections — every existing product keeps working.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_specifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `label` VARCHAR(150) NOT NULL,
            `value` VARCHAR(500) NOT NULL,
            `unit` VARCHAR(50) DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_components` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Carries product_id redundantly (alongside component_id) purely so every
        // handler query can verify ownership with a single WHERE, with no risk of
        // one product's rows ever being reachable through another product's id.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_component_specifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `component_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `label` VARCHAR(150) NOT NULL,
            `value` VARCHAR(500) NOT NULL,
            `unit` VARCHAR(50) DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_component_id` (`component_id`),
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (PDOException $exSpecs) {}

    // Snapshot of a checkout's full order details, captured the moment a Razorpay order
    // is created (before the customer even opens the payment popup). A webhook has no
    // access to the customer's session, so this is what lets it independently create the
    // real order if the browser never completes the round-trip after a successful charge.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `razorpay_pending_orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `razorpay_order_id` VARCHAR(100) NOT NULL,
            `amount_paise` INT NOT NULL,
            `customer_id` INT DEFAULT NULL,
            `customer_name` VARCHAR(150) NOT NULL DEFAULT '',
            `customer_email` VARCHAR(180) NOT NULL DEFAULT '',
            `phone` VARCHAR(30) NOT NULL DEFAULT '',
            `address` TEXT,
            `notes` TEXT,
            `postcode` VARCHAR(10) NOT NULL DEFAULT '',
            `order_type` VARCHAR(20) NOT NULL DEFAULT 'delivery',
            `items_json` JSON NOT NULL,
            `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `delivery_charge` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `promo_code` VARCHAR(50) DEFAULT NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
            `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₹',
            `consumed` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_rzp_order` (`razorpay_order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (PDOException $exPending) {}

} catch (PDOException $e) {
    // Same rule as above: the raw exception (host, driver, path) is logged for the
    // owner, and a visitor only ever sees a friendly message.
    error_log('DB connect failure (final): ' . $e->getMessage());
    die("<h2 style='font-family:sans-serif; color:#ff4757; padding:40px;'>
        ⚠️ Database connection failed.<br><br>
        <small style='color:#aaa;'>The site owner has been notified. Please try again in a few minutes.</small><br><br>
        <small>Make sure MySQL is running and you have imported the database dump into <strong>" . htmlspecialchars(DB_NAME) . "</strong> in phpMyAdmin.<br>
        Then verify credentials in <code>.env</code> (DB_HOST / DB_NAME / DB_USER / DB_PASS).</small>
    </h2>");
}

// ── "Keep me signed in" ─────────────────────────────────────────────────
// Hooked here rather than in a page, because this is the one file every entry
// point already loads — the front controller, every action endpoint, the admin
// — and it runs after config/config.php has started the session but before any
// page decides whether the visitor is signed in. A page-level hook would run
// too late for pages/login.php, which redirects an already-signed-in visitor to
// their account before its own body executes.
//
// Costs nothing when there is no cookie, which is the normal case: the function
// returns on its first condition without querying anything.
require_once __DIR__ . '/../includes/remember_me.php';
rememberMeAutoLogin($pdo);
