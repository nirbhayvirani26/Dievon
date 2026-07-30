<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch product details
$product = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND available = 1");
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
} catch (PDOException $e) {}

if (!$product) {
    header('Location: shop.php');
    exit;
}

// Canonical SEO-friendly URL (/product/aurelia-silk-kurti-1). The id at the end is the
// real lookup key, so old links (?id=1) still resolve — they just 301 to this form.
$productSlugPath = '/product/' . slugify($product['name']) . '-' . $product['id'];
$canonicalUrl = SITE_URL . $productSlugPath;
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (substr($requestPath, -strlen($productSlugPath)) !== $productSlugPath) {
    header('Location: ' . $canonicalUrl, true, 301);
    exit;
}

$hasVideo = !empty(trim($product['video_url'] ?? ''));

// Fetch genuine approved reviews with exception safety (used by both the Product schema and the Reviews section below)
$dbReviews = [];
try {
    $revStmt = $pdo->prepare("SELECT pr.*, c.name as customer_name FROM product_reviews pr LEFT JOIN customers c ON pr.customer_id = c.id WHERE pr.product_id = :pid AND pr.status = 'Approved' ORDER BY pr.created_at DESC");
    $revStmt->execute(['pid' => $productId]);
    $dbReviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $exRev) {
    $dbReviews = [];
}

$revCount = count($dbReviews);
$avgRating = 5.0;
if ($revCount > 0) {
    $sum = array_sum(array_column($dbReviews, 'rating'));
    $avgRating = round($sum / $revCount, 1);
}


// Fetch variants
$variants = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :id AND available = 1 ORDER BY sort_order ASC, id ASC");
    $stmt->execute(['id' => $productId]);
    $variants = $stmt->fetchAll();
} catch (PDOException $e) {}

// Fetch additional images
$additionalImages = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = :id ORDER BY sort_order ASC, id ASC");
    $stmt->execute(['id' => $productId]);
    $additionalImages = $stmt->fetchAll();
} catch (PDOException $e) {}

// Fetch related products
$related = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE category = :category AND id != :id AND available = 1 LIMIT 4");
    $stmt->execute(['category' => $product['category'], 'id' => $productId]);
    $related = $stmt->fetchAll();
} catch (PDOException $e) {}

// ── Discount pricing (MRP vs selling price) ──────────────────────
$mrpPrice = (float)($product['mrp_price'] ?? 0);
$hasDiscount = $mrpPrice > (float)$product['price'];
$discountPercent = $hasDiscount ? (int)round((($mrpPrice - (float)$product['price']) / $mrpPrice) * 100) : 0;

// ── Colour variants (Biba-style image colour selector) ──────────
// Purely additive: products with no rows here fall straight through
// to the existing plain size selector below, unchanged.
$productColors = [];
try {
    $cStmt = $pdo->prepare("SELECT * FROM product_colors WHERE product_id = :id AND is_active = 1 ORDER BY sort_order ASC, id ASC");
    $cStmt->execute(['id' => $productId]);
    $productColors = $cStmt->fetchAll();
    foreach ($productColors as &$col) {
        $imgStmt = $pdo->prepare("SELECT * FROM product_color_images WHERE color_id = :cid ORDER BY sort_order ASC, id ASC");
        $imgStmt->execute(['cid' => $col['id']]);
        $col['images'] = $imgStmt->fetchAll();

        $szStmt = $pdo->prepare("SELECT * FROM product_variants WHERE color_id = :cid AND available = 1 ORDER BY sort_order ASC, id ASC");
        $szStmt->execute(['cid' => $col['id']]);
        $col['sizes'] = $szStmt->fetchAll();

        $col['effective_price'] = $col['price_override'] !== null ? (float)$col['price_override'] : (float)$product['price'];
        $col['effective_mrp'] = $col['mrp_price_override'] !== null ? (float)$col['mrp_price_override'] : $mrpPrice;
    }
    unset($col);
    // Only ever show colours that actually have at least one configured size
    $productColors = array_values(array_filter($productColors, fn($c) => !empty($c['sizes'])));
} catch (PDOException $e) {}

$sizeLadder = require __DIR__ . '/../config/size_ladder.php';

// ── Size guide: product-specific override wins over its category default ──
$sizeGuideChart   = null;
$sizeGuideBody    = [];
$sizeGuideGarment = [];
try {
    $sgStmt = $pdo->prepare("SELECT * FROM size_guide_charts WHERE product_id = :pid");
    $sgStmt->execute(['pid' => $productId]);
    $sizeGuideChart = $sgStmt->fetch();

    if (!$sizeGuideChart) {
        $catStmt = $pdo->prepare("SELECT id FROM categories WHERE name = :name LIMIT 1");
        $catStmt->execute(['name' => $product['category']]);
        $catId = $catStmt->fetchColumn();
        if ($catId) {
            $sgStmt2 = $pdo->prepare("SELECT * FROM size_guide_charts WHERE category_id = :cid AND product_id IS NULL");
            $sgStmt2->execute(['cid' => $catId]);
            $sizeGuideChart = $sgStmt2->fetch();
        }
    }

    if ($sizeGuideChart) {
        $contentStmt = $pdo->prepare("SELECT * FROM size_guide_content WHERE chart_id = :id ORDER BY sort_order ASC, id ASC");
        $contentStmt->execute(['id' => $sizeGuideChart['id']]);
        foreach ($contentStmt->fetchAll() as $r) {
            if ($r['measurement_type'] === 'garment') { $sizeGuideGarment[] = $r; }
            else { $sizeGuideBody[] = $r; }
        }
    }
} catch (PDOException $e) {}

// A chart with no shoulder/bust instructions is a lower-body-only garment (e.g. Bottoms) —
// swap in the leg/waist/hip diagram instead of the full-figure one.
$sgIsLowerBodyOnly = $sizeGuideChart
    && trim($sizeGuideChart['instructions_shoulder'] ?? '') === ''
    && trim($sizeGuideChart['instructions_bust'] ?? '') === '';

// Which measurement overlay lines are relevant for this chart (drives both the
// fallback SVG variant above and the real-photo overlay below).
$sgShowShoulder = $sizeGuideChart && trim($sizeGuideChart['instructions_shoulder'] ?? '') !== '';
$sgShowBust     = $sizeGuideChart && trim($sizeGuideChart['instructions_bust'] ?? '') !== '';
$sgShowWaist    = $sizeGuideChart && trim($sizeGuideChart['instructions_waist'] ?? '') !== '';
$sgShowHips     = $sizeGuideChart && trim($sizeGuideChart['instructions_hips'] ?? '') !== '';
$sgShowLength   = $sizeGuideChart && trim($sizeGuideChart['instructions_length'] ?? '') !== '';

// ── Active promotional coupons (pulled from the real promo_codes table) ──
$activeCoupons = [];
try {
    $pcStmt = $pdo->prepare("SELECT * FROM promo_codes WHERE active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE()) ORDER BY discount_value DESC LIMIT 3");
    $pcStmt->execute();
    $activeCoupons = $pcStmt->fetchAll();
} catch (PDOException $e) {}

// ── Estimated delivery date (next N business days, skipping weekends) ──
function dievonAddBusinessDays($days, $fromTs = null) {
    $ts = $fromTs ?: time();
    $added = 0;
    while ($added < $days) {
        $ts = strtotime('+1 day', $ts);
        if ((int)date('N', $ts) < 6) { $added++; }
    }
    return $ts;
}
$estimatedDeliveryDate = date('l, jS F', dievonAddBusinessDays(5));

// ── Dynamic product specifications (general + per-component) ──────
// Purely additive: products with none of these rows simply show none of
// these sections — every existing product keeps working unchanged.
$generalSpecs = [];
try {
    $gsStmt = $pdo->prepare("SELECT * FROM product_specifications WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $gsStmt->execute(['pid' => $productId]);
    $generalSpecs = $gsStmt->fetchAll();
} catch (PDOException $e) {}

$productComponents = [];
try {
    $compStmt = $pdo->prepare("SELECT * FROM product_components WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $compStmt->execute(['pid' => $productId]);
    $productComponents = $compStmt->fetchAll();
    foreach ($productComponents as &$comp) {
        $csStmt = $pdo->prepare("SELECT * FROM product_component_specifications WHERE component_id = :cid AND product_id = :pid ORDER BY sort_order ASC, id ASC");
        $csStmt->execute(['cid' => $comp['id'], 'pid' => $productId]);
        $comp['specs'] = $csStmt->fetchAll();
    }
    unset($comp);
    // Hide components that ended up with no specifications
    $productComponents = array_values(array_filter($productComponents, fn($c) => !empty($c['specs'])));
} catch (PDOException $e) {}

$pageTitle = !empty($product['meta_title']) ? $product['meta_title'] : $product['name'];
$metaDescription = !empty($product['meta_description']) ? $product['meta_description'] : (substr(strip_tags($product['description']), 0, 155) . '...');
if (!empty($product['image'])) {
    $ogImage = SITE_URL . "/uploads/products/" . $product['image'];
}
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Google Rich Snippet JSON-LD Schema ════════════════════════════ -->
<script type="application/ld+json">
{
  "@context": "https://schema.org/",
  "@type": "Product",
  "name": <?= json_encode($product['name']) ?>,
  "image": <?= json_encode(!empty($product['image']) ? SITE_URL . "/uploads/products/" . $product['image'] : "") ?>,
  "description": <?= json_encode($metaDescription) ?>,
  "sku": <?= json_encode(!empty($product['sku']) ? $product['sku'] : "DV-" . $product['id']) ?>,
  "gtin13": <?= json_encode(!empty($product['barcode']) ? $product['barcode'] : null) ?>,
  "brand": {
    "@type": "Brand",
    "name": <?= json_encode(!empty($product['brand']) ? $product['brand'] : "Dievon") ?>
  },
  <?php if (!empty($productColors)): ?>
  "color": <?= json_encode(array_values(array_map(fn($c) => $c['color_name'], $productColors))) ?>,
  <?php endif; ?>
  <?php if (empty($productColors) && !empty($variants)): ?>
  "additionalProperty": [
    {
      "@type": "PropertyValue",
      "name": "Available Sizes",
      "value": <?= json_encode(implode(', ', array_map(fn($v) => str_replace('Size: ', '', $v['name']), $variants))) ?>
    }
  ],
  <?php endif; ?>
  "offers": {
    "@type": "Offer",
    "url": <?= json_encode($canonicalUrl) ?>,
    "priceCurrency": "INR",
    "price": <?= json_encode(number_format((float)$product['price'], 2, '.', '')) ?>,
    "availability": <?= json_encode(($product['available'] == 1) ? "https://schema.org/InStock" : "https://schema.org/OutOfStock") ?>,
    "itemCondition": "https://schema.org/NewCondition",
    "hasMerchantReturnPolicy": {
      "@type": "MerchantReturnPolicy",
      "applicableCountry": "IN",
      "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
      "merchantReturnDays": 14,
      "returnMethod": "https://schema.org/ReturnByMail",
      "returnFees": "https://schema.org/FreeReturn"
    }
  }<?php if ($revCount > 0): ?>,
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": <?= json_encode((string)$avgRating) ?>,
    "reviewCount": <?= json_encode((string)$revCount) ?>
  }
  <?php endif; ?>
}
</script>

<!-- ══ Breadcrumb Structured Data ══════════════════════════ -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": <?= json_encode(SITE_URL . '/') ?> },
    { "@type": "ListItem", "position": 2, "name": <?= json_encode($product['category']) ?>, "item": <?= json_encode(SITE_URL . '/shop?category=' . urlencode($product['category'])) ?> },
    { "@type": "ListItem", "position": 3, "name": <?= json_encode($product['name']) ?>, "item": <?= json_encode($canonicalUrl) ?> }
  ]
}
</script>

