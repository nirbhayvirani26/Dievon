<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('seo.manage');


$activeTab = 'seo';
$successMsg = '';
$errorMsg   = '';

// Auto create seo_settings table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `page_slug` VARCHAR(100) NOT NULL UNIQUE,
        `meta_title` VARCHAR(255) NOT NULL,
        `meta_description` TEXT DEFAULT NULL,
        `meta_keywords` VARCHAR(255) DEFAULT NULL,
        `og_image` VARCHAR(255) DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM seo_settings")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO seo_settings (page_slug, meta_title, meta_description, meta_keywords, og_image) VALUES
            ('home', 'Dievon | Timeless Luxury Women\'s Fashion', 'Discover Dievon\'s hand-crafted Mulberry Silk Kurtis, Brocade 3-Piece Suits, and Organic Linen Coord Sets. Detailed size guides and easy returns across India.', 'luxury fashion, silk kurtis, 3-piece suits, coord sets, dievon', 'lookbook_1.png'),
            ('shop', 'Shop Luxury Collections | Dievon', 'Browse our exclusive collection of kurtis, three-piece suits and co-ord sets. Detailed size guides, secure checkout and easy returns across India.', 'buy kurtis, luxury clothing, silk dresses, designer fashion', 'lookbook_2.png'),
            ('about', 'Our Heritage & Craftsmanship | Dievon', 'Learn about Dievon\'s dedication to sustainable luxury, bespoke tailoring, and heritage embroidery.', 'heritage fashion, luxury atelier, bespoke kurtis', 'lookbook_3.png'),
            ('contact', 'Contact Our Atelier | Dievon Support', 'Get in touch with Dievon customer care for custom fitting, order inquiries, and personal styling.', 'contact dievon, customer care, styling inquiries', 'lookbook_1.png')
        ");
    }
} catch (PDOException $e) {}

// Handle Save SEO Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_seo'])) {
    // This POST had no token check, so any page an admin visited while signed in
    // could have rewritten every meta title and description on the site.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Security token expired — reload this page and save again.";
    } else {
    try {
        // ── Google Search Console ownership token ──
        // Google issues a meta tag for the property; storing the token means the
        // owner can verify the site without anyone editing a PHP file.
        $gsc = trim((string)($_POST['google_site_verification'] ?? ''));
        // Accept either the bare token or the whole <meta> tag Google shows,
        // because pasting the tag is the obvious thing to do.
        if (preg_match('/content=["\']([^"\']+)["\']/', $gsc, $m)) { $gsc = $m[1]; }
        $gsc = preg_replace('/[^A-Za-z0-9_\-]/', '', $gsc);
        $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (:k, :v)
                       ON DUPLICATE KEY UPDATE setting_value = :v2")
            ->execute(['k' => 'google_site_verification', 'v' => $gsc, 'v2' => $gsc]);

        foreach ($_POST['seo'] as $slug => $data) {
            $title = trim($data['meta_title'] ?? '');
            $desc = trim($data['meta_description'] ?? '');
            $keywords = trim($data['meta_keywords'] ?? '');

            // og_image is saved too.
            //
            // The screen is called "SEO Meta & OpenGraph Manager" and the column
            // has always existed, but nothing on the page could set it and this
            // loop never wrote it — so the share picture for every page was
            // whatever had been seeded, and the only way to change it was SQL.
            // The seeded values point at lookbook slots, which means changing a
            // lookbook photo silently changed how the shop looked on WhatsApp.
            $ogImage = basename(trim($data['og_image'] ?? ''));   // basename: no path traversal

            $stmt = $pdo->prepare("UPDATE seo_settings SET meta_title = :title, meta_description = :desc, meta_keywords = :keywords, og_image = :og WHERE page_slug = :slug");
            $stmt->execute(['title' => $title, 'desc' => $desc, 'keywords' => $keywords, 'og' => $ogImage, 'slug' => $slug]);
        }
        $successMsg = "SEO Meta and OpenGraph tags saved successfully!";
    } catch (PDOException $e) {
        $errorMsg = "Error saving SEO settings: " . $e->getMessage();
    }
    }
}

