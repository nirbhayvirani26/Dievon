<?php
// ============================================================
//  Dievon – Database Backup: download, save on server, restore
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/database_dump.php';

// Owner only, and checked HERE — before any download/restore branch below.
//
// The check was previously inside `if (!isset($_GET['download']))`, so it only
// covered the page that shows the button. Requesting backup.php?download=1
// skipped it entirely and streamed the whole database — every customer record,
// address and order — to anyone with an admin session of any role.
requireAdminCapability('settings.manage');

$backupDir = __DIR__ . '/../database/backups/';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }

// A restore filename must match what our own dumper/auto-backup produce.
// Anything else (path traversal, arbitrary .sql uploads) is refused. The
// optional `_pre_restore` suffix covers the safety snapshots taken before a
// restore, which are themselves valid dumps.
function isValidBackupFilename(string $name): bool
{
    return (bool)preg_match('/^(dievon|dievonfashion)_backup_[0-9-]{8,10}_\d{6}(_pre_restore)?\.sql$/', $name);
}

// ── Actions ─────────────────────────────────────────────────
$flash = null; // ['ok'|'error', message]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = ['error', 'Your session expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'save_local') {
        $file = $backupDir . 'dievon_backup_' . date('Y-m-d_His') . '.sql';
        $bytes = @file_put_contents($file, generateDatabaseDump($pdo));
        if ($bytes !== false) {
            pruneOldBackups($backupDir, 14);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'backup_saved', basename($file));
            $flash = ['ok', 'Backup saved on the server: <code>' . htmlspecialchars(basename($file)) . '</code> (' . number_format($bytes) . ' bytes). Oldest backups pruned to the latest 14.'];
        } else {
            $flash = ['error', 'Could not write the backup file. Check that database/backups is writable.'];
        }
    } elseif (($_POST['action'] ?? '') === 'restore') {
        $file      = basename((string)($_POST['backup_file'] ?? ''));
        $confirmed = trim((string)($_POST['confirm'] ?? ''));
        if (!isValidBackupFilename($file) || !is_file($backupDir . $file)) {
            $flash = ['error', 'Invalid backup file selected.'];
        } elseif ($confirmed !== 'RESTORE') {
            $flash = ['error', 'Restore cancelled — you must type RESTORE to confirm.'];
        } else {
            // Safety net first: snapshot the CURRENT database so a botched or
            // regretted restore is always undoable from this same screen.
            $safety = $backupDir . 'dievon_backup_' . date('Y-m-d_His') . '_pre_restore.sql';
            @file_put_contents($safety, generateDatabaseDump($pdo));

            $result = restoreDatabaseFromFile($pdo, $backupDir . $file);
            if ($result === true) {
                logAdminAction($_SESSION['admin_id'] ?? 1, 'restore_database', $file);
                $flash = ['ok', 'Database restored from <code>' . htmlspecialchars($file) . '</code>. A safety backup of the pre-restore state is saved as <code>' . htmlspecialchars(basename($safety)) . '</code>.'];
            } else {
                $flash = ['error', $result];
            }
        }
    }
}

// ── Downloads ────────────────────────────────────────────────
// download=1       -> fresh dump streamed to the browser (original behaviour)
// download=<file>  -> a previously saved server-side backup file
if (isset($_GET['download'])) {
    $which = (string)$_GET['download'];
    if ($which === '1') {
        logAdminAction($_SESSION['admin_id'] ?? 1, 'download_backup', 'Generated full database backup');
        $filename = 'dievon_backup_' . date('Y-m-d_His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        echo generateDatabaseDump($pdo);
        exit;
    }
    if (isValidBackupFilename($which) && is_file($backupDir . $which)) {
        logAdminAction($_SESSION['admin_id'] ?? 1, 'download_backup', 'Downloaded saved backup ' . $which);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $which . '"');
        header('Content-Length: ' . filesize($backupDir . $which));
        readfile($backupDir . $which);
        exit;
    }
    http_response_code(404);
    die('Backup not found.');
}

