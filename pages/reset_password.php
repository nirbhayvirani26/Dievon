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
        // Only the HASH is accepted. This previously also matched `token = :raw`,
        // which quietly undid the point of hashing: anyone who could read the
        // password_resets table held the stored hash, and passing that hash as the
        // URL token satisfied the raw comparison — letting them reset any account.
        // Storing a hash only helps if the raw value is the sole accepted key.
        // Expiry is judged BY MYSQL against its own NOW(), matching how
        // forgot_password.php writes expires_at. Reading the column into PHP and
        // comparing with time() mixed two clocks that were an hour apart here —
        // the "5-minute skew buffer" below was a symptom of that, not a fix.
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            "SELECT *, (expires_at >= NOW()) AS still_valid
               FROM password_resets
              WHERE LOWER(email) = :email AND token = :hash"
        );
        $stmt->execute(['email' => strtolower($email), 'hash' => $tokenHash]);
        $row = $stmt->fetch();
        if ($row) {
            if ((int)$row['still_valid'] === 1) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $error = 'Your session expired. Please open the link from your email again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $newPassword = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    // strlen() counted BYTES, so a 6-character passphrase using any non-ASCII
    // character passed a check meant to require 6. validatePasswordStrength()
    // uses mb_strlen and the shop-wide minimum.
    $pwError = validatePasswordStrength($newPassword);

    if ($pwError !== null) {
        $error = $pwError;
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match. Please enter identical passwords.";
    } else {
        try {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE customers SET password = :password WHERE LOWER(email) = :email");
            $upd->execute(['password' => $hashed, 'email' => strtolower($email)]);

            // Fetch customer name for confirmation email
            $cStmt = $pdo->prepare("SELECT id, name FROM customers WHERE LOWER(email) = :email");
            $cStmt->execute(['email' => strtolower($email)]);
            $cRow  = $cStmt->fetch();
            $cName = $cRow['name'] ?? 'Valued Customer';

            // A reset is the one moment we must assume the account was taken
            // over. Any "keep me signed in" cookie the attacker holds dies here;
            // leaving them alive would make the reset cosmetic.
            if ($cRow) {
                require_once __DIR__ . '/../includes/remember_me.php';
                rememberMeForgetAll($pdo, (int)$cRow['id']);
            }

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

$pageTitle = "Reset Password | Dievon";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container" style="max-width: 480px; margin: 0 auto;">
        <div class="auth-card-box reveal-on-scroll">
            <span class="editorial-label">Account Recovery</span>
            <h1 class="auth-title" style="font-size: 24px;">Reset Password</h1>

            <?php if ($error): ?>
                <?= dvNotice(htmlspecialchars($error), 'danger') ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <?php /* Same flex-squeeze as pages/verify_email.php — .alert is display:flex,
                         so the icon, the message and this button's wrapper were three
                         columns and the button got whatever width was left. Wrapped as one
                         item so the button sits under the message at full width. */ ?>
                <div class="alert alert-success" style="margin-bottom: 25px; line-height: 1.6">
                    <i class="fa-solid fa-circle-check" style="margin-top: 3px;"></i>
                    <div style="flex: 1; min-width: 0;">
                        <?= htmlspecialchars($success) ?>
                        <div style="margin-top: 15px;">
                            <a href="<?= SITE_URL ?>/login.php" class="btn-luxury" style="display: block; text-align: center; font-size: 12px; padding: 10px;">Sign In Now</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($validToken): ?>
                <form action="reset_password.php?token=<?= urlencode($token) ?>&email=<?= urlencode($email) ?>" method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <div class="form-luxury-group">
                        <label for="resetPass">New Password *</label>
                        <input type="password" id="resetPass" name="password" class="form-luxury-input" required data-toggle-visibility minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
                        <div class="pw-hint">A few words together works well &mdash; length matters more than symbols.</div>
                    </div>

                    <div class="form-luxury-group">
                        <label for="resetConfirm">Confirm New Password *</label>
                        <input type="password" id="resetConfirm" name="confirm_password" class="form-luxury-input" required data-toggle-visibility placeholder="Re-enter password">
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
