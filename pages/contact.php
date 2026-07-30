<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $service = trim($_POST['service'] ?? 'General Enquiry');

    if ($name && $email && $message) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Save to database
            try {
                $ins = $pdo->prepare("INSERT INTO inquiries (name, email, phone, message) VALUES (:name, :email, :phone, :message)");
                $ins->execute([
                    'name' => $name . " [Service: $service]",
                    'email' => $email,
                    'phone' => $phone,
                    'message' => $message
                ]);
            } catch (PDOException $e) {}

            // Send notification to Admin & Acknowledgement to Customer via EmailService
            try {
                require_once __DIR__ . '/../services/EmailService.php';
                $emailService = new EmailService($pdo);
                $emailService->sendContactFormEmails($name, $email, $phone, $service, $message);
                $success = true;
            } catch (\Throwable $exEmail) {
                error_log("Contact email dispatch error: " . $exEmail->getMessage());
                $success = true; // Email failure does not stop form submission success for user
            }
        }
    } else {
        $error = "Please fill in all required fields (Name, Email, and Message).";
    }
}

$pageTitle = "Contact Us";
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'contact'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = SITE_URL . '/uploads/gallery/' . $seoRow['og_image']; }
    }
} catch (PDOException $e) {}
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image section-mb-sm" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/gallery/lookbook_1.png')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Private Invitation</span>
        <h1>Atelier Consultations</h1>
        <p>Book private fittings or request customized size consultation at Dievon.</p>
    </div>
</section>

<!-- ══ Contact Form & Details Section ═════════════════════ -->
<section class="section-space">
    <div class="container" style="max-width: 1100px; margin: 0 auto; padding: 0 20px;">
        
        <div class="contact-layout-grid">
            
            <!-- Left Column: Form -->
            <div class="reveal-on-scroll" style="background: var(--bg-surface); border: 1px solid var(--border-light); padding: 40px;">
                <h2 style="font-family: var(--font-heading); font-size: 24px; font-weight: 400; text-transform: uppercase; margin-bottom: 25px;">Private Inquiry Form</h2>
                
                <?php if ($success): ?>
                    <div style="padding: 20px; background: #e6faf7; color: #2e7d32; border: 1px solid #c3e6cb; font-size: 14px; margin-bottom: 25px; text-align: center;">
                        <i class="fa-solid fa-circle-check" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                        <strong>Request Received</strong><br>
                        An atelier concierge representative will email or call you within 24 business hours to confirm details.
                    </div>
                <?php else: ?>
                    
                    <?php if ($error !== ''): ?>
                        <div style="padding: 12px; background: #fdf2f2; color: #a94442; border: 1px solid #f5c6cb; font-size: 13px; margin-bottom: 25px;">
                            <i class="fa-solid fa-triangle-exclamation" style="margin-right: 5px;"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form action="contact.php" method="POST">
                        <div class="contact-form-row">
                            <div class="form-luxury-group">
                                <label for="cntName">Full Name *</label>
                                <input type="text" id="cntName" name="name" class="form-luxury-input" required placeholder="Eleanor Vance">
                            </div>
                            <div class="form-luxury-group">
                                <label for="cntEmail">Email Address *</label>
                                <input type="email" id="cntEmail" name="email" class="form-luxury-input" required placeholder="eleanor@example.com">
                            </div>
                        </div>

                        <div class="contact-form-row">
                            <div class="form-luxury-group">
                                <label for="cntPhone">Phone Number (optional)</label>
                                <input type="tel" id="cntPhone" name="phone" class="form-luxury-input" placeholder="+44 7700 900 123">
                            </div>
                            <div class="form-luxury-group">
                                <label for="cntService">Requested Consultation *</label>
                                <select id="cntService" name="service" class="form-luxury-input" style="height: 46px; outline: none; cursor: pointer; text-transform: uppercase; font-size: 12px; letter-spacing: 0.05em;">
                                    <option value="General Inquiry">General Product Inquiry</option>
                                    <option value="Private Fitting">Bespoke Size / Private Fitting</option>
                                    <option value="Bridal Customization">Wedding / Bridal Customization</option>
                                    <option value="Atelier Tour">Atelier Consultation & Tour</option>
                                </select>
                            </div>
                        </div>


                        <div class="form-luxury-group">
                            <label for="cntMessage">Inquiry details *</label>
                            <textarea id="cntMessage" name="message" class="form-luxury-input" rows="5" required placeholder="Describe your styling preferences, size requests, or questions here..." style="resize: none;"></textarea>
                        </div>

                        <button type="submit" name="contact_submit" class="btn-luxury" style="width: 100%; justify-content: center; height: 50px; font-size: 13px; font-weight: 600;">
                            Submit Request
                        </button>
                    </form>

                <?php endif; ?>
            </div>

            <!-- Right Column: Info & Details -->
            <div class="reveal-on-scroll" style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 40px; font-size: 14px; line-height: 1.8; color: var(--text-secondary);">
                <h2 style="font-family: var(--font-heading); font-size: 24px; font-weight: 400; text-transform: uppercase; margin-bottom: 25px; color: var(--text-primary);">Dievon Info</h2>
                
                <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 500; text-transform: uppercase; margin-bottom: 10px; color: var(--text-primary);">Flagship Atelier</h3>
                <p style="margin-bottom: 30px;">
                    Dievon London<br>
                    12 Bond Street, Mayfair,<br>
                    London, W1S 1AA<br>
                    <small style="color: var(--text-muted);">By private invitation only.</small>
                </p>

                <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 500; text-transform: uppercase; margin-bottom: 10px; color: var(--text-primary);">Support Hours</h3>
                <p style="margin-bottom: 30px;">
                    Monday to Saturday: 10:00 AM – 7:00 PM<br>
                    Sunday: 12:00 PM – 6:00 PM<br>
                    Concierge Line: <strong>+44 20 7946 0192</strong>
                </p>

                <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 500; text-transform: uppercase; margin-bottom: 10px; color: var(--text-primary);">Digital Desk</h3>
                <p style="margin-bottom: 0;">
                    Client Support: <a href="mailto:support@dievon.com" style="color: var(--color-accent); font-weight: 600; text-decoration: underline;">support@dievon.com</a><br>
                    Private Fittings: <a href="mailto:concierge@dievon.com" style="color: var(--color-accent); font-weight: 600; text-decoration: underline;">concierge@dievon.com</a>
                </p>
            </div>

        </div>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
