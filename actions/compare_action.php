<?php
// ============================================================
//  Dievon – Product comparison data
// ------------------------------------------------------------
//  Returns up to 3 products side by side for the compare drawer.
//
//  Read-only and public, same as quick_view_action.php: it only exposes what the
//  product page already shows. The 3-product cap is enforced HERE as well as in
//  the browser, so a hand-crafted request cannot pull the whole catalogue through
//  this endpoint in one call.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

const COMPARE_MAX = 3;

$raw = trim((string)($_GET['ids'] ?? ''));
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn($i) => $i > 0)));

if (!$ids) {
    echo json_encode(['success' => true, 'products' => []]);
    exit;
}
$ids = array_slice($ids, 0, COMPARE_MAX);

try {
    /* Published AND not archived.
       ────────────────────────────────────────────────────────────────────────
       This tested available = 1 alone, so a row archived while still flagged
       available (is_deleted = 1, available = 1) could be pulled into the
       comparison table by id — priced, sized, linked and presented beside two
       live garments — while the shop grid, the search and the wishlist all
       correctly refused it. Every other read of this table pairs the two.

       seo_url is selected because productUrl() below reads it. Without it the
       link was built from the product NAME instead of the stored slug, so the
       compare table pointed at /product/aurora-silk-saree-244 while every card
       on the site pointed at /product/qa5-aurora-silk-saree-244 — a needless
       301 out of a drawer whose whole job is to be clicked. */
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, seo_url, price, mrp_price, category, image, emoji, description,
                                  fabric, color, sleeve, neck, pattern, occasion, brand, badge,
                                  track_stock, stock_qty
                           FROM products
                           WHERE id IN ($in)
                             AND available = 1
                             AND (is_deleted = 0 OR is_deleted IS NULL)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // One pair of queries for the whole table — see productPriceRangePrime().
    productPriceRangePrime($rows, $pdo);

    // Preserve the order the shopper picked them in, not the order MySQL returned.
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }

    $out = [];
    foreach ($ids as $id) {
        if (!isset($byId[$id])) { continue; }   // unavailable or deleted since selection
        $p = $byId[$id];

        // Sizes actually offered — same NULL-stock rule as quick view: NULL means
        // "no per-size count", not "none left".
        $tracks = (int)($p['track_stock'] ?? 0) === 1;
        $pStock = $p['stock_qty'] ?? null;
        $sizes = [];
        $v = $pdo->prepare("SELECT name, size_code, stock_qty FROM product_variants
                            WHERE product_id = :id AND available = 1 ORDER BY sort_order ASC, id ASC");
        $v->execute(['id' => $id]);
        foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stock = $row['stock_qty'] !== null
                ? (int)$row['stock_qty']
                : (($tracks && $pStock !== null) ? (int)$pStock : 1);
            if ($stock > 0) { $sizes[] = $row['name'] ?: $row['size_code']; }
        }

        // Country pricing, same rule as the grid card and the product page.
        //
        // This read the INR price and passed it to formatPrice(), which stamps
        // the current country's symbol on it — so the comparison table quoted a
        // foreign shopper the rupee figure in their own currency, and listed
        // pieces not sold in their country as though they were buyable. A
        // comparison table exists to be compared, so a wrong number in it is
        // worse than in most places.
        $cmpCountry = function_exists('currentCountryCode') ? currentCountryCode() : 'IN';
        $cmpHome    = strtoupper(function_exists('homeCountryRow') ? (homeCountryRow()['country_code'] ?? 'IN') : 'IN');
        if (strtoupper($cmpCountry) !== $cmpHome && function_exists('productCountryPricing')) {
            $cmpPricing = productCountryPricing($p, $cmpCountry);
            // No row for this country is the shop's signal that it is not sold
            // here — leave it out of the table rather than price it wrongly.
            if ($cmpPricing === null) { continue; }
            $p['price']     = $cmpPricing['price'];
            $p['mrp_price'] = $cmpPricing['mrp'];
        }

        /* The comparable price is the one a shopper can actually pay.
           ────────────────────────────────────────────────────────────────────
           This read products.price, which broke the table's whole purpose in
           two ways at once. It quoted figures nobody can buy at — a saree
           priced 3000 whose sizes are 1500/2600/2600 was listed at ₹3,000
           while every card on the site said "From ₹1,500" — and, because
           dievon-compare.js picks the "Lowest price" badge with
           Math.min(priceRaw), it put that badge on the WRONG garment.

           Measured, comparing 244 / 245 / 243: the badge landed on the Élan
           Été Jacket at ₹2,200, while the Aurora Silk Saree beside it was
           buyable from ₹1,500. A table built to answer "which is cheaper"
           answered it incorrectly.

           productPriceRange() is the same ladder the card, Quick View and the
           cart use, so priceRaw is now the figure the badge should rank on and
           `price` is the figure the shopper will be charged. Country pricing
           already replaced $p['price'] above and overrides sizes entirely, so
           it is honoured by leaving the range out of that case. */
        $mrp    = (float)($p['mrp_price'] ?? 0);
        $price  = (float)$p['price'];
        $varies = false;
        if (strtoupper($cmpCountry) === $cmpHome) {
            $range = productPriceRange($p);
            if ($range['min'] > 0) {
                $price  = (float)$range['min'];
                $varies = (bool)$range['varies'];
            }
        }
        // A struck-through MRP beside a "from" price claims a saving that holds
        // for one size only, so it is withdrawn — the same rule the card and
        // Quick View follow.
        $disc  = !$varies && $mrp > $price && $price > 0;

        $out[] = [
            'id'        => (int)$p['id'],
            'name'      => $p['name'],
            'category'  => $p['category'],
            'badge'     => $p['badge'],
            'image'     => !empty($p['image']) ? SITE_URL . '/uploads/products/' . $p['image'] : null,
            'emoji'     => $p['emoji'],
            'price'     => ($varies ? 'From ' : '') . formatPrice($price),
            // What the "Lowest price" badge ranks on — see dievon-compare.js.
            'priceRaw'  => $price,
            'mrp'       => $disc ? formatPrice($mrp) : null,
            'discount'  => $disc ? (int)round((($mrp - $price) / $mrp) * 100) : 0,
            'sizes'     => $sizes ? implode(', ', $sizes) : '—',
            // isHumanReadableAttribute keeps hex colour swatches out of the table.
            'fabric'    => isHumanReadableAttribute($p['fabric'] ?? '')   ? $p['fabric']   : '—',
            'colour'    => isHumanReadableAttribute($p['color'] ?? '')    ? $p['color']    : '—',
            'sleeve'    => isHumanReadableAttribute($p['sleeve'] ?? '')   ? $p['sleeve']   : '—',
            'neck'      => isHumanReadableAttribute($p['neck'] ?? '')     ? $p['neck']     : '—',
            'pattern'   => isHumanReadableAttribute($p['pattern'] ?? '')  ? $p['pattern']  : '—',
            'occasion'  => isHumanReadableAttribute($p['occasion'] ?? '') ? $p['occasion'] : '—',
            'brand'     => isHumanReadableAttribute($p['brand'] ?? '')    ? $p['brand']    : '—',
            'url'       => productUrl($p['id'], $p['name'], $p['seo_url'] ?? null),
        ];
    }

    echo json_encode(['success' => true, 'products' => $out],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    error_log('compare_action: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load the comparison.']);
}
