<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// Two ways to be allowed in, matching pages/order_confirmation.php exactly:
//
//   1. the browser that actually placed this order (own_orders, set in
//      checkout_action.php), or
//   2. a signed-in customer who owns it (checked further down).
//
// Only the second existed, which made the invoice unreachable for the very
// people most likely to want it: guests. Checkout does not require an account,
// so a guest reached the confirmation page, pressed the button for their
// receipt, and was bounced to a sign-in for an account they had never created.
$invOrderCode = trim($_GET['code'] ?? '');
$invPlacedHere = $invOrderCode !== ''
    && in_array($invOrderCode, $_SESSION['own_orders'] ?? [], true);

if (!isset($_SESSION['customer_id']) && !$invPlacedHere) {
    header('Location: ' . SITE_URL . '/pages/login.php');
    exit;
}

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$orderCode  = $invOrderCode;

if ($orderCode === '') {
    http_response_code(404);
    die('Invoice not found.');
}

// The guest path stops here: the order code is already proven to belong to this
// browser, so the ownership query below — which matches on a customer record —
// has nothing to match against and must be skipped rather than failed.
if ($invPlacedHere && $customerId === 0) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code LIMIT 1");
    $stmt->execute(['code' => $orderCode]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        die('Invoice not found.');
    }
} else {

$customer = null;
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$stmt->execute(['id' => $customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    http_response_code(404);
    die('Invoice not found.');
}

$cEmail = strtolower(trim($customer['email'] ?? ''));

// Only allow a customer to view invoices for orders that belong to them (IDOR guard).
//
// The phone condition that used to sit here was the hole: a customer whose
// phone matched a guest order's phone could pull that stranger's invoice with
// just the order code. A phone number is not proof of identity, so it is never
// a factor. The email is a factor only once the customer has proved they own
// it (verified at registration — see verify_email.php).
//
// Conditions are built dynamically rather than reusing :email twice, because
// PDO_MySQL runs native prepares here and rejects repeated named placeholders.
$conditions = ['customer_id = :id'];
$params     = ['code' => $orderCode, 'id' => $customerId];
if ($cEmail !== '' && isCustomerEmailVerified($customer)) {
    $conditions[] = "LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :email";
    $params['email'] = $cEmail;
}
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code AND (" . implode(' OR ', $conditions) . ") LIMIT 1");
$stmt->execute($params);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    die('Invoice not found for this account.');
}

} // end signed-in branch

$items = orderItems($order['items_json']);
$ordSym = orderCurrencySymbol($order);

$subtotal = 0.0;
foreach ($items as $it) {
    $subtotal += (float)$it['price'] * (int)$it['quantity'];
}
$discount = (float)($order['discount_amount'] ?? 0);
$delivery = (float)($order['delivery_charge'] ?? 0);
$grandTotal = (float)$order['total_price'];

// Tax fields for the shared template. The order's OWN snapshot wins (frozen at
// checkout — see the items_json note in checkout_action.php) so a later change
// to a product's HSN/GST cannot rewrite an invoice already issued. Only where
// an item carries no snapshot (orders placed before the snapshot existed) do
// we fall back to the live product row.
foreach ($items as &$__it) {
    $__it['hsn_code'] = (string)($__it['hsn_code'] ?? '');
    $__it['gst_rate'] = (float)($__it['gst_rate'] ?? 0);
    if ($__it['hsn_code'] === '' && $__it['gst_rate'] <= 0 && !empty($__it['product_id'])) {
        $__p = $pdo->prepare("SELECT hsn_code, gst_rate FROM products WHERE id = :id LIMIT 1");
        $__p->execute(['id' => $__it['product_id']]);
        if ($__row = $__p->fetch(PDO::FETCH_ASSOC)) {
            $__it['hsn_code'] = (string)($__row['hsn_code'] ?? '');
            $__it['gst_rate'] = (float)($__row['gst_rate'] ?? 0);
        }
    }
}
unset($__it);

// Legacy orders (placed before invoice numbering existed) get their serial the
// first time the invoice is actually printed — assigned once, then frozen on
// the row. The atomic counter in nextInvoiceNumber() keeps concurrent prints
// from ever handing out the same number.
if (empty($order['invoice_number']) && function_exists('nextInvoiceNumber')) {
    try {
        $order['invoice_number'] = nextInvoiceNumber($pdo, (string)($order['created_at'] ?? ''));
        $pdo->prepare("UPDATE orders SET invoice_number = :inv WHERE id = :id")
            ->execute(['inv' => $order['invoice_number'], 'id' => $order['id']]);
    } catch (PDOException $e) {
        // Non-fatal: the template still renders, just without a serial this time.
    }
}

// One shared design for every invoice in the system — see the note at the top of
// includes/invoice_template.php for why this is not duplicated per page.
require __DIR__ . '/../includes/invoice_template.php';
