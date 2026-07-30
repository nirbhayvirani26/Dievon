<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    return substr($digits, -10);
}

$customerId = (int)$_SESSION['customer_id'];
$orderCode  = trim($_GET['code'] ?? '');

$customer = null;
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$stmt->execute(['id' => $customerId]);
$customer = $stmt->fetch();

if (!$customer || $orderCode === '') {
    http_response_code(404);
    die('Invoice not found.');
}

$cEmail = strtolower(trim($customer['email'] ?? ''));
$cPhone = normalizePhone(trim($customer['phone'] ?? ''));

// Only allow a customer to view invoices for orders that belong to them (IDOR guard).
// Conditions are built dynamically rather than reusing :email/:phone twice each,
// because PDO_MySQL runs native prepares here and rejects repeated named placeholders.
$conditions = ['customer_id = :id'];
$params     = ['code' => $orderCode, 'id' => $customerId];
if ($cEmail !== '') {
    $conditions[] = "LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :email";
    $params['email'] = $cEmail;
}
if ($cPhone !== '') {
    $conditions[] = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone";
    $params['phone'] = $cPhone;
}
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code AND (" . implode(' OR ', $conditions) . ") LIMIT 1");
$stmt->execute($params);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    die('Invoice not found for this account.');
}

$items = json_decode($order['items_json'], true) ?: [];
$ordSym = !empty($order['currency_symbol']) ? $order['currency_symbol'] : '£';

$subtotal = 0.0;
foreach ($items as $it) {
    $subtotal += (float)$it['price'] * (int)$it['quantity'];
}
$discount = (float)($order['discount_amount'] ?? 0);
$delivery = (float)($order['delivery_charge'] ?? 0);
$grandTotal = (float)$order['total_price'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>TAX INVOICE - <?= htmlspecialchars($order['order_code']) ?></title>
<style>
    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #1e293b; line-height: 1.5; }
    .invoice-header { display: flex; justify-content: space-between; border-bottom: 2px solid #2b080f; padding-bottom: 20px; margin-bottom: 30px; }
    .brand-title { font-size: 26px; font-weight: 700; color: #2b080f; letter-spacing: 2px; }
    .invoice-details { text-align: right; font-size: 13px; }
    .address-grid { display: flex; gap: 40px; margin-bottom: 30px; }
    .address-box { flex: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
    th { background: #2b080f; color: #ffffff; text-align: left; padding: 10px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
    td { padding: 10px; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
    .totals-table { width: 300px; margin-left: auto; font-size: 14px; }
    .totals-table td { padding: 6px 0; border-bottom: none; }
    .print-btn { display: inline-block; margin-bottom: 20px; padding: 10px 20px; background: #2b080f; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
    @media print { .print-btn { display: none; } body { padding: 0; } }
</style>
</head>
<body>
    <button class="print-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>

    <div class="invoice-header">
        <div>
            <div class="brand-title">DIEVON ATELIER</div>
            <div style="font-size:12px; color:#64748b;">Timeless Luxury Women's Fashion</div>
            <div style="font-size:12px; color:#64748b;">London, United Kingdom | support@dievon.com</div>
        </div>
        <div class="invoice-details">
            <h2 style="margin:0; font-size:20px; color:#2b080f;">OFFICIAL TAX INVOICE</h2>
            <div><strong>Invoice #:</strong> INV-<?= htmlspecialchars($order['order_code']) ?></div>
            <div><strong>Date:</strong> <?= htmlspecialchars($order['created_at']) ?></div>
            <div><strong>Status:</strong> <?= htmlspecialchars($order['status']) ?></div>
        </div>
    </div>

    <div class="address-grid">
        <div class="address-box">
            <strong style="color:#2b080f; text-transform:uppercase; font-size:11px; letter-spacing:1px;">Billed &amp; Shipped To:</strong>
            <div style="font-size:15px; font-weight:700; margin-top:6px;"><?= htmlspecialchars($order['customer_name']) ?></div>
            <div>📞 <?= htmlspecialchars($order['phone']) ?></div>
            <div>✉️ <?= htmlspecialchars($order['customer_email'] ?: 'N/A') ?></div>
            <div style="margin-top:6px;">📍 <?= nl2br(htmlspecialchars($order['address'])) ?></div>
        </div>
        <div class="address-box">
            <strong style="color:#2b080f; text-transform:uppercase; font-size:11px; letter-spacing:1px;">Payment &amp; Order Details:</strong>
            <div style="margin-top:6px;"><strong>Order Ref:</strong> <?= htmlspecialchars($order['order_code']) ?></div>
            <div><strong>Payment Method:</strong> <?= strtoupper(htmlspecialchars($order['payment_method'] ?: 'Online')) ?></div>
            <div><strong>Payment Status:</strong> <?= htmlspecialchars($order['payment_status'] ?: 'Unpaid') ?></div>
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
            <?php foreach ($items as $it): $lineTotal = (float)$it['price'] * (int)$it['quantity']; ?>
            <tr>
                <?php
                    $invBits = [];
                    if (!empty($it['color_name'])) { $invBits[] = 'Colour: ' . htmlspecialchars($it['color_name']); }
                    if (!empty($it['variant_name'])) { $invBits[] = htmlspecialchars($it['variant_name']); }
                ?>
                <td><?= htmlspecialchars($it['name']) ?><?= $invBits ? ' (' . implode(', ', $invBits) . ')' : '' ?></td>
                <td style="text-align:center;"><?= (int)$it['quantity'] ?></td>
                <td style="text-align:right;"><?= $ordSym . number_format((float)$it['price'], 2) ?></td>
                <td style="text-align:right; font-weight:700;"><?= $ordSym . number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Subtotal:</td>
            <td style="text-align:right;"><?= $ordSym . number_format($subtotal, 2) ?></td>
        </tr>
        <?php if ($discount > 0): ?>
        <tr>
            <td>Discount<?= !empty($order['promo_code']) ? ' (' . htmlspecialchars($order['promo_code']) . ')' : '' ?>:</td>
            <td style="text-align:right; color:#10b981;">−<?= $ordSym . number_format($discount, 2) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($delivery > 0): ?>
        <tr>
            <td>Shipping Charge:</td>
            <td style="text-align:right;">+<?= $ordSym . number_format($delivery, 2) ?></td>
        </tr>
        <?php endif; ?>
        <tr style="font-weight:800; font-size:16px; border-top:2px solid #2b080f;">
            <td style="padding-top:10px;">TOTAL PAID:</td>
            <td style="text-align:right; padding-top:10px; color:#2b080f;"><?= $ordSym . number_format($grandTotal, 2) ?></td>
        </tr>
    </table>

    <div style="margin-top:60px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px dashed #cbd5e1; padding-top:20px;">
        Thank you for shopping with Dievon Atelier. For returns or support, contact support@dievon.com
    </div>
</body>
</html>
