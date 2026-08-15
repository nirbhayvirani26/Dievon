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
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('catalogue.manage');


// Auto ensure parent_id column exists
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT DEFAULT 0;");
} catch (PDOException $e) {}

// Same for the audience flag, so this screen works whether or not
// update_new_database.php has been run yet. DEFAULT 'women' makes every row
// that already exists correct on creation — this shop has only ever sold
// womenswear — so adding the column changes nothing a shopper can see.
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN gender ENUM('women','men','unisex') NOT NULL DEFAULT 'women';");
} catch (PDOException $e) {}

// And the per-category tax defaults. Every product inherits its HSN code and
// GST rate from the category it is filed under (set in the edit panel below),
// so these columns have to exist even on an install that has not run the
// migration yet.
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN default_hsn_code VARCHAR(50) DEFAULT NULL AFTER gender;");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN default_gst_rate DECIMAL(5,2) DEFAULT 0.00 AFTER default_hsn_code;");
} catch (PDOException $e) {}

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'subcategories') ? 'subcategories' : 'categories';
$isSubTab = ($activeTab === 'subcategories');

$successMsg = '';
$errorMsg   = '';

/* uniqueCategorySlug() moved to config/config.php.
   ────────────────────────────────────────────────────────────────────────────
   It was defined here, which meant admin/category_handler.php — the quick-add
   that the product form uses — had no way to call it, and so inserted
   categories with no slug at all. Two categories may legitimately share a name
   under different parents ("Tops" under Women's and under Sets); one URL cannot
   serve both, which is what the function is for. One definition, both callers. */

// Handle POST Add Category / Subcategory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    // This POST had no token check, so any page an admin visited while signed in
    // could have created categories in the live menu.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security token expired — reload this page and try again.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $parentId = (int)($_POST['parent_id'] ?? 0);

        if ($name !== '') {
            try {
                // Without a slug the category has no clean URL and falls back to
                // the legacy ?category= form, so every new category would start
                // life on a non-canonical address.
                // Audience, taken from the form for a top-level category.
                //
                // A subcategory inherits from the top of its tree, so it is given
                // its PARENT's value rather than anything posted — that keeps the
                // stored data honest even though the form does not offer the choice.
                $wantGender = strtolower(trim((string)($_POST['gender'] ?? '')));
                if (!in_array($wantGender, ['women', 'men', 'unisex'], true)) { $wantGender = 'women'; }
                if ($parentId > 0) {
                    $pg = $pdo->prepare("SELECT gender FROM categories WHERE id = :id");
                    $pg->execute(['id' => $parentId]);
                    $inherited = strtolower(trim((string)$pg->fetchColumn()));
                    if (in_array($inherited, ['women', 'men', 'unisex'], true)) { $wantGender = $inherited; }
                }

                // Without a slug the category has no clean URL and falls back to
                // the legacy ?category= form, so every new category would start
                // life on a non-canonical address.
                // A new category goes to the END of its level, not the front.
                //
                // sort_order was hard-coded 0 here while the existing collections
                // start at 1, so every category added from this screen tied at 0
                // and sorted AHEAD of all of them — silently demoting Kurtis, the
                // lead collection, in the mega menu, the homepage strip and the
                // /men nav, with ties broken arbitrarily by name. The quick-add in
                // admin/category_handler.php:43 already does it this way.
                $nextOrder = (int)$pdo->query(
                    "SELECT COALESCE(MAX(sort_order),0) FROM categories WHERE parent_id = " . (int)$parentId
                )->fetchColumn() + 1;

                $stmt = $pdo->prepare("INSERT INTO categories (name, parent_id, sort_order, slug, gender) VALUES (:name, :parent_id, :sort_order, :slug, :gender)");
                $stmt->execute([
                    'name' => $name,
                    'parent_id' => $parentId,
                    'sort_order' => $nextOrder,
                    'slug' => uniqueCategorySlug($pdo, $name),
                    'gender' => $wantGender,
                ]);
                // Read the id BEFORE anything else touches the connection.
                // logAdminAction() runs its own INSERT (config/config.php:4133),
                // so calling lastInsertId() after it returned the AUDIT LOG id,
                // not the category id. Every category ever created here got its
                // size chart bound to a phantom category — and because
                // categories.id is AUTO_INCREMENT, the audit ids would one day
                // collide with real category ids and silently attach a stale
                // chart to an unrelated collection.
                $newCategoryId = (int)$pdo->lastInsertId();

                logAdminAction($_SESSION['admin_id'] ?? 1, 'category_add', "Created category: $name ({$wantGender})");

                // Every new TOP-LEVEL category automatically gets a size chart
                // cloned from the right gender's default — the owner never has to
                // build one. Sub-categories inherit through the parent walk-up in
                // pages/product.php, so giving them their own chart would only
                // clutter Admin → Size Guides with identical copies.
                if ($parentId <= 0) {
                    autoCreateSizeGuideChartForCategory($pdo, $newCategoryId, $wantGender);
                }
                $successMsg = $parentId > 0
                    ? "Sub-category '{$name}' created!"
                    : "Category '{$name}' created as " . ($wantGender === 'men' ? "Men's" : ($wantGender === 'unisex' ? 'Unisex' : "Women's")) . ".";
            } catch (PDOException $e) {
                $errorMsg = "Error: " . $e->getMessage();
            }
        }
    }
}

