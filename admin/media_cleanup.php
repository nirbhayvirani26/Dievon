<?php
/**
 * Dievon – Orphaned file cleanup
 *
 * Reclaims uploaded files that nothing in the database points at any more.
 *
 * NOTHING IS DELETED HERE. Files are MOVED into a dated quarantine folder, so
 * every action is reversible with one click. Permanently removing a batch is a
 * separate, explicit decision taken after the shop has been checked over.
 *
 * That design is deliberate: the previous "unused" detection in media.php was
 * wrong in a way that would have destroyed 30 live files, and a wrong delete
 * costs a picture that cannot be recovered, while a wrong quarantine costs a
 * click. The in-use test used below is the shared, verified one from
 * config/config.php that checks all 17 file-naming columns.
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


$activeTab  = 'media';
$successMsg = '';
$errorMsg   = '';

/** Folders that actually hold live uploads. */
const CLEANUP_FOLDERS = [
    'uploads/products',
    'uploads/gallery',
    'assets/images/logo',
];

/**
 * A file must be this old before it is even considered.
 *
 * An upload writes the file to disk BEFORE the row naming it is inserted. A
 * cleanup running in that gap would quarantine a brand-new image that is about
 * to become live. A day of grace removes any chance of that.
 */
const CLEANUP_MIN_AGE_HOURS = 24;

/**
 * Files referenced by CODE rather than by a database row.
 *
 * Everything else here is found by scanning the media columns, so a file nothing
 * in the database names looks like an orphan. These three are named only in
 * config/config.php — siteLogoPath() and the two favicon helpers fall back to
 * them when the uploaded file is missing, which is what keeps the branding
 * correct on a second domain that shares this database but not its image folder.
 *
 * Quarantining them would silently strip the site's logo and tab icon, and the
 * scan has no way to know that. Names are compared case-insensitively because
 * logo.PNG has an uppercase extension.
 */
const CLEANUP_PROTECTED = [
    'logo.png',
    'favicon.png',
    'favicondark.png',
];

const QUARANTINE_ROOT = 'uploads/_quarantine';

/* Untouched copies of anything the optimiser rewrites, so every change here is
   reversible in exactly the same way a quarantine is. */
const ORIGINALS_ROOT = 'uploads/_originals';

/* Processed per click. Optimising is CPU-heavy — a few hundred images in one
   request would hit the host's execution limit and leave the job half done. */
const OPTIMISE_BATCH = 20;

$root = realpath(__DIR__ . '/..');

/** Absolute, verified-inside-the-project path for one of our folders. */
function cleanupDir(string $root, string $rel): ?string {
    $abs = realpath($root . '/' . $rel);
    if ($abs === false) { return null; }
    if (strpos($abs, $root) !== 0) { return null; }   // never escape the project
    return $abs;
}

/** Every orphan, freshly evaluated. Never cached — the DB may have changed. */
function findOrphans(PDO $pdo, string $root): array {
    $referenced = referencedMediaFilenames($pdo);
    $cutoff     = time() - (CLEANUP_MIN_AGE_HOURS * 3600);
    $out        = [];

    foreach (CLEANUP_FOLDERS as $rel) {
        $dir = cleanupDir($root, $rel);
        if ($dir === null) { continue; }

        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') { continue; }
            $path = $dir . '/' . $f;
            if (!is_file($path)) { continue; }
            // Referenced by code rather than by a database row — see CLEANUP_PROTECTED.
            if (in_array(mb_strtolower($f), CLEANUP_PROTECTED, true)) { continue; }
            if (isMediaFileInUse($f, $referenced)) { continue; }

            $mtime = filemtime($path);
            $out[] = [
                'folder'   => $rel,
                'filename' => $f,
                'size'     => filesize($path),
                'mtime'    => $mtime,
                'tooNew'   => $mtime > $cutoff,
            ];
        }
    }

    usort($out, fn($a, $b) => $b['size'] <=> $a['size']);
    return $out;
}

