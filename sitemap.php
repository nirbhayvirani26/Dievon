<?php
// Dynamically generated XML sitemap — always reflects the current domain (via
// SITE_URL) and the live product/category/blog catalog, so it never goes stale
// the way a hand-maintained static sitemap.xml would.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/xml; charset=utf-8');

function sm_url($loc, $priority, $changefreq, $lastmod = null) {
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($loc) . '</loc>' . "\n";
    if ($lastmod) {
        echo '    <lastmod>' . htmlspecialchars($lastmod) . '</lastmod>' . "\n";
    }
    echo '    <changefreq>' . $changefreq . '</changefreq>' . "\n";
    echo '    <priority>' . $priority . '</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

sm_url(SITE_URL . '/', '1.0', 'daily');
sm_url(SITE_URL . '/shop', '0.9', 'daily');
sm_url(SITE_URL . '/about', '0.7', 'monthly');
sm_url(SITE_URL . '/blog', '0.8', 'weekly');
sm_url(SITE_URL . '/contact', '0.7', 'monthly');
sm_url(SITE_URL . '/privacy', '0.3', 'yearly');
sm_url(SITE_URL . '/terms', '0.3', 'yearly');
sm_url(SITE_URL . '/shipping', '0.4', 'yearly');
sm_url(SITE_URL . '/returns', '0.4', 'yearly');

try {
    $cats = $pdo->query("SELECT name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cats as $catName) {
        sm_url(SITE_URL . '/shop?category=' . urlencode($catName), '0.6', 'weekly');
    }
} catch (PDOException $e) {}

try {
    $products = $pdo->query("SELECT id, name, created_at FROM products WHERE available = 1 AND is_deleted = 0 ORDER BY id DESC")->fetchAll();
    foreach ($products as $p) {
        $lastmod = !empty($p['created_at']) ? date('Y-m-d', strtotime($p['created_at'])) : null;
        sm_url(SITE_URL . '/product/' . slugify($p['name']) . '-' . (int)$p['id'], '0.8', 'weekly', $lastmod);
    }
} catch (PDOException $e) {}

try {
    $posts = $pdo->query("SELECT id, published_date FROM blog_posts WHERE status = 'Published' ORDER BY id DESC")->fetchAll();
    foreach ($posts as $post) {
        $lastmod = !empty($post['published_date']) ? date('Y-m-d', strtotime($post['published_date'])) : null;
        sm_url(SITE_URL . '/blog-single?id=' . (int)$post['id'], '0.6', 'monthly', $lastmod);
    }
} catch (PDOException $e) {}

echo '</urlset>' . "\n";
