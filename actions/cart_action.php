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
function availableStock(array $product): int {
    if (array_key_exists('total_stock', $product)) {
        $ts  = (int)($product['total_stock']  ?? 0);
        $dmg = (int)($product['damage_stock'] ?? 0);
        $off = (int)($product['sold_offline'] ?? 0);
        $sol = (int)($product['sold_online']  ?? 0);
        return max(0, $ts - $dmg - $off - $sol);
    }
    return (int)($product['stock_qty'] ?? 0);
}

// ── Helper: build cart summary ────────────────────────────────
function cartSummary(): array {
    $items = $_SESSION['cart'] ?? [];
    $count = 0;
    $total = 0.0;
    $validItems = [];

    foreach ($items as $key => $item) {
        $qty = (int)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        if ($qty > 0) {
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
        'success'        => true,
    ];
}

switch ($action) {

    // ── ADD TO CART ──────────────────────────────────────────────
    case 'add':
        $productId = (int)($_POST['product_id'] ?? 0);
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $size      = trim($_POST['size'] ?? '');
        $addQty    = max(1, (int)($_POST['quantity'] ?? 1));

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

        // Check stock availability if stock tracking enabled
        if (!empty($product['track_stock']) && availableStock($product) <= 0) {
            echo json_encode(['success' => false, 'message' => 'Sorry, this product is currently out of stock.']);
            exit;
        }

        // Check if product has active variants in database
        $checkV = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND available = 1");
        $checkV->execute(['pid' => $productId]);
        $dbVariants = $checkV->fetchAll();

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
                $price     = $color['price_override'] !== null ? (float)$color['price_override'] : (float)$product['price'];
            } elseif (!empty($product['track_stock']) && isset($variant['stock_qty']) && $variant['stock_qty'] !== null && (int)$variant['stock_qty'] < $addQty) {
                echo json_encode(['success' => false, 'message' => 'Selected variant has insufficient stock.']);
                exit;
            } else {
                $price = (float)$variant['price'];
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
                $variantId   = (int)$matchedVar['id'];
                $variantName = $matchedVar['name'];
                $price       = (float)$matchedVar['price'];
            } else {
                // Standard ready-to-wear sizes allowed list
                $allowedSizes = ['S', 'M', 'L', 'XL', 'XXL', '3XL', 'CUSTOM'];
                if (!in_array(strtoupper($size), $allowedSizes)) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Invalid size format specified.']);
                    exit;
                }
                $variantName = "Size: " . strtoupper($size);
            }
        }

        // Construct unique cart key
        if ($variantId > 0) {
            $cartKey = "{$productId}:var{$variantId}";
        } elseif ($size !== '') {
            $cartKey = "{$productId}:" . strtoupper($size);
        } else {
            $cartKey = (string)$productId;
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
            $newQty = $_SESSION['cart'][$cartKey]['quantity'] + $addQty;
            // Validate max stock — colour-scoped sizes are capped by their own stock_qty
            if ($colorId && isset($variant)) {
                $newQty = min($newQty, max(0, (int)$variant['stock_qty']));
            } elseif (!empty($product['track_stock']) && $newQty > availableStock($product)) {
                $newQty = availableStock($product);
            }
            $_SESSION['cart'][$cartKey]['quantity'] = $newQty;
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
            ];
        }

        $summary = cartSummary();
        echo json_encode(['message' => 'Added to cart successfully!'] + $summary);
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
                $_SESSION['cart'][$cartKey]['quantity'] = $qty;
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
