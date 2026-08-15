<?php
// ============================================================
//  Dievon – Cart AJAX Handler (Single Source of Truth)
//  POST/GET actions: add | remove | update | clear | get
//  Cart key format:
//    - With size: "42:M"
//    - With variant: "42:7"
//    - Simple product: "42"
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

// ── Helper: real available stock ────────────────────────────────
// `stock_qty` is a legacy column that the Stock tab (admin/stock_handler.php)
// and delivery-triggered deduction (admin/update_order.php) never actually
// update — those maintain total_stock/damage_stock/sold_offline/sold_online
// instead. Mirror that exact formula here so the gate that decides whether a
// customer can buy something matches what the admin panel actually tracks.
// availableStock() now lives in config/config.php so the product page can apply
// the same formula this file enforces. One definition, no drift.

// ── Helper: build cart summary ────────────────────────────────
/* Every reply this endpoint sends is measured against stock as it stands right
   now, not as it stood when the item was added. The bag is a session array, so
   anything that reduced stock afterwards — the owner removing units in the Stock
   tab, another shopper taking the last one — left it claiming a quantity that no
   longer existed, all the way up to the payment page. cartRevalidateStock()
   clamps the lines and hands back a note for each one it changed. */
function cartSummary(): array {
    global $pdo;

    $stockNotices = ($pdo instanceof PDO) ? cartRevalidateStock($pdo) : [];

    $items = $_SESSION['cart'] ?? [];
    $count = 0;
    $total = 0.0;
    $validItems = [];

    foreach ($items as $key => $item) {
        $qty = (int)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        if ($qty > 0) {
            /* The line's own ceiling travels with it, so the cart page can stop
               the + button at the last unit instead of firing a request that
               comes back refused. null means "no ceiling" (untracked). */
            $item['stock_limit'] = ($pdo instanceof PDO) ? cartLineStockLimit($pdo, $item) : null;
            $count += $qty;
            $total += $price * $qty;
            $validItems[] = $item;
        } else {
            unset($_SESSION['cart'][$key]);
        }
    }

    return [
        'items'          => array_values($validItems),
        'cart_count'     => $count,
        'cart_total'     => number_format($total, 2),
        'cart_total_raw' => $total,
        'stock_notices'  => $stockNotices,
        'success'        => true,
    ];
}

