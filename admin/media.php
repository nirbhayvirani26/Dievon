<?php
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
$successMsg = '';
$errorMsg   = '';

// Only these folders are actively used by the site (categories/documents/users
// exist on disk but nothing writes to or reads from them).
// blog added: article pictures now have their own folder, and a media screen
// that cannot see them would report every one of them as missing.
$FOLDERS = ['products', 'gallery', 'blog'];

function safeMediaPath(string $folder, string $filename): ?string {
    global $FOLDERS;
    if (!in_array($folder, $FOLDERS, true)) return null;
    $filename = basename($filename); // strip any path traversal attempt
    if ($filename === '' || $filename === '.' || $filename === '..') return null;
    return __DIR__ . '/../uploads/' . $folder . '/' . $filename;
}

// ── Handle delete ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_media') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $path = safeMediaPath($_POST['folder'] ?? '', $_POST['filename'] ?? '');
        if ($path && file_exists($path)) {
            /*
             * Refuse to delete a file something is still using.
             *
             * The tile says "In use", but nothing stopped the button working
             * anyway — one click could remove a live product photo, and the page
             * would then show a broken image with no way to get the file back.
             * deleteUploadedFileIfUnused() re-checks all 17 file-naming columns
             * at the moment of deletion and also removes the .webp twin, which
             * the previous raw unlink() left stranded on disk forever.
             */
            $folderRel = dirname($path);
            if (deleteUploadedFileIfUnused($pdo, basename($path), $folderRel)) {
                logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_media', "Deleted media file: " . basename($path));
                $successMsg = 'File deleted, along with its WebP copy.';
            } elseif (isCodeReferencedMedia(basename($path))) {
                // Say WHICH kind of "in use" this is.
                //
                // The message below talks about products, posts and banners —
                // true for a database reference, and simply wrong for these.
                // A file the site asks for by name has no record to remove it
                // from, so telling the owner to "remove it from that record"
                // sends them looking for something that does not exist.
                $errorMsg = 'That file is built into the site — it is the shop logo, a favicon, the '
                          . 'default article picture, or a lookbook slot used by the About and policy pages. '
                          . 'Nothing in the database names it, which is why it can look unused. '
                          . 'To change it, upload a replacement with the same filename rather than deleting it.';
            } else {
                // Name the record, rather than listing four things it might be.
                //
                // The blocking row is very often one the owner cannot see from
                // any screen — an ARCHIVED product, a DRAFT article, a settings
                // key — so "remove it from that record first" sent them hunting
                // through a catalogue that does not show it.
                $used = mediaFileUsedBy($pdo, basename($path));
                $errorMsg = $used
                    ? 'Not deleted — this file is still used by: ' . implode(' · ', $used)
                      . '. Change the picture on that record first, then delete this file.'
                    : 'Not deleted — something in the database still refers to this filename, '
                      . 'though not in a record that can be named. It may be inside an archived '
                      . 'order or a saved snapshot.';
            }
        } else {
            $errorMsg = 'File not found.';
        }
    }
}

