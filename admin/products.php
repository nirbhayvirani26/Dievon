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
requireAdminCapability('catalogue.manage');


$activeTab = 'products';
$successMsg = '';
$errorMsg   = '';

// Handle Delete Product (POST only with CSRF check & Soft Deletion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security CSRF validation failed.";
    } else {
        $delId = (int)($_POST['product_id'] ?? 0);
        try {
            // Soft delete product to protect historical orders
            $pdo->prepare("UPDATE products SET is_deleted = 1, available = 0 WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'soft_delete_product', "Soft-deleted product ID $delId");
            $successMsg = "Product archived (soft-deleted) successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error deleting product: " . $e->getMessage();
        }
    }
}

// Handle Restore Product (undo a soft delete)
//
// Soft delete exists so a mistake is recoverable, but until now nothing could
// recover it: the row was flagged is_deleted and there was no way back from any
// screen. Restoring deliberately leaves `available` at 0 — coming back from the
// archive should put the product in front of the owner for checking, not
// straight back on sale.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_product') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security CSRF validation failed.";
    } else {
        $resId = (int)($_POST['product_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE products SET is_deleted = 0 WHERE id = :id")->execute(['id' => $resId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'restore_product', "Restored product ID $resId");
            $successMsg = "Product restored. It is still unpublished — tick Published when you are ready.";
        } catch (PDOException $e) {
            $errorMsg = "Error restoring product: " . $e->getMessage();
        }
    }
}

/**
 * Erase an archived product and everything hanging off it, for good.
 *
 * Safe for order history: an order stores its own snapshot of what was bought in
 * orders.items_json — name, image, variant and price at the time — so a past
 * order still prints correctly after the product row is gone. That is why this
 * can be offered at all.
 *
 * The child rows go too. Without that, variants, colours, images and
 * specifications for a product that no longer exists sit in the database
 * forever, and the size-guide screen keeps offering a chart for a deleted item.
 * Wrapped in a transaction so a failure half way cannot leave a product with its
 * variants deleted and itself intact.
 */
/**
 * Duplicate a product into a genuinely independent one.
 *
 * The old implementation copied the `products` row and stopped. That left a
 * shell — no sizes, no colours, no photographs, no country prices — while
 * appending "-COPY" to the SKU (and "-COPY-COPY" on a copy of a copy) and
 * carrying the original's seo_url across verbatim, so two rows claimed one URL.
 *
 * Worse, because it created nothing under the new id, the new product silently
 * ADOPTED whatever child rows were already sitting under that id. Ids are
 * reused after deletion, and older versions of the purge left rows behind, so a
 * fresh product could arrive owning a deleted product's photographs — and, where
 * a stranded variant existed, that dead variant's PRICE, which the cart prefers
 * over the product's own.
 *
 * Everything here is written under the NEW id: colours are inserted first so
 * their old→new ids can be mapped, and variants are then remapped through that
 * map rather than keeping a color_id that still points into the original.
 *
 * @param array $orig The already-fetched source row (also used for messaging).
 * @return int        The new product's id.
 */
