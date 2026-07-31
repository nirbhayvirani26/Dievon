<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pageTitle = "Luxury Women's Ethnic & Contemporary Fashion | Kurtis, Suits & Co-Ords";
$metaDescription = "Dievon is an ultra-premium luxury women's fashion brand offering elegant silk kurtas, hand-embroidered 3-piece suits, designer dresses, organza dupattas, and tailored co-ord sets.";
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'home'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = SITE_URL . '/uploads/gallery/' . $seoRow['og_image']; }
    }
} catch (PDOException $e) {}
require_once __DIR__ . '/../includes/header.php';

// Fetch products for New Arrivals (limit 8)
$newArrivals = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 AND badge = 'New' ORDER BY id DESC LIMIT 8");
    $newArrivals = $stmt->fetchAll();
    if (count($newArrivals) < 8) {
        $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY id DESC LIMIT 8");
        $newArrivals = $stmt->fetchAll();
    }
} catch (PDOException $e) {}

// Fetch Best Sellers
$bestSellers = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 AND badge = 'Best Seller' ORDER BY id ASC LIMIT 4");
    $bestSellers = $stmt->fetchAll();
    if (empty($bestSellers)) {
        $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY price DESC LIMIT 4");
        $bestSellers = $stmt->fetchAll();
    }
} catch (PDOException $e) {}

// Fetch Trending / Hot
$trending = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 AND badge = 'Hot' ORDER BY id ASC LIMIT 4");
    $trending = $stmt->fetchAll();
    if (empty($trending)) {
        $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY price ASC LIMIT 4");
        $trending = $stmt->fetchAll();
    }
} catch (PDOException $e) {}

// Fetch dynamic hero slider banners
$heroBanners = [];
try {
    $heroBanners = $pdo->query("SELECT * FROM banners WHERE status = 'Active' ORDER BY sort_order ASC, id DESC")->fetchAll();
} catch (PDOException $e) {}
?>


<!-- ==================== 1. LUXURY HERO SLIDER ==================== -->

