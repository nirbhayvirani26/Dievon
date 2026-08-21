<?php
// ============================================================
//  Dievon – Admin: Add / Edit Product Form
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
requireAdminCapability('catalogue.manage');


$activeTab = 'products';
$hideHeaderTitle = true;

$isEdit  = isset($_GET['id']) || (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0);
$product = null;
$errors  = [];

// ── The shop's colour vocabulary ────────────────────────────────────────────
// Colours & Sizes maintains a master list, and this form never looked at it —
// every colour box was free text, so the list could only be chosen from by
// remembering what was in it. Typing invites drift: "Emerald Green", "emerald
// green" and "Emrald Green" become three separate options in the shop's colour
// filter, each matching one product. The seeded data already shows the start of
// it — "red" appears twice, and two live products use colours the master list
// has never heard of.
//
// Offered as a datalist rather than a <select>, so a one-off colour can still be
// typed without first adding it to Colours & Sizes.
$masterColors = [];
try {
    $masterColors = $pdo->query(
        "SELECT name FROM product_attributes WHERE attr_type = 'color' ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ── Suggestions for the rest of the fashion fields ──────────────────────────
// Fabric, sleeve, neck, pattern and occasion each drive a filter on the shop,
// and every one of those filter lists is built from the DISTINCT values found
// on products. So the spelling typed here IS the filter option: "Georgette",
// "georgette" and "Georgett" become three separate tick boxes, each matching a
// single product.
//
// There is no master table for these the way there is for colour, so the
// vocabulary is the catalogue's own — every value already in use is offered
// back. Self-correcting: type it once and it is suggested from then on.
$fashionSuggestions = [];
foreach (['fabric', 'sleeve', 'neck', 'pattern', 'occasion', 'composition'] as $specCol) {
    try {
        $fashionSuggestions[$specCol] = $pdo->query(
            "SELECT DISTINCT `$specCol` FROM products
              WHERE `$specCol` IS NOT NULL AND `$specCol` <> ''
              ORDER BY `$specCol` ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { $fashionSuggestions[$specCol] = []; }
}

// Brands are a closed list — product_form.php already REJECTS a brand that is
// not the shop's own, so suggesting anything else would offer a value the save
// will refuse.
$brandSuggestions = [SHOP_NAME];
try {
    foreach ($pdo->query("SELECT name FROM brands ORDER BY name ASC") as $bRow) {
        $brandSuggestions[] = $bRow['name'];
    }
} catch (PDOException $e) {}
$brandSuggestions = array_values(array_unique(array_filter(array_map('trim', $brandSuggestions))));

// A gallery held from a failed save belongs to THAT attempt only.
//
// Opening the form fresh — a new product, or a different one to edit — drops
// the hold along with its files, so images picked for one product can never
// reappear on another, and an abandoned attempt does not leave them on disk.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['dv_pending_gallery'])) {
    foreach ((array)$_SESSION['dv_pending_gallery'] as $stalePending) {
        $stalePath = __DIR__ . '/../uploads/products/' . basename((string)$stalePending);
        if (is_file($stalePath)) {
            // deleteUploadedFile() removes the .webp twin generated alongside it.
            if (function_exists('deleteUploadedFile')) {
                deleteUploadedFile(basename((string)$stalePending), __DIR__ . '/../uploads/products/');
            } else {
                @unlink($stalePath);
            }
        }
    }
    unset($_SESSION['dv_pending_gallery']);
}

// ── Load existing product for edit ───────────────────────
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute(['id' => (int)$_GET['id']]);
    $product = $stmt->fetch();
    if (!$product) { header('Location: products.php'); exit; }
}

/* ── Current in-stock, the same figure everything else uses ─────────────────
   This did the product-level subtraction by hand — total_stock − damage −
   sold_offline − sold_online — and ignored the sizes entirely. On a product
   whose stock lives on its variants the two numbers are not the same thing:
   Adara reads 40 by that sum while its four sizes hold 35 between them.

   So the edit screen said "In Stock: 40", the products list beside it said
   "IN STOCK (35)", and checkout would refuse the 36th. Three screens, two
   answers, and the one the owner edits stock on was the wrong one — the
   overstated figure is exactly the kind that gets a garment oversold.

   productSellableStock() is what the listing, the storefront and the checkout
   gate all read. One definition, so they cannot disagree again. */
$displayInStock = null;
if ($product && !empty($product['track_stock'])) {
    $displayInStock = function_exists('productSellableStock')
        ? max(0, productSellableStock($pdo, $product))
        : max(0, (int)($product['total_stock'] ?? $product['stock_qty'] ?? 0)
                 - (int)($product['damage_stock'] ?? 0)
                 - (int)($product['sold_offline'] ?? 0)
                 - (int)($product['sold_online']  ?? 0));
}

// ── Load categories from DB ──────────────────────────────
// Grouped under their main collection rather than the flat list of bare names
// this used to be. That list printed every category — main and sub together — in
// sort order, so "Man" and "Shirt" appeared as two unrelated entries and nothing
// on screen said one belonged to the other.
//
// Main collections stay selectable: real products are filed directly on Kurtis,
// Coord Sets and Bottoms today, so disabling them would break editing those.
$categoryGroups = [];
try {
    $cats = $pdo->query("SELECT id, name, parent_id FROM categories ORDER BY sort_order ASC, name ASC")
                ->fetchAll(PDO::FETCH_ASSOC);

    $byParent = [];
    foreach ($cats as $c) { $byParent[(int)$c['parent_id']][] = $c; }

    $emitted = [];
    foreach ($byParent[0] ?? [] as $top) {
        $items = [['id' => (int)$top['id'], 'label' => $top['name'], 'is_sub' => false]];
        $emitted[(int)$top['id']] = true;
        foreach ($byParent[(int)$top['id']] ?? [] as $child) {
            $items[] = ['id' => (int)$child['id'], 'label' => $child['name'], 'is_sub' => true];
            $emitted[(int)$child['id']] = true;
        }
        // Audience is a property of the main collection; sub-categories inherit it,
        // which is why it is stored once per group rather than per item.
        $categoryGroups[] = [
            'name'   => $top['name'],
            'gender' => categoryGender((int)$top['id']),
            'items'  => $items,
        ];
    }

    // A sub-category whose parent row has been deleted would otherwise vanish
    // from this dropdown entirely, making the products inside it uneditable.
    $orphans = [];
    foreach ($cats as $c) {
        if (empty($emitted[(int)$c['id']])) {
            $orphans[] = ['id' => (int)$c['id'], 'label' => $c['name'], 'is_sub' => false];
        }
    }
    if ($orphans) {
        $categoryGroups[] = ['name' => 'Uncategorised', 'gender' => 'women', 'items' => $orphans];
    }
} catch (PDOException $e) {
    $categoryGroups = [];
}
$badges = ['', 'New', 'Hot', 'Best Seller'];

// Per-category tax defaults, for the read-only HSN/GST display in Basic Info.
// Keyed by category id so the form can show the value of whichever collection
// is selected without a round-trip. Set under Categories → edit.
$catTaxMap = [];
try {
    foreach ($pdo->query("SELECT id, default_hsn_code, default_gst_rate FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $ct) {
        $catTaxMap[(int)$ct['id']] = [
            'hsn'  => trim((string)($ct['default_hsn_code'] ?? '')),
            'gst'  => (float)($ct['default_gst_rate'] ?? 0),
            // Flagged for the script below, which has to recompute the slab as
            // the selling price is typed rather than showing one fixed number.
            'auto' => categoryGstIsAuto($ct['default_gst_rate'] ?? null),
            'zero' => abs((float)($ct['default_gst_rate'] ?? 0) - CATEGORY_GST_ZERO) < 0.005,
        ];
    }
} catch (PDOException $e) {}

// Price Includes GST is a store-wide policy (set under Settings → Store General
// Settings → Invoice & GST details), not a per-product choice. The form shows it
// read-only so no single product can disagree with the store.
try {
    $storePriceIncludesGst = ((string)storeSetting($pdo, 'price_includes_gst', '1')) === '1';
} catch (Throwable $e) {
    $storePriceIncludesGst = true;
}

// ── Load existing variants and images for edit ──────────────────────
$existingVariants = [];
$additionalImages = [];
$existingColors   = [];
// Ladder for THIS product's category, not a fixed women's list. The rung label
// picked here is the string saved onto the variant and later shown to the
// shopper on the product page, so serving 32/S on a men's shirt would not just
// look wrong in admin — it would write the wrong size into the database.
// Colour/size rows only render for an already-saved product, so by this point
// $product is loaded (line 30) and its category is known.
$sizeLadder       = sizeLadderForCategory((int)($product['category_id'] ?? 0));
if (isset($_GET['id']) || (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0)) {
    $editPid = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
    if ($editPid > 0) {
        try {
            // Plain (colour-less) size variants — legacy behaviour, unchanged
            $vRows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND color_id IS NULL ORDER BY sort_order ASC, id ASC");
            $vRows->execute(['pid' => $editPid]);
            $existingVariants = $vRows->fetchAll();

            $imgRows = $pdo->prepare("SELECT * FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $imgRows->execute(['pid' => $editPid]);
            $additionalImages = $imgRows->fetchAll();

            // Colour variants, each with its own gallery images + size/stock rows
            $cRows = $pdo->prepare("SELECT * FROM product_colors WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $cRows->execute(['pid' => $editPid]);
            $existingColors = $cRows->fetchAll();
            foreach ($existingColors as &$col) {
                $ciStmt = $pdo->prepare("SELECT * FROM product_color_images WHERE color_id = :cid ORDER BY sort_order ASC, id ASC");
                $ciStmt->execute(['cid' => $col['id']]);
                $col['images'] = $ciStmt->fetchAll();

                $cvStmt = $pdo->prepare("SELECT * FROM product_variants WHERE color_id = :cid ORDER BY sort_order ASC, id ASC");
                $cvStmt->execute(['cid' => $col['id']]);
                $col['sizes'] = $cvStmt->fetchAll();
            }
            unset($col);

            // General (product-level) specifications
            $gsStmt = $pdo->prepare("SELECT * FROM product_specifications WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $gsStmt->execute(['pid' => $editPid]);
            $existingGeneralSpecs = $gsStmt->fetchAll();

            // Components, each with its own nested specifications
            $compStmt = $pdo->prepare("SELECT * FROM product_components WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $compStmt->execute(['pid' => $editPid]);
            $existingComponents = $compStmt->fetchAll();
            foreach ($existingComponents as &$comp) {
                $csStmt = $pdo->prepare("SELECT * FROM product_component_specifications WHERE component_id = :cid ORDER BY sort_order ASC, id ASC");
                $csStmt->execute(['cid' => $comp['id']]);
                $comp['specs'] = $csStmt->fetchAll();
            }
            unset($comp);
        } catch (PDOException $e) { }
    }
}

// ── Per-country prices, keyed by country code ───────────────────────
// A product with no row for a country is not sold there. Only enabled
// non-home countries get an input below; the home country always uses
// products.price.
$existingCountryPrices = [];
if (isset($editPid) && $editPid > 0) {
    try {
        $cpStmt = $pdo->prepare("SELECT country_code, price, sale_price FROM product_country_prices WHERE product_id = :pid");
        $cpStmt->execute(['pid' => $editPid]);
        foreach ($cpStmt->fetchAll() as $cpRow) {
            $existingCountryPrices[strtoupper($cpRow['country_code'])] = $cpRow;
        }
    } catch (PDOException $e) {}
}
$sellableCountries = array_filter(enabledCountries(), fn($c) => (int)$c['is_home'] !== 1);

$specSuggestions      = require __DIR__ . '/../config/spec_suggestions.php';
$existingGeneralSpecs = $existingGeneralSpecs ?? [];
$existingComponents   = $existingComponents ?? [];

// ── Handle form submission ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF gate. The form is a plain multipart POST (not one of the fetch
    // wrappers that already send window.ADMIN_CSRF_TOKEN), so the token must be
    // verified here on the server — every other mutating endpoint in this admin
    // requires one; this form was the last that did not, which let a cross-site
    // POST create or rewrite products from another origin's page.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_flash_error'] = 'Your session expired. Please review the form and save again.';
        header('Location: product_form.php' . (!empty($_POST['product_id']) ? '?id=' . (int)$_POST['product_id'] : ''));
        exit;
    }

    $productId   = (int)($_POST['product_id'] ?? 0);
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = (float)($_POST['price']    ?? 0);
    // The form posts the category ID, and the NAME is read back from it. The name
    // is still stored alongside: several storefront pages read
    // $product['category'] directly, and it is the fallback if a category is ever
    // deleted.
    //
    // This used to run the other way round — the form posted the name and this
    // resolved it with "WHERE name = ... LIMIT 1". That works only while every
    // category name is unique. The day menswear adds its own "Bottoms" or
    // "Shirts" beside the women's one, LIMIT 1 silently returns whichever row
    // sorts first, filing men's stock as womenswear with nothing on screen to
    // show it had happened. An id cannot be ambiguous.
    $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
    $category    = '';
    if ($category_id !== null) {
        $catLookup = $pdo->prepare("SELECT name FROM categories WHERE id = :id");
        $catLookup->execute([':id' => $category_id]);
        $category = (string)($catLookup->fetchColumn() ?: '');
        // Deleted between the form loading and being submitted. Blank the id too,
        // so the "Please select a category" check below catches it rather than
        // writing a product pointed at a row that is gone.
        if ($category === '') { $category_id = null; }
    }
    $emoji       = trim($_POST['emoji']       ?? '✨');
    $badge       = trim($_POST['badge']       ?? '');
    $available    = isset($_POST['available'])    ? 1 : 0;
    // Same problem as related_ids: no input posts this, so every save forced it
    // to 0. Left alone when absent. (It is a leftover from a food-shop template
    // and nothing on the storefront reads it — but silently rewriting a stored
    // value on every edit is wrong regardless of whether anything uses it.)
    $nuts_allergy = array_key_exists('nuts_allergy', $_POST) ? (int)(bool)$_POST['nuts_allergy'] : null;
    $track_stock = isset($_POST['track_stock']) ? 1 : 0;
    $stock_qty   = max(0, (int)($_POST['stock_qty'] ?? 0));
    
    // Dynamic specifications
    // Atelier Code no longer has an input on this form — it was an internal
    // reference nobody tracked, and the Merchant feed already handles products
    // without an MPN (it declares g:identifier_exists=no, which is the correct
    // flag for self-made garments). Absent here means "keep what is stored", so
    // editing an older product never wipes a value it already had.
    $atelier_code = array_key_exists('atelier_code', $_POST) ? trim((string)$_POST['atelier_code']) : null;
    // Same rule as the line above: absent means "keep what is stored", so a
    // save from a screen that does not post the field cannot wipe it.
    $supplier_ref  = array_key_exists('supplier_ref',  $_POST) ? trim((string)$_POST['supplier_ref'])  : null;

    /* ── Which supplier this piece comes from ──────────────────────────────
       The form now posts a supplier_id chosen from the Suppliers screen, so
       nothing about the supplier is edited here. supplier_name is still written
       alongside it, because actions/checkout_action.php freezes the NAME into
       the order snapshot at the time of sale — an order must go on naming
       whoever actually supplied it after the supplier is renamed or removed.

       Absent from the POST means "leave alone", matching every other field. */
    /* COALESCE cannot express "the owner chose none".
       ────────────────────────────────────────────────────────────────────────
       Every other field on this form uses COALESCE(:x, x) so that a screen which
       does not post a field leaves it alone. For a <select> that breaks: picking
       "— none —" posts an empty value, which becomes NULL, which COALESCE reads
       as "not supplied" and keeps the OLD supplier. The supplier could be changed
       but never cleared.

       So these two are resolved to a concrete value here and written directly.
       When the field is genuinely absent, the stored values are loaded first, which
       preserves the leave-alone behaviour without letting NULL mean two things. */
    $supplier_id   = null;
    $supplier_name = null;
    if (!array_key_exists('supplier_id', $_POST) && $productId) {
        try {
            $curSup = $pdo->prepare("SELECT supplier_id, supplier_name FROM products WHERE id = :id");
            $curSup->execute([':id' => $productId]);
            if ($row = $curSup->fetch(PDO::FETCH_ASSOC)) {
                $supplier_id   = $row['supplier_id'] !== null ? (int)$row['supplier_id'] : null;
                $supplier_name = $row['supplier_name'];
            }
        } catch (PDOException $e) { /* leave both null */ }
    }
    if (array_key_exists('supplier_id', $_POST)) {
        $supplier_id = (int)$_POST['supplier_id'] ?: null;
        if ($supplier_id !== null) {
            try {
                $supLookup = $pdo->prepare("SELECT name FROM suppliers WHERE id = :id");
                $supLookup->execute([':id' => $supplier_id]);
                $supplier_name = (string)$supLookup->fetchColumn() ?: null;
                if ($supplier_name === null) { $supplier_id = null; }   // stale id
            } catch (PDOException $e) { $supplier_id = null; }
        } else {
            $supplier_name = '';   // "none" chosen: clear it deliberately
        }
    }
    $color_way    = trim($_POST['color_way']    ?? '');
    // Sourcing is the same for every product — the whole range is sourced from
    // India — so the form has no input for it and every save writes INDIA.
    $sourcing = 'INDIA';
    $composition  = trim($_POST['composition']  ?? '');

    // Advanced features
    $brand        = trim($_POST['brand'] ?? '');
    // Color Way and the old Color box held the same thing, so the separate Color
    // field has been removed. The stored `color` column still feeds the shop
    // filter, the product schema and the product page, so every save mirrors
    // color_way into it — one value, one place to type it. Truncated to the
    // width of the `color` column (50) so a long colour name cannot overflow it.
    $color = mb_substr($color_way, 0, 50);
    $fabric       = trim($_POST['fabric'] ?? '');
    $sleeve       = trim($_POST['sleeve'] ?? '');
    // Fit / model reference — see the form comment: no invented defaults.
    $image_alt       = trim($_POST['image_alt'] ?? '');
    $fit_description = trim($_POST['fit_description'] ?? '');
    $model_height    = trim($_POST['model_height'] ?? '');
    $model_size_worn = trim($_POST['model_size_worn'] ?? '');
    $neck         = trim($_POST['neck'] ?? '');
    $pattern      = trim($_POST['pattern'] ?? '');
    $occasion     = trim($_POST['occasion'] ?? '');
    // The input is gone (see the note in the fashion fields below), so absent now
    // means "keep what is stored" rather than "reset to 0" — the same rule as
    // related_ids and nuts_allergy. Nothing reads this column, but destroying a
    // stored value on every save would still be wrong.
    $discount_percentage = array_key_exists('discount_percentage', $_POST)
        ? (int)$_POST['discount_percentage']
        : null;
    $video_url    = trim($_POST['video_url'] ?? '');
    $wash_care    = trim($_POST['wash_care'] ?? '');
    // These two no longer have inputs on this form — the same text applies to
    // every product, so nothing is stored per product any more. The product page
    // already shows its shop-wide default for products without a stored value.
    // Absent here means "keep what is stored" rather than "clear", so editing an
    // older product never wipes a value it already had.
    $shipping_info = array_key_exists('shipping_info', $_POST) ? trim((string)$_POST['shipping_info']) : null;
    $returns_info  = array_key_exists('returns_info',  $_POST) ? trim((string)$_POST['returns_info'])  : null;

    // SKU, Barcode, Weight, Dimensions, Tags & SEO
    $sku          = trim($_POST['sku'] ?? '');
    $barcode      = trim($_POST['barcode'] ?? '');
    $weight       = trim($_POST['weight'] ?? '');
    $dimensions   = trim($_POST['dimensions'] ?? '');
    $tags         = trim($_POST['tags'] ?? '');
    $seo_url      = trim($_POST['seo_url'] ?? '');
    $meta_title   = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    // related_ids drives the "you may also like" strip on the product page, and
    // this form has NO input for it — so every save posted nothing, this line
    // resolved to '', and the UPDATE below wrote that empty string back. Editing
    // a product to fix a typo silently destroyed its hand-picked related items.
    //
    // Absent now means "leave alone" rather than "clear". The only way to empty
    // it deliberately is to post the field explicitly as blank, which is what a
    // real input would do once one exists.
    if (array_key_exists('related_ids', $_POST)) {
        $related_ids = is_array($_POST['related_ids'])
            ? implode(',', $_POST['related_ids'])
            : trim((string)$_POST['related_ids']);
    } else {
        $related_ids = null;   // null => keep whatever is stored
    }

    // "Yes, publish it anyway" — set by the Publish Anyway button that appears
    // once the completeness warnings have been shown. The missing details below
    // are worth telling the owner about, but they are the owner's call: a real
    // garment can be on sale before someone has weighed it, and a plain save
    // already stores the product as a draft rather than refusing it.
    $confirmIncomplete = ($_POST['confirm_incomplete'] ?? '') === '1';
    // Publish-only gaps (missing fabric, care, weight, HSN/GST, size chart, …).
    // These are warnings, not walls: they never block a save. A plain Save stores
    // the product as a DRAFT and lists these; Publish Anyway confirms them and
    // publishes regardless. Collected separately from $errors so the two are
    // treated differently — errors keep the form on screen, warnings only
    // downgrade the save to a draft.
    $publishWarnings = [];

    // Old files superseded by this save. Deleted only after the database write
    // succeeds, so a validation failure or DB error cannot destroy the picture
    // the shop is still using.
    $filesToDeleteOnSuccess = [];

    // Keep existing image unless a new one is uploaded
    // Read from hidden field so we don't lose it when $product is null on POST
    $imageName = trim($_POST['existing_image'] ?? ($product['image'] ?? ''));

    // Handle image upload
    if (!empty($_FILES['product_image']['name'])) {
        $file    = $_FILES['product_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        /* Warned about, not forbidden.
           ────────────────────────────────────────────────────────────────
           A picture that is not 3:4 will be cropped by the shop, and the
           owner should be told before that happens rather than after. But
           the owner is the one who knows whether the crop matters — a flat
           lay with space around it survives a centre crop that would take a
           model's head off. So the check reports, the form asks, and an
           explicit yes carries this flag.

           The flag is set by the confirmation the owner answers, so it can
           only arrive after they have been shown what would be lost. */
        $allowOddRatio = ($_POST['allow_image_ratio'] ?? '') === '1';
        $ratioErr = $allowOddRatio
            ? null
            : productImageRatioError($file['tmp_name'], (string)$file['name']);

        if (!in_array($mime, $allowed)) {
            $errors[] = 'Image must be JPG, PNG, WebP, or GIF.';
        } elseif ($file['size'] > 8 * 1024 * 1024) {
            $errors[] = 'Image file too large (max 8MB).';
        } elseif ($ratioErr !== null) {
            $errors[] = $ratioErr;
        } elseif (($ext = safeImageExtension($mime)) === null) {
            // Unreachable while $allowed and the map agree — kept so a future
            // widening of $allowed cannot silently reopen the filename path.
            $errors[] = 'Image must be JPG, PNG, WebP, or GIF.';
        } else {
            /* The extension comes from the DETECTED type, never from the
               uploaded filename — see safeImageExtension(). This line read
               pathinfo($file['name']), so "shell.php" carrying GIF magic bytes
               was saved as .php and executed. */
            $filename = slugify($name) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destDir  = __DIR__ . '/../uploads/products/';
            hardenUploadDir($destDir);
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }

            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                /*
                 * The old file is NOT deleted here any more — it is queued and
                 * removed only once the database write has succeeded (see the
                 * end of the `if (empty($errors))` block below).
                 *
                 * Two bugs this fixes:
                 *  - The target came from $_POST['existing_image'], so a posted
                 *    "../gallery/lookbook_1.png" deleted a file in another folder.
                 *    The real current filename is read from the database instead.
                 *  - It ran BEFORE validation, so submitting a replacement image
                 *    with (say) a blank name destroyed the old image and then
                 *    refused to save — losing the picture for nothing.
                 */
                if ($productId > 0) {
                    $cur = $pdo->prepare("SELECT image FROM products WHERE id = :id");
                    $cur->execute(['id' => $productId]);
                    $dbImage = (string)$cur->fetchColumn();
                    if ($dbImage !== '' && $dbImage !== $filename) {
                        $filesToDeleteOnSuccess[] = $dbImage;
                    }
                }
                // Resize/re-compress before the WebP is made, so the delivery copy is
                // generated from the already-optimised original rather than the raw upload.
                optimizeUploadedImage($destDir . $filename);
                generateWebpCopy($destDir . $filename);
                $imageName = $filename;
            } else {
                $errors[] = 'Failed to save image file.';
            }
        }
    }

    // Handle video file upload (used if no image available)
    if (!empty($_FILES['product_video']['name'])) {
        $vFile    = $_FILES['product_video'];
        $allowedV = ['video/mp4', 'video/webm', 'video/quicktime'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $vMime    = finfo_file($finfo, $vFile['tmp_name']);
        finfo_close($finfo);

        if (!in_array($vMime, $allowedV)) {
            $errors[] = 'Video must be MP4, WebM, or MOV format.';
        } elseif ($vFile['size'] > 50 * 1024 * 1024) {
            $errors[] = 'Video file too large (max 50MB).';
        } else {
            $vExt      = strtolower(pathinfo($vFile['name'], PATHINFO_EXTENSION));
            $vFilename = slugify($name) . '-video_' . bin2hex(random_bytes(4)) . '.' . $vExt;
            $destDir   = __DIR__ . '/../uploads/products/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }

            if (move_uploaded_file($vFile['tmp_name'], $destDir . $vFilename)) {
                // Replacing a video previously left the old one on disk forever —
                // at up to 50MB each (the cap checked above) that was the single
                // biggest per-edit leak in the admin.
                if ($productId > 0) {
                    $curV = $pdo->prepare("SELECT video_url FROM products WHERE id = :id");
                    $curV->execute(['id' => $productId]);
                    $dbVideo = (string)$curV->fetchColumn();
                    if ($dbVideo !== '' && $dbVideo !== $vFilename) {
                        $filesToDeleteOnSuccess[] = $dbVideo;
                    }
                }
                $video_url = $vFilename;
            } else {
                $errors[] = 'Failed to save video file.';
            }
        }
    }

    // Handle multiple additional images
    // Gallery images already moved to disk by an ATTEMPT THAT FAILED VALIDATION.
    //
    // The files are moved before the completeness checks run, and the insert is
    // gated on those checks passing. So uploading a gallery, hitting the
    // "Missing: fabric, sleeve…" warning and then pressing "Publish Anyway" lost
    // every image: a browser does not re-send files on the second submit, so
    // $_FILES arrived empty and no product_images row was ever written — while
    // the files themselves sat orphaned in uploads/products/ forever.
    //
    // Held in the SESSION rather than a hidden form field on purpose. A hidden
    // field is user-supplied, so a hand-made POST could name any file in the
    // uploads folder and attach it to a product; the session cannot be edited
    // by the browser at all.
    $uploadedAdditionalImages = array_values(array_filter(
        (array)($_SESSION['dv_pending_gallery'] ?? []),
        fn($f) => is_string($f) && $f !== '' && is_file(__DIR__ . '/../uploads/products/' . basename($f))
    ));

    if (!empty($_FILES['additional_images']['name'][0])) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        foreach ($_FILES['additional_images']['name'] as $key => $filename_orig) {
            if ($_FILES['additional_images']['error'][$key] === UPLOAD_ERR_OK) {
                $tmp = $_FILES['additional_images']['tmp_name'][$key];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp);
                finfo_close($finfo);

                /* Each picture is judged on its own, and a rejection NAMES it.
                   ────────────────────────────────────────────────────────────
                   Several files arrive at once here, so "one of your images is
                   the wrong shape" would leave the owner opening all of them to
                   find out which. The others still upload — refusing the whole
                   batch over one bad file would be its own kind of rude. */
                $galleryRatioErr = (($_POST['allow_image_ratio'] ?? '') === '1')
                    ? null
                    : productImageRatioError($tmp, (string)$filename_orig);
                if ($galleryRatioErr !== null) {
                    $errors[] = $galleryRatioErr;
                    continue;
                }

                if (in_array($mime, $allowed)
                    && $_FILES['additional_images']['size'][$key] <= 8 * 1024 * 1024
                    && ($ext = safeImageExtension($mime)) !== null) {
                    // From the detected type, not the uploaded name — the gallery
                    // path had the identical hole as the cover image above.
                    /* Named from the file's CONTENT, not from chance.
                       ────────────────────────────────────────────────────────
                       This used bin2hex(random_bytes(4)), so the same photograph
                       re-submitted got a brand-new name every time. A save that
                       fails re-posts the whole form, files included — so three
                       photographs and three blocked attempts left NINE copies on
                       disk and nine entries queued, and array_unique below could
                       not tell they were the same pictures because every name
                       differed.

                       A content hash makes a re-upload land on the same
                       filename: it overwrites itself, the queue dedupes, and a
                       blocked save costs nothing. Different pictures still get
                       different names, which is all the randomness was for. */
                    $filename = slugify($name) . '-' . ($key + 1) . '_'
                              . substr(md5_file($tmp) ?: bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
                    $destDir  = __DIR__ . '/../uploads/products/';
                    
                    if (move_uploaded_file($tmp, $destDir . $filename)) {
                        optimizeUploadedImage($destDir . $filename);
                        generateWebpCopy($destDir . $filename);
                        $uploadedAdditionalImages[] = $filename;
                    }
                }
            }
        }
    }

    // Read BEFORE validation: the compare-at rule below needs $mrp_price, and
    // reading it afterwards meant the check compared against null and never fired.
    //
    // cost_price is no longer posted by this form (see the pricing section below).
    // It is deliberately NOT written on save so any existing value is preserved
    // rather than being zeroed out every time a product is edited.
    $mrp_price  = (float)($_POST['mrp_price']  ?? 0);
    // Per-country prices. Keyed by country code, read as posted; validated and
    // written to product_country_prices once $productId is known (see below the
    // main product save). A blank price means "not sold there" and removes any
    // existing row for that country.
    $countryPricesPosted     = $_POST['country_price']      ?? [];
    $countrySalePricesPosted = $_POST['country_sale_price'] ?? [];
    // Tax mode. By default the product inherits its HSN code & GST rate from the
    // category's defaults (set under Categories → edit), and those values are
    // written onto the product so invoices keep working. A sub-category without
    // a default of its own inherits the first one found up its tree. When no
    // default exists, null means "keep whatever the product already stores".
    // Unchecking "Use Category Tax Settings" switches the form to the product's
    // own HSN/GST fields, which are then saved exactly as typed.
    $useCategoryTax   = (($_POST['use_category_tax'] ?? '0') === '1');
    // Price Includes GST is a store-wide policy now (Settings → Invoice & GST
    // details), so the product always mirrors the store's choice — it is never
    // a per-product edit.
    try {
        $priceIncludesGst = ((string)storeSetting($pdo, 'price_includes_gst', '1')) === '1';
    } catch (Throwable $e) {
        $priceIncludesGst = true;
    }
    if (!$useCategoryTax) {
        // Override: this product carries its own tax details. Blank means no tax.
        $hsn_code = trim((string)($_POST['hsn_code'] ?? ''));
        $gst_rate = (float)($_POST['gst_rate'] ?? 0);
    } else {
        $catTax = null;
        $taxCid = (int)($category_id ?? 0);
        try {
            // Prepared once and reused per hop; the walk is bounded (20 levels) so
            // a mis-linked category tree can never loop forever.
            $taxInfo = $pdo->prepare("SELECT parent_id, default_hsn_code, default_gst_rate FROM categories WHERE id = :id");
            for ($hop = 0; $taxCid > 0 && $hop < 20; $hop++) {
                $taxInfo->execute([':id' => $taxCid]);
                $taxRow = $taxInfo->fetch(PDO::FETCH_ASSOC);
                if (!$taxRow) { break; }
                // categoryGstIsSet, not "> 0": the apparel auto marker is negative,
                // so a plain greater-than test walks straight past a collection set
                // to Apparel – Auto by Price and inherits from its parent instead.
                if (trim((string)($taxRow['default_hsn_code'] ?? '')) !== '' || categoryGstIsSet($taxRow['default_gst_rate'] ?? null)) {
                    $catTax = $taxRow;
                    break;
                }
                $taxCid = (int)($taxRow['parent_id'] ?? 0);
            }
        } catch (PDOException $e) {
            // Columns missing (migration not run yet) — inherit nothing, keep stored.
            $catTax = null;
        }
        $hsn_code = ($catTax && trim((string)$catTax['default_hsn_code']) !== '')
            ? trim((string)$catTax['default_hsn_code'])
            : null;   // null => keep whatever is stored
        // Resolved against THIS product's selling price, so a collection set to
        // Apparel – Auto by Price gives 5% to a ₹1,800 kurti and 18% to a ₹4,200
        // suit filed in the same place.
        //
        // When the walk found nothing at all — no collection above this product
        // has been given a rate — the apparel slabs apply rather than nothing.
        // That is what makes the Collection field optional: a clothes shop is
        // correct without anyone opening it, and the dropdown is there to say
        // otherwise for a collection that is not apparel.
        $gst_rate = productGstRateFor($catTax['default_gst_rate'] ?? null, (float)$price);
    }

    // ── Has someone else saved this product since the form was opened? ──
    //
    // Every field is submitted on every save, so without this the second person
    // to press Save silently overwrites the first — their work simply vanishes
    // with no error and nothing in the log to explain it.
    //
    // The check compares the updated_at that was current when the form was
    // rendered against what is stored now. It refuses rather than merging,
    // because a half-merged product (one person's price, another's description)
    // is worse than being told to reload.
    if ($productId > 0) {
        $seenAt = trim((string)($_POST['loaded_updated_at'] ?? ''));
        if ($seenAt !== '') {
            $cur = $pdo->prepare("SELECT updated_at FROM products WHERE id = :id");
            $cur->execute([':id' => $productId]);
            $nowAt = (string)$cur->fetchColumn();
            if ($nowAt !== '' && $nowAt !== $seenAt) {
                /* Warn once, then let the next Save through.
                   ────────────────────────────────────────────────────────────
                   The re-rendered form used to send the ORIGINAL timestamp back
                   (see the repopulate block below), so once this fired it could
                   never pass again: every retry compared the same stale value
                   against the same newer one and failed identically. The only
                   way out was to reload, which throws away everything typed —
                   and each blocked attempt re-uploaded the gallery files, which
                   is how three photographs became nine.

                   Handing the current timestamp back makes the SECOND press of
                   Save succeed. That is deliberate: the owner has been shown
                   the warning and told what it means, and pressing Save again
                   is them answering it. Silently unpassable is not a safer
                   guard, it is a trap. */
                $errors[] = 'Someone else saved this product at ' . htmlspecialchars($nowAt)
                          . ', after you opened it. Nothing was changed yet. Reload to see their'
                          . ' version — or press Save again to keep what you have typed and'
                          . ' overwrite theirs.';
                $staleSeenAt = $nowAt;
            }
        }
    }

    // ── Validate ────────────────────────────────────────────
    //
    // Everything here is checked on the SERVER. The form marks fields required,
    // but `required` is a browser courtesy — the POST can be replayed without it,
    // and that is how incomplete products reached the live catalogue.
    //
    // The rules are split in two:
    //   ALWAYS  — data that is wrong however the product is stored
    //   PUBLISH — extra requirements before shoppers can see it
    // A draft may be saved half-finished; that is the point of a draft.
    if (strlen($name) < 2)        $errors[] = 'Product name is required.';
    if (strlen($description) < 5) $errors[] = 'Description is required.';
    if ($price <= 0)               $errors[] = 'Price must be greater than 0.';
    if (empty($category))         $errors[] = 'Please select a category.';

    // A negative price is ALREADY reported by the "must be greater than 0" check
    // above, so this second message only ever appeared alongside it — two lines
    // saying the same thing about the same field. Kept as a comment rather than
    // a duplicate error.
    //
    // GST is checked here instead, which nothing was validating: 99 was accepted
    // and would have printed on a tax invoice. India's slabs are fixed, so
    // anything outside them is a typo rather than a preference. 0.25 and 3 are
    // real slabs (rough diamonds, gold) and are allowed even though a garment
    // shop will not use them — refusing a legitimate rate would be worse.
    if ($gst_rate !== null && (float)$gst_rate > 0) {
        // Floats throughout. Written as [0.25, 3, 5, …] the literals are INTs, and
        // a strict in_array() against a cast float then rejected every whole-number
        // slab — 12 came back "not a real slab". Strict comparison is still right
        // (it stops "12abc" matching); the types just have to agree.
        $gstSlabs = [0.0, 0.25, 3.0, 5.0, 12.0, 18.0, 28.0];
        if (!in_array(round((float)$gst_rate, 2), $gstSlabs, true)) {
            $errors[] = 'GST rate ' . htmlspecialchars((string)$gst_rate) . '% is not a real slab. '
                      . 'Indian GST is 0, 0.25, 3, 5, 12, 18 or 28 — a garment is usually 5 or 12. '
                      . 'This figure prints on the tax invoice, so it has to be one of those. '
                      . 'If it came from the category\'s default, correct it under Categories → edit; '
                      . 'if it is this product\'s own rate, fix it in the override fields.';
        }
    }

    // SKU must be unique when given. Two products sharing one code makes stock
    // counts, invoices and returns ambiguous forever after.
    if ($sku !== '') {
        $skuChk = $pdo->prepare(
            "SELECT id, name FROM products WHERE sku = :sku AND id <> :id AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1"
        );
        $skuChk->execute([':sku' => $sku, ':id' => $productId]);
        if ($clash = $skuChk->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = 'SKU "' . htmlspecialchars($sku) . '" is already used by "' . htmlspecialchars($clash['name']) . '".';
        }
    }

    // Compare-at price must genuinely be higher, or the "discount" is a lie.
    // Equal is also rejected: it renders as "0% OFF", which reads as a bug.
    if ($mrp_price > 0 && $mrp_price <= $price) {
        $errors[] = 'Compare-at price must be higher than the selling price, otherwise the saving shown would be zero or negative.';
    }

    // A stated discount percentage has to be believable.
    if ($discount_percentage < 0 || $discount_percentage > 90) {
        $errors[] = 'Discount must be between 0 and 90 percent.';
    }

    // The category must still exist. A product filed under a deleted category
    // disappears from navigation while still being live on its own URL.
    if (!empty($category_id)) {
        $catChk = $pdo->prepare("SELECT id FROM categories WHERE id = :id");
        $catChk->execute([':id' => (int)$category_id]);
        if (!$catChk->fetchColumn()) {
            $errors[] = 'That category no longer exists. Please choose another.';
        }
    }

    // $stock_qty is already clamped with max(0, ...) further up, so the stored
    // value can never be negative — but that clamp is silent. Someone who typed
    // "-50" got 0 and no warning, then wondered why the piece showed as sold
    // out. Test what was SUBMITTED, not what survived the clamp.
    if ($track_stock && (int)($_POST['stock_qty'] ?? 0) < 0) {
        $errors[] = 'Stock quantity cannot be negative.';
    }

    // ── Publish-only requirements ───────────────────────────
    // `available` is what makes a product visible on the storefront, so it is
    // the publish switch. Refusing these at publish time — rather than at save
    // time — is what lets a product be built up over several sittings.
    $isPublishing = !empty($available);

    if ($isPublishing) {
        // The image may be newly uploaded in this request, or already on the row.
        $existingImage = '';
        if ($productId > 0) {
            $imgChk = $pdo->prepare("SELECT image FROM products WHERE id = :id");
            $imgChk->execute([':id' => $productId]);
            $existingImage = (string)$imgChk->fetchColumn();
        }
        if (empty($imageName) && $existingImage === '') {
            // A photo is the one thing a published product cannot do without —
            // the card and the product page are built around it — but Publish
            // Anyway is the owner's explicit call to put it live regardless. A
            // plain save is not refused either: it stores the product as a
            // draft, and the image can be added before publishing.
            if (!$confirmIncomplete) {
                $publishWarnings[] = 'No main image yet. A draft is fine without one; publishing requires it.';
            }
        }

        // SKU is generated, not demanded.
        //
        // Unlike a shipping weight or an HSN code, a SKU is not a fact about the
        // garment that only a human can know — it is an internal reference, and
        // the shop already invents one wherever a product has none ("DV-" . id,
        // in the admin list and on the product page). Refusing to publish over a
        // code the system would happily make up itself was busywork.
        //
        // Derived from the product name so it means something at a glance, with a
        // numeric suffix if that base is taken. The loop is bounded: after 50
        // collisions it falls back to something that cannot clash.
        /* An empty box means "I did not type one", not "this product has none".
           ────────────────────────────────────────────────────────────────────
           This asked only whether the field was blank, and generated from the
           product's name AS IT IS TODAY. So on a product that was live and had
           since been renamed, clearing the box quietly minted a DIFFERENT code:
           DV-LUNASATI became DV-AURORASA, with no warning and no record of the
           old one.

           That code is the product's public identity — it is the `sku` in the
           Product schema, the per-size variant SKUs and the productGroupID, so
           changing it tells Google this is a different item and leaves any
           customer quoting the old one unable to be found. The collision suffix
           made it worse still: the same product could come back as
           DV-AURORASA-1 or -2 depending on what else existed that day.

           A SKU is assigned once and then permanent. Checking the stored value
           first is what makes that true — after this, renaming a product cannot
           alter its SKU and emptying the box cannot destroy one. Only a product
           that genuinely has none ever gets a fresh code. */
        /* Once it has been SOLD, the code is frozen.
           ────────────────────────────────────────────────────────────────
           The comment above says a SKU is assigned once and then permanent,
           and the blank-box case honours that — but typing a different code
           still overwrote it. That is the damaging direction: orders store the
           SKU they were sold under (items_json and order_items both), so
           changing it afterwards leaves the order history quoting a code the
           product no longer carries, and nothing can be reconciled by SKU.

           A product that has never been ordered is still free to be recoded —
           an owner importing codes from a supplier or an old system needs that.
           The lock arrives at the moment it starts to matter. */
        if ($sku !== '' && $productId) {
            try {
                $sold = $pdo->prepare(
                    /* created_at guards against id reuse.
                        MySQL hands a deleted product's id to the next one, and this
                        shop has orders going back further than some of its products
                        — so product_id alone matched 13 historical orders belonging
                        to a product that no longer exists, and locked the SKU of a
                        clone that had never been sold. An order can only concern
                        this product if it was placed after this product existed. */
                     "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                        WHERE oi.product_id = :id AND o.created_at >= :born
                      UNION
                      SELECT 1 FROM orders WHERE items_json LIKE :like AND created_at >= :born2
                      LIMIT 1"
                );
                $bornSt = $pdo->prepare("SELECT created_at FROM products WHERE id = :id");
                $bornSt->execute([':id' => $productId]);
                $born = (string)$bornSt->fetchColumn() ?: '1970-01-01';
                $sold->execute([':id' => $productId, ':born' => $born, ':born2' => $born,
                                ':like' => '%"product_id":' . (int)$productId . ',%']);
                if ($sold->fetchColumn()) {
                    $keepSold = $pdo->prepare("SELECT sku FROM products WHERE id = :id");
                    $keepSold->execute([':id' => $productId]);
                    $storedSku = trim((string)$keepSold->fetchColumn());
                    if ($storedSku !== '' && $storedSku !== $sku) {
                        $sku = $storedSku;   // silently keep it; the field is read-only in the UI
                    }
                }
            } catch (PDOException $e) {
                // Unreadable: fall through and accept the typed value.
            }
        }

        if ($sku === '' && $productId) {
            try {
                $keep = $pdo->prepare("SELECT sku FROM products WHERE id = :id");
                $keep->execute([':id' => $productId]);
                $sku = trim((string)$keep->fetchColumn());
            } catch (PDOException $e) {
                // Unreadable row: fall through and generate rather than block the save.
            }
        }

        /* One generator, shared with the duplicate feature.
           This rule used to live only here, which is why cloning a product
           could not use it and grew its own "-COPY" scheme instead. Moved to
           generateProductSku() in config.php; the behaviour is unchanged. */
        if ($sku === '') {
            // The category is what the code is built from now, so it has to be
            // handed over — without it every product would land under GEN.
            $sku = generateProductSku($pdo, $name, $productId ?: 0, $category);
        }

        // ── Brand ──
        // The seed catalogue shipped with Sabyasachi, Manish Malhotra and Anita
        // Dongre recorded as the brand on eight products. Those names go straight
        // into the Product schema and the Merchant Center feed, and listing another
        // company's trademark as your own brand is what gets a Merchant account
        // suspended. So a plain publish refuses a brand that is not the shop's own
        // or one recorded in the brands table — Publish Anyway is the owner's
        // explicit override.
        $brandTyped = mb_strtolower(trim((string)$brand));
        if ($brandTyped !== '') {
            $allowedBrands = [mb_strtolower(SHOP_NAME)];
            try {
                foreach ($pdo->query("SELECT name FROM brands") as $bRow) {
                    $allowedBrands[] = mb_strtolower(trim($bRow['name']));
                }
            } catch (PDOException $e) {}
            if (!in_array($brandTyped, $allowedBrands, true) && !$confirmIncomplete) {
                // A draft is fine — the feed only sees published products, so the
                // name can sit there until the brand is added under Brands.
                // Publish Anyway is the owner's explicit call to put it live
                // regardless.
                $publishWarnings[] = 'Brand "' . $brand . '" is not one of yours. Add it under Brands, or use "' . SHOP_NAME . '".';
            }
        }

        // ── Fabric vs the garment's own name ──
        // "Zariah Georgette Kurti" recorded as Silk contradicts itself on the page,
        // in the shop filter and in the schema material. A plain publish refuses it
        // because whichever of the two is wrong, a shopper is being misinformed;
        // Publish Anyway is the owner's explicit override.
        $nameLower   = mb_strtolower($name);
        $fabricLower = mb_strtolower(trim($fabric));
        /* The fabric only has to CONTAIN the word, not equal it.
           ────────────────────────────────────────────────────────────────────
           This demanded $fabricLower === $fw, so every compound fabric name in
           the catalogue was reported as a contradiction with itself: "Aarini
           Ivory Embroidered Khadi Cotton Short Kurti" set to "Khadi Cotton" was
           refused with 'the name says "Cotton" but the fabric is set to "Khadi
           Cotton"' — two strings that plainly agree. Pure Silk, Cotton Blend,
           Chanderi Silk and Silk Blend all failed the same way, and the product
           was held back as a draft over it.

           A real contradiction is the fabric not mentioning the word at all —
           a "Georgette Kurti" recorded as Silk. That is what this now tests. */
        foreach (['silk','cotton','linen','georgette','crepe','organza','satin','brocade','chiffon','velvet','rayon','chanderi','muslin'] as $fw) {
            if (str_contains($nameLower, $fw) && $fabricLower !== '' && !str_contains($fabricLower, $fw) && !$confirmIncomplete) {
                // Publish Anyway is the owner's explicit call to put it live
                // despite the contradiction.
                $publishWarnings[] = 'The name says "' . ucfirst($fw) . '" but the fabric is set to "' . $fabric
                                   . '". Correct whichever is wrong before publishing.';
                break;
            }
        }
        // SEO title, description, slug and tags are DERIVED, not demanded.
        //
        // These four used to be three blocking errors, which was busywork with no
        // purpose: resolveProductSeo() in config/config.php already falls back to
        // an auto-generated value for every one of them, and the storefront has
        // always rendered that fallback. The form was refusing to publish a
        // product over fields the site would have filled in by itself a moment
        // later.
        //
        // Anything typed by hand still wins — resolveProductSeo() only supplies a
        // value where the field is blank — so a deliberately written title is
        // never overwritten. Writing the derived values into the columns here
        // (rather than leaving them empty and resolving on read) means the Google
        // title is visible in admin and can be edited afterwards.
        /* Rename the product and its SEO follows it.
           ────────────────────────────────────────────────────────────────────
           resolveProductSeo() only fills BLANKS, and this form posts the STORED
           slug and meta title back in the value="" of their inputs. So after a
           rename the old values arrived non-blank, the resolver left them alone,
           and a kurti renamed from "Aarna Mustard" to "Neerja Teal" kept
           /product/aarna-mustard-… — a browser tab, a share card and a Merchant
           feed row all went on naming a garment the shop no longer sold.

           Only AUTO-GENERATED values are refreshed. The test is whether what the
           form posted still equals what the OLD row would have generated by
           itself — if it does, nobody ever typed there, so regenerating restores
           the intent rather than overwriting a decision. A hand-written title
           differs from that and is left exactly alone.

           Two things this has to get right, both of which it got wrong before:

             • It must run BEFORE $seoSource is built. $seoSource captures these
               four variables by value, so blanking them afterwards changed
               nothing at all and the slug never moved. That was the whole bug.
             • $autoOld must be resolved from the old row with the four SEO
               fields BLANKED. Passing the row as-is just handed the stored
               values straight back, which made the test "posted === stored" —
               true for a hand-written title too, so the guard protected nothing.

           The numeric id is what actually resolves a product URL, so a link
           already shared keeps working after the slug changes. */
        if ($productId > 0) {
            try {
                $prevQ = $pdo->prepare("SELECT * FROM products WHERE id = :id");
                $prevQ->execute([':id' => $productId]);
                $prev = $prevQ->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { $prev = null; }

            if ($prev && trim((string)$prev['name']) !== '' && trim((string)$prev['name']) !== trim($name)) {
                // What the product would have generated for itself before the
                // rename — the old row, with the four derived fields emptied so
                // the resolver actually derives them instead of echoing them.
                $autoOld = resolveProductSeo(array_merge($prev, [
                    'meta_title' => '', 'meta_description' => '', 'seo_url' => '', 'tags' => '',
                ]));
                if (trim((string)$seo_url)          === trim((string)$autoOld['seo_url']))          { $seo_url = ''; }
                if (trim((string)$meta_title)       === trim((string)$autoOld['meta_title']))       { $meta_title = ''; }
                if (trim((string)$meta_description) === trim((string)$autoOld['meta_description'])) { $meta_description = ''; }
                if (trim((string)$tags)             === trim((string)$autoOld['tags']))             { $tags = ''; }
            }
        }

        // Built AFTER the rename block above, so that anything blanked there is
        // captured as blank and gets regenerated from the NEW name below.
        $seoSource = [
            'name' => $name, 'description' => $description, 'category' => $category,
            'fabric' => $fabric, 'color' => $color, 'pattern' => $pattern,
            'occasion' => $occasion, 'sleeve' => $sleeve, 'neck' => $neck,
            'brand' => $brand, 'price' => $price,
            'meta_title' => $meta_title, 'meta_description' => $meta_description,
            'seo_url' => $seo_url, 'tags' => $tags,
        ];

        if (function_exists('resolveProductSeo')) {
            $resolvedSeo      = resolveProductSeo($seoSource);
            $meta_title       = $resolvedSeo['meta_title'];
            $meta_description = $resolvedSeo['meta_description'];
            $seo_url          = $resolvedSeo['seo_url'];
            $tags             = $resolvedSeo['tags'];
        }

        // Alt text describes the photograph, so it can be built from what the
        // garment IS — name, colour, category — which is exactly what a useful
        // alt says. Left blank it is both an accessibility failure and lost
        // Google Images traffic, and nobody ever types it by hand.
        if (trim((string)$image_alt) === '' && $name !== '') {
            $altBits = [$name];
            if (trim((string)$color) !== '')    { $altBits[] = 'in ' . $color; }
            elseif (trim((string)$fabric) !== '') { $altBits[] = 'in ' . $fabric; }
            $image_alt = trim(implode(' ', $altBits));
        }

        // Completeness. A shopper cannot touch the fabric or try the garment on,
        // so these fields ARE the product — a listing without them is asking
        // someone to spend thousands on a photograph.
        //
        // Reported as ONE message naming everything missing, rather than a dozen
        // separate errors that have to be cleared one save at a time.
        $incomplete = [];
        if (trim($fabric) === '')        { $incomplete[] = 'fabric'; }
        if (trim($sleeve) === '')        { $incomplete[] = 'sleeve type'; }
        if (trim($neck) === '')          { $incomplete[] = 'neckline'; }
        if (trim((string)$fit_description) === '') { $incomplete[] = 'fit description'; }
        /* Care instructions, dimensions and shipping weight are NOT demanded here.
           ────────────────────────────────────────────────────────────────────────
           Each of the three now has a working default, so a blank one is no longer
           a gap a shopper or a courier ever sees:

             care        productWashCare() supplies the shop's standard wording, and
                         the form is pre-filled with it — so the field is only empty
                         if the owner deliberately cleared it.
             dimensions  ShiprocketService falls back to default_parcel_dimensions_cm
                         from Store Settings.
             weight      same, via default_parcel_weight_kg.

           Listing them anyway made the warning cry wolf on every single product:
           the owner learned to press "Publish Anyway" without reading it, which is
           how the fabric and neckline items — which have NO fallback and genuinely
           do go missing from the page — stopped being noticed too.

           A warning is only worth showing while it is still worth reading. */
        // Alt text is no longer demanded here — it is derived above from the
        // product's own name and colour, which is what a useful alt says anyway.

        // A warning the owner can accept, not a wall. A plain save stores the
        // product as a draft and lists these; "Publish Anyway" confirms them
        // (confirm_incomplete=1) and the publish goes through.
        if ($incomplete && !$confirmIncomplete) {
            $publishWarnings[] = 'Missing: ' . implode(', ', $incomplete)
                               . '. These are the details a shopper uses instead of handling the garment.';
        }

        /* Tax status, judged on what the product will ACTUALLY have after this
           save — not on the variables being written.
           ────────────────────────────────────────────────────────────────────
           $hsn_code is null when the category supplies none, and null means
           "keep whatever is stored" (see the COALESCE in the UPDATE). Testing
           it directly read that null as an empty string, so a product with a
           perfectly good stored HSN was told it had none, every time it was
           saved with Use Category Tax Settings ticked.

           And the two are reported separately now. The old wording said "No HSN
           code or GST rate" whichever was missing — so an owner whose rate had
           resolved correctly to 5% was sent to Categories to fix a rate that
           was never wrong, and the actual gap (a blank HSN on every collection)
           went unnamed. */
        $effectiveHsn = trim((string)($hsn_code ?? ($product['hsn_code'] ?? '')));
        $effectiveGst = ($gst_rate !== null) ? (float)$gst_rate : (float)($product['gst_rate'] ?? 0);

        $taxMissing = [];
        if ($effectiveHsn === '')  { $taxMissing[] = 'HSN code'; }
        if ($effectiveGst <= 0)    { $taxMissing[] = 'GST rate'; }

        if ($taxMissing && !$confirmIncomplete) {
            $publishWarnings[] = 'No ' . implode(' and no ', $taxMissing) . '. '
                               . ($useCategoryTax
                                   ? 'This product takes its tax from its collection, and that collection has no '
                                     . implode(' or ', $taxMissing) . ' set — add it under Categories, or untick '
                                     . '"Use Category Tax Settings" and type this product\'s own.'
                                   : 'Set it on this product, or tick "Use Category Tax Settings" to take it from the collection.')
                               . ' Or press Publish Anyway — without '
                               . (count($taxMissing) > 1 ? 'them' : 'it')
                               . ' the invoice prints as an "Order Summary", not a proper tax invoice: legal, but not what a '
                               . 'GST-registered seller wants going out.';
        }

        // Size chart. detectShippingZone-style fallback applies: the product may
        // inherit a chart from its category or the shop-wide default, so only
        // complain when NOTHING would be shown.
        try {
            $sgCount = 0;
            $sgStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM size_guide_content c
                   JOIN size_guide_charts g ON g.id = c.chart_id
                  WHERE c.measurement_type = 'garment'
                    AND (g.product_id = :pid
                         OR g.category_id = (SELECT id FROM categories WHERE name = :cat LIMIT 1)
                         OR (g.category_id IS NULL AND g.product_id IS NULL))"
            );
            $sgStmt->execute([':pid' => $productId, ':cat' => $category]);
            $sgCount = (int)$sgStmt->fetchColumn();
            if ($sgCount === 0 && !$confirmIncomplete) {
                $publishWarnings[] = 'No size chart would be shown for this product. Add garment measurements '
                                   . 'in Size Guide (for this product, its category, or the shop-wide default) before publishing.';
            }
        } catch (PDOException $e) {
            // Size guide tables missing — do not block a save over it.
            error_log('size chart publish check: ' . $e->getMessage());
        }
    }

    /* A slug has to be unique — for a draft as much as a published product.
       ────────────────────────────────────────────────────────────────────────
       resolveProductSeo() (run in the publish block above) supplies a slug but
       has no database to check it against, and it runs only when publishing — so
       a hand-typed slug that already belongs to another product, or two products
       sharing a name, stored the same seo_url twice with nothing to stop it. The
       router resolves on the trailing id, so no page ever loads the wrong
       product, but the two then advertise the same canonical /product/<slug>
       address — the exact duplicate-URL that generateProductSlug() and the
       duplicate feature already guard everywhere else.

       Deliberately OUTSIDE the publish block so a draft is deduped too: a
       collision typed today must not wait until publish to be caught, and two
       drafts must not share a slug either. Only a non-empty slug is touched — a
       blank one is left for productUrl() to derive from the name at render time —
       and this product's own row is excluded so a plain re-save of an
       already-unique slug is never needlessly suffixed. */
    if (function_exists('generateProductSlug') && trim((string)$seo_url) !== '') {
        $seo_url = generateProductSlug($pdo, $seo_url, $productId ?: 0);
    }

    // ── A plain Save with publish warnings saves as a DRAFT ──
    // The completeness and tax gaps above are worth telling the owner about, but
    // they are the owner's call: a real garment can be on sale before someone has
    // weighed it, and refusing the save meant the whole form had to be re-filled
    // to get past it. So a plain Save never refuses on those — it stores the
    // product as a draft (unpublished), keeps everything the owner typed, and
    // stays on the form with the gaps listed and a "Publish Anyway" button that
    // publishes without them. Publish Anyway (confirm_incomplete=1) skips all of
    // this and saves straight through as a publish.
    $postedAvailable = $available;   // what the owner actually submitted

    // Was this product ALREADY on sale before this save?
    //
    // Read from the database rather than from $product, because the form can be
    // posted without ?id= in the URL, in which case $product was never loaded.
    $wasPublished = false;
    if ($productId > 0) {
        try {
            $wasPub = $pdo->prepare("SELECT available FROM products WHERE id = :id");
            $wasPub->execute([':id' => $productId]);
            $wasPublished = ((int)$wasPub->fetchColumn() === 1);
        } catch (PDOException $e) {}
    }

    $savedAsDraft      = false;
    $savedLiveWithGaps = false;
    if (empty($errors) && !empty($publishWarnings) && !$confirmIncomplete) {
        if ($wasPublished && $postedAvailable === 1) {
            // Already selling — leave it selling.
            //
            // This used to force EVERY incomplete save to a draft, with no regard
            // for whether the product was already live. So correcting a typo on a
            // product that had been on sale for weeks quietly removed it from the
            // shop: the owner saw no warning that mattered, the listing simply
            // stopped appearing, and re-opening the form showed the Published box
            // unticked. Measured on product #15, which went available=0 on an
            // ordinary edit.
            //
            // Holding a listing back before it has ever gone live is protective.
            // Taking a live one down without being asked is not the same thing —
            // it costs sales silently. The gaps are still reported below; they
            // just no longer close the shop door.
            $savedLiveWithGaps = true;
        } else {
            $available    = 0;   // never been live — stays a draft until completed
            $savedAsDraft = true;
        }
    }


    if (empty($errors)) {
        try {
            if ($productId > 0) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE products SET name=:name, description=:description, price=:price,
                    category=:category, category_id=:category_id, emoji=:emoji, image=:image, badge=:badge, available=:available, nuts_allergy=COALESCE(:nuts_allergy, nuts_allergy),
                    track_stock=:track_stock, stock_qty=:stock_qty, atelier_code=COALESCE(:atelier_code, atelier_code), supplier_name=:supplier_name, supplier_ref=COALESCE(:supplier_ref, supplier_ref), supplier_id=:supplier_id, color_way=:color_way, sourcing=:sourcing, composition=:composition,
                    brand=:brand, color=:color, fabric=:fabric, sleeve=:sleeve, neck=:neck, pattern=:pattern, occasion=:occasion, discount_percentage=COALESCE(:discount_percentage, discount_percentage), video_url=:video_url, wash_care=:wash_care, shipping_info=COALESCE(:shipping_info, shipping_info), returns_info=COALESCE(:returns_info, returns_info),
                    sku=:sku, barcode=:barcode, weight=:weight, dimensions=:dimensions, tags=:tags, seo_url=:seo_url, meta_title=:meta_title, meta_description=:meta_description, related_ids=COALESCE(:related_ids, related_ids),
                    mrp_price=:mrp_price, hsn_code=COALESCE(:hsn_code, hsn_code), gst_rate=COALESCE(:gst_rate, gst_rate),
                    use_category_tax=:use_category_tax, price_includes_gst=:price_includes_gst,
                    fit_description=:fit_description, model_height=:model_height, model_size_worn=:model_size_worn, image_alt=:image_alt
                    WHERE id=:id");
                $stmt->execute(compact('name','description','price','category','category_id','emoji','badge','available','atelier_code','supplier_name','supplier_ref','supplier_id','color_way','sourcing','composition','brand','color','fabric','sleeve','neck','pattern','occasion','discount_percentage','video_url','wash_care','shipping_info','returns_info','sku','barcode','weight','dimensions','tags','seo_url','meta_title','meta_description','related_ids','mrp_price','hsn_code','gst_rate','fit_description','model_height','model_size_worn','image_alt') + ['image' => $imageName, 'nuts_allergy' => $nuts_allergy, 'track_stock' => $track_stock, 'stock_qty' => $stock_qty, 'use_category_tax' => $useCategoryTax ? 1 : 0, 'price_includes_gst' => $priceIncludesGst ? 1 : 0, 'id' => $productId]);
                
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO products (name, description, price, category, category_id, emoji, image, badge, available, nuts_allergy, track_stock, stock_qty, atelier_code, supplier_name, supplier_ref, supplier_id, color_way, sourcing, composition, brand, color, fabric, sleeve, neck, pattern, occasion, discount_percentage, video_url, wash_care, shipping_info, returns_info, sku, barcode, weight, dimensions, tags, seo_url, meta_title, meta_description, related_ids, mrp_price, hsn_code, gst_rate, use_category_tax, price_includes_gst, fit_description, model_height, model_size_worn, image_alt)
                    VALUES (:name, :description, :price, :category, :category_id, :emoji, :image, :badge, :available, :nuts_allergy, :track_stock, :stock_qty, :atelier_code, :supplier_name, :supplier_ref, :supplier_id, :color_way, :sourcing, :composition, :brand, :color, :fabric, :sleeve, :neck, :pattern, :occasion, :discount_percentage, :video_url, :wash_care, :shipping_info, :returns_info, :sku, :barcode, :weight, :dimensions, :tags, :seo_url, :meta_title, :meta_description, :related_ids, :mrp_price, :hsn_code, :gst_rate, :use_category_tax, :price_includes_gst, :fit_description, :model_height, :model_size_worn, :image_alt)");
                $stmt->execute(compact('name','description','price','category','category_id','emoji','badge','available','atelier_code','supplier_name','supplier_ref','supplier_id','color_way','sourcing','composition','brand','color','fabric','sleeve','neck','pattern','occasion','discount_percentage','video_url','wash_care','shipping_info','returns_info','sku','barcode','weight','dimensions','tags','seo_url','meta_title','meta_description','related_ids','mrp_price','hsn_code','gst_rate','fit_description','model_height','model_size_worn','image_alt') + ['image' => $imageName, 'nuts_allergy' => (int)($nuts_allergy ?? 0), 'track_stock' => $track_stock, 'stock_qty' => $stock_qty, 'use_category_tax' => $useCategoryTax ? 1 : 0, 'price_includes_gst' => $priceIncludesGst ? 1 : 0]);
                $productId = $pdo->lastInsertId();

                /* A brand-new product cannot already own anything.
                   ────────────────────────────────────────────────────────────
                   MySQL reuses an id after the row holding it is deleted, and
                   older versions of the purge stopped before the child tables —
                   so rows keyed to that id can still be sitting there. A new
                   product then silently adopts a deleted one's photographs, and
                   worse, a stranded size row brings its own PRICE, which the
                   cart prefers over the product's own. That is a wrong price
                   nobody typed and nothing on screen explains.

                   Proven rather than theorised: two products created during the
                   Phase-1 audit arrived owning a deleted product's photograph.
                   The duplicate feature guards the same way. */
                $pdo->prepare("DELETE FROM product_color_images WHERE color_id IN (SELECT id FROM product_colors WHERE product_id = :id)")
                    ->execute([':id' => $productId]);
                foreach (['product_variants', 'product_colors', 'product_images',
                          'product_country_prices', 'product_specifications'] as $inheritTable) {
                    try {
                        $pdo->prepare("DELETE FROM `$inheritTable` WHERE product_id = :id")->execute([':id' => $productId]);
                    } catch (PDOException $e) {
                        // Table absent on this install — not a reason to fail the save.
                    }
                }
            }

            // ── Per-country prices ──────────────────────────────────────
            // Only enabled non-home countries can carry a row; a blank price
            // means "not sold there" and removes any existing row instead of
            // writing a zero, which would otherwise look like a free product.
            foreach (array_filter(enabledCountries(), fn($c) => (int)$c['is_home'] !== 1) as $ccode => $crow) {
                $cpRaw  = trim((string)($countryPricesPosted[$ccode] ?? ''));
                $cspRaw = trim((string)($countrySalePricesPosted[$ccode] ?? ''));
                if ($cpRaw === '') {
                    $pdo->prepare("DELETE FROM product_country_prices WHERE product_id = :pid AND country_code = :cc")
                        ->execute([':pid' => $productId, ':cc' => $ccode]);
                    continue;
                }
                $cpVal  = max(0.0, (float)$cpRaw);
                $cspVal = $cspRaw === '' ? null : max(0.0, (float)$cspRaw);
                $pdo->prepare(
                    "INSERT INTO product_country_prices (product_id, country_code, price, sale_price)
                     VALUES (:pid, :cc, :price, :sale)
                     ON DUPLICATE KEY UPDATE price = VALUES(price), sale_price = VALUES(sale_price)"
                )->execute([':pid' => $productId, ':cc' => $ccode, ':price' => $cpVal, ':sale' => $cspVal]);
            }

            // Insert additional images
            //
            // array_unique guards the retry path: a gallery carried over from a
            // failed attempt must not be inserted twice if the same file is also
            // re-picked before pressing save again.
            foreach (array_unique($uploadedAdditionalImages) as $addlImg) {
                /* sort_order set on insert, not left at 0.
                   Every row defaulting to 0 meant the gallery fell back to id
                   order, so a reorder saved here was overwritten by the next
                   upload — the new photo would share position 0 with the rest
                   and land wherever its id put it. Appending past the current
                   maximum keeps new uploads at the end, where they can then be
                   moved. COALESCE covers the first image on a product. */
                $stmtImg = $pdo->prepare(
                    "INSERT INTO product_images (product_id, image, sort_order)
                     VALUES (?, ?, (SELECT COALESCE(MAX(s.sort_order), 0) + 1
                                      FROM (SELECT sort_order FROM product_images WHERE product_id = ?) s))"
                );
                $stmtImg->execute([$productId, $addlImg, $productId]);
            }
            // Saved and attached — the hold is no longer needed.
            unset($_SESSION['dv_pending_gallery']);

            // Saved. Only now is it safe to remove what this save replaced.
            // deleteUploadedFile() also removes the matching .webp twin.
            foreach ($filesToDeleteOnSuccess as $stale) {
                deleteUploadedFile($stale, __DIR__ . '/../uploads/products/');
            }

            // Who changed what. Product edits were the one high-value action with
            // no audit trail at all — a price could move, or a piece could be
            // published, with nothing recording who did it or when.
            //
            // The price and publish state are named explicitly because those are
            // the two fields anyone would actually need to reconstruct later.
            logAdminAction(
                $_SESSION['admin_id'] ?? 1,
                $isEdit ? 'product_updated' : 'product_created',
                sprintf('#%d "%s" — %s, %s%s',
                    $productId,
                    $name,
                    formatPrice($price),
                    $available ? 'published' : 'draft',
                    $mrp_price > 0 ? ', compare-at ' . formatPrice($mrp_price) : ''
                )
            );

            // A colour product's cover follows its default colour's opening
            // photograph, so the shop card and the product page can never show
            // different garments. Skipped when this save uploaded a main image
            // by hand — an explicit choice outranks the rule until the colours
            // change again.
            if (empty($_FILES['product_image']['name'])) {
                syncProductCoverImage($pdo, $productId);
            }

            if ($savedAsDraft) {
                // Success, but as a draft: stay on the form so the owner is told
                // what is missing and offered Publish Anyway — everything they
                // typed is already safely stored.
                $draftSaved = true;
            } elseif ($savedLiveWithGaps) {
                // Saved AND still on sale. Staying on the form is the only way the
                // owner ever learns which details are missing — redirecting to the
                // list would hide them — but nothing is being withheld, so there is
                // no "Publish Anyway" to press.
                $liveSaved = true;
            } else {
                header('Location: products.php?' . ($isEdit ? 'product_updated=1' : 'product_added=1')); exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
            // The force-to-draft above only ever applied to the write. If the
            // write failed, restore what the owner submitted so the re-rendered
            // form (and the retry) keeps their intent.
            $available = $postedAvailable;
        }
    }

    // The save did not go through, so the gallery is held for the retry.
    //
    // Without this, a save that failed validation silently dropped every image:
    // the browser sends no files the second time, and these were only ever held
    // in a local variable that died with the request.
    //
    // Skipped when the product DID save as a draft — those images were already
    // attached to the row, so re-holding them would make a later page load treat
    // attached files as abandoned and delete them off disk.
    if (empty($draftSaved) && $uploadedAdditionalImages) {
        $_SESSION['dv_pending_gallery'] = array_values(array_unique($uploadedAdditionalImages));
    }

    // ── Re-populate the form ──────────────────────────────
    // Rebuilt from the POSTed values, field by field, so a failed save never
    // loses what the owner typed. When a draft-save succeeded, the row is then
    // re-read from the database — fresh id, updated_at and SKU — which also
    // refreshes the size ladder and the gallery that were loaded at the top of
    // this file for a product that did not exist yet.
    $product = [
        'id' => $productId, 'name' => $name, 'description' => $description,
        // category_id as well as the name: the dropdown now selects by id, so
        // without it a validation error would redraw the form with the category
        // silently reset to "— Select —" and the owner would resubmit it blank.
        'price' => $price, 'category' => $category, 'category_id' => $category_id, 'emoji' => $emoji,
        'image' => $imageName, 'badge' => $badge, 'available' => $available,
        'nuts_allergy' => $nuts_allergy,
        'track_stock' => $track_stock,
        'stock_qty'   => $stock_qty,
        'atelier_code' => $atelier_code, 'color_way' => $color_way,
        'sourcing' => $sourcing, 'composition' => $composition,
        'brand' => $brand, 'color' => $color, 'fabric' => $fabric,
        'sleeve' => $sleeve, 'neck' => $neck, 'pattern' => $pattern,
        'occasion' => $occasion, 'discount_percentage' => $discount_percentage,
        'video_url' => $video_url, 'wash_care' => $wash_care,
        'shipping_info' => $shipping_info, 'returns_info' => $returns_info,
        'sku' => $sku, 'barcode' => $barcode, 'weight' => $weight,
        'dimensions' => $dimensions, 'tags' => $tags, 'seo_url' => $seo_url,
        'meta_title' => $meta_title, 'meta_description' => $meta_description,
        'related_ids' => $related_ids,
        'mrp_price' => $mrp_price, 'hsn_code' => $hsn_code, 'gst_rate' => $gst_rate,
        'use_category_tax' => $useCategoryTax ? 1 : 0, 'price_includes_gst' => $priceIncludesGst ? 1 : 0,
        'fit_description' => $fit_description, 'model_height' => $model_height,
        'model_size_worn' => $model_size_worn, 'image_alt' => $image_alt,
        // Normally the timestamp the form last saw, so an unrelated failure
        // (a validation error, say) still gets a genuine concurrency check on
        // the retry. But when THIS attempt was blocked by that check, hand back
        // the current value instead — otherwise the retry is guaranteed to fail
        // for the same reason, forever, and the owner loses their work.
        'updated_at' => isset($staleSeenAt) && $staleSeenAt !== ''
            ? $staleSeenAt
            : trim((string)($_POST['loaded_updated_at'] ?? '')),
    ];
    $isEdit = $productId > 0;

    if (!empty($draftSaved) || !empty($liveSaved)) {
        // The save succeeded, so prefer the row that was actually written.
        try {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
            $stmt->execute(['id' => $productId]);
            $savedRow = $stmt->fetch();
            if ($savedRow) { $product = $savedRow; }
            $isEdit = true;
            // Recompute what was calculated at the top of the file while this
            // product did not exist yet: the size ladder for its category, and
            // the gallery images this save just attached.
            $sizeLadder = sizeLadderForCategory((int)($product['category_id'] ?? 0));
            $imgRows = $pdo->prepare("SELECT * FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $imgRows->execute(['pid' => $productId]);
            $additionalImages = $imgRows->fetchAll();
        } catch (PDOException $e) {
            // Reload failed — the posted snapshot above already has everything.
        }
    }
}

$activeTab = 'products';
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header admin-page-header-flex">
    <div>
        <h1 class="admin-page-title"><?= $isEdit ? '✏️ Edit Product' : '➕ Add New Product' ?></h1>
        <p class="admin-page-subtitle"><?= $isEdit ? 'Update product details, pricing, variants & photos' : 'Add a new garment or luxury item to your catalog' ?></p>
    </div>
    <a href="products.php" class="btn-secondary admin-header-btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Products
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger admin-alert-mb">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>
        <?php foreach ($errors as $e) echo '<div>'. htmlspecialchars($e) .'</div>'; ?>
    </div>
</div>
<?php endif; ?>

<?php /* Publish details are missing. Two situations land here:
         - the save went through as a DRAFT: everything the owner typed is stored
           and every field is left exactly as it was; the box lists what is
           missing and offers Publish Anyway to push it live without them;
         - the save is blocked by real errors above: the warnings are listed here
           too, so the owner can fix everything in one pass rather than one
           save at a time (the Publish Anyway button is not offered here — the
           errors must be fixed first).

         The button is type="button" because this block sits OUTSIDE <form>: it
         checks the Published box, sets the confirmation flag and then submits
         the form by id. requestSubmit() is preferred over submit() so the
         browser still runs its own required-field checks — plain .submit()
         skips them entirely. */ ?>
<?php if (!empty($publishWarnings) && (!empty($errors) || !empty($draftSaved) || !empty($liveSaved))): ?>
<?php // Green when the product stayed on sale, amber when something is being
      // held back. The colour is the fastest way to answer the only question
      // that matters here: is my product still in the shop or not? ?>
<div class="alert <?= !empty($liveSaved) ? 'alert-success' : 'alert-info' ?> admin-alert-mb">
    <i class="fa-solid <?= (!empty($draftSaved) || !empty($liveSaved)) ? 'fa-circle-check' : 'fa-circle-info' ?>" style="color:<?= !empty($liveSaved) ? '#059669' : '#b45309' ?>;"></i>
    <div>
        <div><strong><?php
            if (!empty($liveSaved))      { echo 'Saved — and still on sale. These details are worth adding when you can:'; }
            elseif (!empty($draftSaved)) { echo 'Saved as a draft — not visible to shoppers yet.'; }
            else                         { echo 'Publish details still missing — fix them together with the errors above.'; }
        ?></strong></div>
        <?php foreach ($publishWarnings as $w) echo '<div>'. htmlspecialchars($w) .'</div>'; ?>
        <?php if (!empty($draftSaved)): ?>
        <button type="button" class="btn-sm btn-sm-danger" style="margin-top:12px; padding:8px 16px; border:none; cursor:pointer;"
                onclick="var f=document.getElementById('productForm');
                         document.getElementById('confirmIncompleteFlag').value='1';
                         var av=document.getElementById('availableCheckbox');
                         if (av) { av.checked = true; }
                         if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }">
            <i class="fa-solid fa-check"></i> Publish Anyway
        </button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>


        <?php if (!empty($_SESSION['admin_flash_error'])): ?>
        <div style="padding:12px 16px; border-radius:8px; margin:0 0 16px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:13px;">
            <?= htmlspecialchars($_SESSION['admin_flash_error']) ?>
        </div>
        <?php unset($_SESSION['admin_flash_error']); endif; ?>

        <form action="product_form.php" method="POST" enctype="multipart/form-data" id="productForm">
        <?php /* Set only by the confirmation in dvCheckImageRatio() below, so it
                 can never be true unless the owner was shown what would be
                 cropped and said to add the picture anyway. Reset on every fresh
                 choice of file — a yes to one photograph is not a yes to the
                 next one. */ ?>
        <input type="hidden" name="allow_image_ratio" id="allowImageRatio" value="">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <?php
            // Carries the owner's "yes, publish it anyway" past the completeness
            // and tax warnings. Written by the Publish Anyway button that appears
            // beside the warnings, so the confirmation can never be given before
            // the warning has been read.
            ?>
            <?php /* Sticky once given. Without this, confirming the warnings and then
                     tripping an unrelated error (a missing image, say) would silently
                     drop the confirmation, and fixing the image would bring the same
                     warnings straight back. */ ?>
            <input type="hidden" name="confirm_incomplete" id="confirmIncompleteFlag"
                   value="<?= !empty($confirmIncomplete) ? '1' : '' ?>">
            <?php if ($isEdit): ?>
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="existing_image" value="<?= htmlspecialchars($product['image'] ?? '') ?>">
            <?php endif; ?>

            <div class="product-form-grid">

                <!-- ── Form Fields ───────────────────────── -->
                <div>
                    <div class="glass-panel form-section">
                        <h3><i class="fa-solid fa-circle-info"></i> Basic Info</h3>

                        <div class="form-group">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="name" class="form-control"
                                placeholder="e.g. Aurelia Silk Kurti"
                                value="<?= htmlspecialchars($product['name'] ?? '') ?>" required
                                oninput="updatePreviewName(this.value)">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" rows="3"
                                placeholder="Describe the fabric, craftsmanship, and fit…" required><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Selling Price (₹) *</label>
                                <input type="number" name="price" class="form-control"
                                    step="0.01" min="0.01" placeholder="e.g. 1299.00"
                                    value="<?= htmlspecialchars($product['price'] ?? '') ?>" required
                                    oninput="updatePreviewPrice(this.value)">
                            </div>
                            <div class="form-group">
                                <label class="form-label">MRP / Strikethrough (₹)</label>
                                <input type="number" name="mrp_price" class="form-control"
                                    step="0.01" min="0" placeholder="e.g. 1899.00"
                                    value="<?= htmlspecialchars($product['mrp_price'] ?? '') ?>">
                                <small class="text-muted">Set higher than Selling Price to put this product on Sale — shows a strikethrough price and "% OFF" badge across the site.</small>
                            </div>
                        </div>

                        <?php if ($sellableCountries): ?>
                        <?php // Prices are set per country, not converted (see admin/countries.php).
                              // A blank price here means the product is simply not sold in that
                              // country — the same rule the storefront country picker follows. ?>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label class="form-label">International Pricing</label>
                            <p class="form-hint" style="margin-top:-4px;">
                                Leave a country blank to keep this product India-only for that market.
                                Manage which countries are enabled under
                                <a href="countries.php" target="_blank">Countries We Sell To</a>.
                            </p>
                            <div class="form-row" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));">
                                <?php foreach ($sellableCountries as $ccode => $crow):
                                    $existingCp = $existingCountryPrices[$ccode] ?? null;
                                ?>
                                <div class="form-group">
                                    <label class="form-label"><?= htmlspecialchars($crow['country_name']) ?> (<?= htmlspecialchars($crow['currency_symbol']) ?> <?= htmlspecialchars($crow['currency_code']) ?>)</label>
                                    <input type="number" name="country_price[<?= htmlspecialchars($ccode) ?>]" class="form-control"
                                        step="0.01" min="0" placeholder="Not sold here"
                                        value="<?= $existingCp ? htmlspecialchars($existingCp['price']) : '' ?>">
                                    <input type="number" name="country_sale_price[<?= htmlspecialchars($ccode) ?>]" class="form-control"
                                        step="0.01" min="0" placeholder="Sale price (optional)" style="margin-top:6px;"
                                        value="<?= ($existingCp && $existingCp['sale_price'] !== null) ? htmlspecialchars($existingCp['sale_price']) : '' ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- Cost Price was removed from this form: it is internal-only, is not
                             read anywhere on the storefront, and sat next to Selling Price and
                             MRP where it was easy to type into the wrong box. The `cost_price`
                             DB column is intentionally left in place so historic values are
                             preserved and are not overwritten when a product is saved. -->

                        <?php // Tax settings: every product inherits its category's defaults by
                              // default (set under Categories → the collection's edit panel). The
                              // "Use Category Tax Settings" checkbox LOCKS the HSN/GST fields to
                              // the inherited values; unchecking it unlocks them so this product
                              // can carry its own. The inherited values are written onto the
                              // product on save so invoices keep working. price_includes_gst is a
                              // plain Yes/No stored on the product. ?>
                        <div class="form-row">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="admin-checkbox-label">
                                    <input type="checkbox" name="use_category_tax" id="useCategoryTax" value="1"
                                           class="admin-checkbox-input" <?= !empty($product['use_category_tax'] ?? 1) ? 'checked' : '' ?>>
                                    Use Category Tax Settings
                                </label>
                                <small class="text-muted" id="taxModeHint">Inherited from the collection's defaults — uncheck to edit this product's own HSN & GST.</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Product HSN Code</label>
                                <input type="text" name="hsn_code" id="taxHsnInput" class="form-control<?= !empty($product['use_category_tax'] ?? 1) ? ' tax-locked' : '' ?>"
                                       placeholder="e.g. 6204 4300"
                                       <?= !empty($product['use_category_tax'] ?? 1) ? 'readonly tabindex="-1"' : '' ?>
                                       value="<?= htmlspecialchars((string)($product['hsn_code'] ?? '')) ?>">
                                <small class="text-muted">Tax classification for invoices. Leave blank for no tax on this product.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Product GST Tax Class/Rate (%)</label>
                                <input type="number" name="gst_rate" id="taxGstInput" class="form-control<?= !empty($product['use_category_tax'] ?? 1) ? ' tax-locked' : '' ?>"
                                       step="0.01" min="0" placeholder="e.g. 12"
                                       <?= !empty($product['use_category_tax'] ?? 1) ? 'readonly tabindex="-1"' : '' ?>
                                       value="<?= isset($product['gst_rate']) && $product['gst_rate'] !== '' ? htmlspecialchars((string)(float)$product['gst_rate']) : '' ?>">
                                <small class="text-muted">Indian GST slabs: 0, 0.25, 3, 5, 12, 18 or 28. 0 = no tax.</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Price Includes GST</label>
                                <input type="text" class="form-control tax-locked" readonly tabindex="-1"
                                       value="<?= $storePriceIncludesGst ? 'Yes — selling price already includes GST' : 'No — GST is added on top of the selling price' ?>">
                                <small class="text-muted">Store-wide setting — change it under Settings &rarr; Store General Settings &rarr; Invoice &amp; GST details. The same choice applies to every product.</small>
                            </div>
                        </div>

                        <?php
                                // Which category is already chosen. Prefer the id; fall back to
                                // matching the stored NAME for products saved before this form
                                // started posting ids, so editing an old product does not quietly
                                // reset its category to blank.
                                $selectedCatId = (int)($product['category_id'] ?? 0);
                                if ($selectedCatId === 0 && !empty($product['category'])) {
                                    foreach ($categoryGroups as $g) {
                                        foreach ($g['items'] as $it) {
                                            if (strcasecmp(trim($it['label']), trim((string)$product['category'])) === 0) {
                                                $selectedCatId = $it['id'];
                                                break 2;
                                            }
                                        }
                                    }
                                }

                                // Which audience that category belongs to, so editing an existing
                                // product opens on the correct side rather than resetting it.
                                $selectedAudience = '';
                                foreach ($categoryGroups as $g) {
                                    foreach ($g['items'] as $it) {
                                        if ($it['id'] === $selectedCatId) { $selectedAudience = $g['gender']; break 2; }
                                    }
                                }

                                $audiences = [];
                                foreach ($categoryGroups as $g) { $audiences[$g['gender']] = true; }
                                $audienceLabels = ['women' => 'Woman', 'men' => 'Man', 'unisex' => 'Unisex'];
                                if ($selectedAudience === '') { $selectedAudience = (string)array_key_first($audiences); }
                            ?>
                        <?php /* Its own .form-row. That grid is exactly two columns
                                 (admin/assets/css/style.css:836), so dropping these two
                                 selects into the HSN/GST row above gave it four children,
                                 which reflowed the whole panel and pushed the live image
                                 preview down the page. */ ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Who is it for *</label>
                                <select id="productAudience" class="form-control">
                                    <?php foreach (array_keys($audiences) as $aud): ?>
                                    <option value="<?= htmlspecialchars($aud) ?>" <?= $selectedAudience === $aud ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($audienceLabels[$aud] ?? ucfirst($aud)) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-hint">Choose this first — it narrows the collections below.</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <?php // Every option is rendered server-side and the script below hides the
                                      // ones that do not match. If that script ever fails to run the form
                                      // still works with the full list, which is how it behaved before. ?>
                                <select name="category_id" id="productCategory" class="form-control" required>
                                    <option value="">— Select —</option>
                                    <?php foreach ($categoryGroups as $group): ?>
                                    <optgroup label="<?= htmlspecialchars($group['name']) ?>" data-gender="<?= htmlspecialchars($group['gender']) ?>">
                                        <?php foreach ($group['items'] as $item): ?>
                                        <option value="<?= (int)$item['id'] ?>" data-gender="<?= htmlspecialchars($group['gender']) ?>" <?= $selectedCatId === $item['id'] ? 'selected' : '' ?>>
                                            <?= $item['is_sub'] ? '— ' : '' ?><?= htmlspecialchars($item['label']) ?><?= $item['is_sub'] ? '' : ' (main collection)' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-hint">
                                    The collection you pick is what makes the product menswear or womenswear —
                                    there is no separate setting on the product itself.
                                </p>
                            </div>
                        </div>

                            <script>
                            (function () {
                                var aud = document.getElementById('productAudience');
                                var cat = document.getElementById('productCategory');
                                if (!aud || !cat) { return; }

                                // Rebuilding the <select> from a stored copy rather than toggling
                                // display on <optgroup>: hiding option groups with CSS is honoured
                                // inconsistently across browsers, and a merely hidden option can
                                // still be selected by keyboard, which would file a men's product
                                // under a women's collection with nothing on screen to show it.
                                var master = cat.cloneNode(true);

                                function apply(keepValue) {
                                    var want = aud.value;
                                    cat.innerHTML = '';
                                    var blank = document.createElement('option');
                                    blank.value = '';
                                    blank.textContent = '— Select —';
                                    cat.appendChild(blank);

                                    var matched = 0;
                                    Array.prototype.forEach.call(master.querySelectorAll('optgroup'), function (g) {
                                        if (g.getAttribute('data-gender') !== want) { return; }
                                        var clone = g.cloneNode(true);
                                        cat.appendChild(clone);
                                        matched += clone.querySelectorAll('option').length;
                                    });

                                    // Keep the current choice only when it survives the filter.
                                    if (keepValue) {
                                        cat.value = keepValue;
                                        if (cat.value !== keepValue) { cat.value = ''; }
                                    }
                                    cat.disabled = matched === 0;
                                }

                                apply(cat.value);
                                aud.addEventListener('change', function () { apply(cat.value); });
                            })();
                            </script>

                            <script>
                            // Tax settings: the HSN/GST fields below are ALWAYS visible. While the
                            // "Use Category Tax Settings" checkbox is checked they are locked to the
                            // inherited values (which update as the category changes, falling back to
                            // the product's own stored values when the category has no defaults — so
                            // editing a legacy product shows what the invoice will actually print).
                            // Unchecking unlocks them so this product can carry its own HSN/GST; the
                            // inherited values are copied in as a starting point.
                            (function () {
                                var taxMap = <?= json_encode($catTaxMap, JSON_UNESCAPED_UNICODE) ?>;
                                var catSel = document.getElementById('productCategory');
                                var audSel = document.getElementById('productAudience');
                                var useCat = document.getElementById('useCategoryTax');
                                var hsnIn = document.getElementById('taxHsnInput');
                                var gstIn = document.getElementById('taxGstInput');
                                var hint  = document.getElementById('taxModeHint');
                                // The product's own stored values, so the locked fields can fall
                                // back to them when no category default exists.
                                var storedHsn = <?= json_encode((string)($product['hsn_code'] ?? '')) ?>;
                                var storedGst = <?= json_encode(isset($product['gst_rate']) && $product['gst_rate'] !== '' ? (float)$product['gst_rate'] : 0) ?>;
                                if (!catSel || !hsnIn || !gstIn || !useCat) { return; }
                                // Apparel – Auto by Price: the slab depends on what this
                                // garment costs, so the locked field has to follow the
                                // price box instead of showing one stored number.
                                var priceIn = document.querySelector('input[name="price"]');
                                var APPAREL_THRESHOLD = <?= (int)APPAREL_GST_THRESHOLD ?>;
                                function autoApparelGst() {
                                    var p = parseFloat(priceIn && priceIn.value) || 0;
                                    return p > APPAREL_THRESHOLD ? 18 : 5;
                                }
                                function inheritedTax() {
                                    var t = taxMap[catSel.value] || null;
                                    var h = (t && t.hsn) ? t.hsn : storedHsn;
                                    if (t && t.auto) { return { hsn: h, gst: autoApparelGst() }; }
                                    if (t && t.zero) { return { hsn: h, gst: 0 }; }
                                    // Nothing set on the collection: the apparel slabs are the
                                    // default, matching what the save actually stores.
                                    var g = (t && t.gst > 0) ? t.gst : autoApparelGst();
                                    return { hsn: h, gst: g };
                                }
                                if (priceIn) { priceIn.addEventListener('input', refreshTax); }
                                function lockTax(on) {
                                    hsnIn.readOnly = on;
                                    gstIn.readOnly = on;
                                    hsnIn.tabIndex = on ? -1 : 0;
                                    gstIn.tabIndex = on ? -1 : 0;
                                    hsnIn.classList.toggle('tax-locked', on);
                                    gstIn.classList.toggle('tax-locked', on);
                                }
                                function refreshTax() {
                                    // Only touch the fields while locked: once unlocked, whatever
                                    // the owner typed is theirs to keep.
                                    if (useCat.checked) {
                                        var v = inheritedTax();
                                        hsnIn.value = v.hsn;
                                        gstIn.value = v.gst;
                                    }
                                }
                                function syncTaxMode() {
                                    var on = useCat.checked;
                                    lockTax(on);
                                    hint.textContent = on
                                        ? 'Inherited from the collection\'s defaults — uncheck to edit this product\'s own HSN & GST.'
                                        : 'Override: this product\'s own HSN & GST will be saved exactly as typed.';
                                    if (on) { refreshTax(); }
                                }
                                useCat.addEventListener('change', syncTaxMode);
                                catSel.addEventListener('change', refreshTax);
                                if (audSel) { audSel.addEventListener('change', function () { setTimeout(refreshTax, 0); }); }
                                syncTaxMode();
                            })();
                            </script>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Badge</label>
                                <select name="badge" class="form-control">
                                    <?php foreach ($badges as $b): ?>
                                    <option value="<?= $b ?>" <?= ($product['badge'] ?? '') === $b ? 'selected' : '' ?>>
                                        <?= $b ?: '— None —' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php
                                /* Say so on the screen. A badge that vanishes on its own is a
                                   good rule and an alarming surprise — without this line the
                                   owner would open the shop one morning, find New gone from a
                                   product still showing New here, and reasonably call it a
                                   bug. The countdown is shown for a saved product so there is
                                   never any doubt about where it stands. */
                                $badgeNote = '';
                                if (strcasecmp(trim((string)($product['badge'] ?? '')), 'New') === 0
                                    && trim((string)($product['created_at'] ?? '')) !== '') {
                                    $addedTs = strtotime((string)$product['created_at']);
                                    if ($addedTs !== false) {
                                        $daysLeft = NEW_BADGE_DAYS - (int)floor((time() - $addedTs) / 86400);
                                        $badgeNote = $daysLeft > 0
                                            ? ' Showing for another ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . '.'
                                            : ' This one is past ' . NEW_BADGE_DAYS . ' days, so shoppers no longer see it.';
                                    }
                                }
                                ?>
                                <p class="form-hint">
                                    <strong>New</strong> comes off by itself <?= NEW_BADGE_DAYS ?> days after the product was
                                    added — you never have to remember to clear it.<?= $badgeNote ?>
                                    Hot and Best Seller stay until you change them.
                                </p>
                            </div>
                            <?php /* admin-publish-group, because this one is not a lone checkbox:
                                     it carries an explanation under it, and the row layout that
                                     .admin-form-checkbox-align provides put the two side by side. */ ?>
                            <div class="form-group admin-form-checkbox-align admin-publish-group">
                                <?php // updated_at as it was when this form rendered. Compared on save so a
                                      // concurrent edit is refused rather than silently overwritten. ?>
                                <input type="hidden" name="loaded_updated_at" value="<?= htmlspecialchars($product['updated_at'] ?? '') ?>">

                                <?php // Reworded from "Available in shop". Unticked is a DRAFT — the product is
                                      // saved but invisible to shoppers, which is what lets an incomplete listing
                                      // be built up over several sittings without ever going live half-finished.
                                      // The publish-only validation rules key off exactly this box. ?>
                                <label class="admin-checkbox-label">
                                    <input type="checkbox" name="available" value="1" id="availableCheckbox" class="admin-checkbox-input"
                                        <?= ($product['available'] ?? 1) ? 'checked' : '' ?>>
                                    Published &mdash; visible to shoppers
                                </label>
                                <p class="admin-publish-hint">
                                    Leave unticked to keep it as a <strong>draft</strong>. A draft can be saved
                                    incomplete; publishing requires an image, SKU, SEO title, meta description and slug.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dynamic Fashion Specifications -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-gem"></i> Fashion Specifications</h3>
                        <p class="admin-section-desc">These details appear on the product page.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <?php // Color Way is the one colour field now — the duplicate "Color" box in
                                      // Advanced Filters was removed, and every save mirrors this value into
                                      // the stored colour column (which powers the shop filter, the schema
                                      // and the product page). Old products may have a colour only in that
                                      // legacy column, so it is shown here as a fallback rather than lost. ?>
                                <label class="form-label">Color Way
                                    <a href="attributes.php?type=colors" style="font-size:11px; font-weight:400; color:var(--text-muted); margin-left:6px;"
                                       title="Manage the shop's colour list">
                                        <?= $masterColors ? count($masterColors) . ' saved' : 'add colours' ?> &rarr;
                                    </a>
                                </label>
                                <input type="text" name="color_way" list="dv-color-options" class="form-control" placeholder="e.g. Mint Green" value="<?= htmlspecialchars($product['color_way'] ?? ($product['color'] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Composition</label>
                                <input type="text" name="composition" list="dv-composition-options" class="form-control" placeholder="e.g. 100% Premium Silk" value="<?= htmlspecialchars($product['composition'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="grid-column:1 / -1;">
                                <?php // Sourcing is fixed for every product — the save handler writes
                                      // INDIA regardless — so it is shown read-only instead of editable. ?>
                                <label class="form-label">Sourcing / Origin</label>
                                <input type="text" class="form-control" value="INDIA" readonly tabindex="-1">
                                <p class="form-hint">Sourced in India — the same for every product.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Filtering & Details -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-filter"></i> Advanced Filters & Details</h3>
                        <p class="admin-section-desc">These attributes power the sidebar filters and product page tabs.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Brand</label>
                                <input type="text" name="brand" list="dv-brand-options" class="form-control" placeholder="e.g. Dievon" value="<?= htmlspecialchars($product['brand'] ?? '') ?>">
                            </div>
                            <?php // The duplicate "Color" box used to sit here — it held exactly the same
                                  // thing as Color Way in Fashion Specifications, so it has been removed.
                                  // Colour is now typed once, in Color Way (see above). ?>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Fabric</label>
                                <input type="text" name="fabric" list="dv-fabric-options" class="form-control" placeholder="e.g. Cotton" value="<?= htmlspecialchars($product['fabric'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Sleeve</label>
                                <input type="text" name="sleeve" list="dv-sleeve-options" class="form-control" placeholder="e.g. Full Sleeve" value="<?= htmlspecialchars($product['sleeve'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Neck</label>
                                <input type="text" name="neck" list="dv-neck-options" class="form-control" placeholder="e.g. Round Neck" value="<?= htmlspecialchars($product['neck'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Pattern</label>
                                <input type="text" name="pattern" list="dv-pattern-options" class="form-control" placeholder="e.g. Floral" value="<?= htmlspecialchars($product['pattern'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Occasion</label>
                                <input type="text" name="occasion" list="dv-occasion-options" class="form-control" placeholder="e.g. Casual" value="<?= htmlspecialchars($product['occasion'] ?? '') ?>">
                            </div>
                            <?php // The "Discount (%)" box that stood here has been REMOVED.
                                  //
                                  // It saved a number to products.discount_percentage that not one
                                  // line of the storefront ever read — verified by creating a product
                                  // with it set to 15 and finding no trace of it on the product page,
                                  // the card, the cart or the schema. It was validated (0–90) and had
                                  // a helpful placeholder, so it looked entirely functional while
                                  // doing nothing at all.
                                  //
                                  // Discounts are the MRP field in Basic Info: set MRP above Price
                                  // and the strike-through, the "% OFF" badge and the sale listing
                                  // all follow from the two real numbers. The column is left in the
                                  // database and its stored value preserved on save, so nothing is
                                  // destroyed for anyone who set it previously. ?>
                        </div>

                        <?php // Fit and model reference. Shown on the product page only when
                              // filled in — unlike the rows above, these get NO invented
                              // default, because "Regular fit" on a garment nobody assessed is
                              // a guess the shopper would act on when choosing a size. ?>
                        <div class="form-row">
                            <div class="form-group" style="grid-column:1 / -1;">
                                <label class="form-label">Image Alt Text
                                    <span class="form-hint">describes the photo for screen readers and Google Images</span>
                                </label>
                                <input type="text" name="image_alt" class="form-control" maxlength="160"
                                       placeholder="e.g. Model wearing a wine silk kurti with gold zari border"
                                       value="<?= htmlspecialchars($product['image_alt'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fit Description</label>
                                <input type="text" name="fit_description" class="form-control" maxlength="255"
                                       placeholder="e.g. Relaxed through the body, true to size"
                                       value="<?= htmlspecialchars($product['fit_description'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Model Height</label>
                                <input type="text" name="model_height" class="form-control" maxlength="40"
                                       placeholder="e.g. 5ft 8in"
                                       value="<?= htmlspecialchars($product['model_height'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Size Worn by Model</label>
                                <input type="text" name="model_size_worn" class="form-control" maxlength="20"
                                       placeholder="e.g. S"
                                       value="<?= htmlspecialchars($product['model_size_worn'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="admin-attribute-grid">
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Wash Care</label>
                                <textarea name="wash_care" class="form-control" rows="3"><?= htmlspecialchars(productWashCare($product ?? [])) ?></textarea>
                                <p class="form-hint">Filled in with the shop's standard care wording. Edit it for silk, zari or hand embroidery — anything that must not be washed at home. Clear it and the standard wording is shown on the page anyway.</p>
                            </div>
                            <div class="form-group">
                                <?php // Shipping & Returns are no longer set per product — the same
                                      // promise applies to every garment, and the product page shows
                                      // the shop-wide default when a product has no stored value.
                                      // Existing per-product values are preserved on save (see the
                                      // POST handler), so nothing already entered is destroyed. ?>
                                <label class="form-label">Shipping &amp; Returns</label>
                                <p class="form-hint" style="margin-top:6px;">
                                    The same for every product — the shop-wide promise is shown
                                    automatically on the product page. No entry needed here.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory & Logistics (SKU, Barcode, Weight, Dimensions) -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3><i class="fa-solid fa-boxes-packing"></i> Inventory &amp; Logistics Specifications</h3>
                        <p class="admin-section-desc">Track SKU codes, barcodes for scanner billing, and package weight/dimensions for shipping calculators.</p>
                        
                        <?php /* Who this came from, and what THEY call it.
                                 ────────────────────────────────────────────────
                                 An order used to arrive naming only the garment
                                 and its size, which is not enough to fulfil it —
                                 the owner had to remember which supplier made it.
                                 Both figures are frozen into the order at the
                                 moment of sale, so changing supplier later cannot
                                 rewrite who actually supplied an old order.

                                 atelier_code is not new: it has been in the
                                 database all along and is already read by
                                 productDisplayCode(). It simply never had a box. */ ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Supplier</label>
                                <?php /* Chosen, not typed. Everything about the supplier itself —
                                         owner, phone — lives on the Suppliers screen; this only
                                         records WHICH supplier the piece comes from. */
                                      $dvSuppliers = [];
                                      try {
                                          $dvSuppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")
                                                             ->fetchAll(PDO::FETCH_ASSOC);
                                      } catch (PDOException $e) { /* migration not run yet */ }
                                      $dvCurSup = (int)($product['supplier_id'] ?? 0); ?>
                                <select name="supplier_id" class="form-control">
                                    <option value="">— none —</option>
                                    <?php foreach ($dvSuppliers as $sup): ?>
                                        <option value="<?= (int)$sup['id'] ?>" <?= $dvCurSup === (int)$sup['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sup['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-hint">
                                    <?php if ($dvSuppliers): ?>
                                        Shown on the order so you know who to call.
                                        Add or edit suppliers under <a href="suppliers.php">Suppliers</a>.
                                    <?php else: ?>
                                        No suppliers yet — add them under <a href="suppliers.php">Suppliers</a> first.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Supplier's design no.</label>
                                <input type="text" name="supplier_ref" class="form-control"
                                       placeholder="e.g. MT-4471"
                                       value="<?= htmlspecialchars($product['supplier_ref'] ?? '') ?>">
                                <p class="form-hint">
                                    Their code for this piece, not yours — this is what you quote when
                                    you reorder. Admin only: it is never shown to a shopper. If a single
                                    colourway has its own code, put that on the colour instead.
                                </p>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">SKU Code</label>
                                <?php /* Read-only once the piece has been sold, matching what the
                                         save handler enforces. Shown rather than hidden, because the
                                         code is still the thing you quote to a customer or a
                                         supplier — it just cannot be rewritten under an order that
                                         already went out carrying it. */
                                      $skuLocked = false;
                                      if (!empty($product['id'])) {
                                          try {
                                              $skuChk = $pdo->prepare(
                                                  "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                                                    WHERE oi.product_id = :id AND o.created_at >= :born
                                                   UNION
                                                   SELECT 1 FROM orders WHERE items_json LIKE :like AND created_at >= :born2
                                                   LIMIT 1"
                                              );
                                              $skuBorn = (string)($product['created_at'] ?? '1970-01-01');
                                              $skuChk->execute([':id' => (int)$product['id'], ':born' => $skuBorn, ':born2' => $skuBorn,
                                                                ':like' => '%"product_id":' . (int)$product['id'] . ',%']);
                                              $skuLocked = (bool)$skuChk->fetchColumn();
                                          } catch (PDOException $e) { $skuLocked = false; }
                                      } ?>
                                <input type="text" name="sku" class="form-control<?= $skuLocked ? ' tax-locked' : '' ?>"
                                       placeholder="e.g. DV-KURTI-001"
                                       value="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                                       <?= $skuLocked ? 'readonly tabindex="-1"' : '' ?>>
                                <p class="form-hint">
                                    <?php if ($skuLocked): ?>
                                        Locked — this piece has been sold, and past orders record the code
                                        it was sold under. Changing it now would leave those orders quoting
                                        a code this product no longer has.
                                    <?php else: ?>
                                        Your own code. Left blank, one is made from the product name. Once
                                        the piece has been sold the code is fixed.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Barcode / EAN</label>
                                <input type="text" name="barcode" class="form-control" placeholder="e.g. 5060123456789" value="<?= htmlspecialchars($product['barcode'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Package Weight</label>
                                <input type="text" name="weight" class="form-control" placeholder="e.g. 0.85 kg" value="<?= htmlspecialchars($product['weight'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Dimensions (L × W × H)</label>
                                <input type="text" name="dimensions" class="form-control" placeholder="e.g. 35 × 25 × 5 cm" value="<?= htmlspecialchars($product['dimensions'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <?php // The SEO panel starts closed on a fresh form; it opens automatically
                          // when the product already carries stored SEO values, so editing them
                          // does not mean hunting for the toggle first. ?>
                    <?php
                    /* Always starts collapsed.
                       ────────────────────────────────────────────────────────
                       This used to open whenever the product had a seo_url,
                       tags, meta_title or meta_description. seo_url is written
                       automatically on every save, so the condition was true for
                       11 of 12 products — the panel was effectively always open
                       on edit, and a long block of optional fields stood between
                       the owner and the ones they came to change.

                       Everything inside is optional and derives itself from the
                       product when left blank, so there is nothing here that
                       needs attention on a routine edit. It opens on the one
                       click it has always had. */
                    $seoStored = false;
                    ?>
                    <!-- SEO, OpenGraph & Search Tags -->
                    <div class="glass-panel form-section admin-section-mt">
                        <h3 style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                            <span><i class="fa-solid fa-magnifying-glass-chart"></i> SEO, OpenGraph &amp; Google Schema</span>
                            <button type="button" id="seoToggleBtn" onclick="toggleSeoPanel()" class="btn-sm btn-sm-outline" style="padding:6px 12px; cursor:pointer;">
                                <i class="fa-solid <?= $seoStored ? 'fa-chevron-up' : 'fa-chevron-down' ?>"></i>
                                <?= $seoStored ? 'Hide' : 'Show' ?> SEO options
                            </button>
                        </h3>
                        <p class="admin-section-desc">Optimize how your product ranks on Google Search, WhatsApp, Instagram, and Twitter share previews.</p>

                        <div id="seoPanelBody" style="display:<?= $seoStored ? '' : 'none' ?>;">

                        <?php
                        // Every field here is optional. Left blank, the storefront derives the
                        // value from the product's own name/category/description/attributes via
                        // resolveProductSeo() in config/config.php. We show that exact derived
                        // value as the placeholder so the admin can see what will actually ship.
                        $autoSeo = resolveProductSeo($product ?? []);
                        ?>
                        <div class="seo-auto-note">
                            <i class="fa-solid fa-wand-magic-sparkles seo-auto-note-icon"></i>
                            <div>
                                <strong class="seo-auto-note-title">These are generated automatically</strong>
                                Leave any field blank and it is built from the product name, category, description and
                                attributes — the greyed-out text in each box is exactly what will be used.
                                Fill a field in only when you want to override it.
                                <button type="button" onclick="fillAutoSeo()" class="seo-auto-fill-btn">
                                    Copy the generated values in so I can edit them
                                </button>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">SEO URL Slug <small class="text-muted">(auto)</small></label>
                                <input type="text" name="seo_url" id="seoUrlField" class="form-control" placeholder="<?= htmlspecialchars($autoSeo['seo_url'] ?: 'e.g. luxury-silk-kurti-green') ?>" value="<?= htmlspecialchars($product['seo_url'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Search Tags <small class="text-muted">(auto, comma separated)</small></label>
                                <input type="text" name="tags" id="seoTagsField" class="form-control" placeholder="<?= htmlspecialchars($autoSeo['tags'] ?: 'e.g. silk, kurti, summer, wedding, green') ?>" value="<?= htmlspecialchars($product['tags'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Meta Title <small class="text-muted">(auto — best under 60 characters)</small></label>
                            <input type="text" name="meta_title" id="seoTitleField" class="form-control" placeholder="<?= htmlspecialchars($autoSeo['meta_title'] ?: 'e.g. Buy Luxury Green Silk Kurti | Dievon') ?>" value="<?= htmlspecialchars($product['meta_title'] ?? '') ?>" oninput="countSeo(this,'seoTitleCount',60)">
                            <small class="text-muted"><span id="seoTitleCount"></span></small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Meta Description <small class="text-muted">(auto — best under 155 characters)</small></label>
                            <textarea name="meta_description" id="seoDescField" class="form-control" rows="2" placeholder="<?= htmlspecialchars($autoSeo['meta_description'] ?: 'e.g. Explore Dievon hand-crafted Mulberry Silk Kurti with metallic embroidery.') ?>" oninput="countSeo(this,'seoDescCount',155)"><?= htmlspecialchars($product['meta_description'] ?? '') ?></textarea>
                            <small class="text-muted"><span id="seoDescCount"></span></small>
                        </div>
                        </div>
                    </div>

                    <script>
                    // Pull the server-generated values into the inputs so they can be tweaked
                    // by hand. Until then the fields stay empty, which is what keeps them
                    // "automatic" — the storefront regenerates them as the product changes.
                    const DIEVON_AUTO_SEO = <?= json_encode($autoSeo, JSON_UNESCAPED_UNICODE) ?>;
                    async function fillAutoSeo() {
                        // Copying the values in turns them into stored, manual text. From then
                        // on they no longer follow the product — rename the garment, change its
                        // fabric or price, and these fields will keep the old wording until
                        // someone edits them by hand. Worth an explicit confirmation.
                        const ok = await dievonConfirm(
                            'Right now these SEO fields are automatic — they rewrite themselves whenever you change the product name, category or description.\n\n' +
                            'Copying the generated text into the boxes makes them manual. They will stay exactly as written and will stop updating on their own.\n\n' +
                            'Do you want to take over and edit the SEO yourself?',
                            { title: 'Switch SEO to manual?', confirmText: 'Yes, let me edit', cancelText: 'Keep it automatic' }
                        );
                        if (!ok) return;

                        const map = {
                            seoUrlField:   DIEVON_AUTO_SEO.seo_url,
                            seoTagsField:  DIEVON_AUTO_SEO.tags,
                            seoTitleField: DIEVON_AUTO_SEO.meta_title,
                            seoDescField:  DIEVON_AUTO_SEO.meta_description
                        };
                        Object.entries(map).forEach(([id, val]) => {
                            const el = document.getElementById(id);
                            if (el && !el.value.trim()) { el.value = val || ''; el.dispatchEvent(new Event('input')); }
                        });
                        dievonAlert('The generated text has been copied into the fields. Edit it as you like — to go back to automatic, simply clear a field and save.', { type: 'success', title: 'Now editable' });
                    }
                    function countSeo(el, targetId, limit) {
                        const out = document.getElementById(targetId);
                        if (!out) return;
                        const n = el.value.trim().length;
                        // Colour comes from the .seo-count-over class, not an inline style.
                        if (n === 0) { out.textContent = 'Blank — the generated value shown above will be used.'; out.classList.remove('seo-count-over'); return; }
                        out.textContent = n + ' / ' + limit + ' characters' + (n > limit ? ' — Google may truncate this' : '');
                        out.classList.toggle('seo-count-over', n > limit);
                    }
                    document.addEventListener('DOMContentLoaded', () => {
                        ['seoTitleField','seoDescField'].forEach(id => {
                            const el = document.getElementById(id);
                            if (el) countSeo(el, id === 'seoTitleField' ? 'seoTitleCount' : 'seoDescCount', id === 'seoTitleField' ? 60 : 155);
                        });
                    });
                    function toggleSeoPanel() {
                        const body = document.getElementById('seoPanelBody');
                        const btn  = document.getElementById('seoToggleBtn');
                        if (!body || !btn) return;
                        const open = body.style.display !== 'none';
                        body.style.display = open ? 'none' : '';
                        btn.innerHTML = open
                            ? '<i class="fa-solid fa-chevron-down"></i> Show SEO options'
                            : '<i class="fa-solid fa-chevron-up"></i> Hide SEO options';
                    }
                    </script>

                    <!-- Stock Management -->
                    <div class="form-row admin-form-row-mt">
                        <div class="form-group admin-form-checkbox-align">
                            <label class="admin-checkbox-label">
                                <input type="checkbox" name="track_stock" value="1" id="trackStockCb" class="admin-checkbox-input"
                                    <?= !empty($product['track_stock']) ? 'checked' : '' ?>>
                                📦 Track Stock
                            </label>
                            <?php if ($displayInStock !== null): ?>
                            <span class="admin-stock-badge-active">
                                🟢 In Stock: <?= $displayInStock ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <!-- Stock quantity is managed via the Stock tab, not here -->
                        <input type="hidden" name="stock_qty" value="<?= (int)($product['stock_qty'] ?? 0) ?>">
                    </div>

                    <!-- ── Image & Video Media Upload ───────────────────── -->
                    <div class="glass-panel form-section">
                        <h3><i class="fa-solid fa-photo-film"></i> Product Image &amp; 360° Video</h3>
                        <p class="admin-section-desc">Upload primary product photo and/or 360° product video.</p>

                        <?php if (!empty($product['image'])): ?>
                        <div class="admin-current-img-box">
                            <p class="admin-current-img-label">Current image:</p>
                            <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>"
                                 alt="Current product image"
                                 class="image-current-thumb admin-current-img-thumb"
                                 id="previewCurrentImg">
                        </div>
                        <?php endif; ?>

                        <div class="form-group admin-mb-18">
                            <label class="form-label admin-label-bold">1. Product Main Photo</label>
                            <div class="image-upload-box" id="uploadBox">
                                <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp,image/gif"
                                    id="productImageInput" onchange="previewUpload(this)">
                                <div class="image-upload-icon">📷</div>
                                <?php /* The ratio, said where the file is chosen.
                                         ────────────────────────────────────────────────
                                         Every product image frame on the shop is 3:4 with
                                         object-fit: cover — the card, the gallery, Quick
                                         View, "similar products", the mega-menu promo. A
                                         3:4 photograph therefore loses NOTHING. Any other
                                         shape is centre-cropped to fit, which is what
                                         takes a model's head off.
                                         Nothing in the admin said so, so the rule was
                                         invisible until the crop appeared on the shop.
                                         2000px is the long edge the optimiser resizes to,
                                         so 1500x2000 is the largest size that survives
                                         untouched. */ ?>
                                <p class="image-upload-label">
                                    <strong>Click to upload photo</strong> or drag &amp; drop<br>
                                    <strong>1500 &times; 2000 px (3:4 portrait)</strong> — anything else is cropped to fit<br>
                                    JPG, PNG, WebP, or GIF — max 8MB
                                </p>
                            </div>

                            <img src="" alt="New image preview" id="newImgPreview" class="admin-new-img-preview">
                        </div>

                        <!-- ── Product 360° Video (Bottom of Product Image section) ── -->
                        <div class="form-group admin-video-box-mt">
                            <label class="form-label admin-label-bold"><i class="fa-solid fa-video admin-video-icon-primary"></i> 2. Product 360° Video (Use if no product photo or for 360° view)</label>
                            <?php if (!empty($product['video_url'])): ?>
                            <div class="admin-video-info-text">
                                📹 Current Video: <strong class="admin-video-url-bold"><?= htmlspecialchars($product['video_url']) ?></strong>
                            </div>
                            <?php endif; ?>
                            <div class="admin-video-flex-column">
                                <div>
                                    <label class="form-label admin-sublabel-muted">Upload Product Video File (MP4 / WebM)</label>
                                    <input type="file" name="product_video" class="form-control" accept="video/mp4,video/webm,video/quicktime">
                                </div>
                                <div>
                                    <label class="form-label admin-sublabel-muted">OR Enter Video URL (YouTube / External MP4 Link)</label>
                                    <input type="text" name="video_url" class="form-control" placeholder="e.g. https://www.youtube.com/watch?v=... or my_video.mp4" value="<?= htmlspecialchars($product['video_url'] ?? '') ?>">
                                </div>
                            </div>
                            <small class="form-text text-muted admin-help-text-muted">
                                💡 Note: If you have no main photo uploaded, this Product 360° Video will automatically take the place of <code>product-main-img</code> on the storefront product page!
                            </small>
                        </div>

                        <!-- Fallback emoji (shown if no image) -->
                        <div class="form-group admin-mt-16">
                            <label class="form-label admin-label-xs">Emoji (shown if no image or video)</label>
                            <input type="text" name="emoji" id="emojiInput" class="form-control admin-emoji-input-size"
                                value="<?= htmlspecialchars($product['emoji'] ?? '✨') ?>">
                        </div>
                    </div>

                    <!-- ── Additional Gallery Images ──────────── -->
                    <?php
                    // On a colour product these images are never displayed — the
                    // product page shows the selected colour's photographs. Saying
                    // so here stops the shopkeeper uploading a set that will not
                    // appear anywhere, which is exactly what happened on #13.
                    $hasColourways = productHasColourways($pdo, (int)($product['id'] ?? 0));
                    ?>
                    <div class="glass-panel form-section admin-section-mt<?= $hasColourways ? ' section-superseded' : '' ?>">
                        <h3><i class="fa-solid fa-images"></i> Additional Images Gallery</h3>
                        <?php if ($hasColourways): ?>
                        <?php /* dvNotice() — the shared notice box (config/config.php).
                                 Hand-written markup here is what let this box get its own
                                 broken flex layout, which sliced the sentence into columns
                                 at every bold word. One component, one place to be right. */ ?>
                        <?= dvNotice(
                            "This product is sold by colourway, so the product page shows the selected "
                            . "<strong>colour's</strong> photographs and not these. Upload the shots under "
                            . "<strong>Colour Variants</strong> further down instead. "
                            . "The main image now follows the first colour's first photo automatically, "
                            . "so the shop card matches what a shopper sees after the tap.",
                            'muted'
                        ) ?>
                        <?php else: ?>
                        <p class="admin-section-desc">Upload multiple photos to show every side of your product.</p>
                        <?php endif; ?>

                        <?php if (!empty($additionalImages)): ?>
                        <?php /* Order is editable here because it is the order the shop uses:
                                 pages/product.php reads these with ORDER BY sort_order, id, so
                                 whatever sits first in this row is the second photograph a
                                 shopper sees. Before this there was no way to change it — a
                                 photo uploaded last stayed last for ever. */ ?>
                        <div class="admin-gallery-wrap-flex" id="additionalImagesContainer">
                            <?php foreach ($additionalImages as $gi => $img): ?>
                            <div class="gallery-image-wrap admin-gallery-thumb-item"
                                 id="ai-<?= $img['id'] ?>" data-img-id="<?= $img['id'] ?>">
                                <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($img['image']) ?>" class="admin-gallery-thumb" alt="">
                                <?php /* admin-gallery-del-btn — the stylesheet's own name for this
                                         button, which draws the red circle at top right. Renaming it
                                         to something of my own once made it invisible. */ ?>
                                <button type="button" onclick="deleteAdditionalImage(<?= $img['id'] ?>)" title="Remove Image" class="admin-gallery-del-btn">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                                <span class="admin-gallery-saved-tag"><?= $gi === 0 ? 'First' : 'Saved' ?></span>
                                <?php /* Arrows AS WELL AS drag, not instead of it.
                                         Drag is quicker once you know it is there, but nothing on a
                                         tile announces that it can be dragged, and a drag that has to
                                         cross a wrapped row is fiddly. The buttons are the discoverable
                                         path and the keyboard one — they can be tabbed to, which drag
                                         cannot. ⇡ is the whole job in one press for the common case.
                                         Bottom-right, because the delete button owns the top-right
                                         corner and the First/Saved tag owns the bottom-left. */ ?>
                                <div class="gal-move">
                                    <button type="button" class="gal-move-btn" title="Move to first"
                                            onclick="galMove(this,'first')" <?= $gi === 0 ? 'disabled' : '' ?>>&#8673;</button>
                                    <button type="button" class="gal-move-btn" title="Move back"
                                            onclick="galMove(this,-1)" <?= $gi === 0 ? 'disabled' : '' ?>>&#8592;</button>
                                    <button type="button" class="gal-move-btn" title="Move forward"
                                            onclick="galMove(this,1)" <?= $gi === count($additionalImages) - 1 ? 'disabled' : '' ?>>&#8594;</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="form-hint" id="galOrderHint">Hover a photo and use the arrows to reorder — &#8673; sends it to the front. The first one here is shown straight after the main image.</p>
                        <?php endif; ?>

                        <div class="image-upload-box">
                            <input type="file" name="additional_images[]" id="additional_images_input" accept="image/jpeg,image/png,image/webp,image/gif" multiple onchange="previewSelectedGalleryImages(this)">
                            <div class="image-upload-icon">📸</div>
                            <p class="image-upload-label">
                                <strong>Select multiple files</strong><br>
                                <strong>1500 &times; 2000 px (3:4 portrait)</strong> — anything else is cropped to fit<br>
                                Shift-click or Cmd-click to select multiple
                            </p>
                        </div>
                        <div id="newSelectedGalleryPreview" class="admin-gallery-wrap-flex admin-section-mt"></div>

                        <?php // Images held over from a save that hit a warning. Shown so the
                              // gallery does not look lost while the warning is on screen —
                              // they attach as soon as the product actually saves. ?>
                        <?php $heldGallery = (array)($_SESSION['dv_pending_gallery'] ?? []); ?>
                        <?php if ($heldGallery): ?>
                        <div class="admin-section-mt" style="padding:12px 14px; background:var(--bg-surface-soft); border:1px solid var(--border-light); border-left:3px solid var(--color-secondary); border-radius:8px;">
                            <p style="margin:0 0 10px; font-size:12.5px; color:var(--text-secondary);">
                                <i class="fa-solid fa-clock-rotate-left" style="color:var(--color-secondary);"></i>
                                <strong><?= count($heldGallery) ?> image<?= count($heldGallery) === 1 ? '' : 's' ?> waiting</strong>
                                from your last attempt. You do not need to pick <?= count($heldGallery) === 1 ? 'it' : 'them' ?> again —
                                <?= count($heldGallery) === 1 ? 'it attaches' : 'they attach' ?> as soon as this product saves.
                            </p>
                            <div class="admin-gallery-wrap-flex">
                                <?php foreach ($heldGallery as $hg): ?>
                                <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars(rawurlencode(basename((string)$hg))) ?>"
                                     alt="" style="width:64px; height:64px; object-fit:cover; border-radius:8px; border:1px solid var(--border-light);">
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn-primary admin-btn-save-submit">
                        <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                        <?= $isEdit ? 'Save Changes' : 'Add Product' ?>
                    </button>

                    <?php /* The "Publish Anyway" button lived here as well and has been
                             removed: it now sits inside the red warning box at the top of
                             the page, beside the message that explains it. Two identical
                             buttons a full page apart is one too many, and the one down
                             here was the one nobody could see when the warning appeared. */ ?>
                </div>

                <!-- ── Live Preview ───────────────────────── -->
                <div class="admin-sticky-preview">
                    <div class="glass-panel preview-card">
                        <p class="admin-preview-eyebrow">Live Preview</p>
                        <div class="preview-img-wrap <?= !empty($product['image']) ? 'has-image' : '' ?>" id="previewWrap">
                            <?php if (!empty($product['image'])): ?>
                            <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>" alt="Preview" id="previewImg" class="admin-preview-img-fill">
                            <?php else: ?>
                            <span id="previewEmoji" class="admin-preview-emoji-size"><?= htmlspecialchars($product['emoji'] ?? '✨') ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 id="previewName" class="admin-preview-name-text"><?= htmlspecialchars($product['name'] ?? 'Product Name') ?></h3>
                        <div id="previewPrice" class="admin-preview-price-text">
                            ₹<?= number_format((float)($product['price'] ?? 0), 2) ?>
                        </div>

                        <?php /* Save, inside the sticky preview panel.
                                 The only save button was at the foot of the left column, and
                                 this form is long enough that changing one field near the top
                                 meant scrolling past everything to commit it. This panel is
                                 position:sticky, so this button stays on screen the whole way
                                 down.

                                 It is a plain submit inside the same <form>, not a duplicate
                                 of anything — pressing either one performs the identical save,
                                 including the required-field checks. */ ?>
                        <button type="submit" class="btn-primary preview-save-btn">
                            <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                            <?= $isEdit ? 'Save Changes' : 'Add Product' ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <?php /* Keep the concurrent-edit stamp in step with THIS page's own saves.
                 See admin/product_stamp.php for the whole story: sizes, colours,
                 specifications, components and photographs all save by themselves
                 while this form is open, several of them write to the products
                 row, and updated_at moves. The guard above then refused the first
                 Save and blamed "someone else" — which was the owner, on this
                 same screen. Ticking Track Stock off and watching it come back
                 was the clearest symptom.

                 One wrapper around fetch() rather than a line in each of the
                 thirty-odd call sites, so no existing handler had to be touched.

                 Honest limit: if a genuinely different admin saves while this
                 page is open AND this page then saves a size, the refresh adopts
                 their stamp too and the warning is lost. That is a far narrower
                 hole than the guard firing on ordinary single-admin editing,
                 which is what it did before. */ ?>
        <script>
        (function () {
            var form = document.getElementById('productForm');
            if (!form || !window.fetch) { return; }
            var stamp = form.querySelector('input[name="loaded_updated_at"]');
            if (!stamp) { return; }

            // The handlers on this page that can move products.updated_at.
            var OWN_SAVE = /(variant|color|image|specification|component)_handler\.php/;
            var native   = window.fetch.bind(window);
            var queued   = null;

            function productId() {
                var f = form.querySelector('input[name="product_id"]');
                return f && f.value ? f.value : '';
            }

            function refreshStamp() {
                // Several handlers often finish together; one lookup covers them.
                if (queued) { return; }
                queued = window.setTimeout(function () {
                    queued = null;
                    var id = productId();
                    if (!id) { return; }
                    native('product_stamp.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d && d.success && d.updated_at) { stamp.value = d.updated_at; }
                        })
                        .catch(function () {
                            /* Leave the old stamp. The worst case is the warning
                               the owner saw before, and a second Save still goes
                               through — nothing is lost either way. */
                        });
                }, 250);
            }

            window.fetch = function (input, init) {
                var url = (typeof input === 'string') ? input : (input && input.url) || '';
                return native(input, init).then(function (res) {
                    if (res && res.ok && OWN_SAVE.test(url)) { refreshStamp(); }
                    return res;
                });
            };
        })();
        </script>


        <?php // One datalist for every colour box on this page — the main Color
              // field, each existing colour row, and the add-a-colour input. A
              // datalist suggests without restricting, so an unlisted colour can
              // still be typed; adding it to Colours & Sizes afterwards keeps the
              // spelling consistent the next time. ?>
        <datalist id="dv-color-options">
            <?php foreach ($masterColors as $mc): ?>
            <option value="<?= htmlspecialchars($mc) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <?php // One datalist per fashion field, built from the values already in the
              // catalogue. Suggestions only — anything new can still be typed, and
              // becomes a suggestion for the next product by virtue of existing. ?>
        <?php foreach ([
            'fabric'      => 'dv-fabric-options',
            'sleeve'      => 'dv-sleeve-options',
            'neck'        => 'dv-neck-options',
            'pattern'     => 'dv-pattern-options',
            'occasion'    => 'dv-occasion-options',
            'composition' => 'dv-composition-options',
        ] as $specCol => $specListId): ?>
        <datalist id="<?= $specListId ?>">
            <?php foreach (($fashionSuggestions[$specCol] ?? []) as $sv): ?>
            <option value="<?= htmlspecialchars($sv) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <?php endforeach; ?>

        <datalist id="dv-brand-options">
            <?php foreach ($brandSuggestions as $bs): ?>
            <option value="<?= htmlspecialchars($bs) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <?php if ($isEdit && !empty($product['id'])): ?>
        <!-- ── Product Sizes Section ─────────────────── -->
        <?php
        // A product sells EITHER by plain size OR by colourway — never both. The
        // storefront picks the colour ladder whenever an active colour exists, so
        // once colours are added this whole panel stops being read. Saying so is
        // the difference between "these sizes are ignored" and a shopkeeper
        // wondering why their stock numbers do nothing.
        $sizesSuperseded = productHasColourways($pdo, (int)($product['id'] ?? 0));
        ?>
        <div class="glass-panel form-section<?= $sizesSuperseded ? ' section-superseded' : '' ?>" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-ruler-combined" style="color:var(--color-secondary);"></i>
                Product Sizes
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — e.g. Small, Medium, Large</span>
            </h3>
            <?php if ($sizesSuperseded): ?>
            <?php
                $sizeNote = "This product is sold by colourway, so the website uses each colour's own "
                          . "<strong>Sizes &amp; Stock</strong> below and ignores everything in this panel. "
                          . "Set sizes and stock per colour instead.";
                if (!empty($existingVariants)) {
                    $sizeNote .= "<br><strong>There are " . count($existingVariants) . " size(s) still saved here</strong> — "
                               . "they are not on sale and their stock is not counted. Delete them to avoid "
                               . "confusion, or remove the colours to go back to plain sizes.";
                }
                echo dvNotice($sizeNote, 'muted');
            ?>
            <?php endif; ?>
            <?php
            // The ladder is the template. Ticking a rung writes the size_code with it,
            // which is what makes XS sort before XL however you enter them, ties the
            // size to the size guide, and gives stock somewhere to live.
            //
            // Before this, every plain size was free text: all 42 in the database had
            // size_code NULL and stock_qty NULL, spelled five different ways, and the
            // storefront listed them in whatever order they happened to be typed.
            $ladderCodes = array_column($sizeLadder, 'code');
            $plainByCode = [];
            $plainCustom = [];
            foreach ($existingVariants as $v) {
                $vCode = trim((string)($v['size_code'] ?? ''));
                if ($vCode !== '' && in_array($vCode, $ladderCodes, true) && !isset($plainByCode[$vCode])) {
                    $plainByCode[$vCode] = $v;
                } else {
                    $plainCustom[] = $v;   // Free Size, 500ml — anything off-ladder
                }
            }
            $basePrice = (float)($product['price'] ?? 0);
            ?>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:14px;">
                Tick the sizes this product comes in. <strong>Shown as</strong> is exactly what a
                shopper sees on the size button — rename it if your label differs, and the size
                still sorts where the ladder puts it. <strong>Stock</strong> blank means this size
                is not counted.
            </p>
            <?php /* Said plainly, and separately, because it was one clause in the
                     middle of a paragraph about labels and stock. A number in the
                     price box does not adjust the product price, it REPLACES it for
                     that one size — which is how a size can quietly end up selling
                     at a completely different figure from the rest of the product. */ ?>
            <p class="size-price-warning">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <strong>Price</strong> is per size, and it <strong>replaces</strong> the product's
                selling price for that size alone — it is not a discount or an adjustment. Leave it
                blank and the size simply sells at the product price. Any size that has its own
                price is outlined below.
            </p>

            <div class="size-stock-grid size-stock-grid--spaced">
                <?php foreach ($sizeLadder as $sz):
                    $existing = $plainByCode[$sz['code']] ?? null;
                    $ePrice   = $existing ? (float)$existing['price'] : 0.0;
                    $eHasOver = $existing && $ePrice > 0 && abs($ePrice - $basePrice) > 0.005;
                ?>
                <div class="size-stock-row">
                    <label>
                        <input type="checkbox" id="psize-<?= $sz['code'] ?>" <?= $existing ? 'checked' : '' ?>
                            data-variant-id="<?= $existing['id'] ?? '' ?>"
                            onchange="togglePlainSize('<?= $sz['code'] ?>', '<?= htmlspecialchars($sz['label'], ENT_QUOTES) ?>', this)">
                        <?= htmlspecialchars($sz['label']) ?>
                    </label>
                    <input type="text" id="plabel-<?= $sz['code'] ?>" class="form-control"
                        placeholder="Shown as <?= htmlspecialchars($sz['label'], ENT_QUOTES) ?>"
                        value="<?= $existing ? htmlspecialchars($existing['name']) : '' ?>"
                        <?= $existing ? '' : 'disabled' ?>
                        onchange="savePlainSize('<?= $sz['code'] ?>')">
                    <input type="number" id="pstock-<?= $sz['code'] ?>" min="0" class="form-control" placeholder="Stock"
                        value="<?= ($existing && $existing['stock_qty'] !== null) ? (int)$existing['stock_qty'] : '' ?>"
                        <?= $existing ? '' : 'disabled' ?>
                        onchange="savePlainSize('<?= $sz['code'] ?>')">
                    <?php /* Marked when this size carries its own price.
                             ────────────────────────────────────────────────
                             $eHasOver was already worked out above and used
                             only to decide whether to print a value — so a
                             size selling at 2000 looked exactly like one
                             following the product's price, a lone number in
                             one box among twenty. A shopper choosing that
                             size is charged it, and nothing on this screen
                             said so. The class draws the box differently and
                             the title says what the number does. */ ?>
                    <input type="number" id="pprice-<?= $sz['code'] ?>" min="0" step="0.01"
                        class="form-control<?= $eHasOver ? ' size-price-overridden' : '' ?>"
                        <?php /* The figure this size falls back to, as a number the script can
                                 compare against. Without it markPriceOverride() can only ask
                                 "is the box empty?", and typing the product's own price into it
                                 would light the outline until the next reload disagreed — the
                                 same screen-says-one-thing fault in miniature. */ ?>
                        data-base-price="<?= number_format($basePrice, 2, '.', '') ?>"
                        placeholder="<?= htmlspecialchars(formatPrice($basePrice), ENT_QUOTES) ?>"
                        value="<?= $eHasOver ? number_format($ePrice, 2, '.', '') : '' ?>"
                        title="Price for this size only. A number here REPLACES the product's selling price of <?= htmlspecialchars(formatPrice($basePrice), ENT_QUOTES) ?> whenever a shopper picks <?= htmlspecialchars($sz['label'], ENT_QUOTES) ?>. Leave blank to follow the product price."
                        aria-label="Price for size <?= htmlspecialchars($sz['label'], ENT_QUOTES) ?> — replaces the product price when set"
                        <?= $existing ? '' : 'disabled' ?>
                        onchange="savePlainSize('<?= $sz['code'] ?>')">
                </div>
                <?php endforeach; ?>
            </div>

            <?php // Anything that is not a garment size — Free Size, 500ml, a legacy row
                  // still waiting on a size_code. Kept as free text because a ladder
                  // cannot describe it. ?>
            <h4 style="font-size:13px; font-weight:600; color:var(--text-secondary); margin:0 0 10px;">
                Other sizes &amp; options
                <span style="font-weight:400; color:var(--text-muted);">— for anything the ladder above cannot describe</span>
            </h4>

            <div id="variantsList">
                <?php foreach ($plainCustom as $v): ?>
                <?php
                // The price box shows a figure only when the size REALLY costs something
                // different. Every row used to be pre-filled with number_format($v['price'])
                // — including the sizes that simply inherited the product price — so the
                // panel looked like it demanded a price per size, and editing the product
                // price left those stale copies behind at the old figure.
                $vPrice       = (float)$v['price'];
                $vBase        = (float)($product['price'] ?? 0);
                $vHasOverride = $vPrice > 0 && abs($vPrice - $vBase) > 0.005;
                ?>
                <div class="variant-row" id="vrow-<?= $v['id'] ?>" style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--bg-main); border-radius:var(--radius-sm); margin-bottom:10px; border:1px solid var(--border-light); flex-wrap:wrap;">
                    <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:14px;"></i>
                    <input type="text" id="vn-<?= $v['id'] ?>" value="<?= htmlspecialchars($v['name']) ?>" placeholder="Size name (e.g. M, 34/M, Free Size)"
                        class="form-control" style="flex:1; min-width:120px;"
                        onchange="saveVariantRow(<?= $v['id'] ?>)">
                    <div style="display:flex; align-items:center; gap:4px;">
                        <span style="font-size:15px;"><?= currencySymbol() ?></span>
                        <input type="number" id="vp-<?= $v['id'] ?>" value="<?= $vHasOverride ? number_format($vPrice, 2, '.', '') : '' ?>"
                            step="0.01" min="0" placeholder="Same as <?= htmlspecialchars(formatPrice($vBase)) ?>"
                            class="form-control" style="width:150px;"
                            <?php // Lets restorePriceBox()/markPriceOverride() judge this box by the
                                  // same rule as the other two panels. Without it a refused save
                                  // left the rejected figure sitting here. ?>
                            data-base-price="<?= number_format((float)$vBase, 2, '.', '') ?>"
                            onchange="saveVariantRow(<?= $v['id'] ?>)">
                    </div>
                    <input type="number" id="vs-<?= $v['id'] ?>" min="0" value="<?= $v['stock_qty'] === null ? '' : (int)$v['stock_qty'] ?>"
                        placeholder="Stock" class="form-control" style="width:90px;"
                        onchange="saveVariantRow(<?= $v['id'] ?>)">
                    <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-secondary); white-space:nowrap; cursor:pointer;">
                        <input type="checkbox" id="va-<?= $v['id'] ?>" <?= $v['available'] ? 'checked' : '' ?>
                            onchange="saveVariantRow(<?= $v['id'] ?>)">
                        Available
                    </label>
                    <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteVariant(<?= $v['id'] ?>, <?= (int)$product['id'] ?>)">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:12px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface);">
                <i class="fa-solid fa-plus-circle" style="color:var(--color-secondary); font-size:18px; flex-shrink:0;"></i>
                <input type="text" id="newVariantName" placeholder="Free Size, One Size, 500ml…" class="form-control" style="flex:1;">
                <div style="display:flex; align-items:center; gap:4px;">
                    <span style="font-size:15px;"><?= currencySymbol() ?></span>
                    <input type="number" id="newVariantPrice" placeholder="Same as <?= htmlspecialchars(formatPrice((float)($product['price'] ?? 0))) ?>" step="0.01" min="0" class="form-control" style="width:150px;">
                </div>
                <input type="number" id="newVariantStock" min="0" placeholder="Stock" class="form-control" style="width:90px;">
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addVariant(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Size
                </button>
            </div>
            <p style="font-size:12px; color:var(--text-muted); margin-top:10px;">
                <i class="fa-solid fa-circle-info"></i>
                Use the ladder above for garment sizes — a size added here has no place in the
                ladder, so it sorts after them and is not matched by the size guide.
            </p>
        </div>

        <!-- ── Colour Variants Section (Biba-style image colour selector) ── -->
        <div class="glass-panel form-section" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-palette" style="color:var(--color-primary);"></i>
                Colour Variants
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — each colour gets its own gallery, price and sizes/stock</span>
            </h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">
                Add one colour per look. Upload a gallery for each colour, pick a circular thumbnail, and set which sizes are in stock for that colour.
                Products with no colour added here keep using the plain "Product Sizes" list above.
            </p>

            <div id="colorsList">
                <?php foreach ($existingColors as $c):
                    $sizesByCode = [];
                    foreach ($c['sizes'] as $s) { $sizesByCode[$s['size_code']] = $s; }
                ?>
                <?php
                // Two things a shopper will notice, so say them here.
                //
                // The first message used to read "This colour will not appear on the
                // website", which was true while pages/product.php filtered out any
                // colour with no sizes. It no longer does — a colour the shopkeeper
                // created is always shown — so the warning is now about the colour
                // being unbuyable rather than invisible. Leaving the old wording in
                // place would have made the panel state the opposite of the truth.
                $cAvailableSizes = count(array_filter($c['sizes'] ?? [], fn($s) => (int)($s['available'] ?? 0) === 1));
                $cImageCount     = count($c['images'] ?? []);
                ?>
                <div class="color-card" id="ccard-<?= $c['id'] ?>" style="border:1px solid var(--border-light); border-radius:var(--radius-md); padding:20px; margin-bottom:18px; background:var(--bg-main);">
                    <?php if ($cAvailableSizes === 0): ?>
                    <div class="color-warn color-warn--blocking" id="cwarn-size-<?= $c['id'] ?>">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div><strong>Nobody can buy this colour yet.</strong>
                        It is shown on the website with its own swatch, but no size can be added to the bag —
                        the shopper sees &ldquo;no sizes configured&rdquo;. Tick a size below and set its stock.</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($cImageCount === 0): ?>
                    <div class="color-warn color-warn--soft" id="cwarn-img-<?= $c['id'] ?>">
                        <i class="fa-solid fa-image"></i>
                        <div><strong>No images for this colour.</strong>
                        The swatch will show, but clicking it keeps the product's main photos — so to a shopper it looks like nothing happened. Upload a gallery below.</div>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap;">
                        <div style="text-align:center; flex-shrink:0;">
                            <img id="cthumb-<?= $c['id'] ?>" src="<?= !empty($c['thumbnail']) ? SITE_URL . '/uploads/products/' . htmlspecialchars($c['thumbnail']) : '' ?>"
                                style="width:72px; height:72px; border-radius:50%; object-fit:cover; border:3px solid <?= !empty($c['thumbnail']) ? 'var(--color-primary)' : 'var(--border-light)' ?>; background:var(--bg-surface); <?= empty($c['thumbnail']) ? 'display:none;' : '' ?>">
                            <div id="cthumb-placeholder-<?= $c['id'] ?>" style="width:72px; height:72px; border-radius:50%; border:2px dashed var(--border-strong); display:<?= empty($c['thumbnail']) ? 'flex' : 'none' ?>; align-items:center; justify-content:center; color:var(--text-muted); font-size:11px; text-align:center;">No<br>thumb</div>
                            <label style="display:block; margin-top:6px; font-size:11px; color:var(--color-secondary); cursor:pointer;">
                                Upload
                                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="uploadColorThumbnail(<?= $c['id'] ?>, this)">
                            </label>
                        </div>

                        <div style="flex:1; min-width:220px; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label class="form-label" style="font-size:11px;">Colour Name *</label>
                                <input type="text" id="cname-<?= $c['id'] ?>" list="dv-color-options" class="form-control" value="<?= htmlspecialchars($c['color_name']) ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:11px;">Variant SKU</label>
                                <input type="text" id="csku-<?= $c['id'] ?>" class="form-control" value="<?= htmlspecialchars($c['sku'] ?? '') ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:11px;">Price Override <span style="color:var(--text-muted);">(blank = main price)</span></label>
                                <?php /* number_format WITHOUT a thousands separator, or the box
                                         silently empties and the override is destroyed.
                                         ────────────────────────────────────────────────────
                                         This printed value="1,650.00". An <input type="number">
                                         refuses a value it cannot parse, so .value came back as
                                         "" — the box rendered blank even though the override was
                                         set, and the panel beside it correctly said "Price
                                         Override of ₹1,650.00", which is how the two disagreed in
                                         plain sight. updateColor() then reads .value and posts it,
                                         so editing ANY field in this block — the colour's name,
                                         its SKU, the Active tick — wrote price_override = NULL.
                                         An override deleted by renaming a colour, silently, and
                                         with it every per-size price it was suppressing.
                                         Reproduced on a colour holding 1650: typing a SKU cleared
                                         it. The per-size boxes below already pass '.' and '' here;
                                         this is the same fix, and cmrp- below needs it too. */ ?>
                                <input type="number" step="0.01" min="0" id="cprice-<?= $c['id'] ?>" class="form-control" value="<?= $c['price_override'] !== null ? number_format((float)$c['price_override'], 2, '.', '') : '' ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:11px;">MRP Override <span style="color:var(--text-muted);">(blank = main MRP)</span></label>
                                <?php // Same separator bug as the Price Override above, same fix. ?>
                                <input type="number" step="0.01" min="0" id="cmrp-<?= $c['id'] ?>" class="form-control" value="<?= $c['mrp_price_override'] !== null ? number_format((float)$c['mrp_price_override'], 2, '.', '') : '' ?>" onchange="updateColor(<?= $c['id'] ?>)">
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-secondary); cursor:pointer; white-space:nowrap;">
                                <input type="checkbox" id="cactive-<?= $c['id'] ?>" <?= $c['is_active'] ? 'checked' : '' ?> onchange="updateColor(<?= $c['id'] ?>)">
                                Active
                            </label>
                            <button type="button" class="btn-danger" style="padding:6px 12px; font-size:12px;" onclick="deleteColor(<?= $c['id'] ?>, '<?= htmlspecialchars($c['color_name'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-trash"></i> Delete Colour
                            </button>
                        </div>
                    </div>

                    <!-- Gallery -->
                    <div style="margin-top:16px;">
                        <label class="form-label" style="font-size:12px;">Gallery Images for this Colour</label>
                        <div id="cgallery-<?= $c['id'] ?>" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
                            <?php foreach ($c['images'] as $img): ?>
                            <div class="color-gallery-item" id="cimg-<?= $img['id'] ?>" style="position:relative; width:64px; height:64px;">
                                <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($img['image']) ?>" style="width:100%; height:100%; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid <?= ($c['thumbnail'] === $img['image']) ? 'var(--color-primary)' : 'transparent' ?>;" title="Click to set as thumbnail" onclick="setColorThumbnail(<?= $c['id'] ?>, '<?= htmlspecialchars($img['image'], ENT_QUOTES) ?>')">
                                <button type="button" onclick="deleteColorImage(<?= $img['id'] ?>, <?= $c['id'] ?>)" style="position:absolute; top:-6px; right:-6px; width:18px; height:18px; border-radius:50%; background:#c0392b; color:#fff; border:none; font-size:10px; cursor:pointer; line-height:1;">×</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--color-secondary); cursor:pointer; padding:6px 12px; border:1px dashed var(--border-strong); border-radius:6px;">
                            <i class="fa-solid fa-upload"></i> Add gallery images
                            <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none;" onchange="uploadColorGallery(<?= $c['id'] ?>, this)">
                        </label>
                        <?php /* Colour photographs land in the same 3:4 frames as the
                                 product's own — the shop card and the gallery do not care
                                 which of the two a picture came from. */ ?>
                        <p style="font-size:11px; color:var(--text-muted); margin:8px 0 0;">
                            <strong>1500 &times; 2000 px (3:4 portrait)</strong> — anything else is cropped to fit.
                        </p>

                        <?php // Offered only while the product still has photos of its own.
                              //
                              // Without this, adding a first colourway made the product's own
                              // photographs disappear from the website, and the only way to
                              // show them again was to create a second colour and upload the
                              // very same files a second time. This attaches the files that
                              // are already on the server — nothing is uploaded, copied on
                              // disk, or deleted. ?>
                        <?php if (!empty($additionalImages) || !empty($product['image'])): ?>
                        <button type="button" id="cadopt-<?= $c['id'] ?>"
                                onclick="adoptProductImages(<?= $c['id'] ?>, this)"
                                style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--color-primary); cursor:pointer; padding:6px 12px; margin-left:8px; border:1px dashed var(--color-primary); border-radius:6px; background:var(--bg-surface);">
                            <?php /* fa-photo-film, matching this section's own heading. It was
                                     fa-arrow-down-long — the same arrow the "use the product's
                                     sizes" button below uses — so two buttons a few lines apart
                                     had the same icon, the same dashed outline and nearly the
                                     same sentence. The icon now says WHICH thing is being
                                     taken from the product. */ ?>
                            <i class="fa-solid fa-photo-film"></i> Use the product's existing photos
                        </button>
                        <p style="font-size:11.5px; color:var(--text-muted); margin:8px 0 0; line-height:1.55;">
                            Photographed this product in <strong>this</strong> colour? Attach the
                            <?= (int)(count($additionalImages) + (empty($product['image']) ? 0 : 1)) ?>
                            photo(s) already on the product instead of uploading them again.
                            The product keeps its own copies — nothing is moved or deleted.
                        </p>
                        <?php endif; ?>
                    </div>

                    <!-- Sizes & Stock -->
                    <div style="margin-top:18px;">
                        <label class="form-label" style="font-size:12px;">Sizes &amp; Stock for this Colour</label>
                        <?php
                        /* Copy from another colour.
                           ────────────────────────────────────────────────────
                           Six colours in four sizes is 24 ticks and 24 stock
                           boxes typed by hand, and nothing warns you if one
                           colour ends up with three sizes while the rest have
                           four — that colour just quietly sells one size fewer.

                           This only ADDS sizes the source has and this colour
                           lacks. A size already ticked here keeps its own stock
                           untouched, so copying can never overwrite a number
                           already counted. A colour that genuinely lacks a size
                           is handled by copying, then unticking that one. */
                        $otherColors = array_values(array_filter(
                            $existingColors,
                            fn($oc) => (int)$oc['id'] !== (int)$c['id']
                        ));
                        ?>
                        <?php
                        /* Shaped like "Use the product's existing photos" directly above.
                           ────────────────────────────────────────────────────────────
                           That button already solves the same problem for images — the
                           product has them, this colour needs them, one click. Sizes had
                           no equivalent, so the sizes entered on the product were retyped
                           under every colour. Same idea, same place, same one click; the
                           picker beside it is only for the second colour onwards, once
                           there is another colour worth copying from. */
                        $plainTicked = count($plainByCode);
                        ?>
                        <?php if ($plainTicked > 0 || $otherColors): ?>
                        <div class="size-copy-row">
                            <?php if ($plainTicked > 0): ?>
                            <button type="button" class="size-copy-adopt"
                                    onclick="copyColorSizes(<?= (int)$c['id'] ?>, this, 'plain')">
                                <?php /* fa-ruler-combined — the same icon the Product Sizes
                                         section heading uses, so the button points visibly at
                                         where the sizes are coming from. */ ?>
                                <i class="fa-solid fa-ruler-combined"></i>
                                Copy the product's <?= (int)$plainTicked ?> size<?= $plainTicked === 1 ? '' : 's' ?> here
                            </button>
                            <?php endif; ?>

                            <?php if ($otherColors): ?>
                            <label for="ccopy-<?= (int)$c['id'] ?>">or copy from</label>
                            <select id="ccopy-<?= (int)$c['id'] ?>" class="form-control">
                                <option value="">another colour…</option>
                                <?php foreach ($otherColors as $oc): ?>
                                <option value="<?= (int)$oc['id'] ?>"><?= htmlspecialchars($oc['color_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-sm btn-sm-outline"
                                    onclick="copyColorSizes(<?= (int)$c['id'] ?>, this)">
                                <i class="fa-solid fa-copy"></i> Copy
                            </button>
                            <?php endif; ?>
                            <span class="size-copy-status" id="ccopystat-<?= (int)$c['id'] ?>"></span>
                        </div>
                        <?php endif; ?>
                        <?php
                        /* What a size under THIS colour follows when it has no price
                           of its own — the colour's override where one is set, the
                           product price otherwise. Same ladder effectiveVariantPrice()
                           walks, so the placeholder in each box below is the figure
                           that will genuinely be charged if the box is left empty. */
                        $cHasOverride = $c['price_override'] !== null;
                        $cBasePrice   = $cHasOverride ? (float)$c['price_override'] : $basePrice;
                        ?>
                        <p style="font-size:12px; color:var(--text-muted); margin:0 0 10px;">
                            <i class="fa-solid fa-circle-info"></i>
                            Tick a size, set its stock, and rename it if your label differs — the
                            <strong>Shown as</strong> box is exactly what a shopper sees on the size button.
                            The tick keeps its place in the ladder either way, so a size renamed
                            &ldquo;M&rdquo; still sorts where 34/M belongs.
                            <strong>Price</strong> is per size and <strong>replaces</strong> what that one
                            size sells at; blank means it follows
                            <?= $cHasOverride ? "this colour's override" : "the product price" ?>
                            (<?= htmlspecialchars(formatPrice($cBasePrice), ENT_QUOTES) ?>).
                        </p>
                        <?php if ($cHasOverride): ?>
                        <?php /* Said once, here, rather than left for the shopper to find.
                                 effectiveVariantPrice() returns a colour's price_override
                                 BEFORE it ever looks at a size's own price, so while this
                                 override is set every price box below is inert. The boxes
                                 stay editable — clearing the override later brings them
                                 into effect, and being able to enter them in advance is
                                 worth more than disabling them — but nothing about a
                                 typed-in number would otherwise say it was being ignored,
                                 which is the exact complaint this whole screen exists to
                                 answer. Saving one repeats this on the save itself. */ ?>
                        <p class="size-price-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            This colour has a <strong>Price Override</strong> of
                            <?= htmlspecialchars(formatPrice((float)$c['price_override']), ENT_QUOTES) ?>,
                            and a colour override beats a per-size price — so every size here sells at that
                            figure and the price boxes below have no effect until the override is cleared.
                        </p>
                        <?php endif; ?>
                        <div class="size-stock-grid">
                            <?php foreach ($sizeLadder as $sz):
                                $existing = $sizesByCode[$sz['code']] ?? null;
                                // Mirrors the plain-size card: a stored price that differs
                                // from what the size would otherwise follow is an override,
                                // and is drawn as one.
                                $cePrice   = $existing ? (float)$existing['price'] : 0.0;
                                $ceHasOver = $existing && $cePrice > 0 && abs($cePrice - $cBasePrice) > 0.005;
                            ?>
                            <div class="size-stock-row">
                                <label>
                                    <input type="checkbox" id="csize-<?= $c['id'] ?>-<?= $sz['code'] ?>" <?= $existing ? 'checked' : '' ?>
                                        data-variant-id="<?= $existing['id'] ?? '' ?>"
                                        onchange="toggleColorSize(<?= $c['id'] ?>, '<?= $sz['code'] ?>', '<?= $sz['label'] ?>', this)">
                                    <?= $sz['label'] ?>
                                </label>
                                <?php // The storefront pill prints the variant's stored NAME, not the
                                      // ladder label, so this box is the whole of "how do I rename a
                                      // size". size_code stays the canonical key underneath, which is
                                      // what keeps ordering and cart matching intact. ?>
                                <input type="text" id="clabel-<?= $c['id'] ?>-<?= $sz['code'] ?>" class="form-control"
                                    placeholder="Shown as <?= htmlspecialchars($sz['label'], ENT_QUOTES) ?>"
                                    value="<?= $existing ? htmlspecialchars($existing['name']) : '' ?>"
                                    <?= $existing ? '' : 'disabled' ?>
                                    onchange="updateColorSizeLabel(<?= $c['id'] ?>, '<?= $sz['code'] ?>', this)">
                                <input type="number" min="0" id="cstock-<?= $c['id'] ?>-<?= $sz['code'] ?>" class="form-control" placeholder="Stock"
<?php /* A NULL stock means the size is not counted, and the box must be BLANK to
         say so. Casting straight to int drew it as "0", which reads as "none
         left" — and that 0 is what the owner saves back on the next edit, turning
         an uncounted size into a sold-out one without anyone deciding to. The
         identical field for plain sizes already guards this; the colour-scoped
         copy did not. */ ?>
                                    value="<?= ($existing && $existing['stock_qty'] !== null) ? (int)$existing['stock_qty'] : '' ?>"
                                    <?= $existing ? '' : 'disabled' ?>
                                    onchange="updateColorSizeStock(<?= $c['id'] ?>, '<?= $sz['code'] ?>', this)">
                                <?php /* The per-size price, which this panel never had.
                                         ────────────────────────────────────────────────
                                         Sizes under a colour ARE priced individually by
                                         the shop — actions/cart_action.php resolves them
                                         through effectiveVariantPrice() like every other
                                         surface — but there was no box here to set one,
                                         so the only way a colour size ever acquired a
                                         price was by duplicating a product that already
                                         had one. The figure was chargeable and invisible
                                         at both ends: nothing here to type it into, and
                                         nothing on the product page to display it.

                                         Same markup as the plain-size card so the two
                                         panels stay one control with two placements, and
                                         so .size-stock-row's second track has an occupant
                                         on this card as it does on that one. */ ?>
                                <input type="number" min="0" step="0.01" id="cprice-<?= $c['id'] ?>-<?= $sz['code'] ?>"
                                    class="form-control<?= $ceHasOver ? ' size-price-overridden' : '' ?><?= $cHasOverride ? ' size-price-inert' : '' ?>"
                                    data-base-price="<?= number_format($cBasePrice, 2, '.', '') ?>"
                                    placeholder="<?= htmlspecialchars(formatPrice($cBasePrice), ENT_QUOTES) ?>"
                                    value="<?= $ceHasOver ? number_format($cePrice, 2, '.', '') : '' ?>"
                                    title="<?= $cHasOverride
                                        ? 'This colour has a Price Override of ' . htmlspecialchars(formatPrice($cBasePrice), ENT_QUOTES) . ', which takes precedence — a price typed here has no effect until that override is cleared.'
                                        : 'Price for this size in this colour only. A number here REPLACES the product\'s selling price of ' . htmlspecialchars(formatPrice($cBasePrice), ENT_QUOTES) . ' whenever a shopper picks ' . htmlspecialchars($sz['label'], ENT_QUOTES) . ' in ' . htmlspecialchars($c['color_name'], ENT_QUOTES) . '. Leave blank to follow it.' ?>"
                                    <?php /* The label has to carry the same caveat the title does.
                                             Announcing "replaces the product price when set" on a
                                             box that a colour override has made inert tells a
                                             screen-reader user the opposite of what will happen —
                                             and they have no tinting or dashed border to read it
                                             back from. */ ?>
                                    aria-label="Price for size <?= htmlspecialchars($sz['label'], ENT_QUOTES) ?> in <?= htmlspecialchars($c['color_name'], ENT_QUOTES) ?><?= $cHasOverride
                                        ? ' — inactive: this colour&rsquo;s Price Override takes precedence'
                                        : ' — replaces the product price when set' ?>"
                                    <?= $existing ? '' : 'disabled' ?>
                                    onchange="updateColorSizePrice(<?= $c['id'] ?>, '<?= $sz['code'] ?>', this)">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($existingColors)): ?>
                <!-- Empty state. The thumbnail/gallery upload controls live INSIDE each
                     colour card, so with no colours added there is no upload button
                     anywhere — which reads as "the upload option is missing on this
                     product" unless we say why. -->
                <div class="colors-empty-state">
                    <i class="fa-solid fa-palette colors-empty-icon"></i>
                    <strong class="colors-empty-title">No colours added yet</strong>
                    <span class="colors-empty-text">
                        Add a colour below first — each colour then gets its own
                        <strong>thumbnail upload</strong> and <strong>image gallery</strong>.
                        That is why no image upload appears on this product yet.
                    </span>
                </div>
            <?php endif; ?>

            <div style="display:flex; align-items:center; gap:12px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface); flex-wrap:wrap;">
                <input type="text" id="newColorName" list="dv-color-options" placeholder="Colour name (e.g. Green)" class="form-control" style="flex:1; min-width:140px;">
                <input type="text" id="newColorSku" placeholder="Variant SKU (optional)" class="form-control" style="width:160px;">
                <input type="number" step="0.01" min="0" id="newColorPrice" placeholder="Price override (optional)" class="form-control" style="width:170px;">
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addColor(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Colour
                </button>
            </div>
        </div>

        <!-- ── Product Specifications Section (general Label/Value/Unit rows) ── -->
        <div class="glass-panel form-section" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-list-check" style="color:var(--color-secondary);"></i>
                Product Specifications
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — general details shown on the product page (e.g. Fabric: Cotton)</span>
            </h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">
                Add any label/value details relevant to this product. Suggestions are just hints — you can always type a custom label.
                Empty sections are hidden automatically on the product page.
            </p>

            <div id="generalSpecsList">
                <?php foreach ($existingGeneralSpecs as $spec): ?>
                <div class="spec-row" id="gspec-<?= $spec['id'] ?>" style="display:flex; align-items:center; gap:10px; padding:12px 16px; background:var(--bg-main); border-radius:var(--radius-sm); margin-bottom:10px; border:1px solid var(--border-light); flex-wrap:wrap;">
                    <div style="display:flex; flex-direction:column; gap:2px;">
                        <button type="button" title="Move up" onclick="moveGeneralSpec(<?= $spec['id'] ?>, 'up')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-up"></i></button>
                        <button type="button" title="Move down" onclick="moveGeneralSpec(<?= $spec['id'] ?>, 'down')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-down"></i></button>
                    </div>
                    <input type="text" class="form-control gspec-label" list="specLabelSuggestions" value="<?= htmlspecialchars($spec['label']) ?>" placeholder="Label (e.g. Fabric)" style="flex:1; min-width:120px;" onchange="updateGeneralSpec(<?= $spec['id'] ?>)">
                    <input type="text" class="form-control gspec-value" value="<?= htmlspecialchars($spec['value']) ?>" placeholder="Value (e.g. Pure Cotton)" style="flex:1; min-width:140px;" onchange="updateGeneralSpec(<?= $spec['id'] ?>)">
                    <input type="text" class="form-control gspec-unit" list="specUnitSuggestions" value="<?= htmlspecialchars($spec['unit'] ?? '') ?>" placeholder="Unit (optional)" style="width:110px;" onchange="updateGeneralSpec(<?= $spec['id'] ?>)">
                    <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteGeneralSpec(<?= $spec['id'] ?>)">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:10px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface); flex-wrap:wrap;">
                <input type="text" id="newGeneralSpecLabel" list="specLabelSuggestions" placeholder="Label (e.g. Fabric)" class="form-control" style="flex:1; min-width:120px;">
                <input type="text" id="newGeneralSpecValue" placeholder="Value (e.g. Pure Cotton)" class="form-control" style="flex:1; min-width:140px;">
                <input type="text" id="newGeneralSpecUnit" list="specUnitSuggestions" placeholder="Unit (optional)" class="form-control" style="width:110px;">
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addGeneralSpec(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Specification
                </button>
            </div>
        </div>

        <!-- ── Product Components Section (Kurti / Palazzo / Dupatta ..., each with its own specs) ── -->
        <div class="glass-panel form-section" style="margin-top:28px;">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-shirt" style="color:var(--color-primary);"></i>
                Product Components
                <span style="font-size:12px; font-weight:500; color:var(--text-muted); margin-left:4px;">Optional — for sets with multiple pieces (e.g. Kurti + Palazzo + Dupatta)</span>
            </h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">
                Add a component for each piece in this product, then add its own specifications underneath it.
                Each component with at least one specification appears as its own section on the product page.
            </p>

            <div id="componentsList">
                <?php foreach ($existingComponents as $comp): ?>
                <div class="component-card" id="comp-<?= $comp['id'] ?>" style="border:1px solid var(--border-light); border-radius:var(--radius-md); padding:18px; margin-bottom:16px; background:var(--bg-main);">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                        <div style="display:flex; flex-direction:column; gap:2px;">
                            <button type="button" title="Move up" onclick="moveComponent(<?= $comp['id'] ?>, 'up')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-up"></i></button>
                            <button type="button" title="Move down" onclick="moveComponent(<?= $comp['id'] ?>, 'down')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;"><i class="fa-solid fa-caret-down"></i></button>
                        </div>
                        <input type="text" class="form-control comp-name" list="componentNameSuggestions" value="<?= htmlspecialchars($comp['name']) ?>" placeholder="Component name (e.g. Kurti)" style="flex:1; min-width:160px; font-weight:600;" onchange="updateComponent(<?= $comp['id'] ?>)">
                        <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteComponent(<?= $comp['id'] ?>, '<?= htmlspecialchars($comp['name'], ENT_QUOTES) ?>')">
                            <i class="fa-solid fa-trash"></i> Delete Component
                        </button>
                    </div>

                    <div id="compSpecsList-<?= $comp['id'] ?>">
                        <?php foreach ($comp['specs'] as $spec): ?>
                        <div class="spec-row" id="cspec-<?= $spec['id'] ?>" style="display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--bg-surface); border-radius:var(--radius-sm); margin-bottom:8px; border:1px solid var(--border-light); flex-wrap:wrap;">
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <button type="button" title="Move up" onclick="moveComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>, 'up')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;font-size:11px;"><i class="fa-solid fa-caret-up"></i></button>
                                <button type="button" title="Move down" onclick="moveComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>, 'down')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;line-height:1;font-size:11px;"><i class="fa-solid fa-caret-down"></i></button>
                            </div>
                            <input type="text" class="form-control cspec-label" list="componentLabelSuggestions" value="<?= htmlspecialchars($spec['label']) ?>" placeholder="Label (e.g. Fabric)" style="flex:1; min-width:110px; font-size:13px;" onchange="updateComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                            <input type="text" class="form-control cspec-value" value="<?= htmlspecialchars($spec['value']) ?>" placeholder="Value" style="flex:1; min-width:120px; font-size:13px;" onchange="updateComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                            <input type="text" class="form-control cspec-unit" list="specUnitSuggestions" value="<?= htmlspecialchars($spec['unit'] ?? '') ?>" placeholder="Unit" style="width:90px; font-size:13px;" onchange="updateComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                            <button type="button" class="btn-danger" style="padding:5px 10px; flex-shrink:0; font-size:12px;" onclick="deleteComponentSpec(<?= $spec['id'] ?>, <?= $comp['id'] ?>)">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; margin-top:10px; padding:10px 14px; border:1.5px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface); flex-wrap:wrap;">
                        <input type="text" id="newCompSpecLabel-<?= $comp['id'] ?>" list="componentLabelSuggestions" placeholder="Label" class="form-control" style="flex:1; min-width:110px; font-size:13px;">
                        <input type="text" id="newCompSpecValue-<?= $comp['id'] ?>" placeholder="Value" class="form-control" style="flex:1; min-width:120px; font-size:13px;">
                        <input type="text" id="newCompSpecUnit-<?= $comp['id'] ?>" list="specUnitSuggestions" placeholder="Unit" class="form-control" style="width:90px; font-size:13px;">
                        <button type="button" class="btn-primary" style="padding:8px 14px; flex-shrink:0; font-size:12px;" onclick="addComponentSpec(<?= $comp['id'] ?>, <?= (int)$product['id'] ?>)">
                            <i class="fa-solid fa-plus"></i> Add Spec
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:12px; margin-top:14px; padding:14px 16px; border:2px dashed var(--border-strong); border-radius:var(--radius-sm); background:var(--bg-surface);">
                <input type="text" id="newComponentName" list="componentNameSuggestions" placeholder="Component name (e.g. Kurti, Palazzo, Dupatta)" class="form-control" style="flex:1;">
                <button type="button" class="btn-primary" style="padding:10px 18px; flex-shrink:0;" onclick="addComponent(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Component
                </button>
            </div>
        </div>

        <datalist id="specLabelSuggestions">
            <?php foreach ($specSuggestions['general_labels'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="componentNameSuggestions">
            <?php foreach ($specSuggestions['component_names'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="componentLabelSuggestions">
            <?php foreach ($specSuggestions['component_labels'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="specUnitSuggestions">
            <?php foreach ($specSuggestions['units'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
        <?php endif; ?>

    </main>
</div>

<script>
function previewUpload(input) {
    const newPrev = document.getElementById('newImgPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            if (newPrev) {
                newPrev.src = e.target.result;
                newPrev.classList.add('active');
                newPrev.style.setProperty('display', 'block', 'important');
            }

            // Update the live preview card
            const wrap = document.getElementById('previewWrap');
            let img = document.getElementById('previewImg');
            if (!img) {
                img = document.createElement('img');
                img.id = 'previewImg';
                img.alt = 'Preview';
                img.className = 'admin-preview-img-fill';
                if (wrap) {
                    wrap.innerHTML = '';
                    wrap.appendChild(img);
                }
            }
            if (wrap) wrap.classList.add('has-image');
            if (img) img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        if (newPrev) {
            newPrev.src = '';
            newPrev.classList.remove('active');
            newPrev.style.setProperty('display', 'none', 'important');
        }
    }
}

function updatePreviewName(val) {
    document.getElementById('previewName').textContent = val || 'Product Name';
}

function updatePreviewPrice(val) {
    const p = parseFloat(val) || 0;
    document.getElementById('previewPrice').textContent = '₹' + p.toFixed(2);
}

// Drag-and-drop highlight
const box = document.getElementById('uploadBox');
box.addEventListener('dragover', e => { e.preventDefault(); box.classList.add('dragover'); });
box.addEventListener('dragleave', () => box.classList.remove('dragover'));
box.addEventListener('drop', e => {
    e.preventDefault(); box.classList.remove('dragover');
    const input = document.getElementById('productImageInput');
    input.files = e.dataTransfer.files;
    previewUpload(input);
});

// ── Variant Management ────────────────────────────────────────
// The product these rows belong to. The update handler scopes its UPDATE by
// product_id, so this has to be the real id — see saveVariantRow().
const DIEVON_VARIANT_PRODUCT_ID = <?= (int)($product['id'] ?? 0) ?>;

// ── Plain (colour-less) sizes, ticked off the ladder ──────────
//
// Same shape as the colour version, minus color_id: ticking writes a variant
// carrying size_code, which is what the free-text box never did. The code is
// the canonical key — ordering, size-guide matching and cart matching all hang
// off it — while the "Shown as" box only decides what the button reads.
async function togglePlainSize(code, label, checkbox) {
    const labelEl = document.getElementById('plabel-' + code);
    const stockEl = document.getElementById('pstock-' + code);
    const priceEl = document.getElementById('pprice-' + code);
    const enable  = (on) => [labelEl, stockEl, priceEl].forEach(el => { if (el) el.disabled = !on; });

    if (checkbox.checked) {
        const body = new URLSearchParams();
        body.set('action', 'add');
        body.set('product_id', DIEVON_VARIANT_PRODUCT_ID);
        body.set('name', label);
        body.set('size_code', code);
        body.set('price', '0');       // 0 => handler substitutes the product price
        body.set('stock_qty', '');    // blank => not counted until a number is typed
        body.set('csrf_token', window.ADMIN_CSRF_TOKEN || '');

        try {
            const res  = await fetch('variant_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const data = await res.json();
            if (!data.success) { alert(data.message || 'Error adding size.'); checkbox.checked = false; return; }
            checkbox.dataset.variantId = data.id;
            enable(true);
            if (labelEl) labelEl.value = label;
            if (stockEl) { stockEl.value = ''; stockEl.focus(); }
        } catch (e) {
            alert('Network error. Please try again.');
            checkbox.checked = false;
        }
        return;
    }

    const variantId = checkbox.dataset.variantId;
    if (!variantId) { enable(false); return; }
    if (!await dievonConfirm(`Remove size ${label}? Its stock and price for this size will be lost.`)) {
        checkbox.checked = true;
        return;
    }
    try {
        const res = await fetch('variant_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id=${variantId}&product_id=${DIEVON_VARIANT_PRODUCT_ID}&csrf_token=${encodeURIComponent(window.ADMIN_CSRF_TOKEN || '')}`,
        });
        const data = await res.json();
        if (!data.success) { alert('Error removing size.'); checkbox.checked = true; return; }
        checkbox.dataset.variantId = '';
        enable(false);
        if (labelEl) labelEl.value = '';
        if (stockEl) stockEl.value = '';
        if (priceEl) priceEl.value = '';
    } catch (e) {
        alert('Network error. Please try again.');
        checkbox.checked = true;
    }
}

// Label, stock and price always travel together: variant_handler.php rewrites all
// of them in one statement, so sending only the field that changed would blank
// the other two.
/* Chained for the same reason as the colour cards — see chainRowSave(). This
   panel posts the same full-card snapshot, so it raced the same way. The onchange
   attributes in the markup still call savePlainSize(); the work moved down into
   savePlainSizeNow() so the DOM is read when the request is actually built. */
function savePlainSize(code) {
    return chainRowSave('ps-' + code, () => savePlainSizeNow(code));
}

function savePlainSizeNow(code) {
    const checkbox = document.getElementById('psize-' + code);
    const variantId = checkbox ? checkbox.dataset.variantId : '';
    if (!variantId) return;

    const labelEl = document.getElementById('plabel-' + code);
    const stockEl = document.getElementById('pstock-' + code);
    const priceEl = document.getElementById('pprice-' + code);
    if (priceBoxRejectedBadInput(priceEl)) { return; }
    const label   = (labelEl && labelEl.value.trim() !== '')
        ? labelEl.value.trim()
        : (checkbox.parentElement ? checkbox.parentElement.textContent.trim() : code);

    const body = new URLSearchParams();
    body.set('action', 'update');
    body.set('id', variantId);
    body.set('product_id', DIEVON_VARIANT_PRODUCT_ID);
    body.set('name', label);
    body.set('price', (priceEl && priceEl.value.trim() !== '') ? priceEl.value.trim() : '0');
    body.set('stock_qty', (stockEl && stockEl.value.trim() !== '') ? stockEl.value.trim() : '');
    body.set('available', '1');
    body.set('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { restorePriceBox(priceEl, data); showSaveError(data.message); return; }
        // This panel owns a price box too, and was the one saver that reported
        // neither the outline nor the clamp — so a price typed into the ladder
        // saved in complete silence, which is the fault being fixed everywhere
        // else on this screen.
        markPriceOverride(priceEl);
        showSizePriceNotice(data.warning);
    })
    .catch(() => alert('Network error. Please try again.'));
}

function addVariant(productId) {
    const nameEl  = document.getElementById('newVariantName');
    const priceEl = document.getElementById('newVariantPrice');
    const stockEl = document.getElementById('newVariantStock');
    const name    = nameEl.value.trim();
    const price   = parseFloat(priceEl.value) || 0;
    const stock   = (stockEl && stockEl.value.trim() !== '') ? String(Math.max(0, parseInt(stockEl.value, 10) || 0)) : '';

    if (!name) { alert('Please enter a size name (e.g. S, M, L)'); nameEl.focus(); return; }

    fetch('variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=add&product_id=${productId}&name=${encodeURIComponent(name)}&price=${price}&stock_qty=${encodeURIComponent(stock)}&csrf_token=${encodeURIComponent(window.ADMIN_CSRF_TOKEN || '')}`,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { alert(data.message || 'Error adding variant'); return; }
        // Append new row to list
        const list = document.getElementById('variantsList');
        const id = data.id;
        const row = document.createElement('div');
        row.className = 'variant-row';
        row.id = 'vrow-' + id;
        row.style.cssText = 'display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--bg-main); border-radius:var(--radius-sm); margin-bottom:10px; border:1px solid var(--border-light);';
        // The price box stays EMPTY when no override was typed, matching how the
        // server-rendered rows above decide: a filled box would claim this size
        // has its own price when it is simply inheriting the product's.
        row.innerHTML = `
            <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:14px;"></i>
            <input type="text" id="vn-${id}" value="${escHtml(name)}" placeholder="Size name"
                class="form-control" style="flex:1; min-width:120px;"
                onchange="saveVariantRow(${id})">
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="font-size:15px;"><?= currencySymbol() ?></span>
                <input type="number" id="vp-${id}" value="${price > 0 ? price.toFixed(2) : ''}" step="0.01" min="0"
                    placeholder="Same as <?= htmlspecialchars(formatPrice((float)($product['price'] ?? 0)), ENT_QUOTES) ?>"
                    class="form-control" style="width:150px;"
                    onchange="saveVariantRow(${id})">
            </div>
            <input type="number" id="vs-${id}" min="0" value="${escHtml(stock)}" placeholder="Stock"
                class="form-control" style="width:90px;" onchange="saveVariantRow(${id})">
            <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-secondary); white-space:nowrap; cursor:pointer;">
                <input type="checkbox" id="va-${id}" checked onchange="saveVariantRow(${id})">
                Available
            </label>
            <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteVariant(${id}, ${productId})">
                <i class="fa-solid fa-trash"></i>
            </button>`;
        list.appendChild(row);
        nameEl.value  = '';
        priceEl.value = '';
        if (stockEl) stockEl.value = '';
        nameEl.focus();
        // The size saved, but the storefront may not charge the figure that was
        // typed — see sizePriceClampNotice() in variant_handler.php. Shown after
        // the row is drawn so the screen already agrees with the database before
        // it explains what the shopper will actually be asked to pay.
        showSizePriceNotice(data.warning);
    })
    .catch(() => alert('Network error. Please try again.'));
}

/* One place that tells the owner a size price will not reach the shop.
   ────────────────────────────────────────────────────────────────────────────
   Adding a size and editing one both hit the same clamp, and both used to end
   with a green flash and nothing else — the row saved, the box kept the typed
   figure, and the product page went on showing the product's price. This is the
   only signal that the two disagree, so both call sites share it rather than
   each wording it slightly differently.

   dievonAlert is the shop's branded dialog (assets/js/dievon-dialog.js, loaded
   by admin/includes/footer.php); the alert fallback keeps the message visible
   on a page where that script has not loaded, rather than swallowing it. */
function showSizePriceNotice(message) {
    if (!message) { return; }
    if (typeof window.dievonAlert === 'function') {
        window.dievonAlert(message, { type: 'warning' });
    } else {
        alert(message);
    }
}

/* A refused save must not be dressed as a successful one.
   ────────────────────────────────────────────────────────────────────────────
   These failures went through the bare alert(), which dievon-dialog.js overrides
   with its DEFAULT styling — so "₹100,000,000.00 is higher than this shop can
   store, so it was not saved" was announced under a green tick and the heading
   "Success". The words said refused and every other signal said saved, which is
   the precise confusion this screen keeps being fixed for. */
function showSaveError(message) {
    const text = message || 'Could not save this size.';
    if (typeof window.dievonAlert === 'function') {
        window.dievonAlert(text, { type: 'error' });
    } else {
        alert(text);
    }
}

/* Keep the "this size has its own price" outline honest after a save.
   ────────────────────────────────────────────────────────────────────────────
   The server decides it as "stored price differs from what the size would
   otherwise follow" ($eHasOver / $ceHasOver), so the box a shopkeeper is
   looking at has to be judged the same way — otherwise typing the product's own
   price lights the outline now and loses it on the next reload, and the screen
   contradicts itself over a number nobody changed. data-base-price carries the
   fallback figure so both sides can apply one rule. */
/* Put a refused price box back to what the database actually holds.
   ────────────────────────────────────────────────────────────────────────────
   A rejected save leaves the typed figure sitting in the box, so the screen goes
   on showing a number the shop is not charging — the same disagreement between
   box and database that this screen has been fixed for twice already. The
   handler sends the stored figure back with the refusal precisely so the box can
   be corrected here rather than waiting for a reload nobody knows to do. Blank
   is a real answer: it means the size follows the price above. */
function restorePriceBox(priceEl, data) {
    if (!priceEl || !data || data.stored_price === undefined) { return; }
    const base = parseFloat(priceEl.dataset.basePrice || '0');
    const stored = parseFloat(data.stored_price);
    // A stored figure equal to the fallback IS "follows the price above", and
    // this panel spells that as an empty box — same rule markPriceOverride uses.
    priceEl.value = (!data.stored_price || !(stored > 0) || (base > 0 && Math.abs(stored - base) < 0.005))
        ? ''
        : data.stored_price;
    markPriceOverride(priceEl);
}

/* Unparseable text in a price box must not read as "clear this price".
   ────────────────────────────────────────────────────────────────────────────
   An <input type="number"> holding "abc" reports value === "", which is exactly
   what a deliberately emptied box reports — so the savers posted price=0, the
   handler read that as "follow the price above", and a size selling at 1,500
   went back to the product price on a mistyped keystroke, answering
   {"success":true} with no warning. The same class of mistake as "-50", which
   IS refused; only the shape of the typo differed.

   validity.badInput is what tells the two apart: true only when the control
   holds text it cannot parse. The stashed value comes from focusin below,
   because the DOM no longer holds the figure that was there a moment ago. */
function priceBoxRejectedBadInput(priceEl) {
    if (!priceEl || !priceEl.validity || !priceEl.validity.badInput) { return false; }
    priceEl.value = priceEl.dataset.lastGood !== undefined ? priceEl.dataset.lastGood : '';
    markPriceOverride(priceEl);
    showSizePriceNotice('That price is not a number, so nothing on this size was changed. '
        + 'To make this size follow the price above, clear the box or enter 0.');
    return true;
}

/* Remember what a price box held before it was edited, so a rejected edit has
   something truthful to go back to. Delegated because these boxes are also
   created at runtime (addVariant, copyColorSizes). */
document.addEventListener('focusin', function (e) {
    const el = e.target;
    if (el && el.id && /^(pprice-|cprice-\d+-|vp-)/.test(el.id)) {
        el.dataset.lastGood = el.value;
    }
});

function markPriceOverride(priceEl) {
    if (!priceEl) { return; }
    const typed = priceEl.value.trim();
    const base  = parseFloat(priceEl.dataset.basePrice || '0');
    const isOverride = typed !== ''
        && parseFloat(typed) > 0
        && !(base > 0 && Math.abs(parseFloat(typed) - base) < 0.005);
    priceEl.classList.toggle('size-price-overridden', isOverride);
}

let updateTimer = {};

// Saves one Product Sizes row — name, price, stock and availability together.
//
// This replaces updateVariant(), which posted product_id=0 while the handler ran
// "UPDATE ... WHERE id = :id AND product_id = :pid". Nothing matches product_id 0,
// so every edit here affected ZERO rows — renaming a size, correcting its price or
// unticking Available all did nothing, and the row still flashed green because the
// handler reports success whether or not it changed anything. Measured directly:
// rowCount() = 0 against a real variant.
//
// Reading the four fields from the row by id also drops the old arrangement where
// each control passed the other two by re-querying the DOM inline; a rename had to
// go and fetch the price box, and vice versa.
function saveVariantRow(id) {
    clearTimeout(updateTimer[id]);
    updateTimer[id] = setTimeout(() => {
        const nameEl  = document.getElementById('vn-' + id);
        const priceEl = document.getElementById('vp-' + id);
        const stockEl = document.getElementById('vs-' + id);
        const availEl = document.getElementById('va-' + id);
        const name    = nameEl ? nameEl.value.trim() : '';
        if (!name) { if (nameEl) nameEl.focus(); return; }
        if (priceBoxRejectedBadInput(priceEl)) { return; }

        const body = new URLSearchParams();
        body.set('action', 'update');
        body.set('id', id);
        body.set('product_id', DIEVON_VARIANT_PRODUCT_ID);
        body.set('name', name);
        // Blank price means "no override" — the handler substitutes the product
        // price when it receives 0, so the size follows the product from now on.
        body.set('price', (priceEl && priceEl.value.trim() !== '') ? priceEl.value.trim() : '0');
        // stock_qty is always sent so that CLEARING the box stores NULL ("not
        // counted") instead of being read as "field omitted, leave as it was".
        body.set('stock_qty', (stockEl && stockEl.value.trim() !== '') ? stockEl.value.trim() : '');
        if (availEl && availEl.checked) { body.set('available', '1'); }
        body.set('csrf_token', window.ADMIN_CSRF_TOKEN || '');

        fetch('variant_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then(r => r.json())
        .then(data => {
            const row = document.getElementById('vrow-' + id);
            if (row) {
                row.style.borderColor = data.success ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)';
                setTimeout(() => { row.style.borderColor = ''; }, 1200);
            }
            if (!data.success) { restorePriceBox(priceEl, data); showSaveError(data.message); return; }
            // A green border means "stored", which is true — and used to be the
            // whole story even when the figure stored was one the shop will never
            // charge. See showSizePriceNotice().
            markPriceOverride(priceEl);
            showSizePriceNotice(data.warning);
        })
        .catch(() => alert('Network error. Please try again.'));
    }, 600);
}

async function deleteVariant(id, productId) {
    if (!await dievonConfirm('Remove this variant? This cannot be undone.')) return;
    fetch('variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&id=${id}&product_id=${productId}&csrf_token=${encodeURIComponent(window.ADMIN_CSRF_TOKEN || '')}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('vrow-' + id);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity 0.3s'; setTimeout(() => row.remove(), 300); }
        }
    });
}

/* Reorder with the arrows.
   'first' is one press for the common case — a photo uploaded last, wanted
   first. The DOM moves before the save is sent, so the tile lands where it was
   put on the same frame as the click rather than after a round trip. */
function galMove(btn, where) {
    var tile = btn.closest('.admin-gallery-thumb-item');
    var row  = document.getElementById('additionalImagesContainer');
    if (!tile || !row) { return; }

    if (where === 'first')  { row.insertBefore(tile, row.firstElementChild); }
    else if (where === -1)  { var p = tile.previousElementSibling; if (p) { row.insertBefore(tile, p); } }
    else                    { var n = tile.nextElementSibling;     if (n) { row.insertBefore(n, tile); } }

    galRefreshTags();
    galSaveOrder();
}

/* The "First" tag follows whichever photo is actually first. */
function galRefreshTags() {
    var tiles = Array.prototype.slice.call(
        document.querySelectorAll('#additionalImagesContainer .admin-gallery-thumb-item'));
    tiles.forEach(function (t, i) {
        var tag = t.querySelector('.admin-gallery-saved-tag');
        if (tag) { tag.textContent = (i === 0) ? 'First' : 'Saved'; }
        /* The ends cannot move further, whichever way the order was changed —
           so this runs after a DRAG too, not only after an arrow press. */
        var b = t.querySelectorAll('.gal-move-btn');
        if (b.length === 3) { b[0].disabled = (i === 0); b[1].disabled = (i === 0); b[2].disabled = (i === tiles.length - 1); }
    });
}

function galSaveOrder() {
    var ids = Array.prototype.slice.call(
        document.querySelectorAll('#additionalImagesContainer .admin-gallery-thumb-item'))
        .map(function (t) { return t.getAttribute('data-img-id'); })
        .filter(Boolean);
    if (!ids.length) { return; }

    var hint = document.getElementById('galOrderHint');
    fetch('image_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=reorder&ids=' + encodeURIComponent(ids.join(','))
            + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (hint) {
            /* Says which way it went. A silent failure here is the bad one: the
               tiles have already moved on screen, so without this the owner
               would believe an order that was never stored. */
            hint.textContent = d.success
                ? 'Order saved. The first photo here is the one shown straight after the main image.'
                : 'Order NOT saved — ' + (d.message || 'the server refused it') + '. Reload before relying on this order.';
            hint.style.color = d.success ? '' : 'var(--color-danger)';
        }
    })
    .catch(function () {
        if (hint) {
            hint.textContent = 'Order NOT saved — could not reach the server. Reload before relying on this order.';
            hint.style.color = 'var(--color-danger)';
        }
    });
}

async function deleteAdditionalImage(id) {
    if (!await dievonConfirm('Remove this image? This cannot be undone.')) return;
    fetch('image_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        // The endpoint now requires a CSRF token — it deletes a file from disk.
        body: 'action=delete&id=' + encodeURIComponent(id)
            + '&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || ''),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('ai-' + id);
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }
        } else {
            // Surface the real reason (e.g. a permissions problem) instead of a
            // generic message, since the row is deliberately kept on failure.
            (window.dievonAlert || alert)(data.message || 'Error deleting image.', { type: 'error' });
        }
    });
}

let galleryDataTransfer = new DataTransfer();

/* The photographs the owner chose to keep even though they are not 3:4.
   Held per file rather than as one flag because gallery pictures accumulate
   across several trips to the file picker: one odd photograph must not leave
   the check switched off for everything chosen afterwards, and removing that
   photograph again must switch it back on. */
const dvOverriddenFiles = new WeakSet();

/** Recompute the hidden override flag from what is actually selected right now. */
function dvSyncRatioFlag() {
    const flag = document.getElementById('allowImageRatio');
    if (!flag) return;
    const main = document.querySelector('input[type=file][name="product_image"]');
    const mainOdd = !!(main && main.files && main.files[0] && dvOverriddenFiles.has(main.files[0]));
    const galleryOdd = Array.from(galleryDataTransfer.files).some(f => dvOverriddenFiles.has(f));
    flag.value = (mainOdd || galleryOdd) ? '1' : '';
}

async function previewSelectedGalleryImages(input) {
    const container = document.getElementById('newSelectedGalleryPreview');
    if (!container) return;

    if (input.files && input.files.length > 0) {
        // Screen each newly chosen file before it joins the accumulated set.
        // Doing it here rather than in a separate change handler matters: by the
        // time such a handler ran, input.files would already be the accumulated
        // list, so the owner would be asked again about photographs approved on
        // an earlier trip to the picker.
        for (const file of Array.from(input.files)) {
            const verdict = await dvCheckImageRatio(file);
            if (verdict === 'cancel') { continue; }   // this one is left out, the rest still go in
            if (verdict === 'override') { dvOverriddenFiles.add(file); }
            galleryDataTransfer.items.add(file);
        }

        // Sync accumulated files back to input element
        input.files = galleryDataTransfer.files;
    }

    dvSyncRatioFlag();
    renderGalleryPreviews();
}

function renderGalleryPreviews() {
    const container = document.getElementById('newSelectedGalleryPreview');
    if (!container) return;
    container.innerHTML = '';

    const files = galleryDataTransfer.files;
    if (files && files.length > 0) {
        Array.from(files).forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const wrap = document.createElement('div');
                wrap.id = 'new-gallery-wrap-' + index;
                wrap.className = 'gallery-image-wrap admin-gallery-new-item';
                wrap.innerHTML = `
                    <img src="${e.target.result}" class="admin-gallery-thumb-img">
                    <button type="button" onclick="removeNewSelectedImage(${index})" title="Remove from selection" class="admin-gallery-del-btn">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <span class="admin-gallery-new-tag">New</span>
                `;
                container.appendChild(wrap);
            };
            reader.readAsDataURL(file);
        });
    }
}