/**
 * Images that would get smaller if re-processed.
 *
 * Only files ALREADY on disk — new uploads are optimised as they arrive. A file
 * is a candidate if it is bigger than the display needs (over the dimension cap)
 * or simply heavy for its size; the real saving is measured at processing time,
 * and a file that does not actually shrink is left untouched.
 */
function findOptimisable(string $root): array {
    $out = [];
    foreach (CLEANUP_FOLDERS as $rel) {
        $dir = cleanupDir($root, $rel);
        if ($dir === null) { continue; }

        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') { continue; }
            $path = $dir . '/' . $f;
            if (!is_file($path)) { continue; }

            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            // .webp files are regenerated from their original, never edited here.
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) { continue; }

            $info = @getimagesize($path);
            if (!$info) { continue; }
            $size = filesize($path);

            $oversized = ($info[0] > 2000 || $info[1] > 2000);
            $heavy     = $size > 250 * 1024;
            if (!$oversized && !$heavy) { continue; }

            $out[] = [
                'folder' => $rel, 'filename' => $f, 'size' => $size,
                'w' => $info[0], 'h' => $info[1], 'oversized' => $oversized,
            ];
        }
    }
    usort($out, fn($a, $b) => $b['size'] <=> $a['size']);
    return $out;
}

/** Quarantine batches, newest first. */
function quarantineBatches(string $root): array {
    $base = $root . '/' . QUARANTINE_ROOT;
    if (!is_dir($base)) { return []; }

    $batches = [];
    foreach (scandir($base) as $b) {
        if ($b === '.' || $b === '..' || !is_dir($base . '/' . $b)) { continue; }
        $files = 0; $bytes = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/' . $b, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) { $files++; $bytes += $f->getSize(); }
        }
        $batches[] = ['name' => $b, 'files' => $files, 'bytes' => $bytes, 'mtime' => filemtime($base . '/' . $b)];
    }
    usort($batches, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $batches;
}

// ── Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security check failed — reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Move orphans into a new quarantine batch ──
        if ($action === 'quarantine') {
            $batch    = date('Y-m-d_His');
            $baseDest = $root . '/' . QUARANTINE_ROOT . '/' . $batch;

            // Re-derive the orphan list right now. The listing the admin was
            // looking at may be minutes old, and a product could have been saved
            // in between — this is the check that actually protects live files.
            $orphans = array_filter(findOrphans($pdo, $root), fn($o) => !$o['tooNew']);

            $moved = 0; $failed = 0; $bytes = 0;
            foreach ($orphans as $o) {
                $srcDir = cleanupDir($root, $o['folder']);
                if ($srcDir === null) { $failed++; continue; }

                $src = $srcDir . '/' . basename($o['filename']);
                if (!is_file($src)) { continue; }

                $destDir = $baseDest . '/' . $o['folder'];
                if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) { $failed++; continue; }

                if (@rename($src, $destDir . '/' . basename($o['filename']))) {
                    $moved++; $bytes += $o['size'];
                } else {
                    $failed++;
                }
            }

            if ($moved > 0) {
                // Keep the quarantine out of the web root's reach.
                if (function_exists('hardenUploadDir')) { hardenUploadDir($root . '/' . QUARANTINE_ROOT); }
                if (function_exists('logAdminAction')) {
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'media_quarantine', "Quarantined $moved file(s) into $batch");
                }
                $successMsg = "Moved $moved file(s) (" . round($bytes / 1048576, 1) . " MB) into quarantine batch $batch. "
                            . "Nothing has been deleted — check your shop, then remove the batch if all looks right.";
            } else {
                $successMsg = 'Nothing to move — no eligible orphaned files were found.';
            }
            if ($failed > 0) { $errorMsg = "$failed file(s) could not be moved. Check folder permissions."; }
        }

        // ── Put a batch back exactly where it came from ──
        if ($action === 'restore') {
            $batch = basename(trim($_POST['batch'] ?? ''));
            $base  = realpath($root . '/' . QUARANTINE_ROOT . '/' . $batch);

            if ($batch === '' || $base === false || strpos($base, $root) !== 0) {
                $errorMsg = 'That quarantine batch could not be found.';
            } else {
                $restored = 0; $skipped = 0;
                foreach (CLEANUP_FOLDERS as $rel) {
                    $from = $base . '/' . $rel;
                    if (!is_dir($from)) { continue; }
                    $to = cleanupDir($root, $rel);
                    if ($to === null) { continue; }

                    foreach (scandir($from) as $f) {
                        if ($f === '.' || $f === '..' || !is_file($from . '/' . $f)) { continue; }
                        // Never overwrite something that exists again.
                        if (is_file($to . '/' . $f)) { $skipped++; continue; }
                        if (@rename($from . '/' . $f, $to . '/' . $f)) { $restored++; }
                    }
                }
                // Remove the now-empty batch directories.
                @array_map('rmdir', array_reverse(glob($base . '/*/*', GLOB_ONLYDIR) ?: []));
                @array_map('rmdir', array_reverse(glob($base . '/*', GLOB_ONLYDIR) ?: []));
                @rmdir($base);

                if (function_exists('logAdminAction')) {
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'media_restore', "Restored $restored file(s) from $batch");
                }
                $successMsg = "Restored $restored file(s) from $batch." . ($skipped ? " $skipped skipped (a file of that name already exists again)." : '');
            }
        }

        // ── Shrink existing images, keeping an untouched original ──
        if ($action === 'optimise') {
            $batch  = date('Y-m-d_His');
            $backup = $root . '/' . ORIGINALS_ROOT . '/' . $batch;

            // Re-derived now, not taken from the form, so the list matches reality.
            $todo = array_slice(findOptimisable($root), 0, OPTIMISE_BATCH);

            $done = 0; $skipped = 0; $before = 0; $after = 0;
            foreach ($todo as $t) {
                $srcDir = cleanupDir($root, $t['folder']);
                if ($srcDir === null) { continue; }
                $path = $srcDir . '/' . basename($t['filename']);
                if (!is_file($path)) { continue; }

                // Back up BEFORE touching anything. If the copy fails, skip the
                // file entirely rather than optimise something unrecoverable.
                $destDir = $backup . '/' . $t['folder'];
                if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) { $skipped++; continue; }
                if (!@copy($path, $destDir . '/' . basename($path))) { $skipped++; continue; }

                $sizeBefore = filesize($path);
                if (optimizeUploadedImage($path)) {
                    clearstatcache(true, $path);
                    $before += $sizeBefore;
                    $after  += filesize($path);
                    $done++;
                    // The delivery copy must be rebuilt from the new original,
                    // or the site keeps serving a WebP made from the old one.
                    generateWebpCopy($path);
                } else {
                    // Nothing changed — drop the pointless backup copy.
                    @unlink($destDir . '/' . basename($path));
                    $skipped++;
                }
            }

            if ($done > 0) {
                if (function_exists('hardenUploadDir')) { hardenUploadDir($root . '/' . ORIGINALS_ROOT); }
                if (function_exists('logAdminAction')) {
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'media_optimise', "Optimised $done image(s), batch $batch");
                }
                $successMsg = sprintf(
                    'Optimised %d image(s): %s MB down to %s MB, saving %s MB. '
                    . 'The untouched originals are kept in backup %s — check your shop, then delete the backup if all looks right.',
                    $done, round($before / 1048576, 1), round($after / 1048576, 1),
                    round(($before - $after) / 1048576, 1), $batch
                );
                $remaining = max(0, count(findOptimisable($root)));
                if ($remaining > 0) { $successMsg .= " {$remaining} image(s) still to do — press the button again."; }
            } else {
                $successMsg = 'Nothing to optimise — every image is already as small as it usefully gets.';
                @rmdir($backup);
            }
        }

        // ── Put optimised images back to their originals ──
        if ($action === 'restore_originals') {
            $batch = basename(trim($_POST['batch'] ?? ''));
            $base  = realpath($root . '/' . ORIGINALS_ROOT . '/' . $batch);

            if ($batch === '' || $base === false || strpos($base, realpath($root . '/' . ORIGINALS_ROOT)) !== 0) {
                $errorMsg = 'That backup could not be found.';
            } else {
                $restored = 0;
                foreach (CLEANUP_FOLDERS as $rel) {
                    $from = $base . '/' . $rel;
                    if (!is_dir($from)) { continue; }
                    $to = cleanupDir($root, $rel);
                    if ($to === null) { continue; }
                    foreach (scandir($from) as $f) {
                        if ($f === '.' || $f === '..' || !is_file($from . '/' . $f)) { continue; }
                        if (@copy($from . '/' . $f, $to . '/' . $f)) {
                            generateWebpCopy($to . '/' . $f);   // rebuild the delivery copy too
                            $restored++;
                        }
                    }
                }
                if (function_exists('logAdminAction')) {
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'media_restore_originals', "Restored $restored original(s) from $batch");
                }
                $successMsg = "Restored $restored original image(s) from $batch.";
            }
        }

        // ── Delete an originals backup for good ──
        if ($action === 'purge_originals') {
            $batch = basename(trim($_POST['batch'] ?? ''));
            $base  = realpath($root . '/' . ORIGINALS_ROOT . '/' . $batch);
            if ($batch === '' || $base === false || strpos($base, realpath($root . '/' . ORIGINALS_ROOT)) !== 0) {
                $errorMsg = 'That backup could not be found.';
            } else {
                $deleted = 0;
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    if ($f->isFile()) { if (@unlink($f->getPathname())) { $deleted++; } }
                    else { @rmdir($f->getPathname()); }
                }
                @rmdir($base);
                $successMsg = "Deleted $deleted backup file(s) from $batch.";
            }
        }

        // ── The only place anything is really deleted ──
        if ($action === 'purge') {
            $batch = basename(trim($_POST['batch'] ?? ''));
            $base  = realpath($root . '/' . QUARANTINE_ROOT . '/' . $batch);

            if ($batch === '' || $base === false || strpos($base, $root . '/' . QUARANTINE_ROOT) !== 0) {
                // The path check is what stops a crafted batch name from pointing
                // this loop at, say, uploads/products.
                $errorMsg = 'That quarantine batch could not be found.';
            } else {
                $deleted = 0;
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    if ($f->isFile()) { if (@unlink($f->getPathname())) { $deleted++; } }
                    else { @rmdir($f->getPathname()); }
                }
                @rmdir($base);
                if (function_exists('logAdminAction')) {
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'media_purge', "Permanently deleted $deleted file(s) from $batch");
                }
                $successMsg = "Permanently deleted $deleted file(s) from $batch.";
            }
        }
    }
}

