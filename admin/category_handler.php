<?php
// ============================================================
//  Dievon – Admin: Category Handler
//  Actions: add | rename | delete
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('catalogue.manage', true);


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method. POST required.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
    exit;
}

$action = $_POST['action'] ?? '';
logAdminAction($_SESSION['admin_id'] ?? 1, 'category_action_' . $action, "Action: $action");

// ── ADD ───────────────────────────────────────────────────────
if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    if (strlen($name) < 2) { echo json_encode(['success' => false, 'message' => 'Name too short.']); exit; }

    $maxOrder = $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM categories")->fetchColumn();
    /* The slug is set HERE, at creation, and not left for later.
       ────────────────────────────────────────────────────────────────────────
       This INSERT listed only (name, sort_order), so every category added
       through the quick-add — the one on the product form, which is the fast
       path an owner actually uses — was born with an empty slug. A category
       with no slug never gets a /collections/<slug> address: categoryUrlByName()
       skips it and falls back to ?category=<name> forever, and nothing in the
       admin ever offers to fill it in. The main Categories page set a slug from
       the day it was written; only this path did not. */
    $stmt = $pdo->prepare("INSERT INTO categories (name, sort_order, slug) VALUES (:name, :order, :slug)");
    if ($stmt->execute([
        'name'  => $name,
        'order' => $maxOrder + 1,
        'slug'  => uniqueCategorySlug($pdo, $name),
    ])) {
        /* The new id is captured ONCE, before anything else writes.
           ────────────────────────────────────────────────────────────────────
           lastInsertId() answers for the most recent INSERT on the connection,
           not for the statement above it — and the size-guide helper on the next
           line performs its own INSERT. So the id returned to the caller
           described the size_guide_charts row (or 0 when that insert did not
           happen), never the category just created. The screen then held a
           category whose id was wrong, so the very next edit or delete acted on
           nothing, or on another table's row number.
           categories.php documents fixing exactly this; the quick-add handler
           kept the bug. */
        $newCategoryId = (int)$pdo->lastInsertId();

        // The quick-add has no audience field, so the chart is cloned from the
        // women's default — the same template a category with no gender gets.
        autoCreateSizeGuideChartForCategory($pdo, $newCategoryId, 'women');
        echo json_encode([
            'success' => true,
            'id' => $newCategoryId,
            'name' => $name,
            'sort_order' => $maxOrder + 1
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error.']);
    }
    exit;
}

// ── RENAME ───────────────────────────────────────────────────
if ($action === 'rename') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || strlen($name) < 2) { echo json_encode(['success' => false, 'message' => 'Invalid.']); exit; }

    /* Rename the category AND every product that names it.
       ────────────────────────────────────────────────────────────────────────
       A product stores its category twice: category_id, and `category` as plain
       text. This updated only the categories table, so renaming left every
       product still carrying the old wording — the menu said one thing, the
       products said another, and nothing reported the split.

       That is not cosmetic. shopGenderSqlFilter() matches on the NAME as well as
       the id when deciding which side of the shop a piece belongs to, and
       pages/shop.php filters by name, so a stale value quietly drops products out
       of their own category listing.

       Matched on the OLD name rather than on category_id, because rows that were
       imported or edited by hand can carry the name without the id, and those are
       exactly the ones that would be left behind. Both statements run in one
       transaction: a rename that half-applied would be worse than one that failed. */
    $prev = $pdo->prepare("SELECT name FROM categories WHERE id = :id");
    $prev->execute(['id' => $id]);
    $oldName = (string)($prev->fetchColumn() ?: '');

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE categories SET name=:name WHERE id=:id")
            ->execute(['name' => $name, 'id' => $id]);

        $touched = 0;
        if ($oldName !== '' && $oldName !== $name) {
            $st = $pdo->prepare(
                "UPDATE products SET category = :new
                  WHERE category = :old OR category_id = :id"
            );
            $st->execute(['new' => $name, 'old' => $oldName, 'id' => $id]);
            $touched = $st->rowCount();
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'products_updated' => $touched]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['success' => false, 'message' => 'Rename failed — nothing was changed.']);
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }

    $catName = $pdo->prepare("SELECT name FROM categories WHERE id = :id");
    $catName->execute(['id' => $id]);
    $cat = $catName->fetch();
    if (!$cat) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

    /* A product references its category twice — category_id AND the plain-text
       `category` — so both have to be checked, exactly as the delete on
       categories.php does. Checking only the NAME let a category that was still
       the fk target of a product (its text edited or imported without the name)
       be deleted anyway, orphaning that category_id onto a row that no longer
       exists. Matched on either so a product cannot be stranded whichever way it
       points. */
    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM products
          WHERE (category_id = :id OR category = :cat)
            AND (is_deleted = 0 OR is_deleted IS NULL)"
    );
    $count->execute(['id' => $id, 'cat' => $cat['name']]);
    if ($count->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete: products are using this category. Reassign them first.']);
        exit;
    }

    /* A parent cannot be removed out from under its children.
       ────────────────────────────────────────────────────────────────────────
       This check was missing entirely, so deleting a parent left every
       sub-category pointing at a parent_id that is now gone — the subtree is
       stranded, its own collection page 404s, and the products beneath it drop
       out of the parent's navigation with nothing on screen to explain it. The
       delete on categories.php refuses this; this endpoint, reachable by a direct
       POST, has to refuse it too. */
    $childCount = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = :id");
    $childCount->execute(['id' => $id]);
    if ((int)$childCount->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete: this category still has sub-categories. Delete or reassign those first.']);
        exit;
    }

    $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute(['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
