<?php
// ============================================================
//  Dievon – Luxury Order Confirmation Page
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$code = trim($_GET['code'] ?? '');
if (empty($code)) {
    header('Location: ' . SITE_URL . '/pages/home.php');
    exit;
}

// Fetch order from DB
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code LIMIT 1");
$stmt->execute(['code' => $code]);
$order = $stmt->fetch();
if (!$order) {
    header('Location: ' . SITE_URL . '/pages/home.php');
    exit;
}

$items = json_decode($order['items_json'], true) ?? [];
$isCollection = (strpos($order['address'], 'Collection') !== false);

$pageTitle = "Order Confirmed #" . htmlspecialchars($order['order_code']);
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Luxury Confirmation Hero ════════════════════════════ -->
<section class="luxury-hero order-conf-hero">
    <div class="container">
        <div class="order-conf-icon-check">
            <i class="fa-solid fa-check"></i>
        </div>
        <span class="luxury-hero-eyebrow order-conf-eyebrow">Thank You For Your Order</span>
        <h1 class="order-conf-heading">Order Confirmed</h1>
        <p class="order-conf-desc">
            Thank you <strong><?= htmlspecialchars($order['customer_name']) ?></strong>. Your order has been placed into our atelier dispatch queue. A confirmation summary has been sent to <strong><?= htmlspecialchars($order['customer_email']) ?></strong>.
        </p>
    </div>
</section>

