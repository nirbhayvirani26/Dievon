<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pageTitle = "Shipping & Delivery Policy | Dievon";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "Dievon delivery times, courier partners and charges across India, the free-delivery threshold, and how international orders are handled.";
require_once __DIR__ . '/../includes/header.php';

// Read the REAL rates rather than restating them in prose. This page previously
// promised "free over £150, flat £15.00" while checkout actually charged ₹99 and
// gave free delivery over ₹2000 — wrong currency and wrong numbers, on the page
// customers read before deciding to buy. Now the policy cannot drift from what
// checkout charges, because both read the same Store Settings.
$shipFreeMin = (float)storeSetting($pdo, 'free_shipping_min', 2000);
$shipFee     = (float)storeSetting($pdo, 'standard_shipping_fee', 99);
$shipIntlFee = (float)storeSetting($pdo, 'international_shipping_fee', 2500);

// Whether this page may promise delivery abroad. It used to say so unconditionally
// — "Global courier partners", "DHL Express / FedEx", a flat international rate —
// while the country selector was off, no country was enabled for selling, and
// Cash on Delivery is domestic-only. See shipsInternationally() in config.php.
$shipsAbroad = shipsInternationally($pdo);
?>

<?php // Slot 4 — the shared policy-page photograph. ?>
<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(4) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Logistics &amp; Dispatch</span>
        <h1>Shipping &amp; Delivery Policy</h1>
        <p><?= $shipsAbroad ? 'Courier partners, dispatch timelines and delivery guarantees.'
                             : 'Dispatch timelines, delivery charges and courier partners across India.' ?></p>
    </div>
</section>

<section class="section-space">
    <div class="container">
        <div class="reveal-on-scroll legal-page">
            <h2 class="legal-heading">1. Shipping Timelines &amp; Dispatch</h2>
            <p class="legal-text">Orders placed before 2:00 PM IST are dispatched on the same business day. Standard delivery within India takes 3–5 business days, with metro cities usually arriving sooner.<?php if ($shipsAbroad): ?> International orders arrive within 5–8 business days.<?php endif; ?></p>
            <?php if (!$shipsAbroad): ?>
            <p class="legal-text">We currently deliver within India only. If you would like a piece sent
               to an address outside India, <a href="<?= SITE_URL ?>/contact" class="legal-link">get in touch</a>
               and we will tell you whether we can arrange it.</p>
            <?php endif; ?>

            <h2 class="legal-heading">2. Shipping Rates &amp; Free Delivery</h2>
            <p class="legal-text">Complimentary shipping is automatically applied to all orders over <?= formatPrice($shipFreeMin) ?>. Orders below <?= formatPrice($shipFreeMin) ?> are subject to a flat <?= formatPrice($shipFee) ?> delivery charge.<?php if ($shipsAbroad): ?> International orders are charged a flat <?= formatPrice($shipIntlFee) ?>, and the free-delivery threshold does not apply to them.<?php endif; ?></p>

            <h2 class="legal-heading">3. Order Tracking</h2>
            <p class="legal-text-last">Once your order is packed and dispatched, a shipping confirmation email containing your tracking code and printable thermal shipping label link will be sent to your registered email.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
