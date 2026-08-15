<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// The homepage answers at both / and /home. The sitemap submits /, so that is
// the canonical, every internal link now points there, and /home is redirected
// to it — otherwise the two addresses each declared themselves canonical and
// competed as duplicates of one another.
//
// Done here rather than in .htaccess because a root-relative RewriteRule target
// drops the subfolder prefix when the site is not at the document root: under
// MAMP it sent /DievonOrders/home to /. SITE_URL is correct in both places.
$canonicalUrl = SITE_URL . '/';

// pages/men.php renders this same template in menswear context. Without its own
// canonical, /men would declare itself a duplicate of / and Google would drop
// the entire menswear half from the index — the exact failure a real URL exists
// to avoid.
// Only the canonical here. The title and description are set further down,
// AFTER the seo_settings lookup — assigning them at this point achieved nothing,
// because line ~30 and then the database row both overwrite them.
if (!empty($GLOBALS['DIEVON_FORCE_SHOP_GENDER']) && $GLOBALS['DIEVON_FORCE_SHOP_GENDER'] === 'men') {
    $canonicalUrl = SITE_URL . '/men';
}

$homeReqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && preg_match('#/home/?$#', $homeReqPath)) {
    header('Location: ' . SITE_URL . '/', true, 301);
    exit;
}
// Both lines are composed from live stock rather than written down, so the
// homepage describes the shop it actually is. Previously they were fixed
// sentences naming womenswear and, worse, advertising "designer dresses" and
// "organza dupattas" that the catalogue has never carried — a claim to Google
// that nobody would think to revisit. Adding menswear now updates this by
// itself; an explicit value in Admin › SEO still overrides both (below).
$pageTitle = dievonHomeTitle($pdo);
$metaDescription = dievonHomeDescription($pdo);
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'home'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = cacheBustedUploadUrl(SITE_URL . '/uploads/gallery/' . $seoRow['og_image']); }
    }
} catch (PDOException $e) {}

// Menswear title, applied LAST so nothing above can undo it.
//
// The seo_settings row read just above belongs to the homepage, which is the
// womenswear one — its title is literally "Luxury Women's Ethnic & Contemporary
// Fashion". Left alone, /men published that as its browser tab and its search
// result headline, so the menswear address described itself as womenswear.
if (!empty($GLOBALS['DIEVON_FORCE_SHOP_GENDER']) && $GLOBALS['DIEVON_FORCE_SHOP_GENDER'] === 'men') {
    // Named, not just "Menswear". A 17-character title wastes most of the ~60
    // characters a search result gives you, and says nothing about what is
    // actually sold — the words alongside it are what someone scanning results
    // matches against.
    //
    // The categories are read from live men's stock rather than typed. The
    // hard-coded version said "shirts, trousers and tailoring" — and nothing in
    // this catalogue has ever been called tailoring, so /men advertised a
    // department that does not exist. It also matched pages/shop.php word for
    // word, which put one identical sentence on two separate indexable URLs.
    $menCats  = liveCategoryNamesForGender($pdo, 'men');
    $menList  = $menCats ? humanList($menCats) : 'shirts and trousers';

    $pageTitle       = 'Menswear — ' . trimToLength(compactTitleList($menCats ?: ['Shirts', 'Trousers']), 42);
    $metaDescription = trimToLength(
        'Shop menswear at ' . SHOP_NAME . ' — ' . $menList
        . ', with detailed size guides and easy returns across India.', 155);

    // The share image was the womenswear lookbook, because seo_settings holds one
    // og_image for the homepage and /men renders that same template. A menswear
    // link posted to WhatsApp or Instagram previewed a woman in a kurti.
    // Uses the first men's banner, and only overrides when one genuinely exists.
    try {
        $ogSt = $pdo->prepare(
            "SELECT image FROM banners
              WHERE status = 'Active' AND image <> '' AND (gender = 'men' OR gender = 'both')
           ORDER BY sort_order ASC, id ASC LIMIT 1"
        );
        $ogSt->execute();
        $ogFile = (string)$ogSt->fetchColumn();
        if ($ogFile !== '' && file_exists(__DIR__ . '/../uploads/products/' . $ogFile)) {
            $ogImage = SITE_URL . '/uploads/products/' . $ogFile;
        }
    } catch (PDOException $e) {
        // gender column not added yet — keep the existing image
    }
}

require_once __DIR__ . '/../includes/header.php';

// Every product row on this page is limited to the side of the shop being
// browsed. Without it the womenswear homepage listed men's shirts under New
// Arrivals — the switch changed the menu while the products underneath stayed
// mixed, which is worse than no switch because it looks deliberate.
//
// shopGenderSqlFilter() returns an empty string while only one audience trades,
// so all six queries below are byte-identical to before until menswear is live.
$homeGenderSql = function_exists('shopGenderSqlFilter') ? shopGenderSqlFilter() : '';

// Archived stock must not be merchandised.
//
// All six rails below tested `available = 1` and nothing else, while the shop
// grid, the search, the sitemap and the Merchant feed all pair that with an
// is_deleted guard. A row archived while still flagged available therefore
// appeared ONLY here — on the shop's most-visited page — with a price, a badge,
// a wishlist heart, Quick View and a Details link, and nowhere else on the site.
// Measured: product 249 (available = 1, is_deleted = 1) rendered in New Arrivals
// under a "New" badge while /shop, /search and every collection correctly hid it.
//
// One constant so the six queries cannot drift apart again.
$homeLiveSql = " AND (is_deleted = 0 OR is_deleted IS NULL)";

// Fetch products for New Arrivals (limit 8)
$newArrivals = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'New'{$homeGenderSql} ORDER BY id DESC LIMIT 8");
    $newArrivals = $stmt->fetchAll();
    if (count($newArrivals) < 8) {
        $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql}{$homeGenderSql} ORDER BY id DESC LIMIT 8");
        $newArrivals = $stmt->fetchAll();
    }
} catch (PDOException $e) {}

