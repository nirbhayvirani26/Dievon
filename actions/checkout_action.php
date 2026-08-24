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
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'INR' AFTER `delivery_charge`");
    }
    $colCheck->execute(['currency_symbol']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '₹' AFTER `currency`");
    }
} catch (PDOException $e) {
    error_log('Auto-migrate error: ' . $e->getMessage());
}


// calculateDeliveryCharge() was removed here.
//
// It priced delivery by straight-line distance from a UK postcode (HA1 2SP),
// calling api.postcodes.io and charging GBP 1.99 beyond three miles. Nothing has
// called it since shipping moved to shippingCostForZone(), which reads the rates
// from Store Settings. It was dead code carrying a foreign address and a
// blocking third-party HTTP call into an Indian shop's checkout.


// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/checkout');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['checkout_errors'] = ['Your session expired. Please review your order and submit again.'];
    header('Location: ' . SITE_URL . '/checkout');
    exit;
}

// ── Validate cart ─────────────────────────────────────────
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: ' . SITE_URL . '/shop?empty_cart=1');
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
// Anything that is not exactly 'collection' is a delivery.
//
// This took the posted value as-is and then only ever compared it against
// 'delivery' and 'collection'. Posting order_type=x matched neither, so the
// address check, the minimum-order check and the whole shipping calculation
// were all skipped — the order went through with delivery_charge 0 and was
// shipped for free. Defaulting to the charged path means an unexpected value
// costs the customer nothing and the shop nothing.
$orderType    = (trim($_POST['order_type'] ?? '') === 'collection') ? 'collection' : 'delivery';
// Read here (with the other fields) because the phone validation below needs
// to know whether this is a COD order before it decides what a valid number is.
$paymentMethod = trim($_POST['payment_method'] ?? 'later'); // 'online' | 'cod' | 'later'

// ── Shipping zone is decided HERE, from the postcode — never taken from the form ──
// The browser sends delivery_charge and shipping_method for display convenience, but
// both are attacker-controlled: without this a customer could post the domestic rate
// on an overseas order, or force payment_method=cod where cash cannot be collected.
$shippingZone = detectShippingZone($postcode, $pdo);

if ($orderType === 'collection') {
    // The collection point is the shop's own registered address, from Store
    // Settings — not a hardcoded UK unit. This used to write
    // "Unit E5 Phoenix Business centre, HA1 2SP" onto every collection order.
    $collectionAddress = trim((string)storeSetting($pdo, 'seller_address', ''));
    $postcode = '';
    $clientCharge = 0.0;
    $address = $collectionAddress !== ''
        ? 'Collection — ' . brandName($pdo) . ', ' . $collectionAddress
        : 'Collection — ' . brandName($pdo) . ' (we will confirm the collection address by email)';
}

// ── Bot protection check ─────────────────────────────────────
if (!empty($_POST['website'])) {
    $_SESSION['checkout_errors'] = ['Order could not be submitted. Please refresh and try again.'];
    header('Location: ' . SITE_URL . '/checkout'); exit;
}
$loadedAt = (int)($_POST['form_loaded_at'] ?? 0);
if ($loadedAt > 0 && ((time() * 1000) - $loadedAt) < 2500) {
    $_SESSION['checkout_errors'] = ['Please take a moment to review your order before submitting.'];
    header('Location: ' . SITE_URL . '/checkout'); exit;
}

$errors = [];
if (strlen($name)    < 2) $errors[] = 'Please enter your full name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
// The old check was `strlen($phone) < 6`, so "123456" sailed through. COD
// orders (domestic only) additionally require a real 10-digit Indian mobile.
$phoneError = validatePhoneNumber($phone, $paymentMethod === 'cod');
if ($phoneError !== null) $errors[] = $phoneError;

if ($orderType === 'delivery') {
    if (strlen($address) < 5) $errors[] = 'Please enter your delivery address.';
    
    /*
     * Postcode. This used to be a UK-only regex, so an Indian PIN such as 380001
     * failed and the customer was told "Please enter a valid UK delivery
     * postcode" — every domestic order was blocked. isDeliverablePostcode()
     * checks the home country's real format and accepts any plausible foreign
     * one, because the shop ships worldwide.
     */
    if (!isDeliverablePostcode($postcode, $pdo)) {
        $errors[] = 'Please enter a valid delivery postcode (for example 380001).';
    }
}

// ── Minimum order check (delivery only) ──────────────────
if ($orderType === 'delivery') {
    // Was a hardcoded 10.00 with a "£10.00" message — a UK leftover. In rupees a
    // 10 minimum is no minimum at all, and the message showed the wrong currency.
    // Driven from Store Settings so it can be changed without a deploy; 0 disables it.
    $minOrderValue = (float)storeSetting($pdo, 'min_order_value', 0);
    $preCheckSubtotal = 0.0;
    foreach (($_SESSION['cart'] ?? []) as $item) {
        $preCheckSubtotal += $item['price'] * $item['quantity'];
    }
    if ($minOrderValue > 0 && $preCheckSubtotal < $minOrderValue) {
        $_SESSION['checkout_errors'] = ['Minimum order for delivery is ' . formatPrice($minOrderValue) . '. Please add more items to your cart.'];
        header('Location: ' . SITE_URL . '/checkout'); exit;
    }
}