/* ── Delete every unused file in one go ─────────────────────────────────────
   Deleting them one tile at a time was the only way this screen offered, and a
   shop that has been trading a while accumulates hundreds — every replaced
   product photograph, every abandoned upload.

   Each file goes through deleteUploadedFileIfUnused(), the same check the
   single-file button uses, rather than trusting the "Unused" badge on its tile.
   That badge was worked out when the page was drawn; a product could have been
   given that photograph in another tab since. The helper re-reads all 17
   file-naming columns at the moment of deletion, so the worst case is that a
   file is KEPT, never that a live image is destroyed. Files the CODE depends on
   — the logo, favicons, lookbook slots — are refused by it too, even though no
   database row names them.

   This is permanent. media_cleanup.php quarantines instead, and remains the
   safer route; this exists because deleting 200 files one at a time is not a
   real option. The confirmation says so plainly.

   What was KEPT is reported next to what went: "42 deleted" alone does not say
   whether the other eight were a problem or simply still in use. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_unused_media') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $deleted = 0; $kept = 0; $freed = 0;
        foreach ($FOLDERS as $folder) {
            $dir = __DIR__ . '/../uploads/' . $folder;
            if (!is_dir($dir)) { continue; }
            foreach ((array)scandir($dir) as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                $full = $dir . '/' . $entry;
                if (!is_file($full)) { continue; }
                /* A .webp is never considered on its own: the database stores
                   photo.jpg and never photo.webp, so every twin would read as
                   unused and be stripped from photographs still in use. The
                   helper removes each twin along with its parent instead. */
                if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'webp') { continue; }
                $size = (int)@filesize($full);
                if (deleteUploadedFileIfUnused($pdo, $entry, $dir)) { $deleted++; $freed += $size; }
                else { $kept++; }
            }
        }
        if ($deleted > 0) {
            logAdminAction($_SESSION['admin_id'] ?? 1, 'purge_unused_media',
                "Deleted $deleted unused media file(s), freeing $freed bytes");
        }
        $successMsg = $deleted === 0
            ? 'Nothing removed — every file on disk is still in use.'
            : "Deleted $deleted unused file" . ($deleted === 1 ? '' : 's')
              . ' (' . formatBytes($freed) . ' freed, WebP copies included).'
              . ($kept > 0
                  ? ' ' . $kept . ' file' . ($kept === 1 ? ' was' : 's were') . ' kept — still in use.'
                  : '');
    }
}

// ── Handle upload (goes to uploads/gallery/ — the shared folder used by
// banners and blog posts) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_media') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } elseif (!empty($_FILES['media_file']['name'])) {
        $file    = $_FILES['media_file'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            $errorMsg = 'Image must be JPG, PNG, WebP, or GIF.';
        } elseif ($file['size'] > 8 * 1024 * 1024) {
            $errorMsg = 'Image file too large (max 8MB).';
        } else {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = 'gallery_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destDir  = __DIR__ . '/../uploads/gallery/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }

            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                optimizeUploadedImage($destDir . $filename);
                logAdminAction($_SESSION['admin_id'] ?? 1, 'upload_media', "Uploaded media file: $filename");
                $successMsg = 'Image uploaded successfully.';
            } else {
                $errorMsg = 'Failed to save image file.';
            }
        }
    }
}

// ── Build the "in use" lookup so admins know what's safe to delete ─────
// Delegated to referencedMediaFilenames() in config/config.php so the Media
// Library and any cleanup script share one definition of "in use".
//
// This block used to query four columns (products, product_images, banners,
// blog_posts) and compare exact filenames. That was not merely incomplete — it
// made the Delete button destructive: every .webp on disk read as unused
// (the DB stores photo.jpg, never photo.webp), as did the logo, both favicons
// and the two live How-to-Measure diagrams, because store_settings was never
// consulted. Seventeen columns are checked now, and a .webp counts as in use
// while its original is.
$referencedMedia = referencedMediaFilenames($pdo);

// ── Scan disk for files in each folder ──────────────────────────────
$mediaFiles = [];
$totalSize = 0;
$imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

foreach ($FOLDERS as $folder) {
    $dir = __DIR__ . '/../uploads/' . $folder . '/';
    if (!is_dir($dir)) continue;

    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $imageExts)) continue;

        $size = filesize($dir . $f);
        $totalSize += $size;

        $mediaFiles[] = [
            'folder'   => $folder,
            'filename' => $f,
            'size'     => $size,
            'mtime'    => filemtime($dir . $f),
            'inUse'    => isMediaFileInUse($f, $referencedMedia),
            'ext'      => $ext,
            'stem'     => pathinfo($f, PATHINFO_FILENAME),
        ];
    }
}

/*
 * Fold each generated .webp into the original it was made from.
 *
 * Every upload also writes a same-name .webp (generateWebpCopy in
 * config/config.php), so the library was listing each photo twice and it looked
 * as though uploading once produced duplicates. The .webp is not a separate
 * image an admin can manage — deleting it alone would only make the site
 * slower — so it is shown as a saving on its original's tile instead.
 *
 * A .webp with no original is still listed on its own: that one IS a real,
 * separately-managed file.
 */
$byFolderStem = [];
foreach ($mediaFiles as $i => $m) {
    if ($m['ext'] !== 'webp') { $byFolderStem[$m['folder'] . '|' . $m['stem']] = $i; }
}

