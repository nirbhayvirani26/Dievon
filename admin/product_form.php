<?php
// ============================================================
//  Dievon – Admin: Add / Edit Product Form
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}

require_once '../config/config.php';
require_once '../config/db.php';

$activeTab = 'products';
$hideHeaderTitle = true;

$isEdit  = isset($_GET['id']) || (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0);
$product = null;
$errors  = [];

// ── Load existing product for edit ───────────────────────
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute(['id' => (int)$_GET['id']]);
    $product = $stmt->fetch();
    if (!$product) { header('Location: products.php'); exit; }
}

// ── Compute current in-stock for display ─────────────────
$displayInStock = null;
if ($product && !empty($product['track_stock'])) {
    $ts  = (int)($product['total_stock']  ?? $product['stock_qty'] ?? 0);
    $dmg = (int)($product['damage_stock'] ?? 0);
    $off = (int)($product['sold_offline'] ?? 0);
    $sol = (int)($product['sold_online']  ?? 0);
    $displayInStock = max(0, $ts - $dmg - $off - $sol);
}

// ── Load categories from DB ──────────────────────────────
$categories = [];
try {
    $cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
    foreach ($cats as $c) { $categories[] = $c['name']; }
} catch (PDOException $e) {
    $categories = [];
}
$badges = ['', 'New', 'Hot', 'Best Seller'];

