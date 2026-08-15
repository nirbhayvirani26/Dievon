<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Our Story";
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'about'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = cacheBustedUploadUrl(SITE_URL . '/uploads/gallery/' . $seoRow['og_image']); }
    }
} catch (PDOException $e) {}
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(1) ?>')">
    <div class="container">
        <?php // The brand line, in the one place on the site whose whole job is to
              // say who Dievon is. Same SHOP_TAGLINE as the footer and the emails. ?>
        <span class="luxury-hero-eyebrow"><?= htmlspecialchars(SHOP_TAGLINE) ?></span>
        <h1>The Dievon Legacy</h1>
        <?php // "Tuscan calfskins" on an Indian womenswear label that has never sold
              // leather. Replaced with the fabrics actually stocked. ?>
        <p>A commitment to pure mulberry silk, organza and linen, and to tailoring built over generations.</p>
    </div>
</section>

<!-- ══ Narrative / Heritage Grid ══════════════════════════ -->
<section class="about-narrative-section section-space">
    <div class="container product-detail-container">

        
        <div class="about-narrative-grid reveal-on-scroll is-spaced">
            <div>
                <span class="editorial-label">The Inception</span>
                <h2 class="about-heading">Crafted by Hand,<br>Inspired by Legacy</h2>
                <p class="about-copy">
                    Dievon was founded with a simple, uncompromising vision: to design garments that capture the regal fluid drape of raw Mulberry silk, organza, and the timeless heritage of Indian hand embroidery.
                </p>
                <p class="about-copy">
                    We believe true luxury is about refinement, authentic materials, and artisanal mastery. By partnering directly with master handloom weavers in Jaipur, Lucknow, and Chanderi, we preserve heritage craftsmanship while delivering a modern, high-fashion silhouette.
                </p>
            </div>
            <div>
                <img src="<?= lookbookUrl(2) ?>" alt="A Dievon kurta set in mulberry silk" class="about-figure-img" loading="lazy">
            </div>
        </div>

        <div class="about-narrative-grid reveal-on-scroll">
            <div>
                <img src="<?= lookbookUrl(3) ?>" alt="Hand-embroidery being worked on a Dievon garment" class="about-figure-img" loading="lazy">
            </div>
            <div>
                <span class="editorial-label">Our Craft Standards</span>
                <h2 class="about-heading">Uncompromising Materiality</h2>
                <p class="about-copy">
                    Every weave, dye, and embroidery stitch selected for our collections undergoes extensive tactile and quality checks. Our signature Kurta Sets and 3-Piece ensembles are crafted from heavy Mulberry silk and pure organza that flow with dramatic, liquid-like grace.
                </p>
                <p class="about-copy">
                    Our hand-embroidered Zari and Gota Patti embellishments are crafted by master artisans with decades of expertise. We create investment heirlooms built to celebrate life's most precious occasions.
                </p>
            </div>
        </div>

    </div>
</section>

<!-- ══ Brand Values / Features ════════════════════════════ -->
<section class="about-values-section section-space">
    <div class="container product-detail-container text-center">

        <span class="editorial-label about-values-label">Dievon Standards</span>
        <h2 class="about-values-heading">Bespoke Commitments</h2>
        
        <div class="about-values-grid reveal-on-scroll">
            <?php // These three cards previously read: an Italian flag over "Como &
                  // Florence Ateliers" (this is an Indian label), "Ethically Certified …
                  // animal welfare" (an unverifiable claim, on a catalogue with no leather),
                  // and "Book private fittings" (a service the site does not offer). They
                  // now state three things the site genuinely delivers, and each one is
                  // checkable by clicking through. ?>
            <div>
                <div class="about-value-icon">📐</div>
                <h3 class="about-value-title">A Measurement Chart on Every Piece</h3>
                <?php // "chest or bust", because this promise is made to both audiences on a
                      // page that has no gender branch. Men's garments are measured at the
                      // CHEST (see pages/product.php:229) against a 36-52 ladder, so the old
                      // wording described a measurement the men's size guide does not use. ?>
                <p class="about-value-text">Every garment carries its own size chart in inches — chest or bust, waist, hips and shoulder — so you can order your size without guessing.</p>
            </div>
            <div>
                <div class="about-value-icon">↩️</div>
                <h3 class="about-value-title"><?= (int)RETURN_WINDOW_DAYS ?>-Day Returns</h3>
                <p class="about-value-text">Not right? Send it back within <?= (int)RETURN_WINDOW_DAYS ?> days of delivery, unworn and with tags attached, and we refund to your original payment method.</p>
            </div>
            <div>
                <div class="about-value-icon">🚚</div>
                <h3 class="about-value-title">Dispatched the Same Day</h3>
                <p class="about-value-text">Orders placed before 2pm IST leave the same business day, and arrive across India in three to five working days.</p>
            </div>
        </div>
    </div>
</section>


<?php
require_once __DIR__ . '/../includes/footer.php';
?>
