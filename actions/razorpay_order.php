<?php
// ============================================================
//  Dievon – Razorpay: Create Order
//  Called via AJAX before form submit when "Pay Online" chosen.
//  Uses plain cURL against the Razorpay REST API — no SDK/Composer
//  dependency, so there's no vendor path to get wrong.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

if (RAZORPAY_KEY_ID === '' || RAZORPAY_KEY_SECRET === '') {
    echo json_encode(['error' => 'Online payment is not yet configured. Please choose Bank Wire or Cash on Delivery.']);
    exit;
}

$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    echo json_encode(['error' => 'Cart is empty']);
    exit;
}

function calculateDeliveryChargeRzp(string $postcode): float {
    $shopLat  = 51.5729;
    $shopLon  = -0.3356; // HA1 2SP
    $freeMiles = 3.0;
    $charge    = 1.99;

    $clean = str_replace(' ', '', strtoupper(trim($postcode)));
    $url   = 'https://api.postcodes.io/postcodes/' . urlencode($clean);

    try {
        $ctx  = stream_context_create(['http' => ['timeout' => 4]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return 0.0;
        $data = json_decode($json, true);
        if (empty($data['result'])) return $charge;

        $lat2 = (float)$data['result']['latitude'];
        $lon2 = (float)$data['result']['longitude'];

        $R    = 3958.8;
        $dLat = deg2rad($lat2 - $shopLat);
        $dLon = deg2rad($lon2 - $shopLon);
        $a    = sin($dLat/2)**2 + cos(deg2rad($shopLat)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        $miles = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $miles <= $freeMiles ? 0.0 : $charge;
    } catch (Throwable $e) {
        return 0.0;
    }
}

$cartTotal = 0.0;
foreach ($cart as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}

$promo          = $_SESSION['promo'] ?? null;
$discountAmount = 0.0;
if ($promo) {
    try {
        $pStmt = $pdo->prepare("SELECT * FROM promo_codes WHERE id = :id AND code = :code AND active = 1");
        $pStmt->execute(['id' => $promo['id'], 'code' => $promo['code']]);
        $promoRow = $pStmt->fetch();
        if ($promoRow &&
            (is_null($promoRow['max_uses']) || $promoRow['uses_count'] < $promoRow['max_uses']) &&
            (empty($promoRow['expires_at'])  || strtotime($promoRow['expires_at']) >= strtotime('today')) &&
            $cartTotal >= (float)$promoRow['min_order']
        ) {
            if ($promoRow['discount_type'] === 'percentage') {
                $discountAmount = round($cartTotal * ($promoRow['discount_value'] / 100), 2);
            } else {
                $discountAmount = min((float)$promoRow['discount_value'], $cartTotal);
            }
        }
    } catch (Exception $e) { }
}

$deliveryCharge = 0.0;
$postcode   = strtoupper(trim($_REQUEST['postcode'] ?? ''));
$orderType  = trim($_REQUEST['order_type'] ?? 'delivery');

if ($orderType === 'delivery' && !empty($postcode) && preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
    $deliveryCharge = calculateDeliveryChargeRzp($postcode);
}

$activeCurrency = getCurrentCurrency();
$currencyData   = $GLOBALS['CURRENCY_RATES'][$activeCurrency] ?? ['symbol' => '£', 'rate' => 1.00];

$total          = max(0, $cartTotal - $discountAmount + $deliveryCharge);
$convertedTotal = $total * (float)$currencyData['rate'];

// Razorpay only settles in INR on standard accounts — charge in INR regardless
// of the storefront's display currency.
$amountPaise = (int)round($total * 100);

if ($amountPaise < 100) {
    echo json_encode(['error' => 'Order total is too small for card payment.']);
    exit;
}

$payload = json_encode([
    'amount'   => $amountPaise,
    'currency' => 'INR',
    'receipt'  => 'dievon_' . time() . '_' . random_int(1000, 9999),
    'notes'    => [
        'shop'       => SHOP_NAME,
        'promo'      => $promo['code'] ?? '',
        'postcode'   => $postcode,
        'order_type' => $orderType,
    ],
]);

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || $httpCode >= 400) {
    error_log('Razorpay order create error: ' . $curlErr . ' | HTTP ' . $httpCode . ' | ' . $response);
    echo json_encode(['error' => 'Payment setup failed. Please try again or choose Pay Later.']);
    exit;
}

$order = json_decode($response, true);
if (empty($order['id'])) {
    echo json_encode(['error' => 'Payment setup failed. Please try again or choose Pay Later.']);
    exit;
}

$_SESSION['razorpay_order_id'] = $order['id'];
$_SESSION['razorpay_amount']   = $amountPaise;

// Snapshot everything checkout_action.php would need to build the real order, keyed by
// this Razorpay order id. A server-to-server webhook has no access to this PHP session,
// so this is what lets it independently finish the order if the browser never completes
// the round-trip after Razorpay actually charges the customer.
try {
    $customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
    $pendingItems = [];
    foreach ($cart as $item) {
        $pendingItems[] = [
            'cart_key'     => $item['cart_key']     ?? (string)($item['product_id'] ?? ''),
            'product_id'   => $item['product_id']   ?? null,
            'variant_id'   => $item['variant_id']   ?? null,
            'variant_name' => $item['variant_name'] ?? '',
            'name'         => $item['name']         ?? '',
            'emoji'        => $item['emoji']        ?? '',
            'image'        => $item['image']        ?? '',
            'price'        => (float)($item['price'] ?? 0),
            'quantity'     => (int)($item['quantity'] ?? 1),
        ];
    }

    $pdo->prepare("INSERT INTO razorpay_pending_orders
        (razorpay_order_id, amount_paise, customer_id, customer_name, customer_email, phone, address, notes, postcode, order_type, items_json, subtotal, discount_amount, delivery_charge, total_price, promo_code, currency, currency_symbol)
        VALUES (:rzp_id, :amount, :cid, :name, :email, :phone, :address, :notes, :postcode, :order_type, :items, :subtotal, :discount, :delivery, :total, :promo, :currency, :symbol)
        ON DUPLICATE KEY UPDATE amount_paise = VALUES(amount_paise), items_json = VALUES(items_json), total_price = VALUES(total_price)")
        ->execute([
            'rzp_id'    => $order['id'],
            'amount'    => $amountPaise,
            'cid'       => $customerId,
            'name'      => trim($_POST['customer_name']  ?? ''),
            'email'     => trim($_POST['customer_email'] ?? ''),
            'phone'     => trim($_POST['phone']           ?? ''),
            'address'   => trim($_POST['address']         ?? ''),
            'notes'     => trim($_POST['notes']           ?? ''),
            'postcode'  => $postcode,
            'order_type'=> $orderType,
            'items'     => json_encode($pendingItems),
            'subtotal'  => $cartTotal,
            'discount'  => $discountAmount,
            'delivery'  => $deliveryCharge,
            'total'     => $total,
            'promo'     => $promo['code'] ?? null,
            'currency'  => 'INR',
            'symbol'    => '₹',
        ]);
} catch (PDOException $e) {
    // Non-fatal: the normal browser-completes-checkout path still works without this row —
    // it only means the webhook safety net can't recover this particular payment if used.
    error_log('Razorpay pending-order snapshot failed: ' . $e->getMessage());
}

echo json_encode([
    'order_id' => $order['id'],
    'amount'   => $amountPaise,
    'currency' => 'INR',
    'key_id'   => RAZORPAY_KEY_ID,
    'display_total' => number_format($total, 2),
]);
