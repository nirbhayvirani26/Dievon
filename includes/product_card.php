<?php
/**
 * Dievon – The product card. One definition, used everywhere.
 *
 * This markup previously existed three times over — shop.php, home.php and the
 * "Suggested Pieces" grid on product.php — and they had drifted apart. The
 * suggested grid had lost its badge, wishlist button, compare toggle, Quick
 * View, strikethrough price and Details button, so the same product looked like
 * a different component depending on which page you were standing on.
 *
 * A partial rather than a copy means restyling the card once restyles it
 * everywhere, and a card can never silently lose a feature again.
 *
 * Usage:
 *   $card = $p;                       // the product row
 *   include __DIR__ . '/product_card.php';
 *
 * Optional, set before including:
 *   $cardCompare       — false to hide the compare toggle (default true)
 *   $cardQuickView     — false to hide Quick View (default true)
 *   $cardExtraClass    — extra class on the <article>, e.g. 'product-card-carousel'
 *   $cardFallbackBadge — badge to show when the product has none and is not on sale
 */

$cardProduct = $card ?? null;
if (!is_array($cardProduct) || empty($cardProduct['id'])) { return; }

$cardCompare       = $cardCompare   ?? true;
$cardQuickView     = $cardQuickView ?? true;
$cardExtraClass    = $cardExtraClass ?? '';
$cardFallbackBadge = $cardFallbackBadge ?? '';

// Country pricing: product_country_prices carries one flat price per
// product (see productCountryPricing() in config.php), so a shopper abroad
// either sees that figure or, with no row for their country yet, the card
// behaves like a sold-out one rather than showing an untranslated INR price.
$cardNotSoldHere = false;
if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
    $cardCountryPricing = productCountryPricing($cardProduct);
    if ($cardCountryPricing === null) {
        $cardNotSoldHere = true;
        $cardPrice = (float)($cardProduct['price'] ?? 0);
        $cardMrp   = (float)($cardProduct['mrp_price'] ?? 0);
    } else {
        $cardPrice = $cardCountryPricing['price'];
        $cardMrp   = $cardCountryPricing['mrp'];
    }
} else {
    $cardPrice = (float)($cardProduct['price'] ?? 0);
    $cardMrp   = (float)($cardProduct['mrp_price'] ?? 0);
}

// A "discount" is only real when the compare-at price is genuinely higher.
// Equal values would render as "0% OFF", which reads as a bug.
$cardHasDiscount    = $cardMrp > $cardPrice && $cardPrice > 0;
$cardDiscountPercent = $cardHasDiscount ? (int)round((($cardMrp - $cardPrice) / $cardMrp) * 100) : 0;

// ── Sold-out state ──────────────────────────────────────────
// The card had no stock awareness at all, so a sold-out garment still showed
// its price and a buyable "Details" button. Published + track_stock + no
// sellable stock left = sold out. The per-product stock is cached per request
// (one small query, shared across every rail and grid on the page).
$cardPublished = (int)($cardProduct['available'] ?? 1) === 1;
$cardSoldOut   = false;
if ($cardPublished && !empty($cardProduct['track_stock'])) {
    static $cardStockCache = [];
    $cardPid = (int)$cardProduct['id'];
    if (!array_key_exists($cardPid, $cardStockCache)) {
        $cardStockCache[$cardPid] = productSellableStock($GLOBALS['pdo'] ?? null, $cardProduct);
    }
    $cardSoldOut = $cardStockCache[$cardPid] <= 0;
}
// Not sold in the shopper's country reads the same as sold out on the card:
// no price to show, no way to buy it here.
$cardSoldOut = $cardSoldOut || $cardNotSoldHere;

$cardUrl = productUrl($cardProduct['id'], $cardProduct['name'], $cardProduct['seo_url'] ?? null);
?>
<?php /* data-product-id is what syncBagNotes() in includes/footer.php matches on.
         The "in your bag" line is filled in by that one function for BOTH card
         renderers — this partial and createProductCard() in pages/shop.php — so
         the two cannot drift apart again, and the count stays right the moment
         the bag changes without waiting for a page load. */ ?>
