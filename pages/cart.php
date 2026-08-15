<?php
$pageTitle = "Shopping Bag";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "Your Dievon shopping bag.";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';

// The same three Store Settings values shippingCostForZone() uses server-side.
// The cart previously stated "Complimentary" unconditionally — in the summary
// row, in the estimator, and in the estimator's reply whatever postcode was
// typed — while checkout charged ₹99 under the free-shipping threshold. The
// shopper first learned the real cost on the payment page.
// Per-country threshold, not the global India one — see freeShippingMinForCountry().
$cartFreeShipMin = freeShippingMinForCountry($pdo);
/* Through shippingCostForZone(), not the raw Store Settings rows.
   ────────────────────────────────────────────────────────────────────────────
   These two read `standard_shipping_fee` and `international_shipping_fee`
   directly, while the checkout and actions/checkout_action.php both price
   delivery through shippingCostForZone() — which reads the COUNTRIES screen
   first and only falls back to those settings. Three screens, three answers for
   one postcode: this estimator quoted ₹2,500 for a foreign PIN, the checkout
   page quoted ₹99 for the same one, and the server refused it outright.

   The two probe amounts (0, and a figure far above any threshold) ask the same
   helper for the fee with and without free delivery applied, exactly as
   pages/checkout.php does. */
$cartShipFee     = shippingCostForZone('domestic', 0.0, $pdo);
$cartIntlShipFee = shippingCostForZone('international', 0.0, $pdo);
/* Does the shop actually deliver abroad?
   isDeliverablePostcode() refuses every foreign postcode when it does not, so
   quoting a price for one promised a service checkout will not sell — the
   shopper typed SW1A 1AA, was told "International delivery: ₹2,500.00", and
   then met "Please enter a valid delivery postcode" on the payment page. */
$cartShipsIntl   = shipsInternationally($pdo);
?>

<!-- ══ Luxury Cart Hero ══════════════════════════════════ -->
<section class="luxury-hero section-mb-sm has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(2) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Atelier Wardrobe</span>
        <h1>Shopping Cart</h1>
        <p>Review and customize your selected pieces before secure checkout.</p>
    </div>
</section>