// Latest Chronicles — the three newest published articles.
//
// These three cards used to be hand-typed HTML pointing at /blog-single?id=1,
// 2 and 3, with titles typed beside the links rather than read from the posts.
// The two never agreed: the card headed "Hand-Embroidered Zari: Preserving
// Heritage Craft" opened an article called "Calfskin Sourcing: Preserving the
// Tuscan Heritage". Once those three off-brand posts were unpublished, all
// three cards became hard 404s. Reading the posts is the only arrangement in
// which a card cannot advertise an article it does not open.
$latestPosts = [];
try {
    $latestPosts = $pdo->query(
        "SELECT id, title, category, image, excerpt, published_date
           FROM blog_posts
          WHERE status = 'Published'" . blogGenderSqlFilter($pdo) . "
       ORDER BY published_date DESC, id DESC
          LIMIT 3"
    )->fetchAll();
} catch (PDOException $e) {}

// Fetch Best Sellers
$bestSellers = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'Best Seller'{$homeGenderSql} ORDER BY id ASC LIMIT 4");
    $bestSellers = $stmt->fetchAll();
    if (empty($bestSellers)) {
        $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql}{$homeGenderSql} ORDER BY price DESC LIMIT 4");
        $bestSellers = $stmt->fetchAll();
    }
} catch (PDOException $e) {}

// Fetch Trending / Hot
$trending = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'Hot'{$homeGenderSql} ORDER BY id ASC LIMIT 4");
    $trending = $stmt->fetchAll();
    if (empty($trending)) {
        $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql}{$homeGenderSql} ORDER BY price ASC LIMIT 4");
        $trending = $stmt->fetchAll();
    }
} catch (PDOException $e) {}

// Fetch dynamic hero slider banners
$heroBanners = [];
try {
    $heroBanners = $pdo->query("SELECT * FROM banners WHERE status = 'Active' ORDER BY sort_order ASC, id DESC")->fetchAll();

    // Show only the banners meant for the side being browsed. Filtered in PHP
    // rather than SQL because the gender column may not exist yet on an install
    // that has not run update_new_database.php — naming it in the query there
    // would fail the whole thing and leave the homepage with no hero at all.
    //
    // A banner with no gender recorded counts as womenswear, which is what every
    // banner on this shop was when the column was introduced.
    // Occasion Edit banners are a different placement on this same page, so they
    // must not also appear in the slider at the top. Filtered in PHP, not SQL,
    // for the same reason as gender: the column may not exist yet.
    $heroBanners = array_values(array_filter($heroBanners, function ($b) {
        return (string)($b['placement'] ?? 'hero') !== 'occasion';
    }));

    if (function_exists('shopGenderSelectorEnabled') && shopGenderSelectorEnabled()) {
        $wantGender = currentShopGender();
        $heroBanners = array_values(array_filter($heroBanners, function ($b) use ($wantGender) {
            $g = strtolower(trim((string)($b['gender'] ?? 'women')));
            if ($g === '' ) { $g = 'women'; }
            return $g === 'both' || $g === $wantGender;
        }));
    }
} catch (PDOException $e) {}

/* How many slides this page will actually render.
 *
 * The three branches below decide it: uploaded banners if there are any, one
 * honest menswear slide when menswear is trading without banners of its own,
 * otherwise the three default womenswear slides. The controls have to be
 * counted from the SAME decision, and they were not — the dots hard-coded a
 * fallback of 3 and the arrows were printed unconditionally.
 *
 * Measured on /men: one slide, three dots and two arrows. Two of those dots
 * pointed at nothing and both arrows cycled a single slide back onto itself, so
 * the shopper clicked a control and the page appeared frozen.
 */
$heroSlideCount = !empty($heroBanners)
    ? count($heroBanners)
    : ((function_exists('currentShopGender') && currentShopGender() === 'men') ? 1 : 3);
?>


<!-- ==================== 1. LUXURY HERO SLIDER ==================== -->

