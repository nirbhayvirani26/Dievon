<?php
// ============================================================
//  Dievon – Promo Code Handler
//  GET  action=validate  code=XXX  cart_total=XX.XX  → JSON
//  POST action=create/update/delete (admin only)
// ============================================================
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$action = $_REQUEST['action'] ?? '';

// ── PUBLIC: Validate a promo code ─────────────────────────────
if ($action === 'validate') {
    $code       = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
    $codeGiven  = $code !== '';

    // Summed from the session cart, NOT taken from the request.
    //
    // This used to read $_GET['cart_total'], so the basket's worth was whatever
    // the browser said it was. Asking for a 10% code with cart_total=1000000
    // came back with a discount of ₹100,000 and rendered "Total Due ₹99.00" on
    // checkout — and the same number was what min_order was tested against, so
    // the minimum-spend rule could be talked out of as easily.
    //
    // The final charge was never actually at risk: checkout_action.php recomputes
    // the discount from the real cart before an order is written. But a shopper
    // being shown ₹99 and charged ₹2,199 is its own kind of broken, and a rule
    // the client can answer for itself is not a rule.
    $cartTotal = 0.0;
    foreach (($_SESSION['cart'] ?? []) as $line) {
        $cartTotal += ((float)($line['price'] ?? 0)) * ((int)($line['quantity'] ?? 0));
    }
    $cartTotal = round($cartTotal, 2);

    // No code typed? Re-check the one already applied to this basket.
    //
    // The cart calls this after every quantity change purely to ask "is a promo
    // still in force, and what is it worth at the new total?" — it sends no code
    // because the answer is in the session. Refusing that with "Please enter a
    // promo code" made the cart wipe the discount it had just applied: the
    // shopper saw the green "Coupon applied!" message with the total unchanged,
    // while checkout — which re-reads the session directly — showed the reduced
    // total. Two different prices for the same basket.
    if ($code === '' && !empty($_SESSION['promo']['code'])) {
        $code = strtoupper(trim((string)$_SESSION['promo']['code']));
    }

    if ($code === '') {
        // Genuinely nothing applied. A silent answer when the shopper typed
        // nothing, an instruction when they did.
        echo json_encode([
            'success' => false,
            'message' => $codeGiven ? 'Please enter a promo code.' : '',
        ]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code = :code AND active = 1");
    $stmt->execute(['code' => $code]);
    $promo = $stmt->fetch();

    if (!$promo) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired promo code.']);
        exit;
    }

    // Check expiry
    if (!empty($promo['expires_at']) && strtotime($promo['expires_at']) < strtotime('today')) {
        echo json_encode(['success' => false, 'message' => 'This promo code has expired.']);
        exit;
    }

    // Check usage limit
    if (!is_null($promo['max_uses']) && $promo['uses_count'] >= $promo['max_uses']) {
        echo json_encode(['success' => false, 'message' => 'This promo code has reached its usage limit.']);
        exit;
    }

    /* The code's figures for the country being paid in.
       ────────────────────────────────────────────────────────────────────────
       These were read straight off the promo row, in the shop's own currency,
       and compared against a basket priced in the shopper's. So a UK shopper
       with £84 of clothes was told "Minimum order of ₹300.00 required" — a
       threshold in a currency they are not spending, that their basket both did
       and did not meet depending which number you looked at. resolveCartDiscount()
       now reads the same per-country figures, so the message and the charge
       cannot disagree. */
    if (promoIsUnpricedForCountry($pdo, $promo)) {
        echo json_encode([
            'success' => false,
            'message' => 'This code is not available in your country.',
        ]);
        exit;
    }
    $promoVals = promoValuesForCountry($pdo, $promo);

    // Check minimum order
    if ($cartTotal < $promoVals['min_order']) {
        echo json_encode([
            'success' => false,
            // formatPrice() renders in the CURRENT country's currency, and the
            // threshold above is now that country's own figure — so the two agree.
            'message' => 'Minimum order of ' . formatPrice($promoVals['min_order']) . ' required for this code.',
        ]);
        exit;
    }

    // Calculate discount
    if ($promo['discount_type'] === 'percentage') {
        $discountAmount = round($cartTotal * ($promoVals['discount_value'] / 100), 2);
        $discountLabel  = (int)$promoVals['discount_value'] . '% off';
    } else {
        $discountAmount = min($promoVals['discount_value'], $cartTotal);
        $discountLabel  = formatPrice($promoVals['discount_value']) . ' off';
    }

    $newTotal = max(0, $cartTotal - $discountAmount);

    // Store in session
    $_SESSION['promo'] = [
        'id'              => $promo['id'],
        'code'            => $promo['code'],
        'discount_type'   => $promo['discount_type'],
        'discount_value'  => $promo['discount_value'],
        'discount_amount' => $discountAmount,
        'label'           => $discountLabel,
    ];

    echo json_encode([
        'success'         => true,
        'code'            => $promo['code'],
        'description'     => $promo['description'],
        // Raw numbers, NOT number_format()'d.
        //
        // These two fields are read by JavaScript that does parseFloat() on them
        // (pages/cart.php:372-373, pages/checkout.php:781-785). parseFloat stops
        // at the first character it cannot read, and number_format inserts a
        // thousands comma — so "9,450.00" was parsed as 9. A ₹10,500 basket with
        // a coupon displayed a total of ₹9.00 on the cart, ₹10,499.00 on
        // checkout, and charged ₹9,450.00. Three different numbers on the two
        // screens where a customer confirms what they are paying.
        //
        // Formatting is the front end's job and it already does it: every
        // consumer passes these through formatPriceJS() before display.
        'discount_amount' => round((float)$discountAmount, 2),
        'discount_label'  => $discountLabel,
        'new_total'       => round((float)$newTotal, 2),
        'min_order'       => (float)$promo['min_order'],   // for live frontend validation
        'message'         => '🎉 Promo applied! ' . $discountLabel,
    ]);
    exit;
}

// ── PUBLIC: Remove promo ──────────────────────────────────────
if ($action === 'remove') {
    unset($_SESSION['promo']);
    echo json_encode(['success' => true]);
    exit;
}

// ── ADMIN ONLY below this line ────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

// Every admin action below must carry a valid CSRF token. The admin UI
// (admin/promo.php) already sends one; this is what stops a malicious page
// from silently creating or deleting promo codes on behalf of a signed-in admin.
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
    exit;
}

