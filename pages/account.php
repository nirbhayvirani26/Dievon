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
    // NULL, not '', when left blank — customers.phone is UNIQUE, so a second
    // account saving an empty string collides with the first. Same rule as
    // pages/register.php; see the note there.
    $phone = trim($_POST['phone'] ?? '');
    $phone = ($phone !== '') ? $phone : null;
    $address = trim($_POST['address'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Changing a password here had no length rule at all — an account could be
    // downgraded to a one-character password from inside the profile form even
    // once registration enforced a minimum.
    $pwError = ($password !== '')
        ? validatePasswordStrength($password, (string)($_SESSION['customer_email'] ?? ''), $name)
        : null;

    // Changing a password requires proving you know the current one.
    //
    // It did not, so anyone with the open tab — a shared laptop, a borrowed
    // phone, a session left signed in — could set a new password and lock the
    // owner out of their own account, addresses and order history. Worse, a
    // stolen 30-day "keep me signed in" cookie was enough on its own: it
    // authenticates silently, and this form asked for nothing further.
    //
    // Mirrors what actions/customer_action.php already demands before deleting
    // an account — the lesser action should not be the easier one.
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $reauthError = null;
    if ($password !== '') {
        if ($currentPassword === '') {
            $reauthError = 'Please enter your current password to change it.';
        } else {
            $me = $pdo->prepare("SELECT password FROM customers WHERE id = :id");
            $me->execute(['id' => $customerId]);
            if (!password_verify($currentPassword, (string)$me->fetchColumn())) {
                $reauthError = 'That password is not correct. Your password has not been changed.';
            }
        }

        // A session resumed from a remember-me cookie is not proof of identity.
        // The flag was written at login and read nowhere until now.
        if ($reauthError === null && !empty($_SESSION['auth_via_remember'])) {
            $reauthError = 'Please sign in with your password before changing it.';
        }
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh and try again.";
    } elseif ($reauthError !== null) {
        $error = $reauthError;
    } elseif ($pwError !== null) {
        $error = $pwError;
    } elseif ($name) {
        try {
            if ($password !== '') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE customers SET name = :name, phone = :phone, address = :address, password = :password WHERE id = :id");
                $stmt->execute(['name' => $name, 'phone' => $phone, 'address' => $address, 'password' => $hashed, 'id' => $customerId]);

                // A new session id, so a fixated or shared one cannot outlive the
                // change. Done before the cookies are revoked, while the session
                // is still the one that authenticated.
                session_regenerate_id(true);

                // People change a password to end somebody else's access. A
                // "keep me signed in" cookie that survived it would keep that
                // access alive for another 30 days, so every one is revoked —
                // including this browser's, which still holds its live session.
                rememberMeForgetAll($pdo, (int)$customerId);
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

// Whether this customer has proved they own their email address (clicked the
// verification link). It decides BOTH what orders are visible below and
// whether guest orders may be linked — an email match is only proof of
// ownership after verification. A phone number is never a factor: an account
// whose phone matched a guest order's phone used to be able to see and claim
// that order, which is exactly how a stranger read another customer's orders
// and invoices via the phone field.
$emailVerified = isCustomerEmailVerified($customer);

// ── Fetch Past Orders (customer_id, plus email ONLY when verified) ──
$orders = [];
try {
    $cEmail = strtolower(trim($customer['email'] ?? ''));

    if ($emailVerified) {
        linkGuestOrdersByEmail($pdo, (int)$customerId, $cEmail);
    }

    // Built conditionally (rather than reusing :email twice) because
    // PDO_MySQL runs native prepares here (ATTR_EMULATE_PREPARES = false), which
    // errors with "Invalid parameter number" if the same named placeholder repeats.
    $conditions = ['customer_id = :id'];
    $params     = ['id' => $customerId];
    if ($emailVerified && $cEmail !== '') {
        $conditions[] = "LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :email";
        $params['email'] = $cEmail;
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
<section class="luxury-hero section-mb-sm has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(1) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Atelier VIP Portal</span>
        <h1>User Account</h1>
        <p>Welcome back, <?= htmlspecialchars((string)($customer['name'] ?? '')) ?>. Manage your wardrobe, orders, address book &amp; concierge requests.</p>
    </div>
</section>

<!-- ══ Member Account Main Portal ══════════════════════════ -->
<section class="section-space">
    <div class="container account-container">
        
        <div class="account-layout-grid reveal-on-scroll">
            
            <!-- Left Side: Account Navigation Sidebar -->
            <div class="account-nav-sidebar">
                <div class="account-user-header">
                    <strong><?= htmlspecialchars((string)($customer['name'] ?? '')) ?></strong>
                    <span><?= htmlspecialchars((string)($customer['email'] ?? '')) ?></span>
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
                        <a href="<?= SITE_URL ?>/logout.php" class="acc-tab-btn signout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out</a>
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

                <?php if (!$emailVerified): ?>
                    <div class="account-alert" >
                        <i class="fa-solid fa-envelope-circle-check"></i>
                        <strong>Verify your email to see guest orders.</strong>
                        Any orders placed on this address before you created the account will appear here once you
                        confirm you own it.
                        <a href="<?= SITE_URL ?>/pages/verify_email.php?resend=1&email=<?= urlencode((string)($customer['email'] ?? '')) ?>" style="color: inherit; font-weight: 700; text-decoration: underline; white-space: nowrap;">Resend verification email</a>
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
                        <p class="acc-note">No recent orders placed.</p>
                    <?php else: ?>
                        <div style="padding: 18px; background: var(--bg-surface-soft); border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px;">
                            <strong>Latest Order Code:</strong> <span style="color: var(--color-primary); font-weight: 700;"><?= htmlspecialchars((string)($orders[0]['order_code'] ?? '')) ?></span><br>
                            <strong>Date:</strong> <?= date('M d, Y', strtotime($orders[0]['created_at'])) ?> | <strong>Total:</strong> <?= orderCurrencySymbol($orders[0]) . number_format($orders[0]['total_price'], 2) ?><br>
                            <strong>Status:</strong> <span style="font-weight: 700; color: #2e7d32;"><?= htmlspecialchars((string)($orders[0]['status'] ?? '')) ?></span>
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
                            <a href="<?= SITE_URL ?>/shop" class="btn-luxury" style="font-size: 11px; padding: 10px 24px;">Explore Collections</a>
                        </div>
                    <?php else: ?>
                        <?php
                        /* Filter chips.
                           ────────────────────────────────────────────────────────────
                           Every order is already on the page — the query at the top of
                           this file fetched them all — so filtering is done in the
                           browser. No reload, no second query, and it still works if
                           the customer has one order or forty.

                           Counted from the real rows rather than hard-coded, and a
                           bucket with nothing in it is not printed at all: a "Returns
                           & Refunds (0)" chip invites a click that shows an empty
                           screen. The buckets themselves come from orderStatusGroups()
                           in config.php so this page and any future one agree. */
                        $ordGroupCounts = [];
                        foreach ($orders as $o) {
                            $g = orderStatusGroup((string)($o['status'] ?? ''));
                            if ($g !== '') { $ordGroupCounts[$g] = ($ordGroupCounts[$g] ?? 0) + 1; }
                        }
                        ?>
                        <div class="acc-order-filters" role="group" aria-label="Filter orders by status">
                            <button type="button" class="acc-order-filter is-active" data-filter="all"
                                    onclick="filterAccountOrders('all', this)">
                                All <span>(<?= count($orders) ?>)</span>
                            </button>
                            <?php foreach (orderStatusGroups() as $gKey => $gInfo): ?>
                                <?php if (empty($ordGroupCounts[$gKey])) { continue; } ?>
                                <button type="button" class="acc-order-filter" data-filter="<?= htmlspecialchars($gKey) ?>"
                                        onclick="filterAccountOrders('<?= htmlspecialchars($gKey) ?>', this)">
                                    <?= htmlspecialchars($gInfo['label']) ?> <span>(<?= (int)$ordGroupCounts[$gKey] ?>)</span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div id="accOrdersList" style="display: flex; flex-direction: column; gap: 20px;">
                            <?php foreach ($orders as $o):
                                $ordSym = orderCurrencySymbol($o);
                                $orderItems = orderItems($o['items_json']);
                                $ordGroup = orderStatusGroup((string)($o['status'] ?? ''));
                                [$ordBadgeBg, $ordBadgeFg] = orderStatusBadgeColours((string)($o['status'] ?? ''));
                            ?>
                            <div class="acc-order-card" data-order-group="<?= htmlspecialchars($ordGroup) ?>" style="border: 1px solid var(--border-strong); border-radius: 6px; padding: 20px; background: var(--bg-surface);">
                                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 12px; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                                    <div>
                                        <strong style="font-size: 15px; color: var(--color-primary);"><?= htmlspecialchars((string)($o['order_code'] ?? '')) ?></strong>
                                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 2px;">Placed on <?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></span>
                                    </div>
                                    <div style="text-align: right;">
                                        <?php /* Was hard-coded success green for every status, so "Cancelled"
                                                 and "Refunded" both announced themselves in the colour that
                                                 means "all good" — telling the customer the opposite of what
                                                 happened. Colour now follows the status. */ ?>
                                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 12px; border-radius: 4px; background: <?= $ordBadgeBg ?>; color: <?= $ordBadgeFg ?>;">
                                            <?= htmlspecialchars((string)($o['status'] ?? '')) ?>
                                        </span>
                                        <span style="font-size: 15px; font-weight: 700; display: block; margin-top: 4px; color: var(--text-primary);"><?= $ordSym . number_format($o['total_price'], 2) ?></span>
                                    </div>
                                </div>

                                <!-- Line Items List -->
                                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px;">
                                    <?php foreach ($orderItems as $it): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px; background: var(--bg-surface-soft); padding: 10px 14px; border-radius: 4px;">
                                        <div>
                                            <strong><?= htmlspecialchars((string)($it['name'] ?? '')) ?></strong>
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
                                    <?php // /pages/print_invoice.php, not /print_invoice.php. The file has
                                          // never been at the web root; this only resolved because a catch-all
                                          // rewrite happened to map it, which is not something to depend on —
                                          // one .htaccess change on the live host and every customer's invoice
                                          // link 404s at once. ?>
                                    <a href="<?= SITE_URL ?>/pages/print_invoice.php?code=<?= urlencode($o['order_code']) ?>" target="_blank" rel="noopener" class="btn-luxury-outline" style="padding: 6px 14px; font-size: 11px;">
                                        <i class="fa-solid fa-file-pdf"></i> Download GST Invoice
                                    </a>

                                    <button onclick="reorderPastItems(<?= $o['id'] ?>)" class="btn-luxury-outline" style="padding: 6px 14px; font-size: 11px;">
                                        <i class="fa-solid fa-rotate-right"></i> Reorder Items
                                    </button>

                                    <?php if (in_array($o['status'], ['Pending', 'Pending Payment', 'Confirmed', 'Processing'])): ?>
                                        <button onclick="requestOrderCancel('<?= htmlspecialchars((string)($o['order_code'] ?? '')) ?>')" class="btn-luxury-outline" style="padding: 6px 14px; font-size: 11px; color: var(--color-danger); border-color: var(--color-danger);">
                                            <i class="fa-solid fa-ban"></i> Request Cancellation
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!empty($o['tracking_number'])): ?>
                                        <span style="font-size: 12px; color: var(--text-muted); margin-left: auto;">
                                            <?php // The column is `carrier`. This read $o['courier_name'], which does
                                                  // not exist on the orders table — so the ?: always took the fallback
                                                  // and every customer was told their parcel was with "Express",
                                                  // whichever courier it was actually handed to. ?>
                                            🚚 Courier: <strong><?= htmlspecialchars(trim((string)($o['carrier'] ?? '')) !== '' ? $o['carrier'] : 'Courier') ?></strong> (AWB: <?= htmlspecialchars((string)($o['tracking_number'] ?? '')) ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php /* Only reachable if a bucket empties after load — the chips are built
                                 from real counts, so no chip starts out empty. Kept anyway: silence
                                 after a click reads as a broken page. */ ?>
                        <div id="accOrdersEmpty" style="display: none; text-align: center; padding: 40px 0; color: var(--text-muted);">
                            <p style="text-transform: uppercase; font-size: 12px; letter-spacing: 0.05em; font-weight: 600;">No orders in this group.</p>
                        </div>

                        <script>
                        /* Show only the cards in the chosen bucket.
                           Filtering what is already on the page — no request, no reload, and the
                           back button is unaffected because nothing about the URL changes. */
                        function filterAccountOrders(group, btn) {
                            var cards = document.querySelectorAll('#accOrdersList .acc-order-card');
                            var shown = 0;
                            cards.forEach(function (card) {
                                var match = (group === 'all') || (card.dataset.orderGroup === group);
                                card.style.display = match ? '' : 'none';
                                if (match) { shown++; }
                            });

                            var empty = document.getElementById('accOrdersEmpty');
                            if (empty) { empty.style.display = shown === 0 ? 'block' : 'none'; }

                            document.querySelectorAll('.acc-order-filter').forEach(function (b) {
                                b.classList.toggle('is-active', b === btn);
                                // Screen readers get the state too — without this the active chip is
                                // conveyed by colour alone, which is invisible to anyone not looking.
                                b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                            });
                        }
                        document.querySelectorAll('.acc-order-filter').forEach(function (b) {
                            b.setAttribute('aria-pressed', b.classList.contains('is-active') ? 'true' : 'false');
                        });
                        </script>
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
                                <h4 class="addr-name"><?= htmlspecialchars((string)($addr['full_name'] ?? '')) ?></h4>
                                <p class="addr-text">
                                    <?= htmlspecialchars((string)($addr['address_line1'] ?? '')) ?><br>
                                    <?php if ($addr['address_line2']): ?><?= htmlspecialchars((string)($addr['address_line2'] ?? '')) ?><br><?php endif; ?>
                                    <?= htmlspecialchars((string)($addr['city'] ?? '')) ?>, <?= htmlspecialchars((string)($addr['state'] ?? '')) ?> <?= htmlspecialchars((string)($addr['postcode'] ?? '')) ?><br>
                                    <?= htmlspecialchars((string)($addr['country'] ?? '')) ?><br>
                                    Phone: <?= htmlspecialchars((string)($addr['phone'] ?? '')) ?>
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
                                    <input type="tel" name="phone" class="form-luxury-input" required placeholder="<?= htmlspecialchars(shopPhone()) ?>">
                                </div>
                            </div>
                            <div class="acc-block">
                                <label class="form-label-sm">Address Line 1 *</label>
                                <input type="text" name="address_line1" class="form-luxury-input" required placeholder="<?= htmlspecialchars(shopAddressExample()) ?>">
                            </div>
                            <div class="acc-block">
                                <label class="form-label-sm">Address Line 2 (Optional)</label>
                                <input type="text" name="address_line2" class="form-luxury-input" placeholder="Suite 4B">
                            </div>
                            <div class="acc-form-row-3">
                                <div>
                                    <label class="form-label-sm">City *</label>
                                    <input type="text" name="city" class="form-luxury-input" required placeholder="Mumbai">
                                </div>
                                <div>
                                    <label class="form-label-sm">State / County *</label>
                                    <input type="text" name="state" class="form-luxury-input" required placeholder="Maharashtra">
                                </div>
                                <div>
                                    <label class="form-label-sm">Postcode / Pincode *</label>
                                    <input type="text" name="postcode" class="form-luxury-input" required placeholder="400050">
                                </div>
                            </div>
                            <div class="acc-block">
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                                    <input type="checkbox" name="is_default" value="1" checked> Set as default shipping address
                                </label>
                            </div>
                            <button type="submit" class="btn-luxury acc-btn-sm">Save Address</button>
                        </form>
                    </div>
                </div>

                <!-- 4. WISHLIST TAB -->
                <div id="tab-wishlist" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">My Wishlist</h2>
                    <p class="acc-tab-desc">Your saved couture pieces and private wardrobe curation.</p>
                    <div id="accWishlistContainer">
                        <p class="acc-note">Loading your wishlist items...</p>
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
                            <div class="acc-stack">
                                <?php foreach ($returns as $r): ?>
                                <div style="border: 1px solid var(--border-light); padding: 15px; border-radius: 6px; background: var(--bg-surface-soft); font-size: 13px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                                        <strong>RMA Code: <?= htmlspecialchars((string)($r['return_code'] ?? '')) ?></strong> (Order: <?= htmlspecialchars((string)($r['order_code'] ?? '')) ?>)
                                        <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 4px; background: #fff8e1; color: #b7791f;">
                                            <?= htmlspecialchars((string)($r['status'] ?? '')) ?>
                                        </span>
                                    </div>
                                    <div style="margin-top: 6px; color: var(--text-secondary);">
                                        Type: <strong><?= strtoupper(htmlspecialchars((string)($r['request_type'] ?? ''))) ?></strong> | Reason: <?= htmlspecialchars((string)($r['reason'] ?? '')) ?>
                                        <?php if ($r['exchange_size']): ?> | Requested Size: <strong><?= htmlspecialchars((string)($r['exchange_size'] ?? '')) ?></strong><?php endif; ?>
                                    </div>

                                    <?php
                                    /* Who is collecting it, and the number to track it by.
                                       ────────────────────────────────────────────────────
                                       The shop records both against the RMA — admin/returns.php
                                       has the fields and saves them — but the customer whose
                                       parcel it is was never shown either. They had no way to
                                       know a courier had been booked, and nothing to quote when
                                       chasing it. The data was already being fetched here
                                       (SELECT r.*), just never printed.

                                       Only rendered once the shop has actually filled them in,
                                       so a return still being reviewed shows no empty row. */
                                    $rmaCarrier = trim((string)($r['return_carrier'] ?? ''));
                                    $rmaAwb     = trim((string)($r['return_awb'] ?? ''));
                                    ?>
                                    <?php if ($rmaCarrier !== '' || $rmaAwb !== ''): ?>
                                    <div class="rma-return-tracking">
                                        <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                                        <?php if ($rmaCarrier !== ''): ?>
                                            Return courier: <strong><?= htmlspecialchars($rmaCarrier) ?></strong><?php endif; ?>
                                        <?php if ($rmaAwb !== ''): ?>
                                            <?= $rmaCarrier !== '' ? ' &middot; ' : '' ?>Tracking:
                                            <strong class="rma-awb"><?= htmlspecialchars($rmaAwb) ?></strong>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Submit Return Form -->
                    <div class="acc-form-card">
                        <h3 class="acc-card-title">Request Return or Size Exchange</h3>
                        <form id="returnRequestForm" onsubmit="submitReturnRequest(event)" enctype="multipart/form-data">
                            <div class="acc-block">
                                <label class="form-label-sm">Select Order *</label>
                                <?php
                                    // Only orders that are ACTUALLY eligible.
                                    //
                                    // This list used to include every order the customer had ever
                                    // placed — cancelled ones included — under the heading "Select
                                    // Eligible Order". The server rejects those correctly
                                    // (actions/customer_action.php:237), so a customer picked a
                                    // cancelled order, wrote a reason, attached a photo, and only
                                    // then learned it was never possible.
                                    //
                                    // The tests below are the SAME ones the server applies: status
                                    // must be Delivered or Completed, and delivery must fall inside
                                    // the return window — counted from delivery, not the order date.
                                    $eligibleOrders = array_values(array_filter($orders, function ($o) {
                                        if (!in_array(strtolower((string)($o['status'] ?? '')), ['delivered', 'completed'], true)) {
                                            return false;
                                        }
                                        $deliveredAt = $o['delivered_at'] ?? null;
                                        if (empty($deliveredAt)) { return true; }  // not stamped yet — let the server decide
                                        return time() <= strtotime($deliveredAt) + (RETURN_WINDOW_DAYS * 86400);
                                    }));
                                ?>
                                <select name="order_id" class="form-luxury-input" required <?= empty($eligibleOrders) ? 'disabled' : '' ?>>
                                    <?php if (empty($eligibleOrders)): ?>
                                        <option value="">No orders are currently eligible</option>
                                    <?php else: ?>
                                        <option value="">— Select Eligible Order —</option>
                                        <?php foreach ($eligibleOrders as $o):
                                            $oSym = orderCurrencySymbol($o);
                                            $when = !empty($o['delivered_at']) ? $o['delivered_at'] : $o['created_at'];
                                        ?>
                                            <option value="<?= $o['id'] ?>"><?= htmlspecialchars((string)($o['order_code'] ?? '')) ?> (Delivered <?= date('d M Y', strtotime($when)) ?> — <?= $oSym . number_format($o['total_price'], 2) ?>)</option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <?php if (empty($eligibleOrders)): ?>
                                <p class="form-hint acc-return-empty">
                                    Returns and exchanges are available for <?= (int)RETURN_WINDOW_DAYS ?> days after an order is
                                    delivered. Nothing you have ordered is inside that window at the moment.
                                    <a href="<?= SITE_URL ?>/contact">Contact us</a> if you think that is wrong.
                                </p>
                                <?php endif; ?>
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
                                <label class="acc-field-label">Replacement Size Requested *</label>
                                <select name="exchange_size" class="form-luxury-input">
                                    <option value="XS">XS (Extra Small)</option>
                                    <option value="S">S (Small)</option>
                                    <option value="M">M (Medium)</option>
                                    <option value="L">L (Large)</option>
                                    <option value="XL">XL (Extra Large)</option>
                                    <option value="XXL">XXL (Double Extra Large)</option>
                                </select>
                            </div>
                            <div class="acc-block">
                                <label class="acc-field-label">Upload Photograph (Optional, Max 5MB)</label>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-luxury-input">
                            </div>
                            <div class="acc-block">
                                <label class="acc-field-label">Additional Details</label>
                                <textarea name="details" class="form-luxury-input" rows="3" placeholder="Describe the fit or condition issue..."></textarea>
                            </div>
                            <button type="submit" class="btn-luxury acc-btn-sm">Submit RMA Request</button>
                        </form>
                    </div>
                </div>

                <!-- 6. REFUND INFORMATION TAB -->
                <?php
                // Any order with money returned, not only fully-refunded ones.
                //
                // This filtered on status === 'Refunded', and a PARTIAL refund
                // deliberately leaves the status alone (see markOrderRefundState()
                // in services/RefundService.php). So refunding ₹100 of a ₹344 order
                // put the money back and showed the customer nothing at all — this
                // tab, which promises to "track full and partial refund details",
                // said "no active refund transactions".
                //
                // refunded_amount is the fact that matters: it is above zero exactly
                // when something has been sent back.
                $refundedOrders = array_filter($orders, function ($o) {
                    return $o['status'] === 'Refunded'
                        || (float)($o['refunded_amount'] ?? 0) > 0;
                });
                ?>
                <div id="tab-refunds" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">Refund Status &amp; Credit Information</h2>
                    <p class="acc-intro">Track full and partial refund processing details for returned orders.</p>
                    <?php if (empty($refundedOrders)): ?>
                    <div style="background: var(--bg-surface-soft); border: 1px solid var(--border-light); padding: 20px; border-radius: 6px; font-size: 13px;">
                        <p style="margin: 0 0 10px; color: var(--text-muted);">No active refund transactions pending. Approved refunds deposit directly to your original payment card within 3–5 business days.</p>
                    </div>
                    <?php else: ?>
                    <div class="acc-stack">
                        <?php foreach ($refundedOrders as $ro): $rSym = orderCurrencySymbol($ro); ?>
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
                    <p class="acc-intro">Get dedicated guidance regarding your wardrobe, size advice, or order inquiries.</p>
                    
                    <?php if (!empty($tickets)): ?>
                        <div style="margin-bottom: 25px; display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($tickets as $t): ?>
                            <div class="ticket-item">
                                <div class="ticket-item-head">
                                    <strong><?= htmlspecialchars((string)($t['ticket_code'] ?? '')) ?> — <?= htmlspecialchars((string)($t['subject'] ?? '')) ?></strong>
                                    <span class="ticket-item-status"><?= htmlspecialchars((string)($t['status'] ?? '')) ?></span>
                                </div>
                                <p class="ticket-item-msg"><?= htmlspecialchars((string)($t['message'] ?? '')) ?></p>
                                <?php if (!empty($t['attachment'])): ?>
                                    <a href="<?= SITE_URL ?>/uploads/tickets/<?= htmlspecialchars((string)($t['attachment'] ?? '')) ?>" target="_blank" rel="noopener">
                                        <img src="<?= SITE_URL ?>/uploads/tickets/<?= htmlspecialchars((string)($t['attachment'] ?? '')) ?>"
                                             alt="Photo attached to ticket <?= htmlspecialchars((string)($t['ticket_code'] ?? '')) ?>"
                                             class="ticket-item-photo">
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($t['admin_reply'])): ?>
                                    <div class="ticket-reply">
                                        <div class="ticket-reply-label">
                                            Reply From Your Advisor<?php if (!empty($t['replied_at'])): ?>
                                                · <?= date('j M Y', strtotime($t['replied_at'])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ticket-reply-body"><?= nl2br(htmlspecialchars((string)($t['admin_reply'] ?? ''))) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form id="supportTicketForm" onsubmit="submitSupportTicket(event)">
                        <div class="acc-block">
                            <label class="acc-field-label">Subject *</label>
                            <input type="text" name="subject" class="form-luxury-input" required placeholder="Sizing guidance or delivery inquiry...">
                        </div>
                        <div class="acc-block">
                            <label class="acc-field-label">Reference Order (Optional)</label>
                            <select name="order_id" class="form-luxury-input">
                                <option value="">— Select Order Reference —</option>
                                <?php foreach ($orders as $o): ?>
                                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars((string)($o['order_code'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="acc-block">
                            <label class="acc-field-label">Message *</label>
                            <textarea name="message" class="form-luxury-input" rows="4" required placeholder="Describe your inquiry..."></textarea>
                        </div>
                        <div class="ticket-form-group">
                            <label class="ticket-form-label">Attach a Photo (Optional)</label>
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-luxury-input ticket-file-input" onchange="previewTicketPhoto(this)">
                            <small class="ticket-file-hint">
                                JPG, PNG or WebP · 5MB maximum. Helpful if you are reporting a fault or damage to a garment.
                            </small>
                            <img id="ticketPhotoPreview" alt="Selected photo preview" class="ticket-photo-preview">
                        </div>
                        <button type="submit" class="btn-luxury ticket-submit-btn">Submit Support Ticket</button>
                    </form>
                </div>

                <!-- 8. PROFILE TAB -->
                <div id="tab-profile" class="acc-content-tab" style="display: none;">
                    <h2 class="acc-tab-title">Edit Profile Information</h2>
                    <form action="account.php" method="POST" style="max-width: 500px;">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <div class="form-luxury-group">
                            <label for="accName">Full Name *</label>
                            <input type="text" id="accName" name="name" class="form-luxury-input" required value="<?= htmlspecialchars((string)($customer['name'] ?? '')) ?>">
                        </div>
                        <div class="form-luxury-group">
                            <label>Email Address</label>
                            <input type="email" class="form-luxury-input" value="<?= htmlspecialchars((string)($customer['email'] ?? '')) ?>" disabled style="background: var(--bg-surface-soft); color: var(--text-muted); cursor: not-allowed;">
                        </div>
                        <div class="form-luxury-group">
                            <label for="accPhone">Phone Number</label>
                            <input type="tel" id="accPhone" name="phone" class="form-luxury-input" value="<?= htmlspecialchars((string)($customer['phone'] ?? '')) ?>">
                        </div>
                        <div class="form-luxury-group">
                            <label for="accAddress">Default Address</label>
                            <textarea id="accAddress" name="address" class="form-luxury-input" rows="3" style="resize: none;"><?= htmlspecialchars((string)($customer['address'] ?? '')) ?></textarea>
                        </div>
                        <?php // The password field the handler has always been waiting for.
                              //
                              // account.php:23 reads $_POST['password'], validates its
                              // strength, hashes it and revokes every "keep me signed in"
                              // cookie — a complete, correct change-password path with no
                              // way to reach it. The only password input anywhere on this
                              // page was inside the delete-my-account box, so a signed-in
                              // customer could delete their account but not change their
                              // password: the only route was to sign out and use "forgot
                              // password", which needs access to their inbox.
                              //
                              // Left blank, nothing about the password changes. ?>
                        <div class="form-luxury-group">
                            <label for="accCurrentPassword">Current Password</label>
                            <input type="password" id="accCurrentPassword" name="current_password" class="form-luxury-input"
                                   autocomplete="current-password" data-toggle-visibility
                                   placeholder="Only needed if you are changing your password">
                            <div class="pw-hint acc-hint">
                                Asked for so that nobody who finds this page open can change your password.
                            </div>
                        </div>

                        <div class="form-luxury-group">
                            <label for="accPassword">New Password</label>
                            <input type="password" id="accPassword" name="password" class="form-luxury-input"
                                   autocomplete="new-password" data-toggle-visibility
                                   minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                   placeholder="Leave blank to keep your current password">
                            <div class="pw-hint acc-hint">
                                At least <?= PASSWORD_MIN_LENGTH ?> characters. Changing it signs you out
                                of every other device.
                            </div>
                        </div>
                        <button type="submit" name="update_profile" class="btn-luxury" style="padding: 12px 28px; font-size: 12px;">Save Profile Changes</button>
                    </form>
                </div>

                <!-- 9. PRIVACY & DATA RIGHTS TAB -->
                <div id="tab-privacy" class="acc-content-tab acc-tab-hidden">
                    <h2 class="acc-tab-title">Your Data &amp; Privacy</h2>
                    <p class="acc-tab-intro">Under India's Digital Personal Data Protection Act, 2023 you can ask for a copy of what we hold about you, or ask us to delete it.</p>

                    <div class="acc-privacy-stack">
                        <div class="acc-privacy-card">
                            <h4 class="acc-privacy-title">Download a copy of your data</h4>
                            <p class="acc-privacy-text">A JSON file containing your profile, saved addresses and order history.</p>
                            <form action="<?= SITE_URL ?>/actions/customer_action.php" method="POST">
                                <input type="hidden" name="action" value="export_data">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <button type="submit" class="btn-luxury-outline acc-privacy-btn">Download My Data (.JSON)</button>
                            </form>
                        </div>

                        <div class="acc-privacy-card acc-privacy-card-danger">
                            <h4 class="acc-privacy-title acc-privacy-title-danger">Delete my account</h4>
                            <p class="acc-privacy-text">This happens straight away and cannot be undone. It removes your profile, saved addresses, wishlist and support messages.</p>
                            <p class="acc-privacy-text">
                                Your past orders and invoices are kept for 8 years because Indian tax and GST law requires it &mdash;
                                but your name, email, phone and address are stripped from them, so they no longer identify you.
                                Reviews you have written stay on the product under &ldquo;Dievon Customer&rdquo;.
                            </p>
                            <p class="acc-privacy-text">You cannot delete your account while an order is on its way, because we need your address to deliver it.</p>

                            <form id="deleteAccountForm" onsubmit="submitAccountDeletion(event)">
                                <div class="acc-privacy-field">
                                    <label for="deleteConfirmPassword" class="form-luxury-label">Enter your password to confirm</label>
                                    <input type="password" id="deleteConfirmPassword" name="password" class="form-luxury-input" autocomplete="current-password" required>
                                </div>
                                <button type="submit" class="btn-luxury-outline acc-privacy-btn acc-privacy-btn-danger">Delete My Account Permanently</button>
                            </form>
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

/* Data & Privacy tab. showAccountTab() sets style.display directly when a tab
   is chosen, so this class only has to decide the state the page loads in. */
.acc-tab-hidden { display: none; }
.acc-tab-intro {
    color: var(--text-secondary);
    font-size: 13px;
    margin-bottom: 25px;
}
.acc-privacy-stack {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.acc-privacy-card {
    border: 1px solid var(--border-light);
    background: var(--bg-surface-soft);
    padding: 20px;
    border-radius: 6px;
}
.acc-privacy-card-danger { border-color: var(--color-danger); }
.acc-privacy-title {
    margin: 0 0 8px;
    font-size: 14px;
}
.acc-privacy-title-danger { color: var(--color-danger); }
.acc-privacy-text {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.7;
    margin-bottom: 12px;
}
.acc-privacy-field { margin-bottom: 14px; max-width: 340px; }
.acc-privacy-btn {
    font-size: 11px;
    padding: 8px 16px;
}
.acc-privacy-btn-danger {
    color: var(--color-danger);
    border-color: var(--color-danger);
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
                <a href="<?= SITE_URL ?>/shop.php" class="btn-luxury" style="font-size: 11px; padding: 8px 20px;">Explore Collections</a>
            </div>
        `;
        return;
    }

    let html = '<div class="acc-wishlist-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">';
    items.forEach(p => {
        const imgSrc = p.image ? `${window.SITE_URL}/uploads/products/${p.image}` : '';
        const imgHtml = imgSrc 
            ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(p.name)}" style="width: 100%; height: 180px; object-fit: cover; border-radius: 4px;">`
            : `<div style="width: 100%; height: 180px; background: var(--bg-surface-soft); display: flex; align-items: center; justify-content: center; font-size: 40px; border-radius: 4px;">${escHtml(p.emoji)}</div>`;

        html += `
            <div class="acc-wishlist-card" style="border: 1px solid var(--border-light); padding: 14px; border-radius: 4px; background: var(--bg-surface); display: flex; flex-direction: column; position: relative;">
                <button onclick="removeAccountWishlist(${p.id})" style="position: absolute; top: 10px; right: 10px; background: transparent; border: none; font-size: 16px; color: var(--color-danger); cursor: pointer;" aria-label="Remove">
                    <i class="fa-solid fa-heart"></i>
                </button>
                <a href="<?= SITE_URL ?>/product.php?id=${p.id}" style="display: block; margin-bottom: 10px;">
                    ${imgHtml}
                </a>
                <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">${escHtml(p.category)}</span>
                <strong style="font-size: 13px; margin: 4px 0; font-family: var(--font-heading); color: var(--text-primary); min-height: 36px; line-height: 1.3;">${escHtml(p.name)}</strong>
                <div style="font-size: 12px; font-weight: 700; color: var(--color-secondary); margin-bottom: 12px;">${typeof formatPriceJS === 'function' ? formatPriceJS(p.price) : '<?= currencySymbol() ?>' + p.price}</div>
                
                <div style="display: flex; flex-direction: column; gap: 6px; margin-top: auto;">
                    <?php // select_size=1 dropped — nothing has ever read it. ?>
                    <a href="<?= SITE_URL ?>/product.php?id=${p.id}" class="btn-luxury" style="width: 100%; padding: 8px; font-size: 10px; justify-content: center; text-transform: uppercase;">
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

    fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

async function deleteAddress(id) {
    if (!await dievonConfirm('Are you sure you want to delete this address?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_address');
    formData.append('address_id', id);
    formData.append('csrf_token', csrfToken);

    fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData })
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

    fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

async function requestOrderCancel(code) {
    if (!await dievonConfirm('Are you sure you want to request cancellation for order ' + code + '?')) return;
    const formData = new FormData();
    formData.append('action', 'cancel_order');
    formData.append('order_code', code);
    formData.append('csrf_token', csrfToken);

    fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData })
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

    fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
}

// Client-side guard mirroring the server rules in actions/customer_action.php,
// so an oversized or wrong-type file is caught before the upload is attempted.
function previewTicketPhoto(input) {
    const preview = document.getElementById('ticketPhotoPreview');
    const file = input.files && input.files[0];
    if (!preview) return;
    // Visibility is driven by the .is-visible class, not an inline style.
    if (!file) { preview.classList.remove('is-visible'); preview.removeAttribute('src'); return; }

    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
        alert('Please attach a JPG, PNG or WebP image.');
        input.value = ''; preview.classList.remove('is-visible');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        alert('Photo size must be 5MB or smaller.');
        input.value = ''; preview.classList.remove('is-visible');
        return;
    }
    preview.src = URL.createObjectURL(file);
    preview.classList.add('is-visible');
}

function submitSupportTicket(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('action', 'submit_ticket');
    formData.append('csrf_token', csrfToken);

    fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData })
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

    fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success && data.redirect) window.location.href = data.redirect;
        });
}

// Deletes the account for real. This used to open a support ticket promising a
// person would erase the account "within 48 hours", then report success — while
// nothing at all had been erased and no tool existed to erase it.
async function submitAccountDeletion(e) {
    e.preventDefault();

    const form  = e.target;
    const pwEl  = document.getElementById('deleteConfirmPassword');
    const btn   = form.querySelector('button[type="submit"]');

    if (!pwEl.value) {
        showToast('Password required', 'Please enter your password to confirm.');
        return;
    }

    if (!await dievonConfirm(
        'This deletes your account permanently and cannot be undone. Your past invoices are kept for 8 years as tax law requires, but with your name and contact details removed. Delete your account?'
    )) return;

    const formData = new FormData();
    formData.append('action', 'delete_account');
    formData.append('password', pwEl.value);
    formData.append('csrf_token', csrfToken);

    btn.disabled = true;
    btn.textContent = 'Deleting…';

    try {
        const res  = await fetch(window.SITE_URL + '/actions/customer_action.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            // The session is already gone server-side, so there is nothing to
            // return to — send them to the home page rather than a page that
            // would bounce them to sign-in.
            showToast('Account deleted', data.message);
            setTimeout(() => { window.location.href = window.SITE_URL + '/'; }, 2500);
            return;
        }

        showToast('Not deleted', data.message);
    } catch (err) {
        showToast('Not deleted', 'Something went wrong. Your account has not been changed.');
    }

    // Only reached when the deletion did not happen, so the form stays usable.
    pwEl.value = '';
    btn.disabled = false;
    btn.textContent = 'Delete My Account Permanently';
}

function toggleExchangeSizeField(val) {
    const wrap = document.getElementById('exchangeSizeWrap');
    if (wrap) wrap.style.display = val === 'exchange' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
