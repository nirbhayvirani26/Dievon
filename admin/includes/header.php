<?php
// ============================================================
//  Dievon – Admin Dashboard
//  Tabs: Orders | Products | Gallery | Categories
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

$successMsg = '';
$errorMsg   = '';

// ── Handle product delete (POST only with CSRF) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product' && isset($_POST['id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $delId = (int)$_POST['id'];
        try {
            $pdo->prepare("UPDATE products SET is_deleted = 1, available = 0 WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'soft_delete_product', "Soft-deleted product ID $delId");
            $successMsg = 'Product archived (soft-deleted) successfully.';
        } catch (PDOException $e) {
            $errorMsg = 'Could not delete product: ' . $e->getMessage();
        }
    }
}

// ── URL success messages ────────────────────────────────
if (isset($_GET['order_deleted'])) $successMsg = '✅ Order deleted successfully.';

// ── URL success messages ──────────────────────────────────
if (isset($_GET['product_added']))   $successMsg = '✅ New product added successfully!';
if (isset($_GET['product_updated'])) $successMsg = '✅ Product updated successfully!';

// ── Load stats ────────────────────────────────────────────
$totalOrders   = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending'")->fetchColumn();
$totalRevenue  = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE payment_status IN ('Paid', 'Cash')")->fetchColumn();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