<section class="hero-slider-section">
    <div class="slider-container" id="heroSlider">
        <?php if (!empty($heroBanners)): ?>
            <?php foreach ($heroBanners as $idx => $banner): 
                $imgFile = $banner['image'] ?? '';
                if (!empty($imgFile) && file_exists(__DIR__ . '/../uploads/products/' . $imgFile)) {
                    $imgUrl = SITE_URL . '/uploads/products/' . $imgFile;
                } elseif (!empty($imgFile) && file_exists(__DIR__ . '/../uploads/gallery/' . $imgFile)) {
                    $imgUrl = SITE_URL . '/uploads/gallery/' . $imgFile;
                } else {
                    $imgUrl = SITE_URL . '/uploads/gallery/lookbook_' . (($idx % 3) + 1) . '.png';
                }
                $slideBg = "background-image: url('" . htmlspecialchars($imgUrl) . "') !important; background-size: cover !important; background-position: center !important;";
            ?>
                <div class="slide slide-<?= ($idx % 3) + 1 ?>" style="<?= $slideBg ?>">
                    <div class="slide-overlay"></div>
                    <div class="slide-content">
                        <span class="slide-eyebrow">Edition <?= $idx + 1 ?></span>
                        <h1><?= htmlspecialchars($banner['title']) ?></h1>
                        <p><?= htmlspecialchars($banner['subtitle'] ?? 'Curated luxury fashion ensembles crafted for elegant moods.') ?></p>
                        <?php
                        // Banner links are authored in the admin panel and are usually stored
                        // relative (e.g. "shop?category=Kurtis"). A relative href resolves
                        // against the current directory, so it 404s on any multi-segment route
                        // such as /product/<slug>-<id>. Make it absolute unless it is already
                        // an absolute URL or root-relative path.
                        $bannerLink = trim($banner['link'] ?? '') ?: 'shop';
                        // `~` delimiter, not `#` — the pattern itself contains a literal `#`
                        // (anchor links), which would terminate a `#`-delimited regex early.
                        if (!preg_match('~^(https?://|//|/|#|mailto:|tel:)~i', $bannerLink)) {
                            $bannerLink = SITE_URL . '/' . ltrim($bannerLink, '/');
                        }
                        ?>
                        <a href="<?= htmlspecialchars($bannerLink) ?>" class="btn-luxury btn-hero-explore">Explore Collection</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Default Curated Slides -->
            <div class="slide slide-1">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <span class="slide-eyebrow">Volume I</span>
                    <h1>The Silk Kurtis</h1>
                    <p>Fluid silhouettes draped in pure heavy mulberry silk. Designed for grace in motion.</p>
                    <a href="<?= SITE_URL ?>/shop?category=Kurtis" class="btn-luxury btn-hero-explore">Explore Edition</a>
                </div>
            </div>
            <div class="slide slide-2">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <span class="slide-eyebrow">Volume II</span>
                    <h2>3-Piece Suits</h2>
                    <p>Elegant tailored brocades and soft raw silks. Perfect for premium celebrations.</p>
                    <a href="<?= SITE_URL ?>/shop?category=3+Piece+Suits" class="btn-luxury btn-hero-explore">View Collection</a>
                </div>
            </div>
            <div class="slide slide-3">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <span class="slide-eyebrow">Volume III</span>
                    <h2>Coord Sets</h2>
                    <p>Luxurious satin wide-legs and tailored organic linens. Designed for refined modern loungewear.</p>
                    <a href="<?= SITE_URL ?>/shop?category=Coord+Sets" class="btn-luxury btn-hero-explore">Discover Sets</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <button onclick="prevSlide()" class="hero-arrow hero-arrow-left" aria-label="Previous slide"><i class="fa-solid fa-chevron-left"></i></button>
    <button onclick="nextSlide()" class="hero-arrow hero-arrow-right" aria-label="Next slide"><i class="fa-solid fa-chevron-right"></i></button>

    <div class="hero-dots-container">
        <?php 
        $dotsCount = !empty($heroBanners) ? count($heroBanners) : 3;
        for ($i = 0; $i < $dotsCount; $i++): 
        ?>
            <span class="slide-dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $i ?>)"></span>
        <?php endfor; ?>
    </div>
</section>

<!-- ==================== 2. FEATURED CATEGORIES ==================== -->
<section class="featured-categories section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Curated Ensembles</span>
            <h2 class="section-title">Dievon Collections</h2>
        </div>
        <div class="home-categories-grid">
            <?php
            // Fetch ONLY main parent categories for the collections section
            $stmtCat = $pdo->query("SELECT * FROM categories WHERE parent_id = 0 OR parent_id IS NULL ORDER BY sort_order ASC");
            $allCategories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

            foreach($allCategories as $cat): 
                // Get an image from a product in this category, if any
                $stmtProdImg = $pdo->prepare("SELECT image FROM products WHERE category = :cat AND image != '' LIMIT 1");
                $stmtProdImg->execute(['cat' => $cat['name']]);
                $prodImg = $stmtProdImg->fetchColumn();
                $catImgSrc = $prodImg ? SITE_URL . '/uploads/products/' . htmlspecialchars($prodImg) : SITE_URL . '/assets/images/placeholder.png'; 
            ?>
            <a href="<?= SITE_URL ?>/shop?category=<?= urlencode($cat['name']) ?>" class="category-card zoom-box">
                <img class="zoom-img" src="<?= $catImgSrc ?>" alt="<?= htmlspecialchars($cat['name']) ?>" loading="lazy">
                <div class="category-card-overlay">
                    <div>
                        <h3><?= htmlspecialchars($cat['name']) ?></h3>
                        <span class="category-card-link">View Collection <i class="fa-solid fa-arrow-right-long"></i></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ==================== 3. NEW ARRIVALS ==================== -->