function removeNewSelectedImage(index) {
    const input = document.getElementById('additional_images_input');
    const newDt = new DataTransfer();
    
    Array.from(galleryDataTransfer.files).forEach((file, i) => {
        if (i !== index) {
            newDt.items.add(file);
        }
    });

    galleryDataTransfer = newDt;
    if (input) {
        input.files = galleryDataTransfer.files;
    }

    dvSyncRatioFlag();   // dropping the odd-sized one puts the check back on
    renderGalleryPreviews();
}



function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Colour Variant Management ──────────────────────────────────
function addColor(productId) {
    const nameEl = document.getElementById('newColorName');
    const name   = nameEl.value.trim();
    if (!name) { alert('Please enter a colour name (e.g. Green)'); nameEl.focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('product_id', productId);
    fd.append('color_name', name);
    fd.append('sku', document.getElementById('newColorSku').value.trim());
    fd.append('price_override', document.getElementById('newColorPrice').value);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { location.reload(); }
            else { alert(data.message || 'Error adding colour.'); }
        })
        .catch(() => alert('Network error. Please try again.'));
}

function updateColor(id) {
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('color_name', document.getElementById('cname-' + id).value.trim());
    fd.append('sku', document.getElementById('csku-' + id).value.trim());
    fd.append('price_override', document.getElementById('cprice-' + id).value);
    fd.append('mrp_price_override', document.getElementById('cmrp-' + id).value);
    if (document.getElementById('cactive-' + id).checked) fd.append('is_active', '1');
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving colour.'); })
        .catch(() => alert('Network error. Please try again.'));
}

