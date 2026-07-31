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

// Calculate totals
$cartTotal = 0.0;
foreach ($cart as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}

// Load applied promo from session
$appliedPromo   = $_SESSION['promo'] ?? null;
$discountAmount = $appliedPromo ? (float)$appliedPromo['discount_amount'] : 0.0;
$grandTotal     = max(0, $cartTotal - $discountAmount);

// Fetch logged-in customer info and saved addresses if available
$customer = null;
$savedAddresses = [];
if (isset($_SESSION['customer_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['customer_id']]);
        $customer = $stmt->fetch();
        
        // Mock address book for customer if logged in
        if ($customer) {
            $savedAddresses[] = [
                'id' => 1,
                'name' => $customer['name'],
                'address' => $customer['address'] ?? '123 Mayfair High St, London',
                'postcode' => 'W1K 4QA',
                'phone' => $customer['phone'] ?? '+44 7700 900123'
            ];
        }
    } catch (PDOException $e) {}
}

$pageTitle = "Checkout";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>

<!-- ══ Luxury Hero ═══════════════════════════════════════ -->
<section class="luxury-hero checkout-hero">
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
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger checkout-alert" style="background: #fef2f2; border: 1px solid #f87171; color: #991b1b; padding: 15px; border-radius: 6px; margin-bottom: 25px;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <?php foreach ($errors as $err): ?>
                            <div><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Guest Checkout / Account Login Header Banner -->
                <div class="checkout-auth-banner" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <?php if ($customer): ?>
                            <span style="font-size: 14px; font-weight: 600; color: var(--color-primary);"><i class="fa-solid fa-circle-check"></i> Logged in as <?= htmlspecialchars($customer['name']) ?></span>
                            <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 2px;"><?= htmlspecialchars($customer['email']) ?></span>
                        <?php else: ?>
                            <span style="font-size: 13px; font-weight: 600; color: var(--text-primary);"><i class="fa-solid fa-user-tag"></i> Checking out as Guest</span>
                            <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 2px;">Fast &amp; secure guest checkout. No password required.</span>
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

                <!-- Inline Login Box (Collapsible) -->
                <?php if (!$customer): ?>
                <div id="checkoutLoginBox" style="display: none; background: #fafafa; border: 1px solid var(--border-strong); padding: 25px; margin-bottom: 30px; border-radius: 6px;">
                    <h4 style="font-family: var(--font-heading); font-size: 16px; margin-bottom: 15px;">Customer Login</h4>
                    <form action="<?= SITE_URL ?>/actions/customer_action.php?action=login" method="POST">
                        <div class="form-row">
                            <input type="email" name="email" placeholder="Email Address" required class="form-luxury-input" style="padding: 10px; font-size: 13px;">
                            <input type="password" name="password" placeholder="Password" required class="form-luxury-input" style="padding: 10px; font-size: 13px;">
                        </div>
                        <button type="submit" class="btn-luxury" style="padding: 10px 20px; font-size: 11px;">Sign In to Account</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Step Navigation Tabs -->
                <div class="step-nav-header">
                    <span class="step-nav-tab active-tab" id="tabStep1">1. Shipping &amp; Address</span>
                    <span class="step-nav-tab" id="tabStep2">2. Delivery &amp; Gift Options</span>
                    <span class="step-nav-tab" id="tabStep3">3. Payment &amp; Invoice</span>
                </div>

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
                    <div id="step1Content">
                        
                        <!-- Saved Address Book Picker (Logged In Users) -->
                        <?php if ($customer && !empty($savedAddresses)): ?>
                        <div class="address-book-picker" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; margin-bottom: 25px; border-radius: 6px;">
                            <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-primary); margin-bottom: 8px;">
                                <i class="fa-solid fa-address-book"></i> Select from Address Book
                            </label>
                            <select onchange="applySavedAddress(this.value)" class="form-luxury-input" style="width: 100%; padding: 10px; font-size: 13px;">
                                <option value="">-- Use New Address Below --</option>
                                <?php foreach ($savedAddresses as $addr): ?>
                                    <option value="<?= htmlspecialchars($addr['address']) ?>|<?= htmlspecialchars($addr['postcode']) ?>|<?= htmlspecialchars($addr['phone']) ?>">
                                        <?= htmlspecialchars($addr['name']) ?> — <?= htmlspecialchars($addr['address']) ?>, <?= htmlspecialchars($addr['postcode']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <h2 class="checkout-step-title" style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; margin-bottom: 20px;">
                            Shipping Address
                        </h2>
                        
                        <div class="form-row">
                            <div class="form-luxury-group">
                                <label for="customer_name" style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Full Name *</label>
                                <input type="text" id="customer_name" name="customer_name" class="form-luxury-input" required 
                                       value="<?= htmlspecialchars($customer['name'] ?? $_POST['customer_name'] ?? '') ?>" placeholder="Jane Smith" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                            <div class="form-luxury-group">
                                <label for="customer_email" style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Email Address *</label>
                                <input type="email" id="customer_email" name="customer_email" class="form-luxury-input" required
                                       value="<?= htmlspecialchars($customer['email'] ?? $_POST['customer_email'] ?? '') ?>" placeholder="jane@example.com" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-luxury-group">
                                <label for="phone" style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" class="form-luxury-input" required
                                       value="<?= htmlspecialchars($customer['phone'] ?? $_POST['phone'] ?? '') ?>" placeholder="+44 7700 900 123" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                            <div class="form-luxury-group">
                                <label for="delivery_postcode" style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Postcode / Zip *</label>
                                <input type="text" id="delivery_postcode" name="delivery_postcode" class="form-luxury-input" required
                                       value="<?= htmlspecialchars($_POST['delivery_postcode'] ?? '') ?>" placeholder="W1K 4QA" style="width: 100%; padding: 12px; font-size: 13px;">
                            </div>
                        </div>

                        <div class="form-luxury-group" style="margin-bottom: 20px;">
                            <label for="address" style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Delivery Street Address *</label>
                            <textarea id="address" name="address" class="form-luxury-input" rows="3" required placeholder="House/Flat number, Street Name, Town/City" style="width: 100%; padding: 12px; font-size: 13px; resize: none;"><?= htmlspecialchars($customer['address'] ?? $_POST['address'] ?? '') ?></textarea>
                        </div>

                        <!-- Register Account Checkbox (For Guest) -->
                        <?php if (!$customer): ?>
                        <div style="margin-bottom: 20px; background: #fafafa; border: 1px solid var(--border-light); padding: 15px; border-radius: 4px;">
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                <input type="checkbox" name="create_account" value="1" onchange="document.getElementById('passwordGroup').style.display = this.checked ? 'block' : 'none'">
                                Create a Dievon account for fast 1-click checkout &amp; order tracking
                            </label>
                            <div id="passwordGroup" style="display: none; margin-top: 12px;">
                                <input type="password" name="new_password" placeholder="Create Password" class="form-luxury-input" style="width: 100%; padding: 10px; font-size: 13px;">
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Multiple Addresses Checkbox -->
                        <div style="margin-bottom: 25px;">
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-secondary); cursor: pointer;">
                                <input type="checkbox" name="multiple_addresses" value="1" onchange="document.getElementById('multiAddrNote').style.display = this.checked ? 'block' : 'none'">
                                Ship items in this order to multiple addresses
                            </label>
                            <div id="multiAddrNote" style="display: none; font-size: 12px; color: var(--color-primary); margin-top: 8px; font-weight: 500;">
                                Note: Our atelier concierge will contact you after order placement to assign specific addresses to individual items.
                            </div>
                        </div>

                        <div class="btn-step-row" style="margin-top: 25px;">
                            <button type="button" onclick="goToStep(2)" class="btn-luxury" style="padding: 14px 32px; font-size: 12px; text-transform: uppercase;">
                                Continue to Shipping &amp; Options <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- ==================== STEP 2: SHIPPING METHOD & GIFT OPTIONS ==================== -->
                    <div id="step2Content" style="display: none;">
                        <h2 class="checkout-step-title" style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; margin-bottom: 20px;">
                            Select Shipping Method
                        </h2>
                        
                        <div class="shipping-options-grid" style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 30px;">
                            <!-- Domestic Shipping Option -->
                            <label class="shipping-option-card">
                                <div class="shipping-info-wrap" style="display: flex; align-items: center; gap: 12px;">
                                    <input type="radio" name="shipping_method" value="standard" checked onclick="updateShippingCost(0.00)">
                                    <div>
                                        <h4 style="margin: 0; font-size: 14px; font-weight: 600;"><i class="fa-solid fa-truck-fast" style="color: var(--color-primary);"></i> Standard Delivery (Domestic)</h4>
                                        <span style="font-size: 12px; color: var(--text-muted);">Tracked courier delivery (2-4 business days)</span>
                                    </div>
                                </div>
                                <span class="shipping-price-badge" style="font-weight: 700; color: var(--color-accent); font-size: 12px; text-transform: uppercase;">Complimentary</span>
                            </label>

                            <!-- International Express Shipping Option -->
                            <label class="shipping-option-card">
                                <div class="shipping-info-wrap" style="display: flex; align-items: center; gap: 12px;">
                                    <input type="radio" name="shipping_method" value="international_express" onclick="updateShippingCost(15.00)">
                                    <div>
                                        <h4 style="margin: 0; font-size: 14px; font-weight: 600;"><i class="fa-solid fa-plane-departure" style="color: var(--color-primary);"></i> Worldwide Express Shipping (DHL / FedEx)</h4>
                                        <span style="font-size: 12px; color: var(--text-muted);">Priority international express courier with live tracking (3-5 business days)</span>
                                    </div>
                                </div>
                                <span class="shipping-price-badge" style="font-weight: 700; color: var(--text-primary); font-size: 13px;">+<?= formatPrice(15.00) ?></span>
                            </label>
                        </div>

                        <!-- Gift Packaging & Gift Message Section -->
                        <div class="gift-options-box" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 25px; border-radius: 6px; margin-bottom: 30px;">
                            <h3 style="font-family: var(--font-heading); font-size: 16px; font-weight: 400; text-transform: uppercase; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-gift" style="color: var(--color-primary);"></i> Luxury Gift Packaging &amp; Personal Message
                            </h3>
                            
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; cursor: pointer; margin-bottom: 12px;">
                                <input type="checkbox" name="gift_wrap" value="1" onchange="document.getElementById('giftMessageWrap').style.display = this.checked ? 'block' : 'none'">
                                Include Complimentary Signature Gift Box, Silk Ribbon &amp; Card
                            </label>

                            <div id="giftMessageWrap" style="display: none; margin-top: 15px;">
                                <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Personal Handwritten Gift Message</label>
                                <textarea name="gift_message" rows="3" placeholder="Write your message to the recipient here..." class="form-luxury-input" style="width: 100%; padding: 10px; font-size: 13px; resize: none;"></textarea>
                            </div>
                        </div>

                        <!-- Order Notes Section -->
                        <div class="form-luxury-group" style="margin-bottom: 30px;">
                            <label for="notes" style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Order Notes / Delivery Instructions (Optional)</label>
                            <textarea id="notes" name="notes" class="form-luxury-input" rows="2" placeholder="Gate access code, concierge instructions, special size notes..." style="width: 100%; padding: 12px; font-size: 13px; resize: none;"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="btn-step-space-between" style="display: flex; justify-content: space-between;">
                            <button type="button" onclick="goToStep(1)" class="btn-luxury-outline" style="padding: 12px 24px; font-size: 12px;">Back</button>
                            <button type="button" onclick="goToStep(3)" class="btn-luxury" style="padding: 14px 32px; font-size: 12px; text-transform: uppercase;">
                                Continue to Payment &amp; Invoice <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- ==================== STEP 3: PAYMENT METHOD STRUCTURE & INVOICE ==================== -->
                    <div id="step3Content" style="display: none;">
                        <h2 class="checkout-step-title" style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; margin-bottom: 20px;">
                            Select Payment Method Structure
                        </h2>
                        
                        <div class="payment-mode-grid" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 25px;">
                            <label onclick="selectPaymentMode('online')" class="payment-mode-label active-pm" id="pmOnlineLabel" style="display: flex; align-items: center; gap: 12px; border: 1.5px solid var(--color-primary); padding: 16px 20px; border-radius: 6px; background: var(--bg-surface); cursor: pointer;">
                                <input type="radio" name="payment_method" value="online" id="pmOnlineRadio" checked>
                                <div>
                                    <strong style="font-size: 14px; display: block;">Card / UPI / Netbanking (Razorpay)</strong>
                                    <span style="font-size: 12px; color: var(--text-muted);">Visa, Mastercard, UPI, wallets &amp; netbanking</span>
                                </div>
                            </label>

                            <label onclick="selectPaymentMode('cod')" class="payment-mode-label" id="pmCodLabel" style="display: flex; align-items: center; gap: 12px; border: 1px solid var(--border-strong); padding: 16px 20px; border-radius: 6px; background: var(--bg-surface); cursor: pointer;">
                                <input type="radio" name="payment_method" value="cod" id="pmCodRadio">
                                <div>
                                    <strong style="font-size: 14px; display: block;">Cash on Delivery (COD)</strong>
                                    <span style="font-size: 12px; color: var(--text-muted);">Pay cash to courier upon physical delivery</span>
                                </div>
                            </label>

                            <label onclick="selectPaymentMode('later')" class="payment-mode-label" id="pmLaterLabel" style="display: flex; align-items: center; gap: 12px; border: 1px solid var(--border-strong); padding: 16px 20px; border-radius: 6px; background: var(--bg-surface); cursor: pointer;">
                                <input type="radio" name="payment_method" value="later" id="pmLaterRadio">
                                <div>
                                    <strong style="font-size: 14px; display: block;">Private Tax Invoice &amp; Direct Bank Wire</strong>
                                    <span style="font-size: 12px; color: var(--text-muted);">Official Tax Invoice sent via email with wire instructions</span>
                                </div>
                            </label>
                        </div>

                        <!-- Razorpay Panel -->
                        <div id="razorpayPanel" class="razorpay-panel" style="margin-bottom: 25px; background: #fafafa; border: 1px solid var(--border-light); padding: 20px; border-radius: 6px;">
                            <div id="razorpayStatusMsg" style="font-size: 13px; color: var(--text-muted);">
                                <i class="fa-solid fa-lock" style="color: var(--color-primary);"></i> You'll enter your card, UPI, or netbanking details in Razorpay's secure checkout window after you click "Place Secure Order".
                            </div>
                        </div>

                        <!-- Tax Invoice / Billing Address Structure -->
                        <div class="invoice-section-box" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; margin-bottom: 30px;">
                            <h3 style="font-family: var(--font-heading); font-size: 15px; font-weight: 400; text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-file-invoice" style="color: var(--color-primary);"></i> Tax Invoice &amp; Billing Details
                            </h3>
                            
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; cursor: pointer; margin-bottom: 10px;">
                                <input type="checkbox" name="billing_same" value="1" checked onchange="document.getElementById('billingCustomWrap').style.display = this.checked ? 'none' : 'block'">
                                Billing address same as shipping address
                            </label>

                            <div id="billingCustomWrap" style="display: none; margin-top: 12px;">
                                <input type="text" name="billing_address" placeholder="Company Name / Custom Billing Address" class="form-luxury-input" style="width: 100%; padding: 10px; font-size: 13px;">
                            </div>

                            <label style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--text-muted); margin-top: 10px; cursor: pointer;">
                                <input type="checkbox" name="send_pdf_invoice" value="1" checked>
                                Automatically email official PDF Tax Invoice upon order completion
                            </label>
                        </div>

                        <div class="btn-step-space-between" style="display: flex; justify-content: space-between;">
                            <button type="button" onclick="goToStep(2)" class="btn-luxury-outline" style="padding: 12px 24px; font-size: 12px;">Back</button>
                            
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
                <div class="checkout-summary-card" style="background: var(--bg-surface); border: 1px solid var(--border-strong); padding: 25px; position: sticky; top: 20px; box-shadow: var(--shadow-sm);">
                    <h2 class="summary-title" style="font-family: var(--font-heading); font-size: 20px; font-weight: 400; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 20px; border-bottom: 1px solid var(--border-light); padding-bottom: 12px;">
                        Order Summary
                    </h2>
                    
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
                                        <div style="font-size: 11px; color: var(--text-muted);">Qty: <?= $item['quantity'] ?> <?= !empty($item['color_name']) ? '• ' . htmlspecialchars($item['color_name']) : '' ?> <?= $item['variant_name'] ? '• ' . htmlspecialchars($item['variant_name']) : '' ?></div>
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
                            <span id="chkShippingText" style="font-weight: 600; color: var(--color-accent); font-size: 12px; text-transform: uppercase;">Complimentary</span>
                        </div>

                        <div class="summary-grand-total" style="display: flex; justify-content: space-between; margin-top: 15px; padding-top: 12px; border-top: 1.5px solid var(--border-strong); font-size: 18px; font-weight: 700; color: var(--color-primary);">
                            <span>Total Due</span>
                            <span id="chkGrandTotal"><?= formatPrice($grandTotal) ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</section>

