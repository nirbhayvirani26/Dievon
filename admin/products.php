<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$activeTab = 'products';
$successMsg = '';
$errorMsg   = '';

// Handle Delete Product (POST only with CSRF check & Soft Deletion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security CSRF validation failed.";
    } else {
        $delId = (int)($_POST['product_id'] ?? 0);
        try {
            // Soft delete product to protect historical orders
            $pdo->prepare("UPDATE products SET is_deleted = 1, available = 0 WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'soft_delete_product', "Soft-deleted product ID $delId");
            $successMsg = "Product archived (soft-deleted) successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error deleting product: " . $e->getMessage();
        }
    }
}

// Handle Duplicate Product (POST only with CSRF check)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'duplicate_product') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security CSRF validation failed.";
    } else {
        $dupId = (int)($_POST['product_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
            $stmt->execute(['id' => $dupId]);
            $orig = $stmt->fetch();
            if ($orig) {
                unset($orig['id'], $orig['created_at'], $orig['updated_at']);
                $orig['name'] = $orig['name'] . ' (Copy)';
                $orig['sku']  = (!empty($orig['sku']) ? $orig['sku'] . '-COPY' : 'DV-DUP-' . time());
                
                $cols = array_keys($orig);
                $colSql = implode(', ', $cols);
                $valSql = ':' . implode(', :', $cols);
                
                $insStmt = $pdo->prepare("INSERT INTO products ($colSql) VALUES ($valSql)");
                $insStmt->execute($orig);
                logAdminAction($_SESSION['admin_id'] ?? 1, 'duplicate_product', "Duplicated product ID $dupId as '{$orig['name']}'");
                $successMsg = "Product duplicated successfully as '{$orig['name']}'!";
            }
        } catch (PDOException $e) {
            $errorMsg = "Error duplicating product: " . $e->getMessage();
        }
    }
}

