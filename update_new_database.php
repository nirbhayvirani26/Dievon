<?php
// ============================================================
//  Dievon – Database update for the latest code deploy
// ------------------------------------------------------------
//  Open this page on the live site AFTER uploading the new code, while
//  logged into /admin. It adds the tables, columns and indexes the new
//  features need.
//
//  Safety properties, all deliberate:
//   * Admin login required — this alters the live database, so it must
//     never be runnable by anyone who simply guesses the URL.
//   * Nothing is destructive. It only ever ADDS. No DROP, no DELETE, no
//     column is ever removed and no existing row is overwritten (the one
//     UPDATE only fills category_id where it is currently NULL).
//   * Idempotent. Every step checks whether it has already been applied,
//     so clicking twice is harmless and simply reports "already done".
//   * MySQL 5.7 compatible — 5.7 has no ADD COLUMN IF NOT EXISTS, so
//     every change is guarded by an INFORMATION_SCHEMA lookup instead.
//   * Nothing runs on GET. You have to press the button.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

// Owner-level, not just signed in.
// This file issues schema changes against the live database. A login check
// alone let any staff account — content, support, catalogue — run them by
// opening the URL. settings.manage is the permission that already guards
// every other screen able to change how the shop works.
requireAdminCapability('settings.manage');

// ── Schema inspection helpers (MySQL 5.7 safe) ──────────────────────────────
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

