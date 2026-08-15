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
    // Rejected unless the form this came from was one we served. Without this
    // another site could submit it silently using the visitor's browser.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // Registration previously hashed whatever was submitted — a one-character
    // password was accepted, because "Min 6 characters" was only placeholder
    // text in the input and no server-side rule existed.
    $pwError = ($name && $email && $password) ? validatePasswordStrength($password, $email, $name) : null;

    // The address has to be a real one.
    //
    // Nothing checked it: "not-an-email", "a@" and even "x y z" created real
    // accounts. Those customers can never verify their address, never reset a
    // password and never receive an order confirmation — and the shop cannot
    // tell them so, because there is nowhere to write to.
    $emailError = ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
        ? 'Please enter a valid email address.'
        : null;

    if ($emailError !== null) {
        $error = $emailError;
    } elseif ($pwError !== null) {
        $error = $pwError;
    } elseif ($name && $email && $password) {
        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(email) = :email");
            $chk->execute(['email' => $email]);

            // Phone was never checked, so the same number could register any
            // number of accounts — and because sign-in accepts a phone, only one
            // of them was ever reachable that way.
            $phoneTaken = false;
            if ($phone !== '') {
                $pChk = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE phone = :phone");
                $pChk->execute(['phone' => $phone]);
                $phoneTaken = ((int)$pChk->fetchColumn() > 0);
            }

            if ((int)$chk->fetchColumn() > 0) {
                $error = "An account with this email address already exists.";
            } elseif ($phoneTaken) {
                $error = "An account already uses that phone number. Please sign in, or register with a different number.";
            } else {
                $hashedPass = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO customers (name, email, password, phone, address) VALUES (:name, :email, :password, :phone, :address)");
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'password' => $hashedPass,
                    // A blank optional phone is stored as NULL, never ''.
                    //
                    // customers.phone carries a UNIQUE index (uniq_customer_phone),
                    // and '' is a value like any other — so the SECOND customer who
                    // left this optional field blank collided with the first and
                    // registration failed with a duplicate-key error for everyone
                    // thereafter. MySQL treats NULLs as distinct in a unique index,
                    // so any number of phone-less accounts coexist while the index
                    // still does its real job of preventing two accounts sharing a
                    // number. The field is labelled "(optional)" a few lines below
                    // and has no required attribute, so this is the intended input.
                    'phone' => ($phone !== '' ? $phone : null),
                    'address' => $address ?: null
                ]);

                $customerId = (int)$pdo->lastInsertId();

                // ── Email verification gate ─────────────────────────────────────
                // Registration used to claim every guest order placed with the
                // typed email — and the phone, if one was given — right here, with
                // no proof the address was theirs. An attacker who knew a guest's
                // email could register with it and instantly own that guest's
                // order history, name, address and invoices. This shop's orders
                // are mostly guest orders, so that was the rule, not the edge
                // case.
                //
                // Now the account starts UNVERIFIED. No orders are linked here at
                // all — email OR phone. A link is emailed to the address; guest
                // orders are linked only when the customer clicks it, because
                // only the person who can read the inbox can do that. Phone
                // numbers are never used to link or reveal orders: a phone is
                // not proof of identity, and nothing verifies it.
                $verifyUrl = '';
                $rawToken = issueEmailVerification($pdo, $customerId);
                if ($rawToken !== null) {
                    $verifyUrl = SITE_URL . '/pages/verify_email.php?token=' . urlencode($rawToken) . '&email=' . urlencode($email);
                }

                // Send Welcome Email
                try {
                    require_once __DIR__ . '/../services/EmailService.php';
                    $emailService = new EmailService($pdo);
                    $emailService->sendWelcomeEmail($email, $name);
                    if ($verifyUrl !== '') {
                        $emailService->sendEmailVerificationEmail($email, $name, $verifyUrl);
                    }
                } catch (\Throwable $exEmail) {
                    error_log("Welcome/verification email error: " . $exEmail->getMessage());
                }

                // Not signed in yet: the verification link is the key to the
                // account (and its guest orders), so the account is presented
                // after the address is proved.
                header('Location: verify_email.php?sent=1&email=' . urlencode($email));
                exit;
            }
        } catch (PDOException $e) {
            // The driver's message goes to the log, not to the visitor.
            //
            // This printed the raw exception on the page — "SQLSTATE[22001]:
            // String data, right truncated: 1406 Data too long for column..." —
            // which names tables and columns and tells anyone probing the form
            // exactly how the database is shaped. It is also meaningless to the
            // customer, who only needs to know it did not work.
            error_log('Registration failed: ' . $e->getMessage());
            $error = "Sorry, we could not create your account just now. Please check your details and try again.";
        }
    } else {
        $error = "Please fill in all required fields (Name, Email, and Password).";
    }

    }
}

$pageTitle = "Register Account";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "Create a Dievon account to track orders, save addresses and keep a wishlist.";
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
                <?= dvNotice(htmlspecialchars($error), 'danger') ?>
            <?php endif; ?>

            <form action="register.php" method="POST" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
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
                    <input type="password" id="regPassword" name="password" class="form-luxury-input" required data-toggle-visibility minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
                    <div class="pw-hint">Tip: a few words together &mdash; like 'silk garden morning' &mdash; is easier to remember and far harder to guess than a short password with symbols.</div>
                </div>

                <div class="form-luxury-group">
                    <label for="regPhone">Phone Number (optional)</label>
                    <input type="tel" id="regPhone" name="phone" class="form-luxury-input" placeholder="<?= htmlspecialchars(shopPhone()) ?>">
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
