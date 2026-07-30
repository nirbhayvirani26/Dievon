<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$activeTab = 'support_tickets';
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ticket_status') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security validation failed (Invalid CSRF token).";
    } else {
        $id = (int)($_POST['ticket_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $allowed = ['Open', 'In Progress', 'Resolved', 'Closed'];
        if (in_array($status, $allowed)) {
            $pdo->prepare("UPDATE customer_tickets SET status = :status WHERE id = :id")->execute(['status' => $status, 'id' => $id]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'update_ticket_status', "Ticket ID $id set to $status");
            $successMsg = "Ticket status updated.";
        } else {
            $errorMsg = "Invalid status.";
        }
    }
}

$tickets = [];
try {
    $tickets = $pdo->query("
        SELECT t.*, c.name AS customer_name, c.email AS customer_email, o.order_code
        FROM customer_tickets t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN orders o ON t.order_id = o.id
        ORDER BY (t.status = 'Open') DESC, t.id DESC
    ")->fetchAll();
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-panel" style="padding: 24px;">
    <?php if (empty($tickets)): ?>
        <div style="text-align:center; padding:40px; color:var(--text-muted);">No support tickets submitted yet.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="data-table" style="width:100%;">
        <thead>
            <tr>
                <th>Ticket</th>
                <th>Customer</th>
                <th>Order</th>
                <th>Subject &amp; Message</th>
                <th>Status</th>
                <th style="text-align:right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tickets as $t):
                $badgeBg = match($t['status']) {
                    'Open' => 'rgba(239,68,68,0.12)', 'In Progress' => 'rgba(245,158,11,0.12)',
                    'Resolved' => 'rgba(16,185,129,0.12)', default => 'rgba(148,163,184,0.15)',
                };
                $badgeColor = match($t['status']) {
                    'Open' => '#ef4444', 'In Progress' => '#f59e0b',
                    'Resolved' => '#10b981', default => '#64748b',
                };
            ?>
            <tr>
                <td style="color:var(--text-muted);">#<?= (int)$t['id'] ?><br><small><?= htmlspecialchars($t['ticket_code']) ?></small></td>
                <td><?= htmlspecialchars($t['customer_name'] ?? 'Unknown') ?><br><small style="color:var(--text-muted);"><?= htmlspecialchars($t['customer_email'] ?? '') ?></small></td>
                <td><?= $t['order_code'] ? htmlspecialchars($t['order_code']) : '—' ?></td>
                <td style="max-width:320px;">
                    <strong style="display:block;"><?= htmlspecialchars($t['subject']) ?></strong>
                    <span style="font-size:12px; color:var(--text-secondary);"><?= htmlspecialchars($t['message']) ?></span>
                </td>
                <td><span style="background:<?= $badgeBg ?>; color:<?= $badgeColor ?>; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:700;"><?= htmlspecialchars($t['status']) ?></span></td>
                <td style="text-align:right;">
                    <form method="POST" style="margin:0; display:flex; gap:6px; justify-content:flex-end;">
                        <input type="hidden" name="action" value="update_ticket_status">
                        <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <select name="status" class="form-control" style="font-size:12px; padding:4px 8px; height:auto;">
                            <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-sm btn-sm-primary"><i class="fa-solid fa-check"></i></button>
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
