<?php
// ============================================================
//  Dievon – Admin: Reset password
//  The other half of forgot_password.php: the 6-digit code from
//  the email plus the new password. The code is single-use and
//  expires after ADMIN_LOGIN_CODE_TTL_MINUTES minutes.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: dashboard.php');
    exit;
}

const RESET_MAX_ATTEMPTS = 5;   // wrong codes per session before a 15-minute pause
const RESET_LOCK_MINUTES = 15;

/**
 * Throttle key for the shared `login_attempts` table.
 *
 * Prefixed on purpose: wrong codes here must not spend the account's SIGN-IN
 * budget and lock the owner out of admin/login.php as a side effect. The row
 * still carries the source address, and loginLockRemaining() counts by address
 * whatever the key is, so the per-IP ceiling spans both pages.
 */
function adminResetThrottleKey(string $identifier): string {
    return 'admin-reset:' . mb_strtolower(trim($identifier));
}

$error  = '';
$ok     = false;
$locked = false;

// Two counters guard this form, and only one of them is a defence.
//
// The session counter is the courtesy one: it tells somebody who mistyped to
// pause, without a database round trip, and the timer is stored beside the
// count so five fat-fingered tries genuinely clear after RESET_LOCK_MINUTES
// rather than for the life of the session. It cannot be the limit, because the
// session cookie belongs to whoever is calling — delete it and the count is
// zero again. On its own it stops honest typos and nothing else.
$fails       = (int)($_SESSION['pw_reset_fails'] ?? 0);
$lockedUntil = (int)($_SESSION['pw_reset_locked_until'] ?? 0);
if ($lockedUntil > time()) {
    $locked = true;
    $error  = 'Too many incorrect attempts. Please wait a few minutes and try again.';
} else {
    if ($lockedUntil > 0) {
        // The lock window has passed — start the counter afresh.
        unset($_SESSION['pw_reset_locked_until'], $_SESSION['pw_reset_fails']);
        $fails = 0;
    }
    if ($fails >= RESET_MAX_ATTEMPTS) {
        $_SESSION['pw_reset_locked_until'] = time() + RESET_LOCK_MINUTES * 60;
        unset($_SESSION['pw_reset_fails']);
        $locked = true;
        $error  = 'Too many incorrect attempts. Please wait ' . RESET_LOCK_MINUTES . ' minutes and try again.';
    }
}

