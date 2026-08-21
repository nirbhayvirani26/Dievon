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

/*
 * Shipping now comes from the same helper checkout_action.php uses.
 *
 * This file used to price delivery from a UK postcode lookup (postcodes.io,
 * 3 free miles from HA1 2SP, then GBP 1.99). checkout_action.php was moved to
 * shippingCostForZone() and this was not, so the two disagreed: for an Indian
 * PIN the UK regex below failed, this file charged the card 0 shipping, and
 * checkout_action.php then expected +99. The gap is larger than the 300-paise
 * tolerance at checkout_action.php:319, so a card that had really been charged
 * was saved as payment_status = 'Unpaid' and payment_method = 'later'.
 * Both sides must derive the fee identically or every online order breaks.
 */

/*
 * Nothing is charged for a garment this country cannot buy.
 *
 * This file created a payable Razorpay order straight from the session cart
 * without asking whether each piece is actually sold in the shopper's country.
 * productCountryPricing() returns null for a non-home country with no price row
 * — the shop's own signal that the piece is not on sale there, which the shop
 * grid, the product page and the card all honour by hiding it.
 *
 * Left unchecked the sequence is: the card is charged, the money is taken, and
 * then actions/checkout_action.php applies the same rule and refuses to record
 * the order. The customer is out of pocket for something the shop will not send,
 * and there is no order row to refund against.
 *
 * Checked BEFORE any money is requested, and refused with the item named, so the
 * shopper can remove it and pay for the rest.
 */
$unavailableHere = [];
foreach ($cart as $item) {
    $pid = (int)($item['product_id'] ?? 0);
    if ($pid <= 0) { continue; }
    try {
        $pStmt = $pdo->prepare("SELECT id, name, price, mrp_price FROM products WHERE id = :id");
        $pStmt->execute(['id' => $pid]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $pRow = null; }
    if (!$pRow) { continue; }

    if (function_exists('productCountryPricing') && productCountryPricing($pRow) === null) {
        $unavailableHere[] = (string)($item['name'] ?? $pRow['name']);
    }
}
if ($unavailableHere) {
    echo json_encode([
        'success' => false,
        'message' => 'We cannot ship ' . htmlspecialchars(implode(', ', array_unique($unavailableHere)))
                   . ' to your country yet. Please remove it from your bag to continue.',
    ]);
    exit;
}

$cartTotal = 0.0;
foreach ($cart as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}

$promo          = $_SESSION['promo'] ?? null;
$discountAmount = 0.0;
if ($promo) {
    /* The SAME resolver checkout charges by — not a second copy of it.
       ────────────────────────────────────────────────────────────────────────
       This re-implemented the promo rules by hand: fetch the row, check
       max_uses, check expiry, compare min_order, then work out the amount.
       resolveCartDiscount() in config/config.php does exactly that, and is what
       actions/checkout_action.php uses to decide the figure actually charged.
       Two copies of one rule — and this copy was already behind, because
       per-country promo values landed in the shared function and never reached
       here. A shopper abroad would have been asked for one amount by Razorpay
       and charged another by checkout.

       The old catch was silent as well: anything thrown in there quietly made
       the discount zero and the gateway asked for the full price with nobody
       told. A failure here is money, so it says so. */
    try {
        $resolvedPromo  = resolveCartDiscount($pdo, $promo, $cartTotal);
        $discountAmount = (float)($resolvedPromo['amount'] ?? 0.0);
    } catch (Throwable $e) {
        error_log('Razorpay order: discount resolve failed for code '
            . ($promo['code'] ?? '?') . ' — charging undiscounted: ' . $e->getMessage());
        $discountAmount = 0.0;
    }
}

$deliveryCharge = 0.0;
$postcode   = strtoupper(trim($_REQUEST['postcode'] ?? ''));
$orderType  = trim($_REQUEST['order_type'] ?? 'delivery');

if ($orderType === 'delivery' && $postcode !== '') {
    // Identical derivation to actions/checkout_action.php:271 — same helper,
    // same arguments, same order of operations.
    $shippingZone   = detectShippingZone($postcode, $pdo);
    $deliveryCharge = shippingCostForZone($shippingZone, $cartTotal - $discountAmount, $pdo);
}

// ── GST added on top (Store Setting `price_includes_gst` = No) ───────────
// Must match checkout_action.php EXACTLY, or the amount charged here by
// Razorpay would differ from the order checkout_action.php saves — and a
// webhook-recovered order's invoice would show tax that is not in its total.
// Same rule: exclusive only when the setting is explicitly '0'.
$storeGstInclusive = ((string)storeSetting($pdo, 'price_includes_gst', '1')) !== '0';
$gstAddedTotal = 0.0;
$cartGstCache  = [];
if (!$storeGstInclusive) {
    foreach ($cart as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid > 0 && !isset($cartGstCache[$pid])) {
            $cartGstCache[$pid] = 0.0;
            try {
                $tg = $pdo->prepare("SELECT gst_rate FROM products WHERE id = :id LIMIT 1");
                $tg->execute(['id' => $pid]);
                $cartGstCache[$pid] = (float)$tg->fetchColumn();
            } catch (PDOException $e) {}
        }
        $rate = (float)$cartGstCache[$pid];
        if ($rate > 0) {
            $gstAddedTotal += round((float)$item['price'] * (int)($item['quantity'] ?? 1) * $rate / 100, 2);
        }
    }
}

