<?php
// Dynamically generated XML sitemap — always reflects the current domain (via
// SITE_URL) and the live product/category/blog catalog, so it never goes stale
// the way a hand-maintained static sitemap.xml would.
//
// The rule this file follows: a sitemap is a list of URLs you are asking Google
// to index, so every entry must be a canonical URL that returns 200 and has
// something on it. Listing a redirect, a noindex page or an empty category
// spends crawl budget and shows up in Search Console as "Discovered — currently
// not indexed", which is noise that hides real problems.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/xml; charset=utf-8');

function sm_url($loc, $priority, $changefreq, $lastmod = null) {
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . "\n";
    if ($lastmod) {
        echo '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1) . '</lastmod>' . "\n";
    }
    echo '    <changefreq>' . $changefreq . '</changefreq>' . "\n";
    echo '    <priority>' . $priority . '</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Cart, checkout, account, wishlist, order tracking and the 404 are deliberately
// absent: they carry <meta name="robots" content="noindex">, and a sitemap entry
// for a noindex page is a direct contradiction Search Console reports as an error.
sm_url(SITE_URL . '/', '1.0', 'daily');
sm_url(SITE_URL . '/shop', '0.9', 'daily');

// /men is the menswear landing page and its own canonical, so it has to be
// listed in its own right. Its collections were already here, but the entry
// point they hang off was not — Google could reach individual men's collections
// while the page that presents menswear as a section went unsubmitted.
//
// Gated on menswear actually having stock, following the rule at the top of this
// file: an empty page in a sitemap is spent crawl budget and shows up in Search
// Console as "Discovered — currently not indexed". While the shop is womenswear
// only, /men is not advertised.
if (function_exists('gendersWithLiveStock') && in_array('men', gendersWithLiveStock(), true)) {
    sm_url(SITE_URL . '/men', '0.9', 'daily');
}
sm_url(SITE_URL . '/about', '0.7', 'monthly');
sm_url(SITE_URL . '/blog', '0.8', 'weekly');
sm_url(SITE_URL . '/contact', '0.7', 'monthly');
sm_url(SITE_URL . '/privacy', '0.3', 'yearly');
sm_url(SITE_URL . '/terms', '0.3', 'yearly');
sm_url(SITE_URL . '/shipping', '0.4', 'yearly');
sm_url(SITE_URL . '/returns', '0.4', 'yearly');

// ── Collections ─────────────────────────────────────────────────────────────
// Only categories that have something to sell, counted across the whole subtree
// so a parent whose pieces all live in its subcategories still qualifies.
//
// This previously listed every row in the table as /shop?category=<name>. Two
// problems: that URL now answers 301 to the collection URL, and empty categories
// (including one called "Testing") were being submitted as pages worth indexing.
try {
    $cats = $pdo->query("SELECT id, name, slug, parent_id FROM categories")->fetchAll(PDO::FETCH_ASSOC);

    $liveCounts = [];
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM products
          WHERE (category_id = :cid OR category = :cname)
            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)"
    );
    foreach ($cats as $c) {
        $countStmt->execute(['cid' => (int)$c['id'], 'cname' => $c['name']]);
        $liveCounts[(int)$c['id']] = (int)$countStmt->fetchColumn();
    }

    // Roll each count up to every ancestor.
    $parentOf = [];
    foreach ($cats as $c) { $parentOf[(int)$c['id']] = (int)($c['parent_id'] ?? 0); }
    $subtree = $liveCounts;
    foreach ($liveCounts as $cid => $n) {
        if ($n === 0) { continue; }
        $p = $parentOf[$cid] ?? 0;
        $guard = 0; // a category made its own ancestor must not hang the sitemap
        while ($p > 0 && $guard++ < 10) {
            $subtree[$p] = ($subtree[$p] ?? 0) + $n;
            $p = $parentOf[$p] ?? 0;
        }
    }

    foreach ($cats as $c) {
        if (($subtree[(int)$c['id']] ?? 0) < 1) { continue; }
        // A top-level collection outranks a subcategory of it.
        $priority = (int)($c['parent_id'] ?? 0) === 0 ? '0.7' : '0.6';
        sm_url(categoryUrl($c), $priority, 'weekly');
    }
} catch (PDOException $e) {}

// ── Products ────────────────────────────────────────────────────────────────
try {
    /* updated_at is SELECTED ONLY IF IT EXISTS, and a failure here is no longer
       silent.
       ────────────────────────────────────────────────────────────────────────
       products.updated_at is created by update_new_database.php, a migration
       somebody has to remember to run. Where it had not been run this SELECT
       threw "Unknown column 'updated_at'", the empty catch below swallowed it,
       and the sitemap was published with every static page and NOT ONE PRODUCT —
       a valid XML document, HTTP 200, no warning anywhere, and a catalogue
       invisible to search. Measured on this install: 10 URLs emitted, 25
       products available, zero of them listed.

       Two changes. The column is probed rather than assumed, so a database
       missing it still gets a complete sitemap with created_at as the fallback
       date. And the catch records what went wrong instead of discarding it —
       an XML comment a human will see when they look at the file, plus the
       server error log. A sitemap that cannot list products must say so; that
       is the whole failure here, not the missing column. */
    $hasUpdatedAt = false;
    try {
        $hasUpdatedAt = (bool)$pdo->query("SHOW COLUMNS FROM `products` LIKE 'updated_at'")->fetch();
    } catch (PDOException $e) { $hasUpdatedAt = false; }

    $products = $pdo->query(
        "SELECT id, name, seo_url, created_at" . ($hasUpdatedAt ? ", updated_at" : "") . " FROM products
          WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
          ORDER BY id DESC"
    )->fetchAll();
    foreach ($products as $p) {
        // updated_at is the honest answer to "when did this page last change";
        // created_at told Google a five-times-revised listing had never moved.
        $stamp = !empty($p['updated_at']) ? $p['updated_at'] : ($p['created_at'] ?? null);
        $lastmod = $stamp ? date('Y-m-d', strtotime($stamp)) : null;
        sm_url(productUrl($p['id'], $p['name'], $p['seo_url'] ?? null), '0.8', 'weekly', $lastmod);
    }
} catch (PDOException $e) {
    // Visible in the file itself and in the log. A sitemap silently missing the
    // entire catalogue is the worst possible way for this to fail.
    error_log('sitemap.php: product listing FAILED — ' . $e->getMessage());
    echo "  <!-- product listing failed; see the server error log -->\n";
}

// ── Journal ─────────────────────────────────────────────────────────────────
try {
    $posts = $pdo->query("SELECT id, published_date FROM blog_posts WHERE status = 'Published' ORDER BY id DESC")->fetchAll();
    foreach ($posts as $post) {
        $lastmod = !empty($post['published_date']) ? date('Y-m-d', strtotime($post['published_date'])) : null;
        sm_url(SITE_URL . '/blog-single?id=' . (int)$post['id'], '0.6', 'monthly', $lastmod);
    }
} catch (PDOException $e) {}

echo '</urlset>' . "\n";
