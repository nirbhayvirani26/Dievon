<?php
// Google Merchant Center product feed.
//
// This file previously would have got the account suspended on its first crawl:
//
//   1. Every price was labelled GBP while the store sells in INR, so a ₹350 kurti
//      was submitted as £350 — a ~100× mismatch against the landing page. Price
//      mismatch is one of the fastest routes to a disapproved feed.
//   2. g:link pointed at /product.php?id=N, a path this site does not serve.
//      Every item linked to a 404.
//   3. Soft-deleted products were included.
//   4. Apparel needs g:size, g:color, g:item_group_id, g:gender, g:age_group and
//      an identifier declaration. None were sent, which disqualifies clothing
//      items outright in most target countries.
//
// Money and URLs now come from the same helpers the product page and its
// structured data use, so the feed cannot drift from what a shopper sees.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/xml; charset=utf-8');

$currency = getCurrentCurrency();
$country  = function_exists('currentCountryCode') ? currentCountryCode() : 'IN';
$shipFee  = (float)storeSetting($pdo, 'standard_shipping_fee', 99);
$freeOver = (float)storeSetting($pdo, 'free_shipping_min', 0);

$products = [];
try {
    $products = $pdo->query(
        "SELECT * FROM products
          WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
          ORDER BY id DESC"
    )->fetchAll();
} catch (PDOException $e) {}

/** Size variants of a product, for one feed item per size. */
function feedVariants(PDO $pdo, int $productId): array {
    try {
        $st = $pdo->prepare(
            "SELECT * FROM product_variants
              WHERE product_id = :id AND available = 1
              ORDER BY sort_order ASC, id ASC"
        );
        $st->execute(['id' => $productId]);
        return $st->fetchAll();
    } catch (PDOException $e) { return []; }
}

function feedTag(string $name, $value, bool $cdata = false): void {
    $value = trim((string)$value);
    if ($value === '') { return; }
    echo "      <$name>";
    echo $cdata ? '<![CDATA[' . $value . ']]>' : htmlspecialchars($value, ENT_XML1);
    echo "</$name>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
  <channel>
    <title><?= htmlspecialchars(SHOP_NAME, ENT_XML1) ?> Product Feed</title>
    <link><?= htmlspecialchars(SITE_URL, ENT_XML1) ?></link>
    <description>Google Merchant Center product feed for <?= htmlspecialchars(SHOP_NAME, ENT_XML1) ?> garments.</description>
<?php foreach ($products as $p):
    $link  = productUrl($p['id'], $p['name'], $p['seo_url'] ?? null);
    $image = !empty($p['image'])
        ? SITE_URL . '/uploads/products/' . $p['image']
        : siteLogoUrl($pdo);
    $baseCode = productDisplayCode($p);
    $variants = feedVariants($pdo, (int)$p['id']);

    // Sale pricing: g:price is the regular price and g:sale_price the current one,
    // so Merchant Center can show the strike-through the site already shows.
    $mrp        = (float)($p['mrp_price'] ?? 0);
    $sellPrice  = (float)$p['price'];
    $hasSale    = $mrp > $sellPrice && $sellPrice > 0;
    $regular    = $hasSale ? $mrp : $sellPrice;

    // Free delivery above the threshold is what this item would really cost to ship.
    $shipRate = ($freeOver > 0 && $sellPrice >= $freeOver) ? 0.0 : $shipFee;

    $description = trim(preg_replace('/\s+/', ' ', strip_tags((string)$p['description'])));
    $material    = trim((string)($p['composition'] ?: ($p['fabric'] ?? '')));

    // A garment with no photograph or no description is not advertisable, so it
    // is left OUT of the feed rather than padded to look complete.
    //
    // g:description is a required Merchant Center attribute — an item missing it
    // is disapproved on submission. Worse, the $image line above quietly
    // substituted the shop LOGO whenever a product had no picture, and a logo
    // used as a product image is separately grounds for disapproval and, at
    // volume, for account-level warnings against the whole feed.
    //
    // This file already refuses to invent data elsewhere: it declares
    // g:identifier_exists=no rather than fabricating a GTIN. Same judgement here.
    if (empty($p['image']) || $description === '') {
        continue;
    }

    // One item per size when sizes exist, sharing an item_group_id. Google matches
    // a shopper's size to a specific item; a single sizeless item cannot be shown
    // for a size query at all.
    $rows = [];
    foreach ($variants as $v) {
        $size = trim(str_replace('Size:', '', (string)($v['size_code'] ?: $v['name'])));
        if ($size === '') { continue; }
        $rows[] = [
            'id'    => $baseCode . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $size)),
            'size'  => $size,
            'price' => (float)($v['price'] ?? 0) > 0 ? (float)$v['price'] : $sellPrice,
            'stock' => array_key_exists('stock_qty', $v) && $v['stock_qty'] !== null
                ? ((int)$v['stock_qty'] > 0) : productIsInStock($p),
        ];
    }
    if (!$rows) {
        $rows[] = ['id' => $baseCode, 'size' => '', 'price' => $sellPrice, 'stock' => productIsInStock($p)];
    }

    foreach ($rows as $row):
        $rowRegular = $hasSale ? $mrp : $row['price'];
