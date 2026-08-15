<?php
/**
 * Dievon – Clear orphaned product rows
 * ============================================================================
 * An orphan here is a row whose parent product no longer exists: a colour, a
 * gallery photo, a size, a country price left behind when the product was
 * deleted. They appear on no screen, so nothing can edit or remove them — but
 * they still NAME their image files, so the Media Library counts those files as
 * "In use" and refuses to delete them:
 *
 *     "Not deleted — this file is still used by: Colour photo.
 *      Change the picture on that record first, then delete this file."
 *
 * Which is impossible advice, because the record it means cannot be opened from
 * anywhere. That guard is right to exist — it stops you breaking a live image —
 * it just cannot tell a real reference from a dangling one.
 *
 * The main cause was a bug in the product purge: product_color_images is keyed
 * on color_id, not product_id, so the delete matched nothing and the failure was
 * swallowed. Fixed in admin/products.php. This clears what the old code left.
 *
 * SAFE BY CONSTRUCTION: every statement deletes only rows whose parent is
 * already gone (LEFT JOIN … WHERE parent IS NULL). A row belonging to a real
 * product cannot match, whatever you click.
 *
 * Run it, then delete the file. Delete this file from the server afterwards.
 */

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
requireAdminCapability('settings.manage', false);

/** child table => [the column holding the parent id, the parent table] */
$orphanMap = [
    'product_color_images'   => ['color_id',   'product_colors'],
    'product_colors'         => ['product_id', 'products'],
    'product_images'         => ['product_id', 'products'],
    'product_variants'       => ['product_id', 'products'],
    'product_country_prices' => ['product_id', 'products'],
];

function orphanCount(PDO $pdo, string $child, string $key, string $parent): ?int {
    try {
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM `$child` c
               LEFT JOIN `$parent` p ON p.id = c.`$key`
              WHERE c.`$key` IS NOT NULL AND p.id IS NULL"
        )->fetchColumn();
    } catch (PDOException $e) {
        return null;   // table absent on this install
    }
}

$done = [];
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_orphans') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security check failed — reload the page and try again.';
    } else {
        /* Deepest child first: product_color_images hangs off product_colors, so
           clearing colours first would orphan its images beyond our own reach. */
        foreach ($orphanMap as $child => [$key, $parent]) {
            try {
                $st = $pdo->prepare(
                    "DELETE c FROM `$child` c
                       LEFT JOIN `$parent` p ON p.id = c.`$key`
                      WHERE c.`$key` IS NOT NULL AND p.id IS NULL"
                );
                $st->execute();
                if ($st->rowCount() > 0) { $done[$child] = $st->rowCount(); }
            } catch (PDOException $e) {
                error_log("Orphan cleanup failed on $child: " . $e->getMessage());
            }
        }
        if (function_exists('logAdminAction')) {
            logAdminAction((int)($_SESSION['admin_id'] ?? 1), 'orphan_cleanup',
                'Cleared orphan rows: ' . json_encode($done));
        }
    }
}

$counts = [];
$total  = 0;
foreach ($orphanMap as $child => [$key, $parent]) {
    $n = orphanCount($pdo, $child, $key, $parent);
    if ($n === null) { continue; }
    $counts[$child] = $n;
    $total += $n;
}

$labels = [
    'product_color_images'   => 'Colour photos',
    'product_colors'         => 'Colours',
    'product_images'         => 'Gallery photos',
    'product_variants'       => 'Sizes',
    'product_country_prices' => 'Country prices',
];

$pageTitle = 'Clear Orphaned Rows';
require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-panel form-section">
    <h3><i class="fa-solid fa-broom"></i> Rows left behind by deleted products</h3>

    <?php if ($errorMsg): ?>
        <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
    <?php endif; ?>

    <?php if ($done): ?>
        <?php
        $bits = [];
        foreach ($done as $t => $n) { $bits[] = '<strong>' . $n . ' ' . ($labels[$t] ?? $t) . '</strong>'; }
        echo dvNotice('Cleared ' . implode(', ', $bits)
            . '. The images they were holding should now show as <strong>Unused</strong> in the Media Library.', 'success');
        ?>
    <?php endif; ?>

    <?= dvNotice(
        'These are rows whose product has already been deleted. They appear on no screen, so nothing '
        . 'can edit them — but they still name their image files, which is why the Media Library says '
        . '<em>"this file is still used by: Colour photo"</em> and refuses to delete it. '
        . '<strong>Take a database backup first.</strong>',
        'info'
    ) ?>

    <?php if ($total === 0): ?>
        <?= dvNotice('No orphaned rows. Nothing to clear.', 'success') ?>
    <?php else: ?>
        <table class="admin-table" style="width:100%; max-width:520px;">
            <thead><tr><th>What</th><th style="text-align:right;">Orphaned rows</th></tr></thead>
            <tbody>
            <?php foreach ($counts as $t => $n): ?>
                <tr>
                    <td><?= htmlspecialchars($labels[$t] ?? $t) ?></td>
                    <td style="text-align:right; font-weight:<?= $n ? '700' : '400' ?>;
                               color:<?= $n ? 'var(--color-danger)' : 'var(--text-muted)' ?>;"><?= $n ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="POST" style="margin-top:20px;"
              onsubmit="return dvConfirmForm(this, 'Delete <?= $total ?> orphaned row(s)?\n\nEach one belongs to a product that no longer exists.', { confirmText: 'Clear rows' });">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="clear_orphans">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-broom"></i> Clear <?= $total ?> orphaned row<?= $total === 1 ? '' : 's' ?>
            </button>
        </form>

        <?= dvNotice(
            'Only rows whose parent is already missing can match — a row belonging to a real product '
            . 'cannot be touched by this, whatever you click.',
            'muted'
        ) ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
