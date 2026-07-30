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

$activeTab = 'banners';
$successMsg = '';
$errorMsg   = '';

// Auto create banners table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `banners` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(255) NOT NULL,
        `subtitle` TEXT DEFAULT NULL,
        `image` VARCHAR(255) DEFAULT NULL,
        `link` VARCHAR(255) DEFAULT 'shop',
        `status` ENUM('Active','Inactive') DEFAULT 'Active',
        `sort_order` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM banners")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO banners (title, subtitle, link, status, image, sort_order) VALUES
            ('The Silk Kurtis', 'Volume I — Fluid silhouettes draped in pure heavy mulberry silk.', 'shop?category=Kurtis', 'Active', 'lookbook_1.png', 1),
            ('3-Piece Suits', 'Volume II — Elegant tailored brocades and soft raw silks.', 'shop?category=3+Piece+Suits', 'Active', 'lookbook_2.png', 2),
            ('Coord Sets', 'Volume III — Luxurious satin wide-legs and tailored organic linens.', 'shop?category=Coord+Sets', 'Active', 'lookbook_3.png', 3)
        ");
    }
} catch (PDOException $e) {}

// Edit Mode detection
$editBanner = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM banners WHERE id = :id");
        $stmt->execute(['id' => $editId]);
        $editBanner = $stmt->fetch();
    } catch (PDOException $e) {}
}

// Handle Add or Edit Banner Slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banner'])) {
    $bannerId = (int)($_POST['banner_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $link     = trim($_POST['link'] ?? 'shop');
    $status   = $_POST['status'] ?? 'Active';
    $imgName  = $_POST['existing_image'] ?? '';

    // Handle new image upload
    if (!empty($_FILES['banner_image']['name'])) {
        $file    = $_FILES['banner_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($file['type'], $allowed) && $file['size'] <= 8 * 1024 * 1024) {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = 'banner_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destDir  = __DIR__ . '/../uploads/products/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }
            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                $imgName = $filename;
            }
        }
    }

    if ($title) {
        try {
            if ($bannerId > 0) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE banners SET title = :title, subtitle = :subtitle, image = :image, link = :link, status = :status WHERE id = :id");
                $stmt->execute(['title' => $title, 'subtitle' => $subtitle, 'image' => $imgName, 'link' => $link, 'status' => $status, 'id' => $bannerId]);
                $successMsg = "Banner slide '{$title}' updated successfully!";
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO banners (title, subtitle, image, link, status) VALUES (:title, :subtitle, :image, :link, :status)");
                $stmt->execute(['title' => $title, 'subtitle' => $subtitle, 'image' => $imgName, 'link' => $link, 'status' => $status]);
                $successMsg = "New banner slide '{$title}' added successfully!";
            }
            $editBanner = null; // Clear edit mode after save
        } catch (PDOException $e) {
            $errorMsg = "Error saving banner: " . $e->getMessage();
        }
    }
}

// Handle Status Toggle (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_banner') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed (Invalid CSRF token).";
    } else {
        $toggleId = (int)($_POST['banner_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE banners SET status = IF(status='Active','Inactive','Active') WHERE id = :id")->execute(['id' => $toggleId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'toggle_banner', "Toggled banner status ID $toggleId");
            $successMsg = "Banner status updated.";
        } catch (PDOException $e) {}
    }
}

// Handle Delete (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_banner') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed (Invalid CSRF token).";
    } else {
        $delId = (int)($_POST['banner_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM banners WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_banner', "Deleted banner ID $delId");
            $successMsg = "Banner slide deleted successfully.";
        } catch (PDOException $e) {}
    }
}

