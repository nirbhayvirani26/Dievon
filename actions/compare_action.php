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
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, price, mrp_price, category, image, emoji, description,
                                  fabric, color, sleeve, neck, pattern, occasion, brand, badge,
                                  track_stock, stock_qty
                           FROM products
                           WHERE id IN ($in) AND available = 1");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        $mrp   = (float)($p['mrp_price'] ?? 0);
        $price = (float)$p['price'];
        $disc  = $mrp > $price;

        $out[] = [
            'id'        => (int)$p['id'],
            'name'      => $p['name'],
            'category'  => $p['category'],
            'badge'     => $p['badge'],
            'image'     => !empty($p['image']) ? SITE_URL . '/uploads/products/' . $p['image'] : null,
            'emoji'     => $p['emoji'],
            'price'     => formatPrice($price),
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