async function deleteColor(id, name) {
    if (!await dievonConfirm(`Delete colour "${name}"? This removes its gallery images and size/stock data too. This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting colour.'); })
        .catch(() => alert('Network error. Please try again.'));
}

async function uploadColorThumbnail(id, input) {
    if (!input.files || !input.files[0]) return;

    // Same question, same wording, before this one leaves the browser.
    const verdict = await dvCheckImageRatio(input.files[0]);
    if (verdict === 'cancel') { input.value = ''; return; }

    const fd = new FormData();
    fd.append('action', 'update');
    if (verdict === 'override') { fd.append('allow_image_ratio', '1'); }
    fd.append('id', id);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('color_name', document.getElementById('cname-' + id).value.trim());
    fd.append('sku', document.getElementById('csku-' + id).value.trim());
    fd.append('price_override', document.getElementById('cprice-' + id).value);
    fd.append('mrp_price_override', document.getElementById('cmrp-' + id).value);
    if (document.getElementById('cactive-' + id).checked) fd.append('is_active', '1');
    fd.append('thumbnail', input.files[0]);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error uploading thumbnail.'); return; }
            // Saved the colour but refused the picture — say so rather than
            // leaving a colour with no thumbnail and no explanation.
            if (data.image_error) {
                (window.dievonAlert || alert)(data.image_error, { type: 'warning', title: 'Photograph not added' });
                return;
            }
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.getElementById('cthumb-' + id);
                const ph  = document.getElementById('cthumb-placeholder-' + id);
                if (img) { img.src = e.target.result; img.style.display = 'block'; img.style.borderColor = 'var(--color-primary)'; }
                if (ph) ph.style.display = 'none';
            };
            reader.readAsDataURL(input.files[0]);
        })
        .catch(() => alert('Network error. Please try again.'));
}

function setColorThumbnail(colorId, image) {
    const fd = new FormData();
    fd.append('action', 'set_thumbnail');
    fd.append('id', colorId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('image', image);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error setting thumbnail.'); return; }
            const gallery = document.getElementById('cgallery-' + colorId);
            if (gallery) {
                gallery.querySelectorAll('img').forEach(im => { im.style.borderColor = 'transparent'; });
            }
            const img = document.getElementById('cthumb-' + colorId);
            const ph  = document.getElementById('cthumb-placeholder-' + colorId);
            if (img) { img.src = window.ADMIN_UPLOADS_URL + '/products/' + image; img.style.display = 'block'; img.style.borderColor = 'var(--color-primary)'; }
            if (ph) ph.style.display = 'none';
            event.target.style.borderColor = 'var(--color-primary)';
        })
        .catch(() => alert('Network error. Please try again.'));
}

// Attach the product's own photographs to this colour.
//
// Nothing is uploaded and nothing is deleted — the files are already on the
// server, so this only adds rows pointing at them. The product keeps its copies,
// which is what makes the action safe to undo by simply deleting the images
// again from this colour.
function adoptProductImages(colorId, btn) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Attaching…';

    const fd = new FormData();
    fd.append('action', 'adopt_product_images');
    fd.append('color_id', colorId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = original;
            if (!data.success) { alert(data.message || 'Could not attach the photos.'); return; }

            const gallery = document.getElementById('cgallery-' + colorId);
            (data.added || []).forEach(item => {
                if (!gallery) return;
                const wrap = document.createElement('div');
                wrap.className = 'color-gallery-item';
                wrap.id = 'cimg-' + item.id;
                wrap.style.cssText = 'position:relative; width:64px; height:64px;';
                wrap.innerHTML =
                    '<img src="<?= SITE_URL ?>/uploads/products/' + item.image + '" style="width:100%; height:100%; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid transparent;"' +
                    ' title="Click to set as thumbnail" onclick="setColorThumbnail(' + colorId + ', \'' + item.image + '\')">' +
                    '<button type="button" onclick="deleteColorImage(' + item.id + ', ' + colorId + ')" style="position:absolute; top:-6px; right:-6px; width:18px; height:18px; border-radius:50%; background:#c0392b; color:#fff; border:none; font-size:10px; cursor:pointer; line-height:1;">×</button>';
                gallery.appendChild(wrap);
            });

            // The colour-has-no-images warning is now out of date.
            const warn = document.getElementById('cwarn-img-' + colorId);
            if (warn && (data.added || []).length) { warn.style.display = 'none'; }

            if (typeof showToast === 'function') { showToast('Photos attached', data.message); }
            else { alert(data.message); }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = original;
            alert('Network error. Please try again.');
        });
}

async function uploadColorGallery(colorId, input) {
    if (!input.files || !input.files.length) return;

    /* Asked here too, and for each picture separately.
       ────────────────────────────────────────────────────────────────────
       These upload immediately over AJAX rather than with the form, so
       without this the server's refusal would arrive as a message after the
       wait, with no way to say "add it anyway". Cancelling one file clears
       the whole selection: the input holds them as one list, and dropping
       just the refused one is not something a file input allows. */
    let allowOdd = false;
    for (const f of input.files) {
        const verdict = await dvCheckImageRatio(f);
        if (verdict === 'cancel') { input.value = ''; return; }
        if (verdict === 'override') { allowOdd = true; }
    }

    const fd = new FormData();
    fd.append('action', 'upload_gallery');
    if (allowOdd) { fd.append('allow_image_ratio', '1'); }
    fd.append('color_id', colorId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    Array.from(input.files).forEach(f => fd.append('gallery_images[]', f));
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error uploading images.'); return; }
            /* A refused picture is reported by name.
               ────────────────────────────────────────────────────────────
               The handler returns success:true when SOME files saved, so a
               photograph rejected for being the wrong shape would otherwise
               just never appear — no error, no clue which one, and the owner
               left counting thumbnails to work out what happened. */
            if (data.rejected && data.rejected.length) {
                (window.dievonAlert || alert)(
                    data.rejected.join('\n\n'),
                    { type: 'warning', title: 'Some photographs were not added' }
                );
            }
            const gallery = document.getElementById('cgallery-' + colorId);
            if (!gallery) return;
            (data.uploaded || []).forEach(item => {
                const wrap = document.createElement('div');
                wrap.className = 'color-gallery-item';
                wrap.id = 'cimg-' + item.id;
                wrap.style.cssText = 'position:relative; width:64px; height:64px;';
                wrap.innerHTML = `
                    <img src="<?= SITE_URL ?>/uploads/products/${escHtml(item.image)}" style="width:100%; height:100%; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid transparent;" title="Click to set as thumbnail" onclick="setColorThumbnail(${colorId}, '${escHtml(item.image)}')">
                    <button type="button" onclick="deleteColorImage(${item.id}, ${colorId})" style="position:absolute; top:-6px; right:-6px; width:18px; height:18px; border-radius:50%; background:#c0392b; color:#fff; border:none; font-size:10px; cursor:pointer; line-height:1;">×</button>`;
                gallery.appendChild(wrap);
            });
            input.value = '';
        })
        .catch(() => alert('Network error. Please try again.'));
}

async function deleteColorImage(imageId, colorId) {
    if (!await dievonConfirm('Remove this image? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_image');
    fd.append('id', imageId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('color_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Error deleting image.'); return; }
            const el = document.getElementById('cimg-' + imageId);
            if (el) el.remove();
        })
        .catch(() => alert('Network error. Please try again.'));
}

async function toggleColorSize(colorId, code, label, checkbox) {
    const stockInput = document.getElementById('cstock-' + colorId + '-' + code);
    const labelInput = document.getElementById('clabel-' + colorId + '-' + code);
    const priceInput = document.getElementById('cprice-' + colorId + '-' + code);
    const productId  = <?= (int)($product['id'] ?? 0) ?>;

    if (checkbox.checked) {
        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('product_id', productId);
        fd.append('color_id', colorId);
        fd.append('size_code', code);
        fd.append('name', label);
        fd.append('stock_qty', '0');
        fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

        fetch('variant_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { alert(data.message || 'Error adding size.'); checkbox.checked = false; return; }
                checkbox.dataset.variantId = data.id;
                // The rename box opens with the size ticked; it starts on the ladder
                // label, which is what was just saved as the variant's name.
                if (labelInput) { labelInput.disabled = false; labelInput.value = label; }
                // Blank, not zero: the row was added with no price of its own, and an
                // empty box is how this panel says "follows the price above".
                if (priceInput) { priceInput.disabled = false; priceInput.value = ''; priceInput.classList.remove('size-price-overridden'); }
                if (stockInput) { stockInput.disabled = false; stockInput.value = '0'; stockInput.focus(); }
            })
            .catch(() => { alert('Network error. Please try again.'); checkbox.checked = false; });
    } else {
        const variantId = checkbox.dataset.variantId;
        if (!variantId) {
            if (stockInput) { stockInput.disabled = true; stockInput.value = ''; }
            if (labelInput) { labelInput.disabled = true; labelInput.value = ''; }
            if (priceInput) { priceInput.disabled = true; priceInput.value = ''; priceInput.classList.remove('size-price-overridden'); }
            return;
        }
        // Names the price too when there is one to lose — removing the size drops
        // the row, and with it a figure that is not written down anywhere else.
        const losingPrice = priceInput && priceInput.value.trim() !== '';
        const removeAsk = losingPrice
            ? `Remove size ${label} for this colour? Its stock and its ${priceInput.value.trim()} price will be lost.`
            : `Remove size ${label} for this colour? Its stock data will be lost.`;
        if (!await dievonConfirm(removeAsk)) { checkbox.checked = true; return; }

        fetch('variant_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id=${variantId}&product_id=${productId}&csrf_token=${encodeURIComponent(window.ADMIN_CSRF_TOKEN || '')}`,
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert('Error removing size.'); checkbox.checked = true; return; }
            checkbox.dataset.variantId = '';
            if (stockInput) { stockInput.disabled = true; stockInput.value = ''; }
            if (labelInput) { labelInput.disabled = true; labelInput.value = ''; }
            if (priceInput) { priceInput.disabled = true; priceInput.value = ''; priceInput.classList.remove('size-price-overridden'); }
        })
        .catch(() => { alert('Network error. Please try again.'); checkbox.checked = true; });
    }
}

