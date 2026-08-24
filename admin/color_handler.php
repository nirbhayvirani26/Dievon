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
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('catalogue.manage', true);


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

/**
 * Save an uploaded colour photograph, or explain why it was not saved.
 *
 * $error is filled in on every rejection. It used to return a bare null for all
 * of them — wrong file type, too large, unreadable — so a colour photo could be
 * chosen, silently discarded, and the owner left looking at a colour with no
 * picture and no idea why. A refusal has to say which file and what was wrong
 * with it.
 */
function dievon_save_uploaded_image(array $file, string $uploadDir, array $allowedMime, string $prefix, ?string &$error = null): ?string {
    $error = null;
    $shown = (string)($file['name'] ?? '');
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $error = $shown !== '' ? '"' . $shown . '" did not upload.' : 'No file was received.';
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMime)) {
        $error = '"' . $shown . '" must be JPG, PNG, WebP or GIF.';
        return null;
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        $error = '"' . $shown . '" is larger than 8MB.';
        return null;
    }
    /* Colour photographs appear in the same 3:4 frames as the product's own.
       Skipped when the owner has already been shown what would be cropped and
       chosen to add it anyway — the confirmation in the product form sets this. */
    if (($_POST['allow_image_ratio'] ?? '') !== '1') {
        $ratioErr = productImageRatioError($file['tmp_name'], $shown);
        if ($ratioErr !== null) { $error = $ratioErr; return null; }
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        // Resize/re-compress before the WebP is made, so the delivery copy is
        // generated from the already-optimised original rather than the raw upload.
        optimizeUploadedImage($uploadDir . $filename);
        generateWebpCopy($uploadDir . $filename);
        return $filename;
    }
    return null;
}

/**
 * Reject a colour price that cannot be true. Returns an error message, or null.
 *
 * Neither override was checked at all, so anything could be stored — and was.
 * A real colour on this shop ended up at ₹77.00 with a "was" price of ₹54.00,
 * against a product priced ₹675.00: a price RISE presented as a discount, and a
 * colour selling for a ninth of its product. The storefront hides a strike-
 * through that is below the asking price, so nothing false reached a shopper,
 * but the money was still wrong on every sale of that colour.
 *
 * Both boxes are optional. Empty means "follow the product", which is what a
 * colour should normally do — the override is for a colourway that genuinely
 * costs a different amount, not somewhere to type a number by accident.
 */