// Repeat customer detection
$repeatPhones = [];
try {
    $repeatPhones = $pdo->query("SELECT phone FROM orders GROUP BY phone HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
$repeatPhoneSet = array_flip($repeatPhones);
$repeatCustomerCount = count($repeatPhones);

// ── Load orders ───────────────────────────────────────────
$orders = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();

// ── Load stock data (for Stock tab) ───────────────────────
$stockProducts      = [];
$stockMigrationDone = false;
$stockV2Done        = false; // true when total_stock + sold_online also exist
try {
    // Try full v2 schema
    $stockProducts = $pdo->query(
        "SELECT id, name, emoji, image, category,
                IFNULL(track_stock,  0) AS track_stock,
                IFNULL(total_stock,  0) AS total_stock,
                IFNULL(stock_qty,    0) AS stock_qty,
                IFNULL(damage_stock, 0) AS damage_stock,
                IFNULL(sold_offline, 0) AS sold_offline,
                IFNULL(sold_online,  0) AS sold_online
         FROM products ORDER BY name ASC"
    )->fetchAll();
    $stockMigrationDone = true;
    $stockV2Done        = true;
} catch (PDOException $e) {
    // v2 columns missing — try v1 (damage + offline only)
    try {
        $stockProducts = $pdo->query(
            "SELECT id, name, emoji, image, category,
                    IFNULL(track_stock,  0) AS track_stock,
                    IFNULL(stock_qty,    0) AS total_stock,
                    IFNULL(stock_qty,    0) AS stock_qty,
                    IFNULL(damage_stock, 0) AS damage_stock,
                    IFNULL(sold_offline, 0) AS sold_offline,
                    0 AS sold_online
             FROM products ORDER BY name ASC"
        )->fetchAll();
        $stockMigrationDone = true;
    } catch (PDOException $e2) {
        // No stock columns at all — basic fallback
        try {
            $stockProducts = $pdo->query(
                "SELECT id, name, emoji, image, category,
                        0 AS track_stock, 0 AS total_stock, 0 AS stock_qty,
                        0 AS damage_stock, 0 AS sold_offline, 0 AS sold_online
                 FROM products ORDER BY name ASC"
            )->fetchAll();
        } catch (PDOException $e3) { $stockProducts = []; }
    }
}


// ── Load products ─────────────────────────────────────────
$products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();

// ── Load gallery (removed) ──────────────────────────────────

// ── Load categories ───────────────────────────────────────
$catList = [];
try { $catList = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll(); } catch (PDOException $e) {}

// ── Load promo codes ───────────────────────────────
$promoCodes = [];
try { $promoCodes = $pdo->query("SELECT * FROM promo_codes ORDER BY created_at DESC")->fetchAll(); } catch (PDOException $e) {}

// ── Active tab ────────────────────────────────────────────
if (!isset($activeTab)) $activeTab = $_GET['tab'] ?? 'orders';
$validTabs = [
    'orders', 'products', 'categories', 'subcategories', 'brands', 'colors', 'sizes',
    'stock', 'customers', 'promos', 'reviews', 'invoices', 'banners', 'homepage_builder',
    'homepage', 'blog', 'seo', 'newsletter', 'media', 'revenue_report', 'revenue',
    'settings', 'backup', 'admin_users', 'roles', 'logs', 'inquiries', 'dashboard',
    'returns', 'support_tickets', 'size_guide'
];
if (!in_array($activeTab, $validTabs)) $activeTab = 'orders';

// ── Load inquiries ──────────────────────────────────────
$inquiries        = [];
$unreadInquiries  = 0;
try {
    // Auto-create table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inquiries` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`       VARCHAR(120) NOT NULL,
        `email`      VARCHAR(180) NOT NULL,
        `phone`      VARCHAR(30)  NOT NULL DEFAULT '',
        `message`    TEXT         NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Mark as read if viewing the tab
    if ($activeTab === 'inquiries') {
        $pdo->exec("UPDATE inquiries SET is_read = 1 WHERE is_read = 0");
    }
    $inquiries       = $pdo->query("SELECT * FROM inquiries ORDER BY created_at DESC")->fetchAll();
    $unreadInquiries = (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_read = 0")->fetchColumn();
} catch (PDOException $e) {}

// ── Revenue tab data ──────────────────────────────────
$revData = [];
if ($activeTab === 'revenue') {
    $revFrom = $_GET['rev_from'] ?? date('Y-m-01');
    $revTo   = $_GET['rev_to']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $revFrom)) $revFrom = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $revTo))   $revTo   = date('Y-m-d');

    // Summary by payment status
    try {
        $rstmt = $pdo->prepare("SELECT payment_status, SUM(total_price) AS total, COUNT(*) AS cnt FROM orders WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY payment_status");
        $rstmt->execute(['f' => $revFrom, 't' => $revTo]);
        $byStatus = [];
        while ($r = $rstmt->fetch()) $byStatus[$r['payment_status']] = $r;
        $revData['online']       = (float)($byStatus['Paid']['total']  ?? 0);
        $revData['cash']         = (float)($byStatus['Cash']['total']  ?? 0);
        $revData['total']        = $revData['online'] + $revData['cash'];
        $revData['unpaid_total'] = (float)($byStatus['Unpaid']['total'] ?? 0);
        $revData['unpaid_count'] = (int)($byStatus['Unpaid']['cnt']    ?? 0);
    } catch (PDOException $e) { $revData['total'] = 0; $revData['online'] = 0; $revData['cash'] = 0; $revData['unpaid_total'] = 0; $revData['unpaid_count'] = 0; }

    // Product map for categories
    $revProductMap = [];
    try {
        $rows = $pdo->query("SELECT id, name, category FROM products")->fetchAll();
        foreach ($rows as $row) $revProductMap[$row['id']] = $row;
    } catch (PDOException $e) {}

    // Category breakdown + product sold qty (all-time & this month)
    $catRevenue      = [];
    $productAllTime  = [];
    $productThisMonth = [];
    $thisYear  = (int)date('Y');
    $thisMonth = (int)date('n');

    try {
        // For category table: filter by date range (paid only)
        $oStmt = $pdo->prepare("SELECT items_json, payment_status, created_at FROM orders WHERE DATE(created_at) BETWEEN :f AND :t");
        $oStmt->execute(['f' => $revFrom, 't' => $revTo]);
        $revOrders = $oStmt->fetchAll();
    } catch (PDOException $e) { $revOrders = []; }

    foreach ($revOrders as $o) {
        $isPaid = in_array($o['payment_status'], ['Paid', 'Cash']);
        $items  = json_decode($o['items_json'], true) ?? [];
        foreach ($items as $it) {
            $pid  = $it['product_id'] ?? 0;
            $cat  = $revProductMap[$pid]['category'] ?? ($it['category'] ?? 'Other');
            $qty  = (int)$it['quantity'];
            $line = (float)$it['price'] * $qty;
            if ($isPaid) {
                if (!isset($catRevenue[$cat])) $catRevenue[$cat] = ['revenue' => 0, 'qty' => 0];
                $catRevenue[$cat]['revenue'] += $line;
                $catRevenue[$cat]['qty']     += $qty;
            }
        }
    }
    arsort($catRevenue);

    // Product charts: all-time paid orders
    try {
        $allStmt = $pdo->query("SELECT items_json, created_at FROM orders WHERE payment_status IN ('Paid','Cash')");
        while ($o = $allStmt->fetch()) {
            $dt    = new DateTime($o['created_at']);
            $items = json_decode($o['items_json'], true) ?? [];
            $isThisMonth = ((int)$dt->format('Y') === $thisYear && (int)$dt->format('n') === $thisMonth);
            foreach ($items as $it) {
                $nm  = $it['name'];
                $qty = (int)$it['quantity'];
                $productAllTime[$nm]  = ($productAllTime[$nm]  ?? 0) + $qty;
                if ($isThisMonth) $productThisMonth[$nm] = ($productThisMonth[$nm] ?? 0) + $qty;
            }
        }
    } catch (PDOException $e) {}
    arsort($productAllTime);
    arsort($productThisMonth);
    $revData['chart_alltime']   = array_slice($productAllTime,   0, 10, true);
    $revData['chart_thismonth'] = array_slice($productThisMonth, 0, 10, true);
    $revData['cat_revenue']     = $catRevenue;
    $revData['from']            = $revFrom;
    $revData['to']              = $revTo;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Dashboard – <?= SHOP_NAME ?></title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/logo/logo.PNG">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/responsive.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/fontawesome/css/all.min.css">
    <script>
        window.ADMIN_CSRF_TOKEN = '<?= generateCsrfToken() ?>';
    </script>