<article class="product-card reveal-on-scroll<?= $cardSoldOut ? ' sold-out' : '' ?><?= $cardExtraClass !== '' ? ' ' . htmlspecialchars($cardExtraClass) : '' ?>"
         data-product-id="<?= (int)$cardProduct['id'] ?>">
    <?php if ($cardNotSoldHere): ?>
        <span class="badge-luxury badge-sold-out">Not Available Here</span>
    <?php elseif ($cardSoldOut): ?>
        <span class="badge-luxury badge-sold-out">Sold Out</span>
    <?php elseif ($cardHasDiscount): ?>
        <span class="badge-luxury badge-sale"><?= $cardDiscountPercent ?>% OFF</span>
    <?php elseif (!empty($cardProduct['badge'])): ?>
        <span class="badge-luxury"><?= htmlspecialchars($cardProduct['badge']) ?></span>
    <?php elseif ($cardFallbackBadge !== ''): ?>
        <span class="badge-luxury"><?= htmlspecialchars($cardFallbackBadge) ?></span>
    <?php endif; ?>

    <button class="product-card-wishlist-btn"
            onclick="event.preventDefault(); handleWishlistClick(<?= (int)$cardProduct['id'] ?>, this)"
            aria-label="Add to Wishlist">
        <i class="fa-regular fa-heart"></i>
    </button>

    <div class="card-img-container">
        <a href="<?= $cardUrl ?>" class="card-img-link">
            <?php if (!empty($cardProduct['image'])):
                // Serve the WebP copy where one exists. Every uploaded image already has
                // one generated beside it (see dievonMakeWebp), and pages/product.php has
                // always used them — but this card, which is every tile on the shop, the
                // collections, the home page and the suggested grid, was still serving the
                // full-size JPEG/PNG. Across the catalogue that is 13.4 MB of originals
                // against 3.5 MB of WebP, on exactly the pages a shopper lands on first.
                $cardWebp = webpUrlIfExists('products', $cardProduct['image']);
                // productImageAlt() rather than a fifth hand-written copy of the same
                // fallback. This partial renders the card on the shop, the category
                // pages, the home rails and the related strip, so it is the single
                // biggest source of alt text on the site.
                $cardAlt  = productImageAlt($cardProduct);
            ?>
                <picture>
                    <?php if ($cardWebp): ?><source srcset="<?= htmlspecialchars($cardWebp) ?>" type="image/webp"><?php endif; ?>
                    <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($cardProduct['image']) ?>"
                         alt="<?= htmlspecialchars($cardAlt) ?>" loading="lazy" decoding="async" class="card-img">
                </picture>
                <?php
                /* The second shot, revealed on hover.
                   ────────────────────────────────────────────────────────────
                   Only built when the product actually has a different second
                   photograph, so a single-picture product is untouched rather
                   than cross-fading to itself.

                   src is deliberately empty and the real path sits in data-src:
                   a shop grid of 24 tiles would otherwise pull a second full
                   image per tile on first paint, doubling the page weight for
                   something most shoppers never hover. The delegated listener
                   in includes/footer.php fills it in on the first pointer that
                   arrives, and aria-hidden keeps the duplicate garment out of
                   the accessibility tree — it is the same product, already
                   named by the image above. */
                $cardHoverImg = productHoverImage($cardProduct);
                if ($cardHoverImg):
                    $cardHoverWebp = webpUrlIfExists('products', $cardHoverImg);
                ?>
                    <img class="card-img card-img-hover" alt="" aria-hidden="true" decoding="async"
                         data-src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($cardHoverImg) ?>"
                         <?php if ($cardHoverWebp): ?>data-webp="<?= htmlspecialchars($cardHoverWebp) ?>"<?php endif; ?>>
                <?php endif; ?>
            <?php elseif (!empty($cardProduct['video_url'])):
                $cardVid = trim((string)$cardProduct['video_url']);
                $cardVidSrc = (str_starts_with($cardVid, 'http://') || str_starts_with($cardVid, 'https://'))
                    ? $cardVid : SITE_URL . '/uploads/products/' . $cardVid;
            ?>
                <video src="<?= htmlspecialchars($cardVidSrc) ?>" autoplay loop muted playsinline class="card-img"></video>
            <?php else: ?>
                <div class="card-img-fallback"><?= htmlspecialchars($cardProduct['emoji'] ?? '👗') ?></div>
            <?php endif; ?>
        </a>

        <?php if ($cardCompare): ?>
        <label class="compare-toggle" title="Compare (up to 3)" onclick="event.stopPropagation()">
            <input type="checkbox" class="compare-check" value="<?= (int)$cardProduct['id'] ?>"
                   data-name="<?= htmlspecialchars($cardProduct['name'], ENT_QUOTES) ?>"
                   onchange="toggleCompare(this)">
            <span>Compare</span>
        </label>
        <?php endif; ?>

        <?php if ($cardQuickView): ?>
        <button onclick="event.preventDefault(); openQuickView(<?= (int)$cardProduct['id'] ?>)" class="quick-view-btn">Quick View</button>
        <?php endif; ?>
    </div>

    <div class="product-card-details">
        <p class="product-card-bag-note" data-bag-note hidden></p>
        <span class="product-card-category"><?= htmlspecialchars($cardProduct['category'] ?? '') ?></span>
        <h3 class="product-card-title">
            <a href="<?= $cardUrl ?>"><?= htmlspecialchars($cardProduct['name']) ?></a>
        </h3>
        <?php
        /* The price a shopper can actually pay.
           ────────────────────────────────────────────────────────────────
           This printed products.price flat. Every other surface resolves the
           price through effectiveVariantPrice(), which prefers a size's own
           price — so on a product whose sizes are priced individually the card
           advertised a figure that was not for sale. A product priced 50 whose
           only size was priced 2000 was listed at ₹50 here and charged ₹2,000
           at the bag.

           Country pricing is one flat figure per product and overrides sizes
           entirely (see productCountryPricing), so it is left exactly as it
           was — the spread only applies where the product's own columns are
           the ones in play. */
        $cardRange   = $cardCountryPricing ?? null;
        $cardVaries  = false;
        if ($cardRange === null && !$cardNotSoldHere) {
            $r = productPriceRange($cardProduct);
            if ($r['varies'] || $r['min'] < $cardPrice - 0.005) {
                $cardPrice   = $r['min'];
                $cardVaries  = $r['varies'];
                // A "from" price and a strikethrough MRP together claim a
                // discount that only holds for the cheapest size, so the
                // strike is dropped once the price is a floor rather than
                // the price.
                $cardHasDiscount = $cardHasDiscount && !$cardVaries;
            }
        }
        ?>
        <div class="product-card-price">
            <?php if ($cardNotSoldHere): ?>
                Not available in your country
            <?php else: ?>
                <?php if ($cardHasDiscount): ?>
                    <span class="price-mrp-strike"><?= formatPrice($cardMrp) ?></span>
                <?php endif; ?>
                <?php if ($cardVaries): ?><span class="price-from-label">From</span> <?php endif; ?>
                <?= formatPrice($cardPrice) ?>
            <?php endif; ?>
        </div>
        <?php if ($cardNotSoldHere): ?>
        <a href="<?= $cardUrl ?>" class="btn-luxury product-card-cta">Details</a>
        <?php elseif ($cardSoldOut): ?>
        <span class="btn-luxury product-card-cta sold-out-cta" aria-disabled="true">Sold Out</span>
        <?php else: ?>
        <a href="<?= $cardUrl ?>" class="btn-luxury product-card-cta">Details</a>
        <?php endif; ?>
    </div>
</article>
<?php
// Clear the per-card options. Without this a caller that sets $cardExtraClass once
// leaks it into every later include in the same request — the carousel class would
// end up on the grid below it.
unset($cardCompare, $cardQuickView, $cardExtraClass, $cardFallbackBadge, $card);
?>