function dievon_validate_color_prices(PDO $pdo, int $productId, ?float $price, ?float $mrp): ?string {
    if ($price !== null && $price <= 0) {
        return 'A colour price must be more than zero. Leave the box empty for the colour to use the product price.';
    }
    if ($mrp !== null && $mrp < 0) {
        return 'The compare-at price cannot be negative.';
    }

    $base = 0.0;
    try {
        $st = $pdo->prepare("SELECT price FROM products WHERE id = :pid");
        $st->execute(['pid' => $productId]);
        $base = (float)$st->fetchColumn();
    } catch (PDOException $e) {
        return null;   // Cannot check without the product price; do not block the save.
    }

    // What this colour will actually sell for once saved.
    $effective = $price !== null ? $price : $base;

    if ($mrp !== null && $mrp > 0 && $effective > 0 && $mrp < $effective) {
        return 'The compare-at price (' . formatPrice($mrp) . ') is BELOW the selling price ('
             . formatPrice($effective) . '). A "was" price has to be the higher of the two, or it is a price rise '
             . 'shown as a discount. Raise it, or clear the box.';
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
/* The colour must be one from the Color tab.
   ────────────────────────────────────────────────────────────────────────────
   The product form now offers a constrained list rather than a free-text box,
   but a <select> is only a suggestion to anything that is not a browser — this
   endpoint takes POSTs, so the rule has to be enforced where the write happens
   or the same drift walks straight back in.

   Returns the master's OWN spelling, so 'navy' is stored as 'Navy' and the shop
   filter — which matches product_colors.color_name as a string — cannot end up
   with the same colour under three casings. */
function dievon_master_color_or_error(PDO $pdo, string $name) {
    $canonical = dievonCanonicalColor($pdo, $name);
    if ($canonical !== null) { return ['ok' => true, 'name' => $canonical]; }
    if (!dievonMasterColors($pdo)) { return ['ok' => true, 'name' => trim($name)]; }  // master not set up yet
    return ['ok' => false, 'message' =>
        '"' . $name . '" is not in the Color tab. Add it there first (Attributes → Colours), '
        . 'then choose it here — that is what keeps the shop\'s colour filter working.'];
}

if ($action === 'add') {
    $colorName = trim($_POST['color_name'] ?? '');
    $sku       = trim($_POST['sku'] ?? '');
    $priceOverride    = trim($_POST['price_override'] ?? '') !== '' ? (float)$_POST['price_override'] : null;
    $mrpPriceOverride = trim($_POST['mrp_price_override'] ?? '') !== '' ? (float)$_POST['mrp_price_override'] : null;

    if ($productId <= 0 || strlen($colorName) < 1) {
        echo json_encode(['success' => false, 'message' => 'Colour name is required.']);
        exit;
    }

    $master = dievon_master_color_or_error($pdo, $colorName);
    if (!$master['ok']) { echo json_encode(['success' => false, 'message' => $master['message']]); exit; }
    $colorName = $master['name'];

    if ($priceError = dievon_validate_color_prices($pdo, $productId, $priceOverride, $mrpPriceOverride)) {
        echo json_encode(['success' => false, 'message' => $priceError]);
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
        $thumbFilename = dievon_save_uploaded_image($_FILES['thumbnail'], $uploadDir, $allowedMime, slugify($colorName) . '-thumb', $thumbErr);
        if ($thumbFilename) {
            $pdo->prepare("UPDATE product_colors SET thumbnail = :t WHERE id = :id")->execute(['t' => $thumbFilename, 'id' => $id]);
        }
    }

    syncProductCoverImage($pdo, $productId);
    echo json_encode(['success' => true, 'id' => $id, 'color_name' => $colorName, 'sku' => $sku,
                      'thumbnail' => $thumbFilename, 'image_error' => $thumbErr ?? null]);
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

    /* Only when the name is actually being CHANGED. A colour saved before this
       rule existed may hold a value that is no longer selectable, and editing
       that colour's price or SKU must not be refused because of its name — that
       would strand the row with no way to fix it from the form. */
    $current = $pdo->prepare("SELECT color_name FROM product_colors WHERE id = :id AND product_id = :pid");
    $current->execute(['id' => $id, 'pid' => $productId]);
    $existingName = (string)($current->fetchColumn() ?: '');

    if (strcasecmp(trim($existingName), $colorName) !== 0) {
        $master = dievon_master_color_or_error($pdo, $colorName);
        if (!$master['ok']) { echo json_encode(['success' => false, 'message' => $master['message']]); exit; }
        $colorName = $master['name'];
    }

    if ($priceError = dievon_validate_color_prices($pdo, $productId, $priceOverride, $mrpPriceOverride)) {
        echo json_encode(['success' => false, 'message' => $priceError]);
        exit;
    }

    $pdo->prepare("UPDATE product_colors SET color_name=:name, sku=:sku, price_override=:price, mrp_price_override=:mrp, is_active=:active WHERE id=:id AND product_id=:pid")
        ->execute([
            'name' => $colorName, 'sku' => ($sku !== '' ? $sku : null), 'price' => $priceOverride,
            'mrp' => $mrpPriceOverride, 'active' => $isActive, 'id' => $id, 'pid' => $productId,
        ]);

    // Optional thumbnail replace.
    //
    // deleteUploadedFile() rather than unlink(): every upload here also generates
    // a .webp delivery twin, and a plain unlink removed only the original. That
    // left orphaned .webp files behind on every colour edit and every colour
    // deletion — uploads/products still holds a set from colours deleted earlier.
    if (!empty($_FILES['thumbnail']['name'])) {
        $thumbFilename = dievon_save_uploaded_image($_FILES['thumbnail'], $uploadDir, $allowedMime, slugify($colorName) . '-thumb');
        if ($thumbFilename) {
            $old = $pdo->prepare("SELECT thumbnail FROM product_colors WHERE id = :id");
            $old->execute(['id' => $id]);
            $oldThumb = $old->fetchColumn();
            if ($oldThumb) { deleteUploadedFile($oldThumb, $uploadDir); }
            $pdo->prepare("UPDATE product_colors SET thumbnail = :t WHERE id = :id")->execute(['t' => $thumbFilename, 'id' => $id]);
        }
    }

    // Deactivating or reordering a colour can change which one the product page
    // opens on, and the cover has to follow it.
    syncProductCoverImage($pdo, $productId);
    echo json_encode(['success' => true]);
    exit;
}

// ── ADOPT the product's own photographs into this colour ──────
//
// Solves the trap that made adding a second colourway cost double work.
//
// A shopkeeper photographs the kurti in Olive Silk and uploads those shots as the
// PRODUCT's photos — which is the obvious way to add a product. Later a second
// colourway arrives, so they create one colour, Red. The moment any colour exists
// the page shows the COLOUR's photographs, so Olive's four shots vanish and only
// Red is left. Getting Olive back meant creating a second colour and uploading the
// same four files again, by hand, for photos already on the server.
//
// This attaches them instead. The files are shared, not duplicated on disk: the
// rows point at the same names, and deleting a colour uses
// deleteUploadedFileIfUnused(), which refuses to unlink a file another row still
// references. So adopting is safe and reversible — remove the colour and the
// product's own gallery is untouched.
if ($action === 'adopt_product_images') {
    $colorId = (int)($_POST['color_id'] ?? 0);
    if ($colorId <= 0 || $productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing colour.']);
        exit;
    }

    // The colour must belong to the product named in the request — never trust a
    // colour id on its own to decide whose photographs get copied.
    $own = $pdo->prepare("SELECT id, thumbnail FROM product_colors WHERE id = :cid AND product_id = :pid");
    $own->execute(['cid' => $colorId, 'pid' => $productId]);
    $colorRow = $own->fetch();
    if (!$colorRow) {
        echo json_encode(['success' => false, 'message' => 'That colour does not belong to this product.']);
        exit;
    }

    // Main image first, then the additional gallery in its own order — the same
    // sequence the product page used before the colour existed.
    $sourceFiles = [];
    $main = $pdo->prepare("SELECT image FROM products WHERE id = :pid");
    $main->execute(['pid' => $productId]);
    $mainImage = trim((string)$main->fetchColumn());
    if ($mainImage !== '') { $sourceFiles[] = $mainImage; }

    $extra = $pdo->prepare("SELECT image FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $extra->execute(['pid' => $productId]);
    foreach ($extra->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $f = trim((string)$f);
        if ($f !== '' && !in_array($f, $sourceFiles, true)) { $sourceFiles[] = $f; }
    }

    if (!$sourceFiles) {
        echo json_encode(['success' => false, 'message' => 'This product has no photos of its own to copy.']);
        exit;
    }

    // Whatever the colour already holds is left alone and never duplicated.
    $have = $pdo->prepare("SELECT image FROM product_color_images WHERE color_id = :cid");
    $have->execute(['cid' => $colorId]);
    $existing = array_map('strval', $have->fetchAll(PDO::FETCH_COLUMN));

    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM product_color_images WHERE color_id = $colorId")->fetchColumn();

    $ins = $pdo->prepare("INSERT INTO product_color_images (color_id, image, sort_order) VALUES (:cid, :img, :sort)");
    $added = [];
    $uploadRoot = __DIR__ . '/../uploads/products/';
    foreach ($sourceFiles as $file) {
        if (in_array($file, $existing, true)) { continue; }
        // A row naming a file that is not on this server would only render as a
        // broken tile in the colour gallery.
        if (!is_file($uploadRoot . $file)) { continue; }
        $maxOrder++;
        $ins->execute(['cid' => $colorId, 'img' => $file, 'sort' => $maxOrder]);
        $added[] = ['id' => $pdo->lastInsertId(), 'image' => $file];
    }

    // Give the colour a swatch picture if it had none, so the ring is not left
    // showing a fallback when the colour now has photographs of its own.
    if (empty($colorRow['thumbnail']) && $added) {
        $pdo->prepare("UPDATE product_colors SET thumbnail = :t WHERE id = :cid")
            ->execute(['t' => $added[0]['image'], 'cid' => $colorId]);
    }

    syncProductCoverImage($pdo, $productId);

    echo json_encode([
        'success'  => true,
        'added'    => $added,
        'skipped'  => count($sourceFiles) - count($added),
        'message'  => $added
            ? count($added) . ' photo' . (count($added) === 1 ? '' : 's') . ' attached to this colour.'
            : 'This colour already has all of the product\'s photos.',
    ]);
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
    $rejected = [];   // named, so a refused picture is never silently dropped
    if (!empty($_FILES['gallery_images']['name'][0])) {
        $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM product_color_images WHERE color_id = $colorId")->fetchColumn();
        foreach ($_FILES['gallery_images']['name'] as $key => $origName) {
            $file = [
                'name' => $origName,
                'tmp_name' => $_FILES['gallery_images']['tmp_name'][$key],
                'error' => $_FILES['gallery_images']['error'][$key],
                'size' => $_FILES['gallery_images']['size'][$key],
            ];
            $filename = dievon_save_uploaded_image($file, $uploadDir, $allowedMime, 'color_gallery', $imgErr);
            if (!$filename && $imgErr) { $rejected[] = $imgErr; }
            if ($filename) {
                $maxOrder++;
                $pdo->prepare("INSERT INTO product_color_images (color_id, image, sort_order) VALUES (:cid, :img, :sort)")
                    ->execute(['cid' => $colorId, 'img' => $filename, 'sort' => $maxOrder]);
                $uploaded[] = ['id' => $pdo->lastInsertId(), 'image' => $filename];
            }
        }
    }
    // The very first photograph on the default colour becomes the shop cover.
    syncProductCoverImage($pdo, $productId ?: (int)$pdo->query("SELECT product_id FROM product_colors WHERE id = $colorId")->fetchColumn());
    echo json_encode(['success' => true, 'uploaded' => $uploaded, 'rejected' => $rejected]);
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
        $pdo->prepare("DELETE FROM product_color_images WHERE id = :id")->execute(['id' => $id]);
        // If that image was the colour's thumbnail, clear it so it's not a broken reference
        $pdo->prepare("UPDATE product_colors SET thumbnail = NULL WHERE id = :cid AND thumbnail = :img")
            ->execute(['cid' => $row['color_id'], 'img' => $row['image']]);

        // Rows first, file second, and only once nothing else names it.
        //
        // A filename here can be shared: set_thumbnail points a colour at one of
        // its own gallery pictures, and a cover archived by syncProductCoverImage
        // also sits in product_images. An unconditional unlink deleted a file that
        // another row was still displaying. The row has to go first or the check
        // just finds this very row and keeps the file forever.
        deleteUploadedFileIfUnused($pdo, $row['image'], $uploadDir);

        // Deleting the opening photograph promotes the next one — including on
        // the shop card, which was showing a file that no longer exists.
        $ownerId = $productId ?: (int)$pdo->query("SELECT product_id FROM product_colors WHERE id = " . (int)$row['color_id'])->fetchColumn();
        syncProductCoverImage($pdo, $ownerId);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE colour (cascades sizes + gallery images) ───────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false]); exit; }

    // Filenames are noted now, but nothing is unlinked until every row that
    // referenced them is gone — deleteUploadedFileIfUnused() below asks the
    // database whether anything still needs each file, and with the rows still
    // present the answer would always be yes.
    $imgs = $pdo->prepare("SELECT image FROM product_color_images WHERE color_id = :cid");
    $imgs->execute(['cid' => $id]);
    $imageFiles = $imgs->fetchAll(PDO::FETCH_COLUMN);

    $thumbStmt = $pdo->prepare("SELECT thumbnail FROM product_colors WHERE id = :id");
    $thumbStmt->execute(['id' => $id]);
    $thumb = (string)$thumbStmt->fetchColumn();

    $pdo->prepare("DELETE FROM product_color_images WHERE color_id = :cid")->execute(['cid' => $id]);

    // Remove this colour's size/stock rows from product_variants
    $pdo->prepare("DELETE FROM product_variants WHERE color_id = :cid AND product_id = :pid")
        ->execute(['cid' => $id, 'pid' => $productId]);

    $pdo->prepare("DELETE FROM product_colors WHERE id = :id AND product_id = :pid")
        ->execute(['id' => $id, 'pid' => $productId]);

    // Now the files. A picture still named by another colour, by the product's
    // own gallery or by the cover is kept — losing it would cost the shop a
    // photograph it cannot get back, which is worse than leaving one behind.
    foreach ($imageFiles as $img) { deleteUploadedFileIfUnused($pdo, $img, $uploadDir); }
    if ($thumb !== '') { deleteUploadedFileIfUnused($pdo, $thumb, $uploadDir); }

    syncProductCoverImage($pdo, $productId);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
