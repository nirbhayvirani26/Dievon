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
    // Rejected unless the form this came from was one we served. Without this
    // another site could submit it silently using the visitor's browser.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {

    $rawInput = trim($_POST['email'] ?? '');
    $input = strtolower($rawInput);
    $password = trim($_POST['password'] ?? '');

    // Throttle BEFORE touching the database. Checking first means a locked-out
    // attacker cannot use response timing to tell a real account from a fake one.
    $lockFor = loginLockRemaining($pdo, $rawInput);

    if ($lockFor > 0) {
        $error = 'Too many sign-in attempts. Please wait ' . humaniseSeconds($lockFor)
               . ' and try again, or reset your password.';
    } elseif ($rawInput !== '' && $password !== '') {
        try {
            // Email first, phone only as a fallback.
            //
            // The single OR query returned whichever row the engine happened to
            // produce first. Several accounts can legitimately share one phone
            // number — a household, or an owner with test accounts — and in that
            // case only ONE of them could ever sign in by phone; the others were
            // unreachable that way with no explanation.
            //
            // Email is unique, so it is tried first and is always unambiguous.
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE LOWER(email) = :input LIMIT 1");
            $stmt->execute(['input' => $input]);
            $customer = $stmt->fetch();

            if (!$customer) {
                $byPhone = $pdo->prepare("SELECT * FROM customers WHERE phone = :raw");
                $byPhone->execute(['raw' => $rawInput]);
                $phoneMatches = $byPhone->fetchAll();

                if (count($phoneMatches) === 1) {
                    $customer = $phoneMatches[0];
                } elseif (count($phoneMatches) > 1) {
                    // Ambiguous on purpose: say so rather than silently picking one.
                    recordFailedLogin($pdo, $rawInput);
                    $error = 'Several accounts use that phone number. Please sign in with your email address instead.';
                }
            }

            if ($customer) {
                if (password_verify($password, $customer['password'])) {
                    // Session fixation: an attacker who can set a visitor's session
                    // id before they sign in would otherwise still hold a valid
                    // session afterwards. Regenerating issues a fresh id at exactly
                    // the moment the session gains privileges.
                    session_regenerate_id(true);

                    // A clean slate on success, so earlier fumbled attempts do not
                    // count against the customer later in the day.
                    clearFailedLogins($pdo, $rawInput);

                    $_SESSION['customer_id'] = $customer['id'];
                    $_SESSION['customer_name'] = $customer['name'];
                    $_SESSION['customer_email'] = $customer['email'];

                    // This session was proven with a typed password, not restored
                    // from a cookie — see includes/remember_me.php.
                    $_SESSION['auth_via_remember'] = false;

                    // "Keep me signed in", only when it was actually ticked. Opt-in
                    // matters: on a shared or public machine the customer must be
                    // able to sign in without leaving a 30-day credential behind.
                    if (!empty($_POST['remember_me'])) {
                        rememberMeIssue($pdo, (int)$customer['id']);
                    }

                    // Update last_login timestamp
                    try {
                        $upLast = $pdo->prepare("UPDATE customers SET last_login = NOW() WHERE id = :id");
                        $upLast->execute(['id' => $customer['id']]);
                    } catch (Exception $exLast) {}

                    // Guest Order Linking — only for a VERIFIED address, and
                    // never by phone. The old block linked guest orders on the
                    // strength of the typed email (or phone) alone; an attacker
                    // who registered with a guest's email got their whole history
                    // without ever proving the address was theirs. The email is
                    // only proof of ownership once the customer clicked the
                    // verification link mailed to it — see verify_email.php.
                    if (isCustomerEmailVerified($customer)) {
                        linkGuestOrdersByEmail($pdo, (int)$customer['id'], (string)($customer['email'] ?? ''));
                    }

                    header('Location: account.php');
                    exit;
                } else {
                    error_log("[Auth Diagnostic] Failed password_verify for customer ID " . (int)$customer['id'] . ". DB Hash length: " . strlen($customer['password']) . ", Hash prefix: " . substr($customer['password'], 0, 7));
                    recordFailedLogin($pdo, $rawInput);
                    $error = "Invalid email/phone or password credentials.";
                }
            } elseif ($error === '') {
                // $error may already be set by the ambiguous-phone branch above;
                // don't overwrite that more helpful message with the generic one.
                error_log("[Auth Diagnostic] Customer lookup returned 0 rows for login input.");
                // Recorded even when no such account exists. Skipping it here would
                // make a wrong-password response measurably different from an
                // unknown-account one, which is an account enumeration oracle.
                recordFailedLogin($pdo, $rawInput);
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
}

$pageTitle = "Login";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "Sign in to your Dievon account to track orders, manage addresses and see your wishlist.";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Customer Login Page (560px Clean Class Layout) ════════════════════════ -->
<section class="auth-section">
    <div class="auth-container">
        
        <div class="auth-card-box reveal-on-scroll">
            <span class="editorial-label">Dievon Member</span>
            <h1 class="auth-title">Member Sign In</h1>

            <?php if ($error !== ''): ?>
                <?= dvNotice(htmlspecialchars($error), 'danger') ?>
            <?php endif; ?>

            <?php
            // The divider is tied to the same condition as the buttons, so
            // "Or Connect With" can never appear above an empty space again.
            $hasSocialLogin = (defined('GOOGLE_CLIENT_ID')   && !empty(GOOGLE_CLIENT_ID))
                           || (defined('FACEBOOK_APP_ID')    && !empty(FACEBOOK_APP_ID))
                           || (defined('APPLE_CLIENT_ID')    && !empty(APPLE_CLIENT_ID));
            ?>
            <?php if ($hasSocialLogin): ?>
            <div class="auth-divider">
                <hr>
                <span class="auth-divider-text">Or Connect With</span>
                <hr>
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
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <div class="form-luxury-group">
                    <label for="loginEmail">Email Address or Phone Number *</label>
                    <input type="text" id="loginEmail" name="email" class="form-luxury-input" required placeholder="you@example.com or <?= htmlspecialchars(shopPhone()) ?>">
                </div>
                
                <div class="form-luxury-group">
                    <label for="loginPassword">Password *</label>
                    <input type="password" id="loginPassword" name="password" class="form-luxury-input" required data-toggle-visibility placeholder="••••••••">
                </div>

                <div class="auth-forgot-row">
                    <label class="auth-remember" for="loginRemember">
                        <input type="checkbox" id="loginRemember" name="remember_me" value="1">
                        <span>Keep me signed in for <?= REMEMBER_DAYS ?> days</span>
                    </label>
                    <a href="<?= SITE_URL ?>/forgot_password.php">Forgot Password?</a>
                </div>
                <p class="auth-remember-hint">Leave this unticked on a shared or public computer.</p>

                <button type="submit" class="btn-luxury auth-submit-btn">
                    Sign In
                </button>
            </form>

            <?php
            /*
             * "Or Connect With" and its three social buttons used to render here
             * unconditionally. They were not wired to anything: each one called
             * alert('Google login initiated.') and stopped. oauth_google.php does
             * not exist. A shopper who clicked got a browser alert box.
             *
             * The REAL OAuth buttons are higher up this page and already render
             * only when GOOGLE_CLIENT_ID / FACEBOOK_APP_ID are configured — none
             * are, so nothing shows. That is the honest state: when you add
             * credentials the working buttons appear on their own.
             *
             * The divider lives with them rather than here, so a heading can never
             * again sit above nothing.
             */
            ?>

            <!-- Create Account Link -->
            <p class="auth-footer-text">
                New to Dievon? <a href="<?= SITE_URL ?>/register">Create account</a>
            </p>

            <?php // The staff sign-in link used to sit here, on the public customer
                  // login page. Knowing the URL is not itself a vulnerability, but
                  // advertising it to every shopper hands a target to anyone probing
                  // the site, and no customer has ever needed it. Staff reach the
                  // panel by bookmark. ?>

        </div>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
