<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Page Not Found | Dievon";
$noindex = true;
// Only default to 404 when nothing has been decided yet.
//
// This read `!== 404`, which forced a 404 over any code a caller had already
// set — so pages/product.php:33, which deliberately answers 410 Gone for a
// withdrawn product and explains at length why, had its 410 overwritten here
// on every single request. That path has never once emitted a 410: the whole
// point is that Google drops a 410 from the index far faster than a 404, and
// the discontinued product URLs were quietly getting the slower one.
//
// 200 is the "caller said nothing" case, since that is what PHP reports before
// anything sets a status. Any explicit code (410 here, and anything added
// later) is now left alone.
if (http_response_code() === 200) { http_response_code(404); }
require_once __DIR__ . '/../includes/header.php';
?>

<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(3) ?>'); padding: 160px 0 90px; text-align: center;">
    <div class="container reveal-on-scroll">
        <div style="font-size: 72px; margin-bottom: 10px; font-family: var(--font-heading); color: #ffffff;">404</div>
        <span class="luxury-hero-eyebrow">Destination Unknown</span>
        <h1 style="font-size: 32px; margin-bottom: 20px;">Page Not Found</h1>
        <p style="max-width: 500px; margin: 0 auto 30px;">
            The page or garment collection you were searching for may have moved or is no longer available.
        </p>
        <?php /* wrap + stretch. Without them the row refused to break, so on a
                 narrow screen the two buttons squeezed against each other until
                 their labels ran to two and three lines and the pair finished at
                 different heights -- the last thing a shopper sees before deciding
                 whether to carry on. They sit side by side while there is room and
                 stack cleanly when there is not. */ ?>
        <div style="display: flex; justify-content: center; align-items: stretch; flex-wrap: wrap; gap: 15px;">
            <a href="<?= SITE_URL ?>/" class="btn-luxury" style="white-space: nowrap;">Return to Homepage</a>
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury-outline" style="white-space: nowrap;">Browse Shop Collections</a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
