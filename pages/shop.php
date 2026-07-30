<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// Handle AJAX load more request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 6;
    $offset = ($page - 1) * $limit;
    
    $categoriesList = isset($_GET['categories']) && $_GET['categories'] !== '' ? explode(',', $_GET['categories']) : [];
    $colorsList = isset($_GET['colors']) && $_GET['colors'] !== '' ? explode(',', $_GET['colors']) : [];
    $brandsList = isset($_GET['brands']) && $_GET['brands'] !== '' ? explode(',', $_GET['brands']) : [];
    $fabricsList = isset($_GET['fabrics']) && $_GET['fabrics'] !== '' ? explode(',', $_GET['fabrics']) : [];
    $sleevesList = isset($_GET['sleeves']) && $_GET['sleeves'] !== '' ? explode(',', $_GET['sleeves']) : [];
    $necksList = isset($_GET['necks']) && $_GET['necks'] !== '' ? explode(',', $_GET['necks']) : [];
    $patternsList = isset($_GET['patterns']) && $_GET['patterns'] !== '' ? explode(',', $_GET['patterns']) : [];
    $occasionsList = isset($_GET['occasions']) && $_GET['occasions'] !== '' ? explode(',', $_GET['occasions']) : [];
    $minPrice = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
    $maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
    $sortBy = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : '';
    
    $sql = "SELECT * FROM products WHERE available = 1";
    $params = [];
    
    if (!empty($categoriesList)) {
        $catClauses = [];
        foreach ($categoriesList as $cVal) {
            $catClauses[] = "category = ?";
            $params[] = $cVal;
            if (stripos($cVal, 'kurti') !== false || stripos($cVal, 'kurta') !== false) {
                $catClauses[] = "category LIKE '%Kurti%'";
                $catClauses[] = "category LIKE '%Kurta%'";
            }
            if (stripos($cVal, 'suit') !== false) {
                $catClauses[] = "category LIKE '%Suit%'";
            }
            if (stripos($cVal, 'coord') !== false || stripos($cVal, 'co-ord') !== false) {
                $catClauses[] = "category LIKE '%Coord%'";
                $catClauses[] = "category LIKE '%Co-Ord%'";
            }
        }
        $sql .= " AND (" . implode(" OR ", array_unique($catClauses)) . ")";
    }
    
    if (!empty($colorsList)) {
        $in = str_repeat('?,', count($colorsList) - 1) . '?';
        $sql .= " AND (color IN ($in) OR color_way IN ($in))";
        $params = array_merge($params, $colorsList, $colorsList);
    }

    if (!empty($brandsList)) {
        $in = str_repeat('?,', count($brandsList) - 1) . '?';
        $sql .= " AND brand IN ($in)";
        $params = array_merge($params, $brandsList);
    }

    if (!empty($fabricsList)) {
        $in = str_repeat('?,', count($fabricsList) - 1) . '?';
        $sql .= " AND fabric IN ($in)";
        $params = array_merge($params, $fabricsList);
    }

    if (!empty($sleevesList)) {
        $in = str_repeat('?,', count($sleevesList) - 1) . '?';
        $sql .= " AND sleeve IN ($in)";
        $params = array_merge($params, $sleevesList);
    }

    if (!empty($necksList)) {
        $in = str_repeat('?,', count($necksList) - 1) . '?';
        $sql .= " AND neck IN ($in)";
        $params = array_merge($params, $necksList);
    }

    if (!empty($patternsList)) {
        $in = str_repeat('?,', count($patternsList) - 1) . '?';
        $sql .= " AND pattern IN ($in)";
        $params = array_merge($params, $patternsList);
    }

    if (!empty($occasionsList)) {
        $in = str_repeat('?,', count($occasionsList) - 1) . '?';
        $sql .= " AND occasion IN ($in)";
        $params = array_merge($params, $occasionsList);
    }
    
    if ($minPrice > 0) {
        $sql .= " AND price >= ?";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $sql .= " AND price <= ?";
        $params[] = $maxPrice;
    }

    $saleOnly = !empty($_GET['sale']);
    if ($saleOnly) {
        $sql .= " AND mrp_price > 0 AND mrp_price > price";
    }

    $activeSearch = isset($_GET['search']) ? trim($_GET['search']) : (isset($_GET['q']) ? trim($_GET['q']) : '');
    if ($activeSearch !== '') {
        $sql .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ? OR fabric LIKE ? OR color LIKE ?)";
        $sTerm = '%' . $activeSearch . '%';
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
    }
    
    switch ($sortBy) {
        case 'price_low':
            $sql .= " ORDER BY price ASC";
            break;
        case 'price_high':
            $sql .= " ORDER BY price DESC";
            break;
        case 'newest':
            $sql .= " ORDER BY id DESC";
            break;
        default:
            $sql .= " ORDER BY id DESC";
            break;
    }
    
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($i + 1, $val, $type);
        }
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $variantsByProduct = [];
        if (!empty($products)) {
            try {
                $pids = array_column($products, 'id');
                $pidsPlaceholder = implode(',', array_map('intval', $pids));
                if (!empty($pidsPlaceholder)) {
                    $vRows = $pdo->query("SELECT * FROM product_variants WHERE product_id IN ($pidsPlaceholder) AND available = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
                    foreach ($vRows as $v) {
                        $variantsByProduct[$v['product_id']][] = $v;
                    }
                }
            } catch (PDOException $exV) {}
        }
        
        $formattedProducts = [];
        foreach ($products as $p) {
            $p['variants'] = isset($variantsByProduct[$p['id']]) ? $variantsByProduct[$p['id']] : [];
            $p['formatted_price'] = formatPrice($p['price']);
            $pMrp = (float)($p['mrp_price'] ?? 0);
            $pHasDiscount = $pMrp > (float)$p['price'];
            $p['has_discount'] = $pHasDiscount;
            $p['discount_percent'] = $pHasDiscount ? (int)round((($pMrp - (float)$p['price']) / $pMrp) * 100) : 0;
            $p['formatted_mrp'] = $pHasDiscount ? formatPrice($pMrp) : '';
            $formattedProducts[] = $p;
        }
        
        @ob_clean();
        echo json_encode([
            'success' => true,
            'products' => $formattedProducts,
            'has_more' => count($products) === $limit
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (PDOException $e) {
        @ob_clean();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

$pageTitle = "Collections";
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'shop'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = SITE_URL . '/uploads/gallery/' . $seoRow['og_image']; }
    }
} catch (PDOException $e) {}

// Canonical: keep single-dimension collection URLs (category, sale) as their own
// indexable page; collapse everything else (search, sort, price range, multi-filter
// combinations) to the base /shop URL so Google doesn't see endless near-duplicates.
if (!empty($_GET['category']) && empty($_GET['search']) && empty($_GET['q'])) {
    $canonicalUrl = SITE_URL . '/shop?category=' . urlencode(trim($_GET['category']));
} elseif (!empty($_GET['sale']) && empty($_GET['category']) && empty($_GET['search']) && empty($_GET['q'])) {
    $canonicalUrl = SITE_URL . '/shop?sale=1';
} else {
    $canonicalUrl = SITE_URL . '/shop';
}

// Internal search results are low-value, near-infinite-combination pages — keep them out of the index.
if (!empty($_GET['search']) || !empty($_GET['q'])) {
    $noindex = true;
}

require_once __DIR__ . '/../includes/header.php';

// Fetch distinct filter attributes
$categories = [];
$brands     = [];
$colors     = [];
$sizes      = [];
$fabrics    = [];
$sleeves    = [];
$necks      = [];
$patterns   = [];
$occasions  = [];

try {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
    
    // DIEVON sells only its own products
    $brands = ['DIEVON'];

    // DB Colors + distinct product colors normalized with proper capitalization
    $dbColors = [];
    try { $dbColors = $pdo->query("SELECT name FROM product_attributes WHERE attr_type='color' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) {}
    $prodColors = $pdo->query("SELECT DISTINCT color FROM products WHERE color != '' AND color IS NOT NULL ORDER BY color ASC")->fetchAll(PDO::FETCH_COLUMN);
    $rawColors = array_merge($dbColors, $prodColors);
    $cleanColors = array_map(function($c) { return ucwords(strtolower(trim($c))); }, $rawColors);
    $colors = array_values(array_unique(array_filter($cleanColors)));

    // DB Sizes
    try { $sizes = $pdo->query("SELECT name FROM product_attributes WHERE attr_type='size' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) {}

    $rawFabrics = $pdo->query("SELECT DISTINCT fabric FROM products WHERE fabric != '' AND fabric IS NOT NULL ORDER BY fabric ASC")->fetchAll(PDO::FETCH_COLUMN);
    $fabrics = array_values(array_unique(array_filter(array_map(function($f) { return ucwords(strtolower(trim($f))); }, $rawFabrics))));

    $rawSleeves = $pdo->query("SELECT DISTINCT sleeve FROM products WHERE sleeve != '' AND sleeve IS NOT NULL ORDER BY sleeve ASC")->fetchAll(PDO::FETCH_COLUMN);
    $sleeves = array_values(array_unique(array_filter(array_map(function($s) { return ucwords(strtolower(trim($s))); }, $rawSleeves))));

    $rawNecks = $pdo->query("SELECT DISTINCT neck FROM products WHERE neck != '' AND neck IS NOT NULL ORDER BY neck ASC")->fetchAll(PDO::FETCH_COLUMN);
    $necks = array_values(array_unique(array_filter(array_map(function($n) { return ucwords(strtolower(trim($n))); }, $rawNecks))));

    $rawPatterns = $pdo->query("SELECT DISTINCT pattern FROM products WHERE pattern != '' AND pattern IS NOT NULL ORDER BY pattern ASC")->fetchAll(PDO::FETCH_COLUMN);
    $patterns = array_values(array_unique(array_filter(array_map(function($p) { return ucwords(strtolower(trim($p))); }, $rawPatterns))));

    $rawOccasions = $pdo->query("SELECT DISTINCT occasion FROM products WHERE occasion != '' AND occasion IS NOT NULL ORDER BY occasion ASC")->fetchAll(PDO::FETCH_COLUMN);
    $occasions = array_values(array_unique(array_filter(array_map(function($o) { return ucwords(strtolower(trim($o))); }, $rawOccasions))));

    $activeSearch = isset($_GET['search']) ? trim($_GET['search']) : (isset($_GET['q']) ? trim($_GET['q']) : '');
} catch (PDOException $e) {}
?>

<?php if (!empty($_GET['category']) && empty($activeSearch)): ?>
<!-- ══ Breadcrumb Structured Data ══════════════════════════ -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": <?= json_encode(SITE_URL . '/') ?> },
    { "@type": "ListItem", "position": 2, "name": "Shop", "item": <?= json_encode(SITE_URL . '/shop') ?> },
    { "@type": "ListItem", "position": 3, "name": <?= json_encode(trim($_GET['category'])) ?>, "item": <?= json_encode($canonicalUrl) ?> }
  ]
}
</script>
<?php endif; ?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image shop-hero section-mb-sm" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_2.png')">
    <div class="container">
        <?php if ($activeSearch !== ''): ?>
            <span class="luxury-hero-eyebrow">Search Results</span>
            <h1>Search: "<?= htmlspecialchars($activeSearch) ?>"</h1>
            <p>Showing curated results matching your query. <a href="shop" style="color: var(--color-accent); text-decoration: underline; font-weight: 600; margin-left: 8px;">Clear Search ✕</a></p>
        <?php elseif (!empty($_GET['sale'])): ?>
            <span class="luxury-hero-eyebrow">Limited Time</span>
            <h1>Sale</h1>
            <p>Discounted atelier pieces, while stocks last.</p>
        <?php else: ?>
            <span class="luxury-hero-eyebrow">Explore Collections</span>
            <h1>Dievon Curated Catalog</h1>
            <p>Explore luxury garments crafted for every elegant mood.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ══ Shop Catalog Layout ════════════════════════════════ -->
<section class="shop-catalog section-space">
    <div class="container shop-layout">
        
        <!-- Left Sidebar Filters -->
        <aside class="shop-sidebar">
            <h3 class="sidebar-filters-title">Filters</h3>
            
            <div class="filter-accordion">
                <div class="filter-header open" onclick="toggleFilter(this)">
                    Categories <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body open" id="catFilterGroup">
                    <?php foreach ($categories as $cat): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="category_filter[]" value="<?= htmlspecialchars($cat['name']) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($cat['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-accordion">
                <div class="filter-header open" onclick="toggleFilter(this)">
                    Price Range <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body open">
                    <div class="price-range-container">
                        <div class="price-range-inputs">
                            <div class="price-input-box">
                                <span>£</span>
                                <input type="number" id="minPrice" placeholder="Min" onchange="applyFilters()">
                            </div>
                            <span>-</span>
                            <div class="price-input-box">
                                <span>£</span>
                                <input type="number" id="maxPrice" placeholder="Max" onchange="applyFilters()">
                            </div>
                        </div>
                        <button type="button" class="btn-apply-price" onclick="applyFilters()">Apply Filter</button>
                    </div>
                </div>
            </div>


            <?php if (!empty($brands)): ?>
            <div class="filter-accordion">
                <div class="filter-header" onclick="toggleFilter(this)">
                    Brand <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body">
                    <?php foreach ($brands as $b): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="brand_filter[]" value="<?= htmlspecialchars($b) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($b) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($colors)): ?>
            <div class="filter-accordion">
                <div class="filter-header" onclick="toggleFilter(this)">
                    Color <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body">
                    <?php foreach ($colors as $c): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="color_filter[]" value="<?= htmlspecialchars($c) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($c) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($fabrics)): ?>
            <div class="filter-accordion">
                <div class="filter-header" onclick="toggleFilter(this)">
                    Fabric <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body">
                    <?php foreach ($fabrics as $f): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="fabric_filter[]" value="<?= htmlspecialchars($f) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($f) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($sleeves)): ?>
            <div class="filter-accordion">
                <div class="filter-header" onclick="toggleFilter(this)">
                    Sleeve <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body">
                    <?php foreach ($sleeves as $s): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="sleeve_filter[]" value="<?= htmlspecialchars($s) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($s) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($necks)): ?>
            <div class="filter-accordion">
                <div class="filter-header" onclick="toggleFilter(this)">
                    Neck <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body">
                    <?php foreach ($necks as $n): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="neck_filter[]" value="<?= htmlspecialchars($n) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($n) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($patterns)): ?>
            <div class="filter-accordion">
                <div class="filter-header" onclick="toggleFilter(this)">
                    Pattern <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body">
                    <?php foreach ($patterns as $p): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="pattern_filter[]" value="<?= htmlspecialchars($p) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($p) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($occasions)): ?>
            <div class="filter-accordion">
                <div class="filter-header" onclick="toggleFilter(this)">
                    Occasion <i class="fa-solid fa-angle-down filter-toggle-icon"></i>
                </div>
                <div class="filter-body">
                    <?php foreach ($occasions as $o): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="occasion_filter[]" value="<?= htmlspecialchars($o) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($o) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>


            <button class="btn-luxury-outline btn-reset-filters" onclick="resetFilters()">Clear Filters</button>
        </aside>

        <!-- Main Product Area -->
        <div class="shop-main">
            <!-- Sorting Bar -->
            <div class="filters-sorting-bar">
                <div class="view-controls">
                    <button class="view-btn active" id="btnGrid" onclick="setGridView('grid')" title="Grid View"><i class="fa-solid fa-border-all"></i></button>
                    <button class="view-btn" id="btnList" onclick="setGridView('list')" title="List View"><i class="fa-solid fa-list"></i></button>
                </div>
                <div class="sorting-controls">
                    <label for="sortBySelect">Sort By:</label>
                    <select id="sortBySelect" onchange="applyFilters()">
                        <option value="default">Default</option>
                        <option value="price_asc">Price: Low to High</option>
                        <option value="price_desc">Price: High to Low</option>
                        <option value="name_asc">Name: A-Z</option>
                    </select>
                </div>
            </div>

            <!-- Product Grid -->
            <div class="products-grid" id="shopProductsGrid">
                <?php 
                $initialProducts = [];
                try {
                    $initCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
                    $initSearch = isset($_GET['search']) ? trim($_GET['search']) : (isset($_GET['q']) ? trim($_GET['q']) : '');
                    $initSaleOnly = !empty($_GET['sale']);

                    $sqlInit = "SELECT * FROM products WHERE available = 1";
                    if ($initSaleOnly) {
                        $sqlInit .= " AND mrp_price > 0 AND mrp_price > price";
                    }
                    if ($initCategory !== '') {
                        if (stripos($initCategory, 'kurti') !== false || stripos($initCategory, 'kurta') !== false) {
                            $sqlInit .= " AND (category = " . $pdo->quote($initCategory) . " OR category LIKE '%Kurti%' OR category LIKE '%Kurta%')";
                        } else if (stripos($initCategory, 'suit') !== false) {
                            $sqlInit .= " AND (category = " . $pdo->quote($initCategory) . " OR category LIKE '%Suit%')";
                        } else if (stripos($initCategory, 'coord') !== false || stripos($initCategory, 'co-ord') !== false) {
                            $sqlInit .= " AND (category = " . $pdo->quote($initCategory) . " OR category LIKE '%Coord%' OR category LIKE '%Co-Ord%')";
                        } else {
                            $sqlInit .= " AND category = " . $pdo->quote($initCategory);
                        }
                    }
                    if ($initSearch !== '') {
                        $sQuoted = $pdo->quote('%' . $initSearch . '%');
                        $sqlInit .= " AND (name LIKE $sQuoted OR description LIKE $sQuoted OR category LIKE $sQuoted OR fabric LIKE $sQuoted OR color LIKE $sQuoted)";
                    }
                    $sqlInit .= " ORDER BY id DESC LIMIT 6";
                    
                    $stmtInit = $pdo->query($sqlInit);
                    $initialProducts = $stmtInit->fetchAll();
                } catch (PDOException $e) {}
                
                foreach ($initialProducts as $p):
                    $imgSrc = !empty($p['image']) ? 'uploads/products/' . htmlspecialchars($p['image']) : '';
                    $formattedPrice = formatPrice($p['price']);
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
                    <button class="product-card-wishlist-btn" onclick="event.preventDefault(); handleWishlistClick(<?= $p['id'] ?>, this)" aria-label="Add to Wishlist">
                        <i class="fa-regular fa-heart"></i>
                    </button>
                    <div class="card-img-container">
                        <a href="<?= productUrl($p['id'], $p['name']) ?>" class="card-img-link">
                            <?php if (!empty($p['image'])): ?>
                                <img src="uploads/products/<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy" class="card-img">
                            <?php elseif (!empty($p['video_url'])): ?>
                                <?php $vUrl = trim($p['video_url']); $vSrc = (strpos($vUrl, 'http://') === 0 || strpos($vUrl, 'https://') === 0) ? $vUrl : 'uploads/products/' . $vUrl; ?>
                                <video src="<?= htmlspecialchars($vSrc) ?>" autoplay loop muted playsinline class="card-img"></video>
                            <?php else: ?>
                                <div class="card-img-fallback"><?= htmlspecialchars($p['emoji'] ?? '👗') ?></div>
                            <?php endif; ?>
                        </a>
                        <button onclick="event.preventDefault(); window.location.href='<?= productUrl($p['id'], $p['name']) ?>'" class="quick-view-btn">Quick View</button>
                    </div>
                    <div class="product-card-details">
                        <span class="product-card-category"><?= htmlspecialchars($p['category']) ?></span>
                        <h3 class="product-card-title">
                            <a href="<?= productUrl($p['id'], $p['name']) ?>"><?= htmlspecialchars($p['name']) ?></a>
                        </h3>
                        <div class="product-card-price">
                            <?php if ($pHasDiscount): ?>
                                <span class="price-mrp-strike"><?= formatPrice($pMrp) ?></span>
                            <?php endif; ?>
                            <?= $formattedPrice ?>
                        </div>
                        <a href="<?= productUrl($p['id'], $p['name']) ?>" class="btn-luxury product-card-cta">
                            Details
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <!-- Scroll Loader -->
            <div id="scrollLoader" class="scroll-loader-wrap">
                <div class="loader-spinner" id="loaderSpinner" style="display:none;"></div>
                <p id="loaderText"></p>
            </div>
        </div>

    </div>
</section>



<script>
    function toggleFilter(headerEl) {
        headerEl.classList.toggle('open');
        const bodyEl = headerEl.nextElementSibling;
        if (bodyEl) {
            bodyEl.classList.toggle('open');
        }
    }

    // Catalog state
    let currentPage = 1;
    let loading = false;
    let hasMore = true;

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const catParam = urlParams.get('category');

        if (catParam) {
            const cb = document.querySelector(`input[name="category_filter[]"][value="${catParam}"]`);
            if (cb) cb.checked = true;
        }

        const initialCount = document.querySelectorAll('#shopProductsGrid .product-card').length;
        if (initialCount > 0) {
            currentPage = 2;
            hasMore = initialCount >= 6;
            document.getElementById('loaderSpinner').style.display = 'none';
            document.getElementById('loaderText').textContent = hasMore ? 'Scroll for more pieces' : 'End of Collections';
        } else {
            loadProducts(true);
        }
        
        // Infinite scroll event listener
        window.addEventListener('scroll', () => {
            if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 250) {
                if (!loading && hasMore) {
                    loadProducts(false);
                }
            }
        });
    });

    function getSelectedCheckboxes(name) {
        const cbs = document.querySelectorAll(`input[name="${name}"]:checked`);
        let values = [];
        cbs.forEach(cb => values.push(cb.value));
        return values.join(',');
    }

    function applyFilters() {
        loadProducts(true);
    }
    
    function resetFilters() {
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.getElementById('minPrice').value = '';
        document.getElementById('maxPrice').value = '';
        document.getElementById('sortBySelect').value = 'default';
        if (window.location.search.includes('search=') || window.location.search.includes('q=') || window.location.search.includes('sale=')) {
            window.location.href = 'shop';
            return;
        }
        loadProducts(true);
    }

    let allLoadedProducts = {};

    let currentView = 'grid';

    function setGridView(view) {
        currentView = view;
        const grid = document.getElementById('shopProductsGrid');
        const btnGrid = document.getElementById('btnGrid');
        const btnList = document.getElementById('btnList');
        
        if (view === 'list') {
            grid.style.gridTemplateColumns = '1fr';
            grid.classList.add('list-view');
            btnList.classList.add('active');
            btnList.style.color = 'var(--text-primary)';
            btnGrid.classList.remove('active');
            btnGrid.style.color = 'var(--text-muted)';
        } else {
            // Restore grid columns (responsive)
            if (window.innerWidth <= 900) {
                grid.style.gridTemplateColumns = 'repeat(2, 1fr)';
            } else {
                grid.style.gridTemplateColumns = 'repeat(3, 1fr)';
            }
            grid.classList.remove('list-view');
            btnGrid.classList.add('active');
            btnGrid.style.color = 'var(--text-primary)';
            btnList.classList.remove('active');
            btnList.style.color = 'var(--text-muted)';
        }
    }

    // Handle resize for grid
    window.addEventListener('resize', () => {
        if (currentView === 'grid') setGridView('grid');
    });

    function loadProducts(reset = false) {
        if (reset) {
            currentPage = 1;
            hasMore = true;
        }

        loading = true;
        document.getElementById('loaderSpinner').style.display = 'block';
        document.getElementById('loaderText').textContent = 'Loading exquisite items...';

        const categories = getSelectedCheckboxes('category_filter[]');
        const colors = getSelectedCheckboxes('color_filter[]');
        const brands = getSelectedCheckboxes('brand_filter[]');
        const fabrics = getSelectedCheckboxes('fabric_filter[]');
        const sleeves = getSelectedCheckboxes('sleeve_filter[]');
        const necks = getSelectedCheckboxes('neck_filter[]');
        const patterns = getSelectedCheckboxes('pattern_filter[]');
        const occasions = getSelectedCheckboxes('occasion_filter[]');
        const minPrice = document.getElementById('minPrice').value;
        const maxPrice = document.getElementById('maxPrice').value;
        const sort_by = document.getElementById('sortBySelect').value;

        const urlParams = new URLSearchParams(window.location.search);
        const searchQuery = urlParams.get('search') || urlParams.get('q') || '';
        const saleOnly = urlParams.get('sale');

        let url = `${window.location.pathname}?ajax=1&page=${currentPage}&sort_by=${sort_by}`;
        if (searchQuery) url += `&search=${encodeURIComponent(searchQuery)}`;
        if (saleOnly) url += `&sale=${encodeURIComponent(saleOnly)}`;
        if (categories) url += `&categories=${encodeURIComponent(categories)}`;
        if (colors) url += `&colors=${encodeURIComponent(colors)}`;
        if (brands) url += `&brands=${encodeURIComponent(brands)}`;
        if (fabrics) url += `&fabrics=${encodeURIComponent(fabrics)}`;
        if (sleeves) url += `&sleeves=${encodeURIComponent(sleeves)}`;
        if (necks) url += `&necks=${encodeURIComponent(necks)}`;
        if (patterns) url += `&patterns=${encodeURIComponent(patterns)}`;
        if (occasions) url += `&occasions=${encodeURIComponent(occasions)}`;
        if (minPrice) url += `&min_price=${encodeURIComponent(minPrice)}`;
        if (maxPrice) url += `&max_price=${encodeURIComponent(maxPrice)}`;

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const grid = document.getElementById('shopProductsGrid');
                    
                    if (reset) {
                        grid.innerHTML = '';
                    }
                    
                    if (data.products.length === 0 && currentPage === 1) {
                        grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 60px 0; color: var(--text-secondary);">No pieces found matching your criteria.</div>`;
                        hasMore = false;
                        document.getElementById('loaderText').textContent = '';
                        document.getElementById('loaderSpinner').style.display = 'none';
                        loading = false;
                        return;
                    }

                    // Render products
                    data.products.forEach(p => {
                        allLoadedProducts[p.id] = p;
                        const card = createProductCard(p);
                        grid.appendChild(card);
                        if (typeof dievonObserveReveal === 'function') dievonObserveReveal(card);
                    });

                    hasMore = data.has_more;
                    currentPage++;
                    
                    if (!hasMore) {
                        document.getElementById('loaderText').textContent = 'End of Collections';
                    } else {
                        document.getElementById('loaderText').textContent = 'Scroll for more pieces';
                    }
                }
                document.getElementById('loaderSpinner').style.display = 'none';
                loading = false;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('loaderSpinner').style.display = 'none';
                document.getElementById('loaderText').textContent = 'End of Collections';
                loading = false;
            });
    }

    function slugify(text) {
        return String(text).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'product';
    }
    function productUrl(p) {
        return `${window.SITE_URL}/product/${slugify(p.name)}-${p.id}`;
    }

    function createProductCard(p) {
        const article = document.createElement('article');
        article.className = 'product-card reveal-on-scroll';

        let imgHtml = '';
        if (p.image) {
            imgHtml = `<img src="uploads/products/${escHtml(p.image)}" alt="${escHtml(p.name)}" loading="lazy" class="card-img">`;
        } else if (p.video_url) {
            const vSrc = (p.video_url.startsWith('http://') || p.video_url.startsWith('https://')) ? p.video_url : `uploads/products/${p.video_url}`;
            imgHtml = `<video src="${escHtml(vSrc)}" autoplay loop muted playsinline class="card-img"></video>`;
        } else {
            imgHtml = `<div class="card-img-fallback" aria-label="No image available">${escHtml(p.emoji)}</div>`;
        }

        const badgeHtml = p.has_discount ? `<span class="badge-luxury badge-sale">${p.discount_percent}% OFF</span>` : (p.badge ? `<span class="badge-luxury">${escHtml(p.badge)}</span>` : '');

        // Sizing indicator
        let sizeInfo = '';
        if (p.variants && p.variants.length > 0) {
            const sizes = p.variants.map(v => v.name.replace('Size: ', '')).join(', ');
            sizeInfo = `<span class="product-card-sizes">Sizes: ${escHtml(sizes)}</span>`;
        }

        // Wishlist active check
        const isWishlisted = getWishlist().indexOf(p.id.toString()) > -1;
        const wishlistClass = isWishlisted ? 'fa-solid fa-heart wishlist-btn-active' : 'fa-regular fa-heart';

        article.innerHTML = `
            ${badgeHtml}

            <button class="product-card-wishlist-btn" onclick="event.preventDefault(); handleWishlistClick(${p.id}, this)" aria-label="Add to Wishlist">
                <i class="${wishlistClass}"></i>
            </button>

            <div class="card-img-container">
                <a href="${productUrl(p)}" class="card-img-link">
                    ${imgHtml}
                </a>
                <button onclick="event.preventDefault(); window.location.href='${productUrl(p)}'" class="quick-view-btn">Quick View</button>
            </div>
            <div class="product-card-details">
                <span class="product-card-category">${escHtml(p.category)}</span>
                <h3 class="product-card-title">
                    <a href="${productUrl(p)}">${escHtml(p.name)}</a>
                </h3>
                ${sizeInfo}
                <div class="product-card-price">
                    ${p.has_discount ? `<span class="price-mrp-strike">${escHtml(p.formatted_mrp)}</span>` : ''}
                    ${p.formatted_price ? escHtml(p.formatted_price) : (typeof formatPriceJS === 'function' ? formatPriceJS(p.price) : '£' + parseFloat(p.price).toFixed(2))}
                </div>
                <a href="${productUrl(p)}" class="btn-luxury product-card-cta">
                    Details
                </a>
            </div>
        `;
        return article;
    }
