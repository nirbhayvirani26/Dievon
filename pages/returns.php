<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Returns & Refunds Policy | Dievon Atelier";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="luxury-hero" style="padding: 100px 0 40px;">
    <div class="container">
        <span class="luxury-hero-eyebrow">Assurance &amp; Guarantees</span>
        <h1>Returns &amp; Refunds Policy</h1>
        <p>14-day luxury return guarantee, exchanges, and full refund processing.</p>
    </div>
</section>

<section class="section-space">
    <div class="container" style="max-width: 900px; margin: 0 auto; color: var(--text-secondary); line-height: 1.8; font-size: 15px;">
        <div class="reveal-on-scroll" style="background: var(--bg-surface); padding: 40px; border: 1px solid var(--border-light); border-radius: 8px;">
            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">1. 14-Day Return Policy</h2>
            <p style="margin-bottom: 24px;">We want you to be completely delighted with your purchase. You may return unworn, unaltered garments in original luxury packaging with all tags attached within 14 days of delivery.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">2. How to Request a Return</h2>
            <p style="margin-bottom: 24px;">To initiate a return, visit your customer account dashboard or email <a href="mailto:returns@dievon.com" style="color: var(--color-primary); font-weight: 600;">returns@dievon.com</a> with your order number (`INV-XXXX`). Our concierge team will issue a prepaid return collection label.</p>

            <h2 style="color: var(--text-primary); font-family: var(--font-heading); margin-bottom: 16px; font-size: 24px;">3. Refund Processing</h2>
            <p style="margin-bottom: 0;">Once your returned garment passes boutique inspection, your refund will be processed back to your original payment method within 3–5 business days.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