<!-- ══ Shopping Cart Main Section ══════════════════════════ -->
<section class="section-space">
    <div class="container reveal-on-scroll">

        <?php // Moved above the cart: shoppers reach for this before scrolling
              // past every line item, and on a phone it was below the fold. ?>
        <div class="cart-top-actions">
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury-outline cart-continue-link">
                <i class="fa-solid fa-arrow-left"></i> Continue Shopping
            </a>
        </div>

        <div id="cartPageLayout" class="cart-page-layout is-hidden">

            <!-- Left Side: Table of Items & Actions -->
            <div class="cart-left-panel">
                <div class="cart-panel-header">
                    <h2 class="cart-panel-title">
                        Shopping Cart <span id="cartCountBadgeText" class="cart-count-badge">(0 Items)</span>
                    </h2>
                    <button onclick="clearCart()" class="btn-text-danger cart-clear-btn">
                        <i class="fa-regular fa-trash-can"></i> Empty Cart
                    </button>
                </div>

                <!-- Items Container -->
                <div id="cartPageList">
                    <!-- Dynamically populated via JS -->
                </div>

                <!-- Accordion Box: Estimate Shipping & Gift Card Structure -->
                <div class="cart-extra-tools-grid">

                    <!-- Estimate Shipping Calculator -->
                    <div class="cart-tool-box">
                        <h3 class="cart-tool-title">
                            <i class="fa-solid fa-truck-fast"></i> Estimate Shipping
                        </h3>
                        <div class="cart-tool-fields">
                            <?php // Defaulted to India — this said "United Kingdom", a leftover
                                  // that pre-filled the wrong country for every shopper. ?>
                            <input type="text" id="shippingCountry" placeholder="Country (e.g. India)"
                                   value="India" class="form-luxury-input cart-tool-input">
                            <div class="cart-tool-row">
                                <input type="text" id="shippingPincode" placeholder="Postcode / PIN"
                                       class="form-luxury-input cart-tool-input cart-tool-input-grow">
                                <button onclick="estimateShippingCost()" class="btn-luxury-outline cart-tool-btn">Estimate</button>
                            </div>
                            <div id="shippingEstimateMsg" class="cart-tool-msg is-ok">Free delivery within India on orders over <?= formatPrice($cartFreeShipMin) ?>. Enter a postcode for an exact quote.</div>
                        </div>
                    </div>

                    <?php /* The Gift Card box was removed, not styled away, because it told
                             the customer a lie. applyGiftCard() checked only that the two
                             fields were non-empty and then printed "✓ Gift Card validated!
                             Balance applied at checkout step." — no server call, no lookup,
                             no balance, and there is no gift_cards table in the database at
                             all. Any sixteen digits with any PIN produced that message, and
                             then checkout charged the full amount.

                             A shopper who believed it had every reason to; that is a payment
                             dispute waiting to happen, which is why an empty box is safer
                             than a convincing one.

                             To bring it back it needs, at minimum: a gift_cards table
                             (code, PIN hash, balance, expiry, status), server-side redeem
                             and balance-check endpoints, the balance carried into
                             actions/checkout_action.php where the payable total is derived
                             server-side, and a partial-redemption rule for when the card is
                             worth less than the order. The promo-code flow in
                             actions/promo_action.php is the closest working model. */ ?>

                </div>
            </div>


            <!-- Right Side: Cart Summary & Coupon Section -->
            <div class="cart-right-panel">
                <div class="cart-summary-card">
                    <h2 class="cart-summary-title">Cart Summary</h2>

                    <div class="cart-summary-row">
                        <span class="cart-summary-label">Subtotal</span>
                        <span id="summarySubtotal" class="cart-summary-value"><?= formatPrice(0) ?></span>
                    </div>

                    <!-- Applied Promo Row -->
                    <div id="summaryPromoRow" class="cart-summary-row cart-summary-row-promo is-hidden">
                        <span id="summaryPromoLabel">Coupon Discount</span>
                        <span id="summaryPromoAmount" class="cart-summary-value">-<?= formatPrice(0) ?></span>
                    </div>

                    <!-- Estimated Shipping Row -->
                    <div class="cart-summary-row cart-summary-row-divider">
                        <span class="cart-summary-label">Estimated Shipping</span>
                        <span class="cart-summary-shipping">Calculated at checkout</span>
                    </div>

                    <div class="cart-summary-row cart-summary-total">
                        <span>Total</span>
                        <span id="summaryGrandTotal"><?= formatPrice(0) ?></span>
                    </div>

                    <!-- Coupon Code Input Box -->
                    <div class="cart-promo-box">
                        <label for="promoCodeInput" class="cart-promo-label">Apply Coupon Code</label>
                        <div class="cart-promo-row">
                            <input type="text" id="promoCodeInput" placeholder="ENTER COUPON" class="cart-promo-input">
                            <button onclick="applyPromoCode()" class="btn-luxury cart-promo-btn">Apply</button>
                        </div>
                        <div id="promoMessage" class="cart-promo-msg"></div>
                    </div>

                    <!-- Proceed to Checkout Button -->
                    <a href="<?= SITE_URL ?>/checkout" class="btn-luxury cart-checkout-btn">
                        Proceed to Checkout <i class="fa-solid fa-lock"></i>
                    </a>
                </div>
            </div>

        </div>

        <!-- Empty Cart State -->
        <div id="cartPageEmpty" class="cart-empty is-hidden">
            <div class="cart-empty-icon">👜</div>
            <h2 class="cart-empty-title">Your Shopping Cart is Empty</h2>
            <p class="cart-empty-text">
                Your shopping bag is currently waiting for exquisite couture pieces. Explore our latest arrivals to update your wardrobe.
            </p>
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury cart-empty-btn">Shop New Collections</a>
        </div>

    </div>
</section>

