<?php
// ============================================================
//  Dievon – Admin invoice view
// ------------------------------------------------------------
//  A thin admin-authenticated wrapper around includes/invoice_template.php.
//
//  admin/orders.php previously built its own invoice HTML inside a JavaScript
//  document.write() template literal — a second, drifting copy of the customer
//  invoice. This exists so both open the SAME template: one design, one place to
//  change it. The only difference between this and pages/print_invoice.php is
//  who is allowed to view which order.
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('orders.view');


$orderCode = trim($_GET['code'] ?? '');
if ($orderCode === '') {
    http_response_code(400);
    die('Order code required.');
}

// Admins may view any order — no customer ownership check, unlike the customer copy.
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code LIMIT 1");
$stmt->execute(['code' => $orderCode]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    die('Order not found.');
}

/* items_json is the invoice. order_items is not, and never was.
   ────────────────────────────────────────────────────────────────────────
   This preferred the order_items table and treated items_json as a legacy
   fallback. It is the other way round: checkout writes items_json and there
   is no INSERT INTO order_items anywhere in the codebase — two other files
   say so outright (actions/review_action.php:72, actions/customer_action.php:470).

   The preference normally made no difference, because the table is empty and
   the fallback always ran. It matters when the table ISN'T empty. order_items
   holds only product_name, variant_name, quantity and price — no hsn_code, no
   gst_rate, no price_includes_gst. So an order with rows there printed with no
   tax snapshot, and the code below then fell back to the LIVE product row for
   HSN and GST. Change a product's tax details later and an invoice already
   issued would reprint with different figures — the exact thing freezing the
   snapshot at checkout exists to prevent, on the one document that must never
   change after the fact.

   items_json is read first now. order_items is consulted only if the order has
   no items_json at all, which covers any genuinely old row. */
$items = orderItems($order['items_json']);
if (!$items) {
    try {
        $iStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC");
        $iStmt->execute(['id' => $order['id']]);
        $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// Tax fields for the template. The order's OWN snapshot wins (frozen at
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

require __DIR__ . '/../includes/invoice_template.php';
