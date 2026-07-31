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
        $reply  = trim($_POST['admin_reply'] ?? '');
        $allowed = ['Open', 'In Progress', 'Resolved', 'Closed'];
        if (in_array($status, $allowed)) {
            // Read the ticket first so we can tell what actually changed — the
            // customer should only be emailed about a real change, not every save.
            $tStmt = $pdo->prepare("
                SELECT t.*, c.name AS customer_name, c.email AS customer_email
                FROM customer_tickets t
                LEFT JOIN customers c ON t.customer_id = c.id
                WHERE t.id = :id LIMIT 1");
            $tStmt->execute(['id' => $id]);
            $ticket = $tStmt->fetch();

            $statusChanged = $ticket && $ticket['status'] !== $status;
            $isNewReply    = $reply !== '' && (($ticket['admin_reply'] ?? '') !== $reply);

            if ($isNewReply) {
                $pdo->prepare("UPDATE customer_tickets SET status = :status, admin_reply = :reply, replied_at = NOW() WHERE id = :id")
                    ->execute(['status' => $status, 'reply' => $reply, 'id' => $id]);
            } else {
                $pdo->prepare("UPDATE customer_tickets SET status = :status WHERE id = :id")
                    ->execute(['status' => $status, 'id' => $id]);
            }

            logAdminAction($_SESSION['admin_id'] ?? 1, 'update_ticket_status',
                "Ticket ID $id set to $status" . ($isNewReply ? ' (advisor replied)' : ''));

            $notified = [];
            if ($ticket && !empty($ticket['customer_email'])) {
                // Never let an SMTP problem surface as a failed status update —
                // the change is already committed at this point.
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $svc = getEmailService();
                    $name = $ticket['customer_name'] ?? 'Customer';
                    if ($isNewReply) {
                        $svc->sendTicketReplyEmail($ticket, $name, $ticket['customer_email'], $reply);
                        $notified[] = 'reply';
                    }
                    if ($statusChanged) {
                        $svc->sendTicketStatusEmail($ticket, $name, $ticket['customer_email'], $status);
                        $notified[] = 'status update';
                    }
                } catch (\Throwable $e) {
                    error_log('Ticket notification failed for ticket ' . $id . ': ' . $e->getMessage());
                    $errorMsg = "Ticket saved, but the notification email could not be sent.";
                }
            }

            if (!$errorMsg) {
                $successMsg = "Ticket updated."
                    . ($notified ? ' Customer emailed (' . implode(' + ', $notified) . ').' : ' No email sent (nothing changed).');
            }
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
                // Status colours now live in CSS as .ticket-status-badge--<status>
                // rather than being computed here and echoed into a style attribute.
            ?>
            <tr>
                <td style="color:var(--text-muted);">#<?= (int)$t['id'] ?><br><small><?= htmlspecialchars($t['ticket_code']) ?></small></td>
                <td><?= htmlspecialchars($t['customer_name'] ?? 'Unknown') ?><br><small style="color:var(--text-muted);"><?= htmlspecialchars($t['customer_email'] ?? '') ?></small></td>
                <td><?= $t['order_code'] ? htmlspecialchars($t['order_code']) : '—' ?></td>
                <td style="max-width:320px;">
                    <strong style="display:block;"><?= htmlspecialchars($t['subject']) ?></strong>
                    <span style="font-size:12px; color:var(--text-secondary);"><?= htmlspecialchars($t['message']) ?></span>
                    <?php if (!empty($t['attachment'])): ?>
                        <a href="<?= SITE_URL ?>/uploads/tickets/<?= htmlspecialchars($t['attachment']) ?>" target="_blank" rel="noopener"
                           title="Open full size photo">
                            <img src="<?= SITE_URL ?>/uploads/tickets/<?= htmlspecialchars($t['attachment']) ?>"
                                 alt="Customer photo for ticket <?= htmlspecialchars($t['ticket_code']) ?>"
                                 class="ticket-photo-thumb">
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($t['admin_reply'])): ?>
                        <div class="ticket-admin-reply">
                            <strong class="ticket-admin-reply-label">
                                Replied<?= !empty($t['replied_at']) ? ' · ' . date('j M Y', strtotime($t['replied_at'])) : '' ?>
                            </strong><br>
                            <?= nl2br(htmlspecialchars($t['admin_reply'])) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><span class="ticket-status-badge ticket-status-badge--<?= strtolower(str_replace(' ', '-', $t['status'])) ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                <td class="ticket-action-cell">
                    <form method="POST" class="ticket-reply-form">
                        <input type="hidden" name="action" value="update_ticket_status">
                        <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <textarea name="admin_reply" class="form-control ticket-reply-textarea" rows="3"
                                  placeholder="Write a reply — the customer is emailed it…"><?= htmlspecialchars($t['admin_reply'] ?? '') ?></textarea>
                        <div class="ticket-reply-controls">
                            <select name="status" class="form-control ticket-status-select">
                                <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $s): ?>
                                <option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-sm btn-sm-primary" title="Save and email the customer">
                                <i class="fa-solid fa-paper-plane"></i> Send
                            </button>
                        </div>
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
