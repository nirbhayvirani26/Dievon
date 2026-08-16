<?php
$activeTab = (isset($_GET['view']) && $_GET['view'] === 'invoices') ? 'invoices' : 'orders';

// Role check runs BEFORE includes/header.php: that file starts the session
// and immediately prints the page chrome, so a check placed after it can
// still refuse the content but can no longer set a 403 — the headers are
// already sent and the browser is told 200 OK.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('orders.view');
require_once 'includes/header.php';

?>
        
        <div class="glass-panel" style="padding:24px; overflow:hidden;">
            <?php if (empty($orders)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">📋</div>
                <p style="font-size:16px;">No orders yet. Share your shop and orders will appear here!</p>
                <a href="../shop.php" target="_blank" class="btn-primary" style="margin-top:20px; display:inline-flex;">
                    <i class="fa-solid fa-globe"></i> Open Shop
                </a>
            </div>
            <?php else: ?>

            <!-- Customer Name Filter -->
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px; background:rgba(255,255,255,0.05); padding:12px 16px; border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted); font-size:14px;"></i>
                <input type="text" id="orderNameFilter"
                       placeholder="Filter by customer name…"
                       class="form-control"
                       style="font-size:13px; padding:6px 12px; height:auto; background:var(--bg-main); max-width:320px;"
                       oninput="filterOrdersByName(this.value)">
                <span id="orderFilterCount" style="font-size:12px; color:var(--text-muted); margin-left:4px;"></span>
            </div>

            <div class="table-wrapper">
                <table class="data-table" id="ordersTable" style="table-layout:fixed; width:100%;">
                    <colgroup>
                        <col style="width:36px;">
                        <col style="width:110px;">
                        <col style="width:160px;">
                        <col style="width:180px;">
                        <col style="width:80px;">
                        <col style="width:120px;">
                        <col style="width:120px;">
                        <col style="width:100px;">
                        <col style="width:90px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th onclick="toggleSort('status')" style="cursor:pointer; user-select:none; white-space:nowrap;">
                                Status <i id="sort-icon-status" class="fa-solid fa-sort" style="margin-left:4px; opacity:0.5; font-size:12px;"></i>
                            </th>
                            <th onclick="toggleSort('payment')" style="cursor:pointer; user-select:none; white-space:nowrap;">
                                Payment <i id="sort-icon-payment" class="fa-solid fa-sort" style="margin-left:4px; opacity:0.5; font-size:12px;"></i>
                            </th>
                            <th onclick="toggleSort('date')" style="cursor:pointer; user-select:none; white-space:nowrap;">
                                Date <i id="sort-icon-date" class="fa-solid fa-sort-down" style="margin-left:4px; opacity:1; font-size:12px;"></i>
                            </th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order):
                            $items = orderItems($order['items_json']);
                            $statusClass = 'status-' . strtolower(str_replace(' ', '-', $order['status']));
                        ?>
                        <tr id="row-<?= $order['id'] ?>" class="order-row" data-id="<?= $order['id'] ?>" data-date="<?= strtotime($order['created_at']) ?>" data-status="<?= htmlspecialchars($order['status']) ?>" data-payment-status="<?= htmlspecialchars($order['payment_status'] ?? 'Unpaid') ?>" data-payment-method="<?= htmlspecialchars($order['payment_method'] ?? 'later') ?>">
                            <td>
                                <button class="expand-btn" onclick="toggleDetail(<?= $order['id'] ?>)" title="View details">
                                    <i class="fa-solid fa-chevron-down" id="icon-<?= $order['id'] ?>"></i>
                                </button>
                            </td>
                            <td>
                                <span style="font-weight:700; font-family:var(--font-heading); color:var(--color-primary); letter-spacing:1px; font-size:12px;">
                                    <?= htmlspecialchars($order['order_code']) ?>
                                </span>
                                <?php if (!empty($order['invoice_number'])): ?>
                                <div style="font-size:10px; color:var(--text-muted); font-weight:600; letter-spacing:0.5px;">
                                    <?= htmlspecialchars($order['invoice_number']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= htmlspecialchars($order['customer_name']) ?>
                                <?php if (isset($repeatPhoneSet[$order['phone']])): ?>
                                <span style="display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; padding:2px 6px; border-radius:20px; background:rgba(139,92,246,0.15); color:#8b5cf6; margin-left:4px;">🔁</span>
                                <?php endif; ?>
                            </td>
                            <td style="overflow:hidden;">
                                <?php
                                    $totalQty = array_sum(array_column($items, 'quantity'));
                                    $firstItem = $items[0] ?? null;
                                ?>
                                <?php if ($firstItem): ?>
                                <span class="items-pill" style="font-size:11px;"><?= htmlspecialchars($firstItem['emoji'] ?? '✨') ?> ×<?= (int)$firstItem['quantity'] ?></span>
                                <?php if (count($items) > 1): ?>
                                <span style="font-size:11px; color:var(--text-muted); font-weight:600;">+<?= count($items) - 1 ?> more</span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700; font-family:var(--font-heading); color:var(--color-primary); font-size:14px;">
                                <?php $ordSym = orderCurrencySymbol($order); ?>
                                <?= $ordSym . number_format($order['total_price'], 2) ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>" style="font-size:11px; padding:3px 8px;">
                                    <?= htmlspecialchars($order['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    /* Labels match admin/assets/js/admin.js:72-74 word for word.
                                       That map rewrites this badge after a save, and it read
                                       'Paid Online' / 'Cash Received' / 'Not Paid' while this
                                       printed 'Paid' / 'Cash' / 'Unpaid' — so the same order
                                       renamed itself the moment you pressed Save, for a state
                                       that had not changed. */
                                    $ps = $order['payment_status'] ?? 'Unpaid';
                                    $pm = strtolower(trim((string)($order['payment_method'] ?? 'later')));
                                    $payGw = orderIsGatewayPaid($order);

                                    if ($ps === 'Paid' && $payGw) {
                                        $payIcon = '<i class="fa-solid fa-credit-card" style="color:#10b981;"></i>';
                                        $payColor = '#10b981';
                                        $payBg = 'rgba(16,185,129,0.1)';
                                        $payLabel = 'Paid Online';
                                    } elseif ($ps === 'Paid') {
                                        /* Marked Paid with no Razorpay payment behind it. Calling that
                                           "Paid Online" in the same green as a gateway-confirmed order
                                           would hide the one case worth looking at — and this is the
                                           state that silently turns a refund manual. */
                                        $payIcon = '<i class="fa-solid fa-triangle-exclamation" style="color:#8a5a00;"></i>';
                                        $payColor = '#8a5a00';
                                        $payBg = '#fff4e5';
                                        $payLabel = 'Paid, no record';
                                    } elseif ($ps === 'Cash') {
                                        $payIcon = '<i class="fa-solid fa-money-bill-wave" style="color:#f59e0b;"></i>';
                                        $payColor = '#f59e0b';
                                        $payBg = 'rgba(245,158,11,0.1)';
                                        /* 'Cash' is the stored value for any payment the shop confirms by
                                           hand, not only notes handed to a courier — the dropdown that sets
                                           it is labelled "Payment Received" for that reason. This badge said
                                           "Cash Received" whatever the method, so a UK bank transfer against
                                           a Pay-later order was reported on the orders screen as cash in
                                           hand. Same stored value, wording that matches how they paid. */
                                        $payLabel = ($pm === 'cod') ? 'Cash Received' : 'Payment Received';
                                    } else {
                                        $payIcon = '<i class="fa-solid fa-clock" style="color:var(--text-muted);"></i>';
                                        $payColor = 'var(--text-muted)';
                                        $payBg = 'rgba(100,100,100,0.08)';
                                        $payLabel = 'Not Paid';
                                    }

                                    // How the shopper chose to pay. $pm was already being read here
                                    // and then never used, so the row showed WHETHER money had
                                    // arrived but never HOW it was meant to.
                                    $pmLabel = ['online' => 'Online', 'cod' => 'COD', 'later' => 'Pay later'][$pm]
                                             ?? ucfirst($pm);
                                ?>
                                <span id="pay-badge-<?= $order['id'] ?>" style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 8px; border-radius:20px; background:<?= $payBg ?>; color:<?= $payColor ?>; white-space:nowrap;">
                                    <?= $payIcon ?> <?= $payLabel ?>
                                </span>
                                <?php /* Deliberately OUTSIDE the badge span. admin.js rewrites that
                                         span's innerHTML on save; anything placed inside would be
                                         wiped. The method is also not what the save changes — an
                                         order paid by COD is still COD after you mark it received. */ ?>
                                <div style="font-size:10px; color:var(--text-muted); font-weight:600; letter-spacing:0.4px; margin-top:3px; white-space:nowrap;"
                                     title="How the customer chose to pay at checkout">
                                    <?= htmlspecialchars($pmLabel) ?>
                                </div>
                            </td>
                            <td style="color:var(--text-secondary); font-size:12px; white-space:nowrap;">
                                <?= date('d M y', strtotime($order['created_at'])) ?>
                                <div style="font-size:11px; color:var(--text-muted);"><?= date('H:i', strtotime($order['created_at'])) ?></div>
                            </td>
                            <td style="text-align:right;">
                                <div class="admin-actions">
                                    <button class="expand-btn admin-action-btn" onclick="toggleDetail(<?= $order['id'] ?>)" title="Details" aria-label="View details for order <?= htmlspecialchars($order['order_code']) ?>">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button class="admin-action-btn is-danger" onclick="deleteOrder(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>')" title="Delete" aria-label="Delete order <?= htmlspecialchars($order['order_code']) ?>">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <!-- Expandable detail row -->
                        <tr class="order-detail-row" id="detail-<?= $order['id'] ?>">
                            <td colspan="9">
                                <div class="order-detail-inner">
                                    <!-- Customer Info Strip -->
                                    <div style="display:flex; flex-wrap:wrap; gap:20px; margin-bottom:16px; padding:12px 14px; background:var(--bg-main); border-radius:var(--radius-sm); border:1px solid var(--border-light); font-size:13px;">
                                        <div style="display:flex; align-items:center; gap:7px;">
                                            <i class="fa-solid fa-user" style="color:var(--color-primary); width:14px;"></i>
                                            <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:7px; color:var(--text-secondary);">
                                            <i class="fa-solid fa-phone" style="color:var(--color-secondary); width:14px;"></i>
                                            <?= htmlspecialchars($order['phone']) ?>
                                        </div>
                                        <?php if (!empty($order['customer_email'])): ?>
                                        <div style="display:flex; align-items:center; gap:7px; color:var(--text-secondary);">
                                            <i class="fa-solid fa-envelope" style="color:#8b5cf6; width:14px;"></i>
                                            <a href="mailto:<?= htmlspecialchars($order['customer_email']) ?>" style="color:var(--text-secondary); text-decoration:none;"><?= htmlspecialchars($order['customer_email']) ?></a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="order-detail-grid">
                                        <div class="order-detail-field">
                                            <label>📍 Delivery Address</label>
                                            <p><?= nl2br(htmlspecialchars($order['address'])) ?></p>
                                        </div>
                                        <div class="order-detail-field">
                                            <label>📝 Special Notes</label>
                                            <p><?= !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : '<span style="color:var(--text-muted);">None</span>' ?></p>
                                        </div>
                                    </div>
                                    <!-- Items table -->
                                    <table style="width:100%; border-collapse:collapse; margin-bottom:16px;">
                                        <thead>
                                            <tr style="border-bottom:1px solid var(--border-color);">
                                                <th style="text-align:left; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Item</th>
                                                <th style="text-align:center; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Qty</th>
                                                <th style="text-align:right; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Price</th>
                                                <th style="text-align:right; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($items as $it):
                                            $adminItBits = [];
                                            if (!empty($it['color_name'])) { $adminItBits[] = 'Colour: ' . htmlspecialchars($it['color_name']); }
                                            if (!empty($it['variant_name'])) { $adminItBits[] = htmlspecialchars($it['variant_name']); }

                                            /* What is needed to actually fulfil the line.
                                               ────────────────────────────────────────────
                                               This row named the garment and its size and
                                               nothing else, so an order gave no way to tell
                                               which supplier made it or what they call it —
                                               it had to be remembered, or looked up product
                                               by product.

                                               Read from the ORDER's own snapshot, never from
                                               the product row, so an order still names the
                                               supplier who filled it after the product has
                                               been moved to a different one. Older orders
                                               predate these fields and simply show nothing,
                                               which is honest — they were never recorded. */
                                            $adminItRef = [];
                                            if (!empty($it['sku']))          { $adminItRef[] = htmlspecialchars($it['sku']); }
                                            if (!empty($it['supplier']))     { $adminItRef[] = htmlspecialchars($it['supplier']); }

                                            /* The supplier's own design number — and a warning when
                                               there isn't one.
                                               ────────────────────────────────────────────────────
                                               A line reading "DV-GEN-0001 · Dwarkesh" looks complete
                                               and is not: DV-GEN-0001 is OUR sku, generated here, and
                                               the supplier has never seen it. Reordering that garment
                                               needs THEIR number, so a line missing it cannot be
                                               fulfilled — and nothing on the page said so.

                                               When the snapshot has no number, the product is asked
                                               for its current one before anything is called missing.
                                               Otherwise filling the field in afterwards would leave
                                               this order warning forever about something already
                                               fixed, and a warning that cannot be cleared stops being
                                               read. Same live-lookup reasoning as the phone above.

                                               Only ever a fallback: a number frozen at order time
                                               still wins, so a product moved to a new supplier cannot
                                               rewrite what the old one called it. */
                                            $dvRefMissing = false;
                                            if (!empty($it['supplier_ref'])) {
                                                $adminItRef[] = htmlspecialchars($it['supplier_ref']);
                                            } elseif (!empty($it['supplier'])) {
                                                $dvProdRefs = $dvProdRefs ?? [];
                                                $dvPid = (int)($it['product_id'] ?? 0);
                                                $dvNowRef = '';
                                                if ($dvPid > 0) {
                                                    if (!array_key_exists($dvPid, $dvProdRefs)) {
                                                        try {
                                                            $dvRq = $pdo->prepare("SELECT supplier_ref FROM products WHERE id = :id");
                                                            $dvRq->execute([':id' => $dvPid]);
                                                            $dvProdRefs[$dvPid] = (string)($dvRq->fetchColumn() ?: '');
                                                        } catch (PDOException $e) { $dvProdRefs[$dvPid] = ''; }
                                                    }
                                                    $dvNowRef = trim($dvProdRefs[$dvPid]);
                                                }
                                                if ($dvNowRef !== '') {
                                                    // Flagged as today's value, not the order's: it was
                                                    // added after this sale, so it describes the product
                                                    // now rather than what was recorded at the time.
                                                    $adminItRef[] = htmlspecialchars($dvNowRef) . ' <span class="order-ref-late">(added later)</span>';
                                                } else {
                                                    $dvRefMissing = true;
                                                }
                                            }

                                            /* The supplier's CURRENT phone, looked up live — deliberately
                                               not frozen with the name beside it.
                                               ────────────────────────────────────────────────────────
                                               The name is a historical fact: this order was supplied by
                                               them, and that never changes. A phone number is the
                                               opposite — it answers "how do I reach them NOW", so an
                                               order from March must show the number they use today, not
                                               the one recorded when it was placed. Freezing it would
                                               hand you a disconnected line exactly when you needed it.

                                               Cached per request so a ten-line order does not run ten
                                               queries for the same supplier. */
                                            if (!empty($it['supplier'])) {
                                                static $dvSupPhones = [];
                                                $supKey = (string)$it['supplier'];
                                                if (!array_key_exists($supKey, $dvSupPhones)) {
                                                    try {
                                                        $ph = $pdo->prepare("SELECT phone, owner_name FROM suppliers WHERE name = :n LIMIT 1");
                                                        $ph->execute([':n' => $supKey]);
                                                        $dvSupPhones[$supKey] = $ph->fetch(PDO::FETCH_ASSOC) ?: [];
                                                    } catch (PDOException $e) { $dvSupPhones[$supKey] = []; }
                                                }
                                                $supMeta = $dvSupPhones[$supKey];
                                                if (!empty($supMeta['owner_name'])) { $adminItRef[] = htmlspecialchars($supMeta['owner_name']); }
                                                if (!empty($supMeta['phone']))      { $adminItRef[] = htmlspecialchars($supMeta['phone']); }
                                            }
                                        ?>
                                        <tr>
                                            <td style="padding:8px; font-size:14px;"><?= htmlspecialchars($it['emoji'] ?? '✨') ?> <?= htmlspecialchars($it['name']) ?><?= $adminItBits ? ' <span style="font-size:11px;color:var(--text-muted);">('.implode(' · ', $adminItBits).')</span>' : '' ?>
                                                <?php if ($adminItRef): ?>
                                                    <span class="order-item-ref"><?= implode(' · ', $adminItRef) ?></span>
                                                <?php endif; ?>
                                                <?php if ($dvRefMissing): ?>
                                                    <?php /* The supplier is named on screen. "1 item needs a
                                                             design no." would send you hunting through the
                                                             order to find which one. */ ?>
                                                    <span class="order-ref-warn">
                                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                                        No design no. for <?= htmlspecialchars($it['supplier']) ?> — you cannot reorder this from them without it.
                                                        <?php if ((int)($it['product_id'] ?? 0) > 0): ?>
                                                            <a href="product_form.php?id=<?= (int)$it['product_id'] ?>">Add it</a>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:8px; text-align:center; color:var(--text-secondary);">× <?= (int)$it['quantity'] ?></td>
                                            <td style="padding:8px; text-align:right; color:var(--text-secondary);"><?= orderCurrencySymbol($order) ?><?= number_format($it['price'], 2) ?></td>
                                            <td style="padding:8px; text-align:right; font-weight:700; color:var(--color-primary);"><?= orderCurrencySymbol($order) ?><?= number_format($it['price'] * $it['quantity'], 2) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <?php 
                                                $subtotal = 0;
                                                foreach ($items as $it) {
                                                    $subtotal += $it['price'] * $it['quantity'];
                                                }
                                            ?>
                                            <tr style="border-top:1px solid var(--border-light);">
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">Subtotal</td>
                                                <td style="padding:6px 8px; text-align:right; color:var(--text-secondary); font-weight:700;"><?= orderCurrencySymbol($order) ?><?= number_format($subtotal, 2) ?></td>
                                            </tr>
                                            <?php if (!empty($order['promo_code']) && $order['discount_amount'] > 0): ?>
                                            <tr>
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">🎟️ Promo: <strong><?= htmlspecialchars($order['promo_code']) ?></strong></td>
                                                <td style="padding:6px 8px; text-align:right; color:#10b981; font-weight:700;">−<?= orderCurrencySymbol($order) ?><?= number_format($order['discount_amount'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ((float)($order['delivery_charge'] ?? 0) > 0): ?>
                                            <tr>
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">🚚 Delivery Charge</td>
                                                <td style="padding:6px 8px; text-align:right; color:#f59e0b; font-weight:700;">+<?= $ordSym . number_format($order['delivery_charge'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ((float)($order['cod_fee'] ?? 0) > 0): ?>
                                            <tr>
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">💵 COD Handling Fee</td>
                                                <td style="padding:6px 8px; text-align:right; color:#f59e0b; font-weight:700;">+<?= $ordSym . number_format($order['cod_fee'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr style="border-top:1px solid var(--border-light);">
                                                <td colspan="3" style="padding:6px 8px; font-weight:700; font-size:15px;">Total</td>
                                                <td style="padding:6px 8px; text-align:right; font-weight:800; font-size:16px; color:var(--color-primary);"><?= $ordSym . number_format($order['total_price'], 2) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    <?php
                                    // ── Reconciliation note ─────────────────────────────
                                    // A Razorpay order whose cart changed between "pay online"
                                    // and submit is recorded at the ACTUAL charged amount (see
                                    // checkout_action.php) — so the line items below can sum to
                                    // less than total_price. Without this note that looks like
                                    // a data error, and an admin might "correct" the amount
                                    // against what the bank actually collected.
                                    $recParts = round($subtotal - (float)($order['discount_amount'] ?? 0)
                                                   + (float)($order['delivery_charge'] ?? 0)
                                                   + (float)($order['cod_fee'] ?? 0), 2);
                                    $recTotal = round((float)$order['total_price'], 2);
                                    if (abs($recParts - $recTotal) > 0.01 && !empty($order['razorpay_payment_id'])): ?>
                                    <div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:12px; color:#92400e; margin-bottom:14px;">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        <strong>Recorded at the charged amount.</strong> The items below sum to
                                        <?= $ordSym . number_format($recParts, 2) ?>, but the total is
                                        <?= $ordSym . number_format($recTotal, 2) ?> because the cart changed after the
                                        Razorpay payment was taken. The amount stored is what the customer was actually
                                        charged — do not adjust it to match the line items.
                                        <?php if (!empty($order['razorpay_payment_id'])): ?>
                                        <span style="font-family:monospace;">(payment <?= htmlspecialchars($order['razorpay_payment_id']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <!-- Status + Payment + Invoice Print row -->
                                     <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:14px; background:var(--bg-main); border-radius:var(--radius-sm); border:1px solid var(--border-light);">

                                         <?php /* Saving a status emails the customer. EmailService only suppresses a
                                                  REPEAT of the same status (sendOrderStatusEmail():702), so stepping an
                                                  order through every state sends five separate emails for one purchase.

                                                  On its own full-width line — flex:1 0 100% forces the wrap — rather than
                                                  inline with the controls. In the row it was one more item competing for
                                                  horizontal space and it pushed the Courier and AWB fields off the right
                                                  edge. A note about being careful should not cost you the two fields you
                                                  actually need to fill in.

                                                  Not a title="" tooltip either. The first version was, and the person it
                                                  was written for could not find it — which is the whole failure of a
                                                  tooltip: only someone who already suspects it is there will read it. */ ?>
                                         <div style="flex:1 0 100%; font-size:11px; color:#8a5a00; background:#fff4e5; border:1px solid #fde68a; border-radius:4px; padding:6px 10px;">
                                             <i class="fa-solid fa-envelope"></i>
                                             Saving emails the customer &middot; <strong>Confirmed &rarr; Shipped &rarr; Delivered</strong> is usually enough
                                         </div>

                                         <!-- Order Status -->
                                         <div style="display:flex; align-items:center; gap:8px;">
                                             <label style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; white-space:nowrap;">Order Status</label>
                                             <select class="status-select" id="status-<?= $order['id'] ?>">
                                                 <?php
                                                   // Only what a person may actually set. Refunded and Returned
                                                   // are outcomes — the Refund panel and the Returns screen set
                                                   // them once money or goods have genuinely moved. Offering
                                                   // them here let an order clerk reach Refunded in one click,
                                                   // restoring stock and dropping the sale from the GST report
                                                   // with no money sent and no refund recorded.
                                                   //
                                                   // An order ALREADY in one of those states must still show it,
                                                   // or this dropdown would misreport the order as whatever
                                                   // happens to be first in the list. Shown selected and
                                                   // disabled: it reads correctly and cannot be re-submitted.
                                                   $ordStatus  = (string)$order['status'];
                                                   $selectable = manuallySettableOrderStatuses();
                                                 ?>
                                                 <?php if (!in_array($ordStatus, $selectable, true)): ?>
                                                 <option value="<?= htmlspecialchars($ordStatus) ?>" selected disabled><?= htmlspecialchars($ordStatus) ?> — set automatically</option>
                                                 <?php endif; ?>
                                                 <?php foreach ($selectable as $s): ?>
                                                 <option value="<?= $s ?>" <?= $ordStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
                                                 <?php endforeach; ?>
                                             </select>
                                             <?php // Courier and AWB, sent with the status change.
                                                   //
                                                   // update_order.php has always accepted tracking_number and carrier,
                                                   // the status email has always included them and the customer's
                                                   // account page has always displayed them — but nothing in this
                                                   // panel ever asked for them, so they could not be filled in from
                                                   // anywhere. The whole tracking feature was wired at both ends and
                                                   // open in the middle.
                                                   //
                                                   // The courier list comes from Store Settings, so the names stay
                                                   // consistent between orders instead of being retyped each time.
                                                   $courierList = array_values(array_filter(array_map('trim',
                                                       explode(',', (string)storeSetting($pdo, 'courier_partners', '')))));
                                             ?>
                                             <input list="courier-options" class="status-select" id="carrier-<?= $order['id'] ?>"
                                                    value="<?= htmlspecialchars((string)($order['carrier'] ?? '')) ?>"
                                                    placeholder="Courier" style="width:120px;"
                                                    title="Which courier is carrying this parcel">
                                             <input type="text" class="status-select" id="tracking-<?= $order['id'] ?>"
                                                    value="<?= htmlspecialchars((string)($order['tracking_number'] ?? '')) ?>"
                                                    placeholder="AWB / tracking no." style="width:150px;" maxlength="100"
                                                    title="Tracking number the courier gave you">
                                             <button class="btn-sm btn-sm-primary" onclick="updateStatus(<?= $order['id'] ?>, '<?= $order['order_code'] ?>')">
                                                 <i class="fa-solid fa-check"></i> Save
                                             </button>
                                         </div>

                                         <div style="width:1px; height:32px; background:var(--border-light);"></div>

                                         <!-- Payment Status -->
                                         <div style="display:flex; align-items:center; gap:8px;">
                                             <label style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Payment</label>
                                             <?php if (orderIsGatewayPaid($order)): ?>
                                                 <?php /* Razorpay confirmed this money server-to-server, so there is nothing
                                                          here for a person to decide. Shown as a fact carrying its payment
                                                          reference rather than as a control — a disabled dropdown still reads
                                                          as "something you could change", and this is not one.
                                                          update_order.php refuses the change anyway; this saves the click. */ ?>
                                                 <span style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:6px 12px; border-radius:4px; background:#e6f7ec; color:#2e7d32;"
                                                       title="Confirmed by Razorpay. Use the Refund panel to return money to the customer.">
                                                     <i class="fa-solid fa-lock"></i> Paid online
                                                 </span>
                                                 <span style="font-size:11px; color:var(--text-muted); font-family:monospace;"><?= htmlspecialchars((string)$order['razorpay_payment_id']) ?></span>
                                             <?php else: ?>
                                                 <?php /* 'Paid' is not offered. It means "a gateway verified this", which no
                                                          person can declare — see manuallySettablePaymentStatuses(). Money the
                                                          shop confirms itself, in whatever form, is recorded as Cash. */ ?>
                                                 <?php /* An order already holding 'Paid' must still SHOW 'Paid'.
                                                          Removing that option from the list did not remove it from the
                                                          orders table, so a paid order fell back to displaying the first
                                                          option — "Not Paid" — and one press of Save would have written
                                                          that back, demoting a paid order and blocking its own refund.

                                                          Shown selected and disabled: it reads truthfully and cannot be
                                                          re-submitted. Same treatment the Order Status dropdown above
                                                          already gives Refunded and Returned. */ ?>
                                                 <select class="status-select" id="pstatus-<?= $order['id'] ?>">
                                                     <?php if (($order['payment_status'] ?? '') === 'Paid'): ?>
                                                         <option value="Paid" selected disabled>✅ Paid — set by gateway</option>
                                                     <?php endif; ?>
                                                     <option value="Unpaid" <?= ($order['payment_status'] ?? 'Unpaid') === 'Unpaid' ? 'selected' : '' ?>>⏳ Not Paid</option>
                                                     <option value="Cash"   <?= ($order['payment_status'] ?? '') === 'Cash'   ? 'selected' : '' ?>>💵 Payment Received</option>
                                                 </select>
                                                 <button class="btn-sm btn-sm-primary" onclick="updatePaymentStatus(<?= $order['id'] ?>)">
                                                     <i class="fa-solid fa-check"></i> Save
                                                 </button>
                                                 <?php /* An order already holding 'Paid' with no gateway reference cannot be
                                                          represented by the two options above, and quietly showing "Not Paid"
                                                          would be a lie about money. Flag it instead of hiding it. */ ?>
                                                 <?php if (($order['payment_status'] ?? '') === 'Paid'): ?>
                                                     <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:4px; background:#fff4e5; color:#8a5a00;"
                                                           title="Marked Paid by hand — no Razorpay payment is attached to this order. If the money did arrive, set it to Payment Received.">
                                                         ⚠ Paid, no gateway record
                                                     </span>
                                                 <?php endif; ?>
                                             <?php endif; ?>
                                             <?php
                                             // Remittance = the courier paying the shop, which happens AFTER the
                                             // cash was handed over at delivery (payment_status 'Cash'). Only a
                                             // delivered COD order can have one recorded.
                                             $oIsCodCash = strtolower((string)($order['payment_method'] ?? '')) === 'cod'
                                                         && (string)($order['payment_status'] ?? '') === 'Cash';
                                             ?>
                                             <?php if ($oIsCodCash): ?>
                                                 <button class="btn-sm btn-sm-outline" style="background:#ffffff; color:#1e293b; border:1px solid #cbd5e1; font-weight:600; white-space:nowrap;"
                                                         onclick="recordRemittance(<?= $order['id'] ?>, <?= (float)$order['total_price'] ?>)">
                                                     <?= !empty($order['remitted_at']) ? '✏️ Update Remittance' : '💸 Record Remittance' ?>
                                                 </button>
                                                 <?php if (!empty($order['remitted_at'])): ?>
                                                     <span style="font-size:11px; font-weight:700; color:#10b981; white-space:nowrap;">
                                                         ✓ <?= currencySymbol() ?><?= number_format((float)($order['remitted_amount'] ?? 0), 2) ?> &middot; <?= date('j M', strtotime($order['remitted_at'])) ?>
                                                     </span>
                                                 <?php endif; ?>
                                             <?php endif; ?>
                                         </div>

                                         <div style="width:1px; height:32px; background:var(--border-light);"></div>

                                         <!-- Invoice & Shipping Label Printing -->
                                         <div style="display:flex; align-items:center; gap:8px;">
                                             <button class="btn-sm btn-sm-outline" onclick='printTaxInvoice(<?= htmlspecialchars(json_encode($order), ENT_QUOTES) ?>)' style="background:#ffffff; color:#1e293b; border:1px solid #cbd5e1; font-weight:600;">
                                                 <i class="fa-solid fa-file-invoice"></i> Tax Invoice
                                             </button>
                                             <button class="btn-sm btn-sm-outline" onclick='printShippingLabel(<?= htmlspecialchars(json_encode($order), ENT_QUOTES) ?>)' style="background:#ffffff; color:#1e293b; border:1px solid #cbd5e1; font-weight:600;">
                                                 <i class="fa-solid fa-tag"></i> Shipping Label
                                             </button>
                                         </div>

                                         <span id="status-msg-<?= $order['id'] ?>" style="font-size:12px; color:var(--color-secondary);"></span>
                                     </div>

                                     <?php
                                     // ── Refund panel ──────────────────────────────────────
                                     // Only shown where a refund is actually possible, so the
                                     // button cannot be pressed on an unpaid order.
                                     $oPaid       = (float)($order['total_price'] ?? 0);
                                     $oRefunded   = (float)($order['refunded_amount'] ?? 0);
                                     $oRefundable = max(0, round($oPaid - $oRefunded, 2));
                                     $oIsPaid     = in_array((string)($order['payment_status'] ?? ''), ['Paid', 'Cash'], true);
                                     // Same rule the refund itself applies — orderIsOnlineRefundable().
                                     // This tested only method + reference and skipped payment_status,
                                     // so it could hide the Cash/UPI/Bank dropdown and promise an
                                     // automatic Razorpay refund on an order RefundService would then
                                     // record as manual: no money sent, no payout channel recorded.
                                     $oOnline     = orderIsOnlineRefundable($order);
                                     ?>
                                     <?php if ($oIsPaid): ?>
                                     <div class="refund-panel">
                                         <div class="refund-panel-head">
                                             <span class="refund-panel-title"><i class="fa-solid fa-rotate-left"></i> Refund</span>
                                             <span class="refund-panel-meta">
                                                 <?php if ($oRefunded > 0): ?>
                                                     Already refunded <strong><?= formatPrice($oRefunded) ?></strong> of <?= formatPrice($oPaid) ?>.
                                                 <?php endif; ?>
                                                 <?php if ($oRefundable > 0): ?>
                                                     Refundable now: <strong><?= formatPrice($oRefundable) ?></strong>
                                                     <?= $oOnline ? '&mdash; goes back through Razorpay automatically'
                                                                  : '&mdash; not paid online, this only records the refund' ?>
                                                 <?php else: ?>
                                                     <strong>Fully refunded.</strong>
                                                 <?php endif; ?>
                                             </span>
                                         </div>
                                         <?php // The controls only render for someone who may actually use
                                               // them. update_order.php enforces refunds.manage regardless —
                                               // this just avoids offering a button that answers "you do not
                                               // have permission" to the order staff who see this screen most. ?>
                                         <?php if ($oRefundable > 0 && adminCan('refunds.manage')): ?>
                                         <div class="refund-panel-row">
                                             <label for="refund-amt-<?= $order['id'] ?>">Amount</label>
                                             <input type="number" step="0.01" min="0.01" max="<?= $oRefundable ?>"
                                                    id="refund-amt-<?= $order['id'] ?>" value="<?= $oRefundable ?>"
                                                    class="refund-input refund-input-amount">
                                             <input type="text" id="refund-reason-<?= $order['id'] ?>"
                                                    placeholder="Reason (optional)" maxlength="200"
                                                    class="refund-input refund-input-reason">
                                             <?php // Manual refunds (cash/COD/bank) go back by hand — record the
                                                   // channel so the books say where the money went. Online refunds
                                                   // go back through Razorpay, so no channel is offered. ?>
                                             <?php if (!$oOnline): ?>
                                             <select id="refund-channel-<?= $order['id'] ?>" class="refund-input" style="max-width:150px;">
                                                 <option value="">Payout method…</option>
                                                 <option value="cash">💵 Cash</option>
                                                 <option value="upi">📱 UPI</option>
                                                 <option value="bank">🏦 Bank transfer</option>
                                             </select>
                                             <?php endif; ?>
                                             <button class="btn-sm btn-sm-danger"
                                                     onclick="issueRefund(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>', <?= $oRefundable ?>, <?= $oOnline ? 'true' : 'false' ?>)">
                                                 <i class="fa-solid fa-indian-rupee-sign"></i> Refund
                                             </button>
                                             <span id="refund-msg-<?= $order['id'] ?>" class="refund-msg"></span>
                                         </div>
                                         <?php elseif ($oRefundable > 0): ?>
                                         <?php // Says why the controls are absent. Otherwise the panel reads
                                               // "Refundable now: ₹344" with no way to act and no explanation. ?>
                                         <div class="refund-panel-row">
                                             <span class="refund-msg">Refunds are handled by the shop owner — ask them to process this one.</span>
                                         </div>
                                         <?php endif; ?>
                                     </div>
                                     <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            // One shared datalist for every courier box on the page. Suggestions
            // only — the input stays free text, so a one-off courier can still be
            // typed without first adding it to Store Settings.
            $courierOptions = array_values(array_filter(array_map('trim',
                explode(',', (string)storeSetting($pdo, 'courier_partners', '')))));
            ?>
            <datalist id="courier-options">
                <?php foreach ($courierOptions as $co): ?>
                <option value="<?= htmlspecialchars($co) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <?php if (empty($courierOptions)): ?>
            <p style="font-size:12px; color:var(--text-muted); margin-top:10px;">
                <i class="fa-solid fa-circle-info"></i>
                Add your couriers under <a href="settings.php?tab=settings">Store Settings → Courier partners</a>
                (comma separated) and they will appear as suggestions in the courier box.
            </p>
            <?php endif; ?>

            <!-- Summary Counter Bar at Bottom of Table -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding: 14px 18px; background: var(--bg-main); border-radius: var(--radius-sm); border: 1px solid var(--border-light); font-size: 13px; color: var(--text-secondary);">
                <div>
                    <i class="fa-solid fa-list-check" style="color: var(--color-primary); margin-right: 6px;"></i>
                    <strong>Total Orders Displayed:</strong> <span id="ordersTotalDisplayCount"><?= count($orders) ?></span> of <?= count($orders) ?> Orders
                </div>
                <div style="display: flex; gap: 18px; font-size: 12px;">
                    <span>⏳ <strong>Pending:</strong> <?= count(array_filter($orders, fn($o) => strtolower($o['status']) === 'pending')) ?></span>
                    <span>🚚 <strong>Dispatched:</strong> <?= count(array_filter($orders, fn($o) => strtolower($o['status']) === 'dispatched')) ?></span>
                    <span>✅ <strong>Delivered:</strong> <?= count(array_filter($orders, fn($o) => strtolower($o['status']) === 'delivered')) ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
function printTaxInvoice(order) {
    // Opens the ONE shared invoice template (includes/invoice_template.php) rather
    // than building a second copy of the HTML here. This used to be ~110 lines of
    // document.write() markup that had already drifted from the customer invoice —
    // different fields, no logo, its own CSS. One template now serves both.
    window.open('print_invoice.php?code=' + encodeURIComponent(order.order_code), '_blank');
}

function printShippingLabel(order) {
    const items = typeof order.items_json === 'string' ? JSON.parse(order.items_json) : (order.items_json || []);

    // Every order field is escaped before it reaches the label markup.
    //
    // Name, address, phone and postcode were interpolated raw, so an address
    // containing "<" — "Flat 3 <rear entrance>", "H.No 12 <opp. temple>" — had
    // everything from that character swallowed by the browser as a tag, and the
    // parcel went out with a silently truncated address. Nobody would see it
    // happen: the label just looks short. Defined locally because this markup is
    // written into a brand-new window that loads none of the admin scripts.
    const e = (s) => String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const win = window.open('', '_blank', 'width=600,height=700');
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>SHIPPING LABEL - ${e(order.order_code)}</title>
            <style>
                body { font-family: 'Courier New', Courier, monospace; padding: 20px; color: #000; }
                .label-box { border: 4px solid #000; padding: 20px; width: 100%; max-width: 480px; margin: 0 auto; background: #fff; }
                .header-row { display: flex; justify-content: space-between; border-bottom: 3px solid #000; padding-bottom: 10px; margin-bottom: 15px; }
                .ship-title { font-size: 20px; font-weight: 900; letter-spacing: 2px; }
                .section { margin-bottom: 15px; border-bottom: 1px solid #000; padding-bottom: 10px; }
                .label-title { font-size: 10px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; text-decoration: underline; }
                .recipient-name { font-size: 20px; font-weight: 900; margin: 4px 0; }
                .recipient-addr { font-size: 14px; font-weight: 700; line-height: 1.4; }
                .barcode-box { text-align: center; font-size: 28px; letter-spacing: 6px; font-weight: 900; border: 2px dashed #000; padding: 10px; margin-top: 15px; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body onload="window.print()">
            <div class="label-box">
                <div class="header-row">
                    <div class="ship-title">EXPRESS PARCEL</div>
                    <div style="font-weight:700;">PRIORITY STANDARD</div>
                </div>

                <?php
                // Sender block on the shipping label. This was hardcoded to
                // "100 Mayfair Luxury Way, London, UK" with a UK phone — printed
                // onto every parcel of an India-based, GST-registered business,
                // so a returned parcel had nowhere real to go.
                //
                // Read from Store Settings, falling back to the constants in
                // config.php. If no street address has been set, the line is
                // omitted rather than filled with an invented one: a label with
                // a missing address is obvious, a label with a wrong address is not.
                $senderName  = trim((string)storeSetting($pdo, 'site_name', SHOP_NAME));
                // seller_address is the key admin/settings.php actually writes (:71, :496).
                // This read 'store_address', which nothing has ever written, so every
                // shipping label printed the "[ Set your return address in Store
                // Settings ]" placeholder however carefully the owner filled the form in.
                $senderAddr  = trim((string)storeSetting($pdo, 'seller_address', ''));
                $senderPhone = trim((string)storeSetting($pdo, 'contact_phone', SHOP_PHONE));
                ?>
                <div class="section">
                    <div class="label-title">FROM (SENDER):</div>
                    <div style="font-weight:700; font-size:13px;"><?= htmlspecialchars($senderName) ?></div>
                    <?php if ($senderAddr !== ''): ?>
                        <div style="font-size:11px;"><?= nl2br(htmlspecialchars($senderAddr)) ?></div>
                    <?php else: ?>
                        <div style="font-size:11px; color:#b45309;">[ Set your return address in Store Settings ]</div>
                    <?php endif; ?>
                    <?php if ($senderPhone !== ''): ?>
                        <div style="font-size:11px;">TEL: <?= htmlspecialchars($senderPhone) ?></div>
                    <?php endif; ?>
                </div>

                <div class="section" style="background:#f1f5f9; padding:10px;">
                    <div class="label-title">TO (RECIPIENT):</div>
                    <div class="recipient-name">${e(order.customer_name)}</div>
                    <?php // Escape FIRST, then turn newlines into <br> — the other
                          // way round would escape the <br> tags themselves. ?>
                    <div class="recipient-addr">${e(order.address).replace(/\n/g, '<br>')}</div>
                    <div style="font-size:13px; font-weight:700; margin-top:6px;">TEL: ${e(order.phone)}</div>
                    ${order.postcode ? `<div style="font-size:13px; font-weight:700; margin-top:2px;">PIN / POSTCODE: ${e(order.postcode)}</div>` : ''}
                </div>

                <div class="section" style="border:none;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:700;">
                        <div>ORDER #: ${e(order.order_code)}</div>
                        <div>ITEMS: ${items.length} PCS</div>
                    </div>
                    <div style="font-size:11px; margin-top:4px;">CONTENTS: LUXURY GARMENTS / ACCESSORIES</div>
                    <div style="font-size:11px; font-weight:700; color:#b91c1c; margin-top:4px;">⚠️ HANDLE WITH CARE - FRAGILE ATELIER PACKAGING</div>
                    ${order.payment_method === 'cod' ? `
                    <div style="font-size:16px; font-weight:900; border:3px solid #000; padding:8px 10px; margin-top:10px; text-align:center; letter-spacing:1px;">
                        💵 COLLECT ON DELIVERY: ${order.currency_symbol || '₹'}${Number(order.total_price).toFixed(2)}
                    </div>` : ''}
                </div>

                <div class="barcode-box">
                    ||| | |||| ||| ||||||| |<br>
                    <span style="font-size:14px; letter-spacing:2px;">*${e(order.order_code)}*</span>
                </div>
            </div>
        </body>
        </html>
    `);
    win.document.close();
}
</script>

<?php require_once 'includes/footer.php'; ?>
