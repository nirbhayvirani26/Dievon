<?php
/*
 * ═══════════════════════════════════════════════════════════════════════
 *  AUDIT LOG  (admin/logs.php)
 * ───────────────────────────────────────────────────────────────────────
 *  Who changed what, and when.
 *
 *  logAdminAction() has been writing to admin_audit_logs since the panel was
 *  built — 76 records were already stored on this install — but this page
 *  handed straight off to module_router.php, which printed a stub reading
 *  "This module is active and synchronized live with database settings" and
 *  no table at all. Every one of those records was unreachable.
 *
 *  It matters most the day a second person has a login: "who marked this
 *  parcel dispatched?" and "who issued that refund?" are questions a shop
 *  eventually has to answer, and the answer cannot be reconstructed later.
 *
 *  Read-only by design. An audit trail that the panel can edit or delete is
 *  not an audit trail, so there is no delete button here and no clear-all —
 *  not as an oversight, but because the value is in it being untouchable.
 * ═══════════════════════════════════════════════════════════════════════
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
requireAdminCapability('settings.manage');

$activeTab = 'logs';
$errorMsg  = '';

// ── Filters ─────────────────────────────────────────────────────────────
$fAction = trim((string)($_GET['action_type'] ?? ''));
$fAdmin  = (int)($_GET['admin'] ?? 0);
$fSearch = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 60;

$where  = [];
$params = [];
if ($fAction !== '') { $where[] = 'l.action = :act';    $params[':act'] = $fAction; }
if ($fAdmin  >  0)   { $where[] = 'l.admin_id = :aid';  $params[':aid'] = $fAdmin; }
if ($fSearch !== '') { $where[] = 'l.details LIKE :q';  $params[':q']   = '%' . $fSearch . '%'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$total = 0;
$actions = [];
$admins = [];
try {
    $cst = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_logs l $whereSql");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();

    // LIMIT/OFFSET interpolated from ints — MySQL will not bind them with
    // emulated prepares switched off.
    $offset = ($page - 1) * $perPage;
    $lst = $pdo->prepare(
        "SELECT l.*, u.username, u.full_name
           FROM admin_audit_logs l
      LEFT JOIN admin_users u ON u.id = l.admin_id
         $whereSql
      ORDER BY l.created_at DESC, l.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $lst->execute($params);
    $rows = $lst->fetchAll(PDO::FETCH_ASSOC);

    $actions = $pdo->query("SELECT action, COUNT(*) n FROM admin_audit_logs GROUP BY action ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
    $admins  = $pdo->query(
        "SELECT DISTINCT l.admin_id, u.username
           FROM admin_audit_logs l
      LEFT JOIN admin_users u ON u.id = l.admin_id
       ORDER BY u.username ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMsg = 'Could not read the audit log: ' . $e->getMessage();
}

$totalPages = max(1, (int)ceil($total / $perPage));

/**
 * A human label and a colour for each action name.
 *
 * The stored strings are machine names (soft_delete_product), which are fine in
 * a database and poor in a table someone reads under pressure. Anything not
 * listed falls back to the raw name with underscores opened out, so a new
 * action added later still reads sensibly without touching this map.
 */
function dievonAuditLabel(string $action): array {
    static $map = [
        'product_created'        => ['Product created',        '#059669'],
        'product_updated'        => ['Product updated',        '#2563eb'],
        'soft_delete_product'    => ['Product archived',       '#b45309'],
        'category_add'           => ['Category added',         '#059669'],
        'category_delete'        => ['Category deleted',       '#b91c1c'],
        'refund_issued'          => ['Refund issued',          '#b91c1c'],
        'cod_remitted'           => ['COD marked remitted',    '#059669'],
        'cod_marked_paid'        => ['COD marked paid',        '#059669'],
        'lookbook_update'        => ['Lookbook image changed', '#2563eb'],
        'lookbook_restore'       => ['Lookbook image restored','#b45309'],
        'admin_users_save_roles' => ['Staff roles changed',    '#7c3aed'],
        'size_charts_cleaned'    => ['Size charts tidied',     '#2563eb'],
        'size_codes_backfilled'  => ['Size codes filled in',   '#2563eb'],
        'email_resend'           => ['Email resent',           '#2563eb'],
    ];
    if (isset($map[$action])) { return $map[$action]; }
    return [ucfirst(str_replace('_', ' ', $action)), '#6b7280'];
}

