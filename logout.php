<?php
// Signing out has to end BOTH ways of being signed in. Clearing the session
// keys alone would leave the "keep me signed in" cookie intact, so the very
// next page load would silently sign the customer straight back in — on a
// shared machine that is the opposite of what pressing Sign Out is for.
require_once __DIR__ . '/config/config.php';   // starts the session
require_once __DIR__ . '/config/db.php';       // provides $pdo
require_once __DIR__ . '/includes/remember_me.php';

rememberMeForget($pdo);

unset($_SESSION['customer_id']);
unset($_SESSION['customer_name']);
unset($_SESSION['customer_email']);
unset($_SESSION['auth_via_remember']);

// A fresh session id after a privilege change, exactly as on sign-in.
session_regenerate_id(true);

header('Location: ' . SITE_URL . '/login?logged_out=1');
exit;