// Saves one colour size — its label, stock and price always travel together.
//
// The name has to be sent on every update because variant_handler.php rewrites
// name, available, stock and price in a single statement; omitting the label
// while saving stock would blank it. It used to read the label off the checkbox
// caption, which is the LADDER text — so any rename was silently overwritten by
// "34/M" the next time the stock box was touched.
//
// price is sent on every save for the same reason, now that these cards own a
// price box. It was previously a hard-coded 0 — "follow the product price" —
// which meant saving a stock figure silently flattened whatever price the size
// carried. Sending the box's real contents makes that impossible: what is on
// screen is what is stored. A blank box still resolves to 0 server-side, which
// is the deliberate way to say "follow the price above".
function saveColorSize(colorId, code) {
    const checkbox  = document.getElementById('csize-' + colorId + '-' + code);
    const variantId = checkbox ? checkbox.dataset.variantId : '';
    if (!variantId) return;

    const labelEl = document.getElementById('clabel-' + colorId + '-' + code);
    const stockEl = document.getElementById('cstock-' + colorId + '-' + code);
    const priceEl = document.getElementById('cprice-' + colorId + '-' + code);
    if (priceBoxRejectedBadInput(priceEl)) { return; }
    // Falls back to the ladder caption when the box is left empty, so a size can
    // never end up nameless on the storefront.
    const label = (labelEl && labelEl.value.trim() !== '')
        ? labelEl.value.trim()
        : (checkbox.parentElement ? checkbox.parentElement.textContent.trim() : code);

    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', variantId);
    fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
    fd.append('name', label);
    fd.append('available', '1');
    fd.append('stock_qty', stockEl ? stockEl.value : '');
    fd.append('price', (priceEl && priceEl.value.trim() !== '') ? priceEl.value.trim() : '0');
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    return fetch('variant_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { restorePriceBox(priceEl, data); showSaveError(data.message); return data; }
            // The outline has to follow the number it describes, or a size that has
            // just been given its own price goes on looking like one that follows
            // the price above until the page is reloaded.
            markPriceOverride(priceEl);
            // Same notice the plain-size rows show: the row saved, but the shop may
            // not charge this figure. See sizePriceClampNotice() in variant_handler.php.
            showSizePriceNotice(data.warning);
            return data;
        })
        .catch(() => alert('Network error. Please try again.'));
}

