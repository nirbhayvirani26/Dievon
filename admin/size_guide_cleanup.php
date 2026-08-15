<?php
// ============================================================
//  Dievon – Admin: Left-over Size Charts
//
//  A size chart can be attached to one specific product. When that product is
//  archived or removed, its chart stays behind — nothing deletes it, because
//  archiving a product is reversible and taking its measurements with it would
//  make restoring the product lose data.
//
//  So the charts are harmless, just invisible: no shopper can reach one, since
//  the product it belongs to is no longer on the shop. This page lists them so
//  the decision to keep or remove them is yours rather than automatic.
//
//  Deliberately narrow. It ONLY ever considers charts with a product_id whose
//  product is archived or gone. It will not touch:
//    * the shop-wide "How to Measure" image (that is store_settings, a
//      different table entirely, and no code here reads or writes it)
//    * any chart attached to a CATEGORY — categories outlive their stock
//    * any chart attached to a product that is still live
//    * anything at all on GET. Nothing happens until you press the button.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('sizeguide.manage');

$activeTab       = 'size_guide';
$hideHeaderTitle = true;

$successMsg = '';
$errorMsg   = '';
$removed    = [];

/**
 * Charts whose product_id points at a product that is archived or no longer there.
 *
 * A LEFT JOIN plus "p.id IS NULL" rather than a NOT IN (...) subquery, so a chart
 * pointing at an id that was hard-deleted is caught as well as one whose product
 * is merely archived. Charts with no product_id are never returned — those belong
 * to a category, or to the shop as a whole, and are none of this page's business.
 */