<!-- ══ Product Hero ════════════════════════════ -->
<?php if (!empty($product['image'])): ?>
<section class="luxury-hero has-bg-image section-mb-sm" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow"><?= htmlspecialchars($product['category']) ?></span>
        <h1><?= htmlspecialchars($product['name']) ?></h1>
    </div>
</section>
<?php endif; ?>

<!-- ══ Product Detail Showcase ════════════════════════════ -->
<section class="product-showcase-section section-space">
    <div class="container product-detail-container">

        
        <div class="product-layout">
            
            <!-- Left Column: Image Grid -->
            <div class="product-image-section" id="productImageSection">
                <div class="sticky-sentinel" aria-hidden="true"></div>
                <div class="product-gallery-grid" id="productGalleryGrid">
                    <!-- Main Product Image -->
                    <?php if (!empty($product['image'])): ?>
                    <div class="gallery-grid-item" onclick="openProductLightbox('<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>')">
                        <?php if (!empty($product['badge'])): ?>
                            <span class="badge-luxury badge-product-page"><?= htmlspecialchars($product['badge']) ?></span>
                        <?php endif; ?>
                        <?php $mainWebp = webpUrlIfExists('products', $product['image']); ?>
                        <picture>
                            <?php if ($mainWebp): ?><source srcset="<?= htmlspecialchars($mainWebp) ?>" type="image/webp"><?php endif; ?>
                            <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?> - Main" class="gallery-grid-img">
                        </picture>
                    </div>
                    <?php endif; ?>

                    <!-- Additional Images -->
                    <?php foreach ($additionalImages as $index => $img): ?>
                    <div class="gallery-grid-item" onclick="openProductLightbox('<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($img['image']) ?>')">
                        <?php $addlWebp = webpUrlIfExists('products', $img['image']); ?>
                        <picture>
                            <?php if ($addlWebp): ?><source srcset="<?= htmlspecialchars($addlWebp) ?>" type="image/webp"><?php endif; ?>
                            <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($img['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?> - Angle <?= $index + 2 ?>" class="gallery-grid-img">
                        </picture>
                    </div>
                    <?php endforeach; ?>

                    <!-- Video (if any) -->
                    <?php if ($hasVideo): ?>
                    <div class="gallery-grid-item video-grid-item">
                        <?php 
                        $vUrl = trim($product['video_url']);
                        if (strpos($vUrl, 'youtube.com') !== false || strpos($vUrl, 'youtu.be') !== false):
                            preg_match('/(?:v=|\/)([a-zA-Z0-9_-]{11})/', $vUrl, $m);
                            $ytId = $m[1] ?? '';
                        ?>
                            <iframe class="gallery-grid-video" src="https://www.youtube.com/embed/<?= $ytId ?>?autoplay=0&mute=1&loop=1&playlist=<?= $ytId ?>" frameborder="0" allow="encrypted-media" allowfullscreen></iframe>
                        <?php else: ?>
                            <?php $videoSrc = (strpos($vUrl, 'http://') === 0 || strpos($vUrl, 'https://') === 0) ? $vUrl : SITE_URL . '/uploads/products/' . $vUrl; ?>
                            <video class="gallery-grid-video" src="<?= htmlspecialchars($videoSrc) ?>" autoplay loop muted playsinline controls></video>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column: Details & Actions -->
            <div class="product-details-col">
                <div class="product-buy-box">
                <!-- Breadcrumbs -->
                <nav class="product-breadcrumbs">
                    <a href="home">Dievon</a> &nbsp;&bull;&nbsp;
                    <a href="shop?category=<?= urlencode($product['category']) ?>"><?= htmlspecialchars($product['category']) ?></a> &nbsp;&bull;&nbsp;
                    <span><?= htmlspecialchars($product['name']) ?></span>
                </nav>

                <?php if (empty($product['image'])): ?>
                <h1 class="product-title-heading">
                    <?= htmlspecialchars($product['name']) ?>
                </h1>
                <?php endif; ?>

                <div class="product-price-heading" id="productPriceDisplay">
                    <span class="price-current-amount" id="priceCurrentAmount"><?= formatPrice($product['price']) ?></span>
                    <span class="price-mrp-amount" id="priceMrpAmount" style="<?= $hasDiscount ? '' : 'display:none;' ?>"><?= formatPrice($mrpPrice) ?></span>
                    <span class="price-off-badge" id="priceOffBadge" style="<?= $hasDiscount ? '' : 'display:none;' ?>"><?= $discountPercent ?>% OFF</span>
                </div>

                <div class="product-description-text">
                    <?= nl2br(htmlspecialchars($product['description'])) ?>
                </div>

                <?php if (!empty($productColors)): ?>
                <!-- ══ Colour Selector (Biba-style image swatches) ══════════ -->
                <div class="product-colors-wrap">
                    <span class="variant-select-label color-select-label">
                        Colour: <strong id="selectedColorNameLabel"><?= htmlspecialchars($productColors[0]['color_name']) ?></strong>
                    </span>
                    <div class="color-swatch-row" id="colorSwatchRow" role="radiogroup" aria-label="Select colour">
                        <?php foreach ($productColors as $idx => $c):
                            $thumbSrc = '';
                            if (!empty($c['thumbnail'])) { $thumbSrc = SITE_URL . '/uploads/products/' . htmlspecialchars($c['thumbnail']); }
                            elseif (!empty($c['images'])) { $thumbSrc = SITE_URL . '/uploads/products/' . htmlspecialchars($c['images'][0]['image']); }
                        ?>
                        <button type="button"
                                class="color-swatch-btn <?= $idx === 0 ? 'color-swatch-selected' : '' ?>"
                                data-color-id="<?= $c['id'] ?>"
                                onclick="selectProductColor(<?= $c['id'] ?>, this)"
                                role="radio"
                                aria-checked="<?= $idx === 0 ? 'true' : 'false' ?>"
                                aria-label="Colour: <?= htmlspecialchars($c['color_name'], ENT_QUOTES) ?>">
                            <span class="color-swatch-ring">
                                <?php if ($thumbSrc): ?>
                                <img src="<?= $thumbSrc ?>" alt="<?= htmlspecialchars($product['name']) ?> - <?= htmlspecialchars($c['color_name']) ?>">
                                <?php else: ?>
                                <span class="color-swatch-fallback-emoji"><?= htmlspecialchars($product['emoji'] ?? '👗') ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="color-swatch-name"><?= htmlspecialchars($c['color_name']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ══ Size Selector — Biba-style numeric/letter buttons, scoped to selected colour ══ -->
                <div class="product-variants-wrap" id="colorSizeSelectorWrap">
                    <div class="size-select-header-row">
                        <span class="variant-select-label">Select Size *</span>
                        <button type="button" onclick="openSizeGuideModal()" class="size-guide-trigger-btn">
                            📏 SIZE GUIDE
                        </button>
                    </div>
                    <div class="variant-pills-grid size-ladder-grid" id="sizeLadderGrid"></div>
                    <p id="sizeSelectValidationMsg" class="size-select-validation-msg" style="display:none;"></p>
                </div>
                <?php elseif (!empty($variants)): ?>
                <div class="product-variants-wrap">
                    <div class="size-select-header-row">
                        <span class="variant-select-label">Select Size / Option *</span>
                        <button type="button" onclick="openSizeGuideModal()" class="size-guide-trigger-btn">
                            📏 SIZE GUIDE
                        </button>
                    </div>
                    <div class="variant-pills-grid">
                        <?php foreach ($variants as $v): ?>
                            <button type="button"
                                    class="variant-pill-btn"
                                    onclick="selectProductVariant(<?= $v['id'] ?>, '<?= addslashes($v['name']) ?>', <?= $v['price'] ?>, this)">
                                <?= htmlspecialchars($v['name']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="product-variants-wrap">
                    <div class="size-select-header-row">
                        <span class="variant-select-label">Select Size *</span>
                        <button type="button" onclick="openSizeGuideModal()" class="size-guide-trigger-btn">
                            📏 SIZE GUIDE
                        </button>
                    </div>
                    <div class="variant-pills-grid">
                        <?php foreach (['S', 'M', 'L', 'XL', 'XXL'] as $sz): ?>
                            <button type="button"
                                    class="variant-pill-btn"
                                    onclick="selectProductSize('<?= $sz ?>', this)">
                                <?= $sz ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Express Delivery & Return Guarantee Badges -->
                <div class="product-trust-badges">
                    <div class="trust-badge-item">
                        <i class="fa-solid fa-truck-fast"></i> <strong>Dispatched in 24 Hours</strong>
                    </div>
                    <div class="trust-badge-item trust-badge-return">
                        <i class="fa-solid fa-rotate-left"></i> <strong>14-Day Return Eligible</strong>
                    </div>
                </div>

                <!-- In-Bag Status Banner (Visible if item is in cart) -->
                <div id="inBagStatusBanner" class="in-bag-status-banner" style="display: none;">
                    <!-- Populated dynamically via JS -->
                </div>

                <!-- Quantity Stepper + Wishlist -->
                <div class="product-quantity-row">
                    <div class="qty-controls product-qty-controls">
                        <button type="button" onclick="changeProductQuantity(-1)" class="qty-btn" aria-label="Decrease quantity">−</button>
                        <span id="selectedQtyDisplay" class="qty-value">1</span>
                        <button type="button" onclick="changeProductQuantity(1)" class="qty-btn" aria-label="Increase quantity">+</button>
                    </div>

                    <button onclick="handleWishlistToggleClick()" class="btn-wishlist-action" aria-label="Add to Wishlist">
                        <i class="fa-regular fa-heart" id="detailWishlistIcon"></i>
                    </button>
                </div>

                <!-- Action Buttons: Add to Bag & Buy Now / Checkout / Product Enquiry -->
                <div class="product-action-buttons-group">
                    <button onclick="handleAddToCartClick()" class="btn-luxury btn-add-bag">
                        <i class="fa-solid fa-bag-shopping"></i> Add to Bag
                    </button>

                    <button onclick="handleBuyNowClick()" class="btn-luxury-outline btn-buy-now">
                        Buy Now
                    </button>

                    <button onclick="document.getElementById('productEnquiryModal').style.display='flex'" class="btn-luxury-outline" style="padding: 12px 18px; font-size: 13px;" title="Inquire About Fit / Customization">
                        <i class="fa-regular fa-envelope"></i> Fitting Enquiry
                    </button>
                </div>
                </div>

                <!-- Compact Purchase Benefits -->
                <div class="product-benefits-row">
                    <div class="benefit-item">
                        <i class="fa-solid fa-truck-fast"></i>
                        <span>Free Shipping<br><small>On orders over £150</small></span>
                    </div>
                    <div class="benefit-item">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span>14-Day Easy<br>Return/Exchange</span>
                    </div>
                    <div class="benefit-item">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Assured<br>Quality</span>
                    </div>
                    <div class="benefit-item">
                        <i class="fa-solid fa-money-bill-wave"></i>
                        <span>COD<br>Available</span>
                    </div>
                </div>

                <!-- COD Availability Checker -->
                <div class="delivery-checker-box">
                    <div class="delivery-estimate-row">
                        <i class="fa-solid fa-truck-fast"></i>
                        <span>Standard delivery by <strong><?= htmlspecialchars($estimatedDeliveryDate) ?></strong></span>
                    </div>
                    <span class="delivery-checker-label">Check Exact Delivery Date / COD Availability:</span>
                    <div class="delivery-checker-input-wrap">
                        <input type="text" id="zipCode" placeholder="Enter Zip / Postal Code">
                        <button onclick="checkDelivery()" class="btn-luxury-outline">Check</button>
                    </div>
                    <div id="deliveryStatus" class="delivery-status-msg"></div>
                </div>

                <!-- Shop with Confidence -->
                <div class="shop-confidence-box">
                    <h4 class="shop-confidence-title">Shop with Confidence</h4>
                    <ul class="shop-confidence-list">
                        <li><i class="fa-solid fa-lock"></i> Secure Checkout &amp; Data Privacy</li>
                        <li><i class="fa-solid fa-user-tie"></i> Styled by Our In-House Team</li>
                        <li><i class="fa-solid fa-magnifying-glass"></i> Quality-Checked Before Dispatch</li>
                        <li><i class="fa-solid fa-headset"></i> Dedicated Post-Purchase Support</li>
                    </ul>
                </div>


                <!-- Product Detail Accordions -->
                <div class="product-accordions">

                    <?php if (!empty($activeCoupons)): ?>
                    <div class="product-accordion">
                        <div class="product-accordion-header" onclick="toggleProductAccordion(this)">
                            Offers &amp; Coupons <i class="fa-solid fa-plus toggle-icon"></i>
                        </div>
                        <div class="product-accordion-content">
                            <div class="coupon-list">
                                <?php foreach ($activeCoupons as $c):
                                    $discountLabel = $c['discount_type'] === 'percentage'
                                        ? (rtrim(rtrim(number_format((float)$c['discount_value'], 1), '0'), '.') . '% OFF')
                                        : (formatPrice($c['discount_value']) . ' OFF');
                                    $minOrderNote = (float)$c['min_order'] > 0 ? ' on orders above ' . formatPrice($c['min_order']) : '';
                                ?>
                                <div class="coupon-row">
                                    <div class="coupon-code-chip">
                                        <span class="coupon-code-text"><?= htmlspecialchars($c['code']) ?></span>
                                        <button type="button" class="coupon-copy-btn" onclick="copyCouponCode('<?= addslashes($c['code']) ?>', this)" title="Copy code"><i class="fa-regular fa-copy"></i></button>
                                    </div>
                                    <div class="coupon-desc">
                                        <strong><?= htmlspecialchars($discountLabel) ?></strong><?= htmlspecialchars($minOrderNote) ?>
                                        <?php if (!empty($c['description'])): ?><div class="coupon-desc-sub"><?= htmlspecialchars($c['description']) ?></div><?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($generalSpecs) || !empty($productComponents)): ?>
                    <div class="product-accordion">
                        <div class="product-accordion-header" role="button" tabindex="0" aria-expanded="false" onclick="toggleProductAccordion(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleProductAccordion(this);}">
                            Product Details <i class="fa-solid fa-plus toggle-icon"></i>
                        </div>
                        <div class="product-accordion-content">
                            <?php if (!empty($productComponents)): ?>
                            <div class="product-details-tabs" role="tablist" aria-label="Product details by component">
                                <button type="button" class="product-details-tab-btn active" role="tab" aria-selected="true" onclick="switchProductDetailsTab(this, 'about')">About</button>
                                <?php foreach ($productComponents as $comp): ?>
                                <button type="button" class="product-details-tab-btn" role="tab" aria-selected="false" onclick="switchProductDetailsTab(this, 'comp-<?= $comp['id'] ?>')"><?= htmlspecialchars($comp['name']) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <div class="product-details-tab-panel" data-panel="about">
                                <div class="specifications-grid">
                                    <?php if (!empty($productComponents)): ?>
                                    <div><strong>No. of Components:</strong> <?= count($productComponents) ?></div>
                                    <div><strong>Components:</strong> <?= htmlspecialchars(implode(', ', array_map(fn($c) => $c['name'], $productComponents))) ?></div>
                                    <?php endif; ?>
                                    <?php foreach ($generalSpecs as $spec): ?>
                                    <div><strong><?= htmlspecialchars($spec['label']) ?>:</strong> <?= htmlspecialchars($spec['value']) ?><?= !empty($spec['unit']) ? ' ' . htmlspecialchars($spec['unit']) : '' ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php foreach ($productComponents as $comp): ?>
                            <div class="product-details-tab-panel" data-panel="comp-<?= $comp['id'] ?>" style="display:none;">
                                <div class="specifications-grid">
                                    <?php foreach ($comp['specs'] as $spec): ?>
                                    <div><strong><?= htmlspecialchars($spec['label']) ?>:</strong> <?= htmlspecialchars($spec['value']) ?><?= !empty($spec['unit']) ? ' ' . htmlspecialchars($spec['unit']) : '' ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="product-accordion">
                        <div class="product-accordion-header" onclick="toggleProductAccordion(this)">
                            Specifications <i class="fa-solid fa-plus toggle-icon"></i>
                        </div>
                        <div class="product-accordion-content">
                            <div class="specifications-grid">
                                <div><strong>Atelier Code:</strong> <?= htmlspecialchars(!empty($product['atelier_code']) ? $product['atelier_code'] : 'MD-' . str_pad($product['id'], 4, '0', STR_PAD_LEFT)) ?></div>
                                <div><strong>Color:</strong> <?= htmlspecialchars(!empty($product['color']) ? $product['color'] : (!empty($product['color_way']) ? $product['color_way'] : 'Editorial')) ?></div>
                                <div><strong>Brand:</strong> <?= htmlspecialchars(!empty($product['brand']) ? $product['brand'] : 'Dievon In-House') ?></div>
                                <div><strong>Fabric:</strong> <?= htmlspecialchars(!empty($product['fabric']) ? $product['fabric'] : '100% Premium Material') ?></div>
                                <div><strong>Sleeve:</strong> <?= htmlspecialchars(!empty($product['sleeve']) ? $product['sleeve'] : 'Standard') ?></div>
                                <div><strong>Neck:</strong> <?= htmlspecialchars(!empty($product['neck']) ? $product['neck'] : 'Standard') ?></div>
                                <div><strong>Pattern:</strong> <?= htmlspecialchars(!empty($product['pattern']) ? $product['pattern'] : 'Solid') ?></div>
                                <div><strong>Occasion:</strong> <?= htmlspecialchars(!empty($product['occasion']) ? $product['occasion'] : 'Versatile') ?></div>
                            </div>

                        </div>
                    </div>

                    <div class="product-accordion">
                        <div class="product-accordion-header" onclick="toggleProductAccordion(this)">
                            Fabric & Care <i class="fa-solid fa-plus toggle-icon"></i>
                        </div>
                        <div class="product-accordion-content">
                            <p>We recommend professional dry clean only to preserve the drape and structural integrity of the fabric. Store in a cool, dry place inside a breathable cotton garment bag.</p>
                            <?php if (!empty($product['composition'])): ?>
                                <p style="margin-top: 10px;"><strong>Composition:</strong> <?= htmlspecialchars($product['composition']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($product['sourcing'])): ?>
                                <p style="margin-top: 10px;"><strong>Sourcing:</strong> <?= htmlspecialchars($product['sourcing']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="product-accordion">
                        <div class="product-accordion-header" onclick="toggleProductAccordion(this)">
                            Shipping & Returns <i class="fa-solid fa-plus toggle-icon"></i>
                        </div>
                        <div class="product-accordion-content">
                            <p>Dievon provides insured tracked courier delivery on all orders. Express dispatch within 24-48 business hours. Returns and size exchanges are accepted within 14 days of delivery in original, unworn condition with tags attached.</p>
                        </div>
                    </div>

                    <!-- Product Specifications & Identifiers -->
                    <div class="product-accordion">
                        <div class="product-accordion-header" onclick="toggleProductAccordion(this)">
                            Specifications &amp; Logistics <i class="fa-solid fa-plus toggle-icon"></i>
                        </div>
                        <div class="product-accordion-content">
                            <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: var(--text-secondary); line-height: 1.8;">
                                <li><strong>SKU:</strong> <?= htmlspecialchars(!empty($product['sku']) ? $product['sku'] : 'DV-'.$product['id']) ?></li>
                                <?php if (!empty($product['barcode'])): ?>
                                    <li><strong>Barcode / EAN:</strong> <?= htmlspecialchars($product['barcode']) ?></li>
                                <?php endif; ?>
                                <?php if (!empty($product['weight'])): ?>
                                    <li><strong>Package Weight:</strong> <?= htmlspecialchars($product['weight']) ?></li>
                                <?php endif; ?>
                                <?php if (!empty($product['dimensions'])): ?>
                                    <li><strong>Dimensions (L×W×H):</strong> <?= htmlspecialchars($product['dimensions']) ?></li>
                                <?php endif; ?>
                                <?php if (!empty($product['tags'])): ?>
                                    <li><strong>Tags:</strong> <?= htmlspecialchars($product['tags']) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <?php if (!empty($product['video_url'])): ?>
                    <div class="product-accordion">
                        <div class="product-accordion-header" onclick="toggleProductAccordion(this)">
                            Campaign Video <i class="fa-solid fa-plus toggle-icon"></i>
                        </div>
                        <div class="product-accordion-content">
                            <video style="width: 100%; max-height: 400px; object-fit: cover; border: 1px solid var(--border-light);" controls>
                                <source src="<?= htmlspecialchars($product['video_url']) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>
</section>

<!-- ══ Ratings & Reviews ════════════════════════════ -->
<section class="product-reviews-section section-space">
    <div class="container product-reviews-container">

        <div class="product-section-header-row">
            <div>
                <h2>Customer Reviews</h2>
                <div class="product-rating-row" style="margin-top: 5px;">
                    <div class="stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fa-<?= $i <= round($avgRating) ? 'solid' : 'regular' ?> fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <span><?= $revCount > 0 ? "{$avgRating} / 5 ({$revCount} " . ($revCount === 1 ? "Review" : "Reviews") . ")" : "No customer reviews yet." ?></span>
                </div>
            </div>
            <button class="btn-luxury-outline btn-write-review" onclick="openFadeModal('writeReviewModal')">Write a Review</button>
        </div>

        <div class="reviews-list-grid">
            <?php if ($revCount > 0): ?>
                <?php foreach ($dbReviews as $r): ?>
                <div class="review-card-item">
                    <div class="review-card-header">
                        <div>
                            <strong class="review-buyer-name"><?= htmlspecialchars($r['author_name'] ?? $r['customer_name'] ?? 'Guest Customer') ?></strong>
                            <?php if (!empty($r['verified_buyer']) || !empty($r['is_verified'])): ?>
                                <span class="review-verified-tag"><i class="fa-solid fa-circle-check"></i> Verified Buyer</span>
                            <?php endif; ?>
                        </div>
                        <div class="review-date"><?= date('F j, Y', strtotime($r['created_at'] ?? 'now')) ?></div>
                    </div>
                    <div class="review-card-stars">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <i class="fa-<?= $s <= (int)$r['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <h4 class="review-card-title"><?= htmlspecialchars($r['title'] ?? 'Verified Customer Review') ?></h4>
                    <p class="review-card-text"><?= nl2br(htmlspecialchars($r['review_text'] ?? '')) ?></p>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:var(--text-muted); font-size:14px; text-align:center; padding:30px 0; grid-column: 1 / -1;">
                    <i class="fa-regular fa-comment-dots" style="font-size:32px; display:block; margin-bottom:12px; opacity:0.4;"></i>
                    There are no reviews for this garment yet. Be the first to submit a review!
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ══ Q&A ════════════════════════════ -->
<section class="qa-section section-space">
    <div class="container">
        <div class="product-section-header-row">
            <div>
                <h2>Questions & Answers</h2>
            </div>
            <button class="btn-luxury-outline" style="padding: 10px 24px; font-size: 12px; font-weight: 500;" onclick="openFadeModal('askQuestionModal')">Ask a Question</button>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 30px;">
            <div style="border-bottom: 1px solid var(--border-light); padding-bottom: 20px;">
                <div style="margin-bottom: 10px;">
                    <strong style="font-size: 14px; color: var(--text-primary);">Q: What sizing and fit options are available for this piece?</strong>
                    <span style="font-size: 12px; color: var(--text-muted); margin-left: 10px;">- Verified Customer</span>
                </div>
                <div style="display: flex; gap: 15px;">
                    <div style="font-weight: 600; font-size: 14px; color: var(--color-primary);">A:</div>
                    <div style="font-size: 13px; color: var(--text-secondary); line-height: 1.6;">
                        All Dievon garments follow standard Atelier ready-to-wear sizing (S, M, L, XL, XXL). For detailed bust, waist, and hip measurements, please click the 📐 Size Guide button on this page.
                        <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">By Dievon Concierge</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Write Review Modal -->
<div id="writeReviewModal" class="quick-view-modal" style="display:none;" onclick="closeFadeModal('writeReviewModal')">
    <div class="quick-view-content product-modal-form-content" onclick="event.stopPropagation()">
        <button class="quick-view-close" onclick="closeFadeModal('writeReviewModal')">&times;</button>
        <h2 class="product-modal-form-title">Write a Review</h2>
        <div id="writeReviewMsg" class="product-modal-form-msg" style="display:none;"></div>
        <form id="writeReviewForm" onsubmit="submitProductReview(event, <?= (int)$productId ?>)">
            <div class="form-luxury-group">
                <label>Rating</label>
                <select name="rating" class="form-luxury-input" required>
                    <option value="5">5 Stars - Excellent</option>
                    <option value="4">4 Stars - Good</option>
                    <option value="3">3 Stars - Average</option>
                    <option value="2">2 Stars - Poor</option>
                    <option value="1">1 Star - Terrible</option>
                </select>
            </div>
            <div class="form-luxury-group">
                <label>Review Title</label>
                <input type="text" name="title" class="form-luxury-input" required>
            </div>
            <div class="form-luxury-group">
                <label>Your Review</label>
                <textarea name="review_text" class="form-luxury-input form-luxury-textarea-tall" required></textarea>
            </div>
            <button type="submit" class="btn-luxury" style="width: 100%; justify-content: center; height: 50px;">Submit Review</button>
        </form>
    </div>
</div>

<!-- Ask Question Modal -->
<div id="askQuestionModal" class="quick-view-modal" style="display:none;" onclick="closeFadeModal('askQuestionModal')">
    <div class="quick-view-content product-modal-form-content" onclick="event.stopPropagation()">
        <button class="quick-view-close" onclick="closeFadeModal('askQuestionModal')">&times;</button>
        <h2 class="product-modal-form-title">Ask a Question</h2>
        <form onsubmit="event.preventDefault(); alert('Question submitted!'); closeFadeModal('askQuestionModal');">
            <div class="form-luxury-group">
                <label>Your Question</label>
                <textarea class="form-luxury-input form-luxury-textarea-tall" required></textarea>
            </div>
            <button type="submit" class="btn-luxury" style="width: 100%; justify-content: center; height: 50px;">Submit Question</button>
        </form>
    </div>
</div>

<!-- ══ Product Lightbox Zoom Modal ════════════════════════════ -->
<div id="productLightboxModal" class="product-lightbox-modal" onclick="closeProductLightbox(event)">
    <button type="button" class="product-lightbox-close" onclick="closeProductLightbox(event)">&times;</button>
    
    <div class="product-lightbox-container-wrapper">
        <!-- Vertical Thumbnails List on Left -->
        <div class="product-lightbox-thumbnails" onclick="event.stopPropagation()">
            <!-- Populated dynamically via JS -->
        </div>
        
        <!-- Main Image Area in Center -->
        <div class="product-lightbox-main-area" onclick="event.stopPropagation()">
            <button type="button" class="product-lightbox-nav prev" onclick="navProductLightbox(-1, event)"><i class="fa-solid fa-chevron-left"></i></button>
            <div class="product-lightbox-content">
                <img id="productLightboxImg" src="" alt="<?= htmlspecialchars($product['name']) ?> - Full view">
            </div>
            <button type="button" class="product-lightbox-nav next" onclick="navProductLightbox(1, event)"><i class="fa-solid fa-chevron-right"></i></button>
            <div id="productLightboxCounter" class="product-lightbox-counter">1 / 1</div>
        </div>
    </div>
</div>

<script>
let galleryImagesList = [
    <?php if (!empty($product['image'])): ?>
    '<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>',
    <?php endif; ?>
    <?php foreach ($additionalImages as $img): ?>
    '<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($img['image']) ?>',
    <?php endforeach; ?>
];
let currentGalleryIndex = 0;

function openProductLightbox(src) {
    if (!src) {
        if (galleryImagesList.length > 0) {
            src = galleryImagesList[0];
        } else {
            return;
        }
    }
    
    const modal = document.getElementById('productLightboxModal');
    const lbImg = document.getElementById('productLightboxImg');
    const counter = document.getElementById('productLightboxCounter');
    
    if (modal && lbImg) {
        lbImg.src = src;
        modal.style.display = 'flex';
        requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add('is-visible')));
        document.body.style.overflow = 'hidden';

        const idx = galleryImagesList.indexOf(src);
        if (idx !== -1) currentGalleryIndex = idx;

        if (counter && galleryImagesList.length > 0) {
            counter.textContent = (currentGalleryIndex + 1) + ' / ' + galleryImagesList.length;
        }

        renderLightboxThumbnails();
        lbResetZoom();
    }
}

function renderLightboxThumbnails() {
    const thumbsContainer = document.querySelector('.product-lightbox-thumbnails');
    if (thumbsContainer) {
        thumbsContainer.innerHTML = galleryImagesList.map((imgSrc, idx) => `
            <div class="product-lightbox-thumb-item ${idx === currentGalleryIndex ? 'active' : ''}" onclick="selectLightboxImage(${idx})">
                <img src="${imgSrc}" alt="${escHtml(DIEVON_PRODUCT_NAME)} - Thumbnail ${idx + 1}">
            </div>
        `).join('');
    }
}

function selectLightboxImage(idx) {
    if (idx < 0 || idx >= galleryImagesList.length) return;
    currentGalleryIndex = idx;

    const lbImg = document.getElementById('productLightboxImg');
    const counter = document.getElementById('productLightboxCounter');

    if (lbImg) {
        lbImg.classList.add('is-swapping');
        setTimeout(() => {
            lbImg.src = galleryImagesList[idx];
            lbImg.classList.remove('is-swapping');
        }, 120);
    }
    if (counter) counter.textContent = (idx + 1) + ' / ' + galleryImagesList.length;

    document.querySelectorAll('.product-lightbox-thumb-item').forEach((item, i) => {
        if (i === idx) {
            item.classList.add('active');
            item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            item.classList.remove('active');
        }
    });

    lbResetZoom();
}

function closeProductLightbox(e) {
    if (e) e.stopPropagation();
    const modal = document.getElementById('productLightboxModal');
    if (modal) {
        modal.classList.remove('is-visible');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
        document.body.style.overflow = '';
        lbResetZoom();
    }
}

function openFadeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'flex';
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('is-visible')));
}
function closeFadeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('is-visible');
    setTimeout(() => { el.style.display = 'none'; }, 300);
}

