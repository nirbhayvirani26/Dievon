<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// Redirect if not logged in
if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = $_SESSION['customer_id'];
$error = '';
$success = '';

// ── Handle Profile Updates ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh and try again.";
    } elseif ($name) {
        try {
            if ($password !== '') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE customers SET name = :name, phone = :phone, address = :address, password = :password WHERE id = :id");
                $stmt->execute(['name' => $name, 'phone' => $phone, 'address' => $address, 'password' => $hashed, 'id' => $customerId]);
            } else {
                $stmt = $pdo->prepare("UPDATE customers SET name = :name, phone = :phone, address = :address WHERE id = :id");
                $stmt->execute(['name' => $name, 'phone' => $phone, 'address' => $address, 'id' => $customerId]);
            }
            $_SESSION['customer_name'] = $name;
            $success = "Profile details updated successfully.";
        } catch (PDOException $e) {
            $error = "Error updating profile.";
        }
    } else {
        $error = "Name field is required.";
    }
}

// ── Fetch Customer Data (Strict IDOR Security) ──────────────────────
$customer = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
    $stmt->execute(['id' => $customerId]);
    $customer = $stmt->fetch();
} catch (PDOException $e) {}

if (!$customer) {
    header('Location: logout.php');
    exit;
}

// ── Fetch Past Orders (Dynamic match by customer_id, email, or normalized phone) ──
$orders = [];
function normalizePhone(string $phone): string {
    // Compare on the last 10 digits so "+44 7879 092355" and "07879092355"
    // (same UK number, different country-code prefix) are treated as equal.
    $digits = preg_replace('/\D+/', '', $phone);
    return substr($digits, -10);
}
try {
    $cEmail = strtolower(trim($customer['email'] ?? ''));
    $cPhone = normalizePhone(trim($customer['phone'] ?? ''));

    // Link any unlinked guest orders placed with matching email or unique normalized phone
    if (!empty($cEmail)) {
        $pdo->prepare("UPDATE orders SET customer_id = :cid WHERE (customer_id IS NULL OR customer_id = 0) AND LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :email")
            ->execute(['cid' => $customerId, 'email' => $cEmail]);
    }
    if (!empty($cPhone)) {
        $phoneCount = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone");
        $phoneCount->execute(['phone' => $cPhone]);
        if ((int)$phoneCount->fetchColumn() === 1) {
            $pdo->prepare("UPDATE orders SET customer_id = :cid, customer_email = CASE WHEN customer_email = '' OR customer_email IS NULL THEN :email ELSE customer_email END WHERE (customer_id IS NULL OR customer_id = 0) AND RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone")
                ->execute(['cid' => $customerId, 'phone' => $cPhone, 'email' => $cEmail]);
        }
    }

    // Built conditionally (rather than reusing :email/:phone twice each) because
    // PDO_MySQL runs native prepares here (ATTR_EMULATE_PREPARES = false), which
    // errors with "Invalid parameter number" if the same named placeholder repeats.
    $conditions = ['customer_id = :id'];
    $params     = ['id' => $customerId];
    if ($cEmail !== '') {
        $conditions[] = "LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :email";
        $params['email'] = $cEmail;
    }
    if ($cPhone !== '') {
        $conditions[] = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.',''), 10) = :phone";
        $params['phone'] = $cPhone;
    }
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE " . implode(' OR ', $conditions) . " ORDER BY id DESC");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Account order fetch error: ' . $e->getMessage());
}

// ── Fetch Customer Address Book ──────────────────────────────────────
$addresses = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM customer_addresses WHERE customer_id = :id ORDER BY is_default DESC, id DESC");
    $stmt->execute(['id' => $customerId]);
    $addresses = $stmt->fetchAll();
} catch (PDOException $e) {}

// ── Fetch Customer RMA Returns ───────────────────────────────────────
$returns = [];
try {
    $stmt = $pdo->prepare("SELECT r.*, o.order_code FROM customer_returns r JOIN orders o ON r.order_id = o.id WHERE r.customer_id = :id ORDER BY r.id DESC");
    $stmt->execute(['id' => $customerId]);
    $returns = $stmt->fetchAll();
} catch (PDOException $e) {}

// ── Fetch Customer Support Tickets ──────────────────────────────────
$tickets = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM customer_tickets WHERE customer_id = :id ORDER BY id DESC");
    $stmt->execute(['id' => $customerId]);
    $tickets = $stmt->fetchAll();
} catch (PDOException $e) {}

