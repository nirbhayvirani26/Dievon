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

// Moved ABOVE the POST handlers below.
// It used to sit after them, and after the page had already rendered — so a
// staff account without this permission ran the INSERT/DELETE first and was
// only shown the 403 afterwards. The record was already gone by then.
requireAdminCapability('catalogue.manage');

// Colours only. ?type=sizes is accepted and quietly treated as colours.
//
// This page offered a sizes tab that no link ever reached — $activeTab below was
// computed and never used, so the switcher was never built — and the size rows it
// managed drove exactly one thing: the order of the shop's size filter chips.
// That order now comes from the size ladder in config/size_ladder.php, the same
// list the product form, the colour Sizes & Stock grid, the size guide and the
// product page all use.
//
// Leaving the tab reachable would mean editing a second size list that changes
// nothing on the website, which is how the two drifted apart in the first place
// (the master list stopped at XL while the ladder runs to 5XL). Sizes are edited
// per product on the product form, and shop-wide in the ladder file.
//
// Existing attr_type='size' rows are left in the table — unreferenced, but a
// harmless record, and deleting a shopkeeper's data to tidy a page is not a
// trade worth making.
$type = 'colors';
$activeTab = 'colors';
$successMsg = '';
$errorMsg   = '';

// Auto create attributes table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_attributes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `attr_type` ENUM('color','size') NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `code` VARCHAR(50) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

