<?php
// ============================================================
//  Dievon – One-Click Database Setup / Sync
//  Run this once after uploading to a new server (or any time you
//  want to double-check the live database matches what the code expects).
//
//  Safe to run repeatedly: every step here is IF NOT EXISTS / guarded,
//  so re-running never duplicates data or breaks anything.
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php'; // already provisions: customers, product_images,
// orders, order_items, password_resets, customer_addresses, customer_returns,
// customer_tickets, customer_logs, plus the orders.status width fix.

$results = [];
function logStep(array &$results, string $step, bool $ok, string $msg = ''): void {
    $results[] = ['step' => $step, 'ok' => $ok, 'msg' => $msg];
}

// ── Tables with no other home in the codebase — created explicitly here,
// using the exact schema this app is built and tested against ─────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(150) NOT NULL,
        `description` TEXT NOT NULL,
        `price` DECIMAL(10,2) NOT NULL,
        `category` VARCHAR(50) NOT NULL,
        `emoji` VARCHAR(10) NOT NULL DEFAULT '✨',
        `color` VARCHAR(50) NOT NULL DEFAULT '#ff6b9d,#c44dff',
        `badge` VARCHAR(30) NOT NULL DEFAULT '',
        `available` TINYINT(1) NOT NULL DEFAULT '1',
        `image` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `track_stock` TINYINT(1) NOT NULL DEFAULT '0',
        `stock_qty` INT NOT NULL DEFAULT '0',
        `damage_stock` INT NOT NULL DEFAULT '0',
        `sold_offline` INT NOT NULL DEFAULT '0',
        `total_stock` INT NOT NULL DEFAULT '0',
        `sold_online` INT NOT NULL DEFAULT '0',
        `brand` VARCHAR(255) DEFAULT NULL,
        `fabric` VARCHAR(100) DEFAULT NULL,
        `sleeve` VARCHAR(100) DEFAULT NULL,
        `neck` VARCHAR(100) DEFAULT NULL,
        `pattern` VARCHAR(100) DEFAULT NULL,
        `occasion` VARCHAR(100) DEFAULT NULL,
        `discount_percentage` INT DEFAULT '0',
        `video_url` VARCHAR(500) DEFAULT NULL,
        `wash_care` TEXT,
        `shipping_info` TEXT,
        `returns_info` TEXT,
        `specifications` JSON DEFAULT NULL,
        `atelier_code` VARCHAR(100) DEFAULT NULL,
        `color_way` VARCHAR(100) DEFAULT NULL,
        `sourcing` VARCHAR(255) DEFAULT NULL,
        `composition` VARCHAR(255) DEFAULT NULL,
        `sku` VARCHAR(100) DEFAULT NULL,
        `barcode` VARCHAR(100) DEFAULT NULL,
        `weight` VARCHAR(100) DEFAULT NULL,
        `dimensions` VARCHAR(100) DEFAULT NULL,
        `tags` TEXT,
        `seo_url` VARCHAR(255) DEFAULT NULL,
        `meta_title` VARCHAR(255) DEFAULT NULL,
        `meta_description` TEXT,
        `related_ids` TEXT,
        `cost_price` DECIMAL(10,2) DEFAULT '0.00',
        `mrp_price` DECIMAL(10,2) DEFAULT '0.00',
        `hsn_code` VARCHAR(50) DEFAULT NULL,
        `gst_rate` DECIMAL(5,2) DEFAULT '0.00',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    logStep($results, 'products', true);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_variants` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `product_id` INT NOT NULL,
        `name` VARCHAR(80) NOT NULL,
        `price` DECIMAL(10,2) NOT NULL,
        `sort_order` INT NOT NULL DEFAULT '0',
        `available` TINYINT(1) NOT NULL DEFAULT '1',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_product_id` (`product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    logStep($results, 'product_variants', true);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `categories` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(80) NOT NULL,
        `sort_order` INT NOT NULL DEFAULT '0',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `parent_id` INT DEFAULT '0',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    logStep($results, 'categories', true);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `promo_codes` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(50) NOT NULL,
        `description` VARCHAR(200) NOT NULL DEFAULT '',
        `discount_type` ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
        `discount_value` DECIMAL(10,2) NOT NULL,
        `min_order` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        `max_uses` INT DEFAULT NULL,
        `uses_count` INT NOT NULL DEFAULT '0',
        `active` TINYINT(1) NOT NULL DEFAULT '1',
        `expires_at` DATE DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    logStep($results, 'promo_codes', true);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `admin_id` INT NOT NULL,
        `action` VARCHAR(100) NOT NULL,
        `details` TEXT,
        `ip_address` VARCHAR(45) DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    logStep($results, 'admin_audit_logs', true);
} catch (PDOException $e) {
    logStep($results, 'core tables', false, $e->getMessage());
}

// ── email_logs (+ a couple of legacy order columns) via the existing,
// already-written migration function — just never wired up anywhere ────
try {
    require_once __DIR__ . '/../database/email_migrations.php';
    $ok = runEmailSystemMigrations($pdo);
    logStep($results, 'email_logs, inquiries', $ok, $ok ? '' : 'See error log');
} catch (\Throwable $e) {
    logStep($results, 'email_logs, inquiries', false, $e->getMessage());
}

// ── Everything else self-migrates the moment its own admin page loads.
// Warm each one up here (output discarded) so a single visit to this page
// provisions the whole site, using the exact same code that already runs
// in production for these — not a hand-copied duplicate that could drift. ──
$warmupPages = [
    'brands.php'            => [],
    'attributes.php'        => ['type' => 'colors'],
    'banners.php'            => [],
    'seo.php'                => [],
    'blog.php'                => [],
    'homepage_builder.php'    => [],
    'reviews.php'            => [],
    'newsletter.php'        => [],
];

foreach ($warmupPages as $file => $getParams) {
    $savedGet = $_GET;
    $_GET = $getParams;
    try {
        ob_start();
        include __DIR__ . '/' . $file;
        ob_end_clean();
        logStep($results, $file, true);
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        logStep($results, $file, false, $e->getMessage());
    }
    $_GET = $savedGet;
}

$allOk = true;
foreach ($results as $r) { if (!$r['ok']) { $allOk = false; break; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup – Dievon Admin</title>
<style>
    body { font-family: sans-serif; background: #140408; color: #fff; padding: 40px 20px; }
    .card { max-width: 680px; margin: 0 auto; background: #1f070e; border: 1px solid rgba(255,255,255,0.15); padding: 35px; border-radius: 8px; }
    h1 { font-family: serif; color: #C59B4B; margin-top: 0; }
    .row { display: flex; justify-content: space-between; padding: 10px 14px; background: rgba(255,255,255,0.04); margin-bottom: 6px; border-radius: 4px; font-size: 13px; }
    .ok { color: #10b981; font-weight: 700; }
    .fail { color: #ef4444; font-weight: 700; }
    a.btn { display: inline-block; margin-top: 20px; padding: 12px 24px; background: #C59B4B; color: #140408; text-decoration: none; border-radius: 6px; font-weight: 700; font-size: 13px; }
</style>
</head>
<body>
<div class="card">
    <h1>✨ Database Setup</h1>
    <p style="color:#cbd5e1; font-size:14px;">Checked every table this site needs and created anything missing. Safe to re-run any time.</p>
    <div style="margin: 20px 0;">
        <?php foreach ($results as $r): ?>
        <div class="row">
            <span><?= htmlspecialchars($r['step']) ?><?= $r['msg'] ? ' — ' . htmlspecialchars($r['msg']) : '' ?></span>
            <span class="<?= $r['ok'] ? 'ok' : 'fail' ?>"><?= $r['ok'] ? '✓ Ready' : '✕ Error' ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($allOk): ?>
        <div style="padding:14px; background:rgba(16,185,129,0.15); border:1px solid #10b981; color:#10b981; border-radius:6px; font-weight:700;">
            🎉 Database is fully set up and ready.
        </div>
    <?php else: ?>
        <div style="padding:14px; background:rgba(239,68,68,0.15); border:1px solid #ef4444; color:#ef4444; border-radius:6px;">
            ⚠️ Something needs attention — check the errors above.
        </div>
    <?php endif; ?>
    <a href="dashboard.php" class="btn">Go to Admin Dashboard →</a>
</div>
</body>
</html>
