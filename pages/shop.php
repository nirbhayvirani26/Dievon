<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// ── /collections/<slug> ──────────────────────────────────────────────────────
// The clean collection URL is rewritten here as ?collection=<slug>. Resolving it
// straight into $_GET['category'] means every existing code path below — the
// AJAX branch, the first-page query, the sidebar, the canonical — keeps working
// exactly as it did for ?category=<name>, with no second implementation to drift.
//
// It is deliberately the slug and not the name in the URL: a slug is stable
// under a rename, so an indexed link survives the owner retitling a category.
$activeCategoryRow = null;

/**
 * Send an address this collection used to live at on to its current one.
 *
 * Returns false when the slug was never in use, so the caller can 404.
 */
$shopRedirectRetiredSlug = function (PDO $pdo, string $slug): bool {
    try {
        $hist = $pdo->prepare(
            "SELECT c.* FROM category_slug_history h
               JOIN categories c ON c.id = h.category_id
              WHERE h.old_slug = :s LIMIT 1"
        );
        $hist->execute(['s' => $slug]);
        if ($moved = $hist->fetch(PDO::FETCH_ASSOC)) {
            $passThrough = $_GET;
            unset($passThrough['collection'], $passThrough['category'], $passThrough['route']);
            ksort($passThrough);
            $target = categoryUrl($moved);
            if ($passThrough) { $target .= '?' . http_build_query($passThrough); }
            header('Location: ' . $target, true, 301);
            exit;
        }
    } catch (PDOException $e) {}
    return false;
};

/**
 * A page that genuinely does not exist. Never answer 200 with an empty grid.
 *
 * use ($pdo) is load-bearing. 404.php pulls in includes/header.php, which builds
 * the menu with $pdo->query() — and a closure does not inherit the enclosing
 * scope in PHP, so without this the connection was null inside here and the page
 * died with "Call to a member function query() on null" instead of rendering.
 *
 * That turned every genuine 404 on this page into a white screen: all 7 empty
 * categories, page 2 of every collection, any filter combination matching
 * nothing, and both men's categories — which are empty, so the entire men's side
 * of the shop was down. Measured: /collections/long-kurtis returned 660 bytes of
 * fatal error, against 139KB for a category that has products.
 */
$shopNotFound = function () use ($pdo): void {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
};

