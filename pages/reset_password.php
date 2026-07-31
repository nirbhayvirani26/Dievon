<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$token = trim($_GET['token'] ?? '');
$email = trim($_GET['email'] ?? '');
$error = '';
$success = '';
$validToken = false;

if ($token && $email) {
    try {
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE LOWER(email) = :email AND (token = :hash OR token = :raw)");
        $stmt->execute(['email' => strtolower($email), 'hash' => $tokenHash, 'raw' => $token]);
        $row = $stmt->fetch();
        if ($row) {
            if (strtotime($row['expires_at']) >= time() - 300) { // Allows 5-min clock skew buffer
                $validToken = true;
            } else {
                $error = "This password reset link has expired. Please request a new link.";
            }
        } else {
            $error = "This password reset link is invalid or has expired. Please request a new link.";
        }
    } catch (PDOException $e) {
        $error = "Database validation error.";
    }
} else {
    $error = "Invalid password reset request.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $newPassword = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match. Please enter identical passwords.";
    } else {
        try {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE customers SET password = :password WHERE LOWER(email) = :email");
            $upd->execute(['password' => $hashed, 'email' => strtolower($email)]);

            // Fetch customer name for confirmation email
            $cStmt = $pdo->prepare("SELECT name FROM customers WHERE LOWER(email) = :email");
            $cStmt->execute(['email' => strtolower($email)]);
            $cName = $cStmt->fetchColumn() ?: 'Valued Customer';

            // Single-use token: delete token after successful reset
            $del = $pdo->prepare("DELETE FROM password_resets WHERE LOWER(email) = :email");
            $del->execute(['email' => strtolower($email)]);

            // Send Password Changed Security Email
            try {
                require_once __DIR__ . '/../services/EmailService.php';
                $emailService = new EmailService($pdo);
                $emailService->sendPasswordChangedEmail($email, $cName);
            } catch (\Throwable $exEmail) {
                error_log("Password changed email error: " . $exEmail->getMessage());
            }

            // Security: Invalidate any active sessions after password reset
            unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email']);

            $success = "Your password has been successfully updated! You can now sign in with your new password.";
            $validToken = false;
        } catch (PDOException $e) {
            $error = "Error updating password. Please try again.";
        }
    }
}

$pageTitle = "Reset Password | Dievon Atelier";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container" style="max-width: 480px; margin: 0 auto;">
        <div class="auth-card-box reveal-on-scroll">
            <span class="editorial-label">Account Recovery</span>
            <h1 class="auth-title" style="font-size: 24px;">Reset Password</h1>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div style="padding: 16px; background: #e6faf7; color: #2e7d32; border: 1px solid #c3e6cb; border-radius: 6px; font-size: 13px; margin-bottom: 25px;">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                    <div style="margin-top: 15px;">
                        <a href="<?= SITE_URL ?>/login.php" class="btn-luxury" style="display: block; text-align: center; font-size: 12px; padding: 10px;">Sign In Now</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($validToken): ?>
                <form action="reset_password.php?token=<?= urlencode($token) ?>&email=<?= urlencode($email) ?>" method="POST" class="auth-form">
                    <div class="form-luxury-group">
                        <label for="resetPass">New Password *</label>
                        <input type="password" id="resetPass" name="password" class="form-luxury-input" required placeholder="Minimum 6 characters">
                    </div>

                    <div class="form-luxury-group">
                        <label for="resetConfirm">Confirm New Password *</label>
                        <input type="password" id="resetConfirm" name="confirm_password" class="form-luxury-input" required placeholder="Re-enter password">
                    </div>

                    <button type="submit" class="btn-luxury auth-submit-btn" style="width: 100%; margin-top: 10px;">
                        Update Password
                    </button>
                </form>
            <?php elseif (!$success): ?>
                <div style="margin-top: 20px;">
                    <a href="<?= SITE_URL ?>/forgot_password.php" class="btn-luxury-outline" style="display: block; text-align: center; font-size: 12px; padding: 10px;">Request New Reset Link</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
