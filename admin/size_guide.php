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

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
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

function sg_val($rows, $label, $field) {
    return isset($rows[$label]) && $rows[$label][$field] !== null ? $rows[$label][$field] : '';
}

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

    <?php if (!$categoryId && !$productId): ?>
        <div style="text-align:center; padding:50px 20px; color:var(--text-muted);">
            <div style="font-size:44px; margin-bottom:12px; opacity:0.3;"><i class="fa-solid fa-ruler-combined"></i></div>
            <p>Choose a category or a product above to view or edit its size guide.</p>
        </div>
    <?php else: ?>

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

        <button type="button" class="btn-primary" style="margin-top:20px; padding:12px 28px;" onclick="saveSizeGuide()">
            <i class="fa-solid fa-floppy-disk"></i> Save Size Guide
        </button>
        <span id="sgSaveStatus" style="margin-left:14px; font-size:13px; color:var(--text-muted);"></span>
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

function copyBodyToGarment() {
    if (!confirm('Copy all Body Measurement numbers into the Garment tab? This overwrites anything already entered on the Garment tab (Length is left for you to fill in separately).')) return;
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
            if (data.success) setTimeout(() => location.reload(), 600);
        })
        .catch(() => { status.textContent = 'Network error.'; });
}
</script>

<?php require_once 'includes/footer.php'; ?>