// Handle Add/Delete (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attr']) && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $errorMsg = "Security validation failed (Invalid CSRF token).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attr'])) {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $attrType = $type === 'sizes' ? 'size' : 'color';
    if ($name) {
        try {
            /* No duplicates, compared without case.
               ────────────────────────────────────────────────────────────────
               There is no unique index on this table, and the list ended up
               holding 'red' twice. A duplicate is not cosmetic here: this list
               is now the only source of colours for every product, it is what
               the shop's filter names, and two rows spelling one colour is
               exactly the drift the whole change exists to remove. */
            /* A comma is the separator Color Way uses to hold more than one
               colour on a product, so a colour NAMED with a comma would split
               into two that do not exist and match nothing. */
            if ($attrType === 'color' && str_contains($name, ',')) {
                throw new RuntimeException('A colour name cannot contain a comma — the comma is what separates two colours on a product.');
            }
            $clash = $pdo->prepare("SELECT name FROM product_attributes
                                     WHERE attr_type = :type AND LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1");
            $clash->execute(['type' => $attrType, 'name' => $name]);
            if ($existing = $clash->fetchColumn()) {
                throw new RuntimeException("'{$existing}' is already on the list.");
            }
            $stmt = $pdo->prepare("INSERT INTO product_attributes (attr_type, name, code) VALUES (:type, :name, :code)");
            $stmt->execute(['type' => $attrType, 'name' => $name, 'code' => $code]);
            $successMsg = ucfirst($attrType) . " '{$name}' created successfully.";
        } catch (RuntimeException $e) {
            $errorMsg = $e->getMessage();
        } catch (PDOException $e) {
            $errorMsg = "Error adding attribute: " . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        $delId = (int)$_POST['delete'];
        try {
            $pdo->prepare("DELETE FROM product_attributes WHERE id = :id")->execute(['id' => $delId]);
            $successMsg = "Item deleted successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error deleting attribute: " . $e->getMessage();
        }
    }
}

/* ── Reconciliation: colours products use that this list does not have ───────
 *
 * The product form only offers this list now, but products saved BEFORE that
 * rule hold whatever was typed at the time. Those values still feed the shop's
 * colour filter, so until they are dealt with the filter keeps showing them.
 *
 * Deliberately not a migration script with names baked into it. This machine's
 * database and the live shop's database have different strays — the QA colours
 * here will not exist there, and live will have its own — so the panel reads
 * whichever database it is running in and the shopkeeper decides, per row, on
 * each site. Nothing is created or rewritten without a press.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reconcile'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        $mode  = (string)($_POST['reconcile'] ?? '');
        $stray = trim((string)($_POST['stray'] ?? ''));

        if ($stray === '') {
            $errorMsg = 'Nothing selected.';
        } elseif ($mode === 'add') {
            try {
                $clash = $pdo->prepare("SELECT name FROM product_attributes
                                         WHERE attr_type = 'color' AND LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1");
                $clash->execute(['name' => $stray]);
                if ($clash->fetchColumn()) {
                    throw new RuntimeException("'{$stray}' is already on the list.");
                }
                $ins = $pdo->prepare("INSERT INTO product_attributes (attr_type, name, code) VALUES ('color', :name, '')");
                $ins->execute(['name' => $stray]);
                $successMsg = "'{$stray}' added to the colour list. The products already using it now match the filter.";
            } catch (RuntimeException $e) {
                $errorMsg = $e->getMessage();
            } catch (PDOException $e) {
                $errorMsg = 'Could not add that colour: ' . $e->getMessage();
            }
        } elseif ($mode === 'rename') {
            $to = trim((string)($_POST['rename_to'] ?? ''));
            if ($to === '') {
                $errorMsg = 'Choose the colour to rename it to.';
            } else {
                /* One transaction across all three columns. A rename that
                   updated the variants and then failed on products would leave
                   the same garment under two colours, which is worse than the
                   stray it was fixing.

                   Only the NAME moves. product_colors.id is untouched, so every
                   size, stock number, image and price override stays attached
                   to exactly the colour it was on, and order_items keeps its own
                   copy of what was actually bought. */
                try {
                    $pdo->beginTransaction();
                    $moved = 0;

                    /* Colour Way can hold a LIST, so only the matching PART moves.
                       ────────────────────────────────────────────────────────
                       A garment that is "Black,Green" must come back as
                       "Black,Emerald Green" when Green is renamed — an exact-match
                       UPDATE would never touch it, and a blind string replace could
                       corrupt a name that contains another ("Green" inside "Emerald
                       Green"). Splitting and comparing whole parts is the only safe
                       way. */
                    $rows = $pdo->query("SELECT id, color_way FROM products
                                          WHERE color_way IS NOT NULL AND color_way <> ''");
                    $setWay = $pdo->prepare("UPDATE products SET color_way = :cw WHERE id = :id");
                    foreach ($rows as $row) {
                        $parts = dievonColorWayList((string)$row['color_way']);
                        $hit = false;
                        foreach ($parts as $i => $part) {
                            if (strcasecmp(trim($part), $stray) === 0) { $parts[$i] = $to; $hit = true; }
                        }
                        if (!$hit) { continue; }
                        // unique(), or renaming one half onto the other leaves "Green,Green".
                        $setWay->execute(['cw' => implode(',', array_values(array_unique($parts))), 'id' => (int)$row['id']]);
                        $moved++;
                    }

                    // products.color holds the PRIMARY colour only — a single value.
                    $a = $pdo->prepare("UPDATE products SET color = :to WHERE LOWER(TRIM(color)) = LOWER(TRIM(:from))");
                    $a->execute(['to' => mb_substr($to, 0, 50), 'from' => $stray]);

                    // A colour variant is always one colour, never a list.
                    $c = $pdo->prepare("UPDATE product_colors SET color_name = :to WHERE LOWER(TRIM(color_name)) = LOWER(TRIM(:from))");
                    $c->execute(['to' => $to, 'from' => $stray]);

                    $moved += $a->rowCount() + $c->rowCount();
                    $pdo->commit();
                    $successMsg = "'{$stray}' renamed to '{$to}' on {$moved} row(s). "
                                . "Sizes, stock, images and price overrides are untouched — they belong to the colour, not its name.";
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $errorMsg = 'Rename failed, nothing was changed: ' . $e->getMessage();
                }
            }
        }
    }
}

$strayColors = [];
try { $strayColors = dievonStrayColors($pdo); } catch (Throwable $e) {}

