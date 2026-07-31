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
        if (!empty($seoRow['og_image'])) { $ogImage = SITE_URL . '/uploads/gallery/' . $seoRow['og_image']; }
    }
} catch (PDOException $e) {}
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_1.png')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Dievon Heritage</span>
        <h1>The Dievon Legacy</h1>
        <p>A commitment to pure mulberry silk, Tuscan calfskins, and tailors crafted over generations.</p>
    </div>
</section>

<!-- ══ Narrative / Heritage Grid ══════════════════════════ -->
<section class="about-narrative-section section-space">
    <div class="container product-detail-container">

        
        <div class="about-narrative-grid reveal-on-scroll" style="margin-bottom: 80px;">
            <div>
                <span class="editorial-label">The Inception</span>
                <h2 style="font-family: var(--font-heading); font-size: 38px; font-weight: 300; text-transform: uppercase; margin-bottom: 25px; line-height: 1.3;">Crafted by Hand,<br>Inspired by Legacy</h2>
                <p style="color: var(--text-secondary); font-size: 14px; line-height: 1.8; margin-bottom: 20px;">
                    Dievon was founded with a simple, uncompromising vision: to design garments that capture the regal fluid drape of raw Mulberry silk, organza, and the timeless heritage of Indian hand embroidery.
                </p>
                <p style="color: var(--text-secondary); font-size: 14px; line-height: 1.8; margin-bottom: 0;">
                    We believe true luxury is about refinement, authentic materials, and artisanal mastery. By partnering directly with master handloom weavers in Jaipur, Lucknow, and Chanderi, we preserve heritage craftsmanship while delivering a modern, high-fashion silhouette for today's woman.
                </p>
            </div>
            <div>
                <img src="<?= SITE_URL ?>/uploads/gallery/lookbook_2.png" alt="Dievon Atelier Heritage" style="width: 100%; border: 1px solid var(--border-light); box-shadow: var(--shadow-lg);">
            </div>
        </div>

        <div class="about-narrative-grid reveal-on-scroll">
            <div>
                <img src="<?= SITE_URL ?>/uploads/gallery/lookbook_3.png" alt="Artisan Atelier Studio" style="width: 100%; border: 1px solid var(--border-light); box-shadow: var(--shadow-lg);">
            </div>
            <div>
                <span class="editorial-label">Our Craft Standards</span>
                <h2 style="font-family: var(--font-heading); font-size: 38px; font-weight: 300; text-transform: uppercase; margin-bottom: 25px; line-height: 1.3;">Uncompromising Materiality</h2>
                <p style="color: var(--text-secondary); font-size: 14px; line-height: 1.8; margin-bottom: 20px;">
                    Every weave, dye, and embroidery stitch selected for our collections undergoes extensive tactile and quality checks. Our signature Kurta Sets and 3-Piece ensembles are crafted from heavy Mulberry silk and pure organza that flow with dramatic, liquid-like grace.
                </p>
                <p style="color: var(--text-secondary); font-size: 14px; line-height: 1.8; margin-bottom: 0;">
                    Our hand-embroidered Zari and Gota Patti embellishments are crafted by master artisans with decades of expertise. We create investment heirlooms built to celebrate life's most precious occasions.
                </p>
            </div>
        </div>

    </div>
</section>

<!-- ══ Brand Values / Features ════════════════════════════ -->
<section class="about-values-section section-space">
    <div class="container product-detail-container text-center">

        <span class="editorial-label" style="margin-bottom: 20px;">Dievon Standards</span>
        <h2 style="font-family: var(--font-heading); font-size: 36px; font-weight: 300; text-transform: uppercase; margin-bottom: 50px;">Bespoke Commitments</h2>
        
        <div class="about-values-grid reveal-on-scroll">
            <div>
                <div style="font-size: 32px; color: var(--color-accent); margin-bottom: 15px;">🇮🇹</div>
                <h3 style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; margin-bottom: 10px;">Como & Florence Ateliers</h3>
                <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6;">Our garments are created in family ateliers employing craftsmen with decades of specialized expertise.</p>
            </div>
            <div>
                <div style="font-size: 32px; color: var(--color-accent); margin-bottom: 15px;">🌿</div>
                <h3 style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; margin-bottom: 10px;">Ethically Certified</h3>
                <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6;">We source only from certified ethical suppliers, prioritizing material traceability and animal welfare.</p>
            </div>
            <div>
                <div style="font-size: 32px; color: var(--color-accent); margin-bottom: 15px;">📐</div>
                <h3 style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; margin-bottom: 10px;">Bespoke Sizing Consultation</h3>
                <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6;">Book private fittings or customized length tailorings to create the perfect drape for your silhouette.</p>
            </div>
        </div>
    </div>
</section>


<?php
require_once __DIR__ . '/../includes/footer.php';
?>
