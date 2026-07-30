<!-- ══ Cart Sidebar ════════════════════════════════════════ -->
<div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
<aside class="cart-sidebar" id="cartSidebar">
    <div class="cart-header">
        <h2><i class="fa-solid fa-bag-shopping"></i> Shopping Bag</h2>
        <button class="btn-close-cart" onclick="closeCart()" aria-label="Close cart">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="cart-items" id="cartItems">
        <div class="cart-empty">
            <div class="cart-empty-icon">👜</div>
            <p>Your shopping bag is empty.</p>
        </div>
    </div>

    <div class="cart-footer" id="cartFooter" style="display:none;">
        <div class="cart-total-row">
            <span class="cart-total-label">Subtotal</span>
            <span class="cart-total-amount" id="cartTotal">₹0.00</span>
        </div>
        <a href="<?= SITE_URL ?>/cart" class="btn-luxury-outline" style="display: flex; justify-content: center; width: 100%; text-align: center; padding: 12px; margin-bottom: 10px; font-weight: 600; font-size: 12px; border-color: var(--color-primary); color: var(--color-primary);">
            View Shopping Cart Page
        </a>
        <a href="<?= SITE_URL ?>/checkout" class="btn-luxury">
            Proceed to Checkout
        </a>
        <button class="btn-clear-cart" onclick="clearCart()">
            <i class="fa-solid fa-trash"></i> Clear Bag
        </button>
    </div>

</aside>

<!-- ══ Toast ════════════════════════════════════════════════ -->
<div class="toast" id="toast">
    <span class="toast-icon">✨</span>
    <div>
        <div class="toast-text" id="toastText">Added to Bag!</div>
        <div class="toast-sub" id="toastSub"></div>
    </div>
</div>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<footer class="footer-enhanced">
    <div class="container">

        <div class="footer-top">
            <div class="footer-brand">
                <a href="<?= SITE_URL ?>/home">
                    <img src="<?= SITE_URL ?>/assets/images/logo/logo.PNG" alt="Dievon">
                </a>
                <p>
                    Timeless luxury women's fashion designed for the modern connoisseur. Handcrafted with the finest fabrics and tailored for elegance.
                </p>
                <div class="footer-social">
                    <!-- TODO: replace with Dievon's real social profile URLs when available -->
                    <a href="contact" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <a href="contact" aria-label="Facebook"><i class="fa-brands fa-facebook"></i></a>
                    <a href="https://wa.me/<?= defined('SHOP_WHATSAPP') ? SHOP_WHATSAPP : '919876543210' ?>?text=Hello%20Dievon%20Concierge" target="_blank" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                    <a href="contact" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
                    <a href="contact" aria-label="Tumblr"><i class="fa-brands fa-tumblr"></i></a>
                    <a href="contact" aria-label="Pinterest"><i class="fa-brands fa-pinterest"></i></a>
                </div>
            </div>
            <div class="footer-col">
                <h4>Collections</h4>
                <ul>
                    <li><a href="shop?category=Dresses">Dresses</a></li>
                    <li><a href="shop?category=Handbags">Handbags</a></li>
                    <li><a href="shop?category=Shoes">Shoes</a></li>
                    <li><a href="shop?category=Outerwear">Outerwear</a></li>
                    <li><a href="shop?category=Jewelry">Accessories</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Customer Service</h4>
                <ul>
                    <li><a href="about">Our Story</a></li>
                    <li><a href="blog">Journal</a></li>
                    <li><a href="contact">Contact Us</a></li>
                    <li><a href="shipping">Shipping &amp; Delivery</a></li>
                    <li><a href="returns">Returns &amp; Refunds</a></li>
                    <li><a href="privacy">Privacy Policy</a></li>
                    <li><a href="terms">Terms &amp; Conditions</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Newsletter</h4>
                <p>
                    Subscribe to receive private invitations, lookbook launches, and boutique updates.
                </p>
                <form class="footer-newsletter-form" onsubmit="return submitNewsletterSignup(event, this);">
                    <input type="email" name="email" placeholder="YOUR EMAIL ADDRESS" required>
                    <button type="submit">Join</button>
                </form>
                <div class="footer-newsletter-msg" style="display:none; font-size:12px; margin-top:8px;"></div>
            </div>
        </div>
        <div class="footer-bottom-bar">
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> DIEVON ATELIER. ALL RIGHTS RESERVED.</p>
            </div>
            <!-- Secure Payments -->
            <div class="footer-secure-payments">
                <div class="secure-payments-label"><i class="fa-solid fa-lock"></i> 100% Secure Payments</div>
                <div class="secure-payments-logos">
                    <i class="fa-brands fa-cc-visa" title="Visa"></i>
                    <i class="fa-brands fa-cc-mastercard" title="Mastercard"></i>
                    <img src="<?= SITE_URL ?>/assets/images/payment/rupay.svg" alt="RuPay" title="RuPay">
                    <img src="<?= SITE_URL ?>/assets/images/payment/upi.svg" alt="UPI" title="UPI">
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- ══ Mobile Bottom Navigation Dock ═════════════════════════ -->
<nav class="mobile-bottom-dock">
    <a href="home" class="mobile-dock-item <?php echo (!isset($path) || $path == 'home') ? 'active' : ''; ?>">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <button type="button" class="mobile-dock-item" onclick="openSearchOverlay()">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span>Search</span>
    </button>
    <?php if (isset($_SESSION['customer_id'])): ?>
        <a href="account" class="mobile-dock-item <?php echo (isset($path) && $path == 'account') ? 'active' : ''; ?>">
            <i class="fa-solid fa-user"></i>
            <span>Account</span>
        </a>
    <?php else: ?>
        <a href="login" class="mobile-dock-item <?php echo (isset($path) && $path == 'login') ? 'active' : ''; ?>">
            <i class="fa-regular fa-user"></i>
            <span>Login</span>
        </a>
    <?php endif; ?>
    <a href="https://wa.me/<?= defined('SHOP_WHATSAPP') ? SHOP_WHATSAPP : '919876543210' ?>?text=Hello%20Dievon%20Concierge" target="_blank" class="mobile-dock-item mobile-dock-whatsapp">
        <i class="fa-brands fa-whatsapp"></i>
        <span>Contact Us</span>
    </a>
