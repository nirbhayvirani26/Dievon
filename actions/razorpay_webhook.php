<?php
// ============================================================
//  Dievon – Razorpay Webhook (server-to-server payment confirmation)
// ------------------------------------------------------------
//  Configure in Razorpay Dashboard -> Settings -> Webhooks:
//    URL:    SITE_URL/actions/razorpay_webhook.php
//    Events: payment.captured
//  Set the webhook secret shown there as RAZORPAY_WEBHOOK_SECRET in .env.
//
//  Why this exists: the normal checkout flow only creates an order if the
//  customer's browser survives the full round-trip after Razorpay charges
//  them (payment success -> JS handler -> form POST to checkout_action.php).
//  If their connection drops or the tab closes at that exact moment, Razorpay
//  still has the money but no order would ever be created. This endpoint is
//  called directly by Razorpay's servers, independent of the customer's
//  browser, and can finish the order using the snapshot saved in
//  razorpay_pending_orders at the moment the Razorpay order was created.
//
//  No session, no CSRF token — this is not a browser request. Trust is
//  established entirely by verifying the webhook signature below.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

function rzp_webhook_ack($httpCode, $message) {
    http_response_code($httpCode);
    echo json_encode(['status' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rzp_webhook_ack(405, 'method not allowed');
}

if (empty(RAZORPAY_WEBHOOK_SECRET)) {
    // Feature not configured yet — acknowledge so Razorpay doesn't retry forever,
    // but this means the safety net is currently inactive.
    error_log('Razorpay webhook called but RAZORPAY_WEBHOOK_SECRET is not set in .env');
    rzp_webhook_ack(200, 'webhook not configured');
}

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (empty($rawBody) || empty($signature)) {
    rzp_webhook_ack(400, 'missing body or signature');
}

$expectedSignature = hash_hmac('sha256', $rawBody, RAZORPAY_WEBHOOK_SECRET);
if (!hash_equals($expectedSignature, $signature)) {
    error_log('Razorpay webhook signature mismatch — request rejected');
    rzp_webhook_ack(400, 'invalid signature');
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    rzp_webhook_ack(400, 'invalid json');
}

$event = $payload['event'] ?? '';
if ($event !== 'payment.captured') {
    // We only act on the one event that means "money is confirmed in the account".
    // Anything else (order.paid, payment.authorized, etc.) is safely ignored.
    rzp_webhook_ack(200, 'event ignored');
}

$paymentEntity = $payload['payload']['payment']['entity'] ?? null;
if (!$paymentEntity || empty($paymentEntity['order_id']) || empty($paymentEntity['id'])) {
    rzp_webhook_ack(400, 'malformed payload');
}

$rzpOrderId   = $paymentEntity['order_id'];
$rzpPaymentId = $paymentEntity['id'];
$capturedPaise = (int)($paymentEntity['amount'] ?? 0);

try {
    // Idempotency: if the normal browser flow already created this order (the common
    // case — this webhook usually arrives right alongside a browser that succeeded
    // fine), there is nothing left to do.
    $existing = $pdo->prepare("SELECT id FROM orders WHERE razorpay_payment_id = :pid");
    $existing->execute(['pid' => $rzpPaymentId]);
    if ($existing->fetch()) {
        rzp_webhook_ack(200, 'already recorded');
    }

    $pending = $pdo->prepare("SELECT * FROM razorpay_pending_orders WHERE razorpay_order_id = :oid");
    $pending->execute(['oid' => $rzpOrderId]);
    $snapshot = $pending->fetch();

    if (!$snapshot) {
        // Nothing we can reconstruct an order from. Logged for manual reconciliation
        // against the Razorpay dashboard rather than silently dropped.
        error_log("Razorpay webhook: payment $rzpPaymentId captured for order $rzpOrderId but no pending snapshot found");
        rzp_webhook_ack(200, 'no snapshot found, logged for manual review');
    }

    if ((int)$snapshot['consumed'] === 1) {
        rzp_webhook_ack(200, 'snapshot already consumed');
    }

    // Sanity check: the payment actually captured should match what we expected to
    // charge when the order was created. Small tolerance for the same benign delivery-
    // charge recalculation drift covered in checkout_action.php.
    $expectedPaise = (int)$snapshot['amount_paise'];
    if (abs($expectedPaise - $capturedPaise) > 300) {
        error_log("Razorpay webhook amount mismatch for order $rzpOrderId: expected {$expectedPaise}p, captured {$capturedPaise}p — not auto-creating order, needs manual review");
        rzp_webhook_ack(200, 'amount mismatch, logged for manual review');
    }

    $orderCode = 'CB-' . random_int(100000, 999999);

    $pdo->prepare("INSERT INTO orders
        (customer_id, order_code, customer_name, customer_email, phone, address, notes, items_json, total_price, promo_code, discount_amount, payment_method, payment_status, razorpay_payment_id, postcode, delivery_charge, currency, currency_symbol, status)
        VALUES (:customer_id, :order_code, :customer_name, :customer_email, :phone, :address, :notes, :items_json, :total_price, :promo_code, :discount_amount, 'online', 'Paid', :razorpay_payment_id, :postcode, :delivery_charge, :currency, :currency_symbol, 'Pending')")
        ->execute([
            'customer_id'     => $snapshot['customer_id'],
            'order_code'      => $orderCode,
            'customer_name'   => $snapshot['customer_name'],
            'customer_email'  => $snapshot['customer_email'],
            'phone'           => $snapshot['phone'],
            'address'         => $snapshot['address'],
            'notes'           => $snapshot['notes'],
            'items_json'      => $snapshot['items_json'],
            'total_price'     => $snapshot['total_price'],
            'promo_code'      => $snapshot['promo_code'],
            'discount_amount' => $snapshot['discount_amount'],
            'razorpay_payment_id' => $rzpPaymentId,
            'postcode'        => $snapshot['postcode'],
            'delivery_charge' => $snapshot['delivery_charge'],
            'currency'        => $snapshot['currency'],
            'currency_symbol' => $snapshot['currency_symbol'],
        ]);

    $pdo->prepare("UPDATE razorpay_pending_orders SET consumed = 1 WHERE razorpay_order_id = :oid")
        ->execute(['oid' => $rzpOrderId]);

    if (!empty($snapshot['promo_code'])) {
        $pdo->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE code = :code")
            ->execute(['code' => $snapshot['promo_code']]);
    }

    $orderRow = [
        'order_code'      => $orderCode,
        'customer_name'   => $snapshot['customer_name'],
        'phone'           => $snapshot['phone'],
        'address'         => $snapshot['address'],
        'notes'           => $snapshot['notes'],
        'items_json'      => $snapshot['items_json'],
        'total_price'     => $snapshot['total_price'],
        'promo_code'      => $snapshot['promo_code'],
        'discount_amount' => $snapshot['discount_amount'],
        'created_at'      => date('Y-m-d H:i:s'),
        'payment_status'  => 'Paid',
        'payment_method'  => 'online',
        'customer_email'  => $snapshot['customer_email'],
    ];

    try { sendOrderEmail($orderRow); } catch (Exception $e) { error_log('Webhook admin email failed: ' . $e->getMessage()); }
    if (!empty($snapshot['customer_email'])) {
        try { sendCustomerConfirmationEmail($orderRow, $snapshot['customer_email']); } catch (Exception $e) { error_log('Webhook customer email failed: ' . $e->getMessage()); }
    }

    error_log("Razorpay webhook: recovered order $orderCode from pending snapshot for payment $rzpPaymentId");
    rzp_webhook_ack(200, 'order created');

} catch (PDOException $e) {
    error_log('Razorpay webhook DB error: ' . $e->getMessage());
    rzp_webhook_ack(500, 'internal error');
}
