<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// Check admin login
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$activeTab = 'returns';
$pageTitle = "RMA Returns & Exchanges";
require_once __DIR__ . '/includes/header.php';

// Handle status updates
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $returnId = (int)$_POST['return_id'];
    $newStatus = $_POST['status'];

    try {
        $stmt = $pdo->prepare("UPDATE customer_returns SET status = :st WHERE id = :id");
        $stmt->execute(['st' => $newStatus, 'id' => $returnId]);
        $msg = "RMA Status updated to " . htmlspecialchars($newStatus) . " successfully.";
    } catch (PDOException $e) {
        $msg = "Error updating RMA status.";
    }
}

// Fetch all returns
$returns = [];
try {
    $returns = $pdo->query("SELECT r.*, c.name as customer_name, c.email as customer_email, o.order_code FROM customer_returns r JOIN customers c ON r.customer_id = c.id JOIN orders o ON r.order_id = o.id ORDER BY r.id DESC")->fetchAll();
} catch (PDOException $e) {}
?>

<div class="admin-header">
    <h1><i class="fa-solid fa-rotate-left"></i> RMA Returns &amp; Size Exchanges</h1>
    <p>Review customer return requests, inspect uploaded photos, and update RMA status lifecycle.</p>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom: 20px; background: #e6faf7; color: #2e7d32; padding: 12px; border-radius: 4px; border: 1px solid #c3e6cb;">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<div class="card" style="background: var(--bg-surface); padding: 25px; border-radius: 6px; border: 1px solid var(--border-light);">
    <?php if (empty($returns)): ?>
        <p style="color: var(--text-muted);">No customer RMA return requests submitted yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-strong); text-align: left;">
                        <th style="padding: 10px;">RMA Code</th>
                        <th style="padding: 10px;">Order Code</th>
                        <th style="padding: 10px;">Customer</th>
                        <th style="padding: 10px;">Type</th>
                        <th style="padding: 10px;">Reason</th>
                        <th style="padding: 10px;">Exch. Size</th>
                        <th style="padding: 10px;">Photo</th>
                        <th style="padding: 10px;">Status</th>
                        <th style="padding: 10px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($returns as $r): ?>
                        <tr style="border-bottom: 1px solid var(--border-light);">
                            <td style="padding: 12px 10px; font-weight: 700; color: var(--color-primary);"><?= htmlspecialchars($r['return_code']) ?></td>
                            <td style="padding: 12px 10px;"><?= htmlspecialchars($r['order_code']) ?></td>
                            <td style="padding: 12px 10px;">
                                <strong><?= htmlspecialchars($r['customer_name']) ?></strong><br>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($r['customer_email']) ?></span>
                            </td>
                            <td style="padding: 12px 10px; font-weight: 600;"><?= strtoupper(htmlspecialchars($r['request_type'])) ?></td>
                            <td style="padding: 12px 10px;"><?= htmlspecialchars($r['reason']) ?></td>
                            <td style="padding: 12px 10px;"><?= htmlspecialchars($r['exchange_size'] ?: 'N/A') ?></td>
                            <td style="padding: 12px 10px;">
                                <?php if (!empty($r['photo_path'])): ?>
                                    <a href="../uploads/returns/<?= htmlspecialchars($r['photo_path']) ?>" target="_blank" style="color: var(--color-primary); font-weight: 700; text-decoration: underline;">View Photo 📷</a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 10px;">
                                <span style="font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 3px; background: #fff8e1; color: #b7791f;">
                                    <?= htmlspecialchars($r['status']) ?>
                                </span>
                            </td>
                            <td style="padding: 12px 10px;">
                                <form action="returns.php" method="POST" style="display: flex; gap: 6px;">
                                    <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
                                    <select name="status" style="font-size: 11px; padding: 4px;">
                                        <option value="Pending" <?= $r['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Approved" <?= $r['status'] === 'Approved' ? 'selected' : '' ?>>Approved</option>
                                        <option value="Rejected" <?= $r['status'] === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                                        <option value="Pickup Scheduled" <?= $r['status'] === 'Pickup Scheduled' ? 'selected' : '' ?>>Pickup Scheduled</option>
                                        <option value="Quality Check" <?= $r['status'] === 'Quality Check' ? 'selected' : '' ?>>Quality Check</option>
                                        <option value="Completed" <?= $r['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                    </select>
                                    <button type="submit" name="update_status" class="btn btn-sm btn-primary" style="font-size: 10px; padding: 4px 8px;">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