$attributes = [];
try {
    $targetType = $type === 'sizes' ? 'size' : 'color';

    /* Alphabetical. This was id DESC, so the list was in the order the colours
       happened to be typed, backwards — and a colour list exists to be looked up
       by name. No sort_order column on this table, so nothing manual is being
       overridden.

       Only ever colours: $type is fixed above and the Sizes tab was deliberately
       removed (see the note at the top of this file). Sorting by name would be
       wrong for sizes — S, M, L, XL is a ladder, not an alphabet — but that
       branch would be dead code here, so it is not written. If sizes ever return
       to this page, they need id order, not this. */
    $stmt = $pdo->prepare("SELECT * FROM product_attributes WHERE attr_type = :type ORDER BY name ASC, id ASC");
    $stmt->execute(['type' => $targetType]);
    $attributes = $stmt->fetchAll();
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';

?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">🎨 <?= ucfirst($type) ?> Master Attributes</h1>
        <p class="admin-page-subtitle">Configure <?= strtolower($type) ?> options available for product size selectors and shop filters.</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<div class="glass-panel form-section" style="margin-bottom: 24px;">
    <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 16px;">Add New <?= ucfirst($type) ?> Attribute</h3>
    <form action="attributes.php?type=<?= htmlspecialchars($type) ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-row" style="display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center;">
            <div class="form-group" style="margin:0;">
                <input type="text" name="name" class="form-control" placeholder="<?= $type === 'sizes' ? 'Size Label (e.g. S, M, L, XL, 38)' : 'Color Name (e.g. Emerald Green, Rose Gold)' ?>" required>
            </div>
            <?php if ($type === 'colors'): ?>
                <div class="form-group" style="margin:0;">
                    <input type="color" name="code" value="#991b1b" style="width: 50px; height: 42px; padding: 2px; border: 1px solid var(--border-strong); cursor: pointer;">
                </div>
            <?php endif; ?>
            <button type="submit" name="add_attr" class="btn-primary" style="padding: 10px 20px; font-size: 14px;">
                Add <?= ucfirst($type) ?>
            </button>
        </div>
    </form>
</div>

<?php /* Shown only when there is something to reconcile, so a tidy shop never
         sees a panel telling it about a problem it does not have. */ ?>
<?php if ($strayColors): ?>
<div class="glass-panel" style="padding:0; overflow:hidden; margin-bottom:22px; border:1px solid #c9a227;">
    <div style="padding:16px 24px; border-bottom:1px solid var(--border-light); background:rgba(201,162,39,0.08);">
        <div style="font-weight:700; font-size:15px; color:var(--text-primary);">
            ⚠️ <?= count($strayColors) ?> colour<?= count($strayColors) === 1 ? '' : 's' ?> in use but not on this list
        </div>
        <div style="font-size:12.5px; color:var(--text-muted); margin-top:6px; line-height:1.6; max-width:70ch;">
            These were typed into products before colours had to be chosen from this list.
            Each one still appears in the shop's colour filter as its own entry, matching only
            the products that happen to spell it exactly this way.
            <strong>Add</strong> keeps the colour and puts it on the list.
            <strong>Rename</strong> moves those products onto a colour you already have.
            Renaming changes the label only — sizes, stock, images and price overrides stay with
            the colour, and past orders keep the name they were bought under.
        </div>
    </div>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Colour found</th>
                    <th>Where</th>
                    <th>Used by</th>
                    <th style="width:330px; text-align:right;">Fix</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($strayColors as $sc): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($sc['value']) ?></td>
                    <td style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars(implode(', ', $sc['fields'])) ?></td>
                    <td style="font-size:12px; color:var(--text-muted);">
                        <?php /* Named, not counted. A bare "3 products" cannot be acted on —
                                 the shopkeeper has to know WHICH garments change. */ ?>
                        <?= htmlspecialchars(implode(', ', array_slice(array_values($sc['products']), 0, 3))) ?>
                        <?php if (count($sc['products']) > 3): ?>
                            <em>and <?= count($sc['products']) - 3 ?> more</em>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <form method="POST" style="display:inline-flex; gap:6px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                            <input type="hidden" name="stray" value="<?= htmlspecialchars($sc['value']) ?>">
                            <button type="submit" name="reconcile" value="add" class="btn-secondary" style="padding:5px 10px; font-size:12px;">
                                Add to list
                            </button>
                            <?php if ($attributes): ?>
                                <select name="rename_to" class="form-control" style="width:150px; padding:5px 8px; font-size:12px;">
                                    <option value="">Rename to&hellip;</option>
                                    <?php foreach ($attributes as $a): ?>
                                        <option value="<?= htmlspecialchars($a['name']) ?>"><?= htmlspecialchars($a['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php /* The shop's own dialog, not the browser's grey confirm box:
                                         window.dievonConfirm, loaded on every admin page by
                                         admin/includes/footer.php and used the same way by countries.php
                                         and the product form. Wired below rather than inline, because it
                                         returns a Promise and an onclick can only return true or false. */ ?>
                                <button type="submit" name="reconcile" value="rename" class="btn-secondary" style="padding:5px 10px; font-size:12px;"
                                        data-confirm-rename="Rename <?= htmlspecialchars($sc['value'], ENT_QUOTES) ?> on <?= count($sc['products']) ?> product(s)?&#10;&#10;Only the colour name changes. Sizes, stock, images and price overrides stay exactly where they are.">
                                    Rename
                                </button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
/* Uses the shop's dialog when it is there and the browser's box only if it is
   not, the same fallback countries.php and the product form use.

   requestSubmit(button) rather than form.submit(): the pressed button carries
   name="reconcile" value="rename", and a plain submit() drops the submitter, so
   the handler would receive no action at all and silently do nothing. The
   `confirmed` flag stops the second, real press from asking again. */
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-confirm-rename]');
    if (!btn || btn.dataset.confirmed === '1') { return; }
    e.preventDefault();
    var ask = (typeof window.dievonConfirm === 'function')
        ? window.dievonConfirm
        : function (m) { return Promise.resolve(window.confirm(m)); };
    Promise.resolve(ask(btn.getAttribute('data-confirm-rename'))).then(function (ok) {
        if (!ok) { return; }
        btn.dataset.confirmed = '1';
        var form = btn.form;
        if (form && typeof form.requestSubmit === 'function') { form.requestSubmit(btn); }
        else { btn.click(); }
    });
});
</script>