function navProductLightbox(dir, e) {
    if (e) e.stopPropagation();
    if (!galleryImagesList.length) return;

    const nextIdx = (currentGalleryIndex + dir + galleryImagesList.length) % galleryImagesList.length;
    selectLightboxImage(nextIdx);
}

// Keyboard arrows navigation for Lightbox
document.addEventListener('keydown', e => {
    const modal = document.getElementById('productLightboxModal');
    if (modal && modal.style.display === 'flex') {
        if (e.key === 'ArrowLeft') {
            navProductLightbox(-1);
        } else if (e.key === 'ArrowRight') {
            navProductLightbox(1);
        } else if (e.key === 'Escape') {
            closeProductLightbox();
        }
    }
});

// ── Lightbox zoom, pan & swipe (vanilla JS — no external library) ──────
(function () {
    const MIN_SCALE = 1;
    const MAX_SCALE = 4;
    const DBLTAP_MS = 300;
    const DBLTAP_DIST = 30;
    const SWIPE_THRESHOLD = 50;

    let scale = 1, panX = 0, panY = 0;
    let isPanning = false, panStartX = 0, panStartY = 0, panOriginX = 0, panOriginY = 0;
    let pinchStartDist = 0, pinchStartScale = 1;
    let touchStartX = 0, touchStartY = 0, touchMoved = false;
    let lastTapTime = 0, lastTapX = 0, lastTapY = 0;

    function getImg() { return document.getElementById('productLightboxImg'); }

    window.lbResetZoom = function () {
        scale = 1; panX = 0; panY = 0;
        const img = getImg();
        if (img) {
            img.style.transform = '';
            img.classList.remove('lb-zoomed', 'lb-panning');
        }
    };

    // Keep the pan inside the bounds of the zoomed image so it can never be
    // dragged fully off-screen with no way back.
    function clampPan() {
        const img = getImg();
        if (!img) return;
        const container = img.closest('.product-lightbox-main-area');
        if (!container) return;
        const cw = container.clientWidth, ch = container.clientHeight;
        const iw = img.offsetWidth * scale, ih = img.offsetHeight * scale;
        const maxX = Math.max(0, (iw - cw) / 2);
        const maxY = Math.max(0, (ih - ch) / 2);
        panX = Math.min(maxX, Math.max(-maxX, panX));
        panY = Math.min(maxY, Math.max(-maxY, panY));
    }

    function applyTransform() {
        const img = getImg();
        if (!img) return;
        clampPan();
        img.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
        img.classList.toggle('lb-zoomed', scale > 1);
    }

    function setZoom(newScale, originXRatio, originYRatio) {
        newScale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, newScale));
        if (newScale === 1) { panX = 0; panY = 0; }
        scale = newScale;
        applyTransform();
    }

    function toggleDblZoom(clientX, clientY) {
        const img = getImg();
        if (!img) return;
        if (scale > 1) {
            setZoom(1);
        } else {
            const rect = img.getBoundingClientRect();
            panX = 0; panY = 0;
            scale = 2.2;
            applyTransform();
            // Nudge the pan so the tapped point stays roughly under the cursor/finger
            const offsetX = (clientX - (rect.left + rect.width / 2));
            const offsetY = (clientY - (rect.top + rect.height / 2));
            panX = -offsetX * (scale - 1) / scale;
            panY = -offsetY * (scale - 1) / scale;
            applyTransform();
        }
    }

    function dist(t1, t2) {
        return Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const img = getImg();
        if (!img) return;

        // Desktop: double-click to zoom
        img.addEventListener('dblclick', function (e) {
            toggleDblZoom(e.clientX, e.clientY);
        });

        // Desktop: scroll wheel to zoom
        img.addEventListener('wheel', function (e) {
            e.preventDefault();
            setZoom(scale + (e.deltaY < 0 ? 0.25 : -0.25));
        }, { passive: false });

        // Desktop: drag to pan when zoomed
        img.addEventListener('mousedown', function (e) {
            if (scale <= 1) return;
            isPanning = true;
            panStartX = e.clientX; panStartY = e.clientY;
            panOriginX = panX; panOriginY = panY;
            img.classList.add('lb-panning');
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!isPanning) return;
            panX = panOriginX + (e.clientX - panStartX);
            panY = panOriginY + (e.clientY - panStartY);
            applyTransform();
        });
        document.addEventListener('mouseup', function () {
            if (isPanning) { isPanning = false; img.classList.remove('lb-panning'); }
        });

        // Touch: pinch-zoom, single-finger pan/swipe, double-tap zoom
        img.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                pinchStartDist = dist(e.touches[0], e.touches[1]);
                pinchStartScale = scale;
            } else if (e.touches.length === 1) {
                touchMoved = false;
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                if (scale > 1) {
                    isPanning = true;
                    panStartX = touchStartX; panStartY = touchStartY;
                    panOriginX = panX; panOriginY = panY;
                }
            }
        }, { passive: true });

        img.addEventListener('touchmove', function (e) {
            touchMoved = true;
            if (e.touches.length === 2 && pinchStartDist > 0) {
                e.preventDefault();
                const newDist = dist(e.touches[0], e.touches[1]);
                setZoom(pinchStartScale * (newDist / pinchStartDist));
            } else if (e.touches.length === 1 && isPanning && scale > 1) {
                e.preventDefault();
                panX = panOriginX + (e.touches[0].clientX - panStartX);
                panY = panOriginY + (e.touches[0].clientY - panStartY);
                applyTransform();
            }
        }, { passive: false });

        img.addEventListener('touchend', function (e) {
            isPanning = false;
            pinchStartDist = 0;

            if (e.changedTouches.length !== 1) return;
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;

            // Double-tap to zoom
            const now = Date.now();
            const tapDist = Math.hypot(endX - lastTapX, endY - lastTapY);
            if (!touchMoved && now - lastTapTime < DBLTAP_MS && tapDist < DBLTAP_DIST) {
                toggleDblZoom(endX, endY);
                lastTapTime = 0;
                return;
            }
            lastTapTime = now; lastTapX = endX; lastTapY = endY;

            // Swipe to navigate (only when not zoomed in)
            if (scale <= 1 && touchMoved) {
                const dx = endX - touchStartX;
                const dy = endY - touchStartY;
                if (Math.abs(dx) > SWIPE_THRESHOLD && Math.abs(dx) > Math.abs(dy)) {
                    navProductLightbox(dx < 0 ? 1 : -1);
                }
            }
        });
    });
})();
</script>