<section class="new-arrivals-section section-space">
    <div class="container">
        <div class="home-section-header reveal-on-scroll">
            <div>
                <span class="editorial-label">Latest Creations</span>
                <h2 class="section-title">New Arrivals</h2>
            </div>
            <div class="home-section-header-btns">
                <button onclick="scrollCarousel(-1)" class="btn-carousel-arrow" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></button>
                <button onclick="scrollCarousel(1)" class="btn-carousel-arrow" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>

        <div id="newArrivalsCarousel" class="new-arrivals-carousel hide-scrollbar">
            <?php foreach ($newArrivals as $p):
                $imgSrc = !empty($p['image']) ? SITE_URL . '/uploads/products/' . htmlspecialchars($p['image']) : '';
                $pMrp = (float)($p['mrp_price'] ?? 0);
                $pHasDiscount = $pMrp > (float)$p['price'];
                $pDiscountPercent = $pHasDiscount ? (int)round((($pMrp - (float)$p['price']) / $pMrp) * 100) : 0;
            ?>
            <article class="product-card product-card-carousel reveal-on-scroll">
                <?php if ($pHasDiscount): ?>
                    <span class="badge-luxury badge-sale"><?= $pDiscountPercent ?>% OFF</span>
                <?php else: ?>
                    <span class="badge-luxury">New</span>
                <?php endif; ?>
                <button onclick="event.preventDefault(); handleWishlistClick(<?= $p['id'] ?>, this)" aria-label="Add to Wishlist" class="product-card-wishlist-btn">
                    <i class="fa-regular fa-heart"></i>
                </button>
                <div class="card-img-container">
                    <a href="<?= productUrl($p['id'], $p['name']) ?>" class="card-img-link">
                        <?php if ($imgSrc): ?>
                            <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="card-img" loading="lazy">
                        <?php else: ?>
                            <div class="card-img-fallback"><?= htmlspecialchars($p['emoji']) ?></div>
                        <?php endif; ?>
                    </a>
                    <button onclick="event.preventDefault(); window.location.href='<?= productUrl($p['id'], $p['name']) ?>'" class="quick-view-btn">Quick View</button>
                </div>
                <div class="product-card-details">
                    <span class="product-card-category"><?= htmlspecialchars($p['category']) ?></span>
                    <h3 class="product-card-title"><a href="<?= productUrl($p['id'], $p['name']) ?>"><?= htmlspecialchars($p['name']) ?></a></h3>
                    <div class="product-card-price">
                        <?php if ($pHasDiscount): ?><span class="price-mrp-strike"><?= formatPrice($pMrp) ?></span><?php endif; ?>
                        <?= formatPrice($p['price']) ?>
                    </div>
                    <a href="<?= productUrl($p['id'], $p['name']) ?>" class="btn-luxury product-card-cta">Details</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ==================== 4. TRENDING / BEST SELLERS ==================== -->