$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="margin-bottom:22px;">
    <h1 class="admin-page-title">📋 Audit Log</h1>
    <p class="admin-page-subtitle">
        Who changed what, and when. Read-only — nothing here can be edited or deleted,
        which is the whole point of keeping it.
    </p>
</div>

<?php if ($errorMsg): ?>
<?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<div class="glass-panel" style="padding:20px 24px; margin-bottom:20px;">
    <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label class="form-label" style="font-size:12px;">Action</label>
            <select name="action_type" class="form-control" style="min-width:210px;">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <?php [$lbl] = dievonAuditLabel((string)$a['action']); ?>
                    <option value="<?= htmlspecialchars((string)$a['action']) ?>" <?= $fAction === $a['action'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lbl) ?> (<?= (int)$a['n'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" style="font-size:12px;">Who</label>
            <select name="admin" class="form-control" style="min-width:160px;">
                <option value="0">Everyone</option>
                <?php foreach ($admins as $ad): ?>
                <option value="<?= (int)$ad['admin_id'] ?>" <?= $fAdmin === (int)$ad['admin_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ad['username'] ?: ('Admin #' . (int)$ad['admin_id'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="form-label" style="font-size:12px;">Search the detail</label>
            <input type="text" name="q" value="<?= htmlspecialchars($fSearch) ?>" class="form-control" placeholder="order code, product name…">
        </div>
        <button type="submit" class="btn-primary" style="padding:10px 20px;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <?php if ($fAction || $fAdmin || $fSearch): ?>
        <a href="logs.php" class="btn-secondary" style="padding:10px 18px; text-decoration:none;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="glass-panel" style="padding:24px;">
    <?php if (!$rows): ?>
        <p style="margin:0; font-size:14px; color:var(--text-secondary);">
            <?= $total === 0 && !$fAction && !$fAdmin && !$fSearch
                ? 'Nothing has been recorded yet. Changes made in the panel will appear here.'
                : 'Nothing matches those filters.' ?>
        </p>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr><th style="width:150px;">When</th><th style="width:190px;">Action</th><th style="width:130px;">Who</th><th>Detail</th><th style="width:120px;">From</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php [$label, $colour] = dievonAuditLabel((string)$r['action']); ?>
                <tr>
                    <td style="white-space:nowrap; font-size:12.5px; color:var(--text-secondary);">
                        <?= $r['created_at'] ? date('j M Y, H:i', strtotime($r['created_at'])) : '—' ?>
                    </td>
                    <td>
                        <span style="font-size:12px; font-weight:700; color:<?= $colour ?>;"><?= htmlspecialchars($label) ?></span>
                    </td>
                    <td style="font-size:12.5px;">
                        <?= htmlspecialchars($r['username'] ?: ('Admin #' . (int)$r['admin_id'])) ?>
                        <?php if (!empty($r['full_name'])): ?>
                            <br><span style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($r['full_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12.5px;"><?= htmlspecialchars((string)$r['details']) ?></td>
                    <td style="font-size:11.5px; color:var(--text-muted);"><?= htmlspecialchars((string)$r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <?php $qs = function (int $p) use ($fAction, $fAdmin, $fSearch) {
        return 'logs.php?' . http_build_query(array_filter([
            'action_type' => $fAction, 'admin' => $fAdmin ?: '', 'q' => $fSearch, 'page' => $p,
        ]));
    }; ?>
    <div style="display:flex; gap:10px; align-items:center; margin-top:18px; font-size:13px;">
        <?php if ($page > 1): ?><a href="<?= htmlspecialchars($qs($page - 1)) ?>" class="btn-secondary" style="padding:7px 14px; text-decoration:none;">← Newer</a><?php endif; ?>
        <span style="color:var(--text-muted);">Page <?= $page ?> of <?= $totalPages ?> &nbsp;·&nbsp; <?= $total ?> record<?= $total === 1 ? '' : 's' ?></span>
        <?php if ($page < $totalPages): ?><a href="<?= htmlspecialchars($qs($page + 1)) ?>" class="btn-secondary" style="padding:7px 14px; text-decoration:none;">Older →</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