if (!empty($_GET['collection'])) {
    $activeCategoryRow = categoryBySlug($pdo, (string)$_GET['collection']);
    if ($activeCategoryRow) {
        $_GET['category'] = $activeCategoryRow['name'];

        // Reached through ?collection= directly rather than through the
        // /collections/ rewrite: that is a second address serving the same page,
        // so send it to the canonical one exactly as ?category= is sent.
        $reqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (strpos($reqPath, '/collections/') === false
            && empty($_GET['ajax'])
            && !empty($activeCategoryRow['slug'])
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $passThrough = $_GET;
            unset($passThrough['collection'], $passThrough['category'], $passThrough['route']);
            ksort($passThrough);
            $target = categoryUrl($activeCategoryRow);
            if ($passThrough) { $target .= '?' . http_build_query($passThrough); }
            header('Location: ' . $target, true, 301);
            exit;
        }
    } else {
        $shopRedirectRetiredSlug($pdo, (string)$_GET['collection']);
        // An unknown slug is a genuinely missing page, not an empty catalogue —
        // returning 200 with every product would let Google index typos forever.
        $shopNotFound();
    }
} elseif (!empty($_GET['category'])) {
    try {
        $st = $pdo->prepare("SELECT * FROM categories WHERE name = :n LIMIT 1");
        $st->execute(['n' => trim((string)$_GET['category'])]);
        $activeCategoryRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {}

    // A category name that is not in the table is not an empty shop — it is a
    // page that does not exist. This branch used to answer 200 with an empty
    // grid and a canonical pointing at itself, which made ?category=Handbags,
    // ?category=Shoes and every other typo or guess an indexable soft-404. The
    // parameter is unbounded, so that was an unbounded number of them.
    if (!$activeCategoryRow
        && empty($_GET['ajax'])
        && empty($_GET['search']) && empty($_GET['q'])
        && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $shopRedirectRetiredSlug($pdo, slugify(trim((string)$_GET['category']), 100));
        $shopNotFound();
    }

    // Send the legacy ?category=<name> form to the canonical collection URL.
    //
    // 301 rather than a canonical tag alone: banner links stored in the database,
    // anything already shared, and any URL Google has seen all point at the old
    // form, and a permanent redirect passes their value to the one address that
    // should rank instead of leaving two competing versions of the same page.
    //
    // Skipped for AJAX, which is the same page fetching its own next page —
    // redirecting that would break "load more".
    if ($activeCategoryRow
        && empty($_GET['ajax'])
        && !empty($activeCategoryRow['slug'])
        && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $passThrough = $_GET;
        // 'route' is injected by the .htaccess clean-URL fallback and 'collection'
        // by the /collections/ rule — internal plumbing, not shopper-facing filters.
        // Echoing them back would publish /collections/kurtis?route=shop.
        unset($passThrough['category'], $passThrough['route'], $passThrough['collection']);
        // Stable target whatever order the client sent the parameters in —
        // otherwise ?category=X&badges=New and ?badges=New&category=X redirect to
        // two different-looking URLs for the same page.
        ksort($passThrough);
        $target = categoryUrl($activeCategoryRow);
        if ($passThrough) { $target .= '?' . http_build_query($passThrough); }
        header('Location: ' . $target, true, 301);
        exit;
    }
}

/**
 * ORDER BY clause for a sort_by value.
 *
 * Sorting had never worked: the dropdown emits price_asc / price_desc / name_asc,
 * and the switch this replaces tested for price_low / price_high / newest, so no
 * option ever matched and every sort silently fell through to "newest first".
 * The initial page query did not read sort_by at all, so even a matching value
 * would only have taken effect from the second page onward.
 *
 * Both vocabularies are accepted so that any link already shared keeps working.
 */
function shopOrderBy(string $sortBy): string {
    switch ($sortBy) {
        case 'price_asc':
        case 'price_low':   return " ORDER BY price ASC";
        case 'price_desc':
        case 'price_high':  return " ORDER BY price DESC";
        case 'name_asc':    return " ORDER BY name ASC";
        case 'name_desc':   return " ORDER BY name DESC";
        default:            return " ORDER BY id DESC";   // newest first
    }
}

/**
 * Escape the three characters LIKE treats as syntax.
 *
 * Every search box on the shop wraps the shopper's term in %…% and hands it to
 * LIKE, so a term that itself contains % or _ was not searched for — it was
 * executed. "Aur%Saree" matched "Aurora Silk Saree", "Aurora_Silk" matched
 * "Aurora Silk", and a lone "%" returned the whole catalogue under a heading
 * announcing a search for it. Escaping widens nothing and blocks nothing that
 * used to work; it only makes punctuation mean itself.
 *
 * Guarded because ajax_search.php defines the same helper and a future include
 * order must not fatal on a redeclare.
 */
if (!function_exists('likeEscape')) {
    function likeEscape(string $term): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}

/**
 * 'asc' / 'desc' when the shopper asked to sort by price, '' otherwise.
 * Both vocabularies, matching shopOrderBy() above.
 */
function shopPriceSortDirection(string $sortBy): string {
    switch ($sortBy) {
        case 'price_asc':
        case 'price_low':   return 'asc';
        case 'price_desc':
        case 'price_high':  return 'desc';
        default:            return '';
    }
}

/** Does this request need the buyable price rather than products.price? */
function shopNeedsBuyablePrice(float $minPrice, float $maxPrice, string $sortBy): bool {
    return $minPrice > 0 || $maxPrice > 0 || shopPriceSortDirection($sortBy) !== '';
}

/**
 * A page of products filtered and ordered by the price a shopper can ACTUALLY pay.
 *
 * THE BUG THIS EXISTS TO CLOSE
 * ────────────────────────────────────────────────────────────────────────────
 * The price filter said `AND products.price BETWEEN ?` and the price sort said
 * `ORDER BY products.price`, while the card standing next to them prints
 * productPriceRange()['min'] — the cheapest size or colourway a shopper can
 * actually buy. On any product whose sizes carry their own prices those are two
 * different numbers, so the grid contradicted itself:
 *
 *   • A saree priced 3000 whose sizes are 1500 / 2600 / 2600 advertises
 *     "From ₹1,500" on its card. Filtering ₹1,400–₹1,550 excluded it — the
 *     shopper asked for what the card offered and was told there was nothing.
 *   • Trousers priced 1250 whose only colourway overrides to 1100 advertise
 *     "₹1,100". Filtering ₹1,000–₹1,200 returned an empty grid.
 *   • Sorted High to Low, that saree landed between ₹2,499 and ₹2,501 showing
 *     "From ₹1,500" — a visible break in the middle of the ordering.
 *
 * WHY IT IS RESOLVED IN PHP
 * ────────────────────────────────────────────────────────────────────────────
 * The cheapest buyable price is not a column and cannot honestly be made into
 * one in SQL: the ladder runs colour price_override → size price → product
 * price, a stored size price of 0 means "follow the product", and a size dearer
 * than a product that is on sale is clamped back down. productPriceRange() is
 * the one implementation of that ladder — the cart, the product page and the
 * card all reach it — and config/config.php says in as many words that a second
 * copy would drift from the first. So this CALLS it rather than re-expressing
 * it, and the figure that filters and sorts is by construction the same figure
 * the card prints.
 *
 * Cost is kept down by ranking on a slim projection — id, price, mrp_price is
 * everything productPriceRange() reads — and fetching the full rows only for
 * the six that survive. Three light queries instead of one, and only on
 * requests that actually asked for a price filter or a price sort; ordinary
 * browsing never enters this path.
 *
 * $sql must carry every NON-price filter and no ORDER BY / LIMIT.
 * Returns ['rows' => array, 'has_more' => bool].
 */
function shopBuyablePricedPage(PDO $pdo, string $sql, array $params, float $minPrice,
                               float $maxPrice, string $sortBy, int $limit, int $offset): array {
    // Rank on the identifiers and the two price columns only.
    $slimSql = preg_replace(
        '/^\s*SELECT\s+\*\s+FROM\s+products\b/i',
        'SELECT id, price, mrp_price FROM products',
        $sql,
        1
    );
    $st = $pdo->prepare($slimSql . ' ORDER BY id DESC');
    $st->execute($params);
    $slim = $st->fetchAll(PDO::FETCH_ASSOC);

    // One pair of queries for the whole candidate set, not a pair per product.
    productPriceRangePrime($slim, $pdo);

    $ranked = [];
    foreach ($slim as $seq => $r) {
        $range = productPriceRange($r);
        $buyable = $range['min'] > 0 ? (float)$range['min'] : (float)($r['price'] ?? 0);
        if ($minPrice > 0 && $buyable < $minPrice - 0.005) { continue; }
        if ($maxPrice > 0 && $buyable > $maxPrice + 0.005) { continue; }
        $ranked[] = ['id' => (int)$r['id'], 'buyable' => $buyable, 'seq' => $seq];
    }

    $dir = shopPriceSortDirection($sortBy);
    if ($dir !== '') {
        usort($ranked, static function (array $a, array $b) use ($dir): int {
            if (abs($a['buyable'] - $b['buyable']) > 0.005) {
                return $dir === 'asc'
                    ? $a['buyable'] <=> $b['buyable']
                    : $b['buyable'] <=> $a['buyable'];
            }
            return $a['seq'] <=> $b['seq'];   // equal prices: newest first, as elsewhere
        });
    } elseif ($sortBy === 'name_asc' || $sortBy === 'name_desc') {
        // Name sorts still belong to SQL; re-apply here because the scan above
        // deliberately ordered by id so the price tiebreak is deterministic.
        $ids = array_column($ranked, 'id');
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $order = $pdo->query("SELECT id FROM products WHERE id IN ($in) ORDER BY name "
                                 . ($sortBy === 'name_desc' ? 'DESC' : 'ASC'))->fetchAll(PDO::FETCH_COLUMN);
            $pos = array_flip(array_map('intval', $order));
            usort($ranked, static fn($a, $b) => ($pos[$a['id']] ?? PHP_INT_MAX) <=> ($pos[$b['id']] ?? PHP_INT_MAX));
        }
    }

    $total    = count($ranked);
    $hasMore  = ($offset + $limit) < $total;
    $pageIds  = array_column(array_slice($ranked, $offset, $limit), 'id');
    if (!$pageIds) { return ['rows' => [], 'has_more' => false]; }

    // Full rows for the survivors only, put back into the ranked order.
    $in   = implode(',', array_map('intval', $pageIds));
    $rows = $pdo->query("SELECT * FROM products WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
    $ordered = [];
    foreach ($pageIds as $pid) { if (isset($byId[$pid])) { $ordered[] = $byId[$pid]; } }

    return ['rows' => $ordered, 'has_more' => $hasMore];
}

// Handle AJAX load more request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 6;
    $offset = ($page - 1) * $limit;
    
    $categoriesList = isset($_GET['categories']) && $_GET['categories'] !== '' ? explode(',', $_GET['categories']) : [];
    // /collections/<slug> and ?category=<name> carry the filter in the URL rather
    // than in a checkbox. The browser appends ?ajax=1 to the current path, so the
    // slug still arrives here — without this, page 2 of a collection would come
    // back holding the entire catalogue.
    if (!$categoriesList && !empty($_GET['category'])) {
        $categoriesList = [trim((string)$_GET['category'])];
    }
    $sizesList  = isset($_GET['sizes'])  && $_GET['sizes']  !== '' ? array_filter(array_map('trim', explode(',', (string)$_GET['sizes']))) : [];
    $colorsList = isset($_GET['colors']) && $_GET['colors'] !== '' ? explode(',', $_GET['colors']) : [];
    $brandsList = isset($_GET['brands']) && $_GET['brands'] !== '' ? explode(',', $_GET['brands']) : [];
    $fabricsList = isset($_GET['fabrics']) && $_GET['fabrics'] !== '' ? explode(',', $_GET['fabrics']) : [];
    $sleevesList = isset($_GET['sleeves']) && $_GET['sleeves'] !== '' ? explode(',', $_GET['sleeves']) : [];
    $necksList = isset($_GET['necks']) && $_GET['necks'] !== '' ? explode(',', $_GET['necks']) : [];
    $patternsList = isset($_GET['patterns']) && $_GET['patterns'] !== '' ? explode(',', $_GET['patterns']) : [];
    $occasionsList = isset($_GET['occasions']) && $_GET['occasions'] !== '' ? explode(',', $_GET['occasions']) : [];
    // badge carries New / Hot / Best Seller. The "NEW" item in the main nav has
    // always linked to ?badge=New and has always returned the whole catalogue,
    // because no branch of this file ever read it.
    $badgesList = isset($_GET['badges']) && $_GET['badges'] !== '' ? explode(',', $_GET['badges']) : [];
    $minPrice = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
    $maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
    $sortBy = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : '';
    
    $sql = "SELECT * FROM products WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)";
    // Limit to the side of the shop being browsed, but ONLY when no specific
    // category was asked for: a named collection already states its own scope,
    // and re-filtering it by the visitor's current side would answer an empty
    // grid to anyone who followed a men's link while the cookie still said
    // women. Returns '' entirely while a single audience is trading.
    if (empty($categoriesList)) {
        $sql .= shopGenderSqlFilter();
    }
    $params = [];

    if (!empty($categoriesList)) {
        // Resolve the requested category names to real ids plus everything nested
        // beneath them, so "Kurtis" includes Long/Short Kurtis and Anarkali Sets while
        // "Short Kurtis" returns ONLY short kurtis. This replaced a set of hardcoded
        // `category LIKE '%Kurti%'` rules that returned the wrong products and covered
        // only three category families; any new family needed a PHP edit to work.
        $catIds = categoryIdsForNames($pdo, $categoriesList);

        if (!empty($catIds)) {
            $in = str_repeat('?,', count($catIds) - 1) . '?';
            // The name fallback keeps products whose category_id was never backfilled
            // (or was nulled by a category deletion) reachable by their stored name.
            $nameIn = str_repeat('?,', count($categoriesList) - 1) . '?';
            $sql .= " AND (category_id IN ($in) OR (category_id IS NULL AND category IN ($nameIn)))";
            $params = array_merge($params, $catIds, $categoriesList);
        } else {
            // Unknown category name — match on the stored name only rather than
            // silently returning the whole catalogue.
            $nameIn = str_repeat('?,', count($categoriesList) - 1) . '?';
            $sql .= " AND category IN ($nameIn)";
            $params = array_merge($params, $categoriesList);
        }
    }
    
    if (!empty($badgesList)) {
        $in = str_repeat('?,', count($badgesList) - 1) . '?';
        $sql .= " AND badge IN ($in)";
        $params = array_merge($params, $badgesList);
    }

    if (!empty($colorsList)) {
        $in = str_repeat('?,', count($colorsList) - 1) . '?';
        // product_colors is the THIRD place a colour can live, and the only one
        // a shopper can actually select and buy.
        //
        // This matched `color` and `color_way` only — two free-text boxes on the
        // product itself — so a real colourway, with its own SKU, price, images
        // and per-size stock, was unmatchable: "Maroon" on product 23 returned an
        // empty grid, and only appeared to work for "Midnight Black" because that
        // name happened to also be typed into the product's own color_way box.
        // Colourways are the newer, richer feature, so the filter was blind to a
        // growing share of the catalogue precisely as the owner used it more.
        // EXISTS mirrors how the size filter reaches product_variants.
        $sql .= " AND (color IN ($in) OR color_way IN ($in)
                       OR EXISTS (
                              SELECT 1 FROM product_colors pc
                               WHERE pc.product_id = products.id
                                 AND pc.is_active = 1
                                 AND pc.color_name IN ($in)
                          ))";
        $params = array_merge($params, $colorsList, $colorsList, $colorsList);
    }

    // Size is the one filter that cannot be a column comparison: sellable sizes
    // are rows in product_variants, not a field on the product. EXISTS keeps it
    // to one row per product — a JOIN would return the same product once per
    // matching size and break both the count and the paging.
    //
    // Matched on the label with the "Size: " prefix stripped, so the URL carries
    // ?sizes=M rather than the storage format, and an available variant on an
    // available product is the only thing that counts.
    if (!empty($sizesList)) {
        $in = str_repeat('?,', count($sizesList) - 1) . '?';
        // Matched on size_code FIRST, which is what the chip now carries, so
        // ?sizes=XS finds a colour variant named "30/XS" and a plain one named
        // "Size: XS" alike — previously only an exact name match counted, so the
        // two spellings of one size could never be selected together.
        //
        // The name comparison stays for variants with no code of their own
        // (Free Size, 500ml) and for any row a backfill has not reached.
        $sql .= " AND EXISTS (
                      SELECT 1 FROM product_variants v
                       WHERE v.product_id = products.id
                         AND v.available = 1
                         AND (
                               v.size_code IN ($in)
                               OR TRIM(REPLACE(REPLACE(v.name, 'Size:', ''), 'size:', '')) IN ($in)
                             )
                  )";
        $params = array_merge($params, $sizesList, $sizesList);
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
    
    // NOTE: no `AND price >= ?` here. The price range is applied against the
    // BUYABLE price further down — see shopBuyablePricedPage().

    $saleOnly = !empty($_GET['sale']);
    if ($saleOnly) {
        $sql .= " AND mrp_price > 0 AND mrp_price > price";
    }

    $activeSearch = isset($_GET['search']) ? trim($_GET['search']) : (isset($_GET['q']) ? trim($_GET['q']) : '');
    if ($activeSearch !== '') {
        $sql .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ? OR fabric LIKE ? OR color LIKE ?)";
        // % and _ are LIKE wildcards, so a term containing either matched far
        // more than it said: "50% off" behaved as "50<anything>off", and a bare
        // "%" returned the entire catalogue under a heading claiming to be a
        // search for it. Escaped, so a shopper's punctuation is searched for
        // rather than executed.
        $sTerm = '%' . likeEscape($activeSearch) . '%';
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
    }

    try {
        if (shopNeedsBuyablePrice($minPrice, $maxPrice, $sortBy)) {
            $paged    = shopBuyablePricedPage($pdo, $sql, $params, $minPrice, $maxPrice, $sortBy, $limit, $offset);
            $products = $paged['rows'];
            $hasMore  = $paged['has_more'];
        } else {
            $pagedSql    = $sql . shopOrderBy($sortBy);
            // Fetch one extra row so we know whether there is another page without
            // running a COUNT(*) as well.  LIMIT 7 / 6 keeps the extra invisible.
            $pagedSql   .= " LIMIT ? OFFSET ?";
            $pagedParams = $params;
            $pagedParams[] = $limit + 1;
            $pagedParams[] = $offset;

            $stmt = $pdo->prepare($pagedSql);
            foreach ($pagedParams as $i => $val) {
                $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($i + 1, $val, $type);
            }
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Detect has_more and drop the extra row BEFORE the variant query —
            // no sense looking up variant details for a row the client never sees.
            $hasMore = count($products) > $limit;
            if ($hasMore) {
                array_pop($products);
            }
        }

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
        // One pair of queries for the whole page of results — see
        // productHoverImage(). Without this each card below would prime itself.
        productHoverImagePrime($products);
        productPriceRangePrime($products);
        foreach ($products as $p) {
            $p['variants'] = isset($variantsByProduct[$p['id']]) ? $variantsByProduct[$p['id']] : [];
            // Same country override as includes/product_card.php: a shopper abroad
            // sees the flat product_country_prices figure, or nothing to buy at all
            // when the product has no row for their country yet. Without this, the
            // AJAX "Load More" / sort / filter grid disagreed with the first page
            // of server-rendered cards the moment a second country was enabled.
            $pNotSoldHere = false;
            if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
                $pCountryPricing = productCountryPricing($p);
                if ($pCountryPricing === null) {
                    $pNotSoldHere = true;
                } else {
                    $p['price']     = $pCountryPricing['price'];
                    $p['mrp_price'] = $pCountryPricing['mrp'];
                }
            }
            $p['not_sold_here'] = $pNotSoldHere;
            // Never left empty: createProductCard() below falls back to a raw,
            // un-converted p.price when formatted_price is falsy, which would
            // print the INR figure with no symbol fix — the exact bug this whole
            // change exists to remove.
            /* The buyable figure, matching the server-rendered card.
               includes/product_card.php was fixed to show the lowest price a
               shopper can actually pay; this JSON feeds the Load More cards,
               and still sent products.price — so the first page of a grid read
               "From ₹10" and everything after it read "₹10" for the same
               product. One grid, two prices. */
            $pRange = function_exists('productPriceRange')
                ? productPriceRange($p)
                : ['min' => (float)$p['price'], 'varies' => false];
            $pShown = $pRange['min'] > 0 ? $pRange['min'] : (float)$p['price'];
            $p['price_varies']    = $pRange['varies'] ? 1 : 0;
            $p['formatted_price'] = $pNotSoldHere ? 'Not available in your country' : formatPrice($pShown);
            $pMrp = (float)($p['mrp_price'] ?? 0);
            /* The saving is measured against the price actually shown, and is
               withdrawn entirely once that price is a "From".
               ────────────────────────────────────────────────────────────────
               These three lines read products.price while formatted_price above
               already carried the buyable figure, so a Load More card claimed
               "22% OFF" over "₹1,600 ₹1,100" — a genuine 31% — and a card whose
               price had become a floor still showed a strikethrough MRP that
               only holds for one size. includes/product_card.php settles it the
               same way; the two renderers of this grid have to agree. */
            $pVaries      = (bool)$pRange['varies'];
            $pHasDiscount = !$pNotSoldHere && !$pVaries && $pMrp > $pShown && $pShown > 0;
            $p['has_discount'] = $pHasDiscount;
            $p['discount_percent'] = $pHasDiscount ? (int)round((($pMrp - $pShown) / $pMrp) * 100) : 0;
            $p['formatted_mrp'] = $pHasDiscount ? formatPrice($pMrp) : '';
            // Same two things the server-rendered card uses (includes/product_card.php):
            // the WebP copy, and real alt text. Without them the cards appended by
            // "load more" served the full-size original and described it only by name.
            $p['image_webp'] = !empty($p['image']) ? webpUrlIfExists('products', $p['image']) : null;
            $p['image_alt']  = productImageAlt($p);
            // Sold-out state, worked out the same way includes/product_card.php
            // does it. Without this the JSON carried no notion of stock at all,
            // so a card appended by Load More — or the whole grid after a sort or
            // a filter, which re-renders through this path — lost its "Sold Out"
            // badge and offered a normal Add to Bag on something unbuyable.
            $p['is_sold_out'] = ((int)($p['available'] ?? 1) === 1 && !empty($p['track_stock']))
                ? (productSellableStock($pdo, $p) <= 0)
                : false;

            // Only what the card actually draws leaves the server.
            //
            // The whole products row went out as JSON to any visitor — 63 fields,
            // including cost_price, total_stock, damage_stock, sold_offline,
            // sold_online, stock_qty, hsn_code, gst_rate, atelier_code and
            // sourcing. Anyone could read the shop's margins, its true inventory
            // position and its suppliers by opening /shop?ajax=1, and this path
            // serves the whole grid after every sort, filter and Load More.
            //
            // The stock columns are still needed ABOVE, because productSellableStock()
            // and the sold-out flag are computed server-side — so the projection
            // happens here, at the encode boundary, rather than by narrowing the
            // SELECT. The field list is what createProductCard() reads, nothing more.
            $cardFields = [
                'id', 'name', 'seo_url', 'category', 'price', 'image', 'image_webp', 'image_alt',
                'emoji', 'badge', 'video_url', 'available', 'is_deleted',
                'formatted_price', 'formatted_mrp', 'has_discount', 'discount_percent', 'price_varies',
                'is_sold_out', 'not_sold_here',
            ];
            $card = array_intersect_key($p, array_flip($cardFields));

            // The hover photograph, so a Load More card behaves like one that
            // arrived with the page. Only the filename leaves the server, the
            // same as 'image' above — nothing about stock or cost is added here.
            $hoverImg = productHoverImage($p);
            if ($hoverImg !== null) {
                $card['image_hover']      = $hoverImg;
                $card['image_hover_webp'] = webpUrlIfExists('products', $hoverImg) ?: null;
            }

            // Variants likewise: the card prints size names and nothing else, but
            // these rows were emitted by SELECT *, carrying per-size stock_qty and
            // cost figures with them.
            $card['variants'] = array_map(
                static fn(array $v): array => ['name' => $v['name'] ?? ''],
                $p['variants'] ?? []
            );

            $formattedProducts[] = $card;
        }

        @ob_clean();
        echo json_encode([
            'success' => true,
            'products' => $formattedProducts,
            'has_more' => $hasMore
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (PDOException $e) {
        @ob_clean();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Named per audience: /men is its own indexable address, and a browser tab or a
// search result reading "Collections" says nothing about which half it opened.
$pageTitle = (function_exists('currentShopGender') && currentShopGender() === 'men')
    ? "Menswear"
    : "Collections";
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'shop'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = cacheBustedUploadUrl(SITE_URL . '/uploads/gallery/' . $seoRow['og_image']); }
    }
} catch (PDOException $e) {}