function dievonDuplicateProduct(PDO $pdo, int $sourceId, array $orig): int
{
    $uploadDir = __DIR__ . '/../uploads/products/';
    $row = $orig;
    unset($row['id'], $row['created_at'], $row['updated_at']);

    $row['name'] = $orig['name'] . ' (Copy)';

    /* A fresh code in the shop's own shape, not a suffix on someone else's.
       generateProductSku() is the same function the product form uses when
       publishing a product with an empty SKU box. */
    $row['sku'] = generateProductSku($pdo, $row['name'], 0, $row['category'] ?? null);

    // Its own address. Copying seo_url verbatim gave two products one URL.
    if (array_key_exists('seo_url', $row)) {
        $row['seo_url'] = !empty($orig['seo_url'])
            ? generateProductSlug($pdo, $row['name'])
            : null;
    }

    /* Give the copy its own files. With the filename copied verbatim both rows
       named one file — and because the product form unlinks the old file when
       an image is replaced, editing either product destroyed the other's
       picture. */
    foreach (['image', 'video_url'] as $fileCol) {
        if (!empty($row[$fileCol])) {
            $row[$fileCol] = duplicateUploadedFile($row[$fileCol], $uploadDir);
        }
    }

    /* A copy starts with no inventory of its own.
       ────────────────────────────────────────────────────────────────────────
       The products row was copied whole, which carried the source's stock across
       with it: total_stock (goods RECEIVED for the ORIGINAL), the damage/offline/
       online counters (that product's own audit history, one of which is filed
       with the tax return), and the legacy product-level stock_qty. Per-size
       stock is reset alongside the variants below. A clone is a new garment with
       nothing received yet — inheriting the original's sellable units and its
       past offline sales is exactly the phantom stock that oversells a piece that
       does not physically exist. Zeroed here so the copy reads sold-out until real
       stock is booked in, the same clean slate a hand-made product starts on. */
    foreach (['total_stock', 'damage_stock', 'sold_offline', 'sold_online', 'stock_qty'] as $stockCol) {
        if (array_key_exists($stockCol, $row)) { $row[$stockCol] = 0; }
    }

    /* A barcode is a GTIN — a globally unique identifier that ships as g:gtin in
       the Merchant feed and gtin13 in the product schema. Two live products
       sharing one is a duplicate-identifier disapproval waiting to happen, which
       is the same reason the SKU above is regenerated rather than shared. There
       is nothing to regenerate a barcode to, so the copy simply starts without
       one until the owner assigns its own. */
    if (array_key_exists('barcode', $row)) { $row['barcode'] = null; }

    $pdo->beginTransaction();
    try {
        $cols = array_keys($row);
        $pdo->prepare("INSERT INTO products (" . implode(', ', $cols) . ") VALUES (:" . implode(', :', $cols) . ")")
            ->execute($row);
        $newId = (int)$pdo->lastInsertId();

        /* Nothing may pre-exist under a brand-new id.
           This is the guard against inherited debris described above: whatever
           is keyed to this id belongs to a product that no longer exists. */
        $pdo->prepare("DELETE FROM product_color_images WHERE color_id IN (SELECT id FROM product_colors WHERE product_id = :id)")
            ->execute([':id' => $newId]);
        foreach (['product_variants', 'product_colors', 'product_images',
                  'product_country_prices', 'product_specifications',
                  'product_components', 'product_component_specifications'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE product_id = :id")->execute([':id' => $newId]); }
            catch (PDOException $e) { /* table absent on this install */ }
        }

        // ── Colours first, keeping old→new so variants can be remapped ──────
        $colorMap = [];
        $srcColors = $pdo->prepare("SELECT * FROM product_colors WHERE product_id = :id ORDER BY sort_order, id");
        $srcColors->execute([':id' => $sourceId]);
        foreach ($srcColors->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $oldColorId = (int)$c['id'];
            unset($c['id'], $c['created_at']);
            $c['product_id'] = $newId;
            if (!empty($c['thumbnail'])) {
                $c['thumbnail'] = duplicateUploadedFile($c['thumbnail'], $uploadDir);
            }
            // A colour SKU must not be shared either.
            if (!empty($c['sku'])) { $c['sku'] = $c['sku'] . '-' . $newId; }
            $cc = array_keys($c);
            $pdo->prepare("INSERT INTO product_colors (" . implode(', ', $cc) . ") VALUES (:" . implode(', :', $cc) . ")")
                ->execute($c);
            $colorMap[$oldColorId] = (int)$pdo->lastInsertId();
        }

        // ── Colour galleries, hung off the NEW colour ids ───────────────────
        foreach ($colorMap as $oldCid => $newCid) {
            $imgs = $pdo->prepare("SELECT image, sort_order FROM product_color_images WHERE color_id = :c ORDER BY sort_order, id");
            $imgs->execute([':c' => $oldCid]);
            foreach ($imgs->fetchAll(PDO::FETCH_ASSOC) as $im) {
                $pdo->prepare("INSERT INTO product_color_images (color_id, image, sort_order) VALUES (:c, :i, :s)")
                    ->execute([
                        ':c' => $newCid,
                        ':i' => duplicateUploadedFile($im['image'], $uploadDir),
                        ':s' => (int)$im['sort_order'],
                    ]);
            }
        }

        // ── Variants, with color_id translated through the map ──────────────
        $srcVars = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :id ORDER BY sort_order, id");
        $srcVars->execute([':id' => $sourceId]);
        foreach ($srcVars->fetchAll(PDO::FETCH_ASSOC) as $v) {
            unset($v['id'], $v['created_at']);
            $v['product_id'] = $newId;
            /* The whole point of the map. Left alone, a cloned size would still
               point at the ORIGINAL product's colour — the copy's stock and
               price would hang off another product's colourway. */
            $v['color_id'] = (!empty($v['color_id']) && isset($colorMap[(int)$v['color_id']]))
                ? $colorMap[(int)$v['color_id']]
                : null;
            /* No stock travels with the copy — see the products-row reset above.
               NULL is preserved so an untracked size stays untracked; a real
               count resets to 0 so the clone cannot sell units the original
               received. */
            if (array_key_exists('stock_qty', $v) && $v['stock_qty'] !== null) {
                $v['stock_qty'] = 0;
            }
            $vc = array_keys($v);
            $pdo->prepare("INSERT INTO product_variants (" . implode(', ', $vc) . ") VALUES (:" . implode(', :', $vc) . ")")
                ->execute($v);
        }

        // ── Gallery, country prices, specifications, components ─────────────
        $gal = $pdo->prepare("SELECT image, sort_order FROM product_images WHERE product_id = :id ORDER BY sort_order, id");
        $gal->execute([':id' => $sourceId]);
        foreach ($gal->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $pdo->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (:p, :i, :s)")
                ->execute([':p' => $newId, ':i' => duplicateUploadedFile($g['image'], $uploadDir), ':s' => (int)$g['sort_order']]);
        }

        $cp = $pdo->prepare("SELECT country_code, price, sale_price FROM product_country_prices WHERE product_id = :id");
        $cp->execute([':id' => $sourceId]);
        foreach ($cp->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pdo->prepare("INSERT INTO product_country_prices (product_id, country_code, price, sale_price) VALUES (:p,:c,:pr,:s)")
                ->execute([':p' => $newId, ':c' => $r['country_code'], ':pr' => $r['price'], ':s' => $r['sale_price']]);
        }

        /* General specification rows.
           The descriptive `products` columns (fabric, neck, composition, …) were
           copied whole with the row above, so the spec sheet that sits beside
           them on the product page has to travel too — otherwise the copy
           describes itself less fully than the original it was made from, and the
           section header here has claimed to copy them all along while the code
           did not. */
        $sp = $pdo->prepare("SELECT label, value, unit, sort_order FROM product_specifications WHERE product_id = :id ORDER BY sort_order, id");
        $sp->execute([':id' => $sourceId]);
        foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $pdo->prepare("INSERT INTO product_specifications (product_id, label, value, unit, sort_order) VALUES (:p,:l,:v,:u,:s)")
                ->execute([':p' => $newId, ':l' => $s['label'], ':v' => $s['value'], ':u' => $s['unit'], ':s' => (int)$s['sort_order']]);
        }

        /* Components and their own specification rows. A component's specs carry
           BOTH product_id and component_id, so each new component id has to be
           mapped before its rows can be re-pointed — the same old→new discipline
           the colours used above. */
        $comps = $pdo->prepare("SELECT id, name, sort_order FROM product_components WHERE product_id = :id ORDER BY sort_order, id");
        $comps->execute([':id' => $sourceId]);
        foreach ($comps->fetchAll(PDO::FETCH_ASSOC) as $comp) {
            $pdo->prepare("INSERT INTO product_components (product_id, name, sort_order) VALUES (:p,:n,:s)")
                ->execute([':p' => $newId, ':n' => $comp['name'], ':s' => (int)$comp['sort_order']]);
            $newCompId = (int)$pdo->lastInsertId();
            $cs = $pdo->prepare("SELECT label, value, unit, sort_order FROM product_component_specifications WHERE component_id = :cid AND product_id = :pid ORDER BY sort_order, id");
            $cs->execute([':cid' => (int)$comp['id'], ':pid' => $sourceId]);
            foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pdo->prepare("INSERT INTO product_component_specifications (product_id, component_id, label, value, unit, sort_order) VALUES (:p,:c,:l,:v,:u,:s)")
                    ->execute([':p' => $newId, ':c' => $newCompId, ':l' => $r['label'], ':v' => $r['value'], ':u' => $r['unit'], ':s' => (int)$r['sort_order']]);
            }
        }

        /* Reviews and questions are deliberately NOT copied: they were written
           by real customers about the original garment, and attributing them to
           a different product would be inventing testimony. */

        $pdo->commit();
        return $newId;
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function dievonPurgeProduct(PDO $pdo, int $pid): void {
    /* product_component_specifications was missing from this list.
       ────────────────────────────────────────────────────────────────────────
       It carries product_id like the rest, so it was purgeable all along — it
       simply was not named, and the measurements attached to every deleted
       product's pieces stayed behind. Found four such rows still describing a
       product 29 that no longer existed, alongside its two product_components
       rows: "Top — Length 42 inch", for a garment nobody could open.

       That is not merely untidy. MySQL reissues ids, so the next product to be
       given 29 would have inherited a previous garment's pieces and their
       measurements, and the product page would have shown them as its own.

       Listed immediately after product_components because it hangs off it —
       deleting the parent without the child is what created the orphans. */
    $children = [
        'product_variants', 'product_colors', 'product_images',
        'product_components', 'product_component_specifications',
        'product_specifications', 'product_questions',
        'product_reviews', 'product_country_prices', 'size_guide_charts',
        // Undo history for the charts on the line above. Once those are gone the
        // snapshots restore nothing, so leaving them behind keeps rows that
        // cannot be acted on.
        'size_guide_snapshots',
    ];

    /* The photographs, gathered BEFORE the rows that name them are deleted.
       ────────────────────────────────────────────────────────────────────────
       Purging removed every database row and left every JPEG on disk, for ever.
       Ten deleted products meant ten sets of orphaned photographs nobody could
       see, find or account for — which is what fills the media library with
       files no screen will ever show again.

       Read first, delete after: once the rows are gone the filenames are gone
       with them, and nothing is left to look the files up by.

       products.image_alt is deliberately NOT read. It is alt TEXT, not a
       filename; handing it to a file deleter asks the disk for a file named
       after a sentence.

       The unlinking itself happens after the commit, through
       deleteUploadedFileIfUnused(), which re-checks every file-naming column in
       the database first. uploads/products/ is shared with blog posts, banners
       and size guides, so a photograph this product used may still belong to
       one of those — that check is what stops a purge here blanking an image on
       the blog. It removes the .webp twin as well. */
    $doomedFiles = [];
    foreach ([['products', 'id'], ['product_images', 'product_id']] as [$tbl, $key]) {
        try {
            $q = $pdo->prepare("SELECT image FROM `$tbl` WHERE `$key` = :id AND image IS NOT NULL AND image <> ''");
            $q->execute([':id' => $pid]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fn) { $doomedFiles[basename($fn)] = true; }
        } catch (PDOException $e) { /* table absent on this install */ }
    }
    // Colour galleries hang off the COLOUR, so they are reached through it.
    try {
        $q = $pdo->prepare("SELECT ci.image FROM product_color_images ci
                             JOIN product_colors c ON c.id = ci.color_id
                            WHERE c.product_id = :id AND ci.image IS NOT NULL AND ci.image <> ''");
        $q->execute([':id' => $pid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fn) { $doomedFiles[basename($fn)] = true; }
    } catch (PDOException $e) { /* table absent on this install */ }
    /* order_items and stock_movements are deliberately NOT here, and must not be
       added. Both are historical records that happen to name a product rather
       than belonging to one: order_items holds real line items on real orders —
       41 of them currently point at products already deleted — and purging those
       would rewrite what a customer was actually sold and charged, taking the
       revenue and GST reports with it. stock_movements is the same argument for
       the stock ledger. A deleted product must lose its description, not its
       trading history. */
    $pdo->beginTransaction();
    try {
        /* Colour galleries hang off the COLOUR, not the product.
           ────────────────────────────────────────────────────────────────────
           product_color_images is keyed on color_id — it has no product_id
           column at all. It was in the list below, so the purge ran
           "DELETE FROM product_color_images WHERE product_id = …", which threw,
           and the catch swallowed it as "table or column absent here".

           The rows therefore survived every deletion. They belong to a product
           that no longer exists, appear on no screen, and still name their
           files — so the Media Library went on reporting those photographs as
           "In use" on a shop with no products left, with nothing to explain it.
           Sixteen such rows were sitting in this database.

           Deleted first, while product_colors can still tell us which colours
           belonged to this product. After that row goes, the link is gone. */
        try {
            $pdo->prepare(
                "DELETE FROM product_color_images
                  WHERE color_id IN (SELECT id FROM product_colors WHERE product_id = :id)"
            )->execute([':id' => $pid]);
        } catch (PDOException $e) {
            error_log("Purge: product_color_images cleanup failed for product $pid: " . $e->getMessage());
        }

        foreach ($children as $t) {
            // Tables differ between installs; a missing one must not abort the purge.
            try { $pdo->prepare("DELETE FROM `$t` WHERE product_id = :id")->execute([':id' => $pid]); }
            catch (PDOException $e) { /* table or column absent here */ }
        }
        $pdo->prepare("DELETE FROM products WHERE id = :id AND is_deleted = 1")->execute([':id' => $pid]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }

    /* Only now, and deliberately outside the transaction.
       ────────────────────────────────────────────────────────────────────────
       Unlinking cannot be rolled back. Inside the transaction a later failure
       would undo the rows and leave the photographs already destroyed — a
       product restored to its listing with every image gone. After the commit
       the rows are certainly gone, so the files are certainly unreferenced.

       A file that refuses to delete is logged, not raised: the product IS
       deleted by this point, and failing the request over a leftover JPEG would
       tell the owner the purge broke when it did not. The media library's
       unused-file sweep will catch anything left behind. */
    foreach (array_keys($doomedFiles) as $fn) {
        try {
            deleteUploadedFileIfUnused($pdo, $fn, __DIR__ . '/../uploads/products');
        } catch (Throwable $e) {
            error_log("Purge: could not remove image $fn for product $pid: " . $e->getMessage());
        }
    }
}

// Handle Permanent Delete (archived products only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'purge_product') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security CSRF validation failed.";
    } else {
        $pid = (int)($_POST['product_id'] ?? 0);
        try {
            // The is_deleted = 1 guard is deliberate: a live product can never be
            // erased by this route, however the request was formed.
            $chk = $pdo->prepare("SELECT name FROM products WHERE id = :id AND is_deleted = 1");
            $chk->execute([':id' => $pid]);
            $pname = $chk->fetchColumn();
            if ($pname === false) {
                $errorMsg = "That product is not archived, so it was not deleted.";
            } else {
                dievonPurgeProduct($pdo, $pid);
                logAdminAction($_SESSION['admin_id'] ?? 1, 'purge_product', "Permanently deleted product ID $pid ($pname)");
                $successMsg = "\"$pname\" permanently deleted.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Error deleting product: " . $e->getMessage();
        }
    }
}

// Handle Empty Archive (delete every archived product at once)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'empty_archive') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security CSRF validation failed.";
    } else {
        try {
            $ids = $pdo->query("SELECT id FROM products WHERE is_deleted = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $pid) { dievonPurgeProduct($pdo, (int)$pid); }
            logAdminAction($_SESSION['admin_id'] ?? 1, 'empty_archive', 'Emptied product archive (' . count($ids) . ' products)');
            $successMsg = count($ids) . " archived product(s) permanently deleted.";
        } catch (PDOException $e) {
            $errorMsg = "Error emptying archive: " . $e->getMessage();
        }
    }
}

// Handle Duplicate Product (POST only with CSRF check)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'duplicate_product') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security CSRF validation failed.";
    } else {
        $dupId = (int)($_POST['product_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
            $stmt->execute(['id' => $dupId]);
            $orig = $stmt->fetch();
            if ($orig) {
                $newId = dievonDuplicateProduct($pdo, $dupId, $orig);
                logAdminAction($_SESSION['admin_id'] ?? 1, 'duplicate_product', "Duplicated product ID $dupId as ID $newId");
                $successMsg = "Product duplicated as '{$orig['name']} (Copy)' — its sizes, colours and photographs were copied too. "
                            . "Open it to set its own price and SKU.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Error duplicating product: " . $e->getMessage();
        }
    }
}

// product_form.php redirects here after a successful save. A redirect is a
// fresh GET, so the outcome is signalled by the query string and turned into
// the same success banner the other actions use — without this, saving a
// product dropped the owner back on the list with no confirmation at all.
if (!empty($_GET['product_added'])) {
    $successMsg = 'Product added successfully.';
} elseif (!empty($_GET['product_updated'])) {
    $successMsg = 'Product updated successfully.';
}

// $productList, NOT $products.
//
// admin/includes/header.php:109 runs its own unfiltered
// "SELECT * FROM products ORDER BY id DESC" into $products, and it is included
// further down this file — so it silently overwrote whatever was queried here.
// The Live/Archived/All tabs highlighted correctly (they read $view) while the
// table below them always showed all 14 rows, because by render time $products
// was the header's copy. A distinct name is the fix; renaming the header's
// variable instead would risk every other admin screen that may rely on it.
$productList = [];
try {
    // View filter. The list used to show live and archived products identically,
    // with nothing to tell them apart — so archiving a product looked like it had
    // done nothing, because the row was still sitting there afterwards.
    // Two different states, previously conflated. is_deleted is whether a product
    // is ARCHIVED; available is whether it is PUBLISHED. The old "Live" tab
    // filtered only on the first, so it counted 11 published products and 2
    // drafts as one number — and there was no way at all to answer "how many
    // products are still drafts?".
    $view = $_GET['view'] ?? 'active';
    if (!in_array($view, ['active', 'published', 'draft', 'archived', 'all', 'women', 'men'], true)) { $view = 'active'; }

    $notArchived = "(is_deleted = 0 OR is_deleted IS NULL)";

    /**
     * SQL limiting products to one audience.
     *
     * A product carries no gender of its own — it inherits the audience of the
     * collection it sits in — so this resolves to the set of category ids (and,
     * for older rows with no category_id, names) on that side. Unisex categories
     * deliberately answer to both.
     *
     * Returns a condition that matches nothing when a side has no categories at
     * all, rather than an empty IN() which is a SQL syntax error.
     */
    $audienceSql = function (string $g) use ($pdo): string {
        if (!function_exists('categoryScopeForShopGender')) { return '1=1'; }
        $scope = categoryScopeForShopGender($g);
        $parts = [];
        if (!empty($scope['ids'])) {
            $parts[] = "category_id IN (" . implode(',', array_map('intval', $scope['ids'])) . ")";
        }
        if (!empty($scope['names'])) {
            $parts[] = "category IN (" . implode(',', array_map([$pdo, 'quote'], $scope['names'])) . ")";
        }
        return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
    };

    $whereView = [
        'active'    => "WHERE $notArchived",
        'published' => "WHERE available = 1 AND $notArchived",
        'draft'     => "WHERE available = 0 AND $notArchived",
        'archived'  => "WHERE is_deleted = 1",
        'all'       => "",
        'women'     => "WHERE $notArchived AND " . $audienceSql('women'),
        'men'       => "WHERE $notArchived AND " . $audienceSql('men'),
    ][$view];

    $productList = $pdo->query("SELECT * FROM products $whereView ORDER BY id DESC")->fetchAll();

    $cnt = fn(string $w) => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE $w")->fetchColumn();
    $activeCount    = $cnt($notArchived);
    $publishedCount = $cnt("available = 1 AND $notArchived");
    $draftCount     = $cnt("available = 0 AND $notArchived");
    $archivedCount  = $cnt("is_deleted = 1");
    $allCount       = $cnt("1");
    $womenCount     = $cnt("$notArchived AND " . $audienceSql('women'));
    $menCount       = $cnt("$notArchived AND " . $audienceSql('men'));
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;

// Plain sizes still missing their ladder code. Counted here so the one-shot tool
// that fills them in is findable on a fresh deployment — otherwise it is a URL
// nobody knows to type, and every product carries on sorting its sizes in the
// order they were entered. Same contextual-link pattern as size_guide.php.
$sizeCodeGaps = 0;
try {
    $sizeCodeGaps = (int)$pdo->query(
        "SELECT COUNT(*) FROM product_variants
          WHERE color_id IS NULL AND (size_code IS NULL OR size_code = '')"
    )->fetchColumn();
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>


<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">👗 Product Management</h1>
        <p class="admin-page-subtitle">Manage garments, prices, stock quantities, variants, and gallery images.</p>
    </div>
    <a href="product_form.php" class="btn-primary" style="padding: 10px 20px; font-size: 14px;">
        <i class="fa-solid fa-plus"></i> Add New Product
    </a>
</div>

<?php // Worded as a tidy-up rather than a fault: nothing is broken, these sizes
      // simply sort by entry order and the shop's size filter cannot group them. ?>
<?php if ($sizeCodeGaps > 0): ?>
<div style="margin-bottom:20px; padding:12px 16px; background:var(--bg-surface-soft); border:1px solid var(--border-light);
            border-left:3px solid var(--color-secondary); border-radius:8px; font-size:13px; line-height:1.6; color:var(--text-secondary);">
    <i class="fa-solid fa-ruler-combined" style="color:var(--color-secondary);"></i>
    <?= $sizeCodeGaps ?> product size<?= $sizeCodeGaps === 1 ? '' : 's' ?>
    <?= $sizeCodeGaps === 1 ? 'has' : 'have' ?> no ladder code yet, so <?= $sizeCodeGaps === 1 ? 'it sorts' : 'they sort' ?>
    in the order <?= $sizeCodeGaps === 1 ? 'it was' : 'they were' ?> typed and the shop's size filter cannot group
    <?= $sizeCodeGaps === 1 ? 'it' : 'them' ?> with the same size on other products —
    <a href="backfill_size_codes.php" style="font-weight:700;">review and fill them in</a>. Nothing is deleted or renamed.
</div>
<?php endif; ?>

<?php /* Live / Archived / All. Archiving is a soft delete — the row survives so
         past orders and reports keep their references — but until now there was
         no screen that separated the two, and no way at all to get a product
         back or to finally be rid of one. */ ?>
<div class="prod-view-tabs">
    <?php foreach ([
        'active'    => 'Active (' . (int)$activeCount . ')',
        'published' => 'Published (' . (int)$publishedCount . ')',
        'draft'     => 'Draft (' . (int)$draftCount . ')',
        'archived'  => 'Archived (' . (int)$archivedCount . ')',
        'all'       => 'All (' . (int)$allCount . ')',
    ] as $key => $label): ?>
        <a href="products.php?view=<?= $key ?>"
           class="btn-sm prod-view-tab <?= $view === $key ? 'btn-sm-primary' : 'btn-sm-outline' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>

    <?php /* Audience is a different axis from status — a product is published OR
             draft, and separately for women OR men — so these two sit behind a
             divider rather than reading as more status tabs. Both exclude
             archived products, matching the Active tab they sit beside. */ ?>
    <span class="prod-tab-divider"></span>
    <?php foreach ([
        'women' => 'Woman (' . (int)$womenCount . ')',
        'men'   => 'Man (' . (int)$menCount . ')',
    ] as $key => $label): ?>
        <a href="products.php?view=<?= $key ?>"
           class="btn-sm prod-view-tab <?= $view === $key ? 'btn-sm-primary' : 'btn-sm-outline' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>

    <?php if ($view === 'archived' && $archivedCount > 0): ?>
        <form method="POST" action="products.php?view=archived" class="prod-tabs-spacer"
              onsubmit="return dvConfirmForm(this,'Permanently delete ALL <?= (int)$archivedCount ?> archived product(s)? This cannot be undone. Past orders are unaffected — they keep their own record of what was bought.');">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="empty_archive">
            <button type="submit" class="btn-sm btn-sm-danger prod-view-tab">
                <i class="fa-solid fa-trash"></i> Empty Archive (<?= (int)$archivedCount ?>)
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<div class="glass-panel" style="padding:24px; overflow:hidden;">
    <?php if (empty($productList)): ?>
    <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
        <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">👗</div>
        <p style="font-size:16px;">No products found in catalogue.</p>
        <a href="product_form.php" class="btn-primary" style="margin-top:20px; display:inline-flex;">
            <i class="fa-solid fa-plus"></i> Add First Product
        </a>
    </div>
    <?php else: ?>
    
    <!-- Filter & Sort Bar -->
    <div style="display:flex; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; background:var(--bg-surface-soft); padding:14px; border-radius:var(--radius-sm); border:1px solid var(--border-light);">
        <div style="display:flex; align-items:center; gap:8px;">
            <label for="prodFilterCategory" style="font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Category:</label>
            <select id="prodFilterCategory" class="form-control" style="font-size:13px; padding:6px 12px; height:auto; width:auto; min-width:160px;" onchange="filterAndSortProducts()">
                <option value="all">🔍 All Categories</option>
                <?php
                // From $productList too, so the filter offers exactly the categories
                // present in the rows below it. Reading $products here would have
                // listed categories from the header's unfiltered copy, letting you
                // pick one with nothing to show on the current tab.
                $prodCats = array_unique(array_column($productList, 'category'));
                sort($prodCats);
                foreach ($prodCats as $catName): 
                ?>
                <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="display:flex; align-items:center; gap:8px;">
            <label for="prodSort" style="font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Sort By:</label>
            <select id="prodSort" class="form-control" style="font-size:13px; padding:6px 12px; height:auto; width:auto; min-width:160px;" onchange="filterAndSortProducts()">
                <option value="default">Default (Newest)</option>
                <option value="name_asc">Name (A-Z)</option>
                <option value="name_desc">Name (Z-A)</option>
                <option value="price_asc">Price (Low to High)</option>
                <option value="price_desc">Price (High to Low)</option>
            </select>
        </div>
    </div>

    <div class="table-wrapper">
        <table class="data-table prod-table" id="productsTable">
            <thead>
                <tr>
                    <th style="width:70px;">Image</th>
                    <th>Product Name &amp; Details</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock Status</th>
                    <th style="width:180px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productList as $p): 
                    $imgSrc = '';
                    if (!empty($p['image'])) {
                        if (file_exists(__DIR__ . '/../uploads/products/' . $p['image'])) {
                            $imgSrc = SITE_URL . '/uploads/products/' . htmlspecialchars($p['image']);
                        } elseif (file_exists(__DIR__ . '/../uploads/gallery/' . $p['image'])) {
                            $imgSrc = SITE_URL . '/uploads/gallery/' . htmlspecialchars($p['image']);
                        }
                    }
                ?>
                <tr data-category="<?= htmlspecialchars($p['category']) ?>" data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>" data-price="<?= $p['price'] ?>">
                    <td>
                        <?php if ($imgSrc): ?>
                            <img src="<?= $imgSrc ?>" style="width:55px; height:65px; object-fit:cover; border-radius:4px; border:1px solid var(--border-light);">
                        <?php else: ?>
                            <div style="width:55px; height:65px; background:var(--bg-surface-soft); border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:20px;"><?= htmlspecialchars($p['emoji'] ?? '✨') ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="font-size:15px; color:var(--text-primary); display:block;"><?= htmlspecialchars($p['name']) ?></strong>
                        <?php /* Archived rows were listed exactly like live ones. Deleting a
                                 product therefore looked like it had done nothing at all: the
                                 row was still there, unchanged, after the page reloaded. */ ?>
                        <?php if (!empty($p['is_deleted'])): ?>
                            <span class="prod-badge prod-badge--archived">Archived</span>
                        <?php elseif (empty($p['available'])): ?>
                            <?php /* Draft, i.e. saved but not published. Distinct from
                                     Archived: a draft is on its way onto the shop, an
                                     archived product has been taken off it. Both are
                                     invisible to shoppers, which is exactly why the row
                                     has to say which one it is. */ ?>
                            <span class="prod-badge prod-badge--draft">Draft</span>
                        <?php endif; ?>
                        <?php /* productDisplayCode(), not a second fallback written here.
                                 This row built its own — 'DV-' . id — while the shop, the
                                 product page and the structured data all use the shared
                                 function, which zero-pads. So a product with no SKU of its
                                 own appeared as DV-0007 to the customer and DV-7 to you:
                                 a shopper quoting their code, and you searching this list
                                 for it, were looking for different strings for the same
                                 piece. That mismatch is the exact thing the shared function
                                 exists to prevent; this screen was the one place still
                                 answering it separately. */ ?>
                        <span style="font-size:12px; color:var(--text-muted);">SKU: <code><?= htmlspecialchars(productDisplayCode($p)) ?></code></span>
                        <?php
                        /* Named supplier, no design number of theirs.
                           ────────────────────────────────────────────
                           The SKU above is OURS — generated here, and the supplier
                           has never seen it. Reordering needs THEIR number, so a
                           product in this state cannot be restocked from the person
                           who made it, and the row gave no sign of that until the
                           piece had already sold. admin/orders.php says the same
                           thing on a line that has already gone out; this is the
                           same fact early enough to act on.

                           Only when a supplier IS named: with nobody recorded there
                           is no one to get a number from, which is a different gap
                           and not one this badge can help with.

                           Not on archived rows — those are off the shop and are not
                           being reordered, so the badge would be pure noise. */
                        $pNeedsDesignNo = empty($p['is_deleted'])
                            && trim((string)($p['supplier_name'] ?? '')) !== ''
                            && trim((string)($p['supplier_ref']  ?? '')) === '';
                        ?>
                        <?php if ($pNeedsDesignNo): ?>
                            <span class="prod-badge prod-badge--nodesign"
                                  title="No design no. recorded for <?= htmlspecialchars($p['supplier_name']) ?> — you cannot reorder this from them. Add it under Supplier's design no.">
                                ⚠ No design no.
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-size:12px; font-weight:700; background:var(--bg-surface-soft); padding:4px 8px; border-radius:4px; color:var(--color-primary);"><?= htmlspecialchars($p['category']) ?></span>
                        <?php
                        /* Who the product is for, shown beside the category rather than
                           in a column of its own — because it IS the category. A product
                           has no gender field; it inherits the audience of the collection
                           it sits in, so putting the two side by side shows the cause
                           next to the effect.

                           This is also the fastest way to spot a mis-filed product: a
                           women's garment reading MEN here is exactly the mistake that
                           put "Iris Straight Crepe Pants" on the menswear side and made
                           the whole shop advertise menswear it did not stock. */
                        $pAudience = function_exists('productGender') ? productGender($p) : 'women';
                        $pAudLabel = ['women' => 'Woman', 'men' => 'Man', 'unisex' => 'Unisex'][$pAudience] ?? ucfirst($pAudience);
                        $pAudClass = 'prod-audience prod-audience--'
                            . (in_array($pAudience, ['women','men','unisex'], true) ? $pAudience : 'women');
                        ?>
                        <span class="<?= $pAudClass ?>" title="Audience comes from the collection this product is in">
                            <?= htmlspecialchars($pAudLabel) ?>
                        </span>
                    </td>
                    <td>
                        <strong style="font-size:14px; color:var(--text-primary);"><?= currencySymbol() ?><?= number_format($p['price'], 2) ?></strong>
                    </td>
                    <td>
                        <?php
                        // Sellable stock is what checkout actually enforces — for
                        // tracked products: variant stock when size variants exist,
                        // otherwise total_stock − damage_stock − sold_offline − sold_online.
                        // The legacy stock_qty column is dead here and misled the listing
                        // into "Out of Stock" for products with plenty left.
                        $sellableStock = null;
                        if (!empty($p['track_stock'])) {
                            $sellableStock = productSellableStock($pdo, $p);
                        }
                        ?>
                        <?php if (!empty($p['track_stock'])): ?>
                            <?php if ($sellableStock >= 1000000): ?>
                                <span class="badge-luxury" style="background:#ecfdf5; color:#10b981;" title="Size-level stock not tracked — treated as unlimited">🟢 In Stock</span>
                            <?php elseif ($sellableStock > 5): ?>
                                <span class="badge-luxury" style="background:#ecfdf5; color:#10b981;">🟢 In Stock (<?= $sellableStock ?>)</span>
                            <?php elseif ($sellableStock > 0): ?>
                                <span class="badge-luxury" style="background:#fffbeb; color:#f59e0b;">⚠️ Low Stock (<?= $sellableStock ?>)</span>
                            <?php else: ?>
                                <span class="badge-luxury" style="background:#fef2f2; color:#ef4444;">🔴 Out of Stock</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:11px; color:var(--text-muted);">∞ Available</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php /* One flex row of equal square buttons. Previously each action
                                 was an inline <form> with its own padding and margin, which
                                 was fine at three buttons and broke at four: archived rows
                                 wrapped, dropping the delete button onto a second line
                                 underneath the restore. Uniform 32px targets in a nowrap row
                                 keep every row the same height whatever it offers. */ ?>
                        <div class="prod-actions">
                            <a href="product_form.php?id=<?= $p['id'] ?>" class="prod-action-btn is-primary" title="Edit product" aria-label="Edit <?= htmlspecialchars($p['name']) ?>">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <form method="POST" action="products.php">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="duplicate_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="prod-action-btn" title="Duplicate product" aria-label="Duplicate <?= htmlspecialchars($p['name']) ?>">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </form>
                            <?php if (!empty($p['is_deleted'])): ?>
                            <form method="POST" action="products.php?view=<?= htmlspecialchars($view) ?>" onsubmit="return dvConfirmForm(this,'Restore product &quot;<?= addslashes($p['name']) ?>&quot; from the archive?');">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="restore_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="prod-action-btn is-restore" title="Restore from archive" aria-label="Restore <?= htmlspecialchars($p['name']) ?>">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </button>
                            </form>
                            <?php /* Erase for good. Offered only on already-archived rows, so a
                                     live product can never be destroyed in one click. */ ?>
                            <form method="POST" action="products.php?view=<?= htmlspecialchars($view) ?>" onsubmit="return dvConfirmForm(this,'Permanently delete &quot;<?= htmlspecialchars(addslashes($p['name'] ?? ''), ENT_QUOTES) ?>&quot;? This cannot be undone. Past orders are unaffected — they keep their own record of what was bought.');">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="purge_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="prod-action-btn is-danger" title="Delete permanently" aria-label="Permanently delete <?= htmlspecialchars($p['name']) ?>">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="products.php?view=<?= htmlspecialchars($view) ?>" onsubmit="return dvConfirmForm(this,'Archive product &quot;<?= addslashes($p['name']) ?>&quot;?');">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="prod-action-btn is-danger" title="Archive product" aria-label="Archive <?= htmlspecialchars($p['name']) ?>">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
function filterAndSortProducts() {
    const cat = document.getElementById('prodFilterCategory').value;
    const sort = document.getElementById('prodSort').value;
    const rows = Array.from(document.querySelectorAll('#productsTable tbody tr'));

    rows.forEach(row => {
        const rowCat = row.getAttribute('data-category');
        if (cat === 'all' || rowCat === cat) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    if (sort !== 'default') {
        const tbody = document.querySelector('#productsTable tbody');
        rows.sort((a, b) => {
            const nameA = a.getAttribute('data-name');
            const nameB = b.getAttribute('data-name');
            const priceA = parseFloat(a.getAttribute('data-price'));
            const priceB = parseFloat(b.getAttribute('data-price'));

            if (sort === 'name_asc') return nameA.localeCompare(nameB);
            if (sort === 'name_desc') return nameB.localeCompare(nameA);
            if (sort === 'price_asc') return priceA - priceB;
            if (sort === 'price_desc') return priceB - priceA;
            return 0;
        });
        rows.forEach(row => tbody.appendChild(row));
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