/* One save at a time per size row, in the order the edits were made.
   ────────────────────────────────────────────────────────────────────────────
   Every control on a size card posts a FULL snapshot of that card — name, stock,
   available, price — so two saves in flight together are two snapshots racing,
   and whichever lands last wins wholesale. Edit stock and then price a few
   milliseconds apart and the stock request, built before the price box was
   touched and still carrying the OLD price, can arrive after the price request
   and quietly undo it. Measured at 0 ms interleaving: 3 of 8 rounds kept the
   previous round's figure.

   Harmless until now only because saveColorSize() used to post a hard-coded
   price=0 — there was no price on these cards to lose. Adding the price box put
   a real figure into every snapshot and made the race destructive.

   Chaining rather than debouncing: the callback reads the DOM when it RUNS, so
   each request is built from the boxes as they stand after the previous one
   finished, and the later edit always wins. A debounce would also close the race
   but adds a window where navigating away loses the edit outright — a fault the
   variant-row panel already has at 600 ms. `.then(fn, fn)` so one failed save
   does not strand the rest of the queue for that row. */
const dvSaveChain = {};
function chainRowSave(key, run) {
    const prev = dvSaveChain[key] || Promise.resolve();
    const next = prev.then(run, run);
    dvSaveChain[key] = next.catch(() => {});
    return next;
}

