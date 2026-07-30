<?php
session_start();
// Unset customer session keys
unset($_SESSION['customer_id']);
unset($_SESSION['customer_name']);
unset($_SESSION['customer_email']);

// Redirect to login
header('Location: login.php?logged_out=1');
exit;