<script>
    // Callback invoked by footer.php when cart loads or changes
    function onCartUpdated(cart) {
        const layout = document.getElementById('cartPageLayout');
        const empty = document.getElementById('cartPageEmpty');
        const countBadge = document.getElementById('cartCountBadgeText');
        
        if (!cart.items || cart.items.length === 0) {
            // Clear everything BEFORE bailing out. This used to return straight
            // away, leaving the last-rendered item list, the line counter and the
            // money figures on screen — so removing the final item showed an empty
            // cart in one place and a product in another, and a price with no items.
            const listEl = document.getElementById('cartPageList');
            if (listEl) { listEl.innerHTML = ''; }
            if (countBadge) { countBadge.textContent = '(0 Items)'; }
            ['summarySubtotal', 'summaryPromoAmount', 'summaryGrandTotal'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.textContent = formatPriceJS(0); }
            });
            layout.classList.add('is-hidden');
            empty.classList.remove('is-hidden');
            return;
        }

        layout.classList.remove('is-hidden');
        empty.classList.add('is-hidden');

        if (countBadge) {
            // cart_count, not items.length: the header badge counts UNITS, so a
            // line with quantity 3 made the page say "1 Item" beside a badge of 3.
            const units = (cart.cart_count !== undefined) ? cart.cart_count : cart.items.length;
            countBadge.textContent = `(${units} ${units === 1 ? 'Item' : 'Items'})`;
        }

        // Render list of items
        const listEl = document.getElementById('cartPageList');
        let html = '';
        
        cart.items.forEach(item => {
            const itemSubtotalRaw = item.price * item.quantity;
            const cartKey = item.cart_key || item.product_id;
            const imgHtml = item.image
                ? `<img src="${window.SITE_URL}/uploads/products/${escHtml(item.image)}" alt="${escHtml(item.name)}" class="cart-item-img" onerror="this.outerHTML='<div class=&quot;cart-item-img cart-item-img-fallback&quot;>${escHtml(item.emoji || '🛍️')}</div>';">`
                : `<div class="cart-item-img cart-item-img-fallback">${escHtml(item.emoji || '🛍️')}</div>`;
            const colorLabel = item.color_name ? `<span class="cart-item-option">Colour: ${escHtml(item.color_name)}</span>` : '';
            // Strip the stored "Size: " prefix before adding one, or the line reads
            // "· Size: Size: M". See variantSizeLabel() in config/config.php.
            const vSize = String(item.variant_name || '').replace(/^\s*size\s*:\s*/i, '').trim();
            const variantLabel = vSize ? `<span class="cart-item-option">${item.color_name ? ' · ' : ''}Size: ${escHtml(vSize)}</span>` : '';

            /* What is actually left of this line, sent by cartSummary().
               null means the piece is not counted, so no ceiling applies. The
               shopper is told BEFORE the payment page that they hold the last
               ones — the cart used to say nothing at all, and the first news of
               a stock change arrived as a checkout error. */
            const limit = (item.stock_limit === null || item.stock_limit === undefined)
                ? null : parseInt(item.stock_limit, 10);
            const atLimit = (limit !== null && item.quantity >= limit);
            const stockNote = atLimit
                ? `<div class="cart-item-stock-note">${item.quantity >= limit && limit <= 3
                      ? `Only ${limit} left — you have ${limit === 1 ? 'the last one' : 'them all'} in your bag.`
                      : 'Your bag holds all we have left of this one.'}</div>`
                : '';

            html += `
            <div class="cart-item-row">
                ${imgHtml}
                <div class="cart-item-main">
                    <h3 class="cart-item-name">
                        <a href="<?= SITE_URL ?>/product.php?id=${item.product_id}">${escHtml(item.name)}</a>
                    </h3>
                    <div class="cart-item-options">${colorLabel}${variantLabel}</div>
                    <div class="cart-item-price">${formatPriceJS(item.price)}</div>
                    ${stockNote}

                    <!-- Save for Later & Remove Links -->
                    <div class="cart-item-links">
                        <button onclick="saveForLater('${item.product_id}', '${cartKey}')" class="cart-item-link">
                            <i class="fa-regular fa-heart"></i> Save for Later
                        </button>
                        <span class="cart-item-link-sep">|</span>
                        <button onclick="removeItem('${cartKey}')" class="cart-item-link cart-item-link-danger">
                            <i class="fa-solid fa-trash-can"></i> Remove
                        </button>
                    </div>
                </div>

                <!-- Update Quantity Stepper -->
                <div class="qty-controls cart-qty">
                    <button onclick="updateQty('${cartKey}', ${item.quantity - 1})" class="cart-qty-btn" aria-label="Decrease quantity">−</button>
                    <span class="cart-qty-value">${item.quantity}</span>
                    <button onclick="updateQty('${cartKey}', ${item.quantity + 1})" class="cart-qty-btn"
                            aria-label="Increase quantity"
                            ${atLimit ? 'disabled title="That is all we have left"' : ''}>+</button>
                </div>

                <div class="cart-item-subtotal">
                    ${formatPriceJS(itemSubtotalRaw)}
                </div>
            </div>
            `;
        });
        
        listEl.innerHTML = html;

        // Render summary figures.
        //
        // The Total is set here, synchronously, at the same moment as the subtotal.
        // It used to be written ONLY by checkPromoCodeSession()'s async response —
        // so until that returned (and it never returns a total when there is no
        // promo) the page showed a real product above a Total of ₹0.00. A money
        // figure must never be left to an asynchronous call to fill in.
        const subtotalRaw = cart.cart_total_raw || 0;
        document.getElementById('summarySubtotal').textContent = formatPriceJS(subtotalRaw);
        const grandEl = document.getElementById('summaryGrandTotal');
        if (grandEl) { grandEl.textContent = formatPriceJS(subtotalRaw); }

        // A promo, if one applies, then reduces it.
        checkPromoCodeSession(subtotalRaw);
    }

    function saveForLater(productId, cartKey) {
        // Add, never toggle.
        //
        // This called toggleWishlist(), which REMOVES an item that is already
        // saved. So a garment the shopper had already hearted was deleted from
        // the wishlist AND removed from the bag, while the message said "moved to
        // your Wishlist" — it ended up in neither. It only went wrong for items
        // already in the wishlist, which are the ones they like most.
        const id = String(productId);
        let saved = false;
        try {
            const list = (typeof getWishlist === 'function') ? getWishlist() : [];
            if (list.indexOf(id) === -1) {
                list.push(id);
                localStorage.setItem('dievon_wishlist', JSON.stringify(list));
                if (typeof updateWishlistBadge === 'function') { updateWishlistBadge(); }
            }
            saved = true;
        } catch (e) {
            // Private browsing can refuse localStorage. Better to leave the item
            // in the bag than to remove it with nowhere to put it.
        }

        if (!saved) {
            if (typeof showToast === 'function') {
                showToast('Could not save', 'Your browser is blocking saved items. The piece is still in your bag.');
            }
            return;
        }

        removeItem(cartKey);
        if (typeof showToast === 'function') {
            showToast('Saved for Later', 'Item moved to your Wishlist!');
        }
    }

    // Mirrors shippingCostForZone() in config/config.php exactly. Keep the two in
    // step: this is the figure the shopper is promised, and that one is the figure
    // they are charged. It used to reply "Complimentary" to every postcode.
    // The discount currently applied, so the shipping estimator can judge the
    // free-delivery threshold on the same post-coupon figure the server charges
    // on. Without it the estimator used the pre-discount subtotal and promised
    // "Complimentary" on baskets that were then charged the delivery fee.
    let cartAppliedDiscount = 0;

    const CART_SHIP = {
        freeOver: <?= json_encode($cartFreeShipMin) ?>,
        fee:      <?= json_encode($cartShipFee) ?>,
        intlFee:  <?= json_encode($cartIntlShipFee) ?>,
        shipsIntl: <?= $cartShipsIntl ? 'true' : 'false' ?>
    };

    // Same test as detectShippingZone(): an Indian PIN is six digits starting 1-9.
    function cartIsDomesticPin(pin) {
        return /^[1-9][0-9]{5}$/.test(pin.replace(/\s+/g, ''));
    }

    function cartShippingFor(pin, subtotal) {
        if (!cartIsDomesticPin(pin)) { return { cost: CART_SHIP.intlFee, zone: 'international' }; }
        const free = CART_SHIP.freeOver > 0 && subtotal >= CART_SHIP.freeOver;
        return { cost: free ? 0 : CART_SHIP.fee, zone: 'domestic' };
    }

    function estimateShippingCost() {
        const country = document.getElementById('shippingCountry').value.trim();
        const pin = document.getElementById('shippingPincode').value.trim();
        const msg = document.getElementById('shippingEstimateMsg');
        if (!pin) {
            msg.style.color = 'var(--color-danger)';
            msg.textContent = 'Please enter a postcode or zip code.';
            return;
        }

        // cartState is the live cart object maintained in includes/footer.php.
        // cartTotalRaw is only a parameter name elsewhere in this file, not a
        // global — referencing it here would throw and break the button.
        const grossSubtotal = (typeof cartState !== 'undefined' && cartState)
            ? (parseFloat(cartState.cart_total_raw) || 0)
            : 0;
        const subtotal = Math.max(0, grossSubtotal - cartAppliedDiscount);
        const q = cartShippingFor(pin, subtotal);

        // Nowhere to send it — say so rather than quoting a price for a delivery
        // the checkout will refuse. Same rule isDeliverablePostcode() applies.
        if (q.zone === 'international' && !CART_SHIP.shipsIntl) {
            msg.style.color = 'var(--color-danger)';
            msg.textContent = `We do not deliver outside India yet, so we cannot quote for ${pin}. Please check the postcode if you meant an Indian PIN.`;
            const rowIntl = document.querySelector('.cart-summary-shipping');
            if (rowIntl) { rowIntl.textContent = 'Calculated at checkout'; }
            return;
        }

        msg.style.color = 'var(--color-success)';
        if (q.cost === 0) {
            msg.textContent = `✓ Shipping to ${country} (${pin}): Complimentary`;
        } else if (q.zone === 'domestic') {
            const short = CART_SHIP.freeOver - subtotal;
            msg.textContent = `✓ Shipping to ${country} (${pin}): ${formatPriceJS(q.cost)}`
                + (short > 0 ? ` — spend ${formatPriceJS(short)} more for free delivery` : '');
        } else {
            msg.textContent = `✓ International delivery to ${country} (${pin}): ${formatPriceJS(q.cost)}`;
        }

        // Keep the summary row honest too, not just this one line.
        const row = document.querySelector('.cart-summary-shipping');
        if (row) { row.textContent = q.cost === 0 ? 'Complimentary' : formatPriceJS(q.cost); }
    }

    // applyGiftCard() was deleted along with the box it served — see the note
    // beside the cart tools above. It only ever printed a success message; it
    // never validated anything. Leaving the function behind would invite the
    // markup back without the backend that has to come first.

    function checkPromoCodeSession(cartTotalRaw) {
        fetch(`<?= SITE_URL ?>/promo_handler.php?action=validate&cart_total=${cartTotalRaw}`)
            .then(res => res.json())
            .then(data => {
                const promoRow = document.getElementById('summaryPromoRow');
                const grandTotalEl = document.getElementById('summaryGrandTotal');
                
                if (data.success) {
                    cartAppliedDiscount = parseFloat(data.discount_amount) || 0;
                    promoRow.classList.remove('is-hidden');
                    document.getElementById('summaryPromoLabel').textContent = `Discount (${data.code})`;
                    document.getElementById('summaryPromoAmount').textContent = '-' + formatPriceJS(data.discount_amount);
                    grandTotalEl.textContent = formatPriceJS(data.new_total);
                    
                    const msg = document.getElementById('promoMessage');
                    msg.style.color = 'var(--color-success)';
                    msg.innerHTML = `🎉 Coupon <strong>${data.code}</strong> Applied! &bull; <a href="#" onclick="removePromoCode(event)" class="cart-promo-remove">Remove</a>`;
                } else {
                    cartAppliedDiscount = 0;
                    promoRow.classList.add('is-hidden');
                    grandTotalEl.textContent = formatPriceJS(cartTotalRaw);
                }
            })
            .catch(() => {
                document.getElementById('summaryGrandTotal').textContent = formatPriceJS(cartTotalRaw);
            });
    }

    function applyPromoCode() {
        const code = document.getElementById('promoCodeInput').value.trim();
        const msg = document.getElementById('promoMessage');
        
        if (!code) {
            msg.style.color = 'var(--color-danger)';
            msg.textContent = 'Please enter a coupon code.';
            return;
        }

        const subtotalRaw = cartState.cart_total_raw || 0;

        fetch(`<?= SITE_URL ?>/promo_handler.php?action=validate&code=${encodeURIComponent(code)}&cart_total=${subtotalRaw}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    msg.style.color = 'var(--color-success)';
                    msg.textContent = data.message;
                    onCartUpdated(cartState);
                    if (typeof showToast === 'function') {
                        showToast('Coupon Applied!', 'Discount applied to your order!');
                    }
                } else {
                    msg.style.color = 'var(--color-danger)';
                    msg.textContent = data.message;
                }
            })
            .catch(() => {
                msg.style.color = 'var(--color-danger)';
                msg.textContent = 'Connection error, please try again.';
            });
    }

    function removePromoCode(e) {
        e.preventDefault();
        fetch('<?= SITE_URL ?>/promo_handler.php?action=remove')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('promoCodeInput').value = '';
                    document.getElementById('promoMessage').textContent = '';
                    onCartUpdated(cartState);
                    if (typeof showToast === 'function') {
                        showToast('Coupon Removed', 'Coupon code removed');
                    }
                }
            });
    }
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
