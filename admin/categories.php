<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Auto ensure parent_id column exists
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT DEFAULT 0;");
} catch (PDOException $e) {}

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'subcategories') ? 'subcategories' : 'categories';
$isSubTab = ($activeTab === 'subcategories');

$successMsg = '';
$errorMsg   = '';

// Handle POST Add Category / Subcategory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $name = trim($_POST['name'] ?? '');
    $parentId = (int)($_POST['parent_id'] ?? 0);
    
    if ($name !== '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (name, parent_id, sort_order) VALUES (:name, :parent_id, 0)");
            $stmt->execute(['name' => $name, 'parent_id' => $parentId]);
            $successMsg = $parentId > 0 ? "Sub-category '{$name}' created!" : "Category '{$name}' created!";
        } catch (PDOException $e) {
            $errorMsg = "Error: " . $e->getMessage();
        }
    }
}

if (isset($_GET['delete_cat'])) {
    $delId = (int)$_GET['delete_cat'];
    try {
        $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute(['id' => $delId]);
        $successMsg = "Category deleted successfully.";
    } catch (PDOException $e) {}
}

// Fetch categories
$parentCategories = [];
$subCategories = [];
$subGrouped = [];

try {
    $allCatList = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Auto-heal: If no sub-categories exist in DB, create initial sub-categories linked to Kurtis
    $hasSub = false;
    foreach ($allCatList as $c) {
        if ((int)($c['parent_id'] ?? 0) > 0) {
            $hasSub = true;
            break;
        }
    }
    
    if (!$hasSub && !empty($allCatList)) {
        $kurtis = $pdo->query("SELECT id FROM categories WHERE (name LIKE '%Kurti%' OR name LIKE '%Kurta%') AND (parent_id = 0 OR parent_id IS NULL) ORDER BY id ASC LIMIT 1")->fetch();
        if ($kurtis) {
            $kurtisId = (int)$kurtis['id'];
            $defaultSubs = ['Short Kurtis', 'Long Kurtis', 'Anarkali Sets', 'Sharara & Gharara Sets', 'Straight Cut Kurtis'];
            foreach ($defaultSubs as $idx => $sName) {
                $cCheck = $pdo->prepare("SELECT id FROM categories WHERE name = :name");
                $cCheck->execute(['name' => $sName]);
                $foundRow = $cCheck->fetch();
                if (!$foundRow) {
                    $pdo->prepare("INSERT INTO categories (name, parent_id, sort_order) VALUES (:name, :pid, :sort)")
                        ->execute(['name' => $sName, 'pid' => $kurtisId, 'sort' => $idx + 1]);
                } else {
                    $pdo->prepare("UPDATE categories SET parent_id = :pid WHERE id = :id")
                        ->execute(['pid' => $kurtisId, 'id' => $foundRow['id']]);
                }
            }
            $allCatList = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    foreach ($allCatList as $c) {
        $pid = (int)($c['parent_id'] ?? 0);
        if ($pid === 0) {
            $parentCategories[] = $c;
        } else {
            $subCategories[] = $c;
            $subGrouped[$pid][] = $c;
        }
    }
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header">
    <h1 class="admin-page-title">🏷️ Manage Categories &amp; Sub-Categories</h1>
    <p class="admin-page-subtitle">Organize your store catalog into Main Categories and Sub-Categories for your Header Mega Menu</p>
</div>

<?php if (!empty($successMsg)): ?>
<div class="alert alert-success" style="margin-bottom: 20px;">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?>
</div>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
<div class="alert alert-danger" style="margin-bottom: 20px;">
    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
</div>
<?php endif; ?>

<!-- Tab Navigation Switcher -->
<div style="display: flex; gap: 10px; margin-bottom: 24px;">
    <a href="categories.php?tab=categories" class="btn <?= !$isSubTab ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration: none; font-weight: 600;">
        <i class="fa-solid fa-folder"></i> Main Categories (<?= count($parentCategories) ?>)
    </a>
    <a href="categories.php?tab=subcategories" class="btn <?= $isSubTab ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration: none; font-weight: 600;">
        <i class="fa-solid fa-folder-tree"></i> Sub-Categories (<?= count($subCategories) ?>)
    </a>
</div>

<!-- Add Category Box -->
<div class="glass-panel" style="margin-bottom: 24px;">
    <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 16px;">
        <i class="fa-solid fa-plus"></i> Add New <?= $isSubTab ? 'Sub-Category' : 'Main Category' ?>
    </h3>
    
    <form action="categories.php?tab=<?= $activeTab ?>" method="POST" style="display: flex; gap: 12px; align-items: center; max-width: 750px; flex-wrap: wrap;">
        <?php if ($isSubTab): ?>
            <div style="flex: 1; min-width: 220px;">
                <select name="parent_id" class="form-control" required>
                    <option value="">-- Select Parent Category --</option>
                    <?php foreach ($parentCategories as $parent): ?>
                        <option value="<?= $parent['id'] ?>"><?= htmlspecialchars($parent['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" name="parent_id" value="0">
        <?php endif; ?>

        <div style="flex: 2; min-width: 240px;">
            <input type="text" name="name" class="form-control" placeholder="<?= $isSubTab ? 'Sub-category name (e.g. Short Kurtis, Anarkali Sets)' : 'Main Category name (e.g. Kurtis & Kurta Sets)' ?>" required>
        </div>
        
        <button type="submit" name="save_category" class="btn btn-primary" style="padding: 10px 24px; white-space: nowrap;">
            Save <?= $isSubTab ? 'Sub-Category' : 'Category' ?>
        </button>
    </form>
</div>

<!-- Table Card -->
<div class="glass-panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border-light);">
        <h3 style="font-size: 16px; font-weight: 700; margin: 0; text-transform: uppercase; letter-spacing: 0.05em;">
            <?= $isSubTab ? 'Sub-Categories Grouped by Parent Category (' . count($subCategories) . ')' : 'All Main Categories (' . count($parentCategories) . ')' ?>
        </h3>
        <span style="font-size: 12px; color: var(--text-muted);">Switch tabs above to view <?= $isSubTab ? 'Main Categories' : 'Sub-Categories' ?></span>
    </div>
    
    <div class="table-wrapper">
        <?php if ($isSubTab): ?>
            <?php if (empty($subCategories)): ?>
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                    No sub-categories created yet. Use the form above to add your first sub-category!
                </div>
            <?php else: ?>
                <?php foreach ($parentCategories as $parent): 
                    $pId = (int)$parent['id'];
                    $pSubs = $subGrouped[$pId] ?? [];
                ?>
                    <div style="margin-bottom: 24px; border: 1px solid var(--border-light); border-radius: 8px; overflow: hidden; background: #ffffff;">
                        <div style="background: var(--bg-surface-soft); padding: 12px 18px; border-bottom: 1px solid var(--border-light); font-weight: 700; font-size: 13px; text-transform: uppercase; color: var(--color-accent); display: flex; justify-content: space-between; align-items: center;">
                            <span>📁 <?= htmlspecialchars($parent['name']) ?></span>
                            <span class="badge-luxury" style="background: rgba(197,155,75,0.15); color: var(--color-accent); font-size: 11px;">
                                <?= count($pSubs) ?> Sub-Categories
                            </span>
                        </div>
                        
                        <?php if (empty($pSubs)): ?>
                            <div style="padding: 16px 18px; font-size: 13px; color: var(--text-muted); font-style: italic;">
                                No sub-categories assigned to <?= htmlspecialchars($parent['name']) ?> yet.
                            </div>
                        <?php else: ?>
                            <table class="data-table" style="width: 100%; margin: 0;">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">ID</th>
                                        <th>Sub-Category Name</th>
                                        <th style="width: 120px; text-align: right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pSubs as $cat): ?>
                                        <tr>
                                            <td style="color: var(--text-muted); font-weight: 600;">#<?= $cat['id'] ?></td>
                                            <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($cat['name']) ?></td>
                                            <td style="text-align: right;">
                                                <a href="categories.php?tab=<?= $activeTab ?>&delete_cat=<?= $cat['id'] ?>" onclick="return confirm('Delete sub-category &quot;<?= addslashes($cat['name']) ?>&quot;?');" class="btn-sm btn-sm-danger" style="padding: 5px 12px; text-decoration: none;">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php else: ?>
            <!-- Main Categories Table -->
            <table class="data-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Category Name</th>
                        <th style="width: 160px;">Sub-Categories Count</th>
                        <th style="width: 120px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($parentCategories)): ?>
                        <tr>
                            <td colspan="4" style="padding: 30px; text-align: center; color: var(--text-muted);">
                                No main categories created yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($parentCategories as $cat): 
                            $pId = (int)$cat['id'];
                            $pSubCount = count($subGrouped[$pId] ?? []);
                        ?>
                            <tr>
                                <td style="color: var(--text-muted); font-weight: 600;">#<?= $cat['id'] ?></td>
                                <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($cat['name']) ?></td>
                                <td>
                                    <span class="badge-luxury" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); color: var(--text-primary); font-size: 11px;">
                                        <?= $pSubCount ?> Sub-Categories
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <a href="categories.php?tab=<?= $activeTab ?>&delete_cat=<?= $cat['id'] ?>" onclick="return confirm('Delete main category &quot;<?= addslashes($cat['name']) ?>&quot;?');" class="btn-sm btn-sm-danger" style="padding: 5px 12px; text-decoration: none;">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
