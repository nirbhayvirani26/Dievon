<?php
/**
 * Dievon – Refresh the stored GST rate on every product
 * ============================================================================
 * products.gst_rate is written only when a product is SAVED through the admin
 * form. Anything seeded, imported, or created before the tax feature existed
 * still holds the column default of 0 — which is why every product screen shows
 * 0% while the collection it sits in is set to "Apparel – Auto by Price" (5%
 * under ₹2,500).
 *
 * Orders are already correct without this: actions/checkout_action.php resolves
 * the rate at order time through productTaxSnapshot() rather than reading the
 * column. This is purely so the ADMIN screens stop reporting a rate the shop
 * does not actually charge.
 *
 * SAFE BY CONSTRUCTION:
 *   * Only products with "use collection tax" ticked are touched. A product
 *     where the owner unticked it and typed a rate by hand has stated a
 *     decision, and that is left exactly as typed.
 *   * Only the gst_rate column is written. Prices, stock and HSN are untouched.
 *   * It writes the SAME value productTaxSnapshot() already returns, so it can
 *     be run repeatedly with no further effect.
 *   * No order, past or present, is read or changed. Historical invoices are
 *     frozen in items_json and cannot be altered from here.
 *
 * It does NOT set HSN codes. Those are a classification decision that depends
 * on fabric and construction, and belong to the shop's accountant.
 *
 * Run it, check the summary, then delete this file from the server.
 */

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
$GLOBALS['pdo'] = $pdo;

$didRun = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes');

$rows = $pdo->query(
    "SELECT * FROM products WHERE COALESCE(is_deleted,0) = 0 ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$plan = [];
foreach ($rows as $p) {
    // A hand-typed rate is a decision, not a gap. Leave it.
    if (array_key_exists('use_category_tax', $p) && (int)$p['use_category_tax'] === 0) { continue; }
    $should = productTaxSnapshot($pdo, $p, (float)$p['price'])['gst_rate'];
    if (abs((float)($p['gst_rate'] ?? 0) - $should) > 0.001) {
        $plan[] = ['id' => (int)$p['id'], 'name' => (string)$p['name'],
                   'from' => (float)($p['gst_rate'] ?? 0), 'to' => $should];
    }
}

$updated = 0;
if ($didRun && $plan) {
    $st = $pdo->prepare("UPDATE products SET gst_rate = :r WHERE id = :id");
    foreach ($plan as $row) { $st->execute([':r' => $row['to'], ':id' => $row['id']]); $updated++; }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Refresh product GST rates – Dievon</title>
<style>
 body{font-family:system-ui,sans-serif;max-width:820px;margin:40px auto;padding:0 20px;color:#3a0c1b;line-height:1.6}
 h1{font-size:22px}
 .panel{background:#fbf8f3;border-left:3px solid #C59B4B;padding:14px 18px;border-radius:4px;margin:18px 0}
 .done{background:#f1f8f1;border-left-color:#2e7d32}
 table{border-collapse:collapse;width:100%;font-size:13px;margin:14px 0}
 th,td{text-align:left;padding:6px 10px;border-bottom:1px solid #eee}
 button{background:#511126;color:#fff;border:0;padding:11px 22px;border-radius:4px;font-size:14px;cursor:pointer}
 code{background:#f5eee8;padding:1px 5px;border-radius:3px}
</style>
<h1>Refresh the stored GST rate on products</h1>

<?php if ($didRun): ?>
    <div class="panel done">
        <strong>Done.</strong> <?= (int)$updated ?> product<?= $updated === 1 ? '' : 's' ?> updated.
        Orders were already charging the correct rate; this only corrects what the product screens display.
    </div>
    <p>Delete this file from the server.</p>
<?php elseif (!$plan): ?>
    <div class="panel"><strong>Nothing to do</strong> — every product already stores the rate its collection resolves to.</div>
    <p>Delete this file from the server.</p>
<?php else: ?>
    <div class="panel">
        <strong><?= count($plan) ?></strong> product<?= count($plan) === 1 ? '' : 's' ?> store a rate that does not match
        their collection. Only <code>gst_rate</code> is written; prices, stock and HSN codes are untouched, and products
        with a hand-typed rate are skipped.
    </div>
    <table>
        <tr><th>#</th><th>Product</th><th>Stored</th><th>Will become</th></tr>
        <?php foreach ($plan as $r): ?>
        <tr>
            <td><?= $r['id'] ?></td>
            <td><?= htmlspecialchars(mb_substr($r['name'], 0, 52)) ?></td>
            <td><?= rtrim(rtrim(number_format($r['from'], 2), '0'), '.') ?>%</td>
            <td><strong><?= rtrim(rtrim(number_format($r['to'], 2), '0'), '.') ?>%</strong></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <form method="post"><input type="hidden" name="confirm" value="yes">
        <button type="submit">Update these products</button>
    </form>
<?php endif; ?>
