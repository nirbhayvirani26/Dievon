<?php
// ============================================================
//  Dievon – Admin: Size Guide Handler
//  Actions: save
//  A chart is scoped to EITHER a category (default) OR one
//  specific product (override). Saving replaces that chart's
//  measurement rows wholesale with whatever the admin submitted —
//  blank cells are stored as NULL, never fabricated.
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('sizeguide.manage', true);


header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
    exit;
}

if ($action === 'save') {
    // '__global__' is the shop-wide default chart: category_id AND product_id both
    // NULL. It must be distinguishable from "nothing selected", which is also null
    // — otherwise saving the default would be rejected by the guard below.
    $rawCategory   = trim((string)($_POST['category_id'] ?? ''));
    $isGlobalChart = ($rawCategory === '__global__');

    $categoryId = (!$isGlobalChart && $rawCategory !== '') ? (int)$rawCategory : null;
    $productId  = trim($_POST['product_id']  ?? '') !== '' ? (int)$_POST['product_id']  : null;
    $unit       = ($_POST['unit'] ?? 'in') === 'cm' ? 'cm' : 'in';

    if (!$categoryId && !$productId && !$isGlobalChart) {
        echo json_encode(['success' => false, 'message' => 'Select a category or a product first.']);
        exit;
    }

    $tolerance     = trim($_POST['tolerance'] ?? '');
    $instrShoulder = trim($_POST['instructions_shoulder'] ?? '');
    $instrBust     = trim($_POST['instructions_bust'] ?? '');
    $instrWaist    = trim($_POST['instructions_waist'] ?? '');
    $instrHips     = trim($_POST['instructions_hips'] ?? '');
    $instrLength   = trim($_POST['instructions_length'] ?? '');

    $toPercent = function ($v) {
        return ($v !== null && trim((string)$v) !== '') ? max(0, min(100, (float)$v)) : null;
    };
    $posShoulderTop   = $toPercent($_POST['pos_shoulder_top'] ?? null);
    $posShoulderWidth = $toPercent($_POST['pos_shoulder_width'] ?? null);
    $posBustTop       = $toPercent($_POST['pos_bust_top'] ?? null);
    $posBustWidth     = $toPercent($_POST['pos_bust_width'] ?? null);
    $posWaistTop      = $toPercent($_POST['pos_waist_top'] ?? null);
    $posWaistWidth    = $toPercent($_POST['pos_waist_width'] ?? null);
    $posHipsTop       = $toPercent($_POST['pos_hips_top'] ?? null);
    $posHipsWidth     = $toPercent($_POST['pos_hips_width'] ?? null);
    $posLengthTop     = $toPercent($_POST['pos_length_top'] ?? null);
    $posLengthBottom  = $toPercent($_POST['pos_length_bottom'] ?? null);

    // rows: JSON array of {measurement_type, size_label, numeric_size, bust, waist, hips, shoulder, length}
    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows)) $rows = [];

    // Sanity-check the numbers. These WARN only — an unusual sizing run can be
    // legitimate, so the save always proceeds and the admin decides. Returned to the
    // browser so it can name the exact cells that look wrong.
    require_once __DIR__ . '/../config/size_guide_validator.php';
    $validation = validateSizeGuideRows($rows, $unit);

    try {
        $pdo->beginTransaction();

        // Snapshot whatever is currently stored BEFORE overwriting it, so a mistake
        // is one click to undo. Wrapped so a snapshot failure can never block a save.
        try {
            if ($productId) {
                $prevStmt = $pdo->prepare("SELECT * FROM size_guide_charts WHERE product_id = :k");
                $prevStmt->execute(['k' => $productId]);
            } elseif ($isGlobalChart) {
                // `category_id = NULL` never matches in SQL, so the global chart
                // needs its own IS NULL query or the undo snapshot silently misses.
                // Gender-scoped for the same reason as the save lookup below —
                // otherwise Undo would restore the other side's chart.
                $snapGender = ($_POST['body_gender'] ?? '') === 'men' ? 'men' : 'women';
                $prevStmt = $snapGender === 'men'
                    ? $pdo->prepare("SELECT * FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL AND gender = 'men' ORDER BY id ASC LIMIT 1")
                    : $pdo->prepare("SELECT * FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL AND (gender IS NULL OR gender = 'women') ORDER BY (gender = 'women') DESC, id ASC LIMIT 1");
                $prevStmt->execute();
            } else {
                $prevStmt = $pdo->prepare("SELECT * FROM size_guide_charts WHERE category_id = :k AND product_id IS NULL");
                $prevStmt->execute(['k' => $categoryId]);
            }
            if ($prevChart = $prevStmt->fetch(PDO::FETCH_ASSOC)) {
                $prevRowsStmt = $pdo->prepare("SELECT * FROM size_guide_content WHERE chart_id = :id ORDER BY sort_order, id");
                $prevRowsStmt->execute(['id' => $prevChart['id']]);
                $pdo->prepare("INSERT INTO size_guide_snapshots (chart_id, category_id, product_id, label, payload) VALUES (:cid, :cat, :prod, :lab, :pay)")
                    ->execute([
                        'cid'  => $prevChart['id'],
                        'cat'  => $prevChart['category_id'],
                        'prod' => $prevChart['product_id'],
                        'lab'  => 'Auto-saved before edit',
                        'pay'  => json_encode([
                            'chart' => $prevChart,
                            'rows'  => $prevRowsStmt->fetchAll(PDO::FETCH_ASSOC),
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                // Keep the 20 most recent per chart; older ones are noise.
                $pdo->prepare("DELETE FROM size_guide_snapshots WHERE chart_id = :id AND id NOT IN (
                        SELECT id FROM (SELECT id FROM size_guide_snapshots WHERE chart_id = :id2 ORDER BY id DESC LIMIT 20) keep)")
                    ->execute(['id' => $prevChart['id'], 'id2' => $prevChart['id']]);
            }
        } catch (PDOException $snapErr) {
            error_log('Size guide snapshot failed: ' . $snapErr->getMessage());
        }

        // Find or create the chart row for this scope
        // Which side of the shop this save belongs to. Read here, before the
        // chart is located, because the shop-wide scope has one chart per
        // gender and picking the wrong one writes men's figures onto the
        // women's chart — which is exactly what was happening.
        $saveGender = ($_POST['body_gender'] ?? '') === 'men' ? 'men' : 'women';

        if ($productId) {
            $find = $pdo->prepare("SELECT id FROM size_guide_charts WHERE product_id = :pid");
            $find->execute(['pid' => $productId]);
        } elseif ($isGlobalChart) {
            // Without this branch, saving the shop-wide default would find nothing
            // and INSERT a second all-NULL chart on every save.
            //
            // Scoped by gender to match the storefront: there are two shop-wide
            // charts, and this used to take whichever came first, so every save
            // from the Men tab overwrote the WOMEN'S chart. The men's shop-wide
            // sleeve and length figures were therefore impossible to enter.
            $find = $saveGender === 'men'
                ? $pdo->prepare("SELECT id FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL AND gender = 'men' ORDER BY id ASC LIMIT 1")
                : $pdo->prepare("SELECT id FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL AND (gender IS NULL OR gender = 'women') ORDER BY (gender = 'women') DESC, id ASC LIMIT 1");
            $find->execute();
        } else {
            $find = $pdo->prepare("SELECT id FROM size_guide_charts WHERE category_id = :cid AND product_id IS NULL");
            $find->execute(['cid' => $categoryId]);
        }
        $chartId = $find->fetchColumn();

        $posParams = [
            'pst' => $posShoulderTop, 'psw' => $posShoulderWidth,
            'pbt' => $posBustTop, 'pbw' => $posBustWidth,
            'pwt' => $posWaistTop, 'pww' => $posWaistWidth,
            'pht' => $posHipsTop, 'phw' => $posHipsWidth,
            'plt' => $posLengthTop, 'plb' => $posLengthBottom,
        ];

        if (!$chartId) {
            $ins = $pdo->prepare("INSERT INTO size_guide_charts (category_id, product_id, unit, tolerance, instructions_shoulder, instructions_bust, instructions_waist, instructions_hips, instructions_length,
                    pos_shoulder_top, pos_shoulder_width, pos_bust_top, pos_bust_width, pos_waist_top, pos_waist_width, pos_hips_top, pos_hips_width, pos_length_top, pos_length_bottom)
                VALUES (:cat, :prod, :unit, :tol, :is1, :is2, :is3, :is4, :is5,
                    :pst, :psw, :pbt, :pbw, :pwt, :pww, :pht, :phw, :plt, :plb)");
            $ins->execute(array_merge([
                'cat' => $productId ? null : $categoryId, 'prod' => $productId, 'unit' => $unit,
                'tol' => $tolerance,
                'is1' => $instrShoulder, 'is2' => $instrBust, 'is3' => $instrWaist, 'is4' => $instrHips, 'is5' => $instrLength,
            ], $posParams));
            $chartId = $pdo->lastInsertId();

            // Stamp the new chart with the gender it was created under.
            //
            // The INSERT above never set `gender`, so a chart created from the Men
            // tab came out NULL — and the Men lookup only matches gender = 'men'.
            // The save appeared to work and the figures vanished on reload, every
            // time, with a fresh orphan chart left behind on each attempt. A NULL
            // shop-wide chart is worse than useless: update_new_database.php counts
            // any category_id/product_id NULL row when deciding whether the
            // shop-wide default still needs seeding, so one orphan marks that step
            // permanently done.
            //
            // Written as a separate guarded UPDATE rather than a column in the
            // INSERT because `gender` does not exist on every database yet — adding
            // it to the INSERT would turn a missing column into a failed save.
            $chartHasGender = (bool)$pdo->query("SHOW COLUMNS FROM size_guide_charts LIKE 'gender'")->fetchColumn();
            if ($chartHasGender) {
                // A category chart belongs to its category's side of the shop; the
                // tab is only authoritative for the shop-wide default.
                $newGender = $categoryId ? categoryGender((int)$categoryId) : $saveGender;
                $pdo->prepare("UPDATE size_guide_charts SET gender = :g WHERE id = :id")
                    ->execute(['g' => $newGender, 'id' => $chartId]);
            }
        } else {
            $upd = $pdo->prepare("UPDATE size_guide_charts SET unit=:unit, tolerance=:tol, instructions_shoulder=:is1, instructions_bust=:is2, instructions_waist=:is3, instructions_hips=:is4, instructions_length=:is5,
                    pos_shoulder_top=:pst, pos_shoulder_width=:psw, pos_bust_top=:pbt, pos_bust_width=:pbw, pos_waist_top=:pwt, pos_waist_width=:pww,
                    pos_hips_top=:pht, pos_hips_width=:phw, pos_length_top=:plt, pos_length_bottom=:plb
                WHERE id=:id");
            $upd->execute(array_merge([
                'unit' => $unit, 'tol' => $tolerance,
                'is1' => $instrShoulder, 'is2' => $instrBust, 'is3' => $instrWaist,
                'is4' => $instrHips, 'is5' => $instrLength, 'id' => $chartId,
            ], $posParams));
        }

        // Optional illustration upload
        if (!empty($_FILES['illustration']['name'])) {
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['illustration']['tmp_name']);
            finfo_close($finfo);
            if (in_array($mime, $allowed) && $_FILES['illustration']['size'] <= 8 * 1024 * 1024) {
                $ext = strtolower(pathinfo($_FILES['illustration']['name'], PATHINFO_EXTENSION));
                $filename = 'sizeguide_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destDir = __DIR__ . '/../uploads/products/';
                if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }
                if (move_uploaded_file($_FILES['illustration']['tmp_name'], $destDir . $filename)) {
                    optimizeUploadedImage($destDir . $filename);
                    // What is being replaced?
                    $prevQ = $pdo->prepare("SELECT illustration_image FROM size_guide_charts WHERE id = :id");
                    $prevQ->execute(['id' => $chartId]);
                    $prevImg = (string)$prevQ->fetchColumn();

                    $pdo->prepare("UPDATE size_guide_charts SET illustration_image = :img WHERE id = :id")
                        ->execute(['img' => $filename, 'id' => $chartId]);

                    // Reference-counted on purpose. Undo snapshots store the whole
                    // chart row as JSON, filename included, so a plain unlink here
                    // would break Restore. deleteUploadedFileIfUnused() reads
                    // size_guide_snapshots.payload too and declines while any
                    // snapshot still names the file.
                    if ($prevImg !== '' && $prevImg !== $filename) {
                        deleteUploadedFileIfUnused($pdo, $prevImg, $destDir);
                    }
                }
            }
        }

        // Replace content rows wholesale
        $pdo->prepare("DELETE FROM size_guide_content WHERE chart_id = :id")->execute(['id' => $chartId]);

        $insRow = $pdo->prepare("INSERT INTO size_guide_content (chart_id, measurement_type, size_label, numeric_size, bust, waist, hips, shoulder, sleeve, length, sort_order)
            VALUES (:chart_id, :type, :label, :numeric, :bust, :waist, :hips, :shoulder, :sleeve, :length, :sort)");

        // BODY rows go to the single shop-wide table per GENDER; GARMENT rows stay
        // with this chart. Body measurements describe the wearer, so duplicating
        // them per category only created copies that drift apart — and a men's
        // product must never be measured against the women's bust ladder.
        //
        // A pre-migration database has no gender column yet. The save must not
        // die on it (a whole chart save would roll back) — fall back to the old
        // gender-less insert, which is exactly the shape the table has right now.
        $bodyGender = ($_POST['body_gender'] ?? '') === 'men' ? 'men' : 'women';
        $bodyHasGender = (bool)$pdo->query("SHOW COLUMNS FROM size_guide_body_global LIKE 'gender'")->fetchColumn();

        // The gender COLUMN is not enough — the UNIQUE KEY has to cover gender too.
        //
        // The column and the widened key are two separate upgrade steps, so a
        // database can easily have one without the other. On such a database the
        // key is still on size_label alone, and saving the men's ladder makes
        // 'XS' collide with the WOMEN'S 'XS' row: ON DUPLICATE KEY UPDATE then
        // overwrites the women's bust/waist/hips with men's chest figures while
        // leaving the row labelled gender = 'women'. Every women's product on the
        // site would silently show men's numbers.
        //
        // This table is never snapshotted and the Undo button does not cover it,
        // so that overwrite is unrecoverable. Refuse the save instead — a blocked
        // save costs a database upgrade, a permitted one costs the women's ladder.
        $bodyKeyIsGendered = false;
        if ($bodyHasGender) {
            $idx = $pdo->query("SHOW INDEX FROM size_guide_body_global WHERE Key_name = 'uniq_gender_size'")->fetchAll();
            $bodyKeyIsGendered = count($idx) > 0;
        }
        if ($bodyHasGender && !$bodyKeyIsGendered && $bodyGender === 'men') {
            throw new RuntimeException(
                'This database still stores one shared size ladder, so saving men\'s body '
                . 'measurements would overwrite the women\'s. Run /update_new_database.php '
                . 'once to separate them, then save again. Nothing has been changed.'
            );
        }
        if ($bodyHasGender && $bodyKeyIsGendered) {
            $globalBody = $pdo->prepare("INSERT INTO size_guide_body_global (gender, size_label, numeric_size, bust, waist, hips, shoulder, sort_order)
                                         VALUES (:g,:l,:n,:b,:w,:h,:s,:o)
                                         ON DUPLICATE KEY UPDATE numeric_size=:n2, bust=:b2, waist=:w2, hips=:h2, shoulder=:s2, sort_order=:o2");
        } else {
            $globalBody = $pdo->prepare("INSERT INTO size_guide_body_global (size_label, numeric_size, bust, waist, hips, shoulder, sort_order)
                                         VALUES (:l,:n,:b,:w,:h,:s,:o)
                                         ON DUPLICATE KEY UPDATE numeric_size=:n2, bust=:b2, waist=:w2, hips=:h2, shoulder=:s2, sort_order=:o2");
        }

        $sort = 0;
        $bodySort = 0;
        foreach ($rows as $r) {
            $type  = ($r['measurement_type'] ?? '') === 'garment' ? 'garment' : 'body';
            $label = trim($r['size_label'] ?? '');
            if ($label === '') continue;

            $num = ($r['numeric_size'] ?? '') !== '' ? trim($r['numeric_size']) : null;
            $toDecimal = function ($v) { return ($v !== '' && $v !== null) ? (float)$v : null; };

            if ($type === 'body') {
                $bodyArgs = [
                    'l' => $label, 'n' => $num,
                    'b' => $toDecimal($r['bust'] ?? ''), 'w' => $toDecimal($r['waist'] ?? ''),
                    'h' => $toDecimal($r['hips'] ?? ''), 's' => $toDecimal($r['shoulder'] ?? ''),
                    'o' => $bodySort,
                    'n2' => $num,
                    'b2' => $toDecimal($r['bust'] ?? ''), 'w2' => $toDecimal($r['waist'] ?? ''),
                    'h2' => $toDecimal($r['hips'] ?? ''), 's2' => $toDecimal($r['shoulder'] ?? ''),
                    'o2' => $bodySort,
                ];
                if ($bodyHasGender) { $bodyArgs = ['g' => $bodyGender] + $bodyArgs; }
                $globalBody->execute($bodyArgs);
                $bodySort++;
                continue;
            }

            $insRow->execute([
                'chart_id' => $chartId, 'type' => $type, 'label' => $label, 'numeric' => $num,
                'bust' => $toDecimal($r['bust'] ?? ''), 'waist' => $toDecimal($r['waist'] ?? ''),
                'hips' => $toDecimal($r['hips'] ?? ''), 'shoulder' => $toDecimal($r['shoulder'] ?? ''),
                // Sleeve is garment-only and frequently blank (sleeveless pieces),
                // so a null here is a real value, not a missing one.
                'sleeve' => $toDecimal($r['sleeve'] ?? ''),
                'length' => $toDecimal($r['length'] ?? ''), 'sort' => $sort++,
            ]);
        }

        $pdo->commit();
        // Warnings ride along with a successful save — the data is stored either way,
        // the admin just gets told which cells look like typos.
        echo json_encode([
            'success'  => true,
            'chart_id' => $chartId,
            'warnings' => $validation['warnings'],
            'cells'    => $validation['cells'],
        ]);
    } catch (RuntimeException $e) {
        // A refusal this file raised on purpose. Its wording is written for the
        // admin to act on, so it goes through as-is rather than being flattened
        // into the generic database message below.
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['success' => false, 'message' => 'Database error saving size guide.']);
    }
    exit;
}

// ── Site-wide "how to measure" image ────────────────────────────────────────
// Stored once in store_settings and used by every category that has no picture
// of its own, so a single upload covers the whole catalogue.
if ($action === 'save_global_illustration') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: size_guide.php?err=csrf'); exit;
    }

    // Desktop and mobile are stored as two separate settings, because a wide
    // desktop diagram is unreadable when squeezed onto a phone.
    $variant = ($_POST['variant'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop';
    $key     = $variant === 'mobile' ? 'size_guide_illustration_mobile' : 'size_guide_illustration';

    // store_settings is normally created by admin/settings.php. If that page has never
    // been opened on this install the table will not exist, and the write below would
    // throw AFTER the file had already been moved into place — leaving the image on
    // disk but no setting pointing at it, which looks exactly like "the upload did
    // nothing". Create it on demand and report failures instead of dying silently.
    $save = function ($value) use ($pdo, $key) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `store_settings` (
                `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
                `setting_value` TEXT DEFAULT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (:k, :v)
                           ON DUPLICATE KEY UPDATE setting_value = :v2")
                ->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
            return true;
        } catch (PDOException $e) {
            error_log('size guide illustration save failed: ' . $e->getMessage());
            return false;
        }
    };

    if (!empty($_POST['remove'])) {
        // Read what is there before blanking it, so the file can go too.
        $prevGlobal = (string)storeSetting($pdo, $key, '');
        $ok = $save('');
        if ($ok && $prevGlobal !== '') {
            deleteUploadedFileIfUnused($pdo, $prevGlobal, __DIR__ . '/../uploads/gallery/');
        }
        logAdminAction($_SESSION['admin_id'] ?? 1, 'size_guide_illustration', "Removed site-wide $variant measurement image");
        header('Location: size_guide.php?img=' . ($ok ? 'removed' : 'dberror')); exit;
    }

    if (!empty($_FILES['global_illustration']['name']) && $_FILES['global_illustration']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['global_illustration'];
        $allowedExts  = ['jpg', 'jpeg', 'png', 'webp'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($ext, $allowedExts, true) || !in_array($mime, $allowedMimes, true)) {
            header('Location: size_guide.php?img=badtype'); exit;
        }
        if ($file['size'] > 8 * 1024 * 1024) {
            header('Location: size_guide.php?img=toobig'); exit;
        }

        $dir = __DIR__ . '/../uploads/gallery/';
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        $filename = 'howtomeasure_' . $variant . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            optimizeUploadedImage($dir . $filename);
            $prevGlobal = (string)storeSetting($pdo, $key, '');
            // If the settings write fails the file is on disk but nothing points at it,
            // so say so rather than reporting a success the admin cannot see.
            $ok = $save($filename);
            // Only reclaim the old one once the new one is actually saved.
            if ($ok && $prevGlobal !== '' && basename($prevGlobal) !== $filename) {
                deleteUploadedFileIfUnused($pdo, $prevGlobal, $dir);
            }
            logAdminAction($_SESSION['admin_id'] ?? 1, 'size_guide_illustration', "Set site-wide $variant measurement image to $filename");
            header('Location: size_guide.php?img=' . ($ok ? 'saved' : 'dberror')); exit;
        }
        header('Location: size_guide.php?img=failed'); exit;
    }
    header('Location: size_guide.php?img=none'); exit;
}

// ── Undo: list snapshots, or restore one ────────────────────────────────────
if ($action === 'list_snapshots' || $action === 'restore_snapshot') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
        exit;
    }
    // Same sentinel handling as 'save'. Without it, (int)'__global__' is 0, which
    // matches no chart — Undo would list nothing for the shop-wide default and give
    // no clue why.
    $rawCategory   = trim((string)($_POST['category_id'] ?? ''));
    $isGlobalChart = ($rawCategory === '__global__');

    $categoryId = (!$isGlobalChart && $rawCategory !== '') ? (int)$rawCategory : null;
    $productId  = !empty($_POST['product_id'])  ? (int)$_POST['product_id']  : null;

    try {
        if ($action === 'list_snapshots') {
            if ($productId) {
                $q = $pdo->prepare("SELECT id, label, created_at FROM size_guide_snapshots WHERE product_id = :k ORDER BY id DESC LIMIT 20");
                $q->execute(['k' => $productId]);
            } elseif ($isGlobalChart) {
                $q = $pdo->prepare("SELECT id, label, created_at FROM size_guide_snapshots WHERE category_id IS NULL AND product_id IS NULL ORDER BY id DESC LIMIT 20");
                $q->execute();
            } else {
                $q = $pdo->prepare("SELECT id, label, created_at FROM size_guide_snapshots WHERE category_id = :k AND product_id IS NULL ORDER BY id DESC LIMIT 20");
                $q->execute(['k' => $categoryId]);
            }
            echo json_encode(['success' => true, 'snapshots' => $q->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        $snapId = (int)($_POST['snapshot_id'] ?? 0);
        $s = $pdo->prepare("SELECT * FROM size_guide_snapshots WHERE id = :id");
        $s->execute(['id' => $snapId]);
        $snap = $s->fetch(PDO::FETCH_ASSOC);
        if (!$snap) {
            echo json_encode(['success' => false, 'message' => 'That saved version no longer exists.']);
            exit;
        }
        $payload = json_decode($snap['payload'], true);
        if (!is_array($payload) || empty($payload['chart'])) {
            echo json_encode(['success' => false, 'message' => 'That saved version could not be read.']);
            exit;
        }

        $pdo->beginTransaction();
        $c = $payload['chart'];
        $chartId = (int)$c['id'];

        // Restoring is itself an edit, so snapshot the current state first — undoing
        // an undo has to work too.
        $curRows = $pdo->prepare("SELECT * FROM size_guide_content WHERE chart_id = :id ORDER BY sort_order, id");
        $curRows->execute(['id' => $chartId]);
        $curChart = $pdo->prepare("SELECT * FROM size_guide_charts WHERE id = :id");
        $curChart->execute(['id' => $chartId]);
        if ($cc = $curChart->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("INSERT INTO size_guide_snapshots (chart_id, category_id, product_id, label, payload) VALUES (:cid,:cat,:prod,'Auto-saved before restore',:pay)")
                ->execute(['cid' => $chartId, 'cat' => $cc['category_id'], 'prod' => $cc['product_id'],
                           'pay' => json_encode(['chart' => $cc, 'rows' => $curRows->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE)]);
        }

        $pdo->prepare("UPDATE size_guide_charts SET unit=:u, instructions_shoulder=:i1, instructions_bust=:i2, instructions_waist=:i3,
                instructions_hips=:i4, instructions_length=:i5, illustration_image=:img WHERE id=:id")
            ->execute([
                'u' => $c['unit'] ?? 'in',
                'i1' => $c['instructions_shoulder'] ?? '', 'i2' => $c['instructions_bust'] ?? '',
                'i3' => $c['instructions_waist'] ?? '',    'i4' => $c['instructions_hips'] ?? '',
                'i5' => $c['instructions_length'] ?? '',   'img' => $c['illustration_image'] ?? null,
                'id' => $chartId,
            ]);

        $pdo->prepare("DELETE FROM size_guide_content WHERE chart_id = :id")->execute(['id' => $chartId]);
        $insRow = $pdo->prepare("INSERT INTO size_guide_content (chart_id, measurement_type, size_label, numeric_size, bust, waist, hips, shoulder, sleeve, length, sort_order)
                                 VALUES (:cid,:mt,:sl,:ns,:b,:w,:h,:sh,:slv,:len,:so)");
        foreach ($payload['rows'] ?? [] as $r) {
            $insRow->execute([
                'cid' => $chartId, 'mt' => $r['measurement_type'] ?? 'body', 'sl' => $r['size_label'] ?? '',
                'ns' => $r['numeric_size'] ?? null, 'b' => $r['bust'] ?? null, 'w' => $r['waist'] ?? null,
                'h' => $r['hips'] ?? null, 'sh' => $r['shoulder'] ?? null,
                'slv' => $r['sleeve'] ?? null, 'len' => $r['length'] ?? null,
                'so' => $r['sort_order'] ?? 0,
            ]);
        }
        $pdo->commit();
        logAdminAction($_SESSION['admin_id'] ?? 1, 'restore_size_guide', "Chart $chartId restored from snapshot $snapId");
        echo json_encode(['success' => true, 'message' => 'Previous version restored.']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['success' => false, 'message' => 'Could not restore that version.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