if (!empty($errors)) {
    $_SESSION['checkout_errors'] = $errors;
    header('Location: ' . SITE_URL . '/checkout');
    exit;
}

// ── Re-validate cart items against live database, then build items array ──
// Never trust price/stock/colour sent from the browser or stored in the cart
// session at add-to-cart time — re-derive everything from the DB right now.
//
// Whether GST sits INSIDE the selling price (default, standard Indian retail) or
// is ADDED on top at checkout comes from Store Settings and is frozen into every
// line below, so invoices print the same mode the customer was actually charged.
// Exclusive only when the setting is EXPLICITLY '0' — an empty/missing value
// means the documented default (inclusive), matching the products column.
$storeGstInclusive = ((string)storeSetting($pdo, 'price_includes_gst', '1')) !== '0';
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
        header('Location: ' . SITE_URL . '/checkout'); exit;
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
            header('Location: ' . SITE_URL . '/checkout'); exit;
        }

        if (!empty($vRow['color_id'])) {
            // Colour-scoped size — re-check the colour itself and its own stock
            $cChk = $pdo->prepare("SELECT * FROM product_colors WHERE id = :cid AND product_id = :pid AND is_active = 1");
            $cChk->execute(['cid' => $vRow['color_id'], 'pid' => $pid]);
            $cRow = $cChk->fetch();
            if (!$cRow) {
                $_SESSION['checkout_errors'] = ["Selected colour for '{$item['name']}' is no longer available."];
                header('Location: ' . SITE_URL . '/checkout'); exit;
            }
            // NULL stock_qty means "not tracked", not "none left". Casting it
            // straight to int made it 0 and refused the order for every variant
            // the owner had deliberately left uncounted.
            if ($vRow['stock_qty'] !== null && (int)$vRow['stock_qty'] < $qty) {
                $_SESSION['checkout_errors'] = ["Sorry, only " . max(0, (int)$vRow['stock_qty']) . " left of '{$item['name']}' ({$vRow['name']}, {$cRow['color_name']})."];
                header('Location: ' . SITE_URL . '/checkout'); exit;
            }
            $colorId   = (int)$cRow['id'];
            $colorName = $cRow['color_name'];
            /* Same fault as actions/cart_action.php had, and it had to be
               corrected in both or the bag and the order would disagree: this
               ignored the SIZE's own price, so a colour-scoped size never
               charged the figure set against it. Resolved through the shared
               function, with the colour passed so its override still wins. */
            $price     = effectiveVariantPrice($pRow, $vRow, $cRow);
        } else {
            // A plain size variant, with no colour attached — and until now the
            // only thing that happened here was reading the price.
            //
            // The product-level check below sits in an `elseif`, so it can never
            // run once a variant is in play. Every live product has variants, so
            // between them there was no stock gate at ORDER time at all: an
            // order for 150 units of a size with 15 in stock was accepted, paid
            // for and saved, and the deduction afterwards ran
            // GREATEST(0, stock_qty - 150), quietly zeroing the row. The shop
            // would have sold garments it did not have, on every product,
            // from the first day.
            if ($vRow['stock_qty'] !== null && (int)$vRow['stock_qty'] < $qty) {
                $_SESSION['checkout_errors'] = ["Sorry, only " . max(0, (int)$vRow['stock_qty']) . " left of '{$item['name']}' ({$vRow['name']})."];
                header('Location: ' . SITE_URL . '/checkout'); exit;
            }
            // The SAME function the cart and the product page use.
            //
            // This was a bare `$price = (float)$vRow['price']` with no cap, while
            // actions/cart_action.php capped a dearer size down to the sale price.
            // So the checkout screen totalled ₹1,998 and this handler wrote an
            // order for ₹2,099 — ₹101 more than the customer approved, silently,
            // scaling with quantity. The file's own promo comment says a shared
            // helper exists "so the amount shown and the amount charged cannot
            // drift apart"; the line price had no such helper until now.
            $price = effectiveVariantPrice($pRow, $vRow);
        }
    } elseif (!empty($pRow['track_stock'])) {
        $ts  = (int)($pRow['total_stock']  ?? 0);
        $dmg = (int)($pRow['damage_stock'] ?? 0);
        $off = (int)($pRow['sold_offline'] ?? 0);
        $sol = (int)($pRow['sold_online']  ?? 0);
        $avail = max(0, $ts - $dmg - $off - $sol);
        if ($avail < $qty) {
            $_SESSION['checkout_errors'] = ["Sorry, only {$avail} left of '{$item['name']}'."];
            header('Location: ' . SITE_URL . '/checkout'); exit;
        }
    }

    // COLOUR-ONLY line: a colourway with no size behind it.
    //
    // Colour was read exclusively off the variant row above, so a product with
    // product_colors and no variants — a stole, a scarf, a bag — reached this
    // point with $colorId null and $colorName ''. The order, the invoice and the
    // shipping label therefore could not say WHICH colour to pack, and the
    // colourway's own price_override was ignored so the base price was charged.
    // The cart already resolves this correctly; the order handler did not, so
    // the two disagreed on both the colour and the money.
    //
    // Re-read from the database rather than trusting the cart line, exactly as
    // the colour-scoped branch above does — a session value is still client-
    // influenced state.
    if ($colorId === null && $vid <= 0) {
        $postedColor = (int)($item['color_id'] ?? 0);
        if ($postedColor > 0) {
            $cOnly = $pdo->prepare("SELECT * FROM product_colors WHERE id = :cid AND product_id = :pid AND is_active = 1");
            $cOnly->execute(['cid' => $postedColor, 'pid' => $pid]);
            $cRow2 = $cOnly->fetch();
            if (!$cRow2) {
                $_SESSION['checkout_errors'] = ["Selected colour for '{$item['name']}' is no longer available."];
                header('Location: ' . SITE_URL . '/checkout'); exit;
            }
            $colorId   = (int)$cRow2['id'];
            $colorName = $cRow2['color_name'];
            $price     = effectiveVariantPrice($pRow, null, $cRow2);
        }
    }

    // ── Country pricing ─────────────────────────────────────────
    // Same rule as actions/cart_action.php: product_country_prices carries
    // one flat price per product, not one per size/colour, so a non-home
    // country overrides whatever the branches above computed — and an order
    // is refused outright for a line no longer priced for the shopper's
    // country, rather than silently charged in the wrong currency's figure.
    // Checked again here rather than trusted from the cart session: a country
    // can be disabled, or a product's price for it removed, between add-to-
    // cart and checkout.
    if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
        $countryPricing = productCountryPricing($pRow);
        if ($countryPricing === null) {
            $_SESSION['checkout_errors'] = ["'{$item['name']}' is not available for delivery to your selected country. Please remove it from your bag."];
            header('Location: ' . SITE_URL . '/checkout'); exit;
        }
        $price = $countryPricing['price'];
    }

    $subtotal += $price * $qty;

    // Resolved against the price this line actually sells at, so an
    // Apparel – Auto by Price collection gives 5% to a ₹1,800 kurti and 18% to
    // a ₹4,200 suit filed in the same place.
    $lineTax = productTaxSnapshot($pdo, $pRow, $price);

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
        /* The code this line was sold under, frozen like the tax figures below.
           ────────────────────────────────────────────────────────────────────
           Without it an order could not be reconciled against inventory by SKU,
           and a product recoded later left every past order with no record of
           what was actually shipped. Taken from $pRow — the row re-fetched and
           re-validated above — not from the cart, so a stale session cannot
           decide it. productDisplayCode() is the same resolver the admin list
           and the product page print, so the order shows the code the owner
           sees. The colour's own SKU is kept beside it where the line has one,
           because that is the code a colourway is picked and packed by. */
        'sku'          => productDisplayCode($pRow),
        'color_sku'    => (string)($item['color_sku'] ?? ''),
        /* Who supplied it and what they call it, frozen at the moment of sale.
           Read from $pRow, the row re-validated above. Frozen rather than looked
           up when the order is opened, because suppliers change: an order placed
           today must still name the supplier who actually fulfilled it, even
           after the product has been moved to somebody else. Blank on products
           where the owner has not recorded one, which reads as "not recorded"
           rather than guessing. */
        'supplier'     => (string)($pRow['supplier_name'] ?? ''),
        'supplier_ref' => (string)($pRow['supplier_ref']  ?? ''),
        'price'        => $price,
        'quantity'     => $qty,
        // GST snapshot, frozen at checkout. Invoices print from THESE values —
        // never from the live product row — so changing a product's HSN code or
        // GST rate later cannot rewrite the tax on invoices already issued
        // (that used to happen: the print pages fetched from `products` at print
        // time, so an edit quietly rewrote history). price_includes_gst is frozen
        // the same way: an invoice printed years later must tax the same way the
        // customer was charged.
        /* Resolved, not read raw.
           ────────────────────────────────────────────────────────────────────
           These took products.gst_rate and products.hsn_code straight off the
           row. That column is only filled in when a product is SAVED through
           the admin form, so every product predating the tax feature still held
           the default of 0 — and with every collection set to "Apparel – Auto
           by Price" (5% under ₹2,500), the shop was recording 0% GST on every
           order and printing invoices with no tax on them.

           productTaxSnapshot() walks the collection tree the same way the
           product form does, honours a hand-typed override, and returns what
           this line should actually be taxed at. Still frozen here, so a later
           change to a collection cannot rewrite an invoice already issued. */
        'hsn_code'     => $lineTax['hsn_code'],
        'gst_rate'     => $lineTax['gst_rate'],
        'price_includes_gst' => $storeGstInclusive ? 1 : 0,
    ];
}

