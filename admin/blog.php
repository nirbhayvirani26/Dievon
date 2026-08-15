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
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/blog_content.php';
requireAdminCapability('content.manage');


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

    // How this article's body is stored, recorded rather than guessed.
    //
    // Existing rows default to 'text', which is what they are: every article
    // written before the visual editor is plain text with "## " headings.
    // Guessing from the bytes instead was actively destructive — a plain-text
    // sentence like "anything under <a lakh is a steal" reads as an HTML tag,
    // and re-saving that post deleted the words from the database for good.
    try {
        $pdo->exec("ALTER TABLE `blog_posts`
                    ADD COLUMN `content_format` ENUM('text','html') NOT NULL DEFAULT 'text' AFTER `content`");
    } catch (PDOException $e) {}

    // Which side of the shop an article belongs to.
    //
    // Every product query on the homepage is already filtered by audience, but
    // the article query was not — so a man browsing /men saw womenswear
    // articles, illustrated with photographs of women. DEFAULT 'women' is
    // correct for every article written so far; nothing changes until an
    // article is deliberately marked otherwise.
    try {
        $pdo->exec("ALTER TABLE `blog_posts`
                    ADD COLUMN `gender` ENUM('women','men','both') NOT NULL DEFAULT 'women' AFTER `category`");
    } catch (PDOException $e) {}

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO blog_posts (title, slug, category, gender, image, excerpt, content, content_format, tags, status, published_date) VALUES
            ('Summer Silk Draping: A Study in Elegance', 'summer-silk-draping-elegance', 'Style Guide', 'women', 'lookbook_3.png', 'How to style, fit, and accessorize our signature silk evening slip dress for summer soirées.', 'Draping is an ancient textile art form that speaks without words. In this chronicle, we explore the fluid movement and weights of pure organic mulberry silk. Our designers spent months researching Como silk weavers to formulate the precise weight profile of our Aurelia slip gown.\n\nTo accessorize our signature silk slip dress for summer soirée invitations, we suggest keeping things minimalist yet impactful. A delicate gold herringbone chain and a hand-selected baroque freshwater pearl suspended from a thin link will highlight the delicate cowl neckline.', 'text', 'silk, summer, style guide, evening wear', 'Published', '2026-07-15'),
            ('Calfskin Sourcing: Preserving the Tuscan Heritage', 'calfskin-sourcing-tuscan-heritage', 'Atelier Craftsmanship', 'women', 'lookbook_2.png', 'A tour inside our leather ateliers in Florence, where raw leather meets gold hardware.', 'Tuscany is famous across the globe for its leatherwork heritage, but what sets the Elysian leather handbag apart? It starts at the selection tables, where our master artisans source only full-grain calfskins showing dense, uniform grain.\n\nVegetable tanning processes follow, utilising oak bark and chestnut extracts in a slow, 30-day barrel tanning cycle. This traditional, chemical-free method preserves the organic texture of the hides.', 'text', 'leather, florence, craftsmanship, luxury bags', 'Published', '2026-07-10'),
            ('The Autumn Silhouette Sneak Preview', 'autumn-silhouette-sneak-preview', 'Editorial', 'women', 'lookbook_1.png', 'A sneak peek at next season\'s cashmere double-breasted trench coats and blazers.', 'As the warm coastal winds of summer mature, our design desk in Paris turns its focus to structural tailoring. The upcoming Autumn Collection centers on Virgin Wool and Cashmere outer layers.\n\nOur signature double-breasted trench coat is woven in Biella, Italy from a heavy, double-faced cashmere virgin wool blend.', 'text', 'autumn, trench coat, cashmere, paris', 'Published', '2026-07-01')
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

// Handle Save Post (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post']) && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $errorMsg = "Security validation failed (Invalid CSRF token).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post'])) {
    $postId   = (int)($_POST['post_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'Style Guide');
    $excerpt  = trim($_POST['excerpt'] ?? '');
    // The editor declares its own format in a hidden field; anything else — a
    // browser with JavaScript off, an old bookmarked form — is plain text.
    //
    // The format is NOT inferred from the body. Sniffing for a '<' sent legacy
    // plain-text articles through the HTML sanitiser, which parsed ordinary
    // sentences as tags and deleted the words permanently on save.
    $content  = trim($_POST['content'] ?? '');
    $format   = ($_POST['content_format'] ?? '') === 'html' ? 'html' : 'text';

    // Only HTML is rebuilt from the allowlist. Plain text is stored as typed and
    // escaped at render time, exactly as it always has been.
    $contentBefore = $content;
    if ($format === 'html' && $content !== '') {
        $content = blogSanitizeHtml($content);
    }
    $tags     = trim($_POST['tags'] ?? '');
    $metaT    = trim($_POST['meta_title'] ?? '');
    $metaD    = trim($_POST['meta_description'] ?? '');
    // status is written straight into an ENUM('Published','Draft'). Anything else
    // is coerced by the database rather than refused, so it is whitelisted here
    // for the same reason gender below is.
    $status   = ($_POST['status'] ?? 'Published') === 'Draft' ? 'Draft' : 'Published';
    // Audience. Anything unrecognised falls back to womenswear, which is what
    // every article was before this field existed.
    $artGender = strtolower(trim((string)($_POST['gender'] ?? 'women')));
    if (!in_array($artGender, ['women','men','both'], true)) { $artGender = 'women'; }
    $imgName  = $_POST['existing_image'] ?? '';

    /* Lengths are checked HERE rather than being left to the column definition.
       Every one of these fields went into the INSERT unmeasured, so a title of
       256 characters — ordinary for a long editorial headline — came back as
       "Error saving article: SQLSTATE[22001]: String data, right truncated:
       1406 Data too long for column 'title' at row 1". That is the raw driver
       message printed to the screen: it names the table column, it tells the
       author nothing they can act on, and it discloses schema detail to anyone
       who can reach this form. The same applies to excerpt, tags and the two
       SEO overrides, which are all VARCHARs of various sizes.
       Measured with mb_strlen, not strlen: the columns count CHARACTERS, and a
       Devanagari or accented headline is several bytes per character, so a byte
       count would refuse titles the database would have accepted. */
    $blogLimits = [
        'Title'            => [$title,  255],
        'Category'         => [$category, 100],
        'Tags'             => [$tags,   255],
        'Meta title'       => [$metaT,  255],
    ];
    foreach ($blogLimits as $fieldLabel => [$fieldValue, $fieldMax]) {
        if (mb_strlen((string)$fieldValue) > $fieldMax) {
            $errorMsg = $fieldLabel . ' is too long — keep it to ' . $fieldMax
                      . ' characters or fewer. Nothing was saved.';
            break;
        }
    }

    if ($errorMsg === '') {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        if (!$slug) $slug = 'post-' . time();
        // The slug column is UNIQUE, and nothing checked it before the write.
        // Two articles sharing a title — "Autumn Edit", written a year apart —
        // produced the same slug, so the second save died on a duplicate-key
        // error that was printed verbatim to the author. A title made only of
        // non-Latin characters is worse: every one of them reduces to the same
        // 'post-<unix second>' fallback above, so two of them saved in the same
        // second collide as well.
        // Suffixing is the same approach roles_handler.php already uses when it
        // derives a role slug, so both screens behave alike.
        $slug = mb_substr($slug, 0, 240);
        try {
            $slugChk = $pdo->prepare(
                "SELECT COUNT(*) FROM blog_posts WHERE slug = :s AND id <> :id"
            );
            $slugBase = $slug;
            $slugN    = 2;
            while (true) {
                $slugChk->execute([':s' => $slug, ':id' => $postId]);
                if ((int)$slugChk->fetchColumn() === 0) { break; }
                $slug = $slugBase . '-' . $slugN++;
                if ($slugN > 200) { $slug = $slugBase . '-' . bin2hex(random_bytes(3)); break; }
            }
        } catch (PDOException $eSlug) { /* pre-migration: keep the derived slug */ }
    }

    // Superseded image, deleted only after the save commits.
    $blogStaleImage = '';

    if (!empty($_FILES['post_image']['name'])) {
        $file    = $_FILES['post_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        // MIME is read from the file CONTENTS (finfo), never from the client-sent
        // header — $file['type'] is a string anyone can forge, and this uploader
        // used to trust it, so a PHP payload renamed post.php and labelled
        // "image/png" sailed straight through into a web-served folder.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
        finfo_close($finfo);
        if (in_array($mime, $allowed, true) && $file['size'] <= 8 * 1024 * 1024) {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = $slug . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            // Article pictures get their own folder.
            //
            // They used to be written into uploads/products/ alongside product
            // photography, banner artwork and size-guide diagrams. Nothing in
            // the filename said which was which, so clearing out product images
            // — a perfectly reasonable thing to do after deleting products —
            // took every article picture with them. That is exactly what
            // happened on the live site.
            //
            // Reading still falls back to uploads/products/ (see blogImageUrl),
            // so articles uploaded before this keep working.
            $destDir  = __DIR__ . '/../uploads/blog/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }
            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                // Resize/re-compress before the WebP is made, so the delivery copy is
                // generated from the already-optimised original rather than the raw upload.
                optimizeUploadedImage($destDir . $filename);
                generateWebpCopy($destDir . $filename);
                // Queue the superseded image; removed after the save succeeds so a
                // failed update cannot destroy the picture still on the live post.
                if ($postId > 0) {
                    $prev = $pdo->prepare("SELECT image FROM blog_posts WHERE id = :id");
                    $prev->execute(['id' => $postId]);
                    $prevImg = (string)$prev->fetchColumn();
                    if ($prevImg !== '' && $prevImg !== $filename) { $blogStaleImage = $prevImg; }
                }
                $imgName = $filename;
            }
        }
    }

    // A save that goes nowhere used to say nothing at all.
    //
    // There was no else branch here, so when the body was empty the form came
    // back with neither a success nor an error message. On an existing post
    // that is worse than it sounds: this one statement writes the title,
    // category, image, excerpt, tags and SEO fields too, so every edit made in
    // the same submission was discarded silently alongside it.
    if ($errorMsg === '' && (!$title || !$content)) {
        if ($title && $contentBefore !== '' && $content === '') {
            $errorMsg = 'Nothing was saved. The article body contained only formatting '
                      . '(no text), so there was nothing to publish.';
        } else {
            $errorMsg = 'Nothing was saved. Both a title and an article body are required.';
        }
    }

    // $errorMsg in the condition, so a field that failed the length check above
    // does not go on to be written anyway.
    if ($errorMsg === '' && $title && $content) {
        try {
            if ($postId > 0) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE blog_posts SET title = :title, slug = :slug, category = :category, gender = :gender, image = :image, excerpt = :excerpt, content = :content, content_format = :content_format, tags = :tags, meta_title = :meta_title, meta_description = :meta_description, status = :status WHERE id = :id");
                $stmt->execute(['title' => $title, 'slug' => $slug, 'category' => $category, 'gender' => $artGender, 'image' => $imgName, 'excerpt' => $excerpt, 'content' => $content, 'content_format' => $format, 'tags' => $tags, 'meta_title' => $metaT, 'meta_description' => $metaD, 'status' => $status, 'id' => $postId]);
                $successMsg = "Blog article '{$title}' updated successfully!";
                // Save committed — now the replaced image can go.
                if (!empty($blogStaleImage)) {
                    // Try both folders: pictures uploaded before the blog had its own live
                    // in uploads/products/, newer ones in uploads/blog/.
                    foreach (['blog', 'products'] as $bDir) {
                        deleteUploadedFileIfUnused($pdo, $blogStaleImage, __DIR__ . '/../uploads/' . $bDir . '/');
                    }
                }
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO blog_posts (title, slug, category, gender, image, excerpt, content, content_format, tags, meta_title, meta_description, status, published_date) VALUES (:title, :slug, :category, :gender, :image, :excerpt, :content, :content_format, :tags, :meta_title, :meta_description, :status, :published_date)");
                $stmt->execute(['title' => $title, 'slug' => $slug, 'category' => $category, 'gender' => $artGender, 'image' => $imgName, 'excerpt' => $excerpt, 'content' => $content, 'content_format' => $format, 'tags' => $tags, 'meta_title' => $metaT, 'meta_description' => $metaD, 'status' => $status, 'published_date' => date('Y-m-d')]);
                $successMsg = "New blog article '{$title}' published successfully!";
            }
            $editPost = null;
            logAdminAction($_SESSION['admin_id'] ?? 1,
                $postId > 0 ? 'update_blog' : 'create_blog',
                ($postId > 0 ? "Updated blog post ID $postId" : 'Created blog post')
                . " ('{$title}', {$status})");
        } catch (PDOException $e) {
            // The driver's own words used to be printed straight to the page.
            // They named the table and column on a length overflow and quoted the
            // clashing value on a duplicate key — schema detail on a screen an
            // Editor can open, and nothing an author could act on either way.
            // Logged for whoever maintains the shop, summarised for whoever is
            // writing the article.
            error_log('blog save: ' . $e->getMessage());
            $errorMsg = 'The article could not be saved. Nothing was changed — '
                      . 'check the title and try again.';
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
            // Reclaim the image. uploads/products/ is shared with products, banners
            // and size guides, so deleteUploadedFileIfUnused() checks every
            // file-naming column and only unlinks when nothing else names the file.
            $old = $pdo->prepare("SELECT image FROM blog_posts WHERE id = :id");
            $old->execute(['id' => $delId]);
            $oldImg = (string)$old->fetchColumn();

            $pdo->prepare("DELETE FROM blog_posts WHERE id = :id")->execute(['id' => $delId]);

            if ($oldImg !== '') {
                foreach (['blog', 'products'] as $bDir) {
                    deleteUploadedFileIfUnused($pdo, $oldImg, __DIR__ . '/../uploads/' . $bDir . '/');
                }
            }
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
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
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
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
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
                <?php
                    // The picture, not just its filename.
                    //
                    // blogImageUrl() is used rather than a hand-built path so this
                    // preview follows exactly what the storefront shows — including
                    // the rule that a lookbook_* filename means "this article has no
                    // cover of its own" and falls back to the default. Building the
                    // path here instead would have shown a lookbook photograph the
                    // article does not actually use.
                    $bpUrl      = $editPost && !empty($editPost['image']) ? blogImageUrl($editPost['image']) : '';
                    $bpIsFallbk = $bpUrl !== '' && strpos($bpUrl, 'blog_default') !== false;
                ?>
                <?php if ($editPost): ?>
                    <div style="margin-top:10px;">
                        <?php if ($bpUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($bpUrl) ?>" alt="Current cover image"
                                 style="max-width:220px; width:100%; height:auto; border:1px solid var(--border-light); border-radius:6px; display:block;">
                        <?php endif; ?>
                        <?php if ($bpIsFallbk): ?>
                            <div style="margin-top:6px; padding:9px 11px; background:#fff7ed; border:1px solid #fed7aa; border-radius:6px; font-size:12px; color:#9a3412;">
                                This article has <strong>no cover picture of its own</strong> — the shared default is
                                shown above, and on the Journal page and in every shared link. Upload one below.
                            </div>
                        <?php elseif (!empty($editPost['image'])): ?>
                            <span style="font-size:11px; color:var(--text-muted); margin-top:6px; display:block;">
                                Current cover: <code><?= htmlspecialchars($editPost['image']) ?></code>
                                &middot; leave the upload box empty to keep it
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php // Same three options and the same wording as the banner screen,
                  // so one idea is not described two different ways in one admin. ?>
            <?php $bgArticle = $editPost['gender'] ?? 'women'; ?>
            <div class="form-group">
                <label class="form-label">Show To</label>
                <select name="gender" class="form-control">
                    <option value="women" <?= $bgArticle === 'women' ? 'selected' : '' ?>>Women only</option>
                    <option value="men"   <?= $bgArticle === 'men'   ? 'selected' : '' ?>>Men only</option>
                    <option value="both"  <?= $bgArticle === 'both'  ? 'selected' : '' ?>>Both</option>
                </select>
                <small class="text-muted">A kurti styling piece is Women only; a delivery or care note can be Both.</small>
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
            <?php // data-rich-editor is what blog-editor.js looks for. Without the
                  // script the plain textarea below is what the admin gets, and it
                  // still saves — the editor is an enhancement, not a dependency. ?>
            <?php // Declares how the body is stored. Stays 'text' unless the editor
                  // script loads and switches it to 'html' — so a browser with
                  // JavaScript disabled saves plain text and is rendered as such,
                  // instead of the server having to guess from the bytes. ?>
            <input type="hidden" name="content_format" id="content_format"
                   value="<?= ($editPost['content_format'] ?? 'text') === 'html' ? 'html' : 'text' ?>">
            <textarea name="content" data-rich-editor class="form-control" rows="8"
                      placeholder="Write full article body text..." required><?= htmlspecialchars($editPost['content'] ?? '') ?></textarea>
            <small style="display:block; margin-top:6px; color:var(--text-muted); font-size:12px;">
                Select text, then use the buttons above it. Pasted text arrives unformatted on purpose,
                so an article always matches the site's own styling.
            </small>
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
                        // The SAME resolver the storefront uses, so this thumbnail is
                        // exactly what a shopper sees. It previously checked
                        // uploads/products/ first for any filename including lookbook_*,
                        // and served the .png while the site served the .webp — so one
                        // article could show two different photographs. Proven on live:
                        // a stale uploads/products/lookbook_3.png (1024x1024) here against
                        // the real uploads/gallery/lookbook_3 (1122x1402) on the site.
                        $imgSrc = blogImageUrl($p['image'] ?? '');
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
                                <?php /* The same bug the Delete button below had, in the same table.
                                         This was <a href="blog.php?toggle=ID">, and nothing in this
                                         file reads $_GET['toggle'] — the only handler wants a POST
                                         carrying action=toggle_blog and a CSRF token. So the badge
                                         showed a pointer cursor, the page reloaded, and the article
                                         stayed exactly as published as it was before. An owner
                                         taking an article down believed they had.
                                         A form for the same reason the delete is one: a URL that
                                         changes what the public can see must not be fireable by
                                         anything that merely follows links, and a link cannot carry
                                         a CSRF token. */ ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="toggle_blog">
                                    <input type="hidden" name="blog_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="badge-luxury"
                                            style="background:<?= $p['status']==='Published' ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $p['status']==='Published' ? '#10b981' : '#ef4444' ?>; cursor:pointer; border:0; font:inherit;"
                                            title="<?= $p['status']==='Published' ? 'Unpublish this article' : 'Publish this article' ?>">
                                        <?= htmlspecialchars((string)$p['status']) ?>
                                    </button>
                                </form>
                            </td>
                            <td style="text-align:right;">
                                <div class="admin-actions">
                                    <a href="blog.php?edit=<?= $p['id'] ?>" class="admin-action-btn is-primary" title="Edit article" aria-label="Edit article <?= htmlspecialchars($p['title'] ?? '') ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <?php // A POST, not a link.
                                          //
                                          // This was <a href="blog.php?delete=ID">, and nothing in
                                          // this file ever reads $_GET['delete'] — the only handler
                                          // wants a POST carrying action=delete_blog and a CSRF
                                          // token. So the confirm box appeared, the page reloaded,
                                          // and the article was still there. Silently, every time.
                                          //
                                          // Restored as a form rather than by adding a GET branch:
                                          // a URL that deletes can be fired by anything that follows
                                          // links — a crawler, a preloader, an email scanner — and
                                          // it cannot carry a CSRF token. ?>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return dvConfirmForm(this, 'Delete &quot;<?= htmlspecialchars(addslashes($p['title'] ?? ''), ENT_QUOTES) ?>&quot;?\n\nThis cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="action" value="delete_blog">
                                        <input type="hidden" name="blog_id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="admin-action-btn is-danger" title="Delete article" aria-label="Delete article <?= htmlspecialchars($p['title'] ?? '') ?>">
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

<?php // Loaded after the form exists so the script can find the textarea without
      // waiting on DOMContentLoaded. filemtime busts the cache on every edit —
      // live sends a month-long max-age for static assets. ?>
<script src="<?= SITE_URL ?>/admin/assets/js/blog-editor.js?v=<?= filemtime(__DIR__ . '/assets/js/blog-editor.js') ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
