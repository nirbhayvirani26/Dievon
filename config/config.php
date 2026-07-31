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

// Machine-readable price in the active currency: converted like formatPrice(), but with
// NO currency symbol and NO thousands separator. Required for schema.org / OpenGraph
// price values — number_format()'s default "1,299.00" is invalid to a parser and would
// silently invalidate the Product rich snippet.
function priceForMachines($amountInINR) {
    $curr = getCurrentCurrency();
    $rateData = $GLOBALS['CURRENCY_RATES'][$curr] ?? $GLOBALS['CURRENCY_RATES']['INR'];
    $converted = (float)$amountInINR * $rateData['rate'];
    return number_format($converted, 2, '.', '');
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

// ── Global body measurements ────────────────────────────────────────────────
/**
 * The default shop-wide body size table, used to seed size_guide_body_global on a
 * fresh install so every category has working body measurements from day one
 * without anyone typing 54 numbers.
 *
 * Follows config/size_ladder.php: the numeric size IS the bust, with the waist 4"
 * under and the hips 4" over — the proportion most Indian ready-to-wear ladders
 * are cut to. These are a sane starting point, not gospel: they are editable in
 * Admin → Size Guide → Body tab, and that edit applies to the whole shop.
 */
function defaultGlobalBodyRows(): array {
    $ladder = require __DIR__ . '/size_ladder.php';
    $rows = [];
    foreach ($ladder as $i => $sz) {
        $bust = (float)$sz['numeric'];
        $rows[] = [
            'size_label'   => $sz['code'],
            'numeric_size' => (string)$sz['numeric'],
            'bust'         => $bust,
            'waist'        => $bust - 6,
            'hips'         => $bust + 3,
            'shoulder'     => 13.5 + ($i * 0.5),
            'sort_order'   => $i,
        ];
    }
    return $rows;
}

/**
 * Fill size_guide_body_global with the defaults, but only when it is completely
 * empty — never overwrites numbers an admin has already set.
 * Returns the number of rows inserted.
 */
function seedGlobalBodyRows(PDO $pdo): int {
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM size_guide_body_global")->fetchColumn() > 0) {
            return 0;
        }
    } catch (PDOException $e) {
        return 0;   // table not created yet
    }
    $ins = $pdo->prepare("INSERT INTO size_guide_body_global (size_label, numeric_size, bust, waist, hips, shoulder, sort_order)
                          VALUES (:l,:n,:b,:w,:h,:s,:o)
                          ON DUPLICATE KEY UPDATE size_label = size_label");
    $n = 0;
    foreach (defaultGlobalBodyRows() as $r) {
        $ins->execute([
            'l' => $r['size_label'], 'n' => $r['numeric_size'], 'b' => $r['bust'],
            'w' => $r['waist'], 'h' => $r['hips'], 's' => $r['shoulder'], 'o' => $r['sort_order'],
        ]);
        $n++;
    }
    return $n;
}

/**
 * The one BODY measurement table, shared by every category.
 *
 * Body measurements describe the wearer, not the garment — a 34" bust is a size M
 * whatever she is buying — so there is exactly one of these for the whole shop.
 * GARMENT measurements stay per category, because a kurti hem and a top hem are
 * genuinely different lengths.
 *
 * Falls back to the per-chart body rows if the global table is empty, so an
 * un-migrated database (or a rollback) keeps working unchanged.
 */
function globalBodySizeRows(PDO $pdo, array $fallbackRows = []): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $cache = $pdo->query("SELECT size_label, numeric_size, bust, waist, hips, shoulder, sort_order
                                  FROM size_guide_body_global ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $cache = [];   // table not created yet — caller falls back
        }
    }
    if (!$cache) { return $fallbackRows; }

    // Shape it like size_guide_content rows so every existing template that reads
    // $r['bust'] etc. keeps working with no changes.
    return array_map(function ($r) {
        return [
            'measurement_type' => 'body',
            'size_label'       => $r['size_label'],
            'numeric_size'     => $r['numeric_size'],
            'bust'             => $r['bust'],
            'waist'            => $r['waist'],
            'hips'             => $r['hips'],
            'shoulder'         => $r['shoulder'],
            'length'           => null,          // body rows never carry a length
            'sort_order'       => $r['sort_order'],
        ];
    }, $cache);
}