</script>


<!-- ══ Mobile Sticky Bottom Bar (Sort By & Refine) ═════════════════ -->
<div class="mobile-shop-bottom-bar">
    <button type="button" class="mobile-filter-btn" onclick="openMobileSortDrawer()">
        <i class="fa-solid fa-arrow-down-short-wide"></i> Sort By
    </button>
    <button type="button" class="mobile-filter-btn" onclick="openMobileRefineDrawer()">
        <i class="fa-solid fa-sliders"></i> Refine
    </button>
</div>

<!-- Mobile Drawer Backdrop -->
<div class="mobile-drawer-backdrop" id="mobileShopBackdrop" onclick="closeAllMobileDrawers()"></div>

<!-- ══ Mobile Sort By Sheet Modal ════════════════════════════════ -->
<div class="mobile-drawer-sheet" id="mobileSortDrawer">
    <div class="mobile-drawer-header">
        <span>Sort By</span>
        <button type="button" class="mobile-drawer-close-btn" onclick="closeMobileSortDrawer()">&times;</button>
    </div>
    <div class="sort-options-list">
        <div class="sort-option-item selected" data-val="default" onclick="selectMobileSort('default', this)">
            <span>Default (Newest)</span>
            <i class="fa-solid fa-check check-icon" style="color: var(--color-primary);"></i>
        </div>
        <div class="sort-option-item" data-val="price_asc" onclick="selectMobileSort('price_asc', this)">
            <span>Price: Low to High</span>
            <i class="fa-solid fa-check check-icon" style="display:none; color: var(--color-primary);"></i>
        </div>
        <div class="sort-option-item" data-val="price_desc" onclick="selectMobileSort('price_desc', this)">
            <span>Price: High to Low</span>
            <i class="fa-solid fa-check check-icon" style="display:none; color: var(--color-primary);"></i>
        </div>
        <div class="sort-option-item" data-val="name_asc" onclick="selectMobileSort('name_asc', this)">
            <span>Name: A to Z</span>
            <i class="fa-solid fa-check check-icon" style="display:none; color: var(--color-primary);"></i>
        </div>
    </div>
