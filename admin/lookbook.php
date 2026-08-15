<?php
/*
 * ═══════════════════════════════════════════════════════════════════════
 *  LOOKBOOK IMAGES  (admin/lookbook.php)
 * ───────────────────────────────────────────────────────────────────────
 *  The storefront leans on three fixed photographs — lookbook_1.png,
 *  lookbook_2.png and lookbook_3.png in uploads/gallery/ — as its fallback
 *  imagery everywhere a real photo is missing: the hero slider, the
 *  Shop-by-Occasion panels, the shop page hero, blog covers, the editorial
 *  section and a dozen page headers (cart, checkout, wishlist, 404 …).
 *
 *  Because those filenames are hardcoded across ~20 files, this screen does
 *  the one thing that updates all of them at once: it replaces the file in
 *  place (converting any upload to PNG, capping the width, and regenerating
 *  the .webp delivery copy). No database rows, no storefront code changes —
 *  the next page load everywhere shows the new photograph.
 *
 *  Safety: the first time a slot is replaced, the previous file is copied to
 *  uploads/_originals/lookbook/ and left there. There is no restore button —
 *  that copy is taken once and never refreshed, so it is a manual safety net,
 *  not an undo. Every change is written to the audit log.
 * ═══════════════════════════════════════════════════════════════════════
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Permission decided here on the server, every time — hiding a sidebar link is
// not access control.
requireAdminCapability('media.manage');

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

$activeTab  = 'lookbook';
$successMsg = '';
$errorMsg   = '';

$galleryDir = __DIR__ . '/../uploads/gallery/';
$backupDir  = __DIR__ . '/../uploads/_originals/lookbook/';

// ── Handle upload / remove ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Please refresh and try again.';
    } else {
        $slot = (int)($_POST['slot'] ?? 0);
    // Which side of the shop this photograph is for. 'men' writes a separate
    // file beside the existing one; the women's image is never overwritten, so
    // uploading a men's version cannot damage what the rest of the site shows.
    $lbAud  = ($_POST['audience'] ?? 'women') === 'men' ? 'men' : 'women';
    $lbBase = 'lookbook_' . $slot . ($lbAud === 'men' ? '_men' : '');

        if ($slot < 1 || $slot > LOOKBOOK_SLOTS) {
            $errorMsg = 'Invalid lookbook slot.';
        } elseif (!empty($_POST['remove_men'])) {
            /* Delete the men's photograph for this slot.
               ────────────────────────────────────────────────────────────────
               Uploading one was a one-way door: the file sat there for ever and
               the men's side kept using it, with no way back from this screen
               short of a file manager.

               Only ever touches lookbook_<n>_men.*. The women's file is named
               without the suffix, so it cannot be reached by this branch at all
               — which matters, because it is the fallback every page uses and
               there is no undo for deleting it.

               Both extensions go: the .webp is a delivery copy of the .png, and
               leaving one behind would keep serving the picture that was just
               removed. */
            $lbRemoved = 0;
            foreach (['png', 'webp', 'jpg', 'jpeg'] as $lbExt) {
                $lbPath = $galleryDir . 'lookbook_' . $slot . '_men.' . $lbExt;
                if (is_file($lbPath) && @unlink($lbPath)) { $lbRemoved++; }
            }
            if ($lbRemoved > 0) {
                $successMsg = "Men's photograph removed from Lookbook {$slot}. "
                            . "The women's picture is now used for everyone on this slot.";
                if (function_exists('logAdminAction')) {
                    logAdminAction((int)($_SESSION['admin_id'] ?? 0),
                        'lookbook_men_removed', "Lookbook slot {$slot}");
                }
            } else {
                $errorMsg = "There is no men's photograph on Lookbook {$slot} to remove.";
            }
        /* The 'restore' branch was here and has gone with its button. Removed
           rather than left unreachable: a POST route that overwrites a live image
           should not survive the control that called it, and dead branches are how
           a screen ends up doing something nobody can see. The backup files are
           untouched — see the note where the button used to be. */
        } else {
            // ── Replace with an uploaded image ──────────────────────────
            $fileKey = 'lookbook_' . $slot;
            $file    = $_FILES[$fileKey] ?? null;

            if (!$file || empty($file['name'])) {
                $errorMsg = "Please choose an image for Lookbook $slot.";
            } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errorMsg = "Lookbook $slot is larger than the server's upload limit — please compress it.";
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = "Lookbook $slot could not be uploaded (error {$file['error']}).";
            } else {
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)
                    || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    $errorMsg = "Lookbook $slot: only PNG, JPG or WebP images are accepted.";
                } elseif ($file['size'] > 10 * 1024 * 1024) {
                    $errorMsg = "Lookbook $slot is over 10MB — please compress it before uploading.";
                } else {
                    $target = $galleryDir . $lbBase . '.png';

                    // One-time backup of the current file, so every slot keeps a
                    // path back to what shipped with the site.
                    if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }
                    if (is_file($target) && !is_file($backupDir . $lbBase . '.png')) {
                        @copy($target, $backupDir . $lbBase . '.png');
                    }

                    // Decode by real content, not extension.
                    $info = @getimagesize($file['tmp_name']);
                    $type = $info[2] ?? null;

                    // Refuse absurd dimensions BEFORE decoding — a 10MB file can
                    // still be 12000px wide, and decoding that spikes memory.
                    if ($info && (($info[0] ?? 0) > 8000 || ($info[1] ?? 0) > 8000)) {
                        $errorMsg = "Lookbook $slot is extremely large ($info[0]×$info[1]px) — please resize it before uploading.";
                    } else {
                    switch ($type) {
                        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($file['tmp_name']); break;
                        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($file['tmp_name']); break;
                        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : null; break;
                        default: break;
                    }

                    if (!$img) {
                        $errorMsg = "Lookbook $slot: could not read the uploaded image.";
                    } else {
                        imagepalettetotruecolor($img);
                        imagealphablending($img, true);
                        imagesavealpha($img, true);

                        // Cap the width at 1920px so a phone photo can't bloat
                        // every page that shows it.
                        $w = imagesx($img);
                        $h = imagesy($img);
                        $maxW = 1920;
                        if ($w > $maxW) {
                            $nh  = (int)round($h * $maxW / $w);
                            $resized = imagecreatetruecolor($maxW, $nh);
                            imagealphablending($resized, false);
                            imagesavealpha($resized, true);
                            imagecopyresampled($resized, $img, 0, 0, 0, 0, $maxW, $nh, $w, $h);
                            imagedestroy($img);
                            $img = $resized;
                        }

                        // Atomic write: encode to a temp file first, then rename
                        // over the live image — a failed write can never leave a
                        // half-saved hero image behind.
                        $tmp = $target . '.tmp' . bin2hex(random_bytes(3));
                        $saved = imagepng($img, $tmp, 7);
                        imagedestroy($img);

                        if ($saved) {
                            @rename($tmp, $target);
                            @unlink($galleryDir . 'lookbook_' . $slot . '.webp'); // kill any stale delivery copy
                            generateWebpCopy($target);
                            logAdminAction($_SESSION['admin_id'] ?? 1, 'lookbook_update', "Replaced lookbook_$slot.png");
                            $successMsg = "Lookbook $slot updated — it is now live across the whole site.";
                        } else {
                            @unlink($tmp);
                            $errorMsg = "Lookbook $slot: could not save the image (file permissions?).";
                        }
                    }
                }
            }
        }
    }
}
}

