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

/* Store the gallery order the owner just arranged.
   ────────────────────────────────────────────────────────────────────────────
   sort_order already existed and was already what pages/product.php orders by —
   it was simply never written, so every row sat at 0 and the gallery fell back
   to id order, which is upload order. That is why a photo uploaded last could
   never be moved to the front.

   Only rows belonging to ONE product are touched, and that product is taken
   from the FIRST id given rather than from anything the browser claims. A
   request naming ids from two products writes the ones that match and ignores
   the rest, so this cannot be used to renumber another product's gallery.

   One transaction: a half-applied order is worse than none, because the page
   would then show an arrangement the owner never chose. */
if ($action === 'reorder') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
    if (!$ids) {
        echo json_encode(['success' => false, 'message' => 'no images given']);
        exit;
    }
    try {
        $own = $pdo->prepare("SELECT product_id FROM product_images WHERE id = :id");
        $own->execute([':id' => $ids[0]]);
        $pid = (int)$own->fetchColumn();
        if ($pid <= 0) {
            echo json_encode(['success' => false, 'message' => 'those images no longer exist']);
            exit;
        }

        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE product_images SET sort_order = :pos WHERE id = :id AND product_id = :pid");
        $n = 0;
        foreach ($ids as $pos => $imgId) {
            $upd->execute([':pos' => $pos + 1, ':id' => $imgId, ':pid' => $pid]);
            $n += $upd->rowCount();
        }
        $pdo->commit();

        logAdminAction($_SESSION['admin_id'] ?? 1, 'reorder_gallery',
            "Reordered $n gallery image(s) for product $pid");
        echo json_encode(['success' => true, 'reordered' => $n]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Gallery reorder failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'could not save the order']);
    }
    exit;
}

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
