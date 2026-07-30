<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

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

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    try {
        $pdo->prepare("DELETE FROM brands WHERE id = :id")->execute(['id' => $delId]);
        $successMsg = "Brand deleted successfully.";
    } catch (PDOException $e) {}
}

$brands = [];
try {
    $brands = $pdo->query("SELECT * FROM brands ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px;">
    <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 16px;">Add New Brand</h3>
    <form action="brands.php" method="POST" style="display: flex; gap: 12px; max-width: 500px;">
        <input type="text" name="name" placeholder="Brand Name (e.g. Dievon Atelier, Gucci)" required style="flex: 1; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
        <button type="submit" name="add_brand" style="background: #111827; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer;">Add Brand</button>
    </form>
</div>

<div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
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
                        <td style="padding: 14px 16px;"><span style="background: #ecfdf5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;"><?= $b['status'] ?></span></td>
                        <td style="padding: 14px 16px; color: #6b7280;"><?= date('M d, Y', strtotime($b['created_at'])) ?></td>
                        <td style="padding: 14px 16px;">
                            <a href="brands.php?delete=<?= $b['id'] ?>" onclick="return confirm('Delete this brand?');" style="color: #ef4444; font-weight: 600; text-decoration: none;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
