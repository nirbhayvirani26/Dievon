<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if ($action === 'delete' && $id > 0) {
    try {
        // Fetch image filename to delete from disk
        $stmt = $pdo->prepare("SELECT image FROM product_images WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $img = $stmt->fetchColumn();

        if ($img) {
            $destDir = __DIR__ . '/../uploads/products/';
            if (file_exists($destDir . $img)) {
                unlink($destDir . $img);
            }
        }

        $stmtDel = $pdo->prepare("DELETE FROM product_images WHERE id = :id");
        $stmtDel->execute(['id' => $id]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