$orphans   = findOrphans($pdo, $root);
$eligible  = array_values(array_filter($orphans, fn($o) => !$o['tooNew']));
$tooNew    = array_values(array_filter($orphans, fn($o) => $o['tooNew']));
$batches   = quarantineBatches($root);
$optimisable = findOptimisable($root);
$optMb       = round(array_sum(array_column($optimisable, 'size')) / 1048576, 1);
$originalBatches = [];
$origBase = $root . '/' . ORIGINALS_ROOT;
if (is_dir($origBase)) {
    foreach (scandir($origBase) as $b) {
        if ($b === '.' || $b === '..' || !is_dir($origBase . '/' . $b)) { continue; }
        $files = 0; $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origBase . '/' . $b, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) { $files++; $bytes += $f->getSize(); }
        }
        $originalBatches[] = ['name' => $b, 'files' => $files, 'bytes' => $bytes];
    }
    usort($originalBatches, fn($a, $b) => strcmp($b['name'], $a['name']));
}
$totalMb   = round(array_sum(array_column($eligible, 'size')) / 1048576, 1);

$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';

function mcBytes(int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
}
?>

<div class="admin-page-header mc-header">
    <div>
        <h1 class="admin-page-title">🧹 Storage Cleanup</h1>
        <p class="admin-page-subtitle">
            Reclaim uploaded files that no product, post, banner or setting points at any more.
            Files are moved to a quarantine you can undo — never deleted straight away.
        </p>
    </div>
    <div class="mc-header-actions">
        <a href="media_missing.php" class="btn-secondary mc-back"><i class="fa-solid fa-magnifying-glass"></i> Missing Images</a>
        <a href="media.php" class="btn-secondary mc-back"><i class="fa-solid fa-images"></i> Media Library</a>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="mc-alert mc-alert-ok">✅ <?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="mc-alert mc-alert-err">⚠️ <?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="glass-panel mc-panel">
    <h2 class="mc-section-title">Files nothing is using</h2>

    <?php if (empty($eligible) && empty($tooNew)): ?>
        <p class="mc-empty">✨ Nothing to clean up — every uploaded file is still in use.</p>
    <?php else: ?>

        <?php if ($eligible): ?>
            <p class="mc-lead">
                <strong><?= count($eligible) ?> file(s)</strong> totalling <strong><?= $totalMb ?> MB</strong>
                are not referenced by any record. Checked against every table and column in the shop
                that can name a file, and a <code>.webp</code> counts as in use while its original is.
            </p>

            <div class="mc-table-wrap">
                <table class="data-table mc-table">
                    <thead>
                        <tr><th>File</th><th>Folder</th><th>Size</th><th>Last changed</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($eligible as $o): ?>
                        <tr>
                            <td class="mc-file"><?= htmlspecialchars($o['filename']) ?></td>
                            <td><?= htmlspecialchars($o['folder']) ?></td>
                            <td><?= mcBytes($o['size']) ?></td>
                            <td><?= date('d M Y', $o['mtime']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" class="mc-actions"
                  onsubmit="return dvConfirmForm(this, 'Move <?= count($eligible) ?> file(s) to quarantine?\n\nNothing is deleted — you can restore them with one click.');">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="quarantine">
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-box-archive"></i> Move <?= count($eligible) ?> file(s) to quarantine
                </button>
                <span class="mc-note">Reversible. Nothing is deleted at this step.</span>
            </form>
        <?php else: ?>
            <p class="mc-empty">✨ No files are eligible for cleanup right now.</p>
        <?php endif; ?>

        <?php if ($tooNew): ?>
            <p class="mc-note mc-toonew">
                <?= count($tooNew) ?> unreferenced file(s) were changed in the last <?= CLEANUP_MIN_AGE_HOURS ?> hours
                and are being left alone for now. A file is written to disk a moment before the record naming it
                is saved, so anything this recent might be an upload still in progress.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="glass-panel mc-panel">
    <h2 class="mc-section-title">Shrink existing images</h2>
    <?php if (empty($optimisable)): ?>
        <p class="mc-empty">✨ Every image is already as small as it usefully gets.</p>
    <?php else: ?>
        <p class="mc-lead">
            <strong><?= count($optimisable) ?> image(s)</strong> totalling <strong><?= $optMb ?> MB</strong> can be made
            smaller. Photos are resized to at most 2000px (your product lightbox shows 1400px)
            and re-compressed at high quality, with a gentle sharpen applied to anything resized
            so detail is not softened.
            <strong>The untouched original of every file is kept</strong>, so this is reversible.
            <?= count($optimisable) > OPTIMISE_BATCH ? 'Runs ' . OPTIMISE_BATCH . ' at a time — press again to continue.' : '' ?>
        </p>

        <div class="mc-table-wrap">
            <table class="data-table mc-table">
                <thead><tr><th>File</th><th>Size now</th><th>Dimensions</th><th>Will be</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($optimisable, 0, 12) as $o): ?>
                    <tr>
                        <td class="mc-file"><?= htmlspecialchars($o['filename']) ?></td>
                        <td><?= mcBytes($o['size']) ?></td>
                        <td><?= $o['w'] ?>&times;<?= $o['h'] ?></td>
                        <td><?= $o['oversized'] ? 'resized + re-compressed' : 're-compressed only' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($optimisable) > 12): ?>
                <p class="mc-note">…and <?= count($optimisable) - 12 ?> more.</p>
            <?php endif; ?>
        </div>

        <form method="POST" class="mc-actions"
              onsubmit="return dvConfirmForm(this, 'Shrink up to <?= OPTIMISE_BATCH ?> image(s)?\n\nThe original of each one is backed up first, so you can undo this.');">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="optimise">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-compress"></i> Shrink <?= min(OPTIMISE_BATCH, count($optimisable)) ?> image(s)
            </button>
            <span class="mc-note">Originals are backed up. Reversible.</span>
        </form>
    <?php endif; ?>