// ── Save per-category SEO ───────────────────────────────────────────────────
// Blank fields are stored blank on purpose: categorySeo() then generates that
// value from the products in the category, which stays right as stock changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category_seo'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security token expired — reload this page and try again.";
    } else {
        $seoId = (int)($_POST['category_id'] ?? 0);
        try {
            $cur = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
            $cur->execute(['id' => $seoId]);
            $curRow = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) {
                $errorMsg = "That category no longer exists.";
            } else {
                $wantSlug = trim((string)($_POST['slug'] ?? ''));
                // Fall back to the name so clearing the field cannot leave a
                // category with no URL at all.
                $newSlug = uniqueCategorySlug($pdo, $wantSlug !== '' ? $wantSlug : $curRow['name'], $seoId);

                // Audience is only settable on a top-level category: everything
                // below reads its answer from the top of the tree, so letting a
                // subcategory hold its own value would create a setting that
                // looks saved and is then ignored.
                $isTopLevel = (int)($curRow['parent_id'] ?? 0) === 0;
                $wantGender = strtolower(trim((string)($_POST['gender'] ?? '')));
                if (!in_array($wantGender, ['women', 'men', 'unisex'], true)) {
                    $wantGender = strtolower(trim((string)($curRow['gender'] ?? 'women')));
                    if (!in_array($wantGender, ['women', 'men', 'unisex'], true)) { $wantGender = 'women'; }
                }
                if (!$isTopLevel) { $wantGender = $curRow['gender'] ?? 'women'; }

                // ── Collection image ────────────────────────────────────────
                // Kept as whatever is already stored unless this request either
                // uploads a replacement or asks for removal, so saving the SEO
                // fields alone never disturbs the picture.
                //
                // Validation mirrors the brand uploads in admin/settings.php: an
                // extension allow-list AND a MIME check, because either alone is
                // trivially fooled — a .php renamed to .jpg passes the extension
                // test, and a browser-declared MIME type is just a header.
                $catImage = (string)($curRow['image'] ?? '');
                $catImgErr = '';

                if (!empty($_POST['remove_category_image'])) {
                    // Take the file with it rather than orphaning it in uploads/gallery,
                    // which the Media Library does not scan.
                    if ($catImage !== '') {
                        $old = dirname(__DIR__) . '/uploads/gallery/' . basename($catImage);
                        if (is_file($old)) { @unlink($old); }
                    }
                    $catImage = '';
                } elseif (!empty($_FILES['category_image']['name']) && is_uploaded_file($_FILES['category_image']['tmp_name'])) {
                    $f    = $_FILES['category_image'];
                    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                    $okEx = ['jpg', 'jpeg', 'png', 'webp'];
                    $okMi = ['image/jpeg', 'image/png', 'image/webp'];
                    $mime = function_exists('finfo_open')
                        ? (finfo_file($fi = finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) ?: '')
                        : (string)($f['type'] ?? '');
                    if (isset($fi) && $fi) { finfo_close($fi); }

                    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
                        $catImgErr = 'The image did not upload correctly. Try again.';
                    } elseif (!in_array($ext, $okEx, true) || !in_array($mime, $okMi, true)) {
                        $catImgErr = 'Collection images must be a JPG, PNG or WebP.';
                    } elseif ((int)$f['size'] > 3 * 1024 * 1024) {
                        $catImgErr = 'That image is larger than 3 MB. Please resize it first.';
                    } else {
                        $dir = dirname(__DIR__) . '/uploads/gallery/';
                        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                        // Name derived from the category plus a random suffix: readable
                        // in the folder, and never guessable or collidable.
                        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$curRow['name']));
                        $new  = 'category-' . trim($base, '-') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (move_uploaded_file($f['tmp_name'], $dir . $new)) {
                            /* Optimised and given a WebP twin, like every other upload.
                               ────────────────────────────────────────────────────────
                               This was the only upload path in the panel that did
                               neither — product photos, colour photos, banners, blog
                               pictures and media-library uploads all do. So a 1.7MB
                               collection photograph was stored at 1.7MB with no WebP,
                               and it is one of the largest images on the shop: the hero
                               at the top of a collection page and the picture behind
                               every mega-menu promo card.
                               Measured across the two folders: JPEGs elsewhere stop at
                               2000px and average 481KB, while PNGs — which never passed
                               through here — reach 2876px and average 862KB. */
                            if (function_exists('optimizeUploadedImage')) {
                                optimizeUploadedImage($dir . $new);
                            }
                            if (function_exists('generateWebpCopy')) {
                                generateWebpCopy($dir . $new);
                            }
                            if ($catImage !== '' && is_file($dir . basename($catImage))) {
                                @unlink($dir . basename($catImage));
                                // The twin goes with it, or it is left orphaned on disk
                                // holding a filename nothing references.
                                $oldWebp = $dir . pathinfo(basename($catImage), PATHINFO_FILENAME) . '.webp';
                                if (is_file($oldWebp)) { @unlink($oldWebp); }
                            }
                            $catImage = $new;
                        } else {
                            $catImgErr = 'The image could not be saved. Check folder permissions on uploads/gallery.';
                        }
                    }
                }

                /* Alt text, written for the owner when they leave it blank.
                   ────────────────────────────────────────────────────────────
                   The product form already does this (product_form.php:864,
                   name + colour or fabric). Collections did not, and all
                   thirteen were empty — the collection image is one of the
                   largest pictures on the site and it was carrying no
                   description at all, which is both a screen-reader dead end
                   and lost Google Images traffic.

                   Typed text always wins; this only fills a blank. */
                $catAlt = trim((string)($_POST['image_alt'] ?? ''));
                if ($catAlt === '') {
                    $catName = trim((string)($curRow['name'] ?? ''));
                    if ($catName !== '') { $catAlt = $catName . ' collection'; }
                }

                $pdo->prepare(
                    "UPDATE categories
                        SET slug = :slug, meta_title = :mt, meta_description = :md,
                            intro = :intro, image_alt = :alt, gender = :gender, image = :image,
                            default_hsn_code = :hsn, default_gst_rate = :gst
                      WHERE id = :id"
                )->execute([
                    'slug'   => $newSlug,
                    'mt'     => trim((string)($_POST['meta_title'] ?? '')),
                    'md'     => trim((string)($_POST['meta_description'] ?? '')),
                    'intro'  => trim((string)($_POST['intro'] ?? '')),
                    'alt'    => $catAlt,
                    'gender' => $wantGender,
                    'image'  => $catImage !== '' ? $catImage : null,
                    // The tax defaults every product under this collection inherits.
                    'hsn'    => trim((string)($_POST['default_hsn_code'] ?? '')),
                    // Only a value the list actually offers can be stored; anything
                    // else came from a tampered or stale form, and a rate nobody
                    // chose would go on to print on real invoices.
                    'gst'    => array_key_exists((string)($_POST['default_gst_rate'] ?? ''), categoryGstOptions())
                        ? (float)$_POST['default_gst_rate']
                        : 0.0,
                    'id'     => $seoId,
                ]);
                logAdminAction($_SESSION['admin_id'] ?? 1, 'category_seo_save', "SEO updated for category #$seoId");

                $successMsg = "SEO saved for '{$curRow['name']}'.";
                // Reported rather than swallowed: the rest of the form saved fine,
                // so a silent failure would look like the upload had worked.
                if ($catImgErr !== '') { $errorMsg = $catImgErr . ' The other fields were saved.'; }
                if ($newSlug !== $wantSlug && $wantSlug !== '') {
                    $successMsg .= " The address you typed was already in use, so it was saved as /collections/{$newSlug}.";
                }
                if ($curRow['slug'] !== $newSlug && !empty($curRow['slug'])) {
                    // Keep the old address working. Without this, every link to it —
                    // search results, bookmarks, anything shared — becomes a 404 the
                    // moment the address is edited.
                    try {
                        $pdo->prepare(
                            "INSERT INTO category_slug_history (old_slug, category_id) VALUES (:s, :id)
                             ON DUPLICATE KEY UPDATE category_id = :id2"
                        )->execute(['s' => $curRow['slug'], 'id' => $seoId, 'id2' => $seoId]);
                        // A slug coming back into use must stop redirecting to itself.
                        $pdo->prepare("DELETE FROM category_slug_history WHERE old_slug = :s")
                            ->execute(['s' => $newSlug]);
                        $successMsg .= " The old address /collections/{$curRow['slug']} now redirects here, so existing links keep working.";
                    } catch (PDOException $e) {
                        $successMsg .= " Note: the old address /collections/{$curRow['slug']} will now 404.";
                    }
                }
            }
        } catch (PDOException $e) {
            $errorMsg = "Error saving SEO: " . $e->getMessage();
        }
    }
}

