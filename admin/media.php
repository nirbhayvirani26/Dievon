<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$activeTab = 'media';
$successMsg = '';
$errorMsg   = '';

// Only these folders are actively used by the site (categories/documents/users
// exist on disk but nothing writes to or reads from them).
$FOLDERS = ['products', 'gallery'];

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
            unlink($path);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'delete_media', "Deleted media file: " . basename($path));
            $successMsg = 'File deleted successfully.';
        } else {
            $errorMsg = 'File not found.';
        }
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
                logAdminAction($_SESSION['admin_id'] ?? 1, 'upload_media', "Uploaded media file: $filename");
                $successMsg = 'Image uploaded successfully.';
            } else {
                $errorMsg = 'Failed to save image file.';
            }
        }
    }
}

// ── Build the "in use" lookup so admins know what's safe to delete ─────
// One combined set, not per-folder: banners and blog posts both actually
// save their uploads into uploads/products/ (despite the folder names),
// so a file's usage can't be inferred from which folder it happens to sit in.
$usedFilenames = [];
foreach ([
    "SELECT image FROM products WHERE image IS NOT NULL AND image != ''",
    "SELECT image FROM product_images WHERE image IS NOT NULL AND image != ''",
    "SELECT image FROM banners WHERE image IS NOT NULL AND image != ''",
    "SELECT image FROM blog_posts WHERE image IS NOT NULL AND image != ''",
] as $sql) {
    try {
        $usedFilenames = array_merge($usedFilenames, $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {}
}
$usedFilenames = array_flip($usedFilenames);

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
            'inUse'    => isset($usedFilenames[$f]),
        ];
    }
}

usort($mediaFiles, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$unusedCount = count(array_filter($mediaFiles, fn($m) => !$m['inUse']));

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

require_once __DIR__ . '/includes/header.php';
?>

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
            <div style="height:130px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                <img src="../uploads/<?= $m['folder'] ?>/<?= htmlspecialchars($m['filename']) ?>" alt="" style="width:100%; height:100%; object-fit:cover;" loading="lazy">
            </div>
            <div style="padding:10px 12px;">
                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); word-break:break-all; margin-bottom:4px;" title="<?= htmlspecialchars($m['filename']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($m['filename'], 0, 24, '…')) ?>
                </div>
                <div style="font-size:10px; color:var(--text-muted); margin-bottom:6px;">
                    <?= ucfirst($m['folder']) ?> · <?= formatBytes($m['size']) ?>
                </div>
                <span style="display:inline-block; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; margin-bottom:8px; <?= $m['inUse'] ? 'background:rgba(16,185,129,0.12); color:#10b981;' : 'background:rgba(245,158,11,0.12); color:#f59e0b;' ?>">
                    <?= $m['inUse'] ? 'In use' : 'Unused' ?>
                </span>
                <form method="POST" style="margin:0;" onsubmit="return confirm('<?= $m['inUse'] ? 'This file is still in use somewhere on the site. Deleting it will break that image. Delete anyway?' : 'Delete this file?' ?>');">
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
