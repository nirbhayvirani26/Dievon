<?php
$activeTab = 'promos';
// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once 'includes/header.php';

$promoCodes = [];
try {
    $promoCodes = $pdo->query("SELECT * FROM promo_codes ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {}
?>
        
        <div class="glass-panel" style="padding:28px;">
            <div class="admin-page-header" style="margin-bottom:24px;">
                <div>
                    <h2 class="admin-page-title" style="margin:0;">🎟️ Promo Codes</h2>
                    <p class="admin-page-subtitle" style="margin-top:4px;">Create and manage discount codes for your customers</p>
                </div>
            </div>

            <!-- Create new promo code -->
            <div style="background:var(--bg-main); border-radius:var(--radius-md); padding:24px; border:1px solid var(--border-light); margin-bottom:28px;">
                <h3 style="margin:0 0 18px; font-size:15px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-plus-circle" style="color:var(--color-secondary);"></i> Create New Promo Code
                </h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Code *</label>
                        <input type="text" id="promoCode" class="form-control" placeholder="e.g. SUMMER20" style="text-transform:uppercase;" oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Description (shown to customer)</label>
                        <input type="text" id="promoDesc" class="form-control" placeholder="e.g. Summer sale 20% off">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Discount Type *</label>
                        <select id="promoType" class="form-control">
                            <option value="percentage">Percentage (e.g. 10%)</option>
                            <option value="fixed">Fixed Amount (e.g. £5 off)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Discount Value * <span style="color:var(--text-muted); font-size:11px;">(% or £)</span></label>
                        <input type="number" id="promoValue" class="form-control" placeholder="e.g. 10" min="0.01" step="0.01">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Minimum Order (£) <span style="color:var(--text-muted); font-size:11px;">optional</span></label>
                        <input type="number" id="promoMin" class="form-control" placeholder="0.00" min="0" step="0.01">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Max Uses <span style="color:var(--text-muted); font-size:11px;">optional — leave blank for unlimited</span></label>
                        <input type="number" id="promoMax" class="form-control" placeholder="Unlimited" min="1">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Expires On <span style="color:var(--text-muted); font-size:11px;">optional</span></label>
                        <input type="date" id="promoExpires" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0; display:flex; align-items:flex-end;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px;">
                            <input type="checkbox" id="promoActive" checked style="width:16px; height:16px;">
                            Active (available immediately)
                        </label>
                    </div>
                </div>
                <div style="margin-top:18px;">
                    <button class="btn-primary" onclick="createPromo()" style="padding:11px 24px;">
                        <i class="fa-solid fa-plus"></i> Create Promo Code
                    </button>
                </div>
            </div>

            <!-- Promo codes list -->
            <?php if (empty($promoCodes)): ?>
            <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px; opacity:0.3;">🎟️</div>
                <p>No promo codes yet. Create your first one above!</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Min Order</th>
                            <th>Uses</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promoCodes as $p): ?>
                        <tr id="promorow-<?= $p['id'] ?>">
                            <td>
                                <code style="font-size:14px; font-weight:800; color:var(--color-primary); background:var(--color-primary-bg); padding:3px 10px; border-radius:6px; letter-spacing:1px;"><?= htmlspecialchars($p['code']) ?></code>
                                <?php if (!empty($p['description'])): ?>
                                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><?= htmlspecialchars($p['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $p['discount_type'] === 'percentage' ? '<i class="fa-solid fa-percent"></i> Percentage' : '<i class="fa-solid fa-sterling-sign"></i> Fixed' ?></td>
                            <td style="font-weight:700; color:var(--color-secondary);">
                                <?= $p['discount_type'] === 'percentage' ? (int)$p['discount_value'] . '%' : '£' . number_format($p['discount_value'], 2) ?>
                            </td>
                            <td><?= $p['min_order'] > 0 ? '£' . number_format($p['min_order'], 2) : '<span style="color:var(--text-muted);">—</span>' ?></td>
                            <td>
                                <?= $p['uses_count'] ?>
                                <?php if (!is_null($p['max_uses'])): ?>
                                / <?= $p['max_uses'] ?>
                                <?php else: ?>
                                <span style="color:var(--text-muted); font-size:11px;">/ ∞</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($p['expires_at']) ? date('d M Y', strtotime($p['expires_at'])) : '<span style="color:var(--text-muted);">Never</span>' ?></td>
                            <td>
                                <span id="promo-badge-<?= $p['id'] ?>" style="font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px;
                                    <?= $p['active'] ? 'background:rgba(16,185,129,0.15); color:#10b981;' : 'background:rgba(100,100,100,0.12); color:var(--text-muted);' ?>">
                                    <?= $p['active'] ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button id="promo-toggle-<?= $p['id'] ?>" class="btn-secondary" style="padding:6px 12px; font-size:12px;" onclick="togglePromo(<?= $p['id'] ?>)">
                                        <i class="fa-solid fa-toggle-<?= $p['active'] ? 'on' : 'off' ?>"></i>
                                        <?= $p['active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                    <button class="btn-danger" style="padding:6px 12px; font-size:12px;" onclick="deletePromo(<?= $p['id'] ?>, '<?= htmlspecialchars($p['code']) ?>')">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

<script>
function createPromo() {
    const code = document.getElementById('promoCode').value.trim();
    const value = parseFloat(document.getElementById('promoValue').value);

    if (!code) { alert('Please enter a promo code.'); return; }
    if (!value || value <= 0) { alert('Please enter a discount value greater than 0.'); return; }

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('code', code);
    formData.append('description', document.getElementById('promoDesc').value.trim());
    formData.append('discount_type', document.getElementById('promoType').value);
    formData.append('discount_value', value);
    formData.append('min_order', document.getElementById('promoMin').value || 0);
    formData.append('max_uses', document.getElementById('promoMax').value);
    formData.append('expires_at', document.getElementById('promoExpires').value);
    formData.append('active', document.getElementById('promoActive').checked ? '1' : '');
    formData.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('promo_handler.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error creating promo code.');
            }
        })
        .catch(() => alert('Network error. Please try again.'));
}

function togglePromo(id) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('promo_handler.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error updating promo code.');
            }
        });
}

async function deletePromo(id, code) {
    if (!await dievonConfirm(`Delete promo code "${code}"? This cannot be undone.`)) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    formData.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    fetch('promo_handler.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById('promorow-' + id);
                if (row) { row.style.opacity = '0'; row.style.transition = 'opacity 0.3s'; setTimeout(() => row.remove(), 300); }
            } else {
                alert(data.message || 'Error deleting promo code.');
            }
        });
}
</script>

<?php require_once 'includes/footer.php'; ?>