// ── Load existing variants and images for edit ──────────────────────
$existingVariants = [];
$additionalImages = [];
$existingColors   = [];
$sizeLadder       = require __DIR__ . '/../config/size_ladder.php';
if (isset($_GET['id']) || (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0)) {
    $editPid = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
    if ($editPid > 0) {
        try {
            // Plain (colour-less) size variants — legacy behaviour, unchanged
            $vRows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND color_id IS NULL ORDER BY sort_order ASC, id ASC");
            $vRows->execute(['pid' => $editPid]);
            $existingVariants = $vRows->fetchAll();

            $imgRows = $pdo->prepare("SELECT * FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $imgRows->execute(['pid' => $editPid]);
            $additionalImages = $imgRows->fetchAll();

            // Colour variants, each with its own gallery images + size/stock rows
            $cRows = $pdo->prepare("SELECT * FROM product_colors WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $cRows->execute(['pid' => $editPid]);
            $existingColors = $cRows->fetchAll();
            foreach ($existingColors as &$col) {
                $ciStmt = $pdo->prepare("SELECT * FROM product_color_images WHERE color_id = :cid ORDER BY sort_order ASC, id ASC");
                $ciStmt->execute(['cid' => $col['id']]);
                $col['images'] = $ciStmt->fetchAll();

                $cvStmt = $pdo->prepare("SELECT * FROM product_variants WHERE color_id = :cid ORDER BY sort_order ASC, id ASC");
                $cvStmt->execute(['cid' => $col['id']]);
                $col['sizes'] = $cvStmt->fetchAll();
            }
            unset($col);

            // General (product-level) specifications
            $gsStmt = $pdo->prepare("SELECT * FROM product_specifications WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $gsStmt->execute(['pid' => $editPid]);
            $existingGeneralSpecs = $gsStmt->fetchAll();

            // Components, each with its own nested specifications
            $compStmt = $pdo->prepare("SELECT * FROM product_components WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $compStmt->execute(['pid' => $editPid]);
            $existingComponents = $compStmt->fetchAll();
            foreach ($existingComponents as &$comp) {
                $csStmt = $pdo->prepare("SELECT * FROM product_component_specifications WHERE component_id = :cid ORDER BY sort_order ASC, id ASC");
                $csStmt->execute(['cid' => $comp['id']]);
                $comp['specs'] = $csStmt->fetchAll();
            }
            unset($comp);
        } catch (PDOException $e) { }
    }
}

$specSuggestions      = require __DIR__ . '/../config/spec_suggestions.php';
$existingGeneralSpecs = $existingGeneralSpecs ?? [];
$existingComponents   = $existingComponents ?? [];

// ── Handle form submission ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId   = (int)($_POST['product_id'] ?? 0);
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = (float)($_POST['price']    ?? 0);
    $category    = trim($_POST['category']    ?? '');
    $emoji       = trim($_POST['emoji']       ?? '✨');
    $badge       = trim($_POST['badge']       ?? '');
    $available    = isset($_POST['available'])    ? 1 : 0;
    $nuts_allergy = isset($_POST['nuts_allergy']) ? 1 : 0;
    $track_stock = isset($_POST['track_stock']) ? 1 : 0;
    $stock_qty   = max(0, (int)($_POST['stock_qty'] ?? 0));
    
    // Dynamic specifications
    $atelier_code = trim($_POST['atelier_code'] ?? '');
    $color_way    = trim($_POST['color_way']    ?? '');
    $sourcing     = trim($_POST['sourcing']     ?? '');
    $composition  = trim($_POST['composition']  ?? '');

    // Advanced features
    $brand        = trim($_POST['brand'] ?? '');
    $color        = trim($_POST['color'] ?? '');
    $fabric       = trim($_POST['fabric'] ?? '');
    $sleeve       = trim($_POST['sleeve'] ?? '');
    $neck         = trim($_POST['neck'] ?? '');
    $pattern      = trim($_POST['pattern'] ?? '');
    $occasion     = trim($_POST['occasion'] ?? '');
    $discount_percentage = (int)($_POST['discount_percentage'] ?? 0);
    $video_url    = trim($_POST['video_url'] ?? '');
    $wash_care    = trim($_POST['wash_care'] ?? '');
    $shipping_info = trim($_POST['shipping_info'] ?? '');
    $returns_info = trim($_POST['returns_info'] ?? '');

    // SKU, Barcode, Weight, Dimensions, Tags & SEO
    $sku          = trim($_POST['sku'] ?? '');
    $barcode      = trim($_POST['barcode'] ?? '');
    $weight       = trim($_POST['weight'] ?? '');
    $dimensions   = trim($_POST['dimensions'] ?? '');
    $tags         = trim($_POST['tags'] ?? '');
    $seo_url      = trim($_POST['seo_url'] ?? '');
    $meta_title   = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $related_ids  = is_array($_POST['related_ids'] ?? null) ? implode(',', $_POST['related_ids']) : trim($_POST['related_ids'] ?? '');

    // Keep existing image unless a new one is uploaded
    // Read from hidden field so we don't lose it when $product is null on POST
    $imageName = trim($_POST['existing_image'] ?? ($product['image'] ?? ''));

    // Handle image upload
    if (!empty($_FILES['product_image']['name'])) {
        $file    = $_FILES['product_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            $errors[] = 'Image must be JPG, PNG, WebP, or GIF.';
        } elseif ($file['size'] > 8 * 1024 * 1024) {
            $errors[] = 'Image file too large (max 8MB).';
        } else {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = slugify($name) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destDir  = __DIR__ . '/../uploads/products/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }

            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                // Delete old image if replacing
                if (!empty($imageName) && file_exists($destDir . $imageName)) {
                    unlink($destDir . $imageName);
                    $oldWebp = preg_replace('/\.[^.]+$/', '.webp', $destDir . $imageName);
                    if (is_file($oldWebp)) { unlink($oldWebp); }
                }
                generateWebpCopy($destDir . $filename);
                $imageName = $filename;
            } else {
                $errors[] = 'Failed to save image file.';
            }
        }
    }

    // Handle video file upload (used if no image available)
    if (!empty($_FILES['product_video']['name'])) {
        $vFile    = $_FILES['product_video'];
        $allowedV = ['video/mp4', 'video/webm', 'video/quicktime'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $vMime    = finfo_file($finfo, $vFile['tmp_name']);
        finfo_close($finfo);

        if (!in_array($vMime, $allowedV)) {
            $errors[] = 'Video must be MP4, WebM, or MOV format.';
        } elseif ($vFile['size'] > 50 * 1024 * 1024) {
            $errors[] = 'Video file too large (max 50MB).';
        } else {
            $vExt      = strtolower(pathinfo($vFile['name'], PATHINFO_EXTENSION));
            $vFilename = slugify($name) . '-video_' . bin2hex(random_bytes(4)) . '.' . $vExt;
            $destDir   = __DIR__ . '/../uploads/products/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }

            if (move_uploaded_file($vFile['tmp_name'], $destDir . $vFilename)) {
                $video_url = $vFilename;
            } else {
                $errors[] = 'Failed to save video file.';
            }
        }
    }

    // Handle multiple additional images
    $uploadedAdditionalImages = [];
    if (!empty($_FILES['additional_images']['name'][0])) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        foreach ($_FILES['additional_images']['name'] as $key => $filename_orig) {
            if ($_FILES['additional_images']['error'][$key] === UPLOAD_ERR_OK) {
                $tmp = $_FILES['additional_images']['tmp_name'][$key];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp);
                finfo_close($finfo);

                if (in_array($mime, $allowed) && $_FILES['additional_images']['size'][$key] <= 8 * 1024 * 1024) {
                    $ext      = strtolower(pathinfo($filename_orig, PATHINFO_EXTENSION));
                    $filename = slugify($name) . '-' . ($key + 1) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $destDir  = __DIR__ . '/../uploads/products/';
                    
                    if (move_uploaded_file($tmp, $destDir . $filename)) {
                        generateWebpCopy($destDir . $filename);
                        $uploadedAdditionalImages[] = $filename;
                    }
                }
            }
        }
    }

    // Validate
    if (strlen($name) < 2)        $errors[] = 'Product name is required.';
    if (strlen($description) < 5) $errors[] = 'Description is required.';
    if ($price <= 0)               $errors[] = 'Price must be greater than 0.';
    if (empty($category))         $errors[] = 'Please select a category.';

    $cost_price = (float)($_POST['cost_price'] ?? 0);
    $mrp_price  = (float)($_POST['mrp_price']  ?? 0);
    $hsn_code   = trim($_POST['hsn_code']   ?? '');
    $gst_rate   = (float)($_POST['gst_rate']   ?? 0);

    if (empty($errors)) {
        try {
            if ($productId > 0) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE products SET name=:name, description=:description, price=:price,
                    category=:category, emoji=:emoji, image=:image, badge=:badge, available=:available, nuts_allergy=:nuts_allergy,
                    track_stock=:track_stock, stock_qty=:stock_qty, atelier_code=:atelier_code, color_way=:color_way, sourcing=:sourcing, composition=:composition,
                    brand=:brand, color=:color, fabric=:fabric, sleeve=:sleeve, neck=:neck, pattern=:pattern, occasion=:occasion, discount_percentage=:discount_percentage, video_url=:video_url, wash_care=:wash_care, shipping_info=:shipping_info, returns_info=:returns_info,
                    sku=:sku, barcode=:barcode, weight=:weight, dimensions=:dimensions, tags=:tags, seo_url=:seo_url, meta_title=:meta_title, meta_description=:meta_description, related_ids=:related_ids,
                    cost_price=:cost_price, mrp_price=:mrp_price, hsn_code=:hsn_code, gst_rate=:gst_rate
                    WHERE id=:id");
                $stmt->execute(compact('name','description','price','category','emoji','badge','available','atelier_code','color_way','sourcing','composition','brand','color','fabric','sleeve','neck','pattern','occasion','discount_percentage','video_url','wash_care','shipping_info','returns_info','sku','barcode','weight','dimensions','tags','seo_url','meta_title','meta_description','related_ids','cost_price','mrp_price','hsn_code','gst_rate') + ['image' => $imageName, 'nuts_allergy' => $nuts_allergy, 'track_stock' => $track_stock, 'stock_qty' => $stock_qty, 'id' => $productId]);
                
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO products (name, description, price, category, emoji, image, badge, available, nuts_allergy, track_stock, stock_qty, atelier_code, color_way, sourcing, composition, brand, color, fabric, sleeve, neck, pattern, occasion, discount_percentage, video_url, wash_care, shipping_info, returns_info, sku, barcode, weight, dimensions, tags, seo_url, meta_title, meta_description, related_ids, cost_price, mrp_price, hsn_code, gst_rate)
                    VALUES (:name, :description, :price, :category, :emoji, :image, :badge, :available, :nuts_allergy, :track_stock, :stock_qty, :atelier_code, :color_way, :sourcing, :composition, :brand, :color, :fabric, :sleeve, :neck, :pattern, :occasion, :discount_percentage, :video_url, :wash_care, :shipping_info, :returns_info, :sku, :barcode, :weight, :dimensions, :tags, :seo_url, :meta_title, :meta_description, :related_ids, :cost_price, :mrp_price, :hsn_code, :gst_rate)");
                $stmt->execute(compact('name','description','price','category','emoji','badge','available','atelier_code','color_way','sourcing','composition','brand','color','fabric','sleeve','neck','pattern','occasion','discount_percentage','video_url','wash_care','shipping_info','returns_info','sku','barcode','weight','dimensions','tags','seo_url','meta_title','meta_description','related_ids','cost_price','mrp_price','hsn_code','gst_rate') + ['image' => $imageName, 'nuts_allergy' => $nuts_allergy, 'track_stock' => $track_stock, 'stock_qty' => $stock_qty]);
                $productId = $pdo->lastInsertId();
            }

            // Insert additional images
            foreach ($uploadedAdditionalImages as $addlImg) {
                $stmtImg = $pdo->prepare("INSERT INTO product_images (product_id, image) VALUES (?, ?)");
                $stmtImg->execute([$productId, $addlImg]);
            }

            header('Location: products.php?' . ($isEdit ? 'product_updated=1' : 'product_added=1')); exit;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    // Re-populate if errors
    $product = [
        'id' => $productId, 'name' => $name, 'description' => $description,
        'price' => $price, 'category' => $category, 'emoji' => $emoji,
        'image' => $imageName, 'badge' => $badge, 'available' => $available,
        'nuts_allergy' => $nuts_allergy,
        'track_stock' => $track_stock,
        'stock_qty'   => 0, // managed via Stock tab
        'atelier_code' => $atelier_code, 'color_way' => $color_way,
        'sourcing' => $sourcing, 'composition' => $composition
    ];
    $isEdit = $productId > 0;
}

$activeTab = 'products';
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header admin-page-header-flex">
    <div>
        <h1 class="admin-page-title"><?= $isEdit ? '✏️ Edit Product' : '➕ Add New Product' ?></h1>
        <p class="admin-page-subtitle"><?= $isEdit ? 'Update product details, pricing, variants & photos' : 'Add a new garment or luxury item to your catalog' ?></p>
    </div>
    <a href="products.php" class="btn-secondary admin-header-btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Products
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger admin-alert-mb">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div><?php foreach ($errors as $e) echo '<div>'. htmlspecialchars($e) .'</div>'; ?></div>
</div>
<?php endif; ?>


        <form action="product_form.php" method="POST" enctype="multipart/form-data">
            <?php if ($isEdit): ?>
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="existing_image" value="<?= htmlspecialchars($product['image'] ?? '') ?>">
            <?php endif; ?>

            <div class="product-form-grid">

                <!-- ── Form Fields ───────────────────────── -->
                <div>
                    <div class="glass-panel form-section">
                        <h3><i class="fa-solid fa-circle-info"></i> Basic Info</h3>

                        <div class="form-group">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="name" class="form-control"
                                placeholder="e.g. Aurelia Silk Kurti"
                                value="<?= htmlspecialchars($product['name'] ?? '') ?>" required
                                oninput="updatePreviewName(this.value)">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" rows="3"
                                placeholder="Describe the fabric, craftsmanship, and fit…" required><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Selling Price (₹) *</label>
                                <input type="number" name="price" class="form-control"
                                    step="0.01" min="0.01" placeholder="e.g. 1299.00"
                                    value="<?= htmlspecialchars($product['price'] ?? '') ?>" required
                                    oninput="updatePreviewPrice(this.value)">
                            </div>
                            <div class="form-group">
                                <label class="form-label">MRP / Strikethrough (₹)</label>
                                <input type="number" name="mrp_price" class="form-control"
                                    step="0.01" min="0" placeholder="e.g. 1899.00"
                                    value="<?= htmlspecialchars($product['mrp_price'] ?? '') ?>">
                                <small class="text-muted">Set higher than Selling Price to put this product on Sale — shows a strikethrough price and "% OFF" badge across the site.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Cost Price (₹) <small class="text-muted">(Admin Only)</small></label>
                                <input type="number" name="cost_price" class="form-control"
                                    step="0.01" min="0" placeholder="e.g. 450.00"
                                    value="<?= htmlspecialchars($product['cost_price'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">HSN / Tax Code</label>
                                <input type="text" name="hsn_code" class="form-control"
                                    placeholder="e.g. 6204.22"
                                    value="<?= htmlspecialchars($product['hsn_code'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">GST / VAT Rate (%)</label>
                                <input type="number" name="gst_rate" class="form-control"
                                    step="0.1" min="0" placeholder="e.g. 12.00"
                                    value="<?= htmlspecialchars($product['gst_rate'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category" class="form-control" required>
                                    <option value="">— Select —</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat ?>" <?= ($product['category'] ?? '') === $cat ? 'selected' : '' ?>>
                                        <?= $cat ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Badge</label>
                                <select name="badge" class="form-control">
                                    <?php foreach ($badges as $b): ?>
                                    <option value="<?= $b ?>" <?= ($product['badge'] ?? '') === $b ? 'selected' : '' ?>>
                                        <?= $b ?: '— None —' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group admin-form-checkbox-align">
                                <label class="admin-checkbox-label">
                                    <input type="checkbox" name="available" value="1" class="admin-checkbox-input"
                                        <?= ($product['available'] ?? 1) ? 'checked' : '' ?>>
                                    Available in shop
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dynamic Fashion Specifications -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-gem"></i> Fashion Specifications</h3>
                        <p class="admin-section-desc">These details appear on the product page.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Atelier Code</label>
                                <input type="text" name="atelier_code" class="form-control" placeholder="e.g. MD-0014" value="<?= htmlspecialchars($product['atelier_code'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Color Way</label>
                                <input type="text" name="color_way" class="form-control" placeholder="e.g. Mint Green" value="<?= htmlspecialchars($product['color_way'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Sourcing / Origin</label>
                                <input type="text" name="sourcing" class="form-control" placeholder="e.g. Tuscan Ateliers" value="<?= htmlspecialchars($product['sourcing'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Composition</label>
                                <input type="text" name="composition" class="form-control" placeholder="e.g. 100% Premium Silk" value="<?= htmlspecialchars($product['composition'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Filtering & Details -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-filter"></i> Advanced Filters & Details</h3>
                        <p class="admin-section-desc">These attributes power the sidebar filters and product page tabs.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Brand</label>
                                <input type="text" name="brand" class="form-control" placeholder="e.g. Dievon" value="<?= htmlspecialchars($product['brand'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Color</label>
                                <input type="text" name="color" class="form-control" placeholder="e.g. Red" value="<?= htmlspecialchars($product['color'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Fabric</label>
                                <input type="text" name="fabric" class="form-control" placeholder="e.g. Cotton" value="<?= htmlspecialchars($product['fabric'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Sleeve</label>
                                <input type="text" name="sleeve" class="form-control" placeholder="e.g. Full Sleeve" value="<?= htmlspecialchars($product['sleeve'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Neck</label>
                                <input type="text" name="neck" class="form-control" placeholder="e.g. Round Neck" value="<?= htmlspecialchars($product['neck'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Pattern</label>
                                <input type="text" name="pattern" class="form-control" placeholder="e.g. Floral" value="<?= htmlspecialchars($product['pattern'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Occasion</label>
                                <input type="text" name="occasion" class="form-control" placeholder="e.g. Casual" value="<?= htmlspecialchars($product['occasion'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Discount (%)</label>
                                <input type="number" name="discount_percentage" class="form-control" min="0" max="100" placeholder="e.g. 15" value="<?= htmlspecialchars($product['discount_percentage'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="admin-attribute-grid">
                            <div class="form-group">
                                <label class="form-label">Wash Care</label>
                                <textarea name="wash_care" class="form-control" rows="3" placeholder="e.g. Dry clean only..."><?= htmlspecialchars($product['wash_care'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Shipping Info</label>
                                <textarea name="shipping_info" class="form-control" rows="3" placeholder="e.g. Dispatched in 2-3 days..."><?= htmlspecialchars($product['shipping_info'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Returns Info</label>
                                <textarea name="returns_info" class="form-control" rows="3" placeholder="e.g. 14 days easy returns..."><?= htmlspecialchars($product['returns_info'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory & Logistics (SKU, Barcode, Weight, Dimensions) -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-boxes-packing"></i> Inventory &amp; Logistics Specifications</h3>
                        <p class="admin-section-desc">Track SKU codes, barcodes for scanner billing, and package weight/dimensions for shipping calculators.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">SKU Code</label>
                                <input type="text" name="sku" class="form-control" placeholder="e.g. DV-KURTI-001" value="<?= htmlspecialchars($product['sku'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Barcode / EAN</label>
                                <input type="text" name="barcode" class="form-control" placeholder="e.g. 5060123456789" value="<?= htmlspecialchars($product['barcode'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Package Weight</label>
                                <input type="text" name="weight" class="form-control" placeholder="e.g. 0.85 kg" value="<?= htmlspecialchars($product['weight'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Dimensions (L × W × H)</label>
                                <input type="text" name="dimensions" class="form-control" placeholder="e.g. 35 × 25 × 5 cm" value="<?= htmlspecialchars($product['dimensions'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- SEO, OpenGraph & Search Tags -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-magnifying-glass-chart"></i> SEO, OpenGraph &amp; Google Schema</h3>
                        <p class="admin-section-desc">Optimize how your product ranks on Google Search, WhatsApp, Instagram, and Twitter share previews.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">SEO URL Slug</label>
                                <input type="text" name="seo_url" class="form-control" placeholder="e.g. luxury-silk-kurti-green" value="<?= htmlspecialchars($product['seo_url'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Search Tags (comma separated)</label>
                                <input type="text" name="tags" class="form-control" placeholder="e.g. silk, kurti, summer, wedding, green" value="<?= htmlspecialchars($product['tags'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Meta Title</label>
                            <input type="text" name="meta_title" class="form-control" placeholder="e.g. Buy Luxury Green Silk Kurti | Dievon Atelier" value="<?= htmlspecialchars($product['meta_title'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Meta Description</label>
                            <textarea name="meta_description" class="form-control" rows="2" placeholder="e.g. Explore Dievon's hand-crafted Mulberry Silk Kurti with metallic embroidery. Free UK delivery and custom fitting."><?= htmlspecialchars($product['meta_description'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Stock Management -->
                    <div class="form-row admin-form-row-mt">
                        <div class="form-group admin-form-checkbox-align">
                            <label class="admin-checkbox-label">
                                <input type="checkbox" name="track_stock" value="1" id="trackStockCb" class="admin-checkbox-input"
                                    <?= !empty($product['track_stock']) ? 'checked' : '' ?>>
                                📦 Track Stock
                            </label>
                            <?php if ($displayInStock !== null): ?>
                            <span class="admin-stock-badge-active">
                                🟢 In Stock: <?= $displayInStock ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <!-- Stock quantity is managed via the Stock tab, not here -->
                        <input type="hidden" name="stock_qty" value="<?= (int)($product['stock_qty'] ?? 0) ?>">
                    </div>

                    <!-- ── Image & Video Media Upload ───────────────────── -->
                    <div class="glass-panel form-section">
                        <h3><i class="fa-solid fa-photo-film"></i> Product Image &amp; 360° Video</h3>
                        <p class="admin-section-desc">Upload primary product photo and/or 360° product video.</p>

                        <?php if (!empty($product['image'])): ?>
                        <div class="admin-current-img-box">
                            <p class="admin-current-img-label">Current image:</p>
                            <img src="../uploads/products/<?= htmlspecialchars($product['image']) ?>"
                                 alt="Current product image"
                                 class="image-current-thumb admin-current-img-thumb"
                                 id="previewCurrentImg">
                        </div>
                        <?php endif; ?>

                        <div class="form-group admin-mb-18">
                            <label class="form-label admin-label-bold">1. Product Main Photo</label>
                            <div class="image-upload-box" id="uploadBox">
                                <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp,image/gif"
                                    id="productImageInput" onchange="previewUpload(this)">
                                <div class="image-upload-icon">📷</div>
                                <p class="image-upload-label">
                                    <strong>Click to upload photo</strong> or drag & drop<br>
                                    JPG, PNG, WebP, or GIF — max 8MB
                                </p>
                            </div>

                            <img src="" alt="New image preview" id="newImgPreview" class="admin-new-img-preview">
                        </div>

                        <!-- ── Product 360° Video (Bottom of Product Image section) ── -->
                        <div class="form-group admin-video-box-mt">
                            <label class="form-label admin-label-bold"><i class="fa-solid fa-video admin-video-icon-primary"></i> 2. Product 360° Video (Use if no product photo or for 360° view)</label>
                            <?php if (!empty($product['video_url'])): ?>
                            <div class="admin-video-info-text">
                                📹 Current Video: <strong class="admin-video-url-bold"><?= htmlspecialchars($product['video_url']) ?></strong>
                            </div>
                            <?php endif; ?>
                            <div class="admin-video-flex-column">
                                <div>
                                    <label class="form-label admin-sublabel-muted">Upload Product Video File (MP4 / WebM)</label>
                                    <input type="file" name="product_video" class="form-control" accept="video/mp4,video/webm,video/quicktime">
                                </div>
                                <div>
                                    <label class="form-label admin-sublabel-muted">OR Enter Video URL (YouTube / External MP4 Link)</label>
                                    <input type="text" name="video_url" class="form-control" placeholder="e.g. https://www.youtube.com/watch?v=... or my_video.mp4" value="<?= htmlspecialchars($product['video_url'] ?? '') ?>">
                                </div>
                            </div>
                            <small class="form-text text-muted admin-help-text-muted">
                                💡 Note: If you have no main photo uploaded, this Product 360° Video will automatically take the place of <code>product-main-img</code> on the storefront product page!
                            </small>
                        </div>

                        <!-- Fallback emoji (shown if no image) -->
                        <div class="form-group admin-mt-16">
                            <label class="form-label admin-label-xs">Emoji (shown if no image or video)</label>
                            <input type="text" name="emoji" id="emojiInput" class="form-control admin-emoji-input-size"
                                value="<?= htmlspecialchars($product['emoji'] ?? '✨') ?>">
                        </div>
                    </div>

                    <!-- ── Additional Gallery Images ──────────── -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-images"></i> Additional Images Gallery</h3>
                        <p class="admin-section-desc">Upload multiple photos to show every side of your product.</p>

                        <?php if (!empty($additionalImages)): ?>
                        <div class="admin-gallery-wrap-flex" id="additionalImagesContainer">
                            <?php foreach ($additionalImages as $img): ?>
                            <div class="gallery-image-wrap admin-gallery-thumb-item" id="ai-<?= $img['id'] ?>">
                                <img src="../uploads/products/<?= htmlspecialchars($img['image']) ?>" class="admin-gallery-thumb-img">
                                <button type="button" onclick="deleteAdditionalImage(<?= $img['id'] ?>)" title="Remove Image" class="admin-gallery-del-btn">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                                <span class="admin-gallery-saved-tag">Saved</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="image-upload-box">
                            <input type="file" name="additional_images[]" id="additional_images_input" accept="image/jpeg,image/png,image/webp,image/gif" multiple onchange="previewSelectedGalleryImages(this)">
                            <div class="image-upload-icon">📸</div>
                            <p class="image-upload-label">
                                <strong>Select multiple files</strong><br>
                                Shift-click or Cmd-click to select multiple
                            </p>
                        </div>
                        <div id="newSelectedGalleryPreview" class="admin-gallery-wrap-flex admin-section-mt"></div>
                    </div>

                    <button type="submit" class="btn-primary admin-btn-save-submit">
                        <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                        <?= $isEdit ? 'Save Changes' : 'Add Product' ?>
                    </button>
                </div>

                <!-- ── Live Preview ───────────────────────── -->
                <div class="admin-sticky-preview">
                    <div class="glass-panel preview-card">
                        <p class="admin-preview-eyebrow">Live Preview</p>
                        <div class="preview-img-wrap <?= !empty($product['image']) ? 'has-image' : '' ?>" id="previewWrap">
                            <?php if (!empty($product['image'])): ?>
                            <img src="../uploads/products/<?= htmlspecialchars($product['image']) ?>" alt="Preview" id="previewImg" class="admin-preview-img-fill">
                            <?php else: ?>
                            <span id="previewEmoji" class="admin-preview-emoji-size"><?= htmlspecialchars($product['emoji'] ?? '✨') ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 id="previewName" class="admin-preview-name-text"><?= htmlspecialchars($product['name'] ?? 'Product Name') ?></h3>
                        <div id="previewPrice" class="admin-preview-price-text">
                            ₹<?= number_format((float)($product['price'] ?? 0), 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($isEdit && !empty($product['id'])): ?>
        <!-- ── Product Sizes Section ─────────────────── -->
        <div class="glass-panel form-section" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-ruler-combined" style="color:var(--color-secondary);"></i>
                Product Sizes
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — e.g. Small, Medium, Large</span>
            </h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">
                Add size options with custom prices (optional). If price is left empty or 0, the main selling price above is automatically used.
            </p>

            <div id="variantsList">
                <?php foreach ($existingVariants as $v): ?>
                <div class="variant-row" id="vrow-<?= $v['id'] ?>" style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--bg-main); border-radius:var(--radius-sm); margin-bottom:10px; border:1px solid var(--border-light);">
                    <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:14px;"></i>
                    <input type="text" value="<?= htmlspecialchars($v['name']) ?>" placeholder="Size name (e.g. 500ml)"
                        class="form-control" style="flex:1; min-width:100px;"
                        onchange="updateVariant(<?= $v['id'] ?>, this.value, document.getElementById('vp-<?= $v['id'] ?>').value, document.getElementById('va-<?= $v['id'] ?>').checked)">
                    <div style="display:flex; align-items:center; gap:4px;">
                        <span style="font-size:15px;">₹</span>
                        <input type="number" id="vp-<?= $v['id'] ?>" value="<?= number_format($v['price'], 2) ?>" step="0.01" min="0" placeholder="Price (Optional)"
                            class="form-control" style="width:110px;"
                            onchange="updateVariant(<?= $v['id'] ?>, document.querySelector('#vrow-<?= $v['id'] ?> input[type=text]').value, this.value, document.getElementById('va-<?= $v['id'] ?>').checked)">
                    </div>
                    <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-secondary); white-space:nowrap; cursor:pointer;">
                        <input type="checkbox" id="va-<?= $v['id'] ?>" <?= $v['available'] ? 'checked' : '' ?>
                            onchange="updateVariant(<?= $v['id'] ?>, document.querySelector('#vrow-<?= $v['id'] ?> input[type=text]').value, document.getElementById('vp-<?= $v['id'] ?>').value, this.checked)">
                        Available
                    </label>
                    <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteVariant(<?= $v['id'] ?>, <?= (int)$product['id'] ?>)">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:12px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface);">
                <i class="fa-solid fa-plus-circle" style="color:var(--color-secondary); font-size:18px; flex-shrink:0;"></i>
                <input type="text" id="newVariantName" placeholder="Size name (e.g. S, M, L, XL, XXL, Free Size)" class="form-control" style="flex:1;">
                <div style="display:flex; align-items:center; gap:4px;">
                    <span style="font-size:15px;">₹</span>
                    <input type="number" id="newVariantPrice" placeholder="Price (Optional)" step="0.01" min="0" class="form-control" style="width:120px;">
                </div>
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addVariant(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Size
                </button>
            </div>
            <p style="font-size:12px; color:var(--text-muted); margin-top:10px;">
                <i class="fa-solid fa-circle-info"></i>
                Entering a custom price for a size is optional. If left blank or 0, the main selling price above will be used automatically.
            </p>
        </div>

        <!-- ── Colour Variants Section (Biba-style image colour selector) ── -->
        <div class="glass-panel form-section" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-palette" style="color:var(--color-primary);"></i>
                Colour Variants
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — each colour gets its own gallery, price and sizes/stock</span>
            </h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">
                Add one colour per look. Upload a gallery for each colour, pick a circular thumbnail, and set which sizes are in stock for that colour.
                Products with no colour added here keep using the plain "Product Sizes" list above.
            </p>

            <div id="colorsList">
                <?php foreach ($existingColors as $c):
                    $sizesByCode = [];
                    foreach ($c['sizes'] as $s) { $sizesByCode[$s['size_code']] = $s; }
                ?>
                <div class="color-card" id="ccard-<?= $c['id'] ?>" style="border:1px solid var(--border-light); border-radius:var(--radius-md); padding:20px; margin-bottom:18px; background:var(--bg-main);">
                    <div style="display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap;">
                        <div style="text-align:center; flex-shrink:0;">
                            <img id="cthumb-<?= $c['id'] ?>" src="<?= !empty($c['thumbnail']) ? '../uploads/products/' . htmlspecialchars($c['thumbnail']) : '' ?>"
                                style="width:72px; height:72px; border-radius:50%; object-fit:cover; border:3px solid <?= !empty($c['thumbnail']) ? 'var(--color-primary)' : 'var(--border-light)' ?>; background:var(--bg-surface); <?= empty($c['thumbnail']) ? 'display:none;' : '' ?>">
                            <div id="cthumb-placeholder-<?= $c['id'] ?>" style="width:72px; height:72px; border-radius:50%; border:2px dashed var(--border-strong); display:<?= empty($c['thumbnail']) ? 'flex' : 'none' ?>; align-items:center; justify-content:center; color:var(--text-muted); font-size:11px; text-align:center;">No<br>thumb</div>
                            <label style="display:block; margin-top:6px; font-size:11px; color:var(--color-secondary); cursor:pointer;">
                                Upload
                                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="uploadColorThumbnail(<?= $c['id'] ?>, this)">
                            </label>
                        </div>

                        <div style="flex:1; min-width:220px; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label class="form-label" style="font-size:11px;">Colour Name *</label>
                                <input type="text" id="cname-<?= $c['id'] ?>" class="form-control" value="<?= htmlspecialchars($c['color_name']) ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:11px;">Variant SKU</label>
                                <input type="text" id="csku-<?= $c['id'] ?>" class="form-control" value="<?= htmlspecialchars($c['sku'] ?? '') ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:11px;">Price Override <span style="color:var(--text-muted);">(blank = main price)</span></label>
                                <input type="number" step="0.01" min="0" id="cprice-<?= $c['id'] ?>" class="form-control" value="<?= $c['price_override'] !== null ? number_format($c['price_override'], 2) : '' ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:11px;">MRP Override <span style="color:var(--text-muted);">(blank = main MRP)</span></label>
                                <input type="number" step="0.01" min="0" id="cmrp-<?= $c['id'] ?>" class="form-control" value="<?= $c['mrp_price_override'] !== null ? number_format($c['mrp_price_override'], 2) : '' ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-secondary); cursor:pointer; white-space:nowrap;">
                                <input type="checkbox" id="cactive-<?= $c['id'] ?>" <?= $c['is_active'] ? 'checked' : '' ?> onchange="updateColor(<?= $c['id'] ?>)">
                                Active
                            </label>
                            <button type="button" class="btn-danger" style="padding:6px 12px; font-size:12px;" onclick="deleteColor(<?= $c['id'] ?>, '<?= htmlspecialchars($c['color_name'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-trash"></i> Delete Colour
                            </button>
                        </div>
                    </div>

                    <!-- Gallery -->
                    <div style="margin-top:16px;">
                        <label class="form-label" style="font-size:12px;">Gallery Images for this Colour</label>
                        <div id="cgallery-<?= $c['id'] ?>" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
                            <?php foreach ($c['images'] as $img): ?>
                            <div class="color-gallery-item" id="cimg-<?= $img['id'] ?>" style="position:relative; width:64px; height:64px;">
                                <img src="../uploads/products/<?= htmlspecialchars($img['image']) ?>" style="width:100%; height:100%; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid <?= ($c['thumbnail'] === $img['image']) ? 'var(--color-primary)' : 'transparent' ?>;" title="Click to set as thumbnail" onclick="setColorThumbnail(<?= $c['id'] ?>, '<?= htmlspecialchars($img['image'], ENT_QUOTES) ?>')">
                                <button type="button" onclick="deleteColorImage(<?= $img['id'] ?>, <?= $c['id'] ?>)" style="position:absolute; top:-6px; right:-6px; width:18px; height:18px; border-radius:50%; background:#c0392b; color:#fff; border:none; font-size:10px; cursor:pointer; line-height:1;">×</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--color-secondary); cursor:pointer; padding:6px 12px; border:1px dashed var(--border-strong); border-radius:6px;">
                            <i class="fa-solid fa-upload"></i> Add gallery images
                            <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none;" onchange="uploadColorGallery(<?= $c['id'] ?>, this)">
                        </label>
                    </div>

                    <!-- Sizes & Stock -->
                    <div style="margin-top:18px;">
                        <label class="form-label" style="font-size:12px;">Sizes &amp; Stock for this Colour</label>
                        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px,1fr)); gap:10px;">
                            <?php foreach ($sizeLadder as $sz):
                                $existing = $sizesByCode[$sz['code']] ?? null;
                            ?>
                            <div class="size-stock-row" style="display:flex; align-items:center; gap:8px; padding:8px 10px; background:var(--bg-surface); border-radius:6px; border:1px solid var(--border-light);">
                                <label style="display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer; flex:1;">
                                    <input type="checkbox" id="csize-<?= $c['id'] ?>-<?= $sz['code'] ?>" <?= $existing ? 'checked' : '' ?>
                                        data-variant-id="<?= $existing['id'] ?? '' ?>"
                                        onchange="toggleColorSize(<?= $c['id'] ?>, '<?= $sz['code'] ?>', '<?= $sz['label'] ?>', this)">
                                    <?= $sz['label'] ?>
                                </label>
                                <input type="number" min="0" id="cstock-<?= $c['id'] ?>-<?= $sz['code'] ?>" class="form-control" placeholder="Stock"
                                    style="width:70px; padding:4px 6px; font-size:12px;" value="<?= $existing ? (int)$existing['stock_qty'] : '' ?>"
                                    <?= $existing ? '' : 'disabled' ?>
                                    onchange="updateColorSizeStock(<?= $c['id'] ?>, '<?= $sz['code'] ?>', this)">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:12px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface); flex-wrap:wrap;">
                <input type="text" id="newColorName" placeholder="Colour name (e.g. Green)" class="form-control" style="flex:1; min-width:140px;">
                <input type="text" id="newColorSku" placeholder="Variant SKU (optional)" class="form-control" style="width:160px;">
                <input type="number" step="0.01" min="0" id="newColorPrice" placeholder="Price override (optional)" class="form-control" style="width:170px;">
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addColor(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Colour
                </button>
            </div>
        </div>

        <!-- ── Product Specifications Section (general Label/Value/Unit rows) ── -->
        <div class="glass-panel form-section" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-list-check" style="color:var(--color-secondary);"></i>
                Product Specifications
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — general details shown on the product page (e.g. Fabric: Cotton)</span>
            </h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">
                Add any label/value details relevant to this product. Suggestions are just hints — you can always type a custom label.
                Empty sections are hidden automatically on the product page.
            </p>

            <div id="generalSpecsList">
                <?php foreach ($existingGeneralSpecs as $spec): ?>
                <div class="spec-row" id="gspec-<?= $spec['id'] ?>" style="display:flex; align-items:center; gap:10px; padding:12px 16px; background:var(--bg-main); border-radius:var(--radius-sm); margin-bottom:10px; border:1px solid var(--border-light); flex-wrap:wrap;">
                    <div style="display:flex; flex-direction:column; gap:2px;">
                        <button type="button" title="Move up" onclick="moveGeneralSpec(<?= $spec['id'] ?>, 'up')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-up"></i></button>
                        <button type="button" title="Move down" onclick="moveGeneralSpec(<?= $spec['id'] ?>, 'down')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-down"></i></button>
                    </div>
                    <input type="text" class="form-control gspec-label" list="specLabelSuggestions" value="<?= htmlspecialchars($spec['label']) ?>" placeholder="Label (e.g. Fabric)" style="flex:1; min-width:120px;" onchange="updateGeneralSpec(<?= $spec['id'] ?>)">
                    <input type="text" class="form-control gspec-value" value="<?= htmlspecialchars($spec['value']) ?>" placeholder="Value (e.g. Pure Cotton)" style="flex:1; min-width:140px;" onchange="updateGeneralSpec(<?= $spec['id'] ?>)">
                    <input type="text" class="form-control gspec-unit" list="specUnitSuggestions" value="<?= htmlspecialchars($spec['unit'] ?? '') ?>" placeholder="Unit (optional)" style="width:110px;" onchange="updateGeneralSpec(<?= $spec['id'] ?>)">
                    <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteGeneralSpec(<?= $spec['id'] ?>)">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:10px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface); flex-wrap:wrap;">
                <input type="text" id="newGeneralSpecLabel" list="specLabelSuggestions" placeholder="Label (e.g. Fabric)" class="form-control" style="flex:1; min-width:120px;">
                <input type="text" id="newGeneralSpecValue" placeholder="Value (e.g. Pure Cotton)" class="form-control" style="flex:1; min-width:140px;">
                <input type="text" id="newGeneralSpecUnit" list="specUnitSuggestions" placeholder="Unit (optional)" class="form-control" style="width:110px;">
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addGeneralSpec(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Specification
                </button>
            </div>
        </div>

        <!-- ── Product Components Section (Kurti / Palazzo / Dupatta ..., each with its own specs) ── -->
        <div class="glass-panel form-section" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-shirt" style="color:var(--color-primary);"></i>
                Product Components
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — for sets with multiple pieces (e.g. Kurti + Palazzo + Dupatta)</span>
            </h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">
                Add a component for each piece in this product, then add its own specifications underneath it.
                Each component with at least one specification appears as its own section on the product page.
            </p>

            <div id="componentsList">
                <?php foreach ($existingComponents as $comp): ?>
                <div class="component-card" id="comp-<?= $comp['id'] ?>" style="border:1px solid var(--border-light); border-radius:var(--radius-md); padding:18px; margin-bottom:16px; background:var(--bg-main);">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                        <div style="display:flex; flex-direction:column; gap:2px;">
                            <button type="button" title="Move up" onclick="moveComponent(<?= $comp['id'] ?>, 'up')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-up"></i></button>
                            <button type="button" title="Move down" onclick="moveComponent(<?= $comp['id'] ?>, 'down')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-down"></i></button>
                        </div>
                        <input type="text" class="form-control comp-name" list="componentNameSuggestions" value="<?= htmlspecialchars($comp['name']) ?>" placeholder="Component name (e.g. Kurti)" style="flex:1; min-width:160px; font-weight:600;" onchange="updateComponent(<?= $comp['id'] ?>)">
                        <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteComponent(<?= $comp['id'] ?>, '<?= htmlspecialchars($comp['name'], ENT_QUOTES) ?>')">
                            <i class="fa-solid fa-trash"></i> Delete Component
                        </button>
                    </div>

                    <div id="compSpecsList-<?= $comp['id'] ?>">
                        <?php foreach ($comp['specs'] as $spec): ?>
                        <div class="spec-row" id="cspec-<?= $spec['id'] ?>" style="display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--bg-surface); border-radius:var(--radius-sm); margin-bottom:8px; border:1px solid var(--border-light); flex-wrap:wrap;">
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <button type="button" title="Move up" onclick="moveComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>, 'up')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;font-size:11px;"><i class="fa-solid fa-caret-up"></i></button>
                                <button type="button" title="Move down" onclick="moveComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>, 'down')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;font-size:11px;"><i class="fa-solid fa-caret-down"></i></button>
                            </div>
                            <input type="text" class="form-control cspec-label" list="componentLabelSuggestions" value="<?= htmlspecialchars($spec['label']) ?>" placeholder="Label (e.g. Fabric)" style="flex:1; min-width:110px; font-size:13px;" onchange="updateComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                            <input type="text" class="form-control cspec-value" value="<?= htmlspecialchars($spec['value']) ?>" placeholder="Value" style="flex:1; min-width:120px; font-size:13px;" onchange="updateComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                            <input type="text" class="form-control cspec-unit" list="specUnitSuggestions" value="<?= htmlspecialchars($spec['unit'] ?? '') ?>" placeholder="Unit" style="width:90px; font-size:13px;" onchange="updateComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                            <button type="button" class="btn-danger" style="padding:5px 10px; flex-shrink:0; font-size:12px;" onclick="deleteComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; margin-top:10px; padding:10px 14px; border:1.5px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface); flex-wrap:wrap;">
                        <input type="text" id="newCompSpecLabel-<?= $comp['id'] ?>" list="componentLabelSuggestions" placeholder="Label" class="form-control" style="flex:1; min-width:110px; font-size:13px;">
                        <input type="text" id="newCompSpecValue-<?= $comp['id'] ?>" placeholder="Value" class="form-control" style="flex:1; min-width:120px; font-size:13px;">
                        <input type="text" id="newCompSpecUnit-<?= $comp['id'] ?>" list="specUnitSuggestions" placeholder="Unit" class="form-control" style="width:90px; font-size:13px;">
                        <button type="button" class="btn-primary" style="padding:8px 14px; flex-shrink:0; font-size:12px;" onclick="addComponentSpec(<?= $comp['id'] ?>, <?= (int)$product['id'] ?>)">
                            <i class="fa-solid fa-plus"></i> Add Spec
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:12px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface);">
                <input type="text" id="newComponentName" list="componentNameSuggestions" placeholder="Component name (e.g. Kurti, Palazzo, Dupatta)" class="form-control" style="flex:1;">
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addComponent(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Component
                </button>
            </div>
        </div>

        <datalist id="specLabelSuggestions">
            <?php foreach ($specSuggestions['general_labels'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="componentNameSuggestions">
            <?php foreach ($specSuggestions['component_names'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="componentLabelSuggestions">
            <?php foreach ($specSuggestions['component_labels'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="specUnitSuggestions">
            <?php foreach ($specSuggestions['units'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <?php endif; ?>

    </main>
</div>

<script>
function previewUpload(input) {
    const newPrev = document.getElementById('newImgPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            if (newPrev) {
                newPrev.src = e.target.result;
                newPrev.classList.add('active');
                newPrev.style.setProperty('display', 'block', 'important');
            }

            // Update the live preview card
            const wrap = document.getElementById('previewWrap');
            let img = document.getElementById('previewImg');
            if (!img) {
                img = document.createElement('img');
                img.id = 'previewImg';
                img.alt = 'Preview';
                img.className = 'admin-preview-img-fill';
                if (wrap) {
                    wrap.innerHTML = '';
                    wrap.appendChild(img);
                }
            }
            if (wrap) wrap.classList.add('has-image');
            if (img) img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        if (newPrev) {
            newPrev.src = '';
            newPrev.classList.remove('active');
            newPrev.style.setProperty('display', 'none', 'important');
        }
    }
}

function updatePreviewName(val) {
    document.getElementById('previewName').textContent = val || 'Product Name';
}

function updatePreviewPrice(val) {
    const p = parseFloat(val) || 0;
    document.getElementById('previewPrice').textContent = '₹' + p.toFixed(2);
}

// Drag-and-drop highlight
const box = document.getElementById('uploadBox');
box.addEventListener('dragover', e => { e.preventDefault(); box.classList.add('dragover'); });
box.addEventListener('dragleave', () => box.classList.remove('dragover'));
box.addEventListener('drop', e => {
    e.preventDefault(); box.classList.remove('dragover');
    const input = document.getElementById('productImageInput');
    input.files = e.dataTransfer.files;
    previewUpload(input);
});

// ── Variant Management ────────────────────────────────────────
function addVariant(productId) {
    const nameEl  = document.getElementById('newVariantName');
    const priceEl = document.getElementById('newVariantPrice');
    const name    = nameEl.value.trim();
    const price   = parseFloat(priceEl.value) || 0;

    if (!name) { alert('Please enter a size name (e.g. S, M, L)'); nameEl.focus(); return; }

    fetch('variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=add&product_id=${productId}&name=${encodeURIComponent(name)}&price=${price}`,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { alert(data.message || 'Error adding variant'); return; }
        // Append new row to list
        const list = document.getElementById('variantsList');
        const id = data.id;
        const row = document.createElement('div');
        row.className = 'variant-row';
        row.id = 'vrow-' + id;
        row.style.cssText = 'display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--bg-main); border-radius:var(--radius-sm); margin-bottom:10px; border:1px solid var(--border-light);';
        row.innerHTML = `
            <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:14px;"></i>
            <input type="text" value="${escHtml(name)}" placeholder="Size name"
                class="form-control" style="flex:1; min-width:100px;"
                onchange="updateVariant(${id}, this.value, document.getElementById('vp-${id}').value, document.getElementById('va-${id}').checked)">
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="font-size:15px;">£</span>
                <input type="number" id="vp-${id}" value="${price.toFixed(2)}" step="0.01" min="0.01" placeholder="Price"
                    class="form-control" style="width:90px;"
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, this.value, document.getElementById('va-${id}').checked)">
            </div>
            <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-secondary); white-space:nowrap; cursor:pointer;">
                <input type="checkbox" id="va-${id}" checked
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, document.getElementById('vp-${id}').value, this.checked)">
                Available
            </label>
            <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteVariant(${id}, ${productId})">
                <i class="fa-solid fa-trash"></i>
            </button>`;
        list.appendChild(row);
        nameEl.value  = '';
        priceEl.value = '';
        nameEl.focus();
    })
    .catch(() => alert('Network error. Please try again.'));
}

let updateTimer = {};
function updateVariant(id, name, price, available) {
    clearTimeout(updateTimer[id]);
    updateTimer[id] = setTimeout(() => {
        fetch('variant_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update&id=${id}&product_id=0&name=${encodeURIComponent(name)}&price=${parseFloat(price)||0}&${available ? 'available=1' : ''}`,
        })
        .then(r => r.json())
        .then(data => {
            const row = document.getElementById('vrow-' + id);
            if (row) {
                row.style.borderColor = data.success ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)';
                setTimeout(() => { row.style.borderColor = ''; }, 1200);
            }
        });
    }, 600);
}

function deleteVariant(id, productId) {
    if (!confirm('Remove this variant? This cannot be undone.')) return;
    fetch('variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&id=${id}&product_id=${productId}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('vrow-' + id);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity 0.3s'; setTimeout(() => row.remove(), 300); }
        }
    });
}

function deleteAdditionalImage(id) {
    if (!confirm('Remove this image? This cannot be undone.')) return;
    fetch('image_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&id=${id}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('ai-' + id);
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }
        } else {
            alert('Error deleting image.');
        }
    });
}

let galleryDataTransfer = new DataTransfer();

function previewSelectedGalleryImages(input) {
    const container = document.getElementById('newSelectedGalleryPreview');
    if (!container) return;

    if (input.files && input.files.length > 0) {
        // Accumulate files instead of replacing
        Array.from(input.files).forEach(file => {
            galleryDataTransfer.items.add(file);
        });

        // Sync accumulated files back to input element
        input.files = galleryDataTransfer.files;
    }

    renderGalleryPreviews();
}

function renderGalleryPreviews() {
    const container = document.getElementById('newSelectedGalleryPreview');
    if (!container) return;
    container.innerHTML = '';

    const files = galleryDataTransfer.files;
    if (files && files.length > 0) {
        Array.from(files).forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const wrap = document.createElement('div');
                wrap.id = 'new-gallery-wrap-' + index;
                wrap.className = 'gallery-image-wrap admin-gallery-new-item';
                wrap.innerHTML = `
                    <img src="${e.target.result}" class="admin-gallery-thumb-img">
                    <button type="button" onclick="removeNewSelectedImage(${index})" title="Remove from selection" class="admin-gallery-del-btn">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <span class="admin-gallery-new-tag">New</span>
                `;
                container.appendChild(wrap);
            };
            reader.readAsDataURL(file);
        });
    }
}

function removeNewSelectedImage(index) {
    const input = document.getElementById('additional_images_input');
    const newDt = new DataTransfer();
    
    Array.from(galleryDataTransfer.files).forEach((file, i) => {
        if (i !== index) {
            newDt.items.add(file);
        }
    });

    galleryDataTransfer = newDt;
    if (input) {
        input.files = galleryDataTransfer.files;
    }
    
    renderGalleryPreviews();
}



function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Colour Variant Management ──────────────────────────────────
function addColor(productId) {
    const nameEl = document.getElementById('newColorName');
    const name   = nameEl.value.trim();
    if (!name) { alert('Please enter a colour name (e.g. Green)'); nameEl.focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('product_id', productId);
    fd.append('color_name', name);
    fd.append('sku', document.getElementById('newColorSku').value.trim());
    fd.append('price_override', document.getElementById('newColorPrice').value);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { location.reload(); }
            else { alert(data.message || 'Error adding colour.'); }
        })
        .catch(() => alert('Network error. Please try again.'));
}

function updateColor(id) {
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('color_name', document.getElementById('cname-' + id).value.trim());
    fd.append('sku', document.getElementById('csku-' + id).value.trim());
    fd.append('price_override', document.getElementById('cprice-' + id).value);
    fd.append('mrp_price_override', document.getElementById('cmrp-' + id).value);
    if (document.getElementById('cactive-' + id).checked) fd.append('is_active', '1');
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving colour.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function deleteColor(id, name) {
    if (!confirm(`Delete colour "${name}"? This removes its gallery images and size/stock data too. This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting colour.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function uploadColorThumbnail(id, input) {
    if (!input.files || !input.files[0]) return;
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('color_name', document.getElementById('cname-' + id).value.trim());
    fd.append('sku', document.getElementById('csku-' + id).value.trim());
    fd.append('price_override', document.getElementById('cprice-' + id).value);
    fd.append('mrp_price_override', document.getElementById('cmrp-' + id).value);
    if (document.getElementById('cactive-' + id).checked) fd.append('is_active', '1');
    fd.append('thumbnail', input.files[0]);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error uploading thumbnail.'); return; }
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.getElementById('cthumb-' + id);
                const ph  = document.getElementById('cthumb-placeholder-' + id);
                if (img) { img.src = e.target.result; img.style.display = 'block'; img.style.borderColor = 'var(--color-primary)'; }
                if (ph) ph.style.display = 'none';
            };
            reader.readAsDataURL(input.files[0]);
        })
        .catch(() => alert('Network error. Please try again.'));
}

function setColorThumbnail(colorId, image) {
    const fd = new FormData();
    fd.append('action', 'set_thumbnail');
    fd.append('id', colorId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('image', image);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error setting thumbnail.'); return; }
            const gallery = document.getElementById('cgallery-' + colorId);
            if (gallery) {
                gallery.querySelectorAll('img').forEach(im => { im.style.borderColor = 'transparent'; });
            }
            const img = document.getElementById('cthumb-' + colorId);
            const ph  = document.getElementById('cthumb-placeholder-' + colorId);
            if (img) { img.src = '../uploads/products/' + image; img.style.display = 'block'; img.style.borderColor = 'var(--color-primary)'; }
            if (ph) ph.style.display = 'none';
            event.target.style.borderColor = 'var(--color-primary)';
        })
        .catch(() => alert('Network error. Please try again.'));
}

function uploadColorGallery(colorId, input) {
    if (!input.files || !input.files.length) return;
    const fd = new FormData();
    fd.append('action', 'upload_gallery');
    fd.append('color_id', colorId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    Array.from(input.files).forEach(f => fd.append('gallery_images[]', f));
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error uploading images.'); return; }
            const gallery = document.getElementById('cgallery-' + colorId);
            if (!gallery) return;
            (data.uploaded || []).forEach(item => {
                const wrap = document.createElement('div');
                wrap.className = 'color-gallery-item';
                wrap.id = 'cimg-' + item.id;
                wrap.style.cssText = 'position:relative; width:64px; height:64px;';
                wrap.innerHTML = `
                    <img src="../uploads/products/${escHtml(item.image)}" style="width:100%; height:100%; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid transparent;" title="Click to set as thumbnail" onclick="setColorThumbnail(${colorId}, '${escHtml(item.image)}')">
                    <button type="button" onclick="deleteColorImage(${item.id}, ${colorId})" style="position:absolute; top:-6px; right:-6px; width:18px; height:18px; border-radius:50%; background:#c0392b; color:#fff; border:none; font-size:10px; cursor:pointer; line-height:1;">×</button>`;
                gallery.appendChild(wrap);
            });
            input.value = '';
        })
        .catch(() => alert('Network error. Please try again.'));
}

function deleteColorImage(imageId, colorId) {
    if (!confirm('Remove this image? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_image');
    fd.append('id', imageId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error deleting image.'); return; }
            const el = document.getElementById('cimg-' + imageId);
            if (el) el.remove();
        })
        .catch(() => alert('Network error. Please try again.'));
}

function toggleColorSize(colorId, code, label, checkbox) {
    const stockInput = document.getElementById('cstock-' + colorId + '-' + code);
    const productId  = <?= (int)($product['id'] ?? 0) ?>;

    if (checkbox.checked) {
        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('product_id', productId);
        fd.append('color_id', colorId);
        fd.append('size_code', code);
        fd.append('name', label);
        fd.append('stock_qty', '0');
        fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

        fetch('variant_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { alert(data.message || 'Error adding size.'); checkbox.checked = false; return; }
                checkbox.dataset.variantId = data.id;
                if (stockInput) { stockInput.disabled = false; stockInput.value = '0'; stockInput.focus(); }
            })
            .catch(() => { alert('Network error. Please try again.'); checkbox.checked = false; });
    } else {
        const variantId = checkbox.dataset.variantId;
        if (!variantId) { if (stockInput) { stockInput.disabled = true; stockInput.value = ''; } return; }
        if (!confirm(`Remove size ${label} for this colour? Its stock data will be lost.`)) { checkbox.checked = true; return; }

        fetch('variant_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id=${variantId}&product_id=${productId}`,
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert('Error removing size.'); checkbox.checked = true; return; }
            checkbox.dataset.variantId = '';
            if (stockInput) { stockInput.disabled = true; stockInput.value = ''; }
        })
        .catch(() => { alert('Network error. Please try again.'); checkbox.checked = true; });
    }
}

