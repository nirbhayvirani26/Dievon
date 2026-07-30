<?php
// ============================================================
//  Dievon – Site Configuration
// ============================================================

define('SHOP_NAME',    'Dievon');
define('SHOP_TAGLINE', 'Timeless Luxury Women’s Fashion');
define('SHOP_PHONE',   '+91 98765 43210');
define('SHOP_WHATSAPP','919876543210');
define('ADMIN_EMAIL',  'princevir2610@gmail.com');
define('META_PIXEL_ID', ''); // Enter your Meta Pixel ID here when available (e.g. '1234567890')

// Admin login credentials (change these!)
define('ADMIN_USERNAME', 'dievonadmin');
define('ADMIN_PASSWORD', 'Dievon@2026');

// ── Environment Auto-Detection (Local MAMP vs Live Hostinger) ──
$hostHeader = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocal = (strpos($hostHeader, 'localhost') !== false || strpos($hostHeader, '127.0.0.1') !== false || strpos($hostHeader, '8888') !== false || php_sapi_name() === 'cli');

if ($isLocal) {
    // LOCAL / MAMP Credentials
    define('SITE_URL', 'http://localhost:8888/DievonOrders');
    define('DB_HOST', 'localhost');
    define('DB_PORT', '8889');
    define('DB_NAME', 'dievonfashion');
    define('DB_USER', 'root');
    define('DB_PASS', 'root');
} else {
    // LIVE / Hostinger Credentials (works dynamically for dievon.com, stage.dievon.com, testing.dievon.com, etc.)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    define('SITE_URL', $protocol . $hostHeader);
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'u167013900_dievon');
    define('DB_USER', 'u167013900_dievonuser');
    define('DB_PASS', 'Dievon@2026*');
}



// ── Razorpay Payment Keys ─────────────────────────────────────
// Get these from: https://dashboard.razorpay.com/app/keys
// Real values live in .env (never committed/backed up with the rest of the source) —
// these string literals are fallbacks only, used if .env has no override.
// Use TEST keys (rzp_test_... / test secret) while testing, LIVE keys when ready to launch.
// "Pay Online" at checkout stays disabled (falls back to Bank Wire/COD) until both are filled in.
require_once __DIR__ . '/EnvLoader.php';
define('RAZORPAY_KEY_ID',     (string)EnvLoader::get('RAZORPAY_KEY_ID',     'rzp_test_TJ7K15lBViA0Hw'));
define('RAZORPAY_KEY_SECRET', (string)EnvLoader::get('RAZORPAY_KEY_SECRET', '9JXgHibcZS9VgaPH5sAQl0kJ'));
// Separate secret for the webhook (Dashboard -> Settings -> Webhooks -> create one pointed
// at SITE_URL/actions/razorpay_webhook.php, subscribed to "payment.captured"). Empty by
// default — the webhook endpoint safely no-ops until this is set.
define('RAZORPAY_WEBHOOK_SECRET', (string)EnvLoader::get('RAZORPAY_WEBHOOK_SECRET', ''));

// ── Multi-Currency & Default Currency Configuration ──────────────────
// Set ENABLE_CURRENCY_SWITCHER to true in the future to re-enable multi-currency selector
define('ENABLE_CURRENCY_SWITCHER', false); 
define('DEFAULT_CURRENCY', 'INR');          // Default store currency (INR ₹)

$GLOBALS['CURRENCY_RATES'] = [
    'INR' => ['symbol' => '₹', 'rate' => 1.00, 'name' => 'INR (₹)'],
    'GBP' => ['symbol' => '£', 'rate' => 0.0095, 'name' => 'GBP (£)'],
    'USD' => ['symbol' => '$', 'rate' => 0.012, 'name' => 'USD ($)']
];

