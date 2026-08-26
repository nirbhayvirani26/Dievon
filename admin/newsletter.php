<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Moved ABOVE the POST handlers below.
// It used to sit after them, and after the page had already rendered — so a
// staff account without this permission ran the INSERT/DELETE first and was
// only shown the 403 afterwards. The record was already gone by then.
requireAdminCapability('settings.manage');

$activeTab = 'newsletter';
$successMsg = '';
$errorMsg   = '';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `email` VARCHAR(191) NOT NULL UNIQUE,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_subscriber') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed (Invalid CSRF token).";
    } else {
        $id = (int)($_POST['subscriber_id'] ?? 0);
        $pdo->prepare("DELETE FROM newsletter_subscribers WHERE id = :id")->execute(['id' => $id]);
        logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_newsletter_subscriber', "Deleted subscriber ID $id");
        $successMsg = "Subscriber removed successfully.";
    }
}

$subscribers = [];
try {
    $subscribers = $pdo->query("SELECT * FROM newsletter_subscribers ORDER BY created_at DESC")->fetchAll();
} catch (PDOException $e) {}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="newsletter_subscribers_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Email', 'Subscribed On'], ',', '"', '');
    foreach ($subscribers as $s) {
        fputcsv($fp, [$s['email'], $s['created_at']], ',', '"', '');
    }
    fclose($fp);
    exit;
}

require_once __DIR__ . '/includes/header.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';

?>

<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 18px;">
        <strong style="font-size: 14px;"><?= count($subscribers) ?> Total Subscribers</strong>
        <?php if (!empty($subscribers)): ?>
        <a href="?export=csv" class="btn-sm btn-sm-outline"><i class="fa-solid fa-download"></i> Export CSV</a>
        <?php endif; ?>
    </div>

    <?php if (empty($subscribers)): ?>
        <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 14px;">
            No newsletter subscribers yet.
        </div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="data-table" style="width:100%;">
        <thead>
            <tr>
                <th>Email</th>
                <th>Subscribed On</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($subscribers as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['email']) ?></td>
                <td><?= date('d M Y, H:i', strtotime($s['created_at'])) ?></td>
                <td style="text-align:right;">
                    <div class="admin-actions">
                        <form method="POST" onsubmit="return dvConfirmForm(this,'Remove this subscriber?');">
                            <input type="hidden" name="action" value="delete_subscriber">
                            <input type="hidden" name="subscriber_id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <button type="submit" class="admin-action-btn is-danger" title="Remove subscriber" aria-label="Remove subscriber <?= htmlspecialchars($s['email'] ?? '') ?>"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
