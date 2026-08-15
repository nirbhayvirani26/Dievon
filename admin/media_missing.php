<?php
/**
 * Dievon – Missing file report
 *
 * The reverse of media_cleanup.php. That one finds files with no record; this
 * finds records whose file is gone from disk.
 *
 * Written after the live uploads/ folder was deleted: the database still names
 * every image, so this lists exactly which records are pointing at nothing, and
 * therefore precisely what has to be re-uploaded. Read-only — it changes
 * nothing, anywhere.
 */
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('media.manage');


$activeTab = 'media';
$root = realpath(__DIR__ . '/..');

/**
 * Where each column's files are expected to live.
 * uploads/products is the shared default — products, blog posts, banners and
 * size guide illustrations all write there despite the folder name.
 */
function expectedFolders(string $table, string $col): array {
    if ($table === 'store_settings')      { return ['assets/images/logo', 'uploads/gallery', 'uploads/products']; }
    if ($table === 'size_guide_charts')   { return ['uploads/products', 'uploads/gallery']; }
    if ($table === 'customer_tickets')    { return ['uploads/tickets']; }
    if ($table === 'customer_returns')    { return ['uploads/returns']; }
    if ($table === 'gallery')             { return ['uploads/gallery', 'uploads/products']; }
    if ($table === 'categories')          { return ['uploads/categories', 'uploads/products']; }
    return ['uploads/products', 'uploads/gallery'];
}

/** A human label for the record, so the admin knows what to re-upload. */
function describeRow(PDO $pdo, string $table, $id): string {
    $labels = [
        'products' => 'name', 'blog_posts' => 'title', 'banners' => 'title',
        'categories' => 'name', 'product_colors' => 'name',
    ];
    if (!isset($labels[$table])) { return $table . ' #' . $id; }
    try {
        $s = $pdo->prepare("SELECT `{$labels[$table]}` FROM `$table` WHERE id = :id");
        $s->execute(['id' => $id]);
        $v = $s->fetchColumn();
        return $v !== false && $v !== null ? (string)$v : ($table . ' #' . $id);
    } catch (PDOException $e) {
        return $table . ' #' . $id;
    }
}

$missing = [];
$okCount = 0;

foreach (mediaFileColumns() as [$table, $col]) {
    // store_settings/snapshots hold free text; only scan them for filenames.
    $idCol = in_array($table, ['store_settings'], true) ? 'setting_key' : 'id';
    try {
        $rows = $pdo->query("SELECT `$idCol` AS rid, `$col` AS val FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        continue;   // table/column not on this install
    }

    foreach ($rows as $r) {
        $raw = trim((string)$r['val']);
        if ($raw === '') { continue; }

        // A value may be a bare name, a path, or JSON holding several.
        if (!preg_match_all('/[\w\-. ]+\.(?:jpe?g|png|webp|gif|svg|ico|mp4|webm|mov|pdf)/i', $raw, $m)) { continue; }

        foreach (array_unique($m[0]) as $hit) {
            $file = basename(trim($hit));
            $found = false;
            foreach (expectedFolders($table, $col) as $folder) {
                if (is_file($root . '/' . $folder . '/' . $file)) { $found = true; break; }
            }
            if ($found) { $okCount++; continue; }

            $missing[] = [
                'table' => $table,
                'column' => $col,
                'id' => $r['rid'],
                'file' => $file,
                'label' => describeRow($pdo, $table, $r['rid']),
                'folders' => expectedFolders($table, $col),
            ];
        }
    }
}

// Group by table so the admin can work through one screen at a time.
$byTable = [];
foreach ($missing as $m) { $byTable[$m['table']][] = $m; }
ksort($byTable);

$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';

$whereToFix = [
    'products'             => 'Products → edit the product → Main Image',
    'product_images'       => 'Products → edit the product → Gallery Images',
    'product_colors'       => 'Products → edit the product → Colour Variants → thumbnail',
    'product_color_images' => 'Products → edit the product → Colour Variants → gallery',
    'blog_posts'           => 'Journal → edit the post → Featured Image',
    'banners'              => 'Banners → edit the slide → Image',
    'categories'           => 'Categories → edit the category → Image',
    'store_settings'       => 'Store Settings → logo / favicon, or Size Guide → measurement image',
    'size_guide_charts'    => 'Size Guide → the chart → illustration',
    'customer_tickets'     => 'Customer attachment — cannot be re-uploaded by you',
    'customer_returns'     => 'Customer return photo — cannot be re-uploaded by you',
];
?>

<div class="admin-page-header mc-header">
    <div>
        <h1 class="admin-page-title">🔍 Missing Images</h1>
        <p class="admin-page-subtitle">
            Records that point at a file which is not on the server. This page only reads — it changes nothing.
        </p>
    </div>
    <a href="media_cleanup.php" class="btn-secondary mc-back"><i class="fa-solid fa-broom"></i> Storage Cleanup</a>
</div>

<?php if (empty($missing)): ?>
    <div class="glass-panel mc-panel">
        <p class="mc-empty">✅ Every image referenced in the database is present on the server.
        <?= $okCount ?> file reference(s) checked.</p>
    </div>
<?php else: ?>
    <div class="mc-alert mc-alert-err">
        <?= count($missing) ?> reference(s) point at a file that is not on the server.
        <?= $okCount ?> other(s) are fine.
    </div>

    <?php foreach ($byTable as $table => $items): ?>
    <div class="glass-panel mc-panel">
        <h2 class="mc-section-title"><?= htmlspecialchars($table) ?> — <?= count($items) ?> missing</h2>
        <?php if (isset($whereToFix[$table])): ?>
            <p class="mc-lead">Fix in: <strong><?= htmlspecialchars($whereToFix[$table]) ?></strong></p>
        <?php endif; ?>
        <div class="mc-table-wrap">
            <table class="data-table mc-table">
                <thead><tr><th>Record</th><th>Missing file</th><th>Looked in</th></tr></thead>
                <tbody>
                <?php foreach ($items as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m['label']) ?></td>
                        <td class="mc-file"><?= htmlspecialchars($m['file']) ?></td>
                        <td class="mc-note"><?= htmlspecialchars(implode(', ', $m['folders'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
