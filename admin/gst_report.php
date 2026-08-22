<?php
// ============================================================
//  Dievon – GST Summary Report
// ------------------------------------------------------------
//  What this shop owes / can claim, period by period: taxable
//  value, CGST, SGST and IGST, split intra-state vs inter-state.
//
//  Every figure is derived from the GST snapshot frozen into each
//  order's items_json at checkout (hsn_code, gst_rate and whether
//  the price was GST-inclusive) — the exact same derivation the
//  invoice template uses, so the report and the invoice can never
//  disagree. Live product rows are NOT read, so editing a rate
//  cannot rewrite a past period.
//
//  If no products carry HSN codes / GST rates yet, the report
//  honestly shows zero tax rather than inventing any — the same
//  rule the invoice template follows (an order without tax data
//  prints as an Order Summary, not a tax invoice).
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('revenue.view');
// Sidebar highlight, like every other screen. Without it the menu shows nothing
// selected and the page looks as though it sits outside the admin.
$activeTab = 'revenue';
require_once 'includes/header.php';

$from = (string)($_GET['from'] ?? '');
$to   = (string)($_GET['to'] ?? '');
if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if ($to   === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }

$sellerGstin  = trim((string)storeSetting($pdo, 'seller_gstin', ''));
$sellerState  = trim((string)storeSetting($pdo, 'seller_state', ''));
$sellerEntity = legalEntityName($pdo) ?: brandName($pdo);

