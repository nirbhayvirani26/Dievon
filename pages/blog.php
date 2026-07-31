<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Query published articles from DB
$articles = [];
try {
    $articles = $pdo->query("SELECT * FROM blog_posts WHERE status = 'Published' ORDER BY published_date DESC, id DESC")->fetchAll();
} catch (PDOException $e) {}

$pageTitle = "Dievon Journal | Luxury Fashion & Style Guides";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image section-mb-sm" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_3.png')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Dievon Journal</span>
        <h1>Boutique Chronicles</h1>
        <p>A collection of editorial musings, style guides, and atelier production logs.</p>
    </div>
</section>

<!-- ══ Blog List Grid ═════════════════════════════════════ -->
<section class="section-space">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        
        <?php if (empty($articles)): ?>
            <div style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
                <div style="font-size: 48px; margin-bottom: 12px; opacity: 0.3;">📰</div>
                <p style="font-size: 16px;">No journal articles published yet. Check back soon for style guides!</p>
            </div>
        <?php else: ?>
            <div class="blog-list-grid">
                <?php foreach ($articles as $a): 
                    $imgFile = $a['image'] ?? '';
                    if (!empty($imgFile) && file_exists(__DIR__ . '/../uploads/products/' . $imgFile)) {
                        $imgUrl = SITE_URL . '/uploads/products/' . $imgFile;
                    } elseif (!empty($imgFile) && file_exists(__DIR__ . '/../uploads/gallery/' . $imgFile)) {
                        $imgUrl = SITE_URL . '/uploads/gallery/' . $imgFile;
                    } else {
                        $imgUrl = SITE_URL . '/uploads/gallery/lookbook_1.png';
                    }
                ?>
                    <article class="reveal-on-scroll" style="background: var(--bg-surface); border: 1px solid var(--border-light); display: flex; flex-direction: column;">
                        <div style="height: 250px; overflow: hidden; position: relative;">
                            <a href="<?= SITE_URL ?>/blog-single?id=<?= $a['id'] ?>">
                                <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($a['title']) ?>" style="width: 100%; height: 100%; object-fit: cover; transition: var(--transition-slow);">
                            </a>
                        </div>
                        <div style="padding: 30px; display: flex; flex-direction: column; flex: 1;">
                            <div style="margin-bottom: 12px; font-size: 11px; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">
                                <span style="color: var(--color-accent);"><?= htmlspecialchars($a['category'] ?? 'Style Guide') ?></span> &bull; 
                                <span style="color: var(--text-muted);"><?= date('F d, Y', strtotime($a['published_date'])) ?></span>
                            </div>
                            <h2 style="font-family: var(--font-heading); font-size: 24px; font-weight: 400; line-height: 1.3; margin-bottom: 15px; min-height: 62px;">
                                <a href="<?= SITE_URL ?>/blog-single?id=<?= $a['id'] ?>" style="color: var(--text-primary);"><?= htmlspecialchars($a['title']) ?></a>
                            </h2>
                            <p style="color: var(--text-secondary); font-size: 13px; line-height: 1.7; margin-bottom: 25px; flex: 1;">
                                <?= htmlspecialchars($a['excerpt']) ?>
                            </p>
                            <div>
                                <a href="<?= SITE_URL ?>/blog-single?id=<?= $a['id'] ?>" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; color: var(--text-primary);">
                                    Read Article <i class="fa-solid fa-arrow-right-long" style="margin-left: 5px;"></i>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