// ── Preview data ────────────────────────────────────────────────────────
$slotMeta = [
    1 => [
        'title' => 'Lookbook 1',
        'where' => 'Hero slider fallback (when a banner has no photo) · Shop-by-Occasion panel · Blog cover fallback · Instagram wall · Contact / About / Account page headers',
    ],
    2 => [
        'title' => 'Lookbook 2',
        'where' => 'Hero slider fallback (when a banner has no photo) · Shop page hero · “Tailored by Hand” editorial image · Cart / Checkout / Returns / Terms page headers',
    ],
    3 => [
        'title' => 'Lookbook 3',
        'where' => 'Hero slider fallback (when a banner has no photo) · Blog page hero · Wishlist / 404 / Orders page headers · About page figures',
    ],
    4 => [
        // Added so the policy pages stop borrowing slots 1-3: changing the About
        // photograph used to change the Privacy page with it, and a picture chosen
        // to sell a garment is rarely right above a legal page.
        'title' => 'Lookbook 4 — Policy pages',
        'where' => 'Privacy Policy · Terms & Conditions · Shipping Policy · Returns & Exchanges page headers',
    ],
];
$slots = [];
foreach ($slotMeta as $n => $meta) {
    $png = $galleryDir . 'lookbook_' . $n . '.png';
    $slots[$n] = $meta + [
        'url'       => SITE_URL . '/uploads/gallery/lookbook_' . $n . '.png',
        'exists'    => is_file($png),
        'size'      => is_file($png) ? round(filesize($png) / 1024) . ' KB' : 'missing',
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="lookbook-intro">
    <p>
        These 3 photos are the site's <strong>backup pictures</strong>. They show on pages and
        banners that have no photo of their own — like the page headers, the occasion panels and
        blog covers. (The hero slider itself is managed in the <strong>Banner Slider Manager</strong>.)
        Upload a new one here and it updates everywhere automatically. Each upload is saved as a
        web-optimised PNG (max 1920px wide, ~10MB limit). The very first picture a slot held is
        kept in <code>uploads/_originals/lookbook/</code> as a manual safety copy &mdash; taken once,
        never refreshed, so it is not an undo for your most recent upload.
    </p>
</div>

<div class="lookbook-grid">
    <?php foreach ($slots as $n => $s): ?>
    <div class="lookbook-card">
        <?php
        /* Look for the men's file BEFORE the preview, so both can be shown.
           This was found further down and reported only as a line of text —
           "a men's version already exists" — which tells you it is there but
           not what it is. Two photographs are the whole point of the feature,
           and you cannot judge whether they suit each other from a filename. */
        $lbMen = null;
        foreach (['png', 'webp'] as $lbExt) {
            $lbTry = $galleryDir . 'lookbook_' . $n . '_men.' . $lbExt;
            if (is_file($lbTry)) {
                $lbMen = [
                    'file' => 'lookbook_' . $n . '_men.' . $lbExt,
                    'url'  => SITE_URL . '/uploads/gallery/lookbook_' . $n . '_men.' . $lbExt,
                    'mt'   => (int)@filemtime($lbTry),
                ];
                break;
            }
        }
        ?>
        <div class="lookbook-preview<?= $lbMen ? ' lookbook-preview--pair' : '' ?>">
            <?php if ($s['exists']): ?>
                <figure class="lookbook-shot">
                    <img src="<?= htmlspecialchars($s['url']) ?>?v=<?= @filemtime($galleryDir . 'lookbook_' . $n . '.png') ?>" alt="<?= htmlspecialchars($s['title']) ?> preview">
                    <figcaption>Women<?= $lbMen ? '' : ' &middot; used for everyone' ?></figcaption>
                </figure>
            <?php else: ?>
                <div class="lookbook-preview-missing">File missing</div>
            <?php endif; ?>
            <?php if ($lbMen): ?>
                <figure class="lookbook-shot">
                    <img src="<?= htmlspecialchars($lbMen['url']) ?>?v=<?= $lbMen['mt'] ?>" alt="<?= htmlspecialchars($s['title']) ?> — men's version">
                    <figcaption>Men</figcaption>
                </figure>
            <?php endif; ?>
        </div>
        <div class="lookbook-body">
            <h3 class="lookbook-title">
                <?= htmlspecialchars($s['title']) ?>
                <span class="lookbook-size"><?= htmlspecialchars($s['size']) ?></span>
            </h3>
            <p class="lookbook-where"><strong>Where it appears:</strong> <?= htmlspecialchars($s['where']) ?></p>

            <?php
            /* One upload box per audience, instead of one box and a dropdown.
               ────────────────────────────────────────────────────────────────
               The old form had a single file field and a "Upload this as"
               select, so replacing both pictures meant using the same control
               twice and remembering to change the dropdown in between — and
               forgetting meant overwriting the women's photograph with a men's
               one. Which file you are replacing is now decided by which box you
               pick, and the audience travels as a hidden field so the handler is
               unchanged.

               Already resolved above the preview; this was the same disk lookup
               run a second time on every card. */
            $lbMenFile = $lbMen['file'] ?? null;
            ?>

            <div class="lookbook-uploads">
                <form method="post" enctype="multipart/form-data" class="lookbook-form lookbook-upload">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="slot" value="<?= $n ?>">
                    <input type="hidden" name="audience" value="women">
                    <span class="lookbook-upload-label">Women&rsquo;s photograph</span>
                    <input type="file" name="lookbook_<?= $n ?>" accept="image/png,image/jpeg,image/webp" required>
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-upload"></i> <?= $s['exists'] ? 'Replace' : 'Upload' ?>
                    </button>
                </form>

                <form method="post" enctype="multipart/form-data" class="lookbook-form lookbook-upload">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="slot" value="<?= $n ?>">
                    <input type="hidden" name="audience" value="men">
                    <span class="lookbook-upload-label">Men&rsquo;s photograph <span class="lookbook-optional">optional</span></span>
                    <input type="file" name="lookbook_<?= $n ?>" accept="image/png,image/jpeg,image/webp" required>
                    <button type="submit" class="btn-secondary">
                        <i class="fa-solid fa-upload"></i> <?= $lbMenFile ? 'Replace' : 'Upload' ?>
                    </button>
                </form>
            </div>

            <small class="text-muted lookbook-hint">
                The men&rsquo;s photograph is stored separately and shown only while the men&rsquo;s side is
                being browsed. Leave it empty and the women&rsquo;s picture is used for everyone.
                <?php if ($lbMenFile): ?>
                    <br>Currently <code><?= htmlspecialchars($lbMenFile) ?></code>.
                <?php endif; ?>
            </small>

            <?php if ($lbMen): ?>
            <?php /* Only rendered when a men's photograph actually exists, so the
                     control is absent rather than present-and-useless on the slots
                     that have none. dvConfirmForm is the shop's own dialog, used by
                     every other destructive action in this panel. */ ?>
            <form method="post" class="lookbook-form lookbook-secondary-form"
                  onsubmit="return dvConfirmForm(this,'Remove the men&apos;s photograph from Lookbook <?= $n ?>? The women&apos;s picture will be used for everyone on this slot.',{danger:true});">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="slot" value="<?= $n ?>">
                <button type="submit" name="remove_men" value="1" class="btn-secondary">
                    <i class="fa-solid fa-trash"></i> Remove men&rsquo;s photo
                </button>
            </form>
            <?php endif; ?>

            <?php /* "Restore original" used to sit here. Removed.
                     ────────────────────────────────────────────────────────────
                     The backup it restored is written ONCE — on the first ever
                     replacement of a slot, and never refreshed (see the upload
                     branch above). So the button did not undo your last change; it
                     put back whatever was there before your first one, which for
                     these slots is early August. Click it expecting to reverse a
                     bad upload and you would have got a months-old picture instead,
                     over the top of good work, with no way back.

                     The snapshots stay in uploads/_originals/lookbook/ — nothing is
                     deleted, and a file can still be recovered by hand if it is ever
                     genuinely wanted. What is gone is a control that promised
                     something it did not do. */ ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
