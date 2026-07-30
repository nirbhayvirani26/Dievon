<?php
$pageTitle = "Shopping Bag";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Luxury Cart Hero ══════════════════════════════════ -->
<section class="luxury-hero section-mb-sm">
    <div class="container">
        <span class="luxury-hero-eyebrow">Atelier Wardrobe</span>
        <h1>Shopping Cart</h1>
        <p>Review and customize your selected pieces before secure checkout.</p>
    </div>
</section>

<!-- ══ Shopping Cart Main Section ══════════════════════════ -->
<section class="section-space">
    <div class="container reveal-on-scroll" style="max-width: 1240px; margin: 0 auto; padding: 0 20px;">
        
        <div id="cartPageLayout" class="cart-page-layout" style="display: none;">
            
            <!-- Left Side: Table of Items & Actions -->
            <div class="cart-left-panel">
                <div class="cart-panel-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-strong); padding-bottom: 15px; margin-bottom: 25px;">
                    <h2 style="font-family: var(--font-heading); font-size: 24px; font-weight: 300; text-transform: uppercase; letter-spacing: 0.05em; margin: 0;">
                        Shopping Cart <span id="cartCountBadgeText" style="font-size: 14px; color: var(--text-muted); font-family: var(--font-body);">(0 Items)</span>
                    </h2>
                    <button onclick="clearCart()" class="btn-text-danger" style="background: none; border: none; color: var(--color-danger); font-size: 12px; font-weight: 600; cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em;">
                        <i class="fa-regular fa-trash-can"></i> Empty Cart
                    </button>
                </div>
                
                <!-- Items Container -->
                <div id="cartPageList">
                    <!-- Dynamically populated via JS -->
                </div>
                
                <!-- Bottom Cart Action Bar -->
                <div class="cart-bottom-actions" style="margin-top: 35px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; border-top: 1px solid var(--border-light); padding-top: 25px;">
                    <a href="shop" class="btn-luxury-outline" style="font-size: 12px; padding: 12px 24px;">
                        <i class="fa-solid fa-arrow-left"></i> Continue Shopping
                    </a>
                </div>

                <!-- Accordion Box: Estimate Shipping & Gift Card Structure -->
                <div class="cart-extra-tools-grid">
                    
                    <!-- Estimate Shipping Calculator -->
                    <div class="cart-tool-box" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 24px;">
                        <h4 style="font-family: var(--font-heading); font-size: 16px; font-weight: 400; text-transform: uppercase; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-truck-fast" style="color: var(--color-primary);"></i> Estimate Shipping
                        </h4>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <input type="text" id="shippingCountry" placeholder="Country (e.g. United Kingdom)" value="United Kingdom" class="form-luxury-input" style="padding: 10px; font-size: 13px;">
                            <div style="display: flex; gap: 10px;">
                                <input type="text" id="shippingPincode" placeholder="Postcode / Zip" class="form-luxury-input" style="flex: 1; padding: 10px; font-size: 13px;">
                                <button onclick="estimateShippingCost()" class="btn-luxury-outline" style="padding: 10px 16px; font-size: 11px;">Estimate</button>
                            </div>
                            <div id="shippingEstimateMsg" style="font-size: 12px; color: var(--color-success); font-weight: 600;">Standard Atelier Shipping: Complimentary</div>
                        </div>
                    </div>

                    <!-- Gift Card / Voucher Structure -->
                    <div class="cart-tool-box" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 24px;">
                        <h4 style="font-family: var(--font-heading); font-size: 16px; font-weight: 400; text-transform: uppercase; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-gift" style="color: var(--color-primary);"></i> Gift Card Structure
                        </h4>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <input type="text" id="giftCardNumber" placeholder="Enter 16-Digit Gift Card Number" class="form-luxury-input" style="padding: 10px; font-size: 13px;">
                            <div style="display: flex; gap: 10px;">
                                <input type="password" id="giftCardPin" placeholder="PIN" class="form-luxury-input" style="width: 90px; padding: 10px; font-size: 13px;">
                                <button onclick="applyGiftCard()" class="btn-luxury-outline" style="flex: 1; padding: 10px 16px; font-size: 11px;">Redeem Card</button>
                            </div>
                            <div id="giftCardMsg" style="font-size: 12px; font-weight: 500;"></div>
                        </div>
                    </div>

                </div>
            </div>

            
            <!-- Right Side: Cart Summary & Coupon Section -->
            <div class="cart-right-panel">
                <div class="cart-summary-card" style="background: var(--bg-surface); border: 1px solid var(--border-strong); padding: 30px; position: sticky; top: 20px; box-shadow: var(--shadow-sm);">
                    <h2 style="font-family: var(--font-heading); font-size: 22px; font-weight: 300; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 25px; border-bottom: 1px solid var(--border-light); padding-bottom: 15px;">
                        Cart Summary
                    </h2>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 14px;">
                        <span style="color: var(--text-secondary);">Subtotal</span>
                        <span id="summarySubtotal" style="font-weight: 600; color: var(--text-primary);">£0.00</span>
                    </div>
                    
                    <!-- Applied Promo Row -->
                    <div id="summaryPromoRow" style="display: none; justify-content: space-between; margin-bottom: 15px; font-size: 14px; color: var(--color-success);">
                        <span id="summaryPromoLabel">Coupon Discount</span>
                        <span id="summaryPromoAmount" style="font-weight: 600;">-£0.00</span>
                    </div>
                    
                    <!-- Estimated Shipping Row -->
                    <div style="display: flex; justify-content: space-between; margin-bottom: 25px; font-size: 14px; border-bottom: 1px solid var(--border-light); padding-bottom: 15px;">
                        <span style="color: var(--text-secondary);">Estimated Shipping</span>
                        <span style="font-weight: 600; text-transform: uppercase; color: var(--color-accent); font-size: 12px;">Complimentary</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 30px; font-size: 18px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">
                        <span>Total</span>
                        <span id="summaryGrandTotal" style="color: var(--color-primary);">£0.00</span>
                    </div>

                    <!-- Coupon Code Input Box -->
                    <div style="margin-bottom: 30px; border-top: 1px solid var(--border-light); padding-top: 25px;">
                        <label for="promoCodeInput" style="display: block; font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.08em; color: var(--text-primary); margin-bottom: 10px;">
                            Apply Coupon Code
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="promoCodeInput" placeholder="ENTER COUPON" style="flex: 1; padding: 12px; border: 1px solid var(--border-strong); background: var(--bg-surface-soft); text-transform: uppercase; font-size: 13px; outline: none;">
                            <button onclick="applyPromoCode()" class="btn-luxury" style="padding: 12px 20px; font-size: 11px; text-transform: uppercase;">Apply</button>
                        </div>
                        <div id="promoMessage" style="font-size: 12px; margin-top: 8px; font-weight: 500;"></div>
                    </div>

                    <!-- Proceed to Checkout Button -->
                    <a href="checkout" class="btn-luxury" style="display: flex; justify-content: center; width: 100%; text-align: center; height: 52px; align-items: center; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; gap: 10px;">
                        Proceed to Checkout <i class="fa-solid fa-lock"></i>
                    </a>
                </div>
            </div>

        </div>

        <!-- Empty Cart State -->
        <div id="cartPageEmpty" style="text-align: center; padding: 100px 0; display: none;">
            <div style="font-size: 60px; margin-bottom: 20px; color: var(--color-primary);">👜</div>
            <h2 style="font-family: var(--font-heading); font-size: 32px; font-weight: 300; text-transform: uppercase; margin-bottom: 15px;">Your Shopping Cart is Empty</h2>
            <p style="color: var(--text-secondary); font-size: 14px; max-width: 440px; margin: 0 auto 30px; line-height: 1.7;">
                Your shopping bag is currently waiting for exquisite couture pieces. Explore our latest arrivals to update your wardrobe.
            </p>
            <a href="shop" class="btn-luxury" style="padding: 16px 36px; font-size: 13px;">Shop New Collections</a>
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
            layout.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        layout.style.display = 'grid';
        empty.style.display = 'none';

        if (countBadge) {
            countBadge.textContent = `(${cart.items.length} ${cart.items.length === 1 ? 'Item' : 'Items'})`;
        }

        // Render list of items
        const listEl = document.getElementById('cartPageList');
        let html = '';
        
        cart.items.forEach(item => {
            const itemSubtotalRaw = item.price * item.quantity;
            const cartKey = item.cart_key || item.product_id;
            const imgHtml = item.image
                ? `<img src="uploads/products/${escHtml(item.image)}" alt="${escHtml(item.name)}" style="width: 90px; height: 110px; object-fit: cover; border: 1px solid var(--border-light);" onerror="this.outerHTML='<div style=&quot;width:90px;height:110px;background:var(--bg-surface-soft);display:flex;align-items:center;justify-content:center;font-size:30px;&quot;>${escHtml(item.emoji || '🛍️')}</div>';">`
                : `<div style="width: 90px; height: 110px; background: var(--bg-surface-soft); display: flex; align-items: center; justify-content: center; font-size: 30px;">${escHtml(item.emoji || '🛍️')}</div>`;
            const colorLabel = item.color_name ? `<span style="font-size:12px;color:var(--text-muted);font-weight:500;">Colour: ${escHtml(item.color_name)}</span>` : '';
            const variantLabel = item.variant_name ? `<span style="font-size:12px;color:var(--text-muted);font-weight:500;">${item.color_name ? ' · Size: ' : 'Option: '}${escHtml(item.variant_name)}</span>` : '';

            html += `
            <div style="display: flex; gap: 20px; align-items: center; padding: 25px 0; border-bottom: 1px solid var(--border-light); position: relative; flex-wrap: wrap;">
                ${imgHtml}
                <div style="flex: 1; min-width: 200px;">
                    <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 400; margin-bottom: 6px;">
                        <a href="product.php?id=${item.product_id}" style="color: var(--text-primary); text-decoration: none;">${escHtml(item.name)}</a>
                    </h3>
                    <div style="margin-bottom: 8px;">${colorLabel}${variantLabel}</div>
                    <div style="font-size: 14px; color: var(--color-primary); font-weight: 600;">${formatPriceJS(item.price)}</div>
                    
                    <!-- Save for Later & Remove Links -->
                    <div style="display: flex; gap: 15px; margin-top: 12px; font-size: 12px;">
                        <button onclick="saveForLater('${item.product_id}', '${cartKey}')" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; gap: 5px; padding: 0; font-weight: 500;">
                            <i class="fa-regular fa-heart"></i> Save for Later
                        </button>
                        <span style="color: var(--border-strong);">|</span>
                        <button onclick="removeItem('${cartKey}')" style="background: none; border: none; color: var(--color-danger); cursor: pointer; display: flex; align-items: center; gap: 5px; padding: 0; font-weight: 500;">
                            <i class="fa-solid fa-trash-can"></i> Remove
                        </button>
                    </div>
                </div>

                <!-- Update Quantity Stepper -->
                <div class="qty-controls" style="display: flex; align-items: center; border: 1px solid var(--border-strong); background: var(--bg-surface-soft); border-radius: 4px; padding: 2px 6px;">
                    <button onclick="updateQty('${cartKey}', ${item.quantity - 1})" style="background: none; border: none; cursor: pointer; font-size: 16px; width: 30px; height: 32px; display: flex; align-items: center; justify-content: center; color: var(--text-primary);">−</button>
                    <span style="font-size: 14px; font-weight: 700; width: 30px; text-align: center; color: var(--text-primary);">${item.quantity}</span>
                    <button onclick="updateQty('${cartKey}', ${item.quantity + 1})" style="background: none; border: none; cursor: pointer; font-size: 16px; width: 30px; height: 32px; display: flex; align-items: center; justify-content: center; color: var(--text-primary);">+</button>
                </div>

                <div style="font-size: 16px; font-weight: 700; color: var(--text-primary); min-width: 80px; text-align: right;">
                    ${formatPriceJS(itemSubtotalRaw)}
                </div>
            </div>
            `;
        });
        
        listEl.innerHTML = html;

        // Render summary figures
        document.getElementById('summarySubtotal').textContent = formatPriceJS(cart.cart_total_raw || 0);
        
        // Validate promo session calculations
        checkPromoCodeSession(cart.cart_total_raw || 0);
    }

    function saveForLater(productId, cartKey) {
        if (typeof toggleWishlist === 'function') {
            toggleWishlist(productId);
        }
        removeItem(cartKey);
        if (typeof showToast === 'function') {
            showToast('Saved for Later', 'Item moved to your Wishlist!');
        }
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
        msg.style.color = 'var(--color-success)';
        msg.textContent = `✓ Shipping to ${country} (${pin}): Express Delivery Available (Complimentary / ${formatPriceJS(0)})`;
    }

    function applyGiftCard() {
        const cardNum = document.getElementById('giftCardNumber').value.trim();
        const pin = document.getElementById('giftCardPin').value.trim();
        const msg = document.getElementById('giftCardMsg');
        if (!cardNum || !pin) {
            msg.style.color = 'var(--color-danger)';
            msg.textContent = 'Please enter Gift Card Number and PIN.';
            return;
        }
        msg.style.color = 'var(--color-success)';
        msg.textContent = '✓ Gift Card validated! Balance applied at checkout step.';
    }

    function checkPromoCodeSession(cartTotalRaw) {
        fetch('promo_handler.php?action=validate&cart_total=' + cartTotalRaw)
            .then(res => res.json())
            .then(data => {
                const promoRow = document.getElementById('summaryPromoRow');
                const grandTotalEl = document.getElementById('summaryGrandTotal');
                
                if (data.success) {
                    promoRow.style.display = 'flex';
                    document.getElementById('summaryPromoLabel').textContent = `Discount (${data.code})`;
                    document.getElementById('summaryPromoAmount').textContent = '-' + formatPriceJS(data.discount_amount);
                    grandTotalEl.textContent = formatPriceJS(data.new_total);
                    
                    const msg = document.getElementById('promoMessage');
                    msg.style.color = 'var(--color-success)';
                    msg.innerHTML = `🎉 Coupon <strong>${data.code}</strong> Applied! &bull; <a href="#" onclick="removePromoCode(event)" style="color: var(--text-muted); text-decoration: underline;">Remove</a>`;
                } else {
                    promoRow.style.display = 'none';
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

        fetch(`promo_handler.php?action=validate&code=${encodeURIComponent(code)}&cart_total=${subtotalRaw}`)
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
        fetch('promo_handler.php?action=remove')
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
