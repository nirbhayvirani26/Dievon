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

$activeTab = 'blog';
$successMsg = '';
$errorMsg   = '';

// Auto create blog_posts table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `blog_posts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(255) NOT NULL,
        `slug` VARCHAR(255) NOT NULL UNIQUE,
        `category` VARCHAR(100) DEFAULT 'Style Guide',
        `image` VARCHAR(255) DEFAULT NULL,
        `excerpt` TEXT DEFAULT NULL,
        `content` LONGTEXT NOT NULL,
        `tags` VARCHAR(255) DEFAULT NULL,
        `meta_title` VARCHAR(255) DEFAULT NULL,
        `meta_description` TEXT DEFAULT NULL,
        `status` ENUM('Published','Draft') DEFAULT 'Published',
        `published_date` DATE DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO blog_posts (title, slug, category, image, excerpt, content, tags, status, published_date) VALUES
            ('Summer Silk Draping: A Study in Elegance', 'summer-silk-draping-elegance', 'Style Guide', 'lookbook_3.png', 'How to style, fit, and accessorize our signature silk evening slip dress for summer soirées.', 'Draping is an ancient textile art form that speaks without words. In this chronicle, we explore the fluid movement and weights of pure organic mulberry silk. Our designers spent months researching Como silk weavers to formulate the precise weight profile of our Aurelia slip gown.\n\nTo accessorize our signature silk slip dress for summer soirée invitations, we suggest keeping things minimalist yet impactful. A delicate gold herringbone chain and a hand-selected baroque freshwater pearl suspended from a thin link will highlight the delicate cowl neckline.', 'silk, summer, style guide, evening wear', 'Published', '2026-07-15'),
            ('Calfskin Sourcing: Preserving the Tuscan Heritage', 'calfskin-sourcing-tuscan-heritage', 'Atelier Craftsmanship', 'lookbook_2.png', 'A tour inside our leather ateliers in Florence, where raw leather meets gold hardware.', 'Tuscany is famous across the globe for its leatherwork heritage, but what sets the Elysian leather handbag apart? It starts at the selection tables, where our master artisans source only full-grain calfskins showing dense, uniform grain.\n\nVegetable tanning processes follow, utilising oak bark and chestnut extracts in a slow, 30-day barrel tanning cycle. This traditional, chemical-free method preserves the organic texture of the hides.', 'leather, florence, craftsmanship, luxury bags', 'Published', '2026-07-10'),
            ('The Autumn Silhouette Sneak Preview', 'autumn-silhouette-sneak-preview', 'Editorial', 'lookbook_1.png', 'A sneak peek at next season\'s cashmere double-breasted trench coats and blazers.', 'As the warm coastal winds of summer mature, our design desk in Paris turns its focus to structural tailoring. The upcoming Autumn Collection centers on Virgin Wool and Cashmere outer layers.\n\nOur signature double-breasted trench coat is woven in Biella, Italy from a heavy, double-faced cashmere virgin wool blend.', 'autumn, trench coat, cashmere, paris', 'Published', '2026-07-01')
        ");
    }
} catch (PDOException $e) {}

// Edit Mode detection
$editPost = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = :id");
        $stmt->execute(['id' => $editId]);
        $editPost = $stmt->fetch();
    } catch (PDOException $e) {}
}

// Handle Save Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post'])) {
    $postId   = (int)($_POST['post_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'Style Guide');
    $excerpt  = trim($_POST['excerpt'] ?? '');
    $content  = trim($_POST['content'] ?? '');
    $tags     = trim($_POST['tags'] ?? '');
    $metaT    = trim($_POST['meta_title'] ?? '');
    $metaD    = trim($_POST['meta_description'] ?? '');
    $status   = $_POST['status'] ?? 'Published';
    $imgName  = $_POST['existing_image'] ?? '';

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    if (!$slug) $slug = 'post-' . time();

    if (!empty($_FILES['post_image']['name'])) {
        $file    = $_FILES['post_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($file['type'], $allowed) && $file['size'] <= 8 * 1024 * 1024) {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = $slug . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destDir  = __DIR__ . '/../uploads/products/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }
            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                generateWebpCopy($destDir . $filename);
                $imgName = $filename;
            }
        }
    }

    if ($title && $content) {
        try {
            if ($postId > 0) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE blog_posts SET title = :title, slug = :slug, category = :category, image = :image, excerpt = :excerpt, content = :content, tags = :tags, meta_title = :meta_title, meta_description = :meta_description, status = :status WHERE id = :id");
                $stmt->execute(['title' => $title, 'slug' => $slug, 'category' => $category, 'image' => $imgName, 'excerpt' => $excerpt, 'content' => $content, 'tags' => $tags, 'meta_title' => $metaT, 'meta_description' => $metaD, 'status' => $status, 'id' => $postId]);
                $successMsg = "Blog article '{$title}' updated successfully!";
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO blog_posts (title, slug, category, image, excerpt, content, tags, meta_title, meta_description, status, published_date) VALUES (:title, :slug, :category, :image, :excerpt, :content, :tags, :meta_title, :meta_description, :status, :published_date)");
                $stmt->execute(['title' => $title, 'slug' => $slug, 'category' => $category, 'image' => $imgName, 'excerpt' => $excerpt, 'content' => $content, 'tags' => $tags, 'meta_title' => $metaT, 'meta_description' => $metaD, 'status' => $status, 'published_date' => date('Y-m-d')]);
                $successMsg = "New blog article '{$title}' published successfully!";
            }
            $editPost = null;
        } catch (PDOException $e) {
            $errorMsg = "Error saving article: " . $e->getMessage();
        }
    }
}

