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
requireAdminCapability('content.manage');


// Auto-ensure the audience column, so this screen works whether or not
// update_new_database.php has been run. DEFAULT 'women' is correct for every
// banner that exists today — this shop has only ever advertised womenswear.
try {
    $pdo->exec("ALTER TABLE banners ADD COLUMN gender ENUM('women','men','both') NOT NULL DEFAULT 'women';");
} catch (PDOException $e) {}

// Where the banner appears. The two "Occasion Edit" panels on the homepage were
// hardcoded HTML with their background photographs fixed in CSS
// (.banner-wedding / .banner-office), so there was no way to change either the
// picture or the words without editing files — and no way at all to show
// menswear a different one. Making them banners puts both in admin.
// DEFAULT 'hero' keeps every existing banner in the slider where it already is.
try {
    $pdo->exec("ALTER TABLE banners ADD COLUMN placement ENUM('hero','occasion') NOT NULL DEFAULT 'hero';");
} catch (PDOException $e) {}

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

// Handle Add or Edit Banner Slide (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banner']) && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $errorMsg = "Security validation failed (Invalid CSRF token).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banner'])) {
    $bannerId = (int)($_POST['banner_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $link     = trim($_POST['link'] ?? 'shop');
    /* Whitelisted for the same reason gender and placement are, a few lines
       below — this is an ENUM('Active','Inactive') and the value went in
       untouched. Any other string died on "SQLSTATE[01000]: Warning: 1265 Data
       truncated for column 'status'", printed raw to the page; and on a server
       running without STRICT_TRANS_TABLES the same POST stores '' instead,
       which matches neither 'Active' nor 'Inactive'. Such a banner is invisible
       on the homepage AND unfixable from this screen, because the toggle flips
       between the two real values and never reaches ''. */
    $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
    $imgName  = $_POST['existing_image'] ?? '';

    /* Column limits, checked before the write rather than after it. title and
       link are VARCHAR(255); pasting a long tracking URL into the link field
       produced the raw driver message naming the column. */
    if (mb_strlen($title) > 255) {
        $errorMsg = 'The banner title is too long — keep it to 255 characters or fewer. Nothing was saved.';
        $title = '';
    } elseif (mb_strlen($link) > 255) {
        $errorMsg = 'That link is too long — keep it to 255 characters or fewer. Nothing was saved.';
        $title = '';
    }

    // What is stored is a BARE FILENAME, never a path.
    //
    // existing_image is a hidden field and was written to the database verbatim,
    // so any POST could set a banner's image to an arbitrary path. Measured on
    // this install: two banner rows held "../../config/db.php" and
    // "../../config/config.php", which the homepage rendered as
    // url('.../uploads/products/../../config/db.php') in a CSS background — the
    // occasion panels showed no picture at all, and because those rows sorted
    // ahead of the real banners they displaced them.
    //
    // The upload branch below is careful — MIME read from the file's own bytes,
    // filename generated here — and this line bypassed every bit of it.
    $imgName = basename(str_replace('\\', '/', (string)$imgName));
    if ($imgName !== '' && !preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$/i', $imgName)) {
        $imgName = '';
    }

    // Handle new image upload
    // Superseded image, deleted only after the save commits.
    $bannerStaleImage = '';

    if (!empty($_FILES['banner_image']['name'])) {
        $file    = $_FILES['banner_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        // MIME is read from the file CONTENTS (finfo), never from the client-sent
        // header — $file['type'] is a string anyone can forge, and this uploader
        // used to trust it, so a PHP payload renamed banner.php and labelled
        // "image/png" sailed straight through into a web-served folder.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
        finfo_close($finfo);
        if (in_array($mime, $allowed, true) && $file['size'] <= 8 * 1024 * 1024) {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = 'banner_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            // Banners get their own folder. They used to be written into
            // uploads/products/ alongside product photography, so clearing out
            // product images took the homepage hero with them.
            $destDir  = __DIR__ . '/../uploads/banners/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }
            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                // banners.php was the only uploader not generating a WebP copy.
                // Resize/re-compress before the WebP is made, so the delivery copy is
                // generated from the already-optimised original rather than the raw upload.
                optimizeUploadedImage($destDir . $filename);
                generateWebpCopy($destDir . $filename);
                if ($bannerId > 0) {
                    $prev = $pdo->prepare("SELECT image FROM banners WHERE id = :id");
                    $prev->execute(['id' => $bannerId]);
                    $prevImg = (string)$prev->fetchColumn();
                    if ($prevImg !== '' && $prevImg !== $filename) { $bannerStaleImage = $prevImg; }
                }
                $imgName = $filename;
            }
        }
    }

    if ($title) {
        try {
            // Audience. Anything unrecognised falls back to womenswear, which is
            // what every banner on this shop was before the field existed.
            $bannerGender = strtolower(trim((string)($_POST['gender'] ?? 'women')));
            if (!in_array($bannerGender, ['women', 'men', 'both'], true)) { $bannerGender = 'women'; }
            $bannerPlacement = ($_POST['placement'] ?? 'hero') === 'occasion' ? 'occasion' : 'hero';

            if ($bannerId > 0) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE banners SET title = :title, subtitle = :subtitle, image = :image, link = :link, status = :status, gender = :gender, placement = :placement WHERE id = :id");
                $stmt->execute(['title' => $title, 'subtitle' => $subtitle, 'image' => $imgName, 'link' => $link, 'status' => $status, 'gender' => $bannerGender, 'placement' => $bannerPlacement, 'id' => $bannerId]);
                $successMsg = "Banner slide '{$title}' updated successfully!";
                if (!empty($bannerStaleImage)) {
                    // Both folders: banners uploaded before they had their own
                    // live in uploads/products/, newer ones in uploads/banners/.
                    foreach (['banners', 'products'] as $bClean) {
                        deleteUploadedFileIfUnused($pdo, $bannerStaleImage, __DIR__ . '/../uploads/' . $bClean . '/');
                    }
                }
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO banners (title, subtitle, image, link, status, gender, placement) VALUES (:title, :subtitle, :image, :link, :status, :gender, :placement)");
                $stmt->execute(['title' => $title, 'subtitle' => $subtitle, 'image' => $imgName, 'link' => $link, 'status' => $status, 'gender' => $bannerGender, 'placement' => $bannerPlacement]);
                $successMsg = "New banner slide '{$title}' added successfully!";
            }
            $editBanner = null; // Clear edit mode after save
            /* Creating and editing a banner were the only two actions on this
               screen that left no trace — toggle and delete below both log. A
               banner is the first thing every visitor sees, so "who changed the
               homepage, and when" is exactly the question the audit log exists
               to answer. */
            logAdminAction($_SESSION['admin_id'] ?? 1,
                $bannerId > 0 ? 'update_banner' : 'create_banner',
                ($bannerId > 0 ? "Updated banner ID $bannerId" : 'Created banner')
                . " ('{$title}', {$status}, {$bannerPlacement})");
        } catch (PDOException $e) {
            // Not the driver's own words: they name the table column and are of
            // no use to whoever is uploading a banner.
            error_log('banner save: ' . $e->getMessage());
            $errorMsg = 'The banner could not be saved. Nothing was changed — please try again.';
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
            $old = $pdo->prepare("SELECT image FROM banners WHERE id = :id");
            $old->execute(['id' => $delId]);
            $oldImg = (string)$old->fetchColumn();

            $pdo->prepare("DELETE FROM banners WHERE id = :id")->execute(['id' => $delId]);

            // Same shared-folder caution as the blog handler above.
            if ($oldImg !== '') {
                deleteUploadedFileIfUnused($pdo, $oldImg, __DIR__ . '/../uploads/products/');
            }
            logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_banner', "Deleted banner ID $delId");
            $successMsg = "Banner slide deleted successfully.";
        } catch (PDOException $e) {}
    }
}

