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
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name && $email && $password) {
        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(email) = :email");
            $chk->execute(['email' => $email]);
            if ((int)$chk->fetchColumn() > 0) {
                $error = "An account with this email address already exists.";
            } else {
                $hashedPass = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO customers (name, email, password, phone, address) VALUES (:name, :email, :password, :phone, :address)");
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'password' => $hashedPass,
                    'phone' => $phone,
                    'address' => $address ?: null
                ]);

                $customerId = $pdo->lastInsertId();
                $_SESSION['customer_id'] = $customerId;
                $_SESSION['customer_name'] = $name;
                $_SESSION['customer_email'] = $email;

                // Send Welcome Email
                try {
                    require_once __DIR__ . '/../services/EmailService.php';
                    $emailService = new EmailService($pdo);
                    $emailService->sendWelcomeEmail($email, $name);
                } catch (\Throwable $exEmail) {
                    error_log("Welcome email error: " . $exEmail->getMessage());
                }

                // Guest Order Auto-Linking
                try {
                    // Compare on the last 10 digits so "+44 7879 092355" and "07879092355"
                    // (same UK number, different country-code prefix) are treated as equal.
                    $normalizedPhone = substr(preg_replace('/\D+/', '', $phone), -10);
                    if (!empty($email)) {
                        $linkStmt = $pdo->prepare("UPDATE orders SET customer_id = :cid WHERE (customer_id IS NULL OR customer_id = 0) AND LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :cemail");
                        $linkStmt->execute([
                            'cid' => $customerId,
                            'cemail' => strtolower($email),
                        ]);
                    }
                    if (!empty($normalizedPhone)) {
                        $phoneCount = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone");
                        $phoneCount->execute(['phone' => $normalizedPhone]);
                        if ((int)$phoneCount->fetchColumn() === 1) {
                            $linkStmt = $pdo->prepare("UPDATE orders SET customer_id = :cid, customer_email = CASE WHEN customer_email = '' OR customer_email IS NULL THEN :cemail ELSE customer_email END WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone AND (customer_id IS NULL OR customer_id = 0)");
                            $linkStmt->execute([
                                'cid' => $customerId,
                                'phone' => $normalizedPhone,
                                'cemail' => strtolower($email),
                            ]);
                        }
                    }
                } catch (PDOException $exLink) { error_log('Register order-link error: ' . $exLink->getMessage()); }

                header('Location: account.php?registered=1');
                exit;
            }
        } catch (PDOException $e) {
            $error = "Database registration error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields (Name, Email, and Password).";
    }
}

$pageTitle = "Register Account";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Member Registration Page (560px Clean Class Layout) ═══════════════════════════ -->
<section class="auth-section">
    <div class="auth-container">
        
        <div class="auth-card-box reveal-on-scroll">
            <div style="text-align: center; margin-bottom: 22px;">
                <span class="editorial-label">Join Dievon</span>
                <h1 class="auth-title">Create Profile</h1>
                <p style="color: var(--text-muted); font-size: 13px; margin: 0;">Enjoy express delivery and VIP atelier fittings.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" style="text-align: left; margin-bottom: 20px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" class="auth-form">
                <div class="form-luxury-group">
                    <label for="regName">Full Name *</label>
                    <input type="text" id="regName" name="name" class="form-luxury-input" required placeholder="Eleanor Vance">
                </div>

                <div class="form-luxury-group">
                    <label for="regEmail">Email Address *</label>
                    <input type="email" id="regEmail" name="email" class="form-luxury-input" required placeholder="eleanor@example.com">
                </div>

                <div class="form-luxury-group">
                    <label for="regPassword">Password *</label>
                    <input type="password" id="regPassword" name="password" class="form-luxury-input" required placeholder="Min 6 characters">
                </div>

                <div class="form-luxury-group">
                    <label for="regPhone">Phone Number (optional)</label>
                    <input type="tel" id="regPhone" name="phone" class="form-luxury-input" placeholder="+44 7700 900 123">
                </div>

                <button type="submit" class="btn-luxury auth-submit-btn" style="margin-top: 10px;">
                    Create Member Profile
                </button>
            </form>

            <!-- Login Link -->
            <p class="auth-footer-text">
                Already a member? <a href="<?= SITE_URL ?>/login">Sign In here</a>
            </p>

        </div>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