// Deleting used to be a plain link: GET categories.php?delete_cat=<id>, with no
// token and no check for products still in the category. A link prefetch, a
// crawler or a forged image tag could quietly remove categories, and deleting one
// that still holds stock takes its collection page down with it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security token expired — reload this page and try again.";
    } else {
        $delId = (int)($_POST['delete_cat'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
            $row->execute(['id' => $delId]);
            $delRow = $row->fetch(PDO::FETCH_ASSOC);

            if (!$delRow) {
                $errorMsg = "That category no longer exists.";
            } else {
                $inUse = $pdo->prepare(
                    "SELECT COUNT(*) FROM products
                      WHERE (category_id = :cid OR category = :cname)
                        AND (is_deleted = 0 OR is_deleted IS NULL)"
                );
                $inUse->execute(['cid' => $delId, 'cname' => $delRow['name']]);
                $childCount = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = :id");
                $childCount->execute(['id' => $delId]);

                if ((int)$inUse->fetchColumn() > 0) {
                    $errorMsg = "'{$delRow['name']}' still has products in it. Move them to another category first.";
                } elseif ((int)$childCount->fetchColumn() > 0) {
                    $errorMsg = "'{$delRow['name']}' still has sub-categories. Delete or reassign those first.";
                } else {
                    $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute(['id' => $delId]);
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'category_delete', "Deleted category: {$delRow['name']}");
                    $successMsg = "Category '{$delRow['name']}' deleted.";
                }
            }
        } catch (PDOException $e) {
            $errorMsg = "Error deleting category: " . $e->getMessage();
        }
    }
}