<!-- ══ Order Summary Section ═════════════════════════════════ -->
<section class="section-space" style="padding-top: 20px;">
    <div class="container order-conf-container reveal-on-scroll">
        
        <!-- Order Code & Status Banner -->
        <div class="order-conf-banner">
            <div>
                <span class="order-conf-label-sm">Order Reference</span>
                <span class="order-conf-ref-code"><?= htmlspecialchars($order['order_code']) ?></span>
            </div>
            <div>
                <span class="order-conf-label-sm">Status</span>
                <span class="order-conf-status-badge">
                    <?= htmlspecialchars($order['status']) ?>
                </span>
            </div>
            <div>
                <span class="order-conf-label-sm">Order Date</span>
                <span class="order-conf-date-val"><?= date('F j, Y', strtotime($order['created_at'])) ?></span>
            </div>
        </div>

        <?php if ($isCollection): ?>
        <!-- Collection Info Box -->
        <div class="order-conf-collection-box">
            <i class="fa-solid fa-store" style="font-size: 32px; color: var(--color-accent); margin-bottom: 12px;"></i>
            <h3 style="font-family: var(--font-heading); font-size: 18px; text-transform: uppercase; margin: 0 0 10px; color: var(--text-primary);">Flagship Atelier Collection</h3>
            <p style="font-size: 13px; color: var(--text-secondary); margin: 0 0 15px;">
                <strong>Dievon Atelier</strong><br>
                Unit E5 Phoenix Business Centre, HA1 2SP<br>
                <span style="font-weight: 600; color: var(--text-primary);">Collection Hours: 11:00 AM – 8:00 PM</span>
            </p>
        </div>
        <?php endif; ?>

        <!-- Order Items & Calculation Panel -->
        <div class="order-conf-panel">
            <h3 class="order-conf-panel-title">
                Ordered Garments &amp; Items
            </h3>

            <div class="order-conf-items-list">
                <?php foreach ($items as $item): ?>
                <div class="order-conf-item-row">
                    <div class="order-conf-item-left">
                        <?php if (!empty($item['image'])): ?>
                        <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>"
                             style="width:44px; height:54px; object-fit:cover; border-radius:4px; flex-shrink:0;"
                             onerror="this.outerHTML='<span class=&quot;order-conf-item-emoji&quot;><?= htmlspecialchars($item['emoji'] ?? '👗') ?></span>';">
                        <?php else: ?>
                        <span class="order-conf-item-emoji"><?= htmlspecialchars($item['emoji'] ?? '👗') ?></span>
                        <?php endif; ?>
                        <div>
                            <h4 class="order-conf-item-name"><?= htmlspecialchars($item['name']) ?></h4>
                            <?php if (!empty($item['color_name'])): ?>
                                <span class="order-conf-item-sub">Colour: <?= htmlspecialchars($item['color_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['variant_name'])): ?>
                                <span class="order-conf-item-sub"><?= !empty($item['color_name']) ? 'Size' : 'Variant' ?>: <?= htmlspecialchars($item['variant_name']) ?></span>
                            <?php endif; ?>
                            <span class="order-conf-item-meta">Qty: <?= (int)$item['quantity'] ?> × <?= (function_exists('formatPrice') ? formatPrice($item['price']) : '₹' . number_format($item['price'], 2)) ?></span>
                        </div>
                    </div>
                    <span class="order-conf-item-price">
                        <?= (function_exists('formatPrice') ? formatPrice($item['price'] * $item['quantity']) : '₹' . number_format($item['price'] * $item['quantity'], 2)) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Price Breakdown Calculation -->
            <?php
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float)$item['price'] * (int)$item['quantity'];
            }
            ?>
            <div class="order-conf-calc-wrap">
                <div class="order-conf-calc-row">
                    <span>Subtotal:</span>
                    <span style="font-weight: 600; color: var(--text-primary);"><?= (function_exists('formatPrice') ? formatPrice($subtotal) : '₹' . number_format($subtotal, 2)) ?></span>
                </div>
                <?php if (!empty($order['promo_code']) && (float)$order['discount_amount'] > 0): ?>
                <div class="order-conf-calc-discount">
                    <span>Promo Discount (<?= htmlspecialchars($order['promo_code']) ?>):</span>
                    <span style="font-weight: 600;">−<?= (function_exists('formatPrice') ? formatPrice((float)$order['discount_amount']) : '₹' . number_format((float)$order['discount_amount'], 2)) ?></span>
                </div>
                <?php endif; ?>
                <?php if ((float)$order['delivery_charge'] > 0): ?>
                <div class="order-conf-calc-row">
                    <span>Shipping Charge:</span>
                    <span style="font-weight: 600; color: var(--text-primary);"><?= (function_exists('formatPrice') ? formatPrice((float)$order['delivery_charge']) : '₹' . number_format((float)$order['delivery_charge'], 2)) ?></span>
                </div>
                <?php endif; ?>
                <div class="order-conf-calc-total">
                    <span>Total Amount:</span>
                    <span class="order-conf-total-val"><?= (function_exists('formatPrice') ? formatPrice((float)$order['total_price']) : '₹' . number_format((float)$order['total_price'], 2)) ?></span>
                </div>
            </div>
        </div>

        <!-- Customer & Shipping Details Panel -->
        <div class="order-conf-details-grid">
            <div>
                <h4 style="font-family: var(--font-heading); font-size: 14px; text-transform: uppercase; margin: 0 0 10px; color: var(--text-primary);">Client Info</h4>
                <p style="margin: 0; color: var(--text-secondary); line-height: 1.6;">
                    <strong><?= htmlspecialchars($order['customer_name']) ?></strong><br>
                    <?= htmlspecialchars($order['customer_email']) ?><br>
                    <?= htmlspecialchars($order['phone']) ?>
                </p>
            </div>
            <div>
                <h4 style="font-family: var(--font-heading); font-size: 14px; text-transform: uppercase; margin: 0 0 10px; color: var(--text-primary);"><?= $isCollection ? 'Collection Point' : 'Delivery Address' ?></h4>
                <p style="margin: 0; color: var(--text-secondary); line-height: 1.6;">
                    <?= nl2br(htmlspecialchars($order['address'])) ?>
                </p>
            </div>
            <div>
                <h4 style="font-family: var(--font-heading); font-size: 14px; text-transform: uppercase; margin: 0 0 10px; color: var(--text-primary);">Payment Method</h4>
                <p style="margin: 0; color: var(--text-secondary); line-height: 1.6;">
                    <span style="text-transform: uppercase; font-weight: 600;"><?= htmlspecialchars($order['payment_method']) ?></span><br>
                    Payment Status: <span style="font-weight: 600; color: #10b981;"><?= htmlspecialchars($order['payment_status']) ?></span>
                </p>
            </div>
        </div>

        <!-- Action CTA Buttons -->
        <div class="order-conf-actions-row">
            <a href="<?= SITE_URL ?>/pages/shop.php" class="btn-luxury">
                <i class="fa-solid fa-bag-shopping"></i> Continue Shopping
            </a>
            <button onclick="window.print()" class="btn-luxury-outline">
                <i class="fa-solid fa-print"></i> Print Receipt
            </button>
        </div>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