<section class="hero-slider-section">
    <div class="slider-container" id="heroSlider">
        <?php if (!empty($heroBanners)): ?>
            <?php foreach ($heroBanners as $idx => $banner): 
                // One resolver, shared with the admin screen — see
                // bannerImageLocation(). This block and admin/banners.php each
                // had their own copy searching products then gallery, and a
                // third was needed the moment banners got their own folder.
                $imgFile = $banner['image'] ?? '';
                $imgLoc  = bannerImageLocation($imgFile);
                if ($imgLoc) {
                    $imgDir = $imgLoc['dir'];
                    $imgUrl = SITE_URL . '/uploads/' . $imgDir . '/' . $imgLoc['file'];
                } else {
                    $imgDir = 'gallery';
                    $imgUrl = SITE_URL . '/uploads/gallery/lookbook_' . (($idx % 3) + 1) . '.png';
                }
                $imgBase = basename(parse_url($imgUrl, PHP_URL_PATH) ?: $imgFile);
                $imgWebp = webpUrlIfExists($imgDir, $imgBase);
            ?>
                <?php
                // A real <img>, not a CSS background.
                //
                // Two things were wrong with the background. It served the ORIGINAL
                // file and never looked for the .webp twin the uploader had already
                // made — the three live banners are 2,317KB, 848KB and 1,748KB as PNG
                // against 273KB and 259KB as WebP, so the homepage was pulling roughly
                // 4.9MB of hero image on a phone. And a background cannot begin
                // downloading until the stylesheet has parsed, while this is the
                // largest thing on the page and the one Google times (LCP).
                //
                // <picture> serves WebP where the browser takes it and the original
                // everywhere else. The first slide carries fetchpriority=high because
                // it is on screen immediately.
                //
                // Every slide is loading="eager", NOT lazy. The slider is a filmstrip:
                // slides 2+ sit outside the viewport horizontally and are brought in by
                // a CSS transform, which is not a scroll — so a browser applying lazy
                // loading strictly never registers them as approaching the viewport and
                // never fetches them. The result is a first slide that works and blank
                // panels behind it, and it differs by browser because each implements
                // the lazy heuristic differently: it showed in Chrome while Safari,
                // holding an older cached stylesheet, looked fine.
                //
                // Lazy loading is for images DOWN the page. Nothing in the hero is
                // below the fold — it is the first thing on screen.
                //
                // z-index 0 sits under .slide-overlay (1) and .slide-content (2), so
                // the tint and the text still layer exactly as before.
                ?>
                <div class="slide slide-<?= ($idx % 3) + 1 ?> slide-has-img">
                    <picture>
                        <?php if ($imgWebp): ?><source srcset="<?= htmlspecialchars(cacheBustedUploadUrl($imgWebp)) ?>" type="image/webp"><?php endif; ?>
                        <img src="<?= htmlspecialchars(cacheBustedUploadUrl($imgUrl)) ?>"
                             alt="<?= htmlspecialchars($banner['title'] ?? 'Dievon collection') ?>"
                             class="slide-img"
                             <?= $idx === 0 ? 'fetchpriority="high" decoding="async"' : 'decoding="async"' ?>
                             loading="eager">
                    </picture>
                    <div class="slide-overlay"></div>
                    <div class="slide-content">
                        <span class="slide-eyebrow">Edition <?= $idx + 1 ?></span>
                        <?php
                        // One H1 per page, on the first slide only. This loop used to
                        // print <h1> for every banner, so a shop with three banners
                        // published three competing "main headings" — and which one a
                        // crawler saw first depended on sort_order, not on meaning.
                        // The hand-written fallback slides below already do it this way;
                        // the database-driven path had simply drifted from them.
                        //
                        // Purely a markup change: .slide-content h1 and .slide-content h2
                        // are declared together in style.css with identical !important
                        // rules, so every slide still looks exactly as it did.
                        ?>
                        <?php if ($idx === 0): ?>
                            <h1><?= htmlspecialchars($banner['title']) ?></h1>
                        <?php else: ?>
                            <h2><?= htmlspecialchars($banner['title']) ?></h2>
                        <?php endif; ?>
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
        <?php elseif (function_exists('currentShopGender') && currentShopGender() === 'men'): ?>
            <?php /* Menswear with no banners of its own yet.
                     The womenswear default slides below cannot stand in for it: their
                     first slide carries the page's <h1>, so /men was publishing "The
                     Silk Kurtis" as its primary heading — the one line Google reads as
                     what the page is about, on the menswear address. One honest slide
                     naming the section beats three advertising the wrong half of the
                     shop, and it disappears the moment a men's banner is uploaded. */ ?>
            <div class="slide slide-1" style="background-image: url('<?= lookbookUrl(2) ?>') !important; background-size: cover !important; background-position: center !important;">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <span class="slide-eyebrow">Menswear</span>
                    <h1>Dievon Men</h1>
                    <p>Shirts, trousers and tailoring, cut clean and made to be worn often.</p>
                    <a href="<?= SITE_URL ?>/shop" class="btn-luxury btn-hero-explore">Explore Menswear</a>
                </div>
            </div>
        <?php else: ?>
            <!-- Default Curated Slides -->
            <div class="slide slide-1">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <span class="slide-eyebrow">Volume I</span>
                    <h1>Luxury Indian Womenswear — Hand-Finished Fashion</h1>
                    <p>Fluid silhouettes draped in pure heavy mulberry silk. Designed for grace in motion.</p>
                    <a href="<?= htmlspecialchars(categoryUrlByName($pdo, 'Kurtis')) ?>" class="btn-luxury btn-hero-explore">Explore Edition</a>
                </div>
            </div>
            <div class="slide slide-2">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <span class="slide-eyebrow">Volume II</span>
                    <h2>3-Piece Suits</h2>
                    <p>Elegant tailored brocades and soft raw silks. Perfect for premium celebrations.</p>
                    <a href="<?= htmlspecialchars(categoryUrlByName($pdo, '3 Piece Suits')) ?>" class="btn-luxury btn-hero-explore">View Collection</a>
                </div>
            </div>
            <div class="slide slide-3">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <span class="slide-eyebrow">Volume III</span>
                    <h2>Coord Sets</h2>
                    <p>Luxurious satin wide-legs and tailored organic linens. Designed for refined modern loungewear.</p>
                    <a href="<?= htmlspecialchars(categoryUrlByName($pdo, 'Coord Sets')) ?>" class="btn-luxury btn-hero-explore">Discover Sets</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php // Controls only exist when there is somewhere to go. A single slide
          // needs no arrows and no dots — and zero slides needs neither markup
          // nor a row of dots floating over an empty band. ?>
    <?php if ($heroSlideCount > 1): ?>
    <button onclick="prevSlide()" class="hero-arrow hero-arrow-left" aria-label="Previous slide"><i class="fa-solid fa-chevron-left"></i></button>
    <button onclick="nextSlide()" class="hero-arrow hero-arrow-right" aria-label="Next slide"><i class="fa-solid fa-chevron-right"></i></button>

    <div class="hero-dots-container">
        <?php for ($i = 0; $i < $heroSlideCount; $i++): ?>
            <span class="slide-dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $i ?>)"></span>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
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

            // Two filters this list was missing, both of which the header, footer
            // and sitemap already applied — so this grid was the one place on the
            // site still advertising collections that lead nowhere.
            //
            //  1. Empty collections. $categoryHasStock is built in includes/header.php,
            //     which has already run by this point, and counts a category's whole
            //     subtree so a parent whose stock all sits in its children still shows.
            //  2. The other side of the shop. Without it a menswear tile sat between
            //     Kurtis and 3 Piece Suits, which reads as a mistake because it is one:
            //     an audience is not a garment type.
            $allCategories = array_values(array_filter($allCategories, function ($c) use ($categoryHasStock) {
                $id = (int)$c['id'];
                if (!empty($categoryHasStock) && empty($categoryHasStock[$id])) { return false; }
                if (function_exists('shopGenderSelectorEnabled') && shopGenderSelectorEnabled()) {
                    return categoryMatchesShopGender($id);
                }
                return true;
            }));

            foreach($allCategories as $cat):
                // The collection's OWN image first, then a product photo, then the
                // placeholder.
                //
                // This used to go straight to "SELECT image FROM products WHERE
                // category = :cat LIMIT 1", which meant three things:
                //
                //  1. categories.image was ignored entirely. An owner could set a
                //     proper collection photograph in the Categories screen and the
                //     home page would keep showing a product shot instead — there
                //     was no way to control how a collection looked here.
                //  2. LIMIT 1 with no ORDER BY lets MySQL return whichever row it
                //     likes, so adding an unrelated product could silently swap the
                //     tile's photograph with nothing having been changed on purpose.
                //  3. It matched on the legacy `category` name string only, so a
                //     product filed by category_id with a blank name contributed
                //     nothing and a well-stocked collection fell back to the
                //     placeholder.
                $catImgSrc = SITE_URL . '/assets/images/placeholder.png';
                $ownImage  = trim((string)($cat['image'] ?? ''));

                if ($ownImage !== '') {
                    // Same folder the category editor and the OG tags use.
                    $catImgSrc = SITE_URL . '/uploads/gallery/' . rawurlencode($ownImage);
                } else {
                    // Newest product with a photo, matched by id OR name so both the
                    // current and the legacy filing work. Stable ordering, so the
                    // tile only changes when the catalogue genuinely changes.
                    $stmtProdImg = $pdo->prepare(
                        "SELECT image FROM products
                          WHERE (category_id = :cid OR category = :cat)
                            AND image IS NOT NULL AND image <> ''
                            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                          ORDER BY id DESC LIMIT 1"
                    );
                    $stmtProdImg->execute(['cid' => (int)$cat['id'], 'cat' => $cat['name']]);
                    $prodImg = $stmtProdImg->fetchColumn();
                    if ($prodImg) {
                        $catImgSrc = SITE_URL . '/uploads/products/' . rawurlencode((string)$prodImg);
                    }
                }
            ?>
            <a href="<?= htmlspecialchars(categoryUrlByName($pdo, $cat['name'])) ?>" class="category-card zoom-box">
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

        <div id="newArrivalsCarousel" class="new-arrivals-carousel hide-scrollbar js-owl-rail">
            <?php // Shared partial — see includes/product_card.php. Each of these grids
                  // carried its own copy of the card markup, so none served the WebP image,
                  // offered Compare, or used the product's own alt text. ?>
            <?php /* One pair of queries for every card on the page, rather than a
                     pair per tile — see productHoverImage(). */
                  productHoverImagePrime($homeGrids = array_merge($newArrivals ?? [], $bestSellers ?? [], $trending ?? []));
                  productPriceRangePrime($homeGrids); ?>
            <?php foreach ($newArrivals as $p): ?>
                <?php $card = $p; $cardExtraClass = 'product-card-carousel'; $cardFallbackBadge = 'New'; $cardCompare = false; include __DIR__ . '/../includes/product_card.php'; ?>
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
            <?php // Shared partial — see includes/product_card.php. Each of these grids
                  // carried its own copy of the card markup, so none served the WebP image,
                  // offered Compare, or used the product's own alt text. ?>
            <?php foreach ($bestSellers as $p): ?>
                <?php $card = $p; $cardCompare = false; include __DIR__ . '/../includes/product_card.php'; ?>
            <?php endforeach; ?>
        </div>

        <div id="gridTrending" class="home-best-sellers-grid is-hidden">
            <?php // Shared partial — see includes/product_card.php. Each of these grids
                  // carried its own copy of the card markup, so none served the WebP image,
                  // offered Compare, or used the product's own alt text. ?>
            <?php foreach ($trending as $p): ?>
                <?php $card = $p; $cardCompare = false; include __DIR__ . '/../includes/product_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php