// ── Size guide "how to measure" text ────────────────────────────────────────
/**
 * The shop-wide default measuring instructions.
 *
 * How you measure a bust does not change between a kurti and a pair of trousers,
 * so these are shared. A category chart can still override any of them (a Bottoms
 * chart, for instance, overrides Length with "from the waistband down the leg"),
 * but a category with no chart of its own still gets proper guidance instead of a
 * blank panel.
 */
function defaultSizeGuideInstructions(): array {
    return [
        'shoulder' => 'Stand relaxed with arms down. Measure across the back from the bony tip of one shoulder to the bony tip of the other, keeping the tape flat along the shoulder line.',
        'bust'     => 'Wear a non-padded bra. Measure around the fullest part of the bust, keeping the tape level and parallel to the floor. Keep it snug but not tight — you should be able to slide a finger underneath.',
        'waist'    => 'Measure around the narrowest part of your natural waistline, usually just above the navel. Do not hold your breath in; keep the tape comfortably loose.',
        'hips'     => 'Stand with your feet together. Measure around the fullest part of your hips and seat, roughly 8 inches below the natural waist, keeping the tape parallel to the floor.',
        'length'   => 'Measure from the highest point of the shoulder, next to the neck, straight down the front to the hem.',
    ];
}

/**
 * Final instruction text for a chart: the category's own wording when it has any,
 * otherwise the shop-wide default. Returns [Label => text] with blanks removed.
 */
// $chart is intentionally untyped: PDO::fetch() returns FALSE (not null) when a
// category has no chart, and a `?array` hint turns that into a fatal TypeError.
function resolveSizeGuideInstructions(PDO $pdo, $chart): array {
    if (!is_array($chart)) { $chart = []; }
    $defaults = defaultSizeGuideInstructions();

    // A stored override lives in store_settings under size_guide_instr_<field>, so the
    // wording can be edited shop-wide without touching every category.
    foreach (array_keys($defaults) as $field) {
        $stored = storeSetting($pdo, 'size_guide_instr_' . $field);
        if ($stored !== null && trim((string)$stored) !== '') {
            $defaults[$field] = $stored;
        }
    }

    $labels = [
        'shoulder' => 'Shoulder',
        'bust'     => 'Bust',
        'waist'    => 'Waist',
        'hips'     => 'Hips',
        'length'   => 'Garment Length',
    ];

    $out = [];
    foreach ($labels as $field => $label) {
        $own = trim((string)($chart['instructions_' . $field] ?? ''));
        $text = $own !== '' ? $own : $defaults[$field];
        if (trim($text) !== '') { $out[$label] = $text; }
    }
    return $out;
}

// ── Store settings ──────────────────────────────────────────────────────────
/** Read a single key from store_settings, with a default. Cached per request. */
function storeSetting(PDO $pdo, string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (PDOException $e) { $cache = []; }
    }
    return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
}

/**
 * The measurement illustration to show in the size guide popup.
 *
 * Resolution order: this chart's own image, then the ONE site-wide default.
 * The site-wide default exists because body measuring (bust/waist/hips) is
 * identical whatever the garment — Biba, for instance, serves a single shared
 * diagram across their whole catalogue. Uploading one image therefore covers
 * every category, instead of having to repeat it per chart.
 */
// Untyped $chart for the same reason as resolveSizeGuideInstructions(): a missing
// chart arrives as boolean FALSE from PDO::fetch(), which a ?array hint rejects.
function sizeGuideIllustrationUrl(PDO $pdo, $chart): ?string {
    if (!is_array($chart)) { $chart = []; }
    if (!empty($chart['illustration_image'])) {
        return SITE_URL . '/uploads/products/' . $chart['illustration_image'];
    }
    $global = storeSetting($pdo, 'size_guide_illustration');
    return $global ? SITE_URL . '/uploads/gallery/' . $global : null;
}

