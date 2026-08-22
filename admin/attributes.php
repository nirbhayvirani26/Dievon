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

// Moved ABOVE the POST handlers below.
// It used to sit after them, and after the page had already rendered — so a
// staff account without this permission ran the INSERT/DELETE first and was
// only shown the 403 afterwards. The record was already gone by then.
requireAdminCapability('catalogue.manage');

// Colours only. ?type=sizes is accepted and quietly treated as colours.
//
// This page offered a sizes tab that no link ever reached — $activeTab below was
// computed and never used, so the switcher was never built — and the size rows it
// managed drove exactly one thing: the order of the shop's size filter chips.
// That order now comes from the size ladder in config/size_ladder.php, the same
// list the product form, the colour Sizes & Stock grid, the size guide and the
// product page all use.
//
// Leaving the tab reachable would mean editing a second size list that changes
// nothing on the website, which is how the two drifted apart in the first place
// (the master list stopped at XL while the ladder runs to 5XL). Sizes are edited
// per product on the product form, and shop-wide in the ladder file.
//
// Existing attr_type='size' rows are left in the table — unreferenced, but a
// harmless record, and deleting a shopkeeper's data to tidy a page is not a
// trade worth making.
$type = 'colors';
$activeTab = 'colors';
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

// Handle Add/Delete (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attr']) && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $errorMsg = "Security validation failed (Invalid CSRF token).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attr'])) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        $delId = (int)$_POST['delete'];
        try {
            $pdo->prepare("DELETE FROM product_attributes WHERE id = :id")->execute(['id' => $delId]);
            $successMsg = "Item deleted successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error deleting attribute: " . $e->getMessage();
        }
    }
}

$attributes = [];
try {
    $targetType = $type === 'sizes' ? 'size' : 'color';

    /* Alphabetical. This was id DESC, so the list was in the order the colours
       happened to be typed, backwards — and a colour list exists to be looked up
       by name. No sort_order column on this table, so nothing manual is being
       overridden.

       Only ever colours: $type is fixed above and the Sizes tab was deliberately
       removed (see the note at the top of this file). Sorting by name would be
       wrong for sizes — S, M, L, XL is a ladder, not an alphabet — but that
       branch would be dead code here, so it is not written. If sizes ever return
       to this page, they need id order, not this. */
    $stmt = $pdo->prepare("SELECT * FROM product_attributes WHERE attr_type = :type ORDER BY name ASC, id ASC");
    $stmt->execute(['type' => $targetType]);
    $attributes = $stmt->fetchAll();
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';

?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">🎨 <?= ucfirst($type) ?> Master Attributes</h1>
        <p class="admin-page-subtitle">Configure <?= strtolower($type) ?> options available for product size selectors and shop filters.</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<div class="glass-panel form-section" style="margin-bottom: 24px;">
    <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 16px;">Add New <?= ucfirst($type) ?> Attribute</h3>
    <form action="attributes.php?type=<?= htmlspecialchars($type) ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-row" style="display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center;">
            <div class="form-group" style="margin:0;">
                <input type="text" name="name" class="form-control" placeholder="<?= $type === 'sizes' ? 'Size Label (e.g. S, M, L, XL, 38)' : 'Color Name (e.g. Emerald Green, Rose Gold)' ?>" required>
            </div>
            <?php if ($type === 'colors'): ?>
                <div class="form-group" style="margin:0;">
                    <input type="color" name="code" value="#991b1b" style="width: 50px; height: 42px; padding: 2px; border: 1px solid var(--border-strong); cursor: pointer;">
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
                                        <span style="display: inline-block; width: 22px; height: 22px; background: <?= htmlspecialchars($a['code'] ?? '#991b1b') ?>; border: 1px solid var(--border-light);"></span>
                                        <code style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($a['code'] ?? '#991b1b') ?></code>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <td style="text-align: right;">
                                <div class="admin-actions">
                                    <form method="POST" action="attributes.php?type=<?= htmlspecialchars($type) ?>" style="display:inline;" onsubmit="return dvConfirmForm(this,'Delete this attribute?');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="delete" value="<?= $a['id'] ?>">
                                        <button type="submit" class="admin-action-btn is-danger" title="Delete" aria-label="Delete <?= htmlspecialchars($a['name'] ?? 'attribute') ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
