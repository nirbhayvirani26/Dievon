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
                <?php foreach ($articles as $a): 
                    // One resolver, shared with the article page, the homepage and the
                    // admin list — so all four cannot disagree again. Lookbook filenames
                    // are deliberately NOT honoured here; see blogImageUrl().
                    $imgUrl = blogImageUrl($a['image'] ?? '');
                ?>
                    <article class="reveal-on-scroll blog-card">
                        <div class="blog-card-media">
                            <a href="<?= SITE_URL ?>/blog-single?id=<?= $a['id'] ?>">
                                <?php $cardWebpUrl = preg_replace('/\.[^.]+$/', '.webp', $imgUrl);
                                      $cardWebpFile = str_replace(SITE_URL . '/', __DIR__ . '/../', $cardWebpUrl); ?>
                                <picture>
                                    <?php if (webpSourceIsFresh($imgUrl, $cardWebpFile)): ?><source srcset="<?= htmlspecialchars(cacheBustedUploadUrl($cardWebpUrl)) ?>" type="image/webp"><?php endif; ?>
                                    <img src="<?= htmlspecialchars(cacheBustedUploadUrl($imgUrl)) ?>" alt="<?= htmlspecialchars($a['title']) ?>" class="blog-card-img" loading="lazy" decoding="async">
                                </picture>
                            </a>
                        </div>
                        <div class="blog-card-body">
                            <div class="blog-card-meta">
                                <span class="blog-card-category"><?= htmlspecialchars($a['category'] ?? 'Style Guide') ?></span> &bull; 
                                <span class="blog-card-date"><?= date('F d, Y', strtotime($a['published_date'])) ?></span>
                            </div>
                            <h2 class="blog-card-title">
                                <a href="<?= SITE_URL ?>/blog-single?id=<?= $a['id'] ?>"><?= htmlspecialchars($a['title']) ?></a>
                            </h2>
                            <p class="blog-card-excerpt">
                                <?= htmlspecialchars($a['excerpt']) ?>
                            </p>
                            <div>
                                <a href="<?= SITE_URL ?>/blog-single?id=<?= $a['id'] ?>" class="blog-card-link">
                                    Read Article <i class="fa-solid fa-arrow-right-long"></i>
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
