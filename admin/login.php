<?php
// ============================================================
//  Dievon – Admin Login
//  URL: /Orders/admin/login.php
// ============================================================
session_start();
require_once '../config/config.php';
require_once '../config/db.php';

// Already logged in → redirect
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: orders.php'); exit;
}

/**
 * Record an admin sign-in attempt, successful or not.
 *
 * Wrapped so a missing table (pre-migration) can never block signing in — the
 * history is valuable, but not more valuable than being able to run the shop.
 */
function logAdminLogin(?PDO $pdo, ?int $adminId, string $username, bool $ok): void {
    if (!($pdo instanceof PDO)) { return; }
    try {
        $pdo->prepare("INSERT INTO admin_login_history (admin_id, username, succeeded, ip_address, user_agent)
                       VALUES (:aid, :u, :ok, :ip, :ua)")
            ->execute([
                ':aid' => $adminId,
                ':u'   => mb_substr($username, 0, 80),
                ':ok'  => $ok ? 1 : 0,
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
    } catch (PDOException $e) {
        error_log('logAdminLogin: ' . $e->getMessage());
    }
}

$error = '';
// True once the password has been accepted and only the code is outstanding.
$awaiting2fa = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');   // NOT trimmed: a password may legitimately end in a space

    // Same throttle the storefront uses. The admin panel is the single most
    // valuable target on the site and had no limit on password guessing at all.
    // Namespaced so an admin lockout cannot lock out a customer of the same name.
    $throttleKey = 'admin:' . $u;
    $lockFor = loginLockRemaining($pdo, $throttleKey);

    // Is this the SECOND step of a sign-in whose password was already accepted?
    //
    // Identified by the pending id held in the session, never by re-posting the
    // password: the code form deliberately submits an empty password field, and
    // carrying the real one through a hidden input would put it in the page
    // source and the browser's form history.
    $pendingId = (int)($_SESSION['admin_2fa_pending_id'] ?? 0);
    $isSecondStep = ($pendingId > 0 && trim((string)($_POST['totp_code'] ?? '')) !== '');

    if ($lockFor > 0) {
        $error = 'Too many sign-in attempts. Please wait ' . humaniseSeconds($lockFor) . ' and try again.';
        logAdminLogin($pdo, null, $u, false);

    } elseif (!$isSecondStep && ($u === '' || $p === '')) {
        $error = 'Please enter both a username and password.';

    } else {
        $admin = null;
        try {
            if ($isSecondStep) {
                // Load by the pending id, so the password is not needed again.
                $st = $pdo->prepare("SELECT * FROM admin_users WHERE id = :id AND is_active = 1 LIMIT 1");
                $st->execute([':id' => $pendingId]);
            } else {
                $st = $pdo->prepare("SELECT * FROM admin_users WHERE username = :u AND is_active = 1 LIMIT 1");
                $st->execute([':u' => $u]);
            }
            $admin = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            // Table not created yet — fall through to the constants below.
            error_log('admin_users lookup: ' . $e->getMessage());
        }

        $ok = false;

        if ($isSecondStep && $admin) {
            // The password step already passed for this admin. The code check
            // below is now the only thing standing between here and a session.
            $ok = true;
            $u  = $admin['username'];
            $throttleKey = 'admin:' . $u;
        } elseif ($admin && password_verify($p, $admin['password_hash'])) {
            $ok = true;
        }
        // The file-credential fallback that used to sit here has been REMOVED.
        //
        // It read ADMIN_USERNAME / ADMIN_PASSWORD out of config/config.php — a file
        // that is uploaded to the server and sits in the web root — and granted a
        // full owner session. Three things were wrong with it:
        //
        //   1. The live password was written in the file in plain text.
        //   2. Its guard was `!$admin`, and $admin is fetched WHERE is_active = 1.
        //      So DEACTIVATING an admin account did not lock that person out — it
        //      made $admin null and handed the fallback straight to them.
        //   3. The second-factor check below is `if ($ok && $admin && ...)`. With
        //      $admin null the fallback skipped 2FA entirely.
        //
        // Sign-in now always goes through an admin_users row, so the 2FA gate, the
        // active flag, the role and the login history all apply to everyone.
        // If the table is ever genuinely empty, update_new_database.php seeds the
        // first account — that is the recovery path, not a password in a file.

        // ── Second factor ───────────────────────────────────
        // The password was right; the session does NOT become an admin session
        // until the code is too. Everything below only runs once both are done.
        if ($ok && $admin && (int)($admin['totp_enabled'] ?? 0) === 1) {
            $code = trim((string)($_POST['totp_code'] ?? ''));

            // Which second factor this account uses. Absent column (migration not
            // run yet) reads as 'app', which is what every existing account is.
            $factorMethod = ($admin['totp_method'] ?? 'app') === 'email' ? 'email' : 'app';

            if ($code === '') {
                // Ask for the code. The username and password are NOT kept in the
                // session while we wait — the pending id alone is enough, and
                // storing credentials mid-flow is how they end up in a session file.
                $_SESSION['admin_2fa_pending_id'] = (int)$admin['id'];
                $awaiting2fa = true;
                $error = '';

                // Email accounts need the code sending before there is anything to
                // type. Sent only on the first prompt, not on a wrong-code retry —
                // otherwise every mistyped digit would issue a fresh code and the
                // one the person is reading would already be dead.
                if ($factorMethod === 'email') {
                    $factorEmail = trim((string)($admin['email'] ?? ''));
                    if ($factorEmail === '') {
                        // Refusing here rather than silently letting them in: the
                        // account asked for a second factor and there is no way to
                        // deliver one, so the safe answer is "not like this".
                        $error = 'This account uses emailed codes but has no email address saved. Ask the shop owner to add one.';
                        $awaiting2fa = false;
                        $ok = false;
                    } else {
                        $issued = issueAdminLoginCode($pdo, (int)$admin['id'], 'login');
                        if ($issued === null) {
                            $error = 'Too many codes requested. Wait 15 minutes, or use one of your recovery codes below.';
                        } else {
                            try {
                                require_once __DIR__ . '/../services/EmailService.php';
                                (new EmailService($pdo))->sendAdminLoginCodeEmail(
                                    $factorEmail, (string)($admin['full_name'] ?: $admin['username']),
                                    $issued, ADMIN_LOGIN_CODE_TTL_MINUTES
                                );
                            } catch (\Throwable $eMail) {
                                error_log('2FA code email: ' . $eMail->getMessage());
                            }
                            // Masked, so the page cannot be used to discover the
                            // address behind a username someone merely guessed.
                            $codeSentTo = maskEmailAddress($factorEmail);
                        }
                    }
                }
            } else {
                if ($factorMethod === 'email') {
                    $codeOk = verifyAdminLoginCode($pdo, (int)$admin['id'], $code, 'login');
                    // First successful emailed code doubles as proof the address
                    // is real — which is what makes a typo surface here instead of
                    // at the moment somebody actually needs to get in.
                    if ($codeOk && empty($admin['email_verified_at'])) {
                        try {
                            $pdo->prepare("UPDATE admin_users SET email_verified_at = NOW() WHERE id = :id")
                                ->execute([':id' => $admin['id']]);
                        } catch (PDOException $eV) {}
                    }
                } else {
                    require_once __DIR__ . '/../config/Totp.php';
                    $codeOk = Totp::verify((string)$admin['totp_secret'], $code);
                }

                // A recovery code is accepted once and then burned, so a
                // written-down list cannot be reused if it is found later.
                if (!$codeOk) {
                    $codes = json_decode((string)($admin['recovery_codes'] ?? '[]'), true) ?: [];
                    $typed = strtoupper(preg_replace('/\s+/', '', $code));
                    foreach ($codes as $i => $stored) {
                        if (hash_equals(strtoupper($stored), $typed)) {
                            unset($codes[$i]);
                            $pdo->prepare("UPDATE admin_users SET recovery_codes = :c WHERE id = :id")
                                ->execute([':c' => json_encode(array_values($codes)), ':id' => $admin['id']]);
                            $codeOk = true;
                            $_SESSION['admin_notice'] = 'You signed in with a recovery code. '
                                . count($codes) . ' remaining — generate a new set if you are running low.';
                            break;
                        }
                    }
                }

                if (!$codeOk) {
                    recordFailedLogin($pdo, $throttleKey);
                    logAdminLogin($pdo, (int)$admin['id'], $u, false);
                    $_SESSION['admin_2fa_pending_id'] = (int)$admin['id'];
                    $awaiting2fa = true;
                    $ok = false;
                    // Wording follows the method, so somebody using emailed codes is
                    // not told to check an app they have never installed.
                    $error = $factorMethod === 'email'
                        ? 'That code was not correct, or it has expired. Check the latest email, or request a new code.'
                        : 'That code was not correct. Check your authenticator app and try again.';
                }
            }

            // Still waiting on the code — do not create an admin session.
            if (!empty($awaiting2fa)) {
                $ok = false;
            }
        }

        if ($ok) {
            // Fresh session id the moment the session gains admin rights.
            session_regenerate_id(true);
            clearFailedLogins($pdo, $throttleKey);
            unset($_SESSION['admin_2fa_pending_id']);

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id']        = $admin['id']   ?? null;
            $_SESSION['admin_username']  = $admin['username'] ?? $u;
            $_SESSION['admin_role']      = $admin['role'] ?? 'owner';
            $_SESSION['admin_name']      = $admin['full_name'] ?? 'Shop Owner';

            if ($admin) {
                try {
                    $pdo->prepare("UPDATE admin_users SET last_login_at = NOW() WHERE id = :id")
                        ->execute([':id' => $admin['id']]);
                } catch (PDOException $e) {}
            }

            logAdminLogin($pdo, $admin['id'] ?? null, $u, true);

            header('Location: orders.php');
            exit;
        }

        // Waiting on the code is NOT a failed sign-in.
        //
        // Without this guard, execution fell through here every time a correct
        // password was followed by the 2FA prompt: it recorded a failed attempt
        // and printed "Incorrect username or password." above the code field.
        // Five ordinary sign-ins would have locked the owner out of their own
        // shop, and the message contradicted the form beneath it.
        //
        // The wrong-code path sets its own message and records its own failure
        // higher up, so it must not be double-counted here either.
        if (!$awaiting2fa) {
            recordFailedLogin($pdo, $throttleKey);
            logAdminLogin($pdo, $admin['id'] ?? null, $u, false);
            // Deliberately identical whether the username exists or not.
            $error = 'Incorrect username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login – <?= SHOP_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
</head>
<body class="admin-login-body">

<div class="admin-login-wrapper">
    <div class="admin-login-card">
        <div class="admin-login-brand">
            <h1 class="admin-login-logo">Dievon</h1>
            <span class="admin-login-tagline">Atelier Management Portal</span>
        </div>

        <?php if ($error): ?>
        <div class="admin-login-alert">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="admin-login-form">
            <?php if ($awaiting2fa): ?>
            <?php // Only the code is asked for here. The username and password have
                  // already been verified and are deliberately NOT carried in hidden
                  // fields — the pending admin id in the session is enough. ?>
            <?php
            // Wording follows the method. Telling somebody who uses emailed codes to
            // "check your authenticator app" is the fastest way to make them think
            // sign-in is broken. $codeSentTo is only set on the request that actually
            // sent one, so a wrong-code retry falls back to the stored address.
            $isEmailFactor = (($factorMethod ?? 'app') === 'email');
            $shownAddress  = $codeSentTo ?? (($isEmailFactor && !empty($admin['email']))
                                ? maskEmailAddress((string)$admin['email']) : '');
            ?>
            <?php if ($isEmailFactor): ?>
            <p class="admin-2fa-hint">
                <?php if ($shownAddress !== ''): ?>
                    We have emailed a 6-digit code to <strong><?= htmlspecialchars($shownAddress) ?></strong>.
                    It expires in <?= ADMIN_LOGIN_CODE_TTL_MINUTES ?> minutes.
                <?php else: ?>
                    Enter the 6-digit code we emailed you, or one of your recovery codes.
                <?php endif; ?>
            </p>
            <?php else: ?>
            <p class="admin-2fa-hint">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
            <?php endif; ?>
            <div class="admin-input-group">
                <label for="totp_code">Authentication code</label>
                <div class="admin-input-box">
                    <i class="fa-solid fa-shield-halved"></i>
                    <input type="text" id="totp_code" name="totp_code" placeholder="000000"
                           inputmode="numeric" autocomplete="one-time-code" required autofocus
                           maxlength="12" pattern="[0-9A-Za-z\-]{6,12}">
                </div>
            </div>
            <?php if ($isEmailFactor): ?>
            <?php // formnovalidate so the empty code field does not block it. An empty
                  // code re-enters the "no code yet" branch, which issues and sends a
                  // fresh one — and retires the previous code, so only ever one is live. ?>
            <button type="submit" name="resend" value="1" formnovalidate class="admin-2fa-resend">
                <i class="fa-solid fa-rotate-right"></i> Email me a new code
            </button>
            <?php endif; ?>
            <input type="hidden" name="username" value="<?= htmlspecialchars($u ?? '') ?>">
            <input type="hidden" name="password" value="">
            <?php else: ?>
            <div class="admin-input-group">
                <label for="username">Username</label>
                <div class="admin-input-box">
                    <i class="fa-regular fa-user"></i>
                    <input type="text" id="username" name="username" placeholder="Enter admin username" required autocomplete="username">
                </div>
            </div>

            <div class="admin-input-group">
                <label for="password">Password</label>
                <div class="admin-input-box">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                    <button type="button" onclick="togglePassword()" class="btn-pwd-eye" aria-label="Toggle password visibility">
                        <i class="fa-solid fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
                <?php // Shown on the credential step only — the code step is not the
                      // moment to send somebody down the reset road again. Placed
                      // BELOW the box, not inside it, so it never covers the eye
                      // toggle. ?>
                <a href="forgot_password.php" class="admin-login-forgot">Forgot password?</a>
            </div>

            <?php endif; ?>

            <button type="submit" class="btn-admin-submit">
                <i class="fa-solid fa-right-to-bracket"></i>
                <?= $awaiting2fa ? 'Verify Code' : 'Sign In to Portal' ?>
            </button>
        </form>

        <div class="admin-login-footer">
            <a href="../home"><i class="fa-solid fa-arrow-left"></i> Return to Main Shop</a>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}
</script>
</body>
</html>