$products = [];
try {
    $products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">👗 Product Management</h1>
        <p class="admin-page-subtitle">Manage garments, prices, stock quantities, variants, and gallery images.</p>
    </div>
    <a href="product_form.php" class="btn-primary" style="padding: 10px 20px; font-size: 14px;">
        <i class="fa-solid fa-plus"></i> Add New Product
    </a>
</div>

<?php if ($successMsg): ?>
    <div style="background:#ecfdf5; color:#065f46; border:1px solid #10b981; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:600;">
        ✅ <?= htmlspecialchars($successMsg) ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div style="background:#fdf2f2; color:#ef4444; border:1px solid #f87171; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:600;">
        ⚠️ <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<div class="glass-panel" style="padding:24px; overflow:hidden;">
    <?php if (empty($products)): ?>
    <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
        <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">👗</div>
        <p style="font-size:16px;">No products found in catalogue.</p>
        <a href="product_form.php" class="btn-primary" style="margin-top:20px; display:inline-flex;">
            <i class="fa-solid fa-plus"></i> Add First Product
        </a>
    </div>
    <?php else: ?>
    
    <!-- Filter & Sort Bar -->
    <div style="display:flex; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; background:var(--bg-surface-soft); padding:14px; border-radius:var(--radius-sm); border:1px solid var(--border-light);">
        <div style="display:flex; align-items:center; gap:8px;">
            <label for="prodFilterCategory" style="font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Category:</label>
            <select id="prodFilterCategory" class="form-control" style="font-size:13px; padding:6px 12px; height:auto; width:auto; min-width:160px;" onchange="filterAndSortProducts()">
                <option value="all">🔍 All Categories</option>
                <?php 
                $prodCats = array_unique(array_column($products, 'category'));
                sort($prodCats);
                foreach ($prodCats as $catName): 
                ?>
                <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="display:flex; align-items:center; gap:8px;">
            <label for="prodSort" style="font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Sort By:</label>
            <select id="prodSort" class="form-control" style="font-size:13px; padding:6px 12px; height:auto; width:auto; min-width:160px;" onchange="filterAndSortProducts()">
                <option value="default">Default (Newest)</option>
                <option value="name_asc">Name (A-Z)</option>
                <option value="name_desc">Name (Z-A)</option>
                <option value="price_asc">Price (Low to High)</option>
                <option value="price_desc">Price (High to Low)</option>
            </select>
        </div>
    </div>

    <div class="table-wrapper">
        <table class="data-table" id="productsTable" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:70px;">Image</th>
                    <th>Product Name &amp; Details</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock Status</th>
                    <th style="width:180px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): 
                    $imgSrc = '';
                    if (!empty($p['image'])) {
                        if (file_exists(__DIR__ . '/../uploads/products/' . $p['image'])) {
                            $imgSrc = '../uploads/products/' . htmlspecialchars($p['image']);
                        } elseif (file_exists(__DIR__ . '/../uploads/gallery/' . $p['image'])) {
                            $imgSrc = '../uploads/gallery/' . htmlspecialchars($p['image']);
                        }
                    }
                ?>
                <tr data-category="<?= htmlspecialchars($p['category']) ?>" data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>" data-price="<?= $p['price'] ?>">
                    <td>
                        <?php if ($imgSrc): ?>
                            <img src="<?= $imgSrc ?>" style="width:55px; height:65px; object-fit:cover; border-radius:4px; border:1px solid var(--border-light);">
                        <?php else: ?>
                            <div style="width:55px; height:65px; background:var(--bg-surface-soft); border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:20px;"><?= htmlspecialchars($p['emoji'] ?? '✨') ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="font-size:15px; color:var(--text-primary); display:block;"><?= htmlspecialchars($p['name']) ?></strong>
                        <span style="font-size:12px; color:var(--text-muted);">SKU: <code><?= htmlspecialchars(!empty($p['sku']) ? $p['sku'] : 'DV-'.$p['id']) ?></code></span>
                    </td>
                    <td>
                        <span style="font-size:12px; font-weight:700; background:var(--bg-surface-soft); padding:4px 8px; border-radius:4px; color:var(--color-primary);"><?= htmlspecialchars($p['category']) ?></span>
                    </td>
                    <td>
                        <strong style="font-size:14px; color:var(--text-primary);">£<?= number_format($p['price'], 2) ?></strong>
                    </td>
                    <td>
                        <?php if (!empty($p['track_stock'])): ?>
                            <?php if ((int)$p['stock_qty'] > 5): ?>
                                <span class="badge-luxury" style="background:#ecfdf5; color:#10b981;">🟢 In Stock (<?= $p['stock_qty'] ?>)</span>
                            <?php elseif ((int)$p['stock_qty'] > 0): ?>
                                <span class="badge-luxury" style="background:#fffbeb; color:#f59e0b;">⚠️ Low Stock (<?= $p['stock_qty'] ?>)</span>
                            <?php else: ?>
                                <span class="badge-luxury" style="background:#fef2f2; color:#ef4444;">🔴 Out of Stock</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:11px; color:var(--text-muted);">∞ Available</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <a href="product_form.php?id=<?= $p['id'] ?>" class="btn-sm btn-sm-primary" style="padding:5px 10px; text-decoration:none; margin-right:4px;">
                            <i class="fa-solid fa-pen"></i> Edit
                        </a>
                        <form method="POST" action="products.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="duplicate_product">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-sm btn-sm-outline" style="padding:5px 10px; border:none; cursor:pointer; margin-right:4px;" title="Duplicate Product">
                                <i class="fa-solid fa-copy"></i>
                            </button>
                        </form>
                        <form method="POST" action="products.php" style="display:inline;" onsubmit="return confirm('Archive product &quot;<?= addslashes($p['name']) ?>&quot;?');">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-sm btn-sm-danger" style="padding:5px 10px; border:none; cursor:pointer;" title="Delete Product">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
function filterAndSortProducts() {
    const cat = document.getElementById('prodFilterCategory').value;
    const sort = document.getElementById('prodSort').value;
    const rows = Array.from(document.querySelectorAll('#productsTable tbody tr'));

    rows.forEach(row => {
        const rowCat = row.getAttribute('data-category');
        if (cat === 'all' || rowCat === cat) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    if (sort !== 'default') {
        const tbody = document.querySelector('#productsTable tbody');
        rows.sort((a, b) => {
            const nameA = a.getAttribute('data-name');
            const nameB = b.getAttribute('data-name');
            const priceA = parseFloat(a.getAttribute('data-price'));
            const priceB = parseFloat(b.getAttribute('data-price'));

            if (sort === 'name_asc') return nameA.localeCompare(nameB);
            if (sort === 'name_desc') return nameB.localeCompare(nameA);
            if (sort === 'price_asc') return priceA - priceB;
            if (sort === 'price_desc') return priceB - priceA;
            return 0;
        });
        rows.forEach(row => tbody.appendChild(row));
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