?>
    <item>
<?php
        feedTag('g:id', $row['id']);
        feedTag('g:item_group_id', count($rows) > 1 ? $baseCode : '');
        feedTag('g:title', $p['name'] . ($row['size'] !== '' ? ' — Size ' . $row['size'] : ''), true);
        feedTag('g:description', $description, true);
        feedTag('g:link', $link);
        feedTag('g:image_link', $image);
        feedTag('g:availability', $row['stock'] ? 'in_stock' : 'out_of_stock');
        feedTag('g:condition', 'new');

        // Currency comes from the same source as the page and the JSON-LD.
        feedTag('g:price', priceForMachines($rowRegular) . ' ' . $currency);
        if ($hasSale) {
            feedTag('g:sale_price', priceForMachines($row['price']) . ' ' . $currency);
        }

        feedTag('g:brand', trim((string)($p['brand'] ?? '')) ?: SHOP_NAME, true);
        feedTag('g:mpn', $p['atelier_code'] ?? '');
        feedTag('g:gtin', $p['barcode'] ?? '');
        // Required when a product genuinely has no GTIN or MPN, which is normal
        // for garments a brand makes itself. Without it the item is disapproved.
        if (empty($p['barcode']) && empty($p['atelier_code'])) {
            feedTag('g:identifier_exists', 'no');
        }

        feedTag('g:product_type', $p['category'] ?? '', true);
        // Google's own taxonomy id for Apparel & Accessories > Clothing.
        feedTag('g:google_product_category', '1604');
        // Read from the product's category, not hardcoded. This was the literal
        // string 'female' for every item — fine while the shop sold only
        // womenswear, but the day a men's category goes live it would submit
        // menswear to Google Shopping as women's, which gets items disapproved
        // or served to the wrong audience. Products in a women's category still
        // send exactly 'female', so today's feed is unchanged.
        $genderMap = ['women' => 'female', 'men' => 'male', 'unisex' => 'unisex'];
        feedTag('g:gender', $genderMap[productGender($p)] ?? 'female');
        feedTag('g:age_group', 'adult');
        feedTag('g:size', $row['size']);
        feedTag('g:color', $p['color_way'] ?: ($p['color'] ?? ''), true);
        feedTag('g:material', $material, true);
        feedTag('g:pattern', $p['pattern'] ?? '', true);
?>
      <g:shipping>
        <g:country><?= htmlspecialchars($country, ENT_XML1) ?></g:country>
        <g:service>Standard</g:service>
        <g:price><?= priceForMachines($shipRate) ?> <?= htmlspecialchars($currency, ENT_XML1) ?></g:price>
      </g:shipping>
      <g:shipping_label><?= htmlspecialchars($shipRate > 0 ? 'standard' : 'free', ENT_XML1) ?></g:shipping_label>
    </item>
<?php endforeach; endforeach; ?>
  </channel>
</rss>
