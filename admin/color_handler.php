<?php
// ============================================================
//  Dievon – Admin: Product Colour-Variant Handler
//  Actions: list | add | update | delete | upload_thumbnail |
//           set_thumbnail | upload_gallery | delete_image
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';

header('Content-Type: application/json');

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

// CSRF check for every mutating action (list is read-only, safe to skip)
if ($action !== 'list') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
        exit;
    }
}

$uploadDir = __DIR__ . '/../uploads/products/';
$allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

function dievon_save_uploaded_image(array $file, string $uploadDir, array $allowedMime, string $prefix): ?string {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMime)) return null;
    if ($file['size'] > 8 * 1024 * 1024) return null;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        generateWebpCopy($uploadDir . $filename);
        return $filename;
    }
    return null;
}

// ── LIST all colours (+ gallery images) for a product ─────────
if ($action === 'list') {
    if ($productId <= 0) { echo json_encode(['success' => false]); exit; }
    $rows = $pdo->prepare("SELECT * FROM product_colors WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $rows->execute(['pid' => $productId]);
    $colors = $rows->fetchAll();
    foreach ($colors as &$c) {
        $imgStmt = $pdo->prepare("SELECT * FROM product_color_images WHERE color_id = :cid ORDER BY sort_order ASC, id ASC");
        $imgStmt->execute(['cid' => $c['id']]);
        $c['images'] = $imgStmt->fetchAll();
    }
    unset($c);
    echo json_encode(['success' => true, 'colors' => $colors]);
    exit;
}

// ── ADD colour ──────────────────────────────────────────────
if ($action === 'add') {
    $colorName = trim($_POST['color_name'] ?? '');
    $sku       = trim($_POST['sku'] ?? '');
    $priceOverride    = trim($_POST['price_override'] ?? '') !== '' ? (float)$_POST['price_override'] : null;
    $mrpPriceOverride = trim($_POST['mrp_price_override'] ?? '') !== '' ? (float)$_POST['mrp_price_override'] : null;

    if ($productId <= 0 || strlen($colorName) < 1) {
        echo json_encode(['success' => false, 'message' => 'Colour name is required.']);
        exit;
    }

    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM product_colors WHERE product_id = $productId")->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO product_colors (product_id, color_name, sku, price_override, mrp_price_override, sort_order) VALUES (:pid, :name, :sku, :price, :mrp, :sort_order)");
    $stmt->execute([
        'pid' => $productId, 'name' => $colorName, 'sku' => ($sku !== '' ? $sku : null),
        'price' => $priceOverride, 'mrp' => $mrpPriceOverride, 'sort_order' => $maxOrder + 1,
    ]);
    $id = $pdo->lastInsertId();

    // Optional thumbnail uploaded alongside colour creation
    $thumbFilename = null;
    if (!empty($_FILES['thumbnail']['name'])) {
        $thumbFilename = dievon_save_uploaded_image($_FILES['thumbnail'], $uploadDir, $allowedMime, slugify($colorName) . '-thumb');
        if ($thumbFilename) {
            $pdo->prepare("UPDATE product_colors SET thumbnail = :t WHERE id = :id")->execute(['t' => $thumbFilename, 'id' => $id]);
        }
    }

    echo json_encode(['success' => true, 'id' => $id, 'color_name' => $colorName, 'sku' => $sku, 'thumbnail' => $thumbFilename]);
    exit;
}

