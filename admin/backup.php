<?php
// ============================================================
//  Dievon – Full Database Backup (.SQL download)
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_GET['download'])) {
    $activeTab = 'backup';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="glass-panel" style="padding: 28px;">
        <p style="font-size: 14px; color: var(--text-secondary); margin-bottom: 16px;">
            Generates a complete <code>.sql</code> file you can import into phpMyAdmin (or any MySQL client) to restore this exact database state.
        </p>
        <a href="backup.php?download=1" class="btn-primary" style="display: inline-flex; text-decoration: none; padding: 12px 24px; font-size: 14px;">
            <i class="fa-solid fa-download"></i> Generate &amp; Download Full Database Backup (.SQL)
        </a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Generate the actual .sql export ─────────────────────────
logAdminAction($_SESSION['admin_id'] ?? 1, 'download_backup', 'Generated full database backup');

$dbName = DB_NAME;
$filename = 'dievon_backup_' . date('Y-m-d_His') . '.sql';

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

echo "-- ============================================================\n";
echo "-- Dievon Atelier — Full Database Backup\n";
echo "-- Database: {$dbName}\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- ============================================================\n\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n";
echo "SET NAMES utf8mb4;\n\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    echo "-- --------------------------------------------------------\n";
    echo "-- Table: `{$table}`\n";
    echo "-- --------------------------------------------------------\n\n";

    echo "DROP TABLE IF EXISTS `{$table}`;\n";
    $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
    echo $createRow['Create Table'] . ";\n\n";

    $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($rowCount === 0) {
        continue;
    }

    $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $columns = array_map(fn($c) => $c['Field'], $colsStmt->fetchAll(PDO::FETCH_ASSOC));
    $colList = '`' . implode('`, `', $columns) . '`';

    $chunkSize = 500;
    for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
        $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) break;

        $valueRows = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $v = $row[$col];
                $vals[] = $v === null ? 'NULL' : $pdo->quote($v);
            }
            $valueRows[] = '(' . implode(', ', $vals) . ')';
        }
        echo "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $valueRows) . ";\n\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
exit;