function getCurrentCurrency() {
    if (!defined('ENABLE_CURRENCY_SWITCHER') || !ENABLE_CURRENCY_SWITCHER) {
        return defined('DEFAULT_CURRENCY') ? DEFAULT_CURRENCY : 'INR';
    }
    $c = $_COOKIE['dievon_currency'] ?? ($_SESSION['currency'] ?? 'INR');
    $c = strtoupper(trim($c));
    if (!isset($GLOBALS['CURRENCY_RATES'][$c])) {
        $c = 'INR';
    }
    return $c;
}

function formatPrice($amountInINR, $includeSymbol = true) {
    $curr = getCurrentCurrency();
    $rateData = $GLOBALS['CURRENCY_RATES'][$curr] ?? $GLOBALS['CURRENCY_RATES']['INR'];
    $converted = (float)$amountInINR * $rateData['rate'];
    $formatted = number_format($converted, 2);
    return $includeSymbol ? $rateData['symbol'] . $formatted : $formatted;
}

// Turns "Aurelia Silk Kurti" into "aurelia-silk-kurti" — used to give uploaded
// image files SEO-friendly names instead of random/generic ones.
function slugify($text, $maxLength = 60) {
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    if ($text === '') { $text = 'image'; }
    if (strlen($text) > $maxLength) { $text = rtrim(substr($text, 0, $maxLength), '-'); }
    return $text;
}

// Canonical SEO-friendly product URL, e.g. /product/aurelia-silk-kurti-1 — the trailing
// id is the real lookup key, so it stays a valid link even if the product is renamed later.
function productUrl($id, $name) {
    return SITE_URL . '/product/' . slugify($name) . '-' . (int)$id;
}

// Generates a same-name .webp copy alongside an uploaded JPEG/PNG/GIF, for pages to
// optionally serve via <picture> (smaller file, faster load — a real Core Web Vitals
// factor). The original file is never modified or removed. Silently no-ops if GD is
// unavailable or the source type is unsupported, since WebP is a bonus, not a requirement.
function generateWebpCopy($absoluteSourcePath) {
    if (!extension_loaded('gd') || !function_exists('imagewebp') || !is_file($absoluteSourcePath)) {
        return false;
    }
    $destPath = preg_replace('/\.[^.]+$/', '.webp', $absoluteSourcePath);

    // Detect the real format from file content, not the extension — an upload can be
    // saved with a mismatched extension (e.g. a .png that is actually JPEG data).
    $imageInfo = @getimagesize($absoluteSourcePath);
    $type = $imageInfo[2] ?? null;

    try {
        switch ($type) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($absoluteSourcePath);
                break;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($absoluteSourcePath);
                if ($img) { imagepalettetotruecolor($img); imagealphablending($img, true); imagesavealpha($img, true); }
                break;
            case IMAGETYPE_GIF:
                $img = @imagecreatefromgif($absoluteSourcePath);
                break;
            default:
                return false;
        }
        if (!$img) { return false; }
        $ok = imagewebp($img, $destPath, 82);
        imagedestroy($img);
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

// Returns the .webp sibling path (URL) for an uploaded image if it actually exists on
// disk, else null — lets templates opt into <picture> only when a real WebP file is there.
function webpUrlIfExists($uploadsSubdir, $filename) {
    if (empty($filename)) { return null; }
    $webpFilename = preg_replace('/\.[^.]+$/', '.webp', $filename);
    $absolutePath = __DIR__ . '/../uploads/' . $uploadsSubdir . '/' . $webpFilename;
    if ($webpFilename !== $filename && is_file($absolutePath)) {
        return SITE_URL . '/uploads/' . $uploadsSubdir . '/' . $webpFilename;
    }
    return null;
}

// ── CSRF Token & Security Helpers ───────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function logAdminAction($adminId, $action, $details = '') {
    global $pdo;
    if (!$pdo) return;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare("INSERT INTO admin_audit_logs (admin_id, action, details, ip_address) VALUES (:aid, :act, :det, :ip)");
        $stmt->execute(['aid' => (int)$adminId, 'act' => $action, 'det' => $details, 'ip' => $ip]);
    } catch (Exception $e) {}
}