function orphanedSizeCharts(PDO $pdo): array {
    try {
        return $pdo->query(
            "SELECT c.id, c.product_id, c.illustration_image,
                    (SELECT COUNT(*) FROM size_guide_content sc WHERE sc.chart_id = c.id) AS row_count,
                    p.id           AS live_id,
                    arch.name      AS archived_name,
                    arch.is_deleted AS archived_flag
               FROM size_guide_charts c
               LEFT JOIN products p
                      ON p.id = c.product_id
                     AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
               LEFT JOIN products arch ON arch.id = c.product_id
              WHERE c.product_id IS NOT NULL
                AND p.id IS NULL
              ORDER BY c.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// ── Remove (POST only, and only ids that are still genuinely orphaned) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_orphans') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Your session has expired. Refresh the page and try again.';
    } else {
        $wanted = array_map('intval', (array)($_POST['chart_ids'] ?? []));

        // Re-checked here, not trusted from the form. If a product was restored in
        // another tab between loading this page and pressing the button, its chart
        // is no longer an orphan and must not be deleted.
        $stillOrphan = array_column(orphanedSizeCharts($pdo), 'id');
        $toRemove    = array_values(array_intersect($wanted, array_map('intval', $stillOrphan)));

        if (empty($toRemove)) {
            $successMsg = 'Nothing was removed — those charts are no longer left over.';
        } else {
            try {
                $pdo->beginTransaction();
                $in = implode(',', array_map('intval', $toRemove));
                // Content rows first, then the chart, so a failure can never leave
                // measurement rows pointing at a chart that no longer exists.
                $pdo->exec("DELETE FROM size_guide_content WHERE chart_id IN ($in)");
                $pdo->exec("DELETE FROM size_guide_charts  WHERE id IN ($in)");
                $pdo->commit();
                $removed = $toRemove;
                logAdminAction($_SESSION['admin_id'] ?? 1, 'size_charts_cleaned',
                    'Removed left-over size chart(s): #' . implode(', #', $toRemove));
                $successMsg = count($toRemove) . ' left-over chart' . (count($toRemove) === 1 ? '' : 's') . ' removed.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('size_guide_cleanup: ' . $e->getMessage());
                $errorMsg = 'Could not remove them. Nothing was changed.';
            }
        }
    }
}

$orphans = orphanedSizeCharts($pdo);

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="margin-bottom:22px;">
    <h1 class="admin-page-title"><i class="fa-solid fa-ruler"></i> Left-over Size Charts</h1>
    <p class="admin-page-subtitle">
        Measurement tables belonging to products that are no longer in the shop.
    </p>
</div>

<?php if ($successMsg): ?>
<div class="alert alert-success" style="margin-bottom:20px">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?>
    <div style="margin-top:8px;"><a href="size_guide.php" style="font-weight:600;">Back to Size Guide &rarr;</a></div>
</div>
<?php endif; ?>

<?php if ($errorMsg): ?>
<?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<?php // Stated before anything else. "Size guide" means two different things in this
      // admin — the How to Measure picture and the measurement tables — and somebody
      // arriving here worried about their picture should be able to stop reading. ?>
<div class="sgc-safe">
    <h3><i class="fa-solid fa-shield-halved"></i> What this page does not touch</h3>
    <ul>
        <li><strong>Your “How to Measure” image.</strong> That is a shop-wide setting, stored somewhere else entirely. Nothing on this page reads or changes it.</li>
        <li><strong>Size charts for your categories.</strong> Only charts tied to a single product are ever listed here.</li>
        <li><strong>Charts for products you still sell.</strong> A chart is only listed once its product is archived or gone.</li>
        <li><strong>Your search rankings.</strong> These charts are already unreachable — the product they belong to is not on the shop, so Google cannot see them either way.</li>
    </ul>
</div>

<div class="glass-panel" style="padding:24px;">
<?php if (empty($orphans)): ?>
    <div class="sgc-clear">
        <i class="fa-solid fa-circle-check"></i>
        <div>
            <strong>Nothing left over.</strong>
            Every size chart belongs to a category, or to a product you still sell. There is nothing to do here.
        </div>
    </div>
<?php else: ?>
    <h3 style="margin:0 0 6px; font-size:15px; text-transform:uppercase; letter-spacing:.05em;">
        <?= count($orphans) ?> left-over chart<?= count($orphans) === 1 ? '' : 's' ?>
    </h3>
    <p style="font-size:13px; color:var(--text-secondary); margin:0 0 18px;">
        Untick anything you want to keep. Keeping them is perfectly safe — they simply sit unused, and
        <strong>if you restore the product its measurements come back with it.</strong>
        Remove them only if you are sure the product is gone for good.
    </p>

    <form method="POST" action="size_guide_cleanup.php">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="action" value="remove_orphans">

        <div class="table-wrapper">
            <table class="data-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="width:52px;">Remove</th>
                        <th style="width:80px;">Chart</th>
                        <th>Belonged to</th>
                        <th style="width:150px;">Measurement rows</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orphans as $o): ?>
                    <tr>
                        <td><input type="checkbox" name="chart_ids[]" value="<?= (int)$o['id'] ?>" checked></td>
                        <td style="color:var(--text-muted); font-weight:600;">#<?= (int)$o['id'] ?></td>
                        <td>
                            <?php if (!empty($o['archived_name'])): ?>
                                <strong><?= htmlspecialchars($o['archived_name']) ?></strong>
                                <span class="sgc-tag">archived — restorable</span>
                            <?php else: ?>
                                <strong>Product #<?= (int)$o['product_id'] ?></strong>
                                <span class="sgc-tag is-gone">permanently deleted</span>
                            <?php endif; ?>
                            <?php if (!empty($o['illustration_image'])): ?>
                                <div class="sgc-note">Has its own diagram photo, which would go with it.</div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$o['row_count'] ?> size<?= (int)$o['row_count'] === 1 ? '' : 's' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:20px; display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
            <button type="submit" class="btn-primary"
                    onclick="return dvConfirmForm(this.form, 'Remove the ticked charts?\n\nThis cannot be undone. Your How to Measure image and your category charts are not affected.', { confirmText: 'Remove charts' });">
                <i class="fa-solid fa-broom"></i> Remove ticked charts
            </button>
            <a href="size_guide.php" style="font-size:13px; color:var(--text-muted);">Leave everything as it is</a>
        </div>
    </form>
<?php endif; ?>
</div>

<style>
.sgc-safe { background:var(--bg-surface-soft); border:1px solid var(--border-light); border-left:3px solid var(--color-success);
            border-radius:8px; padding:15px 18px; margin-bottom:22px; max-width:900px; }
.sgc-safe h3 { margin:0 0 9px; font-size:12px; text-transform:uppercase; letter-spacing:.07em; color:var(--color-success); }
.sgc-safe ul { margin:0; padding-left:20px; }
.sgc-safe li { font-size:13px; line-height:1.65; color:var(--text-secondary); margin-bottom:4px; }
.sgc-clear { display:flex; gap:12px; align-items:flex-start; font-size:14px; color:var(--text-secondary); line-height:1.6; }
.sgc-clear i { color:var(--color-success); font-size:18px; margin-top:2px; }
.sgc-tag { display:inline-block; font-size:10.5px; font-weight:700; letter-spacing:.04em; padding:2px 8px;
           border-radius:20px; background:rgba(197,155,75,.15); color:var(--color-secondary); margin-left:6px; }
.sgc-tag.is-gone { background:rgba(169,68,66,.12); color:var(--color-danger); }
.sgc-note { font-size:11.5px; color:var(--text-muted); margin-top:3px; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
