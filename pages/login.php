<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// Redirect if already logged in
if (isset($_SESSION['customer_id'])) {
    header('Location: account.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = trim($_POST['email'] ?? '');
    $input = strtolower($rawInput);
    $password = trim($_POST['password'] ?? '');

    if ($rawInput !== '' && $password !== '') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE LOWER(email) = :input OR phone = :rawInput");
            $stmt->execute(['input' => $input, 'rawInput' => $rawInput]);
            $customer = $stmt->fetch();

            if ($customer) {
                if (password_verify($password, $customer['password'])) {
                    $_SESSION['customer_id'] = $customer['id'];
                    $_SESSION['customer_name'] = $customer['name'];
                    $_SESSION['customer_email'] = $customer['email'];

                    // Update last_login timestamp
                    try {
                        $upLast = $pdo->prepare("UPDATE customers SET last_login = NOW() WHERE id = :id");
                        $upLast->execute(['id' => $customer['id']]);
                    } catch (Exception $exLast) {}

                    // Guest Order Auto-Linking: associate unlinked past guest orders with matching email or unique normalized phone
                    try {
                        $normalizedPhone = preg_replace('/\D+/', '', $customer['phone'] ?? '');
                        if (!empty($customer['email'])) {
                            $linkStmt = $pdo->prepare("UPDATE orders SET customer_id = :cid WHERE (customer_id IS NULL OR customer_id = 0) AND LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :cemail");
                            $linkStmt->execute([
                                'cid' => $customer['id'],
                                'cemail' => strtolower($customer['email']),
                            ]);
                        }
                        if (!empty($normalizedPhone)) {
                            $phoneCount = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.','') = :phone");
                            $phoneCount->execute(['phone' => $normalizedPhone]);
                            if ((int)$phoneCount->fetchColumn() === 1) {
                                $linkStmt = $pdo->prepare("UPDATE orders SET customer_id = :cid, customer_email = CASE WHEN customer_email = '' OR customer_email IS NULL THEN :cemail ELSE customer_email END WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.','') = :phone AND (customer_id IS NULL OR customer_id = 0)");
                                $linkStmt->execute([
                                    'cid' => $customer['id'],
                                    'phone' => $normalizedPhone,
                                    'cemail' => strtolower($customer['email']),
                                ]);
                            }
                        }
                    } catch (PDOException $exLink) { error_log('Login order-link error: ' . $exLink->getMessage()); }

                    header('Location: account.php');
                    exit;
                } else {
                    error_log("[Auth Diagnostic] Failed password_verify for customer ID " . (int)$customer['id'] . ". DB Hash length: " . strlen($customer['password']) . ", Hash prefix: " . substr($customer['password'], 0, 7));
                    $error = "Invalid email/phone or password credentials.";
                }
            } else {
                error_log("[Auth Diagnostic] Customer lookup returned 0 rows for login input.");
                $error = "Invalid email/phone or password credentials.";
            }
        } catch (PDOException $e) {
            error_log("[Auth Diagnostic] PDOException during login: " . $e->getMessage());
            $error = "Error during sign in. Please try again.";
        }
    } else {
        $error = "Please enter both your email/phone and password.";
    }
}

