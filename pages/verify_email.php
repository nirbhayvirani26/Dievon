<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Email verification gate for guest-order linking.
//
// Registering with a guest's email used to hand over that guest's entire
// order history on the spot — no proof the address belonged to the person
// typing it. Now every new account starts unverified, and this page is the
// one place a customer proves they own the address:
//
//   pages/verify_email.php?token=…&email=…   the link from the email
//   pages/verify_email.php?sent=1&email=…    \"check your inbox\" (after register/resend)
//   pages/verify_email.php?resend=1&email=…  offer to resend the link
//
// Guest orders are linked to the account at the moment of verification —
// the only time an email match is proof of anything.

$error      = '';
$success    = '';
// Method-aware read: on a POST the SUBMITTED address must win. Reading $_GET
// first meant a POST to a URL that still carried ?email=… (a stale bookmark
// of the resend link, or a forged cross-site submit) silently mailed the GET
// address instead of the one typed into the form.
$email      = strtolower(trim(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['email'] ?? '')
        : ($_GET['email'] ?? '')
));
$token      = trim($_GET['token'] ?? '');
$showSent   = false;

// ── RESEND (POST, rate-limited) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $st = $pdo->prepare("SELECT id, name, email_verified_at FROM customers WHERE LOWER(email) = :email");
            $st->execute(['email' => $email]);
            $c = $st->fetch();
            if (!$c) {
                // Same wording whether or not an account exists, so this cannot
                // be used to probe which addresses are registered.
                $showSent = true;
            } elseif (isCustomerEmailVerified($c)) {
                // Already verified: nothing to send — and the response must not
                // SAY so, or anyone could probe whether an address is registered
                // and verified. Same generic wording as every other outcome.
                $showSent = true;
            } else {
                $raw = issueEmailVerification($pdo, (int)$c['id']);
                if ($raw === null) {
                    $error = 'A verification email was sent very recently. Please wait a couple of minutes before requesting another.';
                } else {
                    $verifyUrl = SITE_URL . '/pages/verify_email.php?token=' . urlencode($raw) . '&email=' . urlencode($email);
                    try {
                        require_once __DIR__ . '/../services/EmailService.php';
                        $emailService = new EmailService($pdo);
                        $emailService->sendEmailVerificationEmail($email, $c['name'] ?? 'Valued Customer', $verifyUrl);
                    } catch (\Throwable $exEmail) {
                        error_log('Verification email error: ' . $exEmail->getMessage());
                    }
                    $success = 'A fresh verification link has been sent to ' . htmlspecialchars($email) . '.';
                    $showSent = true;
                }
            }
        } catch (PDOException $e) {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
// ── VERIFY (GET token link) ────────────────────────────────────────────
elseif ($token !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'This verification link is invalid. Please request a new one.';
    } else {
        try {
            // Expiry is judged BY MYSQL against its own NOW(), matching how the
            // token is written — mixing clocks is what broke the reset flow.
            $tokenHash = hash('sha256', $token);
            $st = $pdo->prepare(
                "SELECT id, name, email_verified_at, email_verify_token,
                        (email_verify_token_expires >= NOW()) AS still_valid
                   FROM customers
                  WHERE LOWER(email) = :email"
            );
            $st->execute(['email' => $email]);
            $c = $st->fetch();

            if (!$c) {
                // One wording for no account / wrong token / already verified /
                // replayed link, so the endpoint cannot be used to probe which
                // addresses are registered and verified. (Expired is separate
                // and tells the requester nothing they did not already know:
                // they had to hold a real token to be told that.)
                $error = 'This verification link is invalid or has already been used. Please request a new one.';
            } elseif (isCustomerEmailVerified($c) || !hash_equals((string)($c['email_verify_token'] ?? ''), $tokenHash)) {
                $error = 'This verification link is invalid or has already been used. Please request a new one.';
            } elseif ((int)$c['still_valid'] !== 1) {
                $error = 'This verification link has expired. Please request a new one.';
            } else {
                // Proved: only the person who can read this inbox could click
                // this link, because the token exists solely in the email. This
                // is the one place guest orders are linked by email.
                $pdo->prepare(
                    "UPDATE customers
                        SET email_verified_at = NOW(),
                            email_verify_token = NULL,
                            email_verify_token_expires = NULL,
                            email_verify_sent_at = NULL
                      WHERE id = :id"
                )->execute(['id' => (int)$c['id']]);

                linkGuestOrdersByEmail($pdo, (int)$c['id'], $email);

                $success = 'Your email address has been verified, and any guest orders placed with it are now linked to your account. You can sign in.';
            }
        } catch (PDOException $e) {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
// ── SENT / RESEND state (or the page opened cold) ──────────────────────
elseif (isset($_GET['sent']) || isset($_GET['resend']) || $email === '') {
    $showSent = true;
    if ($email === '' && isset($_GET['resend'])) {
        $error = 'Please enter the email address you registered with to resend the link.';
    }
}

$pageTitle = "Verify Email | Dievon";
// The token rides in the URL — keep the page out of search indexes.
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container" style="max-width: 480px; margin: 0 auto;">
        <div class="auth-card-box reveal-on-scroll">
            <span class="editorial-label">Account Security</span>
            <h1 class="auth-title" style="font-size: 24px;">Verify Your Email</h1>
            <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 25px;">
                Confirm your address to link any guest orders placed with it to your account.
            </p>

            <?php if ($error): ?>
                <?= dvNotice(htmlspecialchars($error), 'danger') ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <?php /* Message and button wrapped together as ONE flex item.
                         .alert is display:flex (style.css:1020), so every child becomes a
                         column — the icon, the loose text, and this button's wrapper all
                         sat side by side. The button was squeezed into the leftover strip
                         until "Sign In Now" broke across three lines and the message wrapped
                         to five. Two children only — icon, then everything else — puts the
                         button back under the text at full width where it was meant to be. */ ?>
                <div class="alert alert-success" style="margin-bottom: 25px; line-height: 1.6">
                    <i class="fa-solid fa-circle-check" style="margin-top: 3px;"></i>
                    <div style="flex: 1; min-width: 0;">
                        <?= $success ?>
                        <div style="margin-top: 15px;">
                            <a href="<?= SITE_URL ?>/login" class="btn-luxury" style="display: block; text-align: center; font-size: 12px; padding: 10px;">Sign In Now</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($showSent && $success === ''): ?>
                <div style="padding: 16px; background: #e6faf7; color: #2e7d32; border: 1px solid #c3e6cb; border-radius: 6px; font-size: 13px; margin-bottom: 25px; line-height: 1.6;">
                    <i class="fa-solid fa-envelope"></i> If an account exists for that address, a verification link has been sent. Check your inbox (and spam folder).
                </div>
            <?php endif; ?>

            <?php if ($success === ''): ?>
                <form action="verify_email.php" method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <div class="form-luxury-group">
                        <label for="verifyEmail">Registered Email Address *</label>
                        <input type="email" id="verifyEmail" name="email" class="form-luxury-input" required
                               value="<?= htmlspecialchars($email) ?>" placeholder="you@example.com">
                    </div>
                    <button type="submit" class="btn-luxury auth-submit-btn" style="width: 100%; margin-top: 10px;">
                        Resend Verification Link
                    </button>
                </form>
            <?php endif; ?>

            <p class="auth-footer-text" style="margin-top: 25px;">
                Remembered your password? <a href="<?= SITE_URL ?>/login">Back to Sign In</a>
            </p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
