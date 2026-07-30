<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Redirect if already logged in
if (isset($_SESSION['customer_id'])) {
    header('Location: account.php');
    exit;
}

$error = '';
$success = '';
$resetUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Rate limiting check: 3 reset requests per email per 15 minutes
            $rateStmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE LOWER(email) = :email AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $rateStmt->execute(['email' => $email]);
            if ((int)$rateStmt->fetchColumn() >= 3) {
                jsonResp(false, 'Too many password reset requests. Please wait 15 minutes before trying again.');
            }

            $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE LOWER(email) = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate secure random token; store SHA-256 hash in database
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $del = $pdo->prepare("DELETE FROM password_resets WHERE LOWER(email) = :email");
                $del->execute(['email' => $email]);

                $ins = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at, created_at) VALUES (:email, :token, :expires, NOW())");
                $ins->execute([
                    'email' => $email,
                    'token' => $tokenHash,
                    'expires' => $expiresAt
                ]);

                $resetUrl = SITE_URL . "/pages/reset_password.php?token=" . urlencode($rawToken) . "&email=" . urlencode($email);

                // Send Password Reset Email
                try {
                    require_once __DIR__ . '/../services/EmailService.php';
                    $emailService = new EmailService($pdo);
                    $emailService->sendPasswordResetEmail($email, $user['name'] ?? 'Valued Customer', $resetUrl);
                } catch (\Throwable $exEmail) {
                    error_log("Password reset email error: " . $exEmail->getMessage());
                }

                $success = "A password reset link has been generated and sent to your email.";
            } else {
                // Security best practice: generic response prevents account enumeration
                $success = "If an account exists for " . htmlspecialchars($email) . ", a password reset link has been generated.";
            }
        } catch (PDOException $e) {
            $error = "Error generating reset link. Please try again.";
        }
    }
}

$pageTitle = "Forgot Password | Dievon Atelier";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container" style="max-width: 480px; margin: 0 auto;">
        <div class="auth-card-box reveal-on-scroll">
            <span class="editorial-label">Account Security</span>
            <h1 class="auth-title" style="font-size: 24px;">Forgot Password</h1>
            <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 25px;">Enter your email address to receive a secure password reset link.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div style="padding: 16px; background: #e6faf7; color: #2e7d32; border: 1px solid #c3e6cb; border-radius: 6px; font-size: 13px; margin-bottom: 25px; line-height: 1.6;">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                    <?php if ($resetUrl): ?>
                        <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed #a3e0d5;">
                            <strong>Direct Reset Link:</strong><br>
                            <a href="<?= htmlspecialchars($resetUrl) ?>" style="word-break: break-all; color: var(--color-primary); font-weight: 700; text-decoration: underline;">
                                <?= htmlspecialchars($resetUrl) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form action="forgot_password.php" method="POST" class="auth-form">
                <div class="form-luxury-group">
                    <label for="resetEmail">Registered Email Address *</label>
                    <input type="email" id="resetEmail" name="email" class="form-luxury-input" required placeholder="you@example.com">
                </div>

                <button type="submit" class="btn-luxury auth-submit-btn" style="width: 100%; margin-top: 10px;">
                    Send Reset Link
                </button>
            </form>

            <p class="auth-footer-text" style="margin-top: 25px;">
                Remembered your password? <a href="login.php">Back to Sign In</a>
            </p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
