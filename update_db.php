<?php
// ============================================================
//  Dievon – One-click database schema sync
// ------------------------------------------------------------
//  Visit this page (while logged into /admin) any time after deploying
//  updated code. Just requiring config/db.php below re-runs every
//  CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS migration this
//  codebase already uses — the exact same thing that happens silently on
//  every normal page load. Nothing here ever drops a table, deletes a
//  column, or touches existing data — it only ever adds what's missing,
//  and is safe to run as many times as you like.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/config.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

// Owner-level, not just signed in.
// This file issues schema changes against the live database. A login check
// alone let any staff account — content, support, catalogue — run them by
// opening the URL. settings.manage is the permission that already guards
// every other screen able to change how the shop works.
requireAdminCapability('settings.manage');

require_once __DIR__ . '/config/db.php';

$tables = [];
$dbError = '';
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Database Sync – <?= htmlspecialchars(SHOP_NAME) ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f2ee; margin: 0; padding: 40px 20px; color: #2b080f; }
        .wrap { max-width: 640px; margin: 0 auto; }
        .card { background: #ffffff; border: 1px solid #e5e1da; padding: 32px; box-shadow: 0 4px 20px rgba(43,8,15,0.06); }
        .status-icon { font-size: 40px; margin-bottom: 8px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p.sub { color: #6b6459; font-size: 14px; margin: 0 0 24px; line-height: 1.6; }
        .table-list { background: #faf9f6; border: 1px solid #e5e1da; padding: 16px; max-height: 320px; overflow-y: auto; font-size: 13px; line-height: 1.9; color: #4a453d; }
        .table-count { font-weight: 700; color: #2b080f; }
        .error-box { background: #fdf2f2; border: 1px solid #f5c6cb; color: #a94442; padding: 14px 16px; font-size: 13px; }
        .btn-back { display: inline-block; margin-top: 24px; background: #2b080f; color: #fff; text-decoration: none; padding: 12px 22px; font-size: 13px; font-weight: 600; }
        .timestamp { color: #a39d90; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <?php if ($dbError): ?>
                <div class="status-icon">⚠️</div>
                <h1>Could not connect to the database</h1>
                <div class="error-box"><?= htmlspecialchars($dbError) ?></div>
            <?php else: ?>
                <div class="status-icon">✅</div>
                <h1>Database is up to date</h1>
                <p class="sub">Every table and column this codebase needs has been checked and created if it was missing. Nothing existing was changed, dropped, or overwritten.</p>
                <div class="table-list">
                    <span class="table-count"><?= count($tables) ?> tables</span> currently in the database:<br><br>
                    <?= implode(', ', array_map('htmlspecialchars', $tables)) ?>
                </div>
                <div class="timestamp">Checked: <?= date('Y-m-d H:i:s') ?></div>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(SITE_URL) ?>/admin/dashboard.php" class="btn-back">← Back to Admin</a>
        </div>
    </div>
</body>
</html>
