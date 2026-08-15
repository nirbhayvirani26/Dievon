<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/legal_render.php';

$pageTitle = "Terms & Conditions | Dievon";
$metaDescription = "The terms that apply when you buy from Dievon: orders, pricing, delivery, cancellations, returns and how disputes are handled.";

// Source text: content/legal/terms.txt. Same arrangement as the privacy notice —
// plain text with "## " headings and {{TOKENS}} filled in from Store Settings,
// so nothing on this page states a fact about the business that nobody supplied.
//
// This replaced a three-section page (pricing, payment, intellectual property)
// which said nothing about how an order is formed, delivery, cancellation,
// returns, liability, or where a dispute would be heard.
$termsFile   = __DIR__ . '/../content/legal/terms.txt';
$termsSource = @file_get_contents($termsFile);
$termsUpdated = date('j F Y', @filemtime($termsFile) ?: time());

require_once __DIR__ . '/../includes/header.php';
?>

<?php // Slot 4 — the shared policy-page photograph. ?>
<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(4) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Legal &amp; Governance</span>
        <h1>Terms &amp; Conditions</h1>
        <p>The terms you agree to when you order from Dievon.</p>
    </div>
</section>

<section class="section-space">
    <div class="container">
        <?php if ($termsSource === false): ?>
            <div class="legal-page">
                <div class="legal-gap-notice">
                    <strong>This page could not be loaded.</strong>
                    Please <a href="<?= SITE_URL ?>/contact">contact us</a> and we will send you
                    a copy of our terms directly.
                </div>
            </div>
        <?php else: ?>
            <?php
                // Read from Store Settings, never typed.
                //
                // This line said "Delivery is ₹99, free over ₹2,000" as literal text.
                // Those figures live in Settings → standard_shipping_fee and
                // free_shipping_min, and checkout charges from there — so raising the
                // delivery fee left the Terms page publicly promising the old price.
                // A published price a shop does not honour is the one kind of stale
                // text that is actually a legal problem.
                $tShipFee  = (float)storeSetting($pdo, 'standard_shipping_fee', 0);
                $tFreeOver = (float)storeSetting($pdo, 'free_shipping_min', 0);
                $tDelivery = 'Prices are in rupees and include GST.';
                if ($tShipFee > 0) {
                    $tDelivery .= ' Delivery is ' . formatPrice($tShipFee);
                    $tDelivery .= $tFreeOver > 0
                        ? ', free over ' . formatPrice($tFreeOver) . '.'
                        : '.';
                } elseif ($tFreeOver > 0) {
                    $tDelivery .= ' Delivery is free over ' . formatPrice($tFreeOver) . '.';
                } else {
                    $tDelivery .= ' Delivery is free on every order.';
                }

                $termsSummary = [
                    $tDelivery,
                    'Your order becomes a contract when we confirm dispatch — not when you press pay.',
                    'Orders placed before 2pm IST leave the same business day and arrive in 3–5 business days across India.',
                    '<strong>You have ' . (int)RETURN_WINDOW_DAYS . ' days from delivery</strong> to send something back, unworn and with tags attached.',
                    'Refunds go to the way you paid, within 3–5 business days of us checking the item.',
                    'Nothing here takes away your rights under the Consumer Protection Act, 2019.',
                ];
            ?>
            <?= legalRenderPage($termsSource, $pdo, $termsUpdated, $termsSummary) ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