$banners = [];
try {
    $banners = $pdo->query("SELECT * FROM banners ORDER BY sort_order ASC, id DESC")->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">🖼️ Homepage Banner Slider Manager</h1>
        <p class="admin-page-subtitle">Manage hero slider banners, titles, background images, and call-to-action links.</p>
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

<!-- Add / Edit Banner Form -->
<div class="glass-panel form-section" style="margin-bottom:28px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="font-size:16px; font-weight:700; color:var(--text-primary); margin:0;">
            <?= $editBanner ? '✏️ Edit Banner Slide #' . $editBanner['id'] : '➕ Add New Banner Slide' ?>
        </h3>
        <?php if ($editBanner): ?>
            <a href="banners.php" style="font-size:12px; color:var(--color-primary); font-weight:700; text-decoration:none;">Cancel Edit</a>
        <?php endif; ?>
    </div>

    <form action="banners.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="banner_id" value="<?= $editBanner['id'] ?? 0 ?>">
        <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editBanner['image'] ?? '') ?>">

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Banner Title *</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Royal Silk Edition" value="<?= htmlspecialchars($editBanner['title'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Eyebrow / Subtitle</label>
                <input type="text" name="subtitle" class="form-control" placeholder="e.g. Volume IV — Draped in heavy mulberry silk" value="<?= htmlspecialchars($editBanner['subtitle'] ?? '') ?>">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Button Link URL</label>
                <input type="text" name="link" class="form-control" placeholder="e.g. shop?category=Kurtis" value="<?= htmlspecialchars($editBanner['link'] ?? 'shop') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="Active" <?= ($editBanner['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= ($editBanner['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Upload New Background Image</label>
                <input type="file" name="banner_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                <?php if ($editBanner && !empty($editBanner['image'])): ?>
                    <span style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Current image: <code><?= htmlspecialchars($editBanner['image']) ?></code></span>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" name="save_banner" class="btn-primary" style="padding:10px 24px; font-size:14px;">
            <i class="fa-solid fa-floppy-disk"></i> <?= $editBanner ? 'Save Changes' : 'Add Slide' ?>
        </button>
    </form>
</div>

<!-- Banners List Table -->
<div class="glass-panel" style="padding:0; overflow:hidden;">
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary);">
        🖼️ Active Homepage Hero Banners (<?= count($banners) ?>)
    </div>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:70px;">Preview</th>
                    <th>Title &amp; Subtitle</th>
                    <th>Button Link</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:160px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($banners)): ?>
                    <tr><td colspan="5" style="padding:30px; text-align:center; color:var(--text-muted);">No banners configured yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($banners as $b): 
                        $imgSrc = '';
                        if (!empty($b['image'])) {
                            if (file_exists(__DIR__ . '/../uploads/products/' . $b['image'])) {
                                $imgSrc = '../uploads/products/' . htmlspecialchars($b['image']);
                            } elseif (file_exists(__DIR__ . '/../uploads/gallery/' . $b['image'])) {
                                $imgSrc = '../uploads/gallery/' . htmlspecialchars($b['image']);
                            }
                        }
                    ?>
                        <tr>
                            <td>
                                <?php if ($imgSrc): ?>
                                    <img src="<?= $imgSrc ?>" style="width:60px; height:45px; object-fit:cover; border-radius:4px; border:1px solid var(--border-light);">
                                <?php else: ?>
                                    <div style="width:60px; height:45px; background:var(--bg-surface-soft); border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:18px;">✨</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="font-size:15px; color:var(--text-primary); display:block;"><?= htmlspecialchars($b['title']) ?></strong>
                                <span style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($b['subtitle']) ?></span>
                            </td>
                            <td>
                                <code style="font-size:12px; background:var(--bg-surface-soft); padding:4px 8px; border-radius:4px; color:var(--color-primary);"><?= htmlspecialchars($b['link']) ?></code>
                            </td>
                            <td>
                                <a href="banners.php?toggle=<?= $b['id'] ?>" style="text-decoration:none;">
                                    <span class="badge-luxury" style="background:<?= $b['status']==='Active' ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $b['status']==='Active' ? '#10b981' : '#ef4444' ?>; cursor:pointer;">
                                        <?= $b['status'] ?>
                                    </span>
                                </a>
                            </td>
                            <td style="text-align:right;">
                                <a href="banners.php?edit=<?= $b['id'] ?>" class="btn-sm btn-sm-primary" style="padding:6px 12px; text-decoration:none; margin-right:6px;">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </a>
                                <a href="banners.php?delete=<?= $b['id'] ?>" onclick="return confirm('Delete this banner slide?');" class="btn-sm btn-sm-danger" style="padding:6px 12px; text-decoration:none;">
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
