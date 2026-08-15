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

$error  = '';
$ok     = false;
$locked = false;

// A simple wrong-code counter, kept in the session. The code itself is already
// 6 digits, single-use and 10 minutes old at most — this just stops someone
// grinding through combinations on this page.
//
// The lock is time-boxed, not permanent: the timer is stored alongside the
// counter, so a person who mistyped five times is genuinely back in after
// RESET_LOCK_MINUTES rather than locked out for the life of the session.
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
                // here may reveal whether an account exists.
                $error = 'That code was not correct, or it has expired. Request a new one and try again.';
                $_SESSION['pw_reset_fails'] = $fails + 1;
                unset($_SESSION['pw_reset_locked_until']);
            } else {
                $pwError = validatePasswordStrength($pw1, (string)($account['email'] ?? ''), (string)($account['full_name'] ?? ''));
                if ($pwError !== null) {
                    $error = $pwError;
                } elseif (!verifyAdminLoginCode($pdo, (int)$account['id'], $code, 'password_reset')) {
                    $error = 'That code was not correct, or it has expired. Request a new one and try again.';
                    $_SESSION['pw_reset_fails'] = $fails + 1;
                    unset($_SESSION['pw_reset_locked_until']);
                } else {
                    try {
                        $pdo->prepare("UPDATE admin_users SET password_hash = :h, must_change_password = 0 WHERE id = :id")
                            ->execute(['h' => password_hash($pw1, PASSWORD_DEFAULT), 'id' => (int)$account['id']]);

                        // Clear any sign-in throttling this account had built up.
                        $pdo->prepare("DELETE FROM login_attempts WHERE identifier = :i")
                            ->execute(['i' => 'admin:' . mb_strtolower(trim((string)$account['username']))]);

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