function updateColorSizeStock(colorId, code) { chainRowSave('cs-' + colorId + '-' + code, () => saveColorSize(colorId, code)); }
function updateColorSizeLabel(colorId, code) { chainRowSave('cs-' + colorId + '-' + code, () => saveColorSize(colorId, code)); }
function updateColorSizePrice(colorId, code) { chainRowSave('cs-' + colorId + '-' + code, () => saveColorSize(colorId, code)); }

/**
 * Copy the ticked sizes and their stock from one colour onto another.
 *
 * Additive on purpose. A size this colour ALREADY has is left completely alone —
 * its stock is a real count of real garments, and silently replacing it with
 * another colour's number is the one mistake here that would not be visible on
 * screen afterwards. So copying can add sizes and can never overwrite one.
 *
 * A colour that does not come in every size is handled the other way round:
 * copy the full set, then untick the one it lacks. That is one click, and it
 * leaves the tick marks on screen agreeing with what is stored.
 *
 * Sizes are added one at a time rather than in parallel — variant_handler.php
 * writes a row per call, and firing four at once at the same product invites the
 * duplicate-key race the plain-size code already had to be fixed for.
 */
/* ── Image ratio: ask, do not refuse ──────────────────────────────────────
   Checked the moment a file is chosen, in the browser, so the answer comes
   before anything is uploaded rather than after a failed save. The server
   still enforces the rule — this only decides whether the request carries
   the owner's explicit yes.

   Reads the real pixel dimensions from the file itself; nothing is sent
   anywhere to do it.
   ──────────────────────────────────────────────────────────────────────── */