<script>
let currentStep = 1;
let selectedShippingCost = 0.00;

function goToStep(step) {
    // Validate required fields on step 1 before proceeding
    if (step === 2 && currentStep === 1) {
        const name = document.getElementById('customer_name').value.trim();
        const email = document.getElementById('customer_email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const postcode = document.getElementById('delivery_postcode').value.trim();
        const address = document.getElementById('address').value.trim();
        
        if (!name || !email || !phone || !postcode || !address) {
            alert('Please fill in all required delivery details before proceeding.');
            return;
        }
    }

    document.getElementById('step1Content').style.display = step === 1 ? 'block' : 'none';
    document.getElementById('step2Content').style.display = step === 2 ? 'block' : 'none';
    document.getElementById('step3Content').style.display = step === 3 ? 'block' : 'none';

    document.getElementById('tabStep1').className = step === 1 ? 'step-nav-tab active-tab' : 'step-nav-tab';
    document.getElementById('tabStep2').className = step === 2 ? 'step-nav-tab active-tab' : 'step-nav-tab';
    document.getElementById('tabStep3').className = step === 3 ? 'step-nav-tab active-tab' : 'step-nav-tab';

    currentStep = step;
    window.scrollTo({ top: 200, behavior: 'smooth' });
}

function applySavedAddress(value) {
    if (!value) return;
    const parts = value.split('|');
    if (parts.length >= 3) {
        document.getElementById('address').value = parts[0];
        document.getElementById('delivery_postcode').value = parts[1];
        document.getElementById('phone').value = parts[2];
    }
}

function updateShippingCost(cost) {
    selectedShippingCost = parseFloat(cost);
    document.getElementById('deliveryChargeInput').value = selectedShippingCost.toFixed(2);
    document.getElementById('chkShippingText').textContent = selectedShippingCost === 0 ? 'Complimentary' : formatPriceJS(selectedShippingCost);
    
    // Recalculate grand total
    const cartTotal = <?= (float)$cartTotal ?>;
    const discount = <?= (float)$discountAmount ?>;
    const newTotal = Math.max(0, cartTotal - discount + selectedShippingCost);
    document.getElementById('chkGrandTotal').textContent = formatPriceJS(newTotal);
}

function selectPaymentMode(mode) {
    document.getElementById('pmOnlineLabel').className = mode === 'online' ? 'payment-mode-label active-pm' : 'payment-mode-label';
    document.getElementById('pmCodLabel').className = mode === 'cod' ? 'payment-mode-label active-pm' : 'payment-mode-label';
    document.getElementById('pmLaterLabel').className = mode === 'later' ? 'payment-mode-label active-pm' : 'payment-mode-label';

    document.getElementById('razorpayPanel').style.display = mode === 'online' ? 'block' : 'none';
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
    const cartTotalRaw = <?= (float)$cartTotal ?>;
    fetch(`promo_handler.php?action=validate&code=${encodeURIComponent(code)}&cart_total=${cartTotalRaw}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                msg.style.color = 'var(--color-success)';
                msg.innerHTML = `🎉 Coupon <strong>${data.code}</strong> applied! Saved ${formatPriceJS(data.discount_amount)}`;
                document.getElementById('chkDiscountRow').style.display = 'flex';
                document.getElementById('chkDiscountAmount').textContent = '-' + formatPriceJS(data.discount_amount);
                
                const newTotal = Math.max(0, cartTotalRaw - parseFloat(data.discount_amount) + selectedShippingCost);
                document.getElementById('chkGrandTotal').textContent = formatPriceJS(newTotal);
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

function handleCheckout() {
    const form = document.getElementById('checkoutForm');
    if (form.dataset.submitting === 'true') return;

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
require_once __DIR__ . '/../includes/footer.php';
?>
