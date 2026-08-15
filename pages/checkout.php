<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Load cart
$cart = $_SESSION['cart'] ?? [];
$errors = $_SESSION['checkout_errors'] ?? [];
unset($_SESSION['checkout_errors']);

// Redirect if cart empty
if (empty($cart)) {
    header('Location: shop?empty_cart=1');
    exit;
}

// Pieces this country cannot buy, named BEFORE a payment method is offered.
//
// actions/checkout_action.php applies productCountryPricing() and refuses to
// record an order containing a piece with no price for the shopper's country.
// This page did not, so it happily priced the line, totalled it, and offered
// "Pay Online" — the shopper reached the very last step, paid, and only then met
// a refusal. With Razorpay in front of it that meant a real charge against an
// order the shop would not record.
//
// Named here so the basket can be corrected before any money is involved.
$blockedInCountry = [];
if (function_exists('productCountryPricing') && function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
    foreach ($cart as $ckItem) {
        $ckPid = (int)($ckItem['product_id'] ?? 0);
        if ($ckPid <= 0) { continue; }
        try {
            $ckStmt = $pdo->prepare("SELECT id, name, price, mrp_price FROM products WHERE id = :id");
            $ckStmt->execute(['id' => $ckPid]);
            $ckRow = $ckStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $ckRow = null; }
        if ($ckRow && productCountryPricing($ckRow) === null) {
            $blockedInCountry[$ckPid] = (string)($ckItem['name'] ?? $ckRow['name']);
        }
    }
}

// Calculate totals
$cartTotal = 0.0;
foreach ($cart as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}

// Load applied promo from session, then re-price it against THIS basket.
//
// The stored discount_amount was whatever the code was worth at the moment it
// was typed. Add or remove anything afterwards and it went stale, so this page
// showed a discount and a Total Due that actions/checkout_action.php would not
// honour — and the customer only found out on the bank statement. Both now ask
// resolveCartDiscount(), so the figure on screen is the figure charged.
$appliedPromo   = $_SESSION['promo'] ?? null;
$resolvedPromo  = resolveCartDiscount($pdo, $appliedPromo, $cartTotal);
$discountAmount = $resolvedPromo['amount'];
if ($appliedPromo && $resolvedPromo['code'] === null) {
    // No longer valid at this basket size — stop showing it as applied.
    $appliedPromo = null;
}
$subtotalAfterDiscount = max(0, $cartTotal - $discountAmount);

// Shipping, computed HERE rather than halfway down the page.
//
// The summary printed "Shipping ₹99" and a Total Due that did not include it,
// because $grandTotal was subtotal-minus-discount only and $domesticCost was not
// worked out until the shipping options rendered, further down. The two figures
// sat next to each other on screen and disagreed, and the Total only became
// correct once the customer typed a postcode and the JavaScript recalculated —
// which is the "total corrects on the next step" the reviewer saw.
//
// Domestic is the default because this shop ships within India; the postcode
// switches it, and actions/checkout_action.php re-derives the fee server-side
// either way, so this is a display value, not a trusted one.
$domesticCost = shippingCostForZone('domestic', $subtotalAfterDiscount, $pdo);
$grandTotal   = $subtotalAfterDiscount + $domesticCost;

