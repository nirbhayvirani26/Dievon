<?php
/* The product form's concurrent-edit stamp.
 * ─────────────────────────────────────────────────────────────────────────────
 * product_form.php refuses a save when products.updated_at has moved since the
 * form was drawn, so that two people editing the same garment cannot silently
 * overwrite each other.
 *
 * The trouble is that the form ITSELF moves that value. Sizes, colours,
 * specifications, components and gallery photographs all save on their own while
 * the page sits open, and several of those write to the products row — a colour
 * save runs syncProductCoverImage(), the stock screen adjusts total_stock, and
 * updated_at carries ON UPDATE CURRENT_TIMESTAMP, so the row's stamp moves. The
 * guard then compared the stamp captured when the page was DRAWN against one the
 * page had just moved itself, and told the owner someone else had been editing.
 * The first Save was refused in full, and every field on the details form —
 * Track Stock most visibly — snapped back to what it had been.
 *
 * So this hands the current stamp back, and the open form keeps its copy in
 * step with its own saves. Read-only: it takes no action and changes nothing,
 * which is why it needs no CSRF token.
 */
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
requireAdminCapability('catalogue.manage', true);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'No product given']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT updated_at FROM products WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $at = $stmt->fetchColumn();
    if ($at === false) {
        echo json_encode(['success' => false, 'message' => 'That product no longer exists']);
        exit;
    }
    echo json_encode(['success' => true, 'updated_at' => (string)$at]);
} catch (PDOException $e) {
    error_log('product_stamp lookup failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not read the product']);
}
