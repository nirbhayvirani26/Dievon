<?php
/**
 * Dievon – Suppliers
 * ============================================================================
 * One record per supplier, kept here rather than retyped on every product. The
 * product form only picks one and records the supplier's own design number for
 * that piece; everything about the supplier itself lives on this screen.
 *
 * A supplier that products still point at is NOT deletable. The alternative —
 * deleting anyway and leaving those products pointing at nothing — is how the
 * orphan rows elsewhere in this shop happened. The count is shown so it is
 * obvious why, and the products can be moved first.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed. Reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $name   = trim((string)($_POST['name'] ?? ''));
        $owner  = trim((string)($_POST['owner_name'] ?? ''));
        $phone  = trim((string)($_POST['phone'] ?? ''));
        $id     = (int)($_POST['id'] ?? 0);

        try {
            if ($action === 'save') {
                if ($name === '') {
                    $errorMsg = 'A supplier needs a name.';
                } elseif ($id > 0) {
                    $pdo->prepare("UPDATE suppliers SET name = :n, owner_name = :o, phone = :p WHERE id = :id")
                        ->execute([':n' => $name, ':o' => $owner ?: null, ':p' => $phone ?: null, ':id' => $id]);
                    /* Products keep a copy of the NAME because checkout freezes it
                       into the order snapshot. Renaming here updates the products
                       so new orders carry the new name; orders already placed keep
                       the name they were sold under, which is the point. */
                    $pdo->prepare("UPDATE products SET supplier_name = :n WHERE supplier_id = :id")
                        ->execute([':n' => $name, ':id' => $id]);
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'update_supplier', "Updated supplier #$id ($name)");
                    $successMsg = 'Supplier updated.';
                } else {
                    $pdo->prepare("INSERT INTO suppliers (name, owner_name, phone) VALUES (:n, :o, :p)")
                        ->execute([':n' => $name, ':o' => $owner ?: null, ':p' => $phone ?: null]);
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'add_supplier', "Added supplier '$name'");
                    $successMsg = 'Supplier added.';
                }
            } elseif ($action === 'delete' && $id > 0) {
                $inUse = $pdo->prepare("SELECT COUNT(*) FROM products WHERE supplier_id = :id");
                $inUse->execute([':id' => $id]);
                $n = (int)$inUse->fetchColumn();
                if ($n > 0) {
                    $errorMsg = "That supplier is still used by $n product" . ($n === 1 ? '' : 's')
                              . '. Change those products to another supplier first.';
                } else {
                    $pdo->prepare("DELETE FROM suppliers WHERE id = :id")->execute([':id' => $id]);
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_supplier', "Deleted supplier #$id");
                    $successMsg = 'Supplier deleted.';
                }
            }
        } catch (PDOException $e) {
            $errorMsg = (stripos($e->getMessage(), 'Duplicate') !== false)
                ? 'There is already a supplier with that name.'
                : 'Could not save. Please try again.';
        }
    }
}

$suppliers = [];
try {
    $suppliers = $pdo->query(
        "SELECT s.*, (SELECT COUNT(*) FROM products WHERE supplier_id = s.id) AS product_count
           FROM suppliers s ORDER BY s.name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMsg = 'The suppliers table does not exist yet — run update_new_database.php first.';
}

$pageTitle = 'Suppliers';
// Matches the tab name claimed by the menu row in includes/header.php, or the
// sidebar highlights nothing while you are standing on this page.
$activeTab = 'suppliers';
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header">
    <h1><i class="fa-solid fa-truck-field"></i> Suppliers</h1>
    <p class="admin-section-desc">
        Who you buy from. Recorded once here, then picked on each product — so a phone
        number lives in one place instead of being retyped on every piece.
    </p>
</div>

<?php if ($successMsg): ?><div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if ($errorMsg):   ?><div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

<div class="glass-panel form-section">
    <h3><i class="fa-solid fa-plus"></i> Add a supplier</h3>
    <form method="post" class="form-row">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label class="form-label">Supplier name</label>
            <input type="text" name="name" class="form-control" placeholder="e.g. Meera Textiles" required>
        </div>
        <div class="form-group">
            <label class="form-label">Owner / contact name</label>
            <input type="text" name="owner_name" class="form-control" placeholder="e.g. Ramesh Patel">
        </div>
        <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" placeholder="e.g. 98765 43210">
        </div>
        <div class="form-group form-group-actions">
            <button type="submit" class="btn-primary">Add supplier</button>
        </div>
    </form>
</div>

<div class="glass-panel form-section admin-section-mt">
    <h3><i class="fa-solid fa-list"></i> Your suppliers (<?= count($suppliers) ?>)</h3>

    <?php if (!$suppliers): ?>
        <p class="admin-section-desc">None yet. Add your first one above.</p>
    <?php else: ?>
    <?php /* .table-wrapper + .data-table is what every other admin table uses:
             the wrapper scrolls horizontally so a narrow window cannot cut the
             Delete button off the right-hand edge, which is exactly what my own
             class did. Using the established pair rather than inventing another. */ ?>
    <div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr><th>Supplier</th><th>Owner / contact</th><th>Phone</th><th>Products</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($suppliers as $s): ?>
            <tr>
                <form method="post">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <td><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($s['name']) ?>"></td>
                <td><input type="text" name="owner_name" class="form-control" value="<?= htmlspecialchars($s['owner_name'] ?? '') ?>"></td>
                <td><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($s['phone'] ?? '') ?>"></td>
                <td class="admin-cell-center"><?= (int)$s['product_count'] ?></td>
                <td class="admin-cell-actions">
                    <button type="submit" class="btn-primary btn-row">Save</button>
                </form>
                <?php /* Deletion is its own form so the row's Save cannot submit it by accident. */ ?>
                <form method="post" class="admin-form-inline"
                      <?php /* dvConfirmForm, not the browser's confirm(): the shop has its own
                               dialog and every other delete on the site uses it. The helper
                               submits via HTMLFormElement.submit(), which does not re-fire
                               onsubmit, so this cannot loop. */ ?>
                      onsubmit="return dvConfirmForm(this, 'This removes <?= htmlspecialchars($s['name'], ENT_QUOTES) ?> from your supplier list. Products already pointing at them would be left with nothing, so the delete is refused while any product still uses them.', { title: 'Delete <?= htmlspecialchars($s['name'], ENT_QUOTES) ?>?' });">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn-danger btn-row"
                            <?= (int)$s['product_count'] > 0 ? 'disabled title="Used by ' . (int)$s['product_count'] . ' product(s)"' : '' ?>>
                        Delete
                    </button>
                </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="admin-section-desc admin-note-mt">
        A supplier used by a product cannot be deleted — move those products to another
        supplier first, or the products would be left pointing at nothing.
    </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