$activeCurrency = getCurrentCurrency();
// Fallback is INR: this shop sells in India only. It used to fall back to GBP,
// a leftover from the site's UK origins, so a missing or misspelt currency
// setting would have silently stamped every order (and now every refund row)
// with the wrong currency while the amounts stayed in rupees.
$currencyData   = $GLOBALS['CURRENCY_RATES'][$activeCurrency] ?? ['symbol' => '₹', 'rate' => 1.00];

$total          = max(0, $cartTotal - $discountAmount + $gstAddedTotal + $deliveryCharge);
$convertedTotal = $total * (float)$currencyData['rate'];

/* Charge in the currency the shopper was quoted.
   ────────────────────────────────────────────────────────────────────────────
   This sent 'INR' whatever the storefront displayed, with a comment explaining
   that standard Razorpay accounts settle in rupees. That is true of the ACCOUNT
   and irrelevant to the code: a shopper shown £45 was charged 45 rupees — a
   hundredfold undercharge in their favour and an order the shop loses money on.
   Refusing the payment would have been better than taking the wrong one.

   The country row already held currency_code. Reading it means enabling a
   country under Countries We Sell To is the only step, with no code change
   waiting behind it — which is the whole point.

   What this CANNOT do is make Razorpay accept a foreign currency: that is a
   capability on the account, applied for separately. If it is not enabled the
   API refuses order creation, and the message below says so plainly instead of
   leaving a shopper staring at a failed payment nobody can explain. */
$payCurrency = checkoutCurrency();
$amountMinor = (int)round($total * $payCurrency['minor']);

if ($amountMinor < 100) {
    echo json_encode(['error' => 'Order total is too small for card payment.']);
    exit;
}

