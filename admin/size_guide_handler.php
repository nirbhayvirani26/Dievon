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

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
    exit;
}

if ($action === 'save') {
    $categoryId = trim($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
    $productId  = trim($_POST['product_id']  ?? '') !== '' ? (int)$_POST['product_id']  : null;
    $unit       = ($_POST['unit'] ?? 'in') === 'cm' ? 'cm' : 'in';

    if (!$categoryId && !$productId) {
        echo json_encode(['success' => false, 'message' => 'Select a category or a product first.']);
        exit;
    }

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

    try {
        $pdo->beginTransaction();

        // Find or create the chart row for this scope
        if ($productId) {
            $find = $pdo->prepare("SELECT id FROM size_guide_charts WHERE product_id = :pid");
            $find->execute(['pid' => $productId]);
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
            $ins = $pdo->prepare("INSERT INTO size_guide_charts (category_id, product_id, unit, instructions_shoulder, instructions_bust, instructions_waist, instructions_hips, instructions_length,
                    pos_shoulder_top, pos_shoulder_width, pos_bust_top, pos_bust_width, pos_waist_top, pos_waist_width, pos_hips_top, pos_hips_width, pos_length_top, pos_length_bottom)
                VALUES (:cat, :prod, :unit, :is1, :is2, :is3, :is4, :is5,
                    :pst, :psw, :pbt, :pbw, :pwt, :pww, :pht, :phw, :plt, :plb)");
            $ins->execute(array_merge([
                'cat' => $productId ? null : $categoryId, 'prod' => $productId, 'unit' => $unit,
                'is1' => $instrShoulder, 'is2' => $instrBust, 'is3' => $instrWaist, 'is4' => $instrHips, 'is5' => $instrLength,
            ], $posParams));
            $chartId = $pdo->lastInsertId();
        } else {
            $upd = $pdo->prepare("UPDATE size_guide_charts SET unit=:unit, instructions_shoulder=:is1, instructions_bust=:is2, instructions_waist=:is3, instructions_hips=:is4, instructions_length=:is5,
                    pos_shoulder_top=:pst, pos_shoulder_width=:psw, pos_bust_top=:pbt, pos_bust_width=:pbw, pos_waist_top=:pwt, pos_waist_width=:pww,
                    pos_hips_top=:pht, pos_hips_width=:phw, pos_length_top=:plt, pos_length_bottom=:plb
                WHERE id=:id");
            $upd->execute(array_merge([
                'unit' => $unit, 'is1' => $instrShoulder, 'is2' => $instrBust, 'is3' => $instrWaist,
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
                    $pdo->prepare("UPDATE size_guide_charts SET illustration_image = :img WHERE id = :id")
                        ->execute(['img' => $filename, 'id' => $chartId]);
                }
            }
        }

        // Replace content rows wholesale
        $pdo->prepare("DELETE FROM size_guide_content WHERE chart_id = :id")->execute(['id' => $chartId]);

        $insRow = $pdo->prepare("INSERT INTO size_guide_content (chart_id, measurement_type, size_label, numeric_size, bust, waist, hips, shoulder, length, sort_order)
            VALUES (:chart_id, :type, :label, :numeric, :bust, :waist, :hips, :shoulder, :length, :sort)");

        $sort = 0;
        foreach ($rows as $r) {
            $type  = ($r['measurement_type'] ?? '') === 'garment' ? 'garment' : 'body';
            $label = trim($r['size_label'] ?? '');
            if ($label === '') continue;

            $num = ($r['numeric_size'] ?? '') !== '' ? trim($r['numeric_size']) : null;
            $toDecimal = function ($v) { return ($v !== '' && $v !== null) ? (float)$v : null; };

            $insRow->execute([
                'chart_id' => $chartId, 'type' => $type, 'label' => $label, 'numeric' => $num,
                'bust' => $toDecimal($r['bust'] ?? ''), 'waist' => $toDecimal($r['waist'] ?? ''),
                'hips' => $toDecimal($r['hips'] ?? ''), 'shoulder' => $toDecimal($r['shoulder'] ?? ''),
                'length' => $toDecimal($r['length'] ?? ''), 'sort' => $sort++,
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'chart_id' => $chartId]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error saving size guide.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
