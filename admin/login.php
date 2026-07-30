<?php
// ============================================================
//  Dievon – Admin Login
//  URL: /Orders/admin/login.php
// ============================================================
session_start();
require_once '../config/config.php';

// Already logged in → redirect
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: orders.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    if ($u === ADMIN_USERNAME && $p === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: orders.php'); exit;
    }
    $error = 'Incorrect username or password.';
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
            <h1 class="admin-login-logo">DIEVON</h1>
            <span class="admin-login-tagline">Atelier Management Portal</span>
        </div>

        <?php if ($error): ?>
        <div class="admin-login-alert">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="admin-login-form">
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
            </div>

            <button type="submit" class="btn-admin-submit">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
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