function updateColorSizeStock(colorId, code, input) {
    const checkbox  = document.getElementById('csize-' + colorId + '-' + code);
    const variantId = checkbox ? checkbox.dataset.variantId : '';
    if (!variantId) return;

    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', variantId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('name', input.closest('.size-stock-row').querySelector('label').textContent.trim());
    fd.append('price', '0');
    fd.append('available', '1');
    fd.append('stock_qty', input.value);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('variant_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error updating stock.'); })
        .catch(() => alert('Network error. Please try again.'));
}

// ── General Product Specifications ──────────────────────────────
const DIEVON_PRODUCT_ID = <?= (int)($product['id'] ?? 0) ?>;

function addGeneralSpec(productId) {
    const labelEl = document.getElementById('newGeneralSpecLabel');
    const valueEl = document.getElementById('newGeneralSpecValue');
    const label = labelEl.value.trim();
    const value = valueEl.value.trim();
    if (!label || !value) { alert('Please enter both a label and a value.'); (label ? valueEl : labelEl).focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('product_id', productId);
    fd.append('label', label);
    fd.append('value', value);
    fd.append('unit', document.getElementById('newGeneralSpecUnit').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error adding specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function updateGeneralSpec(id) {
    const row = document.getElementById('gspec-' + id);
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('label', row.querySelector('.gspec-label').value.trim());
    fd.append('value', row.querySelector('.gspec-value').value.trim());
    fd.append('unit', row.querySelector('.gspec-unit').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function deleteGeneralSpec(id) {
    if (!confirm('Delete this specification? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function moveGeneralSpec(id, direction) {
    const fd = new FormData();
    fd.append('action', direction === 'up' ? 'move_up' : 'move_down');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error reordering.'); })
        .catch(() => alert('Network error. Please try again.'));
}

// ── Product Components & their nested Specifications ─────────────
function addComponent(productId) {
    const nameEl = document.getElementById('newComponentName');
    const name = nameEl.value.trim();
    if (!name) { alert('Please enter a component name (e.g. Kurti).'); nameEl.focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add_component');
    fd.append('product_id', productId);
    fd.append('name', name);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error adding component.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function updateComponent(id) {
    const card = document.getElementById('comp-' + id);
    const fd = new FormData();
    fd.append('action', 'update_component');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('name', card.querySelector('.comp-name').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving component.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function deleteComponent(id, name) {
    if (!confirm(`Delete component "${name}"? This removes all of its specifications too. This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action', 'delete_component');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting component.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function moveComponent(id, direction) {
    const fd = new FormData();
    fd.append('action', direction === 'up' ? 'move_component_up' : 'move_component_down');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error reordering.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function addComponentSpec(componentId, productId) {
    const labelEl = document.getElementById('newCompSpecLabel-' + componentId);
    const valueEl = document.getElementById('newCompSpecValue-' + componentId);
    const label = labelEl.value.trim();
    const value = valueEl.value.trim();
    if (!label || !value) { alert('Please enter both a label and a value.'); (label ? valueEl : labelEl).focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add_spec');
    fd.append('component_id', componentId);
    fd.append('product_id', productId);
    fd.append('label', label);
    fd.append('value', value);
    fd.append('unit', document.getElementById('newCompSpecUnit-' + componentId).value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error adding specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function updateComponentSpec(id, componentId) {
    const row = document.getElementById('cspec-' + id);
    const fd = new FormData();
    fd.append('action', 'update_spec');
    fd.append('id', id);
    fd.append('component_id', componentId);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('label', row.querySelector('.cspec-label').value.trim());
    fd.append('value', row.querySelector('.cspec-value').value.trim());
    fd.append('unit', row.querySelector('.cspec-unit').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function deleteComponentSpec(id, componentId) {
    if (!confirm('Delete this specification? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_spec');
    fd.append('id', id);
    fd.append('component_id', componentId);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function moveComponentSpec(id, componentId, direction) {
    const fd = new FormData();
    fd.append('action', direction === 'up' ? 'move_spec_up' : 'move_spec_down');
    fd.append('id', id);
    fd.append('component_id', componentId);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error reordering.'); })
        .catch(() => alert('Network error. Please try again.'));
}

</script>
<?php require_once 'includes/footer.php'; ?>


