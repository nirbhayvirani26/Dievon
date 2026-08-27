<?php
/*
 * ═══════════════════════════════════════════════════════════════════════
 *  HEALTH CHECK  (admin/health.php)
 * ───────────────────────────────────────────────────────────────────────
 *  The questions worth asking before a deploy, answered against THIS
 *  server rather than against someone's memory of it.
 *
 *  Most of what breaks a live shop is not code — it is a setting that is
 *  right on the laptop and wrong on the host. MAIL_TEST_MODE is the worst
 *  of them: it defaults to TRUE, and .env.example ships it TRUE, so a
 *  shop whose .env was copied from the example sends every order
 *  confirmation to the owner's own inbox marked [TEST] and not one
 *  customer ever hears from it. Nothing on any screen says so. That is
 *  the kind of thing this page exists to shout about.
 *
 *  Strictly READ-ONLY. It runs SELECTs, reads settings and stats files,
 *  and writes nothing — so it is safe to open on the live shop at any
 *  time, including mid-trade.
 *
 *  Secrets are never printed. A credential is reported as set or not set;
 *  its value is not shown, because this page is a screenshot away from a
 *  support chat.
 * ═══════════════════════════════════════════════════════════════════════
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

/* Owner only. The findings name which settings are missing and which
   tables are absent — a map of where this installation is soft — so it
   sits behind the same capability as Store Settings rather than being
   readable by anyone who can process an order. */
requireAdminCapability('settings.manage');

$activeTab = 'health';

/* ── The result collector ────────────────────────────────────────────
   Three states, and the middle one earns its place: a WARN is something
   that is not broken here and now but will bite on a particular day —
   an empty Razorpay key on a shop taking only COD, say. Reporting those
   as failures would train the reader to ignore red, which is how the
   real failure gets missed. */
$groups = [];
$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
function hcAdd(string $group, string $state, string $label, string $detail = ''): void {
    global $groups, $counts;
    $groups[$group][] = ['state' => $state, 'label' => $label, 'detail' => $detail];
    $counts[$state] = ($counts[$state] ?? 0) + 1;
}

// ── 1. Email delivery ────────────────────────────────────────────────
$G = 'Email delivery';

$testMode = EnvLoader::get('MAIL_TEST_MODE', null);
if ($testMode === null || $testMode === '') {
    hcAdd($G, 'fail', 'MAIL_TEST_MODE is not set',
        'It defaults to TRUE when absent, which sends every customer email to the admin address with "[TEST]" '
      . 'in the subject. Customers receive nothing. Add MAIL_TEST_MODE=false to .env.');
} elseif (filter_var($testMode, FILTER_VALIDATE_BOOLEAN)) {
    hcAdd($G, 'fail', 'MAIL_TEST_MODE is ON',
        'Every customer email is being redirected to the admin address and prefixed "[TEST]". '
      . 'No customer is receiving order confirmations. Set MAIL_TEST_MODE=false.');
} else {
    hcAdd($G, 'pass', 'Test mode off — customers receive their own email');
}

foreach (['MAIL_HOST' => 'SMTP host', 'MAIL_USERNAME' => 'SMTP username', 'MAIL_PASSWORD' => 'SMTP password'] as $key => $what) {
    $set = trim((string)EnvLoader::get($key, '')) !== '';
    $set ? hcAdd($G, 'pass', $what . ' is set')
         : hcAdd($G, 'fail', $what . ' is missing', 'Without it no mail can be sent at all.');
}

/* The port decides the handshake — see the note in EmailService::sendMail().
   465 is implicit TLS, 587 is STARTTLS, and pairing the wrong label with the
   wrong port is what makes sending hang until timeout rather than fail. */
$port = (int)EnvLoader::get('MAIL_PORT', 0);
if (!$port) {
    hcAdd($G, 'warn', 'MAIL_PORT is not set', 'Falls back to 465. Set it explicitly so the handshake is not a guess.');
} elseif (in_array($port, [465, 587], true)) {
    hcAdd($G, 'pass', 'Mail port is ' . $port . ' (' . ($port === 465 ? 'implicit TLS' : 'STARTTLS') . ')');
} else {
    hcAdd($G, 'warn', 'Mail port ' . $port . ' is unusual', 'Expected 465 or 587.');
}

