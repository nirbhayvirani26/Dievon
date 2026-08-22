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
$order = $stmt->fetch();   // false, not null, when missing
if (!$order) {
    header('Location: ' . SITE_URL . '/pages/home.php');
    exit;
}

/*
 * This page prints name, full address, email AND phone, and used to render for
 * anyone who had the order code — no login, no check at all. Two ways in now:
 * the browser that actually placed the order (set in checkout_action.php), or a
 * signed-in customer who owns it. Everyone else is sent to the tracking page,
 * which asks for the email or postcode as a second factor.
 */
$placedHere = in_array($order['order_code'], $_SESSION['own_orders'] ?? [], true);
$ownsIt     = !empty($_SESSION['customer_id'])
              && (int)$order['customer_id'] === (int)$_SESSION['customer_id'];

if (!$placedHere && !$ownsIt) {
    header('Location: ' . SITE_URL . '/orders?code=' . urlencode($order['order_code']));
    exit;
}

$items = orderItems($order['items_json']);
$isCollection = (strpos($order['address'], 'Collection') !== false);

$pageTitle = "Order Confirmed #" . htmlspecialchars($order['order_code']);
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Luxury Confirmation Hero ════════════════════════════ -->
<section class="luxury-hero order-conf-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(1) ?>')">
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
                <?php // The shop's own registered address from Store Settings — this was
                      // hardcoded to a UK unit, "Unit E5 Phoenix Business Centre, HA1 2SP",
                      // printed on the confirmation of every collection order. Prints
                      // nothing rather than a wrong address when the setting is blank. ?>
                <strong><?= htmlspecialchars(brandName($pdo ?? null)) ?></strong><br>
                <?php $confAddr = trim((string)storeSetting($pdo ?? null, 'seller_address', '')); ?>
                <?php if ($confAddr !== ''): ?>
                <?= nl2br(htmlspecialchars($confAddr)) ?><br>
                <?php endif; ?>
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
                             style="width:44px; height:54px; object-fit:cover; flex-shrink:0;"
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
                                <span class="order-conf-item-sub">Size: <?= htmlspecialchars(variantSizeLabel($item['variant_name'])) ?></span>
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
                <?php if ((float)($order['cod_fee'] ?? 0) > 0): ?>
                <div class="order-conf-calc-row">
                    <span>COD Handling Fee:</span>
                    <span style="font-weight: 600; color: var(--text-primary);"><?= (function_exists('formatPrice') ? formatPrice((float)$order['cod_fee']) : '₹' . number_format((float)$order['cod_fee'], 2)) ?></span>
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
            <?php // The real invoice document, not window.print().
                  //
                  // This was a plain window.print(), which printed THIS page — the
                  // site header, the nav, the hero, the footer and all — instead of
                  // the receipt. There is a proper invoice template with the logo,
                  // the seller details, the line items and the totals on it, and it
                  // was the one thing this button did not reach.
                  //
                  // Opens in a new tab so the confirmation page, with its tracking
                  // details, is not lost behind the print dialog. ?>
            <a href="<?= SITE_URL ?>/pages/print_invoice.php?code=<?= urlencode($order['order_code']) ?>"
               target="_blank" rel="noopener" class="btn-luxury-outline">
                <i class="fa-solid fa-file-invoice"></i> View / Print Invoice
            </a>
        </div>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