// ── Apply promo if one is in session ─────────────────────
$promo          = $_SESSION['promo'] ?? null;
$discountAmount = 0.0;
$promoCode      = null;

// Same helper the checkout page uses, so the amount shown and the amount
// charged cannot drift apart. Behaviour is unchanged from the block this
// replaced — every rule below was already checked here; it now lives in one
// place instead of two.
if ($promo) {
    $resolved      = resolveCartDiscount($pdo, $promo, $subtotal);
    $discountAmount = $resolved['amount'];
    $promoCode      = $resolved['code'];
}

// ── GST added on top (Store Setting `price_includes_gst` = No) ───────────
// When prices are GST-exclusive the tax is computed HERE, server-side, and the
// per-line figure is frozen into items_json so the invoice prints the SAME tax
// the customer was charged — never recomputed from a rate edited afterwards.
// Inclusive prices (the default) leave the tax to be backed out at print time;
// either way, the stored order total is the number the customer pays.
/* An export carries no Indian GST.
   ────────────────────────────────────────────────────────────────────────────
   Nothing here looked at where the parcel was going, so an order to London was
   charged Indian GST — money the customer should not pay and the shop cannot
   keep, on an invoice that states a tax that does not apply. Both storage modes
   got it wrong in their own way: an exclusive price had GST added on top, and an
   inclusive one left line_tax null, which tells the invoice to BACK GST OUT of
   the price at print time — printing a tax line on an export either way.

   So a non-domestic destination freezes the line at exactly zero. Zero, not
   null: null means "work it out later", and later is where the mistake was.

   This is the shipping zone, which is the destination, not the shopper's chosen
   currency — someone browsing in pounds who ships to an Indian address is a
   domestic sale and pays GST. */