// ── Category tree helpers ───────────────────────────────────────────────────
// Browsing a category must include everything beneath it: opening "Kurtis" should
// show Long Kurtis, Short Kurtis, Anarkali Sets and so on. This used to be faked
// in shop.php with hardcoded `category LIKE '%Kurti%'` rules, which both returned
// the wrong products (Short Kurtis showed Long Kurtis items) and silently returned
// nothing for any category family without a hand-written rule. These walk the real
// parent_id tree instead, so any depth and any future category works untouched.

/** All categories as id => ['id','name','parent_id'], read once and reused. */
function allCategoriesById(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    try {
        foreach ($pdo->query("SELECT id, name, parent_id FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cache[(int)$c['id']] = [
                'id'        => (int)$c['id'],
                'name'      => $c['name'],
                'parent_id' => (int)($c['parent_id'] ?? 0),
            ];
        }
    } catch (PDOException $e) { $cache = []; }
    return $cache;
}

/**
 * A category id plus every descendant id, to any depth.
 * Guards against a cycle (a category made its own ancestor) so a bad row in the
 * table can never spin this into an infinite loop.
 */
function categoryDescendantIds(PDO $pdo, int $categoryId): array {
    $all = allCategoriesById($pdo);
    if (!isset($all[$categoryId])) { return [$categoryId]; }

    $childrenOf = [];
    foreach ($all as $c) { $childrenOf[$c['parent_id']][] = $c['id']; }

    $out   = [];
    $stack = [$categoryId];
    $seen  = [];
    while ($stack) {
        $id = array_pop($stack);
        if (isset($seen[$id])) { continue; }
        $seen[$id] = true;
        $out[] = $id;
        foreach ($childrenOf[$id] ?? [] as $child) { $stack[] = $child; }
    }
    return $out;
}

/** Resolve category names (as used in URLs) to ids, including their descendants. */
function categoryIdsForNames(PDO $pdo, array $names): array {
    $all = allCategoriesById($pdo);
    $byName = [];
    foreach ($all as $c) { $byName[mb_strtolower(trim($c['name']))] = $c['id']; }

    $ids = [];
    foreach ($names as $n) {
        $key = mb_strtolower(trim((string)$n));
        if ($key === '' || !isset($byName[$key])) { continue; }
        foreach (categoryDescendantIds($pdo, $byName[$key]) as $id) { $ids[$id] = true; }
    }
    return array_keys($ids);
}

// ── Automatic SEO / OpenGraph / Schema derivation ───────────────────────────
// Every product needs a meta title, description, slug and keyword set. Filling
// those in by hand for each garment is tedious and usually gets skipped, which
// leaves Google and social previews showing a bare product name. These helpers
// derive sensible values from data the admin has already entered.
//
// The rule everywhere: an explicit value the admin typed ALWAYS wins. These are
// only consulted when the corresponding field is blank.

/** Truncate on a word boundary, never mid-word, and never exceed $limit including the ellipsis. */
function seoTruncate($text, $limit) {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if ($text === '' || mb_strlen($text) <= $limit) { return $text; }
    $slice = mb_substr($text, 0, $limit - 1);
    $lastSpace = mb_strrpos($slice, ' ');
    if ($lastSpace !== false && $lastSpace > $limit * 0.5) {
        $slice = mb_substr($slice, 0, $lastSpace);
    }
    return rtrim($slice, " ,.;:-") . '…';
}

/**
 * Meta title: "<Name> — <Category> | Dievon Atelier", trimmed to ~60 chars, which is
 * roughly where Google truncates. The brand suffix is dropped before the product name
 * is sacrificed, since the name is the part that actually earns the click.
 */
function autoMetaTitle(array $p) {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') { return ''; }
    $category = trim((string)($p['category'] ?? ''));
    $brand    = 'Dievon Atelier';

    $withAll = $category !== '' ? "{$name} — {$category} | {$brand}" : "{$name} | {$brand}";
    if (mb_strlen($withAll) <= 60) { return $withAll; }

    $withBrand = "{$name} | {$brand}";
    if (mb_strlen($withBrand) <= 60) { return $withBrand; }

    return seoTruncate($name, 60);
}