function tableExists(PDO $pdo, string $db, string $table): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t");
    $s->execute(['d' => $db, 't' => $table]);
    return (int)$s->fetchColumn() > 0;
}
function columnExists(PDO $pdo, string $db, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(['d' => $db, 't' => $table, 'c' => $col]);
    return (int)$s->fetchColumn() > 0;
}
function indexExists(PDO $pdo, string $db, string $table, string $index): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND INDEX_NAME=:i");
    $s->execute(['d' => $db, 't' => $table, 'i' => $index]);
    return (int)$s->fetchColumn() > 0;
}
function fkExists(PDO $pdo, string $db, string $table, string $name): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND CONSTRAINT_NAME=:n AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $s->execute(['d' => $db, 't' => $table, 'n' => $name]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Every change this deploy needs, as [label, already-applied test, the SQL].
 * Adding a future migration means appending one row here.
 */
/**
 * True when a column has no meaningful default.
 *
 * MySQL and MariaDB disagree about how information_schema reports defaults:
 * MySQL 5.7 returns an empty default as an empty string, while MariaDB 10.2+
 * returns defaults as *expressions*, so the same empty default comes back as
 * the two characters ''. Comparing against a bare empty string therefore
 * succeeds on MySQL and never succeeds on MariaDB, leaving the step stuck as
 * "pending" no matter how many times the update is run.
 */
function columnDefaultIsBlank(PDO $pdo, string $db, string $table, string $column): bool {
    $s = $pdo->prepare("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = :d AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $s->execute(['d' => $db, 't' => $table, 'c' => $column]);
    $default = $s->fetchColumn();

    if ($default === null || $default === false) { return true; }   // no default at all
    $default = trim((string)$default);

    // Strip one layer of MariaDB expression quoting: '' or "" around the value.
    if (strlen($default) >= 2
        && (($default[0] === "'" && substr($default, -1) === "'")
         || ($default[0] === '"' && substr($default, -1) === '"'))) {
        $default = substr($default, 1, -1);
    }

    return $default === '' || strcasecmp($default, 'NULL') === 0;
}

function buildSteps(PDO $pdo, string $db): array {
    $steps = [];

    // ── 1. Support tickets: photo attachment + advisor reply ──
    foreach ([
        ['attachment',  "ALTER TABLE customer_tickets ADD COLUMN attachment VARCHAR(255) NULL DEFAULT NULL AFTER message"],
        ['admin_reply', "ALTER TABLE customer_tickets ADD COLUMN admin_reply TEXT NULL DEFAULT NULL AFTER status"],
        ['replied_at',  "ALTER TABLE customer_tickets ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL AFTER admin_reply"],
        ['updated_at',  "ALTER TABLE customer_tickets ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER replied_at"],
    ] as [$col, $sql]) {
        $steps[] = [
            'group' => 'Support tickets — photo attachment & advisor reply',
            'label' => "customer_tickets.$col",
            'done'  => !tableExists($pdo, $db, 'customer_tickets') || columnExists($pdo, $db, 'customer_tickets', $col),
            'skip'  => !tableExists($pdo, $db, 'customer_tickets') ? 'table customer_tickets not present' : null,
            'sql'   => $sql,
        ];
    }

    /* ── The mail alarm's own table ──────────────────────────────────────────
       email_logs and inquiries were created by database/email_migrations.php,
       which nothing calls — runEmailSystemMigrations() has no callers anywhere
       in the codebase. Both tables exist on the installs that have been running
       a while, because something created them once; a fresh install, or a
       restore from a backup older than they are, gets neither.

       That failure is silent by design, which is what makes it worth closing.
       EmailService::logEmail() writes inside a catch, the dashboard's failed-
       email count catches PDOException and shows 0, and the header's "mail is
       broken" banner catches Throwable and sets $mailBroken = 0. So with the
       table absent every screen reports that mail is fine — which is precisely
       the state the dashboard comment says that block exists to detect.

       The DDL below is taken from the live tables with SHOW CREATE TABLE, not
       written from the readers' expectations, so a table created here matches
       the one already in service rather than a plausible guess at it.

       password_resets, the third table in that orphan file, is left alone: it
       is also created by config/db.php, so it already has a live owner. */
    $steps[] = [
        'group' => 'Email system',
        'label' => 'email_logs table (the failed-mail alarm reads this)',
        'done'  => tableExists($pdo, $db, 'email_logs'),
        'sql'   => "CREATE TABLE IF NOT EXISTS `email_logs` (
                        `id` INT(11) NOT NULL AUTO_INCREMENT,
                        `email_type` VARCHAR(50) NOT NULL,
                        `recipient` VARCHAR(150) NOT NULL,
                        `subject` VARCHAR(255) NOT NULL,
                        `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                        `error_message` TEXT NULL,
                        `test_mode` TINYINT(1) DEFAULT 0,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `email_type` (`email_type`),
                        KEY `recipient` (`recipient`),
                        KEY `status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    $steps[] = [
        'group' => 'Email system',
        'label' => 'inquiries table (contact form submissions)',
        'done'  => tableExists($pdo, $db, 'inquiries'),
        'sql'   => "CREATE TABLE IF NOT EXISTS `inquiries` (
                        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `name` VARCHAR(120) NOT NULL,
                        `email` VARCHAR(180) NOT NULL,
                        `phone` VARCHAR(30) NOT NULL DEFAULT '',
                        `message` TEXT NOT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    // ── 2. Size guide undo snapshots ──
    $steps[] = [
        'group' => 'Size guide — undo history',
        'label' => 'size_guide_snapshots table',
        'done'  => tableExists($pdo, $db, 'size_guide_snapshots'),
        'sql'   => "CREATE TABLE IF NOT EXISTS size_guide_snapshots (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        chart_id INT NOT NULL,
                        category_id INT NULL,
                        product_id INT NULL,
                        label VARCHAR(120) NULL,
                        payload LONGTEXT NOT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_sgs_chart (chart_id),
                        INDEX idx_sgs_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 3. Shop-wide settings table (holds the How-to-Measure image pointers) ──
    // Normally created by admin/settings.php on first visit. If that page has never
    // been opened on this install, uploading the size guide image saves the file but
    // has nowhere to record it — the upload then looks like it did nothing.
    $steps[] = [
        'group' => 'Shop settings & size guide images',
        'label' => 'store_settings table',
        'done'  => tableExists($pdo, $db, 'store_settings'),
        'sql'   => "CREATE TABLE IF NOT EXISTS `store_settings` (
                        `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
                        `setting_value` TEXT DEFAULT NULL,
                        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 4. One shop-wide BODY size table ──
    $steps[] = [
        'group' => 'Size guide — one shared body table',
        'label' => 'size_guide_body_global table',
        'done'  => tableExists($pdo, $db, 'size_guide_body_global'),
        'sql'   => "CREATE TABLE IF NOT EXISTS size_guide_body_global (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        gender ENUM('women','men') NOT NULL DEFAULT 'women',
                        size_label VARCHAR(20) NOT NULL,
                        numeric_size VARCHAR(20) NULL,
                        bust DECIMAL(6,2) NULL,
                        waist DECIMAL(6,2) NULL,
                        hips DECIMAL(6,2) NULL,
                        shoulder DECIMAL(6,2) NULL,
                        sort_order INT NOT NULL DEFAULT 0,
                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_gender_size (gender, size_label),
                        INDEX idx_sgbg_sort (sort_order)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // Pre-existing installs created this table before genders existed. The column
    // must be added (existing rows become 'women'), and the unique key must move
    // from size_label to (gender, size_label) — otherwise men's and women's XS
    // would collide on the old single-column key.
    $steps[] = [
        'group' => 'Size guide — gender-aware body table',
        'label' => 'add gender to size_guide_body_global',
        'done'  => columnExists($pdo, $db, 'size_guide_body_global', 'gender'),
        'skip'  => tableExists($pdo, $db, 'size_guide_body_global') ? '' : 'size_guide_body_global table does not exist here',
        'sql'   => "ALTER TABLE size_guide_body_global ADD COLUMN gender ENUM('women','men') NOT NULL DEFAULT 'women' AFTER id",
    ];

    $steps[] = [
        'group' => 'Size guide — gender-aware body table',
        'label' => 'unique key covers gender (women and men each have an XS)',
        'done'  => (function () use ($pdo, $db) {
            if (!tableExists($pdo, $db, 'size_guide_body_global')) { return true; }
            try {
                $st = $pdo->query("SHOW INDEX FROM size_guide_body_global WHERE Key_name = 'uniq_gender_size'");
                return (bool)$st->fetchColumn(2);
            } catch (PDOException $e) { return true; }
        })(),
        'skip'  => tableExists($pdo, $db, 'size_guide_body_global') ? '' : 'size_guide_body_global table does not exist here',
        'sql'   => "ALTER TABLE size_guide_body_global DROP INDEX uniq_size_label, ADD UNIQUE KEY uniq_gender_size (gender, size_label)",
    ];

    $steps[] = [
        'group' => 'Size guide — one shared body table',
        'label' => 'fill the body table with the standard size ladder',
        // Only when completely empty — never overwrites numbers already entered.
        // Counts WOMEN only: on a fresh install this must seed the women's ladder
        // even if a later step has already inserted the men's rows — ordering must
        // never let one gender's seed hide the other's. (Pre-gender tables have no
        // column, so the plain count falls back when gender is absent.)
        'done'  => tableExists($pdo, $db, 'size_guide_body_global')
                   && (int)$pdo->query("SELECT COUNT(*) FROM size_guide_body_global" . (columnExists($pdo, $db, 'size_guide_body_global', 'gender') ? " WHERE gender = 'women'" : ''))->fetchColumn() > 0,
        'needs' => 'size_guide_body_global table',
        'php'   => function (PDO $pdo) { return seedGlobalBodyRows($pdo); },
    ];

    $steps[] = [
        'group' => 'Size guide — gender-aware body table',
        'label' => 'fill the MEN\'s body ladder (chest 36\"-52\")',
        // Runs AFTER the women's fill step above, so a fresh install seeds both
        // ladders and neither ordering can hide the other. Only when no men's
        // rows exist — never overwrites numbers already entered.
        'done'  => columnExists($pdo, $db, 'size_guide_body_global', 'gender')
                   && (int)$pdo->query("SELECT COUNT(*) FROM size_guide_body_global WHERE gender = 'men'")->fetchColumn() > 0,
        'needs' => 'size_guide_body_global table',
        'php'   => function (PDO $pdo) { return seedMenGlobalBodyRows($pdo); },
    ];

    // ── Currency defaults ──
    // orders.currency defaulted to 'GBP' and currency_symbol to '£' — leftovers
    // from the site's UK origins. The shop sells in INR only, and every refund
    // row copies the order's currency, so a GBP label on rupee amounts would
    // now propagate into the refund records too.
    // Only the DEFAULT is changed; existing order rows are left untouched,
    // because an order's stored currency is part of its tax invoice.
    foreach ([['currency', 'INR'], ['currency_symbol', '₹']] as [$col, $want]) {
        $steps[] = [
            'group' => 'Currency — INR only',
            'label' => "orders.$col default -> $want",
            'done'  => !columnExists($pdo, $db, 'orders', $col)
                       || (function () use ($pdo, $db, $col, $want) {
                            $s = $pdo->prepare("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                                                WHERE TABLE_SCHEMA=:d AND TABLE_NAME='orders' AND COLUMN_NAME=:c");
                            $s->execute(['d' => $db, 'c' => $col]);
                            $cur = trim((string)$s->fetchColumn());
                            // MariaDB reports defaults as quoted expressions; MySQL does not.
                            if (strlen($cur) >= 2 && $cur[0] === "'" && substr($cur, -1) === "'") {
                                $cur = substr($cur, 1, -1);
                            }
                            return $cur === $want;
                          })(),
            'skip'  => !columnExists($pdo, $db, 'orders', $col) ? "orders.$col not present" : null,
            'sql'   => "ALTER TABLE orders ALTER COLUMN `$col` SET DEFAULT " . $pdo->quote($want),
        ];
    }

    // ── Soft delete for orders ──
    // The trash button ran DELETE FROM orders. An order row IS the GST tax
    // invoice — admin/print_invoice.php rebuilds the document live from it — so
    // one click destroyed a statutory record with no backup and no audit trail.
    $steps[] = [
        'group' => 'Orders — make delete reversible',
        'label' => 'orders.is_deleted column',
        'done'  => columnExists($pdo, $db, 'orders', 'is_deleted'),
        'sql'   => "ALTER TABLE orders ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0",
    ];
    $steps[] = [
        'group' => 'Orders — make delete reversible',
        'label' => 'orders.deleted_at column',
        'done'  => columnExists($pdo, $db, 'orders', 'deleted_at'),
        'sql'   => "ALTER TABLE orders ADD COLUMN deleted_at DATETIME NULL",
    ];
    $steps[] = [
        'group' => 'Orders — make delete reversible',
        'label' => 'orders index idx_orders_is_deleted',
        'done'  => indexExists($pdo, $db, 'orders', 'idx_orders_is_deleted'),
        'skip'  => !columnExists($pdo, $db, 'orders', 'is_deleted') ? 'is_deleted column not present yet — re-run' : null,
        'sql'   => "CREATE INDEX idx_orders_is_deleted ON orders (is_deleted)",
    ];

    // ── Delivery timestamp ──
    // The 14-day return window is advertised as running from DELIVERY
    // (pages/returns.php, and the printed invoice) but was measured from the
    // order date, because no delivery date was ever recorded. Customers lost
    // however many days the courier took — typically 2–5 of the 14.
    $steps[] = [
        'group' => 'Returns — honour the advertised window',
        'label' => 'orders.delivered_at column',
        'done'  => columnExists($pdo, $db, 'orders', 'delivered_at'),
        'sql'   => "ALTER TABLE orders ADD COLUMN delivered_at DATETIME NULL AFTER status",
    ];

    // ── Refunds ──
    // Before this, a refund had nowhere to live: no amount, no date, no Razorpay
    // reference. "Refunded" was only a word in the status dropdown. A separate
    // table (rather than columns on orders) so one order can carry several
    // partial refunds, which is the normal case when a customer keeps some items.
    $steps[] = [
        'group' => 'Refunds — real money back to customers',
        'label' => 'order_refunds table',
        'done'  => tableExists($pdo, $db, 'order_refunds'),
        'sql'   => "CREATE TABLE order_refunds (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        order_id INT NOT NULL,
                        order_code VARCHAR(30) NOT NULL DEFAULT '',
                        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        currency VARCHAR(10) NOT NULL DEFAULT 'INR',
                        method VARCHAR(20) NOT NULL DEFAULT 'manual',
                        razorpay_payment_id VARCHAR(100) NULL,
                        razorpay_refund_id VARCHAR(100) NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'pending',
                        reason VARCHAR(255) NULL,
                        notes TEXT NULL,
                        created_by INT NULL,
                        processed_at DATETIME NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_order_refunds_order (order_id),
                        INDEX idx_order_refunds_rzp (razorpay_refund_id),
                        INDEX idx_order_refunds_status (status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // Running total on the order itself, so "how much is left to refund?" is one
    // read rather than a SUM over the refund rows on every page load.
    $steps[] = [
        'group' => 'Refunds — real money back to customers',
        'label' => 'orders.refunded_amount column',
        'done'  => columnExists($pdo, $db, 'orders', 'refunded_amount'),
        'sql'   => "ALTER TABLE orders ADD COLUMN refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_price",
    ];

    // Lets you find "paid, but the order is dead" without a manual trawl.
    $steps[] = [
        'group' => 'Refunds — real money back to customers',
        'label' => 'orders index idx_orders_refund_watch',
        // 'done' must test only the index. Folding the column check in with || made
        // this report "already applied" on a database where the column did not yet
        // exist, so the index was silently never created. Absence is a 'skip'.
        'done'  => indexExists($pdo, $db, 'orders', 'idx_orders_refund_watch'),
        'skip'  => !columnExists($pdo, $db, 'orders', 'refunded_amount') ? 'refunded_amount column not present yet — re-run' : null,
        'sql'   => "CREATE INDEX idx_orders_refund_watch ON orders (payment_status, status)",
    ];

    // ── 5. products.color default ──
    // The column shipped with a default of '#ff6b9d,#c44dff' (a leftover hex swatch),
    // so every new product was created with a "colour" that is not a colour. It also
    // leaked into the SEO keywords meta tag until the generator started filtering it.
    // Set to empty rather than DROP DEFAULT: the column is NOT NULL and at least one
    // INSERT in the codebase omits it, which would fail under strict mode.
    $steps[] = [
        'group' => 'Product data tidy-up',
        'label' => "products.color default (remove the leftover hex swatch)",
        'done'  => !columnExists($pdo, $db, 'products', 'color')
                   || columnDefaultIsBlank($pdo, $db, 'products', 'color'),
        'skip'  => !columnExists($pdo, $db, 'products', 'color') ? 'products.color not present' : null,
        'sql'   => "ALTER TABLE products ALTER COLUMN color SET DEFAULT ''",
    ];

    // ── 6. Shipping & store defaults ──
    // Only inserted when absent, so an admin who has already set their own rates in
    // Store Settings is never overwritten.
    $steps[] = [
        'group' => 'Shipping & store defaults',
        'label' => 'seed home country, currency and shipping rates',
        'done'  => !tableExists($pdo, $db, 'store_settings')
                   || (int)$pdo->query("SELECT COUNT(*) FROM store_settings
                                        WHERE setting_key IN ('home_country','international_shipping_fee')")->fetchColumn() >= 2,
        'needs' => 'store_settings table',
        'php'   => function (PDO $pdo) {
            $defaults = [
                'home_country'               => 'IN',
                'default_currency'           => 'INR',
                'free_shipping_min'          => '2000',
                'standard_shipping_fee'      => '99',
                'international_shipping_fee' => '2500',
                // 'enable_currency_switcher' used to be seeded here. Dropped: nothing
                // ever read it, so a fresh install was writing a row that no code
                // consulted and an admin field that did nothing when flipped. The
                // header picker is decided by countrySelectorEnabled() instead —
                // true whenever more than one country is enabled — so selling abroad
                // needs no flag, only a country switched on.
            ];
            $ins = $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (:k, :v)
                                  ON DUPLICATE KEY UPDATE setting_key = setting_key");  // never overwrite
            $n = 0;
            foreach ($defaults as $k => $v) { $ins->execute(['k' => $k, 'v' => $v]); $n++; }
            return $n;
        },
    ];

    // ── 3. Products linked to categories by ID, plus filter indexes ──
    $steps[] = [
        'group' => 'Categories — real product link & search indexes',
        'label' => 'products.category_id column',
        'done'  => columnExists($pdo, $db, 'products', 'category_id'),
        'sql'   => "ALTER TABLE products ADD COLUMN category_id INT NULL DEFAULT NULL AFTER category",
    ];
    $steps[] = [
        'group' => 'Categories — real product link & search indexes',
        'label' => 'fill category_id from the existing category names',
        // Re-runnable: only touches rows still missing a link.
        'done'  => columnExists($pdo, $db, 'products', 'category_id')
                   && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id IS NULL AND category IS NOT NULL AND category <> ''")->fetchColumn() === 0,
        'needs' => 'products.category_id column',
        'sql'   => "UPDATE products p JOIN categories c ON LOWER(TRIM(c.name)) = LOWER(TRIM(p.category))
                    SET p.category_id = c.id WHERE p.category_id IS NULL",
    ];
    foreach ([
        ['products',   'idx_products_category_id', "ALTER TABLE products ADD INDEX idx_products_category_id (category_id)"],
        ['products',   'idx_products_available',   "ALTER TABLE products ADD INDEX idx_products_available (available)"],
        ['products',   'idx_products_avail_cat',   "ALTER TABLE products ADD INDEX idx_products_avail_cat (available, category_id)"],
        ['products',   'idx_products_brand',       "ALTER TABLE products ADD INDEX idx_products_brand (brand)"],
        ['products',   'idx_products_fabric',      "ALTER TABLE products ADD INDEX idx_products_fabric (fabric)"],
        ['products',   'idx_products_price',       "ALTER TABLE products ADD INDEX idx_products_price (price)"],
        ['categories', 'idx_categories_parent',    "ALTER TABLE categories ADD INDEX idx_categories_parent (parent_id)"],
    ] as [$tbl, $idx, $sql]) {
        $steps[] = [
            'group' => 'Categories — real product link & search indexes',
            'label' => "$tbl index $idx",
            'done'  => indexExists($pdo, $db, $tbl, $idx),
            'needs' => ($idx === 'idx_products_category_id' || $idx === 'idx_products_avail_cat') ? 'products.category_id column' : null,
            'sql'   => $sql,
        ];
    }
    $steps[] = [
        'group' => 'Categories — real product link & search indexes',
        'label' => 'foreign key products.category_id → categories.id',
        'done'  => fkExists($pdo, $db, 'products', 'fk_products_category'),
        'needs' => 'products.category_id column',
        // SET NULL, never CASCADE: deleting a category must not delete its products.
        'sql'   => "ALTER TABLE products ADD CONSTRAINT fk_products_category
                    FOREIGN KEY (category_id) REFERENCES categories(id)
                    ON DELETE SET NULL ON UPDATE CASCADE",
    ];

    // ── 8. Admin audit log ──
    // logAdminAction() in config/config.php has always INSERTed into
    // admin_audit_logs, but the table was never created. Its body ends in
    // `catch (Exception $e) {}`, so every failed INSERT was swallowed in
    // silence: 17 files across admin/ believe they are recording who deleted
    // a product, edited an order, or issued a refund, and none of it was
    // being written. Nothing errored, so nothing ever surfaced it.
    $steps[] = [
        'group' => 'Admin audit trail',
        'label' => 'admin_audit_logs table',
        'done'  => tableExists($pdo, $db, 'admin_audit_logs'),
        // Column names and types match exactly what logAdminAction() inserts.
        // ip_address is 45 chars to fit a full IPv6 address.
        'sql'   => "CREATE TABLE IF NOT EXISTS admin_audit_logs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        admin_id INT NOT NULL,
                        action VARCHAR(100) NOT NULL,
                        details TEXT NULL,
                        ip_address VARCHAR(45) NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_audit_admin (admin_id),
                        INDEX idx_audit_action (action),
                        INDEX idx_audit_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 9. customer_addresses.country default ──
    // Shipped as DEFAULT 'United Kingdom' on an India-only shop, and there is
    // no country field in the address form — so every address a customer saved
    // was recorded as United Kingdom. db.php uses CREATE TABLE IF NOT EXISTS,
    // which will not alter a table that already exists, hence this step.
    if (tableExists($pdo, $db, 'customer_addresses')) {
        $curDefault = null;
        try {
            $st = $pdo->prepare("SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
                                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'customer_addresses'
                                   AND COLUMN_NAME = 'country'");
            $st->execute([':db' => $db]);
            $curDefault = $st->fetchColumn();
        } catch (PDOException $e) {}

        // MariaDB reports defaults as quoted expressions ("'India'"), MySQL as
        // the bare value. Strip quotes before comparing so this does not report
        // "not applied" forever on one of them.
        $normalised = trim((string)$curDefault, "'\"");

        $steps[] = [
            'group' => 'India-only clean-up',
            'label' => "customer_addresses.country default → India",
            'done'  => $normalised === 'India',
            'sql'   => "ALTER TABLE customer_addresses ALTER COLUMN `country` SET DEFAULT 'India'",
        ];
    }

    // ── 10. Shop-wide default size guide chart ──
    // A chart with category_id AND product_id both NULL. pages/product.php falls
    // back to it when a product has no chart of its own, its category has none,
    // and no ancestor category has one either — which is exactly what happens
    // with a brand-new TOP-LEVEL category. Before this, such products showed the
    // body table and the diagram but no garment column at all.
    //
    // Seeded from size_guide_body_global plus the same ease the existing Kurtis
    // chart uses (bust +3", waist +3", hips +2", shoulder +0.5"), so it is a
    // sensible starting point rather than a blank table. It is editable in
    // Admin -> Size Guide like any other chart.
    if (tableExists($pdo, $db, 'size_guide_charts') && tableExists($pdo, $db, 'size_guide_body_global')) {
        $hasGlobal = (int)$pdo->query(
            "SELECT COUNT(*) FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL"
        )->fetchColumn() > 0;

        $steps[] = [
            'group' => 'Size guide — shop-wide default',
            'label' => 'default chart used by categories with no chart of their own',
            'done'  => $hasGlobal,
            'skip'  => (int)$pdo->query("SELECT COUNT(*) FROM size_guide_body_global")->fetchColumn() === 0
                       ? 'the global body ladder is empty — fill that in first'
                       : null,
            // The runner does $pdo->exec($s['sql']) with a single string, so the
            // chart row and its garment rows are two separate steps.
            'sql'   => "INSERT INTO size_guide_charts
                    (category_id, product_id, unit,
                     instructions_shoulder, instructions_bust, instructions_waist, instructions_hips, instructions_length)
                 VALUES
                    (NULL, NULL, 'in',
                     'Measure straight across the back, from the tip of one shoulder to the other.',
                     'Measure around the fullest part of the bust, keeping the tape level.',
                     'Measure around the narrowest part of the natural waist.',
                     'Measure around the fullest part of the hips.',
                     'Measure from the shoulder seam straight down to the desired hem.')",
        ];

        // Garment rows for that chart. Separate step because the runner executes
        // one statement per step; skips itself until the chart row above exists.
        $globalChartId = (int)$pdo->query(
            "SELECT id FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        $globalRowCount = $globalChartId > 0
            ? (int)$pdo->query("SELECT COUNT(*) FROM size_guide_content WHERE chart_id = {$globalChartId} AND measurement_type = 'garment'")->fetchColumn()
            : 0;

        $steps[] = [
            'group' => 'Size guide — shop-wide default',
            'label' => 'garment measurements for the default chart',
            'done'  => $globalRowCount > 0,
            'skip'  => $globalChartId === 0 ? 'default chart not created yet — re-run' : null,
            'sql'   => "INSERT INTO size_guide_content
                    (chart_id, measurement_type, size_label, numeric_size, bust, waist, hips, shoulder, length, sort_order)
                 SELECT {$globalChartId}, 'garment', b.size_label, b.numeric_size,
                        b.bust + 3.0, b.waist + 3.0, b.hips + 2.0, b.shoulder + 0.5, NULL, b.sort_order
                 FROM size_guide_body_global b
                 -- The shop-wide fallback chart is the WOMEN's default (a men's
                 -- product resolves to the men's default instead), so only the
                 -- women's ladder feeds it — the men's rows would double every
                 -- size label into the same chart.
                 WHERE b.gender = 'women' OR b.gender IS NULL",
        ];
    }

    // ── 11. Sell-in-country control ──
    // Which countries the shop is actually open to. Nothing is offered to
    // shoppers until a row here is enabled, so turning on a new market is a
    // deliberate switch in admin rather than a side effect of adding a price.
    //
    // India is seeded enabled; the rest are seeded DISABLED so behaviour is
    // identical to today until the owner says otherwise.
    $steps[] = [
        'group' => 'International selling',
        'label' => 'store_countries table',
        'done'  => tableExists($pdo, $db, 'store_countries'),
        'sql'   => "CREATE TABLE IF NOT EXISTS store_countries (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        country_code CHAR(2) NOT NULL UNIQUE,
                        country_name VARCHAR(80) NOT NULL,
                        currency_code CHAR(3) NOT NULL,
                        currency_symbol VARCHAR(8) NOT NULL,
                        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                        is_home TINYINT(1) NOT NULL DEFAULT 0,
                        cod_allowed TINYINT(1) NOT NULL DEFAULT 0,
                        shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        free_shipping_min DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        sort_order INT NOT NULL DEFAULT 0,
                        INDEX idx_country_enabled (is_enabled)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    if (tableExists($pdo, $db, 'store_countries')) {
        $countryRows = (int)$pdo->query("SELECT COUNT(*) FROM store_countries")->fetchColumn();
        $steps[] = [
            'group' => 'International selling',
            'label' => 'seed countries (India enabled, others off)',
            'done'  => $countryRows > 0,
            // COD only for India: cash cannot be collected by an international courier.
            'sql'   => "INSERT INTO store_countries
                            (country_code, country_name, currency_code, currency_symbol, is_enabled, is_home, cod_allowed, shipping_fee, free_shipping_min, sort_order)
                        VALUES
                            ('IN', 'India',          'INR', '₹', 1, 1, 1,   99.00, 2000.00, 1),
                            ('GB', 'United Kingdom', 'GBP', '£', 0, 0, 0,   25.00,  250.00, 2),
                            ('US', 'United States',  'USD', '\$', 0, 0, 0,  30.00,  300.00, 3),
                            ('AE', 'United Arab Emirates', 'AED', 'AED ', 0, 0, 0, 90.00, 900.00, 4)",
        ];
    }

    // Per-country product prices. Deliberately a stored price per market, NOT a
    // conversion: £59 is a price you choose, covering duty, freight and
    // positioning. A live rate would give £47.49 and would silently eat margin
    // every time the rupee moved.
    //
    // No row for a country simply means that product is not sold there, which
    // doubles as the "hide this product abroad" control.
    $steps[] = [
        'group' => 'International selling',
        'label' => 'product_country_prices table',
        'done'  => tableExists($pdo, $db, 'product_country_prices'),
        'sql'   => "CREATE TABLE IF NOT EXISTS product_country_prices (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        product_id INT NOT NULL,
                        country_code CHAR(2) NOT NULL,
                        price DECIMAL(10,2) NOT NULL,
                        sale_price DECIMAL(10,2) NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_product_country (product_id, country_code),
                        INDEX idx_pcp_country (country_code)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 11b. Per-country value of a FIXED-amount promo code ──
    //
    // promo_codes holds one discount_value and one min_order, typed in the
    // shop's own currency. Nothing in Dievon converts currency — each country
    // carries its own prices — so a ₹50-off code means nothing to a shopper
    // paying in pounds until a pound figure exists. Without this table a
    // ₹50 code was advertised abroad as "£50.00 off over £300.00", roughly a
    // hundred times the real discount, while the validator refused the same
    // shopper against a ₹300 minimum their basket could never reach.
    //
    // A code with no row for a country is simply not offered there, which is
    // the honest answer when there is no rate to convert with. Percentages
    // need no row at all: 10% is 10% in any currency.
    $steps[] = [
        'group' => 'International selling',
        'label' => 'promo_country_values table',
        'done'  => tableExists($pdo, $db, 'promo_country_values'),
        'sql'   => "CREATE TABLE IF NOT EXISTS promo_country_values (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        promo_id INT NOT NULL,
                        country_code CHAR(2) NOT NULL,
                        discount_value DECIMAL(10,2) NULL,
                        min_order DECIMAL(10,2) NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_promo_country (promo_id, country_code),
                        INDEX idx_pcv_country (country_code)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 12. Complete the garment measurement set ──
    // A shopper buying a kurti online cannot try it on, so the listing has to
    // answer every question a fitting room would. Seven of the eleven expected
    // fields already existed; these are the missing ones.
    //
    // Placement is deliberate:
    //   sleeve            -> per SIZE, next to bust/waist/hips (it varies by size)
    //   tolerance         -> per CHART, it is a making policy ("± 0.5 in")
    //   fit / model info  -> per PRODUCT, since the model wore that one garment
    foreach ([
        ['size_guide_content', 'sleeve',
         "ALTER TABLE size_guide_content ADD COLUMN sleeve DECIMAL(6,2) NULL AFTER shoulder"],
        ['size_guide_charts', 'instructions_sleeve',
         "ALTER TABLE size_guide_charts ADD COLUMN instructions_sleeve TEXT NULL AFTER instructions_length"],
        ['size_guide_charts', 'tolerance',
         "ALTER TABLE size_guide_charts ADD COLUMN tolerance VARCHAR(40) NULL AFTER unit"],
        ['products', 'fit_description',
         "ALTER TABLE products ADD COLUMN fit_description VARCHAR(255) NULL"],
        ['products', 'model_height',
         "ALTER TABLE products ADD COLUMN model_height VARCHAR(40) NULL"],
        ['products', 'model_size_worn',
         "ALTER TABLE products ADD COLUMN model_size_worn VARCHAR(20) NULL"],
    ] as [$table, $col, $sql]) {
        $steps[] = [
            'group' => 'Product measurements — complete the set',
            'label' => "$table.$col",
            'done'  => !tableExists($pdo, $db, $table) || columnExists($pdo, $db, $table, $col),
            'skip'  => !tableExists($pdo, $db, $table) ? "table $table not present" : null,
            'sql'   => $sql,
        ];
    }

    // A default tolerance so the field is never blank on an existing chart.
    // Hand-finished garments genuinely vary; stating the tolerance up front
    // prevents a return over half an inch.
    if (tableExists($pdo, $db, 'size_guide_charts') && columnExists($pdo, $db, 'size_guide_charts', 'tolerance')) {
        $steps[] = [
            'group' => 'Product measurements — complete the set',
            'label' => 'seed a default measurement tolerance on existing charts',
            'done'  => (int)$pdo->query("SELECT COUNT(*) FROM size_guide_charts WHERE tolerance IS NULL OR tolerance = ''")->fetchColumn() === 0,
            'sql'   => "UPDATE size_guide_charts SET tolerance = '± 0.5 in' WHERE tolerance IS NULL OR tolerance = ''",
        ];
    }

    // ── 13. Review integrity ──
    // The submit endpoint accepted anonymous posts, had no CSRF token, defaulted
    // the author to the literal string "Verified Customer" and the title to
    // "Exquisite quality" — so an unauthenticated stranger could file praise
    // attributed to a verified buyer. These columns back the real checks.
    if (tableExists($pdo, $db, 'product_reviews')) {
        foreach ([
            ['is_verified_purchase',
             "ALTER TABLE product_reviews ADD COLUMN is_verified_purchase TINYINT(1) NOT NULL DEFAULT 0"],
            ['order_id',
             "ALTER TABLE product_reviews ADD COLUMN order_id INT NULL"],
            ['reported_count',
             "ALTER TABLE product_reviews ADD COLUMN reported_count INT NOT NULL DEFAULT 0"],
        ] as [$col, $sql]) {
            $steps[] = [
                'group' => 'Review integrity',
                'label' => "product_reviews.$col",
                'done'  => columnExists($pdo, $db, 'product_reviews', $col),
                'sql'   => $sql,
            ];
        }

        // One review per customer per product, enforced by the DATABASE rather
        // than by a SELECT-then-INSERT check — two submissions racing each other
        // both pass an application check and both insert.
        $steps[] = [
            'group' => 'Review integrity',
            'label' => 'one review per customer per product (unique index)',
            'done'  => indexExists($pdo, $db, 'product_reviews', 'uniq_review_customer_product'),
            'skip'  => !columnExists($pdo, $db, 'product_reviews', 'customer_id')
                       ? 'customer_id column not present yet — re-run'
                       : null,
            // Rows with a NULL customer_id (legacy anonymous reviews) are exempt:
            // MySQL treats NULLs as distinct in a unique index, which is what we
            // want — historic data is left alone, new rows always have an owner.
            'sql'   => "CREATE UNIQUE INDEX uniq_review_customer_product ON product_reviews (customer_id, product_id)",
        ];
    }

    // Report-a-review. Kept in its own table so one abusive review can gather
    // several independent reports, and so a report never mutates the review.
    $steps[] = [
        'group' => 'Review integrity',
        'label' => 'review_reports table',
        'done'  => tableExists($pdo, $db, 'review_reports'),
        'sql'   => "CREATE TABLE IF NOT EXISTS review_reports (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        review_id INT NOT NULL,
                        customer_id INT NULL,
                        reason VARCHAR(60) NOT NULL,
                        details VARCHAR(500) NULL,
                        ip_address VARCHAR(45) NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_report_review (review_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // Proof that a customer's erasure request was carried out, holding nothing
    // that could identify them. Only a SHA-256 of the email is kept, so the
    // question "did you delete my account?" can be answered by hashing the
    // address the asker supplies — without us having retained the address we
    // were asked to erase. Order rows survive deletion because Indian tax law
    // requires eight years of them; this table is how we show the rest did not.
    $steps[] = [
        'group' => 'Account security',
        'label' => 'account_deletions table',
        'done'  => tableExists($pdo, $db, 'account_deletions'),
        'sql'   => "CREATE TABLE IF NOT EXISTS account_deletions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        email_hash CHAR(64) NOT NULL,
                        deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        ip_address VARCHAR(45) NULL,
                        INDEX idx_deletion_hash (email_hash)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // "Keep me signed in". Split into a public selector and a secret validator:
    // the selector is indexed and finds the row, only the validator's SHA-256 is
    // stored, and it is replaced on every use. A leak of this table therefore
    // yields no usable cookie, and a copied cookie stops working as soon as
    // either the thief or the real owner loads a page.
    $steps[] = [
        'group' => 'Account security',
        'label' => 'customer_remember_tokens table',
        'done'  => tableExists($pdo, $db, 'customer_remember_tokens'),
        'sql'   => "CREATE TABLE IF NOT EXISTS customer_remember_tokens (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        customer_id INT NOT NULL,
                        selector CHAR(32) NOT NULL,
                        validator_hash CHAR(64) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        user_agent VARCHAR(255) NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_remember_selector (selector),
                        INDEX idx_remember_customer (customer_id),
                        INDEX idx_remember_expires (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 14. Login throttling ──
    // Nothing stood between an attacker and unlimited password guesses. Records
    // one row per failed attempt so both the account and the source address can
    // be throttled: per-account alone lets one attacker spray many accounts,
    // per-IP alone lets a botnet through.
    $steps[] = [
        'group' => 'Account security',
        'label' => 'login_attempts table',
        'done'  => tableExists($pdo, $db, 'login_attempts'),
        'sql'   => "CREATE TABLE IF NOT EXISTS login_attempts (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        identifier VARCHAR(191) NOT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_attempt_id_time (identifier, attempted_at),
                        INDEX idx_attempt_ip_time (ip_address, attempted_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 15. Unique phone numbers ──
    // Registration now rejects a duplicate phone, but an application check alone
    // loses a race: two simultaneous sign-ups both pass the SELECT and both
    // INSERT. Only the database can actually guarantee it.
    //
    // Deliberately SKIPPED rather than forced when duplicates already exist —
    // adding the index would fail, and the alternative (deleting rows to make it
    // fit) would destroy real customer accounts. The owner resolves those first.
    if (tableExists($pdo, $db, 'customers') && columnExists($pdo, $db, 'customers', 'phone')) {
        $dupPhones = (int)$pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT phone FROM customers
                 WHERE phone IS NOT NULL AND phone <> ''
                 GROUP BY phone HAVING COUNT(*) > 1
             ) d"
        )->fetchColumn();

        $steps[] = [
            'group' => 'Account security',
            'label' => 'customers.phone unique index',
            'done'  => indexExists($pdo, $db, 'customers', 'uniq_customer_phone'),
            'skip'  => $dupPhones > 0
                ? "{$dupPhones} phone number(s) are used by more than one account — merge or change them first, then re-run"
                : null,
            // NULL and '' are exempt: MySQL treats NULLs as distinct in a unique
            // index, and phone is optional at registration.
            'sql'   => "CREATE UNIQUE INDEX uniq_customer_phone ON customers (phone)",
        ];
    }

    // ── 16. Real admin accounts ──
    // Admin sign-in compared two plain strings against ADMIN_USERNAME and
    // ADMIN_PASSWORD constants in config/config.php — a file that gets uploaded
    // to the server. One shared credential, stored in plaintext, with no way to
    // give a member of staff their own login or to take one away.
    //
    // role is created now rather than later so permissions have somewhere to
    // live without a second migration.
    $steps[] = [
        'group' => 'Admin accounts',
        'label' => 'admin_users table',
        'done'  => tableExists($pdo, $db, 'admin_users'),
        'sql'   => "CREATE TABLE IF NOT EXISTS admin_users (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        username VARCHAR(80) NOT NULL UNIQUE,
                        password_hash VARCHAR(255) NOT NULL,
                        full_name VARCHAR(120) NULL,
                        email VARCHAR(191) NULL,
                        role ENUM('owner','catalogue','orders') NOT NULL DEFAULT 'orders',
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                        last_login_at DATETIME NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_admin_active (is_active)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // Who signed in, when, from where, and whether it worked. Without this there
    // is no way to answer "was that me?" after something changes in the panel.
    $steps[] = [
        'group' => 'Admin accounts',
        'label' => 'admin_login_history table',
        'done'  => tableExists($pdo, $db, 'admin_login_history'),
        'sql'   => "CREATE TABLE IF NOT EXISTS admin_login_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        admin_id INT NULL,
                        username VARCHAR(80) NOT NULL,
                        succeeded TINYINT(1) NOT NULL,
                        ip_address VARCHAR(45) NULL,
                        user_agent VARCHAR(255) NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_alh_time (created_at),
                        INDEX idx_alh_admin (admin_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // Seed the owner from the existing constants so the current credentials keep
    // working — hashed this time. The password is NOT changed here: locking the
    // owner out of their own shop during an upgrade would be worse than the
    // weak password it replaces. must_change_password flags it for later.
    if (tableExists($pdo, $db, 'admin_users')) {
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
        // A generated password, shown once on this page, rather than one written
        // into config/config.php. The constant this used to read has been deleted
        // precisely so no admin password lives in a file in the web root.
        // must_change_password = 1 forces it to be replaced at first sign-in.
        $seedPassword = bin2hex(random_bytes(6));
        $GLOBALS['dievon_seed_password'] = $seedPassword;
        $steps[] = [
            'group' => 'Admin accounts',
            'label' => 'seed the owner account with a generated password (shown after the update runs)',
            'done'  => $adminCount > 0,
            'sql'   => "INSERT INTO admin_users (username, password_hash, full_name, role, is_active, must_change_password)
                        VALUES ("
                        . $pdo->quote(defined('ADMIN_USERNAME') ? ADMIN_USERNAME : 'admin') . ", "
                        . $pdo->quote(password_hash($seedPassword, PASSWORD_DEFAULT)) . ", "
                        . "'Shop Owner', 'owner', 1, 1)",
        ];
    }

    // ── 17. Admin two-factor ──
    // Authenticator app, not SMS: a SIM-swap defeats SMS, and it is the factor
    // people most often assume is the safe one. recovery_codes exists so a lost
    // phone is an inconvenience rather than a locked shop needing a DBA.
    if (tableExists($pdo, $db, 'admin_users')) {
        foreach ([
            ['totp_secret',    "ALTER TABLE admin_users ADD COLUMN totp_secret VARCHAR(64) NULL"],
            ['totp_enabled',   "ALTER TABLE admin_users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0"],
            ['recovery_codes', "ALTER TABLE admin_users ADD COLUMN recovery_codes TEXT NULL"],
        ] as [$col, $sql]) {
            $steps[] = [
                'group' => 'Admin accounts',
                'label' => "admin_users.$col",
                'done'  => columnExists($pdo, $db, 'admin_users', $col),
                'sql'   => $sql,
            ];
        }
    }

    // ── 18. Concurrent edit protection ──
    // Two admins opening the same product both save the whole form, so the
    // second silently overwrites the first — with no warning and nothing in the
    // audit log to explain why an edit vanished. updated_at gives the form a
    // value to compare against on save.
    $steps[] = [
        'group' => 'Admin safety',
        'label' => 'products.updated_at',
        'done'  => columnExists($pdo, $db, 'products', 'updated_at'),
        'sql'   => "ALTER TABLE products ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    // ── 19. Image alt text ──
    // Alt text is what a blind shopper hears in place of the photograph, and
    // what Google Images indexes — which for a fashion catalogue is real
    // traffic. There was no field and no column, so every product image was
    // published with nothing behind it.
    $steps[] = [
        'group' => 'Product data quality',
        'label' => 'products.image_alt',
        'done'  => columnExists($pdo, $db, 'products', 'image_alt'),
        'sql'   => "ALTER TABLE products ADD COLUMN image_alt VARCHAR(160) NULL",
    ];

    // ── 20. Per-category SEO ──
    // Every category page shared one title ("Collections"), one H1 ("Dievon
    // Curated Catalog") and one meta description, so to Google they read as the
    // same page repeated N times and none of them could rank for its own term.
    // A category also had no stable URL of its own — only ?category=<name>,
    // which breaks the moment the category is renamed.
    foreach ([
        'slug'             => "VARCHAR(120) NULL",
        'meta_title'       => "VARCHAR(180) NULL",
        'meta_description' => "VARCHAR(320) NULL",
        'intro'            => "TEXT NULL",
        'image'            => "VARCHAR(255) NULL",
        'image_alt'        => "VARCHAR(200) NULL",
    ] as $col => $type) {
        $steps[] = [
            'group' => 'Category SEO',
            'label' => "categories.$col",
            'done'  => columnExists($pdo, $db, 'categories', $col),
            'sql'   => "ALTER TABLE categories ADD COLUMN $col $type",
        ];
    }

    // Backfill a URL slug for every existing category. Runs only once — after
    // this, slugs are owned by the admin form and are never auto-rewritten,
    // because changing a slug changes a live URL that Google has already
    // indexed and that customers may have bookmarked.
    $steps[] = [
        'group' => 'Category SEO',
        'label' => 'fill in a URL slug for each existing category',
        'done'  => columnExists($pdo, $db, 'categories', 'slug')
                   && (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE slug IS NULL OR slug = ''")->fetchColumn() === 0,
        'php'   => function (PDO $pdo) {
            $rows = $pdo->query("SELECT id, name FROM categories WHERE slug IS NULL OR slug = ''")->fetchAll();
            $taken = $pdo->query("SELECT slug FROM categories WHERE slug IS NOT NULL AND slug <> ''")->fetchAll(PDO::FETCH_COLUMN);
            $taken = array_flip($taken);
            $upd = $pdo->prepare("UPDATE categories SET slug = :slug WHERE id = :id");
            $n = 0;
            foreach ($rows as $r) {
                $base = slugify($r['name'], 100);
                // Two categories may legitimately share a name under different
                // parents ("Tops" under Women's and under Sets); a URL cannot.
                $slug = $base; $i = 2;
                while (isset($taken[$slug])) { $slug = $base . '-' . $i; $i++; }
                $taken[$slug] = true;
                $upd->execute(['slug' => $slug, 'id' => $r['id']]);
                $n++;
            }
            return $n;
        },
    ];

    $steps[] = [
        'group' => 'Category SEO',
        'label' => 'unique index on categories.slug',
        'done'  => indexExists($pdo, $db, 'categories', 'uniq_category_slug'),
        'sql'   => "ALTER TABLE categories ADD UNIQUE KEY uniq_category_slug (slug)",
    ];

    // store_settings is a key/value table (setting_key, setting_value), not a
    // column per setting. An earlier build of this file added a
    // google_site_verification COLUMN to it, which every row would have had to
    // carry and which nothing reads. Remove it if a previous run created it; the
    // Search Console token lives in a normal row instead, written by admin/seo.php.
    $steps[] = [
        'group' => 'Category SEO',
        'label' => 'remove the stray store_settings.google_site_verification column',
        'done'  => !columnExists($pdo, $db, 'store_settings', 'google_site_verification'),
        'sql'   => "ALTER TABLE store_settings DROP COLUMN google_site_verification",
    ];

    // Renaming a collection's address breaks every link to the old one — search
    // results, anything a customer bookmarked, anything shared on WhatsApp. This
    // records the previous address so the old URL keeps working with a permanent
    // redirect instead of turning into a 404 months after the change.
    $steps[] = [
        'group' => 'Category SEO',
        'label' => 'category_slug_history table',
        'done'  => tableExists($pdo, $db, 'category_slug_history'),
        'sql'   => "CREATE TABLE category_slug_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        old_slug VARCHAR(120) NOT NULL,
                        category_id INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_old_slug (old_slug),
                        KEY idx_category (category_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 21. Business identity published in the policy pages ──
    // The privacy notice and the terms must name the business, say where disputes
    // are heard, and give a grievance officer — the Consumer Protection
    // (E-Commerce) Rules, 2020 require a named officer with contact details, a
    // reply within 48 hours and a resolution within a month. store_settings is a
    // key/value table, so these are rows, not columns; seeding them blank means
    // the admin form shows the fields and the pages show a marked gap until the
    // owner fills them in.
    $steps[] = [
        'group' => 'Legal identity',
        'label' => 'store_settings rows for the published business identity',
        'done'  => (int)$pdo->query(
            "SELECT COUNT(*) FROM store_settings WHERE setting_key IN
             ('company_cin','jurisdiction_city','courier_partners',
              'grievance_officer_name','grievance_officer_email','grievance_officer_phone')"
        )->fetchColumn() === 6,
        'skip'  => tableExists($pdo, $db, 'store_settings') ? '' : 'store_settings is created earlier in this run',
        'php'   => function (PDO $pdo) {
            $keys = ['company_cin', 'jurisdiction_city', 'courier_partners',
                     'grievance_officer_name', 'grievance_officer_email', 'grievance_officer_phone'];
            // INSERT IGNORE, never UPDATE — a value the owner has already typed
            // must survive re-running this page.
            $ins = $pdo->prepare("INSERT IGNORE INTO store_settings (setting_key, setting_value) VALUES (:k, '')");
            $n = 0;
            foreach ($keys as $k) { $ins->execute(['k' => $k]); $n += $ins->rowCount(); }
            return $n;
        },
    ];

    // ── 22. Retire the "worldwide shipping" claims ──
    //
    // seo_settings is DATA, not code — so correcting these locally does nothing to
    // a live site, and uploading files does not carry them across. The seeded
    // descriptions promised "Free Worldwide Shipping" and "Worldwide Express
    // Delivery" on a shop that sells only within India, has no country enabled for
    // selling, and has no courier account abroad.
    //
    // Only rows that STILL contain the claim are touched, so anything the owner has
    // since written themselves is left exactly as it is.
    $steps[] = [
        'group' => 'Truthful copy',
        'label' => 'remove "worldwide shipping" from the page descriptions',
        'done'  => !tableExists($pdo, $db, 'seo_settings')
                   || (int)$pdo->query(
                        "SELECT COUNT(*) FROM seo_settings
                          WHERE meta_description REGEXP 'worldwide|express deliver'
                             OR meta_title       REGEXP 'worldwide|express deliver'
                             OR meta_keywords    REGEXP 'worldwide|express deliver'"
                      )->fetchColumn() === 0,
        'skip'  => tableExists($pdo, $db, 'seo_settings') ? '' : 'seo_settings does not exist here',
        'php'   => function (PDO $pdo) {
            $replacements = [
                'home' => 'Hand-finished Indian womenswear from Dievon — kurtis, three-piece suits, '
                        . 'co-ord sets, tops and bottoms, with detailed size guides and easy returns across India.',
                'shop' => 'Browse the full Dievon collection — kurtis, three-piece suits, co-ord sets, '
                        . "women's tops and bottoms. Detailed size guides, secure checkout, easy returns.",
            ];
            $upd = $pdo->prepare("UPDATE seo_settings SET meta_description = :d WHERE page_slug = :s");
            $n = 0;
            foreach ($replacements as $slug => $desc) {
                $chk = $pdo->prepare(
                    "SELECT COUNT(*) FROM seo_settings
                      WHERE page_slug = :s AND meta_description REGEXP 'worldwide|express deliver'"
                );
                $chk->execute(['s' => $slug]);
                if ((int)$chk->fetchColumn() > 0) { $upd->execute(['d' => $desc, 's' => $slug]); $n++; }
            }
            // Any other row that still carries the claim: strip the offending
            // sentence rather than rewriting copy this file cannot know.
            foreach ($pdo->query(
                "SELECT id, meta_description FROM seo_settings
                  WHERE meta_description REGEXP 'worldwide|express deliver'"
            ) as $row) {
                $clean = preg_replace('/[^.]*\b(worldwide|express deliver)[^.]*\.\s*/i', '', (string)$row['meta_description']);
                $pdo->prepare("UPDATE seo_settings SET meta_description = :d WHERE id = :id")
                    ->execute(['d' => trim($clean), 'id' => $row['id']]);
                $n++;
            }
            // Keywords too.
            foreach ($pdo->query(
                "SELECT id, meta_keywords FROM seo_settings WHERE meta_keywords REGEXP 'worldwide|express deliver'"
            ) as $row) {
                $kept = array_filter(array_map('trim', explode(',', (string)$row['meta_keywords'])),
                    fn($k) => !preg_match('/worldwide|express deliver/i', $k));
                $pdo->prepare("UPDATE seo_settings SET meta_keywords = :k WHERE id = :id")
                    ->execute(['k' => implode(', ', $kept), 'id' => $row['id']]);
                $n++;
            }
            return $n;
        },
    ];

    // ── 23. Suppliers became its own permission ──
    //
    // suppliers.php used to sit behind catalogue.manage, and the tick for that
    // read "Products, categories, brands, colours and sizes" — so handing someone
    // the catalogue also handed them the name and phone number of every supplier
    // the shop buys from, without saying so. It is now its own capability.
    //
    // This step exists so the split does not take access away from anyone who
    // already had it: every stored role holding catalogue.manage gains
    // suppliers.manage. Without it, deploying the code quietly removes the
    // Suppliers link from a catalogue manager's sidebar and 403s the page.
    //
    // The owner role is ['*'] and is skipped — a wildcard already covers it.
    // ── 24. Point every staff account at a real role row ──
    //
    // admin_users has BOTH a legacy `role` string ('owner'/'catalogue'/'orders')
    // and a `role_id` pointing at admin_roles. Accounts created before roles
    // existed have role_id NULL, and everything that reads permissions falls back
    // to the hardcoded ADMIN_CAPABILITIES array in config.php when it is:
    //
    //   admin_users.php:192  $baseCaps = role_id ? <from admin_roles> : ADMIN_CAPABILITIES[role]
    //   config.php:1237      $caps     = ADMIN_CAPABILITIES[$legacyRole] ?? ...
    //
    // So editing a role on Roles & Permissions saved correctly, reported success,
    // and changed nothing at all for those accounts — not the checkboxes shown on
    // Staff Accounts, and not what the person could actually do. A screen that
    // appears to work and silently does nothing is worse than one that errors.
    //
    // The slugs in admin_roles are exactly the legacy strings, so the two can be
    // joined directly. Only NULL rows are touched, so an account deliberately
    // moved to a custom role keeps it, and re-running changes nothing.
    // ── 24a. Clear permission overrides left by deleted staff accounts ──
    //
    // admin_user_capabilities has no foreign key, so deleting a staff account
    // used to leave their grant/deny rows behind. MySQL reissues deleted ids —
    // the next account created can take the id of the one removed and inherit
    // those permissions without anyone granting them. Found in the wild: rows
    // for admin_id 2 after that account was deleted.
    //
    // The handler now deletes them alongside the account; this clears whatever
    // was orphaned before that fix existed.
    $steps[] = [
        'group' => 'Permissions',
        'label' => 'clear per-person permission exceptions (feature retired)',
        // Now clears ALL rows, not only orphans. The screen that displayed and
        // edited these is gone, and a permission that still applies with nowhere
        // to see it is worse than no feature at all — nobody can audit it and
        // nobody can take it away.
        //
        // ANYONE RELYING ON AN EXCEPTION LOSES IT. Grant it on their role instead.
        'done'  => !tableExists($pdo, $db, 'admin_user_capabilities')
                   || (int)$pdo->query("SELECT COUNT(*) FROM admin_user_capabilities")->fetchColumn() === 0,
        'skip'  => tableExists($pdo, $db, 'admin_user_capabilities') ? '' : 'table does not exist here',
        'php'   => function (PDO $pdo) {
            $st = $pdo->prepare("DELETE FROM admin_user_capabilities");
            $st->execute();
            return $st->rowCount();
        },
    ];

    $steps[] = [
        'group' => 'Permissions',
        'label' => 'point staff accounts with no role_id at their matching role row',
        // NULL is not the only broken state. Deleting a role leaves every staff
        // account that held it pointing at an id that no longer exists, and
        // `isset($roleCaps[$myRoleId])` is false for a missing row exactly as it
        // is for NULL — so those accounts fall back to the hardcoded preset too,
        // while the screen shows a role_id and looks correctly configured.
        // Found on a live account: role_id = 5, admin_roles had no row 5.
        'done'  => !tableExists($pdo, $db, 'admin_roles')
                   || !columnExists($pdo, $db, 'admin_users', 'role_id')
                   || (int)$pdo->query(
                        "SELECT COUNT(*) FROM admin_users u
                           JOIN admin_roles r ON r.slug = u.role
                      LEFT JOIN admin_roles cur ON cur.id = u.role_id
                          WHERE u.role_id IS NULL OR cur.id IS NULL"
                      )->fetchColumn() === 0,
        'skip'  => tableExists($pdo, $db, 'admin_roles') ? '' : 'admin_roles does not exist here',
        'php'   => function (PDO $pdo) {
            // Repairs both: never assigned, and assigned to a role since deleted.
            $st = $pdo->prepare(
                "UPDATE admin_users u
                   JOIN admin_roles r ON r.slug = u.role
              LEFT JOIN admin_roles cur ON cur.id = u.role_id
                    SET u.role_id = r.id
                  WHERE u.role_id IS NULL OR cur.id IS NULL"
            );
            $st->execute();
            return $st->rowCount();
        },
    ];

    $steps[] = [
        'group' => 'Permissions',
        'label' => 'grant suppliers.manage to roles that already had catalogue.manage',
        'done'  => !tableExists($pdo, $db, 'admin_roles') || (int)$pdo->query(
                        "SELECT COUNT(*) FROM admin_roles
                          WHERE capabilities LIKE '%catalogue.manage%'
                            AND capabilities NOT LIKE '%suppliers.manage%'"
                   )->fetchColumn() === 0,
        'skip'  => tableExists($pdo, $db, 'admin_roles') ? '' : 'admin_roles does not exist here',
        'php'   => function (PDO $pdo) {
            $n = 0;
            $upd = $pdo->prepare("UPDATE admin_roles SET capabilities = :c WHERE id = :id");
            foreach ($pdo->query("SELECT id, capabilities FROM admin_roles") as $r) {
                $caps = json_decode((string)$r['capabilities'], true);
                if (!is_array($caps)) continue;                          // malformed, leave alone
                if (in_array('*', $caps, true)) continue;                // owner: already everything
                if (!in_array('catalogue.manage', $caps, true)) continue;
                if (in_array('suppliers.manage', $caps, true)) continue;  // re-run safe
                $caps[] = 'suppliers.manage';
                $upd->execute([':c' => json_encode(array_values($caps)), ':id' => $r['id']]);
                $n++;
            }
            return $n;
        },
    ];

    // ── Menswear groundwork ─────────────────────────────────────────────────
    // Every SEO fallback in this shop assumes womenswear: category titles append
    // "for Women" and the Google feed stamps g:gender=female on every item. Add a
    // men's category without this flag and the shop publishes "Shop Men's Shirts
    // for Women" and submits menswear to Google Shopping as female, which gets
    // the items disapproved or shown to the wrong audience.
    //
    // The flag sits on the CATEGORY rather than the product, deliberately.
    // Switching menswear on then means setting one value per top-level category
    // instead of editing every product — and nobody can forget to tag a product.
    //
    // DEFAULT 'women' means every row that already exists is correct the moment
    // the column appears, so today's womenswear pages do not change by a single
    // character. Nothing becomes visible until a category is actually set to men.
    $steps[] = [
        'group' => 'Menswear groundwork',
        'label' => 'add gender to categories (existing rows become women)',
        'done'  => columnExists($pdo, $db, 'categories', 'gender'),
        'sql'   => "ALTER TABLE categories
                        ADD COLUMN gender ENUM('women','men','unisex')
                        NOT NULL DEFAULT 'women'",
    ];

    // Hero banners, unlike collections and products, cannot be sorted by audience
    // automatically: a photograph of a woman in a kurti is not machine-readable.
    // Somebody has to say so, once, per banner.
    //
    // Without this the menswear homepage led with the same womenswear hero as the
    // women's — the menu switched, the biggest picture on the page did not, and
    // the whole split read as broken.
    //
    // 'both' rather than 'unisex' here on purpose: on a banner it means "show this
    // in either view" — a sale message or a delivery promise — which is a
    // different statement from a garment being unisex. DEFAULT 'women' is correct
    // for every banner that exists today.
    $steps[] = [
        'group' => 'Menswear groundwork',
        'label' => 'add gender to hero banners (existing rows become women)',
        'done'  => columnExists($pdo, $db, 'banners', 'gender'),
        'skip'  => tableExists($pdo, $db, 'banners') ? '' : 'banners table does not exist here',
        'sql'   => "ALTER TABLE banners
                        ADD COLUMN gender ENUM('women','men','both')
                        NOT NULL DEFAULT 'women'",
    ];

    // ── Custom roles + per-staff permission overrides ──
    // Access was three hardcoded presets in config/config.php: owner, catalogue,
    // orders. Anyone who did not fit one of the three got whichever was closest,
    // which in practice meant handing out more access than intended.
    //
    // Nothing here changes an existing account's access. A staff member with no
    // custom role and no overrides resolves to exactly the preset they already
    // had — see adminCapabilitySet() in config/config.php.
    $steps[] = [
        'group' => 'Staff roles & permissions',
        'label' => 'admin_roles table',
        'done'  => tableExists($pdo, $db, 'admin_roles'),
        'sql'   => "CREATE TABLE IF NOT EXISTS admin_roles (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        slug VARCHAR(50) NOT NULL UNIQUE,
                        name VARCHAR(100) NOT NULL,
                        description VARCHAR(255) NULL,
                        capabilities TEXT NULL,
                        is_system TINYINT(1) NOT NULL DEFAULT 0,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    $steps[] = [
        'group' => 'Staff roles & permissions',
        'label' => 'seed the three built-in roles',
        'done'  => tableExists($pdo, $db, 'admin_roles')
                   && (int)$pdo->query("SELECT COUNT(*) FROM admin_roles WHERE is_system = 1")->fetchColumn() > 0,
        'needs' => 'admin_roles table',
        'php'   => function (PDO $pdo) {
            $seed = [
                ['owner',     'Owner',             'Everything, including money, settings and staff.',   ['*']],
                ['catalogue', 'Catalogue manager', 'Products, categories, media and SEO.',                ADMIN_CAPABILITIES['catalogue']],
                ['orders',    'Order staff',       'Orders, returns and support tickets.',                ADMIN_CAPABILITIES['orders']],
            ];
            $ins = $pdo->prepare(
                "INSERT INTO admin_roles (slug, name, description, capabilities, is_system)
                 VALUES (:s, :n, :d, :c, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)"
            );
            foreach ($seed as [$slug, $name, $desc, $caps]) {
                $ins->execute([':s' => $slug, ':n' => $name, ':d' => $desc, ':c' => json_encode($caps)]);
            }
            return count($seed) . ' built-in roles seeded';
        },
    ];

    $steps[] = [
        'group' => 'Staff roles & permissions',
        'label' => 'add role_id to admin_users',
        'done'  => columnExists($pdo, $db, 'admin_users', 'role_id'),
        'skip'  => tableExists($pdo, $db, 'admin_users') ? '' : 'admin_users table does not exist here',
        // NULL means "use the built-in preset in the existing role column", so
        // every account that exists right now keeps working untouched.
        'sql'   => "ALTER TABLE admin_users ADD COLUMN role_id INT NULL AFTER role",
    ];

    $steps[] = [
        'group' => 'Staff roles & permissions',
        'label' => 'admin_user_capabilities table (per-person overrides)',
        'done'  => tableExists($pdo, $db, 'admin_user_capabilities'),
        'sql'   => "CREATE TABLE IF NOT EXISTS admin_user_capabilities (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        admin_id INT NOT NULL,
                        capability VARCHAR(60) NOT NULL,
                        mode ENUM('grant','deny') NOT NULL DEFAULT 'grant',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_admin_cap (admin_id, capability),
                        INDEX idx_auc_admin (admin_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── Two-step sign-in by email ──
    // The only second factor was an authenticator app, which assumes every member
    // of staff has one and can be trusted to move it when they change phone. This
    // adds emailed codes as an alternative. It stays opt-in per person: an account
    // with totp_enabled = 0 signs in with a password exactly as before.
    $steps[] = [
        'group' => 'Two-step sign-in by email',
        'label' => 'add totp_method to admin_users',
        'done'  => columnExists($pdo, $db, 'admin_users', 'totp_method'),
        'skip'  => tableExists($pdo, $db, 'admin_users') ? '' : 'admin_users table does not exist here',
        // DEFAULT 'app' so anybody already using an authenticator keeps working
        // untouched — this column only decides where the code comes from.
        'sql'   => "ALTER TABLE admin_users ADD COLUMN totp_method ENUM('app','email') NOT NULL DEFAULT 'app' AFTER totp_enabled",
    ];

    $steps[] = [
        'group' => 'Two-step sign-in by email',
        'label' => 'add email_verified_at to admin_users',
        'done'  => columnExists($pdo, $db, 'admin_users', 'email_verified_at'),
        'skip'  => tableExists($pdo, $db, 'admin_users') ? '' : 'admin_users table does not exist here',
        // An address is only trusted once a code sent to it has actually been
        // typed back in. A typo then shows up while the owner is still on the
        // screen, rather than the next time that person tries to sign in.
        'sql'   => "ALTER TABLE admin_users ADD COLUMN email_verified_at DATETIME NULL AFTER email",
    ];

    $steps[] = [
        'group' => 'Two-step sign-in by email',
        'label' => 'admin_login_codes table',
        'done'  => tableExists($pdo, $db, 'admin_login_codes'),
        // Codes are stored HASHED, like password_resets does with its token. A
        // stolen database backup should not hand over a working second factor,
        // and a code sitting in plain text is exactly that for its lifetime.
        'sql'   => "CREATE TABLE IF NOT EXISTS admin_login_codes (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        admin_id INT NOT NULL,
                        code_hash VARCHAR(255) NOT NULL,
                        purpose ENUM('login','setup','password_reset') NOT NULL DEFAULT 'login',
                        expires_at DATETIME NOT NULL,
                        used_at DATETIME NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_alc_admin (admin_id, created_at),
                        INDEX idx_alc_expiry (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    $steps[] = [
        'group' => 'Admin password reset by email',
        'label' => 'allow password_reset purpose in admin_login_codes',
        // An existing install created this column before the purpose existed, and
        // CREATE TABLE IF NOT EXISTS will not widen it. Detected by checking the
        // column type string — a working install has 'password_reset' in the enum.
        'done'  => (function () use ($pdo, $db) {
            if (!tableExists($pdo, $db, 'admin_login_codes')) { return true; }
            try {
                $t = (string)$pdo->query("SHOW COLUMNS FROM admin_login_codes LIKE 'purpose'")->fetchColumn(1);
                return stripos($t, 'password_reset') !== false;
            } catch (PDOException $e) { return true; }
        })(),
        'skip'  => tableExists($pdo, $db, 'admin_login_codes') ? '' : 'admin_login_codes table does not exist here',
        // Widen the enum so forgot_password.php can store reset codes of its own
        // purpose — a reset code must never double as a sign-in code.
        'sql'   => "ALTER TABLE admin_login_codes MODIFY purpose ENUM('login','setup','password_reset') NOT NULL DEFAULT 'login'",
    ];

    /* ── order_items: the columns it needs to hold a real line ─────────────
       Phase-1 audit finding. order_items has existed since the beginning and
       has NEVER been written to — 126 orders, 3 rows, and no INSERT anywhere
       in the codebase. The real snapshot lives in orders.items_json, and only
       a fallback in admin/print_invoice.php ever read the table.

       Rather than leave a table the schema promises and the code ignores, it
       is now written on every order, as a queryable mirror of the snapshot —
       items_json stays authoritative and is what invoices print from. These
       two columns are what it lacks to record a line faithfully.

       Purely additive and nullable: no existing row changes, nothing is
       dropped, and an install that has not run this yet keeps working because
       the insert names only the columns that are present. */
    foreach ([
        ['variant_id', "ALTER TABLE order_items ADD COLUMN variant_id INT(11) NULL DEFAULT NULL AFTER product_id"],
        ['sku',        "ALTER TABLE order_items ADD COLUMN sku VARCHAR(100) NULL DEFAULT NULL AFTER product_name"],
    ] as [$oiCol, $oiSql]) {
        $steps[] = [
            'group' => 'Order items line detail',
            'label' => "add $oiCol to order_items",
            'done'  => !tableExists($pdo, $db, 'order_items') || columnExists($pdo, $db, 'order_items', $oiCol),
            'skip'  => tableExists($pdo, $db, 'order_items') ? '' : 'order_items table does not exist here',
            'sql'   => $oiSql,
        ];
    }

    /* ── Who supplied this garment ─────────────────────────────────────────
       An order arriving named only "Zariah Georgette Kurti — Size: M" gave the
       owner nothing to act on: no way to tell which supplier made it or what
       that supplier calls it, so fulfilling meant remembering.

       atelier_code already existed for the supplier's own design number — it is
       even read by productDisplayCode() — but had no box to type it into. Only
       the supplier's NAME was genuinely missing, so that is all this adds.
       sourcing is not it: it holds country of origin ("INDIA"). */
    foreach ([
        ['supplier_name', "ALTER TABLE products ADD COLUMN supplier_name VARCHAR(150) NULL DEFAULT NULL AFTER atelier_code"],
        ['supplier_ref',  "ALTER TABLE products ADD COLUMN supplier_ref VARCHAR(100) NULL DEFAULT NULL AFTER supplier_name"],
    ] as [$supCol, $supSql]) {
        $steps[] = [
            'group' => 'Supplier reference',
            'label' => "add $supCol to products",
            'done'  => !tableExists($pdo, $db, 'products') || columnExists($pdo, $db, 'products', $supCol),
            'skip'  => tableExists($pdo, $db, 'products') ? '' : 'products table does not exist here',
            'sql'   => $supSql,
        ];
    }

    /* ── One unique index on order_code, not two ───────────────────────────
       Phase-2 audit finding. `orders` carries TWO unique indexes on the same
       column: `order_code`, created inline by the CREATE TABLE in config/db.php,
       and `uq_order_code`, added at some point afterwards. They enforce exactly
       the same rule, so the second is pure overhead — MySQL maintains and checks
       both on every insert.

       `uq_order_code` is the one dropped: a fresh install has never had it, so
       removing it makes an existing database match what setup produces rather
       than the other way round.

       The uniqueness itself is NOT weakened. `order_code` remains, and it is the
       constraint the duplicate-key retry in checkout_action.php and
       razorpay_webhook.php actually relies on. Guarded on the index existing, so
       an install that never had it skips the step. */
    $steps[] = [
        'group' => 'Order code index',
        'label' => 'drop the duplicate unique index uq_order_code',
        'done'  => !tableExists($pdo, $db, 'orders') || !indexExists($pdo, $db, 'orders', 'uq_order_code'),
        'skip'  => tableExists($pdo, $db, 'orders') ? '' : 'orders table does not exist here',
        'sql'   => "ALTER TABLE orders DROP INDEX uq_order_code",
    ];

    /* ── Suppliers, kept once instead of retyped per product ───────────────
       products.supplier_name was free text, so the same supplier existed as
       many copies as it had products and a phone number had nowhere to live.
       This holds one row per supplier with the owner's name and number.

       products.supplier_name is DELIBERATELY kept alongside supplier_id and
       still written on save: actions/checkout_action.php freezes the supplier
       NAME into the order snapshot at the time of sale, and an order must go on
       naming whoever actually supplied it after the supplier row is edited or
       deleted — the same rule the price snapshot follows. */
    $steps[] = [
        'group' => 'Suppliers',
        'label' => 'create the suppliers table',
        'done'  => tableExists($pdo, $db, 'suppliers'),
        'sql'   => "CREATE TABLE IF NOT EXISTS `suppliers` (
                      `id` INT(11) NOT NULL AUTO_INCREMENT,
                      `name` VARCHAR(150) NOT NULL,
                      `owner_name` VARCHAR(150) NULL DEFAULT NULL,
                      `phone` VARCHAR(30) NULL DEFAULT NULL,
                      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `uq_supplier_name` (`name`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    $steps[] = [
        'group' => 'Suppliers',
        'label' => 'add supplier_id to products',
        'done'  => !tableExists($pdo, $db, 'products') || columnExists($pdo, $db, 'products', 'supplier_id'),
        'skip'  => tableExists($pdo, $db, 'products') ? '' : 'products table does not exist here',
        'sql'   => "ALTER TABLE products ADD COLUMN supplier_id INT(11) NULL DEFAULT NULL AFTER supplier_name",
    ];
    /* Existing free-text names become real supplier rows, so nothing typed
       before this migration is lost and every product keeps its link. */
    $steps[] = [
        'group' => 'Suppliers',
        'label' => 'move existing supplier names into the new table',
        'done'  => !tableExists($pdo, $db, 'suppliers')
                   || !columnExists($pdo, $db, 'products', 'supplier_id')
                   || (int)$pdo->query("SELECT COUNT(*) FROM products WHERE supplier_name IS NOT NULL AND supplier_name <> '' AND supplier_id IS NULL")->fetchColumn() === 0,
        'php'   => function (PDO $pdo) {
            $names = $pdo->query("SELECT DISTINCT supplier_name FROM products WHERE supplier_name IS NOT NULL AND supplier_name <> ''")->fetchAll(PDO::FETCH_COLUMN);
            $ins = $pdo->prepare("INSERT IGNORE INTO suppliers (name) VALUES (:n)");
            $lnk = $pdo->prepare("UPDATE products SET supplier_id = (SELECT id FROM suppliers WHERE name = :n) WHERE supplier_name = :n2");
            foreach ($names as $n) { $ins->execute([':n' => $n]); $lnk->execute([':n' => $n, ':n2' => $n]); }
            return count($names) . ' supplier(s) moved across';
        },
    ];

    return $steps;
}

$steps    = buildSteps($pdo, $dbName);
$pending  = array_values(array_filter($steps, fn($s) => !$s['done'] && empty($s['skip'])));
$results  = [];
$ranNow   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_update') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $results[] = ['ok' => false, 'label' => 'Security check', 'msg' => 'Invalid session token — reload the page and try again.'];
    } else {
        $ranNow = true;
        foreach ($steps as $s) {
            if (!empty($s['skip'])) { $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Skipped — ' . $s['skip']]; continue; }
            if ($s['done'])         { $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Already applied']; continue; }
            try {
                if (!empty($s['php']) && is_callable($s['php'])) {
                    $n = ($s['php'])($pdo);
                    $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Applied' . ($n ? " ($n rows)" : '')];
                    continue;
                }
                $pdo->exec($s['sql']);
                $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Applied'];
            } catch (PDOException $e) {
                $m = $e->getMessage();
                // Treat "already there" errors as success — another run may have won the race.
                if (stripos($m, 'Duplicate column') !== false || stripos($m, 'Duplicate key') !== false || stripos($m, 'already exists') !== false) {
                    $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Already applied'];
                } else {
                    $results[] = ['ok' => false, 'label' => $s['label'], 'msg' => $m];
                }
            }
        }
        if (function_exists('logAdminAction')) {
            logAdminAction($_SESSION['admin_id'] ?? 1, 'run_db_update', 'Ran update_new_database.php');
        }
        // Recompute so the summary reflects reality rather than what we assumed.
        $steps   = buildSteps($pdo, $dbName);
        $pending = array_values(array_filter($steps, fn($s) => !$s['done'] && empty($s['skip'])));
    }
}

$failed = array_filter($results, fn($r) => !$r['ok']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Database Update — Dievon</title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
<style>
    body { background:#faf8f5; font-family:system-ui,-apple-system,"Segoe UI",sans-serif; color:#241017; margin:0; padding:40px 20px; }
    .wrap { max-width:780px; margin:0 auto; background:#fff; border:1px solid #eae4dc; border-radius:8px; padding:32px; box-shadow:0 10px 40px rgba(40,12,24,.06); }
    h1 { font-family:Georgia,serif; font-weight:400; font-size:26px; margin:0 0 6px; color:#511126; }
    .sub { color:#6b5b62; font-size:14px; margin:0 0 24px; }
    .box { border-radius:6px; padding:14px 16px; margin-bottom:18px; font-size:14px; line-height:1.6; }
    .ok { background:rgba(16,185,129,.09); border-left:3px solid #10b981; }
    .warn { background:rgba(245,158,11,.10); border-left:3px solid #f59e0b; }
    .err { background:rgba(239,68,68,.09); border-left:3px solid #ef4444; }
    .grp { font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:#511126; font-weight:700; margin:20px 0 8px; }
    ul { list-style:none; padding:0; margin:0; }
    li { display:flex; gap:10px; padding:7px 0; border-bottom:1px solid #f2ede7; font-size:13.5px; align-items:baseline; }
    li:last-child { border-bottom:none; }
    .tick { color:#10b981; font-weight:700; }
    .dot  { color:#b9a9b0; }
    .cross{ color:#ef4444; font-weight:700; }
    .muted{ color:#8a8a8a; font-size:12px; }
    button { background:#511126; color:#fff; border:none; padding:14px 30px; border-radius:4px; font-size:13px; font-weight:600;
             letter-spacing:.08em; text-transform:uppercase; cursor:pointer; margin-top:22px; }
    button:hover { background:#6d1832; }
    a.back { display:inline-block; margin-top:18px; color:#511126; font-size:13px; }
    code { background:#faf8f5; padding:1px 5px; border-radius:3px; font-size:12px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Database Update</h1>
    <p class="sub">Applies the database changes required by the latest code. Safe to run more than once.</p>

    <?php if ($ranNow): ?>
        <?php if ($failed): ?>
            <div class="box err">
                <strong><?= count($failed) ?> step(s) failed.</strong> Everything else was applied. The messages below are the raw
                database errors — send them over and they can be sorted out.
            </div>
        <?php else: ?>
            <div class="box ok">
                <strong>Update complete.</strong> Your database now matches the new code. Nothing was deleted or overwritten.
            </div>
        <?php endif; ?>
        <ul>
            <?php foreach ($results as $r): ?>
            <li>
                <span class="<?= $r['ok'] ? 'tick' : 'cross' ?>"><?= $r['ok'] ? '✓' : '✕' ?></span>
                <span><?= htmlspecialchars($r['label']) ?> <span class="muted">— <?= htmlspecialchars($r['msg']) ?></span></span>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!$pending): ?>
        <div class="box ok" style="margin-top:20px;">
            <strong>Nothing left to do.</strong> Every change is already applied — your database is up to date.
        </div>
    <?php else: ?>
        <div class="box warn">
            <strong><?= count($pending) ?> change(s) still to apply.</strong>
            Only additions: new tables, columns and indexes. No data is deleted or overwritten.
        </div>
        <?php
        $lastGroup = null;
        foreach ($steps as $s):
            if ($s['group'] !== $lastGroup) { if ($lastGroup !== null) echo '</ul>'; echo '<div class="grp">' . htmlspecialchars($s['group']) . '</div><ul>'; $lastGroup = $s['group']; }
        ?>
            <li>
                <span class="<?= $s['done'] ? 'tick' : 'dot' ?>"><?= $s['done'] ? '✓' : '○' ?></span>
                <span><?= htmlspecialchars($s['label']) ?>
                    <span class="muted">— <?= !empty($s['skip']) ? htmlspecialchars($s['skip']) : ($s['done'] ? 'already applied' : 'will be added') ?></span>
                </span>
            </li>
        <?php endforeach; if ($lastGroup !== null) echo '</ul>'; ?>

        <?php if ($ranNow && !empty($GLOBALS['dievon_seed_password'])
                  && (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn() === 1): ?>
        <div class="box warn">
            <strong>Your first admin account was created.</strong><br>
            Username: <code><?= htmlspecialchars(defined('ADMIN_USERNAME') ? ADMIN_USERNAME : 'admin') ?></code><br>
            Password: <code><?= htmlspecialchars($GLOBALS['dievon_seed_password']) ?></code><br>
            Write this down now — it is shown once and is not stored anywhere in readable form.
            You will be asked to change it the first time you sign in.
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="run_update">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <button type="submit">Run the update</button>
        </form>
    <?php endif; ?>

    <a class="back" href="<?= SITE_URL ?>/admin/index.php">&larr; Back to admin</a>
    <p class="muted" style="margin-top:22px;">
        Signed in as admin · database <code><?= htmlspecialchars($dbName) ?></code> ·
        Once your live site is updated and working you can delete this file.
    </p>
</div>
</body>
</html>