// The seo_settings row above belongs to /shop, which is the womenswear catalogue.
// Applying its title to /men would give both addresses the same tab text and the
// same search-result headline — two pages presenting as one, which is the exact
// duplicate problem the per-category SEO below was written to solve.
if (function_exists('currentShopGender') && currentShopGender() === 'men') {
    // Deliberately worded differently from the /men landing page, which carried
    // this exact sentence byte for byte — one description on two indexable
    // addresses, which is the duplicate problem the comment above describes,
    // reintroduced one line below it. This page is the full grid, so it says so.
    // Categories come from live stock: the old text advertised "tailoring",
    // which the catalogue has never contained.
    $shopMenCats = liveCategoryNamesForGender($pdo, 'men');
    $shopMenList = $shopMenCats ? humanList($shopMenCats) : 'shirts and trousers';

    // "All Menswear", not "Menswear": /men is the landing page and this is the
    // full filterable grid. Identical titles on the two would give them one
    // search-result headline between them — the same collision as the
    // descriptions, which is what the note above was warning about.
    $pageTitle       = 'All Menswear — ' . trimToLength(compactTitleList($shopMenCats ?: ['shirts', 'trousers']), 40);
    $metaDescription = trimToLength(
        'Browse every menswear piece at ' . SHOP_NAME . ' — ' . $shopMenList
        . '. Filter by size, colour and price, with easy returns across India.', 155);
}

// ── Per-category SEO ─────────────────────────────────────────────────────────
// Without this every collection carried the same title, the same H1 ("Dievon
// Curated Catalog") and the same description, so to a search engine they were
// one page repeated and none could rank for its own term. categorySeo() writes
// a different one for each from the products actually in the category, and any
// value the owner types in the admin overrides it.
$categorySeo = null;
$categoryTrail = [];
$activeSearchEarly = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));

// ── The sale view gets a title of its own ───────────────────────────────────
// ?sale=1 has its own canonical URL and its own hero, but it inherited the
// shop's title — so the sale and the full catalogue went to Google as two
// pages called "Shop Luxury Collections", competing with each other and
// telling a searcher nothing about which one was the sale.
//
// The best discount is read from the catalogue rather than typed anywhere, so
// the title cannot promise 50% off after the 50% piece has sold out.
if (!empty($_GET['sale']) && $activeSearchEarly === '') {
    $saleBest = 0;
    $saleTotal = 0;
    try {
        // Scoped to the audience being viewed, exactly like the listing below.
        //
        // Without this the count and the headline discount were read from the
        // WHOLE catalogue: with a men's shirt at 13% off and a women's kurti at
        // 37%, the men's sale page listed the shirt correctly and then titled
        // itself "Up to 37% Off" — a discount not available anywhere on that
        // page. Returns '' while a single audience trades, so this is inert
        // until menswear goes live.
        $saleGenderSql = function_exists('shopGenderSqlFilter') ? shopGenderSqlFilter() : '';
        $saleAgg = $pdo->query(
            "SELECT COUNT(*) AS n, MAX(ROUND(((mrp_price - price) / mrp_price) * 100)) AS best
               FROM products
              WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                AND mrp_price IS NOT NULL AND mrp_price > price" . $saleGenderSql
        )->fetch(PDO::FETCH_ASSOC);
        $saleTotal = (int)($saleAgg['n'] ?? 0);
        $saleBest  = (int)($saleAgg['best'] ?? 0);
    } catch (PDOException $e) {}

    $pageTitle = $saleBest > 0
        ? 'Sale — Up to ' . $saleBest . '% Off | ' . SHOP_NAME
        : 'Sale | ' . SHOP_NAME;

    $metaDescription = $saleTotal > 0
        ? 'Shop the ' . SHOP_NAME . ' sale — ' . $saleTotal . ' piece' . ($saleTotal === 1 ? '' : 's')
          . ' reduced, up to ' . $saleBest . '% off. Detailed size guides, secure checkout and easy returns.'
        : 'The ' . SHOP_NAME . ' sale. Nothing is reduced right now — browse the new season in the meantime.';

    // An empty sale listing is a thin page with nothing to rank for, and it would
    // compete with the collections for no gain. It becomes indexable again by
    // itself the moment something is reduced.
    if ($saleTotal === 0) { $noindex = true; }
}

if ($activeCategoryRow && $activeSearchEarly === '' && empty($_GET['sale'])) {
    $categorySeo   = categorySeo($pdo, $activeCategoryRow);
    $categoryTrail = categoryTrail($pdo, $activeCategoryRow);
    $pageTitle       = $categorySeo['title'];
    $metaDescription = $categorySeo['description'];
    if ($categorySeo['image'] !== '') {
        $ogImage = cacheBustedUploadUrl(SITE_URL . '/uploads/gallery/' . $categorySeo['image']);
    }
}

