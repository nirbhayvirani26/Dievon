<?php
/**
 * Dievon – Public product feed, JSON.
 * ============================================================================
 * Served at /products.json (see the rewrite in .htaccess, alongside the ones
 * for robots.txt and sitemap.xml).
 *
 * Why this exists next to google_merchant_feed.php: that feed is XML, in
 * Google's Merchant Center shape, one row PER SIZE. Most storefront
 * integrations expect Shopify's arrangement instead — JSON, one object per
 * PRODUCT, with its sizes nested underneath. A tool given the XML reports
 * `Unexpected token '<', "<?xml vers"... is not valid JSON` and imports
 * nothing, which is exactly what happened.
 *
 * Public and unauthenticated, deliberately: everything here is already visible
 * to anyone browsing the shop — names, prices, photographs, stock. No email
 * address, no order, no customer, and no admin field (cost price, supplier,
 * margin) is exposed. Read the SELECT below before adding to it.
 *
 * Prices come from effectiveVariantPrice() and URLs from productUrl(), the
 * same helpers the product page and the cart use, so a feed can never quote a
 * figure the shop will not honour.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Half an hour is long enough to blunt a rapid re-sync and short enough that a
// price change reaches the feed the same morning it is made.
header('Cache-Control: public, max-age=1800');

$out = ['generated_at' => date('c'), 'currency' => 'INR', 'products' => []];

try {
    /* Named columns, not SELECT *. The products table carries cost_price,
       supplier_name, supplier_ref and sold_offline; a wildcard here would
       publish the shop's margins and its suppliers to anyone who asked. */
    $stmt = $pdo->query(
        "SELECT id, name, description, price, mrp_price, category, brand, fabric,
                image, seo_url, sku,
                available, track_stock, total_stock, damage_stock, sold_offline, sold_online
           FROM products
          WHERE available = 1 AND COALESCE(is_deleted, 0) = 0
          ORDER BY id DESC"
    );
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'feed unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}

/* Every photograph, in two queries rather than two per product.
   ────────────────────────────────────────────────────────────────────────────
   The feed sent a single `image`, so a consumer's carousel had one picture to
   show and fell back to a still. The gallery has been there all along; it was
   simply never published.

   Both sources are read because the shop stores photographs in two places, and
   which one matters depends on the product: a plain product keeps its extra
   shots in product_images, while a product sold by colourway keeps them on the
   colours — and for those, the colour photographs ARE what a shopper sees, so
   leaving them out would publish the emptiest version of the best products.

   Grouped in PHP from two flat queries. Fetching per product would be two more
   round trips on every row, and this file is hit by an importer that walks the
   whole catalogue in one go. */
$galleryByProduct = [];
$productIds = array_map(static fn($r) => (int)$r['id'], $products);
if ($productIds) {
    $idList = implode(',', $productIds);   // ints from the DB, never request data
    try {
        foreach ($pdo->query(
            "SELECT product_id, image FROM product_images
              WHERE product_id IN ($idList) AND image IS NOT NULL AND image <> ''
           ORDER BY product_id ASC, sort_order ASC, id ASC"
        ) as $row) {
            $galleryByProduct[(int)$row['product_id']][] = (string)$row['image'];
        }
    } catch (PDOException $e) { /* no gallery table — the cover still ships */ }

    try {
        foreach ($pdo->query(
            "SELECT c.product_id, ci.image
               FROM product_color_images ci
               JOIN product_colors c ON c.id = ci.color_id AND c.is_active = 1
              WHERE c.product_id IN ($idList) AND ci.image IS NOT NULL AND ci.image <> ''
           ORDER BY c.product_id ASC, c.sort_order ASC, c.id ASC, ci.sort_order ASC, ci.id ASC"
        ) as $row) {
            $galleryByProduct[(int)$row['product_id']][] = (string)$row['image'];
        }
    } catch (PDOException $e) { /* colourways are optional */ }
}

$feedImageDir = __DIR__ . '/uploads/products/';

foreach ($products as $p) {
    $pid = (int)$p['id'];

    /* Cover first — it is the photograph the shop itself leads with, so a
       carousel opens on the same picture the shopper saw on the card.

       A file that is not on disk is left out rather than published as a URL
       that 404s: the same rule the product page follows, and the reason it
       matters here is that an importer caches what we give it, so a broken
       link can outlive the fix. Duplicates are dropped because a cover is very
       often the first gallery row as well. */
    $images = [];
    foreach (array_merge([(string)($p['image'] ?? '')], $galleryByProduct[$pid] ?? []) as $file) {
        $file = trim($file);
        if ($file === '' || isset($images[$file]) || !is_file($feedImageDir . $file)) { continue; }
        $images[$file] = SITE_URL . '/uploads/products/' . rawurlencode($file);
    }
    $images = array_values($images);

    // Sizes and colourways, priced the way the cart will price them.
    $variants = [];
    try {
        $vs = $pdo->prepare(
            "SELECT v.id, v.size_code, v.name, v.stock_qty, v.price,
                    c.color_name, c.price_override
               FROM product_variants v
          LEFT JOIN product_colors c ON c.id = v.color_id AND c.is_active = 1
              WHERE v.product_id = :id AND v.available = 1
                AND (v.color_id IS NULL OR c.id IS NOT NULL)
           ORDER BY v.sort_order ASC, v.id ASC"
        );
        $vs->execute([':id' => $pid]);
        foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $colour = $v['color_name'] !== null
                ? ['color_name' => $v['color_name'], 'price_override' => $v['price_override']]
                : null;
            $variants[] = [
                'id'           => (int)$v['id'],
                'size'         => $v['size_code'] ?: $v['name'],
                'colour'       => $v['color_name'],
                'price'        => number_format(effectiveVariantPrice($p, $v, $colour), 2, '.', ''),
                // null means the size is not counted, which is not the same as
                // none left — a consumer that treats null as 0 hides sellable stock.
                'stock'        => $v['stock_qty'] === null ? null : (int)$v['stock_qty'],
                'in_stock'     => $v['stock_qty'] === null || (int)$v['stock_qty'] > 0,
            ];
        }
    } catch (PDOException $e) { /* a product with no sizes is still a product */ }

    $inStock = $variants
        ? (bool)array_filter($variants, fn($v) => $v['in_stock'])
        : (empty($p['track_stock']) || availableStock($p) > 0);

    $out['products'][] = [
        'id'          => $pid,
        'sku'         => productDisplayCode($p),
        'title'       => $p['name'],
        'description' => trim((string)$p['description']),
        'url'         => productUrl($pid, $p['name'], $p['seo_url'] ?? null),
        // Kept exactly as it was: consumers already reading `image` must not
        // break because a richer field arrived beside it.
        'image'       => $images[0] ?? (!empty($p['image'])
                            ? SITE_URL . '/uploads/products/' . rawurlencode($p['image'])
                            : null),
        'images'      => $images,
        'brand'       => $p['brand'] ?: SHOP_NAME,
        'category'    => $p['category'],
        'fabric'      => $p['fabric'] ?: null,
        'price'       => number_format((float)effectiveVariantPrice($p, null, null), 2, '.', ''),
        'compare_at'  => ((float)($p['mrp_price'] ?? 0) > 0) ? number_format((float)$p['mrp_price'], 2, '.', '') : null,
        'in_stock'    => $inStock,
        'variants'    => $variants,
    ];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