// ── ADMIN: Create promo ───────────────────────────────────────
if ($action === 'create') {
    $code        = strtoupper(trim($_POST['code']           ?? ''));
    $description = trim($_POST['description']               ?? '');
    $type        = $_POST['discount_type']                  ?? 'percentage';
    $value       = (float)($_POST['discount_value']         ?? 0);
    $minOrder    = (float)($_POST['min_order']              ?? 0);
    $maxUses     = $_POST['max_uses'] !== '' ? (int)$_POST['max_uses'] : null;
    $active      = isset($_POST['active']) ? 1 : 0;
    $expires     = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    if ($code === '' || $value <= 0) {
        echo json_encode(['success' => false, 'message' => 'Code and discount value are required.']);
        exit;
    }
    try {
        $pdo->prepare("INSERT INTO promo_codes (code,description,discount_type,discount_value,min_order,max_uses,active,expires_at)
                       VALUES (:code,:desc,:type,:value,:min,:max,:active,:expires)")
            ->execute(['code'=>$code,'desc'=>$description,'type'=>$type,'value'=>$value,'min'=>$minOrder,'max'=>$maxUses,'active'=>$active,'expires'=>$expires]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'code' => $code]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Code already exists or DB error.']);
    }
    exit;
}

// ── ADMIN: Update promo ───────────────────────────────────────
if ($action === 'update') {
    $id          = (int)($_POST['id']                       ?? 0);
    $description = trim($_POST['description']               ?? '');
    $type        = $_POST['discount_type']                  ?? 'percentage';
    $value       = (float)($_POST['discount_value']         ?? 0);
    $minOrder    = (float)($_POST['min_order']              ?? 0);
    $maxUses     = ($_POST['max_uses'] ?? '') !== '' ? (int)$_POST['max_uses'] : null;
    $active      = isset($_POST['active']) ? 1 : 0;
    $expires     = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    $pdo->prepare("UPDATE promo_codes SET description=:desc,discount_type=:type,discount_value=:value,min_order=:min,max_uses=:max,active=:active,expires_at=:expires WHERE id=:id")
        ->execute(['desc'=>$description,'type'=>$type,'value'=>$value,'min'=>$minOrder,'max'=>$maxUses,'active'=>$active,'expires'=>$expires,'id'=>$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── ADMIN: Toggle active ──────────────────────────────────────
if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE promo_codes SET active = 1 - active WHERE id = :id")->execute(['id' => $id]);
    $row = $pdo->prepare("SELECT active FROM promo_codes WHERE id = :id");
    $row->execute(['id' => $id]);
    echo json_encode(['success' => true, 'active' => (int)$row->fetchColumn()]);
    exit;
}

// ── ADMIN: Delete promo ───────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM promo_codes WHERE id = :id")->execute(['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