</nav>

<!-- Floating WhatsApp Concierge & Back To Top Widgets -->
<a href="https://wa.me/<?= defined('SHOP_WHATSAPP') ? SHOP_WHATSAPP : '919876543210' ?>?text=Hello%20Dievon%20Concierge" target="_blank" class="whatsapp-float-btn" title="Chat on WhatsApp" style="position:fixed; bottom:25px; right:25px; width:50px; height:50px; background:#25d366; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:26px; box-shadow:0 4px 12px rgba(0,0,0,0.25); z-index:998; text-decoration:none;">
    <i class="fa-brands fa-whatsapp"></i>
</a>
<!-- Back To Top Widget -->
<button onclick="window.scrollTo({top:0, behavior:'smooth'})" id="backToTopBtn" style="position:fixed; bottom:85px; right:25px; width:40px; height:40px; background:var(--color-primary); color:#fff; border:none; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; box-shadow:0 4px 12px rgba(0,0,0,0.2); z-index:998; cursor:pointer; opacity:0.8;">
    <i class="fa-solid fa-chevron-up"></i>
</button>

<!-- Luxury Glassmorphic Ultra-Slim Header Effect on Scroll -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    let isCompact = false;
    window.addEventListener('scroll', () => {
        const nav = document.querySelector('.navbar-luxury');
        if (!nav) return;
        const scrollPos = window.scrollY || window.pageYOffset;
        if (!isCompact && scrollPos > 80) {
            nav.classList.add('header-compact');
            isCompact = true;
        } else if (isCompact && scrollPos < 30) {
            nav.classList.remove('header-compact');
            isCompact = false;
        }
    }, { passive: true });
});
</script>


