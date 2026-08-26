<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Redirect if already logged in
if (isset($_SESSION['customer_id'])) {
    header('Location: ' . SITE_URL . '/account');
    exit;
}

$error = '';
$success = '';
$resetUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rejected unless the form this came from was one we served. Without this
    // another site could submit it silently using the visitor's browser.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {

    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Rate limiting: 3 reset requests per email per 15 minutes.
            //
            // This called jsonResp(), which is defined in actions/customer_action.php
            // and does NOT exist in this file — so the moment the limit was reached
            // the page fatalled with "undefined function" instead of rate limiting.
            // The check has therefore never actually worked. This page renders HTML,
            // not JSON, so it sets $error like every other branch here.
            $rateStmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE LOWER(email) = :email AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $rateStmt->execute(['email' => $email]);
            $overLimit = ((int)$rateStmt->fetchColumn() >= 3);

            if ($overLimit) {
                $error = 'Too many password reset requests. Please wait 15 minutes before trying again.';
            } else {
            $stmt = $pdo->prepare("SELECT id, name FROM customers WHERE LOWER(email) = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate secure random token; store SHA-256 hash in database
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);

                // Previous links are invalidated by BLANKING the token, not by
                // deleting the row.
                //
                // This was "DELETE FROM password_resets WHERE LOWER(email) = :email",
                // which wiped the exact rows the rate check above counts. Every
                // request removed the evidence of the last one, so the count was
                // never more than 1 and the "3 per 15 minutes" limit could not fire
                // — measured: five requests in a row, all accepted, five emails.
                // That is an unlimited mail cannon pointed at any registered address,
                // and it burns the sending domain's reputation along with the quota.
                //
                // A blanked token can never match: reset_password.php looks the row
                // up by sha256 hash, and '' is not one. So old links die exactly as
                // they did before, while the row survives to be counted.
                $inv = $pdo->prepare("UPDATE password_resets SET token = '' WHERE LOWER(email) = :email");
                $inv->execute(['email' => $email]);

                // Rows older than the rate window are of no further use to anyone —
                // dropped here so the table does not grow without bound.
                $pdo->prepare("DELETE FROM password_resets WHERE created_at <= (NOW() - INTERVAL 15 MINUTE)")
                    ->execute();

                // expires_at is computed by MYSQL, not by PHP.
                //
                // It used to be date('Y-m-d H:i:s', strtotime('+1 hour')) while
                // created_at was NOW(). With PHP an hour behind MySQL here, the two
                // columns came out IDENTICAL — a token that read as expiring the
                // instant it was created. It only worked because each column was
                // later compared against the clock that wrote it.
                //
                // Had the offset gone the other way, every reset link would have
                // been born already expired. One clock removes the whole class of
                // bug.
                $ins = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at, created_at)
                                      VALUES (:email, :token, NOW() + INTERVAL 1 HOUR, NOW())");
                $ins->execute([
                    'email' => $email,
                    'token' => $tokenHash
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

                // Identical wording whether or not the account exists. The two
                // branches previously said different things — "sent to your email"
                // versus "if an account exists" — which told an attacker exactly
                // which addresses are registered, defeating the stated intent.
                $success = "If an account exists for that address, a password reset link has been sent.";
            } else {
                $success = "If an account exists for that address, a password reset link has been sent.";
            }
            } // end !$overLimit
        } catch (PDOException $e) {
            $error = "Error generating reset link. Please try again.";
        }
    }

    }
}

$pageTitle = "Forgot Password | Dievon";
require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container" style="max-width: 480px; margin: 0 auto;">
        <div class="auth-card-box reveal-on-scroll">
            <span class="editorial-label">Account Security</span>
            <h1 class="auth-title" style="font-size: 24px;">Forgot Password</h1>
            <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 25px;">Enter your email address to receive a secure password reset link.</p>

            <?php if ($error): ?>
                <?= dvNotice(htmlspecialchars($error), 'danger') ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom: 25px; line-height: 1.6">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                    <?php // The reset link is NEVER printed here.
                          //
                          // A "Direct Reset Link" block used to render $resetUrl on this page,
                          // on the public, unauthenticated response to the form. Typing any
                          // customer's address into it returned a live one-hour token for that
                          // account — their saved addresses and order history, taken over by
                          // anyone who knew an email address and never touched an inbox.
                          //
                          // It also quietly undid the account-enumeration protection a few
                          // lines below: both branches print identical wording on purpose, but
                          // only a real account produced the link box, so the box itself
                          // answered the question the wording refuses to answer.
                          //
                          // $resetUrl is still built above, because line 94 passes it to
                          // sendPasswordResetEmail() — the inbox is the only place it belongs. ?>
                </div>
            <?php endif; ?>

            <form action="forgot_password.php" method="POST" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <div class="form-luxury-group">
                    <label for="resetEmail">Registered Email Address *</label>
                    <input type="email" id="resetEmail" name="email" class="form-luxury-input" required placeholder="you@example.com">
                </div>

                <button type="submit" class="btn-luxury auth-submit-btn" style="width: 100%; margin-top: 10px;">
                    Send Reset Link
                </button>
            </form>

            <p class="auth-footer-text" style="margin-top: 25px;">
                Remembered your password? <a href="<?= SITE_URL ?>/login.php">Back to Sign In</a>
            </p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