// Fetch logged-in customer info and saved addresses if available
$customer = null;
$savedAddresses = [];
if (isset($_SESSION['customer_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['customer_id']]);
        $customer = $stmt->fetch();
        
        // Saved address book.
        //
        // This previously invented data when the customer had none: a London
        // street as the address fallback, a UK mobile as the phone fallback,
        // and 'W1K 4QA' as the postcode — the postcode was not a fallback at
        // all, it was hardcoded for every customer. Picking "your" saved
        // address wrote a London W1 postcode into delivery_postcode, which
        // then drove the shipping quote and was stored on the order.
        //
        // The customers table has no postcode column, so there is no honest
        // value to offer. Send an empty string and let the shopper fill it in
        // rather than pre-filling a wrong one.
        //
        // Only offer the option at all when a real address exists; an address
        // book containing one blank entry is worse than no address book.
        if ($customer && trim((string)($customer['address'] ?? '')) !== '') {
            $savedAddresses[] = [
                'id' => 1,
                'name' => $customer['name'],
                'address' => $customer['address'],
                'postcode' => '',
                'phone' => trim((string)($customer['phone'] ?? '')),
            ];
        }
    } catch (PDOException $e) {}
}

$pageTitle = "Checkout";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';

/* Shipping rates come from the COUNTRIES screen, which is where they are edited.
   ────────────────────────────────────────────────────────────────────────────
   These three read the old global Store Settings rows, and the international one
   fed straight into the checkout JS. So a shopper in the United Kingdom — whose
   country row says £15.00, free over £400 — was quoted the global figure of 2500
   instead, rendered as £2,500.00 postage on an £84 order, for a Total Due of
   £2,584.00. The Countries screen had been saying £15 the whole time.

   Both figures now come through shippingCostForZone(), the same function
   checkout_action.php charges by, so the screen and the charge cannot disagree.
   The two probe amounts (0 and a very large number) ask it for the fee with and
   without the free-shipping threshold applied, which is exactly what the JS
   needs to recalculate as the bag changes. */
/* Domestic figures come from the HOME country, international from the
   DESTINATION — the same split shippingCostForZone() makes. Reading the
   threshold from the browsed country instead paired India's ₹99 fee with the
   UK's £400 threshold in one object, which is not a rule that exists anywhere. */
$storeFreeShipMin  = freeShippingMinForCountry($pdo, homeCountryRow()['country_code'] ?? null);
$storeShipFee      = shippingCostForZone('domestic', 0.0, $pdo);
$storeIntlShipFee  = shippingCostForZone('international', 0.0, $pdo);

// The destination's own free-shipping threshold, so the JS can zero the
// international fee on a large order exactly as the server does.
$intlFreeShipMin = 0.0;
$__destRow = allStoreCountries()[currentCountryCode()] ?? null;
if ($__destRow !== null && isset($__destRow['free_shipping_min'])) {
    $intlFreeShipMin = (float)$__destRow['free_shipping_min'];
}
// COD guardrails from the same Store Settings checkout_action.php enforces
// server-side — the browser copy is only a display convenience.
$storeCodFee       = (float)storeSetting($pdo, 'cod_fee', COD_FEE_DEFAULT);
$storeCodMax       = (float)storeSetting($pdo, 'cod_max_order_value', COD_MAX_ORDER_DEFAULT);
?>

<script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>

<!-- ══ Luxury Hero ═══════════════════════════════════════ -->
<section class="luxury-hero checkout-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(2) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Atelier Order Dispatch</span>
        <h1>Checkout</h1>
        <p>Complete your luxury wardrobe purchase securely.</p>
    </div>
</section>

<section class="checkout-main-section section-space">
    <div class="container reveal-on-scroll">
        
        <div class="checkout-grid">
            
            <!-- Left Side: Multi-Step Checkout Form -->
            <div class="checkout-form-panel">
                
                <!-- Errors Banner -->
                <?php /* The error lines are wrapped in ONE element on purpose.
                         .alert is display:flex (style.css:1020) and .checkout-alert
                         (style.css:2810) sets only colours, so it does not override that.
                         Loose <div>s therefore became flex COLUMNS — two checkout errors
                         printed side by side at half width each, three at a third, exactly
                         when the shopper is stuck and needs to read them. Stacked now. */ ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger checkout-alert chk-block">
                        <i class="fa-solid fa-triangle-exclamation chk-subnote"></i>
                        <div class="chk-flex-fill">
                            <?php foreach ($errors as $err): ?>
                                <div><?= htmlspecialchars($err) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php // Said BEFORE a payment method is chosen, not after paying.
                      // actions/checkout_action.php refuses these lines, so without
                      // this the shopper paid first and was refused second. ?>
                <?php if (!empty($blockedInCountry)): ?>
                    <?php /* Everything after the icon wrapped as ONE flex item.
                             .alert is display:flex (style.css:1020) and .checkout-alert sets
                             only colours, so each child became its own COLUMN — the heading,
                             every blocked product name, and the "remove it" line all sat side
                             by side in narrow strips, reading as three unrelated fragments
                             instead of one sentence. Same defect as the errors banner above;
                             this was a second alert on the same page and I missed it then. */ ?>
                    <div class="alert alert-danger checkout-alert chk-block">
                        <i class="fa-solid fa-triangle-exclamation chk-subnote"></i>
                        <div class="chk-flex-fill">
                            <strong>We cannot ship <?= count($blockedInCountry) === 1 ? 'this piece' : 'these pieces' ?> to your country yet.</strong>
                            <?php foreach ($blockedInCountry as $bName): ?>
                                <div><?= htmlspecialchars($bName) ?></div>
                            <?php endforeach; ?>
                            <div style="margin-top:8px;">
                                Please <a href="<?= SITE_URL ?>/cart" style="text-decoration:underline; font-weight:600;">remove
                                <?= count($blockedInCountry) === 1 ? 'it' : 'them' ?> from your bag</a> to continue with the rest of your order.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Guest Checkout / Account Login Header Banner -->
                <div class="checkout-auth-banner" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <?php if ($customer): ?>
                            <span style="font-size: 14px; font-weight: 600; color: var(--color-primary);"><i class="fa-solid fa-circle-check"></i> Logged in as <?= htmlspecialchars($customer['name']) ?></span>
                            <span class="chk-note-block"><?= htmlspecialchars($customer['email']) ?></span>
                        <?php else: ?>
                            <span style="font-size: 13px; font-weight: 600; color: var(--text-primary);"><i class="fa-solid fa-user-tag"></i> Checking out as Guest</span>
                            <span class="chk-note-block">Fast &amp; secure guest checkout. No password required.</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!$customer): ?>
                            <button type="button" onclick="toggleCheckoutLoginModal()" class="btn-luxury-outline" style="font-size: 11px; padding: 8px 16px;">
                                Already a member? Log In
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sign-in prompt (Collapsible) -->
                <?php // This was an inline email/password form posting to
                      // actions/customer_action.php?action=login — an action that does not
                      // exist in that file, which also refuses anyone not already signed in.
                      // Submitting it replaced the whole checkout with the raw text
                      // {"success":false,"message":"Unauthorized. Please sign in."} and threw
                      // away everything the shopper had typed. It could never have worked
                      // with any password.
                      //
                      // It now sends them to the real login page rather than pretending to
                      // log them in here. The warning is deliberate: leaving checkout loses
                      // the form, so they should be told before they go, not after. ?>
                <?php if (!$customer): ?>
                <div id="checkoutLoginBox" style="display: none; background: #fafafa; border: 1px solid var(--border-strong); padding: 25px; margin-bottom: 30px; border-radius: 6px;">
                    <h4 style="font-family: var(--font-heading); font-size: 16px; margin-bottom: 15px;">Already have an account?</h4>
                    <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6; margin-bottom: 16px;">
                        Signing in fills your saved details in automatically.
                        <strong>Your basket is kept</strong>, but anything already typed on this page is not —
                        so sign in first, or simply carry on as a guest below.
                    </p>
                    <a href="<?= SITE_URL ?>/login" class="btn-luxury" style="display: inline-block; padding: 10px 20px; font-size: 11px; text-decoration: none;">Sign In to Account</a>
                    <button type="button" onclick="toggleCheckoutLoginModal()" class="btn-luxury-outline" style="padding: 10px 20px; font-size: 11px; margin-left: 8px;">Continue as guest</button>
                </div>
                <?php endif; ?>

                <!-- Step Navigation Tabs -->

                <form action="<?= SITE_URL ?>/actions/checkout_action.php" method="POST" id="checkoutForm" onsubmit="return handleCheckoutFormSubmit(this);">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                    <!-- Bot Honeypot -->
                    <input type="text" name="website" id="hp_website" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp-input">
                    <input type="hidden" name="form_loaded_at" id="hp_loaded_at" value="0">
                    <script>document.getElementById('hp_loaded_at').value = Date.now();</script>
                    <input type="hidden" name="order_type" value="delivery">
                    <input type="hidden" name="delivery_charge" id="deliveryChargeInput" value="0.00">
                    <input type="hidden" name="razorpay_order_id" id="rzpOrderIdInput" value="">
                    <input type="hidden" name="razorpay_payment_id" id="rzpPaymentIdInput" value="">
                    <input type="hidden" name="razorpay_signature" id="rzpSignatureInput" value="">

                    <!-- ==================== STEP 1: SHIPPING & ADDRESS BOOK ==================== -->
                    <div id="step1Content" class="checkout-section is-open">
                        
                        <!-- Saved Address Book Picker (Logged In Users) -->
                        <?php if ($customer && !empty($savedAddresses)): ?>
                        <div class="address-book-picker" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; margin-bottom: 25px; border-radius: 6px;">
                            <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-primary); margin-bottom: 8px;">
                                <i class="fa-solid fa-address-book"></i> Select from Address Book
                            </label>
                            <select onchange="applySavedAddress(this.value)" class="form-luxury-input chk-input-sm">
                                <option value="">-- Use New Address Below --</option>
                                <?php foreach ($savedAddresses as $addr): ?>
                                    <option value="<?= htmlspecialchars($addr['address']) ?>|<?= htmlspecialchars($addr['postcode']) ?>|<?= htmlspecialchars($addr['phone']) ?>">
                                        <?= htmlspecialchars($addr['name']) ?> — <?= htmlspecialchars($addr['address']) ?>, <?= htmlspecialchars($addr['postcode']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <h2 class="checkout-section-title">
                            <span class="section-name">Shipping Address</span>
                            <button type="button" class="section-edit" onclick="showSection(1)">Edit</button>
                        </h2>
                        <p class="section-summary" id="step1Summary"></p>
                        
                        <div class="form-row">
                            <div class="form-luxury-group">
                                <label class="chk-field-label" for="customer_name">Full Name *</label>
                                <input type="text" id="customer_name" name="customer_name" class="form-luxury-input" required 
                                       value="<?= htmlspecialchars($customer['name'] ?? $_POST['customer_name'] ?? '') ?>" placeholder="Priya Sharma" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                            <div class="form-luxury-group">
                                <label class="chk-field-label" for="customer_email">Email Address *</label>
                                <input type="email" id="customer_email" name="customer_email" class="form-luxury-input" required
                                       value="<?= htmlspecialchars($customer['email'] ?? $_POST['customer_email'] ?? '') ?>" placeholder="jane@example.com" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-luxury-group">
                                <label class="chk-field-label" for="phone">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" class="form-luxury-input" required
                                       value="<?= htmlspecialchars($customer['phone'] ?? $_POST['phone'] ?? '') ?>" placeholder="<?= htmlspecialchars(shopPhone()) ?>" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                            <div class="form-luxury-group">
                                <label class="chk-field-label" for="delivery_postcode">Postcode / Zip *</label>
                                <input type="text" id="delivery_postcode" name="delivery_postcode" class="form-luxury-input" required
                                       value="<?= htmlspecialchars($_POST['delivery_postcode'] ?? '') ?>" placeholder="e.g. 380001" oninput="applyShippingZone()" onchange="applyShippingZone()" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                        </div>

                        <div class="form-luxury-group" style="margin-bottom: 20px;">
                            <label class="chk-field-label" for="address">Delivery Street Address *</label>
                            <textarea id="address" name="address" class="form-luxury-input chk-textarea" rows="3" required placeholder="House/Flat number, Street Name, Town/City"><?= htmlspecialchars($customer['address'] ?? $_POST['address'] ?? '') ?></textarea>
                        </div>

                        <!-- Register Account Checkbox (For Guest) -->
                        <?php if (!$customer): ?>
                        <div style="margin-bottom: 20px; background: #fafafa; border: 1px solid var(--border-light); padding: 15px; border-radius: 4px;">
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                <input type="checkbox" name="create_account" value="1" onchange="document.getElementById('passwordGroup').style.display = this.checked ? 'block' : 'none'">
                                Create a Dievon account for fast 1-click checkout &amp; order tracking
                            </label>
                            <div id="passwordGroup" style="display: none; margin-top: 12px;">
                                <input type="password" name="new_password" placeholder="Create Password" class="form-luxury-input chk-input-sm">
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Multiple Addresses Checkbox -->
                        <div class="chk-block">
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-secondary); cursor: pointer;">
                                <input type="checkbox" name="multiple_addresses" value="1" onchange="document.getElementById('multiAddrNote').style.display = this.checked ? 'block' : 'none'">
                                Ship items in this order to multiple addresses
                            </label>
                            <div id="multiAddrNote" style="display: none; font-size: 12px; color: var(--color-primary); margin-top: 8px; font-weight: 500;">
                                Note: Our atelier concierge will contact you after order placement to assign specific addresses to individual items.
                            </div>
                        </div>

                        <div class="section-advance">
                            <button type="button" class="btn-luxury section-advance-btn"
                                    onclick="advanceTo(2)">
                                Continue to Delivery <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>

                    </div>

                    <!-- ==================== DELIVERY METHOD ==================== -->
                    <div id="step2Content" class="checkout-section is-upcoming" inert>
                        <h2 class="checkout-section-title">
                            <span class="section-name">Delivery Method</span>
                            <button type="button" class="section-edit" onclick="showSection(2)">Edit</button>
                        </h2>
                        <p class="section-summary" id="step2Summary"></p>
                        
                        <p id="shippingZoneNote" class="shipping-zone-note">Enter your postcode and we will work out delivery automatically.</p>
                        <?php
                        // Both options previously carried hardcoded numbers that did not match
                        // what checkout_action.php charges: Standard said "Complimentary"
                        // unconditionally, and Worldwide Express said +₹15.00 while the server
                        // charged the international fee of ₹2,500 — a 166x gap discovered only
                        // after payment. Both now derive from the SAME Store Settings values
                        // shippingCostForZone() uses server-side, so the figure on screen is
                        // the figure charged.
                        // $domesticCost is already computed at the top of this file —
                        // the total needs it before the summary renders. Re-deriving it
                        // here from $grandTotal would feed the shipping-inclusive total
                        // back into the free-shipping threshold and get it wrong.
                        $intlCost = shippingCostForZone('international', $subtotalAfterDiscount, $pdo);
                        ?>
                        <div class="shipping-options-grid" style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 30px;">
                            <!-- Domestic Shipping Option -->
                            <label class="shipping-option-card">
                                <div class="shipping-info-wrap chk-row">
                                    <input type="radio" name="shipping_method" value="standard" checked onclick="updateShippingCost(<?= number_format($domesticCost, 2, '.', '') ?>)">
                                    <div>
                                        <h4 class="chk-row-title"><i class="fa-solid fa-truck-fast chk-accent"></i> Standard Delivery (Domestic)</h4>
                                        <span class="chk-note">
                                            Tracked courier delivery (2-4 business days)<?php
                                            if ($domesticCost > 0 && $storeFreeShipMin > 0) {
                                                echo ' &mdash; free on orders over ' . formatPrice($storeFreeShipMin);
                                            } ?>
                                        </span>
                                    </div>
                                </div>
                                <span class="shipping-price-badge" style="font-weight: 700; color: <?= $domesticCost > 0 ? 'var(--text-primary)' : 'var(--color-accent)' ?>; font-size: <?= $domesticCost > 0 ? '13px' : '12px' ?>; text-transform: <?= $domesticCost > 0 ? 'none' : 'uppercase' ?>;">
                                    <?= $domesticCost > 0 ? '+' . formatPrice($domesticCost) : 'Complimentary' ?>
                                </span>
                            </label>

                            <?php // Offered only when the shop can genuinely deliver abroad.
                                  // This card was always shown, headed "Worldwide Express Shipping
                                  // (DHL / FedEx)" — two couriers with no account behind them — on a
                                  // shop with no country enabled for selling. A shopper could select
                                  // it and be charged the international rate for a service that does
                                  // not exist. shipsInternationally() is the same test the Shipping
                                  // policy page now uses, so the two can never disagree. ?>
                            <?php if (shipsInternationally($pdo)): ?>
                            <!-- International Express Shipping Option -->
                            <label class="shipping-option-card">
                                <div class="shipping-info-wrap chk-row">
                                    <input type="radio" name="shipping_method" value="international_express" onclick="updateShippingCost(<?= number_format($intlCost, 2, '.', '') ?>)">
                                    <div>
                                        <h4 class="chk-row-title"><i class="fa-solid fa-plane-departure chk-accent"></i> International Express Shipping</h4>
                                        <span class="chk-note">Priority international express courier with live tracking (3-5 business days)</span>
                                    </div>
                                </div>
                                <span class="shipping-price-badge" style="font-weight: 700; color: var(--text-primary); font-size: 13px;">+<?= formatPrice($intlCost) ?></span>
                            </label>
                            <?php endif; ?>
                        </div>

                        <!-- Gift Packaging & Gift Message Section -->
                        <div class="gift-options-box" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 25px; border-radius: 6px; margin-bottom: 30px;">
                            <h3 style="font-family: var(--font-heading); font-size: 16px; font-weight: 400; text-transform: uppercase; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-gift chk-accent"></i> Luxury Gift Packaging &amp; Personal Message
                            </h3>
                            
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; cursor: pointer; margin-bottom: 12px;">
                                <input type="checkbox" name="gift_wrap" value="1" onchange="document.getElementById('giftMessageWrap').style.display = this.checked ? 'block' : 'none'">
                                Include Complimentary Signature Gift Box, Silk Ribbon &amp; Card
                            </label>

                            <div id="giftMessageWrap" style="display: none; margin-top: 15px;">
                                <label class="chk-field-label">Personal Handwritten Gift Message</label>
                                <textarea name="gift_message" rows="3" placeholder="Write your message to the recipient here..." class="form-luxury-input" style="width: 100%; padding: 10px; font-size: 13px; resize: none;"></textarea>
                            </div>
                        </div>

                        <!-- Order Notes Section -->
                        <div class="form-luxury-group" style="margin-bottom: 30px;">
                            <label class="chk-field-label" for="notes">Order Notes / Delivery Instructions (Optional)</label>
                            <textarea id="notes" name="notes" class="form-luxury-input chk-textarea" rows="2" placeholder="Gate access code, concierge instructions, special size notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="section-advance">
                            <button type="button" class="btn-luxury section-advance-btn"
                                    onclick="advanceTo(3)">
                                Continue to Payment <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>

                    </div>

                    <!-- ==================== PAYMENT METHOD ==================== -->
                    <div id="step3Content" class="checkout-section is-upcoming" inert>
                        <h2 class="checkout-section-title">
                            <span class="section-name">Payment Method</span>
                            <button type="button" class="section-edit" onclick="showSection(3)">Edit</button>
                        </h2>
                        <p class="section-summary" id="step3Summary"></p>
                        
                        <div class="payment-mode-grid" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 25px;">
                            <label onclick="selectPaymentMode('online')" class="payment-mode-label active-pm" id="pmOnlineLabel" style="display: flex; align-items: center; gap: 12px; border: 1.5px solid var(--color-primary); padding: 16px 20px; border-radius: 6px; background: var(--bg-surface); cursor: pointer;">
                                <input type="radio" name="payment_method" value="online" id="pmOnlineRadio" checked>
                                <div>
                                    <strong class="chk-line">Card / UPI / Netbanking (Razorpay)</strong>
                                    <span class="chk-note">Visa, Mastercard, UPI, wallets &amp; netbanking</span>
                                </div>
                            </label>

                            <label onclick="selectPaymentMode('cod')" class="payment-mode-label" id="pmCodLabel" style="display: flex; align-items: center; gap: 12px; border: 1px solid var(--border-strong); padding: 16px 20px; border-radius: 6px; background: var(--bg-surface); cursor: pointer;">
                                <input type="radio" name="payment_method" value="cod" id="pmCodRadio">
                                <div>
                                    <strong class="chk-line">Cash on Delivery (COD)</strong>
                                    <span class="chk-note">Pay cash to courier upon physical delivery<?php if ($storeCodFee > 0): ?> &middot; <?= formatPrice($storeCodFee) ?> handling fee<?php endif; ?><?php if ($storeCodMax > 0): ?> &middot; available up to <?= formatPrice($storeCodMax) ?><?php endif; ?><?php if (function_exists('codAvailableForPostcode')): ?> &middot; 10-digit Indian mobile required<?php endif; ?></span>
                                </div>
                            </label>

                            <label onclick="selectPaymentMode('later')" class="payment-mode-label" id="pmLaterLabel" style="display: flex; align-items: center; gap: 12px; border: 1px solid var(--border-strong); padding: 16px 20px; border-radius: 6px; background: var(--bg-surface); cursor: pointer;">
                                <input type="radio" name="payment_method" value="later" id="pmLaterRadio">
                                <div>
                                    <strong class="chk-line">Private Tax Invoice &amp; Direct Bank Wire</strong>
                                    <span class="chk-note">Official Tax Invoice sent via email with wire instructions</span>
                                </div>
                            </label>
                        </div>

                        <!-- Razorpay Panel -->
                        <div id="razorpayPanel" class="razorpay-panel" style="margin-bottom: 25px; background: #fafafa; border: 1px solid var(--border-light); padding: 20px; border-radius: 6px;">
                            <div id="razorpayStatusMsg" style="font-size: 13px; color: var(--text-muted);">
                                <i class="fa-solid fa-lock chk-accent"></i> You'll enter your card, UPI, or netbanking details in Razorpay's secure checkout window after you click "Place Secure Order".
                            </div>
                        </div>

                        <!-- Tax Invoice / Billing Address Structure -->
                        <div class="invoice-section-box" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; margin-bottom: 30px;">
                            <h3 style="font-family: var(--font-heading); font-size: 15px; font-weight: 400; text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-file-invoice chk-accent"></i> Tax Invoice &amp; Billing Details
                            </h3>
                            
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; cursor: pointer; margin-bottom: 10px;">
                                <input type="checkbox" name="billing_same" value="1" checked onchange="document.getElementById('billingCustomWrap').style.display = this.checked ? 'none' : 'block'">
                                Billing address same as shipping address
                            </label>

                            <div id="billingCustomWrap" style="display: none; margin-top: 12px;">
                                <input type="text" name="billing_address" placeholder="Company Name / Custom Billing Address" class="form-luxury-input chk-input-sm">
                            </div>

                            <label style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--text-muted); margin-top: 10px; cursor: pointer;">
                                <input type="checkbox" name="send_pdf_invoice" value="1" checked>
                                Automatically email official PDF Tax Invoice upon order completion
                            </label>
                        </div>

                        <div class="btn-step-space-between" style="display: flex; justify-content: space-between;">

                            <button type="button" id="placeOrderBtn" onclick="handleCheckout()" class="btn-luxury" style="padding: 16px 40px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; background: var(--color-primary);">
                                <i class="fa-solid fa-lock" id="btnIcon"></i>
                                <span id="btnText">Place Secure Order</span>
                            </button>
                        </div>
                    </div>

                </form>

            </div>

            
            <!-- Right Side: Order Summary & Coupon Card -->
            <div class="checkout-summary-sidebar">
                <div class="checkout-summary-card">
                    <!-- On a phone this is a toggle: the panel starts collapsed so the
                         customer is not scrolling past the whole basket to reach the form.
                         The total stays on show while collapsed. On desktop the button is
                         inert and the panel is always open. -->
                    <h2 class="summary-title" style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 20px; border-bottom: 1px solid var(--border-light); padding-bottom: 12px;">
                        <button type="button" class="summary-toggle" id="summaryToggle"
                                onclick="toggleOrderSummary()" aria-expanded="false" aria-controls="summaryCollapsible">
                            <span class="summary-toggle-label">Order Summary</span>
                            <span class="summary-toggle-right">
                                <span class="summary-peek" id="chkGrandTotalPeek"><?= formatPrice($grandTotal) ?></span>
                                <i class="fa-solid fa-chevron-down summary-chevron"></i>
                            </span>
                        </button>
                    </h2>

                    <div class="summary-collapsible is-collapsed" id="summaryCollapsible">

                    <!-- Line Items List -->
                    <div style="max-height: 280px; overflow-y: auto; margin-bottom: 20px; padding-right: 5px;">
                        <?php foreach ($cart as $item): ?>
                            <div class="summary-item-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13px; border-bottom: 1px dashed var(--border-light); padding-bottom: 8px;">
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <?php if (!empty($item['image'])): ?>
                                        <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width: 40px; height: 50px; object-fit: cover; border-radius: 3px;"
                                             onerror="this.outerHTML='<span style=&quot;font-size:24px; width:40px; display:inline-block; text-align:center;&quot;><?= htmlspecialchars($item['emoji'] ?? '👗') ?></span>';">
                                    <?php endif; ?>
                                    <div>
                                        <strong style="color: var(--text-primary); font-size: 13px;"><?= htmlspecialchars($item['name']) ?></strong>
                                        <div style="font-size: 11px; color: var(--text-muted);">Qty: <?= $item['quantity'] ?> <?= !empty($item['color_name']) ? '• ' . htmlspecialchars($item['color_name']) : '' ?> <?= variantSizeLabel($item['variant_name'] ?? '') !== '' ? '• Size: ' . htmlspecialchars(variantSizeLabel($item['variant_name'])) : '' ?></div>
                                    </div>
                                </div>
                                <span style="font-weight: 600; color: var(--text-primary);"><?= formatPrice($item['price'] * $item['quantity']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Promo Coupon Box Inside Checkout -->
                    <div style="margin-bottom: 20px; border-top: 1px solid var(--border-light); padding-top: 15px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-primary); margin-bottom: 8px;">
                            <i class="fa-solid fa-ticket"></i> Apply Coupon Code
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="checkoutCouponInput" placeholder="COUPON" value="<?= htmlspecialchars($appliedPromo['code'] ?? '') ?>" style="flex: 1; padding: 8px 12px; font-size: 12px; text-transform: uppercase; border: 1px solid var(--border-strong); background: var(--bg-surface-soft);">
                            <button type="button" onclick="applyCheckoutCoupon()" class="btn-luxury" style="padding: 8px 14px; font-size: 10px; text-transform: uppercase;">Apply</button>
                        </div>
                        <div id="checkoutCouponMsg" style="font-size: 12px; margin-top: 6px; font-weight: 500;">
                            <?php if ($appliedPromo): ?>
                                <span style="color: var(--color-success);">🎉 Coupon <strong><?= htmlspecialchars($appliedPromo['code']) ?></strong> active!</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Totals Breakdown -->
                    <div class="summary-totals-wrap" style="border-top: 1px solid var(--border-light); padding-top: 15px;">
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; color: var(--text-secondary);">
                            <span>Subtotal</span>
                            <span id="chkSubtotal"><?= formatPrice($cartTotal) ?></span>
                        </div>
                        
                        <div id="chkDiscountRow" style="display: <?= $appliedPromo ? 'flex' : 'none' ?>; justify-content: space-between; margin-bottom: 8px; font-size: 13px; color: var(--color-success);">
                            <span>Discount</span>
                            <span id="chkDiscountAmount">-<?= formatPrice($discountAmount) ?></span>
                        </div>

                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 13px; color: var(--text-secondary);">
                            <span>Shipping</span>
                            <span id="chkShippingText" style="font-weight: 600; color: var(--color-accent); font-size: 12px; text-transform: uppercase;"><?= $domesticCost > 0 ? formatPrice($domesticCost) : "Complimentary" ?></span>
                        </div>

                        <div id="chkCodFeeRow" style="display: none; justify-content: space-between; margin-bottom: 8px; font-size: 13px; color: var(--text-secondary);">
                            <span>COD Handling Fee</span>
                            <span id="chkCodFee" style="font-weight: 600;">+<?= formatPrice($storeCodFee) ?></span>
                        </div>

                        <div class="summary-grand-total" style="display: flex; justify-content: space-between; margin-top: 15px; padding-top: 12px; border-top: 1.5px solid var(--border-strong); font-size: 18px; font-weight: 700; color: var(--color-primary);">
                            <span>Total Due</span>
                            <span id="chkGrandTotal"><?= formatPrice($grandTotal) ?></span>
                        </div>
                    </div>

                    </div><!-- /.summary-collapsible -->
                </div>
            </div>

        </div>

    </div>
</section>

<script>
/* ── Order summary: collapsible on phones ──────────────────────────────────
 * The summary sits below the form on a narrow screen, so leaving it expanded
 * pushed the page to 4+ screens of scroll. Collapsed by default under 992px;
 * the grand total stays visible in the header either way. The matching CSS
 * makes the panel unconditionally open on desktop, so if this script fails to
 * run the customer still sees everything.
 */
function toggleOrderSummary() {
    var panel = document.getElementById('summaryCollapsible');
    var btn   = document.getElementById('summaryToggle');
    if (!panel || !btn) { return; }
    var nowOpen = panel.classList.toggle('is-collapsed') === false;
    btn.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
}

function syncSummaryPeek() {
    var peek  = document.getElementById('chkGrandTotalPeek');
    var total = document.getElementById('chkGrandTotal');
    if (peek && total) { peek.textContent = total.textContent; }
}

// These three are the single source of truth for the JavaScript side of the
// summary. cartTotalRaw in particular did NOT exist as a global before — line 552
// read it via `typeof cartTotalRaw !== 'undefined'` and always got 0, so the
// free-shipping threshold was compared against nothing and an order that
// qualified was still quoted ₹99.
let cartTotalRaw = <?= (float)$cartTotal ?>;
let chkDiscount  = <?= (float)$discountAmount ?>;
// Starts at the domestic rate, matching what the page has already printed.
let selectedShippingCost = <?= (float)$domesticCost ?>;
// COD handling fee (0 when disabled). Only added to the total while COD is the
// chosen payment mode — the server re-derives the same figure in checkout_action.php.
let selectedCodFee = <?= (float)$storeCodFee ?>;
let currentPaymentMode = 'online'; // 'online' | 'cod' | 'later'

/* ── Shipping zone, driven by the postcode ────────────────────────────────────
 * The customer no longer picks domestic vs international — the destination decides.
 * Mirrors detectShippingZone() in config/config.php; checkout_action.php re-derives
 * it server-side, so editing this in devtools cannot buy international postage at
 * the domestic rate or force COD on an overseas order.
 */
const DIEVON_SHIPPING = {
    freeOver:      <?= (float)($storeFreeShipMin ?? 150) ?>,
    domesticFee:   <?= (float)($storeShipFee ?? 15) ?>,
    internationalFee: <?= (float)($storeIntlShipFee ?? 45) ?>,
    // The destination's own free-shipping threshold. Without it the international
    // fee was charged on every overseas order however large, while the Countries
    // screen promised free delivery over this figure.
    internationalFreeOver: <?= (float)($intlFreeShipMin ?? 0) ?>
};

function detectZoneFromPostcode(pc) {
    const clean = String(pc || '').toUpperCase().replace(/\s+/g, '');
    if (!clean) return null;                       // nothing typed yet
    return /^[1-9][0-9]{5}$/.test(clean) ? 'domestic' : 'international';
}

function applyShippingZone() {
    const pcEl = document.getElementById('delivery_postcode');
    const zone = detectZoneFromPostcode(pcEl ? pcEl.value : '');
    const note = document.getElementById('shippingZoneNote');

    const stdRadio  = document.querySelector('input[name="shipping_method"][value="standard"]');
    const intlRadio = document.querySelector('input[name="shipping_method"][value="international_express"]');
    const codLabel  = document.getElementById('pmCodLabel');
    const codRadio  = document.getElementById('pmCodRadio');

    if (!zone) {                                   // no postcode yet — leave as-is
        if (note) { note.textContent = 'Enter your postcode and we will work out delivery automatically.'; note.className = 'shipping-zone-note'; }
        return;
    }

    const domestic = zone === 'domestic';

    // Free shipping is judged on the total AFTER any coupon — the same basis the
    // server charges on.
    //
    // This read the pre-discount subtotal, so the two disagreed whenever a
    // coupon straddled the threshold: PHP saw ₹1,890 after a ₹210 discount and
    // charged ₹99, while this saw ₹2,100 and displayed "Complimentary". The
    // customer watched Total Due drop by ₹99 the moment they typed their PIN,
    // and then paid the ₹99 anyway.
    const cartTotalBeforeDiscount = typeof cartTotalRaw !== 'undefined' ? (parseFloat(cartTotalRaw) || 0) : 0;
    const cartTotal = Math.max(0, cartTotalBeforeDiscount - (parseFloat(typeof chkDiscount !== 'undefined' ? chkDiscount : 0) || 0));
    // Both zones honour their own threshold, matching shippingCostForZone().
    const cost = domestic
        ? (DIEVON_SHIPPING.freeOver > 0 && cartTotal >= DIEVON_SHIPPING.freeOver ? 0 : DIEVON_SHIPPING.domesticFee)
        : (DIEVON_SHIPPING.internationalFreeOver > 0 && cartTotal >= DIEVON_SHIPPING.internationalFreeOver
              ? 0 : DIEVON_SHIPPING.internationalFee);

    // Select the matching method and stop the other being chosen by hand.
    if (stdRadio)  { stdRadio.checked  = domestic;  stdRadio.disabled  = !domestic; stdRadio.closest('.shipping-option-card')?.classList.toggle('shipping-option-disabled', !domestic); }
    if (intlRadio) { intlRadio.checked = !domestic; intlRadio.disabled = domestic;  intlRadio.closest('.shipping-option-card')?.classList.toggle('shipping-option-disabled', domestic); }

    // Was updateShipping(cost) — no such function. The real one is
    // updateShippingCost(), so applyShippingZone() threw a ReferenceError on every
    // keystroke in the postcode field and stopped dead: the shipping figure and
    // the grand total in the summary never updated, and the COD hiding below this
    // line never ran either. Orders were still CHARGED correctly, because
    // checkout_action.php recalculates server-side and ignores the browser's
    // number — but the customer was shown the wrong total before paying.
    updateShippingCost(cost);

    // COD cannot be collected abroad — hide it and move the selection off it.
    if (codLabel && codRadio) {
        codLabel.style.display = domestic ? '' : 'none';
        codRadio.disabled = !domestic;
        if (!domestic && codRadio.checked) {
            const online = document.getElementById('pmOnlineRadio');
            if (online) { online.checked = true; if (typeof selectPaymentMode === 'function') selectPaymentMode('online'); }
        }
    }

    if (note) {
        note.textContent = domestic
            ? 'Delivering within India — standard tracked courier applied.'
            : 'International address detected — express international shipping applied. Cash on Delivery is not available outside India.';
        note.className = 'shipping-zone-note ' + (domestic ? 'shipping-zone-note--domestic' : 'shipping-zone-note--intl');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    applyShippingZone();
    // Establish the starting state through the same code path the buttons use,
    // so the markup and the JS can never disagree about which section is open.
    showSection(1);
});


/* The three-step wizard is gone: the sections are all on the page at once now,
   so there is nothing to navigate between. The required-field check that used to
   live in goToStep() moved into handleCheckout() below — and it had to, because
   form.submit() bypasses HTML5 validation entirely, so without it the `required`
   attributes on the address fields would never have been enforced on this path. */


function applySavedAddress(value) {
    if (!value) return;
    const parts = value.split('|');
    if (parts.length < 3) return;

    // Only overwrite a field when the saved address actually has something for
    // it. The postcode is always empty (customers has no postcode column), and
    // blanking a postcode the shopper had already typed would be a regression.
    const set = function (id, val) {
        if (val === undefined || val === '') { return; }
        const el = document.getElementById(id);
        if (el) { el.value = val; }
    };

    set('address', parts[0]);
    set('delivery_postcode', parts[1]);
    set('phone', parts[2]);

    // The postcode drives the shipping quote, so re-run the estimate rather
    // than leaving a stale figure from the previous address on screen. This is
    // the same function the postcode field fires on input/change.
    if (typeof applyShippingZone === 'function') { applyShippingZone(); }
}

function updateShippingCost(cost) {
    selectedShippingCost = parseFloat(cost);
    document.getElementById('deliveryChargeInput').value = selectedShippingCost.toFixed(2);
    document.getElementById('chkShippingText').textContent = selectedShippingCost === 0 ? 'Complimentary' : formatPriceJS(selectedShippingCost);
    
    // Recalculate from the live globals. These used to be PHP literals baked in at
    // page load, so changing the quantity in the cart drawer updated the sidebar
    // subtotal while the Order Summary and Total Due kept the values they had when
    // the page was first opened.
    recalcCheckoutTotal();
}

/**
 * The one place the checkout total is worked out.
 *
 * Everything that can change a figure — postcode, promo, quantity — updates a
 * global and calls this. Before, three separate places each did their own sum
 * from their own copy of the numbers, and they went out of step with each other.
 */
function recalcCheckoutTotal() {
    // The COD handling fee joins the total only while cash is being collected;
    // switching back to online or bank wire drops it again.
    const codFeeAdd = currentPaymentMode === 'cod' ? selectedCodFee : 0;
    const total = Math.max(0, cartTotalRaw - chkDiscount + selectedShippingCost + codFeeAdd);
    const sub = document.getElementById('chkSubtotal');
    if (sub) { sub.textContent = formatPriceJS(cartTotalRaw); }
    const disc = document.getElementById('chkDiscountAmount');
    if (disc) { disc.textContent = '-' + formatPriceJS(chkDiscount); }
    const ship = document.getElementById('chkShippingText');
    if (ship) { ship.textContent = selectedShippingCost === 0 ? 'Complimentary' : formatPriceJS(selectedShippingCost); }
    const feeRow = document.getElementById('chkCodFeeRow');
    const feeEl  = document.getElementById('chkCodFee');
    if (feeRow) { feeRow.style.display = currentPaymentMode === 'cod' ? 'flex' : 'none'; }
    if (feeEl)  { feeEl.textContent = '+' + formatPriceJS(codFeeAdd); }
    const grand = document.getElementById('chkGrandTotal');
    if (grand) { grand.textContent = formatPriceJS(total); }
    if (typeof syncSummaryPeek === 'function') { syncSummaryPeek(); }
    return total;
}

/**
 * Fired by includes/footer.php whenever the cart changes, including from the
 * drawer on this very page. Without this the checkout summary was frozen at
 * whatever the cart held when the page was opened.
 */
function onCartUpdated(cart) {
    if (!cart) { return; }
    cartTotalRaw = parseFloat(cart.cart_total_raw || 0);
    if (!cart.items || cart.items.length === 0) {
        // Nothing left to buy — send them back rather than leaving a checkout
        // form sitting above an empty bag.
        window.location.href = window.SITE_URL + '/cart';
        return;
    }
    recalcCheckoutTotal();
}

function selectPaymentMode(mode) {
    currentPaymentMode = mode;
    document.getElementById('pmOnlineLabel').className = mode === 'online' ? 'payment-mode-label active-pm' : 'payment-mode-label';
    document.getElementById('pmCodLabel').className = mode === 'cod' ? 'payment-mode-label active-pm' : 'payment-mode-label';
    document.getElementById('pmLaterLabel').className = mode === 'later' ? 'payment-mode-label active-pm' : 'payment-mode-label';

    document.getElementById('razorpayPanel').style.display = mode === 'online' ? 'block' : 'none';

    // The COD fee moves the Total Due, so every payment switch re-runs the
    // single total calculation.
    recalcCheckoutTotal();
}

function toggleCheckoutLoginModal() {
    const box = document.getElementById('checkoutLoginBox');
    box.style.display = box.style.display === 'none' ? 'block' : 'none';
}

function applyCheckoutCoupon() {
    const code = document.getElementById('checkoutCouponInput').value.trim();
    const msg = document.getElementById('checkoutCouponMsg');
    if (!code) {
        msg.style.color = 'var(--color-danger)';
        msg.textContent = 'Please enter a coupon code.';
        return;
    }
    // No local re-declaration — this shadowed the global and reintroduced the
    // stale-value bug the global exists to prevent.
    // Absolute. promo_handler.php sits at the web root, but "Buy Now" lands the
    // shopper on /pages/checkout.php, where a bare filename resolved to
    // /pages/promo_handler.php — a 404. Apply then did nothing at all: no
    // discount, no error, no message. Arriving from /cart happened to work,
    // which made it look random.
    fetch(`<?= SITE_URL ?>/promo_handler.php?action=validate&code=${encodeURIComponent(code)}&cart_total=${cartTotalRaw}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                msg.style.color = 'var(--color-success)';
                msg.innerHTML = `🎉 Coupon <strong>${data.code}</strong> applied! Saved ${formatPriceJS(data.discount_amount)}`;
                document.getElementById('chkDiscountRow').style.display = 'flex';
                document.getElementById('chkDiscountAmount').textContent = '-' + formatPriceJS(data.discount_amount);
                
                chkDiscount = parseFloat(data.discount_amount) || 0;
                // The discount just changed, so the free-shipping test has to be
                // re-run — otherwise the delivery fee stays at whatever it was
                // before the coupon and the summary contradicts the charge.
                if (typeof applyShippingZone === 'function') { applyShippingZone(); }
                recalcCheckoutTotal();
    syncSummaryPeek();
            } else {
                msg.style.color = 'var(--color-danger)';
                msg.textContent = data.message;
            }
        });
}

function handleCheckoutFormSubmit(form) {
    const btns = form.querySelectorAll('button[type="submit"], .btn-place-order');
    let isAlreadySubmitting = form.dataset.submitting === 'true';
    if (isAlreadySubmitting) return false;

    form.dataset.submitting = 'true';
    btns.forEach(btn => {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing Order...';
    });
    return true;
}

function setPlaceOrderBusy(busy, label) {
    const btn = document.getElementById('placeOrderBtn');
    const btnText = document.getElementById('btnText');
    btn.disabled = busy;
    if (label) btnText.textContent = label;
}

/* ── One section at a time ────────────────────────────────────────────────────
 * Replaces the old 1/2/3 tab wizard. Only the section being worked on is open.
 * Sections already done collapse to a single summary line with an Edit link, so
 * nothing is lost — a shopper can still go back and correct an address — and
 * sections not yet reached are collapsed to just their title.
 *
 * Collapsing is CSS-only (.is-collapsed hides every child except the heading and
 * its summary), so the fields stay in the DOM and keep their values. The `inert`
 * attribute keeps them out of the tab order while hidden. Crucially none of this
 * uses the disabled attribute — a disabled input is not submitted, which would
 * have silently dropped the delivery method, notes and payment choice from POST.
 */
// The furthest section the shopper has got to. Without this, pressing Edit on
// the address would knock Delivery and Payment back to "not reached yet" and
// make them walk forward through both again to return to where they were.
let furthestSection = 1;

function showSection(n) {
    if (n > furthestSection) { furthestSection = n; }

    for (let i = 1; i <= 3; i++) {
        const el = document.getElementById('step' + i + 'Content');
        if (!el) { continue; }

        el.classList.remove('is-open', 'is-complete', 'is-upcoming');

        if (i === n) {
            el.classList.add('is-open');
            el.removeAttribute('inert');
        } else {
            // Done if the shopper has already been through it — whether that is
            // above the open section or below it after pressing Edit.
            const done = i < n || i <= furthestSection;
            el.classList.add(done ? 'is-complete' : 'is-upcoming');
            el.setAttribute('inert', '');
            if (done) { writeSummary(i); }
        }
    }

    const target = document.getElementById('step' + n + 'Content');
    if (target) {
        const top = target.getBoundingClientRect().top + window.scrollY - 90;
        window.scrollTo({ top: top, behavior: 'smooth' });
    }
}

/* Validate the section being left before opening the next one, so the shopper is
   told what is missing where it is missing rather than at the very end. */
function advanceTo(n) {
    const leaving = document.getElementById('step' + (n - 1) + 'Content');
    if (leaving && !validateSection(leaving)) { return; }
    showSection(n);
}

function validateSection(section) {
    const fields = section.querySelectorAll('[required]');
    for (const f of fields) {
        if (!f.checkValidity()) {
            f.reportValidity();
            return false;
        }
    }
    return true;
}

/* A collapsed section shows what was chosen, otherwise it is just a dead title. */
function writeSummary(n) {
    const out = document.getElementById('step' + n + 'Summary');
    if (!out) { return; }
    const val = id => (document.getElementById(id) || {}).value || '';
    let text = '';

    if (n === 1) {
        text = [val('customer_name'), val('address'), val('delivery_postcode')]
               .map(v => v.trim()).filter(Boolean).join(', ');
    } else if (n === 2) {
        const ship = document.querySelector('input[name="shipping_method"]:checked');
        const card = ship ? ship.closest('.shipping-option-card') : null;
        const name = card ? card.textContent.trim().split('\n')[0].trim() : '';
        const cost = (document.getElementById('chkShippingText') || {}).textContent || '';
        text = [name, cost.trim()].filter(Boolean).join(' — ');
    } else if (n === 3) {
        const pay = document.querySelector('input[name="payment_method"]:checked');
        const lbl = pay ? pay.closest('.payment-mode-label') : null;
        text = lbl ? lbl.textContent.trim().split('\n')[0].trim() : '';
    }
    out.textContent = text;
}

function handleCheckout() {
    const form = document.getElementById('checkoutForm');
    if (form.dataset.submitting === 'true') return;

    // form.submit() does NOT run HTML5 validation, so ask for it explicitly.
    // reportValidity() also focuses and scrolls to the first bad field, which is
    // what makes a single-page checkout usable — the shopper is taken straight to
    // whatever is missing instead of hunting for it.
    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
        return;
    }

    const checkedMethod = form.querySelector('input[name="payment_method"]:checked');
    const paymentMethod = checkedMethod ? checkedMethod.value : 'later';

    if (paymentMethod !== 'online') {
        if (handleCheckoutFormSubmit(form)) form.submit();
        return;
    }

    startRazorpayCheckout(form);
}

function startRazorpayCheckout(form) {
    if (typeof Razorpay === 'undefined') {
        alert('Payment gateway is still loading. Please try again in a moment.');
        return;
    }

    setPlaceOrderBusy(true, 'Preparing secure payment...');

    const postcode = document.getElementById('delivery_postcode').value;
    const orderType = form.querySelector('input[name="order_type"]').value;
    const snapshotFields = {
        postcode: postcode,
        order_type: orderType,
        customer_name: document.getElementById('customer_name').value,
        customer_email: document.getElementById('customer_email').value,
        phone: document.getElementById('phone').value,
        address: document.getElementById('address').value,
        notes: document.getElementById('notes') ? document.getElementById('notes').value : '',
    };
    const snapshotBody = Object.keys(snapshotFields).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(snapshotFields[k])).join('&');

    fetch(window.SITE_URL + '/actions/razorpay_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: snapshotBody,
    })
    .then(r => r.json())
    .then(data => {
        setPlaceOrderBusy(false, 'Place Secure Order');

        if (data.error) {
            alert(data.error);
            return;
        }

        const rzp = new Razorpay({
            key: data.key_id,
            order_id: data.order_id,
            amount: data.amount,
            currency: data.currency,
            name: <?= json_encode(SHOP_NAME) ?>,
            description: 'Order Payment',
            prefill: {
                name: document.getElementById('customer_name').value,
                email: document.getElementById('customer_email').value,
                contact: document.getElementById('phone').value,
            },
            handler: function (response) {
                document.getElementById('rzpOrderIdInput').value = response.razorpay_order_id;
                document.getElementById('rzpPaymentIdInput').value = response.razorpay_payment_id;
                document.getElementById('rzpSignatureInput').value = response.razorpay_signature;
                if (handleCheckoutFormSubmit(form)) form.submit();
            },
            modal: {
                ondismiss: function () {
                    setPlaceOrderBusy(false, 'Place Secure Order');
                }
            }
        });
        rzp.on('payment.failed', function (resp) {
            alert('Payment failed: ' + (resp.error && resp.error.description ? resp.error.description : 'Please try again or choose another payment method.'));
            setPlaceOrderBusy(false, 'Place Secure Order');
        });
        rzp.open();
    })
    .catch(() => {
        alert('Could not start payment. Please try again.');
        setPlaceOrderBusy(false, 'Place Secure Order');
    });
}
</script>

<?php
// Drop the marketing columns from the footer — they are 43% of this page's
// height on a phone, and every link in them leads away from the purchase.
$minimalFooter = true;
require_once __DIR__ . '/../includes/footer.php';
?>