$gscToken = (string)storeSetting($pdo, 'google_site_verification', '');

// ── Indexing readiness ──────────────────────────────────────────────────────
// "Is the site ready for Google?" is otherwise a question nobody can answer
// without knowing where to look. Everything here is checked against the database
// and the filesystem — no request is made back to this site, because a PHP page
// fetching its own URL can occupy the last free worker and hang the server.
$checks = [];
$addCheck = function (string $label, bool $ok, string $detail, string $fix = '') use (&$checks) {
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
};

$addCheck(
    'Search Console ownership',
    $gscToken !== '',
    $gscToken !== '' ? 'Verification tag is being published on every page.' : 'No verification token saved yet.',
    'Add the property in Search Console, choose the HTML tag method, and paste the token above.'
);

$isHttps = stripos(SITE_URL, 'https://') === 0;
$addCheck(
    'Secure address',
    $isHttps,
    $isHttps ? SITE_URL . ' is served over HTTPS.' : SITE_URL . ' is not HTTPS — expected on a local machine, not on the live site.',
    'On the live site, make sure SITE_URL and the certificate both use https://.'
);

$addCheck(
    'Sitemap file',
    is_file(dirname(__DIR__) . '/sitemap.php'),
    'Served at ' . SITE_URL . '/sitemap.xml, rebuilt from the catalogue on every request.',
    'Submit that address under Sitemaps in Search Console.'
);

$addCheck(
    'robots.txt',
    is_file(dirname(__DIR__) . '/robots.php'),
    'Served at ' . SITE_URL . '/robots.txt and points crawlers at the sitemap.',
    ''
);

// Collections that are missing from the sitemap because they have nothing in them.
$emptyCats = [];
$noSlug    = [];
try {
    foreach ($pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $cc) {
        if (empty($cc['slug'])) { $noSlug[] = $cc['name']; }
        $seoRowInfo = categorySeo($pdo, $cc);
        if ((int)$seoRowInfo['product_count'] === 0) { $emptyCats[] = $cc['name']; }
    }
} catch (PDOException $e) {}

$addCheck(
    'Every collection has an address',
    empty($noSlug),
    empty($noSlug) ? 'All collections have a /collections/… address of their own.'
                   : count($noSlug) . ' without one: ' . implode(', ', $noSlug),
    'Open Categories and save an address for each.'
);

// Counted before the collection check below, because "every collection is empty"
// and "there are no products at all" are the same fact reported at two different
// distances, and only the second one is actionable.
try {
    $liveProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
    $noAlt        = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL) AND (image_alt IS NULL OR image_alt = '')")->fetchColumn();
    $archivedProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_deleted = 1")->fetchColumn();
    $draftProducts    = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE available = 0 AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
} catch (PDOException $e) { $liveProducts = 0; $noAlt = 0; $archivedProducts = 0; $draftProducts = 0; }

// The check this page was missing entirely.
//
// With an empty catalogue every other product check passes on a technicality —
// "all 0 live products describe their image" drew a green tick — while the
// collection check filled the panel with one warning per collection. Thirteen
// symptoms, no cause. Nothing here said the one thing that matters: there is
// nothing for Google to index.
$catalogueDetail = $liveProducts > 0
    ? "$liveProducts product" . ($liveProducts === 1 ? '' : 's') . ' can be indexed.'
    : 'No products are visible to Google.'
      . ($archivedProducts > 0 ? " $archivedProducts " . ($archivedProducts === 1 ? 'is' : 'are') . ' archived' : '')
      . ($draftProducts > 0 ? ($archivedProducts > 0 ? ' and ' : ' ') . "$draftProducts " . ($draftProducts === 1 ? 'is a draft' : 'are drafts') : '')
      . ($archivedProducts > 0 || $draftProducts > 0 ? '.' : '');
$addCheck(
    'Products available to index',
    $liveProducts > 0,
    $catalogueDetail,
    $archivedProducts > 0
        ? 'Restore what you still sell under Products → Archived, or publish a draft. Until something is live, the sitemap has only your static pages in it.'
        : 'Add a product and mark it available. Until something is live, the sitemap has only your static pages in it.'
);

