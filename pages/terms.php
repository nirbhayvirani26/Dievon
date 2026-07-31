<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Terms & Conditions | Dievon Atelier";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_2.png')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Legal &amp; Governance</span>
        <h1>Terms &amp; Conditions</h1>
        <p>Terms governing website usage, luxury sales, and atelier order fulfillment.</p>
    </div>
</section>

<section class="section-space">
    <div class="container" style="max-width: 900px; margin: 0 auto; color: var(--text-secondary); line-height: 1.8; font-size: 15px;">
        <div class="reveal-on-scroll" style="background: var(--bg-surface); padding: 40px; border: 1px solid var(--border-light); border-radius: 8px;">
            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">1. Atelier Orders &amp; Product Pricing</h2>
            <p style="margin-bottom: 24px;">All prices listed on Dievon are shown in Great British Pounds (£ / GBP) inclusive of applicable taxes. Orders placed online constitute an offer to purchase, subject to final stock verification and order confirmation.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">2. Payment &amp; Security</h2>
            <p style="margin-bottom: 24px;">Orders must be paid using accepted credit cards, debit cards, or approved Cash on Delivery (COD) zip codes. Dievon reserves the right to cancel unverified orders or suspicious transactions.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">3. Intellectual Property</h2>
            <p style="margin-bottom: 0;">All brand logos, photography, garment designs, lookbooks, and editorial text are protected trademarks and intellectual property of Dievon Atelier.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