</div>

<?php if ($originalBatches): ?>
<div class="glass-panel mc-panel">
    <h2 class="mc-section-title">Original image backups</h2>
    <p class="mc-lead">Untouched copies from before each shrink. Check your product photos look right, then delete these to free the space.</p>
    <div class="mc-table-wrap">
        <table class="data-table mc-table">
            <thead><tr><th>Backup</th><th>Files</th><th>Size</th><th class="mc-right">Action</th></tr></thead>
            <tbody>
            <?php foreach ($originalBatches as $b): ?>
                <tr>
                    <td class="mc-file"><?= htmlspecialchars($b['name']) ?></td>
                    <td><?= $b['files'] ?></td>
                    <td><?= mcBytes($b['bytes']) ?></td>
                    <td class="mc-right">
                        <form method="POST" class="mc-inline"
                              onsubmit="return dvConfirmForm(this, 'Put the original images back? Any shrinking from this batch is undone.');">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="restore_originals">
                            <input type="hidden" name="batch" value="<?= htmlspecialchars($b['name']) ?>">
                            <button type="submit" class="btn-sm btn-sm-outline"><i class="fa-solid fa-rotate-left"></i> Restore originals</button>
                        </form>
                        <form method="POST" class="mc-inline"
                              onsubmit="return dvConfirmForm(this, 'Delete these backups permanently? The shrunk images stay — only the originals go.');">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="purge_originals">
                            <input type="hidden" name="batch" value="<?= htmlspecialchars($b['name']) ?>">
                            <button type="submit" class="btn-sm btn-sm-danger"><i class="fa-solid fa-trash"></i> Delete backups</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($batches): ?>