const DV_RATIO = 3 / 4;
const DV_RATIO_TOLERANCE = 0.005;

function dvMeasureImage(file) {
    return new Promise(resolve => {
        const url = URL.createObjectURL(file);
        const im = new Image();
        im.onload = () => { URL.revokeObjectURL(url); resolve({ w: im.naturalWidth, h: im.naturalHeight }); };
        im.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
        im.src = url;
    });
}

/* Returns 'ok'       — the photograph is 3:4, upload it normally
             'override' — it is not, and the owner chose to add it anyway
             'cancel'   — it is not, and the owner wants to pick another

   'ok' and 'override' both mean "upload", but they must stay distinguishable:
   only 'override' may send the flag that switches the server check off. Sending
   it for correct photographs would leave the rule permanently disabled. */
async function dvCheckImageRatio(file) {
    const d = await dvMeasureImage(file);
    if (!d || !d.w || !d.h) { return 'ok'; }           // unreadable here; the server decides
    const ratio = d.w / d.h;
    if (Math.abs(ratio - DV_RATIO) <= DV_RATIO_TOLERANCE) { return 'ok'; }

    // Say what will be lost, in pixels, before asking.
    let lost;
    if (ratio > DV_RATIO) {
        lost = Math.round(d.w - d.h * DV_RATIO) + 'px would be cut off the sides';
    } else {
        lost = Math.round(d.h - d.w / DV_RATIO) + 'px would be cut off the top and bottom';
    }
    const msg = '"' + file.name + '" is ' + d.w + '×' + d.h + 'px, which is not 3:4.\n\n'
              + lost + ' when the shop crops it to fit.\n\n'
              + 'Product photographs are meant to be 1500×2000px. Add this one anyway?';

    const ok = (typeof dievonConfirm === 'function')
        ? await dievonConfirm(msg, { type: 'warning', danger: false, confirmText: 'Add it anyway', cancelText: 'Choose another' })
        : window.confirm(msg);
    return ok ? 'override' : 'cancel';
}