$payload = json_encode([
    'amount'   => $amountMinor,
    'currency' => $payCurrency['code'],
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

    /* Name the one failure the shop can actually act on.
       ────────────────────────────────────────────────────────────────────────
       Charging in a foreign currency is a capability Razorpay grants per
       ACCOUNT; the code asking for it is not enough. When it has not been
       granted the API refuses the order, and the generic message below sends
       the owner hunting through code that is working correctly.

       Only for a non-home currency, and only in the log plus a slightly clearer
       line to the shopper — the customer must never be shown an account detail,
       but they should be told to use another method rather than pressing Pay
       again on something that cannot succeed. */
    if (empty($payCurrency['is_home'])) {
        error_log('Razorpay refused ' . $payCurrency['code'] . '. If this is a currency error, the '
                . 'Razorpay account needs international payments enabled — the shop code is sending '
                . 'the right currency. Until then, disable overseas selling under '
                . 'Settings → Deliver Outside India, or Countries We Sell To.');
        echo json_encode(['error' => 'Card payment is not available for this country yet. Please choose another payment method.']);
        exit;
    }

    echo json_encode(['error' => 'Payment setup failed. Please try again or choose Pay Later.']);
    exit;
}

$order = json_decode($response, true);
if (empty($order['id'])) {
    echo json_encode(['error' => 'Payment setup failed. Please try again or choose Pay Later.']);
    exit;
}

$_SESSION['razorpay_order_id'] = $order['id'];
$_SESSION['razorpay_amount']   = $amountMinor;

// Snapshot everything checkout_action.php would need to build the real order, keyed by
// this Razorpay order id. A server-to-server webhook has no access to this PHP session,
// so this is what lets it independently finish the order if the browser never completes
// the round-trip after Razorpay actually charges the customer.
try {
    $customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
    // Tax cache so the webhook-built order freezes the same HSN/GST snapshot as
    // a normal checkout (one query per distinct product, not per cart line).
    $pendingTax = [];
    $pendingItems = [];
    foreach ($cart as $item) {
        $pid = (int)($item['product_id'] ?? 0);                if ($pid > 0 && !isset($pendingTax[$pid])) {
                    $pendingTax[$pid] = ['hsn_code' => '', 'gst_rate' => 0];
                    try {
                        $tq = $pdo->prepare("SELECT hsn_code, gst_rate FROM products WHERE id = :id LIMIT 1");
                        $tq->execute(['id' => $pid]);
                        $tr = $tq->fetch(PDO::FETCH_ASSOC);
                        if ($tr) {
                            $pendingTax[$pid] = ['hsn_code' => (string)($tr['hsn_code'] ?? ''), 'gst_rate' => (float)($tr['gst_rate'] ?? 0)];
                        }
                    } catch (PDOException $e) { /* non-fatal — falls back to live lookup at print */ }
                }
                // Same frozen snapshot checkout_action.php writes, so a webhook-built
                // order's invoice never depends on what a product's tax fields say
                // today — including the inclusive/exclusive mode the customer was
                // actually charged under.
                $pendingLineTax = null;
                if (!$storeGstInclusive && (float)$pendingTax[$pid]['gst_rate'] > 0) {
                    $pendingLineTax = round((float)$item['price'] * (int)($item['quantity'] ?? 1)
                                           * (float)$pendingTax[$pid]['gst_rate'] / 100, 2);
                }
                $pendingItems[] = [
                    'cart_key'     => $item['cart_key']     ?? (string)$item['product_id'] ?? '',
                    'product_id'   => $item['product_id']   ?? null,
                    'variant_id'   => $item['variant_id']   ?? null,
                    'variant_name' => $item['variant_name'] ?? '',
                    'name'         => $item['name']         ?? '',
                    'emoji'        => $item['emoji']        ?? '',
                    'image'        => $item['image']        ?? '',
                    'price'        => (float)($item['price'] ?? 0),
                    'quantity'     => (int)($item['quantity'] ?? 1),
                    'hsn_code'     => $pendingTax[$pid]['hsn_code'],
                    'gst_rate'     => $pendingTax[$pid]['gst_rate'],
                    'price_includes_gst' => $storeGstInclusive ? 1 : 0,
                    'line_tax'     => $pendingLineTax,
                ];
    }

    $pdo->prepare("INSERT INTO razorpay_pending_orders
        (razorpay_order_id, amount_paise, customer_id, customer_name, customer_email, phone, address, notes, postcode, order_type, items_json, subtotal, discount_amount, delivery_charge, total_price, promo_code, currency, currency_symbol)
        VALUES (:rzp_id, :amount, :cid, :name, :email, :phone, :address, :notes, :postcode, :order_type, :items, :subtotal, :discount, :delivery, :total, :promo, :currency, :symbol)
        ON DUPLICATE KEY UPDATE amount_paise = VALUES(amount_paise), items_json = VALUES(items_json), total_price = VALUES(total_price)")
        ->execute([
            'rzp_id'    => $order['id'],
            'amount'    => $amountMinor,
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
            'currency'  => $payCurrency['code'],
            'symbol'    => $payCurrency['symbol'],
        ]);
} catch (PDOException $e) {
    // Non-fatal: the normal browser-completes-checkout path still works without this row —
    // it only means the webhook safety net can't recover this particular payment if used.
    error_log('Razorpay pending-order snapshot failed: ' . $e->getMessage());
}

echo json_encode([
    'order_id' => $order['id'],
    'amount'   => $amountMinor,
    'currency' => $payCurrency['code'],
    'key_id'   => RAZORPAY_KEY_ID,
    'display_total' => number_format($total, 2),
]);
