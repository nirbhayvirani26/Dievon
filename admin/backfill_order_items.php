<?php
/**
 * Dievon – Backfill order_items from orders.items_json
 * ============================================================================
 * order_items has existed since the beginning and was never written to: 126
 * orders, 3 rows, and no INSERT anywhere in the codebase. The real record of
 * what was sold has always lived in orders.items_json. Checkout now writes both
 * (see actions/checkout_action.php), but that only covers orders placed from
 * now on — this fills in the history so a report joining order_items does not
 * see a shop that apparently sold nothing until today.
 *
 * items_json REMAINS the authoritative record. Nothing here reads or writes it;
 * these rows are derived FROM it, never the other way round.
 *
 * SAFE BY CONSTRUCTION:
 *   * Orders that already have order_items rows are skipped entirely, so
 *     running it twice cannot double anything.
 *   * It only ever INSERTs into order_items. No order, product or snapshot is
 *     read-modify-written, and nothing is deleted.
 *   * Each order is committed on its own, so one unreadable snapshot cannot
 *     roll back the ones that worked.
 *
 * A NOTE ON SKU: orders placed before the sku field was added to the snapshot
 * do not record one, and this leaves those rows blank rather than filling in
 * the product's CURRENT code. A SKU can change; writing today's code into a
 * two-year-old line would state that the item shipped under a code that may not
 * have existed then. Blank is the honest answer to "we did not record this".
 *
 * Run it once, check the summary, then delete this file from the server.
 */

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$didRun  = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes');
$hasCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM order_items") as $c) { $hasCols[$c['Field']] = true; }

$fields = array_values(array_filter(
    ['order_id', 'product_id', 'variant_id', 'product_name', 'sku', 'variant_name', 'quantity', 'price'],
    static fn(string $f): bool => isset($hasCols[$f])
));

$missingCols = array_diff(['variant_id', 'sku'], array_keys($hasCols));

/* Orders holding a snapshot but no mirrored rows. The NOT EXISTS is what makes
   this safe to re-run: an order already backfilled cannot be selected again. */
$candidates = $pdo->query(
    "SELECT o.id, o.order_code, o.items_json
       FROM orders o
      WHERE o.items_json IS NOT NULL AND o.items_json <> '' AND o.items_json <> '[]'
        AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)
      ORDER BY o.id"
)->fetchAll(PDO::FETCH_ASSOC);

$written = 0; $ordersDone = 0; $skipped = [];

if ($didRun && $fields) {
    $ins = $pdo->prepare(
        "INSERT INTO order_items (" . implode(', ', $fields) . ")
         VALUES (:" . implode(', :', $fields) . ")"
    );

    foreach ($candidates as $o) {
        $lines = json_decode((string)$o['items_json'], true);
        if (!is_array($lines) || !$lines) {
            $skipped[] = $o['order_code'] . ' — snapshot could not be read';
            continue;
        }

        $pdo->beginTransaction();
        try {
            foreach ($lines as $l) {
                if (!is_array($l)) { continue; }
                $all = [
                    'order_id'     => (int)$o['id'],
                    'product_id'   => (int)($l['product_id'] ?? 0),
                    'variant_id'   => !empty($l['variant_id']) ? (int)$l['variant_id'] : null,
                    'product_name' => (string)($l['name'] ?? ''),
                    // Blank where the snapshot predates the sku field — see above.
                    'sku'          => (string)($l['sku'] ?? ''),
                    'variant_name' => trim(((string)($l['color_name'] ?? '')) . ' ' . ((string)($l['variant_name'] ?? ''))),
                    'quantity'     => (int)($l['quantity'] ?? 1),
                    'price'        => (float)($l['price'] ?? 0),
                ];
                $ins->execute(array_intersect_key($all, array_flip($fields)));
                $written++;
            }
            $pdo->commit();
            $ordersDone++;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $skipped[] = $o['order_code'] . ' — ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Backfill order_items – Dievon</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 20px;
           color: #3a0c1b; line-height: 1.6; }
    h1 { font-size: 22px; }
    .panel { background: #fbf8f3; border-left: 3px solid #C59B4B; padding: 14px 18px; margin: 18px 0; }
    .done  { background: #f1f8f1; border-left-color: #2e7d32; }
    button { background: #511126; color: #fff; border: 0; padding: 11px 22px;
             font-size: 14px; cursor: pointer; }
    ul { padding-left: 20px; }
    code { background: #f5eee8; padding: 1px 5px; }
</style>
<h1>Backfill <code>order_items</code> from the order snapshots</h1>

<?php if ($missingCols): ?>
    <div class="panel">
        <strong>Run the database update first.</strong>
        <code>order_items</code> is missing <code><?= htmlspecialchars(implode('</code>, <code>', $missingCols)) ?></code>.
        Open <code>update_new_database.php</code>, then come back — otherwise those
        details cannot be recorded and the rows written now would be incomplete.
    </div>
<?php endif; ?>

<?php if ($didRun): ?>
    <div class="panel done">
        <strong>Done.</strong> <?= (int)$written ?> line<?= $written === 1 ? '' : 's' ?>
        written across <?= (int)$ordersDone ?> order<?= $ordersDone === 1 ? '' : 's' ?>.
        <?php if ($skipped): ?>
            <p>These were left alone:</p>
            <ul><?php foreach ($skipped as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <p>Nothing else to do. Delete this file from the server.</p>
<?php else: ?>
    <div class="panel">
        <strong><?= count($candidates) ?></strong> order<?= count($candidates) === 1 ? '' : 's' ?>
        hold a snapshot but have no <code>order_items</code> rows.
        <p>
            This only adds rows to <code>order_items</code>. It does not read, change or delete a
            single order, product or snapshot, and an order that already has rows is skipped — so
            running it twice cannot duplicate anything.
        </p>
    </div>
    <?php if ($candidates): ?>
    <form method="post">
        <input type="hidden" name="confirm" value="yes">
        <button type="submit">Write the missing rows</button>
    </form>
    <?php else: ?>
        <p>Nothing to backfill. Delete this file from the server.</p>
    <?php endif; ?>
<?php endif; ?>