$grouped = [];
foreach ($mediaFiles as $m) {
    if ($m['ext'] === 'webp') {
        $key = $m['folder'] . '|' . $m['stem'];
        if (isset($byFolderStem[$key])) {
            // Attach to the original rather than listing it separately.
            $owner = $byFolderStem[$key];
            $mediaFiles[$owner]['webpSize'] = $m['size'];
            continue;
        }
    }
    $grouped[] = $m;
}
// Re-read the originals so the attached webpSize is carried through.
$grouped = array_map(function ($m) use ($mediaFiles, $byFolderStem) {
    $key = $m['folder'] . '|' . $m['stem'];
    if (isset($byFolderStem[$key]) && isset($mediaFiles[$byFolderStem[$key]]['webpSize'])) {
        $m['webpSize'] = $mediaFiles[$byFolderStem[$key]]['webpSize'];
    }
    return $m;
}, $grouped);
$mediaFiles = $grouped;

usort($mediaFiles, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$unusedCount = count(array_filter($mediaFiles, fn($m) => !$m['inUse']));

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="mc-header">
    <div></div>
    <a href="media_cleanup.php" class="btn-secondary mc-back">
        <i class="fa-solid fa-broom"></i> Storage Cleanup
    </a>
</div>

<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px;">
    <div class="stat-card glass-panel">
        <div class="stat-label">Total Files</div>
        <div class="stat-value"><?= count($mediaFiles) ?></div>
    </div>
    <div class="stat-card glass-panel">
        <div class="stat-label">Storage Used</div>
        <div class="stat-value" style="font-size:22px;"><?= formatBytes($totalSize) ?></div>
    </div>
    <div class="stat-card glass-panel">
        <div class="stat-label">Unused Files</div>
        <div class="stat-value" style="font-size:22px; <?= $unusedCount > 0 ? 'color:#f59e0b;' : '' ?>"><?= $unusedCount ?></div>
        <?php /* On the card that states the number, so the count and the thing
                 that acts on it are never in two places. Hidden entirely at zero
                 rather than shown disabled — a button that cannot do anything
                 still invites a click and a moment spent working out why nothing
                 happened. The confirmation names the count and says plainly that
                 Storage Cleanup is the reversible alternative. */ ?>
        <?php if ($unusedCount > 0): ?>
        <form method="POST" style="margin:10px 0 0;"
              onsubmit="return dvConfirmForm(this, 'Permanently delete <?= (int)$unusedCount ?> unused file<?= $unusedCount === 1 ? '' : 's' ?>? Anything still used by a product, article or banner is kept automatically. This cannot be undone — use Storage Cleanup instead if you want it reversible.')">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="purge_unused_media">
            <?php /* .btn-sm .btn-sm-danger — the admin's own small destructive
                     button, used by every other delete on these screens. Writing
                     a bespoke one meant this button would drift the first time
                     the shared pair was restyled, and it would be the only red
                     button in the panel that looked slightly different. */ ?>
            <button type="submit" class="btn-sm btn-sm-danger">
                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                Delete all unused
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="glass-panel" style="padding: 24px; margin-bottom: 24px;">
    <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">Upload New Image</h3>
    <p style="font-size:12px; color:var(--text-muted); margin-bottom:14px;">
        Uploads here go to the shared gallery folder, for use in banners, blog posts, or the About page. Product photos are uploaded from the product edit screen instead.
    </p>
    <form method="POST" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="action" value="upload_media">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="file" name="media_file" accept="image/jpeg,image/png,image/webp,image/gif" required class="form-control" style="max-width:320px;">
        <button type="submit" class="btn-primary"><i class="fa-solid fa-upload"></i> Upload</button>
    </form>
</div>

<div class="glass-panel" style="padding: 24px;">
    <?php if (empty($mediaFiles)): ?>
        <div style="text-align:center; padding:40px; color:var(--text-muted);">No media files found.</div>
    <?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:16px;">
        <?php foreach ($mediaFiles as $m): ?>
        <div style="border:1px solid var(--border-light); border-radius:8px; overflow:hidden; background:var(--bg-main);">
            <?php
            /* Draw the thumbnail from the WebP twin when there is one.
               ────────────────────────────────────────────────────────────────
               This frame is 130px tall and was being filled with the full-size
               original — 2.8 MB of PNG to paint a thumbnail. One screen of
               tiles came to roughly 20 MB, and a browser fetches only about six
               files per host at a time, so the rest queued behind the big ones
               and the frames stayed empty long enough to look as though the
               pictures had been lost. Measured on the live site: 2,285,902
               bytes and 1.4s for a single lookbook preview.

               The twin is already on disk, and its size is already read a few
               lines below to print the "91% smaller" line — so the saving was
               being reported to the admin and not actually taken. Same tiles,
               about 1.5 MB instead of 20 MB.

               Falls back to the original whenever no twin exists, and a file
               that IS a .webp is simply drawn as itself. */
            $previewName = (!empty($m['webpSize']) && $m['ext'] !== 'webp')
                ? $m['stem'] . '.webp'
                : $m['filename'];
            ?>
            <?php
            /* Absolute, from SITE_URL — never "../uploads/".
               ────────────────────────────────────────────────────────────────
               A relative path is resolved against whatever URL the browser
               thinks it is on, not against where this file sits on disk. Any
               difference between the two — a rewrite, a trailing slash, the
               panel reached on its own host — sends "../" somewhere that has no
               uploads folder, and every picture 404s while the page around it
               looks perfectly fine. The rest of the panel already builds these
               from SITE_URL; the media grid was the one place that did not. */
            $uploadsBase = rtrim(SITE_URL, '/') . '/uploads/' . $m['folder'] . '/';
            $previewUrl  = $uploadsBase . rawurlencode($previewName);
            /* If the twin turns out not to be there — an install where it was
               never generated, or a half-finished upload — the original is
               tried once before the frame admits defeat. Better a slow picture
               than no picture. */
            $fallbackUrl = ($previewName !== $m['filename'])
                ? $uploadsBase . rawurlencode($m['filename'])
                : '';
            ?>
            <div class="media-thumb">
                <img src="<?= htmlspecialchars($previewUrl) ?>"
                     <?= $fallbackUrl ? 'data-fallback="' . htmlspecialchars($fallbackUrl) . '"' : '' ?>
                     alt="<?= htmlspecialchars($m['filename']) ?>"
                     loading="lazy" decoding="async" width="180" height="130">
            </div>
            <div style="padding:10px 12px;">
                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); word-break:break-all; margin-bottom:4px;" title="<?= htmlspecialchars($m['filename']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($m['filename'], 0, 24, '…')) ?>
                </div>
                <div style="font-size:10px; color:var(--text-muted); margin-bottom:6px;">
                    <?= ucfirst($m['folder']) ?> · <?= formatBytes($m['size']) ?>
                    <?php if (!empty($m['webpSize'])): ?>
                        <?php // The generated companion, shown here instead of as its own tile. ?>
                        <br><span style="color:#10b981; font-weight:600;">
                            + WebP <?= formatBytes($m['webpSize']) ?>
                            (<?= max(0, (int)round((1 - $m['webpSize'] / max(1, $m['size'])) * 100)) ?>% smaller)
                        </span>
                    <?php endif; ?>
                </div>
                <span style="display:inline-block; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; margin-bottom:8px; <?= $m['inUse'] ? 'background:rgba(16,185,129,0.12); color:#10b981;' : 'background:rgba(245,158,11,0.12); color:#f59e0b;' ?>">
                    <?= $m['inUse'] ? 'In use' : 'Unused' ?>
                </span>
                <form method="POST" style="margin:0;" onsubmit="return dvConfirmForm(this,'<?= $m['inUse'] ? 'This file is still in use somewhere on the site. Deleting it will break that image. Delete anyway?' : 'Delete this file?' ?>');">
                    <input type="hidden" name="action" value="delete_media">
                    <input type="hidden" name="folder" value="<?= htmlspecialchars($m['folder']) ?>">
                    <input type="hidden" name="filename" value="<?= htmlspecialchars($m['filename']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <button type="submit" class="btn-sm btn-sm-danger" style="width:100%; justify-content:center;"><i class="fa-solid fa-trash"></i> Delete</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