/* Links and images inside mail. SITE_URL is derived per request and config.php
   treats CLI as local, so anything sent from a cron job embeds
   http://localhost/... unless this is set. */
$publicUrl = trim((string)EnvLoader::get('SITE_PUBLIC_URL', ''));
if ($publicUrl === '') {
    hcAdd($G, 'warn', 'SITE_PUBLIC_URL is not set',
        'Mail sent from a scheduled task will embed localhost links and its images will not load. '
      . 'Set it to the shop address, e.g. https://dievon.com');
} elseif (!str_starts_with($publicUrl, 'https://')) {
    hcAdd($G, 'warn', 'SITE_PUBLIC_URL is not https', 'Currently: ' . htmlspecialchars($publicUrl));
} else {
    hcAdd($G, 'pass', 'Email links point at ' . htmlspecialchars($publicUrl));
}

/* Recent failures, from the log the mailer already writes. A shop can send
   into a black hole for weeks; this is the cheapest place to notice. */
try {
    $recent = $pdo->query(
        "SELECT status, COUNT(*) c FROM email_logs
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $failed = (int)($recent['failed'] ?? 0);
    $sent   = (int)($recent['sent'] ?? 0);
    if ($failed > 0) {
        hcAdd($G, 'fail', $failed . ' email(s) failed in the last 7 days',
            $sent . ' sent successfully. Open the Email Log for the reason on each.');
    } elseif ($sent > 0) {
        hcAdd($G, 'pass', $sent . ' email(s) sent in the last 7 days, none failed');
    } else {
        hcAdd($G, 'warn', 'No email sent in the last 7 days',
            'Normal on a quiet week; suspicious on a busy one.');
    }
} catch (PDOException $e) {
    hcAdd($G, 'warn', 'email_logs table not readable', 'Delivery cannot be audited until it exists.');
}

// ── 2. Payments ──────────────────────────────────────────────────────
$G = 'Payments';
$rzpKey    = trim((string)EnvLoader::get('RAZORPAY_KEY_ID', ''));
$rzpSecret = trim((string)EnvLoader::get('RAZORPAY_KEY_SECRET', ''));
$rzpHook   = trim((string)EnvLoader::get('RAZORPAY_WEBHOOK_SECRET', ''));
if ($rzpKey === '' || $rzpSecret === '') {
    hcAdd($G, 'warn', 'Razorpay keys are not set',
        'Fine for a shop taking only Cash on Delivery. Online payment will not work without them.');
} else {
    hcAdd($G, 'pass', 'Razorpay key and secret are set');
    /* A test key on a live shop takes no real money and is easy to leave
       behind after testing, because nothing about the checkout looks wrong. */
    if (str_starts_with($rzpKey, 'rzp_test_')) {
        hcAdd($G, 'fail', 'Razorpay is in TEST mode',
            'The key begins rzp_test_. Real cards will not be charged. Swap in the live key before trading.');
    } else {
        hcAdd($G, 'pass', 'Razorpay key is a live key');
    }
    $rzpHook === ''
        ? hcAdd($G, 'warn', 'Razorpay webhook secret is not set',
            'Payments confirmed by webhook cannot be verified, so an online order may never be marked paid.')
        : hcAdd($G, 'pass', 'Razorpay webhook secret is set');
}

// ── 3. Database ──────────────────────────────────────────────────────
$G = 'Database';
$needTables = [
    'products', 'product_variants', 'product_colors', 'product_images', 'product_attributes',
    'categories', 'orders', 'order_items', 'customers', 'customer_returns', 'order_refunds',
    'customer_tickets', 'email_logs', 'login_attempts', 'admin_users',
];
$present = [];
try {
    foreach ($pdo->query("SHOW TABLES") as $row) { $present[strtolower((string)reset($row))] = true; }
    $missing = array_values(array_filter($needTables, fn($t) => !isset($present[$t])));
    $missing
        ? hcAdd($G, 'fail', count($missing) . ' expected table(s) missing', implode(', ', $missing)
              . ' — run update_new_database.php to create them.')
        : hcAdd($G, 'pass', 'All ' . count($needTables) . ' expected tables present');
} catch (PDOException $e) {
    hcAdd($G, 'fail', 'Cannot list tables', $e->getMessage());
}

/* utf8mb4 end to end, or the rupee sign and every emoji in a product name
   arrive mangled — and mangled on the way IN is not fixable on the way out. */
try {
    $cs = $pdo->query("SELECT @@character_set_database AS cs, @@collation_database AS co")->fetch(PDO::FETCH_ASSOC);
    str_starts_with((string)$cs['cs'], 'utf8mb4')
        ? hcAdd($G, 'pass', 'Database charset is ' . $cs['cs'])
        : hcAdd($G, 'fail', 'Database charset is ' . $cs['cs'] . ', not utf8mb4',
            'The rupee symbol and emoji will be stored corrupted.');
} catch (PDOException $e) {
    hcAdd($G, 'warn', 'Could not read the database charset');
}

// ── 4. Catalogue ─────────────────────────────────────────────────────
$G = 'Catalogue';
try {
    $live = "available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)";
    $n = fn($sql) => (int)$pdo->query($sql)->fetchColumn();

    $total = $n("SELECT COUNT(*) FROM products WHERE $live");
    $total > 0 ? hcAdd($G, 'pass', $total . ' product(s) live')
               : hcAdd($G, 'fail', 'No live products', 'The shop has nothing to sell.');

    $noImg = $n("SELECT COUNT(*) FROM products WHERE $live AND (image IS NULL OR image = '')");
    $noImg ? hcAdd($G, 'warn', $noImg . ' live product(s) have no photograph',
                'They appear in listings as a blank tile.')
           : hcAdd($G, 'pass', 'Every live product has a photograph');

    $noTax = $n("SELECT COUNT(*) FROM products WHERE $live AND (hsn_code IS NULL OR hsn_code = '' OR gst_rate IS NULL OR gst_rate <= 0)");
    $noTax ? hcAdd($G, 'warn', $noTax . ' live product(s) have no HSN or GST rate',
                'Their invoices print as an "Order Summary" rather than a tax invoice.')
           : hcAdd($G, 'pass', 'Every live product carries HSN and GST');

    /* Values a product holds that its managed list does not know. These are
       what the reconciliation panel in Filters & Attributes exists to merge;
       left alone they become filter chips that match one garment. */
    if (function_exists('dievonStrayAttributes')) {
        $strayTotal = 0; $strayWhere = [];
        foreach (array_keys(DIEVON_ATTR_TYPES) as $type) {
            $s = dievonStrayAttributes($pdo, $type);
            if ($s) { $strayTotal += count($s); $strayWhere[] = $type . ': ' . implode(', ', array_slice(array_column($s, 'value'), 0, 4)); }
        }
        $strayTotal
            ? hcAdd($G, 'warn', $strayTotal . ' attribute value(s) are not on their list',
                implode(' · ', $strayWhere) . ' — reconcile them under Filters & Attributes.')
            : hcAdd($G, 'pass', 'Every attribute value is on its managed list');
    }
} catch (PDOException $e) {
    hcAdd($G, 'warn', 'Catalogue checks could not run', $e->getMessage());
}

// ── 5. Orders ────────────────────────────────────────────────────────
$G = 'Orders';
try {
    $stuck = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders
          WHERE COALESCE(is_deleted,0) = 0 AND status = 'Pending'
            AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)"
    )->fetchColumn();
    $stuck ? hcAdd($G, 'warn', $stuck . ' order(s) have sat on Pending for over 3 days',
                'A shopper watching their order page has been told nothing since they bought.')
           : hcAdd($G, 'pass', 'No orders stuck on Pending');

    $openRma = (int)$pdo->query("SELECT COUNT(*) FROM customer_returns WHERE status = 'Pending'")->fetchColumn();
    $openRma ? hcAdd($G, 'warn', $openRma . ' return request(s) awaiting a decision')
             : hcAdd($G, 'pass', 'No return requests waiting');

    $openTix = (int)$pdo->query("SELECT COUNT(*) FROM customer_tickets WHERE status IN ('Open','Pending')")->fetchColumn();
    $openTix ? hcAdd($G, 'warn', $openTix . ' support ticket(s) open')
             : hcAdd($G, 'pass', 'No support tickets open');
} catch (PDOException $e) {
    hcAdd($G, 'warn', 'Order checks could not run', $e->getMessage());
}