$addCheck(
    'Collections with something to sell',
    empty($emptyCats),
    // With no live products at all, listing every collection as empty is thirteen
    // repetitions of the check above rather than thirteen separate problems.
    $liveProducts === 0
        ? 'All ' . count($emptyCats) . ' collections are empty because no products are live — see the check above rather than editing them one by one.'
        : (empty($emptyCats) ? 'Every collection has stock, so all of them are in the sitemap.'
                             : count($emptyCats) . ' empty and left out of the sitemap: ' . implode(', ', $emptyCats)),
    $liveProducts === 0
        ? 'Fix the catalogue first — these collections fill themselves as soon as products are live again.'
        : 'Empty collections are hidden from the menu and the sitemap on purpose. Add stock or delete them.'
);

// Two pages with the same title compete with each other in search results.
$titles = [];
try {
    foreach ($pdo->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $cc) {
        $t = categorySeo($pdo, $cc)['title'];
        $titles[$t][] = $cc['name'];
    }
} catch (PDOException $e) {}
$dupeTitles = array_filter($titles, fn($v) => count($v) > 1);
$addCheck(
    'Each collection has its own title',
    empty($dupeTitles),
    empty($dupeTitles) ? count($titles) . ' collections, ' . count($titles) . ' different titles.'
                       : 'Shared titles: ' . implode(' / ', array_map(fn($v) => implode(' + ', $v), $dupeTitles)),
    'Give one of them its own title below.'
);

// ($liveProducts / $noAlt are counted further up, alongside the catalogue check.)
// The policy pages state who the customer is dealing with and where to complain.
// Where a value is missing they show a visible gap rather than an invention, so
// the gaps are worth surfacing here too.
$legalGaps = legalIdentityGaps($pdo);
$addCheck(
    'Business identity on the policy pages',
    empty($legalGaps),
    empty($legalGaps)
        ? 'Legal name, address, jurisdiction and grievance officer are all published.'
        : count($legalGaps) . ' missing, shown as a marked gap on the live pages: ' . implode(', ', $legalGaps),
    'Fill these in under Store Settings. The Consumer Protection (E-Commerce) Rules, 2020 require a '
    . 'named grievance officer with contact details, acknowledged within 48 hours and resolved within a month.'
);

// Data faults that reach Google: another label's trademark published as the
// brand, a fabric that contradicts the garment's own name, a missing photograph.
// No amount of code can fix these — only the person who owns the catalogue can.
foreach (catalogueDataProblems($pdo) as $cp) {
    $addCheck($cp['label'], false, $cp['detail'] . ' — ' . implode('; ', array_slice($cp['ids'], 0, 12))
        . (count($cp['ids']) > 12 ? ' …' : ''), 'Fix these in Products before submitting anything to Google.');
}

// "All 0 live products describe their image" was a green tick on an empty
// catalogue — technically true, and the exact opposite of the situation. A check
// with nothing to check reports that, rather than passing.
$addCheck(
    'Product images have a description',
    $liveProducts > 0 && $noAlt === 0,
    $liveProducts === 0
        ? 'Nothing to check — no products are live.'
        : ($noAlt === 0 ? "All $liveProducts live products describe their image."
                        : "$noAlt of $liveProducts live products have no image description."),
    $liveProducts === 0
        ? 'This starts working again once a product is live.'
        : 'Fill in "Image description" on each product. It is what Google Images indexes and what a screen reader reads out.'
);