<section class="trending-section section-space">
    <div class="container">
        <div class="tab-toggle-container">
            <div class="tab-toggle-wrapper">
                <button id="btnTabBest" onclick="toggleTabs('best')" class="btn-luxury">Best Sellers</button>
                <button id="btnTabTrend" onclick="toggleTabs('trend')" class="btn-luxury-outline">Trending Now</button>
            </div>
        </div>

        <div id="gridBestSellers" class="home-best-sellers-grid">
            <?php foreach ($bestSellers as $p):
                $imgSrc = !empty($p['image']) ? SITE_URL . '/uploads/products/' . htmlspecialchars($p['image']) : '';
                $pMrp = (float)($p['mrp_price'] ?? 0);
                $pHasDiscount = $pMrp > (float)$p['price'];
                $pDiscountPercent = $pHasDiscount ? (int)round((($pMrp - (float)$p['price']) / $pMrp) * 100) : 0;
            ?>
            <article class="product-card reveal-on-scroll">
                <?php if ($pHasDiscount): ?>
                    <span class="badge-luxury badge-sale"><?= $pDiscountPercent ?>% OFF</span>
                <?php elseif (!empty($p['badge'])): ?>
                    <span class="badge-luxury"><?= htmlspecialchars($p['badge']) ?></span>
                <?php endif; ?>
                <button onclick="event.preventDefault(); handleWishlistClick(<?= $p['id'] ?>, this)" aria-label="Add to Wishlist" class="product-card-wishlist-btn">
                    <i class="fa-regular fa-heart"></i>
                </button>
                <div class="card-img-container">
                    <a href="<?= productUrl($p['id'], $p['name']) ?>" class="card-img-link">
                        <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="card-img" loading="lazy">
                    </a>
                    <button onclick="event.preventDefault(); window.location.href='<?= productUrl($p['id'], $p['name']) ?>'" class="quick-view-btn">Quick View</button>
                </div>
                <div class="product-card-details">
                    <span class="product-card-category"><?= htmlspecialchars($p['category']) ?></span>
                    <h3 class="product-card-title"><a href="<?= productUrl($p['id'], $p['name']) ?>"><?= htmlspecialchars($p['name']) ?></a></h3>
                    <div class="product-card-price">
                        <?php if ($pHasDiscount): ?><span class="price-mrp-strike"><?= formatPrice($pMrp) ?></span><?php endif; ?>
                        <?= formatPrice($p['price']) ?>
                    </div>
                    <a href="<?= productUrl($p['id'], $p['name']) ?>" class="btn-luxury product-card-cta">Details</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <div id="gridTrending" class="home-best-sellers-grid" style="display: none;">
            <?php foreach ($trending as $p):
                $imgSrc = !empty($p['image']) ? SITE_URL . '/uploads/products/' . htmlspecialchars($p['image']) : '';
                $pMrp = (float)($p['mrp_price'] ?? 0);
                $pHasDiscount = $pMrp > (float)$p['price'];
                $pDiscountPercent = $pHasDiscount ? (int)round((($pMrp - (float)$p['price']) / $pMrp) * 100) : 0;
            ?>
            <article class="product-card reveal-on-scroll">
                <?php if ($pHasDiscount): ?>
                    <span class="badge-luxury badge-sale"><?= $pDiscountPercent ?>% OFF</span>
                <?php elseif (!empty($p['badge'])): ?>
                    <span class="badge-luxury"><?= htmlspecialchars($p['badge']) ?></span>
                <?php endif; ?>
                <button onclick="event.preventDefault(); handleWishlistClick(<?= $p['id'] ?>, this)" aria-label="Add to Wishlist" class="product-card-wishlist-btn">
                    <i class="fa-regular fa-heart"></i>
                </button>
                <div class="card-img-container">
                    <a href="<?= productUrl($p['id'], $p['name']) ?>" class="card-img-link">
                        <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="card-img" loading="lazy">
                    </a>
                    <button onclick="event.preventDefault(); window.location.href='<?= productUrl($p['id'], $p['name']) ?>'" class="quick-view-btn">Quick View</button>
                </div>
                <div class="product-card-details">
                    <span class="product-card-category"><?= htmlspecialchars($p['category']) ?></span>
                    <h3 class="product-card-title"><a href="<?= productUrl($p['id'], $p['name']) ?>"><?= htmlspecialchars($p['name']) ?></a></h3>
                    <div class="product-card-price">
                        <?php if ($pHasDiscount): ?><span class="price-mrp-strike"><?= formatPrice($pMrp) ?></span><?php endif; ?>
                        <?= formatPrice($p['price']) ?>
                    </div>
                    <a href="<?= productUrl($p['id'], $p['name']) ?>" class="btn-luxury product-card-cta">Details</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ==================== 5. SHOP BY OCCASION ==================== -->
