<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// This page had no capability check of any kind.
//
// Being signed in as any staff account was enough to open review moderation and
// run "DELETE FROM product_reviews" — a customer's review gone for good, from an
// account with no catalogue permission. The CSRF token was not a barrier either:
// one token is issued per session, so anyone who can open any admin page already
// holds a valid one for this page.
//
// Placed before the POST handler below, not after it, which is the mistake the
// neighbouring screens made.
requireAdminCapability('catalogue.manage');

$activeTab = 'reviews';
$successMsg = '';
$errorMsg   = '';

// Ensure product_reviews table exists and has the columns this page needs
// (mirrors the auto-migration in actions/review_action.php).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_reviews` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `customer_id` INT DEFAULT NULL,
        `author_name` VARCHAR(150) NOT NULL DEFAULT '',
        `rating` INT DEFAULT 5,
        `title` VARCHAR(255) DEFAULT NULL,
        `review_text` TEXT NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cols = $pdo->query("SHOW COLUMNS FROM product_reviews")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('customer_id', $cols)) $pdo->exec("ALTER TABLE product_reviews ADD COLUMN customer_id INT DEFAULT NULL");
    if (!in_array('author_name', $cols)) $pdo->exec("ALTER TABLE product_reviews ADD COLUMN author_name VARCHAR(150) NOT NULL DEFAULT ''");
    if (!in_array('title', $cols))       $pdo->exec("ALTER TABLE product_reviews ADD COLUMN title VARCHAR(255) DEFAULT NULL");
    if (!in_array('status', $cols))      $pdo->exec("ALTER TABLE product_reviews ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
    if (in_array('reviewer_name', $cols)) {
        try { $pdo->exec("ALTER TABLE product_reviews MODIFY reviewer_name VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
    }
} catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed (Invalid CSRF token).";
    } else {
        $id = (int)($_POST['review_id'] ?? 0);
        if ($_POST['action'] === 'approve_review') {
            $pdo->prepare("UPDATE product_reviews SET status = 'Approved' WHERE id = :id")->execute(['id' => $id]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'approve_review', "Approved review ID $id");
            $successMsg = "Review approved successfully.";
        } elseif ($_POST['action'] === 'delete_review') {
            $pdo->prepare("DELETE FROM product_reviews WHERE id = :id")->execute(['id' => $id]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_review', "Deleted review ID $id");
            $successMsg = "Review deleted successfully.";
        }
    }
}

$reviews = [];
try {
    $reviews = $pdo->query("
        SELECT pr.*, p.name AS product_name
        FROM product_reviews pr
        LEFT JOIN products p ON pr.product_id = p.id
        ORDER BY (pr.status = 'Pending') DESC, pr.id DESC
    ")->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
    <div class="table-wrapper">
    <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: left; min-width: 900px;">
        <thead style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
            <tr>
                <th style="padding: 12px 16px;">ID</th>
                <th style="padding: 12px 16px;">Product</th>
                <th style="padding: 12px 16px;">Customer</th>
                <th style="padding: 12px 16px;">Rating</th>
                <th style="padding: 12px 16px;">Review</th>
                <th style="padding: 12px 16px;">Status</th>
                <th style="padding: 12px 16px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($reviews)): ?>
                <tr><td colspan="7" style="padding: 20px; text-align: center; color: #6b7280;">No customer reviews submitted yet.</td></tr>
            <?php else: ?>
                <?php foreach ($reviews as $r):
                    $status = $r['status'] ?: 'Pending';
                    $badgeBg = $status === 'Approved' ? '#ecfdf5' : '#fff8e1';
                    $badgeColor = $status === 'Approved' ? '#065f46' : '#b7791f';
                ?>
                    <tr style="border-bottom: 1px solid #f3f4f6; vertical-align: top;">
                        <td style="padding: 14px 16px; color: #9ca3af;">#<?= (int)$r['id'] ?></td>
                        <td style="padding: 14px 16px; color: #111827;"><?= htmlspecialchars($r['product_name'] ?? 'Unknown Product') ?></td>
                        <td style="padding: 14px 16px; font-weight: 600; color: #111827;"><?= htmlspecialchars($r['author_name']) ?></td>
                        <td style="padding: 14px 16px; color: #f59e0b; white-space: nowrap;"><?= str_repeat('★', (int)$r['rating']) ?></td>
                        <td style="padding: 14px 16px; color: #4b5563; max-width: 320px;">
                            <strong style="display:block; color:#111827;"><?= htmlspecialchars($r['title'] ?? '') ?></strong>
                            <?= htmlspecialchars($r['review_text']) ?>
                        </td>
                        <td style="padding: 14px 16px;"><span style="background: <?= $badgeBg ?>; color: <?= $badgeColor ?>; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;"><?= htmlspecialchars($status) ?></span></td>
                        <td style="padding: 14px 16px;">
                            <div style="display: flex; gap: 10px;">
                                <?php if ($status !== 'Approved'): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="approve_review">
                                    <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <button type="submit" style="background:none; border:none; padding:0; color: #10b981; font-weight: 600; cursor: pointer;">Approve</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" style="margin:0;" onsubmit="return dvConfirmForm(this,'Delete this review?');">
                                    <input type="hidden" name="action" value="delete_review">
                                    <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <button type="submit" style="background:none; border:none; padding:0; color: #ef4444; font-weight: 600; cursor: pointer;">Delete</button>
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