// Handle Delete (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_blog') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed (Invalid CSRF token).";
    } else {
        $delId = (int)($_POST['blog_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM blog_posts WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_blog', "Deleted blog post ID $delId");
            $successMsg = "Blog article deleted.";
        } catch (PDOException $e) {}
    }
}

// Handle Status Toggle (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_blog') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed (Invalid CSRF token).";
    } else {
        $toggleId = (int)($_POST['blog_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE blog_posts SET status = IF(status='Published','Draft','Published') WHERE id = :id")->execute(['id' => $toggleId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'toggle_blog', "Toggled blog status ID $toggleId");
            $successMsg = "Post status updated.";
        } catch (PDOException $e) {}
    }
}

$posts = [];
try {
    $posts = $pdo->query("SELECT * FROM blog_posts ORDER BY published_date DESC, id DESC")->fetchAll();
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
        <h1 class="admin-page-title">📰 Blog &amp; Journal Manager</h1>
        <p class="admin-page-subtitle">Publish editorial articles, style guides, festival fashion tips, and atelier news.</p>
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

<!-- Add / Edit Article Form -->
<div class="glass-panel form-section" style="margin-bottom:28px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="font-size:16px; font-weight:700; color:var(--text-primary); margin:0;">
            <?= $editPost ? '✏️ Edit Article #' . $editPost['id'] : '➕ Create New Blog Article' ?>
        </h3>
        <?php if ($editPost): ?>
            <a href="blog.php" style="font-size:12px; color:var(--color-primary); font-weight:700; text-decoration:none;">Cancel Edit</a>
        <?php endif; ?>
    </div>

    <form action="blog.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="post_id" value="<?= $editPost['id'] ?? 0 ?>">
        <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editPost['image'] ?? '') ?>">

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Article Title *</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Festival Silk Kurtis: 5 Styling Secrets" value="<?= htmlspecialchars($editPost['title'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <option value="Style Guide" <?= ($editPost['category'] ?? '') === 'Style Guide' ? 'selected' : '' ?>>Style Guide</option>
                    <option value="Festival Fashion" <?= ($editPost['category'] ?? '') === 'Festival Fashion' ? 'selected' : '' ?>>Festival Fashion</option>
                    <option value="Atelier Craftsmanship" <?= ($editPost['category'] ?? '') === 'Atelier Craftsmanship' ? 'selected' : '' ?>>Atelier Craftsmanship</option>
                    <option value="Editorial" <?= ($editPost['category'] ?? '') === 'Editorial' ? 'selected' : '' ?>>Editorial</option>
                    <option value="Latest Trends" <?= ($editPost['category'] ?? '') === 'Latest Trends' ? 'selected' : '' ?>>Latest Trends</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Cover Image</label>
                <input type="file" name="post_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                <?php if ($editPost && !empty($editPost['image'])): ?>
                    <span style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Current cover: <code><?= htmlspecialchars($editPost['image']) ?></code></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Category Tags (Comma Separated)</label>
                <input type="text" name="tags" class="form-control" placeholder="e.g. silk, kurtis, festival, style guide" value="<?= htmlspecialchars($editPost['tags'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Publish Status</label>
                <select name="status" class="form-control">
                    <option value="Published" <?= ($editPost['status'] ?? 'Published') === 'Published' ? 'selected' : '' ?>>Published</option>
                    <option value="Draft" <?= ($editPost['status'] ?? '') === 'Draft' ? 'selected' : '' ?>>Draft</option>
                </select>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Short Excerpt / Summary</label>
            <input type="text" name="excerpt" class="form-control" placeholder="A 1-2 sentence preview summary displayed on the blog list grid." value="<?= htmlspecialchars($editPost['excerpt'] ?? '') ?>">
        </div>

        <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Full Article Body Content *</label>
            <textarea name="content" class="form-control" rows="8" placeholder="Write full article body text..." required><?= htmlspecialchars($editPost['content'] ?? '') ?></textarea>
        </div>

        <!-- SEO Fields -->
        <div style="background:var(--bg-surface-soft); padding:16px; border-radius:6px; border:1px solid var(--border-light); margin-bottom:20px;">
            <strong style="font-size:13px; color:var(--color-primary); text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:12px;">🌐 Article SEO Overrides (Optional)</strong>
            <div class="form-row">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Google Meta Title</label>
                    <input type="text" name="meta_title" class="form-control" placeholder="Custom Google title" value="<?= htmlspecialchars($editPost['meta_title'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Google Meta Description</label>
                    <input type="text" name="meta_description" class="form-control" placeholder="Custom Google description" value="<?= htmlspecialchars($editPost['meta_description'] ?? '') ?>">
                </div>
            </div>
        </div>

        <button type="submit" name="save_post" class="btn-primary" style="padding:10px 24px; font-size:14px;">
            <i class="fa-solid fa-paper-plane"></i> <?= $editPost ? 'Save Changes' : 'Publish Article' ?>
        </button>
    </form>
</div>

<!-- Articles Table List -->
<div class="glass-panel" style="padding:0; overflow:hidden;">
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary);">
        📰 Published Journal Articles (<?= count($posts) ?>)
    </div>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:70px;">Cover</th>
                    <th>Article Title &amp; Excerpt</th>
                    <th>Category</th>
                    <th>Date</th>
                    <th style="width:90px;">Status</th>
                    <th style="width:150px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($posts)): ?>
                    <tr><td colspan="6" style="padding:30px; text-align:center; color:var(--text-muted);">No blog articles published yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($posts as $p): 
                        $imgSrc = '';
                        if (!empty($p['image'])) {
                            if (file_exists(__DIR__ . '/../uploads/products/' . $p['image'])) {
                                $imgSrc = '../uploads/products/' . htmlspecialchars($p['image']);
                            } elseif (file_exists(__DIR__ . '/../uploads/gallery/' . $p['image'])) {
                                $imgSrc = '../uploads/gallery/' . htmlspecialchars($p['image']);
                            }
                        }
                    ?>
                        <tr>
                            <td>
                                <?php if ($imgSrc): ?>
                                    <img src="<?= $imgSrc ?>" style="width:55px; height:40px; object-fit:cover; border-radius:4px; border:1px solid var(--border-light);">
                                <?php else: ?>
                                    <div style="width:55px; height:40px; background:var(--bg-surface-soft); border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:16px;">📰</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="font-size:14px; color:var(--text-primary); display:block;"><?= htmlspecialchars($p['title']) ?></strong>
                                <span style="font-size:12px; color:var(--text-muted); max-width:350px; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($p['excerpt']) ?></span>
                            </td>
                            <td><span style="font-size:12px; font-weight:700; background:var(--bg-surface-soft); padding:4px 8px; border-radius:4px; color:var(--color-primary);"><?= htmlspecialchars($p['category']) ?></span></td>
                            <td style="font-size:12px; color:var(--text-muted);"><?= date('d M Y', strtotime($p['published_date'])) ?></td>
                            <td>
                                <a href="blog.php?toggle=<?= $p['id'] ?>" style="text-decoration:none;">
                                    <span class="badge-luxury" style="background:<?= $p['status']==='Published' ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $p['status']==='Published' ? '#10b981' : '#ef4444' ?>; cursor:pointer;">
                                        <?= $p['status'] ?>
                                    </span>
                                </a>
                            </td>
                            <td style="text-align:right;">
                                <a href="blog.php?edit=<?= $p['id'] ?>" class="btn-sm btn-sm-primary" style="padding:4px 10px; text-decoration:none; margin-right:4px;">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </a>
                                <a href="blog.php?delete=<?= $p['id'] ?>" onclick="return dvConfirmLink(this,'Delete this article?');" class="btn-sm btn-sm-danger" style="padding:4px 10px; text-decoration:none;">
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
