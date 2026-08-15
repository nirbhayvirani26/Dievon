<?php
require_once __DIR__ . '/../config/config.php';

// Force absolute redirect to admin/dashboard.php (prevents relative URL routing issues)
header('Location: ' . SITE_URL . '/admin/dashboard.php');
exit;