<!-- ══ Scripts ══════════════════════════════════════════════ -->
<script src="assets/js/search.js" defer></script>
<script>
function submitNewsletterSignup(e, form) {
    e.preventDefault();
    const msg = form.parentElement.querySelector('.footer-newsletter-msg');
    const btn = form.querySelector('button[type="submit"]');
    const originalLabel = btn.textContent;
    btn.disabled = true;

    const formData = new FormData(form);
    fetch('actions/newsletter_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            msg.style.display = 'block';
            msg.style.color = data.success ? '#10b981' : '#ef4444';
            msg.textContent = data.message;
            if (data.success) form.reset();
            btn.disabled = false;
            btn.textContent = originalLabel;
        })
        .catch(() => {
            msg.style.display = 'block';
            msg.style.color = '#ef4444';
            msg.textContent = 'Something went wrong. Please try again.';
            btn.disabled = false;
            btn.textContent = originalLabel;
        });
    return false;
}

// ── Cart state ──────────────────────────────────────────────
let cartState = { items: [], cart_count: 0, cart_total: '0.00' };

// ── Open / Close cart ────────────────────────────────────────
function openCart() { 
    document.getElementById('cartSidebar').classList.add('open'); 
    document.getElementById('cartOverlay').classList.add('open'); 
    document.body.style.overflow = 'hidden'; 
}
function closeCart() { 
    document.getElementById('cartSidebar').classList.remove('open'); 
    document.getElementById('cartOverlay').classList.remove('open'); 
    document.body.style.overflow = ''; 
}

// ── Add to Cart AJAX helper ──────────────────────────────────
function getCartApiUrl(param) {
    const base = (typeof window.SITE_URL !== 'undefined' && window.SITE_URL) ? window.SITE_URL + '/actions/cart_action.php' : 'actions/cart_action.php';
    return param ? base + param : base;
}

function addToCart(productId, variantId, name, emoji, variantName, variantPrice, size, quantity) {
    variantId = variantId || 0;
    size = size || (typeof variantName === 'string' ? variantName : '');
    let body = 'action=add&product_id=' + productId;
    if (variantId > 0) body += '&variant_id=' + variantId;
    if (size) body += '&size=' + encodeURIComponent(size);
    if (quantity && quantity > 1) body += '&quantity=' + quantity;

    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            cartState = data;
            renderCart();
            updateBadge();
            bumpBadge();
            const label = size ? name + ' (Size: ' + size.toUpperCase() + ')' : (variantName ? name + ' (' + variantName + ')' : name);
            showToast('Added to Bag!', label + ' is now in your shopping bag.');

            if (typeof onCartUpdated === 'function') {
                onCartUpdated(cartState);
            }
        } else if (data.requires_size) {
            window.location.href = `product.php?id=${productId}&select_size=1`;
        } else {
            showToast('⚠️ Notice', data.message || 'Could not add item.');
        }
    })
    .catch(() => showToast('⚠️ Error', 'Could not add item, please try again.'));
}

// ── Remove item ──────────────────────────────────────────────
function removeItem(cartKey) {
    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove&cart_key=' + encodeURIComponent(cartKey),
    })
    .then(r => r.json()).then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
}

// ── Update quantity ───────────────────────────────────────────
function updateQty(cartKey, qty) {
    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update&cart_key=' + encodeURIComponent(cartKey) + '&quantity=' + qty,
    })
    .then(r => r.json()).then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
}

// ── Clear cart ───────────────────────────────────────────────
function clearCart() {
    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear',
    })
    .then(r => r.json()).then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
}

