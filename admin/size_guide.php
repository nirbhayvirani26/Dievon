<?php
// ============================================================
//  Dievon – Admin: Size Guide Management
//  Category defaults + optional per-product overrides.
//  Every measurement field starts empty until an admin fills it
//  in — nothing here is auto-generated or guessed.
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}

require_once '../config/config.php';
require_once '../config/db.php';

$activeTab = 'size_guide';

$categoryId = trim($_GET['category_id'] ?? '') !== '' ? (int)$_GET['category_id'] : null;
$productId  = trim($_GET['product_id']  ?? '') !== '' ? (int)$_GET['product_id']  : null;
// A product id always wins if both were somehow supplied
if ($productId) { $categoryId = null; }

// parent_id is needed as well as id/name: the "missing size guide" check below walks up
// the category tree, because a subcategory with no chart of its own still inherits its
// parent's on the storefront and must not be reported as missing.
$categories = $pdo->query("SELECT id, name, parent_id FROM categories ORDER BY name ASC")->fetchAll();
$products   = $pdo->query("SELECT id, name FROM products WHERE is_deleted = 0 ORDER BY name ASC")->fetchAll();

$sizeLadder = require __DIR__ . '/../config/size_ladder.php';

$chart = null;
$bodyRows = [];
$garmentRows = [];