switch ($action) {

    // ── ADD TO CART ──────────────────────────────────────────────
    case 'add':
        $productId = (int)($_POST['product_id'] ?? 0);
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $size      = trim($_POST['size'] ?? '');
        /* Clamped at both ends. The lower bound was already here; the upper one
           is new. Stock caps a tracked product further down, but an untracked
           piece had no ceiling at all and could be added any number of times. */
        $addQty    = max(1, (int)($_POST['quantity'] ?? 1));
        $addQty    = min($addQty, cartMaxQtyPerItem($pdo));

        if ($productId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product specified.']);
            exit;
        }

        // Fetch product
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND available = 1");
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product is currently unavailable or out of stock.']);
            exit;
        }

        // Variants first — the stock gate below needs them.
        $checkV = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND available = 1");
        $checkV->execute(['pid' => $productId]);
        $dbVariants = $checkV->fetchAll();

        // Stock gate, counting the SIZES as well as the product-level figure.
        //
        // This used availableStock($product) alone, which is a product-level
        // subtraction (total_stock − damage − sold_offline − sold_online). On a
        // product whose stock lives on its sizes, that figure can reach 0 while
        // the sizes still hold units — recording damage or offline sales is
        // enough to do it. Measured on product 26: total 21, damage 3, offline
        // 18, online 3, so availableStock() = 0, while variants 102/103/104 held
        // 2 + 5 + 3 = TEN sellable units. The product page advertised "Only 5
        // left" and every single Add to Bag was refused with "this product is
        // currently out of stock".
        //
        // productIsInStock() is the shared definition the product page and the
        // grid already use, and its docblock says it "mirrors what
        // actions/cart_action.php will allow" — which was not true until now.
        // Per-size stock is still enforced further down, where the chosen
        // variant's own stock_qty is checked against the quantity asked for.
        if (!productIsInStock($product, $dbVariants)) {
            echo json_encode(['success' => false, 'message' => 'Sorry, this product is currently out of stock.']);
            exit;
        }

        // If product has no DB variants, default size to 'Standard' if empty
        if (empty($dbVariants) && $variantId <= 0 && $size === '') {
            $size = 'Standard';
        }

        // 1. Check if product requires size selection or variant
        if ($variantId <= 0 && $size === '') {
            echo json_encode([
                'success'       => false, 
                'requires_size' => true, 
                'product_id'    => $productId, 
                'message'       => 'Please select a garment size before adding to bag.'
            ]);
            exit;
        }

        $variantName = '';
        $price       = (float)$product['price'];
        $colorId     = null;
        $colorName   = '';
        $colorSku    = '';

        if ($variantId > 0) {
            $vStmt = $pdo->prepare("SELECT * FROM product_variants WHERE id = :vid AND product_id = :pid AND available = 1");
            $vStmt->execute(['vid' => $variantId, 'pid' => $productId]);
            $variant = $vStmt->fetch();
            if (!$variant) {
                echo json_encode(['success' => false, 'message' => 'Selected variant is invalid, out of stock, or belongs to another product.']);
                exit;
            }

            // Colour-scoped size: the variant row's own color_id is the source of
            // truth (never trust a client-submitted color_id) — it also doubles as
            // the per-colour stock/price lookup.
            if (!empty($variant['color_id'])) {
                $cStmt = $pdo->prepare("SELECT * FROM product_colors WHERE id = :cid AND product_id = :pid AND is_active = 1");
                $cStmt->execute(['cid' => $variant['color_id'], 'pid' => $productId]);
                $color = $cStmt->fetch();
                if (!$color) {
                    echo json_encode(['success' => false, 'message' => 'Selected colour is no longer available.']);
                    exit;
                }
                if ((int)$variant['stock_qty'] < $addQty) {
                    echo json_encode(['success' => false, 'message' => 'Only ' . max(0, (int)$variant['stock_qty']) . ' left in this size/colour.']);
                    exit;
                }
                $colorId   = (int)$color['id'];
                $colorName = $color['color_name'];
                $colorSku  = $color['sku'] ?? '';
                /* The SIZE's own price was being thrown away here.
                   ────────────────────────────────────────────────────────────
                   This read "the colour's override, or else the product price"
                   and never looked at $variant['price'] at all — so on a product
                   sold as size-within-colour, every size charged the product's
                   figure. A product priced 2000 whose Red/M was priced 1799
                   charged 1799 nowhere: not in the bag, not at checkout, not on
                   the invoice. Only the plain-size branch below ever consulted
                   the size price, which is why this went unseen.

                   effectiveVariantPrice() already ranks these correctly —
                   colour override, then size price, then product price, with
                   the sale clamp — and is what the product page, Quick View and
                   checkout resolve through. */
                $price     = effectiveVariantPrice($product, $variant, $color);
            } elseif (!empty($product['track_stock']) && isset($variant['stock_qty']) && $variant['stock_qty'] !== null && (int)$variant['stock_qty'] < $addQty) {
                echo json_encode(['success' => false, 'message' => 'Selected variant has insufficient stock.']);
                exit;
            } else {
                // One shared price function — config/config.php. The cap that used
                // to live here is inside it, along with the reason it exists. It is
                // shared because actions/checkout_action.php had no cap at all, so
                // the cart and the order disagreed and the customer was billed more
                // than the checkout screen showed.
                $price = effectiveVariantPrice($product, $variant);
            }
            $variantName = $variant['name'];
        } elseif ($size !== '') {
            // Text size validation
            if (!empty($dbVariants)) {
                // Product has explicit DB variants, text size MUST match an available variant
                $matchedVar = null;
                foreach ($dbVariants as $dv) {
                    if (strcasecmp($dv['name'], $size) === 0 || (isset($dv['size']) && strcasecmp($dv['size'], $size) === 0)) {
                        $matchedVar = $dv;
                        break;
                    }
                }
                if (!$matchedVar) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Selected size does not match any valid active variant for this product.']);
                    exit;
                }
                /* Check the stock of the size that was matched.
                   ────────────────────────────────────────────────────────────
                   THIS WAS MISSING. A request can name a size two ways: by
                   variant_id, or by the size text. The variant_id branch above
                   checks stock_qty before accepting; this one resolved the
                   variant, took its id and its price, and added it to the bag
                   without ever looking at how many were left.

                   So a sold-out size could be added — the shopper got "Added to
                   Bag!" on a garment with none in stock — as long as the request
                   carried the size as text rather than an id. Everything after
                   this point trusts that the line was validated.

                   Same rule as the other branch: a NULL stock_qty means the size
                   is not counted, which is not the same as none left. */
                if (!empty($product['track_stock'])
                    && array_key_exists('stock_qty', $matchedVar)
                    && $matchedVar['stock_qty'] !== null
                    && (int)$matchedVar['stock_qty'] < $addQty) {
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => (int)$matchedVar['stock_qty'] === 0
                            ? 'Sorry, this size is sold out.'
                            : 'Only ' . max(0, (int)$matchedVar['stock_qty']) . ' left in this size.',
                    ]);
                    exit;
                }

                $variantId   = (int)$matchedVar['id'];
                $variantName = $matchedVar['name'];
                $price       = (float)$matchedVar['price'];
            } else {
                // This product has no variants, so the only value that belongs
                // here is the 'Standard' placeholder assigned further up for a
                // genuine one-size piece. A real size means the page offered
                // something the catalogue does not have.
                //
                // What stood here waved through a fixed list — S, M, L, XL, XXL,
                // 3XL, CUSTOM — as free text with no stock row behind it: the
                // server-side twin of the hard-coded ladder the product page used
                // to render. The page offered five sizes that did not exist and
                // this accepted them, so a garment listed before its sizes were
                // entered could take an order for XXL.
                //
                // That list also left out 'Standard', so a one-size product was
                // rejected outright — invisible only because the old ladder always
                // posted a size, so the default never came into play.
                if (strcasecmp($size, 'Standard') !== 0) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'This product has no sizes configured. Please refresh the page and try again.']);
                    exit;
                }
                // One size: the line carries no size caption at all rather than a
                // literal "Size: STANDARD" on the cart, the invoice and the order.
                $variantName = '';
            }
        }

        // COLOUR-ONLY product: colours with no sizes behind them.
        //
        // Only the colour-scoped-SIZE path above set $colorId, because it reads
        // the colour off the variant row. A product with product_colors and no
        // variants — a stole, a scarf, a bag — has no variant to read, so the
        // colour was dropped entirely. Two costs, both real: the colourway's
        // price_override was ignored (the page showed Champagne at ₹949 and this
        // charged the base ₹899, and any colour dearer than base sold at a loss),
        // and because the cart key carried no colour, every colourway collapsed
        // into ONE line — so the packing slip and the invoice could not say which
        // colour to ship.
        //
        // The colour is re-read from the database rather than trusted from the
        // POST, exactly as the colour-scoped-size branch does, so a tampered
        // price or another product's colour cannot come in this way.
        if ($colorId === null && empty($dbVariants)) {
            $colorCheck = $pdo->prepare("SELECT COUNT(*) FROM product_colors WHERE product_id = :pid AND is_active = 1");
            $colorCheck->execute(['pid' => $productId]);

            if ((int)$colorCheck->fetchColumn() > 0) {
                $postedColorId = (int)($_POST['color_id'] ?? 0);
                if ($postedColorId <= 0) {
                    // Refused rather than guessed. Picking a colour on the
                    // shopper's behalf would charge them for one they never chose.
                    // This also closes the quick-view and any legacy caller, which
                    // do not send a colour.
                    echo json_encode([
                        'success'        => false,
                        'requires_color' => true,
                        'product_id'     => $productId,
                        'message'        => 'Please choose a colour before adding to bag.',
                    ]);
                    exit;
                }

                $cStmt = $pdo->prepare("SELECT * FROM product_colors WHERE id = :cid AND product_id = :pid AND is_active = 1");
                $cStmt->execute(['cid' => $postedColorId, 'pid' => $productId]);
                $color = $cStmt->fetch();
                if (!$color) {
                    echo json_encode(['success' => false, 'message' => 'Selected colour is no longer available.']);
                    exit;
                }

                $colorId   = (int)$color['id'];
                $colorName = $color['color_name'];
                $colorSku  = $color['sku'] ?? '';
                // Colour with no size behind it. Same shared resolver as every
                // other branch, so the sale clamp applies here too.
                $price     = effectiveVariantPrice($product, null, $color);
            }
        }

        // ── Country pricing ─────────────────────────────────────────
        // product_country_prices carries one flat price per product, not one
        // per size/colour, so a shopper abroad pays that single figure
        // regardless of which variant/colour branch above set $price —
        // overridden here, once, after every branch has run. A product with
        // no row for the shopper's country is refused outright rather than
        // added at its INR price under the wrong currency's symbol.
        if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
            $countryPricing = productCountryPricing($product);
            if ($countryPricing === null) {
                echo json_encode(['success' => false, 'message' => 'Sorry, this product is not available for delivery to your selected country.']);
                exit;
            }
            $price = $countryPricing['price'];
        }

        // Construct unique cart key
        if ($variantId > 0) {
            $cartKey = "{$productId}:var{$variantId}";
        } elseif ($size !== '') {
            $cartKey = "{$productId}:" . strtoupper($size);
        } else {
            $cartKey = (string)$productId;
        }
        // A colour with no variant behind it still makes the line distinct —
        // without this, Champagne and Midnight Black merged into one line of
        // quantity 2. Colour-scoped sizes already key on their variant, which
        // belongs to exactly one colour, so this only applies to colour-only.
        if ($colorId && $variantId <= 0) {
            $cartKey .= ":col{$colorId}";
        }

        // Colour-scoped size uses its own gallery thumbnail when available
        $itemImage = $product['image'] ?? '';
        if ($colorId) {
            $colorImgStmt = $pdo->prepare("SELECT image FROM product_color_images WHERE color_id = :cid ORDER BY sort_order ASC, id ASC LIMIT 1");
            $colorImgStmt->execute(['cid' => $colorId]);
            $colorImg = $colorImgStmt->fetchColumn();
            if ($colorImg) { $itemImage = $colorImg; }
        }

        if (isset($_SESSION['cart'][$cartKey])) {
            $existingQty = (int)$_SESSION['cart'][$cartKey]['quantity'];
            $newQty      = $existingQty + $addQty;

            /* Work out the real ceiling for this line, then SAY when it is reached.
               ────────────────────────────────────────────────────────────────────
               The stock guard further up compares stock against the amount being
               ADDED, not against what would then be in the bag. So asking for 50
               at once is refused properly, but pressing Add one at a time past the
               limit silently clamped and returned success: the number stopped
               moving, no message appeared, and the shopper was left pressing a
               button that looked broken.

               Nothing could be oversold either way — the clamp held and checkout
               re-checks — but "it just stops working" is the wrong way to tell
               someone they already have everything you can sell them. */
            $lineLimit = cartMaxQtyPerItem($pdo);
            $stockLimit = null;

            /* The SIZE's own stock is the ceiling wherever a size is in play —
               with or without a colour attached.
               ────────────────────────────────────────────────────────────────
               This asked for the variant's stock only when a colour was attached
               ($colorId && isset($variant)), and fell back to the product-level
               figure for every plain size. Those two numbers are not the same
               thing: a product can carry total_stock 60 across its sizes while
               the size actually chosen holds 1. The ceiling therefore came out
               as 10 rather than 1, this guard let the add through, and the bag
               was only pulled back into line afterwards by cartRevalidateStock().

               Nothing was oversold — but the shopper got "Added to Bag!" and a
               "your bag has been updated from 2 to 1" notice in the same breath,
               which reads as the site contradicting itself. Measured on a size
               holding exactly 1 unit.

               A NULL stock_qty still means the size is not counted, so it leaves
               the ceiling to the product-level figure below rather than reading
               as zero. */
            $limitVariant = $variant ?? $matchedVar ?? null;
            if (is_array($limitVariant)
                && array_key_exists('stock_qty', $limitVariant)
                && $limitVariant['stock_qty'] !== null) {
                $stockLimit = max(0, (int)$limitVariant['stock_qty']);
            } elseif (!empty($product['track_stock'])) {
                $stockLimit = max(0, (int)availableStock($product));
            }
            if ($stockLimit !== null) { $lineLimit = min($lineLimit, $stockLimit); }

            if ($existingQty >= $lineLimit) {
                echo json_encode([
                    'success' => false,
                    'message' => ($stockLimit !== null && $lineLimit === $stockLimit)
                        ? 'Your bag already holds all ' . $lineLimit . ' we have of this one.'
                        : 'You can order up to ' . $lineLimit . ' of one item.',
                ]);
                exit;
            }

            $newQty = min($newQty, $lineLimit);
            $_SESSION['cart'][$cartKey]['quantity'] = $newQty;

            // Asked for more than was left, but some of it fitted — say so rather
            // than quietly handing over a smaller number than was requested.
            if ($existingQty + $addQty > $lineLimit) {
                $partialAddNotice = 'Only ' . ($newQty - $existingQty) . ' more could be added — '
                                  . 'your bag now holds ' . $newQty . '.';
            }
        } else {
            $_SESSION['cart'][$cartKey] = [
                'cart_key'    => $cartKey,
                'product_id'  => $productId,
                'variant_id'  => $variantId > 0 ? $variantId : null,
                'size'        => $size !== '' ? strtoupper($size) : null,
                'variant_name'=> $variantName,
                'color_id'    => $colorId,
                'color_name'  => $colorName,
                'color_sku'   => $colorSku,
                'name'        => $product['name'],
                'emoji'       => $product['emoji'] ?? '✨',
                'image'       => $itemImage,
                'price'       => $price,
                'quantity'    => $addQty,
                // Tax identity is FROZEN into the cart line here, not looked up
                // when the invoice is printed. A rate change next month must not
                // retroactively rewrite an invoice already issued — the document
                // has to show the rate that applied at the moment of sale.
                'hsn_code'    => $product['hsn_code'] ?? null,
                'gst_rate'    => isset($product['gst_rate']) ? (float)$product['gst_rate'] : null,
            ];
        }

        $summary = cartSummary();
        // A partial add is still a success, but it must not claim the whole
        // amount went in — the shopper asked for more than was left.
        echo json_encode([
            'message' => $partialAddNotice ?? 'Added to cart successfully!',
        ] + $summary);
        break;

    // ── REMOVE ITEM ──────────────────────────────────────────────
    case 'remove':
        $cartKey = trim($_POST['cart_key'] ?? '');
        if ($cartKey !== '' && isset($_SESSION['cart'][$cartKey])) {
            unset($_SESSION['cart'][$cartKey]);
        }
        echo json_encode(cartSummary());
        break;

    // ── UPDATE QUANTITY ──────────────────────────────────────────
    case 'update':
        $cartKey = trim($_POST['cart_key'] ?? '');
        $qty     = (int)($_POST['quantity'] ?? 0);

        if ($cartKey !== '' && isset($_SESSION['cart'][$cartKey])) {
            if ($qty <= 0) {
                unset($_SESSION['cart'][$cartKey]);
            } else {
                // Cap at what is actually in stock.
                //
                // Adding to the cart was stock-gated, but this path — the
                // quantity box on the cart page — accepted whatever number was
                // posted, with no check of any kind. A shopper could add one,
                // then change the quantity to a thousand.
                //
                // A NULL stock_qty means the owner is not tracking that variant,
                // which is not the same as none left, so it is left uncapped.
                $limit = null;
                try {
                    $line = $_SESSION['cart'][$cartKey];
                    if (!empty($line['variant_id'])) {
                        $vq = $pdo->prepare("SELECT stock_qty FROM product_variants WHERE id = :id");
                        $vq->execute(['id' => (int)$line['variant_id']]);
                        $found = $vq->fetchColumn();
                        if ($found !== false && $found !== null) { $limit = (int)$found; }
                    }
                } catch (PDOException $e) { /* leave uncapped rather than block the cart */ }

                if ($limit !== null && $qty > $limit) { $qty = max(0, $limit); }

                /* The ceiling applies here too, and to every line — not only the
                   ones with a stock number behind them. This branch capped by
                   variant stock alone, so a line whose stock is untracked (NULL,
                   or a product with track_stock off) could still be typed up to
                   any figure from the quantity box on the cart page. */
                $qty = min($qty, cartMaxQtyPerItem($pdo));

                if ($qty <= 0) {
                    unset($_SESSION['cart'][$cartKey]);
                } else {
                    $_SESSION['cart'][$cartKey]['quantity'] = $qty;
                }
            }
        }
        echo json_encode(cartSummary());
        break;

    // ── CLEAR CART ───────────────────────────────────────────────
    case 'clear':
        $_SESSION['cart'] = [];
        echo json_encode(cartSummary());
        break;

    // ── GET CART STATE ───────────────────────────────────────────
    case 'get':
    default:
        echo json_encode(cartSummary());
        break;
}
