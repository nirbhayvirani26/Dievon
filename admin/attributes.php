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

$type = $_GET['type'] ?? 'colors';
$activeTab = $type === 'sizes' ? 'sizes' : 'colors';
$successMsg = '';
$errorMsg   = '';

// Auto create attributes table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_attributes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `attr_type` ENUM('color','size') NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `code` VARCHAR(50) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

// Handle Add/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attr'])) {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $attrType = $type === 'sizes' ? 'size' : 'color';
    if ($name) {
        try {
            $stmt = $pdo->prepare("INSERT INTO product_attributes (attr_type, name, code) VALUES (:type, :name, :code)");
            $stmt->execute(['type' => $attrType, 'name' => $name, 'code' => $code]);
            $successMsg = ucfirst($attrType) . " '{$name}' created successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error adding attribute: " . $e->getMessage();
        }
    }
}

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    try {
        $pdo->prepare("DELETE FROM product_attributes WHERE id = :id")->execute(['id' => $delId]);
        $successMsg = "Item deleted successfully.";
    } catch (PDOException $e) {}
}

$attributes = [];
try {
    $targetType = $type === 'sizes' ? 'size' : 'color';
    $stmt = $pdo->prepare("SELECT * FROM product_attributes WHERE attr_type = :type ORDER BY id DESC");
    $stmt->execute(['type' => $targetType]);
    $attributes = $stmt->fetchAll();
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">🎨 <?= ucfirst($type) ?> Master Attributes</h1>
        <p class="admin-page-subtitle">Configure <?= strtolower($type) ?> options available for product size selectors and shop filters.</p>
    </div>
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

<div class="glass-panel form-section" style="margin-bottom: 24px;">
    <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 16px;">Add New <?= ucfirst($type) ?> Attribute</h3>
    <form action="attributes.php?type=<?= htmlspecialchars($type) ?>" method="POST">
        <div class="form-row" style="display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center;">
            <div class="form-group" style="margin:0;">
                <input type="text" name="name" class="form-control" placeholder="<?= $type === 'sizes' ? 'Size Label (e.g. S, M, L, XL, 38)' : 'Color Name (e.g. Emerald Green, Rose Gold)' ?>" required>
            </div>
            <?php if ($type === 'colors'): ?>
                <div class="form-group" style="margin:0;">
                    <input type="color" name="code" value="#991b1b" style="width: 50px; height: 42px; padding: 2px; border: 1px solid var(--border-strong); border-radius: 6px; cursor: pointer;">
                </div>
            <?php endif; ?>
            <button type="submit" name="add_attr" class="btn-primary" style="padding: 10px 20px; font-size: 14px;">
                Add <?= ucfirst($type) ?>
            </button>
        </div>
    </form>
</div>

<div class="glass-panel" style="padding:0; overflow:hidden;">
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary);">
        🏷️ Active <?= ucfirst($type) ?> List (<?= count($attributes) ?>)
    </div>
    <div class="table-wrapper">
        <table class="data-table" style="width: 100%;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th><?= ucfirst($type) ?> Name</th>
                    <?php if ($type === 'colors'): ?><th>Color Code</th><?php endif; ?>
                    <th style="width: 100px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attributes)): ?>
                    <tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">No <?= htmlspecialchars($type) ?> created yet. Standard options available on product form.</td></tr>
                <?php else: ?>
                    <?php foreach ($attributes as $a): ?>
                        <tr>
                            <td style="color: var(--text-muted); font-size: 12px;">#<?= $a['id'] ?></td>
                            <td><strong style="color: var(--text-primary); font-size: 14px;"><?= htmlspecialchars($a['name']) ?></strong></td>
                            <?php if ($type === 'colors'): ?>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="display: inline-block; width: 22px; height: 22px; border-radius: 4px; background: <?= htmlspecialchars($a['code'] ?? '#991b1b') ?>; border: 1px solid var(--border-light);"></span>
                                        <code style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($a['code'] ?? '#991b1b') ?></code>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <td style="text-align: right;">
                                <a href="attributes.php?type=<?= htmlspecialchars($type) ?>&delete=<?= $a['id'] ?>" onclick="return dvConfirmLink(this,'Delete this attribute?');" class="btn-sm btn-sm-danger" style="padding: 4px 10px; text-decoration: none;">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
