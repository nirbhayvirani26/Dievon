<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$activeTab = 'seo';
$successMsg = '';
$errorMsg   = '';

// Auto create seo_settings table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `page_slug` VARCHAR(100) NOT NULL UNIQUE,
        `meta_title` VARCHAR(255) NOT NULL,
        `meta_description` TEXT DEFAULT NULL,
        `meta_keywords` VARCHAR(255) DEFAULT NULL,
        `og_image` VARCHAR(255) DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM seo_settings")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO seo_settings (page_slug, meta_title, meta_description, meta_keywords, og_image) VALUES
            ('home', 'Dievon Atelier | Timeless Luxury Women\'s Fashion', 'Discover Dievon\'s hand-crafted Mulberry Silk Kurtis, Brocade 3-Piece Suits, and Organic Linen Coord Sets. Free Worldwide Shipping.', 'luxury fashion, silk kurtis, 3-piece suits, coord sets, dievon', 'lookbook_1.png'),
            ('shop', 'Shop Luxury Collections | Dievon Atelier', 'Browse our exclusive collection of luxury Kurtis, Dresses, and Coord Sets. Worldwide Express Delivery & Custom Fittings.', 'buy kurtis, luxury clothing, silk dresses, designer fashion', 'lookbook_2.png'),
            ('about', 'Our Heritage & Craftsmanship | Dievon Atelier', 'Learn about Dievon\'s dedication to sustainable luxury, bespoke tailoring, and heritage embroidery.', 'heritage fashion, luxury atelier, bespoke kurtis', 'lookbook_3.png'),
            ('contact', 'Contact Our Atelier | Dievon Support', 'Get in touch with Dievon Atelier customer care for custom fitting, order inquiries, and personal styling.', 'contact dievon, customer care, styling inquiries', 'lookbook_1.png')
        ");
    }
} catch (PDOException $e) {}

// Handle Save SEO Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_seo'])) {
    try {
        foreach ($_POST['seo'] as $slug => $data) {
            $title = trim($data['meta_title'] ?? '');
            $desc = trim($data['meta_description'] ?? '');
            $keywords = trim($data['meta_keywords'] ?? '');
            $stmt = $pdo->prepare("UPDATE seo_settings SET meta_title = :title, meta_description = :desc, meta_keywords = :keywords WHERE page_slug = :slug");
            $stmt->execute(['title' => $title, 'desc' => $desc, 'keywords' => $keywords, 'slug' => $slug]);
        }
        $successMsg = "SEO Meta and OpenGraph tags saved successfully!";
    } catch (PDOException $e) {
        $errorMsg = "Error saving SEO settings: " . $e->getMessage();
    }
}

$seoPages = [];
try {
    $seoPages = $pdo->query("SELECT * FROM seo_settings ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">⚙️ SEO Meta &amp; OpenGraph Manager</h1>
        <p class="admin-page-subtitle">Configure Google search engine titles, descriptions, and social media share previews for each page.</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <div style="background:#ecfdf5; color:#065f46; border:1px solid #10b981; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:600;">
        ✅ <?= htmlspecialchars($successMsg) ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div style="background:#fdf2f2; color:#ef4444; border:1px solid #f87171; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:600;">
        ⚠️ <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<!-- Educational Guide Box -->
<div style="background:var(--bg-surface-soft); border:1px solid var(--border-light); border-radius:8px; padding:20px; margin-bottom:24px;">
    <h3 style="font-size:15px; font-weight:700; color:var(--text-primary); margin-top:0; margin-bottom:8px;">💡 How to Use SEO Meta &amp; OpenGraph Settings:</h3>
    <ul style="margin:0; padding-left:20px; font-size:13px; color:var(--text-secondary); line-height:1.7;">
        <li><strong>Meta Title:</strong> The blue title link that appears on Google Search results (keep under 60 characters).</li>
        <li><strong>Meta Description:</strong> The summary paragraph shown under the Google title link (keep under 155 characters).</li>
        <li><strong>Meta Keywords:</strong> Comma-separated search terms for search crawlers (e.g. <code>silk kurtis, luxury fashion, dievon</code>).</li>
        <li><strong>OpenGraph Preview:</strong> Automatic preview card shown when customers share your links on <strong>WhatsApp, Instagram, or Facebook</strong>.</li>
    </ul>
</div>

<form action="seo.php" method="POST">
    <div class="glass-panel" style="padding:0; overflow:hidden; margin-bottom:24px;">
        <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
            <span>🌐 Website Page Meta Settings (<?= count($seoPages) ?> Pages)</span>
            <button type="submit" name="save_seo" class="btn-primary" style="padding:8px 20px; font-size:13px;">
                <i class="fa-solid fa-floppy-disk"></i> Save SEO Settings
            </button>
        </div>

        <div style="padding:24px; display:flex; flex-direction:column; gap:24px;">
            <?php foreach ($seoPages as $sp): ?>
                <div style="background:#ffffff; border:1px solid var(--border-light); border-radius:8px; padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border-light); padding-bottom:10px;">
                        <span style="font-size:16px; font-weight:700; color:var(--color-primary); text-transform:uppercase; letter-spacing:1px;">
                            📄 <?= htmlspecialchars(strtoupper($sp['page_slug'])) ?> PAGE
                        </span>
                        <code style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars(SITE_URL . '/' . ($sp['page_slug']==='home' ? '' : $sp['page_slug'])) ?></code>
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">Google Meta Title</label>
                        <input type="text" name="seo[<?= $sp['page_slug'] ?>][meta_title]" class="form-control" value="<?= htmlspecialchars($sp['meta_title']) ?>" placeholder="Title for Google Search">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">Google Meta Description</label>
                        <textarea name="seo[<?= $sp['page_slug'] ?>][meta_description]" class="form-control" rows="2" placeholder="Summary paragraph for Google Search"><?= htmlspecialchars($sp['meta_description']) ?></textarea>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Search Keywords (Comma Separated)</label>
                        <input type="text" name="seo[<?= $sp['page_slug'] ?>][meta_keywords]" class="form-control" value="<?= htmlspecialchars($sp['meta_keywords']) ?>" placeholder="e.g. silk kurtis, luxury fashion, dievon">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
