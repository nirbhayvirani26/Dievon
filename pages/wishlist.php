<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// Fetch all available products so we can render them on the client side
$products = [];
try {
    $products = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {}

$pageTitle = "My Wishlist";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero section-mb-sm">
    <div class="container">
        <span class="luxury-hero-eyebrow">Your Curations</span>
        <h1>Private Wishlist</h1>
        <p>Your private, hand-selected edit of Dievon luxury pieces.</p>
    </div>
</section>

<!-- ══ Wishlist Items Section ═════════════════════════════ -->
<section class="section-space">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Grid of Wishlisted Items -->
        <div class="products-grid" id="wishlistGrid">
            <!-- Rendered dynamically by Javascript matching localStorage IDs -->
        </div>

        <!-- Empty Wishlist State -->
        <div id="wishlistEmptyState" style="text-align: center; padding: 80px 0; display: none;">
            <div style="font-size: 50px; margin-bottom: 20px; color: var(--text-muted);">🖤</div>
            <h2 style="font-family: var(--font-heading); font-size: 26px; font-weight: 400; text-transform: uppercase; margin-bottom: 15px;">Your Wishlist is Empty</h2>
            <p style="color: var(--text-secondary); font-size: 14px; max-width: 400px; margin: 0 auto 30px; line-height: 1.6;">
                Save your favorite luxury pieces here to revisit and style them later.
            </p>
            <a href="shop" class="btn-luxury">Shop Collections</a>
        </div>

    </div>
</section>

<script>
    // Product catalog loaded into memory for JS rendering
    const allProducts = <?= json_encode($products) ?>;

    document.addEventListener('DOMContentLoaded', () => {
        renderWishlist();
    });

    function renderWishlist() {
        const wishlistIds = getWishlist(); // get from local storage helper in footer
        const grid = document.getElementById('wishlistGrid');
        const empty = document.getElementById('wishlistEmptyState');
        
        // Filter products in memory
        const wishlistedProducts = allProducts.filter(p => wishlistIds.indexOf(p.id.toString()) > -1);
        
        if (wishlistedProducts.length === 0) {
            grid.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        grid.style.display = 'grid';
        empty.style.display = 'none';
        grid.innerHTML = '';

        wishlistedProducts.forEach(p => {
            const card = document.createElement('article');
            card.className = 'product-card reveal-on-scroll';

            const imgSrc = p.image ? `uploads/products/${p.image}` : '';
            const imgHtml = imgSrc
                ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(p.name)}" class="card-img">`
                : `<div class="card-img-fallback">${escHtml(p.emoji)}</div>`;

            card.innerHTML = `
                <button class="product-card-wishlist-btn" onclick="event.preventDefault(); handleRemove(${p.id})" aria-label="Remove from wishlist">
                    <i class="fa-solid fa-heart wishlist-btn-active"></i>
                </button>

                <div class="card-img-container">
                    <a href="product.php?id=${p.id}" class="card-img-link">
                        ${imgHtml}
                    </a>
                </div>
                <div class="product-card-details">
                    <span class="product-card-category">${escHtml(p.category)}</span>
                    <h3 class="product-card-title">
                        <a href="product.php?id=${p.id}">${escHtml(p.name)}</a>
                    </h3>
                    <div class="product-card-price">
                        ${formatPriceJS(p.price)}
                    </div>
                    <div class="product-card-actions">
                        <button onclick="addToBagFromWishlist(${p.id}, '${escHtml(p.name)}', '${escHtml(p.emoji)}', '${escHtml(p.price)}')" class="btn-luxury">
                            <i class="fa-solid fa-bag-shopping"></i> Add to Bag
                        </button>
                        <a href="product.php?id=${p.id}" class="btn-luxury-outline">
                            View Details
                        </a>
                    </div>
                </div>
            `;
            grid.appendChild(card);
            if (typeof dievonObserveReveal === 'function') dievonObserveReveal(card);
        });
    }

    function handleRemove(productId) {
        toggleWishlist(productId.toString()); // removes it
        renderWishlist(); // re-render
    }

    function addToBagFromWishlist(productId, name, emoji, price) {
        window.location.href = `product.php?id=${productId}&select_size=1`;
    }
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
