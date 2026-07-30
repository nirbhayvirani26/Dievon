<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$article = null;
try {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = :id AND status = 'Published'");
        $stmt->execute(['id' => $id]);
        $article = $stmt->fetch();
    }
    if (!$article) {
        $article = $pdo->query("SELECT * FROM blog_posts WHERE status = 'Published' ORDER BY id ASC LIMIT 1")->fetch();
    }
} catch (PDOException $e) {}

if (!$article) {
    header('Location: blog');
    exit;
}

$pageTitle = !empty($article['meta_title']) ? $article['meta_title'] : $article['title'];
$metaDescription = !empty($article['meta_description']) ? $article['meta_description'] : (substr(strip_tags($article['content']), 0, 155) . '...');

$imgFile = $article['image'] ?? '';
if (!empty($imgFile) && file_exists(__DIR__ . '/../uploads/products/' . $imgFile)) {
    $imgUrl = SITE_URL . '/uploads/products/' . $imgFile;
} elseif (!empty($imgFile) && file_exists(__DIR__ . '/../uploads/gallery/' . $imgFile)) {
    $imgUrl = SITE_URL . '/uploads/gallery/' . $imgFile;
} else {
    $imgUrl = SITE_URL . '/uploads/gallery/lookbook_1.png';
}
$ogImage = $imgUrl;
$canonicalUrl = SITE_URL . '/blog-single?id=' . $article['id'];

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Article Detailed Reading Section ════════════════════ -->
<article style="padding: 120px 0 100px; background: var(--bg-main); font-family: var(--font-body);">
    <div class="container reveal-on-scroll" style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Back to journal -->
        <div style="margin-bottom: 30px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em;">
            <a href="blog" style="color: var(--text-muted);"><i class="fa-solid fa-arrow-left" style="margin-right: 5px;"></i> Back to Journal</a>
        </div>

        <span class="editorial-label" style="margin-bottom: 12px;">
            <?= htmlspecialchars($article['category'] ?? 'Style Guide') ?> &bull; <?= date('F d, Y', strtotime($article['published_date'])) ?>
        </span>
        <h1 style="font-family: var(--font-heading); font-size: 42px; font-weight: 300; text-transform: uppercase; line-height: 1.2; margin-bottom: 40px; color: var(--text-primary);">
            <?= htmlspecialchars($article['title']) ?>
        </h1>

        <!-- Feature Image -->
        <div style="border: 1px solid var(--border-light); padding: 10px; background: var(--bg-surface); margin-bottom: 50px;">
            <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($article['title']) ?>" style="width: 100%; height: auto; max-height: 550px; object-fit: cover;">
        </div>

        <!-- Article Content -->
        <div style="font-size: 16px; line-height: 1.9; color: var(--text-secondary); max-width: 750px; margin: 0 auto;">
            <?php 
                $paragraphs = explode("\n\n", $article['content']);
                foreach ($paragraphs as $p) {
                    echo "<p style='margin-bottom: 25px;'>" . nl2br(htmlspecialchars(trim($p))) . "</p>";
                }
            ?>
        </div>

        <!-- Social Share & Return Buttons -->
        <div style="margin-top: 60px; border-top: 1px solid var(--border-light); padding-top: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div style="display: flex; gap: 12px; align-items: center;">
                <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; color: var(--text-muted);">Share:</span>
                <button onclick="navigator.clipboard.writeText(window.location.href); alert('Article link copied to clipboard!');" style="font-size: 14px; color: var(--text-primary); cursor: pointer; background:none; border:none;"><i class="fa-solid fa-link"></i></button>
                <button onclick="window.open('https://pinterest.com/pin/create/button/?url='+encodeURIComponent(window.location.href), '_blank');" style="font-size: 14px; color: var(--text-primary); cursor: pointer; background:none; border:none;"><i class="fa-brands fa-pinterest"></i></button>
                <button onclick="window.open('https://twitter.com/intent/tweet?url='+encodeURIComponent(window.location.href), '_blank');" style="font-size: 14px; color: var(--text-primary); cursor: pointer; background:none; border:none;"><i class="fa-brands fa-x-twitter"></i></button>
            </div>
            
            <a href="shop" class="btn-luxury" style="font-size: 11px; padding: 10px 24px;">Shop Collections</a>
        </div>

    </div>
</article>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