<section class="occasion-bar-section section-space">
    <div class="container">
        <span class="occasion-bar-title">Shop by Occasion</span>
        <div class="occasion-bar-pills">
            <a href="<?= SITE_URL ?>/shop?category=Dresses" class="btn-luxury-outline pill-btn">Wedding Guest</a>
            <a href="<?= SITE_URL ?>/shop?category=Outerwear" class="btn-luxury-outline pill-btn">Office Chic</a>
            <a href="<?= SITE_URL ?>/shop?category=Dresses" class="btn-luxury-outline pill-btn">Evening Party</a>
            <a href="<?= SITE_URL ?>/shop?category=Jewelry%20%26%20Accessories" class="btn-luxury-outline pill-btn">Festive Look</a>
        </div>
    </div>
</section>

<!-- ==================== 6. FESTIVAL / WEDDING / OFFICE / PARTY COLLECTIONS ==================== -->
<section class="occasion-banners-section">
    <!-- Wedding Collection Banner -->
    <div class="occasion-banner banner-wedding">
        <div class="occasion-banner-overlay"></div>
        <div class="container occasion-banner-content">
            <span class="occasion-banner-eyebrow">Occasion Edit</span>
            <h2>The Wedding Guest &amp; Party</h2>
            <p>
                Graceful silk slip gowns and metallic gold heels curated for your celebratory invitations.
            </p>
            <a href="<?= SITE_URL ?>/shop?category=Dresses" class="btn-luxury btn-banner">Shop the Edit</a>
        </div>
    </div>

    <!-- Office & Festival Collection Banner -->
    <div class="occasion-banner banner-office">
        <div class="occasion-banner-overlay"></div>
        <div class="container occasion-banner-content">
            <span class="occasion-banner-eyebrow">Occasion Edit</span>
            <h2>Office Chic &amp; Festival</h2>
            <p>
                Fluid silk 3-piece suits, hand-embroidered organza dupattas, and tailored linen co-ord sets.
            </p>
            <a href="<?= SITE_URL ?>/shop?category=3+Piece+Suits" class="btn-luxury btn-banner">Shop Ensembles</a>
        </div>
    </div>
</section>

<!-- ==================== 7. VIDEO BANNER ==================== -->
<section class="video-banner-section">
    <video autoplay muted loop playsinline class="video-banner-media">
        <source src="https://player.vimeo.com/external/371433846.sd.mp4?s=236da2f3c054ba208d204af14a5097479edafcb2&profile_id=139&oauth2_token_id=57447761" type="video/mp4">
    </video>
    <div class="video-banner-overlay"></div>
    <div class="video-banner-content">
        <span class="editorial-label">The Atelier Film</span>
        <h2>Crafting the Future of Luxury</h2>
        <p>A cinematic glimpse behind the scissors, needles, and heritage tanning drums of Dievon.</p>
        <button onclick="alert('Playing full documentary...');" class="btn-luxury btn-banner">Play Full Film</button>
    </div>
</section>

<!-- ==================== 8. CUSTOMER REVIEWS ==================== -->
<section class="reviews-section section-space">
    <div class="container reviews-container">
        <span class="editorial-label">Dievon Testimonials</span>
        
        <div class="reviews-slider-wrap">
            <div id="reviewsSlider" class="reviews-slider">
                <div class="review-slide">
                    <div class="review-stars">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    </div>
                    <blockquote class="review-quote">
                        "Dievon has completely elevated my wardrobe. The drape of the Mulberry Silk Kurta Set is unmatched. The fabric feels impossibly soft and regal."
                    </blockquote>
                    <cite class="review-author">— Eleanor R.</cite>
                </div>
                
                <div class="review-slide">
                    <div class="review-stars">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    </div>
                    <blockquote class="review-quote">
                        "The 3-Piece Silk Suit is a work of art. The hand-embroidered detailing is exquisite, and the Organza Dupatta finishes the outfit with timeless grace."
                    </blockquote>
                    <cite class="review-author">— Sophia K.</cite>
                </div>

                <div class="review-slide">
                    <div class="review-stars">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    </div>
                    <blockquote class="review-quote">
                        "The Linen Co-Ord Set is lightweight yet deeply structured, with an incredibly comfortable luxury fit. Express delivery got it to me so fast!"
                    </blockquote>
                    <cite class="review-author">— Charlotte D.</cite>
                </div>
            </div>
        </div>

        <div class="review-dots-container">
            <span class="review-dot active" onclick="goToReview(0)"></span>
            <span class="review-dot" onclick="goToReview(1)"></span>
            <span class="review-dot" onclick="goToReview(2)"></span>
        </div>
    </div>