$pageTitle = "Login";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Customer Login Page (560px Clean Class Layout) ════════════════════════ -->
<section class="auth-section">
    <div class="auth-container">
        
        <div class="auth-card-box reveal-on-scroll">
            <span class="editorial-label">Dievon Member</span>
            <h1 class="auth-title">Member Sign In</h1>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" style="text-align: left; margin-bottom: 20px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Social Login OAuth Buttons Structure (Independent Feature Flags) -->
            <?php if (defined('GOOGLE_CLIENT_ID') && !empty(GOOGLE_CLIENT_ID)): ?>
            <div style="margin-bottom: 10px;">
                <button type="button" onclick="window.location.href='oauth_google.php'" class="btn-luxury-outline" style="width:100%; display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 13px; font-weight: 600; padding: 12px; background: #ffffff; color: #1e293b; border-color: #cbd5e1;">
                    <i class="fa-brands fa-google" style="color: #ea4335; font-size: 16px;"></i> Continue with Google
                </button>
            </div>
            <?php endif; ?>

            <?php if (defined('FACEBOOK_APP_ID') && !empty(FACEBOOK_APP_ID)): ?>
            <div style="margin-bottom: 10px;">
                <button type="button" onclick="window.location.href='oauth_facebook.php'" class="btn-luxury-outline" style="width:100%; display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 13px; font-weight: 600; padding: 12px; background: #1877f2; color: #ffffff; border-color: #1877f2;">
                    <i class="fa-brands fa-facebook" style="font-size: 16px;"></i> Continue with Facebook
                </button>
            </div>
            <?php endif; ?>

            <?php if (defined('APPLE_CLIENT_ID') && !empty(APPLE_CLIENT_ID)): ?>
            <div style="margin-bottom: 10px;">
                <button type="button" onclick="window.location.href='oauth_apple.php'" class="btn-luxury-outline" style="width:100%; display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 13px; font-weight: 600; padding: 12px; background: #000000; color: #ffffff; border-color: #000000;">
                    <i class="fa-brands fa-apple" style="font-size: 16px;"></i> Continue with Apple
                </button>
            </div>
            <?php endif; ?>

            <?php if ((defined('GOOGLE_CLIENT_ID') && !empty(GOOGLE_CLIENT_ID)) || (defined('FACEBOOK_APP_ID') && !empty(FACEBOOK_APP_ID)) || (defined('APPLE_CLIENT_ID') && !empty(APPLE_CLIENT_ID))): ?>
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px; color: var(--text-muted); font-size: 12px;">
                <div style="flex: 1; height: 1px; background: var(--border-light);"></div>
                <span>OR SIGN IN WITH EMAIL / PHONE</span>
                <div style="flex: 1; height: 1px; background: var(--border-light);"></div>
            </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="auth-form">
                <div class="form-luxury-group">
                    <label for="loginEmail">Email Address or Phone Number *</label>
                    <input type="text" id="loginEmail" name="email" class="form-luxury-input" required placeholder="you@example.com or +44 7700 900 123">
                </div>
                
                <div class="form-luxury-group">
                    <label for="loginPassword">Password *</label>
                    <input type="password" id="loginPassword" name="password" class="form-luxury-input" required placeholder="••••••••">
                </div>

                <div class="auth-forgot-row">
                    <a href="<?= SITE_URL ?>/forgot_password.php">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-luxury auth-submit-btn">
                    Sign In
                </button>
            </form>

            <!-- Social Connectors Divider -->
            <div class="auth-divider">
                <hr>
                <span class="auth-divider-text">Or Connect With</span>
                <hr>
            </div>

            <!-- Social Logins -->
            <div class="auth-social-grid">
                <button type="button" onclick="alert('Google login initiated.');" class="auth-social-btn" aria-label="Sign in with Google"><i class="fa-brands fa-google"></i></button>
                <button type="button" onclick="alert('Apple login initiated.');" class="auth-social-btn" aria-label="Sign in with Apple"><i class="fa-brands fa-apple"></i></button>
                <button type="button" onclick="alert('Facebook login initiated.');" class="auth-social-btn" aria-label="Sign in with Facebook"><i class="fa-brands fa-facebook-f"></i></button>
            </div>

            <!-- Create Account Link -->
            <p class="auth-footer-text">
                New to Dievon? <a href="<?= SITE_URL ?>/register">Create account</a>
            </p>

            <div class="auth-admin-link">
                <a href="<?= SITE_URL ?>/admin/login.php"><i class="fa-solid fa-user-lock"></i> Atelier Admin Portal</a>
            </div>

        </div>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
