<?php
// ============================================================
//  Dievon – The one database dumper / restorer
// ------------------------------------------------------------
//  Shared by:
//    admin/backup.php      – download a fresh dump, save one to the server,
//                            restore a server-side backup
//    admin/auto_backup.php – the scheduled (cron) backup
//  One implementation so the three can never drift apart, and so the restore
//  path only ever consumes files this shop's own dumper produced.
//
//  Statement boundaries: every statement is followed by its own sentinel line
//  (`-- @DIEVON_STMT_END`). The restorer splits on the sentinel, NOT on `;\n` —
//  a value inside an INSERT is free to contain a literal semicolon or newline
//  without ever splitting a statement in half. Dumps from before the sentinel
//  existed (the two pre-existing database/backups/*.sql files) are still
//  restorable through a `;\n` fallback that also strips comment lines, so the
//  DROP TABLE statements they carry are never lost.
// ============================================================

/** Marker written after every statement so the restorer can split reliably. */
const DIEVON_DUMP_END_MARKER = '-- @DIEVON_STMT_END';

/**
 * Full MySQL dump as a string: SET FOREIGN_KEY_CHECKS=0, DROP + CREATE for
 * every table, then chunked INSERTs (500 rows at a time) so a large table
 * never materialises in memory as one giant statement.
 */
function generateDatabaseDump(PDO $pdo): string
{
    $out  = "-- ============================================================\n";
    $out .= "-- Dievon — Full Database Backup\n";
    $out .= "-- Database: " . DB_NAME . "\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- ============================================================\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n" . DIEVON_DUMP_END_MARKER . "\n";
    $out .= "SET NAMES utf8mb4;\n" . DIEVON_DUMP_END_MARKER . "\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $out .= "-- --------------------------------------------------------\n";
        $out .= "-- Table: `{$table}`\n";
        $out .= "-- --------------------------------------------------------\n\n";

        $out .= "DROP TABLE IF EXISTS `{$table}`;\n" . DIEVON_DUMP_END_MARKER . "\n";
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $out .= $createRow['Create Table'] . ";\n" . DIEVON_DUMP_END_MARKER . "\n";

        $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($rowCount === 0) {
            continue;
        }

        $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $columns  = array_map(fn($c) => $c['Field'], $colsStmt->fetchAll(PDO::FETCH_ASSOC));
        $colList  = '`' . implode('`, `', $columns) . '`';

        $chunkSize = 500;
        for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
            $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) { break; }

            $valueRows = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($columns as $col) {
                    $v = $row[$col];
                    $vals[] = $v === null ? 'NULL' : $pdo->quote($v);
                }
                $valueRows[] = '(' . implode(', ', $vals) . ')';
            }
            $out .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $valueRows) . ";\n"
                  . DIEVON_DUMP_END_MARKER . "\n";
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n" . DIEVON_DUMP_END_MARKER . "\n";
    return $out;
}

/**
 * Keep only the newest $keep backup files in $dir (oldest deleted). Runs on
 * every server-side save so an unattended cron cannot fill the disk forever.
 */
function pruneOldBackups(string $dir, int $keep = 14): void
{
    $files = glob(rtrim($dir, '/') . '/{dievon,dievonfashion}_backup_*.sql', GLOB_BRACE) ?: [];

    // Automatic backups only. A named one is somebody's decision, not rubbish.
    //
    // The glob above matches anything beginning with the backup prefix, so files
    // the owner had made deliberately before a risky change —
    // "..._pre_specifications.sql", "..._pre_sizeguide_overlay.sql" — were
    // counted alongside the nightly ones and deleted the moment fifteen existed.
    // The whole point of a milestone backup is that it outlives the routine
    // ones, and this quietly guaranteed the opposite.
    //
    // An automatic backup is <prefix>_backup_<8 digits>_<6 digits>.sql and
    // nothing else. Anything with a suffix after the timestamp was named by a
    // person and is left alone, however old it gets.
    $automatic = array_values(array_filter(
        $files,
        fn($f) => (bool)preg_match('/_backup_\d{8}_\d{6}\.sql$/', basename($f))
    ));

    usort($automatic, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($automatic, $keep) as $old) {
        @unlink($old);
    }
}

/**
 * Execute a server-side backup file against the database.
 *
 * Only accepts files this shop's own dumper wrote (header marker + the plain
 * DROP/CREATE/INSERT shape the dumper emits — no procedures, no triggers), so
 * the statement splitter can never mangle a phpMyAdmin export or a hand-typed
 * file into partial execution.
 *
 * Returns true on success, or an error message string.
 */
function restoreDatabaseFromFile(PDO $pdo, string $path)
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        return 'Could not read the backup file.';
    }

    // Header marker our dumper always writes. This is the "is this one of ours"
    // test — anything else is refused outright.
    if (strpos($sql, '-- Dievon — Full Database Backup') === false) {
        return 'Not a Dievon backup file (missing the backup header) — refusing to restore.';
    }

    // Split on the sentinel for dumps our current dumper wrote. For legacy dumps
    // (no sentinel) fall back to splitting on ';' + newline. Either way every
    // chunk then has its -- comment lines stripped, so a DROP TABLE glued to its
    // table-header comment is executed rather than discarded as "a comment".
    if (strpos($sql, DIEVON_DUMP_END_MARKER) !== false) {
        $chunks = preg_split('/\n' . preg_quote(DIEVON_DUMP_END_MARKER, '/') . '\s*/', $sql);
    } else {
        $chunks = preg_split('/;\s*\n/', $sql);
    }

    $pending = [];
    $applied = 0;

    // Pass 1. Statements that fail are stashed rather than aborting the whole
    // restore: the dumper emits tables alphabetically, so a table whose foreign
    // key references a later table (product_questions -> products, say) legitimately
    // fails the first time round. Pass 2 retries them once their parents exist.
    foreach ($chunks as $chunk) {
        $stmt = trim((string)preg_replace('/^--.*$/m', '', $chunk));
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
            $applied++;
        } catch (PDOException $e) {
            $pending[] = $stmt;
        }
    }

    // Pass 2: the forward-referencing tables.
    $lastErr = null;
    foreach ($pending as $i => $stmt) {
        try {
            $pdo->exec($stmt);
            $applied++;
            unset($pending[$i]);
        } catch (PDOException $e) {
            $lastErr = $e->getMessage(); // leave it for the failure report below
        }
    }

    if ($pending) {
        $stmt = reset($pending);
        return 'Restore failed part-way through (' . $applied . " statements applied) at:\n"
             . substr($stmt, 0, 120) . "\nError: " . (string)$lastErr
             . "\nA safety backup of the pre-restore database was saved on the server.";
    }

    return true;
}