</section>

<!-- ==================== 9. INSTAGRAM GALLERY ==================== -->
<section class="insta-section section-space">
    <div class="container insta-container">
        <span class="editorial-label">Digital Atelier</span>
        <h2 class="section-title">Shop Our Instagram</h2>
        
        <div class="home-insta-grid" id="instaGrid">
            <?php 
            $instaImages = [
                'lookbook_1.png', 'lookbook_2.png', 'lookbook_3.png', 
                'georgette_kurti_1784414698877.jpg', 'silk_3_piece_1784414705496.jpg', 'linen_coord_1784414712638.jpg'
            ];
            foreach ($instaImages as $img):
            ?>
            <div class="insta-item">
                <img src="<?= SITE_URL ?>/uploads/<?= strpos($img, 'lookbook') !== false ? 'gallery/' : 'products/' ?><?= $img ?>" alt="Instagram high-fashion photo" loading="lazy">
                <div class="insta-overlay">
                    <div class="insta-meta">
                        <i class="fa-brands fa-instagram"></i> @dievon.official
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ==================== 10. NEWSLETTER ==================== -->
<section class="newsletter-section section-space">
    <div class="container newsletter-container">
        <span class="editorial-label">Private Membership</span>
        <h2 class="section-title">Dievon Invitations</h2>
        <p class="newsletter-subtitle">Subscribe for private lookbook access, digital invitations, and seasonal collection releases.</p>
        
        <form action="#" method="POST" onsubmit="event.preventDefault(); showToast('🎉 Subscribed!', 'Welcome to Dievon private list.');">
            <div class="newsletter-input-wrap">
                <input type="email" id="newsletterEmailInput" class="animated-input" required autocomplete="off">
                <label for="newsletterEmailInput" class="animated-label">Enter your email address</label>
            </div>
            <button type="submit" class="btn-luxury btn-newsletter">Request Membership</button>
        </form>
    </div>
</section>

<!-- ==================== 11. FASHION BLOG ==================== -->
<section class="maison-journal section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Dievon Journal</span>
            <h2 class="section-title">Latest Chronicles</h2>
        </div>
        <div class="home-blog-grid">
            <article class="blog-card">
                <div class="blog-card-img blog-img-1"></div>
                <div class="blog-card-content">
                    <span class="blog-card-tag">Style Guide &bull; July 15</span>
                    <h3 class="blog-card-title"><a href="<?= SITE_URL ?>/blog-single?id=1">Summer Silk Draping: A Study in Ethnic Elegance</a></h3>
                    <p class="blog-card-text">How to style and drape our signature Organza and Mulberry Silk Kurtis for festive gatherings.</p>
                    <a href="<?= SITE_URL ?>/blog-single?id=1" class="blog-card-link">Read Journal <i class="fa-solid fa-arrow-right-long"></i></a>
                </div>
            </article>
            <article class="blog-card">
                <div class="blog-card-img blog-img-2"></div>
                <div class="blog-card-content">
                    <span class="blog-card-tag">Craftsmanship &bull; July 10</span>
                    <h3 class="blog-card-title"><a href="<?= SITE_URL ?>/blog-single?id=2">Hand-Embroidered Zari: Preserving Heritage Craft</a></h3>
                    <p class="blog-card-text">A journey inside our craft ateliers in Jaipur &amp; Lucknow, where raw silk meets intricate hand embroidery.</p>
                    <a href="<?= SITE_URL ?>/blog-single?id=2" class="blog-card-link">Read Journal <i class="fa-solid fa-arrow-right-long"></i></a>
                </div>
            </article>
            <article class="blog-card">
                <div class="blog-card-img blog-img-3"></div>
                <div class="blog-card-content">
                    <span class="blog-card-tag">Editorial &bull; July 01</span>
                    <h3 class="blog-card-title"><a href="<?= SITE_URL ?>/blog-single?id=3">The Festive Lookbook Sneak Preview</a></h3>
                    <p class="blog-card-text">A glimpse into next season's royal velvet 3-piece ensembles and hand-woven Chanderi suits.</p>
                    <a href="<?= SITE_URL ?>/blog-single?id=3" class="blog-card-link">Read Journal <i class="fa-solid fa-arrow-right-long"></i></a>
                </div>
            </article>
        </div>
    </div>
