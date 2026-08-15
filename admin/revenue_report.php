<?php
// ============================================================
//  Dievon – Revenue CSV Report Download
//  Usage: admin/revenue_report.php?from=2025-01-01&to=2025-01-31
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('revenue.view');


$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// Fetch orders in range
$stmt = $pdo->prepare(
    // Archived orders excluded, matching the dashboard tile and the revenue
    // summary. Without this the CSV an accountant works from disagreed with
    // every figure on screen: an order the owner had archived still appeared in
    // the export and was still counted in its totals.
    "SELECT * FROM orders WHERE DATE(created_at) BETWEEN :from AND :to AND COALESCE(is_deleted,0) = 0 ORDER BY created_at DESC"
);
$stmt->execute(['from' => $from, 'to' => $to]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build product → category map
$productMap = [];
try {
    $rows = $pdo->query("SELECT id, name, category FROM products")->fetchAll();
    foreach ($rows as $r) $productMap[$r['id']] = $r;
} catch (PDOException $e) {}

// Category revenue breakdown
$catRevenue = [];
foreach ($orders as $order) {
    if (!in_array($order['payment_status'], ['Paid', 'Cash'])) continue;

    // Money handed back is not revenue.
    //
    // This filtered on payment_status alone, so an order that was paid and then
    // Cancelled, Returned or Refunded still counted at its full value — the
    // report simply had no notion of a refund. admin/gst_report.php already
    // excludes exactly these three statuses, so the two reports disagreed about
    // the same period, and the revenue side was the optimistic one.
    if (in_array((string)$order['status'], ['Cancelled', 'Refunded', 'Returned'], true)) { continue; }

    // A PARTIAL refund reduces revenue in proportion. An order refunded down to
    // half its value keeps its status but is only half a sale, and the category
    // breakdown has to reflect that or the columns stop adding up to the takings.
    $ordTotal    = (float)($order['total_price'] ?? 0);
    $ordRefunded = (float)($order['refunded_amount'] ?? 0);
    $netFactor   = ($ordRefunded > 0 && $ordTotal > 0)
        ? max(0.0, ($ordTotal - $ordRefunded) / $ordTotal)
        : 1.0;

    $items = orderItems($order['items_json']);
    foreach ($items as $item) {
        $pid = $item['product_id'];
        $cat = $productMap[$pid]['category'] ?? ($item['category'] ?? 'Uncategorised');
        $line = (float)$item['price'] * (int)$item['quantity'] * $netFactor;
        if (!isset($catRevenue[$cat])) $catRevenue[$cat] = ['revenue' => 0, 'qty' => 0];
        $catRevenue[$cat]['revenue'] += $line;
        $catRevenue[$cat]['qty']     += (int)$item['quantity'];
    }
}

// Output CSV
$filename = 'revenue_' . $from . '_to_' . $to . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$fp = fopen('php://output', 'w');
// BOM for Excel UTF-8
fwrite($fp, "\xEF\xBB\xBF");

// ── Section 1: Orders ─────────────────────────────────────
fputcsv($fp, ['=== ORDERS: ' . $from . ' to ' . $to . ' ===']);
fputcsv($fp, [
    'Date', 'Order Code', 'Customer', 'Phone', 'Status',
    'Payment', 'Postcode', 'Items', 'Subtotal (' . currencySymbol() . ')', 'Discount (' . currencySymbol() . ')',
    'Delivery (' . currencySymbol() . ')', 'Total (' . currencySymbol() . ')'
]);

$totalRevenue = 0;
$totalOnline  = 0;
$totalCash    = 0;
$totalUnpaid  = 0;

foreach ($orders as $order) {
    $items     = orderItems($order['items_json']);
    $itemsStr  = implode('; ', array_map(fn($i) => $i['name'] . ' x' . $i['quantity'], $items));
    $delivery  = (float)($order['delivery_charge'] ?? 0);
    $postcode  = $order['postcode'] ?? '';
    // The COD handling fee comes out too.
    //
    // Only delivery was subtracted, so on a cash-on-delivery order the COD fee
    // stayed inside "Subtotal" — which is meant to be the value of the goods.
    // The category breakdown further down this same file computes goods value
    // properly from items_json, so the two halves of one CSV disagreed by
    // exactly the COD fee and an accountant reconciling them would find neither
    // figure trustworthy.
    $codFee    = (float)($order['cod_fee'] ?? 0);
    $subtotal  = (float)$order['total_price'] + (float)($order['discount_amount'] ?? 0) - $delivery - $codFee;
    
    fputcsv($fp, [
        date('d/m/Y H:i', strtotime($order['created_at'])),
        $order['order_code'],
        $order['customer_name'],
        $order['phone'],
        $order['status'],
        $order['payment_status'],
        $postcode,
        $itemsStr,
        number_format($subtotal, 2),
        number_format($order['discount_amount'] ?? 0, 2),
        number_format($delivery, 2),
        number_format($order['total_price'], 2),
    ]);
    
    if ($order['payment_status'] === 'Paid')  { $totalRevenue += $order['total_price']; $totalOnline += $order['total_price']; }
    if ($order['payment_status'] === 'Cash')  { $totalRevenue += $order['total_price']; $totalCash   += $order['total_price']; }
    if ($order['payment_status'] === 'Unpaid') $totalUnpaid  += $order['total_price'];
}

fputcsv($fp, []);
fputcsv($fp, ['', '', '', '', '', '', '', 'TOTAL REVENUE:', currencySymbol() . number_format($totalRevenue, 2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Online (Card):', currencySymbol() . number_format($totalOnline, 2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Cash:', currencySymbol() . number_format($totalCash, 2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Unpaid Total:', currencySymbol() . number_format($totalUnpaid, 2)]);

// ── Section 2: Category Breakdown ─────────────────────────
fputcsv($fp, []);
fputcsv($fp, ['=== CATEGORY REVENUE BREAKDOWN ===']);
fputcsv($fp, ['Category', 'Revenue (' . currencySymbol() . ')', 'Items Sold']);
arsort($catRevenue);
foreach ($catRevenue as $cat => $data) {
    fputcsv($fp, [$cat, number_format($data['revenue'], 2), $data['qty']]);
}

fclose($fp);
