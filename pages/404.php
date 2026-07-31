<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Page Not Found | Dievon Atelier";
$noindex = true;
if (http_response_code() !== 404) { http_response_code(404); }
require_once __DIR__ . '/../includes/header.php';
?>

<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_3.png'); padding: 160px 0 90px; text-align: center;">
    <div class="container reveal-on-scroll">
        <div style="font-size: 72px; margin-bottom: 10px; font-family: var(--font-heading); color: #ffffff;">404</div>
        <span class="luxury-hero-eyebrow">Destination Unknown</span>
        <h1 style="font-size: 32px; margin-bottom: 20px;">Page Not Found</h1>
        <p style="max-width: 500px; margin: 0 auto 30px;">
            The page or garment collection you were searching for may have moved or is no longer available.
        </p>
        <div style="display: flex; justify-content: center; gap: 15px;">
            <a href="<?= SITE_URL ?>/home" class="btn-luxury">Return to Homepage</a>
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury-outline">Browse Shop Collections</a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