// ── 6. Files and permissions ─────────────────────────────────────────
$G = 'Files & permissions';
foreach (['uploads/products', 'uploads/banners', 'uploads/returns', 'uploads/blog'] as $rel) {
    $dir = __DIR__ . '/../' . $rel;
    if (!is_dir($dir)) {
        hcAdd($G, 'warn', $rel . ' does not exist', 'It is created on first upload; nothing to do unless uploads fail.');
    } elseif (!is_writable($dir)) {
        hcAdd($G, 'fail', $rel . ' is not writable', 'Uploads to it will fail silently.');
    } else {
        hcAdd($G, 'pass', $rel . ' is writable');
    }
}

/* .env holds the SMTP password and the Razorpay secret. .htaccess denies it,
   but only if .htaccess is being read — which it is not under nginx, and not
   if AllowOverride is off. Worth knowing rather than assuming. */
$envPath = __DIR__ . '/../.env';
if (!is_file($envPath)) {
    hcAdd($G, 'fail', '.env not found', 'The shop is running entirely on defaults.');
} else {
    /* The full path, not just ".env". Read on one machine and acted on on
       another, the bare filename says nothing about WHICH copy is being
       reported -- local or live -- and a permission changed on the wrong one
       leaves the warning standing with no clue why. Names the file so the
       fix can land on the file actually being measured. */
    $envReal = realpath($envPath) ?: $envPath;
    hcAdd($G, 'pass', '.env present', $envReal);
    /* fileperms() answers from PHP's stat cache, which can still hold the
       reading taken before a chmod earlier in this same request. */
    clearstatcache(true, $envPath);
    $perms = substr(sprintf('%o', fileperms($envPath)), -3);

    /* The rule that actually keeps .env off the web, checked in the file rather
       than by requesting the URL -- a PHP page fetching its own site can take
       the last free worker and hang. */
    $htaccess    = @file_get_contents(__DIR__ . '/../.htaccess') ?: '';
    $envWebBlock = (bool)preg_match('/^\s*(<FilesMatch|RewriteRule).*\\\.env/mi', $htaccess);

    $envWebBlock
        ? hcAdd($G, 'pass', '.env is blocked from the web', '.htaccess denies it directly.')
        : hcAdd($G, 'fail', '.env is NOT blocked from the web',
                'Anyone could open ' . SITE_URL . '/.env and read every password in it. '
              . 'Restore the FilesMatch and RewriteRule that deny it in .htaccess.');

    /* World-readable matters on hosting where another account shares the
       filesystem. It is a WARNING and not a failure because the fix is not
       universally safe: where PHP runs as a user other than the file's owner --
       common on shared hosting -- 640 stops the shop reading its own database
       password, and the whole site answers "Database Connection Error" until
       644 is put back. Telling an owner to run a command that can take their
       shop down, without saying so, is worse than not checking at all. */
    if (((int)$perms % 10) > 0) {
        hcAdd($G, 'warn', '.env is world-readable (permissions ' . $perms . ')',
              ($envWebBlock ? 'The web cannot reach it, so this only concerns other accounts on the same server. ' : '')
            . 'Try: chmod 640 ' . $envReal . ' — that exact file, not .env.example. '
            . 'If the site then shows "Database Connection Error", this host runs PHP as a different '
            . 'user and needs ' . $perms . '; put it back with chmod ' . $perms . ' and leave it. '
            . 'Rotating the passwords inside is the protection that does not depend on the host.');
    } else {
        hcAdd($G, 'pass', '.env permissions are ' . $perms, $envReal);
    }
}