$seoPages = [];
try {
    $seoPages = $pdo->query("SELECT * FROM seo_settings ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">⚙️ SEO Meta &amp; OpenGraph Manager</h1>
        <p class="admin-page-subtitle">Configure Google search engine titles, descriptions, and social media share previews for each page.</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<!-- Educational Guide Box -->
<div style="background:var(--bg-surface-soft); border:1px solid var(--border-light); border-radius:8px; padding:20px; margin-bottom:24px;">
    <h3 style="font-size:15px; font-weight:700; color:var(--text-primary); margin-top:0; margin-bottom:8px;">💡 How to Use SEO Meta &amp; OpenGraph Settings:</h3>
    <ul style="margin:0; padding-left:20px; font-size:13px; color:var(--text-secondary); line-height:1.7;">
        <li><strong>Meta Title:</strong> The blue title link that appears on Google Search results (keep under 60 characters).</li>
        <li><strong>Meta Description:</strong> The summary paragraph shown under the Google title link (keep under 155 characters).</li>
        <li><strong>Meta Keywords:</strong> Comma-separated search terms for search crawlers (e.g. <code>silk kurtis, luxury fashion, dievon</code>).</li>
        <li><strong>OpenGraph Preview:</strong> Automatic preview card shown when customers share your links on <strong>WhatsApp, Instagram, or Facebook</strong>.</li>
    </ul>
</div>

<form action="seo.php" method="POST">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

    <div class="glass-panel seo-panel">
        <div class="seo-panel-head">
            <span>✅ Indexing readiness</span>
        </div>
        <div class="seo-panel-body">
            <ul class="seo-check-list">
                <?php foreach ($checks as $c): ?>
                <li class="seo-check <?= $c['ok'] ? 'is-ok' : 'is-warn' ?>">
                    <span class="seo-check-mark"><?= $c['ok'] ? '✓' : '!' ?></span>
                    <span class="seo-check-body">
                        <strong><?= htmlspecialchars($c['label']) ?></strong>
                        <span><?= htmlspecialchars($c['detail']) ?></span>
                        <?php if (!$c['ok'] && $c['fix'] !== ''): ?>
                        <em><?= htmlspecialchars($c['fix']) ?></em>
                        <?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="seo-manual">
                <strong>Then, inside Search Console — these cannot be checked from here:</strong>
                <ol>
                    <li>Add the property and verify it with the token below.</li>
                    <li>Sitemaps → submit <code>sitemap.xml</code>.</li>
                    <li>URL Inspection → test the home page, one collection and one product; press <em>Request indexing</em> on each.</li>
                    <li>Pages → check what is indexed and read the reasons given for anything excluded.</li>
                    <li>Manual actions and Security issues → both should say no issues.</li>
                    <li>Experience → HTTPS, and Core Web Vitals once there is enough traffic to report on.</li>
                    <li>Shopping → Merchant listings, which reports on the product data this site already publishes.</li>
                </ol>
                <p>Steps 3 to 7 need a few days of crawling before they show anything, so verify and submit the sitemap first.</p>
            </div>
        </div>
    </div>

    <div class="glass-panel seo-panel">
        <div class="seo-panel-head">
            <span>🔍 Google Search Console</span>
        </div>
        <div class="seo-panel-body">
            <div class="form-group seo-field">
                <label class="form-label">Site verification token</label>
                <input type="text" name="google_site_verification" class="form-control"
                       value="<?= htmlspecialchars($gscToken) ?>"
                       placeholder="e.g. AbCdEf1234_gHiJkLmNoPqRsTuVwXyZ">
                <p class="form-hint">
                    In Search Console choose <strong>HTML tag</strong> as the verification method and paste
                    what Google gives you here — the whole <code>&lt;meta&gt;</code> tag is fine, the token
                    is taken out of it. Save, then press Verify in Search Console.
                    Leave blank once verified through DNS instead.
                </p>
            </div>
            <p class="form-hint">
                Sitemap to submit: <code><?= htmlspecialchars(SITE_URL) ?>/sitemap.xml</code>
            </p>
        </div>
    </div>

    <div class="glass-panel" style="padding:0; overflow:hidden; margin-bottom:24px;">
        <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
            <span>🌐 Website Page Meta Settings (<?= count($seoPages) ?> Pages)</span>
            <button type="submit" name="save_seo" class="btn-primary" style="padding:8px 20px; font-size:13px;">
                <i class="fa-solid fa-floppy-disk"></i> Save SEO Settings
            </button>
        </div>

        <div style="padding:24px; display:flex; flex-direction:column; gap:24px;">
            <?php foreach ($seoPages as $sp): ?>
                <div style="background:#ffffff; border:1px solid var(--border-light); border-radius:8px; padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border-light); padding-bottom:10px;">
                        <span style="font-size:16px; font-weight:700; color:var(--color-primary); text-transform:uppercase; letter-spacing:1px;">
                            📄 <?= htmlspecialchars(strtoupper($sp['page_slug'])) ?> PAGE
                        </span>
                        <code style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars(SITE_URL . '/' . ($sp['page_slug']==='home' ? '' : $sp['page_slug'])) ?></code>
                    </div>

                    <?php // The home page can write these two for itself, from whatever is
                          // genuinely in stock — so an empty field is a real choice here,
                          // not an unfinished one. Saying so in the form because otherwise
                          // the only way to discover it is to read config.php. ?>
                    <?php $sgAuto = ($sp['page_slug'] === 'home'); ?>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">Google Meta Title</label>
                        <input type="text" name="seo[<?= $sp['page_slug'] ?>][meta_title]" class="form-control" value="<?= htmlspecialchars($sp['meta_title']) ?>" placeholder="<?= $sgAuto ? 'Leave empty to write itself from your live stock' : 'Title for Google Search' ?>">
                        <?php if ($sgAuto): ?>
                            <small style="display:block; margin-top:5px; color:var(--text-muted); font-size:12px;">
                                <strong>Leave both boxes empty and this page writes its own.</strong>
                                It lists the categories that actually have stock and says
                                &ldquo;womenswear&rdquo;, &ldquo;menswear&rdquo; or &ldquo;fashion&rdquo; to match —
                                so the day you put a men&rsquo;s shirt on sale, Google is told, and you
                                need not remember this screen exists. Right now it would say:<br>
                                <em style="color:var(--text-secondary);">&ldquo;<?= htmlspecialchars(dievonHomeTitle($pdo)) ?>&rdquo;</em><br>
                                Type something here only when you want to overrule that.
                            </small>
                        <?php endif; ?>
                    </div>

                    <?php // The picture WhatsApp, Facebook and X show when this page is
                          //  shared. Listed from uploads/gallery so a filename cannot be
                          //  typed wrong, and saved through basename() so a path cannot be
                          //  smuggled in. Empty falls back to the shop logo. ?>
                    <?php
                        $sgGallery = array_values(array_filter(
                            scandir(__DIR__ . '/../uploads/gallery') ?: [],
                            fn($x) => preg_match('/\.(jpe?g|png|webp)$/i', $x)
                        ));
                        sort($sgGallery);
                    ?>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">Share Image (OpenGraph)</label>
                        <select name="seo[<?= $sp['page_slug'] ?>][og_image]" class="form-control">
                            <option value="">— use the shop logo —</option>
                            <?php foreach ($sgGallery as $gimg): ?>
                                <option value="<?= htmlspecialchars($gimg) ?>" <?= ($sp['og_image'] ?? '') === $gimg ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gimg) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($sp['og_image'])): ?>
                            <small style="display:block; margin-top:5px; color:var(--text-muted); font-size:12px;">
                                Currently: <?= htmlspecialchars($sp['og_image']) ?> —
                                shown when someone shares this page in a message or on social media.
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">Google Meta Description</label>
                        <textarea name="seo[<?= $sp['page_slug'] ?>][meta_description]" class="form-control" rows="2" placeholder="<?= $sgAuto ? 'Leave empty to write itself from your live stock' : 'Summary paragraph for Google Search' ?>"><?= htmlspecialchars($sp['meta_description']) ?></textarea>
                        <?php if ($sgAuto): ?>
                            <small style="display:block; margin-top:5px; color:var(--text-muted); font-size:12px;">
                                Empty gives you:
                                <em style="color:var(--text-secondary);">&ldquo;<?= htmlspecialchars(dievonDefaultMetaDescription($pdo)) ?>&rdquo;</em>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Search Keywords (Comma Separated)</label>
                        <input type="text" name="seo[<?= $sp['page_slug'] ?>][meta_keywords]" class="form-control" value="<?= htmlspecialchars($sp['meta_keywords']) ?>" placeholder="e.g. silk kurtis, luxury fashion, dievon">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