$initialProducts = [];
try {
    // Both spellings: ?category=<name> (one, used by links) and ?categories=a,b
    // (many, used by the sidebar and by "load more"). The plural was read only by
    // the AJAX branch, so arriving on ?categories=Kurtis painted the whole
    // catalogue and only the SECOND page of results was filtered.
    $initCategoryNames = isset($_GET['categories']) && $_GET['categories'] !== ''
        ? array_values(array_filter(array_map('trim', explode(',', (string)$_GET['categories']))))
        : [];
    $initCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
    if ($initCategory !== '' && !in_array($initCategory, $initCategoryNames, true)) {
        $initCategoryNames[] = $initCategory;
    }
    $initSearch = isset($_GET['search']) ? trim($_GET['search']) : (isset($_GET['q']) ? trim($_GET['q']) : '');
    $initSaleOnly = !empty($_GET['sale']);

    $sqlInit = "SELECT * FROM products WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)";
    // Same rule as the AJAX branch: only scope by audience when the visitor has
    // not asked for a named collection.
    if (empty($initCategoryNames)) {
        $sqlInit .= shopGenderSqlFilter();
    }
    if ($initSaleOnly) {
        $sqlInit .= " AND mrp_price > 0 AND mrp_price > price";
    }
    if ($initCategoryNames) {
        // Resolve to real category ids plus everything nested beneath, exactly as
        // the AJAX branch above does.
        //
        // This used to be a set of `category LIKE '%Kurti%'` rules, which
        // disagreed with the AJAX query: the first page of /shop?category=Short Kurtis
        // matched every kurti, then "load more" fetched the exact subtree and
        // re-appended products already on screen. It also covered only three
        // hardcoded families, so any new category fell through to an exact name
        // match and lost its subcategories.
        $initCatIds  = categoryIdsForNames($pdo, $initCategoryNames);
        $quotedNames = implode(',', array_map([$pdo, 'quote'], $initCategoryNames));
        if (!empty($initCatIds)) {
            $idIn = implode(',', array_map('intval', $initCatIds));
            // Name fallback for products whose category_id was never backfilled.
            $sqlInit .= " AND (category_id IN ($idIn) OR (category_id IS NULL AND category IN ($quotedNames)))";
        } else {
            $sqlInit .= " AND category IN ($quotedNames)";
        }
    }
    if ($initSearch !== '') {
        // Same LIKE-wildcard escaping as the AJAX branch, or the two paths would
        // answer different result sets for a term containing % or _.
        $sQuoted = $pdo->quote('%' . likeEscape($initSearch) . '%');
        $sqlInit .= " AND (name LIKE $sQuoted OR description LIKE $sQuoted OR category LIKE $sQuoted OR fabric LIKE $sQuoted OR color LIKE $sQuoted)";
    }

    // Attribute filters on the FIRST page load.
    //
    // Every filter used to live only in the AJAX branch at the top of
    // this file, which runs for "load more" requests. Arriving directly
    // on /shop?occasions=Wedding or ?fabrics=Silk therefore showed the
    // whole catalogue and read as a broken filter — and any link the
    // owner shared or advertised behaved the same way.
    //
    // The columns are listed here rather than taken from the request so
    // an unexpected parameter can never name a column. Values are quoted
    // through PDO to match the surrounding string-built statement.
    // Colour is listed against TWO columns, matching the AJAX branch above.
    //
    // It was mapped to `color` alone here, so the first page of results ignored
    // color_way while every subsequent page honoured it. A shopper picking a
    // shade recorded only as a Color Way saw "no products found", then watched
    // matches appear if they scrolled — the filter offered the colour and the
    // opening query could not find it.
    foreach ([
        'occasions' => ['occasion'],
        'badges'    => ['badge'],
        'fabrics'   => ['fabric'],
        'colors'    => ['color', 'color_way'],
        'brands'    => ['brand'],
        'sleeves'   => ['sleeve'],
        'necks'     => ['neck'],
        'patterns'  => ['pattern'],
    ] as $initParam => $initColumns) {
        $initValues = isset($_GET[$initParam]) && $_GET[$initParam] !== ''
            ? array_filter(array_map('trim', explode(',', (string)$_GET[$initParam])))
            : [];
        if (!$initValues) { continue; }
        $quoted = implode(',', array_map([$pdo, 'quote'], $initValues));
        $parts  = [];
        foreach ($initColumns as $col) { $parts[] = "$col IN ($quoted)"; }
        // Colour also lives in product_colors, and this copy must reach it too.
        //
        // Exactly the drift the comment above describes, one layer further in:
        // the AJAX branch now matches colourways through product_colors, so
        // without this the FIRST page of /shop?colors=Maroon answered "no
        // products found" and matches only appeared once the shopper scrolled.
        if ($initParam === 'colors') {
            $parts[] = "EXISTS (
                            SELECT 1 FROM product_colors pc
                             WHERE pc.product_id = products.id
                               AND pc.is_active = 1
                               AND pc.color_name IN ($quoted)
                        )";
        }
        $sqlInit .= ' AND (' . implode(' OR ', $parts) . ')';
    }

    // Size again for the first page. The loop above maps a parameter onto a
    // products column, and size has none — it lives in product_variants — so it
    // is applied separately. Without this, arriving on /shop?sizes=M rendered the
    // whole catalogue and only started filtering once something was clicked.
    $initSizes = isset($_GET['sizes']) && $_GET['sizes'] !== ''
        ? array_filter(array_map('trim', explode(',', (string)$_GET['sizes'])))
        : [];
    if ($initSizes) {
        $quotedSizes = implode(',', array_map([$pdo, 'quote'], $initSizes));
        // Must match the AJAX branch above exactly — size_code first, then the
        // name for anything without one. When only this copy compared names, a
        // chip found the products whose variants happened to be spelled the same
        // way and silently missed the rest: /shop?sizes=XS counted three products
        // and rendered two, dropping the one whose variant is named "30/XS".
        $sqlInit .= " AND EXISTS (
                          SELECT 1 FROM product_variants v
                           WHERE v.product_id = products.id
                             AND v.available = 1
                             AND (
                                   v.size_code IN ($quotedSizes)
                                   OR TRIM(REPLACE(REPLACE(v.name, 'Size:', ''), 'size:', '')) IN ($quotedSizes)
                                 )
                      )";
    }

    $initMin  = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
    $initMax  = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
    $initSort = isset($_GET['sort_by']) ? trim((string)$_GET['sort_by']) : '';

    // Page numbers are honoured here as well as in the AJAX branch. Without this
    // every ?page=N returned the identical first six products at 200, so a crawler
    // following them saw one page of products under an unlimited number of addresses.
    $initPageNo = max(1, (int)($_GET['page'] ?? 1));
    $initOffset = ($initPageNo - 1) * 6;

    // The first page must apply the price range and the price sort to the same
    // figure the card prints — see shopBuyablePricedPage(). Both branches of
    // this file went through products.price, which is why /shop?min_price=1400
    // could answer "no pieces" while a card on the unfiltered grid advertised
    // "From ₹1,500".
    if (shopNeedsBuyablePrice($initMin, $initMax, $initSort)) {
        $initPaged       = shopBuyablePricedPage($pdo, $sqlInit, [], $initMin, $initMax, $initSort, 6, $initOffset);
        $initialProducts = $initPaged['rows'];
        $shopTotalRows   = (int)($initPaged['total'] ?? count($initialProducts));
    } else {
        $initialProducts = $pdo->query($sqlInit . shopOrderBy($initSort) . " LIMIT 6 OFFSET " . (int)$initOffset)->fetchAll();
        /* How many pages exist, so the grid can LINK to them.
           ────────────────────────────────────────────────────────────────────
           The grid renders six server-side and loads the rest over AJAX, and
           nothing ever emitted an <a href="?page=N">. ?page=N works — it is
           honoured a few lines above — but no link pointed at it, and
           robots.php disallows the ajax endpoint, so a crawler could not reach
           page two by any route. Measured by walking every internal link from
           the homepage: 15 of 25 products reachable, 10 findable only by
           knowing the URL. On a shop that is ten garments Google cannot rank.

           COUNT over the same WHERE, with the SELECT list swapped out — the
           filters are already baked into $sqlInit, so the count cannot drift
           from the rows beneath it. */
        try {
            $countSql = preg_replace('/^SELECT \*/', 'SELECT COUNT(*)', $sqlInit, 1);
            $shopTotalRows = (int)$pdo->query($countSql)->fetchColumn();
        } catch (PDOException $e) { $shopTotalRows = count($initialProducts); }
    }
} catch (PDOException $e) {}
$shopTotalRows = $shopTotalRows ?? count($initialProducts ?? []);
$shopTotalPages = max(1, (int)ceil($shopTotalRows / 6));
$shopPageNo     = max(1, (int)($_GET['page'] ?? 1));


// ── Empty collections ───────────────────────────────────────────────────────
// A category that exists in the table but has zero products is a page Google
// should never index.  Answering 200 with an empty grid was a soft-404 — the
// page looked real to a crawler but offered nothing.  Now it is a real 404 so
// it drops from the index and does not compete with stocked pages for rank.
//
// AJAX requests on an empty collection need to return the empty JSON payload
// (has_more=false, products=[]) so the "load more" button can grey itself out —
// a 404 JSON body would break the client.  And pages reached through the
// sidebar multi-category filter (?categories=Kurtis,3 Piece Suits) are
// legitimate filter combinations, not a named collection that should 404.
if ($activeCategoryRow
    && empty($initialProducts)
    && empty($_GET['ajax'])
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $shopNotFound();
}

// ── One indexable URL per collection ────────────────────────────────────────
// A shopper can reach this page under a large number of addresses: a collection,
// a collection plus any combination of fabric/colour/badge/occasion/price, a
// sort order, a page number, a search. Only a handful of those deserve to be in
// the index, and every other one has to point at the address that does.
$shopIsSearch = !empty($_GET['search']) || !empty($_GET['q']);

if ($activeCategoryRow && !$shopIsSearch) {
    // One canonical per collection — the clean URL — so ?category=Kurtis and
    // /collections/kurtis consolidate into a single indexed page instead of
    // competing with each other as duplicates. Filters and sorts on top of a
    // collection point here too; they are refinements of this page, not pages.
    $canonicalUrl = categoryUrl($activeCategoryRow);
} elseif (!empty($_GET['sale']) && empty($_GET['category']) && !$shopIsSearch) {
    $canonicalUrl = SITE_URL . '/shop?sale=1';
} else {
    $canonicalUrl = SITE_URL . '/shop';
}

// Page 2 onwards is its own page of results, not a duplicate of page 1, so it
// gets its own canonical. Collapsing it to page 1 would tell Google the deeper
// products do not exist.
$initPage = max(1, (int)($_GET['page'] ?? 1));
if ($initPage > 1) {
    $canonicalUrl .= (strpos($canonicalUrl, '?') === false ? '?' : '&') . 'page=' . $initPage;
}

// ── What not to index ───────────────────────────────────────────────────────
// Internal search results are low-value, near-infinite-combination pages.
if ($shopIsSearch) {
    $noindex = true;
}

// One rule that covers every thin page this file can produce: an empty
// collection, a filter combination nothing matches, a sale with nothing on sale,
// a page number past the end of the results. Each of those used to answer 200
// with an empty grid, no robots directive and a canonical pointing at itself.
if (empty($initialProducts)) {
    $noindex = true;
}