// ── 7. PHP ───────────────────────────────────────────────────────────
$G = 'PHP';
version_compare(PHP_VERSION, '8.1', '>=')
    ? hcAdd($G, 'pass', 'PHP ' . PHP_VERSION)
    : hcAdd($G, 'fail', 'PHP ' . PHP_VERSION . ' is older than 8.1');

foreach (['pdo_mysql' => 'database', 'gd' => 'image resizing', 'mbstring' => 'text handling',
          'openssl' => 'secure mail and payments', 'curl' => 'Razorpay and Shiprocket',
          'json' => 'every AJAX response'] as $ext => $why) {
    extension_loaded($ext)
        ? hcAdd($G, 'pass', $ext . ' loaded')
        : hcAdd($G, 'fail', $ext . ' is missing', 'Needed for ' . $why . '.');
}

/* display_errors on a live shop puts PHP notices into whatever is being
   produced — including a CSV download, where they land inside the file the
   accountant opens. */
if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN)) {
    hcAdd($G, 'fail', 'display_errors is ON',
        'PHP notices are being printed into pages and into file downloads. Turn it off in production.');
} else {
    hcAdd($G, 'pass', 'display_errors is off');
}

$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>
<div class="admin-card">
    <div class="admin-card-head" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">Health Check</h2>
            <p style="margin:4px 0 0; font-size:12px; color:var(--text-muted);">
                Read-only. Nothing on this page changes anything — safe to run at any time.
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <span class="hc-pill hc-pass"><?= (int)$counts['pass'] ?> passed</span>
            <span class="hc-pill hc-warn"><?= (int)$counts['warn'] ?> to look at</span>
            <span class="hc-pill hc-fail"><?= (int)$counts['fail'] ?> failing</span>
            <a href="health.php" class="btn-admin-outline" style="padding:8px 16px; font-size:12px;">Re-run</a>
        </div>
    </div>

    <?php if ($counts['fail'] > 0): ?>
    <div class="hc-banner hc-banner-fail">
        <strong><?= (int)$counts['fail'] ?> thing<?= $counts['fail'] === 1 ? '' : 's' ?> need attention before trading.</strong>
        Each one below says what it means and what to do.
    </div>
    <?php elseif ($counts['warn'] > 0): ?>
    <div class="hc-banner hc-banner-warn">
        Nothing is broken. <?= (int)$counts['warn'] ?> item<?= $counts['warn'] === 1 ? ' is' : 's are' ?> worth a look.
    </div>
    <?php else: ?>
    <div class="hc-banner hc-banner-pass">Everything checked is in order.</div>
    <?php endif; ?>

    <?php foreach ($groups as $groupName => $rows): ?>
    <div class="hc-group">
        <h3 class="hc-group-title"><?= htmlspecialchars($groupName) ?></h3>
        <?php foreach ($rows as $r): ?>
        <div class="hc-row hc-row-<?= $r['state'] ?>">
            <span class="hc-tag hc-<?= $r['state'] ?>"><?= strtoupper($r['state']) ?></span>
            <div>
                <div class="hc-label"><?= htmlspecialchars($r['label']) ?></div>
                <?php if ($r['detail'] !== ''): ?>
                <div class="hc-detail"><?= htmlspecialchars($r['detail']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<style>
/* Scoped to this page — it is the only screen that shows a pass/fail ledger. */
.hc-pill { padding:5px 12px; font-size:11px; font-weight:700; letter-spacing:.04em; }
.hc-pill.hc-pass { background:#e7f4ec; color:#1c6b3f; }
.hc-pill.hc-warn { background:#fdf3e0; color:#8a5a12; }
.hc-pill.hc-fail { background:#fbe9e9; color:#8c1c1c; }
.hc-banner { margin:16px 0 22px; padding:13px 16px; font-size:13px; border-left:3px solid; }
.hc-banner-pass { background:#f2f9f5; border-color:#1c6b3f; color:#1c6b3f; }
.hc-banner-warn { background:#fdf8ef; border-color:#c68b26; color:#8a5a12; }
.hc-banner-fail { background:#fdf2f2; border-color:#8c1c1c; color:#8c1c1c; }
.hc-group { margin-bottom:26px; }
.hc-group-title { margin:0 0 10px; font-size:12px; letter-spacing:.1em; text-transform:uppercase; color:var(--text-muted); }
.hc-row { display:flex; gap:12px; align-items:flex-start; padding:11px 14px; border:1px solid var(--border-light); border-bottom:none; background:var(--bg-surface); }
.hc-group .hc-row:last-child { border-bottom:1px solid var(--border-light); }
.hc-row-fail { background:#fffafa; }
.hc-tag { flex:0 0 auto; min-width:46px; text-align:center; padding:3px 0; font-size:10px; font-weight:700; letter-spacing:.06em; }
.hc-tag.hc-pass { background:#e7f4ec; color:#1c6b3f; }
.hc-tag.hc-warn { background:#fdf3e0; color:#8a5a12; }
.hc-tag.hc-fail { background:#fbe9e9; color:#8c1c1c; }
.hc-label { font-size:13px; font-weight:600; color:var(--text-primary); }
.hc-detail { margin-top:3px; font-size:12px; line-height:1.5; color:var(--text-muted); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