<!-- ══ Related Suggested Pieces ═══════════════════════════ -->
<?php if (!empty($related)): ?>
<section class="compact-suggested-section section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Dievon Curation</span>
            <h2 class="section-title">Suggested Pieces</h2>
        </div>
        <div class="related-compact-grid">
            <?php foreach ($related as $p):
                $img = !empty($p['image']) ? SITE_URL . '/uploads/products/' . htmlspecialchars($p['image']) : '';
            ?>
            <article class="compact-product-card reveal-on-scroll">
                <div class="compact-card-img-wrap">
                    <a href="<?= SITE_URL ?>/product?id=<?= $p['id'] ?>">
                        <img src="<?= $img ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
                    </a>
                </div>
                <div class="compact-card-details">
                    <span class="compact-card-cat"><?= htmlspecialchars($p['category']) ?></span>
                    <h3 class="compact-card-name"><a href="product.php?id=<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></a></h3>
                    <div class="compact-card-price"><?= formatPrice($p['price']) ?></div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ Recently Viewed ═══════════════════════════ -->
<section class="compact-suggested-section section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Your Journey</span>
            <h2 class="section-title">Recently Viewed</h2>
        </div>
        <div id="recentlyViewedContainer" class="related-compact-grid">
            <!-- Seeded dynamically via Javascript -->
        </div>
        <div id="recentlyViewedEmpty" class="recently-viewed-empty">
            No recently viewed items.
        </div>
    </div>
