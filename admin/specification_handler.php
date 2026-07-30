<?php
// ============================================================
//  Dievon – Admin: Product General Specifications Handler
//  Actions: list | add | update | delete | move_up | move_down
//
//  Handles the flat "Label / Value / Unit" specification rows that
//  belong directly to a product (not to a component). Every mutating
//  query is scoped by product_id so one product's rows can never be
//  read, edited, or deleted through another product's id.
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

// ── LIST all general specifications for a product ─────────────
if ($action === 'list') {
    if ($productId <= 0) { echo json_encode(['success' => false, 'message' => 'Missing product.']); exit; }
    $stmt = $pdo->prepare("SELECT * FROM product_specifications WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $stmt->execute(['pid' => $productId]);
    echo json_encode(['success' => true, 'specifications' => $stmt->fetchAll()]);
    exit;
}

// ── ADD a specification ─────────────────────────────────────
if ($action === 'add') {
    $label = trim($_POST['label'] ?? '');
    $value = trim($_POST['value'] ?? '');
    $unit  = trim($_POST['unit']  ?? '');

    if ($productId <= 0) { echo json_encode(['success' => false, 'message' => 'Missing product.']); exit; }
    if ($label === '' || $value === '') { echo json_encode(['success' => false, 'message' => 'Label and value are required.']); exit; }

    $check = $pdo->prepare("SELECT id FROM products WHERE id = :pid");
    $check->execute(['pid' => $productId]);
    if (!$check->fetch()) { echo json_encode(['success' => false, 'message' => 'Product not found.']); exit; }

    try {
        $pdo->beginTransaction();
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM product_specifications WHERE product_id = :pid");
        $maxStmt->execute(['pid' => $productId]);
        $maxOrder = (int)$maxStmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO product_specifications (product_id, label, value, unit, sort_order) VALUES (:pid, :label, :value, :unit, :sort)");
        $stmt->execute([
            'pid' => $productId, 'label' => $label, 'value' => $value,
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

// ── UPDATE a specification ──────────────────────────────────
if ($action === 'update') {
    $id    = (int)($_POST['id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $value = trim($_POST['value'] ?? '');
    $unit  = trim($_POST['unit']  ?? '');

    if ($id <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }
    if ($label === '' || $value === '') { echo json_encode(['success' => false, 'message' => 'Label and value are required.']); exit; }

    $stmt = $pdo->prepare("UPDATE product_specifications SET label = :label, value = :value, unit = :unit WHERE id = :id AND product_id = :pid");
    $stmt->execute([
        'label' => $label, 'value' => $value, 'unit' => ($unit !== '' ? $unit : null),
        'id' => $id, 'pid' => $productId,
    ]);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount() > 0]);
    exit;
}

// ── DELETE a specification ──────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    $stmt = $pdo->prepare("DELETE FROM product_specifications WHERE id = :id AND product_id = :pid");
    $stmt->execute(['id' => $id, 'pid' => $productId]);

    echo json_encode(['success' => true]);
    exit;
}

// ── MOVE a specification up or down (swap sort_order with its neighbour) ──
if ($action === 'move_up' || $action === 'move_down') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

    try {
        $pdo->beginTransaction();

        $rows = $pdo->prepare("SELECT id, sort_order FROM product_specifications WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['pid' => $productId]);
        $list = $rows->fetchAll();

        $idx = null;
        foreach ($list as $i => $row) { if ((int)$row['id'] === $id) { $idx = $i; break; } }

        if ($idx === null) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Specification not found.']);
            exit;
        }

        $swapWith = $action === 'move_up' ? $idx - 1 : $idx + 1;
        if ($swapWith < 0 || $swapWith >= count($list)) {
            $pdo->rollBack();
            echo json_encode(['success' => true]); // already at the edge — nothing to do
            exit;
        }

        $a = $list[$idx]; $b = $list[$swapWith];
        $upd = $pdo->prepare("UPDATE product_specifications SET sort_order = :sort WHERE id = :id AND product_id = :pid");
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

echo json_encode(['success' => false, 'message' => 'Unknown action']);
