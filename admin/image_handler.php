<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('media.manage', true);


header('Content-Type: application/json');

// This endpoint destroys a file, so it needs the same CSRF protection the
// product handlers have. It had none: a page on another site could make a
// logged-in admin's browser delete arbitrary gallery images.
if (!function_exists('verifyCsrfToken') || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security check failed — reload the page and try again.']);
    exit;
}

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if ($action === 'delete' && $id > 0) {
    try {
        // Fetch image filename to delete from disk
        $stmt = $pdo->prepare("SELECT image FROM product_images WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $img = $stmt->fetchColumn();   // false, not null, when the row is gone

        if ($img) {
            // deleteUploadedFile() keeps the target inside uploads/products and
            // removes the same-name .webp too. This used to unlink only the
            // original, stranding the twin with nothing in the DB naming it.
            $destDir = __DIR__ . '/../uploads/products/';
            if (!deleteUploadedFile((string)$img, $destDir)) {
                // Stop rather than drop the row anyway. Previously the DELETE ran
                // regardless, so a permissions problem silently orphaned the file
                // and no one ever found out.
                echo json_encode(['success' => false,
                    'message' => 'Could not remove the image file from the server — the record was kept so nothing is lost. Check folder permissions.']);
                exit;
            }
        }

        $stmtDel = $pdo->prepare("DELETE FROM product_images WHERE id = :id");
        $stmtDel->execute(['id' => $id]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log('image_handler delete failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not delete this image.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