</section>



<script>
    // Selected variant info
    let hasVariants = <?= !empty($variants) ? 'true' : 'false' ?>;
    let selectedVariantId = 0;
    let selectedVariantName = '';
    let selectedVariantPrice = <?= (float)$product['price'] ?>;
    let selectedVariantMrp = <?= (float)$mrpPrice ?>;

    function copyCouponCode(code, btnEl) {
        const done = () => {
            const icon = btnEl.querySelector('i');
            if (icon) {
                icon.className = 'fa-solid fa-check';
                setTimeout(() => { icon.className = 'fa-regular fa-copy'; }, 1500);
            }
            showToast('Copied!', 'Coupon code ' + code + ' copied to clipboard.');
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(done).catch(() => {
                showToast('Notice', 'Could not copy automatically — code: ' + code);
            });
        } else {
            showToast('Notice', 'Coupon code: ' + code);
        }
    }

    // ── Colour + colour-scoped size data (Biba-style selectors) ──────
    const DIEVON_DEFAULT_IMAGES = galleryImagesList.slice();
    const DIEVON_COLORS = <?= json_encode(array_map(function($c) use ($product) {
        return [
            'id' => (int)$c['id'],
            'color_name' => $c['color_name'],
            'sku' => $c['sku'],
            'price' => $c['effective_price'],
            'mrp' => $c['effective_mrp'],
            'images' => array_map(fn($img) => SITE_URL . '/uploads/products/' . $img['image'], $c['images']),
            'sizes' => array_map(fn($s) => [
                'id' => (int)$s['id'], 'size_code' => $s['size_code'], 'label' => $s['name'], 'stock' => (int)$s['stock_qty'],
            ], $c['sizes']),
        ];
    }, $productColors)) ?>;
    const DIEVON_SIZE_LADDER = <?= json_encode(array_map(fn($s) => ['code' => $s['code'], 'label' => $s['label']], $sizeLadder)) ?>;
    const DIEVON_PRODUCT_EMOJI = <?= json_encode($product['emoji'] ?? '👗') ?>;
    const DIEVON_PRODUCT_BADGE = <?= json_encode($product['badge'] ?? '') ?>;
    const DIEVON_PRODUCT_NAME = <?= json_encode($product['name']) ?>;
    let selectedColorId = DIEVON_COLORS.length ? DIEVON_COLORS[0].id : 0;
    let selectedColorName = DIEVON_COLORS.length ? DIEVON_COLORS[0].color_name : '';

    function initColorSizeSelector() {
        if (!DIEVON_COLORS.length) return;
        selectedVariantPrice = DIEVON_COLORS[0].price;
        selectedVariantMrp = DIEVON_COLORS[0].mrp;
        updatePriceDisplay();
        renderSizeButtons(selectedColorId);
    }

    function findColor(colorId) {
        return DIEVON_COLORS.find(c => c.id === colorId);
    }

    function selectProductColor(colorId, btnEl) {
        const color = findColor(colorId);
        if (!color) return;

        selectedColorId = colorId;
        selectedColorName = color.color_name;

        document.querySelectorAll('.color-swatch-btn').forEach(b => {
            b.classList.remove('color-swatch-selected');
            b.setAttribute('aria-checked', 'false');
        });
        if (btnEl) { btnEl.classList.add('color-swatch-selected'); btnEl.setAttribute('aria-checked', 'true'); }

        const nameLabel = document.getElementById('selectedColorNameLabel');
        if (nameLabel) nameLabel.textContent = color.color_name;

        // Swap gallery grid to this colour's images, or fall back to default images
        const imgsToDisplay = (color.images && color.images.length) ? color.images : DIEVON_DEFAULT_IMAGES;
        if (imgsToDisplay.length) {
            let videoHtml = '';
            <?php if ($hasVideo): ?>
                videoHtml = `
                <div class="gallery-grid-item video-grid-item">
                    <?php 
                    $vUrl = trim($product['video_url']);
                    if (strpos($vUrl, 'youtube.com') !== false || strpos($vUrl, 'youtu.be') !== false):
                        preg_match('/(?:v=|\/)([a-zA-Z0-9_-]{11})/', $vUrl, $m);
                        $ytId = $m[1] ?? '';
                    ?>
                        <iframe class="gallery-grid-video" src="https://www.youtube.com/embed/<?= $ytId ?>?autoplay=0&mute=1&loop=1&playlist=<?= $ytId ?>" frameborder="0" allow="encrypted-media" allowfullscreen></iframe>
                    <?php else: ?>
                        <?php $videoSrc = (strpos($vUrl, 'http://') === 0 || strpos($vUrl, 'https://') === 0) ? $vUrl : SITE_URL . '/uploads/products/' . $vUrl; ?>
                        <video class="gallery-grid-video" src="<?= htmlspecialchars($videoSrc) ?>" autoplay loop muted playsinline controls></video>
                    <?php endif; ?>
                </div>`;
            <?php endif; ?>

            const grid = document.getElementById('productGalleryGrid');
            if (grid) {
                const badgeHtml = DIEVON_PRODUCT_BADGE ? `<span class="badge-luxury badge-product-page">${escHtml(DIEVON_PRODUCT_BADGE)}</span>` : '';
                grid.classList.add('is-swapping');
                setTimeout(() => {
                    grid.innerHTML = imgsToDisplay.map((src, i) => `
                        <div class="gallery-grid-item" onclick="openProductLightbox('${src}')">
                            ${i === 0 ? badgeHtml : ''}
                            <img src="${src}" alt="${escHtml(color.color_name)} - Image ${i + 1}" class="gallery-grid-img">
                        </div>`).join('') + videoHtml;
                    grid.classList.remove('is-swapping');
                }, 200);
            }

            // Keep the lightbox in sync
            galleryImagesList = imgsToDisplay.slice();
            currentGalleryIndex = 0;
        }

        // Reset size selection — sizes differ per colour
        selectedVariantId = 0;
        selectedVariantName = '';
        selectedVariantPrice = color.price;
        selectedVariantMrp = color.mrp;
        updatePriceDisplay();
        renderSizeButtons(colorId);

        const msg = document.getElementById('sizeSelectValidationMsg');
        if (msg) msg.style.display = 'none';
        const wrap = document.getElementById('colorSizeSelectorWrap');
        if (wrap) wrap.style.border = '';
    }

    function renderSizeButtons(colorId) {
        const grid = document.getElementById('sizeLadderGrid');
        if (!grid) return;
        const color = findColor(colorId);
        if (!color) { grid.innerHTML = ''; return; }

        let html = '';
        DIEVON_SIZE_LADDER.forEach(rung => {
            const size = color.sizes.find(s => s.size_code === rung.code);
            if (!size) return; // only show sizes actually configured for this colour
            const outOfStock = size.stock <= 0;
            const lowStock = size.stock > 0 && size.stock <= 5;
            html += `
                <button type="button" class="size-pill-btn ${outOfStock ? 'size-pill-disabled' : ''}"
                        ${outOfStock ? 'disabled aria-disabled="true"' : ''}
                        onclick="selectProductSizeCode(${size.id}, '${escHtml(size.label)}', this)">
                    ${escHtml(size.label)}
                    ${outOfStock ? '<span class="size-pill-low-stock">Out of stock</span>' : (lowStock ? `<span class="size-pill-low-stock">Only ${size.stock} left</span>` : '')}
                </button>`;
        });
        grid.innerHTML = html || '<p class="size-guide-empty-msg">No sizes configured for this colour yet.</p>';
    }

    function selectProductSizeCode(variantId, label, element) {
        selectedVariantId = variantId;
        selectedVariantName = label;

        document.querySelectorAll('#sizeLadderGrid .size-pill-btn').forEach(b => b.classList.remove('size-pill-selected'));
        if (element) element.classList.add('size-pill-selected');

        const msg = document.getElementById('sizeSelectValidationMsg');
        if (msg) msg.style.display = 'none';
        const wrap = document.getElementById('colorSizeSelectorWrap');
        if (wrap) wrap.style.border = '';
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Sticky gallery "lift" effect (desktop only) ──────────────────
    // Detects when .product-image-section is actually in its pinned/stuck
    // state (vs. just scrolling normally) and toggles a class for a subtle
    // shadow — confirms the sticky behaviour visually instead of leaving it
    // as an invisible layout detail.
    (function () {
        const section = document.getElementById('productImageSection');
        const sentinel = section ? section.querySelector('.sticky-sentinel') : null;
        if (!section || !sentinel || !('IntersectionObserver' in window)) return;

        let observer = null;

        function stickyOffset() {
            const raw = getComputedStyle(document.documentElement).getPropertyValue('--header-height').trim();
            const headerH = parseFloat(raw) || 90;
            return headerH + 16;
        }

        function setup() {
            if (observer) observer.disconnect();
            if (window.innerWidth < 992) {
                section.classList.remove('is-stuck');
                return;
            }
            observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    section.classList.toggle('is-stuck', !entry.isIntersecting);
                });
            }, { rootMargin: `-${stickyOffset() + 1}px 0px 0px 0px`, threshold: 0 });
            observer.observe(sentinel);
        }

        setup();
        window.addEventListener('resize', setup);
    })();

    document.addEventListener('DOMContentLoaded', () => {
        // Sync wishlist state
        const wishlist = getWishlist();
        const icon = document.getElementById('detailWishlistIcon');
        if (wishlist.indexOf('<?= $productId ?>') > -1) {
            icon.className = 'fa-solid fa-heart wishlist-btn-active';
            icon.closest('.btn-wishlist-action').classList.add('active');
        }

        // Update price display initially
        updatePriceDisplay();
        initColorSizeSelector();

        // Track & Load Recently Viewed
        trackRecentlyViewed();
        loadRecentlyViewed();
    });

    function selectProductSize(sz, element) {
        selectedVariantId = 0;
        selectedVariantName = sz;

        const pills = document.querySelectorAll('.variant-pill-btn');
        pills.forEach(p => p.classList.remove('selected-pill'));
        element.classList.add('selected-pill');

        updatePriceDisplay();
    }

    function selectProductVariant(vid, vname, price, element) {
        selectedVariantId = vid;
        selectedVariantName = vname;
        selectedVariantPrice = price;

        // Toggle selected styling
        const pills = document.querySelectorAll('.variant-pill-btn');
        pills.forEach(p => p.classList.remove('selected-pill'));
        element.classList.add('selected-pill');

        updatePriceDisplay();
    }

    function updatePriceDisplay() {
        const fmt = typeof formatPriceJS === 'function' ? formatPriceJS : (v => '£' + parseFloat(v).toFixed(2));
        const curEl = document.getElementById('priceCurrentAmount');
        const mrpEl = document.getElementById('priceMrpAmount');
        const offEl = document.getElementById('priceOffBadge');

        if (curEl) curEl.textContent = fmt(selectedVariantPrice);

        const hasDiscount = selectedVariantMrp > selectedVariantPrice;
        if (mrpEl) {
            mrpEl.style.display = hasDiscount ? '' : 'none';
            mrpEl.textContent = fmt(selectedVariantMrp);
        }
        if (offEl) {
            if (hasDiscount) {
                const pct = Math.round(((selectedVariantMrp - selectedVariantPrice) / selectedVariantMrp) * 100);
                offEl.textContent = pct + '% OFF';
                offEl.style.display = '';
            } else {
                offEl.style.display = 'none';
            }
        }
    }

    function validateSizeSelection(actionLabel) {
        const hasColors = DIEVON_COLORS.length > 0;
        const needsSelection = hasColors
            ? selectedVariantId === 0
            : (document.querySelector('.variant-pills-grid') && selectedVariantId === 0 && !selectedVariantName);

        if (!needsSelection) return true;

        const message = hasColors
            ? `Please select a size before ${actionLabel}.`
            : `Please select your garment size before ${actionLabel}.`;
        showToast('⚠️ Select Size', message);

        const wrap = hasColors ? document.getElementById('colorSizeSelectorWrap') : document.querySelector('.product-variants-wrap');
        const inlineMsg = document.getElementById('sizeSelectValidationMsg');
        if (inlineMsg) { inlineMsg.textContent = message; inlineMsg.style.display = 'block'; }
        if (wrap) {
            wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            wrap.style.border = '1px dashed var(--color-danger)';
            setTimeout(() => wrap.style.border = '', 2500);
        }
        return false;
    }

    let selectedQuantity = 1;
    const MAX_PRODUCT_QTY = 10;

    function changeProductQuantity(delta) {
        selectedQuantity = Math.max(1, Math.min(MAX_PRODUCT_QTY, selectedQuantity + delta));
        document.getElementById('selectedQtyDisplay').textContent = selectedQuantity;
    }

    function handleAddToCartClick() {
        if (!validateSizeSelection('adding to bag')) return;

        // Add to cart helper in footer
        addToCart(
            <?= $productId ?>,
            selectedVariantId,
            '<?= addslashes($product['name']) ?>',
            '<?= addslashes($product['emoji'] ?? '✨') ?>',
            selectedVariantName,
            selectedVariantPrice,
            selectedVariantName,
            selectedQuantity
        );
        changeProductQuantity(-MAX_PRODUCT_QTY); // reset stepper back to 1 for the next add
    }

    function handleBuyNowClick() {
        if (!validateSizeSelection('buying')) return;

        let body = 'action=add&product_id=<?= $productId ?>';
        if (selectedVariantId > 0) body += '&variant_id=' + selectedVariantId;
        if (selectedVariantName) body += '&size=' + encodeURIComponent(selectedVariantName);
        if (selectedQuantity > 1) body += '&quantity=' + selectedQuantity;

        fetch(getCartApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '<?= SITE_URL ?>/pages/checkout.php';
            } else {
                showToast('⚠️ Notice', data.message || 'Could not process request.');
            }
        })
        .catch(() => {
            window.location.href = '<?= SITE_URL ?>/pages/checkout.php';
        });
    }

    function updatePageCartIndicators(cart) {
        if (!cart || !cart.items) return;
        const currentProductId = <?= (int)$productId ?>;
        const existingItems = cart.items.filter(i => parseInt(i.product_id) === currentProductId);
        const totalQtyInCart = existingItems.reduce((acc, i) => acc + i.quantity, 0);

        const btn = document.querySelector('.btn-add-bag');
        const banner = document.getElementById('inBagStatusBanner');

        if (totalQtyInCart > 0) {
            if (btn) {
                btn.innerHTML = `<i class="fa-solid fa-check"></i> In Bag (${totalQtyInCart} in Cart)`;
                btn.classList.add('in-bag-active');
            }
            if (banner) {
                banner.style.display = 'flex';
                banner.innerHTML = `
                    <span><i class="fa-solid fa-circle-check"></i> This piece is already in your shopping bag (${totalQtyInCart} selected).</span>
                    <a href="#" onclick="openCart(); return false;" class="in-bag-view-link">View Cart →</a>
                `;
            }
        } else {
            if (btn) {
                btn.innerHTML = `<i class="fa-solid fa-bag-shopping"></i> Add to Bag`;
                btn.classList.remove('in-bag-active');
            }
            if (banner) {
                banner.style.display = 'none';
            }
        }
    }



    function handleWishlistToggleClick() {
        const icon = document.getElementById('detailWishlistIcon');
        const btn = icon.closest('.btn-wishlist-action');
        const added = toggleWishlist('<?= $productId ?>');
        if (added) {
            icon.className = 'fa-solid fa-heart wishlist-btn-active';
            btn.classList.add('active');
        } else {
            icon.className = 'fa-regular fa-heart';
            btn.classList.remove('active');
        }
    }

    function toggleAccordion(id) {
        const acc = document.getElementById(id);
        const icon = document.getElementById(id + 'Icon');
        const show = acc.style.display === 'none';

        acc.style.display = show ? 'block' : 'none';
        icon.className = show ? 'fa-solid fa-minus' : 'fa-solid fa-plus';
    }

    function closeProductAccordion(headerEl) {
        const content = headerEl.nextElementSibling;
        const icon = headerEl.querySelector('.toggle-icon');
        headerEl.classList.remove('open');
        headerEl.setAttribute('aria-expanded', 'false');
        content.style.maxHeight = '0px';
        content.style.paddingTop = '0px';
        content.style.paddingBottom = '0px';
        icon.classList.remove('fa-minus');
        icon.classList.add('fa-plus');
    }

    function toggleProductAccordion(headerEl) {
        const wasOpen = headerEl.classList.contains('open');

        // Only one accordion may be open at a time — close every other open one first
        document.querySelectorAll('.product-accordion-header.open').forEach(otherHeader => {
            if (otherHeader !== headerEl) closeProductAccordion(otherHeader);
        });

        if (wasOpen) {
            closeProductAccordion(headerEl);
            return;
        }

        const content = headerEl.nextElementSibling;
        const icon = headerEl.querySelector('.toggle-icon');
        headerEl.classList.add('open');
        headerEl.setAttribute('aria-expanded', 'true');
        content.style.maxHeight = content.scrollHeight + 40 + "px"; // Add some buffer for padding
        content.style.paddingTop = '15px';
        content.style.paddingBottom = '15px';
        icon.classList.remove('fa-plus');
        icon.classList.add('fa-minus');
    }

    function switchProductDetailsTab(btnEl, tabKey) {
        const content = btnEl.closest('.product-accordion-content');
        if (!content) return;

        content.querySelectorAll('.product-details-tab-btn').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');

        content.querySelectorAll('.product-details-tab-panel').forEach(p => {
            p.style.display = (p.dataset.panel === tabKey) ? '' : 'none';
        });

        // Re-measure the accordion's open height so a taller/shorter panel
        // never gets clipped or leaves a stale gap behind.
        const header = content.previousElementSibling;
        if (header && header.classList.contains('open')) {
            content.style.maxHeight = content.scrollHeight + 40 + 'px';
        }
    }

    function checkDelivery() {
        const zip = document.getElementById('zipCode').value.trim();
        const status = document.getElementById('deliveryStatus');
        if (!zip) {
            status.style.display = 'block';
            status.style.color = 'var(--color-danger)';
            status.textContent = 'Please enter a valid zip/postal code.';
            return;
        }
        
        status.style.display = 'block';
        status.style.color = 'var(--color-secondary)';
        status.textContent = 'Checking availability...';
        
        setTimeout(() => {
            if (zip.length > 3) {
                status.style.color = 'var(--color-primary)';
                status.innerHTML = `<i class="fa-solid fa-check-circle"></i> Express Delivery & COD available for ${escHtml(zip)}`;
            } else {
                status.style.color = 'var(--color-danger)';
                status.innerHTML = `<i class="fa-solid fa-xmark-circle"></i> COD not available for this area.`;
            }
        }, 800);
    }

    function trackRecentlyViewed() {
        const product = {
            id: <?= $product['id'] ?>,
            name: '<?= addslashes($product['name']) ?>',
            price: <?= $product['price'] ?>,
            image: '<?= addslashes($product['image']) ?>',
            category: '<?= addslashes($product['category']) ?>'
        };
        
        let viewed = [];
        try {
            viewed = JSON.parse(localStorage.getItem('dievon_recently_viewed')) || [];
        } catch (e) {}

        // Remove if exists
        viewed = viewed.filter(p => p.id !== product.id);
        // Add to front
        viewed.unshift(product);
        // Keep only last 4
        if (viewed.length > 4) viewed = viewed.slice(0, 4);

        localStorage.setItem('dievon_recently_viewed', JSON.stringify(viewed));
    }

    function loadRecentlyViewed() {
        let viewed = [];
        try {
            viewed = JSON.parse(localStorage.getItem('dievon_recently_viewed')) || [];
        } catch (e) {}
        
        // Exclude current product from display
        viewed = viewed.filter(p => p.id !== <?= $productId ?>);

        const container = document.getElementById('recentlyViewedContainer');
        const empty = document.getElementById('recentlyViewedEmpty');
        
        if (viewed.length === 0) {
            empty.style.display = 'block';
            return;
        }

        viewed.forEach(p => {
            const siteUrl = window.SITE_URL || '';
            const imgHtml = p.image ? `<img src="${siteUrl}/uploads/products/${escHtml(p.image)}" alt="${escHtml(p.name)}">` : '<div style="width:100%;height:100%;background:var(--bg-surface-soft);"></div>';
            const html = `
                <article class="compact-product-card reveal-on-scroll">
                    <div class="compact-card-img-wrap">
                        <a href="${siteUrl}/product?id=${p.id}">${imgHtml}</a>
                    </div>
                    <div class="compact-card-details">
                        <span class="compact-card-cat">${escHtml(p.category)}</span>
                        <h3 class="compact-card-name">
                            <a href="${siteUrl}/product?id=${p.id}">${escHtml(p.name)}</a>
                        </h3>
                        <div class="compact-card-price">${typeof formatPriceJS === 'function' ? formatPriceJS(p.price) : '£' + parseFloat(p.price).toFixed(2)}</div>
                    </div>
                </article>
            `;
            container.insertAdjacentHTML('beforeend', html);
        });

        if (typeof dievonObserveReveal === 'function') {
            container.querySelectorAll('.reveal-on-scroll').forEach(el => dievonObserveReveal(el));
        }
    }

    // ── Dievon Size Guide Modal ────────────────────────────────────
    const DIEVON_SG_UNIT = <?= json_encode($sizeGuideChart['unit'] ?? 'in') ?>;
    let dievonSgLastFocused = null;
    let dievonSgCurrentUnit = DIEVON_SG_UNIT;

    function openSizeGuideModal() {
        const modal = document.getElementById('dievonSizeGuideModal');
        if (!modal) return;
        dievonSgLastFocused = document.activeElement;
        modal.style.display = 'flex';
        requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add('is-visible')));
        document.body.classList.add('dievon-sg-open');
        const closeBtn = document.getElementById('dievonSgCloseBtn');
        if (closeBtn) closeBtn.focus();
        document.addEventListener('keydown', dievonSgEscHandler);
    }

    function closeSizeGuideModal() {
        const modal = document.getElementById('dievonSizeGuideModal');
        if (!modal) return;
        modal.classList.remove('is-visible');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
        document.body.classList.remove('dievon-sg-open');
        document.removeEventListener('keydown', dievonSgEscHandler);
        if (dievonSgLastFocused && typeof dievonSgLastFocused.focus === 'function') dievonSgLastFocused.focus();
    }

    function dievonSgEscHandler(e) {
        if (e.key === 'Escape') closeSizeGuideModal();
    }

    function dievonSgSwitchTab(type) {
        ['body', 'garment'].forEach(t => {
            const table = document.getElementById('sgTable-' + t);
            const btn = document.getElementById('sgTabBtn-' + t);
            if (table) table.style.display = (t === type) ? '' : 'none';
            if (btn) { btn.classList.toggle('active', t === type); btn.setAttribute('aria-selected', t === type ? 'true' : 'false'); }
        });
    }

    function dievonSgSwitchUnit(unit) {
        if (unit === dievonSgCurrentUnit) return;
        dievonSgCurrentUnit = unit;
        document.getElementById('sgUnitBtn-in')?.classList.toggle('active', unit === 'in');
        document.getElementById('sgUnitBtn-cm')?.classList.toggle('active', unit === 'cm');

        document.querySelectorAll('.dievon-sg-measure-cell').forEach(cell => {
            const raw = cell.getAttribute('data-raw');
            if (raw === '' || raw === null) { cell.textContent = '—'; return; }
            let val = parseFloat(raw);
            if (DIEVON_SG_UNIT !== unit) {
                val = (DIEVON_SG_UNIT === 'in') ? (val * 2.54) : (val / 2.54);
            }
            cell.textContent = (Math.round(val * 10) / 10).toString();
        });
    }
