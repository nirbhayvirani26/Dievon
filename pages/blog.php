<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Query published articles from DB
$articles = [];
try {
    // Same audience filter the product grids use — see blogGenderSqlFilter().
    $articles = $pdo->query("SELECT * FROM blog_posts WHERE status = 'Published'" . blogGenderSqlFilter($pdo) . " ORDER BY published_date DESC, id DESC")->fetchAll();
} catch (PDOException $e) {}

$pageTitle = "Dievon Journal | Luxury Fashion & Style Guides";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "Styling notes, fabric guides and sizing advice from the Dievon atelier — how to choose, wear and care for Indian ethnic womenswear.";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image section-mb-sm" style="--hero-bg-image: url('<?= lookbookUrl(3) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Dievon Journal</span>
        <h1>Boutique Chronicles</h1>
        <p>A collection of editorial musings, style guides, and atelier production logs.</p>
    </div>
</section>

<!-- ══ Blog List Grid ═════════════════════════════════════ -->
<section class="section-space">
    <div class="container">
        
        <?php if (empty($articles)): ?>
            <div class="blog-empty">
                <div class="blog-empty-icon">📰</div>
                <p class="blog-empty-text">No journal articles published yet. Check back soon for style guides!</p>
            </div>
        <?php else: ?>
            <div class="blog-list-grid">
                <?php // Shared partial — see includes/blog_card.php. Defaults are
                      // this listing's own shape, so nothing is passed but the row. ?>
                <?php foreach ($articles as $a): ?>
                    <?php $blogPost = $a; include __DIR__ . '/../includes/blog_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