$banners = [];
try {
    $banners = $pdo->query("SELECT * FROM banners ORDER BY sort_order ASC, id DESC")->fetchAll();
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
        <h1 class="admin-page-title">🖼️ Homepage Banner Slider Manager</h1>
        <p class="admin-page-subtitle">Manage hero slider banners, titles, background images, and call-to-action links.</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
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
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
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
            <?php /* A photograph cannot be sorted by audience automatically, so this is
                     the one thing about a banner that has to be stated by hand. Without
                     it the menswear homepage led with a woman in a kurti. */ ?>
            <div class="form-group">
                <label class="form-label">Show this banner to</label>
                <?php $bg = strtolower(trim((string)($editBanner['gender'] ?? 'women'))); if ($bg === '') { $bg = 'women'; } ?>
                <select name="gender" class="form-control">
                    <option value="women" <?= $bg === 'women' ? 'selected' : '' ?>>Women only</option>
                    <option value="men"   <?= $bg === 'men'   ? 'selected' : '' ?>>Men only</option>
                    <option value="both"  <?= $bg === 'both'  ? 'selected' : '' ?>>Both</option>
                </select>
                <small class="text-muted">Use "Both" for anything not about a garment — a sale message or a delivery promise.</small>
            </div>
            <div class="form-group">
                <label class="form-label">Upload New Background Image</label>
                <input type="file" name="banner_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                <?php /* Said here, before the upload, because afterwards it is too late.
                         A banner is stretched across the whole screen width, so a small
                         or portrait picture is enlarged to fit and looks soft — and
                         nothing in the shop can put back detail the photograph never
                         had. A 896x1200 portrait upload, which is what happened, is
                         stretched about 1.6x on a normal laptop. */ ?>
                <div class="banner-size-hint" style="margin-top:8px; padding:10px 12px; background:rgba(197,155,75,0.08); border:1px solid rgba(197,155,75,0.28); border-radius:6px; font-size:12.5px; line-height:1.7; color:var(--text-secondary);">
                    <strong style="color:var(--text-primary);"><i class="fa-solid fa-image"></i> Best size: 2000 &times; 1100 pixels, landscape</strong><br>
                    The banner is stretched across the full width of the screen, so a
                    <strong>wide</strong> picture stays sharp and a small or tall one looks blurry.
                    <ul style="margin:6px 0 0 18px; padding:0;">
                        <li>Landscape, roughly 16:9 — not portrait</li>
                        <li>At least 1600px wide; 2000px is ideal</li>
                        <li>Keep it under 2000px on the longest side, or it gets resized</li>
                        <li>JPG or PNG. A WebP copy is made for you automatically</li>
                        <li>Faces and text near the centre — the edges are cropped on phones</li>
                    </ul>
                </div>
                <?php
                    // Show the picture, not just its filename.
                    //
                    // This printed the stored filename in a <code> tag and nothing
                    // else, so editing a banner showed no image at all — the one
                    // thing a banner actually is. With four slides named
                    // cotton_short_kurti_1784414719998.jpg and the like, there was
                    // no way to tell which one you were editing without opening the
                    // homepage and counting.
                    //
                    // Same two-folder fallback the list below uses: banners are
                    // written to uploads/products, but older ones live in gallery.
                    $bImgSrc = '';
                    if ($editBanner && !empty($editBanner['image'])) {
                        foreach (['products', 'gallery'] as $bDir) {
                            if (file_exists(__DIR__ . '/../uploads/' . $bDir . '/' . $editBanner['image'])) {
                                $bImgSrc = SITE_URL . '/uploads/' . $bDir . '/' . rawurlencode($editBanner['image']);
                                break;
                            }
                        }
                    }
                ?>
                <?php if ($editBanner && !empty($editBanner['image'])): ?>
                    <div style="margin-top:10px;">
                        <?php if ($bImgSrc !== ''): ?>
                            <img src="<?= htmlspecialchars($bImgSrc) ?>" alt="Current banner image"
                                 style="max-width:260px; width:100%; height:auto; border:1px solid var(--border-light); border-radius:6px; display:block;">
                        <?php else: ?>
                            <div style="padding:10px 12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:6px; font-size:12px; color:#9a3412;">
                                The file named on this banner is <strong>missing from the server</strong>, so the slide
                                shows nothing on the homepage. Upload a replacement below.
                            </div>
                        <?php endif; ?>
                        <span style="font-size:11px; color:var(--text-muted); margin-top:6px; display:block;">
                            Current image: <code><?= htmlspecialchars($editBanner['image']) ?></code>
                            &middot; leave the upload box empty to keep it
                        </span>
                    </div>
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
                        // Shared resolver — see bannerImageLocation().
                        $bLoc   = bannerImageLocation($b['image'] ?? '');
                        $imgSrc = $bLoc ? SITE_URL . '/uploads/' . $bLoc['dir'] . '/' . rawurlencode($bLoc['file']) : '';
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
                                <div class="admin-actions">
                                    <a href="banners.php?edit=<?= $b['id'] ?>" class="admin-action-btn is-primary" title="Edit slide" aria-label="Edit banner slide">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <?php // A POST, not a link — the same fault the Blog screen had.
                                          //
                                          // This was <a href="banners.php?delete=ID">, and nothing
                                          // reads $_GET['delete']; the handler at the top of this
                                          // file wants a POST with action=delete_banner and a CSRF
                                          // token. Clicking Delete confirmed, reloaded, and left the
                                          // slide exactly where it was. ?>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return dvConfirmForm(this, 'Delete this banner slide?\n\nThis cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="action" value="delete_banner">
                                        <input type="hidden" name="banner_id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="admin-action-btn is-danger" title="Delete slide" aria-label="Delete banner slide">
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