</div>

<!-- ══ Mobile Refine (Filter) Tabbed Drawer Modal ═════════════════ -->
<div class="mobile-drawer-sheet" id="mobileRefineDrawer">
    <div class="mobile-drawer-header">
        <span>Refine</span>
        <a href="javascript:void(0)" onclick="clearMobileRefine()" style="color: #d97706; font-size: 13px; font-weight: 700; text-decoration: none;">Clear All</a>
    </div>

    <div class="refine-drawer-layout">
        <!-- Left Vertical Tabs Column -->
        <div class="refine-tabs-col">
            <button type="button" class="refine-tab-btn active" onclick="switchRefineTab('tabCategory', this)">Sub Category</button>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabPrice', this)">Price</button>
            <?php if (!empty($brands)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabBrand', this)">Brand</button>
            <?php endif; ?>
            <?php if (!empty($colors)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabColor', this)">Color</button>
            <?php endif; ?>
            <?php if (!empty($fabrics)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabFabric', this)">Fabric</button>
            <?php endif; ?>
            <?php if (!empty($sleeves)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabSleeve', this)">Sleeve</button>
            <?php endif; ?>
            <?php if (!empty($necks)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabNeck', this)">Neck</button>
            <?php endif; ?>
            <?php if (!empty($patterns)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabPattern', this)">Pattern</button>
            <?php endif; ?>
            <?php if (!empty($occasions)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabOccasion', this)">Occasion</button>
            <?php endif; ?>
        </div>

        <!-- Right Filter Content Column -->
        <div class="refine-content-col">
            <!-- Category Tab -->
            <div class="refine-tab-pane active" id="tabCategory">
                <div class="refine-search-box">
                    <input type="text" placeholder="Search for sub category..." onkeyup="filterRefineItems(this, 'refineCatList')">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <div class="refine-checkbox-list" id="refineCatList">
                    <?php foreach ($categories as $cat): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_category[]" value="<?= htmlspecialchars($cat['name']) ?>">
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Price Tab -->
            <div class="refine-tab-pane" id="tabPrice">
                <div style="padding: 10px 0;">
                    <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 6px;">Min Price (£)</label>
                    <input type="number" id="mMinPrice" placeholder="0" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 14px; font-size: 14px;">
                    
                    <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 6px;">Max Price (£)</label>
                    <input type="number" id="mMaxPrice" placeholder="1000" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                </div>
            </div>

            <!-- Brand Tab -->
            <?php if (!empty($brands)): ?>
            <div class="refine-tab-pane" id="tabBrand">
                <div class="refine-search-box">
                    <input type="text" placeholder="Search brand..." onkeyup="filterRefineItems(this, 'refineBrandList')">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <div class="refine-checkbox-list" id="refineBrandList">
                    <?php foreach ($brands as $b): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_brand[]" value="<?= htmlspecialchars($b) ?>">
                            <span><?= htmlspecialchars($b) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Color Tab -->
            <?php if (!empty($colors)): ?>
            <div class="refine-tab-pane" id="tabColor">
                <div class="refine-checkbox-list">
                    <?php foreach ($colors as $c): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_color[]" value="<?= htmlspecialchars($c) ?>">
                            <span><?= htmlspecialchars($c) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Fabric Tab -->
            <?php if (!empty($fabrics)): ?>
            <div class="refine-tab-pane" id="tabFabric">
                <div class="refine-checkbox-list">
                    <?php foreach ($fabrics as $f): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_fabric[]" value="<?= htmlspecialchars($f) ?>">
                            <span><?= htmlspecialchars($f) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Sleeve Tab -->
            <?php if (!empty($sleeves)): ?>
            <div class="refine-tab-pane" id="tabSleeve">
                <div class="refine-checkbox-list">
                    <?php foreach ($sleeves as $s): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_sleeve[]" value="<?= htmlspecialchars($s) ?>">
                            <span><?= htmlspecialchars($s) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Neck Tab -->
            <?php if (!empty($necks)): ?>
            <div class="refine-tab-pane" id="tabNeck">
                <div class="refine-checkbox-list">
                    <?php foreach ($necks as $n): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_neck[]" value="<?= htmlspecialchars($n) ?>">
                            <span><?= htmlspecialchars($n) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pattern Tab -->
            <?php if (!empty($patterns)): ?>
            <div class="refine-tab-pane" id="tabPattern">
                <div class="refine-checkbox-list">
                    <?php foreach ($patterns as $p): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_pattern[]" value="<?= htmlspecialchars($p) ?>">
                            <span><?= htmlspecialchars($p) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Occasion Tab -->
            <?php if (!empty($occasions)): ?>
            <div class="refine-tab-pane" id="tabOccasion">
                <div class="refine-checkbox-list">
                    <?php foreach ($occasions as $o): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_occasion[]" value="<?= htmlspecialchars($o) ?>">
                            <span><?= htmlspecialchars($o) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Bottom Actions Bar -->
    <div class="refine-bottom-actions">
        <button type="button" class="btn-refine-close" onclick="closeMobileRefineDrawer()">Close</button>
        <button type="button" class="btn-refine-apply" onclick="applyMobileRefine()">Apply</button>
    </div>
</div>

<script>
    function openMobileSortDrawer() {
        document.getElementById('mobileShopBackdrop').classList.add('active');
        document.getElementById('mobileSortDrawer').classList.add('open');
    }

    function closeMobileSortDrawer() {
        document.getElementById('mobileSortDrawer').classList.remove('open');
        document.getElementById('mobileShopBackdrop').classList.remove('active');
    }

    function openMobileRefineDrawer() {
        // Sync desktop inputs into mobile refine inputs
        syncDesktopToMobileRefine();
        document.getElementById('mobileShopBackdrop').classList.add('active');
        document.getElementById('mobileRefineDrawer').classList.add('open');
    }

    function closeMobileRefineDrawer() {
        document.getElementById('mobileRefineDrawer').classList.remove('open');
        document.getElementById('mobileShopBackdrop').classList.remove('active');
    }

    function closeAllMobileDrawers() {
        closeMobileSortDrawer();
        closeMobileRefineDrawer();
    }

    function selectMobileSort(val, el) {
        document.getElementById('sortBySelect').value = val;
        document.querySelectorAll('.sort-option-item').forEach(item => {
            item.classList.remove('selected');
            item.querySelector('.check-icon').style.display = 'none';
        });
        if (el) {
            el.classList.add('selected');
            el.querySelector('.check-icon').style.display = 'inline-block';
        }
        applyFilters();
        closeMobileSortDrawer();
    }

    function switchRefineTab(paneId, btn) {
        document.querySelectorAll('.refine-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.refine-tab-pane').forEach(p => p.classList.remove('active'));
        
        btn.classList.add('active');
        const target = document.getElementById(paneId);
        if (target) target.classList.add('active');
    }

    function filterRefineItems(input, listId) {
        const query = input.value.toLowerCase();
        const labels = document.querySelectorAll('#' + listId + ' .refine-checkbox-label');
        labels.forEach(label => {
            const text = label.textContent.toLowerCase();
            label.style.display = text.includes(query) ? 'flex' : 'none';
        });
    }

    function syncDesktopToMobileRefine() {
        // Sync categories
        const dCats = Array.from(document.querySelectorAll('input[name="category_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_category[]"]').forEach(cb => cb.checked = dCats.includes(cb.value));

        // Sync price
        document.getElementById('mMinPrice').value = document.getElementById('minPrice').value || '';
        document.getElementById('mMaxPrice').value = document.getElementById('maxPrice').value || '';

        // Sync brands
        const dBrands = Array.from(document.querySelectorAll('input[name="brand_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_brand[]"]').forEach(cb => cb.checked = dBrands.includes(cb.value));

        // Sync colors
        const dColors = Array.from(document.querySelectorAll('input[name="color_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_color[]"]').forEach(cb => cb.checked = dColors.includes(cb.value));

        // Sync fabrics
        const dFabrics = Array.from(document.querySelectorAll('input[name="fabric_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_fabric[]"]').forEach(cb => cb.checked = dFabrics.includes(cb.value));

        // Sync sleeves
        const dSleeves = Array.from(document.querySelectorAll('input[name="sleeve_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_sleeve[]"]').forEach(cb => cb.checked = dSleeves.includes(cb.value));

        // Sync necks
        const dNecks = Array.from(document.querySelectorAll('input[name="neck_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_neck[]"]').forEach(cb => cb.checked = dNecks.includes(cb.value));

        // Sync patterns
        const dPatterns = Array.from(document.querySelectorAll('input[name="pattern_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_pattern[]"]').forEach(cb => cb.checked = dPatterns.includes(cb.value));

        // Sync occasions
        const dOccasions = Array.from(document.querySelectorAll('input[name="occasion_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_occasion[]"]').forEach(cb => cb.checked = dOccasions.includes(cb.value));
    }

    function applyMobileRefine() {
        // Sync mobile inputs to desktop inputs
        const mCats = Array.from(document.querySelectorAll('input[name="m_category[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="category_filter[]"]').forEach(cb => cb.checked = mCats.includes(cb.value));

        document.getElementById('minPrice').value = document.getElementById('mMinPrice').value || '';
        document.getElementById('maxPrice').value = document.getElementById('mMaxPrice').value || '';

        const mBrands = Array.from(document.querySelectorAll('input[name="m_brand[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="brand_filter[]"]').forEach(cb => cb.checked = mBrands.includes(cb.value));

        const mColors = Array.from(document.querySelectorAll('input[name="m_color[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="color_filter[]"]').forEach(cb => cb.checked = mColors.includes(cb.value));

        const mFabrics = Array.from(document.querySelectorAll('input[name="m_fabric[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="fabric_filter[]"]').forEach(cb => cb.checked = mFabrics.includes(cb.value));

        const mSleeves = Array.from(document.querySelectorAll('input[name="m_sleeve[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="sleeve_filter[]"]').forEach(cb => cb.checked = mSleeves.includes(cb.value));

        const mNecks = Array.from(document.querySelectorAll('input[name="m_neck[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="neck_filter[]"]').forEach(cb => cb.checked = mNecks.includes(cb.value));

        const mPatterns = Array.from(document.querySelectorAll('input[name="m_pattern[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="pattern_filter[]"]').forEach(cb => cb.checked = mPatterns.includes(cb.value));

        const mOccasions = Array.from(document.querySelectorAll('input[name="m_occasion[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="occasion_filter[]"]').forEach(cb => cb.checked = mOccasions.includes(cb.value));

        // Trigger AJAX reload
        applyFilters();
        closeMobileRefineDrawer();
    }

    function clearMobileRefine() {
        document.querySelectorAll('input[name="m_category[]"]').forEach(cb => cb.checked = false);
        document.getElementById('mMinPrice').value = '';
        document.getElementById('mMaxPrice').value = '';
        document.querySelectorAll('input[name="m_brand[]"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="m_color[]"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="m_fabric[]"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="m_sleeve[]"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="m_neck[]"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="m_pattern[]"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="m_occasion[]"]').forEach(cb => cb.checked = false);

        applyMobileRefine();
    }
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