</head>

<body class="admin-wrapper">

<!-- ══ Global Top Header Bar (0,0 Fixed) ════════════════════════════════════ -->
<header class="admin-global-topbar">
    <div style="display: flex; align-items: center; gap: 12px;">
        <button type="button" class="admin-mobile-toggle" onclick="toggleAdminSidebar()" style="display: none; background: none; border: none; color: #ffffff; font-size: 18px; cursor: pointer; padding: 4px;">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a href="../home.php" class="admin-brand-logo">
            <span class="logo-emoji">✨</span> <?= SHOP_NAME ?> <small>ATELIER ADMIN</small>
        </a>
    </div>

    <div class="admin-topbar-actions">
        <a href="../home.php" target="_blank" class="topbar-btn-shop">
            <i class="fa-solid fa-globe"></i> View Live Shop ↗
        </a>
        <div class="admin-user-pill">
            <i class="fa-solid fa-user-shield"></i> <?= htmlspecialchars(ADMIN_USERNAME) ?>
        </div>
        <a href="logout.php" class="topbar-btn-logout">
            Sign Out
        </a>
    </div>
</header>

<!-- Mobile Sidebar Backdrop Overlay -->
<div class="mobile-drawer-backdrop" id="adminSidebarBackdrop" onclick="toggleAdminSidebar(false)"></div>

<script>
function toggleAdminSidebar(force) {
    const sidebar = document.querySelector('.admin-sidebar');
    const backdrop = document.getElementById('adminSidebarBackdrop');
    if (!sidebar) return;
    
    if (typeof force === 'boolean') {
        if (force) {
            sidebar.classList.add('open');
            if (backdrop) backdrop.classList.add('active');
        } else {
            sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('active');
        }
    } else {
        sidebar.classList.toggle('open');
        if (backdrop) backdrop.classList.toggle('active');
    }
}

