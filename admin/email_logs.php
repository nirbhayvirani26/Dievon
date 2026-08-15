<?php
/*
 * ═══════════════════════════════════════════════════════════════════════
 *  EMAIL LOG  (admin/email_logs.php)
 * ───────────────────────────────────────────────────────────────────────
 *  Every message the site sends is already recorded in email_logs by
 *  services/EmailService.php — type, recipient, subject, status and the
 *  error when it failed. Nothing read that table back. The only trace a
 *  failure left anywhere in the panel was the red banner on the dashboard,
 *  which shows a count and the last five, so a shop could send order
 *  confirmations into a black hole for weeks without knowing.
 *
 *  This is a reader over data that already exists: no new table, no change
 *  to how mail is sent. It answers the one question the dashboard cannot —
 *  "did THIS customer get THEIR email?"
 *
 *  Resend deliberately re-sends only the failed ones, and only one at a
 *  time. A "resend all" button on a list that might hold hundreds of
 *  addresses is a way to mail the same person twenty times.
 * ═══════════════════════════════════════════════════════════════════════
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
requireAdminCapability('settings.manage');

$activeTab = 'email_logs';

$successMsg = '';
$errorMsg   = '';

// ── Resend a single failed message ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed. Refresh the page and try again.';
    } else {
        $logId = (int)($_POST['log_id'] ?? 0);
        try {
            $st = $pdo->prepare("SELECT * FROM email_logs WHERE id = :id");
            $st->execute([':id' => $logId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $errorMsg = 'That log entry no longer exists.';
            } elseif ($row['status'] !== 'failed') {
                // Guarded rather than allowed "just in case": re-sending a message
                // that already arrived is a second copy in the customer's inbox.
                $errorMsg = 'That message was sent successfully — there is nothing to resend.';
            } else {
                require_once __DIR__ . '/../services/EmailService.php';
                $svc = new EmailService($pdo);

                // The body is not stored — email_logs keeps the envelope only —
                // so the original message cannot be reproduced. This sends a short,
                // honest follow-up instead, which is far better than a failure
                // nobody ever hears about.
                $sent = $svc->sendFailedDeliveryFollowUp(
                    (string)$row['recipient'],
                    (string)$row['subject']
                );

                if ($sent) {
                    $pdo->prepare("UPDATE email_logs SET status = 'sent', error_message = NULL WHERE id = :id")
                        ->execute([':id' => $logId]);
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'email_resend',
                        'Resent failed email #' . $logId . ' to ' . $row['recipient']);
                    $successMsg = 'Resent to ' . $row['recipient'] . '.';
                } else {
                    $errorMsg = 'Still could not send to ' . $row['recipient'] . '. Check the mail settings.';
                }
            }
        } catch (Throwable $e) {
            $errorMsg = 'Could not resend: ' . $e->getMessage();
        }
    }
}

// ── Filters ─────────────────────────────────────────────────────────────
$fStatus = in_array(($_GET['status'] ?? ''), ['sent', 'failed'], true) ? $_GET['status'] : '';
$fType   = trim((string)($_GET['type'] ?? ''));
$fSearch = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where  = [];
$params = [];
if ($fStatus !== '') { $where[] = 'status = :st';     $params[':st'] = $fStatus; }
if ($fType   !== '') { $where[] = 'email_type = :ty'; $params[':ty'] = $fType; }
if ($fSearch !== '') {
    $where[] = '(recipient LIKE :q OR subject LIKE :q2)';
    $params[':q']  = '%' . $fSearch . '%';
    $params[':q2'] = '%' . $fSearch . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$total = 0;
$counts = ['sent' => 0, 'failed' => 0];
$types = [];
try {
    $cst = $pdo->prepare("SELECT COUNT(*) FROM email_logs $whereSql");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();

    // LIMIT/OFFSET are integers cast above, never bound — MySQL will not take a
    // placeholder there with emulated prepares off.
    $offset = ($page - 1) * $perPage;
    $lst = $pdo->prepare("SELECT * FROM email_logs $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
    $lst->execute($params);
    $rows = $lst->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pdo->query("SELECT status, COUNT(*) n FROM email_logs GROUP BY status") as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }
    $types = $pdo->query("SELECT DISTINCT email_type FROM email_logs ORDER BY email_type ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $errorMsg = $errorMsg ?: 'Could not read the email log: ' . $e->getMessage();
}

$totalPages = max(1, (int)ceil($total / $perPage));
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="margin-bottom:22px;">
    <h1 class="admin-page-title">✉️ Email Log</h1>
    <p class="admin-page-subtitle">
        Every message the shop has sent, newest first. Use it to answer &ldquo;did this customer
        actually get their email?&rdquo; — and to catch the ones that quietly failed.
    </p>
</div>

<?php if ($successMsg): ?>
<?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>
<?php if ($errorMsg): ?>
<?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<div class="glass-panel" style="padding:20px 24px; margin-bottom:20px;">
    <div style="display:flex; gap:26px; flex-wrap:wrap; align-items:center;">
        <div><strong style="font-size:22px;"><?= (int)($counts['sent'] + $counts['failed']) ?></strong><br>
             <span style="color:var(--text-muted); font-size:13px;">total sent</span></div>
        <div><strong style="font-size:22px; color:var(--color-success);"><?= (int)$counts['sent'] ?></strong><br>
             <span style="color:var(--text-muted); font-size:13px;">delivered</span></div>
        <div><strong style="font-size:22px; color:<?= $counts['failed'] > 0 ? '#b91c1c' : 'var(--text-muted)' ?>;"><?= (int)$counts['failed'] ?></strong><br>
             <span style="color:var(--text-muted); font-size:13px;">failed</span></div>
        <?php if ($counts['failed'] > 0): ?>
        <a href="email_logs.php?status=failed" class="btn-danger" style="padding:9px 16px; text-decoration:none; margin-left:auto;">
            <i class="fa-solid fa-triangle-exclamation"></i> Show the failures
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="glass-panel" style="padding:20px 24px; margin-bottom:20px;">
    <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label class="form-label" style="font-size:12px;">Status</label>
            <select name="status" class="form-control" style="min-width:130px;">
                <option value="">All</option>
                <option value="sent"   <?= $fStatus === 'sent'   ? 'selected' : '' ?>>Delivered</option>
                <option value="failed" <?= $fStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        <div>
            <label class="form-label" style="font-size:12px;">Type</label>
            <select name="type" class="form-control" style="min-width:180px;">
                <option value="">All types</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="form-label" style="font-size:12px;">Search recipient or subject</label>
            <input type="text" name="q" value="<?= htmlspecialchars($fSearch) ?>" class="form-control" placeholder="name@example.com">
        </div>
        <button type="submit" class="btn-primary" style="padding:10px 20px;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <?php if ($fStatus || $fType || $fSearch): ?>
        <a href="email_logs.php" class="btn-secondary" style="padding:10px 18px; text-decoration:none;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="glass-panel" style="padding:24px;">
    <?php if (!$rows): ?>
        <p style="margin:0; font-size:14px; color:var(--text-secondary);">
            <?= $total === 0 && !$fStatus && !$fType && !$fSearch
                ? 'No emails have been sent yet. Once the shop starts mailing customers, every message appears here.'
                : 'Nothing matches those filters.' ?>
        </p>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Type</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="white-space:nowrap; font-size:12.5px;">
                        <?= $r['created_at'] ? date('j M Y, H:i', strtotime($r['created_at'])) : '—' ?>
                    </td>
                    <td style="font-size:12.5px;"><?= htmlspecialchars((string)$r['email_type']) ?></td>
                    <td style="font-size:12.5px;"><?= htmlspecialchars((string)$r['recipient']) ?></td>
                    <td style="font-size:12.5px;"><?= htmlspecialchars((string)$r['subject']) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($r['status'] === 'sent'): ?>
                            <span style="color:var(--color-success); font-weight:600; font-size:12.5px;">
                                <i class="fa-solid fa-circle-check"></i> Delivered
                            </span>
                            <?php if (!empty($r['test_mode'])): ?>
                                <br><span style="font-size:11px; color:var(--text-muted);">test mode — not really sent</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#b91c1c; font-weight:600; font-size:12.5px;">
                                <i class="fa-solid fa-circle-xmark"></i> Failed
                            </span>
                            <?php if (!empty($r['error_message'])): ?>
                                <br><span style="font-size:11px; color:var(--text-muted);" title="<?= htmlspecialchars((string)$r['error_message']) ?>">
                                    <?= htmlspecialchars(mb_substr((string)$r['error_message'], 0, 60)) ?><?= mb_strlen((string)$r['error_message']) > 60 ? '…' : '' ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if ($r['status'] === 'failed'): ?>
                        <form method="POST" style="display:inline;"
                              onsubmit="return dvConfirmForm(this, 'Send this message again to <?= htmlspecialchars((string)$r['recipient'], ENT_QUOTES) ?>?', { danger: false, type: 'confirm', confirmText: 'Send again' });">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="resend">
                            <input type="hidden" name="log_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn-sm btn-sm-primary" style="padding:6px 12px; border:none; cursor:pointer; font-size:12px;">
                                <i class="fa-solid fa-paper-plane"></i> Resend
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <?php $qs = function (int $p) use ($fStatus, $fType, $fSearch) {
        return 'email_logs.php?' . http_build_query(array_filter([
            'status' => $fStatus, 'type' => $fType, 'q' => $fSearch, 'page' => $p,
        ]));
    }; ?>
    <div style="display:flex; gap:10px; align-items:center; margin-top:18px; font-size:13px;">
        <?php if ($page > 1): ?><a href="<?= htmlspecialchars($qs($page - 1)) ?>" class="btn-secondary" style="padding:7px 14px; text-decoration:none;">← Newer</a><?php endif; ?>
        <span style="color:var(--text-muted);">Page <?= $page ?> of <?= $totalPages ?> &nbsp;·&nbsp; <?= $total ?> message<?= $total === 1 ? '' : 's' ?></span>
        <?php if ($page < $totalPages): ?><a href="<?= htmlspecialchars($qs($page + 1)) ?>" class="btn-secondary" style="padding:7px 14px; text-decoration:none;">Older →</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
