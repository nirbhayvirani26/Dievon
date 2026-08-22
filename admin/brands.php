<?php
session_start();
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

$activeTab = 'brands';
$successMsg = '';
$errorMsg   = '';

// Auto create brands table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `brands` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `logo` VARCHAR(255) DEFAULT NULL,
        `status` ENUM('Active','Inactive') DEFAULT 'Active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

// Handle Add/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_brand'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name) {
        try {
            $stmt = $pdo->prepare("INSERT INTO brands (name, status) VALUES (:name, 'Active')");
            $stmt->execute(['name' => $name]);
            $successMsg = "Brand '{$name}' created successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error creating brand: " . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        $delId = (int)$_POST['delete'];
        try {
            $pdo->prepare("DELETE FROM brands WHERE id = :id")->execute(['id' => $delId]);
            $successMsg = "Brand deleted successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error deleting brand: " . $e->getMessage();
        }
    }
}

$brands = [];
try {
    /* Alphabetical, not newest-first. This is a reference list you come to in
       order to FIND a brand, and nobody looks one up by when it was added — with
       id DESC the one you wanted was wherever it happened to land. There is no
       sort_order column on this table, so nothing manual is being overridden. */
    $brands = $pdo->query("SELECT * FROM brands ORDER BY name ASC, id ASC")->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';

?>

<div style="background: white; border: 1px solid #e5e7eb; padding: 24px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 16px;">Add New Brand</h3>
    <form action="brands.php" method="POST" style="display: flex; gap: 12px; max-width: 500px;">
        <input type="text" name="name" placeholder="Brand Name (e.g. Dievon, Gucci)" required style="flex: 1; padding: 10px; border: 1px solid #d1d5db;">
        <button type="submit" name="add_brand" style="background: #111827; color: white; border: none; padding: 10px 20px; font-weight: 600; cursor: pointer;">Add Brand</button>
    </form>
</div>

<div style="background: white; border: 1px solid #e5e7eb; overflow: hidden;">
    <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: left;">
        <thead style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
            <tr>
                <th style="padding: 12px 16px;">ID</th>
                <th style="padding: 12px 16px;">Brand Name</th>
                <th style="padding: 12px 16px;">Status</th>
                <th style="padding: 12px 16px;">Created At</th>
                <th style="padding: 12px 16px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($brands)): ?>
                <tr><td colspan="5" style="padding: 20px; text-align: center; color: #6b7280;">No custom brands created yet. Default brand is Dievon.</td></tr>
            <?php else: ?>
                <?php foreach ($brands as $b): ?>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 14px 16px; color: #9ca3af;">#<?= $b['id'] ?></td>
                        <td style="padding: 14px 16px; font-weight: 600; color: #111827;"><?= htmlspecialchars($b['name']) ?></td>
                        <td style="padding: 14px 16px;"><span style="background: #ecfdf5; color: #065f46; padding: 4px 8px; font-size: 11px; font-weight: 700;"><?= $b['status'] ?></span></td>
                        <td style="padding: 14px 16px; color: #6b7280;"><?= date('M d, Y', strtotime($b['created_at'])) ?></td>
                        <td style="padding: 14px 16px;">
                            <form method="POST" action="brands.php" style="display:inline;" onsubmit="return dvConfirmForm(this,'Delete this brand?');">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="delete" value="<?= $b['id'] ?>">
                                <button type="submit" style="color: #ef4444; font-weight: 600; text-decoration: none; background:none; border:none; cursor:pointer; padding:0; font-size:inherit;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