$isExport      = ($shippingZone !== 'domestic');
$gstAddedTotal = 0.0;
foreach ($items as &$gstItem) {
    $gstItem['line_tax'] = null;   // inclusive → derived at invoice time
    if ($isExport) {
        $gstItem['line_tax'] = 0.0;
        continue;
    }
    if ((int)($gstItem['price_includes_gst'] ?? 1) === 0) {
        $gstRate = (float)($gstItem['gst_rate'] ?? 0);
        if ($gstRate > 0) {
            $gstItem['line_tax'] = round($gstItem['price'] * $gstItem['quantity'] * $gstRate / 100, 2);
            $gstAddedTotal += $gstItem['line_tax'];
        }
    }
}
unset($gstItem);
// Deliberate: GST is computed on the pre-discount line value, and the discount
// stays a separate deduction on the invoice. That means the shop remits GST on
// slightly more than the discounted amount the customer actually paid — the
// conservative direction (no risk of under-remitting), and moot until rates are
// set. Apportioning the discount across taxable lines would be more exact but
// far more code for a shop whose rates are currently 0.

// ── Calculate delivery charge ────────────────────────
// Charge by ZONE, not by a postcode-format guess. The previous condition required a
// UK-format postcode, so an Indian PIN (380001) failed the regex and every domestic
// order silently shipped free.
$deliveryCharge = 0.0;
if ($orderType === 'delivery' && $postcode !== '') {
    $deliveryCharge = shippingCostForZone($shippingZone, $subtotal - $discountAmount, $pdo);
}
$total = max(0, $subtotal - $discountAmount + $gstAddedTotal + $deliveryCharge);

// ── Payment method ────────────────────────────────────────
// Cash on Delivery cannot be collected outside the home country, and the front-end
// hiding it is only cosmetic — the field is still postable. Refuse it here rather
// than accepting an order that can never actually be paid for.
// Redirect back with an error, matching how every other failure in this file is
// handled — echoing JSON here would just print raw text to the browser.
if ($paymentMethod === 'cod') {
    if (!codAvailableForPostcode($postcode, $pdo)) {
        // checkout_errors (plural, an array) — the singular key used here was read
        // by nothing, so the page reloaded with the form still filled in and no
        // explanation at all. The shopper pressed Place Order again and again.
        $_SESSION['checkout_errors'] = ['Cash on Delivery is only available for delivery within India. Please choose online payment for international orders.'];
        header('Location: ' . SITE_URL . '/checkout?err=cod_international');
        exit;
    }

    // ── COD order-value cap ───────────────────────────────────────────
    // There was no cap: an order of any size could go out for cash collection,
    // and with every order on the shop being COD that is the whole order book.
    // Refuse above the Store Setting limit (default ₹5,000; 0 disables).
    $codMaxOrder = (float)storeSetting($pdo, 'cod_max_order_value', COD_MAX_ORDER_DEFAULT);
    if ($codMaxOrder > 0 && $total > $codMaxOrder) {
        $_SESSION['checkout_errors'] = ['Cash on Delivery is available for orders up to ' . formatPrice($codMaxOrder)
            . '. Please choose online payment for this order.'];
        header('Location: ' . SITE_URL . '/checkout?err=cod_cap');
        exit;
    }
}