// ── UPDATE colour ───────────────────────────────────────────
if ($action === 'update') {
    $id        = (int)($_POST['id'] ?? 0);
    $colorName = trim($_POST['color_name'] ?? '');
    $sku       = trim($_POST['sku'] ?? '');
    $priceOverride    = trim($_POST['price_override'] ?? '') !== '' ? (float)$_POST['price_override'] : null;
    $mrpPriceOverride = trim($_POST['mrp_price_override'] ?? '') !== '' ? (float)$_POST['mrp_price_override'] : null;
    $isActive  = isset($_POST['is_active']) ? 1 : 0;

    if ($id <= 0 || strlen($colorName) < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }

    $pdo->prepare("UPDATE product_colors SET color_name=:name, sku=:sku, price_override=:price, mrp_price_override=:mrp, is_active=:active WHERE id=:id AND product_id=:pid")
        ->execute([
            'name' => $colorName, 'sku' => ($sku !== '' ? $sku : null), 'price' => $priceOverride,
            'mrp' => $mrpPriceOverride, 'active' => $isActive, 'id' => $id, 'pid' => $productId,
        ]);

    // Optional thumbnail replace
    if (!empty($_FILES['thumbnail']['name'])) {
        $thumbFilename = dievon_save_uploaded_image($_FILES['thumbnail'], $uploadDir, $allowedMime, slugify($colorName) . '-thumb');
        if ($thumbFilename) {
            $old = $pdo->prepare("SELECT thumbnail FROM product_colors WHERE id = :id");
            $old->execute(['id' => $id]);
            $oldThumb = $old->fetchColumn();
            if ($oldThumb && file_exists($uploadDir . $oldThumb)) { unlink($uploadDir . $oldThumb); }
            $pdo->prepare("UPDATE product_colors SET thumbnail = :t WHERE id = :id")->execute(['t' => $thumbFilename, 'id' => $id]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

// ── SET THUMBNAIL from an existing gallery image ─────────────
if ($action === 'set_thumbnail') {
    $id    = (int)($_POST['id'] ?? 0);
    $image = trim($_POST['image'] ?? '');
    if ($id <= 0 || $image === '') { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    // Only allow picking an image that actually belongs to this colour's own gallery
    $check = $pdo->prepare("SELECT id FROM product_color_images WHERE color_id = :cid AND image = :img");
    $check->execute(['cid' => $id, 'img' => $image]);
    if (!$check->fetch()) { echo json_encode(['success' => false, 'message' => 'Image not found in this colour gallery.']); exit; }

    $pdo->prepare("UPDATE product_colors SET thumbnail = :img WHERE id = :id AND product_id = :pid")
        ->execute(['img' => $image, 'id' => $id, 'pid' => $productId]);
    echo json_encode(['success' => true]);
    exit;
}

// ── UPLOAD gallery images for a colour ────────────────────────
if ($action === 'upload_gallery') {
    $colorId = (int)($_POST['color_id'] ?? 0);
    if ($colorId <= 0) { echo json_encode(['success' => false, 'message' => 'Missing colour.']); exit; }

    $uploaded = [];
    if (!empty($_FILES['gallery_images']['name'][0])) {
        $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM product_color_images WHERE color_id = $colorId")->fetchColumn();
        foreach ($_FILES['gallery_images']['name'] as $key => $origName) {
            $file = [
                'name' => $origName,
                'tmp_name' => $_FILES['gallery_images']['tmp_name'][$key],
                'error' => $_FILES['gallery_images']['error'][$key],
                'size' => $_FILES['gallery_images']['size'][$key],
            ];
            $filename = dievon_save_uploaded_image($file, $uploadDir, $allowedMime, 'color_gallery');
            if ($filename) {
                $maxOrder++;
                $pdo->prepare("INSERT INTO product_color_images (color_id, image, sort_order) VALUES (:cid, :img, :sort)")
                    ->execute(['cid' => $colorId, 'img' => $filename, 'sort' => $maxOrder]);
                $uploaded[] = ['id' => $pdo->lastInsertId(), 'image' => $filename];
            }
        }
    }
    echo json_encode(['success' => true, 'uploaded' => $uploaded]);
    exit;
}

// ── DELETE a gallery image ────────────────────────────────────
if ($action === 'delete_image') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false]); exit; }

    $stmt = $pdo->prepare("SELECT image, color_id FROM product_color_images WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row) {
        if (file_exists($uploadDir . $row['image'])) { unlink($uploadDir . $row['image']); }
        $pdo->prepare("DELETE FROM product_color_images WHERE id = :id")->execute(['id' => $id]);
        // If that image was the colour's thumbnail, clear it so it's not a broken reference
        $pdo->prepare("UPDATE product_colors SET thumbnail = NULL WHERE id = :cid AND thumbnail = :img")
            ->execute(['cid' => $row['color_id'], 'img' => $row['image']]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE colour (cascades sizes + gallery images) ───────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false]); exit; }

    // Remove gallery image files + rows
    $imgs = $pdo->prepare("SELECT image FROM product_color_images WHERE color_id = :cid");
    $imgs->execute(['cid' => $id]);
    foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $img) {
        if (file_exists($uploadDir . $img)) { unlink($uploadDir . $img); }
    }
    $pdo->prepare("DELETE FROM product_color_images WHERE color_id = :cid")->execute(['cid' => $id]);

    // Remove thumbnail file if it wasn't part of the gallery list above
    $thumbStmt = $pdo->prepare("SELECT thumbnail FROM product_colors WHERE id = :id");
    $thumbStmt->execute(['id' => $id]);
    $thumb = $thumbStmt->fetchColumn();
    if ($thumb && file_exists($uploadDir . $thumb)) { unlink($uploadDir . $thumb); }

    // Remove this colour's size/stock rows from product_variants
    $pdo->prepare("DELETE FROM product_variants WHERE color_id = :cid AND product_id = :pid")
        ->execute(['cid' => $id, 'pid' => $productId]);

    $pdo->prepare("DELETE FROM product_colors WHERE id = :id AND product_id = :pid")
        ->execute(['id' => $id, 'pid' => $productId]);

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
