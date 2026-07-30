<?php
// ============================================================
//  Dievon – Checkout Handler (POST only)
// ============================================================
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

// ── Auto-migrate: ensure required columns exist ────────────────
try {
    $colCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = ?");
    $colCheck->execute(['postcode']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `postcode` VARCHAR(10) NOT NULL DEFAULT '' AFTER `address`");
    }
    $colCheck->execute(['delivery_charge']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `delivery_charge` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`");
    }
    $colCheck->execute(['customer_id']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `customer_id` INT DEFAULT NULL AFTER `order_code`");
    }
    $colCheck->execute(['customer_email']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `customer_email` VARCHAR(180) NOT NULL DEFAULT '' AFTER `customer_name`");
    }
    $colCheck->execute(['currency']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'GBP' AFTER `delivery_charge`");
    }
    $colCheck->execute(['currency_symbol']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '£' AFTER `currency`");
    }
} catch (PDOException $e) {
    error_log('Auto-migrate error: ' . $e->getMessage());
}


// ── Delivery charge calculation ───────────────────────────
function calculateDeliveryCharge(string $postcode, float $subtotal): float {
    $shopLat   = 51.5729;
    $shopLon   = -0.3356; // HA1 2SP
    $freeMiles = 3.0;     // Within 3 miles = always free
    $charge    = 1.99;

    $clean = str_replace(' ', '', strtoupper(trim($postcode)));
    $url   = 'https://api.postcodes.io/postcodes/' . urlencode($clean);

    try {
        $ctx  = stream_context_create(['http' => ['timeout' => 4]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return 0.0;
        $data = json_decode($json, true);
        if (empty($data['result'])) return $charge;

        $lat2  = (float)$data['result']['latitude'];
        $lon2  = (float)$data['result']['longitude'];
        $R     = 3958.8;
        $dLat  = deg2rad($lat2 - $shopLat);
        $dLon  = deg2rad($lon2 - $shopLon);
        $a     = sin($dLat/2)**2 + cos(deg2rad($shopLat)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        $miles = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $miles <= $freeMiles ? 0.0 : $charge;
    } catch (Throwable $e) {
        return 0.0;
    }
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/pages/checkout.php');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['checkout_errors'] = ['Your session expired. Please review your order and submit again.'];
    header('Location: ' . SITE_URL . '/pages/checkout.php');
    exit;
}

// ── Validate cart ─────────────────────────────────────────
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: ' . SITE_URL . '/pages/shop.php?empty_cart=1');
    exit;
}

// ── Validate fields ───────────────────────────────────────
$name    = trim($_POST['customer_name']  ?? '');
$email   = trim($_POST['customer_email'] ?? '');
$phone   = trim($_POST['phone']          ?? '');
$address = trim($_POST['address']        ?? '');
$notes   = trim($_POST['notes']          ?? '');
$postcode     = strtoupper(trim($_POST['delivery_postcode'] ?? ''));
$clientCharge = round((float)($_POST['delivery_charge'] ?? 0), 2);
$orderType    = trim($_POST['order_type'] ?? 'delivery');

if ($orderType === 'collection') {
    $postcode = 'HA1 2SP';
    $clientCharge = 0.0;
    $address = 'Collection - Dievon, Unit E5 Phoenix Business centre, HA1 2SP (Collection Time: 11 AM to 8 PM)';
}

// ── Bot protection check ─────────────────────────────────────
if (!empty($_POST['website'])) {
    $_SESSION['checkout_errors'] = ['Order could not be submitted. Please refresh and try again.'];
    header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
}
$loadedAt = (int)($_POST['form_loaded_at'] ?? 0);
if ($loadedAt > 0 && ((time() * 1000) - $loadedAt) < 2500) {
    $_SESSION['checkout_errors'] = ['Please take a moment to review your order before submitting.'];
    header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
}

$errors = [];
if (strlen($name)    < 2) $errors[] = 'Please enter your full name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
if (strlen($phone)   < 6) $errors[] = 'Please enter a valid phone number.';

if ($orderType === 'delivery') {
    if (strlen($address) < 5) $errors[] = 'Please enter your delivery address.';
    
    // Validate UK postcode
    if (!preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
        $errors[] = 'Please enter a valid UK delivery postcode.';
    }
}

// ── Minimum order check (delivery only) ──────────────────
if ($orderType === 'delivery') {
    $minOrderValue = 10.00;
    $preCheckSubtotal = 0.0;
    foreach (($_SESSION['cart'] ?? []) as $item) {
        $preCheckSubtotal += $item['price'] * $item['quantity'];
    }
    if ($preCheckSubtotal < $minOrderValue) {
        $_SESSION['checkout_errors'] = ['Minimum order for delivery is £10.00. Please add more items to your cart.'];
        header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
    }
}

if (!empty($errors)) {
    $_SESSION['checkout_errors'] = $errors;
    header('Location: ' . SITE_URL . '/pages/checkout.php');
    exit;
}

// ── Re-validate cart items against live database, then build items array ──
// Never trust price/stock/colour sent from the browser or stored in the cart
// session at add-to-cart time — re-derive everything from the DB right now.
$subtotal = 0.0;
$items    = [];
foreach ($cart as $key => $item) {
    $pid = (int)($item['product_id'] ?? 0);
    $vid = (int)($item['variant_id'] ?? 0);
    $qty = (int)($item['quantity'] ?? 1);

    // Check product availability
    $pChk = $pdo->prepare("SELECT * FROM products WHERE id = :pid AND available = 1");
    $pChk->execute(['pid' => $pid]);
    $pRow = $pChk->fetch();
    if (!$pRow) {
        $_SESSION['checkout_errors'] = ["Item '{$item['name']}' is no longer available."];
        header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
    }

    $price     = (float)$item['price'];
    $colorId   = null;
    $colorName = '';

    // Check variant availability if variant ID specified
    if ($vid > 0) {
        $vChk = $pdo->prepare("SELECT * FROM product_variants WHERE id = :vid AND product_id = :pid AND available = 1");
        $vChk->execute(['vid' => $vid, 'pid' => $pid]);
        $vRow = $vChk->fetch();
        if (!$vRow) {
            $_SESSION['checkout_errors'] = ["Selected variant for '{$item['name']}' is invalid or no longer available."];
            header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
        }

        if (!empty($vRow['color_id'])) {
            // Colour-scoped size — re-check the colour itself and its own stock
            $cChk = $pdo->prepare("SELECT * FROM product_colors WHERE id = :cid AND product_id = :pid AND is_active = 1");
            $cChk->execute(['cid' => $vRow['color_id'], 'pid' => $pid]);
            $cRow = $cChk->fetch();
            if (!$cRow) {
                $_SESSION['checkout_errors'] = ["Selected colour for '{$item['name']}' is no longer available."];
                header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
            }
            if ((int)$vRow['stock_qty'] < $qty) {
                $_SESSION['checkout_errors'] = ["Sorry, only " . max(0, (int)$vRow['stock_qty']) . " left of '{$item['name']}' ({$vRow['name']}, {$cRow['color_name']})."];
                header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
            }
            $colorId   = (int)$cRow['id'];
            $colorName = $cRow['color_name'];
            $price     = $cRow['price_override'] !== null ? (float)$cRow['price_override'] : (float)$pRow['price'];
        } else {
            $price = (float)$vRow['price'];
        }
    } elseif (!empty($pRow['track_stock'])) {
        $ts  = (int)($pRow['total_stock']  ?? 0);
        $dmg = (int)($pRow['damage_stock'] ?? 0);
        $off = (int)($pRow['sold_offline'] ?? 0);
        $sol = (int)($pRow['sold_online']  ?? 0);
        $avail = max(0, $ts - $dmg - $off - $sol);
        if ($avail < $qty) {
            $_SESSION['checkout_errors'] = ["Sorry, only {$avail} left of '{$item['name']}'."];
            header('Location: ' . SITE_URL . '/pages/checkout.php'); exit;
        }
    }

    $subtotal += $price * $qty;
    $items[] = [
        'cart_key'     => $item['cart_key']     ?? (string)$item['product_id'],
        'product_id'   => $item['product_id'],
        'variant_id'   => $item['variant_id']   ?? null,
        'variant_name' => $item['variant_name'] ?? '',
        'color_id'     => $colorId,
        'color_name'   => $colorName,
        'name'         => $item['name'],
        'emoji'        => $item['emoji'],
        'image'        => $item['image']        ?? '',
        'price'        => $price,
        'quantity'     => $qty,
    ];
}

// ── Apply promo if one is in session ─────────────────────
$promo          = $_SESSION['promo'] ?? null;
$discountAmount = 0.0;
$promoCode      = null;

if ($promo) {
    $pStmt = $pdo->prepare("SELECT * FROM promo_codes WHERE id = :id AND code = :code AND active = 1");
    $pStmt->execute(['id' => $promo['id'], 'code' => $promo['code']]);
    $promoRow = $pStmt->fetch();

    if ($promoRow &&
        (is_null($promoRow['max_uses']) || $promoRow['uses_count'] < $promoRow['max_uses']) &&
        (empty($promoRow['expires_at'])  || strtotime($promoRow['expires_at']) >= strtotime('today')) &&
        $subtotal >= (float)$promoRow['min_order']
    ) {
        if ($promoRow['discount_type'] === 'percentage') {
            $discountAmount = round($subtotal * ($promoRow['discount_value'] / 100), 2);
        } else {
            $discountAmount = min((float)$promoRow['discount_value'], $subtotal);
        }
        $promoCode = $promoRow['code'];
    }
}

$total = max(0, $subtotal - $discountAmount);

// ── Calculate delivery charge ────────────────────────
$deliveryCharge = 0.0;
if ($orderType === 'delivery' && !empty($postcode) && preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
    $deliveryCharge = calculateDeliveryCharge($postcode, $subtotal - $discountAmount);
}
$total = max(0, $subtotal - $discountAmount + $deliveryCharge);

// ── Payment method ────────────────────────────────────────
$paymentMethod = $_POST['payment_method'] ?? 'later'; // 'online' or 'later'
$paymentStatus = 'Unpaid';
$razorpayPaymentIdToSave = null;

if ($paymentMethod === 'online') {
    // Verify the Razorpay payment signature server-side (HMAC-SHA256 over
    // order_id|payment_id, keyed with the account secret — this is what
    // proves the payment actually came from Razorpay and wasn't forged).
    $rzpOrderId   = $_POST['razorpay_order_id']   ?? '';
    $rzpPaymentId = $_POST['razorpay_payment_id'] ?? '';
    $rzpSignature = $_POST['razorpay_signature']  ?? '';
    $sessionOrderId     = $_SESSION['razorpay_order_id'] ?? null;
    $sessionAmountPaise = $_SESSION['razorpay_amount']   ?? null;

    if ($rzpOrderId && $rzpPaymentId && $rzpSignature
        && RAZORPAY_KEY_SECRET !== ''
        && $sessionOrderId && hash_equals($sessionOrderId, $rzpOrderId)
    ) {
        // Recorded regardless of the outcome below — even a mismatch/failed verification
        // still needs this id so an admin can look up what actually happened at Razorpay.
        $razorpayPaymentIdToSave = $rzpPaymentId;
        $expectedSignature = hash_hmac('sha256', $rzpOrderId . '|' . $rzpPaymentId, RAZORPAY_KEY_SECRET);
        if (hash_equals($expectedSignature, $rzpSignature)) {
            // The cart can change between "pay online" (which locks in an amount with
            // Razorpay) and this request finishing (which recomputes the total fresh
            // from the current session cart) — e.g. items added in another tab while
            // the payment popup was open. Refuse to mark Paid unless what was actually
            // charged still matches the order being saved, or a customer could pay for
            // a small cart and have a larger one recorded as paid in full.
            $chargedAmountPaise = (int)round($total * 100);
            $tolerancePaise = 300; // covers benign delivery-charge recalculation drift only
            if ($sessionAmountPaise !== null && abs($sessionAmountPaise - $chargedAmountPaise) <= $tolerancePaise) {
                $paymentStatus = 'Paid';
            } else {
                // Razorpay really did charge $sessionAmountPaise — don't just discard the order,
                // or that payment becomes untraceable. Save it as Unpaid/for review instead; an
                // admin can reconcile it against the Razorpay dashboard using the payment id below.
                error_log("Razorpay amount mismatch for order $rzpOrderId / payment $rzpPaymentId: charged {$sessionAmountPaise}p, order total {$chargedAmountPaise}p — saving as Unpaid for manual review");
                $paymentMethod = 'later';
                $paymentStatus = 'Unpaid';
            }
        } else {
            error_log("Razorpay signature mismatch for order $rzpOrderId / payment $rzpPaymentId");
            $paymentMethod = 'later';
            $paymentStatus = 'Unpaid';
        }
    } else {
        $paymentMethod = 'later';
        $paymentStatus = 'Unpaid';
    }
    unset($_SESSION['razorpay_order_id'], $_SESSION['razorpay_amount']);
}

// ── Generate unique order code ────────────────────────────
$orderCode = 'CB-' . random_int(100000, 999999);

// ── Save order to DB ──────────────────────────────────────
function normalizePhone(string $phone): string {
    // Compare on the last 10 digits so "+44 7879 092355" and "07879092355"
    // (same UK number, different country-code prefix) are treated as equal.
    $digits = preg_replace('/\D+/', '', $phone);
    return substr($digits, -10);
}

$customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
$normalizedPhone = normalizePhone($phone);
if (!$customerId && !empty($email)) {
    try {
        $cFind = $pdo->prepare("SELECT id FROM customers WHERE LOWER(email) = :email LIMIT 1");
        $cFind->execute(['email' => strtolower($email)]);
        $foundCid = $cFind->fetchColumn();
        if ($foundCid) {
            $customerId = (int)$foundCid;
        }
    } catch (PDOException $e) {
        error_log('Checkout customer lookup error (email): ' . $e->getMessage());
    }
}
if (!$customerId && !empty($normalizedPhone)) {
    try {
        // Written as a self-join-free subquery (rather than GROUP BY ... HAVING) because
        // MySQL's ONLY_FULL_GROUP_BY mode rejects selecting `id` alongside a GROUP BY
        // on a derived expression, even when HAVING COUNT(*)=1 guarantees uniqueness.
        // Uses two distinct placeholders (not :phone twice) because PDO_MySQL runs
        // native prepares here, which reject a named placeholder repeated in one query.
        $cFind = $pdo->prepare("SELECT id FROM customers c1 WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c1.phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone1 AND (SELECT COUNT(*) FROM customers c2 WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c2.phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone2) = 1 LIMIT 1");
        $cFind->execute(['phone1' => $normalizedPhone, 'phone2' => $normalizedPhone]);
        $foundCid = $cFind->fetchColumn();
        if ($foundCid) {
            $customerId = (int)$foundCid;
        }
    } catch (PDOException $e) {
        error_log('Checkout customer lookup error (phone): ' . $e->getMessage());
    }
}

// Get user's active currency mode and conversion rate
$activeCurrency = getCurrentCurrency();
$currencyData   = $GLOBALS['CURRENCY_RATES'][$activeCurrency] ?? ['symbol' => '£', 'rate' => 1.00];
$currencySymbol = $currencyData['symbol'];
$currRate       = (float)$currencyData['rate'];

// Convert item prices for JSON storage
$itemsConverted = [];
foreach ($items as $item) {
    $convItem = $item;
    $convItem['price'] = round($item['price'] * $currRate, 2);
    $itemsConverted[] = $convItem;
}

$totalConverted     = round($total * $currRate, 2);
$discountConverted  = round($discountAmount * $currRate, 2);
$deliveryConverted  = round($deliveryCharge * $currRate, 2);

try {
    // Try with customer_email and customer_id columns
    $stmt = $pdo->prepare("INSERT INTO orders
        (customer_id, order_code, customer_name, customer_email, phone, address, notes, items_json, total_price, promo_code, discount_amount, payment_method, payment_status, razorpay_payment_id, postcode, delivery_charge, currency, currency_symbol, status)
        VALUES (:customer_id, :order_code, :customer_name, :customer_email, :phone, :address, :notes, :items_json, :total_price, :promo_code, :discount_amount, :payment_method, :payment_status, :razorpay_payment_id, :postcode, :delivery_charge, :currency, :currency_symbol, 'Pending')");

    $stmt->execute([
        'customer_id'     => $customerId,
        'order_code'      => $orderCode,
        'customer_name'   => $name,
        'customer_email'  => $email,
        'phone'           => $phone,
        'address'         => $address,
        'notes'           => $notes,
        'items_json'      => json_encode($itemsConverted),
        'total_price'     => $totalConverted,
        'promo_code'      => $promoCode,
        'discount_amount' => $discountConverted,
        'payment_method'  => $paymentMethod,
        'payment_status'  => $paymentStatus,
        'razorpay_payment_id' => $razorpayPaymentIdToSave,
        'postcode'        => $postcode,
        'delivery_charge' => $deliveryConverted,
        'currency'        => $activeCurrency,
        'currency_symbol' => $currencySymbol,
    ]);

} catch (PDOException $e) {
        if (strpos($e->getMessage(), 'customer_email') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            try {
                $pdo->exec("ALTER TABLE `orders` ADD COLUMN `customer_email` VARCHAR(150) NOT NULL DEFAULT '' AFTER `customer_name`");
            } catch (PDOException $ignore) {}

            try {
                $stmt->execute([
                    'customer_id'     => $customerId,
                    'order_code'      => $orderCode,
                    'customer_name'   => $name,
                    'customer_email'  => $email,
                    'phone'           => $phone,
                    'address'         => $address,
                    'notes'           => $notes,
                    'items_json'      => json_encode($itemsConverted),
                    'total_price'     => $totalConverted,
                    'promo_code'      => $promoCode,
                    'discount_amount' => $discountConverted,
                    'payment_method'  => $paymentMethod,
                    'payment_status'  => $paymentStatus,
                    'razorpay_payment_id' => $razorpayPaymentIdToSave,
                    'postcode'        => $postcode,
                    'delivery_charge' => $deliveryConverted,
                    'currency'        => $activeCurrency,
                    'currency_symbol' => $currencySymbol,
                ]);
            } catch (PDOException $e2) {
                $_SESSION['checkout_errors'] = ['Sorry, we could not place your order. Please try again.'];
                error_log("Order save error (retry): " . $e2->getMessage());
                header('Location: checkout.php');
                exit;
            }
        } else {
            $_SESSION['checkout_errors'] = ['Sorry, we could not place your order. Please try again.'];
            error_log("Order save error: " . $e->getMessage());
            header('Location: checkout.php');
            exit;
        }
    }
// ── Mark the Razorpay pending-order snapshot consumed, if there is one ────
// This is what stops the webhook safety net from creating a second, duplicate
// order once the normal browser flow has already completed this one.
if (!empty($rzpOrderId)) {
    try {
        $pdo->prepare("UPDATE razorpay_pending_orders SET consumed = 1 WHERE razorpay_order_id = :id")
            ->execute(['id' => $rzpOrderId]);
    } catch (PDOException $e) { /* non-fatal */ }
}

// ── Post-save: promo uses count ──────────────────────────
try {
    // Increment promo uses_count
    if ($promo && $promoCode) {
        $pdo->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id")
            ->execute(['id' => $promo['id']]);
    }
    // NOTE: Stock is NOT deducted at checkout.
    // Stock is deducted when the admin marks an order as "Delivered".
} catch (PDOException $e) {
    error_log("Post-save error: " . $e->getMessage()); // non-fatal
}


// ── Send emails ───────────────────────────────────────────
$orderRow = [
    'order_code'      => $orderCode,
    'customer_name'   => $name,
    'phone'           => $phone,
    'address'         => $address,
    'notes'           => $notes,
    'items_json'      => json_encode($items),
    'total_price'     => $total,
    'promo_code'      => $promoCode,
    'discount_amount' => $discountAmount,
    'created_at'      => date('Y-m-d H:i:s'),
    'payment_status'  => $paymentStatus,
    'payment_method'  => $paymentMethod,
    'customer_email'  => $email,
];

try { sendOrderEmail($orderRow); } catch (Exception $e) { error_log("Admin email failed: " . $e->getMessage()); }
if (!empty($email)) {
    try { sendCustomerConfirmationEmail($orderRow, $email); } catch (Exception $e) { error_log("Customer email failed: " . $e->getMessage()); }
}

// ── Clear cart + promo session ────────────────────────────
$_SESSION['cart'] = [];
unset($_SESSION['promo']);

// ── Redirect to confirmation ──────────────────────────────
header('Location: ' . SITE_URL . '/pages/order_confirmation.php?code=' . urlencode($orderCode));
exit;