<div class="glass-panel mc-panel">
    <h2 class="mc-section-title">Quarantine</h2>
    <p class="mc-lead">
        These files have been moved out of the way but still exist. Check your shop looks right,
        then delete a batch for good — or put it back if something is missing.
    </p>

    <div class="mc-table-wrap">
        <table class="data-table mc-table">
            <thead><tr><th>Batch</th><th>Files</th><th>Size</th><th class="mc-right">Action</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td class="mc-file"><?= htmlspecialchars($b['name']) ?></td>
                    <td><?= $b['files'] ?></td>
                    <td><?= mcBytes($b['bytes']) ?></td>
                    <td class="mc-right">
                        <form method="POST" class="mc-inline"
                              onsubmit="return dvConfirmForm(this, 'Put these <?= $b['files'] ?> file(s) back where they came from?');">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="batch" value="<?= htmlspecialchars($b['name']) ?>">
                            <button type="submit" class="btn-sm btn-sm-outline"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                        </form>
                        <form method="POST" class="mc-inline"
                              onsubmit="return dvConfirmForm(this, 'Permanently delete <?= $b['files'] ?> file(s)?\n\nThis one cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="purge">
                            <input type="hidden" name="batch" value="<?= htmlspecialchars($b['name']) ?>">
                            <button type="submit" class="btn-sm btn-sm-danger"><i class="fa-solid fa-trash"></i> Delete for good</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
