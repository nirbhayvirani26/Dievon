<?php
$activeTab = (isset($_GET['view']) && $_GET['view'] === 'invoices') ? 'invoices' : 'orders';
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
                            $items = json_decode($order['items_json'], true) ?? [];
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
                                <?php $ordSym = !empty($order['currency_symbol']) ? $order['currency_symbol'] : (!empty($order['currency']) && $order['currency'] === 'INR' ? '₹' : (!empty($order['currency']) && $order['currency'] === 'USD' ? '$' : '£')); ?>
                                <?= $ordSym . number_format($order['total_price'], 2) ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>" style="font-size:11px; padding:3px 8px;">
                                    <?= htmlspecialchars($order['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $ps = $order['payment_status'] ?? 'Unpaid';
                                    $pm = $order['payment_method'] ?? 'later';
                                    if ($ps === 'Paid') {
                                        $payIcon = '<i class="fa-solid fa-circle-check" style="color:#10b981;"></i>';
                                        $payColor = '#10b981';
                                        $payBg = 'rgba(16,185,129,0.1)';
                                        $payLabel = 'Paid';
                                    } elseif ($ps === 'Cash') {
                                        $payIcon = '<i class="fa-solid fa-money-bill-wave" style="color:#f59e0b;"></i>';
                                        $payColor = '#f59e0b';
                                        $payBg = 'rgba(245,158,11,0.1)';
                                        $payLabel = 'Cash';
                                    } else {
                                        $payIcon = '<i class="fa-solid fa-clock" style="color:var(--text-muted);"></i>';
                                        $payColor = 'var(--text-muted)';
                                        $payBg = 'rgba(100,100,100,0.08)';
                                        $payLabel = 'Unpaid';
                                    }
                                ?>
                                <span id="pay-badge-<?= $order['id'] ?>" style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 8px; border-radius:20px; background:<?= $payBg ?>; color:<?= $payColor ?>; white-space:nowrap;">
                                    <?= $payIcon ?> <?= $payLabel ?>
                                </span>
                            </td>
                            <td style="color:var(--text-secondary); font-size:12px; white-space:nowrap;">
                                <?= date('d M y', strtotime($order['created_at'])) ?>
                                <div style="font-size:11px; color:var(--text-muted);"><?= date('H:i', strtotime($order['created_at'])) ?></div>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; align-items:center; gap:4px; justify-content:flex-end;">
                                    <button class="expand-btn btn-sm btn-sm-outline" onclick="toggleDetail(<?= $order['id'] ?>)" title="Details" style="padding:4px 8px;">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button class="btn-sm btn-sm-danger" onclick="deleteOrder(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>')" title="Delete" style="padding:4px 8px;">
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
                                        ?>
                                        <tr>
                                            <td style="padding:8px; font-size:14px;"><?= htmlspecialchars($it['emoji'] ?? '✨') ?> <?= htmlspecialchars($it['name']) ?><?= $adminItBits ? ' <span style="font-size:11px;color:var(--text-muted);">('.implode(' · ', $adminItBits).')</span>' : '' ?></td>
                                            <td style="padding:8px; text-align:center; color:var(--text-secondary);">× <?= (int)$it['quantity'] ?></td>
                                            <td style="padding:8px; text-align:right; color:var(--text-secondary);">£<?= number_format($it['price'], 2) ?></td>
                                            <td style="padding:8px; text-align:right; font-weight:700; color:var(--color-primary);">£<?= number_format($it['price'] * $it['quantity'], 2) ?></td>
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
                                                <td style="padding:6px 8px; text-align:right; color:var(--text-secondary); font-weight:700;">£<?= number_format($subtotal, 2) ?></td>
                                            </tr>
                                            <?php if (!empty($order['promo_code']) && $order['discount_amount'] > 0): ?>
                                            <tr>
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">🎟️ Promo: <strong><?= htmlspecialchars($order['promo_code']) ?></strong></td>
                                                <td style="padding:6px 8px; text-align:right; color:#10b981; font-weight:700;">−£<?= number_format($order['discount_amount'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ((float)($order['delivery_charge'] ?? 0) > 0): ?>
                                            <tr>
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">🚚 Delivery Charge</td>
                                                <td style="padding:6px 8px; text-align:right; color:#f59e0b; font-weight:700;">+<?= $ordSym . number_format($order['delivery_charge'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr style="border-top:1px solid var(--border-light);">
                                                <td colspan="3" style="padding:6px 8px; font-weight:700; font-size:15px;">Total</td>
                                                <td style="padding:6px 8px; text-align:right; font-weight:800; font-size:16px; color:var(--color-primary);"><?= $ordSym . number_format($order['total_price'], 2) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    <!-- Status + Payment + Invoice Print row -->
                                     <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:14px; background:var(--bg-main); border-radius:var(--radius-sm); border:1px solid var(--border-light);">

                                         <!-- Order Status -->
                                         <div style="display:flex; align-items:center; gap:8px;">
                                             <label style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Order Status</label>
                                             <select class="status-select" id="status-<?= $order['id'] ?>">
                                                 <?php foreach (['Pending Payment', 'Confirmed', 'Processing', 'Packed', 'Shipped', 'Delivered', 'Cancelled', 'Return Requested', 'Returned', 'Refunded', 'Exchange Requested', 'RTO'] as $s): ?>
                                                 <option value="<?= $s ?>" <?= $order['status']===$s ? 'selected' : '' ?>><?= $s ?></option>
                                                 <?php endforeach; ?>
                                             </select>
                                             <button class="btn-sm btn-sm-primary" onclick="updateStatus(<?= $order['id'] ?>, '<?= $order['order_code'] ?>')">
                                                 <i class="fa-solid fa-check"></i> Save
                                             </button>
                                         </div>

                                         <div style="width:1px; height:32px; background:var(--border-light);"></div>

                                         <!-- Payment Status -->
                                         <div style="display:flex; align-items:center; gap:8px;">
                                             <label style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Payment</label>
                                             <select class="status-select" id="pstatus-<?= $order['id'] ?>">
                                                 <option value="Unpaid"  <?= ($order['payment_status'] ?? 'Unpaid') === 'Unpaid' ? 'selected' : '' ?>>⏳ Not Paid</option>
                                                 <option value="Paid"    <?= ($order['payment_status'] ?? '') === 'Paid'   ? 'selected' : '' ?>>✅ Paid Online</option>
                                                 <option value="Cash"    <?= ($order['payment_status'] ?? '') === 'Cash'   ? 'selected' : '' ?>>💵 Cash Received</option>
                                             </select>
                                             <button class="btn-sm btn-sm-primary" onclick="updatePaymentStatus(<?= $order['id'] ?>)">
                                                 <i class="fa-solid fa-check"></i> Save
                                             </button>
                                         </div>

                                         <div style="width:1px; height:32px; background:var(--border-light);"></div>

                                         <!-- Invoice & Shipping Label Printing -->
                                         <div style="display:flex; align-items:center; gap:8px;">
                                             <button class="btn-sm btn-sm-outline" onclick='printTaxInvoice(<?= json_encode($order) ?>)' style="background:#ffffff; color:#1e293b; border:1px solid #cbd5e1; font-weight:600;">
                                                 <i class="fa-solid fa-file-invoice"></i> Tax Invoice
                                             </button>
                                             <button class="btn-sm btn-sm-outline" onclick='printShippingLabel(<?= json_encode($order) ?>)' style="background:#ffffff; color:#1e293b; border:1px solid #cbd5e1; font-weight:600;">
                                                 <i class="fa-solid fa-tag"></i> Shipping Label
                                             </button>
                                         </div>

                                         <span id="status-msg-<?= $order['id'] ?>" style="font-size:12px; color:var(--color-secondary);"></span>
                                     </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

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
    const items = typeof order.items_json === 'string' ? JSON.parse(order.items_json) : (order.items_json || []);
    let itemsRows = '';
    let subtotal = 0;

    items.forEach(it => {
        const lineTotal = (it.price || 0) * (it.quantity || 1);
        subtotal += lineTotal;
        const itBits = [];
        if (it.color_name) itBits.push('Colour: ' + it.color_name);
        if (it.variant_name) itBits.push(it.variant_name);
        itemsRows += `
            <tr>
                <td style="padding:10px; border-bottom:1px solid #e2e8f0; font-size:13px;">${it.name || 'Garment'} ${itBits.length ? '('+itBits.join(' · ')+')' : ''}</td>
                <td style="padding:10px; border-bottom:1px solid #e2e8f0; text-align:center; font-size:13px;">${it.quantity || 1}</td>
                <td style="padding:10px; border-bottom:1px solid #e2e8f0; text-align:right; font-size:13px;">£${parseFloat(it.price || 0).toFixed(2)}</td>
                <td style="padding:10px; border-bottom:1px solid #e2e8f0; text-align:right; font-size:13px; font-weight:700;">£${lineTotal.toFixed(2)}</td>
            </tr>
        `;
    });

    const disc = parseFloat(order.discount_amount || 0);
    const deliv = parseFloat(order.delivery_charge || 0);
    const grandTotal = parseFloat(order.total_price || 0);

    const win = window.open('', '_blank', 'width=800,height=900');
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>TAX INVOICE - ${order.order_code}</title>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #1e293b; line-height: 1.5; }
                .invoice-header { display: flex; justify-content: space-between; border-bottom: 2px solid #2b080f; padding-bottom: 20px; margin-bottom: 30px; }
                .brand-title { font-size: 26px; font-weight: 700; color: #2b080f; letter-spacing: 2px; }
                .invoice-details { text-align: right; font-size: 13px; }
                .address-grid { display: flex; gap: 40px; margin-bottom: 30px; }
                .address-box { flex: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; font-size: 13px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                th { background: #2b080f; color: #ffffff; text-align: left; padding: 10px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
                .totals-table { width: 300px; margin-left: auto; font-size: 14px; }
                .totals-table td { padding: 6px 0; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body onload="window.print()">
            <div class="invoice-header">
                <div>
                    <div class="brand-title">DIEVON ATELIER</div>
                    <div style="font-size:12px; color:#64748b;">Timeless Luxury Women's Fashion</div>
                    <div style="font-size:12px; color:#64748b;">London, United Kingdom | support@dievon.com</div>
                </div>
                <div class="invoice-details">
                    <h2 style="margin:0; font-size:20px; color:#2b080f;">OFFICIAL TAX INVOICE</h2>
                    <div><strong>Invoice #:</strong> INV-${order.order_code}</div>
                    <div><strong>Date:</strong> ${order.created_at}</div>
                    <div><strong>Status:</strong> ${order.status}</div>
                </div>
            </div>

            <div class="address-grid">
                <div class="address-box">
                    <strong style="color:#2b080f; text-transform:uppercase; font-size:11px; letter-spacing:1px;">Billed &amp; Shipped To:</strong>
                    <div style="font-size:15px; font-weight:700; margin-top:6px;">${order.customer_name}</div>
                    <div>📞 ${order.phone}</div>
                    <div>✉️ ${order.customer_email || 'N/A'}</div>
                    <div style="margin-top:6px;">📍 ${order.address.replace(/\n/g, '<br>')}</div>
                </div>
                <div class="address-box">
                    <strong style="color:#2b080f; text-transform:uppercase; font-size:11px; letter-spacing:1px;">Payment &amp; Order Details:</strong>
                    <div style="margin-top:6px;"><strong>Order Ref:</strong> ${order.order_code}</div>
                    <div><strong>Payment Method:</strong> ${(order.payment_method || 'Online').toUpperCase()}</div>
                    <div><strong>Payment Status:</strong> ${order.payment_status || 'Unpaid'}</div>
                    ${order.razorpay_payment_id ? `<div><strong>Razorpay Payment ID:</strong> ${order.razorpay_payment_id}</div>` : ''}
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Item Description</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsRows}
                </tbody>
            </table>

            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td style="text-align:right;">£${subtotal.toFixed(2)}</td>
                </tr>
                ${disc > 0 ? `<tr><td>Discount (${order.promo_code || 'PROMO'}):</td><td style="text-align:right; color:#10b981;">−£${disc.toFixed(2)}</td></tr>` : ''}
                ${deliv > 0 ? `<tr><td>Shipping Charge:</td><td style="text-align:right;">+£${deliv.toFixed(2)}</td></tr>` : ''}
                <tr style="font-weight:800; font-size:16px; border-top:2px solid #2b080f;">
                    <td style="padding-top:10px;">TOTAL PAID:</td>
                    <td style="text-align:right; padding-top:10px; color:#2b080f;">£${grandTotal.toFixed(2)}</td>
                </tr>
            </table>

            <div style="margin-top:60px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px dashed #cbd5e1; padding-top:20px;">
                Thank you for shopping with Dievon Atelier. For returns or support, contact support@dievon.com
            </div>
        </body>
        </html>
    `);
    win.document.close();
}

function printShippingLabel(order) {
    const items = typeof order.items_json === 'string' ? JSON.parse(order.items_json) : (order.items_json || []);
    const win = window.open('', '_blank', 'width=600,height=700');
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>SHIPPING LABEL - ${order.order_code}</title>
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

                <div class="section">
                    <div class="label-title">FROM (SENDER):</div>
                    <div style="font-weight:700; font-size:13px;">DIEVON ATELIER FASHION LOGISTICS</div>
                    <div style="font-size:11px;">100 Mayfair Luxury Way, London, UK</div>
                    <div style="font-size:11px;">TEL: 07700 000000</div>
                </div>

                <div class="section" style="background:#f1f5f9; padding:10px;">
                    <div class="label-title">TO (RECIPIENT):</div>
                    <div class="recipient-name">${order.customer_name}</div>
                    <div class="recipient-addr">${order.address.replace(/\n/g, '<br>')}</div>
                    <div style="font-size:13px; font-weight:700; margin-top:6px;">TEL: ${order.phone}</div>
                </div>

                <div class="section" style="border:none;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:700;">
                        <div>ORDER #: ${order.order_code}</div>
                        <div>ITEMS: ${items.length} PCS</div>
                    </div>
                    <div style="font-size:11px; margin-top:4px;">CONTENTS: LUXURY GARMENTS / ACCESSORIES</div>
                    <div style="font-size:11px; font-weight:700; color:#b91c1c; margin-top:4px;">⚠️ HANDLE WITH CARE - FRAGILE ATELIER PACKAGING</div>
                </div>

                <div class="barcode-box">
                    ||| | |||| ||| ||||||| |<br>
                    <span style="font-size:14px; letter-spacing:2px;">*${order.order_code}*</span>
                </div>
            </div>
        </body>
        </html>
    `);
    win.document.close();
}
</script>

<?php require_once 'includes/footer.php'; ?>
