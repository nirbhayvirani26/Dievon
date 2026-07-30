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
    $stmt = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (:name, :order)");
    if ($stmt->execute(['name' => $name, 'order' => $maxOrder + 1])) {
        echo json_encode([
            'success' => true,
            'id' => $pdo->lastInsertId(),
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
    $pdo->prepare("UPDATE categories SET name=:name WHERE id=:id")->execute(['name' => $name, 'id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }

    // Check if any products use this category name
    $catName = $pdo->prepare("SELECT name FROM categories WHERE id = :id");
    $catName->execute(['id' => $id]);
    $cat = $catName->fetch();
    if (!$cat) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

    $count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category = :cat");
    $count->execute(['cat' => $cat['name']]);
    if ($count->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete: products are using this category. Reassign them first.']);
        exit;
    }

    $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute(['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
