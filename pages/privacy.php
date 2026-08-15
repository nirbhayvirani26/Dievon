<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/legal_render.php';

$pageTitle = "Privacy Policy | Dievon";
$metaDescription = "What personal data Dievon collects, why we hold it, who it is shared with, how long we keep it, and how to ask us to delete it.";

// ── Where this text lives ───────────────────────────────────────────────────
// content/legal/privacy.txt, as plain text with "## " headings and {{TOKENS}}
// for the facts only the owner can supply. Keeping it out of the markup means
// the notice can be edited without touching PHP, and — the reason that matters —
// a fact the owner has not supplied renders as a visible gap rather than as an
// invented registration number or address.
//
// The document is written to the Indian framework: the Digital Personal Data
// Protection Act 2023, the IT Act and its SPDI Rules, and the Consumer
// Protection (E-Commerce) Rules 2020, which require a named grievance officer.
// The review that prompted this rewrite asked for UK/ICO wording; this business
// is Indian and sells only in India, so the same substance — identity, grounds,
// retention, processors, transfers, rights, complaint route — is stated under
// the law that actually applies to it.
$policyFile   = __DIR__ . '/../content/legal/privacy.txt';
$policySource = @file_get_contents($policyFile);

// Taken from the file itself, so the page cannot claim a revision date for a
// revision that never happened.
$policyUpdated = date('j F Y', @filemtime($policyFile) ?: time());

require_once __DIR__ . '/../includes/header.php';
?>

<?php // Slot 4 is the policy pages' own photograph. They used to borrow slots
      // 1, 2 and 3, so changing the About or Cart image silently changed
      // Privacy and Terms too — and a photograph chosen to sell a garment is
      // rarely the right one above a legal page. ?>
<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(4) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Legal &amp; Privacy</span>
        <h1>Privacy Notice</h1>
        <p>What we collect, why we hold it, who it goes to, and what you can ask us to do about it.</p>
    </div>
</section>

<section class="section-space">
    <div class="container">
        <?php if ($policySource === false): ?>
            <div class="legal-page">
                <div class="legal-gap-notice">
                    <strong>This page could not be loaded.</strong>
                    Please <a href="<?= SITE_URL ?>/contact">contact us</a> and we will send you
                    a copy of our privacy notice directly.
                </div>
            </div>
        <?php else: ?>
            <?php
                // The short version. Every line here is stated in full below —
                // this saves a reader time, it does not replace the notice.
                $privacySummary = [
                    'We collect what an order needs: your name, email, phone, delivery address and what you bought.',
                    '<strong>We never see your card details.</strong> Razorpay handles the payment; we are told only whether it worked.',
                    'There is <strong>no Google Analytics, no Meta Pixel and no advertising cookie</strong> on this site. The only cookies we set keep your bag and your sign-in working.',
                    'We share your address with the courier so the parcel can arrive, and nothing with advertisers. <strong>We never sell your data.</strong>',
                    'Order and invoice records are kept for 8 years because tax law requires it. Everything else is deleted when you ask, or when your account closes.',
                    'You can ask us what we hold, correct it, or have it deleted — and complain to our Grievance Officer if we get it wrong.',
                ];
            ?>
            <?= legalRenderPage($policySource, $pdo, $policyUpdated, $privacySummary) ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
