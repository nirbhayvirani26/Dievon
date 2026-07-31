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

$activeTab = 'homepage_builder';
$successMsg = '';
$errorMsg   = '';

// Auto create homepage_sections table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `homepage_sections` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `section_key` VARCHAR(100) NOT NULL UNIQUE,
        `title` VARCHAR(255) NOT NULL,
        `eyebrow` VARCHAR(255) DEFAULT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `sort_order` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM homepage_sections")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO homepage_sections (section_key, title, eyebrow, is_active, sort_order) VALUES
            ('hero_slider', 'Hero Banner Slider', 'Volume Collections', 1, 1),
            ('collections', 'Dievon Collections', 'Curated Ensembles', 1, 2),
            ('new_arrivals', 'New Arrivals', 'Latest Creations', 1, 3),
            ('best_sellers', 'Best Sellers & Trending', 'Curated Favorites', 1, 4),
            ('editorial_story', 'The Dievon Atelier Story', 'Craftsmanship & Heritage', 1, 5),
            ('reviews', 'Customer Reviews', 'Verified Ratings & Feedback', 1, 6),
            ('instagram', '@DievonOfficial Instagram', 'Follow Our Atelier', 1, 7),
            ('newsletter', 'VIP Atelier Newsletter', 'Exclusive Offers & Releases', 1, 8)
        ");
    }
} catch (PDOException $e) {}

// Handle Section Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sections'])) {
    try {
        foreach ($_POST['sections'] as $id => $data) {
            $title = trim($data['title'] ?? '');
            $eyebrow = trim($data['eyebrow'] ?? '');
            $isActive = isset($data['is_active']) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE homepage_sections SET title = :title, eyebrow = :eyebrow, is_active = :is_active WHERE id = :id");
            $stmt->execute(['title' => $title, 'eyebrow' => $eyebrow, 'is_active' => $isActive, 'id' => (int)$id]);
        }
        $successMsg = "Homepage section visibility and headings updated successfully!";
    } catch (PDOException $e) {
        $errorMsg = "Error updating sections: " . $e->getMessage();
    }
}

// Handle Toggle
if (isset($_GET['toggle'])) {
    $secId = (int)$_GET['toggle'];
    try {
        $pdo->prepare("UPDATE homepage_sections SET is_active = IF(is_active=1,0,1) WHERE id = :id")->execute(['id' => $secId]);
        $successMsg = "Section visibility toggled.";
    } catch (PDOException $e) {}
}

$sections = [];
try {
    $sections = $pdo->query("SELECT * FROM homepage_sections ORDER BY sort_order ASC, id ASC")->fetchAll();
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
        <h1 class="admin-page-title">🧩 Homepage Section Builder</h1>
        <p class="admin-page-subtitle">Show/hide homepage blocks, customize titles, and manage section order.</p>
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

<form action="homepage_builder.php" method="POST">
    <div class="glass-panel" style="padding:0; overflow:hidden; margin-bottom:24px;">
        <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
            <span>✨ Live Homepage Sections (<?= count($sections) ?>)</span>
            <button type="submit" name="save_sections" class="btn-primary" style="padding:8px 20px; font-size:13px;">
                <i class="fa-solid fa-floppy-disk"></i> Save Section Changes
            </button>
        </div>
        <div class="table-wrapper">
            <table class="data-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="width:60px;">Status</th>
                        <th>Section Name &amp; Key</th>
                        <th>Eyebrow Label</th>
                        <th>Main Title Heading</th>
                        <th style="width:120px; text-align:right;">Quick Toggle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $s): ?>
                        <tr>
                            <td>
                                <span class="badge-luxury" style="background:<?= $s['is_active'] ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $s['is_active'] ? '#10b981' : '#ef4444' ?>;">
                                    <?= $s['is_active'] ? 'VISIBLE' : 'HIDDEN' ?>
                                </span>
                            </td>
                            <td>
                                <strong style="font-size:14px; color:var(--text-primary); display:block;"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s['section_key']))) ?></strong>
                                <code style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($s['section_key']) ?></code>
                            </td>
                            <td>
                                <input type="text" name="sections[<?= $s['id'] ?>][eyebrow]" class="form-control" style="font-size:13px; padding:6px 10px;" value="<?= htmlspecialchars($s['eyebrow'] ?? '') ?>">
                            </td>
                            <td>
                                <input type="text" name="sections[<?= $s['id'] ?>][title]" class="form-control" style="font-size:13px; padding:6px 10px;" value="<?= htmlspecialchars($s['title'] ?? '') ?>">
                            </td>
                            <td style="text-align:right;">
                                <label style="display:inline-flex; align-items:center; cursor:pointer; gap:6px; font-size:12px; font-weight:700; color:var(--text-secondary);">
                                    <input type="checkbox" name="sections[<?= $s['id'] ?>][is_active]" value="1" <?= $s['is_active'] ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-primary);">
                                    Show
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
