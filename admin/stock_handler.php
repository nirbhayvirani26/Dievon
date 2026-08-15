<?php
// ============================================================
//  Dievon – Admin: Stock Handler
//  Actions: increment_stock (add stock, damage, offline)
// ============================================================
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('catalogue.manage', true);


// ── Helper: column existence check ────────────────────────
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ── Helper: fetch current stock row ───────────────────────
function fetchStockRow(PDO $pdo, int $productId): array {
    $hasTotal   = columnExists($pdo, 'products', 'total_stock');
    $hasDamage  = columnExists($pdo, 'products', 'damage_stock');
    $hasOffline = columnExists($pdo, 'products', 'sold_offline');
    $hasOnline  = columnExists($pdo, 'products', 'sold_online');

    $parts = [
        "IFNULL(stock_qty, 0) AS stock_qty",
        $hasTotal   ? "IFNULL(total_stock, 0) AS total_stock"   : "IFNULL(stock_qty, 0) AS total_stock",
        $hasDamage  ? "IFNULL(damage_stock, 0) AS damage_stock" : "0 AS damage_stock",
        $hasOffline ? "IFNULL(sold_offline, 0) AS sold_offline" : "0 AS sold_offline",
        $hasOnline  ? "IFNULL(sold_online, 0) AS sold_online"   : "0 AS sold_online",
    ];

    $st = $pdo->prepare("SELECT " . implode(', ', $parts) . " FROM products WHERE id = :id");
    $st->execute(['id' => $productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $ts  = (int)($row['total_stock']  ?? 0);
    $dmg = (int)($row['damage_stock'] ?? 0);
    $off = (int)($row['sold_offline'] ?? 0);
    $sol = (int)($row['sold_online']  ?? 0);

    return [
        'total_stock'  => $ts,
        'damage_stock' => $dmg,
        'sold_offline' => $off,
        'sold_online'  => $sol,
        'in_stock'     => max(0, $ts - $dmg - $off - $sol),
    ];
}

$action = trim($_POST['action'] ?? '');

// CSRF check for every mutating action. This file had none while the ten other
// handlers in this folder all did. increment_stock writes total_stock — the
// exact column checkout enforces against — so a forged request could put
// garments on sale that do not exist, or bury ones that do.
if ($action !== '') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
        exit;
    }
}

