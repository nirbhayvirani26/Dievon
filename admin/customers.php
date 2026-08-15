<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Moved ABOVE the POST handlers below.
// It used to sit after them, and after the page had already rendered — so a
// staff account without this permission ran the INSERT/DELETE first and was
// only shown the 403 afterwards. The record was already gone by then.
requireAdminCapability('customers.view');

$activeTab = 'customers';
$successMsg = '';
$errorMsg   = '';

// Handle Delete Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        // Destroying an account is a different job from looking one up. The page
        // gate above stays on customers.view so clerks keep read access.
        requireAdminCapability('customers.manage', true);
        $delId = (int)$_POST['delete_customer'];
        try {
            $pdo->prepare("DELETE FROM customers WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 0, 'customer_deleted', "Customer #{$delId} deleted");
            $successMsg = "Customer account deleted successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error deleting customer: " . $e->getMessage();
        }
    }
}

// Handle Delete Inquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inquiry'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        requireAdminCapability('customers.manage', true);
        try {
            if ($_POST['delete_inquiry'] === 'all') {
                // "Delete everything" is the one action here with no undo, and it
                // was reachable by any account holding customers.view. Logged so
                // there is at least a record of who emptied it and when.
                $wiped = $pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn();
                $pdo->exec("DELETE FROM inquiries");
                logAdminAction($_SESSION['admin_id'] ?? 0, 'inquiries_purged', "Deleted ALL {$wiped} customer enquiries");
                $successMsg = "All customer inquiries deleted.";
            } else {
                $delId = (int)$_POST['delete_inquiry'];
                $pdo->prepare("DELETE FROM inquiries WHERE id = :id")->execute(['id' => $delId]);
                logAdminAction($_SESSION['admin_id'] ?? 0, 'inquiry_deleted', "Enquiry #{$delId} deleted");
                $successMsg = "Inquiry message deleted.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Error deleting inquiry: " . $e->getMessage();
        }
    }
}

// Fetch Customers
$customers = [];
try {
    $customers = $pdo->query("SELECT * FROM customers ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {}

// Fetch Inquiries
$inquiries = [];
try {
    $inquiries = $pdo->query("SELECT * FROM inquiries ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';

?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">👥 Customer Accounts &amp; Inquiries</h1>
        <p class="admin-page-subtitle">Manage registered buyer accounts and website contact messages.</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<!-- ══ SECTION 1: Registered Customer Accounts ════════════════════════ -->
<div class="glass-panel" style="padding:0; overflow:hidden; margin-bottom:32px;">
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
        <span>👤 Registered Customers (<?= count($customers) ?>)</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Customer Name</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Delivery Address</th>
                    <th>Joined Date</th>
                    <th style="width:100px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" style="padding:30px; text-align:center; color:var(--text-muted);">No registered customer accounts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td style="color:var(--text-muted); font-size:12px;">#<?= $c['id'] ?></td>
                            <td><strong style="color:var(--text-primary); font-size:14px;"><?= htmlspecialchars($c['name']) ?></strong></td>
                            <td><a href="mailto:<?= htmlspecialchars($c['email']) ?>" style="color:var(--color-primary); text-decoration:none; font-weight:600; font-size:13px;"><?= htmlspecialchars($c['email']) ?></a></td>
                            <td style="font-size:13px; color:var(--text-secondary);"><?= htmlspecialchars($c['phone'] ?? 'N/A') ?></td>
                            <td style="font-size:12px; color:var(--text-muted); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($c['address'] ?? 'N/A') ?></td>
                            <td style="font-size:12px; color:var(--text-muted);"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                            <td style="text-align:right;">
                                <div class="admin-actions">
                                    <form method="POST" action="customers.php" style="display:inline;" onsubmit="return dvConfirmForm(this,'Delete this customer account?');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="delete_customer" value="<?= $c['id'] ?>">
                                        <button type="submit" class="admin-action-btn is-danger" title="Delete customer" aria-label="Delete customer <?= htmlspecialchars($c['name'] ?? $c['email']) ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
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

<!-- ══ SECTION 2: Customer Contact Inquiries ═════════════════════════ -->
<div class="glass-panel" style="padding:0; overflow:hidden;">
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
        <span>📬 Contact Form Inquiries (<?= count($inquiries) ?>)</span>
        <?php if (!empty($inquiries)): ?>
        <form method="POST" action="customers.php" style="display:inline;" onsubmit="return dvConfirmForm(this,'Delete ALL inquiries?');">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="delete_inquiry" value="all">
            <button type="submit" style="color:#ef4444; font-size:12px; font-weight:700; text-decoration:none; background:none; border:none; cursor:pointer;">
                <i class="fa-solid fa-trash"></i> Clear All
            </button>
        </form>
        <?php endif; ?>
    </div>

    <div style="padding:20px;">
        <?php if (empty($inquiries)): ?>
            <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px; opacity:0.3;">📭</div>
                <p style="font-size:14px; margin:0;">No inquiries received yet.</p>
            </div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($inquiries as $inq): ?>
                <div style="background:var(--bg-surface-soft); border-radius:8px; padding:18px 20px; border:1px solid var(--border-light); position:relative;">
                    <form method="POST" action="customers.php" style="position:absolute; top:16px; right:16px;" onsubmit="return dvConfirmForm(this,'Delete this inquiry?');">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="delete_inquiry" value="<?= $inq['id'] ?>">
                        <button type="submit" style="color:#ef4444; font-size:13px; background:none; border:none; cursor:pointer;" title="Delete">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </form>
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#2b080f; color:#ffffff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px;">
                            <?= strtoupper(mb_substr($inq['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <strong style="font-size:15px; color:var(--text-primary); display:block;"><?= htmlspecialchars($inq['name']) ?></strong>
                            <span style="font-size:12px; color:var(--text-muted);"><?= date('d M Y, H:i', strtotime($inq['created_at'])) ?></span>
                        </div>
                    </div>
                    <div style="font-size:13px; color:var(--text-secondary); margin-bottom:10px; display:flex; gap:16px;">
                        <span>📧 <a href="mailto:<?= htmlspecialchars($inq['email']) ?>" style="color:var(--color-primary); font-weight:600; text-decoration:none;"><?= htmlspecialchars($inq['email']) ?></a></span>
                        <?php if (!empty($inq['phone'])): ?>
                            <span>📞 <?= htmlspecialchars($inq['phone']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="background:#ffffff; border-radius:6px; padding:12px 14px; font-size:13px; color:#334155; border-left:3px solid #2b080f;">
                        <?= nl2br(htmlspecialchars($inq['message'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
