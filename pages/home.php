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

/* The homepage's product picks — one garment, one section.
   ────────────────────────────────────────────────────────────────────────────
   Each of the three sections used to fall back to "any live product" when its
   badge found nothing: newest 8 for New Arrivals, dearest 4 for Best Sellers,
   cheapest 4 for Trending. Across a full catalogue those three sets barely
   overlap. Across the handful of products a shop actually has on opening day
   they returned THE SAME garments, so scrolling the homepage meant meeting the
   same few pieces three times under three different headings — which reads as a
   shop with nothing in it, the exact opposite of what the sections are for.

   So the badge picks are claimed first, in the order the sections appear, since
   those are choices somebody made deliberately. Then each section tops up from
   whatever no other section has taken. A section left with nothing is not drawn
   at all — see the guards further down. One fewer band beats a titled empty one.

   As the catalogue grows the sections fill up on their own and this stops having
   anything to do. */
$homeSeen = [];

/** Take up to $limit rows nobody has used yet, and mark them used. */
$homeClaim = function (array $rows, int $limit) use (&$homeSeen): array {
    $kept = [];
    if ($limit <= 0) { return $kept; }
    foreach ($rows as $row) {
        if (count($kept) >= $limit) { break; }
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0 || isset($homeSeen[$id])) { continue; }
        $homeSeen[$id] = true;
        $kept[] = $row;
    }
    return $kept;
};

// Everything this page may draw on, fetched once instead of three times.
$homePool = [];
try {
    $homePool = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql}{$homeGenderSql} ORDER BY id DESC LIMIT 40")->fetchAll();
} catch (PDOException $e) {}