</section>

<!-- ==================== 12. BRAND STORY ==================== -->
<section class="editorial-section section-space">
    <div class="container">
        <div class="editorial-grid">
            <div class="editorial-img">
                <img src="<?= SITE_URL ?>/uploads/gallery/lookbook_2.png" alt="Dievon Atelier Couture" loading="lazy">
            </div>
            <div class="editorial-content">
                <span class="editorial-label">Artisanal Curation</span>
                <h2 class="editorial-title">Tailored by Hand,<br>Inspired by Legacy</h2>
                <p class="editorial-text">
                    Our fabrics are sourced from organic silk collectives, handloom Chanderi weavers, and artisanal embroidery masters across India. Every thread is woven with utmost care, and every silhouette is tailored to maximize comfort while exuding regal poise. Dievon is a celebration of timeless grace.
                </p>
                <a href="<?= SITE_URL ?>/about" class="btn-luxury-outline">
                    Our Full Story
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ==================== 13. STORE BENEFITS ==================== -->
<section class="benefits-section section-space">
    <div class="container">
        <div class="home-benefits-grid">
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-truck-fast"></i></div>
                <h4 class="benefit-title">Express Delivery</h4>
                <p class="benefit-text">Complimentary worldwide express shipping</p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-rotate-left"></i></div>
                <h4 class="benefit-title">Atelier Returns</h4>
                <p class="benefit-text">Complimentary 14-day return pathway</p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-lock"></i></div>
                <h4 class="benefit-title">SSL Security</h4>
                <p class="benefit-text">256-bit encrypted checkout billing</p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-leaf"></i></div>
                <h4 class="benefit-title">Sustainable Craft</h4>
                <p class="benefit-text">Traceable materials &amp; fair trade ateliers</p>
            </div>
        </div>
    </div>
</section>



