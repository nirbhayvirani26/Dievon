<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Shipping & Delivery Policy | Dievon Atelier";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_3.png')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Logistics &amp; Dispatch</span>
        <h1>Shipping &amp; Delivery Policy</h1>
        <p>Global courier partners, dispatch timelines, and delivery guarantees.</p>
    </div>
</section>

<section class="section-space">
    <div class="container" style="max-width: 900px; margin: 0 auto; color: var(--text-secondary); line-height: 1.8; font-size: 15px;">
        <div class="reveal-on-scroll" style="background: var(--bg-surface); padding: 40px; border: 1px solid var(--border-light); border-radius: 8px;">
            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">1. Shipping Timelines &amp; Dispatch</h2>
            <p style="margin-bottom: 24px;">Orders placed before 2:00 PM GMT are dispatched on the same business day. Standard UK delivery takes 2–3 business days. Express International shipping arrives within 3–5 business days via DHL Express / FedEx.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">2. Shipping Rates &amp; Free Delivery</h2>
            <p style="margin-bottom: 24px;">Complimentary express shipping is automatically applied to all orders over £150. Orders below £150 are subject to a flat £15.00 shipping fee.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">3. Order Tracking</h2>
            <p style="margin-bottom: 0;">Once your order is packed and dispatched, a shipping confirmation email containing your tracking code and printable thermal shipping label link will be sent to your registered email.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
