<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Privacy Policy | Dievon Atelier";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_1.png')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Legal &amp; Privacy</span>
        <h1>Privacy Policy</h1>
        <p>How Dievon collects, protects, and handles your personal information.</p>
    </div>
</section>

<section class="section-space">
    <div class="container" style="max-width: 900px; margin: 0 auto; color: var(--text-secondary); line-height: 1.8; font-size: 15px;">
        <div class="reveal-on-scroll" style="background: var(--bg-surface); padding: 40px; border: 1px solid var(--border-light); border-radius: 8px;">
            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">1. Information We Collect</h2>
            <p style="margin-bottom: 24px;">When you place an order or create an account with Dievon, we collect your name, email address, shipping/billing address, phone number, and payment confirmation status. Payment credentials (credit cards, debit cards) are processed securely through certified payment gateways and are never stored directly on our servers.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">2. How We Use Your Data</h2>
            <p style="margin-bottom: 24px;">Your information is used strictly to fulfill your orders, provide dispatch tracking updates, issue tax invoices, process legitimate returns, and communicate VIP boutique updates if you opted into our newsletter.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">3. Data Protection &amp; Security</h2>
            <p style="margin-bottom: 24px;">We enforce SSL 256-bit encryption on all data transfers. We do not sell, rent, or trade your personal information to third-party marketing companies.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">4. Contacting Us</h2>
            <p style="margin-bottom: 0;">For privacy inquiries or data removal requests, please email us at <a href="mailto:concierge@dievon.com" style="color: var(--color-primary); font-weight: 600;">concierge@dievon.com</a>.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