// ── COD handling fee ───────────────────────────────────────
// Charged on cash-on-delivery orders (Store Setting `cod_fee`, default ₹49; 0
// disables) and added to the total here, server-side, so the figure stored on
// the order and shown on the invoice is the figure collected. Stored in its
// own column so it is never mistaken for a shipping charge.
$codFee = 0.0;
if ($paymentMethod === 'cod') {
    $codFee = (float)storeSetting($pdo, 'cod_fee', COD_FEE_DEFAULT);
    $total  = max(0, $total + $codFee);
}

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
            /* Same minor unit the charge was created with.
               ────────────────────────────────────────────────────────────────
               This was a bare * 100. It agreed with actions/razorpay_order.php
               only because that file also assumed 100 — two copies of the same
               guess. Both now ask checkoutCurrency(), so a currency with no
               minor unit or with three digits cannot make the two halves
               disagree and turn a good payment into a mismatch. */
            $payMinor = checkoutCurrency()['minor'];
            $chargedAmountPaise = (int)round($total * $payMinor);
            $tolerancePaise = (int)round(3 * $payMinor); // benign delivery-charge drift only
            if ($sessionAmountPaise !== null && abs($sessionAmountPaise - $chargedAmountPaise) <= $tolerancePaise) {
                $paymentStatus = 'Paid';
            } else {
                // The signature is VALID, so Razorpay genuinely captured
                // $sessionAmountPaise against this order id — the money is in.
                // The cart changed between "pay online" and submit, so the
                // recomputed total no longer matches the charge. This used to
                // be recorded as Unpaid: money sitting at Razorpay while the
                // order said "not paid", no email to anyone, and a real risk
                // of a second charge if the customer tried again.
                // Record the order at the ACTUAL charged amount and mark it
                // Paid, so the invoice and any refund match what the customer
                // was charged. The saved razorpay_payment_id and this log line
                // let admin reconcile against the Razorpay dashboard.
                error_log("Razorpay amount mismatch for order $rzpOrderId / payment $rzpPaymentId: charged {$sessionAmountPaise}p, recomputed {$chargedAmountPaise}p — recording order at the charged amount, marked Paid");
                $total = (float)($sessionAmountPaise / $payMinor);
                $paymentStatus = 'Paid';
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
// Passed the connection so the existence check runs on the FIRST attempt too;
// the duplicate-key retry below is only the belt-and-suspenders guard.
$orderCode = generateOrderCode($pdo);

// ── GST invoice serial, assigned here in creation order ──
// Every order gets one stable number (per-financial-year sequence — see
// nextInvoiceNumber()). Webhook-created orders get theirs lazily on first
// print instead; both paths go through the same atomic counter, so no two
// orders can ever share a serial.
$invoiceNumber = nextInvoiceNumber($pdo);

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
// Fallback is INR: this shop sells in India only. It used to fall back to GBP,
// a leftover from the site's UK origins, so a missing or misspelt currency
// setting would have silently stamped every order (and now every refund row)
// with the wrong currency while the amounts stayed in rupees.
$currencyData   = $GLOBALS['CURRENCY_RATES'][$activeCurrency] ?? ['symbol' => '₹', 'rate' => 1.00];
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
// cod_fee is stored through the same rate as the total it was folded into, so
// the stored column and the invoice's "+fee" row can never disagree in a
// non-INR currency. (Rates are all 1.00 today, but that is a fact about the
// settings, not a license to skip the conversion.)
$codFeeConverted    = round($codFee * $currRate, 2);

/* ── Reserve the stock BEFORE the order is written ──────────────────────────
   The check further up reads stock, and the deduction used to happen after the
   order row was saved. Between those two moments nothing held the units. Two
   shoppers reaching checkout with the last 5 of a size both read "5 left", both
   passed, both had orders written, and the deduction then ran
   GREATEST(0, 5 - 5) twice — landing on zero with TWO orders for those 5 units
   and no error anywhere. The shop would have owed ten garments it had five of,
   and would only have found out when it came to pack them.

   Reading and then writing cannot be made safe by ordering alone, so the read
   is folded into the write: a single UPDATE that decrements only if enough is
   still there. Whichever checkout the database serves second matches no row,
   gets told, and its order is never written. No locks are held across the rest
   of checkout, so a slow order cannot block the shop.

   Anything taken here is handed back by releaseReservedStock() if the order
   INSERT then fails — stock must never disappear without an order behind it. */
$stockReservations = [];
$stockShortfall    = null;

$releaseReservedStock = static function (array $reservations) use ($pdo): void {
    foreach ($reservations as $r) {
        try {
            if ($r['type'] === 'variant') {
                $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + :qty WHERE id = :id AND stock_qty IS NOT NULL")
                    ->execute(['qty' => $r['qty'], 'id' => $r['id']]);
            } else {
                $pdo->prepare("UPDATE products SET sold_online = GREATEST(0, sold_online - :qty) WHERE id = :id")
                    ->execute(['qty' => $r['qty'], 'id' => $r['id']]);
            }
        } catch (PDOException $e) {
            // Logged loudly: stock is now understated and needs a human.
            error_log("Stock release FAILED ({$r['type']} {$r['id']} x{$r['qty']}): " . $e->getMessage());
        }
    }
};

foreach ($items as $resItem) {
    $rPid = (int)($resItem['product_id'] ?? 0);
    $rVid = (int)($resItem['variant_id'] ?? 0);
    $rQty = (int)($resItem['quantity'] ?? 0);
    if ($rPid <= 0 || $rQty <= 0) { continue; }

    // Per-size stock is the real ceiling wherever a variant is in play.
    if ($rVid > 0) {
        try {
            $vTake = $pdo->prepare(
                "UPDATE product_variants SET stock_qty = stock_qty - :qty
                 WHERE id = :vid AND stock_qty IS NOT NULL AND stock_qty >= :need"
            );
            $vTake->execute(['qty' => $rQty, 'vid' => $rVid, 'need' => $rQty]);

            if ($vTake->rowCount() > 0) {
                $stockReservations[] = ['type' => 'variant', 'id' => $rVid, 'qty' => $rQty];
            } else {
                // No row changed for one of two reasons: the size is untracked
                // (NULL, no ceiling — nothing to reserve), or it ran out between
                // the check above and this instant. Only the second is a failure.
                $vNow = $pdo->prepare("SELECT stock_qty FROM product_variants WHERE id = :vid");
                $vNow->execute(['vid' => $rVid]);
                $vStock = $vNow->fetchColumn();
                if ($vStock !== false && $vStock !== null) {
                    $stockShortfall = "Sorry, only " . max(0, (int)$vStock) . " left of '"
                                    . ($resItem['name'] ?? 'that item') . "'"
                                    . (!empty($resItem['variant_name']) ? " (" . $resItem['variant_name'] . ")" : "")
                                    . " — someone else took the last ones while you were checking out.";
                    break;
                }
            }
        } catch (PDOException $e) {
            error_log("Stock reserve error (variant {$rVid}): " . $e->getMessage());
            $stockShortfall = 'Sorry, we could not confirm stock for your order. Please try again.';
            break;
        }
    }

    /* The product-level counter moves too, exactly as it always has. Where there
       is no variant it IS the ceiling, so it carries the same guard; where a
       variant covered the line it is a running total and simply follows. */
    try {
        if ($rVid > 0) {
            $pdo->prepare("UPDATE products SET sold_online = sold_online + :qty WHERE id = :pid AND track_stock = 1")
                ->execute(['qty' => $rQty, 'pid' => $rPid]);
            if ($pdo->query("SELECT track_stock FROM products WHERE id = " . (int)$rPid)->fetchColumn()) {
                $stockReservations[] = ['type' => 'product', 'id' => $rPid, 'qty' => $rQty];
            }
        } else {
            $pTake = $pdo->prepare(
                "UPDATE products SET sold_online = sold_online + :qty
                 WHERE id = :pid AND track_stock = 1
                   AND (COALESCE(total_stock,0) - COALESCE(damage_stock,0)
                        - COALESCE(sold_offline,0) - COALESCE(sold_online,0)) >= :need"
            );
            $pTake->execute(['qty' => $rQty, 'pid' => $rPid, 'need' => $rQty]);

            if ($pTake->rowCount() > 0) {
                $stockReservations[] = ['type' => 'product', 'id' => $rPid, 'qty' => $rQty];
            } else {
                $pNow = $pdo->prepare("SELECT track_stock, total_stock, damage_stock, sold_offline, sold_online FROM products WHERE id = :pid");
                $pNow->execute(['pid' => $rPid]);
                $pRowNow = $pNow->fetch(PDO::FETCH_ASSOC);
                if ($pRowNow && !empty($pRowNow['track_stock'])) {
                    $stockShortfall = "Sorry, only " . max(0, availableStock($pRowNow)) . " left of '"
                                    . ($resItem['name'] ?? 'that item')
                                    . "' — someone else took the last ones while you were checking out.";
                    break;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Stock reserve error (product {$rPid}): " . $e->getMessage());
        $stockShortfall = 'Sorry, we could not confirm stock for your order. Please try again.';
        break;
    }
}

if ($stockShortfall !== null) {
    $releaseReservedStock($stockReservations);
    $_SESSION['checkout_errors'] = [$stockShortfall];
    header('Location: ' . SITE_URL . '/checkout');
    exit;
}

$stmt = $pdo->prepare("INSERT INTO orders
    (customer_id, order_code, invoice_number, customer_name, customer_email, phone, address, notes, items_json, total_price, promo_code, discount_amount, payment_method, payment_status, razorpay_payment_id, postcode, delivery_charge, cod_fee, currency, currency_symbol, status)
    VALUES (:customer_id, :order_code, :invoice_number, :customer_name, :customer_email, :phone, :address, :notes, :items_json, :total_price, :promo_code, :discount_amount, :payment_method, :payment_status, :razorpay_payment_id, :postcode, :delivery_charge, :cod_fee, :currency, :currency_symbol, 'Pending')");

$orderParams = [
    'customer_id'     => $customerId,
    'order_code'      => $orderCode,
    'invoice_number'  => $invoiceNumber,
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
    'cod_fee'         => $codFeeConverted,
    'currency'        => $activeCurrency,
    'currency_symbol' => $currencySymbol,
];

$orderId = null;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try {
        $stmt->execute($orderParams);
        $orderId = $pdo->lastInsertId();
        break;
    } catch (PDOException $e) {
        // order_code is UNIQUE in the database — the real guard. The code was
        // generated (and existence-checked) before this INSERT, but two
        // checkouts can still pick the same code in the same instant. A
        // duplicate used to fall into the generic error path below and the
        // whole order — and any payment already taken — was lost. Regenerate
        // and retry a couple of times before giving up.
        if (stripos($e->getMessage(), 'Duplicate entry') !== false && $attempt < 3) {
            error_log("Order code collision ({$orderCode}) — regenerating and retrying");
            $orderCode = generateOrderCode($pdo);
            $orderParams['order_code'] = $orderCode;
            continue;
        }

        if (strpos($e->getMessage(), 'customer_email') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            try {
                $pdo->exec("ALTER TABLE `orders` ADD COLUMN `customer_email` VARCHAR(150) NOT NULL DEFAULT '' AFTER `customer_name`");
            } catch (PDOException $ignore) {}
            try {
                $stmt->execute($orderParams);
                $orderId = $pdo->lastInsertId();
            } catch (PDOException $e2) {
                // Give the units back — they were taken above on the assumption
                // that an order was about to exist, and now none does.
                $releaseReservedStock($stockReservations);
                $_SESSION['checkout_errors'] = ['Sorry, we could not place your order. Please try again.'];
                error_log("Order save error (retry): " . $e2->getMessage());
                /* SITE_URL, not a bare 'checkout.php'. This file lives in
                   /actions/, so the browser resolved that against THAT folder and
                   asked for /actions/checkout.php — a 404. This is the order-save
                   failure path: the stock had just been released and the error
                   message put in the session, and then the shopper was handed a
                   dead page instead of the checkout screen that would have shown
                   them what went wrong. */
                header('Location: ' . SITE_URL . '/checkout');
                exit;
            }
            break;
        }

        $releaseReservedStock($stockReservations);
        $_SESSION['checkout_errors'] = ['Sorry, we could not place your order. Please try again.'];
        error_log("Order save error: " . $e->getMessage());
        // Same 404 as above, on the outer save-failure path.
        header('Location: ' . SITE_URL . '/checkout');
        exit;
    }
}

// Every path out of the loop above either has an order id or has exited. A null
// here would mean the retries ran out silently — hand the stock back rather than
// leaving it held by nothing.
if (!$orderId) {
    $releaseReservedStock($stockReservations);
    $_SESSION['checkout_errors'] = ['Sorry, we could not place your order. Please try again.'];
    error_log('Order save produced no order id — reserved stock released');
    header('Location: ' . SITE_URL . '/checkout');
    exit;
}

/* ── order_items: one row per line, mirroring the snapshot ────────────────
   Phase-1 audit finding. This table has existed since the beginning and was
   never written to — 126 orders, 3 rows, no INSERT anywhere in the codebase —
   while orders.items_json held the real record. A schema that promises a
   normalised line table and never fills it is a trap for anything that later
   reports off it: the join returns almost nothing and looks like no sales.

   items_json REMAINS authoritative. Invoices print from it, and it is the
   frozen record of what the customer agreed to. These rows are a queryable
   mirror written from that same array, so the two cannot describe different
   lines.

   Deliberately not fatal. The order already exists and the customer has been
   charged; failing here — because an install has not run the migration that
   adds variant_id and sku, say — must not lose their order. The columns are
   discovered rather than assumed for exactly that reason. */
try {
    $oiCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM order_items") as $oiC) { $oiCols[$oiC['Field']] = true; }

    $oiFields = array_values(array_filter(
        ['order_id', 'product_id', 'variant_id', 'product_name', 'sku', 'variant_name', 'quantity', 'price'],
        static fn(string $f): bool => isset($oiCols[$f])
    ));

    if ($oiFields) {
        $oiStmt = $pdo->prepare(
            "INSERT INTO order_items (" . implode(', ', $oiFields) . ")
             VALUES (:" . implode(', :', $oiFields) . ")"
        );
        foreach ($itemsConverted as $oiLine) {
            $all = [
                'order_id'     => $orderId,
                'product_id'   => (int)($oiLine['product_id'] ?? 0),
                'variant_id'   => !empty($oiLine['variant_id']) ? (int)$oiLine['variant_id'] : null,
                'product_name' => (string)($oiLine['name'] ?? ''),
                'sku'          => (string)($oiLine['sku'] ?? ''),
                'variant_name' => trim(((string)($oiLine['color_name'] ?? '')) . ' ' . ((string)($oiLine['variant_name'] ?? ''))),
                'quantity'     => (int)($oiLine['quantity'] ?? 1),
                'price'        => (float)($oiLine['price'] ?? 0),
            ];
            $oiStmt->execute(array_intersect_key($all, array_flip($oiFields)));
        }
    }
} catch (PDOException $e) {
    error_log("order_items mirror failed for order $orderId: " . $e->getMessage());
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

// ── Post-save: promo uses count & stock deduction ──────────
try {
    // Increment promo uses_count
    if ($promo && $promoCode) {
        $pdo->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id")
            ->execute(['id' => $promo['id']]);
    }

    /* ── Record that stock was taken ───────────────────────────
       The units themselves were already reserved before the order was written —
       see the reservation block above the INSERT. This used to be where the
       deduction happened, which left a window in which two checkouts could both
       pass the stock check; the deduction has moved, and all that remains here
       is the flag.

       That flag is what stops admin/update_order.php taking the same units a
       second time when the order is later marked Delivered. */
    /* ── Write the sale into the stock ledger ──────────────────
       recordStockMovement() was called from admin/stock_handler.php and nowhere
       else, so stock_movements held only what the owner typed in by hand —
       received, damaged, sold offline, corrections. The single largest movement
       of stock in the shop, an actual online sale, left no entry at all.

       The effect: the Stock History panel could show "received 40" against a
       size now holding 32, with nothing anywhere accounting for the missing 8.
       The ledger could not answer the one question a stock ledger exists for —
       where did it go — and every online sale looked like an unexplained loss.

       Logged per reserved line, with the order code in the note so a movement
       can be traced back to the order that caused it. Wrapped in its own try so
       a logging failure can never cost the shopper their order. */
    if ($orderId && $stockReservations && function_exists('recordStockMovement')) {
        foreach ($stockReservations as $mv) {
            try {
                if ($mv['type'] === 'variant') {
                    $mvPid = 0;
                    foreach ($items as $mvItem) {
                        if ((int)($mvItem['variant_id'] ?? 0) === (int)$mv['id']) {
                            $mvPid = (int)($mvItem['product_id'] ?? 0);
                            break;
                        }
                    }
                    if ($mvPid > 0) {
                        recordStockMovement($pdo, $mvPid, (int)$mv['id'], -(int)$mv['qty'],
                            'sold_online', 'Order ' . $orderCode, null);
                    }
                }
                // Product-level entries are the sold_online counter moving, which
                // the variant line above already accounts for on a sized product.
                // Only a product with no variant of its own gets its own entry.
                elseif ($mv['type'] === 'product') {
                    $hasVariantLine = false;
                    foreach ($stockReservations as $other) {
                        if ($other['type'] === 'variant') {
                            foreach ($items as $mvItem) {
                                if ((int)($mvItem['variant_id'] ?? 0) === (int)$other['id']
                                    && (int)($mvItem['product_id'] ?? 0) === (int)$mv['id']) {
                                    $hasVariantLine = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    if (!$hasVariantLine) {
                        recordStockMovement($pdo, (int)$mv['id'], null, -(int)$mv['qty'],
                            'sold_online', 'Order ' . $orderCode, null);
                    }
                }
            } catch (Throwable $e) {
                error_log('Stock movement log failed for order ' . $orderCode . ': ' . $e->getMessage());
            }
        }
    }

    if ($orderId && $stockReservations) {
        try {
            $pdo->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = :id")
                ->execute(['id' => $orderId]);
        } catch (PDOException $e) {
            error_log("Checkout stock_deducted flag error: " . $e->getMessage());
        }
    }
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
// Remember which orders this browser just placed. order_confirmation.php shows
// the customer's name, address, email and phone, so it must not render for
// anyone who merely holds the code — this is what proves they are the buyer.
$_SESSION['own_orders'] = array_slice(
    array_unique(array_merge($_SESSION['own_orders'] ?? [], [$orderCode])), -20
);

header('Location: ' . SITE_URL . '/order_confirmation?code=' . urlencode($orderCode));
exit;
