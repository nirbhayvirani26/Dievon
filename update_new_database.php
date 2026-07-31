<?php
// ============================================================
//  Dievon – Database update for the latest code deploy
// ------------------------------------------------------------
//  Open this page on the live site AFTER uploading the new code, while
//  logged into /admin. It adds the tables, columns and indexes the new
//  features need.
//
//  Safety properties, all deliberate:
//   * Admin login required — this alters the live database, so it must
//     never be runnable by anyone who simply guesses the URL.
//   * Nothing is destructive. It only ever ADDS. No DROP, no DELETE, no
//     column is ever removed and no existing row is overwritten (the one
//     UPDATE only fills category_id where it is currently NULL).
//   * Idempotent. Every step checks whether it has already been applied,
//     so clicking twice is harmless and simply reports "already done".
//   * MySQL 5.7 compatible — 5.7 has no ADD COLUMN IF NOT EXISTS, so
//     every change is guarded by an INFORMATION_SCHEMA lookup instead.
//   * Nothing runs on GET. You have to press the button.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

// ── Schema inspection helpers (MySQL 5.7 safe) ──────────────────────────────
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

function tableExists(PDO $pdo, string $db, string $table): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t");
    $s->execute(['d' => $db, 't' => $table]);
    return (int)$s->fetchColumn() > 0;
}
function columnExists(PDO $pdo, string $db, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(['d' => $db, 't' => $table, 'c' => $col]);
    return (int)$s->fetchColumn() > 0;
}
function indexExists(PDO $pdo, string $db, string $table, string $index): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND INDEX_NAME=:i");
    $s->execute(['d' => $db, 't' => $table, 'i' => $index]);
    return (int)$s->fetchColumn() > 0;
}
function fkExists(PDO $pdo, string $db, string $table, string $name): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND CONSTRAINT_NAME=:n AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $s->execute(['d' => $db, 't' => $table, 'n' => $name]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Every change this deploy needs, as [label, already-applied test, the SQL].
 * Adding a future migration means appending one row here.
 */
function buildSteps(PDO $pdo, string $db): array {
    $steps = [];

    // ── 1. Support tickets: photo attachment + advisor reply ──
    foreach ([
        ['attachment',  "ALTER TABLE customer_tickets ADD COLUMN attachment VARCHAR(255) NULL DEFAULT NULL AFTER message"],
        ['admin_reply', "ALTER TABLE customer_tickets ADD COLUMN admin_reply TEXT NULL DEFAULT NULL AFTER status"],
        ['replied_at',  "ALTER TABLE customer_tickets ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL AFTER admin_reply"],
        ['updated_at',  "ALTER TABLE customer_tickets ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER replied_at"],
    ] as [$col, $sql]) {
        $steps[] = [
            'group' => 'Support tickets — photo attachment & advisor reply',
            'label' => "customer_tickets.$col",
            'done'  => !tableExists($pdo, $db, 'customer_tickets') || columnExists($pdo, $db, 'customer_tickets', $col),
            'skip'  => !tableExists($pdo, $db, 'customer_tickets') ? 'table customer_tickets not present' : null,
            'sql'   => $sql,
        ];
    }

    // ── 2. Size guide undo snapshots ──
    $steps[] = [
        'group' => 'Size guide — undo history',
        'label' => 'size_guide_snapshots table',
        'done'  => tableExists($pdo, $db, 'size_guide_snapshots'),
        'sql'   => "CREATE TABLE IF NOT EXISTS size_guide_snapshots (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        chart_id INT NOT NULL,
                        category_id INT NULL,
                        product_id INT NULL,
                        label VARCHAR(120) NULL,
                        payload LONGTEXT NOT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_sgs_chart (chart_id),
                        INDEX idx_sgs_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 3. Shop-wide settings table (holds the How-to-Measure image pointers) ──
    // Normally created by admin/settings.php on first visit. If that page has never
    // been opened on this install, uploading the size guide image saves the file but
    // has nowhere to record it — the upload then looks like it did nothing.
    $steps[] = [
        'group' => 'Shop settings & size guide images',
        'label' => 'store_settings table',
        'done'  => tableExists($pdo, $db, 'store_settings'),
        'sql'   => "CREATE TABLE IF NOT EXISTS `store_settings` (
                        `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
                        `setting_value` TEXT DEFAULT NULL,
                        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // ── 4. One shop-wide BODY size table ──
    $steps[] = [
        'group' => 'Size guide — one shared body table',
        'label' => 'size_guide_body_global table',
        'done'  => tableExists($pdo, $db, 'size_guide_body_global'),
        'sql'   => "CREATE TABLE IF NOT EXISTS size_guide_body_global (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        size_label VARCHAR(20) NOT NULL,
                        numeric_size VARCHAR(20) NULL,
                        bust DECIMAL(6,2) NULL,
                        waist DECIMAL(6,2) NULL,
                        hips DECIMAL(6,2) NULL,
                        shoulder DECIMAL(6,2) NULL,
                        sort_order INT NOT NULL DEFAULT 0,
                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_size_label (size_label),
                        INDEX idx_sgbg_sort (sort_order)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    $steps[] = [
        'group' => 'Size guide — one shared body table',
        'label' => 'fill the body table with the standard size ladder',
        // Only when completely empty — never overwrites numbers already entered.
        'done'  => tableExists($pdo, $db, 'size_guide_body_global')
                   && (int)$pdo->query("SELECT COUNT(*) FROM size_guide_body_global")->fetchColumn() > 0,
        'needs' => 'size_guide_body_global table',
        'php'   => function (PDO $pdo) { return seedGlobalBodyRows($pdo); },
    ];

    // ── 3. Products linked to categories by ID, plus filter indexes ──
    $steps[] = [
        'group' => 'Categories — real product link & search indexes',
        'label' => 'products.category_id column',
        'done'  => columnExists($pdo, $db, 'products', 'category_id'),
        'sql'   => "ALTER TABLE products ADD COLUMN category_id INT NULL DEFAULT NULL AFTER category",
    ];
    $steps[] = [
        'group' => 'Categories — real product link & search indexes',
        'label' => 'fill category_id from the existing category names',
        // Re-runnable: only touches rows still missing a link.
        'done'  => columnExists($pdo, $db, 'products', 'category_id')
                   && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id IS NULL AND category IS NOT NULL AND category <> ''")->fetchColumn() === 0,
        'needs' => 'products.category_id column',
        'sql'   => "UPDATE products p JOIN categories c ON LOWER(TRIM(c.name)) = LOWER(TRIM(p.category))
                    SET p.category_id = c.id WHERE p.category_id IS NULL",
    ];
    foreach ([
        ['products',   'idx_products_category_id', "ALTER TABLE products ADD INDEX idx_products_category_id (category_id)"],
        ['products',   'idx_products_available',   "ALTER TABLE products ADD INDEX idx_products_available (available)"],
        ['products',   'idx_products_avail_cat',   "ALTER TABLE products ADD INDEX idx_products_avail_cat (available, category_id)"],
        ['products',   'idx_products_brand',       "ALTER TABLE products ADD INDEX idx_products_brand (brand)"],
        ['products',   'idx_products_fabric',      "ALTER TABLE products ADD INDEX idx_products_fabric (fabric)"],
        ['products',   'idx_products_price',       "ALTER TABLE products ADD INDEX idx_products_price (price)"],
        ['categories', 'idx_categories_parent',    "ALTER TABLE categories ADD INDEX idx_categories_parent (parent_id)"],
    ] as [$tbl, $idx, $sql]) {
        $steps[] = [
            'group' => 'Categories — real product link & search indexes',
            'label' => "$tbl index $idx",
            'done'  => indexExists($pdo, $db, $tbl, $idx),
            'needs' => ($idx === 'idx_products_category_id' || $idx === 'idx_products_avail_cat') ? 'products.category_id column' : null,
            'sql'   => $sql,
        ];
    }
    $steps[] = [
        'group' => 'Categories — real product link & search indexes',
        'label' => 'foreign key products.category_id → categories.id',
        'done'  => fkExists($pdo, $db, 'products', 'fk_products_category'),
        'needs' => 'products.category_id column',
        // SET NULL, never CASCADE: deleting a category must not delete its products.
        'sql'   => "ALTER TABLE products ADD CONSTRAINT fk_products_category
                    FOREIGN KEY (category_id) REFERENCES categories(id)
                    ON DELETE SET NULL ON UPDATE CASCADE",
    ];

    return $steps;
}

$steps    = buildSteps($pdo, $dbName);
$pending  = array_values(array_filter($steps, fn($s) => !$s['done'] && empty($s['skip'])));
$results  = [];
$ranNow   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_update') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $results[] = ['ok' => false, 'label' => 'Security check', 'msg' => 'Invalid session token — reload the page and try again.'];
    } else {
        $ranNow = true;
        foreach ($steps as $s) {
            if (!empty($s['skip'])) { $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Skipped — ' . $s['skip']]; continue; }
            if ($s['done'])         { $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Already applied']; continue; }
            try {
                if (!empty($s['php']) && is_callable($s['php'])) {
                    $n = ($s['php'])($pdo);
                    $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Applied' . ($n ? " ($n rows)" : '')];
                    continue;
                }
                $pdo->exec($s['sql']);
                $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Applied'];
            } catch (PDOException $e) {
                $m = $e->getMessage();
                // Treat "already there" errors as success — another run may have won the race.
                if (stripos($m, 'Duplicate column') !== false || stripos($m, 'Duplicate key') !== false || stripos($m, 'already exists') !== false) {
                    $results[] = ['ok' => true, 'label' => $s['label'], 'msg' => 'Already applied'];
                } else {
                    $results[] = ['ok' => false, 'label' => $s['label'], 'msg' => $m];
                }
            }
        }
        if (function_exists('logAdminAction')) {
            logAdminAction($_SESSION['admin_id'] ?? 1, 'run_db_update', 'Ran update_new_database.php');
        }
        // Recompute so the summary reflects reality rather than what we assumed.
        $steps   = buildSteps($pdo, $dbName);
        $pending = array_values(array_filter($steps, fn($s) => !$s['done'] && empty($s['skip'])));
    }
}

$failed = array_filter($results, fn($r) => !$r['ok']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Database Update — Dievon</title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
<style>
    body { background:#faf8f5; font-family:system-ui,-apple-system,"Segoe UI",sans-serif; color:#241017; margin:0; padding:40px 20px; }
    .wrap { max-width:780px; margin:0 auto; background:#fff; border:1px solid #eae4dc; border-radius:8px; padding:32px; box-shadow:0 10px 40px rgba(40,12,24,.06); }
    h1 { font-family:Georgia,serif; font-weight:400; font-size:26px; margin:0 0 6px; color:#511126; }
    .sub { color:#6b5b62; font-size:14px; margin:0 0 24px; }
    .box { border-radius:6px; padding:14px 16px; margin-bottom:18px; font-size:14px; line-height:1.6; }
    .ok { background:rgba(16,185,129,.09); border-left:3px solid #10b981; }
    .warn { background:rgba(245,158,11,.10); border-left:3px solid #f59e0b; }
    .err { background:rgba(239,68,68,.09); border-left:3px solid #ef4444; }
    .grp { font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:#511126; font-weight:700; margin:20px 0 8px; }
    ul { list-style:none; padding:0; margin:0; }
    li { display:flex; gap:10px; padding:7px 0; border-bottom:1px solid #f2ede7; font-size:13.5px; align-items:baseline; }
    li:last-child { border-bottom:none; }
    .tick { color:#10b981; font-weight:700; }
    .dot  { color:#b9a9b0; }
    .cross{ color:#ef4444; font-weight:700; }
    .muted{ color:#8a8a8a; font-size:12px; }
    button { background:#511126; color:#fff; border:none; padding:14px 30px; border-radius:4px; font-size:13px; font-weight:600;
             letter-spacing:.08em; text-transform:uppercase; cursor:pointer; margin-top:22px; }
    button:hover { background:#6d1832; }
    a.back { display:inline-block; margin-top:18px; color:#511126; font-size:13px; }
    code { background:#faf8f5; padding:1px 5px; border-radius:3px; font-size:12px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Database Update</h1>
    <p class="sub">Applies the database changes required by the latest code. Safe to run more than once.</p>

    <?php if ($ranNow): ?>
        <?php if ($failed): ?>
            <div class="box err">
                <strong><?= count($failed) ?> step(s) failed.</strong> Everything else was applied. The messages below are the raw
                database errors — send them over and they can be sorted out.
            </div>
        <?php else: ?>
            <div class="box ok">
                <strong>Update complete.</strong> Your database now matches the new code. Nothing was deleted or overwritten.
            </div>
        <?php endif; ?>
        <ul>
            <?php foreach ($results as $r): ?>
            <li>
                <span class="<?= $r['ok'] ? 'tick' : 'cross' ?>"><?= $r['ok'] ? '✓' : '✕' ?></span>
                <span><?= htmlspecialchars($r['label']) ?> <span class="muted">— <?= htmlspecialchars($r['msg']) ?></span></span>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!$pending): ?>
        <div class="box ok" style="margin-top:20px;">
            <strong>Nothing left to do.</strong> Every change is already applied — your database is up to date.
        </div>
    <?php else: ?>
        <div class="box warn">
            <strong><?= count($pending) ?> change(s) still to apply.</strong>
            Only additions: new tables, columns and indexes. No data is deleted or overwritten.
        </div>
        <?php
        $lastGroup = null;
        foreach ($steps as $s):
            if ($s['group'] !== $lastGroup) { if ($lastGroup !== null) echo '</ul>'; echo '<div class="grp">' . htmlspecialchars($s['group']) . '</div><ul>'; $lastGroup = $s['group']; }
        ?>
            <li>
                <span class="<?= $s['done'] ? 'tick' : 'dot' ?>"><?= $s['done'] ? '✓' : '○' ?></span>
                <span><?= htmlspecialchars($s['label']) ?>
                    <span class="muted">— <?= !empty($s['skip']) ? htmlspecialchars($s['skip']) : ($s['done'] ? 'already applied' : 'will be added') ?></span>
                </span>
            </li>
        <?php endforeach; if ($lastGroup !== null) echo '</ul>'; ?>

        <form method="POST">
            <input type="hidden" name="action" value="run_update">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <button type="submit">Run the update</button>
        </form>
    <?php endif; ?>

    <a class="back" href="<?= SITE_URL ?>/admin/index.php">&larr; Back to admin</a>
    <p class="muted" style="margin-top:22px;">
        Signed in as admin · database <code><?= htmlspecialchars($dbName) ?></code> ·
        Once your live site is updated and working you can delete this file.
    </p>
</div>
</body>
</html>