// ── Page ─────────────────────────────────────────────────────
// Only dumps this shop's own dumper wrote (header marker present) are
// restorable from this screen. Older mysqldump-format files in the same folder
// are listed too, but as download-only — restoring those needs phpMyAdmin, and
// refusing them here beats mangling a foreign format mid-execution.
$backups = [];
foreach (glob($backupDir . '{dievon,dievonfashion}_backup_*.sql', GLOB_BRACE) ?: [] as $f) {
    $isOurs = false;
    if ($fh = @fopen($f, 'rb')) {
        $isOurs = strpos((string)fread($fh, 200), '-- Dievon —') !== false;
        fclose($fh);
    }
    $backups[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f), 'restorable' => $isOurs];
}
usort($backups, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$csrf = generateCsrfToken();

$activeTab = 'backup';
require_once __DIR__ . '/includes/header.php';
?>
<div class="glass-panel" style="padding: 28px;">
    <p style="font-size: 14px; color: var(--text-secondary); margin-bottom: 16px;">
        Full-database backups as <code>.sql</code>. The scheduled backup
        (<code>admin/auto_backup.php</code>) writes one here automatically — see
        the cron note below. Restoring replaces the whole database, so it takes a
        safety snapshot first and asks you to type <strong>RESTORE</strong> to confirm.
    </p>

    <?php if ($flash): ?>
    <div style="padding:12px 16px; margin-bottom:18px; font-size:13px;
                <?= $flash[0] === 'ok'
                    ? 'background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;'
                    : 'background:#fef2f2; border:1px solid #fecaca; color:#991b1b;' ?>">
        <?= $flash[1] ?>
    </div>
    <?php endif; ?>

    <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:26px;">
        <a href="backup.php?download=1" class="btn-primary" style="display:inline-flex; text-decoration:none; padding:12px 24px; font-size:14px;">
            <i class="fa-solid fa-download"></i> Generate &amp; Download Fresh Backup
        </a>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_local">
            <button type="submit" class="btn-primary" style="display:inline-flex; padding:12px 24px; font-size:14px; background:#10b981; border-color:#10b981;">
                <i class="fa-solid fa-hard-drive"></i> Save Backup on Server
            </button>
        </form>
    </div>

    <h3 style="font-size:13px; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); margin:0 0 12px;">
        Backups on Server (<?= count($backups) ?>)
    </h3>

    <?php if (empty($backups)): ?>
    <p style="font-size:13px; color:var(--text-muted);">No backups saved on the server yet. Use "Save Backup on Server" or set up the scheduled backup below.</p>
    <?php else: ?>
    <div class="table-wrapper" style="overflow-x:auto;">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:8px;">File</th>
                    <th style="text-align:left; padding:8px;">Size</th>
                    <th style="text-align:left; padding:8px;">Created</th>
                    <th style="text-align:right; padding:8px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $bk): ?>
                <tr>
                    <td style="padding:8px; font-family:ui-monospace,monospace; font-size:12px;"><?= htmlspecialchars($bk['name']) ?></td>
                    <td style="padding:8px; font-size:12px;"><?= number_format($bk['size']) ?> B</td>
                    <td style="padding:8px; font-size:12px;"><?= date('j M Y H:i', $bk['mtime']) ?></td>
                    <td style="padding:8px; text-align:right; white-space:nowrap;">
                        <a class="btn-sm btn-sm-primary" style="text-decoration:none;" href="backup.php?download=<?= urlencode($bk['name']) ?>">
                            <i class="fa-solid fa-download"></i> Download
                        </a>
                        <?php if (!$bk['restorable']): ?>
                        <span style="font-size:11px; color:var(--text-muted); margin-left:8px;">mysqldump format &mdash; import via phpMyAdmin</span>
                        <?php else: ?>
                        <details style="display:inline-block; margin-left:8px; vertical-align:middle;">
                            <summary class="btn-sm btn-sm-danger" style="cursor:pointer; display:inline-block;">
                                <i class="fa-solid fa-rotate-left"></i> Restore…
                            </summary>
                            <form method="POST" style="margin-top:10px; padding:12px; border:1px solid #fecaca; background:#fff7f7; min-width:340px; text-align:left;"
                                  onsubmit="return dvConfirmForm(this, 'This replaces the ENTIRE database with the selected backup.\n\nEvery product, order and setting is overwritten by whatever the backup holds. This cannot be undone.', { confirmText: 'Replace database' });">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="backup_file" value="<?= htmlspecialchars($bk['name']) ?>">
                                <div style="font-size:12px; color:var(--text-secondary); margin-bottom:8px;">
                                    Restoring <code><?= htmlspecialchars($bk['name']) ?></code> will drop and recreate every table.
                                    A safety backup of the current database is saved automatically first.
                                </div>
                                <label style="font-size:12px; font-weight:700; display:block; margin-bottom:4px;">
                                    Type RESTORE to confirm:
                                </label>
                                <input type="text" name="confirm" class="form-control" style="height:34px; font-size:13px;" autocomplete="off" required>
                                <button type="submit" class="btn-sm btn-sm-danger" style="margin-top:8px;">Restore Database</button>
                            </form>
                        </details>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div style="margin-top:28px; padding:16px; background:var(--bg-main); border:1px solid var(--border-light);">
        <h4 style="margin:0 0 8px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:var(--color-primary);">
            Scheduled (automatic) backups
        </h4>
        <p style="font-size:13px; color:var(--text-secondary); margin:0 0 10px; line-height:1.6;">
            Point a cron job at <code>admin/auto_backup.php</code>. It saves a backup to this
            folder and keeps the latest 14 automatically. Examples:
        </p>
        <pre style="font-size:12px; background:#0f172a; color:#a5f3fc; padding:12px; overflow-x:auto; line-height:1.5;"># Every night at 2:10am, server-side (Hostinger / cPanel cron, PHP):
10 2 * * * /usr/bin/php /home/&lt;user&gt;/public_html/admin/auto_backup.php

# Or a web-cron hitting the URL with the token from .env (BACKUP_CRON_TOKEN):
10 2 * * * wget -q -O /dev/null "<?= htmlspecialchars(SITE_URL) ?>/admin/auto_backup.php?token=YOUR_BACKUP_CRON_TOKEN"</pre>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