$newArrivals = [];
try {
    $newArrivals = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'New'{$homeGenderSql} ORDER BY id DESC LIMIT 8")->fetchAll();
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

$bestSellers = [];
$trending    = [];
try {
    $bestSellers = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'Best Seller'{$homeGenderSql} ORDER BY id ASC LIMIT 4")->fetchAll();
    $trending    = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'Hot'{$homeGenderSql} ORDER BY id ASC LIMIT 4")->fetchAll();
} catch (PDOException $e) {}

/* Claim the deliberate picks in page order, then top each section up from what
   is left — newest for arrivals, dearest for best sellers, keenest for trending,
   which is the ordering each section had before. Nothing is drawn twice. */
$newArrivals = $homeClaim($newArrivals, 8);
$bestSellers = $homeClaim($bestSellers, 4);
$trending    = $homeClaim($trending,    4);

$byNewest = $homePool;                                          // already id DESC
$byDearest = $homePool;
usort($byDearest, fn($a, $b) => (float)($b['price'] ?? 0) <=> (float)($a['price'] ?? 0));
$byKeenest = $homePool;
usort($byKeenest, fn($a, $b) => (float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0));

$newArrivals = array_merge($newArrivals, $homeClaim($byNewest,  8 - count($newArrivals)));
$bestSellers = array_merge($bestSellers, $homeClaim($byDearest, 4 - count($bestSellers)));
$trending    = array_merge($trending,    $homeClaim($byKeenest, 4 - count($trending)));

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
                // z-index 0 sits under .slide-panel (2) and .slide-content, so
                // the scrim and the text still layer exactly as before.
                ?>
                <div class="slide slide-<?= ($idx % 3) + 1 ?> slide-has-img">
                    <?php /* The words lie ON the photograph, in its own negative space.
                             ────────────────────────────────────────────────────────
                             This was briefly a split — an ivory panel beside the
                             picture — because legibility here is measurable rather
                             than a matter of taste: of the two banners live on the
                             shop today, the text zone of one averages luminance 78
                             and the other 196, so no single ink colour reads on both.
                             A panel made legibility independent of the upload.

                             The overlay is what was asked for, and it is the better
                             of the two when the scrim is dark rather than light. A
                             light wash only lifts dark type off a banner that is
                             already darker than the ink; a dark wash carries white
                             type over anything, however bright the upload. That is
                             the same bet dievon.com makes, and .slide-panel now uses
                             live's own gradient stops — see style.css. */ ?>
                    <div class="slide-media">
                        <picture>
                            <?php if ($imgWebp): ?><source srcset="<?= htmlspecialchars(cacheBustedUploadUrl($imgWebp)) ?>" type="image/webp"><?php endif; ?>
                            <img src="<?= htmlspecialchars(cacheBustedUploadUrl($imgUrl)) ?>"
                                 alt="<?= htmlspecialchars($banner['title'] ?? 'Dievon collection') ?>"
                                 class="slide-img"
                                 <?= $idx === 0 ? 'fetchpriority="high" decoding="async"' : 'decoding="async"' ?>
                                 loading="eager">
                        </picture>
                    </div>
                    <div class="slide-panel">
                    <div class="slide-content">
                        <span class="slide-eyebrow">New Season</span>
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
                        <?php /* Two ways in, as the brief asks. The primary keeps the
                                 address the owner typed into the banner in admin; the
                                 secondary is always the full shop, so a visitor who does
                                 not want that particular edition still has somewhere to
                                 go. Neither is new behaviour for the admin — the link
                                 field means exactly what it meant before. */ ?>
                        <div class="slide-actions">
                            <a href="<?= htmlspecialchars($bannerLink) ?>" class="btn-hero-primary">Shop Now</a>
                            <a href="<?= SITE_URL ?>/shop" class="btn-hero-ghost">Explore Collection</a>
                        </div>
                    </div>
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
            <div class="slide slide-1">
                <div class="slide-media" style="background-image: url('<?= lookbookUrl(2) ?>');"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h1>Dievon Men</h1>
                    <p>Shirts, trousers and tailoring, cut clean and made to be worn often.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Default Curated Slides -->
            <?php /* The brief's own copy, on the slide that carries the page's h1.
                     This only renders when the shop has no banners of its own — on a
                     stocked shop the admin slides above replace it entirely — so it is
                     the empty state, not the usual one. Worth knowing that "Timeless
                     Elegance" is a weaker h1 for search than the line it replaces
                     ("Luxury Indian Womenswear — Hand-Finished Fashion"); it is the
                     copy that was asked for, and it is only ever seen by a shop with
                     nothing uploaded. */ ?>
            <div class="slide slide-1">
                <div class="slide-media"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h1>Timeless Elegance</h1>
                    <p>Graceful silhouettes. Exquisite details. Crafted for every moment.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= htmlspecialchars(categoryUrlByName($pdo, 'Kurtis')) ?>" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
                </div>
            </div>
            <div class="slide slide-2">
                <div class="slide-media"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h2>3-Piece Suits</h2>
                    <p>Elegant tailored brocades and soft raw silks. Perfect for premium celebrations.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= htmlspecialchars(categoryUrlByName($pdo, '3 Piece Suits')) ?>" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
                </div>
            </div>
            <div class="slide slide-3">
                <div class="slide-media"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h2>Coord Sets</h2>
                    <p>Luxurious satin wide-legs and tailored organic linens. Designed for refined modern loungewear.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= htmlspecialchars(categoryUrlByName($pdo, 'Coord Sets')) ?>" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
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

            /* Six tiles, and a button for the rest.
               ────────────────────────────────────────────────────────────────
               Six is the mosaic — one large tile, three beside it, two beneath.
               It was briefly uncapped, continuing into aligned rows of three,
               and that works, but it makes the section as tall as the catalogue
               is wide: twelve collections turned this into four rows and pushed
               everything below it off the first two screens.

               So the composition IS the limit. Six fill it exactly, and a
               seventh collection is reached through the button underneath
               rather than by making the section taller.

               Counted BEFORE the slice, because the button has to say how many
               there are in total; asking after the cut would always say six. */
            $catTotalCount = count($allCategories);
            $allCategories = array_slice($allCategories, 0, 6);
            $catShownCount = count($allCategories);

?>
        <?php /* data-count is what makes this work with the two collections the
                 shop has today as well as the six the design was drawn for.
                 A mosaic is an arrangement of a KNOWN number of tiles; hand it
                 two and the reference layout leaves a tall feature beside one
                 small tile and two thirds of the row empty. The CSS keyed to
                 this attribute picks a composition that is whole at every count
                 from one upward — see assets/css/style.css.

                 The list has to be built BEFORE this div, not inside it: the
                 attribute is written as the tag is emitted, so a count taken
                 from a variable the loop below fills is a count of nothing. */ ?>
        <div class="home-categories-grid" data-count="<?= (int)$catShownCount ?>">
            <?php
            foreach($allCategories as $catIndex => $cat):
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
            <?php /* The first collection takes the large tile. It is first because
                     sort_order says so — the same order the header menu and the
                     footer use — so the shop decides what leads here by ordering
                     collections in admin, not by anything written in this file.

                     width/height are the intrinsic ratio, not a size: the tile
                     controls the box and object-fit crops into it. They are here
                     so the browser reserves the space before the photograph
                     arrives, which is what stops the section jumping as the lazy
                     images land. */ ?>
            <a href="<?= htmlspecialchars(categoryUrlByName($pdo, $cat['name'])) ?>"
               class="category-card zoom-box<?= $catIndex === 0 ? ' is-feature' : '' ?>">
                <img class="zoom-img" src="<?= $catImgSrc ?>"
                     alt="<?= htmlspecialchars($cat['name']) ?> collection"
                     width="800" height="1000" loading="lazy" decoding="async">
                <div class="category-card-overlay">
                    <div>
                        <h3><?= htmlspecialchars($cat['name']) ?></h3>
                        <span class="category-card-link">View Collection <i class="fa-solid fa-arrow-right-long"></i></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php /* Only when collections were genuinely held back. A permanent
                 "View All" under a row that already shows everything promises
                 more than the shop has, which is the same fault as a Read more
                 that reveals nothing. */ ?>
        <?php /* The rail arrows were here. This section scrolled sideways on a
                 phone and needed them; the mosaic does not scroll in any
                 direction, so every collection is on screen and there is nothing
                 left for an arrow to move. scrollCatRail() and its resize
                 listener are removed further down this file with them. */ ?>

        <?php if ($catTotalCount > count($allCategories)): ?>
        <div class="home-categories-more">
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury-outline">
                View All <?= (int)$catTotalCount ?> Collections
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ==================== 3. NEW ARRIVALS ==================== -->
<?php /* Nothing to show, nothing drawn. The heading, the carousel arrows and the
         section's own vertical space used to print whatever happened — so a shop
         with no products served a tall empty band titled "New Arrivals", which is
         the same mistake Shop by Occasion below already had fixed. */ ?>
<?php if (!empty($newArrivals)): ?>
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

<?php endif; ?>

<!-- ==================== 4. TRENDING / BEST SELLERS ==================== -->
<?php
/* Two tabs are only worth having when both of them lead somewhere. With one list
   empty the pair became a button that opened a blank grid, so the toggle is
   replaced by a plain heading naming whichever half has stock — and when neither
   has any, the section does not appear. */
$homeHasBoth = !empty($bestSellers) && !empty($trending);
?>
<?php if (!empty($bestSellers) || !empty($trending)): ?>
<section class="trending-section section-space">
    <div class="container">
        <div class="tab-toggle-container">
            <?php if ($homeHasBoth): ?>
            <div class="tab-toggle-wrapper">
                <button id="btnTabBest" onclick="toggleTabs('best')" class="btn-luxury">Best Sellers</button>
                <button id="btnTabTrend" onclick="toggleTabs('trend')" class="btn-luxury-outline">Trending Now</button>
            </div>
            <?php else: ?>
            <h2 class="section-title"><?= !empty($bestSellers) ? 'Best Sellers' : 'Trending Now' ?></h2>
            <?php endif; ?>
        </div>

        <div id="gridBestSellers" class="home-best-sellers-grid<?= empty($bestSellers) ? ' is-hidden' : '' ?>">
            <?php // Shared partial — see includes/product_card.php. Each of these grids
                  // carried its own copy of the card markup, so none served the WebP image,
                  // offered Compare, or used the product's own alt text. ?>
            <?php foreach ($bestSellers as $p): ?>
                <?php $card = $p; $cardCompare = false; include __DIR__ . '/../includes/product_card.php'; ?>
            <?php endforeach; ?>
        </div>

        <?php /* Hidden only when Best Sellers is the one being shown. If that list is
                 empty this grid is what the section is FOR, so it opens visible. */ ?>
        <div id="gridTrending" class="home-best-sellers-grid<?= !empty($bestSellers) ? ' is-hidden' : '' ?>">
            <?php // Shared partial — see includes/product_card.php. Each of these grids
                  // carried its own copy of the card markup, so none served the WebP image,
                  // offered Compare, or used the product's own alt text. ?>
            <?php foreach ($trending as $p): ?>
                <?php $card = $p; $cardCompare = false; include __DIR__ . '/../includes/product_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

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
        "SELECT occasion FROM products
          WHERE occasion IS NOT NULL AND occasion <> ''
            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
            {$homeGenderSql}"
    )->fetchAll(PDO::FETCH_COLUMN);

    /* Counted per OCCASION, not per stored string.
       ────────────────────────────────────────────────────────────────────────
       This was GROUP BY occasion, which groups by the whole field — and the
       field is a list. On the live shop that produced nine tiles for nine
       products: "Casual / Everyday / Day Out / Travel" beside "Casual /
       Workwear / Everyday / Day Out", each one a sentence, each leading to a
       single garment. A section meant to offer four or five ways in was
       offering the catalogue back one product at a time.

       Splitting first gives the handful of real occasions the shop actually
       sells for — Casual, Everyday, Workwear, Festive — each covering every
       garment that names it. The commonest come first, which is what the
       LIMIT was always trying to do. */
    $occCounts = [];
    foreach ($occasions as $occRaw) {
        foreach (dievonSplitOccasions($occRaw) as $occOne) {
            $occCounts[$occOne] = ($occCounts[$occOne] ?? 0) + 1;
        }
    }
    arsort($occCounts);
    $occasions = array_slice(array_keys($occCounts), 0, 12);
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
        $occPh = implode(',', array_fill(0, count($occasions), '?'));
        /* occasion is a LIST, so IN() cannot find "Casual" inside
           "Casual / Everyday / Day Out". One LIKE per tile against the
           normalised, slash-wrapped field does — and without dragging in
           "Smart Casual" the way a bare %Casual% would. */
        $occLike = implode(' OR ', array_fill(0, count($occasions),
                    OCCASION_MATCH_SQL . " LIKE ?"));
        $occCovSt = $pdo->prepare(
            "SELECT occasion, category, image FROM products
              WHERE image IS NOT NULL AND image <> ''
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                AND (({$occLike}) OR category IN ({$occPh}))
                {$homeGenderSql}
              ORDER BY id DESC"
        );
        $occCovSt->execute(array_merge(
            array_map(fn($o) => '%/' . $o . '/%', $occasions),
            $occasions
        ));
        /* One garment, one tile — as far as the catalogue allows.
           ────────────────────────────────────────────────────────────────────
           This gave a product's photograph to EVERY occasion it named, and an
           occasion field is a list: one kurti tagged "Casual / Everyday / Day
           Out / Travel" filled four tiles with the same picture. On the live
           shop five tiles showed two photographs between them, so a row meant to
           show the range of the collection showed the same dress over and over.

           Two passes fix it. The first lets each product furnish ONE tile and
           each photograph appear once, which spreads the row across as many
           different garments as there are. The second fills whatever is still
           empty, reusing pictures only where there is genuinely nothing else —
           a tile with a repeated photograph still beats a blank one.

           Rows arrive newest first, so the newest stock claims tiles first. */
        $occRows     = $occCovSt->fetchAll(PDO::FETCH_ASSOC);
        $occUsedImgs = [];

        $occClaimsOf = static function (array $row) use ($occasions): array {
            // A product's occasion field is a list, so it can supply the
            // photograph for any occasion named inside it. Category matches
            // whole, because a category name is a single value.
            $claims = array_merge(
                dievonSplitOccasions((string)($row['occasion'] ?? '')),
                [(string)($row['category'] ?? '')]
            );
            return array_values(array_filter($claims, static fn($c) =>
                $c !== '' && in_array($c, $occasions, true)));
        };

        foreach ($occRows as $occRow) {                 // pass 1 — spread them out
            $img = (string)($occRow['image'] ?? '');
            if ($img === '' || isset($occUsedImgs[$img])) { continue; }
            foreach ($occClaimsOf($occRow) as $occName) {
                if (!isset($occasionCovers[$occName])) {
                    $occasionCovers[$occName] = $img;
                    $occUsedImgs[$img] = true;
                    break;                              // this garment fills ONE tile
                }
            }
        }

        /* An occasion that cannot have a photograph of its own is not shown.
           ────────────────────────────────────────────────────────────────────
           Spreading the pictures was not enough on its own. Only one garment in
           the shop is tagged Festive, Traditional, Wedding Guest and
           Celebration — so those four tiles could only ever show that same
           dress, four times in a row, whatever order they were assigned in.

           So the row is built from the occasions the catalogue can genuinely
           illustrate: twelve tiles showing seven photographs becomes seven
           tiles, each a different garment. A visitor reads it as a range; the
           old row read as a shop with two dresses in it.

           Nothing is lost permanently — the dropped occasions still work as
           filters, they just do not get a tile until a second garment is tagged
           with them. Add stock and they return by themselves. */
        $occasions = array_values(array_filter(
            $occasions,
            static fn($occName) => isset($occasionCovers[$occName])
        ));
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
            <?php /* Shared partial — see includes/blog_card.php. The rail and the
                     /blog listing had drifted into two components with the same
                     name and two sets of CSS; this is the one card. h3 because
                     these sit under this section's own h2, and a trimmed excerpt
                     because the rail is three across rather than a full-width
                     list. */ ?>
            <?php foreach ($latestPosts as $post): ?>
                <?php
                $blogPost           = $post;
                $blogCardTitleTag   = 'h3';
                $blogCardExcerpt    = 120;
                $blogCardCta        = 'Read Journal';
                $blogCardDateFormat = 'F j';
                $blogCardReveal     = false;   // the rail has no scroll-entrance today
                include __DIR__ . '/../includes/blog_card.php';
                ?>
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

            /* The class only — the appearance belongs to the stylesheet.
               This also wrote background inline, white or transparent, which
               beat any rule CSS could offer: the redesigned dots are a 55% white
               dot that stretches into a short bar when active, and the inline
               "transparent" made every inactive one invisible. An inline style
               is the one thing a stylesheet cannot answer, so the two can never
               both be right; the class is the honest half. */
            $dots.each(function (i) {
                jQuery(this).toggleClass('active', i === idx);
            });
        }

        // Same reason as the rails in includes/footer.php: a slide is a link
        // around a photograph, and the browser's own drag-and-drop eats the
        // mouse events Owl needs. Firefox ignores the CSS user-drag property,
        // so the attribute has to say it as well.
        $hero.find('a, img').attr('draggable', 'false');

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

        /* Owl clones the first and last slides to make the loop seamless, and a
           clone of slide one is a clone of its <h1> — so the rendered page carried
           TWO identical <h1>s even though the HTML has one. The markup already
           takes care to print the heading on the first slide only (see the note
           in the slide loop above); the duplicate arrives after Owl runs, which is
           why it could not be fixed in PHP.

           Demoted rather than removed: the clone is what a shopper sees for the
           moment the loop wraps, so its text has to stay. .slide-content h1 and
           .slide-content h2 are declared together in style.css with identical
           rules, so this is invisible on screen — it only changes what a crawler
           and a screen reader's heading list are handed.

           Runs on initialized AND on refresh, because Owl rebuilds its clones
           whenever the carousel is refreshed or the viewport crosses a
           breakpoint, and a rebuilt clone is a fresh <h1> again. */
        function demoteClonedHeadings() {
            jQuery('#heroSlider .owl-item.cloned').each(function () {
                var clone = this;

                /* The <h1> is only on slide one, so this used to fix slide one and
                   leave slides two, three and four duplicated: their headings are
                   <h2>, which the h1-only selector never saw. Measured on the live
                   homepage — "Grace, Woven in Every Detail" was correctly hidden
                   while "Floral Kurta Sets", "Timeless Lehengas" and "The Art of
                   Effortless Style" each appeared twice in the heading list. */
                jQuery(clone).find('h1').each(function () {
                    var h1 = this;
                    var h2 = document.createElement('h2');
                    h2.className = h1.className;
                    h2.innerHTML = h1.innerHTML;
                    h1.parentNode.replaceChild(h2, h1);
                });

                /* Hide the WHOLE clone, not just its heading. A clone duplicates
                   the slide's link and button too, so a keyboard user tabbing
                   through the hero met the same "Shop Now" two or three times with
                   no way to tell them apart. aria-hidden takes the subtree out of
                   the accessibility tree; tabindex -1 takes it out of the tab
                   order, which aria-hidden alone does not do.

                   Purely additive to what a shopper sees: the clone is what shows
                   for the moment the loop wraps, so it stays on screen. */
                clone.setAttribute('aria-hidden', 'true');
                jQuery(clone).find('a, button, input, [tabindex]').attr('tabindex', '-1');
            });
        }
        $hero.on('initialized.owl.carousel refreshed.owl.carousel', demoteClonedHeadings);
        demoteClonedHeadings();   // initialized has usually already fired by here

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

    /* syncCatRailArrows() and scrollCatRail() were here, driving the arrows on
       the collections rail, and the note above them explained why they had to
       live in this scope. The rail is gone — the collections are a mosaic that
       does not scroll — so both functions had nothing left to move, and the
       load and resize listeners they registered went with them. */

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