// ── Increment stock fields ─────────────────────────────────
// Adds the given quantities to the running totals.
// add_qty       → increments total_stock (Grand Total)
// damage_qty    → increments damage_stock
// offline_qty   → increments sold_offline
if ($action === 'increment_stock') {
    $productId  = (int)($_POST['product_id']  ?? 0);
    $addQty     = max(0, (int)($_POST['add_qty']     ?? 0));
    $damageQty  = max(0, (int)($_POST['damage_qty']  ?? 0));
    $offlineQty = max(0, (int)($_POST['offline_qty'] ?? 0));

    /* Stock that leaves without being sold or damaged — returned to a supplier,
       lost, or a miscount being corrected. There was no way to record any of
       these, so the only options were to call it damage (which makes the damage
       figure fiction) or an offline sale (which makes the revenue figure fiction,
       and that one is filed with the tax return). */
    $removeQty    = max(0, (int)($_POST['remove_qty'] ?? 0));
    $removeReason = trim((string)($_POST['remove_reason'] ?? ''));
    $removeNote   = trim((string)($_POST['remove_note'] ?? ''));
    if ($removeQty > 0 && !array_key_exists($removeReason, stockRemovalReasons())) {
        echo json_encode(['success' => false,
            'message' => 'Choose why the stock is being removed.']);
        exit;
    }

    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        exit;
    }
    /* $removeQty belongs in this test too. It was added as a new field and this
       guard was not updated, so a save carrying ONLY a removal — the ordinary way
       to record goods going back to a supplier — was rejected with "enter at least
       one quantity" while a quantity sat right there in the box. */
    if ($addQty === 0 && $damageQty === 0 && $offlineQty === 0 && $removeQty === 0) {
        echo json_encode(['success' => false, 'message' => 'Enter at least one quantity.']);
        exit;
    }

    // Check required columns exist
    if (!columnExists($pdo, 'products', 'total_stock')) {
        echo json_encode(['success' => false, 'message' => 'Please run setup_stock.php first to enable stock tracking.']);
        exit;
    }

    /* Where does stock actually live for THIS product?
       ────────────────────────────────────────────────────────────────────────
       productSellableStock() reads the size rows whenever a product has any, and
       ignores products.total_stock completely. This handler only ever touched
       total_stock — so on a product with sizes, "add 50" raised the big number on
       screen and made nothing buyable, while damage and offline sales (which are
       product-level counters and ARE subtracted) still reduced it. Stock could
       only ever go down. That is the bug being fixed here: additions now land
       where the shop actually sells from. */
    $variants   = productTrackedVariants($pdo, $productId);
    $hasSizes   = !empty($variants);
    $variantId  = (int)($_POST['variant_id'] ?? 0);
    $variantIds = array_map('intval', array_column($variants, 'id'));

    if ($hasSizes && ($addQty > 0 || $removeQty > 0) && !in_array($variantId, $variantIds, true)) {
        echo json_encode(['success' => false,
            'message' => 'This product is sold by size. Choose which size to add or remove.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $adminId = (int)($_SESSION['admin_id'] ?? 0) ?: null;

        // ── Adding and removing: the sellable figure ──────────────────────────
        if ($hasSizes) {
            $cur = 0;
            foreach ($variants as $v) { if ((int)$v['id'] === $variantId) { $cur = (int)$v['stock_qty']; } }

            if ($removeQty > $cur && $removeQty > 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false,
                    'message' => "That size only holds {$cur}. You cannot remove {$removeQty}."]);
                exit;
            }
            $delta = $addQty - $removeQty;
            if ($delta !== 0) {
                $pdo->prepare("UPDATE product_variants SET stock_qty = GREATEST(0, stock_qty + :d) WHERE id = :vid")
                    ->execute(['d' => $delta, 'vid' => $variantId]);
            }
            // total_stock stays a cumulative record of goods RECEIVED, so it only
            // ever rises — that is what makes it reconcilable against purchases.
            if ($addQty > 0 && columnExists($pdo, 'products', 'total_stock')) {
                $pdo->prepare("UPDATE products SET total_stock = total_stock + :q WHERE id = :id")
                    ->execute(['q' => $addQty, 'id' => $productId]);
            }
        } else {
            if ($removeQty > 0 && columnExists($pdo, 'products', 'total_stock')) {
                $pdo->prepare("UPDATE products SET total_stock = GREATEST(0, total_stock - :q) WHERE id = :id")
                    ->execute(['q' => $removeQty, 'id' => $productId]);
            }
            if ($addQty > 0 && columnExists($pdo, 'products', 'total_stock')) {
                $pdo->prepare("UPDATE products SET total_stock = total_stock + :q WHERE id = :id")
                    ->execute(['q' => $addQty, 'id' => $productId]);
            }
        }

        // ── Damage and offline sales stay product-level counters ──────────────
        // productSellableStock() subtracts both from the variant sum as well, so
        // they correctly reduce what can be sold either way.
        $parts = []; $params = ['id' => $productId];
        if ($damageQty > 0 && columnExists($pdo, 'products', 'damage_stock')) {
            $parts[] = '`damage_stock` = `damage_stock` + :damage_qty';
            $params['damage_qty'] = $damageQty;
        }
        if ($offlineQty > 0 && columnExists($pdo, 'products', 'sold_offline')) {
            $parts[] = '`sold_offline` = `sold_offline` + :offline_qty';
            $params['offline_qty'] = $offlineQty;
        }
        if ($parts) {
            $pdo->prepare("UPDATE products SET " . implode(', ', $parts) . " WHERE id = :id")->execute($params);
        }

        $pdo->commit();

        // ── History, after the movement is safely committed ───────────────────
        $vFor = $hasSizes ? $variantId : null;
        if ($addQty > 0)     { recordStockMovement($pdo, $productId, $vFor,  $addQty,     'received',     '', $adminId); }
        if ($removeQty > 0)  { recordStockMovement($pdo, $productId, $vFor, -$removeQty,  $removeReason,  $removeNote, $adminId); }
        if ($damageQty > 0)  { recordStockMovement($pdo, $productId, null,  -$damageQty,  'damaged',      '', $adminId); }
        if ($offlineQty > 0) { recordStockMovement($pdo, $productId, null,  -$offlineQty, 'sold_offline', '', $adminId); }

        /* Report the figure the SHOP sells on, not the product-level arithmetic.
           fetchStockRow() computes total − damage − offline − online, which is
           right only for products without sizes; for the rest it disagreed with
           the number the page had just displayed. */
        $row  = fetchStockRow($pdo, $productId);
        $prod = $pdo->prepare("SELECT * FROM products WHERE id = :id");
        $prod->execute(['id' => $productId]);
        $prodRow = $prod->fetch(PDO::FETCH_ASSOC) ?: [];
        if (function_exists('productSellableStock') && $prodRow) {
            $sellable = productSellableStock($pdo, $prodRow);
            $row['in_stock'] = ($sellable === PHP_INT_MAX) ? null : (int)$sellable;
        }
        echo json_encode(array_merge(['success' => true], $row));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