// ── Fetch Catalog Products for Wishlist Tab Rendering ────────────────
$accountProducts = [];
try {
    $accountProducts = $pdo->query("SELECT id, name, price, category, image, emoji FROM products WHERE available = 1 ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {}

$pageTitle = "My Account | Dievon VIP Portal";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Luxury Hero Banner ══════════════════════════════════ -->
<section class="luxury-hero section-mb-sm">
    <div class="container">
        <span class="luxury-hero-eyebrow">Atelier VIP Portal</span>
        <h1>User Account</h1>
        <p>Welcome back, <?= htmlspecialchars($customer['name']) ?>. Manage your wardrobe, orders, address book &amp; concierge requests.</p>
    </div>
</section>

<!-- ══ Member Account Main Portal ══════════════════════════ -->
<section class="section-space">
    <div class="container account-container">
        
        <div class="account-layout-grid reveal-on-scroll">
            
            <!-- Left Side: Account Navigation Sidebar -->
            <div class="account-nav-sidebar">
                <div class="account-user-header">
                    <strong><?= htmlspecialchars($customer['name']) ?></strong>
                    <span><?= htmlspecialchars($customer['email']) ?></span>
                </div>

                <ul class="account-menu-list">
                    <li><a href="#dashboard" onclick="showAccountTab('dashboard', this)" class="acc-tab-btn active"><i class="fa-solid fa-gauge-high"></i> Dashboard</a></li>
                    <li><a href="#orders" onclick="showAccountTab('orders', this)" class="acc-tab-btn"><i class="fa-solid fa-box"></i> Orders (<?= count($orders) ?>)</a></li>
                    <li><a href="#addresses" onclick="showAccountTab('addresses', this)" class="acc-tab-btn"><i class="fa-solid fa-address-book"></i> Address Book (<?= count($addresses) ?>)</a></li>
                    <li><a href="#wishlist" onclick="showAccountTab('wishlist', this)" class="acc-tab-btn"><i class="fa-regular fa-heart"></i> Wishlist</a></li>
                    <li><a href="#returns" onclick="showAccountTab('returns', this)" class="acc-tab-btn"><i class="fa-solid fa-rotate-left"></i> RMA Returns (<?= count($returns) ?>)</a></li>
                    <li><a href="#refunds" onclick="showAccountTab('refunds', this)" class="acc-tab-btn"><i class="fa-solid fa-receipt"></i> Refunds</a></li>
                    <li><a href="#support" onclick="showAccountTab('support', this)" class="acc-tab-btn"><i class="fa-solid fa-headset"></i> Support Tickets</a></li>
                    <li><a href="#profile" onclick="showAccountTab('profile', this)" class="acc-tab-btn"><i class="fa-regular fa-user"></i> Profile Details</a></li>
                    <li><a href="#privacy" onclick="showAccountTab('privacy', this)" class="acc-tab-btn"><i class="fa-solid fa-user-shield"></i> Account Privacy</a></li>
                    <li class="signout-item">
                        <a href="logout.php" class="acc-tab-btn signout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out</a>
                    </li>
                </ul>
            </div>

            <!-- Right Side: Dynamic Content Panels -->
            <div class="account-content-panel">
                
                <?php if ($success): ?>
                    <div class="account-alert alert-success">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="account-alert alert-error">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- 1. DASHBOARD OVERVIEW -->
                <div id="tab-dashboard" class="acc-content-tab">
                    <h2 class="acc-tab-title">Account Dashboard Overview</h2>
                    <p class="acc-tab-desc">
                        From your private atelier portal, you can view your order history, download GST tax invoices, manage shipping addresses, and request size exchanges.
                    </p>

                    <!-- Quick Stats Cards -->
                    <div class="acc-stats-grid">
                        <div class="acc-stat-card">
                            <span class="stat-number"><?= count($orders) ?></span>
                            <span class="stat-label">Total Orders</span>
                        </div>
                        <div class="acc-stat-card">
                            <span class="stat-number"><?= count($addresses) ?></span>
                            <span class="stat-label">Saved Addresses</span>
                        </div>
                        <div class="acc-stat-card">
                            <span class="stat-number"><?= count($returns) ?></span>
                            <span class="stat-label">Active Returns</span>
                        </div>
                    </div>

                    <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 400; margin-bottom: 15px;">Recent Order Activity</h3>
                    <?php if (empty($orders)): ?>
                        <p style="color: var(--text-muted); font-size: 13px;">No recent orders placed.</p>
                    <?php else: ?>
                        <div style="padding: 18px; background: var(--bg-surface-soft); border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px;">
                            <strong>Latest Order Code:</strong> <span style="color: var(--color-primary); font-weight: 700;"><?= htmlspecialchars($orders[0]['order_code']) ?></span><br>
                            <strong>Date:</strong> <?= date('M d, Y', strtotime($orders[0]['created_at'])) ?> | <strong>Total:</strong> <?= (!empty($orders[0]['currency_symbol']) ? $orders[0]['currency_symbol'] : '£') . number_format($orders[0]['total_price'], 2) ?><br>
                            <strong>Status:</strong> <span style="font-weight: 700; color: #2e7d32;"><?= htmlspecialchars($orders[0]['status']) ?></span>
                            <div style="margin-top: 12px;">
                                <a href="#orders" onclick="showAccountTab('orders', document.querySelector('[href=\'#orders\']'))" class="btn-luxury-outline" style="font-size: 11px; padding: 6px 14px;">View Full Order History →</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 2. MY ORDERS TAB -->
                <div id="tab-orders" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">My Orders &amp; Invoices</h2>
                    <?php if (empty($orders)): ?>
                        <div style="text-align: center; padding: 50px 0; color: var(--text-secondary);">
                            <div style="font-size: 40px; margin-bottom: 15px; color: var(--text-muted);">📦</div>
                            <p style="text-transform: uppercase; font-size: 12px; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 20px;">You haven't placed any orders yet.</p>
                            <a href="shop" class="btn-luxury" style="font-size: 11px; padding: 10px 24px;">Explore Collections</a>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <?php foreach ($orders as $o):
                                $ordSym = !empty($o['currency_symbol']) ? $o['currency_symbol'] : '£';
                                $orderItems = json_decode($o['items_json'], true) ?: [];
                            ?>
                            <div style="border: 1px solid var(--border-strong); border-radius: 6px; padding: 20px; background: var(--bg-surface);">
                                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 12px; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                                    <div>
                                        <strong style="font-size: 15px; color: var(--color-primary);"><?= htmlspecialchars($o['order_code']) ?></strong>
                                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 2px;">Placed on <?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></span>
                                    </div>
                                    <div style="text-align: right;">
                                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 12px; border-radius: 4px; background: #e6faf7; color: #2e7d32;">
                                            <?= htmlspecialchars($o['status']) ?>
                                        </span>
                                        <span style="font-size: 15px; font-weight: 700; display: block; margin-top: 4px; color: var(--text-primary);"><?= $ordSym . number_format($o['total_price'], 2) ?></span>
                                    </div>
                                </div>

                                <!-- Line Items List -->
                                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px;">
                                    <?php foreach ($orderItems as $it): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; background: var(--bg-surface-soft); padding: 10px 14px; border-radius: 4px;">
                                        <div>
                                            <strong><?= htmlspecialchars($it['name']) ?></strong>
                                            <?php if (!empty($it['color_name']) || !empty($it['variant_name'])): ?>
                                                <span style="font-size: 11px; color: var(--text-muted);"> (<?= htmlspecialchars(trim(($it['color_name'] ?? '') . (!empty($it['color_name']) && !empty($it['variant_name']) ? ' · ' : '') . ($it['variant_name'] ?? ''))) ?>)</span>
                                            <?php endif; ?>
                                            <span style="font-size: 12px; color: var(--text-secondary); display: block;">Qty: <?= (int)$it['quantity'] ?> × <?= $ordSym . number_format($it['price'], 2) ?></span>
                                        </div>
                                        <div style="font-weight: 700; color: var(--color-primary);"><?= $ordSym . number_format($it['quantity'] * $it['price'], 2) ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Action Buttons Row -->
                                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; border-top: 1px solid var(--border-light); padding-top: 12px;">
                                    <a href="print_invoice.php?code=<?= urlencode($o['order_code']) ?>" target="_blank" class="btn-luxury-outline" style="padding: 6px 14px; font-size: 11px;">
                                        <i class="fa-solid fa-file-pdf"></i> Download GST Invoice
                                    </a>

                                    <button onclick="reorderPastItems(<?= $o['id'] ?>)" class="btn-luxury-outline" style="padding: 6px 14px; font-size: 11px;">
                                        <i class="fa-solid fa-rotate-right"></i> Reorder Items
                                    </button>

                                    <?php if (in_array($o['status'], ['Pending', 'Pending Payment', 'Confirmed', 'Processing'])): ?>
                                        <button onclick="requestOrderCancel('<?= htmlspecialchars($o['order_code']) ?>')" class="btn-luxury-outline" style="padding: 6px 14px; font-size: 11px; color: var(--color-danger); border-color: var(--color-danger);">
                                            <i class="fa-solid fa-ban"></i> Request Cancellation
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!empty($o['tracking_number'])): ?>
                                        <span style="font-size: 12px; color: var(--text-muted); margin-left: auto;">
                                            🚚 Courier: <strong><?= htmlspecialchars($o['courier_name'] ?: 'Express') ?></strong> (AWB: <?= htmlspecialchars($o['tracking_number']) ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3. ADDRESS BOOK TAB -->
                <div id="tab-addresses" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">Address Book (Multiple Shipping &amp; Billing)</h2>
                    
                    <div class="acc-address-grid">
                        <?php if (empty($addresses)): ?>
                            <div style="grid-column: 1 / -1; padding: 20px; background: var(--bg-surface-soft); border: 1px solid var(--border-light); font-size: 13px;">
                                No saved addresses found. Add a shipping address below for 1-click checkout.
                            </div>
                        <?php else: ?>
                            <?php foreach ($addresses as $addr): ?>
                            <div class="acc-address-card">
                                <?php if ($addr['is_default']): ?>
                                    <span class="badge-default-addr">Default</span>
                                <?php endif; ?>
                                <h4 class="addr-name"><?= htmlspecialchars($addr['full_name']) ?></h4>
                                <p class="addr-text">
                                    <?= htmlspecialchars($addr['address_line1']) ?><br>
                                    <?php if ($addr['address_line2']): ?><?= htmlspecialchars($addr['address_line2']) ?><br><?php endif; ?>
                                    <?= htmlspecialchars($addr['city']) ?>, <?= htmlspecialchars($addr['state']) ?> <?= htmlspecialchars($addr['postcode']) ?><br>
                                    <?= htmlspecialchars($addr['country']) ?><br>
                                    Phone: <?= htmlspecialchars($addr['phone']) ?>
                                </p>
                                <div style="display: flex; gap: 10px;">
                                    <?php if (!$addr['is_default']): ?>
                                        <button onclick="setDefaultAddress(<?= $addr['id'] ?>)" class="btn-luxury-outline" style="font-size: 10px; padding: 4px 10px;">Set Default</button>
                                    <?php endif; ?>
                                    <button onclick="deleteAddress(<?= $addr['id'] ?>)" class="btn-luxury-outline" style="font-size: 10px; padding: 4px 10px; color: var(--color-danger); border-color: var(--color-danger);">Delete</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Add Address Form -->
                    <div class="acc-form-card">
                        <h3 class="acc-card-title">Add New Address</h3>
                        <form id="addAddressForm" onsubmit="submitNewAddress(event)">
                            <div class="acc-form-row-2">
                                <div>
                                    <label class="form-label-sm">Full Name *</label>
                                    <input type="text" name="full_name" class="form-luxury-input" required placeholder="Eleanor Vance">
                                </div>
                                <div>
                                    <label class="form-label-sm">Phone Number *</label>
                                    <input type="tel" name="phone" class="form-luxury-input" required placeholder="+44 7700 900123">
                                </div>
                            </div>
                            <div style="margin-bottom: 15px;">
                                <label class="form-label-sm">Address Line 1 *</label>
                                <input type="text" name="address_line1" class="form-luxury-input" required placeholder="14 Mayfair Atelier Way">
                            </div>
                            <div style="margin-bottom: 15px;">
                                <label class="form-label-sm">Address Line 2 (Optional)</label>
                                <input type="text" name="address_line2" class="form-luxury-input" placeholder="Suite 4B">
                            </div>
                            <div class="acc-form-row-3">
                                <div>
                                    <label class="form-label-sm">City *</label>
                                    <input type="text" name="city" class="form-luxury-input" required placeholder="London">
                                </div>
                                <div>
                                    <label class="form-label-sm">State / County *</label>
                                    <input type="text" name="state" class="form-luxury-input" required placeholder="Greater London">
                                </div>
                                <div>
                                    <label class="form-label-sm">Postcode / Pincode *</label>
                                    <input type="text" name="postcode" class="form-luxury-input" required placeholder="W1K 2HP">
                                </div>
                            </div>
                            <div style="margin-bottom: 15px;">
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                                    <input type="checkbox" name="is_default" value="1" checked> Set as default shipping address
                                </label>
                            </div>
                            <button type="submit" class="btn-luxury" style="padding: 10px 24px; font-size: 12px;">Save Address</button>
                        </form>
                    </div>
                </div>

                <!-- 4. WISHLIST TAB -->
                <div id="tab-wishlist" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">My Wishlist</h2>
                    <p class="acc-tab-desc">Your saved couture pieces and private wardrobe curation.</p>
                    <div id="accWishlistContainer">
                        <p style="color: var(--text-muted); font-size: 13px;">Loading your wishlist items...</p>
                    </div>
                </div>

                <!-- 5. RMA RETURNS & EXCHANGES TAB -->
                <div id="tab-returns" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">RMA Returns &amp; Size Exchanges</h2>
                    <p class="acc-tab-desc">Submit return requests within 14 days of order receipt under our Dievon Guarantee.</p>

                    <!-- Active Return Requests -->
                    <?php if (!empty($returns)): ?>
                        <div style="margin-bottom: 30px;">
                            <h3 class="acc-card-title">Active Return Requests</h3>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <?php foreach ($returns as $r): ?>
                                <div style="border: 1px solid var(--border-light); padding: 15px; border-radius: 6px; background: var(--bg-surface-soft); font-size: 13px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                                        <strong>RMA Code: <?= htmlspecialchars($r['return_code']) ?></strong> (Order: <?= htmlspecialchars($r['order_code']) ?>)
                                        <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 4px; background: #fff8e1; color: #b7791f;">
                                            <?= htmlspecialchars($r['status']) ?>
                                        </span>
                                    </div>
                                    <div style="margin-top: 6px; color: var(--text-secondary);">
                                        Type: <strong><?= strtoupper(htmlspecialchars($r['request_type'])) ?></strong> | Reason: <?= htmlspecialchars($r['reason']) ?>
                                        <?php if ($r['exchange_size']): ?> | Requested Size: <strong><?= htmlspecialchars($r['exchange_size']) ?></strong><?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Submit Return Form -->
                    <div class="acc-form-card">
                        <h3 class="acc-card-title">Request Return or Size Exchange</h3>
                        <form id="returnRequestForm" onsubmit="submitReturnRequest(event)" enctype="multipart/form-data">
                            <div style="margin-bottom: 15px;">
                                <label class="form-label-sm">Select Order *</label>
                                <select name="order_id" class="form-luxury-input" required>
                                    <option value="">— Select Eligible Order —</option>
                                    <?php foreach ($orders as $o): 
                                        $oSym = !empty($o['currency_symbol']) ? $o['currency_symbol'] : '£';
                                    ?>
                                        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['order_code']) ?> (Placed <?= date('d M Y', strtotime($o['created_at'])) ?> — <?= $oSym . number_format($o['total_price'], 2) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="acc-form-row-2">
                                <div>
                                    <label class="form-label-sm">Request Type *</label>
                                    <select name="request_type" class="form-luxury-input" required onchange="toggleExchangeSizeField(this.value)">
                                        <option value="return">Refund Return</option>
                                        <option value="exchange">Size Exchange</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label-sm">Return Reason *</label>
                                    <select name="reason" class="form-luxury-input" required>
                                        <option value="Sizing Issue">Sizing / Fit Issue</option>
                                        <option value="Defective or Damaged">Defective or Damaged Item</option>
                                        <option value="Different Item Received">Incorrect Item Delivered</option>
                                        <option value="Quality Not as Expected">Fabric / Quality Expectation</option>
                                    </select>
                                </div>
                            </div>
                            <div id="exchangeSizeWrap" style="display: none; margin-bottom: 15px;">
                                <label style="display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 6px;">Replacement Size Requested *</label>
                                <select name="exchange_size" class="form-luxury-input">
                                    <option value="XS">XS (Extra Small)</option>
                                    <option value="S">S (Small)</option>
                                    <option value="M">M (Medium)</option>
                                    <option value="L">L (Large)</option>
                                    <option value="XL">XL (Extra Large)</option>
                                    <option value="XXL">XXL (Double Extra Large)</option>
                                </select>
                            </div>
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 6px;">Upload Photograph (Optional, Max 5MB)</label>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-luxury-input">
                            </div>
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 6px;">Additional Details</label>
                                <textarea name="details" class="form-luxury-input" rows="3" placeholder="Describe the fit or condition issue..."></textarea>
                            </div>
                            <button type="submit" class="btn-luxury" style="padding: 10px 24px; font-size: 12px;">Submit RMA Request</button>
                        </form>
                    </div>
                </div>

                <!-- 6. REFUND INFORMATION TAB -->
                <?php $refundedOrders = array_filter($orders, fn($o) => $o['status'] === 'Refunded'); ?>
                <div id="tab-refunds" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">Refund Status &amp; Credit Information</h2>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">Track full and partial refund processing details for returned orders.</p>
                    <?php if (empty($refundedOrders)): ?>
                    <div style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; font-size: 13px;">
                        <p style="margin: 0 0 10px; color: var(--text-muted);">No active refund transactions pending. Approved refunds deposit directly to your original payment card within 3–5 business days.</p>
                    </div>
                    <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($refundedOrders as $ro): $rSym = !empty($ro['currency_symbol']) ? $ro['currency_symbol'] : '£'; ?>
                        <div style="border: 1px solid var(--border-light); padding: 16px; border-radius: 6px; background: var(--bg-surface-soft); font-size: 13px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong>Order <?= htmlspecialchars($ro['order_code']) ?></strong>
                                <span style="display: block; font-size: 11px; color: var(--text-muted); margin-top: 2px;">Refunded on <?= date('d M Y', strtotime($ro['created_at'])) ?></span>
                            </div>
                            <span style="font-weight: 700; color: var(--color-success);"><?= $rSym . number_format($ro['total_price'], 2) ?> refunded</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 7. SUPPORT TICKETS TAB -->
                <div id="tab-support" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">Atelier Concierge Support Tickets</h2>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">Get dedicated guidance regarding your wardrobe, size advice, or order inquiries.</p>
                    
                    <?php if (!empty($tickets)): ?>
                        <div style="margin-bottom: 25px; display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($tickets as $t): ?>
                            <div style="border: 1px solid var(--border-light); padding: 14px; border-radius: 4px; background: var(--bg-surface-soft); font-size: 13px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <strong><?= htmlspecialchars($t['ticket_code']) ?> — <?= htmlspecialchars($t['subject']) ?></strong>
                                    <span style="font-weight: 700; color: var(--color-primary);"><?= htmlspecialchars($t['status']) ?></span>
                                </div>
                                <p style="margin: 6px 0 0; color: var(--text-secondary);"><?= htmlspecialchars($t['message']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form id="supportTicketForm" onsubmit="submitSupportTicket(event)">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 6px;">Subject *</label>
                            <input type="text" name="subject" class="form-luxury-input" required placeholder="Sizing guidance or delivery inquiry...">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 6px;">Reference Order (Optional)</label>
                            <select name="order_id" class="form-luxury-input">
                                <option value="">— Select Order Reference —</option>
                                <?php foreach ($orders as $o): ?>
                                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['order_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 6px;">Message *</label>
                            <textarea name="message" class="form-luxury-input" rows="4" required placeholder="Describe your inquiry..."></textarea>
                        </div>
                        <button type="submit" class="btn-luxury" style="padding: 10px 24px; font-size: 12px;">Submit Support Ticket</button>
                    </form>
                </div>

                <!-- 8. PROFILE TAB -->
                <div id="tab-profile" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">Edit Profile Information</h2>
                    <form action="account.php" method="POST" style="max-width: 500px;">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <div class="form-luxury-group">
                            <label for="accName">Full Name *</label>
                            <input type="text" id="accName" name="name" class="form-luxury-input" required value="<?= htmlspecialchars($customer['name']) ?>">
                        </div>
                        <div class="form-luxury-group">
                            <label>Email Address</label>
                            <input type="email" class="form-luxury-input" value="<?= htmlspecialchars($customer['email']) ?>" disabled style="background: var(--bg-surface-soft); color: var(--text-muted); cursor: not-allowed;">
                        </div>
                        <div class="form-luxury-group">
                            <label for="accPhone">Phone Number</label>
                            <input type="tel" id="accPhone" name="phone" class="form-luxury-input" value="<?= htmlspecialchars($customer['phone']) ?>">
                        </div>
                        <div class="form-luxury-group">
                            <label for="accAddress">Default Address</label>
                            <textarea id="accAddress" name="address" class="form-luxury-input" rows="3" style="resize: none;"><?= htmlspecialchars($customer['address']) ?></textarea>
                        </div>
                        <button type="submit" name="update_profile" class="btn-luxury" style="padding: 12px 28px; font-size: 12px;">Save Profile Changes</button>
                    </form>
                </div>

                <!-- 9. PRIVACY & DATA RIGHTS TAB -->
                <div id="tab-privacy" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">Account Privacy &amp; Data Rights (GDPR)</h2>
                    <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 25px;">Download a complete copy of your personal data or manage communication preferences.</p>
                    
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <div style="border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; background: var(--bg-surface-soft);">
                            <h4 style="margin: 0 0 8px; font-size: 14px;">Download Personal Data Export</h4>
                            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">Download a complete JSON export of your profile, address book, and order history.</p>
                            <form action="actions/customer_action.php" method="POST">
                                <input type="hidden" name="action" value="export_data">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <button type="submit" class="btn-luxury-outline" style="font-size: 11px; padding: 8px 16px;">Download Personal Data (.JSON)</button>
                            </form>
                        </div>

                        <div style="border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; background: var(--bg-surface-soft);">
                            <h4 style="margin: 0 0 8px; font-size: 14px; color: var(--color-danger);">Request Account Deletion</h4>
                            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">Submit an automated request to permanently erase your profile and address records.</p>
                            <button onclick="requestAccountDeletion()" class="btn-luxury-outline" style="font-size: 11px; padding: 8px 16px; color: var(--color-danger); border-color: var(--color-danger);">Request Profile Erasure</button>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>
</section>

<style>
.acc-tab-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    text-decoration: none;
    transition: var(--transition);
    border-left: 3px solid transparent;
}
.acc-tab-btn i {
    width: 18px;
    text-align: center;
    color: inherit;
    font-size: 14px;
    transition: var(--transition);
}
.acc-tab-btn:hover, .acc-tab-btn.active {
    background: var(--bg-surface-soft);
    color: var(--color-primary);
    border-left-color: var(--color-primary);
    font-weight: 600;
}
.acc-tab-btn:hover i, .acc-tab-btn.active i {
    color: inherit !important;
}
.acc-tab-title {
    font-family: var(--font-heading);
    font-size: 20px;
    font-weight: 400;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 22px;
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 12px;
}
.acc-stat-card {
    background: var(--bg-surface-soft);
    border: 1px solid var(--border-light);
    padding: 20px;
    text-align: center;
    border-radius: 6px;
}
.stat-number {
    font-family: var(--font-heading);
    font-size: 28px;
    font-weight: 400;
    color: var(--color-primary);
    display: block;
}
.stat-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    margin-top: 4px;
    display: block;
}
</style>

<script>
const csrfToken = '<?= generateCsrfToken() ?>';
const accountProducts = <?= json_encode($accountProducts) ?>;

function showAccountTab(tabId, el) {
    document.querySelectorAll('.acc-content-tab').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.acc-tab-btn').forEach(b => b.classList.remove('active'));

    const target = document.getElementById('tab-' + tabId);
    if (target) target.style.display = 'block';

    if (el) el.classList.add('active');
    if (tabId === 'wishlist') renderAccountWishlist();
}

function renderAccountWishlist() {
    const container = document.getElementById('accWishlistContainer');
    if (!container) return;

    const wishlistIds = typeof getWishlist === 'function' ? getWishlist() : [];
    const items = accountProducts.filter(p => wishlistIds.indexOf(p.id.toString()) > -1);

    if (items.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px 0; color: var(--text-secondary);">
                <div style="font-size: 36px; margin-bottom: 10px;">🖤</div>
                <p style="text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 15px;">Your wishlist is currently empty</p>
                <a href="shop.php" class="btn-luxury" style="font-size: 11px; padding: 8px 20px;">Explore Collections</a>
            </div>
        `;
        return;
    }

    let html = '<div class="acc-wishlist-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">';
    items.forEach(p => {
        const imgSrc = p.image ? `uploads/products/${p.image}` : '';
        const imgHtml = imgSrc 
            ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(p.name)}" style="width: 100%; height: 180px; object-fit: cover; border-radius: 4px;">`
            : `<div style="width: 100%; height: 180px; background: var(--bg-surface-soft); display: flex; align-items: center; justify-content: center; font-size: 40px; border-radius: 4px;">${escHtml(p.emoji)}</div>`;

        html += `
            <div class="acc-wishlist-card" style="border: 1px solid var(--border-light); padding: 14px; border-radius: 6px; background: var(--bg-surface); display: flex; flex-direction: column; position: relative;">
                <button onclick="removeAccountWishlist(${p.id})" style="position: absolute; top: 10px; right: 10px; background: transparent; border: none; font-size: 16px; color: var(--color-danger); cursor: pointer;" aria-label="Remove">
                    <i class="fa-solid fa-heart"></i>
                </button>
                <a href="product.php?id=${p.id}" style="display: block; margin-bottom: 10px;">
                    ${imgHtml}
                </a>
                <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">${escHtml(p.category)}</span>
                <strong style="font-size: 13px; margin: 4px 0; font-family: var(--font-heading); color: var(--text-primary); min-height: 36px; line-height: 1.3;">${escHtml(p.name)}</strong>
                <div style="font-size: 12px; font-weight: 700; color: var(--color-secondary); margin-bottom: 12px;">${typeof formatPriceJS === 'function' ? formatPriceJS(p.price) : '£' + p.price}</div>
                
                <div style="display: flex; flex-direction: column; gap: 6px; margin-top: auto;">
                    <a href="product.php?id=${p.id}&select_size=1" class="btn-luxury" style="width: 100%; padding: 8px; font-size: 10px; justify-content: center; text-transform: uppercase;">
                        <i class="fa-solid fa-bag-shopping"></i> Add to Bag
                    </a>
                    <button onclick="removeAccountWishlist(${p.id})" class="btn-luxury-outline" style="width: 100%; padding: 6px; font-size: 10px; justify-content: center; text-transform: uppercase; color: var(--color-danger); border-color: var(--color-danger);">
                        Remove
                    </button>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

function removeAccountWishlist(pid) {
    if (typeof toggleWishlist === 'function') toggleWishlist(pid.toString());
    renderAccountWishlist();
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.location.hash) {
        const hashTab = window.location.hash.replace('#', '');
        const btn = document.querySelector(`[href="${window.location.hash}"]`);
        if (hashTab) showAccountTab(hashTab, btn);
    }
});

function submitNewAddress(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', 'add_address');
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function deleteAddress(id) {
    if (!confirm('Are you sure you want to delete this address?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_address');
    formData.append('address_id', id);
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function setDefaultAddress(id) {
    const formData = new FormData();
    formData.append('action', 'set_default_address');
    formData.append('address_id', id);
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function requestOrderCancel(code) {
    if (!confirm('Are you sure you want to request cancellation for order ' + code + '?')) return;
    const formData = new FormData();
    formData.append('action', 'cancel_order');
    formData.append('order_code', code);
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function submitReturnRequest(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', 'submit_return');
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function submitSupportTicket(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', 'submit_ticket');
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

function reorderPastItems(orderId) {
    const formData = new FormData();
    formData.append('action', 'reorder');
    formData.append('order_id', orderId);
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success && data.redirect) window.location.href = data.redirect;
        });
}

function requestAccountDeletion() {
    if (!confirm('This will submit a request to permanently erase your profile and address records. Continue?')) return;
    const formData = new FormData();
    formData.append('action', 'submit_ticket');
    formData.append('subject', 'Account Deletion Request');
    formData.append('message', 'Customer has requested permanent erasure of their profile and address records under GDPR.');
    formData.append('csrf_token', csrfToken);

    fetch('actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Account deletion request logged (' + data.message.match(/TCK-[A-F0-9]+/)[0] + '). Our privacy officer will process your request within 48 hours.');
            } else {
                alert('Could not submit your request: ' + data.message);
            }
        });
}

function toggleExchangeSizeField(val) {
    const wrap = document.getElementById('exchangeSizeWrap');
    if (wrap) wrap.style.display = val === 'exchange' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