// ── Render cart sidebar ──────────────────────────────────────
function renderCart() {
    const itemsEl  = document.getElementById('cartItems');
    const footerEl = document.getElementById('cartFooter');
    const totalEl  = document.getElementById('cartTotal');

    if (!cartState.items || cartState.items.length === 0) {
        itemsEl.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon">👜</div><p class="cart-empty-msg">Your shopping bag is empty.</p></div>`;
        footerEl.style.display = 'none';
        return;
    }

    let html = '';
    cartState.items.forEach(item => {
        const subtotal  = (item.price * item.quantity).toFixed(2);
        const cartKey   = item.cart_key || item.product_id;
        const imgHtml   = item.image
            ? `<img class="cart-item-img" src="uploads/products/${escHtml(item.image)}" alt="${escHtml(item.name)}">`
            : `<div class="cart-item-img-placeholder">${escHtml(item.emoji)}</div>`;
        const colorLabel = item.color_name ? escHtml(item.color_name) + (item.variant_name ? ' · ' : '') : '';
        const variantLabel = item.variant_name ? `<span class="cart-variant-label">${colorLabel}${escHtml(item.variant_name)}</span>` : (colorLabel ? `<span class="cart-variant-label">${colorLabel}</span>` : '');
        html += `
        <div class="cart-item">
            ${imgHtml}
            <div class="cart-item-info">
                <div class="cart-item-name">${escHtml(item.name)}${variantLabel ? '<br>' + variantLabel : ''}</div>
                <div class="cart-item-price">${formatPriceJS(item.subtotal || (item.price * item.quantity))}</div>
            </div>
            <div class="qty-controls">
                <button class="qty-btn" onclick="updateQty('${cartKey}', ${item.quantity - 1})">−</button>
                <span class="qty-value">${item.quantity}</span>
                <button class="qty-btn" onclick="updateQty('${cartKey}', ${item.quantity + 1})">+</button>
            </div>
            <button class="btn-remove-item" onclick="removeItem('${cartKey}')" title="Remove">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>`;
    });

    itemsEl.innerHTML = html;
    totalEl.textContent = formatPriceJS(cartState.cart_total_raw !== undefined ? cartState.cart_total_raw : cartState.cart_total);
    footerEl.style.display = 'block';
}

// ── Badge helpers ────────────────────────────────────────────
function updateBadge() {
    const count = (cartState && typeof cartState.cart_count !== 'undefined') ? parseInt(cartState.cart_count, 10) : 0;
    const badges = document.querySelectorAll('#cartBadge, #mobileCartCount');
    badges.forEach(b => { 
        if (b) {
            b.textContent = count;
            b.style.display = 'flex';
        }
    });

    // Update sticky floating view cart bar
    const stickyBar = document.getElementById('stickyViewCartBar');
    const stickyCount = document.getElementById('stickyCartCount');
    const stickyTotal = document.getElementById('stickyCartTotal');
    const stickyItemText = document.getElementById('stickyCartItemText');

    if (stickyBar) {
        if (count > 0) {
            stickyBar.classList.add('show');
            if (stickyCount) stickyCount.textContent = count;
            if (stickyItemText) stickyItemText.textContent = count === 1 ? 'item' : 'items';
            if (stickyTotal) stickyTotal.textContent = formatPriceJS(cartState.cart_total_raw !== undefined ? cartState.cart_total_raw : cartState.cart_total);
        } else {
            stickyBar.classList.remove('show');
        }
    }

    if (typeof updatePageCartIndicators === 'function') {
        updatePageCartIndicators(cartState);
    }
}

function bumpBadge() {
    const count = (cartState && typeof cartState.cart_count !== 'undefined') ? parseInt(cartState.cart_count, 10) : 0;
    const badge = document.getElementById('cartBadge');
    if (badge) {
        badge.textContent = count;
        badge.classList.add('bump');
        setTimeout(() => badge.classList.remove('bump'), 400);
    }
}

// ── Toast ────────────────────────────────────────────────────
let toastTimeout;
function showToast(title, sub) {
    const toast = document.getElementById('toast');
    document.getElementById('toastText').textContent = title;
    document.getElementById('toastSub').textContent  = sub || '';
    toast.classList.add('show');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('show'), 3000);
}

// ── Escape HTML helper ───────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Mobile nav drawer toggle ─────────────────────────────────
const ham = document.getElementById('navHamburger');
const drawer = document.getElementById('mobileDrawer');
const drawerClose = document.getElementById('mobileDrawerClose');
function openMobileMenu()  { ham.classList.add('open'); drawer.classList.add('open'); document.body.style.overflow='hidden'; }
function closeMobileMenu() { ham.classList.remove('open'); drawer.classList.remove('open'); document.body.style.overflow=''; }
if (ham) ham.addEventListener('click', openMobileMenu);
if (drawerClose) drawerClose.addEventListener('click', closeMobileMenu);
if (drawer) drawer.addEventListener('click', e => { if (e.target === drawer) closeMobileMenu(); });

// Close on Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { 
        closeCart(); 
        closeMobileMenu(); 
        if (typeof closeVariantPicker === 'function') closeVariantPicker();
    }
});

