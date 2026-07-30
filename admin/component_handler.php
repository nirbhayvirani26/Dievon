<?php
// ============================================================
//  Dievon – Admin: Product Components & Component Specifications Handler
//  Component actions:      list | add_component | update_component |
//                           delete_component | move_component_up | move_component_down
//  Component-spec actions: add_spec | update_spec | delete_spec |
//                           move_spec_up | move_spec_down
//
//  A component (Kurti, Palazzo, Dupatta, ...) belongs to exactly one
//  product, and each specification inside it belongs to exactly one
//  component AND carries the same product_id. Every mutating query is
//  scoped by product_id (and component_id where relevant) so rows can
//  never be read, edited, or deleted through another product's id.
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';

header('Content-Type: application/json');

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

// CSRF check for every mutating action (list is read-only, safe to skip)
if ($action !== 'list') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
        exit;
    }
}

// Confirms a component belongs to the given product; returns false otherwise.
function dievon_component_belongs_to_product(PDO $pdo, int $componentId, int $productId): bool {
    $stmt = $pdo->prepare("SELECT id FROM product_components WHERE id = :id AND product_id = :pid");
    $stmt->execute(['id' => $componentId, 'pid' => $productId]);
    return (bool)$stmt->fetch();
}

// ── LIST all components (+ their specs) for a product ──────────
if ($action === 'list') {
    if ($productId <= 0) { echo json_encode(['success' => false, 'message' => 'Missing product.']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM product_components WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $stmt->execute(['pid' => $productId]);
    $components = $stmt->fetchAll();

    foreach ($components as &$comp) {
        $specStmt = $pdo->prepare("SELECT * FROM product_component_specifications WHERE component_id = :cid ORDER BY sort_order ASC, id ASC");
        $specStmt->execute(['cid' => $comp['id']]);
        $comp['specs'] = $specStmt->fetchAll();
    }
    unset($comp);

    echo json_encode(['success' => true, 'components' => $components]);
    exit;
}

// ── ADD a component ─────────────────────────────────────────
if ($action === 'add_component') {
    $name = trim($_POST['name'] ?? '');
    if ($productId <= 0) { echo json_encode(['success' => false, 'message' => 'Missing product.']); exit; }
    if ($name === '') { echo json_encode(['success' => false, 'message' => 'Component name is required.']); exit; }

    $check = $pdo->prepare("SELECT id FROM products WHERE id = :pid");
    $check->execute(['pid' => $productId]);
    if (!$check->fetch()) { echo json_encode(['success' => false, 'message' => 'Product not found.']); exit; }

    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM product_components WHERE product_id = :pid");
    $maxStmt->execute(['pid' => $productId]);
    $maxOrder = (int)$maxStmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO product_components (product_id, name, sort_order) VALUES (:pid, :name, :sort)");
    $stmt->execute(['pid' => $productId, 'name' => $name, 'sort' => $maxOrder + 1]);

    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name]);
    exit;
}

// ── UPDATE a component's name ───────────────────────────────
if ($action === 'update_component') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }
    if ($name === '') { echo json_encode(['success' => false, 'message' => 'Component name is required.']); exit; }

    $stmt = $pdo->prepare("UPDATE product_components SET name = :name WHERE id = :id AND product_id = :pid");
    $stmt->execute(['name' => $name, 'id' => $id, 'pid' => $productId]);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount() > 0]);
    exit;
}