/* The main product photograph.
   The gallery input is screened inside previewSelectedGalleryImages() instead,
   because that one accumulates files across several picks. */
(function () {
    const input = document.querySelector('input[type=file][name="product_image"]');
    if (!input) return;

    input.addEventListener('change', async function () {
        const file = this.files && this.files[0];
        if (!file) { dvSyncRatioFlag(); return; }

        const verdict = await dvCheckImageRatio(file);
        if (verdict === 'cancel') { this.value = ''; }
        else if (verdict === 'override') { dvOverriddenFiles.add(file); }
        dvSyncRatioFlag();
    });
})();

async function copyColorSizes(targetColorId, btn, forcedSource) {
    const select = document.getElementById('ccopy-' + targetColorId);
    const status = document.getElementById('ccopystat-' + targetColorId);
    // forcedSource is passed by the one-click "use the product's sizes" button;
    // the picker beside it is only consulted when no source was forced.
    const sourceColorId = forcedSource || (select ? select.value : '');
    if (!sourceColorId) { if (status) { status.textContent = 'Pick a colour to copy from first.'; } return; }

    const ladder = <?= json_encode(array_column($sizeLadder, 'code'), JSON_UNESCAPED_UNICODE) ?>;

    /* Two possible sources with different id prefixes: the product's own Sizes
       panel uses psize-/pstock-/plabel-, a colour uses csize-/cstock-/clabel-
       with the colour id in the middle. Resolved here so the copy itself does
       not care which one was chosen. */
    const fromPlain = (sourceColorId === 'plain');
    const srcBox   = code => document.getElementById(fromPlain ? 'psize-'  + code : 'csize-'  + sourceColorId + '-' + code);
    const srcStock = code => document.getElementById(fromPlain ? 'pstock-' + code : 'cstock-' + sourceColorId + '-' + code);
    const srcLabel = code => document.getElementById(fromPlain ? 'plabel-' + code : 'clabel-' + sourceColorId + '-' + code);
    // Both panels own a price box now, so a copy can carry the price with the
    // size instead of landing it on the product price and saying nothing. A
    // source size with a blank box copies as blank, which is still "follow".
    const srcPrice = code => document.getElementById(fromPlain ? 'pprice-' + code : 'cprice-' + sourceColorId + '-' + code);

    const jobs = [];
    ladder.forEach(code => {
        const src = srcBox(code);
        const dst = document.getElementById('csize-' + targetColorId + '-' + code);
        if (!src || !dst || !src.checked) { return; }
        if (dst.checked) { return; }          // already here — leave its stock alone
        jobs.push({
            code,
            stock: (srcStock(code) || {}).value || '0',
            label: (srcLabel(code) || {}).value || '',
            price: ((srcPrice(code) || {}).value || '').trim(),
            box: dst
        });
    });

    /* Named, not just counted.
       ────────────────────────────────────────────────────────────────────
       This reported "2 already here, left untouched" without saying which
       two — so a size that kept its own empty stock box looked like a size
       the copy had failed on. Listing them by their on-screen caption makes
       the one rule this thing has visible at the moment it applies. */
    const skipped = ladder.filter(code => {
        const src = srcBox(code);
        const dst = document.getElementById('csize-' + targetColorId + '-' + code);
        return src && dst && src.checked && dst.checked;
    }).map(code => {
        const dst = document.getElementById('csize-' + targetColorId + '-' + code);
        return dst.parentElement ? dst.parentElement.textContent.trim() : code;
    });
    const alreadyHad = skipped.length;

    /* Sizes present on BOTH sides whose stock no longer matches.
       ────────────────────────────────────────────────────────────────────
       Copying is additive, so a size already ticked here keeps its own
       number — right, because that number is a real count. But it left no
       way back: correct a figure in Product Sizes after copying and this
       said "Nothing to copy", with the two sides visibly disagreeing and no
       button that would reconcile them.

       They are named, with both numbers, and updating them takes a separate
       yes. Overwriting a stock count is not something to do on a press that
       was only asking to add missing sizes. */
    const differing = ladder.map(code => {
        const src = srcBox(code);
        const dst = document.getElementById('csize-' + targetColorId + '-' + code);
        if (!src || !dst || !src.checked || !dst.checked) { return null; }
        const from = (srcStock(code) || {}).value || '0';
        const hereEl = document.getElementById('cstock-' + targetColorId + '-' + code);
        const here = hereEl ? (hereEl.value || '0') : '0';
        if (String(parseInt(from, 10) || 0) === String(parseInt(here, 10) || 0)) { return null; }
        return { code, from, here, caption: dst.parentElement ? dst.parentElement.textContent.trim() : code };
    }).filter(Boolean);

    if (!jobs.length) {
        if (!differing.length) {
            if (status) {
                status.textContent = alreadyHad
                    ? 'Nothing to copy — this colour already has all ' + alreadyHad
                      + ' of those sizes, with the same stock.'
                    : (fromPlain ? 'Product Sizes has no sizes ticked yet.' : 'That colour has no sizes ticked yet.');
            }
            return;
        }

        const list = differing.map(d => d.caption + ' (here ' + d.here + ', there ' + d.from + ')').join(', ');
        const ask  = 'Every size is already here, but the stock differs on: ' + list
                   + '.\n\nReplace the stock on this colour with those numbers?';
        const go = (typeof dievonConfirm === 'function')
            ? await dievonConfirm(ask, { type: 'warning', confirmText: 'Replace stock' })
            : window.confirm(ask);
        if (!go) {
            if (status) { status.textContent = 'Left as it was. Stock still differs on: ' + list + '.'; }
            return;
        }

        btn.disabled = true;
        if (status) { status.textContent = 'Updating stock…'; }
        let changed = 0;
        const failedUpdates = [];
        for (const d of differing) {
            const stk = document.getElementById('cstock-' + targetColorId + '-' + d.code);
            if (!stk) { continue; }
            const was = stk.value;
            stk.value = d.from;
            try {
                // The same call the box makes on its own change event. It resolves
                // with the handler's reply and reports its own errors by alert, so
                // the reply has to be inspected — a rejected promise is only the
                // network case.
                const data = await saveColorSize(targetColorId, d.code);
                if (data && data.success === false) {
                    stk.value = was;                 // put the box back to the truth
                    failedUpdates.push(d.caption);
                } else {
                    changed++;
                }
            } catch (e) {
                stk.value = was;
                failedUpdates.push(d.caption + ' (could not reach the server)');
            }
        }
        btn.disabled = false;
        if (status) {
            status.textContent = 'Stock updated on ' + changed + ' size' + (changed === 1 ? '' : 's') + '.'
                + (failedUpdates.length ? ' COULD NOT UPDATE: ' + failedUpdates.join(', ') + '.' : '');
            status.classList.toggle('size-copy-status--failed', failedUpdates.length > 0);
        }
        return;
    }

    btn.disabled = true;
    if (status) { status.textContent = 'Copying…'; }
    let added = 0;
    const failed = [];

    for (const job of jobs) {
        const label = job.label || (job.box.parentElement ? job.box.parentElement.textContent.trim() : job.code);
        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('product_id', <?= (int)($product['id'] ?? 0) ?>);
        fd.append('color_id', targetColorId);
        fd.append('size_code', job.code);
        fd.append('name', label);
        fd.append('stock_qty', job.stock);
        // Blank copies as 0, which the handler reads as "follow the product
        // price" — the same thing an empty box means on either panel.
        fd.append('price', job.price !== '' ? job.price : '0');
        fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

        /* A size that fails is NAMED, never quietly dropped.
           ────────────────────────────────────────────────────────────────
           These two paths used to `continue` and swallow the error. The run
           carried on, the count came out lower, and the report said only how
           many had been added — so a size that failed looked exactly like a
           size that had been skipped on purpose, and both looked like the
           button half-working. That is the same fault as the unnamed skips
           above, and it is the one that would have been hardest to explain,
           because nothing on screen would have mentioned it at all. */
        try {
            const res  = await fetch('variant_handler.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.success) {
                failed.push(label + (data.message ? ' (' + data.message + ')' : ''));
                continue;
            }
            job.box.checked = true;
            job.box.dataset.variantId = data.id;
            const lab = document.getElementById('clabel-' + targetColorId + '-' + job.code);
            const stk = document.getElementById('cstock-' + targetColorId + '-' + job.code);
            const prc = document.getElementById('cprice-' + targetColorId + '-' + job.code);
            if (lab) { lab.disabled = false; lab.value = label; }
            if (stk) { stk.disabled = false; stk.value = job.stock; }
            if (prc) { prc.disabled = false; prc.value = job.price; markPriceOverride(prc); }
            added++;
        } catch (e) {
            // One failure must not abandon the rest — but it must be reported.
            failed.push(label + ' (could not reach the server)');
        }
    }

    btn.disabled = false;
    if (status) {
        status.textContent = 'Added ' + added + ' size' + (added === 1 ? '' : 's') + '.'
            + (alreadyHad
                ? ' ' + skipped.join(', ') + ' ' + (alreadyHad === 1 ? 'was' : 'were')
                  + ' already ticked here, so ' + (alreadyHad === 1 ? 'its' : 'their')
                  + ' stock was left exactly as you had it.'
                : '')
            + (failed.length
                ? ' COULD NOT ADD: ' + failed.join('; ') + '. Tick '
                  + (failed.length === 1 ? 'that one' : 'those') + ' by hand.'
                : '')
            + (added ? ' Check the stock numbers below.' : '');
        status.classList.toggle('size-copy-status--failed', failed.length > 0);
    }
}

// ── General Product Specifications ──────────────────────────────
const DIEVON_PRODUCT_ID = <?= (int)($product['id'] ?? 0) ?>;

function addGeneralSpec(productId) {
    const labelEl = document.getElementById('newGeneralSpecLabel');
    const valueEl = document.getElementById('newGeneralSpecValue');
    const label = labelEl.value.trim();
    const value = valueEl.value.trim();
    if (!label || !value) { alert('Please enter both a label and a value.'); (label ? valueEl : labelEl).focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('product_id', productId);
    fd.append('label', label);
    fd.append('value', value);
    fd.append('unit', document.getElementById('newGeneralSpecUnit').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error adding specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function updateGeneralSpec(id) {
    const row = document.getElementById('gspec-' + id);
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('label', row.querySelector('.gspec-label').value.trim());
    fd.append('value', row.querySelector('.gspec-value').value.trim());
    fd.append('unit', row.querySelector('.gspec-unit').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

async function deleteGeneralSpec(id) {
    if (!await dievonConfirm('Delete this specification? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function moveGeneralSpec(id, direction) {
    const fd = new FormData();
    fd.append('action', direction === 'up' ? 'move_up' : 'move_down');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('specification_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error reordering.'); })
        .catch(() => alert('Network error. Please try again.'));
}

// ── Product Components & their nested Specifications ─────────────
function addComponent(productId) {
    const nameEl = document.getElementById('newComponentName');
    const name = nameEl.value.trim();
    if (!name) { alert('Please enter a component name (e.g. Kurti).'); nameEl.focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add_component');
    fd.append('product_id', productId);
    fd.append('name', name);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error adding component.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function updateComponent(id) {
    const card = document.getElementById('comp-' + id);
    const fd = new FormData();
    fd.append('action', 'update_component');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('name', card.querySelector('.comp-name').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving component.'); })
        .catch(() => alert('Network error. Please try again.'));
}

async function deleteComponent(id, name) {
    if (!await dievonConfirm(`Delete component "${name}"? This removes all of its specifications too. This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action', 'delete_component');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting component.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function moveComponent(id, direction) {
    const fd = new FormData();
    fd.append('action', direction === 'up' ? 'move_component_up' : 'move_component_down');
    fd.append('id', id);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error reordering.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function addComponentSpec(componentId, productId) {
    const labelEl = document.getElementById('newCompSpecLabel-' + componentId);
    const valueEl = document.getElementById('newCompSpecValue-' + componentId);
    const label = labelEl.value.trim();
    const value = valueEl.value.trim();
    if (!label || !value) { alert('Please enter both a label and a value.'); (label ? valueEl : labelEl).focus(); return; }

    const fd = new FormData();
    fd.append('action', 'add_spec');
    fd.append('component_id', componentId);
    fd.append('product_id', productId);
    fd.append('label', label);
    fd.append('value', value);
    fd.append('unit', document.getElementById('newCompSpecUnit-' + componentId).value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error adding specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function updateComponentSpec(id, componentId) {
    const row = document.getElementById('cspec-' + id);
    const fd = new FormData();
    fd.append('action', 'update_spec');
    fd.append('id', id);
    fd.append('component_id', componentId);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('label', row.querySelector('.cspec-label').value.trim());
    fd.append('value', row.querySelector('.cspec-value').value.trim());
    fd.append('unit', row.querySelector('.cspec-unit').value.trim());
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (!data.success) alert(data.message || 'Error saving specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

async function deleteComponentSpec(id, componentId) {
    if (!await dievonConfirm('Delete this specification? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_spec');
    fd.append('id', id);
    fd.append('component_id', componentId);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error deleting specification.'); })
        .catch(() => alert('Network error. Please try again.'));
}

function moveComponentSpec(id, componentId, direction) {
    const fd = new FormData();
    fd.append('action', direction === 'up' ? 'move_spec_up' : 'move_spec_down');
    fd.append('id', id);
    fd.append('component_id', componentId);
    fd.append('product_id', DIEVON_PRODUCT_ID);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('component_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.message || 'Error reordering.'); })
        .catch(() => alert('Network error. Please try again.'));
}

</script>

<?php
/* Sticky save bar.
   ────────────────────────────────────────────────────────────────────────
   The <form> ends at the pricing block, but the page keeps going for
   another thousand lines — sizes, colours, components, specifications.
   Those sections save themselves over AJAX, so the Save button only ever
   applied to the fields above them; the trouble is it also SITS above
   them, so finishing a product meant scrolling the whole way back up to
   press it.

   The button below is outside the form on purpose and reaches it with
   form="productForm", so it is the same submit — same required-field
   checks, same handler — not a second path that could drift from the
   first.

   Always on screen, with no show/hide on scroll. The first version revealed
   it only once the form's own Save had scrolled past, which meant Save was
   sometimes there and sometimes not, and it depended on a scroll handler
   that is easy to get wrong — IntersectionObserver and requestAnimationFrame
   were each tried and each silently did nothing in an embedded webview,
   leaving no Save button at all. A control that is always in the same place
   needs no JavaScript and cannot fail.
   ────────────────────────────────────────────────────────────────────── */
?>
<script>
/* One save per press.
   ────────────────────────────────────────────────────────────────────────
   There was no guard against submitting this form twice. On a NEW product
   that is how one press becomes two rows: the first POST inserts, and a
   second POST sent before the redirect arrives still carries no product_id,
   so it inserts again. The result is the same garment twice — which is
   exactly what appeared on the live shop, one published and one draft.

   It became easy to trigger because of the stale-save loop fixed earlier
   today: the save was refused, nothing visible changed, and pressing Save
   again was the natural response. A slow connection does the same thing.

   Every submit button is disabled for the rest of the request. Not the FORM
   — a disabled field is not posted, and disabling the form's inputs would
   strip the values out of the very submission being sent. If the browser
   comes back to this page from history, the buttons are restored, or the
   owner would find a form that can never be saved. */
(function () {
    const form = document.getElementById('productForm');
    if (!form) { return; }

    const buttons = () => [
        ...form.querySelectorAll('button[type="submit"], input[type="submit"]'),
        ...document.querySelectorAll('[form="productForm"]')
    ];

    let sending = false;
    form.addEventListener('submit', function (e) {
        if (sending) { e.preventDefault(); return; }
        // Let the browser's own required-field check refuse it first.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) { return; }
        sending = true;
        buttons().forEach(b => {
            b.disabled = true;
            b.dataset.dvBusyLabel = b.innerHTML;
            b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        });
    });

    // Back/forward returns a cached page with the buttons still disabled.
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted && !sending) { return; }
        sending = false;
        buttons().forEach(b => {
            b.disabled = false;
            if (b.dataset.dvBusyLabel) { b.innerHTML = b.dataset.dvBusyLabel; }
        });
    });
})();
</script>

<div class="admin-save-dock" id="adminSaveDock">
    <span class="admin-save-dock-note">
        Sizes, colours and specifications save on their own. This saves the product details.
    </span>
    <button type="submit" form="productForm" class="btn-primary admin-save-dock-btn">
        <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
        <?= $isEdit ? 'Save Changes' : 'Add Product' ?>
    </button>
</div>

<?php require_once 'includes/footer.php'; ?>


