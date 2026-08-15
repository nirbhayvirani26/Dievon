<?php
// ============================================================
//  Dievon – Admin: Forgot password
//  Emails a one-time 6-digit code (same machinery as the emailed
//  sign-in codes) which is then used on reset_password.php.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Already signed in? Nothing to reset.
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: dashboard.php');
    exit;
}

$error     = '';
$sent      = false;
$submitted = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_submit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired — reload the page and try again.';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));

        // One field, two meanings: a username or an email. The account is found
        // either way; only the code sending needs the address.
        if ($identifier === '') {
            $error = 'Enter your admin username or the email on your account.';
        } else {
            try {
                $st = $pdo->prepare(
                    "SELECT id, username, full_name, email FROM admin_users
                      WHERE username = :u OR LOWER(email) = LOWER(:e)"
                );
                $st->execute(['u' => $identifier, 'e' => $identifier]);
                $matches = $st->fetchAll(PDO::FETCH_ASSOC);

                foreach ($matches as $m) {
                    $addr = trim((string)($m['email'] ?? ''));
                    if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) { continue; }

                    // Existing rate limit: at most 3 codes per account per 15 minutes.
                    $code = issueAdminLoginCode($pdo, (int)$m['id'], 'password_reset');
                    if ($code === null) { continue; }

                    try {
                        require_once __DIR__ . '/../services/EmailService.php';
                        (new EmailService($pdo))->sendAdminPasswordResetCodeEmail(
                            $addr,
                            (string)($m['full_name'] ?: $m['username']),
                            $code,
                            ADMIN_LOGIN_CODE_TTL_MINUTES
                        );
                        $sent = true;
                    } catch (\Throwable $e) {
                        error_log('Admin forgot-password email failed: ' . $e->getMessage());
                    }
                }
            } catch (PDOException $e) {
                error_log('Admin forgot-password lookup: ' . $e->getMessage());
            }

            // Deliberately the same words whether or not an account exists — the
            // page must not reveal which usernames/emails are in use.
            $submitted = $identifier;
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
    <title>Forgot Password – <?= SHOP_NAME ?></title>
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

        <?php if ($sent): ?>
        <div class="admin-login-alert" >
            <i class="fa-solid fa-envelope-circle-check"></i>
            <?php if ($submitted !== ''): ?>
                If an account matches <strong><?= htmlspecialchars($submitted) ?></strong> and has an email
                address saved, a 6-digit code is on its way (expires in <?= ADMIN_LOGIN_CODE_TTL_MINUTES ?> minutes).
            <?php else: ?>
                If an account exists and has an email address saved, a 6-digit code has been sent.
            <?php endif; ?>
        </div>
        <p class="admin-2fa-hint">
            Open <a href="reset_password.php">reset your password</a> and enter the code with your new password.
        </p>
        <div class="admin-login-footer">
            <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
        </div>
        <?php else: ?>
        <p class="admin-2fa-hint">
            Enter your admin <strong>username</strong> or the <strong>email</strong> on your account.
            If it matches, a one-time 6-digit code is emailed to the address on file.
        </p>

        <form action="forgot_password.php" method="POST" class="admin-login-form">
            <div class="admin-input-group">
                <label for="identifier">Username or email</label>
                <div class="admin-input-box">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" id="identifier" name="identifier" placeholder="dievonadmin or you@example.com"
                           required autocomplete="username" autofocus maxlength="191">
                </div>
            </div>
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <button type="submit" name="forgot_submit" value="1" class="btn-admin-submit">
                <i class="fa-solid fa-paper-plane"></i> Email me a reset code
            </button>
        </form>

        <div class="admin-login-footer">
            <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
        </div>
        <?php endif; ?>

        <p class="admin-2fa-hint" style="margin-top:18px; font-size:12px;">
            No email received? Ask the shop owner to reset it, or use the command-line reset tool on the server.
        </p>
    </div>
</div>

</body>
</html>
