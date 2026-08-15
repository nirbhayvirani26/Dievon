<?php
// ============================================================
//  DievonOrders - Front Controller Router
// ============================================================

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load Configuration and Database
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

// Parse the requested URL
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Determine base path dynamically (works locally or on Hostinger)
$script_name = dirname($_SERVER['SCRIPT_NAME']);
if ($script_name !== '/' && $script_name !== '\\') {
    // Remove the base directory from the requested path
    $path = preg_replace('#^' . preg_quote($script_name, '#') . '#', '', $path);
}

// Clean the path
$path = trim($path, '/');

// Default route
if (empty($path)) {
    $path = 'home';
}

// Ensure the path does not end in .php (force clean URLs)
if (str_ends_with($path, '.php')) {
    $path = substr($path, 0, -4);
}

// "index" is not a page — it is the name of this router file. Asking for
// /index.php therefore looked for pages/index.php, which does not exist, so the
// homepage's own second address answered with the 404 page. Send it to the root
// instead: that is already what this page's <link rel="canonical"> declares, and
// nothing internally links to /index.php, so no request depends on the old path.
if ($path === 'index') {
    header('Location: ' . SITE_URL . '/', true, 301);
    exit;
}

// Construct the physical file path
$file_path = __DIR__ . '/pages/' . $path . '.php';

// Route the request
if (file_exists($file_path)) {
    require $file_path;
} else {
    // 404 Not Found Handling — serve the site's real styled 404 page, with the correct HTTP status
    http_response_code(404);
    require __DIR__ . '/pages/404.php';
}
