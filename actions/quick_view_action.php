<?php
// ============================================================
//  Dievon – Quick View data endpoint
// ------------------------------------------------------------
//  Returns just enough about one product to fill the Quick View modal, so the
//  shopper can see it and add to bag without leaving the listing page.
//
//  Read-only and public by design: it exposes exactly what the product page
//  already shows to anyone. It deliberately does NOT return cost price, stock
//  internals or any admin-only field.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Product id required.']);
    exit;
}

try {
    /* Published AND not archived. The comment here already said "a hidden or
       ARCHIVED product must not be reachable through Quick View just because
       someone kept the id" — but the clause only tested available = 1, so it
       enforced half of what it claimed. A row archived while still flagged
       available (is_deleted = 1, available = 1) opened a full Quick View panel,
       priced, with live size chips and a working Add to Bag, while the shop
       grid, the search and the sitemap all correctly hid it. Every other read
       of this table pairs the two conditions; this is the last one that did
       not. category_id comes along for sizeLadderForCategory() below, which
       was reading a column that was never selected. */
    $stmt = $pdo->prepare("SELECT id, name, description, price, mrp_price, category, category_id,
                                  image, emoji, fabric, color, color_way, sleeve, neck, pattern, occasion,
                                  badge, available, track_stock, stock_qty, seo_url, created_at
                           FROM products
                          WHERE id = :id
                            AND available = 1
                            AND (is_deleted = 0 OR is_deleted IS NULL)
                          LIMIT 1");
    $stmt->execute(['id' => $productId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Product not available.']);
        exit;
    }

    // Country pricing, exactly as pages/product.php and includes/product_card.php
    // apply it.
    //
    // This read $p['price'] straight — the INR figure — and handed it to
    // formatPrice(), which stamps the CURRENT country's symbol on it. A shopper
    // in a country where the piece costs £45 was shown "£1,899", and a piece not
    // sold in their country at all was shown a full price and an Add to Bag
    // button, while the grid behind the modal correctly hid it. The cart then
    // refused the line.
    //
    // Variant and colour price_overrides are INR-only figures with no country
    // equivalent, so abroad they are ignored in favour of the flat country price
    // — the same rule pages/product.php:66 follows.
    $qvCountry     = function_exists('currentCountryCode') ? currentCountryCode() : 'IN';
    $qvHome        = strtoupper(function_exists('homeCountryRow') ? (homeCountryRow()['country_code'] ?? 'IN') : 'IN');
    $qvIsHome      = strtoupper($qvCountry) === $qvHome;
    $qvAllowOverrides = $qvIsHome;

    if (!$qvIsHome && function_exists('productCountryPricing')) {
        $qvPricing = productCountryPricing($p, $qvCountry);
        if ($qvPricing === null) {
            echo json_encode([
                'success' => false,
                'message' => 'This piece is not available in your country yet.',
            ]);
            exit;
        }
        $p['price']     = $qvPricing['price'];
        $p['mrp_price'] = $qvPricing['mrp'];
    }

    // Sizes: colour-specific rows when the product has colours, otherwise the
    // plain size list. Mirrors how the product page decides.
    // Stock semantics: a variant's stock_qty is NULL for most rows (42 of 49 in this
    // catalogue) — that means "no variant-level count, use the product's". Treating
    // NULL as 0 would mark almost every size out of stock and block the sale, so only
    // an explicit number decides, and even then only when the product tracks stock.
    $tracksStock   = (int)($p['track_stock'] ?? 0) === 1;
    $productStock  = $p['stock_qty'] ?? null;

    $sizes = [];
    // Colour comes with the size, and a DEACTIVATED colourway brings none.
    //
    // This selected every available variant regardless of colour, which caused
    // two problems on any product with colourways. Sizes appeared once PER
    // COLOUR with no colour on the caption — product 23 rendered six chips
    // reading 32/S, 34/M, 36/L, 32/S, 34/M, 36/L — so two chips looked identical
    // and picked different garments at different prices. And deactivating a
    // colour in admin left its sizes on offer here, every one failing at Add to
    // Bag with "Selected colour is no longer available". pages/product.php
    // already filters on is_active; this did not.
    /* A product sells EITHER by plain size OR by colourway — never both.
       ────────────────────────────────────────────────────────────────────────
       pages/product.php has always obeyed that: it reads `color_id IS NULL` for
       the plain ladder and $productColors for the colour one, and shows exactly
       one of them. Quick View took `(color_id IS NULL OR c.is_active = 1)` —
       both at once.

       On a product with six colours in four sizes that meant 24 colour chips
       PLUS the four leftover plain rows the shop had stopped selling: 28 chips,
       four of which offer a garment with no colour at all. Buying one produces
       an order line naming a size and no colourway, which cannot be picked off
       a shelf, and which the product page would never have sold.

       Those plain rows are not junk — they are what the Product Sizes panel
       edits before any colour exists. They simply stop being for sale the
       moment a colour does, and Quick View is the only place that had not been
       told. */
    $hasColour = false;
    try {
        $cChk = $pdo->prepare(
            "SELECT 1 FROM product_colors WHERE product_id = :id AND is_active = 1 LIMIT 1"
        );
        $cChk->execute(['id' => $productId]);
        $hasColour = (bool)$cChk->fetchColumn();
    } catch (PDOException $e) { $hasColour = false; }

    $vStmt = $pdo->prepare("SELECT v.id, v.name, v.size_code, v.price, v.stock_qty,
                                   v.color_id, c.color_name, c.price_override, c.sort_order AS color_sort
                              FROM product_variants v
                              LEFT JOIN product_colors c
                                     ON c.id = v.color_id AND c.product_id = v.product_id
                             WHERE v.product_id = :id
                               AND v.available = 1
                               AND " . ($hasColour
                                   ? "v.color_id IS NOT NULL AND c.is_active = 1"
                                   : "v.color_id IS NULL") . "
                             ORDER BY v.sort_order ASC, v.id ASC");
    $vStmt->execute(['id' => $productId]);
    foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        if ($v['stock_qty'] !== null) {
            $stock = (int)$v['stock_qty'];              // explicit per-size count
        } elseif ($tracksStock && $productStock !== null) {
            $stock = (int)$productStock;                // fall back to the product total
        } else {
            $stock = 1;                                 // stock not tracked → sellable
        }
        // 42 of 49 variants are stored as "Size: M" rather than "M". That full string is
        // what the cart matches on, so it has to travel as 'value' untouched — only the
        // button caption is shortened, otherwise a 46px-wide chip has to render "Size: M".
        $fullName = $v['name'] ?: $v['size_code'];
        $caption  = preg_replace('/^\s*(size|sizes)\s*[:\-]\s*/i', '', (string)$fullName);
        if (trim((string)$caption) === '') { $caption = $fullName; }

        // Name the colour on the chip, so two chips reading "36/L" are told apart.
        if (!empty($v['color_name'])) {
            $caption .= ' · ' . $v['color_name'];
        }

        // The price this specific chip will actually be charged at.
        //
        // Quick View quoted only the product's base price, so picking a
        // colourway carrying a price_override — or a dearer size — added a line
        // to the bag costing more than the panel displayed. Same function the
        // product page, the cart and the order handler use.
        // Abroad, the flat country price wins — a colourway's price_override is
        // an INR figure and applying it under another currency would quote a
        // number that means nothing.
        $chipColor = ($qvAllowOverrides && !empty($v['color_id']))
            ? ['price_override' => $v['price_override']]
            : null;
        $chipPrice = $qvAllowOverrides
            ? effectiveVariantPrice($p, $v, $chipColor)
            : (float)$p['price'];

        $sizes[] = [
            'id'    => (int)$v['id'],
            'label' => $caption,     // shown on the chip
            'value' => $fullName,    // posted to the cart — must stay byte-identical
            'code'  => $v['size_code'],
            'stock' => $stock,
            'price' => formatPrice($chipPrice),
            // Sorting only — stripped before the payload goes out.
            '_cs'   => (int)($v['color_sort'] ?? 0),
            '_cn'   => (string)($v['color_name'] ?? ''),
        ];
    }

    /* Grouped by colour, then walked up the size ladder.
       ────────────────────────────────────────────────────────────────────────
       The query ordered by v.sort_order, which is numbered PER COLOUR — so
       every colour's "1" sorted together, then every colour's "2", and the
       chips came out interleaved: 40/L Navy, 40/L Dark Green, 38/M Navy,
       42/XL Navy, 38/M Dark Green… A shopper hunting one colour had to read
       every chip in the panel.

       Colour first in the owner's own order, then the ladder, so a colour's
       sizes sit together and run small to large. */
    if ($sizes) {
        $ladderPos = [];
        foreach (sizeLadderForCategory((int)($p['category_id'] ?? 0)) as $i => $rung) {
            $ladderPos[$rung['code']] = $i;
        }
        usort($sizes, function ($a, $b) use ($ladderPos) {
            if ($a['_cs'] !== $b['_cs']) { return $a['_cs'] <=> $b['_cs']; }
            if ($a['_cn'] !== $b['_cn']) { return strcasecmp($a['_cn'], $b['_cn']); }
            // A size off the ladder ("Free Size") sorts after the ladder rather
            // than at position 0, where it would head the list.
            $ai = $ladderPos[$a['code']] ?? PHP_INT_MAX;
            $bi = $ladderPos[$b['code']] ?? PHP_INT_MAX;
            return $ai <=> $bi;
        });
        /* The colour name survives as 'color' so the panel can group the chips
           under it and strike the heading when that whole colour has gone.
           Without it the only trace of colour was inside the caption text, and
           a shopper had to read all 24 chips to work out that pink was over.
           Empty string on a colourless product, which renders no heading. */
        foreach ($sizes as &$__s) {
            $__s['color'] = $__s['_cn'];
            unset($__s['_cs'], $__s['_cn']);
        }
        unset($__s);
    }

    // Gallery: main image plus any additional images.
    $images = [];
    if (!empty($p['image'])) { $images[] = SITE_URL . '/uploads/products/' . $p['image']; }
    try {
        $iStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = :id ORDER BY sort_order ASC, id ASC LIMIT 4");
        $iStmt->execute(['id' => $productId]);
        // Same rule as the full product page: a row whose file is not on disk
        // is skipped, not shown as a broken thumbnail and not deleted. The file
        // may be in the media quarantine waiting to be restored.
        $imgDir = __DIR__ . '/../uploads/products/';
        foreach ($iStmt->fetchAll(PDO::FETCH_COLUMN) as $img) {
            if (trim((string)$img) === '' || !is_file($imgDir . $img)) { continue; }
            $images[] = SITE_URL . '/uploads/products/' . $img;
        }
    } catch (PDOException $e) {}

    /* The headline must be a price one of the chips below it will charge.
       ────────────────────────────────────────────────────────────────────────
       This printed products.price while every chip was already priced through
       effectiveVariantPrice(), so the two halves of the same panel disagreed:

         Aurora Silk Saree   headline ₹3,000   chips ₹1,500 / ₹2,600 / ₹2,600
         Zephyr Palazzo      headline ₹1,250   chips ₹1,100 · Ivory (both)

       ₹3,000 is not a price any size of that saree can be bought at, and the
       shopper only found out by clicking a chip — qvPickSize() then rewrote the
       headline underneath them. The card they opened it from said "From ₹1,500"
       all along.

       Resolved through productPriceRange(), which is the same ladder the chips,
       the card and the cart use, so the headline is by construction the
       cheapest chip. Abroad the flat country price already replaced $p['price']
       above and overrides sizes entirely, so it is left exactly as it is.

       The saving is then measured against the price actually shown, and
       withdrawn when that price is only a floor — an MRP struck through beside
       a "from" figure claims a discount that holds for one size only. */
    $mrp   = (float)($p['mrp_price'] ?? 0);
    $price = (float)$p['price'];
    $varies = false;
    if ($qvAllowOverrides) {
        $qvRange = productPriceRange($p);
        if ($qvRange['min'] > 0) {
            $price  = (float)$qvRange['min'];
            $varies = (bool)$qvRange['varies'];
        }
    }
    $hasDiscount = !$varies && $mrp > $price && $price > 0;

    echo json_encode([
        'success' => true,
        'product' => [
            'id'          => (int)$p['id'],
            'name'        => $p['name'],
            'description' => mb_strimwidth(strip_tags((string)$p['description']), 0, 220, '…'),
            'category'    => $p['category'],
            // productBadge(): an expired "New" is not sent to the panel at all.
            'badge'       => productBadge($p),
            'emoji'       => $p['emoji'],
            // "From" when the sizes are priced differently from one another, so
            // the headline reads as a floor rather than as the price — matching
            // the card the shopper opened this from.
            'price'       => ($varies ? 'From ' : '') . formatPrice($price),
            'mrp'         => $hasDiscount ? formatPrice($mrp) : null,
            'discount'    => $hasDiscount ? (int)round((($mrp - $price) / $mrp) * 100) : 0,
            'images'      => array_values(array_unique($images)),
            'sizes'       => $sizes,
            // Only attributes with a real value — see isHumanReadableAttribute(),
            // which also keeps hex colour swatches out of customer-facing text.
            'attributes'  => array_filter([
                'Fabric'   => isHumanReadableAttribute($p['fabric'] ?? '')   ? $p['fabric']   : null,
                'Colour'   => isHumanReadableAttribute(dievonProductColour($p)) ? dievonProductColour($p) : null,
                'Sleeve'   => isHumanReadableAttribute($p['sleeve'] ?? '')   ? $p['sleeve']   : null,
                'Neck'     => isHumanReadableAttribute($p['neck'] ?? '')     ? $p['neck']     : null,
                'Pattern'  => isHumanReadableAttribute($p['pattern'] ?? '')  ? $p['pattern']  : null,
                'Occasion' => isHumanReadableAttribute($p['occasion'] ?? '') ? $p['occasion'] : null,
            ]),
            'url'         => productUrl($p['id'], $p['name'], $p['seo_url'] ?? null),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    error_log('quick_view_action: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load this product.']);
}