/**
 * Meta description: the product's own description, cleanly truncated to ~155 chars.
 * If the description is too thin to be useful on its own, enrich it with the
 * attributes that shoppers actually search for (fabric, colour, occasion).
 */
function autoMetaDescription(array $p) {
    $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string)($p['description'] ?? ''))));

    if (mb_strlen($desc) < 60) {
        $bits = [];
        foreach (['fabric', 'color', 'pattern', 'occasion'] as $key) {
            $v = trim((string)($p[$key] ?? ''));
            if ($v !== '') { $bits[] = $v; }
        }
        $name     = trim((string)($p['name'] ?? ''));
        $category = trim((string)($p['category'] ?? ''));
        $lead     = $desc !== '' ? $desc : trim($name . ($category !== '' ? " — luxury {$category} by Dievon Atelier." : '.'));
        if ($bits) { $lead .= ' ' . implode(' · ', $bits) . '.'; }
        $desc = trim($lead);
    }
    return seoTruncate($desc, 155);
}

/** URL slug derived from the product name. */
function autoSeoSlug(array $p) {
    $name = trim((string)($p['name'] ?? ''));
    return $name === '' ? '' : slugify($name);
}

/** Keyword set built from the attributes already recorded against the product. */
function autoSeoTags(array $p) {
    $tags = [];
    foreach (['category', 'fabric', 'color', 'pattern', 'occasion', 'sleeve', 'neck', 'brand'] as $key) {
        $v = trim((string)($p[$key] ?? ''));
        if ($v !== '') { $tags[] = mb_strtolower($v); }
    }
    $name = trim((string)($p['name'] ?? ''));
    if ($name !== '') { $tags[] = mb_strtolower($name); }
    $tags[] = 'dievon';

    // Case-insensitive de-dupe that preserves the order above.
    $seen = [];
    $out  = [];
    foreach ($tags as $t) {
        $k = mb_strtolower($t);
        if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $t; }
    }
    return implode(', ', $out);
}

/**
 * Resolve the final SEO values for a product: the admin's explicit entry when
 * present, otherwise the derived one. Single source of truth for the storefront.
 */
function resolveProductSeo(array $p) {
    return [
        'meta_title'       => trim((string)($p['meta_title'] ?? ''))       ?: autoMetaTitle($p),
        'meta_description' => trim((string)($p['meta_description'] ?? '')) ?: autoMetaDescription($p),
        'seo_url'          => trim((string)($p['seo_url'] ?? ''))          ?: autoSeoSlug($p),
        'tags'             => trim((string)($p['tags'] ?? ''))             ?: autoSeoTags($p),
    ];
}

// Creates an upload directory (if missing) and drops in an .htaccess that stops any
// uploaded file being executed as a script.
//
// IMPORTANT: `php_flag` MUST stay wrapped in <IfModule>. It is only a valid directive
// when PHP runs as an Apache module; under CGI/FastCGI/PHP-FPM — which is how MAMP and
// most modern hosts run PHP — an unguarded `php_flag` is an "Invalid command" fatal and
// Apache returns HTTP 500 for *every* request in that directory, including the images.
// That is exactly what previously made all uploaded RMA return photos unviewable.
function hardenUploadDir($absoluteDir) {
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        return false;
    }
    $htaccess = rtrim($absoluteDir, '/') . '/.htaccess';
    if (is_file($htaccess)) {
        return true;
    }
    $rules = <<<'HTACCESS'
# Prevent any uploaded file in this directory from being executed as a script.
# `php_flag` is guarded: unguarded it is fatal under CGI/FPM and 500s every request.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php5.c>
    php_flag engine off
</IfModule>

<FilesMatch "\.(?i:php|phtml|phar|php3|php4|php5|php7|phps|cgi|pl|py|sh|exe|htaccess)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

<IfModule mod_mime.c>
    RemoveHandler .php .phtml .phar .php3 .php4 .php5 .php7 .phps .cgi .pl .py
    RemoveType .php .phtml .phar .php3 .php4 .php5 .php7 .phps
</IfModule>

Options -ExecCGI -Indexes
HTACCESS;
    file_put_contents($htaccess, $rules . "\n");
    return true;
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