<?php endif; ?>

<div class="glass-panel" style="padding:0; overflow:hidden;">
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary);">
        🏷️ Active <?= ucfirst($type) ?> List (<?= count($attributes) ?>)
    </div>
    <div class="table-wrapper">
        <table class="data-table" style="width: 100%;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th><?= ucfirst($type) ?> Name</th>
                    <?php if ($type === 'colors'): ?><th>Color Code</th><?php endif; ?>
                    <th style="width: 100px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attributes)): ?>
                    <tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">No <?= htmlspecialchars($type) ?> created yet. Standard options available on product form.</td></tr>
                <?php else: ?>
                    <?php foreach ($attributes as $a): ?>
                        <tr>
                            <td style="color: var(--text-muted); font-size: 12px;">#<?= $a['id'] ?></td>
                            <td><strong style="color: var(--text-primary); font-size: 14px;"><?= htmlspecialchars($a['name']) ?></strong></td>
                            <?php if ($type === 'colors'): ?>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="display: inline-block; width: 22px; height: 22px; background: <?= htmlspecialchars($a['code'] ?? '#991b1b') ?>; border: 1px solid var(--border-light);"></span>
                                        <code style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($a['code'] ?? '#991b1b') ?></code>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <td style="text-align: right;">
                                <div class="admin-actions">
                                    <form method="POST" action="attributes.php?type=<?= htmlspecialchars($type) ?>" style="display:inline;" onsubmit="return dvConfirmForm(this,'Delete this attribute?');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="delete" value="<?= $a['id'] ?>">
                                        <button type="submit" class="admin-action-btn is-danger" title="Delete" aria-label="Delete <?= htmlspecialchars($a['name'] ?? 'attribute') ?>">
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