// The binding limit is the one below: `login_attempts`, keyed on the account
// AND the source address, exactly as admin/login.php uses it. A fresh cookie
// buys nothing here, so a caller cycling sessions to grind the 6-digit code
// still runs into a wall — after LOGIN_MAX_PER_IDENTIFIER tries at one account,
// or LOGIN_MAX_PER_IP from one address whatever names they try.
//
// A GET has no account to key on yet, so the empty key checks the address
// ceiling alone — which is why the form can still render enabled to somebody
// who has already burned an account's ten tries. Nothing is lost by that: the
// POST carries the identifier and is refused on the way in. It only means the
// warning appears when they submit rather than before they type.
$throttleWait = loginLockRemaining($pdo, adminResetThrottleKey(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['identifier'] ?? '') : ''
));
if ($throttleWait > 0) {
    $locked = true;
    $error  = 'Too many incorrect attempts. Please wait ' . humaniseSeconds($throttleWait) . ' and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_submit']) && !$locked) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired — reload the page and try again.';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $code       = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $pw1        = (string)($_POST['password'] ?? '');
        $pw2        = (string)($_POST['confirm'] ?? '');

        if ($identifier === '' || $code === '' || $pw1 === '') {
            $error = 'Fill in every field.';
        } elseif ($pw1 !== $pw2) {
            $error = 'The two passwords did not match.';
        } else {
            $account = null;
            try {
                $st = $pdo->prepare(
                    "SELECT id, username, full_name, email FROM admin_users
                      WHERE username = :u OR LOWER(email) = LOWER(:e) LIMIT 1"
                );
                $st->execute(['u' => $identifier, 'e' => $identifier]);
                $account = $st->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log('Admin reset lookup: ' . $e->getMessage());
            }

            if (!$account) {
                // Same wording for a wrong identifier as a wrong code — nothing
                // here may reveal whether an account exists. That has to include
                // the password rules: checking strength only for real accounts
                // would turn "too short" into a yes/no answer about whether the
                // username exists, so an unknown identifier is held to the same
                // rules, with no account details to compare the password against.
                $pwError = validatePasswordStrength($pw1);
                if ($pwError !== null) {
                    $error = $pwError;
                } else {
                    $error = 'That code was not correct, or it has expired. Request a new one and try again.';
                    recordFailedLogin($pdo, adminResetThrottleKey($identifier));
                    $_SESSION['pw_reset_fails'] = $fails + 1;
                    unset($_SESSION['pw_reset_locked_until']);
                }
            } else {
                $pwError = validatePasswordStrength($pw1, (string)($account['email'] ?? ''), (string)($account['full_name'] ?? ''));
                if ($pwError !== null) {
                    $error = $pwError;
                } elseif (!verifyAdminLoginCode($pdo, (int)$account['id'], $code, 'password_reset')) {
                    $error = 'That code was not correct, or it has expired. Request a new one and try again.';
                    recordFailedLogin($pdo, adminResetThrottleKey($identifier));
                    $_SESSION['pw_reset_fails'] = $fails + 1;
                    unset($_SESSION['pw_reset_locked_until']);
                } else {
                    try {
                        $pdo->prepare("UPDATE admin_users SET password_hash = :h, must_change_password = 0 WHERE id = :id")
                            ->execute(['h' => password_hash($pw1, PASSWORD_DEFAULT), 'id' => (int)$account['id']]);

                        // Clear any sign-in throttling this account had built up,
                        // and the reset counter that the correct code just proved
                        // was the owner's — keyed on what they actually typed,
                        // since that is the key the failures accumulated under.
                        $pdo->prepare("DELETE FROM login_attempts WHERE identifier = :i")
                            ->execute(['i' => 'admin:' . mb_strtolower(trim((string)$account['username']))]);
                        clearFailedLogins($pdo, adminResetThrottleKey($identifier));

                        logAdminAction((int)$account['id'], 'admin_password_reset', "Password reset via emailed code for {$account['username']}");
                    } catch (PDOException $e) {
                        error_log('Admin reset save: ' . $e->getMessage());
                        $error = 'The password could not be saved. Try again, or ask the shop owner for help.';
                    }

                    if ($error === '') {
                        unset($_SESSION['pw_reset_fails'], $_SESSION['pw_reset_locked_until']);
                        $ok = true;
                    }
                }
            }
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
    <title>Reset Password – <?= SHOP_NAME ?></title>
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

        <?php if ($ok): ?>
        <div class="admin-login-alert" >
            <i class="fa-solid fa-circle-check"></i>
            Your password has been changed. You can now sign in with it.
        </div>
        <div class="admin-login-footer">
            <a href="login.php"><i class="fa-solid fa-right-to-bracket"></i> Sign in now</a>
        </div>

        <?php else: ?>
        <?php if ($error): ?>
        <div class="admin-login-alert">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <p class="admin-2fa-hint">
            Enter the 6-digit code from the email, your username (or email), and a new password.
            The code expires in <?= ADMIN_LOGIN_CODE_TTL_MINUTES ?> minutes and works once.
        </p>

        <form action="reset_password.php" method="POST" class="admin-login-form">
            <div class="admin-input-group">
                <label for="identifier">Username or email</label>
                <div class="admin-input-box">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" id="identifier" name="identifier" placeholder="dievonadmin or you@example.com"
                           required autocomplete="username" maxlength="191" <?= $locked ? 'disabled' : '' ?>>
                </div>
            </div>
            <div class="admin-input-group">
                <label for="code">6-digit code from the email</label>
                <div class="admin-input-box">
                    <i class="fa-solid fa-shield-halved"></i>
                    <input type="text" id="code" name="code" placeholder="000000" inputmode="numeric"
                           autocomplete="one-time-code" required maxlength="6" pattern="[0-9]{6}" <?= $locked ? 'disabled' : '' ?>>
                </div>
            </div>
            <div class="admin-input-group">
                <label for="password">New password (min <?= PASSWORD_MIN_LENGTH ?> characters)</label>
                <div class="admin-input-box">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="password" name="password" required
                           autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" <?= $locked ? 'disabled' : '' ?>>
                </div>
            </div>
            <div class="admin-input-group">
                <label for="confirm">Confirm new password</label>
                <div class="admin-input-box">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="confirm" name="confirm" required
                           autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" <?= $locked ? 'disabled' : '' ?>>
                </div>
            </div>
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <button type="submit" name="reset_submit" value="1" class="btn-admin-submit" <?= $locked ? 'disabled' : '' ?>>
                <i class="fa-solid fa-key"></i> Reset password
            </button>
        </form>

        <div class="admin-login-footer">
            <a href="forgot_password.php"><i class="fa-solid fa-envelope"></i> Request a new code</a>
            <span>&nbsp;·&nbsp;</span>
            <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
