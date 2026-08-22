<?php
ob_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

/**
 * Escape the three characters LIKE treats as syntax.
 *
 * Guarded: pages/shop.php declares the same helper for the same reason, and a
 * future include order must not fatal on a redeclare.
 */
if (!function_exists('likeEscape')) {
    function likeEscape(string $term): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}

try {
    // Scoped to the side of the shop being browsed, exactly as the grid is.
    //
    // This had no gender clause at all, so the header autocomplete answered the
    // same eight products to everyone: a woman typing "kurta" was offered men's
    // shirts, and the shop's two search paths openly disagreed — the grid
    // filtered, the autocomplete did not. Whatever the dropdown offers has to be
    // findable in the results it leads to.
    $searchGenderSql = function_exists('shopGenderSqlFilter') ? shopGenderSqlFilter() : '';

    // Archived rows are excluded explicitly rather than by luck. Every other
    // query in the shop pairs available = 1 with this; here it was missing, and
    // only escaped notice because the archived products also happen to be
    // available = 0. A row archived while still marked available would have been
    // offered by the autocomplete and 404-adjacent everywhere else.
    $stmt = $pdo->prepare("
        -- seo_url so productUrl() below can honour the owner's own slug; the
        -- dropdown used to link to product.php?id= and take a 301 on every click.
        SELECT id, name, seo_url, price, image, emoji, category
        FROM products
        WHERE available = 1
          AND (is_deleted = 0 OR is_deleted IS NULL)
          $searchGenderSql
          AND (name LIKE :q1
               OR description LIKE :q2 
               OR category LIKE :q3 
               OR brand LIKE :q4 
               OR color LIKE :q5
               OR fabric LIKE :q6
               OR tags LIKE :q7
               OR sku LIKE :q8)
        ORDER BY 
            CASE 
                WHEN LOWER(name) = LOWER(:q_raw) THEN 1
                WHEN name LIKE :q_start1 THEN 2
                WHEN description LIKE :q_start2 THEN 3
                ELSE 4
            END, id DESC
        LIMIT 8
    ");
    
    /* % and _ are LIKE syntax, not letters.
       ────────────────────────────────────────────────────────────────────────
       The term went into the pattern untouched, so it was executed rather than
       searched for: "Aur%Saree" matched "Aurora Silk Saree", "Aurora_Silk"
       matched "Aurora Silk", and "%%" listed eight arbitrary products as though
       they were results. Escaping only makes a shopper's punctuation mean
       itself — nothing that used to match stops matching. The equality test on
       :q_raw is left alone: = is not LIKE and takes the term verbatim. */
    $likeQuery  = "%" . likeEscape($query) . "%";
    $startQuery = likeEscape($query) . "%";

    $stmt->execute([
        'q1'       => $likeQuery,
        'q2'       => $likeQuery,
        'q3'       => $likeQuery,
        'q4'       => $likeQuery,
        'q5'       => $likeQuery,
        'q6'       => $likeQuery,
        'q7'       => $likeQuery,
        'q8'       => $likeQuery,
        'q_raw'    => $query,
        'q_start1' => $startQuery,
        'q_start2' => $startQuery
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* The dropdown has to quote a price the shopper can actually pay.
       ────────────────────────────────────────────────────────────────────────
       This formatted products.price, while the results page the dropdown leads
       to — and the shop grid, the home rails, the wishlist and the product page
       — all print productPriceRange()['min'], the cheapest buyable size or
       colourway. On any product priced by size the two openly disagreed:

         Aurora Silk Saree   suggestion ₹3,000   results page "From ₹1,500"
         Zephyr Palazzo      suggestion ₹1,250   results page "₹1,100"

       Neither of those suggestion figures is for sale at any size. A dropdown
       that quotes a price the next click contradicts is worse than one that
       quotes none. "From" travels with it, because assets/js/search.js prints
       formatted_price verbatim — so the dropdown says exactly what the card
       under it will say.

       Primed once for all eight rows rather than once per row. */
    productPriceRangePrime($results, $pdo);

    // Format image URLs and multi-currency formatted price
    foreach ($results as &$r) {
        $range  = productPriceRange($r);
        $shown  = $range['min'] > 0 ? (float)$range['min'] : (float)$r['price'];
        $r['formatted_price'] = ($range['varies'] ? 'From ' : '') . formatPrice($shown);

        /* The canonical address, built by productUrl() — not by the browser.
           ────────────────────────────────────────────────────────────────────
           assets/js/search.js linked to SITE_URL + '/product.php?id=' + id, so
           every result in the dropdown cost a 301 to the real /product/<slug>-<id>
           before the page could start loading. The wishlist's old renderer had
           the same defect and it was fixed there by rendering server-side.

           Computed here rather than slugified again in JavaScript: productUrl()
           already knows that the owner's seo_url wins over the name, and a second
           implementation of that rule is exactly the kind of copy that drifts. */
        $r['product_url'] = productUrl($r['id'], $r['name'], $r['seo_url'] ?? null);
        // Absolute URLs. These were relative, and the browser resolves a relative
        // src against the CURRENT page — so a search run from /product/<slug>-<id>
        // asked for /product/uploads/products/x.jpg and every result rendered as a
        // broken-image icon. Only the homepage and /shop happened to be at the
        // right depth. On a clothing site the picture IS the result.
        // The search URL a few lines above was already fixed this way; the image
        // was missed.
        $imgFile = $r['image'] ?? '';
        if (!empty($imgFile) && file_exists(__DIR__ . '/uploads/products/' . $imgFile)) {
            $r['image_url'] = SITE_URL . '/uploads/products/' . $imgFile;
        } elseif (!empty($imgFile) && file_exists(__DIR__ . '/uploads/gallery/' . $imgFile)) {
            $r['image_url'] = SITE_URL . '/uploads/gallery/' . $imgFile;
        } elseif (!empty($imgFile)) {
            $r['image_url'] = SITE_URL . '/uploads/products/' . $imgFile;
        } else {
            $r['image_url'] = '';
        }
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database search error', 'details' => $e->getMessage()]);
}