if ($categoryId || $productId) {
    if ($productId) {
        $stmt = $pdo->prepare("SELECT * FROM size_guide_charts WHERE product_id = :pid");
        $stmt->execute(['pid' => $productId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM size_guide_charts WHERE category_id = :cid AND product_id IS NULL");
        $stmt->execute(['cid' => $categoryId]);
    }
    $chart = $stmt->fetch();

    if ($chart) {
        $cRows = $pdo->prepare("SELECT * FROM size_guide_content WHERE chart_id = :id ORDER BY sort_order ASC, id ASC");
        $cRows->execute(['id' => $chart['id']]);
        foreach ($cRows->fetchAll() as $r) {
            if ($r['measurement_type'] === 'garment') { $garmentRows[$r['size_label']] = $r; }
            else { $bodyRows[$r['size_label']] = $r; }
        }
    }
}

// The Body tab edits the ONE global table shared by every category, so it is loaded
// regardless of whether this category has a chart yet — a brand new category shows
// the shop's body sizing immediately and only needs its garment numbers filled in.
$globalBody = globalBodySizeRows($pdo);
if ($globalBody) {
    $bodyRows = [];
    foreach ($globalBody as $r) { $bodyRows[$r['size_label']] = $r; }
}

function sg_val($rows, $label, $field) {
    return isset($rows[$label]) && $rows[$label][$field] !== null ? $rows[$label][$field] : '';
}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once 'includes/header.php';
?>

<div class="glass-panel" style="padding:28px;">
    <div class="admin-page-header" style="margin-bottom:20px;">
        <div>
            <h2 class="admin-page-title" style="margin:0;"><i class="fa-solid fa-ruler"></i> Size Guide</h2>
            <p class="admin-page-subtitle" style="margin-top:4px;">Set category-wide default measurements, or override for one specific product. Leave any cell blank if you don't have that measurement yet — it will simply be omitted from the storefront popup, never guessed.</p>
        </div>
    </div>

    <!-- Scope selector -->
    <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px; padding:18px; background:var(--bg-main); border-radius:var(--radius-md); border:1px solid var(--border-light);">
        <div style="flex:1; min-width:220px;">
            <label class="form-label">Category Default</label>
            <select class="form-control" onchange="if(this.value) window.location='size_guide.php?category_id='+this.value; ">
                <option value="">— Select a category —</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1; min-width:220px;">
            <label class="form-label">Product Override <span style="color:var(--text-muted); font-size:11px;">(takes priority over its category default)</span></label>
            <select class="form-control" onchange="if(this.value) window.location='size_guide.php?product_id='+this.value; ">
                <option value="">— Select a product —</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $productId === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php
    // Which categories would currently show NO size guide at all? A category with no
    // chart of its own still inherits its parent's, so only report the ones that come
    // up empty after that fallback — those are the ones whose products show no
    // "Size Guide" button at all on the storefront.
    $chartCats = array_flip(array_map('intval',
        $pdo->query("SELECT category_id FROM size_guide_charts WHERE product_id IS NULL")->fetchAll(PDO::FETCH_COLUMN)));

    // Parent map is built once from the categories already in memory rather than
    // querying inside the loop — with a large category tree the previous per-level
    // lookup was an N+1 that grew with both category count and nesting depth.
    $parentOf = [];
    foreach ($categories as $c) { $parentOf[(int)$c['id']] = (int)($c['parent_id'] ?? 0); }

    $missingGuide = [];
    foreach ($categories as $c) {
        $cid = (int)$c['id'];
        $found = isset($chartCats[$cid]);
        $seen  = [$cid => true];
        $pid   = $parentOf[$cid] ?? 0;
        while (!$found && $pid > 0 && !isset($seen[$pid])) {
            $seen[$pid] = true;
            if (isset($chartCats[$pid])) { $found = true; break; }
            $pid = $parentOf[$pid] ?? 0;
        }
        if (!$found) { $missingGuide[] = $c; }
    }
    ?>
    <?php
    $sgVariants = [
        'desktop' => ['label' => 'Desktop image', 'hint' => 'Shown on laptops and tablets (wider than 768px).',  'value' => storeSetting($pdo, 'size_guide_illustration')],
        'mobile'  => ['label' => 'Mobile image',  'hint' => 'Shown on phones (768px and below).',                 'value' => storeSetting($pdo, 'size_guide_illustration_mobile')],
    ];
    ?>
    <?php
    // The upload handler redirects back with ?img=<status>. Without this the whole
    // thing was silent: a rejected file type, a too-large file or a failed settings
    // write all looked identical to "nothing happened".
    $imgStatus = $_GET['img'] ?? '';
    $imgMessages = [
        'saved'   => ['ok',  'Image uploaded and saved. It now appears in the size guide on every product.'],
        'removed' => ['ok',  'Image removed. The built-in drawing will be used instead.'],
        'badtype' => ['err', 'That file type is not allowed. Use a JPG, PNG or WebP image.'],
        'toobig'  => ['err', 'That file is larger than 8MB. Please compress it and try again.'],
        'failed'  => ['err', 'The file could not be written to uploads/gallery/. Check the folder exists and is writable on the server.'],
        'none'    => ['err', 'No file was received. Choose a file before pressing upload.'],
        'dberror' => ['err', 'The image was uploaded but could not be saved to the database — so nothing points at it yet. Check the store_settings table exists.'],
        'csrf'    => ['err', 'Security token expired. Reload the page and try again.'],
    ];
    ?>
    <?php if (isset($imgMessages[$imgStatus])): ?>
    <div class="sg-img-status sg-img-status--<?= $imgMessages[$imgStatus][0] ?>">
        <i class="fa-solid fa-<?= $imgMessages[$imgStatus][0] === 'ok' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
        <?= htmlspecialchars($imgMessages[$imgStatus][1]) ?>
    </div>
    <?php endif; ?>

    <div class="sg-global-image">
        <div class="sg-global-image-copy">
            <strong class="sg-global-image-title"><i class="fa-solid fa-image"></i> "How to Measure" picture — one set for the whole shop</strong>
            Upload once and it appears in the size guide popup on <strong>every product, in every category</strong>.
            Body measuring is the same whatever the garment, so you do not need a different picture per category.
            <span class="sg-global-image-note">
                Upload a separate mobile version if your diagram is wide — the phone then downloads only the mobile file, never both.
                If you set just one, it is used at every screen size. Leave both empty and the built-in drawing is used instead — nothing breaks.
                Use your own original image.
            </span>
        </div>
        <div class="sg-global-image-variants">
            <?php foreach ($sgVariants as $key => $v): ?>
            <div class="sg-global-image-side">
                <span class="sg-variant-label"><?= htmlspecialchars($v['label']) ?></span>
                <?php if ($v['value']): ?>
                    <img src="<?= SITE_URL ?>/uploads/gallery/<?= htmlspecialchars($v['value']) ?>" alt="Current <?= $key ?> measurement diagram" class="sg-global-image-preview">
                <?php else: ?>
                    <div class="sg-global-image-empty">Not set</div>
                <?php endif; ?>
                <span class="sg-variant-hint"><?= htmlspecialchars($v['hint']) ?></span>
                <form method="POST" action="size_guide_handler.php" enctype="multipart/form-data" class="sg-global-image-form">
                    <input type="hidden" name="action" value="save_global_illustration">
                    <input type="hidden" name="variant" value="<?= $key ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="file" name="global_illustration" accept="image/jpeg,image/png,image/webp" class="form-control sg-global-image-input" required>
                    <button type="submit" class="btn-primary sg-global-image-btn">Upload <?= $key ?></button>
                    <?php if ($v['value']): ?>
                    <button type="submit" name="remove" value="1" class="sg-global-image-remove" formnovalidate>Remove</button>
                    <?php endif; ?>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($missingGuide): ?>
    <div class="sg-missing-warning">
        <i class="fa-solid fa-triangle-exclamation sg-missing-icon"></i>
        <div>
            <strong class="sg-missing-title"><?= count($missingGuide) ?> categor<?= count($missingGuide) === 1 ? 'y has' : 'ies have' ?> no size guide</strong>
            Products in <?= count($missingGuide) === 1 ? 'this category' : 'these categories' ?> show no Size Guide button at all.
            Pick the category above and apply a starter template to fix it in one click.
            <div class="sg-missing-list">
                <?php foreach ($missingGuide as $c): ?>
                    <a href="size_guide.php?category_id=<?= (int)$c['id'] ?>" class="sg-missing-chip"><?= htmlspecialchars($c['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$categoryId && !$productId): ?>
        <div style="text-align:center; padding:50px 20px; color:var(--text-muted);">
            <div style="font-size:44px; margin-bottom:12px; opacity:0.3;"><i class="fa-solid fa-ruler-combined"></i></div>
            <p>Choose a category or a product above to view or edit its size guide.</p>
        </div>
    <?php else: ?>

    <?php $sgPresets = require __DIR__ . '/../config/size_guide_presets.php'; ?>
    <div class="sg-template-bar">
        <div class="sg-template-copy">
            <strong class="sg-template-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Not sure where to start?</strong>
            Pick the garment type and every box below is filled with standard measurements
            <em>and</em> the correct "how to measure" wording. Nothing is saved until you press Save,
            so you can try one and adjust the numbers to your own fit.
        </div>
        <div class="sg-template-controls">
            <select id="sgPresetSelect" class="form-control">
                <option value="">— Choose a garment type —</option>
                <?php foreach ($sgPresets as $key => $preset): ?>
                <option value="<?= htmlspecialchars($key) ?>" title="<?= htmlspecialchars($preset['description']) ?>"><?= htmlspecialchars($preset['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-primary sg-template-apply" onclick="applySizePreset()">Apply template</button>
        </div>
    </div>

    <form id="sizeGuideForm">
        <input type="hidden" id="sg_category_id" value="<?= $categoryId ?: '' ?>">
        <input type="hidden" id="sg_product_id" value="<?= $productId ?: '' ?>">

        <div style="display:flex; gap:20px; align-items:center; margin-bottom:20px; flex-wrap:wrap;">
            <div>
                <label class="form-label" style="font-size:12px;">Units used below</label>
                <div style="display:flex; gap:14px;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="radio" name="sg_unit" value="in" <?= (!$chart || $chart['unit'] === 'in') ? 'checked' : '' ?>> Inches</label>
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="radio" name="sg_unit" value="cm" <?= ($chart && $chart['unit'] === 'cm') ? 'checked' : '' ?>> Centimetres</label>
                </div>
            </div>
            <div style="flex:1; min-width:240px;">
                <label class="form-label" style="font-size:12px;">Measurement Illustration (optional — your own original image)</label>
                <input type="file" id="sg_illustration" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control" onchange="sgPreviewNewFile(this)">
            </div>
        </div>

        <?php
        $sgHasImage = !empty($chart['illustration_image']);
        $sgPos = [
            'shoulder_top' => $chart['pos_shoulder_top'] ?? 18, 'shoulder_width' => $chart['pos_shoulder_width'] ?? 45,
            'bust_top'     => $chart['pos_bust_top'] ?? 32,     'bust_width'     => $chart['pos_bust_width'] ?? 45,
            'waist_top'    => $chart['pos_waist_top'] ?? 50,    'waist_width'    => $chart['pos_waist_width'] ?? 35,
            'hips_top'     => $chart['pos_hips_top'] ?? 64,     'hips_width'     => $chart['pos_hips_width'] ?? 50,
            'length_top'   => $chart['pos_length_top'] ?? 15,   'length_bottom'  => $chart['pos_length_bottom'] ?? 95,
        ];
        ?>
        <div id="sgIllustrationPreviewWrap" style="<?= $sgHasImage ? '' : 'display:none;' ?> margin:14px 0 20px;">
            <p style="font-size:11px; color:var(--text-muted); margin-bottom:8px;">
                <i class="fa-solid fa-arrows-up-down"></i> Drag each orange line onto the matching body part in your photo (drag its middle tag up/down, drag its end dots to resize width). Drag the two green dots to mark where the garment length starts and ends.
            </p>
            <div id="sgPreviewStage" style="position:relative; display:inline-block; width:260px; border:1px solid var(--border-light); border-radius:6px; overflow:hidden; background:#f2f2f2; user-select:none;">
                <img id="sgPreviewImg" src="<?= $sgHasImage ? '../uploads/products/' . htmlspecialchars($chart['illustration_image']) : '' ?>" style="display:block; width:100%; height:auto;">

                <?php foreach (['shoulder' => 'Shoulder', 'bust' => 'Bust', 'waist' => 'Waist', 'hips' => 'Hips'] as $field => $labelText): ?>
                <div class="sg-pos-hline" data-field="<?= $field ?>" style="top:<?= $sgPos[$field . '_top'] ?>%; width:<?= $sgPos[$field . '_width'] ?>%;">
                    <span class="sg-pos-handle-left"></span>
                    <span class="sg-pos-drag-area"><?= $labelText ?></span>
                    <span class="sg-pos-handle-right"></span>
                </div>
                <?php endforeach; ?>

                <div class="sg-pos-vhandle" data-field="length_top" style="top:<?= $sgPos['length_top'] ?>%;" title="Length start"></div>
                <div class="sg-pos-vhandle" data-field="length_bottom" style="top:<?= $sgPos['length_bottom'] ?>%;" title="Length end"></div>
                <div id="sgLengthLine"></div>
            </div>
            <?php foreach ($sgPos as $key => $val): ?>
            <input type="hidden" id="sg_pos_<?= $key ?>" value="<?= $val ?>">
            <?php endforeach; ?>
        </div>

        <style>
            .sg-pos-hline { position:absolute; left:50%; transform:translateX(-50%); height:0; border-top:2px dashed #ff5722; display:flex; align-items:center; justify-content:center; z-index:2; }
            .sg-pos-drag-area { background:#ff5722; color:#fff; font-size:9px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; padding:2px 7px; border-radius:3px; cursor:ns-resize; transform:translateY(-50%); white-space:nowrap; }
            .sg-pos-handle-left, .sg-pos-handle-right { position:absolute; top:-5px; width:10px; height:10px; border-radius:50%; background:#fff; border:2px solid #ff5722; cursor:ew-resize; }
            .sg-pos-handle-left { left:-5px; } .sg-pos-handle-right { right:-5px; }
            .sg-pos-vhandle { position:absolute; left:6%; width:13px; height:13px; border-radius:50%; background:#fff; border:2px solid #22c55e; cursor:ns-resize; transform:translate(-50%,-50%); z-index:3; }
            #sgLengthLine { position:absolute; left:6%; width:0; border-left:2px dashed #22c55e; z-index:1; }
        </style>

        <p style="font-size:12px; color:var(--text-muted); margin:-8px 0 14px;">
            <i class="fa-solid fa-circle-info"></i>
            You only need to fill in the sizes you actually stock — leave the rest blank. And this only needs doing <strong>once per category</strong> (or once per product only if that product needs different numbers) — it then applies to every product in that category automatically.
        </p>

        <!-- Tabs -->
        <div style="display:flex; gap:10px; margin-bottom:16px; align-items:center; flex-wrap:wrap;">
            <button type="button" id="tabBtn-body" class="btn-secondary sg-tab-btn active" onclick="switchSgTab('body')" style="padding:8px 18px;">Body Measurements</button>
            <button type="button" id="tabBtn-garment" class="btn-secondary sg-tab-btn" onclick="switchSgTab('garment')" style="padding:8px 18px;">Garment Measurements</button>
            <button type="button" class="btn-secondary" style="padding:8px 14px; font-size:12px;" onclick="copyBodyToGarment()" title="Copy the Body Measurements numbers into the Garment tab as a starting point — you can still adjust them afterwards">
                <i class="fa-solid fa-copy"></i> Copy Body → Garment
            </button>
        </div>

        <?php foreach (['body' => $bodyRows, 'garment' => $garmentRows] as $type => $rowsData): ?>
        <div id="sgTab-<?= $type ?>" class="sg-tab-panel" style="<?= $type === 'garment' ? 'display:none;' : '' ?> overflow-x:auto;">
            <?php if ($type === 'body'): ?>
            <div class="sg-scope-note sg-scope-note--global">
                <i class="fa-solid fa-globe"></i>
                <div>
                    <strong>These body measurements are shared by every category.</strong>
                    A 34&quot; bust is a size M whether she is buying a kurti or trousers, so this table is stored
                    once for the whole shop. Editing it here changes it everywhere — which is the point: you only
                    maintain your sizing in one place.
                </div>
            </div>
            <?php else: ?>
            <div class="sg-scope-note sg-scope-note--local">
                <i class="fa-solid fa-tag"></i>
                <div>
                    <strong>These garment measurements belong to this category only.</strong>
                    This is the finished garment (body + ease), so it is genuinely different per style — a kurti hem
                    is 44–48&quot; while a top is 24–26&quot;, and bottoms carry no bust or shoulder at all.
                </div>
            </div>
            <?php endif; ?>
            <table class="data-table" style="min-width:820px;">
                <thead>
                    <tr>
                        <th>Size</th>
                        <th>Numeric Size</th>
                        <th>Bust</th>
                        <th>Waist</th>
                        <th>Hips</th>
                        <th>Shoulder</th>
                        <?php if ($type === 'garment'): ?><th>Length</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sizeLadder as $sz): ?>
                    <tr>
                        <td style="font-weight:700;"><?= $sz['label'] ?></td>
                        <td><input type="text" class="form-control sg-cell" data-type="<?= $type ?>" data-label="<?= $sz['code'] ?>" data-field="numeric_size" style="width:80px;" value="<?= htmlspecialchars(sg_val($rowsData, $sz['code'], 'numeric_size') ?: $sz['numeric']) ?>"></td>
                        <td><input type="number" step="0.1" class="form-control sg-cell" data-type="<?= $type ?>" data-label="<?= $sz['code'] ?>" data-field="bust" style="width:80px;" value="<?= htmlspecialchars(sg_val($rowsData, $sz['code'], 'bust')) ?>" placeholder="—"></td>
                        <td><input type="number" step="0.1" class="form-control sg-cell" data-type="<?= $type ?>" data-label="<?= $sz['code'] ?>" data-field="waist" style="width:80px;" value="<?= htmlspecialchars(sg_val($rowsData, $sz['code'], 'waist')) ?>" placeholder="—"></td>
                        <td><input type="number" step="0.1" class="form-control sg-cell" data-type="<?= $type ?>" data-label="<?= $sz['code'] ?>" data-field="hips" style="width:80px;" value="<?= htmlspecialchars(sg_val($rowsData, $sz['code'], 'hips')) ?>" placeholder="—"></td>
                        <td><input type="number" step="0.1" class="form-control sg-cell" data-type="<?= $type ?>" data-label="<?= $sz['code'] ?>" data-field="shoulder" style="width:80px;" value="<?= htmlspecialchars(sg_val($rowsData, $sz['code'], 'shoulder')) ?>" placeholder="—"></td>
                        <?php if ($type === 'garment'): ?>
                        <td><input type="number" step="0.1" class="form-control sg-cell" data-type="<?= $type ?>" data-label="<?= $sz['code'] ?>" data-field="length" style="width:80px;" value="<?= htmlspecialchars(sg_val($rowsData, $sz['code'], 'length')) ?>" placeholder="—"></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <!-- Instructions -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:24px;">
            <div class="form-group">
                <label class="form-label">How to measure: Shoulder</label>
                <textarea id="sg_instr_shoulder" class="form-control" rows="3" placeholder="e.g. Measure across the back from shoulder tip to shoulder tip."><?= htmlspecialchars($chart['instructions_shoulder'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">How to measure: Bust</label>
                <textarea id="sg_instr_bust" class="form-control" rows="3" placeholder="e.g. Measure around the fullest part of the bust, keeping the tape level."><?= htmlspecialchars($chart['instructions_bust'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">How to measure: Waist</label>
                <textarea id="sg_instr_waist" class="form-control" rows="3" placeholder="e.g. Measure around the narrowest part of your natural waistline."><?= htmlspecialchars($chart['instructions_waist'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">How to measure: Hips</label>
                <textarea id="sg_instr_hips" class="form-control" rows="3" placeholder="e.g. Measure around the fullest part of the hips."><?= htmlspecialchars($chart['instructions_hips'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="grid-column:1 / -1;">
                <label class="form-label">How to measure: Garment Length</label>
                <textarea id="sg_instr_length" class="form-control" rows="3" placeholder="e.g. Measure from the highest point of the shoulder straight down to the hem."><?= htmlspecialchars($chart['instructions_length'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="sg-save-row">
            <button type="button" class="btn-primary sg-save-btn" onclick="saveSizeGuide()">
                <i class="fa-solid fa-floppy-disk"></i> Save Size Guide
            </button>
            <button type="button" class="btn-secondary sg-undo-btn" onclick="undoSizeGuide()"
                    title="Restore the version from before your last save">
                <i class="fa-solid fa-rotate-left"></i> Undo last save
            </button>
            <span id="sgSaveStatus" class="sg-save-status"></span>
        </div>
        <p class="sg-save-note">
            Every save keeps a copy of the previous version, and the numbers are checked for
            obvious mistakes — you will be told if something looks wrong, but you are never blocked.
        </p>
    </form>
    <?php endif; ?>
</div>

<script>
function sgPreviewNewFile(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('sgPreviewImg').src = e.target.result;
        document.getElementById('sgIllustrationPreviewWrap').style.display = '';
    };
    reader.readAsDataURL(input.files[0]);
}

(function sgInitPositionDrag() {
    const stage = document.getElementById('sgPreviewStage');
    if (!stage) return;

    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

    function dragY(el, onMove) {
        el.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            function move(ev) {
                const rect = stage.getBoundingClientRect();
                const pct = clamp(((ev.clientY - rect.top) / rect.height) * 100, 0, 100);
                onMove(pct);
            }
            function up() {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            }
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
    }

    function dragWidth(handleEl, lineEl, hiddenInput) {
        handleEl.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            function move(ev) {
                const rect = stage.getBoundingClientRect();
                const centerX = rect.left + rect.width / 2;
                const width = clamp((Math.abs(ev.clientX - centerX) / rect.width) * 100 * 2, 10, 96);
                lineEl.style.width = width + '%';
                hiddenInput.value = width.toFixed(1);
            }
            function up() {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            }
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
    }

    ['shoulder', 'bust', 'waist', 'hips'].forEach(function(field) {
        const line = stage.querySelector('.sg-pos-hline[data-field="' + field + '"]');
        if (!line) return;
        const topInput = document.getElementById('sg_pos_' + field + '_top');
        const widthInput = document.getElementById('sg_pos_' + field + '_width');
        dragY(line.querySelector('.sg-pos-drag-area'), function(pct) {
            line.style.top = pct + '%';
            topInput.value = pct.toFixed(1);
        });
        dragWidth(line.querySelector('.sg-pos-handle-left'), line, widthInput);
        dragWidth(line.querySelector('.sg-pos-handle-right'), line, widthInput);
    });

    const topHandle = stage.querySelector('.sg-pos-vhandle[data-field="length_top"]');
    const bottomHandle = stage.querySelector('.sg-pos-vhandle[data-field="length_bottom"]');
    const lengthLine = document.getElementById('sgLengthLine');
    const lengthTopInput = document.getElementById('sg_pos_length_top');
    const lengthBottomInput = document.getElementById('sg_pos_length_bottom');

    function updateLengthLine() {
        const t = parseFloat(topHandle.style.top) || 0;
        const b = parseFloat(bottomHandle.style.top) || 100;
        lengthLine.style.top = Math.min(t, b) + '%';
        lengthLine.style.height = Math.abs(b - t) + '%';
    }
    dragY(topHandle, function(pct) { topHandle.style.top = pct + '%'; lengthTopInput.value = pct.toFixed(1); updateLengthLine(); });
    dragY(bottomHandle, function(pct) { bottomHandle.style.top = pct + '%'; lengthBottomInput.value = pct.toFixed(1); updateLengthLine(); });
    updateLengthLine();
})();

async function copyBodyToGarment() {
    if (!await dievonConfirm('Copy all Body Measurement numbers into the Garment tab? This overwrites anything already entered on the Garment tab (Length is left for you to fill in separately).')) return;
    document.querySelectorAll('.sg-cell[data-type="body"]').forEach(bodyCell => {
        const garmentCell = document.querySelector(`.sg-cell[data-type="garment"][data-label="${bodyCell.dataset.label}"][data-field="${bodyCell.dataset.field}"]`);
        if (garmentCell) garmentCell.value = bodyCell.value;
    });
    switchSgTab('garment');
}

function switchSgTab(type) {
    document.querySelectorAll('.sg-tab-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.sg-tab-btn').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('sgTab-' + type);
    if (panel) panel.style.display = '';
    const btn = document.getElementById('tabBtn-' + type);
    if (btn) btn.classList.add('active');
}

function saveSizeGuide() {
    const rowsMap = {};
    document.querySelectorAll('.sg-cell').forEach(el => {
        const key = el.dataset.type + ':' + el.dataset.label;
        if (!rowsMap[key]) rowsMap[key] = { measurement_type: el.dataset.type, size_label: el.dataset.label };
        rowsMap[key][el.dataset.field] = el.value.trim();
    });
    const rows = Object.values(rowsMap);

    const unit = document.querySelector('input[name="sg_unit"]:checked').value;

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('category_id', document.getElementById('sg_category_id').value);
    fd.append('product_id', document.getElementById('sg_product_id').value);
    fd.append('unit', unit);
    fd.append('instructions_shoulder', document.getElementById('sg_instr_shoulder').value.trim());
    fd.append('instructions_bust', document.getElementById('sg_instr_bust').value.trim());
    fd.append('instructions_waist', document.getElementById('sg_instr_waist').value.trim());
    fd.append('instructions_hips', document.getElementById('sg_instr_hips').value.trim());
    fd.append('instructions_length', document.getElementById('sg_instr_length').value.trim());
    fd.append('rows', JSON.stringify(rows));
    const illustrationInput = document.getElementById('sg_illustration');
    if (illustrationInput && illustrationInput.files[0]) fd.append('illustration', illustrationInput.files[0]);
    ['shoulder_top','shoulder_width','bust_top','bust_width','waist_top','waist_width','hips_top','hips_width','length_top','length_bottom'].forEach(function(key) {
        const el = document.getElementById('sg_pos_' + key);
        if (el) fd.append('pos_' + key, el.value);
    });
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    const status = document.getElementById('sgSaveStatus');
    status.textContent = 'Saving…';

    fetch('size_guide_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            status.textContent = data.success ? 'Saved.' : (data.message || 'Error saving.');
            if (!data.success) return;

            // Highlight anything that looks like a typo. The data IS saved — this is
            // a "check this" prompt, never a block — and the previous version was
            // snapshotted, so Undo is always available.
            document.querySelectorAll('.sg-cell.sg-cell-suspect').forEach(el => el.classList.remove('sg-cell-suspect'));
            (data.cells || []).forEach(key => {
                const [type, size, field] = key.split('|');
                const el = document.querySelector('.sg-cell[data-type="' + type + '"][data-label="' + size + '"][data-field="' + field + '"]');
                if (el) el.classList.add('sg-cell-suspect');
            });

            if (data.warnings && data.warnings.length) {
                status.textContent = 'Saved — but ' + data.warnings.length + ' value(s) look wrong.';
                dievonAlert(
                    'Your changes are saved, but these look like typos:\n\n• ' +
                    data.warnings.slice(0, 8).join('\n• ') +
                    (data.warnings.length > 8 ? '\n• …and ' + (data.warnings.length - 8) + ' more' : '') +
                    '\n\nThe affected boxes are outlined in red. If they are intentional you can ignore this — otherwise fix them, or press Undo to restore the previous version.',
                    { type: 'warning', title: 'Saved — please double-check' }
                );
                return;   // stay on the page so the highlighted cells can be seen
            }
            setTimeout(() => location.reload(), 600);
        })
        .catch(() => { status.textContent = 'Network error.'; });
}

/* ── Undo: restore the version from before the last save ─────────────────── */
async function undoSizeGuide() {
    const fd = new FormData();
    fd.append('action', 'list_snapshots');
    fd.append('category_id', document.getElementById('sg_category_id').value);
    fd.append('product_id', document.getElementById('sg_product_id').value);
    fd.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    const list = await fetch('size_guide_handler.php', { method: 'POST', body: fd }).then(r => r.json()).catch(() => null);
    if (!list || !list.success || !list.snapshots || !list.snapshots.length) {
        dievonAlert('There is no earlier version saved for this size guide yet. A version is kept automatically every time you save.', { type: 'info', title: 'Nothing to undo' });
        return;
    }
    const latest = list.snapshots[0];
    const ok = await dievonConfirm(
        'This will replace everything currently in this size guide with the version saved at ' + latest.created_at + '.\n\n' +
        'The current version is itself saved first, so you can undo this too.',
        { title: 'Restore previous version?', confirmText: 'Restore it', cancelText: 'Cancel' }
    );
    if (!ok) return;

    const fd2 = new FormData();
    fd2.append('action', 'restore_snapshot');
    fd2.append('snapshot_id', latest.id);
    fd2.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');
    const res = await fetch('size_guide_handler.php', { method: 'POST', body: fd2 }).then(r => r.json()).catch(() => null);
    if (res && res.success) {
        dievonAlert('The previous version has been restored.', { type: 'success', title: 'Restored' });
        setTimeout(() => location.reload(), 900);
    } else {
        dievonAlert((res && res.message) || 'Could not restore that version.', { type: 'error' });
    }
}

/* ── Starter templates ────────────────────────────────────────────────
 * Fills the whole form from a garment-type preset so a new category does not
 * have to be typed out from scratch (9 sizes x 5 fields x 2 tabs, plus five
 * how-to-measure explanations). Nothing is persisted here — the admin still
 * reviews the numbers and presses Save.
 */
const SG_PRESETS = <?= json_encode($sgPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

async function applySizePreset() {
    const key = document.getElementById('sgPresetSelect').value;
    if (!key || !SG_PRESETS[key]) {
        dievonAlert('Choose a garment type from the list first.', { type: 'warning' });
        return;
    }
    const preset = SG_PRESETS[key];

    // Only warn if there is actually something to lose.
    const hasData = [...document.querySelectorAll('.sg-cell')].some(el => el.value.trim() !== '' && el.dataset.field !== 'numeric_size')
                 || ['shoulder','bust','waist','hips','length'].some(f => (document.getElementById('sg_instr_' + f)?.value || '').trim() !== '');
    if (hasData) {
        const ok = await dievonConfirm(
            'Applying the "' + preset.label + '" template will overwrite every measurement and every how-to-measure note currently in this form.\n\n' +
            'Nothing is saved until you press Save, so you can still undo by leaving this page without saving.',
            { title: 'Overwrite what is here?', confirmText: 'Overwrite', cancelText: 'Keep mine', danger: true }
        );
        if (!ok) return;
    }

    // 1. how-to-measure text (a blank one is meaningful — it hides that row)
    ['shoulder','bust','waist','hips','length'].forEach(f => {
        const el = document.getElementById('sg_instr_' + f);
        if (el) el.value = preset.instructions[f] || '';
    });

    // 2. the measurement grid, per tab
    ['body','garment'].forEach(type => {
        (preset.rows[type] || []).forEach(row => {
            ['numeric_size','bust','waist','hips','shoulder','length'].forEach(field => {
                const cell = document.querySelector(
                    '.sg-cell[data-type="' + type + '"][data-label="' + row.size_label + '"][data-field="' + field + '"]');
                if (cell) cell.value = (row[field] !== undefined && row[field] !== null) ? row[field] : '';
            });
        });
    });

    const lowerBodyOnly = !preset.instructions.shoulder && !preset.instructions.bust;
    dievonAlert(
        preset.label + ' template applied.\n\n' +
        (lowerBodyOnly
          ? 'Shoulder and bust are intentionally left blank for lower-body garments — the storefront will show the legs/waist/hip diagram instead of the full figure.\n\n'
          : '') +
        'Review the numbers against your own fit, then press Save.',
        { type: 'success', title: 'Template applied' }
    );
}
</script>

<?php require_once 'includes/footer.php'; ?>