// Fetch categories
$parentCategories = [];
$subCategories = [];
$subGrouped = [];

try {
    $allCatList = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    /* The "auto-heal" that undid your deletions has been removed.
       ────────────────────────────────────────────────────────────────────
       This block used to run on every single load of this page, not on
       install and not behind a button:

           if (no sub-categories exist) {
               find a category named like Kurti/Kurta;
               INSERT Short Kurtis, Long Kurtis, Anarkali Sets,
                      Sharara & Gharara Sets, Straight Cut Kurtis;
           }

       So deleting the sub-categories deleted them, and then merely LOOKING
       at this screen put all five back. The delete had worked; the page
       rebuilt them before it drew the list, which is why they appeared to
       return on their own and why deleting them again never helped.

       Its else-branch was worse: a category that already existed by one of
       those five names was silently re-parented under Kurtis. A main
       category called "Anarkali Sets" would move itself into a sub-category
       every time this page was opened.

       Seeding starter data is a job for a setup script that runs once, with
       the shopkeeper's consent. It is not something a listing page should do
       behind their back — an empty category list on a live shop is a
       deliberate act, not damage to repair. */

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

/* ── Audience split ─────────────────────────────────────────────────────────
   categories.gender has existed since the menswear work and drives what each
   side of the shop shows, but this screen never displayed it — "All Main
   Categories (7)" listed womenswear and menswear in one undifferentiated run,
   so the only way to find out whether Trousers was a men's category was to
   scroll to the SEO panel at the bottom and open its editor.

   Audience is a top-level property: a subcategory reads its answer from the top
   of the tree (see the note by the save handler above), so only the parents are
   grouped here and the children inherit for display. */
$genderMeta = [
    'women'  => ['label' => "Women's",  'icon' => 'fa-venus',        'accent' => 'var(--color-primary)'],
    'men'    => ['label' => "Men's",    'icon' => 'fa-mars',         'accent' => '#2f5d8a'],
    'unisex' => ['label' => 'Unisex',   'icon' => 'fa-venus-mars',   'accent' => 'var(--color-secondary)'],
];
$normalizeGender = function ($row) use ($genderMeta): string {
    $g = strtolower(trim((string)($row['gender'] ?? 'women')));
    return isset($genderMeta[$g]) ? $g : 'women';
};

// Keyed in $genderMeta order so Women's always renders before Men's, rather than
// in whatever order the rows happened to come back.
$parentsByGender = array_fill_keys(array_keys($genderMeta), []);
foreach ($parentCategories as $c) {
    $parentsByGender[$normalizeGender($c)][] = $c;
}
$genderCounts = array_map('count', $parentsByGender);

// Every category plus the metadata categorySeo() would generate for it, so the
// editor can show what the page says today and what a blank field would fall
// back to. Re-read after the handlers above so a save is reflected immediately.
$seoCategories = [];
try {
    foreach ($pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) as $sc) {
        $generated = categorySeo($pdo, array_merge($sc, [
            // Blank the stored values so this shows the fallback, not the override.
            'meta_title' => '', 'meta_description' => '', 'intro' => '',
        ]));
        $seoCategories[] = ['row' => $sc, 'generated' => $generated, 'live' => categorySeo($pdo, $sc)];
    }
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header">
    <h1 class="admin-page-title">🏷️ Manage Categories &amp; Sub-Categories</h1>
    <p class="admin-page-subtitle">Organize your store catalog into Main Categories and Sub-Categories for your Header Mega Menu</p>
</div>

<?php // Shown only when the tree really is flat. parent_id is added by the ALTER at
      // the top of this file with DEFAULT 0, and parent_id = 0 IS "main category" —
      // so on an install where the column arrived after the categories did, every
      // sub-category silently became a main one and every count here reads zero.
      // The counts are honest; the tree is what broke. Offer the repair rather than
      // leaving someone to wonder why the number is wrong. ?>
<?php if (empty($subCategories) && !empty($parentCategories)): ?>
<div class="alert alert-danger" style="margin-bottom:22px; line-height:1.6">
    <i class="fa-solid fa-triangle-exclamation" style="color:var(--color-danger);"></i>
    <strong>Every category is filed as a main category, so all sub-category counts read zero.</strong><br>
    That usually means <code>parent_id</code> was added to the categories table after the categories themselves —
    every row defaulted to 0, which is what “main category” means, so the hierarchy flattened.
    <a href="repair_category_tree.php" style="font-weight:700;">Repair the category tree &rarr;</a>
</div>
<?php endif; ?>

<?php if (!empty($successMsg)): ?>
<?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
<?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
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
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
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

        <?php if (!$isSubTab): ?>
        <?php // Asked for at creation, not afterwards.
              //
              // The form only collected a name, and the INSERT never set gender —
              // so every new category took the column default of 'women'. Adding a
              // menswear category meant creating it, then hunting for it in the SEO
              // panel at the foot of the page to change its audience. Anyone who
              // stopped after the obvious step silently shipped a men's collection
              // onto the women's side of the shop.
              //
              // Sub-categories are not offered the choice: they read their audience
              // from the top of the tree, so a control here would look saved and
              // then be ignored. ?>
        <div style="flex: 1; min-width: 160px;">
            <select name="gender" class="form-control" title="Which side of the shop this collection belongs to">
                <option value="women">Women's</option>
                <option value="men">Men's</option>
                <option value="unisex">Unisex</option>
            </select>
        </div>
        <?php endif; ?>

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
            <?php // The bare total said 7 and stopped there. The breakdown is the number
                  // actually worth knowing when the shop has two sides to keep stocked. ?>
            <?php if (!$isSubTab && $parentCategories): ?>
            <span class="cat-head-split">
                <?php $bits = [];
                      foreach ($genderMeta as $gk => $gm) {
                          if (!empty($genderCounts[$gk])) { $bits[] = $genderCounts[$gk] . ' ' . $gm['label']; }
                      }
                      echo htmlspecialchars(implode(' · ', $bits)); ?>
            </span>
            <?php endif; ?>
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
                <?php // Parents walked in audience order here too, so this tab reads in the
                      // same sequence as the Main Categories tab rather than by id. ?>
                <?php foreach ($parentsByGender as $gKey => $gParents): foreach ($gParents as $parent):
                    $pId    = (int)$parent['id'];
                    $pSubs  = $subGrouped[$pId] ?? [];
                    $pMeta  = $genderMeta[$gKey];
                ?>
                    <div style="margin-bottom: 24px; border: 1px solid var(--border-light); border-radius: 8px; overflow: hidden; background: #ffffff;">
                        <div style="background: var(--bg-surface-soft); padding: 12px 18px; border-bottom: 1px solid var(--border-light); font-weight: 700; font-size: 13px; text-transform: uppercase; color: var(--color-accent); display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <span style="display:inline-flex; align-items:center; gap:9px;">
                                📁 <?= htmlspecialchars($parent['name']) ?>
                                <?php // Inherited, not stored on the child: a subcategory reads its
                                      // audience from the top of the tree. ?>
                                <span class="cat-gender-badge" style="--cat-g: <?= $pMeta['accent'] ?>;">
                                    <i class="fa-solid <?= $pMeta['icon'] ?>"></i> <?= htmlspecialchars($pMeta['label']) ?>
                                </span>
                            </span>
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
                                            <?php /* id="catname-N" is what startRename() writes the new name into
                                                     after a successful save, so the row updates without a reload. */ ?>
                                            <td style="font-weight: 600; color: var(--text-primary);"><span id="catname-<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></span></td>
                                            <td style="text-align: right;">
                                                <?php /* Rename had a handler and a JS function and no way to reach
                                                         either — startRename() was called from nowhere, so the only
                                                         thing you could do to a category was delete it. */ ?>
                                                <button type="button" class="admin-action-btn"
                                                        onclick="startRename(<?= (int)$cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name']), ENT_QUOTES) ?>')"
                                                        title="Rename" aria-label="Rename category <?= htmlspecialchars($cat['name']) ?>">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <form action="categories.php?tab=<?= $activeTab ?>" method="POST" class="cat-inline-form"
                                                      onsubmit="return dvConfirmForm(this,'Delete sub-category &quot;<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>&quot;?');">
                                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                    <input type="hidden" name="delete_cat" value="<?= (int)$cat['id'] ?>">
                                                    <?php // A hidden field, not the button's name: dvConfirmForm re-submits with
                                                          // form.submit(), which does not carry the clicked button's value. ?>
                                                    <input type="hidden" name="delete_category" value="1">
                                                    <button type="submit" class="admin-action-btn is-danger" title="Delete" aria-label="Delete category">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endforeach; // parent, then audience group ?>
            <?php endif; ?>
        <?php else: ?>
            <!-- Main Categories Table, split by audience -->
            <table class="data-table cat-main-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th style="width: 200px;">Category Name</th>
                        <th>Sub-Categories</th>
                        <th style="width: 110px; text-align: right;">Actions</th>
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
                        <?php foreach ($parentsByGender as $gKey => $gCats):
                            // A group with nothing in it prints no heading at all — an empty
                            // "Unisex" band above a gap reads as something having gone wrong.
                            if (empty($gCats)) { continue; }
                            $meta = $genderMeta[$gKey];
                        ?>
                        <tr class="cat-group-row">
                            <td colspan="4" style="--cat-group-accent: <?= $meta['accent'] ?>;">
                                <i class="fa-solid <?= $meta['icon'] ?>"></i>
                                <?= htmlspecialchars($meta['label']) ?>
                                <span class="cat-group-count"><?= count($gCats) ?> <?= count($gCats) === 1 ? 'category' : 'categories' ?></span>
                            </td>
                        </tr>
                        <?php foreach ($gCats as $cat):
                            $pId       = (int)$cat['id'];
                            $pSubs     = $subGrouped[$pId] ?? [];
                            $pSubCount = count($pSubs);
                        ?>
                            <tr>
                                <td style="color: var(--text-muted); font-weight: 600;">#<?= $cat['id'] ?></td>
                                <?php /* id="catname-N" — startRename() writes the new name in here on success,
                                         so the row updates without a page reload. */ ?>
                                <td style="font-weight: 600; color: var(--text-primary);"><span id="catname-<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></span></td>
                                <td>
                                    <?php // The count alone answered "are there any?" and nothing else —
                                          // finding out WHICH subcategories a category held meant switching
                                          // tabs and scanning the grouped list. The names are the useful part. ?>
                                    <?php if ($pSubCount === 0): ?>
                                        <span class="cat-sub-none">No sub-categories yet</span>
                                    <?php else: ?>
                                        <div class="cat-sub-wrap">
                                            <span class="cat-sub-count"><?= $pSubCount ?></span>
                                            <?php foreach ($pSubs as $sub): ?>
                                                <span class="cat-sub-chip"><?= htmlspecialchars($sub['name']) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php /* Same rename control as the sub-category rows. */ ?>
                                    <button type="button" class="admin-action-btn"
                                            onclick="startRename(<?= (int)$cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name']), ENT_QUOTES) ?>')"
                                            title="Rename" aria-label="Rename category <?= htmlspecialchars($cat['name']) ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form action="categories.php?tab=<?= $activeTab ?>" method="POST" class="cat-inline-form"
                                                      onsubmit="return dvConfirmForm(this,'Delete main category &quot;<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>&quot;?');">
                                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                    <input type="hidden" name="delete_cat" value="<?= (int)$cat['id'] ?>">
                                                    <?php // A hidden field, not the button's name: dvConfirmForm re-submits with
                                                          // form.submit(), which does not carry the clicked button's value. ?>
                                                    <input type="hidden" name="delete_category" value="1">
                                                    <button type="submit" class="admin-action-btn is-danger" title="Delete" aria-label="Delete category <?= htmlspecialchars($cat['name']) ?>">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endforeach; // audience group ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ══ Per-category SEO ═══════════════════════════════════════════════════
     Each collection needs a title, description and opening paragraph of its own,
     or Google sees the same page repeated once per category and ranks none of
     them. Leave a field blank and it is written from the products in that
     category — which stays accurate as stock changes, so this page is somewhere
     to override rather than somewhere you have to fill in. -->
<div class="glass-panel cat-seo-panel">
    <div class="cat-seo-head">
        <h3>🔍 Collection Page SEO</h3>
        <p>What each collection shows Google and shoppers. Blank fields are written automatically from the products in the category.</p>
    </div>

    <?php foreach ($seoCategories as $entry):
        $row  = $entry['row'];
        $gen  = $entry['generated'];
        $live = $entry['live'];
        $isChild = (int)($row['parent_id'] ?? 0) > 0;
    ?>
    <details class="cat-seo-item">
        <summary>
            <span class="cat-seo-name"><?= $isChild ? '↳ ' : '' ?><?= htmlspecialchars($row['name']) ?></span>
            <span class="cat-seo-count"><?= (int)$live['product_count'] ?> product<?= (int)$live['product_count'] === 1 ? '' : 's' ?></span>
            <code class="cat-seo-url">/collections/<?= htmlspecialchars($row['slug'] ?? '') ?></code>
        </summary>

        <?php // enctype is required for the collection image below — without it the
              // browser posts the filename only and $_FILES arrives empty. ?>
        <form action="categories.php?tab=<?= $activeTab ?>" method="POST" enctype="multipart/form-data" class="cat-seo-form">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="category_id" value="<?= (int)$row['id'] ?>">

            <div class="form-group">
                <label class="form-label">Page address</label>
                <div class="cat-seo-slug">
                    <span><?= htmlspecialchars(SITE_URL) ?>/collections/</span>
                    <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($row['slug'] ?? '') ?>">
                </div>
                <p class="form-hint">
                    Lower case, words joined by hyphens. Changing this breaks the old address —
                    only change one that has not been shared or picked up by Google yet.
                </p>
            </div>

            <?php
                // Audience decides two things a shopper and Google both see: the
                // "... for Women" / "... for Men" in the generated title below,
                // and the g:gender value in the Google Shopping feed.
                //
                // Only a top-level category owns it. A sub-category reads the
                // answer from the top of its tree, so offering the choice here
                // would be a setting that appears to save and is then ignored.
                $catIsTop  = (int)($row['parent_id'] ?? 0) === 0;
                $catGender = strtolower(trim((string)($row['gender'] ?? '')));
                if (!in_array($catGender, ['women', 'men', 'unisex'], true)) { $catGender = 'women'; }
                // For a child, show what it ACTUALLY resolves to, not its own
                // unused column — otherwise a sub-category of Men would sit here
                // claiming "Women" and read as a bug.
                if (!$catIsTop) { $catGender = categoryGender((int)($row['id'] ?? 0)); }
            ?>
            <div class="form-group">
                <label class="form-label">Who this collection is for</label>
                <?php if ($catIsTop): ?>
                <select name="gender" class="form-control">
                    <option value="women"  <?= $catGender === 'women'  ? 'selected' : '' ?>>Women</option>
                    <option value="men"    <?= $catGender === 'men'    ? 'selected' : '' ?>>Men</option>
                    <option value="unisex" <?= $catGender === 'unisex' ? 'selected' : '' ?>>Unisex</option>
                </select>
                <p class="form-hint">
                    Sets the audience in the Google title below, and the gender sent to Google
                    Shopping. Menswear submitted as women's gets the products disapproved, so
                    this must be right before a men's collection goes live.
                </p>
                <?php else: ?>
                <input type="hidden" name="gender" value="<?= htmlspecialchars($catGender) ?>">
                <p class="form-hint">
                    Inherited from the top-level collection — currently
                    <strong><?= htmlspecialchars(ucfirst($catGender)) ?></strong>.
                    Change it on the parent and every sub-collection follows.
                </p>
                <?php endif; ?>
            </div>

            <?php // Default HSN & GST. Every product filed under this collection inherits
                  // these (a sub-collection inherits the first default found up its tree),
                  // and the product form shows them read-only. They are written onto the
                  // product on save so the invoice can be a proper tax invoice. ?>
            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label class="form-label">Default HSN Code</label>
                    <input type="text" name="default_hsn_code" class="form-control" maxlength="50"
                           placeholder="e.g. 6204.22"
                           value="<?= htmlspecialchars($row['default_hsn_code'] ?? '') ?>">
                    <p class="form-hint">Applied to every product filed under this collection and its sub-collections.</p>
                </div>
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label class="form-label">Default GST Tax Class / Rate (%)</label>
                    <?php
                    /* A list, not a free number box. The slabs are fixed by law, so
                       anything typed that is not one of them is simply a wrong
                       invoice — and the apparel rate depends on the price of the
                       garment, which no single typed number can express. */
                    $gstStored = $row['default_gst_rate'] ?? '';
                    /* Nothing chosen shows as Apparel – Auto by Price, because that
                       is what a product filed here actually gets. Showing "0%" for
                       an untouched collection would state a rate the invoice never
                       uses, and 0% now has its own stored value anyway. */
                    $gstSel = categoryGstIsSet($gstStored)
                        ? rtrim(rtrim(number_format((float)$gstStored, 2, '.', ''), '0'), '.')
                        : '0';
                    // A row already holding the -1 marker means the same thing as 0
                    // and must land on the same option, or it would show as "0%".
                    if (categoryGstIsAuto($gstStored)) { $gstSel = '0'; }
                    ?>
                    <select name="default_gst_rate" class="form-control">
                        <?php foreach (categoryGstOptions() as $gstVal => $gstLabel): ?>
                        <?php /* (string) matters: PHP turns '0', '5', '-1' and the rest into
                                 INTEGER array keys, so a strict comparison against the string
                                 form never matched and every collection rendered with the
                                 first option pre-selected — which would have quietly saved
                                 Apparel – Auto by Price over whatever was really set. */ ?>
                        <option value="<?= htmlspecialchars((string)$gstVal) ?>"<?= $gstSel === (string)$gstVal ? ' selected' : '' ?>>
                            <?= htmlspecialchars($gstLabel) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">For applicable apparel: up to ₹2,500 per piece = 5% GST; above ₹2,500 = 18%.</p>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Google title <span class="form-hint">— aim for under 60 characters</span></label>
                <input type="text" name="meta_title" class="form-control" maxlength="180"
                       value="<?= htmlspecialchars($row['meta_title'] ?? '') ?>"
                       placeholder="<?= htmlspecialchars($gen['title']) ?>">
                <p class="form-hint">Leave blank to use: <strong><?= htmlspecialchars($gen['title']) ?></strong> (<?= mb_strlen($gen['title']) ?> characters)</p>
            </div>

            <div class="form-group">
                <label class="form-label">Google description <span class="form-hint">— aim for under 155 characters</span></label>
                <textarea name="meta_description" class="form-control" rows="2" maxlength="320"
                          placeholder="<?= htmlspecialchars($gen['description']) ?>"><?= htmlspecialchars($row['meta_description'] ?? '') ?></textarea>
                <p class="form-hint">Leave blank to use: <?= htmlspecialchars($gen['description']) ?> (<?= mb_strlen($gen['description']) ?> characters)</p>
            </div>

            <div class="form-group">
                <label class="form-label">Opening paragraph on the page</label>
                <textarea name="intro" class="form-control" rows="3"
                          placeholder="<?= htmlspecialchars($gen['intro']) ?>"><?= htmlspecialchars($row['intro'] ?? '') ?></textarea>
                <p class="form-hint">Leave blank to use: <?= htmlspecialchars($gen['intro']) ?></p>
            </div>

            <?php // The collection image itself.
                  //
                  // categories.image was read in three places — the home page tile,
                  // the share preview, and categorySeo() — and written in none. There
                  // was no upload anywhere in the admin, so the column stayed empty on
                  // every category and the field below described a picture that could
                  // not be set. Only main categories get a tile on the home page, but
                  // the share image applies to any collection page, so this is offered
                  // for both. ?>
            <div class="form-group">
                <label class="form-label">Collection image</label>
                <?php $catImg = trim((string)($row['image'] ?? '')); ?>
                <?php if ($catImg !== ''): ?>
                <div class="cat-img-current">
                    <img src="<?= SITE_URL ?>/uploads/gallery/<?= htmlspecialchars(rawurlencode($catImg)) ?>"
                         alt="Current image for <?= htmlspecialchars($row['name']) ?>">
                    <label class="cat-img-remove">
                        <input type="checkbox" name="remove_category_image" value="1">
                        Remove this image
                    </label>
                </div>
                <?php endif; ?>
                <input type="file" name="category_image" class="form-control"
                       accept="image/jpeg,image/png,image/webp">
                <p class="form-hint">
                    Shown as this collection's tile on the home page, and as the preview when the
                    collection is shared. Leave empty and a photo from a product in this collection
                    is used instead. JPG, PNG or WebP, up to 3&nbsp;MB.
                </p>
            </div>

            <div class="form-group">
                <label class="form-label">Description of the collection image</label>
                <input type="text" name="image_alt" class="form-control" maxlength="200"
                       value="<?= htmlspecialchars($row['image_alt'] ?? '') ?>"
                       placeholder="e.g. Model wearing an ivory silk kurti with gold embroidery">
                <p class="form-hint">Read aloud to shoppers using a screen reader, and what Google Images indexes.</p>
            </div>

            <div class="cat-seo-actions">
                <button type="submit" name="save_category_seo" value="1" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Save SEO
                </button>
                <?php if (!empty($row['slug'])): ?>
                <a href="<?= htmlspecialchars(SITE_URL . '/collections/' . rawurlencode($row['slug'])) ?>" target="_blank" rel="noopener" class="btn btn-outline">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> View page
                </a>
                <?php endif; ?>
            </div>
        </form>
    </details>
    <?php endforeach; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
