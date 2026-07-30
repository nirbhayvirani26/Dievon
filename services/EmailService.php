<?php
// ============================================================
//  Dievon – Central Reusable EmailService
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/EnvLoader.php';

// Safe PHPMailer inclusion
$_phpmailerReady = false;
if (file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    $_phpmailerReady = true;
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $_phpmailerReady = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $pdo;
    private $testMode;
    private $testRecipient;
    private $fromAddress;
    private $fromName;
    private $adminAddress;

    public function __construct(?PDO $pdo = null) {
        global $pdo;
        $this->pdo = $pdo;

        $this->testMode = (bool)EnvLoader::get('MAIL_TEST_MODE', true);
        $this->testRecipient = trim((string)EnvLoader::get('MAIL_TEST_RECIPIENT', EnvLoader::get('MAIL_ADMIN_ADDRESS', ADMIN_EMAIL)));
        $this->fromAddress = trim((string)EnvLoader::get('MAIL_FROM_ADDRESS', ADMIN_EMAIL));
        $this->fromName = trim((string)EnvLoader::get('MAIL_FROM_NAME', 'DIEVON Atelier'));
        $this->adminAddress = trim((string)EnvLoader::get('MAIL_ADMIN_ADDRESS', ADMIN_EMAIL));
    }

    /**
     * Core PHPMailer Factory & Dispatch Engine
     */
    private function sendMail(string $toEmail, string $toName, string $subject, string $htmlContent, string $emailType): bool {
        global $_phpmailerReady;
        if (!$_phpmailerReady) {
            $this->logEmail($emailType, $toEmail, $subject, 'failed', 'PHPMailer library not found');
            return false;
        }

        $host = (string)EnvLoader::get('MAIL_HOST', 'smtp.gmail.com');
        $port = (int)EnvLoader::get('MAIL_PORT', 587);
        $user = (string)EnvLoader::get('MAIL_USERNAME', 'princevir2610@gmail.com');
        $pass = (string)EnvLoader::get('MAIL_PASSWORD', '');
        $enc  = (string)EnvLoader::get('MAIL_ENCRYPTION', 'tls');

        // Test mode redirect
        $targetEmail = $toEmail;
        $targetName  = $toName;
        $testHeaderNotice = '';

        if ($this->testMode && !empty($this->testRecipient)) {
            $targetEmail = $this->testRecipient;
            $targetName = "TEST MODE (" . $toName . ")";
            $testHeaderNotice = "
            <div style='background:#fff3cd; color:#856404; padding:10px 16px; font-size:12px; border-bottom:1px solid #ffeeba; text-align:center;'>
                🧪 <strong>TEST MODE ENABLED</strong> — Original Recipient: <code>" . htmlspecialchars($toEmail) . "</code>
            </div>";
        }

        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;

            if (strtolower($enc) === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = $port;

            $mail->setFrom($this->fromAddress ?: $user, $this->fromName);
            $mail->addAddress($targetEmail, $targetName);
            $mail->isHTML(true);

            $mail->Subject = $this->testMode ? "[TEST] " . $subject : $subject;
            $mail->Body    = $testHeaderNotice . $htmlContent;

            $mail->send();
            $this->logEmail($emailType, $toEmail, $subject, 'sent', null);
            return true;

        } catch (Exception $e) {
            $errMsg = $mail->ErrorInfo ?: $e->getMessage();
            error_log("EmailService Error [{$emailType} to {$toEmail}]: " . $errMsg);
            $this->logEmail($emailType, $toEmail, $subject, 'failed', $errMsg);
            return false;
        } catch (\Throwable $t) {
            error_log("EmailService Throwable [{$emailType} to {$toEmail}]: " . $t->getMessage());
            $this->logEmail($emailType, $toEmail, $subject, 'failed', $t->getMessage());
            return false;
        }
    }

    /**
     * Log email dispatch attempt to email_logs table
     */
    private function logEmail(string $type, string $recipient, string $subject, string $status, ?string $error): void {
        try {
            if (!$this->pdo) return;
            $stmt = $this->pdo->prepare("INSERT INTO email_logs (email_type, recipient, subject, status, error_message, test_mode, created_at) VALUES (:type, :rec, :subj, :status, :err, :test, NOW())");
            $stmt->execute([
                'type'   => $type,
                'rec'    => $recipient,
                'subj'   => $subject,
                'status' => $status,
                'err'    => $error,
                'test'   => $this->testMode ? 1 : 0
            ]);
        } catch (\PDOException $e) {
            error_log("Failed to insert email_log: " . $e->getMessage());
        }
    }

    /**
     * DIEVON Luxury HTML Master Template Wrapper
     */
    private function wrapLuxuryTemplate(string $title, string $subtitle, string $bodyContent, string $footerText = ''): string {
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'http://localhost:8888/DievonOrders';
        $year = date('Y');

        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>" . htmlspecialchars($title) . "</title>
    <style>
        body { margin: 0; padding: 0; background-color: #FAF8F5; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #2B2627; -webkit-font-smoothing: antialiased; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #FAF8F5; padding: 36px 0; }
        .main-card { max-width: 600px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; overflow: hidden; border: 1px solid #EAE4DC; box-shadow: 0 8px 30px rgba(81,17,38,0.06); }
        .brand-header { background: #511126; padding: 36px 32px; text-align: center; color: #FFFFFF; }
        .brand-logo { font-size: 26px; font-weight: 300; letter-spacing: 4px; text-transform: uppercase; color: #D4AF37; margin: 0; font-family: Georgia, serif; }
        .brand-subtitle { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: rgba(255,255,255,0.7); margin-top: 6px; }
        .content-area { padding: 32px; font-size: 14px; line-height: 1.7; color: #3A3537; }
        .btn-luxury { display: inline-block; background: #511126; color: #FFFFFF !important; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; padding: 14px 28px; border-radius: 4px; text-decoration: none; margin: 18px 0; }
        .footer { background: #FAF8F5; padding: 24px 32px; border-top: 1px solid #EAE4DC; text-align: center; font-size: 11px; color: #8C8285; line-height: 1.6; }
        .footer a { color: #511126; text-decoration: none; font-weight: 600; }
        table.item-table { width: 100%; border-collapse: collapse; margin: 18px 0; }
        table.item-table th { background: #FAF8F5; color: #511126; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; padding: 10px 12px; text-align: left; border-bottom: 1px solid #EAE4DC; }
        table.item-table td { padding: 12px; border-bottom: 1px solid #F3EEE7; font-size: 13px; }
    </style>
</head>
<body>
<div class='wrapper'>
    <table class='main-card' width='100%' cellpadding='0' cellspacing='0'>
        <tr>
            <td class='brand-header'>
                <h1 class='brand-logo'>DIEVON</h1>
                <div class='brand-subtitle'>" . htmlspecialchars($subtitle) . "</div>
            </td>
        </tr>
        <tr>
            <td class='content-area'>
                {$bodyContent}
            </td>
        </tr>
        <tr>
            <td class='footer'>
                " . ($footerText ? htmlspecialchars($footerText) . "<br>" : "") . "
                &copy; {$year} DIEVON Atelier &bull; Timeless Luxury Women’s Fashion<br>
                Need assistance? Contact our concierge at <a href='mailto:princevir2610@gmail.com'>princevir2610@gmail.com</a>
            </td>
        </tr>
    </table>
</div>
</body>
</html>";
    }

    // ============================================================
    //  1. CUSTOMER REGISTRATION WELCOME EMAIL
    // ============================================================
    public function sendWelcomeEmail(string $email, string $name): bool {
        $safeName = htmlspecialchars($name);
        $safeEmail = htmlspecialchars($email);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Welcome to DIEVON Atelier</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for creating an account with DIEVON. We are delighted to welcome you to our private luxury circle.</p>
            <p>As a registered member, you will enjoy express checkout, personal order tracking, and exclusive early access to new seasonal collections and bespoke bespoke fittings.</p>
            <div style='text-align: center; margin: 24px 0;'>
                <a href='" . SITE_URL . "/pages/login.php' class='btn-luxury'>Sign In to Your Profile &rarr;</a>
            </div>
            <p>If you have any questions regarding your account or need styling guidance, our concierge team is always at your service.</p>
        ";

        $html = $this->wrapLuxuryTemplate("Welcome to DIEVON", "Private Member Account", $body);
        return $this->sendMail($email, $name, "Welcome to DIEVON Atelier", $html, 'welcome');
    }

    // ============================================================
    //  2. FORGOT PASSWORD RESET LINK EMAIL
    // ============================================================
    public function sendPasswordResetEmail(string $email, string $name, string $resetUrl): bool {
        $safeName = htmlspecialchars($name);
        $safeUrl = htmlspecialchars($resetUrl);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Password Reset Request</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>We received a request to reset the password for your DIEVON account (<code>" . htmlspecialchars($email) . "</code>).</p>
            <p>Click the button below to set a new password. This secure link is single-use and will expire in <strong>1 hour</strong>.</p>
            <div style='text-align: center; margin: 26px 0;'>
                <a href='{$safeUrl}' class='btn-luxury'>Reset Your Password &rarr;</a>
            </div>
            <p style='font-size: 12px; color: #7A7275;'>If the button above does not work, copy and paste the following link into your browser:<br>
            <a href='{$safeUrl}' style='color: #511126; word-break: break-all;'>{$safeUrl}</a></p>
            <div style='background: #FFF8EE; border: 1px solid #F5E5D0; padding: 12px 16px; border-radius: 6px; font-size: 12px; color: #8A6D3B; margin-top: 20px;'>
                🔒 <strong>Security Note:</strong> If you did not request a password reset, please ignore this email. Your current password remains completely safe and unchanged.
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Reset Password", "Security & Protection", $body);
        return $this->sendMail($email, $name, "Reset Your DIEVON Account Password", $html, 'password_reset');
    }

    // ============================================================
    //  3. PASSWORD CHANGED CONFIRMATION EMAIL
    // ============================================================
    public function sendPasswordChangedEmail(string $email, string $name): bool {
        $safeName = htmlspecialchars($name);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Password Updated</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>This is a security confirmation to let you know that your password for your DIEVON account has been successfully updated.</p>
            <div style='background: #F4FAF6; border: 1px solid #D1EAD8; padding: 14px 18px; border-radius: 6px; font-size: 13px; color: #1E6B37; margin: 18px 0;'>
                ✅ <strong>Security Alert:</strong> Your new password is now active.
            </div>
            <p>If you made this change, no further action is needed.</p>
            <p style='color: #A94442; font-weight: 600;'>⚠️ If you did NOT change your password, please contact our security team immediately at <a href='mailto:princevir2610@gmail.com' style='color: #A94442;'>princevir2610@gmail.com</a>.</p>
        ";

        $html = $this->wrapLuxuryTemplate("Password Changed", "Account Security Confirmation", $body);
        return $this->sendMail($email, $name, "Security Alert: Your DIEVON Password Was Updated", $html, 'password_changed');
    }

    // ============================================================
    //  4A. ORDER PLACED — CUSTOMER CONFIRMATION EMAIL
    // ============================================================
    public function sendOrderPlacedCustomerEmail(array $order): bool {
        $email = trim($order['customer_email'] ?? '');
        if (empty($email)) return false;

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
        $code = htmlspecialchars($order['order_code'] ?? '');
        $date = date('d M Y, H:i', strtotime($order['created_at'] ?? 'now'));
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);
        $sym = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $status = htmlspecialchars($order['status'] ?? 'Pending');
        $payMethod = htmlspecialchars(ucfirst($order['payment_method'] ?? 'Later'));
        $payStatus = htmlspecialchars(ucfirst($order['payment_status'] ?? 'Unpaid'));

        $items = json_decode($order['items_json'] ?? '[]', true) ?? [];
        $itemRows = '';
        foreach ($items as $it) {
            $name = htmlspecialchars($it['name'] ?? 'Product');
            $variant = !empty($it['variant_name']) ? "<br><span style='font-size:11px;color:#7A7275;'>Size: " . htmlspecialchars($it['variant_name']) . "</span>" : '';
            $qty = (int)($it['quantity'] ?? 1);
            $priceFmt = number_format((float)($it['price'] ?? 0), 2);
            $lineTotal = number_format($qty * (float)($it['price'] ?? 0), 2);

            $itemRows .= "
            <tr>
                <td><strong>{$name}</strong>{$variant}</td>
                <td style='text-align:center;'>{$qty}</td>
                <td style='text-align:right;'>{$sym}{$priceFmt}</td>
                <td style='text-align:right;'><strong>{$sym}{$lineTotal}</strong></td>
            </tr>";
        }

        $address = nl2br(htmlspecialchars($order['address'] ?? ''));

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Order Confirmation</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for your order with DIEVON. We have received your purchase and our atelier team is currently preparing your order with care.</p>

            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px; border-radius: 8px; margin: 18px 0;'>
                <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td><strong>Order Reference:</strong> <span style='color:#511126; font-weight:700;'>{$code}</span></td>
                        <td style='text-align:right;'><strong>Date:</strong> {$date}</td>
                    </tr>
                    <tr>
                        <td style='padding-top:8px;'><strong>Status:</strong> {$status}</td>
                        <td style='padding-top:8px; text-align:right;'><strong>Payment:</strong> {$payMethod} ({$payStatus})</td>
                    </tr>
                </table>
            </div>

            <table class='item-table'>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style='text-align:center;'>Qty</th>
                        <th style='text-align:right;'>Price</th>
                        <th style='text-align:right;'>Total</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemRows}
                </tbody>
            </table>

            <div style='text-align: right; font-size: 16px; font-weight: 700; color: #511126; margin-top: 12px;'>
                Order Total: {$sym}{$totalFmt}
            </div>

            <div style='margin-top: 24px; padding-top: 16px; border-top: 1px solid #EAE4DC;'>
                <strong style='color: #511126;'>📍 Delivery Address:</strong><br>
                <div style='margin-top: 4px; color: #555;'>{$address}</div>
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Order Confirmation #{$code}", "Order Confirmation", $body);
        return $this->sendMail($email, $safeName, "Your DIEVON Order Confirmation [{$code}]", $html, 'order_confirmation');
    }

    // ============================================================
    //  4B. ORDER PLACED — ADMIN NOTIFICATION EMAIL
    // ============================================================
    public function sendOrderPlacedAdminEmail(array $order): bool {
        $adminEmail = $this->adminAddress;
        if (empty($adminEmail)) return false;

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Customer');
        $custEmail = htmlspecialchars($order['customer_email'] ?? 'N/A');
        $phone = htmlspecialchars($order['phone'] ?? 'N/A');
        $code = htmlspecialchars($order['order_code'] ?? '');
        $date = date('d M Y, H:i', strtotime($order['created_at'] ?? 'now'));
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);
        $sym = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $status = htmlspecialchars($order['status'] ?? 'Pending');
        $payMethod = htmlspecialchars(ucfirst($order['payment_method'] ?? 'Later'));
        $payStatus = htmlspecialchars(ucfirst($order['payment_status'] ?? 'Unpaid'));

        $items = json_decode($order['items_json'] ?? '[]', true) ?? [];
        $itemRows = '';
        foreach ($items as $it) {
            $name = htmlspecialchars($it['name'] ?? 'Product');
            $variant = !empty($it['variant_name']) ? "<br><span style='font-size:11px;color:#7A7275;'>Size: " . htmlspecialchars($it['variant_name']) . "</span>" : '';
            $qty = (int)($it['quantity'] ?? 1);
            $priceFmt = number_format((float)($it['price'] ?? 0), 2);
            $lineTotal = number_format($qty * (float)($it['price'] ?? 0), 2);

            $itemRows .= "
            <tr>
                <td><strong>{$name}</strong>{$variant}</td>
                <td style='text-align:center;'>{$qty}</td>
                <td style='text-align:right;'>{$sym}{$priceFmt}</td>
                <td style='text-align:right;'><strong>{$sym}{$lineTotal}</strong></td>
            </tr>";
        }

        $address = nl2br(htmlspecialchars($order['address'] ?? ''));

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>New Order Received!</h2>
            <p>A new order has been placed on DIEVON Atelier.</p>

            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px; border-radius: 8px; margin: 18px 0;'>
                <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td><strong>Order Ref:</strong> <span style='color:#511126; font-weight:700;'>{$code}</span></td>
                        <td style='text-align:right;'><strong>Date:</strong> {$date}</td>
                    </tr>
                    <tr>
                        <td style='padding-top:6px;'><strong>Customer:</strong> {$safeName} ({$custEmail})</td>
                        <td style='padding-top:6px; text-align:right;'><strong>Phone:</strong> {$phone}</td>
                    </tr>
                    <tr>
                        <td style='padding-top:6px;'><strong>Status:</strong> {$status}</td>
                        <td style='padding-top:6px; text-align:right;'><strong>Payment:</strong> {$payMethod} ({$payStatus})</td>
                    </tr>
                </table>
            </div>

            <table class='item-table'>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style='text-align:center;'>Qty</th>
                        <th style='text-align:right;'>Price</th>
                        <th style='text-align:right;'>Total</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemRows}
                </tbody>
            </table>

            <div style='text-align: right; font-size: 16px; font-weight: 700; color: #511126; margin-top: 12px;'>
                Total: {$sym}{$totalFmt}
            </div>

            <div style='margin-top: 20px;'>
                <strong>📍 Delivery Address:</strong><br>
                <div style='margin-top: 4px; color: #555;'>{$address}</div>
            </div>

            <div style='text-align: center; margin-top: 24px;'>
                <a href='" . SITE_URL . "/admin/orders.php' class='btn-luxury'>View Order in Admin Dashboard &rarr;</a>
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("New Order #{$code}", "Admin Order Notification", $body);
        return $this->sendMail($adminEmail, "DIEVON Admin", "🚨 NEW ORDER {$code} – {$sym}{$totalFmt} [{$status}]", $html, 'admin_order_notification');
    }

    // ============================================================
    //  5. ORDER STATUS UPDATES EMAIL (Duplicate Prevention Enforced)
    // ============================================================
    public function sendOrderStatusEmail(array $order, string $newStatus, string $trackingNum = '', string $carrier = ''): bool {
        $email = trim($order['customer_email'] ?? '');
        if (empty($email)) return false;

        $lastNotified = trim($order['last_notified_status'] ?? '');
        if (strtolower($lastNotified) === strtolower($newStatus)) {
            // Duplicate prevention: status has not changed
            return false;
        }

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
        $code = htmlspecialchars($order['order_code'] ?? '');
        $safeStatus = htmlspecialchars($newStatus);
        $sym = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);

        $statusColor = '#511126';
        $statusIcon = '📦';
        $statusMessage = 'Your order status has been updated.';

        switch (strtolower($newStatus)) {
            case 'processing':
                $statusIcon = '⚙️';
                $statusMessage = 'Your order is currently being prepared and quality-checked by our master craftsmen.';
                break;
            case 'shipped':
            case 'dispatched':
                $statusIcon = '🚚';
                $statusMessage = 'Great news! Your package has been dispatched and is on its way to you.';
                break;
            case 'delivered':
            case 'completed':
                $statusIcon = '✨';
                $statusColor = '#1E6B37';
                $statusMessage = 'Your order has been successfully delivered. We hope you adore your new DIEVON piece!';
                break;
            case 'cancelled':
                $statusIcon = '❌';
                $statusColor = '#A94442';
                $statusMessage = 'Your order has been cancelled as requested or due to inventory adjustment.';
                break;
            case 'refunded':
                $statusIcon = '💳';
                $statusColor = '#8A6D3B';
                $statusMessage = 'A refund has been processed for your order.';
                break;
        }

        $trackingHtml = '';
        if (!empty($trackingNum)) {
            $safeTracking = htmlspecialchars($trackingNum);
            $safeCarrier = htmlspecialchars($carrier ?: 'Standard Courier');
            $trackingHtml = "
            <div style='background: #FFF9F0; border: 1px dashed #D4AF37; padding: 14px 18px; border-radius: 6px; margin: 18px 0;'>
                <strong>🚚 Tracking Information:</strong><br>
                Carrier: <strong>{$safeCarrier}</strong><br>
                Tracking Code: <strong style='color:#511126;'>{$safeTracking}</strong>
            </div>";
        }

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: {$statusColor}; margin-top: 0;'>{$statusIcon} Order Status Update</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>The status of your DIEVON order <strong>#{$code}</strong> has been updated to: <span style='font-size:16px; font-weight:700; color:{$statusColor};'>{$safeStatus}</span>.</p>

            <div style='background: #FAF8F5; border-left: 4px solid {$statusColor}; padding: 14px 18px; margin: 18px 0;'>
                {$statusMessage}
            </div>

            {$trackingHtml}

            <p style='margin-top: 20px;'><strong>Order Summary:</strong><br>
            Reference: <strong>#{$code}</strong><br>
            Total: <strong>{$sym}{$totalFmt}</strong></p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/pages/account.php' class='btn-luxury'>Track Order in Account &rarr;</a>
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Order Status Update #{$code}", "Order Status Update", $body);
        $sent = $this->sendMail($email, $safeName, "Order Status Update: #{$code} is now {$safeStatus}", $html, 'order_status_update');

        if ($sent && $this->pdo && !empty($order['id'])) {
            try {
                $upd = $this->pdo->prepare("UPDATE orders SET last_notified_status = :st WHERE id = :id");
                $upd->execute(['st' => $newStatus, 'id' => $order['id']]);
            } catch (\PDOException $ex) {}
        }

        return $sent;
    }

    // ============================================================
    //  6. CONTACT FORM EMAILS (Admin Notification + Customer Acknowledgement)
    // ============================================================
    public function sendContactFormEmails(string $name, string $email, string $phone, string $subject, string $message): bool {
        $safeName = htmlspecialchars($name);
        $safeEmail = htmlspecialchars($email);
        $safePhone = htmlspecialchars($phone ?: 'N/A');
        $safeSubj = htmlspecialchars($subject ?: 'General Enquiry');
        $safeMsg = nl2br(htmlspecialchars($message));

        // 1. Admin Notification Email
        $adminBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>New Contact Form Submission</h2>
            <table width='100%' cellpadding='8' cellspacing='0' style='border-collapse: collapse; margin-bottom: 20px;'>
                <tr><td style='width:120px; font-weight:700;'>Name:</td><td>{$safeName}</td></tr>
                <tr><td style='font-weight:700;'>Email:</td><td><a href='mailto:{$safeEmail}' style='color:#511126;'>{$safeEmail}</a></td></tr>
                <tr><td style='font-weight:700;'>Phone:</td><td>{$safePhone}</td></tr>
                <tr><td style='font-weight:700;'>Subject:</td><td>{$safeSubj}</td></tr>
            </table>
            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px; border-radius: 6px;'>
                <strong>Message:</strong><br>
                {$safeMsg}
            </div>
        ";
        $adminHtml = $this->wrapLuxuryTemplate("Contact Form Enquiry", "Admin Enquiry Alert", $adminBody);
        $this->sendMail($this->adminAddress, "DIEVON Concierge", "📬 Contact Form: {$safeSubj} from {$safeName}", $adminHtml, 'contact_admin_notice');

        // 2. Customer Acknowledgement Email
        $custBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Thank You for Contacting DIEVON</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for reaching out to DIEVON. We have received your inquiry regarding <strong>{$safeSubj}</strong>.</p>
            <p>Our concierge styling team is reviewing your message and will respond within 24 business hours.</p>
            <div style='background: #FAF8F5; border-left: 3px solid #511126; padding: 14px 18px; margin: 18px 0; font-size: 13px;'>
                <strong>Summary of your message:</strong><br>
                {$safeMsg}
            </div>
        ";
        $custHtml = $this->wrapLuxuryTemplate("Inquiry Received", "Customer Service Acknowledgement", $custBody);
        return $this->sendMail($email, $name, "We Have Received Your Inquiry — DIEVON Atelier", $custHtml, 'contact_customer_ack');
    }

    // ============================================================
    //  7. PRODUCT ENQUIRY EMAILS (Admin Notification + Customer Ack)
    // ============================================================
    public function sendProductEnquiryEmails(string $name, string $email, string $phone, string $productName, string $productUrl, string $message): bool {
        $safeName = htmlspecialchars($name);
        $safeEmail = htmlspecialchars($email);
        $safePhone = htmlspecialchars($phone ?: 'N/A');
        $safeProd = htmlspecialchars($productName);
        $safeUrl = htmlspecialchars($productUrl);
        $safeMsg = nl2br(htmlspecialchars($message));

        // 1. Admin Notification
        $adminBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>New Product Enquiry</h2>
            <p>A customer has requested details for product: <strong>{$safeProd}</strong></p>
            <table width='100%' cellpadding='8' cellspacing='0' style='border-collapse: collapse; margin-bottom: 20px;'>
                <tr><td style='width:120px; font-weight:700;'>Product:</td><td><a href='{$safeUrl}' style='color:#511126; font-weight:700;'>{$safeProd}</a></td></tr>
                <tr><td style='font-weight:700;'>Customer:</td><td>{$safeName}</td></tr>
                <tr><td style='font-weight:700;'>Email:</td><td><a href='mailto:{$safeEmail}' style='color:#511126;'>{$safeEmail}</a></td></tr>
                <tr><td style='font-weight:700;'>Phone:</td><td>{$safePhone}</td></tr>
            </table>
            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px; border-radius: 6px;'>
                <strong>Enquiry / Fitting Request:</strong><br>
                {$safeMsg}
            </div>
            <div style='text-align: center; margin-top: 20px;'>
                <a href='{$safeUrl}' class='btn-luxury'>View Product Details &rarr;</a>
            </div>
        ";
        $adminHtml = $this->wrapLuxuryTemplate("Product Enquiry: {$safeProd}", "Admin Product Alert", $adminBody);
        $this->sendMail($this->adminAddress, "DIEVON Concierge", "👗 Product Enquiry: {$safeProd} from {$safeName}", $adminHtml, 'product_enquiry_admin');

        // 2. Customer Acknowledgement
        $custBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Product Inquiry Received</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for inquiring about <strong>{$safeProd}</strong>.</p>
            <p>Our personal styling team will review your inquiry and get back to you with custom fitting and availability details shortly.</p>
            <div style='background: #FAF8F5; border-left: 3px solid #D4AF37; padding: 14px 18px; margin: 18px 0; font-size: 13px;'>
                <strong>Enquiry Reference:</strong> {$safeProd}<br>
                <strong>Message:</strong><br>
                {$safeMsg}
            </div>
            <div style='text-align: center; margin-top: 20px;'>
                <a href='{$safeUrl}' class='btn-luxury'>View {$safeProd} Again &rarr;</a>
            </div>
        ";
        $custHtml = $this->wrapLuxuryTemplate("Inquiry Received: {$safeProd}", "Styling Concierge Acknowledgement", $custBody);
        return $this->sendMail($email, $name, "Product Enquiry Confirmation: {$safeProd} — DIEVON Atelier", $custHtml, 'product_enquiry_customer_ack');
    }
}