// Auto scroll-into-view & preserve sidebar scroll position across page reloads
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (!sidebar) return;

    const savedScroll = localStorage.getItem('adminSidebarScroll');
    if (savedScroll !== null) {
        sidebar.scrollTop = parseInt(savedScroll, 10);
    }

    const activeItem = sidebar.querySelector('a.active');
    if (activeItem) {
        setTimeout(function() {
            activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }, 100);
    }

    sidebar.addEventListener('scroll', function() {
        localStorage.setItem('adminSidebarScroll', sidebar.scrollTop);
    });
});
</script>

<div class="admin-body-layout">

    <!-- ══ Left Fixed Sidebar (Streamlined & Clean) ════════════════════════ -->
    <aside class="admin-sidebar">
        <nav class="admin-sidebar-nav">
            <!-- Group 1: Overview -->
            <div class="sidebar-group-title">Overview</div>
            <a href="dashboard.php" class="<?= $activeTab==='dashboard' ? 'active' : '' ?>"><i class="fa-solid fa-chart-line"></i> Dashboard Analytics</a>

            <!-- Group 2: Catalog & Inventory -->
            <div class="sidebar-group-title">Catalog &amp; Inventory</div>
            <a href="products.php" class="<?= $activeTab==='products' ? 'active' : '' ?>"><i class="fa-solid fa-shirt"></i> Products &amp; Garments</a>
            <a href="categories.php" class="<?= $activeTab==='categories' || $activeTab==='subcategories' ? 'active' : '' ?>"><i class="fa-solid fa-folder-tree"></i> Categories &amp; Subcategories</a>
            <a href="attributes.php?type=colors" class="<?= $activeTab==='colors' || $activeTab==='sizes' || $activeTab==='brands' ? 'active' : '' ?>"><i class="fa-solid fa-palette"></i> Colors, Sizes &amp; Brands</a>
            <a href="settings.php?tab=stock" class="<?= $activeTab==='stock' ? 'active' : '' ?>"><i class="fa-solid fa-boxes-stacked"></i> Inventory &amp; Stock</a>
            <a href="size_guide.php" class="<?= $activeTab==='size_guide' ? 'active' : '' ?>"><i class="fa-solid fa-ruler"></i> Size Guide</a>

            <!-- Group 3: Sales & Clients -->
            <div class="sidebar-group-title">Sales &amp; Clients</div>
            <a href="orders.php" class="<?= $activeTab==='orders' || $activeTab==='invoices' ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-list"></i> Orders &amp; Invoices</a>
            <a href="customers.php" class="<?= $activeTab==='customers' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Customers &amp; Inquiries</a>
            <a href="promo.php" class="<?= $activeTab==='promos' ? 'active' : '' ?>"><i class="fa-solid fa-ticket"></i> Discount Coupons</a>
            <a href="reviews.php" class="<?= $activeTab==='reviews' ? 'active' : '' ?>"><i class="fa-solid fa-star"></i> Customer Reviews</a>
            <a href="returns.php" class="<?= $activeTab==='returns' ? 'active' : '' ?>"><i class="fa-solid fa-rotate-left"></i> RMA Returns</a>
            <a href="support_tickets.php" class="<?= $activeTab==='support_tickets' ? 'active' : '' ?>"><i class="fa-solid fa-headset"></i> Support Tickets</a>

            <!-- Group 4: Store Content & Settings -->
            <div class="sidebar-group-title">Content &amp; Settings</div>
            <a href="banners.php" class="<?= $activeTab==='banners' ? 'active' : '' ?>"><i class="fa-solid fa-sliders"></i> Banner Slider Manager</a>
            <a href="homepage_builder.php" class="<?= $activeTab==='homepage_builder' || $activeTab==='homepage' ? 'active' : '' ?>"><i class="fa-solid fa-table-columns"></i> Homepage Sections</a>
            <a href="blog.php" class="<?= $activeTab==='blog' ? 'active' : '' ?>"><i class="fa-solid fa-newspaper"></i> Blog Journal Manager</a>
            <a href="seo.php" class="<?= $activeTab==='seo' ? 'active' : '' ?>"><i class="fa-solid fa-magnifying-glass"></i> SEO &amp; Social Previews</a>
            <a href="newsletter.php" class="<?= $activeTab==='newsletter' ? 'active' : '' ?>"><i class="fa-solid fa-envelope"></i> Newsletter Subscribers</a>
            <a href="media.php" class="<?= $activeTab==='media' ? 'active' : '' ?>"><i class="fa-solid fa-images"></i> Media Library</a>
            <a href="settings.php?tab=settings" class="<?= $activeTab==='settings' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i> Store Settings</a>
            <a href="backup.php" class="<?= $activeTab==='backup' ? 'active' : '' ?>"><i class="fa-solid fa-database"></i> Database Backup</a>
        </nav>
    </aside>

    <!-- ══ Main Content Area ════════════════════════════════════════ -->
    <div class="admin-main">
        <main class="admin-content">

        <!-- Alerts -->
        <?php if ($successMsg): ?>
        <div class="alert alert-success" style="margin-bottom:24px; background: #ecfdf5; border: 1px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px;">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger" style="margin-bottom:24px; background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px;">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
        </div>
        <?php endif; ?>

        <?php if (empty($hideHeaderTitle)): ?>
        <!-- Page Header -->
        <div class="admin-page-header admin-page-header-flex">
            <div>
                <h1 class="admin-page-title">
                    <?php
                    echo match($activeTab) {
                        'orders'     => 'Orders',
                        'products'   => 'Products',
                        'stock'      => 'Stock Management',
                        'categories' => 'Categories',
                        'revenue'    => 'Revenue & Reports',
                        'promos'     => 'Promos',
                        'inquiries'  => 'Inquiries',
                        'reviews'    => 'Customer Reviews',
                        'newsletter' => 'Newsletter Subscribers',
                        'media'      => 'Media Library & Assets',
                        'returns'    => 'RMA Returns & Exchanges',
                        'support_tickets' => 'Atelier Concierge Support Tickets',
                        'backup'     => 'Database Backup & Restore',
                        'size_guide' => 'Size Guide',
                        default      => 'Admin Panel',
                    };
                    ?>
                </h1>
                <p class="admin-page-subtitle">
                    <?php
                    echo match($activeTab) {
                        'orders'     => 'View and manage all customer orders',
                        'products'   => 'Add, edit, or remove products',
                        'stock'      => 'Track in-stock, damage, and offline sold quantities per product',
                        'categories' => 'Add or remove product categories',
                        'revenue'    => 'Sales reports, payment breakdowns and product charts',
                        'reviews'    => 'Approve, reject, or delete customer product reviews',
                        'newsletter' => 'Everyone who subscribed from the site footer',
                        'media'      => 'Every image uploaded for products, banners, and blog posts',
                        'returns'    => 'Review and process customer return &amp; exchange requests',
                        'support_tickets' => 'Respond to customer concierge and support requests',
                        'backup'     => 'Download a full SQL export of every table',
                        'size_guide' => 'Manage body & garment measurements shown in the storefront size guide popup',
                        default      => '',
                    };
                    ?>
                </p>
            </div>
            <?php if ($activeTab === 'products'): ?>
            <a href="product_form.php" class="btn-primary admin-header-btn-secondary">
                <i class="fa-solid fa-plus"></i> Add Product
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'revenue' || $activeTab === 'orders' || $activeTab === 'dashboard'): ?>
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Orders</div>
                <div class="stat-value"><?= $totalOrders ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Orders</div>
                <div class="stat-value text-warning"><?= $pendingOrders ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value text-success">₹<?= number_format($totalRevenue, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Products</div>
                <div class="stat-value"><?= $totalProducts ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════ ORDERS TAB ═══════════════════ -->