<!-- Slider & Tab scripts -->
<script>
    // ── Hero Slider Script with Touch/Drag support ──────────
    let heroIndex = 0;
    const heroSlider = document.getElementById('heroSlider');
    const heroDots = document.querySelectorAll('.slide-dot');
    const totalHeroSlides = 3;
    let autoplayInterval;

    function startAutoplay() {
        clearInterval(autoplayInterval);
        autoplayInterval = setInterval(() => {
            nextSlide();
        }, 5500);
    }

    function updateSliderPosition() {
        heroSlider.style.transform = `translateX(-${heroIndex * 33.333}%)`;
        heroDots.forEach((dot, idx) => {
            if(idx === heroIndex) {
                dot.style.background = 'white';
                dot.classList.add('active');
            } else {
                dot.style.background = 'transparent';
                dot.classList.remove('active');
            }
        });
    }

    function nextSlide() {
        heroIndex = (heroIndex + 1) % totalHeroSlides;
        updateSliderPosition();
    }

    function prevSlide() {
        heroIndex = (heroIndex - 1 + totalHeroSlides) % totalHeroSlides;
        updateSliderPosition();
    }

    function goToSlide(idx) {
        heroIndex = idx;
        updateSliderPosition();
        startAutoplay();
    }

    // Touch / Drag event handling
    let startX = 0;
    let currentX = 0;
    let isSwiping = false;

    heroSlider.addEventListener('mousedown', dragStart);
    heroSlider.addEventListener('touchstart', dragStart, { passive: true });

    function dragStart(e) {
        startX = e.type === 'touchstart' ? e.touches[0].clientX : e.clientX;
        isSwiping = true;
        
        document.addEventListener('mousemove', dragMove);
        document.addEventListener('touchmove', dragMove, { passive: true });
        document.addEventListener('mouseup', dragEnd);
        document.addEventListener('touchend', dragEnd);
    }

    function dragMove(e) {
        if (!isSwiping) return;
        currentX = e.type === 'touchmove' ? e.touches[0].clientX : e.clientX;
    }

    function dragEnd() {
        if (!isSwiping) return;
        isSwiping = false;
        
        const diffX = currentX - startX;
        if (Math.abs(diffX) > 80) { // threshold of 80px swipe
            if (diffX > 0) {
                prevSlide();
            } else {
                nextSlide();
            }
            startAutoplay();
        }
        
        document.removeEventListener('mousemove', dragMove);
        document.removeEventListener('touchmove', dragMove);
        document.removeEventListener('mouseup', dragEnd);
        document.removeEventListener('touchend', dragEnd);
    }

    startAutoplay();

    // ── New Arrivals Carousel Arrow Scroll ──────────────────
    function scrollCarousel(dir) {
        const carousel = document.getElementById('newArrivalsCarousel');
        if (!carousel) return;
        // Measure the real card pitch (card width + gap) instead of hardcoding it — the
        // hardcoded 310 no longer matched the CSS after the gap changed to 24px, so every
        // arrow click drifted ~6px out of alignment.
        const card = carousel.querySelector('.product-card-carousel');
        const gap = parseFloat(getComputedStyle(carousel).columnGap) || 0;
        const scrollAmount = card ? card.getBoundingClientRect().width + gap : 304;
        carousel.scrollBy({ left: dir * scrollAmount, behavior: 'smooth' });
    }

    // ── Tabbed Collections ───────────────────────────────────
    function toggleTabs(tab) {
        const gridBest = document.getElementById('gridBestSellers');
        const gridTrend = document.getElementById('gridTrending');
        const btnBest = document.getElementById('btnTabBest');
        const btnTrend = document.getElementById('btnTabTrend');

        if (tab === 'best') {
            gridBest.style.display = 'grid';
            gridTrend.style.display = 'none';
            btnBest.className = 'btn-luxury';
            btnBest.style.border = 'none';
            btnTrend.className = 'btn-luxury-outline';
            btnTrend.style.border = 'none';
        } else {
            gridBest.style.display = 'none';
            gridTrend.style.display = 'grid';
            btnBest.className = 'btn-luxury-outline';
            btnBest.style.border = 'none';
            btnTrend.className = 'btn-luxury';
            btnTrend.style.border = 'none';
        }
    }

    // ── Reviews Testimonials Carousel Slider ───────────────
    let reviewIndex = 0;
    const reviewsSlider = document.getElementById('reviewsSlider');
    const reviewDots = document.querySelectorAll('.review-dot');

    function updateReviewPosition() {
        reviewsSlider.style.transform = `translateX(-${reviewIndex * 33.333}%)`;
        reviewDots.forEach((dot, idx) => {
            if(idx === reviewIndex) {
                dot.style.background = 'var(--color-primary)';
                dot.style.opacity = '1';
                dot.classList.add('active');
            } else {
                dot.style.background = 'var(--text-muted)';
                dot.style.opacity = '0.5';
                dot.classList.remove('active');
            }
        });
    }

    function goToReview(idx) {
        reviewIndex = idx;
        updateReviewPosition();
    }

    setInterval(() => {
        reviewIndex = (reviewIndex + 1) % 3;
        updateReviewPosition();
    }, 6000);
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