// ── Scroll-reveal fade-in (site-wide, respects prefers-reduced-motion) ──
(function() {
    const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let observer = null;
    if (!prefersReduced && 'IntersectionObserver' in window) {
        observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    }

    // Call after adding class="reveal-on-scroll" to any element added dynamically
    // (e.g. a product card created via JS after the initial page load).
    window.dievonObserveReveal = function(el) {
        if (!el) return;
        if (!observer) { el.classList.add('revealed'); return; }
        observer.observe(el);
    };

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.reveal-on-scroll').forEach(el => window.dievonObserveReveal(el));
    });
})();

// Wishlist local-storage syncing helper (site-wide)
function getWishlist() {
    try {
        return JSON.parse(localStorage.getItem('dievon_wishlist')) || [];
    } catch(e) {
        return [];
    }
}
function toggleWishlist(productId) {
    let list = getWishlist();
    const idx = list.indexOf(productId);
    let added = false;
    if (idx > -1) {
        list.splice(idx, 1);
    } else {
        list.push(productId);
        added = true;
    }
    localStorage.setItem('dievon_wishlist', JSON.stringify(list));
    updateWishlistBadge();
    showToast(added ? 'Added to Wishlist' : 'Removed from Wishlist', added ? 'Item added to your private wishlist' : 'Item removed from your wishlist');
    return added;
}
function updateWishlistBadge() {
    const list = getWishlist();
    const badge = document.getElementById('wishlistBadge');
    if (badge) {
        badge.textContent = list.length;
        badge.style.display = list.length > 0 ? 'inline-flex' : 'none';
    }
}

function handleWishlistClick(productId, btn) {
    const icon = btn.querySelector('i');
    const added = toggleWishlist(productId.toString());
    if (added) {
        icon.className = 'fa-solid fa-heart wishlist-btn-active';
    } else {
        icon.className = 'fa-regular fa-heart';
    }
}

function syncWishlistHearts() {
    const list = getWishlist();
    document.querySelectorAll('button[onclick*="handleWishlistClick"]').forEach(btn => {
        const match = btn.getAttribute('onclick').match(/handleWishlistClick\((\d+)/);
        if (match && match[1]) {
            const pid = match[1];
            const icon = btn.querySelector('i');
            if (icon) {
                if (list.indexOf(pid.toString()) > -1) {
                    icon.className = 'fa-solid fa-heart wishlist-btn-active';
                } else {
                    icon.className = 'fa-regular fa-heart';
                }
            }
        }
    });
}

// ── Multi-Currency Client Engine ──────────────────────────────
window.CURRENCY_RATES = window.CURRENCY_RATES || {
    'GBP': { symbol: '£', rate: 1.00 },
    'INR': { symbol: '₹', rate: 105.00 },
    'USD': { symbol: '$', rate: 1.30 }
};

if (typeof getCookie !== 'function') {
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }
}

if (typeof getCurrentCurrency !== 'function') {
    function getCurrentCurrency() {
        return 'INR';
    }
}

if (typeof formatPriceJS !== 'function') {
    function formatPriceJS(amountInINR) {
        const curr = getCurrentCurrency();
        const rates = window.CURRENCY_RATES || {};
        const data = rates[curr] || rates['INR'] || { symbol: '₹', rate: 1.00 };
        const converted = (parseFloat(amountInINR) || 0) * data.rate;
        return data.symbol + converted.toFixed(2);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateWishlistBadge();
    syncWishlistHearts();

    // Sync active currency select state
    const savedCurrency = getCurrentCurrency();
    const currencySelect = document.getElementById('currencySelector');
    if (currencySelect) {
        currencySelect.value = savedCurrency;
    }
});

function changeCurrency(val) {
    document.cookie = "dievon_currency=" + val + "; path=/; max-age=" + (86400 * 30);
    localStorage.setItem('dievon_currency', val);
    showToast('Currency Updated', 'Prices switched to ' + val);
    setTimeout(() => { location.reload(); }, 400);
}

// Load cart on page startup
fetch(getCartApiUrl('?action=get'))
    .then(r => r.json())
    .then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
</script>

</body>
</html>