// noindex and a canonical pointing SOMEWHERE ELSE are a contradiction: Google
// may follow the canonical and apply the noindex to the target. A search page
// carrying noindex plus canonical=/shop was therefore at risk of deindexing the
// shop itself. Anything excluded from the index points only at itself.
if (!empty($noindex)) {
    // Built on the TRUSTED host (see trustedSiteHost() in config.php), never the
    // browser's claimed Host header, which a visitor could point anywhere.
    $canonicalUrl = currentPageUrl();
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
// Defined BEFORE the try, not inside it.
//
// This was assigned at the very END of the block below, so any query in between
// that threw left it undefined — and the hero further down reads it. PHP 8 then
// printed "Warning: Undefined variable $activeSearch" into the storefront HTML
// and rendered the heading as Search: "" with the shopper's own term missing.
// A value the page cannot render without does not belong behind a try.
$activeSearch = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));

try {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
    // Only the side of the shop being browsed. Without this the men's filter
    // sidebar offered Kurtis and Anarkali Sets as tick boxes — the exact mixing
    // the split exists to prevent. categoryMatchesShopGender() lets unisex
    // through both ways, and the whole filter is inert while one audience trades.
    if (shopGenderSelectorEnabled()) {
        $categories = array_values(array_filter(
            $categories,
            fn($c) => categoryMatchesShopGender((int)$c['id'])
        ));
    }

    // Categories with nothing in them are dropped from the sidebar.
    //
    // The list was every row in the table, so a collection created but not yet
    // stocked appeared as a tick box that could only ever return "no products
    // found". A filter option that cannot match anything is not a filter, and it
    // also leaks unfinished collections to shoppers before they are ready.
    //
    // This runs regardless of the audience split, which is what makes an empty
    // men's category disappear from the women's sidebar even while only one side
    // is trading — the gender filter above is inert until both sides have stock.
    // Counted through categorySeo(), the same helper the category pages and the
    // sitemap already use, so "empty" means the same thing everywhere.
    if ($categories) {
        $categories = array_values(array_filter($categories, function ($c) use ($pdo) {
            try {
                return (int)categorySeo($pdo, $c)['product_count'] > 0;
            } catch (Throwable $e) {
                // Never hide a category because the count failed — a visible
                // filter that returns nothing beats a category nobody can reach.
                return true;
            }
        }));
    }
    
    /* Brands a shopper can actually buy right now.
       ────────────────────────────────────────────────────────────────────────
       This was the literal list ['Dievon'], on the reasoning that the shop sells
       only its own label. But there is a whole Brands screen in the admin, it
       writes to a `brands` table, and that table already feeds the brand
       dropdown on the product form — so the owner can add a second label and put
       products under it, and the shop's Brand filter would still offer only
       "Dievon". The one brand a shopper could tick was the one that matched
       everything, and the new label could not be filtered for at all.

       Built from the live catalogue, the same way the colour list below is:
       whatever is genuinely on sale is offered, and nothing else. With a
       single-label shop this produces exactly the old list, so nothing changes
       today — it simply stops being wrong the moment a second brand exists.

       Scoped to the side of the shop being browsed, so menswear brands do not
       appear as filters on the womenswear side. */
    $brands = [];
    try {
        // shopGenderSqlFilter() is the same scoping clause the colour list below
        // uses, so the Brand filter cannot offer a menswear label on the
        // womenswear side (or the reverse).
        $brandGenderSql = shopGenderSqlFilter();
        $brands = $pdo->query(
            "SELECT DISTINCT brand FROM products
              WHERE brand IS NOT NULL AND brand <> ''
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                $brandGenderSql
           ORDER BY brand ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // A filter that fails to build must not take the shop down with it.
        $brands = [];
    }

    // Colours a shopper can actually buy right now.
    //
    // Two things were wrong here. The master list from product_attributes was
    // merged in wholesale, so a colour added in Colours & Sizes appeared as a
    // tick box even when no product used it — a filter option that can only
    // ever return "no products found". And the product query had no
    // available/is_deleted guard, so colours belonging to unpublished or
    // ARCHIVED products were offered too.
    //
    // Built from live products only. The master list still decides the ORDER
    // and the preferred spelling, which is what it is genuinely useful for;
    // anything in it that nobody stocks simply does not appear.
    // The hex is read too. product_attributes.code is a colour picker in the
    // admin and is already drawn as a swatch there, but the storefront never
    // looked at it — so the filter was a plain text list for a fashion shop,
    // where colour is the thing people scan for.
    //
    // Guarded, because the rows seeded before the picker existed hold slugs
    // ("emerald-green") rather than hex. Anything that is not a real #rrggbb is
    // shown as text only, exactly as before, instead of rendering a broken chip.
    $dbColors    = [];
    $colorHexMap = [];
    try {
        foreach ($pdo->query("SELECT name, code FROM product_attributes WHERE attr_type='color' ORDER BY name ASC") as $ca) {
            $dbColors[] = $ca['name'];
            $hex = trim((string)($ca['code'] ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                $colorHexMap[ucwords(strtolower(trim((string)$ca['name'])))] = $hex;
            }
        }
    } catch (PDOException $e) {}

    // color AND color_way, because the filter MATCHES on both.
    //
    // The SQL further up reads "color IN (...) OR color_way IN (...)", but this
    // list was built from `color` alone — so a garment whose shade is recorded
    // only in Color Way could be matched by the filter and never offered by it.
    // Half the feature was wired. A product page already falls back to color_way
    // when color is blank, so the two are the same idea to a shopper.
    // Scoped to the side of the shop being browsed, and reading product_colors
    // as well.
    //
    // Two gaps met here. The list ignored gender while the GRID does not, so the
    // menswear shop offered every womenswear colour: 6 of 10 chips answered an
    // empty grid on a shop that plainly had stock, which reads as a broken site
    // rather than an empty result — and each was a crawlable URL returning 200
    // with nothing on it. And it never read product_colors, so a buyable
    // colourway was not offered at all (see the match clause above).
    /* Three queries merged in PHP, NOT one UNION.
       ────────────────────────────────────────────────────────────────────────
       This was a single UNION across products.color / products.color_way and
       product_colors.color_name. Those columns do not share a collation —
       products is utf8mb4_general_ci and product_colors was created by
       config/db.php as utf8mb4_unicode_ci — so MariaDB refused the statement
       outright with SQLSTATE[HY000] 1271 "Illegal mix of collations for
       operation 'UNION'". It is a metadata error, so it fired on every single
       shop page load regardless of what was in the tables.

       That exception was not caught here; it unwound to the catch at the end of
       this whole block, which meant EVERY list built after this line was
       silently abandoned. The shop shipped with only two working filters:

         missing  colour, size, fabric, sleeve, neck, pattern, occasion
         left     category, brand, price

       Worse than absent: a URL still carrying one of them (?fabrics=Silk, a
       shared link, the home page's occasion pills) rendered correctly on the
       server-drawn first page, and then the first sort, filter click or Back
       press rebuilt the grid from the sidebar — which has no box to read the
       filter back off — so the filter silently evaporated, the address bar was
       rewritten without it, and the whole catalogue appeared.

       Merging in PHP removes the cross-table comparison entirely, so no future
       collation drift on any of these three tables can take the sidebar down
       again. Each source is also guarded on its own, below. */
    $colorGenderSql = shopGenderSqlFilter();
    $prodColors = [];
    $colorSources = [
        "SELECT DISTINCT color AS c FROM products
          WHERE color IS NOT NULL AND color <> ''
            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
            $colorGenderSql",
        "SELECT DISTINCT color_way AS c FROM products
          WHERE color_way IS NOT NULL AND color_way <> ''
            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
            $colorGenderSql",
        "SELECT DISTINCT pc.color_name AS c
           FROM product_colors pc
           JOIN products p ON p.id = pc.product_id
          WHERE pc.color_name IS NOT NULL AND pc.color_name <> ''
            AND pc.is_active = 1
            AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
            " . shopGenderSqlFilter('p'),
    ];
    foreach ($colorSources as $colorSql) {
        try {
            foreach ($pdo->query($colorSql . " ORDER BY c ASC")->fetchAll(PDO::FETCH_COLUMN) as $cVal) {
                $prodColors[] = $cVal;
            }
        } catch (PDOException $e) {
            // One colour source failing must not cost the shop its other six
            // filters — the whole point of the guard.
        }
    }
    sort($prodColors, SORT_NATURAL | SORT_FLAG_CASE);

    $norm = fn($c) => ucwords(strtolower(trim((string)$c)));
    $liveSet = [];
    foreach ($prodColors as $pc) { $liveSet[$norm($pc)] = true; }

    // Master list first (its order, its spelling), then any live colour it does
    // not know about — so a colour typed straight onto a product is still offered.
    $colors = [];
    foreach ($dbColors as $dc) { if (isset($liveSet[$norm($dc)])) { $colors[] = $norm($dc); } }
    foreach ($prodColors as $pc) { $colors[] = $norm($pc); }
    $colors = array_values(array_unique(array_filter($colors)));

    // Sizes a shopper can actually buy.
    //
    // This read the master list in Colours & Sizes — and then never rendered it,
    // so there was no size filter on the shop at all. Sellable sizes do not live
    // in that table anyway: they are rows in product_variants ("Size: S"), which
    // is what the cart validates against. Building the filter from the master
    // list would offer sizes nobody stocks and miss ones that are on sale.
    //
    // The ORDER comes from the size ladder, because XS/S/M/L/XL is a sequence and
    // sorting it alphabetically would read as nonsense.
    //
    // That order used to be read from product_attributes (attr_type='size') — a
    // SECOND master list, kept by hand, which stopped at XL while the ladder runs
    // to 5XL. Everything from 2XL up therefore fell outside the ordering and was
    // appended in whatever order the database happened to return it. Two lists
    // that had to agree, maintained separately, and they did not.
    //
    // The ladder is already the single source for the product form, the colour
    // Sizes & Stock grid, the size guide and the product page, so it is the one
    // that decides here too. Both ladders are folded in: the codes are identical,
    // and a menswear label ("40/M") lands on the same rung as its womenswear
    // twin ("34/M"). Anything sellable the ladder does not know — Free Size, a
    // renamed size — is still appended rather than dropped.
    $ladderPos = [];
    foreach (['women', 'men'] as $ladderGender) {
        foreach (sizeLadderFor($ladderGender) as $rungIndex => $rung) {
            $ladderPos[strtoupper((string)$rung['code'])]  ??= $rungIndex;
            $ladderPos[strtoupper((string)$rung['label'])] ??= $rungIndex;
        }
    }

    $liveSizes = [];
    try {
        $liveSizes = $pdo->query(
            "SELECT DISTINCT v.name, v.size_code
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
              WHERE v.available = 1
                AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                AND v.name IS NOT NULL AND v.name <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // Variants are stored as "Size: M" for display; the label alone is what a
    // filter should show and what the URL should carry.
    $sizeLabel = function (string $v): string {
        return trim(preg_replace('/^\s*size\s*:\s*/i', '', $v));
    };

    // One chip per SIZE, keyed on size_code — not one per spelling.
    //
    // Chips were built from the variant name, and the same size is written two
    // ways across the shop: a colour variant carries the ladder label ("30/XS")
    // while a plain size carries the short code ("XS"). The filter therefore
    // offered both as separate buttons for one garment size, and ticking either
    // hid every product that used the other spelling — worse the more colour
    // products exist.
    //
    // The code is the thing that actually means "extra small", and it is the
    // same on both ladders, so a men's 36/XS and a women's 30/XS collapse onto
    // the one chip. It is also the label shown: no single bust or chest number
    // can front a chip that has to cover both.
    //
    // Sizes with no code — Free Size, 500ml — keep their own name as before.
    $liveSizeSet = [];
    foreach ($liveSizes as $ls) {
        $code = strtoupper(trim((string)($ls['size_code'] ?? '')));
        if ($code !== '') {
            $liveSizeSet[$code] = $code;
            continue;
        }
        $lbl = $sizeLabel((string)$ls['name']);
        if ($lbl !== '') { $liveSizeSet[strtoupper($lbl)] ??= $lbl; }
    }

    // Sorted by rung, with everything the ladder does not recognise kept together
    // at the end in alphabetical order rather than in database order.
    $sizes = [];
    foreach ($liveSizeSet as $key => $label) {
        $sizes[] = ['pos' => $ladderPos[$key] ?? PHP_INT_MAX, 'key' => $key, 'label' => $label];
    }
    usort($sizes, static function ($a, $b) {
        if ($a['pos'] !== $b['pos']) { return $a['pos'] <=> $b['pos']; }
        return strcmp($a['key'], $b['key']);
    });
    $sizes = array_column($sizes, 'label');

    // Filter options come from the LIVE catalogue only (published, not deleted).
    // They used to read every row, so a value that existed solely on an
    // unavailable or deleted product — "Lather" and "Diwali" were exactly that,
    // both only on a row with available=0 AND is_deleted=1 — was still offered
    // as a filter chip that could never match anything.
    // Gender-scoped for the same reason the colour list above is: these feed the
    // fabric, sleeve, neck, pattern and occasion chips, and the grid they filter
    // is already scoped. Unscoped, the menswear sidebar offered 7 dead fabric
    // chips (Brocade, Georgette, Linen, Rayon, Rib Knit, Satin, Silk) plus
    // womenswear-only Boat Neck, Scoop Neck, Resort and Party — more than half
    // the sidebar answering an empty grid.
    $liveWhere = "available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)" . shopGenderSqlFilter();

    /* One helper, one guard per list.
       ────────────────────────────────────────────────────────────────────────
       These were five bare $pdo->query() calls sharing the single try/catch
       that wraps this whole block, so ONE of them failing silently emptied
       every list after it — which is exactly how a collation error in the
       colour query above cost the shop seven of its ten filters. A filter that
       cannot build must cost only itself. */
    $attrOptions = static function (string $column) use ($pdo, $liveWhere): array {
        try {
            $rows = $pdo->query(
                "SELECT DISTINCT `$column` FROM products
                  WHERE `$column` != '' AND `$column` IS NOT NULL AND $liveWhere
               ORDER BY `$column` ASC"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map(static fn($v) => ucwords(strtolower(trim((string)$v))), $rows)
        )));
    };

    // The column names are literals here, never request data — nothing a
    // visitor sends can name a column.
    $fabrics   = $attrOptions('fabric');
    $sleeves   = $attrOptions('sleeve');
    $necks     = $attrOptions('neck');
    $patterns  = $attrOptions('pattern');
    $occasions = $attrOptions('occasion');
} catch (PDOException $e) {}
?>

<?php if ($categorySeo): ?>
<!-- ══ Breadcrumb Structured Data ══════════════════════════
     Built from the real ancestor chain, so Kurtis › Short Kurtis reports both
     levels instead of flattening every collection to a single step. -->
<?php
    $crumbs = [
        ['name' => 'Home', 'url' => SITE_URL . '/'],
        ['name' => 'Shop', 'url' => SITE_URL . '/shop'],
    ];
    foreach ($categoryTrail as $tc) {
        $crumbs[] = ['name' => $tc['name'], 'url' => categoryUrl($tc)];
    }
    $crumbItems = [];
    foreach ($crumbs as $i => $c) {
        $crumbItems[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url']];
    }
?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $crumbItems,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>

<?php endif; ?>

<?php
// ── Hero image ──────────────────────────────────────────────────────────────
// lookbook_2.png is a photograph of a woman carrying a handbag, and it was
// hardcoded here — so /men led with womenswear imagery above a menswear menu,
// which undoes the split more effectively than any wrong product would.
//
// A photograph cannot be sorted by audience automatically, so the men's hero is
// taken from whichever banner the owner has tagged for men. Until one exists the
// original image stays: a womenswear photo is wrong on /men, but an empty black
// box is worse, and this fixes itself the moment a men's banner is uploaded.
$shopHeroImage = lookbookUrl(2);
if (function_exists('currentShopGender') && currentShopGender() === 'men') {
    try {
        $hb = $pdo->query(
            "SELECT image FROM banners
              WHERE status = 'Active' AND gender IN ('men','both') AND image <> ''
           ORDER BY sort_order ASC, id DESC LIMIT 1"
        )->fetchColumn();
        if ($hb) { $shopHeroImage = SITE_URL . '/uploads/products/' . rawurlencode($hb); }
    } catch (PDOException $e) {
        // gender column not added yet — keep the default
    }
}
?>
<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image shop-hero section-mb-sm" style="--hero-bg-image: url('<?= htmlspecialchars($shopHeroImage) ?>')">
    <div class="container">
        <?php if ($activeSearch !== ''): ?>
            <span class="luxury-hero-eyebrow">Search Results</span>
            <h1>Search: "<?= htmlspecialchars($activeSearch) ?>"</h1>
            <p>Showing curated results matching your query. <a href="<?= SITE_URL ?>/shop" style="color: var(--color-accent); text-decoration: underline; font-weight: 600; margin-left: 8px;">Clear Search ✕</a></p>
        <?php elseif (!empty($_GET['sale'])): ?>
            <span class="luxury-hero-eyebrow">Limited Time</span>
            <h1>Sale</h1>
            <p>Discounted atelier pieces, while stocks last.</p>
        <?php elseif ($categorySeo): ?>
            <nav class="shop-breadcrumb" aria-label="Breadcrumb">
                <ol class="shop-breadcrumb-list">
                    <li><a href="<?= SITE_URL ?>/">Home</a></li>
                    <li><a href="<?= SITE_URL ?>/shop">Shop</a></li>
                    <?php foreach ($categoryTrail as $ti => $tc): $tLast = ($ti === count($categoryTrail) - 1); ?>
                        <li<?= $tLast ? ' aria-current="page"' : '' ?>>
                            <?php if ($tLast): ?>
                                <span><?= htmlspecialchars($tc['name']) ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(categoryUrl($tc)) ?>"><?= htmlspecialchars($tc['name']) ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <span class="luxury-hero-eyebrow">Collection</span>
            <h1><?= htmlspecialchars($categorySeo['h1']) ?></h1>
            <p class="shop-hero-intro"><?= htmlspecialchars($categorySeo['intro']) ?></p>
        <?php else: ?>
            <?php /* The heading has to name the half of the shop being shown. "Dievon
                     Curated Catalog" over a menswear grid reads as the wrong page —
                     and /men is a real address people can land on from search, where
                     nothing else on screen tells them which catalogue they reached. */ ?>
            <?php if (function_exists('currentShopGender') && currentShopGender() === 'men'): ?>
                <span class="luxury-hero-eyebrow">Menswear</span>
                <h1>Dievon Men</h1>
                <p>Shirts, trousers and tailoring, cut clean and made to be worn often.</p>
            <?php else: ?>
                <span class="luxury-hero-eyebrow">Explore Collections</span>
                <h1>Dievon Curated Catalog</h1>
                <p>Explore luxury garments crafted for every elegant mood.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- ══ Shop Catalog Layout ════════════════════════════════ -->
<section class="shop-catalog section-space">
    <div class="container shop-layout">
        
        <!-- Left Sidebar Filters -->
        <aside class="shop-sidebar">
            <!-- h2, not h3: this is the first section under the page's <h1>, and a
                 jump from h1 straight to h3 leaves a gap in the outline a screen
                 reader reads as a missing level. Every visible property comes from
                 .sidebar-filters-title, which is a class, so nothing moves. -->
            <h2 class="sidebar-filters-title">Filters</h2>
            
            <div class="filter-accordion">
                <button type="button" class="filter-header open" aria-expanded="true" onclick="toggleFilter(this)">
                    Categories <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
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
                <button type="button" class="filter-header open" aria-expanded="true" onclick="toggleFilter(this)">
                    Price Range <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
                <div class="filter-body open">
                    <div class="price-range-container">
                        <div class="price-range-inputs">
<?php
    /* The two boxes below must show the price that is actually being applied.
       They had no value at all, so a page arriving with ?min_price=… — a shared
       link, a reload, or the Back button — showed a price-filtered grid above
       two empty boxes. Worse, the next tick of ANY other filter read those empty
       boxes and dropped the price silently, which is what "the price range does
       not work" looks like from the outside. Every other filter already ticks
       itself back on from the URL (DIEVON_URL_FILTERS); price now does the same. */
    $priceBoxValue = static function (string $param): string {
        $raw = $_GET[$param] ?? '';
        if (!is_string($raw) || trim($raw) === '' || !is_numeric(trim($raw))) { return ''; }
        $n = max(0, (float)trim($raw));
        return $n > 0 ? rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') : '';
    };
?>
                            <div class="price-input-box">
                                <span><?= currencySymbol() ?></span>
                                <input type="number" id="minPrice" placeholder="Min" min="0" step="any"
                                       value="<?= htmlspecialchars($priceBoxValue('min_price'), ENT_QUOTES) ?>"
                                       onchange="applyFilters()">
                            </div>
                            <span>-</span>
                            <div class="price-input-box">
                                <span><?= currencySymbol() ?></span>
                                <input type="number" id="maxPrice" placeholder="Max" min="0" step="any"
                                       value="<?= htmlspecialchars($priceBoxValue('max_price'), ENT_QUOTES) ?>"
                                       onchange="applyFilters()">
                            </div>
                        </div>
                        <button type="button" class="btn-apply-price" onclick="applyFilters()">Apply Filter</button>
                    </div>
                </div>
            </div>


            <?php if (!empty($brands)): ?>
            <div class="filter-accordion">
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Brand <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
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

            <?php // Size sits directly above Colour: for clothing it is the filter that
                  // decides whether a garment is buyable at all, so it goes first. Only
                  // rendered when something is genuinely in stock in some size. ?>
            <?php if (!empty($sizes)): ?>
            <div class="filter-accordion">
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Size <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
                <div class="filter-body">
                    <?php foreach ($sizes as $sz): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="size_filter[]" value="<?= htmlspecialchars($sz) ?>" onchange="applyFilters()">
                            <?= htmlspecialchars($sz) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($colors)): ?>
            <div class="filter-accordion">
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Color <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
                <div class="filter-body">
                    <?php foreach ($colors as $c): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" name="color_filter[]" value="<?= htmlspecialchars($c) ?>" onchange="applyFilters()">
                            <?php // Swatch only where a real hex is recorded against the colour in
                                  // Colours & Sizes. The name is always shown as well, never replaced
                                  // by the chip — a swatch alone is unreadable to a screen reader and
                                  // ambiguous between two similar shades. ?>
                            <?php if (!empty($colorHexMap[$c])): ?>
                                <span class="filter-color-chip" style="background: <?= htmlspecialchars($colorHexMap[$c]) ?>;" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?= htmlspecialchars($c) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($fabrics)): ?>
            <div class="filter-accordion">
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Fabric <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
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
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Sleeve <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
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
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Neck <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
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
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Pattern <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
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
                <button type="button" class="filter-header" aria-expanded="false" onclick="toggleFilter(this)">
                    Occasion <i class="fa-solid fa-angle-down filter-toggle-icon" aria-hidden="true"></i>
                </button>
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
                    <?php /* title= alone is not a reliable accessible name and shows
                             nothing on touch. aria-pressed carries which view is on;
                             setGridView() keeps it in step with the .active class. */ ?>
                    <button class="view-btn active" id="btnGrid" onclick="setGridView('grid')" title="Grid View" aria-label="Grid view" aria-pressed="true"><i class="fa-solid fa-border-all" aria-hidden="true"></i></button>
                    <button class="view-btn" id="btnList" onclick="setGridView('list')" title="List View" aria-label="List view" aria-pressed="false"><i class="fa-solid fa-list" aria-hidden="true"></i></button>
                </div>
                <div class="sorting-controls">
                    <label for="sortBySelect">Sort By:</label>
                    <?php
                        // Echo the active sort back into the control. Without this the
                        // dropdown always read "Default" even when the URL asked for a
                        // price sort, so the page and the control disagreed.
                        $activeSort = isset($_GET['sort_by']) ? trim((string)$_GET['sort_by']) : 'default';
                        $sortOptions = [
                            'default'    => 'Default',
                            'price_asc'  => 'Price: Low to High',
                            'price_desc' => 'Price: High to Low',
                            'name_asc'   => 'Name: A-Z',
                        ];
                        // Accept the legacy spellings so an old link still highlights correctly.
                        $sortAliases = ['price_low' => 'price_asc', 'price_high' => 'price_desc', 'newest' => 'default'];
                        $activeSort = $sortAliases[$activeSort] ?? $activeSort;
                    ?>
                    <select id="sortBySelect" onchange="applyFilters()">
                        <?php foreach ($sortOptions as $sortVal => $sortLabel): ?>
                        <option value="<?= $sortVal ?>"<?= $activeSort === $sortVal ? ' selected' : '' ?>><?= $sortLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Product Grid -->
            <div class="products-grid" id="shopProductsGrid">
                <?php
                // The query that fills $initialProducts now runs ABOVE the header,
                // because whether it returns anything decides $noindex — and the
                // header is what prints the robots meta tag.
                foreach ($initialProducts as $p):
                    $imgSrc = !empty($p['image']) ? SITE_URL . '/uploads/products/' . htmlspecialchars($p['image']) : '';
                    $formattedPrice = formatPrice($p['price']);
                    $pMrp = (float)($p['mrp_price'] ?? 0);
                    $pHasDiscount = $pMrp > (float)$p['price'];
                    $pDiscountPercent = $pHasDiscount ? (int)round((($pMrp - (float)$p['price']) / $pMrp) * 100) : 0;
                ?>
                <?php /* Primed once for the whole grid — see productHoverImage(). */
                      if (!isset($shopHoverPrimed)) { productHoverImagePrime($initialProducts ?? []); productPriceRangePrime($initialProducts ?? []); $shopHoverPrimed = true; } ?>
                <?php // Shared partial — see includes/product_card.php. ?>
                <?php $card = $p; include __DIR__ . '/../includes/product_card.php'; ?>
                <?php endforeach; ?>
            </div>

            <?php
            /* Real links to every page of the grid.
               ────────────────────────────────────────────────────────────────
               The grid loads more over AJAX, which is fine for a shopper and
               useless to a crawler: robots.php disallows the ajax endpoint, so
               without these anchors pages 2..N were unreachable by any path and
               most of the catalogue could not be indexed.

               Kept out of the way visually — the Load More button remains the
               control a person uses — but they are ordinary <a href> elements,
               which is all a crawler needs. Every current filter is preserved in
               each link, so paging inside a filtered view stays inside it.

               rel prev/next are advisory to Google now, but still read by other
               crawlers, and they cost nothing. */
            if (!empty($shopTotalPages) && $shopTotalPages > 1):
                $pgQuery = function (int $n): string {
                    $qs = $_GET; $qs['page'] = $n;
                    return htmlspecialchars('?' . http_build_query($qs), ENT_QUOTES);
                };
            ?>
            <nav class="shop-pagination" aria-label="Catalogue pages"
                 style="display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin:26px 0 6px;">
                <?php if ($shopPageNo > 1): ?>
                    <a class="shop-page-link" rel="prev" href="<?= $pgQuery($shopPageNo - 1) ?>">Previous</a>
                <?php endif; ?>
                <?php for ($n = 1; $n <= $shopTotalPages; $n++): ?>
                    <?php if ($n === $shopPageNo): ?>
                        <span class="shop-page-link shop-page-current" aria-current="page"><?= $n ?></span>
                    <?php else: ?>
                        <a class="shop-page-link" href="<?= $pgQuery($n) ?>"><?= $n ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($shopPageNo < $shopTotalPages): ?>
                    <a class="shop-page-link" rel="next" href="<?= $pgQuery($shopPageNo + 1) ?>">Next</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <?php
                // Structured data belongs on the canonical page only. A filtered or
                // paginated view used to publish CollectionPage naming the unfiltered
                // URL, numberOfItems for the WHOLE category, and an itemListElement of
                // just the filtered products — three statements that contradict each
                // other and each other's page.
                $shopHasRefinements = (bool)array_intersect(array_keys($_GET), [
                    'fabrics', 'badges', 'colors', 'brands', 'sleeves', 'necks',
                    'patterns', 'occasions', 'min_price', 'max_price', 'sort_by', 'page',
                ]);
            ?>
            <?php if ($categorySeo && !$shopHasRefinements && empty($noindex)): ?>
            <!-- ══ CollectionPage + the products on it ═════════════════
     Tells Google this URL is a collection with a known item list, rather than
     leaving it to guess from the markup. -->
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $categorySeo['h1'],
    'description' => $categorySeo['description'],
    'url' => $canonicalUrl,
    'isPartOf' => ['@type' => 'WebSite', 'name' => SHOP_NAME, 'url' => SITE_URL . '/'],
    'mainEntity' => [
        '@type' => 'ItemList',
        'numberOfItems' => $categorySeo['product_count'],
        'itemListElement' => array_values(array_map(function ($p, $i) {
            return [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => productUrl($p['id'], $p['name'], $p['seo_url'] ?? null),
                'name' => $p['name'],
            ];
        }, $initialProducts ?? [], array_keys($initialProducts ?? []))),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>
            <?php endif; ?>

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
        const nowOpen = headerEl.classList.toggle('open');
        // Keep the accessible state in step with the visual one. The open/closed
        // cue for a sighted user is the arrow, rotated purely in CSS off the
        // .open class — nothing a screen reader can perceive. Without this line
        // every filter is announced as collapsed even while it is on screen.
        headerEl.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
        const bodyEl = headerEl.nextElementSibling;
        if (bodyEl) {
            bodyEl.classList.toggle('open');
        }
    }

    // Catalog state
    let currentPage = 1;
    let loading = false;
    let hasMore = true;

    // Set while the grid is being rebuilt to match an address the shopper
    // reached with the Back button — that address is already in history, so
    // writing it again would push a duplicate entry and trap them there.
    let suppressHistory = false;

    // The shop's own path, read ONCE at load.
    //
    // Every request used to be built from window.location.pathname, and the
    // address-bar rewrite further down used to corrupt that path — so the first
    // filter click worked, and every click after it fetched a URL that does not
    // exist, leaving the grid frozen on the first result. Reading the path once
    // means a bad rewrite can never feed itself back into the next fetch.
    const SHOP_BASE_PATH = window.location.pathname;

    // Filters that arrived in the URL rather than from a click. On
    // /collections/<slug> there is no query string at all, and the occasion pills
    // on the home page link with ?occasions=. Either way the sidebar box has to be
    // ticked, or the next "load more" drops the filter and appends the whole
    // catalogue underneath the filtered first page.
<?php
    $urlFilterMap = [
        'categories' => 'category_filter[]', 'occasions' => 'occasion_filter[]',
        'sizes'      => 'size_filter[]',
        'fabrics'    => 'fabric_filter[]',   'colors'    => 'color_filter[]',
        'brands'     => 'brand_filter[]',    'sleeves'   => 'sleeve_filter[]',
        'necks'      => 'neck_filter[]',     'patterns'  => 'pattern_filter[]',
    ];
    $urlFilterValues = [];
    foreach ($urlFilterMap as $param => $field) {
        $raw = (string)($_GET[$param] ?? '');
        $urlFilterValues[$field] = array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
    // The category can also arrive as the path (/collections/<slug>) or as the
    // singular ?category=<name>, neither of which appears in the map above.
    $singleCategory = $activeCategoryRow['name'] ?? (isset($_GET['category']) ? trim((string)$_GET['category']) : '');
    if ($singleCategory !== '' && !$urlFilterValues['category_filter[]']) {
        $urlFilterValues['category_filter[]'] = [$singleCategory];
    }
?>
    window.DIEVON_URL_FILTERS = <?= json_encode($urlFilterValues, JSON_UNESCAPED_UNICODE) ?>;

    // Filters that have no checkbox in the sidebar, so they cannot be read back
    // off the form when the next page is fetched. Carried through verbatim.
    window.DIEVON_STICKY_PARAMS = <?= json_encode(
        array_filter(['badges' => (string)($_GET['badges'] ?? '')], fn($v) => $v !== ''),
        JSON_UNESCAPED_UNICODE
    ) ?>;

    document.addEventListener('DOMContentLoaded', () => {
        Object.entries(window.DIEVON_URL_FILTERS || {}).forEach(([field, values]) => {
            values.forEach(v => {
                const cb = document.querySelector(
                    `input[name="${CSS.escape(field)}"][value="${CSS.escape(v)}"]`);
                if (cb) cb.checked = true;
            });
        });

        const initialCount = document.querySelectorAll('#shopProductsGrid .product-card').length;
        if (initialCount > 0) {
            /* Carry on from the page the SERVER actually drew, not from page 2.
               ────────────────────────────────────────────────────────────────
               The server honours ?page=N (see $initOffset), but this always set
               the scroll cursor to 2, so the two disagreed the moment anyone
               followed a paged address. Measured on /shop?page=3: the grid
               opened on products 13–18, scrolling then appended 7–12 BELOW
               them, and went on to re-append 13–18 a second time — six
               products duplicated and the first six of the catalogue never
               shown at all, under a URL a crawler is free to follow. */
            currentPage = <?= (int)($initPageNo ?? 1) ?> + 1;
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
            // Absolute, because a bare 'shop' resolves against the CURRENT path:
            // on /collections/kurtis it became /collections/shop, which does not
            // exist — so Clear Filters landed the shopper on a 404 and only the
            // Back button got them out.
            window.location.href = '<?= SITE_URL ?>/shop';
            return;
        }
        loadProducts(true);
    }

    /* Back and Forward.
       ────────────────────────────────────────────────────────────────────────
       Applying a filter pushes an entry into history, but nothing was listening
       for the shopper coming back out of it. Pressing Back moved the address bar
       to the previous selection and left the grid showing the current one — the
       page then said "all pieces" in the URL while displaying only Bottoms, with
       the Bottoms box still ticked. Read the address back into the form and
       rebuild the grid from it, without writing to history again. */
    const HISTORY_FILTER_MAP = {
        categories: 'category_filter[]', sizes:    'size_filter[]',
        colors:     'color_filter[]',    brands:   'brand_filter[]',
        fabrics:    'fabric_filter[]',   sleeves:  'sleeve_filter[]',
        necks:      'neck_filter[]',     patterns: 'pattern_filter[]',
        occasions:  'occasion_filter[]',
    };

    window.addEventListener('popstate', () => {
        const p = new URLSearchParams(window.location.search);

        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        Object.entries(HISTORY_FILTER_MAP).forEach(([param, field]) => {
            (p.get(param) || '').split(',').map(v => v.trim()).filter(Boolean).forEach(v => {
                const cb = document.querySelector(
                    `input[name="${CSS.escape(field)}"][value="${CSS.escape(v)}"]`);
                if (cb) cb.checked = true;
            });
        });
        document.getElementById('minPrice').value = p.get('min_price') || '';
        document.getElementById('maxPrice').value = p.get('max_price') || '';
        document.getElementById('sortBySelect').value = p.get('sort_by') || 'default';

        suppressHistory = true;
        try { loadProducts(true); } finally { suppressHistory = false; }
    });

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
            btnList.setAttribute('aria-pressed', 'true');
            btnGrid.setAttribute('aria-pressed', 'false');
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
            btnGrid.setAttribute('aria-pressed', 'true');
            btnList.setAttribute('aria-pressed', 'false');
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
        const sizes = getSelectedCheckboxes('size_filter[]');
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

        // One set of filters, used to build both URLs below.
        //
        // These were assembled by gluing strings together and then cut back
        // apart with a regular expression to make the shareable address. The
        // expression was greedy and, once "?ajax=1" had been snipped out, there
        // was no "?" left for it to stop at — so it turned the LAST "&" into the
        // separator and everything before it silently became part of the path:
        //
        //   /shop?ajax=1&sort_by=default&min_price=200&max_price=400
        //     became  /shop&sort_by=default&min_price=200 ? max_price=400
        //
        // The address bar then held a path that does not exist, that path grew
        // longer with every click, and min_price had been swallowed into it. A
        // parameter list cannot be mis-cut like that, so build one and let the
        // browser do the encoding.
        const params = new URLSearchParams();
        params.set('sort_by', sort_by);
        if (searchQuery) params.set('search', searchQuery);
        if (saleOnly)    params.set('sale', saleOnly);
        if (categories)  params.set('categories', categories);
        if (sizes)       params.set('sizes', sizes);
        if (colors)      params.set('colors', colors);
        if (brands)      params.set('brands', brands);
        if (fabrics)     params.set('fabrics', fabrics);
        if (sleeves)     params.set('sleeves', sleeves);
        if (necks)       params.set('necks', necks);
        if (patterns)    params.set('patterns', patterns);
        if (occasions)   params.set('occasions', occasions);
        Object.entries(window.DIEVON_STICKY_PARAMS || {}).forEach(([k, v]) => params.set(k, v));
        if (minPrice)    params.set('min_price', minPrice);
        if (maxPrice)    params.set('max_price', maxPrice);

        // The request adds the two transport-only parameters; the address bar
        // gets the filters alone, so pasting it reproduces what is on screen.
        const fetchParams = new URLSearchParams(params);
        fetchParams.set('ajax', '1');
        fetchParams.set('page', String(currentPage));
        const url = `${SHOP_BASE_PATH}?${fetchParams.toString()}`;

        // Put the filters in the address bar.
        //
        // The grid was replaced in place and the URL never moved, so a filtered
        // view could not be sent to anyone, saved, or returned to with the Back
        // button — Back left the shop entirely, losing a selection that might
        // have taken a dozen clicks.
        //
        // replaceState on paging (not pushState), or every "load more" would add
        // another Back-button step.
        if (!suppressHistory && window.history && window.history.replaceState) {
            const shareUrl = `${SHOP_BASE_PATH}?${params.toString()}`;
            window.history[reset ? 'pushState' : 'replaceState']({ dievonShop: true }, '', shareUrl);
        }

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
                        // Same scroll-entrance as the static grid: hidden briefly
                        // then revealed by the site's observer (footer.php). New
                        // cards without this class stay plainly visible, so a
                        // failure here can never hide products.
                        card.classList.add('reveal-on-scroll');
                        grid.appendChild(card);
                        if (typeof dievonObserveReveal === 'function') dievonObserveReveal(card);
                    });

                    // Freshly built cards carry an empty bag note. The cart was
                    // loaded long before this grid existed, so fill them in now —
                    // otherwise "2 in your bag" appears on the first page of
                    // results and vanishes on everything a filter or Load More
                    // brings in.
                    if (typeof syncBagNotes === 'function') { syncBagNotes(); }

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
        // The owner's own slug when set, matching productUrl() server-side.
        return `${window.SITE_URL}/product/${slugify(p.seo_url || p.name)}-${p.id}`;
    }

    function createProductCard(p) {
        const article = document.createElement('article');
        article.className = 'product-card reveal-on-scroll';
        // Matches includes/product_card.php. syncBagNotes() in includes/footer.php
        // fills the bag note on both renderers from the same code, so the card a
        // shopper gets from "load more" says the same thing as the one that
        // arrived with the page.
        article.dataset.productId = p.id;

        let imgHtml = '';
        if (p.image) {
            // Mirrors includes/product_card.php — keep the two in step, or a product
            // looks different depending on whether it arrived with the page or with
            // "load more".
            const alt = escHtml(p.image_alt || p.name);
            const src = `<?= SITE_URL ?>/uploads/products/${escHtml(p.image)}`;
            const webpSource = p.image_webp
                ? `<source srcset="${escHtml(p.image_webp)}" type="image/webp">` : '';
            imgHtml = `<picture>${webpSource}<img src="${src}" alt="${alt}" loading="lazy" decoding="async" class="card-img"></picture>`;
            // The hover layer, matching includes/product_card.php: empty src,
            // real path in data-src, filled in by the delegated listener in
            // includes/footer.php the first time a pointer reaches the tile.
            if (p.image_hover) {
                const hSrc  = `<?= SITE_URL ?>/uploads/products/${escHtml(p.image_hover)}`;
                const hWebp = p.image_hover_webp ? ` data-webp="${escHtml(p.image_hover_webp)}"` : '';
                imgHtml += `<img class="card-img card-img-hover" alt="" aria-hidden="true" decoding="async" data-src="${hSrc}"${hWebp}>`;
            }
        } else if (p.video_url) {
            const vSrc = (p.video_url.startsWith('http://') || p.video_url.startsWith('https://')) ? p.video_url : `${window.SITE_URL}/uploads/products/${p.video_url}`;
            imgHtml = `<video src="${escHtml(vSrc)}" autoplay loop muted playsinline class="card-img"></video>`;
        } else {
            imgHtml = `<div class="card-img-fallback" aria-label="No image available">${escHtml(p.emoji)}</div>`;
        }

        // Sold Out wins over any other badge, exactly as the server card orders
        // them (includes/product_card.php:60-64). Kept first for the same reason:
        // a discount badge on something that cannot be bought is worse than no
        // badge at all.
        const badgeHtml = p.not_sold_here ? `<span class="badge-luxury badge-sold-out">Not Available Here</span>` : (p.is_sold_out ? `<span class="badge-luxury badge-sold-out">Sold Out</span>` : (p.has_discount ? `<span class="badge-luxury badge-sale">${p.discount_percent}% OFF</span>` : (p.badge ? `<span class="badge-luxury">${escHtml(p.badge)}</span>` : '')));

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
                <label class="compare-toggle" title="Compare (up to 3)" onclick="event.stopPropagation()">
                    <input type="checkbox" class="compare-check" value="${p.id}" data-name="${escHtml(p.name)}" onchange="toggleCompare(this)">
                    <span>Compare</span>
                </label>
                <button onclick="event.preventDefault(); openQuickView(${p.id})" class="quick-view-btn">Quick View</button>
            </div>
            <div class="product-card-details">
                <p class="product-card-bag-note" data-bag-note hidden></p>
                <span class="product-card-category">${escHtml(p.category)}</span>
                <h3 class="product-card-title">
                    <a href="${productUrl(p)}">${escHtml(p.name)}</a>
                </h3>
                ${sizeInfo}
                <div class="product-card-price">
                    ${p.has_discount ? `<span class="price-mrp-strike">${escHtml(p.formatted_mrp)}</span>` : ''}
                    ${p.price_varies ? '<span class="price-from-label">From</span> ' : ''}${p.formatted_price ? escHtml(p.formatted_price) : (typeof formatPriceJS === 'function' ? formatPriceJS(p.price) : '<?= currencySymbol() ?>' + parseFloat(p.price).toFixed(2))}
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
            <?php if (!empty($sizes)): ?>
            <button type="button" class="refine-tab-btn" onclick="switchRefineTab('tabSize', this)">Size</button>
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
                    <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 6px;">Min Price (<?= currencySymbol() ?>)</label>
                    <input type="number" id="mMinPrice" placeholder="0" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 14px; font-size: 14px;">
                    
                    <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 6px;">Max Price (<?= currencySymbol() ?>)</label>
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

            <!-- Size Tab -->
            <?php if (!empty($sizes)): ?>
            <div class="refine-tab-pane" id="tabSize">
                <div class="refine-checkbox-list">
                    <?php foreach ($sizes as $sz): ?>
                        <label class="refine-checkbox-label">
                            <input type="checkbox" name="m_size[]" value="<?= htmlspecialchars($sz) ?>">
                            <span><?= htmlspecialchars($sz) ?></span>
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

        // Sync sizes
        const dSizes = Array.from(document.querySelectorAll('input[name="size_filter[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="m_size[]"]').forEach(cb => cb.checked = dSizes.includes(cb.value));

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

        const mSizes = Array.from(document.querySelectorAll('input[name="m_size[]"]:checked')).map(i => i.value);
        document.querySelectorAll('input[name="size_filter[]"]').forEach(cb => cb.checked = mSizes.includes(cb.value));

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
        document.querySelectorAll('input[name="m_size[]"]').forEach(cb => cb.checked = false);
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