</script>

<!-- ══ Dievon Size Guide Modal ════════════════════════════════════ -->
<div id="dievonSizeGuideModal" class="dievon-sg-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dievonSgTitle" onclick="if(event.target===this) closeSizeGuideModal()">
    <div class="dievon-sg-modal">
        <div class="dievon-sg-header">
            <div class="dievon-sg-header-text">
                <span class="dievon-sg-eyebrow">Measure With Confidence</span>
                <h3 id="dievonSgTitle" class="dievon-sg-title">DIEVON SIZE GUIDE</h3>
            </div>
            <button type="button" id="dievonSgCloseBtn" class="dievon-sg-close" onclick="closeSizeGuideModal()" aria-label="Close size guide">&times;</button>
        </div>

        <?php if ($sizeGuideChart && (!empty($sizeGuideBody) || !empty($sizeGuideGarment))): ?>
        <p class="dievon-sg-intro">Compare your body measurements against the chart below to find your perfect Dievon fit. All measurements are shown in <?= ($sizeGuideChart['unit'] ?? 'in') === 'in' ? 'inches' : 'centimetres' ?> by default — toggle units at any time.</p>
        <div class="dievon-sg-controls">
            <div class="dievon-sg-tabs" role="tablist">
                <button type="button" id="sgTabBtn-body" class="dievon-sg-tab active" role="tab" aria-selected="true" onclick="dievonSgSwitchTab('body')">BODY MEASUREMENTS</button>
                <button type="button" id="sgTabBtn-garment" class="dievon-sg-tab" role="tab" aria-selected="false" onclick="dievonSgSwitchTab('garment')">GARMENT MEASUREMENTS</button>
            </div>
            <div class="dievon-sg-unit-toggle" role="group" aria-label="Measurement units">
                <button type="button" id="sgUnitBtn-in" class="dievon-sg-unit-btn <?= ($sizeGuideChart['unit'] ?? 'in') === 'in' ? 'active' : '' ?>" onclick="dievonSgSwitchUnit('in')">INCHES</button>
                <button type="button" id="sgUnitBtn-cm" class="dievon-sg-unit-btn <?= ($sizeGuideChart['unit'] ?? 'in') === 'cm' ? 'active' : '' ?>" onclick="dievonSgSwitchUnit('cm')">CM</button>
            </div>
        </div>

        <div class="dievon-sg-body">
            <div class="dievon-sg-table-wrap">
                <table class="dievon-sg-table" id="sgTable-body">
                    <thead><tr><th>Size</th><th>Numeric</th><th>Bust</th><th>Waist</th><th>Hips</th><th>Shoulder</th></tr></thead>
                    <tbody>
                        <?php if (empty($sizeGuideBody)): ?>
                        <tr><td colspan="6" class="dievon-sg-empty-row">No body measurements entered yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($sizeGuideBody as $r): ?>
                        <tr>
                            <td class="dievon-sg-size-cell"><?= htmlspecialchars($r['size_label']) ?></td>
                            <td><?= htmlspecialchars($r['numeric_size'] ?? '—') ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['bust'] !== null ? $r['bust'] : '' ?>"><?= $r['bust'] !== null ? rtrim(rtrim(number_format($r['bust'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['waist'] !== null ? $r['waist'] : '' ?>"><?= $r['waist'] !== null ? rtrim(rtrim(number_format($r['waist'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['hips'] !== null ? $r['hips'] : '' ?>"><?= $r['hips'] !== null ? rtrim(rtrim(number_format($r['hips'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['shoulder'] !== null ? $r['shoulder'] : '' ?>"><?= $r['shoulder'] !== null ? rtrim(rtrim(number_format($r['shoulder'], 1), '0'), '.') : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <table class="dievon-sg-table" id="sgTable-garment" style="display:none;">
                    <thead><tr><th>Size</th><th>Numeric</th><th>Bust</th><th>Waist</th><th>Hips</th><th>Shoulder</th><th>Length</th></tr></thead>
                    <tbody>
                        <?php if (empty($sizeGuideGarment)): ?>
                        <tr><td colspan="7" class="dievon-sg-empty-row">No garment measurements entered yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($sizeGuideGarment as $r): ?>
                        <tr>
                            <td class="dievon-sg-size-cell"><?= htmlspecialchars($r['size_label']) ?></td>
                            <td><?= htmlspecialchars($r['numeric_size'] ?? '—') ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['bust'] !== null ? $r['bust'] : '' ?>"><?= $r['bust'] !== null ? rtrim(rtrim(number_format($r['bust'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['waist'] !== null ? $r['waist'] : '' ?>"><?= $r['waist'] !== null ? rtrim(rtrim(number_format($r['waist'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['hips'] !== null ? $r['hips'] : '' ?>"><?= $r['hips'] !== null ? rtrim(rtrim(number_format($r['hips'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['shoulder'] !== null ? $r['shoulder'] : '' ?>"><?= $r['shoulder'] !== null ? rtrim(rtrim(number_format($r['shoulder'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['length'] !== null ? $r['length'] : '' ?>"><?= $r['length'] !== null ? rtrim(rtrim(number_format($r['length'], 1), '0'), '.') : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="dievon-sg-illustration-row">
                <div class="dievon-sg-illustration">
                    <?php if (!empty($sizeGuideChart['illustration_image'])): ?>
                    <?php
                        $sgLenTop    = $sizeGuideChart['pos_length_top'] ?? 15;
                        $sgLenBottom = $sizeGuideChart['pos_length_bottom'] ?? 95;
                    ?>
                    <div class="dievon-sg-photo-stage">
                        <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($sizeGuideChart['illustration_image']) ?>" alt="Measurement illustration" class="dievon-sg-photo-img">
                        <?php if ($sgShowShoulder): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_shoulder_top'] ?? 18 ?>%; width:<?= $sizeGuideChart['pos_shoulder_width'] ?? 45 ?>%;"><span>Shoulder</span></div>
                        <?php endif; ?>
                        <?php if ($sgShowBust): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_bust_top'] ?? 32 ?>%; width:<?= $sizeGuideChart['pos_bust_width'] ?? 45 ?>%;"><span>Bust</span></div>
                        <?php endif; ?>
                        <?php if ($sgShowWaist): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_waist_top'] ?? 50 ?>%; width:<?= $sizeGuideChart['pos_waist_width'] ?? 35 ?>%;"><span>Waist</span></div>
                        <?php endif; ?>
                        <?php if ($sgShowHips): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_hips_top'] ?? 64 ?>%; width:<?= $sizeGuideChart['pos_hips_width'] ?? 50 ?>%;"><span>Hips</span></div>
                        <?php endif; ?>
                        <?php if ($sgShowLength): ?>
                        <div class="dievon-sg-photo-length" style="top:<?= min($sgLenTop, $sgLenBottom) ?>%; height:<?= abs($sgLenBottom - $sgLenTop) ?>%;"><span>Length</span></div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($sgIsLowerBodyOnly): ?>
                    <svg viewBox="0 0 210 300" class="dievon-sg-illustration-svg" role="img" aria-label="Diagram showing where to measure waist, hips and length on a lower-body silhouette">
                        <path d="M125,40 C138,55 145,72 145,90 C148,140 152,220 155,280 L45,280 C48,220 52,140 55,90 C55,72 62,55 75,40 Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M100,90 L98,280 M102,90 L102,280" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="2,3" opacity="0.5"/>

                        <line x1="72" y1="38" x2="128" y2="38" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="72" y1="34" x2="72" y2="42" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="128" y1="34" x2="128" y2="42" stroke="currentColor" stroke-width="1.5"/>
                        <text x="133" y="41" font-size="9" fill="currentColor">Waist</text>

                        <line x1="50" y1="95" x2="150" y2="95" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="50" y1="91" x2="50" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="150" y1="91" x2="150" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <text x="155" y="98" font-size="9" fill="currentColor">Hips</text>

                        <line x1="20" y1="40" x2="20" y2="280" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="16" y1="40" x2="24" y2="40" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="16" y1="280" x2="24" y2="280" stroke="currentColor" stroke-width="1.5"/>
                        <text x="9" y="168" font-size="9" fill="currentColor" transform="rotate(-90 9 168)">Length</text>
                    </svg>
                    <?php else: ?>
                    <svg viewBox="0 0 210 300" class="dievon-sg-illustration-svg" role="img" aria-label="Diagram showing where to measure shoulder, bust, waist, hips and garment length on a front-facing silhouette">
                        <circle cx="100" cy="24" r="15" fill="none" stroke="currentColor" stroke-width="2"/>
                        <path d="M90,38 L92,50 M110,38 L108,50" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M138,50 C144,65 142,80 142,95 C142,115 136,135 130,150 C126,165 138,178 146,195 C152,220 155,250 155,280 L45,280 C45,250 48,220 54,195 C62,178 74,165 70,150 C64,135 58,115 58,95 C58,80 56,65 62,50 Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>

                        <line x1="55" y1="50" x2="145" y2="50" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="55" y1="46" x2="55" y2="54" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="145" y1="46" x2="145" y2="54" stroke="currentColor" stroke-width="1.5"/>
                        <text x="150" y="53" font-size="9" fill="currentColor">Shoulder</text>

                        <line x1="50" y1="95" x2="150" y2="95" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="50" y1="91" x2="50" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="150" y1="91" x2="150" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <text x="155" y="98" font-size="9" fill="currentColor">Bust</text>

                        <line x1="62" y1="150" x2="138" y2="150" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="62" y1="146" x2="62" y2="154" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="138" y1="146" x2="138" y2="154" stroke="currentColor" stroke-width="1.5"/>
                        <text x="143" y="153" font-size="9" fill="currentColor">Waist</text>

                        <line x1="46" y1="195" x2="154" y2="195" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="46" y1="191" x2="46" y2="199" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="154" y1="191" x2="154" y2="199" stroke="currentColor" stroke-width="1.5"/>
                        <text x="159" y="198" font-size="9" fill="currentColor">Hips</text>

                        <line x1="20" y1="50" x2="20" y2="280" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="16" y1="50" x2="24" y2="50" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="16" y1="280" x2="24" y2="280" stroke="currentColor" stroke-width="1.5"/>
                        <text x="9" y="168" font-size="9" fill="currentColor" transform="rotate(-90 9 168)">Length</text>
                    </svg>
                    <?php endif; ?>
                </div>
                <?php
                    $instructionPairs = [
                        'Shoulder' => $sizeGuideChart['instructions_shoulder'] ?? '',
                        'Bust'     => $sizeGuideChart['instructions_bust'] ?? '',
                        'Waist'    => $sizeGuideChart['instructions_waist'] ?? '',
                        'Hips'     => $sizeGuideChart['instructions_hips'] ?? '',
                        'Garment Length' => $sizeGuideChart['instructions_length'] ?? '',
                    ];
                    $instructionPairs = array_filter($instructionPairs, fn($v) => trim($v) !== '');
                ?>
                <?php if (!empty($instructionPairs)): ?>
                <div class="dievon-sg-instructions">
                    <h4>How to measure</h4>
                    <?php foreach ($instructionPairs as $label => $text): ?>
                    <p><strong><?= htmlspecialchars($label) ?>:</strong> <?= htmlspecialchars($text) ?></p>
                    <?php endforeach; ?>
                    <div class="dievon-sg-tip"><strong>Tip:</strong> Measure over well-fitting undergarments, keep the tape parallel to the floor, and ask someone to help for the most accurate result.</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="dievon-sg-cta">
                <p>Still unsure of your fit?</p>
                <button type="button" class="btn-luxury-outline" style="padding: 10px 22px; font-size: 11px;" onclick="closeSizeGuideModal(); setTimeout(() => openFadeModal('askQuestionModal'), 320);">Ask Our Concierge</button>
            </div>
        </div>
        <?php else: ?>
        <div class="dievon-sg-body">
            <p class="size-guide-empty-msg">The size guide for this product hasn't been set up yet. Please contact us and we'll help you find the right fit.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ Product Enquiry / Concierge Modal ════════════════════════ -->
<div id="productEnquiryModal" class="product-modal-overlay">
    <div class="product-modal-card">
        <div class="product-modal-card-header">
            <h3>👗 Product & Fitting Enquiry</h3>
            <button onclick="document.getElementById('productEnquiryModal').style.display='none'" class="product-modal-close-btn">&times;</button>
        </div>
        <div class="product-modal-card-body">
            <p class="product-modal-hint">
                Have questions regarding <strong><?= htmlspecialchars($product['name']) ?></strong>, custom tailoring, or size fitting?
            </p>
            <form id="productEnquiryForm" onsubmit="submitProductEnquiry(event)">
                <input type="hidden" name="product_id" value="<?= $productId ?>">
                <input type="hidden" name="product_name" value="<?= htmlspecialchars($product['name']) ?>">
                <div class="form-luxury-group">
                    <label>Your Name *</label>
                    <input type="text" name="name" class="form-luxury-input" required placeholder="Eleanor Vance" value="<?= htmlspecialchars($_SESSION['customer_name'] ?? '') ?>">
                </div>
                <div class="form-luxury-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-luxury-input" required placeholder="you@example.com" value="<?= htmlspecialchars($_SESSION['customer_email'] ?? '') ?>">
                </div>
                <div class="form-luxury-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" class="form-luxury-input" placeholder="+91 98765 43210">
                </div>
                <div class="form-luxury-group">
                    <label>Your Message / Fitting Request *</label>
                    <textarea name="message" class="form-luxury-input" rows="3" required placeholder="Ask about size advice, fabric details, or custom order requests..."></textarea>
                </div>
                <div id="enquiryStatusMsg" class="product-modal-form-msg" style="display: none;"></div>
                <button type="submit" id="btnSubmitEnquiry" class="btn-luxury" style="width: 100%;">
                    Submit Product Enquiry
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function submitProductEnquiry(e) {
    e.preventDefault();
    const form = document.getElementById('productEnquiryForm');
    const statusMsg = document.getElementById('enquiryStatusMsg');
    const btn = document.getElementById('btnSubmitEnquiry');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    statusMsg.style.display = 'none';

    const formData = new FormData(form);
    fetch('actions/product_enquiry_action.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        statusMsg.style.display = 'block';
        if (data.success) {
            statusMsg.style.color = '#10b981';
            statusMsg.innerHTML = '✅ ' + data.message;
            form.reset();
            setTimeout(() => {
                document.getElementById('productEnquiryModal').style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = 'Submit Product Enquiry';
            }, 2500);
        } else {
            statusMsg.style.color = '#ef4444';
            statusMsg.innerHTML = '❌ ' + data.message;
            btn.disabled = false;
            btn.innerHTML = 'Submit Product Enquiry';
        }
    })
    .catch(err => {
        statusMsg.style.display = 'block';
        statusMsg.style.color = '#ef4444';
        statusMsg.innerHTML = '❌ Error sending enquiry. Please try again.';
        btn.disabled = false;
        btn.innerHTML = 'Submit Product Enquiry';
    });
}

function submitProductReview(e, productId) {
    e.preventDefault();
    const form = document.getElementById('writeReviewForm');
    const msg = document.getElementById('writeReviewMsg');
    const btn = form.querySelector('button[type="submit"]');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    msg.style.display = 'none';

    const formData = new FormData(form);
    formData.append('product_id', productId);

    fetch('actions/review_action.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        msg.style.display = 'block';
        if (data.success) {
            msg.style.background = '#ecfdf5';
            msg.style.color = '#065f46';
            msg.innerHTML = data.message;
            form.reset();
            setTimeout(() => { closeFadeModal('writeReviewModal'); }, 2500);
        } else {
            msg.style.background = '#fdf2f2';
            msg.style.color = '#a94442';
            msg.innerHTML = data.message;
        }
        btn.disabled = false;
        btn.innerHTML = 'Submit Review';
    })
    .catch(() => {
        msg.style.display = 'block';
        msg.style.background = '#fdf2f2';
        msg.style.color = '#a94442';
        msg.innerHTML = 'Error submitting review. Please try again.';
        btn.disabled = false;
        btn.innerHTML = 'Submit Review';
    });
}

</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