// ==================== 5. SHOP BY OCCASION ====================
// These four pills pointed at Dresses, Outerwear and "Jewelry & Accessories" —
// none of which exist as categories. Every pill landed the shopper on an empty
// grid. The `occasion` column already holds exactly this idea, so the pills are
// built from occasions that genuinely have stock.
//
// Worked out BEFORE the <section> opens, not inside it. Previously the section
// wrapper and the "Shop by Occasion" heading were printed unconditionally and
// only the pills were guarded — which was invisible while womenswear always had
// occasions, and became a titled, completely empty band on /men the moment a
// side had no stock to count.
$occasions = [];
try {
    // Scoped to the side being browsed like every other product query on this
    // page. Without it the menswear homepage offered "Wedding" and "Diwali"
    // pills counted from womenswear, and clicking one returned a grid the
    // shopper had just been told was menswear.
    $occasions = $pdo->query(
        "SELECT occasion, COUNT(*) n FROM products
          WHERE occasion IS NOT NULL AND occasion <> ''
            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
            {$homeGenderSql}
          GROUP BY occasion ORDER BY n DESC LIMIT 12"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// Fall back to this side's real categories rather than printing nothing at all.
// $menuCategories is already gender-filtered in includes/header.php, so a men's
// visitor is offered Shirts and Trousers, never Kurtis.
if (!$occasions) {
    foreach (array_slice($menuCategories ?? [], 0, 12) as $mc) { $occasions[] = $mc['name']; }
}

/* One representative photograph per tile.
   ────────────────────────────────────────────────────────────────────────────
   Nothing new to upload and nothing to maintain: each tile borrows the cover
   shot of the NEWEST live product in that occasion, so the section restyles
   itself as the catalogue changes and can never point at a photo of something
   that has been taken down.

   Matched on occasion OR category in one pass, because $occasions above may
   hold either — it falls back to category names when no occasion has stock,
   and the link below already switches between ?occasions= and ?category= for
   exactly that reason. One query for all four rather than four queries.

   Reuses $homeGenderSql verbatim: shopGenderSqlFilter() was called with no
   alias, so it emits bare column names and this single-table query is the
   shape it expects. A men's visitor therefore gets men's photographs. */
$occasionCovers = [];
if ($occasions) {
    try {
        $occPh   = implode(',', array_fill(0, count($occasions), '?'));
        $occCovSt = $pdo->prepare(
            "SELECT occasion, category, image FROM products
              WHERE image IS NOT NULL AND image <> ''
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                AND (occasion IN ({$occPh}) OR category IN ({$occPh}))
                {$homeGenderSql}
              ORDER BY id DESC"
        );
        $occCovSt->execute(array_merge($occasions, $occasions));
        foreach ($occCovSt->fetchAll(PDO::FETCH_ASSOC) as $occRow) {
            // Newest first, so the first row to claim a name keeps it.
            foreach (['occasion', 'category'] as $occCol) {
                $occName = (string)($occRow[$occCol] ?? '');
                if ($occName !== '' && !isset($occasionCovers[$occName])
                    && in_array($occName, $occasions, true)) {
                    $occasionCovers[$occName] = (string)$occRow['image'];
                }
            }
        }
    } catch (PDOException $e) {}
}
?>
<?php if ($occasions): ?>
<section class="occasion-bar-section section-space">
    <div class="container">
        <?php /* The house heading block, same as every other section on this page.
                 This was the only section using a lone 10px label as its title, with
                 no h2 and no gold rule, so it read as an unfinished strip sitting
                 between two properly titled sections rather than as a section of its
                 own. Using the shared block also means the mobile heading spacing
                 already tuned for .section-title-wrapper applies here for free. */ ?>
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Curated Occasions</span>
            <h2 class="section-title">Shop by Occasion</h2>
        </div>
    </div>

    <?php /* The strip sits OUTSIDE .container on purpose — that is what lets it run
             edge to edge while the heading above stays aligned with the rest of the
             page. Scrolled by finger or trackpad only: no arrows, no dots. The
             affordance is the tile clipped by the right edge of the screen, which
             says "there is more" without adding any furniture to look at. */ ?>
    <div class="occasion-slider js-owl-rail" data-owl-autowidth="1">
            <?php foreach ($occasions as $occ):
                $occCover = $occasionCovers[$occ] ?? '';
                $occWebp  = ($occCover !== '' && function_exists('webpUrlIfExists'))
                          ? webpUrlIfExists('products', $occCover) : '';
            ?>
                <a href="<?= SITE_URL ?>/shop?<?= in_array($occ, array_column($menuCategories ?? [], 'name'), true) ? 'category' : 'occasions' ?>=<?= urlencode($occ) ?>"
                   class="occasion-tile">
                    <?php if ($occCover !== ''): ?>
                        <?php /* alt="" on purpose. The tile's accessible name is the label
                                 underneath it, so describing the photograph as well would
                                 make a screen reader announce every tile twice. */ ?>
                        <picture>
                            <?php if ($occWebp): ?><source srcset="<?= htmlspecialchars($occWebp) ?>" type="image/webp"><?php endif; ?>
                            <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($occCover) ?>"
                                 alt="" loading="lazy" decoding="async" class="occasion-tile-img">
                        </picture>
                    <?php else: ?>
                        <?php /* An occasion with no photographed product still gets a tile —
                                 a gap in the row would look broken, and the link works. */ ?>
                        <span class="occasion-tile-img occasion-tile-img--blank"></span>
                    <?php endif; ?>
                    <span class="occasion-tile-label"><?= htmlspecialchars($occ) ?></span>
                </a>
            <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ==================== 6. FESTIVAL / WEDDING / OFFICE / PARTY COLLECTIONS ==================== -->
<?php
// ── Occasion edits ──────────────────────────────────────────────────────────
// Two wide panels, always — the pair this page has always had.
//
// Their background photographs used to be fixed in the stylesheet
// (.banner-wedding and .banner-office pointed at lookbook_1.png and
// lookbook_2.png), so changing either one meant editing CSS, and showing
// menswear something different was impossible.
//
// They now take the first two banners of whichever side is being browsed. That
// means uploading men's banners changes the men's panels too, with nothing extra
// to configure — the same two images that lead the slider carry these panels.
// The original lookbook photographs stay as the fallback for a side that has no
// banners of its own yet, so the section is never blank.
$occasionImages = [];
try {
    $oiSt = $pdo->prepare(
        "SELECT image FROM banners
          WHERE status = 'Active' AND image <> ''
            AND (gender = :g OR gender = 'both')
       ORDER BY sort_order ASC, id ASC LIMIT 2"
    );
    $oiSt->execute([':g' => function_exists('currentShopGender') ? currentShopGender() : 'women']);
    $occasionImages = $oiSt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // gender column not added yet — fall back to the lookbook images below
}

/**
 * Background for occasion panel $slot (0 or 1).
 *
 * Resolves the filename exactly as the hero slider above does — uploads/products
 * first, then uploads/gallery, then the lookbook fallback. That duplication is
 * deliberate and necessary: the slider checks whether the file actually exists,
 * so a banner row pointing at a missing image quietly falls back there. Building
 * the URL here without the same check meant the two could show different
 * pictures for the same banner — the slider a lookbook photo, this panel a
 * broken image — which is the opposite of "panel 1 matches slide 1".
 */
$occasionBg = function (int $slot) use ($occasionImages) {
    // The WebP twin wherever one exists.
    //
    // These panels reuse the SAME two photographs as the hero slider. Once the
    // slider began serving WebP and this did not, the browser fetched BOTH
    // encodings of one picture — 273KB of WebP for the slide and 2,317KB of PNG
    // for the panel below it. Matching the format means the file is already in
    // cache by the time the panel needs it, so these two panels cost nothing at
    // all to display.
    $pick = function (string $dir, string $file) {
        $webp = webpUrlIfExists($dir, $file);
        return cacheBustedUploadUrl($webp ?: SITE_URL . '/uploads/' . $dir . '/' . $file);
    };

    // Ask the SHARED resolver where the file is — do not search by hand.
    //
    // This looked in uploads/products/ and uploads/gallery/ only. Banners moved
    // to their own uploads/banners/ folder (admin/banners.php:120) so that
    // clearing out product photography could no longer take the homepage hero
    // with it — and this copy was never told. Every banner uploaded since then
    // is invisible to it, so the panel silently drops through to the lookbook
    // photograph below.
    //
    // That is exactly the symptom: the hero slider shows the real banner,
    // because it asks bannerImageLocation(), while the Occasion Edit panel
    // underneath shows a lookbook shot. Two resolvers, one of them out of date.
    // Now there is one, and it checks banners, products and gallery in turn.
    $file = (string)($occasionImages[$slot] ?? '');
    $loc  = function_exists('bannerImageLocation') ? bannerImageLocation($file) : null;
    if ($loc !== null) {
        return $pick($loc['dir'], $loc['file']);
    }

    // Same lookbook numbering the slider uses for slot N.
    return $pick('gallery', 'lookbook_' . (($slot % 3) + 1) . '.png');
};

/* These two panels' PHOTOGRAPHS were already chosen per audience — the query
   feeding $occasionImages scopes by currentShopGender() — but the words and the
   buttons underneath them were hard-coded womenswear. So /men showed a men's
   picture above copy about silk slip gowns, gold heels and organza dupattas,
   and its most prominent button walked a male shopper into 3 Piece Suits, a
   women's collection. That is exactly the failure pages/men.php exists to
   prevent, and /men is in the sitemap.

   The CTA on menswear points at /shop, matching the men's hero slide above:
   the shop reads the gender context, so /shop is already "shop menswear" here,
   and it cannot go stale the way a named category link can. */
$occIsMen = function_exists('currentShopGender') && currentShopGender() === 'men';
?>
<section class="occasion-banners-section">
    <!-- Wedding Collection Banner -->
    <div class="occasion-banner" style="--occ-bg: url('<?= htmlspecialchars($occasionBg(0)) ?>');">
        <div class="occasion-banner-overlay"></div>
        <div class="container occasion-banner-content">
            <span class="occasion-banner-eyebrow">Occasion Edit</span>
            <?php if ($occIsMen): ?>
                <h2>The Wedding Guest &amp; Celebration</h2>
                <p>
                    Festive kurtas, clean tailoring and quiet detail, cut for the season's invitations.
                </p>
            <?php else: ?>
                <h2>The Wedding Guest &amp; Party</h2>
                <p>
                    Graceful silk slip gowns and metallic gold heels curated for your celebratory invitations.
                </p>
            <?php endif; ?>
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury btn-banner">Shop the Edit</a>
        </div>
    </div>

    <!-- Office & Festival Collection Banner -->
    <div class="occasion-banner" style="--occ-bg: url('<?= htmlspecialchars($occasionBg(1)) ?>'); border-top: 1px solid var(--border-light);">
        <div class="occasion-banner-overlay"></div>
        <div class="container occasion-banner-content">
            <span class="occasion-banner-eyebrow">Occasion Edit</span>
            <?php if ($occIsMen): ?>
                <h2>Workwear &amp; Festival</h2>
                <p>
                    Crisp cotton shirts, straight-cut trousers and kurtas that carry from the desk to the evening.
                </p>
                <a href="<?= SITE_URL ?>/shop" class="btn-luxury btn-banner">Shop Menswear</a>
            <?php else: ?>
                <h2>Office Chic &amp; Festival</h2>
                <p>
                    Fluid silk 3-piece suits, hand-embroidered organza dupattas, and tailored linen co-ord sets.
                </p>
                <a href="<?= htmlspecialchars(categoryUrlByName($pdo, '3 Piece Suits')) ?>" class="btn-luxury btn-banner">Shop Ensembles</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php // Section 7, the Atelier Film banner, was removed at the owner's request.
      //
      // It played a stock clip hosted on Vimeo's CDN, and that link had expired:
      // the address answered 403 Forbidden, so every visitor — on the live site
      // as well as locally — saw an empty grey band where a film should have
      // been. The "Play Full Film" lightbox pointed at the same dead address.
      //
      // Removed rather than repointed because the footage was never Dievon's.
      // If a real atelier film is shot later this is the place for it, but a
      // borrowed clip that no longer loads is worth less than the space. ?>

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


<!-- ==================== 10. NEWSLETTER ==================== -->
<section class="newsletter-section section-space">
    <div class="container newsletter-container">
        <span class="editorial-label">Private Membership</span>
        <h2 class="section-title">Dievon Invitations</h2>
        <p class="newsletter-subtitle">Subscribe for private lookbook access, digital invitations, and seasonal collection releases.</p>
        
        <?php // This form used to be a fake: action="#" and a showToast that
              // pretended to subscribe. The footer's form records real signups in
              // newsletter_subscribers via actions/newsletter_action.php — this one
              // now posts to the same endpoint, so a visitor signing up here is
              // actually captured. ?> 
        <form method="POST" onsubmit="return submitHomeNewsletter(event, this);">
            <div class="newsletter-input-wrap">
                <input type="email" id="newsletterEmailInput" name="email" class="animated-input" required autocomplete="off">
                <label for="newsletterEmailInput" class="animated-label">Enter your email address</label>
            </div>
            <button type="submit" class="btn-luxury btn-newsletter">Request Membership</button>
            <div class="newsletter-home-msg" id="newsletterHomeMsg" style="display:none; margin-top:12px; font-size:13px; text-align:center;"></div>
        </form>
    </div>
</section>

<!-- ==================== 11. FASHION BLOG ==================== -->
<?php if (!empty($latestPosts)): ?>
<section class="maison-journal section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Dievon Journal</span>
            <h2 class="section-title">Latest Chronicles</h2>
        </div>
        <div class="home-blog-grid">
            <?php foreach ($latestPosts as $post): ?>
                <?php
                    $postUrl = SITE_URL . '/blog-single?id=' . (int)$post['id'];

                    // Same resolution order as pages/blog.php: the post's own
                    // picture when the file is really there, the shared lookbook
                    // otherwise — never a src that 404s.
                    $postImg  = trim((string)($post['image'] ?? ''));
                    // lookbook_* filenames belong to uploads/gallery/ (the Lookbook
                    // admin screen writes there); never resolve them against the
                    // products folder where stale duplicates could shadow the
                    // updated photograph.
                    // One resolver, shared with /blog, the article page and the admin
                    // list. Lookbook filenames are deliberately not honoured — blog and
                    // lookbook are separate now. See blogImageUrl().
                    $imgUrl = blogImageUrl($postImg);
                    $webpUrl  = preg_replace('/\.[^.]+$/', '.webp', $imgUrl);
                    $webpFile = str_replace(SITE_URL . '/', __DIR__ . '/../', $webpUrl);
                    $webpUrl  = cacheBustedUploadUrl($webpUrl);
                    $imgUrl   = cacheBustedUploadUrl($imgUrl);

                    $postDate = !empty($post['published_date'])
                        ? date('F j', strtotime($post['published_date']))
                        : '';
                ?>
                <article class="blog-card">
                    <div class="blog-card-media">
                        <a href="<?= $postUrl ?>">
                            <picture>
                                <?php if (webpSourceIsFresh($imgUrl, $webpFile)): ?><source srcset="<?= htmlspecialchars($webpUrl) ?>" type="image/webp"><?php endif; ?>
                                <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($post['title']) ?>" class="blog-card-img" loading="lazy" decoding="async">
                            </picture>
                        </a>
                    </div>
                    <div class="blog-card-content">
                        <span class="blog-card-tag">
                            <?= htmlspecialchars($post['category'] ?: 'Journal') ?><?= $postDate !== '' ? ' &bull; ' . htmlspecialchars($postDate) : '' ?>
                        </span>
                        <h3 class="blog-card-title"><a href="<?= $postUrl ?>"><?= htmlspecialchars($post['title']) ?></a></h3>
                        <p class="blog-card-text"><?= htmlspecialchars(trimToLength((string)$post['excerpt'], 120)) ?></p>
                        <a href="<?= $postUrl ?>" class="blog-card-link">Read Journal <i class="fa-solid fa-arrow-right-long"></i></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ==================== 12. BRAND STORY ==================== -->
<section class="editorial-section section-space">
    <div class="container">
        <div class="editorial-grid">
            <div class="editorial-img">
                <img src="<?= lookbookUrl(2) ?>" alt="Dievon couture fashion editorial photograph" loading="lazy">
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
                <h3 class="benefit-title">Express Delivery</h3>
                <?php // Said "Complimentary worldwide express shipping" — untrue in both
                      // halves: delivery is free only above the threshold, and international
                      // is a flat fee that never qualifies for it. Reads the same Store
                      // Setting the cart, the policy page and checkout all use. ?>
                <?php // Threshold AND symbol from the same country row — see
                      // freeShippingMinForCountry(). This printed the India figure under
                      // whatever symbol the shopper was browsing in. ?>
                <p class="benefit-text">Free delivery over <?= formatPrice(freeShippingMinForCountry($pdo)) ?></p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-rotate-left"></i></div>
                <h3 class="benefit-title">Atelier Returns</h3>
                <p class="benefit-text">Complimentary 14-day return pathway</p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-lock"></i></div>
                <h3 class="benefit-title">SSL Security</h3>
                <p class="benefit-text">256-bit encrypted checkout billing</p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-leaf"></i></div>
                <h3 class="benefit-title">Sustainable Craft</h3>
                <p class="benefit-text">Traceable materials &amp; fair trade ateliers</p>
            </div>
        </div>
    </div>
</section>



<!-- Slider & Tab scripts -->
<script>
    /* ── Hero slider — driven by OwlCarousel ──────────────────────────────
       The design is unchanged: same markup, same .slide blocks, same dots, same
       entrance animation on .is-active. Only the engine underneath is different.

       It used to be hand-written — a translateX on a flex row, plus mousedown /
       mousemove / mouseup handlers doing swipe detection by hand. That worked
       until it did not: a press on a slide's <img> started the browser's own
       image drag, which swallowed the mousemove events and left the slider dead
       until you clicked elsewhere. It looked random, and it showed up mostly on
       Windows where press-and-drag is how people swipe a carousel.

       Owl handles pointer, touch and mouse dragging itself, on every browser it
       supports, and it is not our code to get wrong again.

       Inside DOMContentLoaded because jQuery and Owl are deferred in the head —
       deferred scripts finish before this event fires, so both are guaranteed
       ready here. */
    document.addEventListener('DOMContentLoaded', function () {
        var $hero = window.jQuery ? jQuery('#heroSlider') : null;

        // No banners, or the library failed to load: leave the markup alone.
        // The first slide is already visible and readable without any JS, so a
        // missing library costs the rotation, never the content.
        if (!$hero || !$hero.length || typeof jQuery.fn.owlCarousel !== 'function') { return; }

        /* Owl's stylesheet is scoped to .owl-carousel, which Owl itself never
           adds — so without this line none of it applies, including
           `.owl-carousel.owl-drag .owl-item { touch-action: pan-y }`. That rule
           is what hands vertical panning to the phone and horizontal swiping to
           the slider; missing, a sideways swipe scrolled the page instead.

           Added here rather than in the markup because Owl's CSS also hides
           .owl-carousel until .owl-loaded exists — in the HTML that would hide
           the hero until JS ran, and permanently if the library failed. */
        $hero.addClass('owl-carousel');

        var $dots = jQuery('.slide-dot');

        function markActive(idx) {
            // The existing CSS animates .slide.is-active .slide-content children.
            // Owl clones slides for looping, so mark by index within the ORIGINAL
            // items — .owl-item.active carries the currently shown one.
            jQuery('#heroSlider .slide').removeClass('is-active');
            jQuery('#heroSlider .owl-item.active .slide').addClass('is-active');

            $dots.each(function (i) {
                var on = (i === idx);
                jQuery(this).toggleClass('active', on)
                            .css('background', on ? 'white' : 'transparent');
            });
        }

        $hero.owlCarousel({
            items: 1,
            loop: jQuery('#heroSlider .slide').length > 1,
            nav: false,               // the site draws its own arrows
            dots: false,              // and its own dots
            autoplay: true,
            autoplayTimeout: 5500,
            autoplayHoverPause: true,
            smartSpeed: 800,          // matches the old 0.8s transition
            mouseDrag: true,
            touchDrag: true,
            pullDrag: true,
            autoHeight: false,
            animateOut: false
        });

        markActive(0);
        $hero.on('changed.owl.carousel', function (e) {
            // Normalise to an index within the real items, not the clones.
            var count = e.item.count, idx = e.item.index - (e.relatedTarget.clones().length / 2);
            idx = ((idx % count) + count) % count;
            // Wait a tick so .owl-item.active reflects the new position.
            setTimeout(function () { markActive(idx); }, 0);
        });

        /* The three controls in the markup call these by name, so they keep
           working untouched — the buttons and dots did not change. */
        window.nextSlide = function () { $hero.trigger('next.owl.carousel'); };
        window.prevSlide = function () { $hero.trigger('prev.owl.carousel'); };
        window.goToSlide = function (i) { $hero.trigger('to.owl.carousel', [i, 800]); };
    });

    // The Atelier Film lightbox handlers were removed with the section they
    // served (openFilmModal / filmModalEsc / closeFilmModal). Nothing calls them
    // any more, and #filmModal no longer exists, so every one of them would have
    // returned on its first line forever.

    // ── Homepage newsletter — real signup (same endpoint as the footer) ──
    function submitHomeNewsletter(e, form) {
        e.preventDefault();
        const msg = document.getElementById('newsletterHomeMsg');
        const btn = form.querySelector('button[type="submit"]');
        const originalLabel = btn.textContent;
        btn.disabled = true;
        const formData = new FormData(form);
        fetch(window.SITE_URL + '/actions/newsletter_action.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                msg.style.display = 'block';
                msg.style.color = data.success ? '#10b981' : '#ef4444';
                msg.textContent = data.message;
                if (data.success) form.reset();
                btn.disabled = false;
                btn.textContent = originalLabel;
            })
            .catch(() => {
                msg.style.display = 'block';
                msg.style.color = '#ef4444';
                msg.textContent = 'Something went wrong. Please try again.';
                btn.disabled = false;
                btn.textContent = originalLabel;
            });
        return false;
    }

    // ── New Arrivals Carousel Arrow Scroll ──────────────────
    function scrollCarousel(dir) {
        const carousel = document.getElementById('newArrivalsCarousel');
        if (!carousel) return;
        // Measure the real card pitch (card width + gap) instead of hardcoding it — the
        // hardcoded 310 no longer matched the CSS after the gap changed to 24px, so every
        // arrow click drifted ~6px out of alignment.
        /* Owl drives the rail now — see the shared initialiser in
           includes/footer.php. owlRailStep() returns false if Owl is not
           running (library blocked, JS error), and the original scroll-snap
           behaviour below still works in that case, so an arrow click is never
           a dead click. */
        if (typeof owlRailStep === 'function' && owlRailStep(carousel, dir)) { return; }

        const card = carousel.querySelector('.product-card-carousel');
        const gap = parseFloat(getComputedStyle(carousel).columnGap) || 0;
        const scrollAmount = card ? card.getBoundingClientRect().width + gap : 304;
        carousel.scrollBy({ left: dir * scrollAmount, behavior: 'smooth' });
    }

    // ── Tabbed Collections ───────────────────────────────────
    function toggleTabs(tab) {
        const gridBest  = document.getElementById('gridBestSellers');
        const gridTrend = document.getElementById('gridTrending');
        const btnBest   = document.getElementById('btnTabBest');
        const btnTrend  = document.getElementById('btnTabTrend');
        const showBest  = (tab === 'best');

        // Was setting btnX.style.border = 'none' here. Both button classes carry a
        // 1.5px border, so that inline rule stripped 6px off the pair — on a phone the
        // labels were wrapping to two lines until the first click gave them those 6px
        // back, which is why the buttons "fixed themselves" after one tap. Toggling
        // classes only keeps the box identical before and after.
        gridBest.classList.toggle('is-hidden', !showBest);
        gridTrend.classList.toggle('is-hidden', showBest);

        btnBest.classList.toggle('btn-luxury', showBest);
        btnBest.classList.toggle('btn-luxury-outline', !showBest);
        btnTrend.classList.toggle('btn-luxury', !showBest);
        btnTrend.classList.toggle('btn-luxury-outline', showBest);

        btnBest.setAttribute('aria-pressed', showBest ? 'true' : 'false');
        btnTrend.setAttribute('aria-pressed', showBest ? 'false' : 'true');
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
