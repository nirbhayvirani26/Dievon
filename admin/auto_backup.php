<?php
// ============================================================
//  Dievon – Scheduled automatic backup
// ------------------------------------------------------------
//  Usage (pick one):
//    1. Server-side cron (recommended — works even if PHP has no
//       web exposure). Add a cron job on the host:
//         php /home/<user>/public_html/admin/auto_backup.php
//    2. Web-cron with the token from .env (BACKUP_CRON_TOKEN):
//         wget -q -O /dev/null "https://dievon.com/admin/auto_backup.php?token=..."
//    3. Manually from the admin UI (owner session, settings.manage).
//
//  Writes database/backups/dievon_backup_YYYY-MM-DD_HHMMSS.sql and keeps the
//  latest 14 files. Prints one line on success for cron logs.
// ============================================================

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/database_dump.php';

// ── Authorisation ───────────────────────────────────────────
// CLI: the operator of the machine is the operator of the shop — no token.
// Web: the cron token (must be set in .env, else the URL is dead) OR an owner
// admin session. Refused otherwise, so a random web visitor cannot trigger
// backup churn or read file paths.
if (!$isCli) {
    $tok     = (string)EnvLoader::get('BACKUP_CRON_TOKEN', '');
    $given   = (string)($_GET['token'] ?? '');
    $viaToken = $tok !== '' && $given !== '' && hash_equals($tok, $given);

    if (!$viaToken) {
        if (!empty($_SESSION['admin_logged_in'])) {
            requireAdminCapability('settings.manage');
        } else {
            http_response_code(403);
            die('Forbidden');
        }
    }
}

$backupDir = __DIR__ . '/../database/backups/';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }

$file  = $backupDir . 'dievon_backup_' . date('Y-m-d_His') . '.sql';
$bytes = @file_put_contents($file, generateDatabaseDump($pdo));

if ($bytes === false) {
    if (!$isCli) { http_response_code(500); }
    die("Backup FAILED — could not write " . $file . "\n");
}

pruneOldBackups($backupDir, 14);

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
echo 'OK ' . basename($file) . ' (' . number_format($bytes) . ' bytes)' . "\n";