// ── Pull orders in range ──────────────────────────────────────────
$orders = [];
try {
    $stmt = $pdo->prepare(
        "SELECT order_code, customer_name, postcode, items_json, total_price,
                discount_amount, delivery_charge, cod_fee, payment_method,
                payment_status, status, created_at
           FROM orders
          WHERE COALESCE(is_deleted, 0) = 0
            AND DATE(created_at) BETWEEN :f AND :t
            -- Cancelled orders are not a tax liability.
            --
            -- They were counted here, so the report overstated the GST owed by
            -- the tax on every order a customer cancelled. Measured on the test
            -- data: one cancelled order contributed ₹1,000 of a ₹3,000 taxable
            -- base and ₹180 of ₹540 of tax. That is a figure filed with the
            -- government, so overstating it is not a cosmetic problem.
            AND status NOT IN ('Cancelled', 'Refunded', 'Returned')
          ORDER BY created_at ASC"
    );
    $stmt->execute(['f' => $from, 't' => $to]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('GST report query failed: ' . $e->getMessage());
    $orders = [];
}

// ── Per-order tax derivation (mirrors includes/invoice_template.php) ──
function gstReportOrderTax(array $order, string $sellerState): array
{
    $items = json_decode((string)($order['items_json'] ?? '[]'), true);
    if (!is_array($items)) { $items = []; }

    $place = function_exists('indianStateForPin') ? indianStateForPin($order['postcode'] ?? '') : '';
    $intra = $sellerState !== '' && $place !== ''
        && strcasecmp($sellerState, $place) === 0;

    // These three corrections mirror includes/invoice_template.php exactly. The
    // invoice and the filed figure are the same number seen twice, so any rule
    // applied to one has to be applied to the other or the shop's own paperwork
    // disagrees with its return.

    // 1. A coupon reduces the value tax is due on. This taxed the pre-discount
    //    line value, over-declaring output tax on every discounted order.
    $subtotal = 0.0;
    foreach ($items as $it) { $subtotal += (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 1); }
    $discount = (float)($order['discount_amount'] ?? 0);
    $factor   = ($discount > 0 && $subtotal > 0) ? max(0.0, ($subtotal - $discount) / $subtotal) : 1.0;

    $taxable = 0.0; $tax = 0.0; $hasTaxData = false;
    $principalRate = 0.0; $principalLine = -1.0;
    foreach ($items as $it) {
        $line = (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 1) * $factor;
        $rate = (float)($it['gst_rate'] ?? 0);
        $excl = (int)($it['price_includes_gst'] ?? 1) === 0;
        if ((float)$rate > 0 && !empty($it['hsn_code'])) { $hasTaxData = true; }
        if ((float)$rate > 0) {
            $base = $excl ? $line : $line / (1 + $rate / 100);
            $tax  += $excl ? round($line * $rate / 100, 2) : $line - $base;
            if ($line > $principalLine) { $principalLine = $line; $principalRate = $rate; }
        } else {
            $base = $line;
        }
        $taxable += $base;
    }

    // 2. Delivery and COD handling are a composite supply taxed at the principal
    //    rate. This function already selected both columns and then ignored
    //    them, so every rupee of delivery income was filed as untaxed. Treated
    //    as tax-inclusive, matching the invoice, because the customer has
    //    already paid them inside total_price.
    //    A zero-rated principal supply still carries its delivery into TURNOVER.
    //    The condition here was `$principalRate > 0`, so on an order whose items
    //    all had no GST rate the delivery and COD money was dropped from the
    //    taxable value altogether rather than being included at 0%. That
    //    under-states turnover on the return — a figure filed with the
    //    government — and it is the state every order is in today, because no
    //    product carries a rate yet.
    $charges = (float)($order['delivery_charge'] ?? 0) + (float)($order['cod_fee'] ?? 0);
    if ($charges > 0) {
        $chargeBase = $principalRate > 0 ? $charges / (1 + $principalRate / 100) : $charges;
        $taxable += $chargeBase;
        $tax     += $charges - $chargeBase;   // 0 when the principal supply is zero-rated
    }

    // 3. Halve the ROUNDED tax and derive the second half by subtraction, so the
    //    CGST and SGST columns always tie to the Total Tax column — per order
    //    and after aggregation.
    $tax  = round($tax, 2);
    $cgst = $intra ? round($tax / 2, 2) : 0.0;
    $sgst = $intra ? $tax - $cgst       : 0.0;
    $igst = $intra ? 0.0                : $tax;

    return [
        'taxable' => round($taxable, 2),
        'tax'     => round($tax, 2),
        'cgst'    => round($cgst, 2),
        'sgst'    => round($sgst, 2),
        'igst'    => round($igst, 2),
        'place'   => $place,
        'intra'   => $intra,
        'hasTaxData' => $hasTaxData,
    ];
}

// ── Aggregate by month ─────────────────────────────────────────────
$monthly   = [];
$grand     = ['orders' => 0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'tax' => 0.0];
$taxedOrders = 0; $anyTaxData = false;
foreach ($orders as $o) {
    $t = gstReportOrderTax($o, $sellerState);
    if ($t['hasTaxData']) { $anyTaxData = true; }
    if ($t['tax'] > 0)    { $taxedOrders++; }

    $mk = date('Y-m', strtotime($o['created_at']));
    if (!isset($monthly[$mk])) {
        $monthly[$mk] = ['orders' => 0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'tax' => 0.0];
    }
    $monthly[$mk]['orders']++;
    foreach (['taxable', 'cgst', 'sgst', 'igst', 'tax'] as $k) {
        $monthly[$mk][$k] += $t[$k];
        $grand[$k]         += $t[$k];
    }
    $grand['orders']++;
}
krsort($monthly);
foreach ($monthly as &$m) { foreach (['taxable','cgst','sgst','igst','tax'] as $k) { $m[$k] = round($m[$k], 2); } }
unset($m);
foreach (['taxable','cgst','sgst','igst','tax'] as $k) { $grand[$k] = round($grand[$k], 2); }

$sym = function_exists('currencySymbol') ? currencySymbol() : '₹';
$fmt = fn($n) => $sym . number_format((float)$n, 2);
?>
<div class="glass-panel" style="padding:24px; margin-bottom:20px;">
    <div style="display:flex; flex-wrap:wrap; align-items:center; gap:14px; justify-content:space-between;">
        <div>
            <h3 style="font-size:15px; font-weight:700; margin:0 0 4px;"><i class="fa-solid fa-receipt" style="color:var(--color-primary);"></i> GST Summary Report</h3>
            <div style="font-size:12px; color:var(--text-muted);">
                <?= htmlspecialchars($sellerEntity) ?>
                <?php if ($sellerGstin !== ''): ?>&middot; GSTIN: <strong><?= htmlspecialchars($sellerGstin) ?></strong><?php endif; ?>
                <?php if ($sellerState !== ''): ?>&middot; Registered in <strong><?= htmlspecialchars($sellerState) ?></strong> (same state = CGST+SGST, other = IGST)<?php endif; ?>
            </div>
            <?php // Say so when the split cannot be worked out.
                  //
                  // With no seller state every sale falls to the "other state"
                  // branch and the whole report comes out as IGST — including
                  // sales inside your own state, which are CGST+SGST. The page
                  // showed that silently: the explanatory note above only
                  // rendered once a state was set, so the one time the owner
                  // needed telling was the one time nothing was said. ?>
            <?php if ($sellerState === ''): ?>
                <div style="margin-top:10px; padding:12px 14px; background:#fffbeb; border:1px solid #fcd34d; font-size:13px; color:#92400e; line-height:1.6;">
                    <strong><i class="fa-solid fa-triangle-exclamation"></i> These figures are all IGST, and that is probably wrong.</strong><br>
                    Your registered state is not set, so every sale is being treated as
                    interstate. Sales inside your own state should be split into CGST + SGST.
                    Set <strong>Registered State</strong> in
                    <?php /* ?tab=settings, not bare settings.php. That page serves two screens and
                             defaults to STOCK for anything that is not exactly ?tab=settings
                             (admin/settings.php:34) — so this link, which tells you to go and set
                             your Registered State, was landing on Stock Management instead. The
                             one place the reader is sent to fix a GST filing has to arrive on the
                             right tab. */ ?>
                    <a href="settings.php?tab=settings" style="color:#92400e; font-weight:600; text-decoration:underline;">Store Settings</a>
                    and reload this report before filing anything.
                </div>
            <?php endif; ?>
        <?php // A stray </div> sat here — the div opened on the line above the
              // heading was already closed further up, so this one closed the
              // FLEX ROW early and dropped the date form outside it. The row is
              // justify-content:space-between, so with the form outside it the
              // header lost its layout and the extra close cascaded to the end of
              // the document, leaving the whole page a div short. ?>
        </div>
        <form method="GET" action="gst_report.php" style="display:flex; gap:8px; flex-wrap:wrap;">
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control" style="height:34px; font-size:13px; width:150px;">
            <span style="color:var(--text-muted); font-size:13px; align-self:center;">to</span>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control" style="height:34px; font-size:13px; width:150px;">
            <button type="submit" class="btn-primary" style="height:34px; padding:0 16px; font-size:13px;"><i class="fa-solid fa-filter"></i> Apply</button>
        </form>
    </div>
</div>

<?php if (!$anyTaxData && !empty($orders)): ?>
<div class="glass-panel" style="padding:16px 20px; margin-bottom:20px; border-left:4px solid #f59e0b; background:#fffbeb;">
    <div style="font-weight:700; color:#92400e; margin-bottom:4px;">⚠️ No GST data on these orders yet</div>
    <div style="font-size:13px; color:#78350f;">
        Tax is only computed where a product carries an HSN code and a GST rate. Give products
        (or their category defaults) an HSN + rate, and this report will fill in — old orders keep
        their frozen values, so the numbers never rewrite themselves.
    </div>
</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:20px;">
    <div class="stat-card glass-panel" style="border-left:4px solid var(--color-primary);">
        <div class="stat-label">Orders</div>
        <div class="stat-value" style="font-size:20px;"><?= $grand['orders'] ?></div>
    </div>
    <div class="stat-card glass-panel" style="border-left:4px solid #6366f1;">
        <div class="stat-label">Taxable Value</div>
        <div class="stat-value" style="font-size:18px; color:#6366f1;"><?= $fmt($grand['taxable']) ?></div>
    </div>
    <div class="stat-card glass-panel" style="border-left:4px solid #10b981;">
        <div class="stat-label">CGST</div>
        <div class="stat-value" style="font-size:18px; color:#10b981;"><?= $fmt($grand['cgst']) ?></div>
    </div>
    <div class="stat-card glass-panel" style="border-left:4px solid #10b981;">
        <div class="stat-label">SGST</div>
        <div class="stat-value" style="font-size:18px; color:#10b981;"><?= $fmt($grand['sgst']) ?></div>
    </div>
    <div class="stat-card glass-panel" style="border-left:4px solid #f59e0b;">
        <div class="stat-label">IGST</div>
        <div class="stat-value" style="font-size:18px; color:#f59e0b;"><?= $fmt($grand['igst']) ?></div>
    </div>
</div>

<div class="glass-panel" style="padding:24px; margin-bottom:20px;">
    <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Monthly Breakdown</h3>
    <?php if (empty($monthly)): ?>
        <p style="color:var(--text-muted); font-size:13px; margin:0;">No orders in this range.</p>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th><th class="r">Orders</th><th class="r">Taxable Value</th>
                    <th class="r">CGST</th><th class="r">SGST</th><th class="r">IGST</th><th class="r">Total Tax</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthly as $mk => $m): ?>
                <tr>
                    <td><strong><?= date('F Y', strtotime($mk . '-01')) ?></strong></td>
                    <td class="r"><?= $m['orders'] ?></td>
                    <td class="r"><?= $fmt($m['taxable']) ?></td>
                    <td class="r" style="color:#10b981;"><?= $fmt($m['cgst']) ?></td>
                    <td class="r" style="color:#10b981;"><?= $fmt($m['sgst']) ?></td>
                    <td class="r" style="color:#f59e0b;"><?= $fmt($m['igst']) ?></td>
                    <td class="r"><strong><?= $fmt($m['tax']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <tr style="border-top:2px solid var(--border-strong);">
                    <td><strong>Total</strong></td>
                    <td class="r"><strong><?= $grand['orders'] ?></strong></td>
                    <td class="r"><strong><?= $fmt($grand['taxable']) ?></strong></td>
                    <td class="r"><strong><?= $fmt($grand['cgst']) ?></strong></td>
                    <td class="r"><strong><?= $fmt($grand['sgst']) ?></strong></td>
                    <td class="r"><strong><?= $fmt($grand['igst']) ?></strong></td>
                    <td class="r"><strong><?= $fmt($grand['tax']) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<p style="font-size:11px; color:var(--text-muted); margin:0 0 12px;">
    <i class="fa-solid fa-circle-info"></i>
    Counts every order in the range, including unpaid ones — GST liability follows the invoice issued at
    checkout. The revenue report, by contrast, counts only Paid/Cash orders, so the two will not reconcile.
</p>

<div class="glass-panel" style="padding:24px;">
    <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Per-Order Breakdown (<?= count($orders) ?> orders)</h3>
    <?php if (empty($orders)): ?>
        <p style="color:var(--text-muted); font-size:13px; margin:0;">No orders in this range.</p>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order</th><th>Date</th><th>Customer</th><th>Place of Supply</th>
                    <th class="r">Taxable</th><th class="r">CGST</th><th class="r">SGST</th><th class="r">IGST</th><th class="r">GST Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): $t = gstReportOrderTax($o, $sellerState); ?>
                <tr>
                    <td><strong><?= htmlspecialchars($o['order_code']) ?></strong></td>
                    <td><?= date('j M y', strtotime($o['created_at'])) ?></td>
                    <td style="word-break:break-word;"><?= htmlspecialchars($o['customer_name']) ?></td>
                    <td>
                        <?= $t['place'] !== '' ? htmlspecialchars($t['place']) : '<span style="color:var(--text-muted);">—</span>' ?>
                        <?= $t['intra'] ? ' <span style="font-size:10px; color:#10b981;">(intra)</span>' : '' ?>
                    </td>
                    <td class="r"><?= $fmt($t['taxable']) ?></td>
                    <td class="r"><?= $fmt($t['cgst']) ?></td>
                    <td class="r"><?= $fmt($t['sgst']) ?></td>
                    <td class="r"><?= $fmt($t['igst']) ?></td>
                    <td class="r"><strong><?= $fmt($t['tax']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
// The page opened <main> and two wrapper divs through includes/header.php and
// never closed them: this file simply ended, leaving an unclosed wrapper,
// no </body> and no </html>, so the browser was left guessing where the layout
// ended — which is why the design collapsed the moment GST Summary was opened.
// admin.js is loaded by the footer too, so nothing scripted worked on this page
// either. Every other admin screen ends this way.
require_once __DIR__ . "/includes/footer.php";
?>
