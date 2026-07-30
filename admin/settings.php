<?php
$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'settings' : 'stock';
require_once 'includes/header.php';

// ── Store General Settings: persistence (simple key-value table) ──────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_settings` (
        `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `setting_value` TEXT DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

$storeSettingDefaults = [
    'site_name'             => 'DIEVON Luxury Atelier',
    'contact_email'         => 'concierge@dievon.com',
    'contact_phone'         => '+44 20 7946 0192',
    'default_currency'      => 'GBP',
    'free_shipping_min'     => '150',
    'standard_shipping_fee' => '15',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE setting_value = :v2");
            foreach (array_keys($storeSettingDefaults) as $key) {
                $val = trim($_POST[$key] ?? '');
                $stmt->execute(['k' => $key, 'v' => $val, 'v2' => $val]);
            }
            header('Location: settings.php?tab=settings&saved=1');
            exit;
        } catch (PDOException $e) {
            $errorMsg = 'Error saving settings: ' . $e->getMessage();
        }
    }
}

$storeSettings = $storeSettingDefaults;
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll();
    foreach ($rows as $r) {
        if (array_key_exists($r['setting_key'], $storeSettings)) {
            $storeSettings[$r['setting_key']] = $r['setting_value'];
        }
    }
} catch (PDOException $e) {}
?>
        
        <div class="glass-panel" style="padding:24px; overflow:hidden;">

            <?php if ($activeTab === 'settings'): ?>
            <!-- ══ STORE CONFIGURATION TAB ══ -->
            <div style="max-width: 800px; margin: 0 auto; padding: 20px 0;">
                <h3 style="font-size:20px; font-weight:700; margin-bottom:20px; color:var(--text-primary);"><i class="fa-solid fa-gears" style="color:var(--color-primary); margin-right:8px;"></i> Store General Settings</h3>
                <?php if (isset($_GET['saved'])): ?>
                    <div class="alert alert-success" style="margin-bottom:20px; padding:12px 16px; border-radius:6px; background:rgba(16,185,129,0.12); color:#10b981;">✅ Store settings updated successfully!</div>
                <?php endif; ?>
                <form method="POST" action="settings.php?tab=settings" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="save_settings" value="1">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div class="form-group">
                            <label class="form-label">Store Brand Name</label>
                            <input type="text" name="site_name" class="form-control" value="<?= htmlspecialchars($storeSettings['site_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Customer Support Email</label>
                            <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($storeSettings['contact_email']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Phone / Concierge</label>
                            <input type="text" name="contact_phone" class="form-control" value="<?= htmlspecialchars($storeSettings['contact_phone']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default Store Currency</label>
                            <select name="default_currency" class="form-control">
                                <?php foreach (['GBP' => 'GBP (£) - British Pound', 'INR' => 'INR (₹) - Indian Rupee', 'USD' => 'USD ($) - US Dollar'] as $code => $label): ?>
                                <option value="<?= $code ?>" <?= $storeSettings['default_currency'] === $code ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Free Shipping Minimum (£)</label>
                            <input type="number" name="free_shipping_min" class="form-control" value="<?= htmlspecialchars($storeSettings['free_shipping_min']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Standard Shipping Fee (£)</label>
                            <input type="number" name="standard_shipping_fee" class="form-control" value="<?= htmlspecialchars($storeSettings['standard_shipping_fee']) ?>">
                        </div>
                    </div>
                    <p style="font-size:12px; color:var(--text-muted); margin-bottom:14px;">
                        <i class="fa-solid fa-circle-info"></i> These values are saved, but not yet wired into checkout — shipping is still calculated automatically by delivery distance, and contact details elsewhere on the site come from <code>config/config.php</code>.
                    </p>
                    <button type="submit" class="btn-primary" style="padding:12px 28px;"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
                </form>
            </div>
            <?php else: ?>
            <!-- ══ STOCK MANAGEMENT TAB ══ -->
            <?php
            $totalInStock = 0; $totalDamage = 0; $totalOffline = 0; $totalOnline = 0; $grandTotal = 0;
            foreach ($stockProducts as $sp) {
                $ts  = (int)($sp['total_stock']  ?? $sp['stock_qty'] ?? 0);
                $dmg = (int)($sp['damage_stock'] ?? 0);
                $off = (int)($sp['sold_offline'] ?? 0);
                $sol = (int)($sp['sold_online']  ?? 0);
                $totalInStock += max(0, $ts - $dmg - $off - $sol);
                $totalDamage  += $dmg; $totalOffline += $off;
                $totalOnline  += $sol; $grandTotal   += $ts;
            }
            ?>
            <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:28px;">
                <div style="background:rgba(139,92,246,0.08); border:1px solid rgba(139,92,246,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">📦</div>
                    <div style="font-size:26px; font-weight:800; color:#8b5cf6; font-family:var(--font-heading);"><?= $grandTotal ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Grand Total</div>
                </div>
                <div style="background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">🟢</div>
                    <div style="font-size:26px; font-weight:800; color:#10b981; font-family:var(--font-heading);"><?= $totalInStock ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">In Stock</div>
                </div>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">⚠️</div>
                    <div style="font-size:26px; font-weight:800; color:#ef4444; font-family:var(--font-heading);"><?= $totalDamage ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Damage</div>
                </div>
                <div style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">🏪</div>
                    <div style="font-size:26px; font-weight:800; color:#f59e0b; font-family:var(--font-heading);"><?= $totalOffline ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Sold Offline</div>
                </div>
                <div style="background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">🛒</div>
                    <div style="font-size:26px; font-weight:800; color:#3b82f6; font-family:var(--font-heading);"><?= $totalOnline ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Sold Online</div>
                </div>
            </div>

            <?php if (empty($stockProducts)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">📦</div>
                <p style="font-size:16px;">No products yet. <a href="product_form.php" class="btn-primary" style="display:inline-flex; margin-left:10px;"><i class="fa-solid fa-plus"></i> Add Product</a></p>
            </div>
            <?php else: ?>

            <!-- Setup warning -->
            <?php if (!$stockMigrationDone || !$stockV2Done): ?>
            <div style="background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); border-radius:var(--radius-sm); padding:14px 18px; margin-bottom:20px; font-size:13px; color:var(--text-secondary); display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                <div style="display:flex; align-items:flex-start; gap:12px;">
                    <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b; margin-top:2px; flex-shrink:0; font-size:16px;"></i>
                    <div><strong style="color:#f59e0b; font-size:14px;">Database setup required</strong><br>Some stock columns are missing. <a href="setup_stock_v3.php">Run Setup Now →</a></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info note -->
            <div style="background:rgba(139,92,246,0.08); border:1px solid rgba(139,92,246,0.2); border-radius:var(--radius-sm); padding:12px 16px; margin-bottom:20px; font-size:13px; color:var(--text-secondary); display:flex; align-items:flex-start; gap:10px;">
                <i class="fa-solid fa-circle-info" style="color:#8b5cf6; margin-top:2px; flex-shrink:0;"></i>
                <span><strong>Click ‘Edit Stock’ to add new stock, damage, or offline sales.</strong> Grand Total, In Stock, Damage and Sold columns are all read-only — updated when you save via the Edit button. Sold Online auto-counts when an order is marked Delivered.</span>
            </div>

            <div class="table-wrapper">
                <table class="data-table" id="stockTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align:center;">Tracked</th>
                            <th style="text-align:center; color:#8b5cf6;">📦 Grand Total<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(auto-cumulative)</div></th>
                            <th style="text-align:center; color:#10b981;">🟢 In Stock<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(auto-calculated)</div></th>
                            <th style="text-align:center; color:#ef4444;">⚠️ Damage<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(cumulative)</div></th>
                            <th style="text-align:center; color:#f59e0b;">🏪 Sold Offline<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(cumulative)</div></th>
                            <th style="text-align:center; color:#3b82f6;">🛒 Sold Online<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(auto on Delivered)</div></th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stockProducts as $sp):
                            $ts  = (int)($sp['total_stock']  ?? $sp['stock_qty'] ?? 0);
                            $dmg = (int)($sp['damage_stock'] ?? 0);
                            $off = (int)($sp['sold_offline'] ?? 0);
                            $sol = (int)($sp['sold_online']  ?? 0);
                            $ins = max(0, $ts - $dmg - $off - $sol);
                        ?>
                        <tr id="stock-row-<?= $sp['id'] ?>">
                            <!-- Product -->
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <?php if (!empty($sp['image'])): ?>
                                    <img src="../uploads/products/<?= htmlspecialchars($sp['image']) ?>"
                                         style="width:40px; height:40px; object-fit:cover; border-radius:8px; border:1px solid var(--border-light);">
                                    <?php else: ?>
                                    <span style="font-size:26px;"><?= htmlspecialchars($sp['emoji'] ?? '✨') ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:700; font-size:14px;"><?= htmlspecialchars($sp['name']) ?></div>
                                        <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase;"><?= htmlspecialchars($sp['category']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <!-- Tracked -->
                            <td style="text-align:center;">
                                <?php if ($sp['track_stock']): ?>
                                <span style="font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; background:rgba(16,185,129,0.12); color:#10b981;">Yes</span>
                                <?php else: ?>
                                <span style="font-size:11px; padding:3px 9px; border-radius:20px; background:rgba(100,100,100,0.1); color:var(--text-muted);">&#8734; Unlimited</span>
                                <?php endif; ?>
                            </td>
                            <!-- Grand Total (read-only) -->
                            <td style="text-align:center;">
                                <span id="val-total_stock-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#8b5cf6; font-family:var(--font-heading);"><?= $ts ?></span>
                            </td>
                            <!-- In Stock (auto, read-only) -->
                            <td style="text-align:center;">
                                <?php if ($sp['track_stock']): ?>
                                <span id="val-in_stock-<?= $sp['id'] ?>" style="font-weight:900; font-size:20px; color:<?= $ins > 0 ? '#10b981' : '#ef4444' ?>; font-family:var(--font-heading);"><?= $ins ?></span>
                                <?php else: ?>
                                <span style="color:var(--text-muted); font-size:13px;">—</span>
                                <?php endif; ?>
                            </td>
                            <!-- Damage (read-only) -->
                            <td style="text-align:center;">
                                <span id="val-damage_stock-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#ef4444; font-family:var(--font-heading);"><?= $dmg ?></span>
                            </td>
                            <!-- Sold Offline (read-only) -->
                            <td style="text-align:center;">
                                <span id="val-sold_offline-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#f59e0b; font-family:var(--font-heading);"><?= $off ?></span>
                            </td>
                            <!-- Sold Online (auto, read-only) -->
                            <td style="text-align:center;">
                                <span id="val-sold_online-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#3b82f6; font-family:var(--font-heading);"><?= $sol ?></span>
                            </td>
                            <!-- Actions -->
                            <td style="text-align:center;">
                                <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                                    <?php if ($sp['track_stock']): ?>
                                    <button class="btn-sm btn-primary" onclick="openStockEdit(<?= $sp['id'] ?>, '<?= htmlspecialchars($sp['name'], ENT_QUOTES) ?>', <?= $ts ?>, <?= $dmg ?>, <?= $off ?>)" style="font-size:12px; padding:5px 12px;">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit Stock
                                    </button>
                                    <?php endif; ?>
                                    <a href="product_form.php?id=<?= $sp['id'] ?>" class="btn-sm btn-sm-outline" title="Edit product" style="font-size:12px; padding:5px 10px;">
                                        <i class="fa-solid fa-gear"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; // End tab conditional ?>
        </div>

        <!-- Stock Edit Modal (incremental) -->
        <div id="stockEditModal" style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
            <div style="background:var(--bg-surface); border-radius:var(--radius-lg); padding:32px 36px; min-width:340px; max-width:420px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.4); position:relative;">
                <button onclick="closeStockEdit()" style="position:absolute; top:14px; right:16px; background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer;">&#x2715;</button>
                <h3 id="stockEditTitle" style="margin:0 0 6px; font-size:17px;">Edit Stock</h3>
                <p id="stockEditSubtitle" style="font-size:12px; color:var(--text-muted); margin:0 0 22px;">All values are additive — enter 0 to skip a field.</p>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="color:#8b5cf6;">📦 Add New Stock</label>
                    <input type="number" id="stockAddQty" class="form-control" min="0" value="0"
                           style="font-size:16px; font-weight:700; text-align:center;"
                           placeholder="e.g. 50">
                    <small style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Adds to Grand Total &rarr; increases In Stock</small>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="color:#ef4444;">⚠️ Add Damage Qty</label>
                    <input type="number" id="stockDamageQty" class="form-control" min="0" value="0"
                           style="font-size:16px; font-weight:700; text-align:center;"
                           placeholder="e.g. 5">
                    <small style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Adds to Damage &rarr; reduces In Stock</small>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" style="color:#f59e0b;">🏪 Add Sold Offline Qty</label>
                    <input type="number" id="stockOfflineQty" class="form-control" min="0" value="0"
                           style="font-size:16px; font-weight:700; text-align:center;"
                           placeholder="e.g. 10">
                    <small style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Adds to Sold Offline &rarr; reduces In Stock</small>
                </div>

                <div id="stockEditMsg" style="font-size:13px; margin-bottom:12px; min-height:18px;"></div>
                <div style="display:flex; gap:12px;">
                    <button class="btn-primary" onclick="saveStockEdit()" style="flex:1;"><i class="fa-solid fa-check"></i> Save</button>
                    <button class="btn-secondary" onclick="closeStockEdit()" style="flex:1;">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ REVENUE TAB ═══════════════════ -->

<?php require_once 'includes/footer.php'; ?>