// ── DELETE a component (cascades its specifications) ───────────
if ($action === 'delete_component') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    if (!dievon_component_belongs_to_product($pdo, $id, $productId)) {
        echo json_encode(['success' => false, 'message' => 'Component not found.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM product_component_specifications WHERE component_id = :cid AND product_id = :pid")
            ->execute(['cid' => $id, 'pid' => $productId]);
        $pdo->prepare("DELETE FROM product_components WHERE id = :id AND product_id = :pid")
            ->execute(['id' => $id, 'pid' => $productId]);
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error while deleting component.']);
    }
    exit;
}

// ── MOVE a component up or down ─────────────────────────────
if ($action === 'move_component_up' || $action === 'move_component_down') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    try {
        $pdo->beginTransaction();

        $rows = $pdo->prepare("SELECT id, sort_order FROM product_components WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['pid' => $productId]);
        $list = $rows->fetchAll();

        $idx = null;
        foreach ($list as $i => $row) { if ((int)$row['id'] === $id) { $idx = $i; break; } }

        if ($idx === null) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Component not found.']);
            exit;
        }

        $swapWith = $action === 'move_component_up' ? $idx - 1 : $idx + 1;
        if ($swapWith < 0 || $swapWith >= count($list)) {
            $pdo->rollBack();
            echo json_encode(['success' => true]);
            exit;
        }

        $a = $list[$idx]; $b = $list[$swapWith];
        $upd = $pdo->prepare("UPDATE product_components SET sort_order = :sort WHERE id = :id AND product_id = :pid");
        $upd->execute(['sort' => $b['sort_order'], 'id' => $a['id'], 'pid' => $productId]);
        $upd->execute(['sort' => $a['sort_order'], 'id' => $b['id'], 'pid' => $productId]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error while reordering.']);
    }
    exit;
}

// ── ADD a specification inside a component ──────────────────
if ($action === 'add_spec') {
    $componentId = (int)($_POST['component_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $value = trim($_POST['value'] ?? '');
    $unit  = trim($_POST['unit']  ?? '');

    if ($componentId <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }
    if ($label === '' || $value === '') { echo json_encode(['success' => false, 'message' => 'Label and value are required.']); exit; }
    if (!dievon_component_belongs_to_product($pdo, $componentId, $productId)) {
        echo json_encode(['success' => false, 'message' => 'Component not found.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM product_component_specifications WHERE component_id = :cid");
        $maxStmt->execute(['cid' => $componentId]);
        $maxOrder = (int)$maxStmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO product_component_specifications (component_id, product_id, label, value, unit, sort_order) VALUES (:cid, :pid, :label, :value, :unit, :sort)");
        $stmt->execute([
            'cid' => $componentId, 'pid' => $productId, 'label' => $label, 'value' => $value,
            'unit' => ($unit !== '' ? $unit : null), 'sort' => $maxOrder + 1,
        ]);
        $id = $pdo->lastInsertId();
        $pdo->commit();

        echo json_encode(['success' => true, 'id' => $id, 'label' => $label, 'value' => $value, 'unit' => $unit]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error while adding specification.']);
    }
    exit;
}

// ── UPDATE a specification inside a component ────────────────
if ($action === 'update_spec') {
    $id          = (int)($_POST['id'] ?? 0);
    $componentId = (int)($_POST['component_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $value = trim($_POST['value'] ?? '');
    $unit  = trim($_POST['unit']  ?? '');

    if ($id <= 0 || $componentId <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }
    if ($label === '' || $value === '') { echo json_encode(['success' => false, 'message' => 'Label and value are required.']); exit; }

    $stmt = $pdo->prepare("UPDATE product_component_specifications SET label = :label, value = :value, unit = :unit
        WHERE id = :id AND component_id = :cid AND product_id = :pid");
    $stmt->execute([
        'label' => $label, 'value' => $value, 'unit' => ($unit !== '' ? $unit : null),
        'id' => $id, 'cid' => $componentId, 'pid' => $productId,
    ]);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount() > 0]);
    exit;
}

// ── DELETE a specification inside a component ────────────────
if ($action === 'delete_spec') {
    $id          = (int)($_POST['id'] ?? 0);
    $componentId = (int)($_POST['component_id'] ?? 0);
    if ($id <= 0 || $componentId <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    $stmt = $pdo->prepare("DELETE FROM product_component_specifications WHERE id = :id AND component_id = :cid AND product_id = :pid");
    $stmt->execute(['id' => $id, 'cid' => $componentId, 'pid' => $productId]);

    echo json_encode(['success' => true]);
    exit;
}

// ── MOVE a component's specification up or down ──────────────
if ($action === 'move_spec_up' || $action === 'move_spec_down') {
    $id          = (int)($_POST['id'] ?? 0);
    $componentId = (int)($_POST['component_id'] ?? 0);
    if ($id <= 0 || $componentId <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    try {
        $pdo->beginTransaction();

        $rows = $pdo->prepare("SELECT id, sort_order FROM product_component_specifications WHERE component_id = :cid AND product_id = :pid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['cid' => $componentId, 'pid' => $productId]);
        $list = $rows->fetchAll();

        $idx = null;
        foreach ($list as $i => $row) { if ((int)$row['id'] === $id) { $idx = $i; break; } }

        if ($idx === null) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Specification not found.']);
            exit;
        }

        $swapWith = $action === 'move_spec_up' ? $idx - 1 : $idx + 1;
        if ($swapWith < 0 || $swapWith >= count($list)) {
            $pdo->rollBack();
            echo json_encode(['success' => true]);
            exit;
        }

        $a = $list[$idx]; $b = $list[$swapWith];
        $upd = $pdo->prepare("UPDATE product_component_specifications SET sort_order = :sort WHERE id = :id AND component_id = :cid AND product_id = :pid");
        $upd->execute(['sort' => $b['sort_order'], 'id' => $a['id'], 'cid' => $componentId, 'pid' => $productId]);
        $upd->execute(['sort' => $a['sort_order'], 'id' => $b['id'], 'cid' => $componentId, 'pid' => $productId]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error while reordering.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
