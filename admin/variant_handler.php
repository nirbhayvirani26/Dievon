<?php
// ============================================================
//  Dievon – Admin: Variant Handler
//  Actions: list | add | update | delete
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('catalogue.manage', true);


header('Content-Type: application/json');

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

// CSRF check for every mutating action (list is read-only, safe to skip).
// This file had no check at all while the ten other handlers in this folder
// all had one — so add, update and DELETE were forgeable by any page the
// owner visited while signed in. Deleting a variant destroys its row, and
// add/update write the stock column checkout enforces against.
if ($action !== 'list') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
        exit;
    }
}

// Canonical size ladder — used only to validate an optional size_code (colour-scoped sizes)
$sizeLadder = require __DIR__ . '/../config/size_ladder.php';
$validSizeCodes = array_column($sizeLadder, 'code');

// ── LIST all variants for a product (optionally filtered by colour) ──
if ($action === 'list') {
    if ($productId <= 0) { echo json_encode(['success' => false]); exit; }
    $colorFilter = array_key_exists('color_id', $_POST) || array_key_exists('color_id', $_GET);
    if ($colorFilter) {
        $colorId = (int)($_POST['color_id'] ?? $_GET['color_id'] ?? 0);
        $rows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND color_id = :cid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['pid' => $productId, 'cid' => $colorId]);
    } else {
        $rows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['pid' => $productId]);
    }
    echo json_encode(['success' => true, 'variants' => $rows->fetchAll()]);
    exit;
}

// ── ADD variant ───────────────────────────────────────────────
if ($action === 'add') {
    $name  = trim($_POST['name']  ?? '');
    $price = (float)($_POST['price'] ?? 0);

    // Optional colour-scoped fields (NULL for legacy plain-size variants)
    $colorId  = trim($_POST['color_id'] ?? '') !== '' ? (int)$_POST['color_id'] : null;
    $sizeCode = trim($_POST['size_code'] ?? '');
    $sizeCode = in_array($sizeCode, $validSizeCodes, true) ? $sizeCode : null;
    /* An empty stock box means ZERO, not "unlimited".
       ────────────────────────────────────────────────────────────────────────
       This stored NULL when the field was left blank, and NULL means "this size
       is not tracked" — which productSellableStock() and productIsInStock() both
       read as making the WHOLE product sell without limit. So adding a size and
       not typing a number quietly removed the stock ceiling from a garment that
       showed a healthy figure on the stock screen. Two products in this shop had
       exactly that: 30 units on the screen, no limit in reality.

       An empty box is far more likely to mean "none yet" than "sell for ever",
       and none-yet is the answer that cannot oversell. A shop that genuinely does
       not want to count a product turns track_stock off on the product itself,
       which is the deliberate, visible way to say it. */
    $stockQty = trim($_POST['stock_qty'] ?? '') !== '' ? max(0, (int)$_POST['stock_qty']) : 0;

    if ($productId <= 0 || strlen($name) < 1) {
        echo json_encode(['success' => false, 'message' => 'Size name is required.']);
        exit;
    }

    // If size price is 0 or empty, default to main product price
    if ($price <= 0) {
        $pStmt = $pdo->prepare("SELECT price FROM products WHERE id = :pid");
        $pStmt->execute(['pid' => $productId]);
        $basePrice = $pStmt->fetchColumn();
        $price = (float)($basePrice ?: 0);
    }

    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM product_variants WHERE product_id = $productId")->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, name, price, sort_order, color_id, size_code, stock_qty) VALUES (:pid, :name, :price, :sort_order, :color_id, :size_code, :stock_qty)");
    $stmt->execute([
        'pid' => $productId, 'name' => $name, 'price' => $price, 'sort_order' => $maxOrder + 1,
        'color_id' => $colorId, 'size_code' => $sizeCode, 'stock_qty' => $stockQty,
    ]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'price' => number_format($price, 2), 'stock_qty' => $stockQty]);
    exit;
}

// ── UPDATE variant ───────────────────────────────────────────
if ($action === 'update') {
    $id    = (int)($_POST['id']    ?? 0);
    $name  = trim($_POST['name']   ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $avail = isset($_POST['available']) ? 1 : 0;
    /* Same rule as the add branch: a blank box is zero, not unlimited.
       $stockProvided stays as it is — a form that does not send the field at all
       leaves the stored value alone, which is what lets a caller edit a size's
       name or price without touching its stock. Only a field that IS sent and IS
       empty now resolves to 0. */
    $stockProvided = array_key_exists('stock_qty', $_POST);
    $stockQty = $stockProvided
        ? (trim((string)$_POST['stock_qty']) !== '' ? max(0, (int)$_POST['stock_qty']) : 0)
        : null;

    if ($id <= 0 || strlen($name) < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }

    // If size price is 0 or empty, default to main product price
    if ($price <= 0) {
        $pStmt = $pdo->prepare("SELECT price FROM products WHERE id = :pid");
        $pStmt->execute(['pid' => $productId]);
        $basePrice = $pStmt->fetchColumn();
        $price = (float)($basePrice ?: 0);
    }

    if ($stockProvided) {
        $pdo->prepare("UPDATE product_variants SET name=:name, price=:price, available=:available, stock_qty=:stock_qty WHERE id=:id AND product_id=:pid")
            ->execute(['name' => $name, 'price' => $price, 'available' => $avail, 'stock_qty' => $stockQty, 'id' => $id, 'pid' => $productId]);
    } else {
        $pdo->prepare("UPDATE product_variants SET name=:name, price=:price, available=:available WHERE id=:id AND product_id=:pid")
            ->execute(['name' => $name, 'price' => $price, 'available' => $avail, 'id' => $id, 'pid' => $productId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE variant ───────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false]); exit; }
    $pdo->prepare("DELETE FROM product_variants WHERE id=:id AND product_id=:pid")
        ->execute(['id' => $id, 'pid' => $productId]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
