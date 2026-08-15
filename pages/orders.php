<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$orderCode = isset($_GET['code']) ? strtoupper(trim($_GET['code'])) : '';
$verify    = trim($_GET['verify'] ?? '');
$order     = null;
$error     = '';

/*
 * Tracking used to need the order code alone. That printed the customer's name,
 * full street address, postcode, every item and the payment status to anyone who
 * had the code — no login, no second factor, no rate limit. Codes are random
 * ("CB-" + 8 digits) so they cannot be counted through, but with no throttling
 * the space is sweepable, and a code shared in a screenshot or a forwarded email
 * exposed that order permanently.
 *
 * Now the code must be paired with something only the customer knows — the email
 * on the order or its postcode. A logged-in customer who owns the order skips
 * the check, since they have already proven who they are.
 *
 * pages/print_invoice.php already did ownership properly and is the model here.
 */

// Throttled SERVER-SIDE, on state the client cannot throw away.
//
// The counter lived in $_SESSION, so clearing cookies reset it — and a fresh
// session costs one request. A six-digit postcode has a million combinations,
// but Indian PINs are far from uniformly distributed and a shop's customers
// cluster in a handful of circles, so unlimited guessing against a known order
// code is a real path to someone else's name, address, phone and order history.
//
// recordFailedLogin()/loginLockRemaining() key on the identifier AND the client
// IP and persist in login_attempts, which is exactly the property the session
// counter lacked. Keyed per order code, so guessing one code cannot lock out a
// different customer; the helper's own per-IP cap handles an attacker rotating
// codes. Tighter than sign-in because the secret here is only six digits.
$trackKey = 'track:' . strtolower($orderCode);

if ($orderCode !== '') {
    $lockRemaining = loginLockRemaining($pdo, $trackKey);
    if ($lockRemaining > 0) {
        $mins  = (int)ceil($lockRemaining / 60);
        $error = "Too many tracking attempts. Please wait "
               . ($mins > 1 ? "$mins minutes" : "a minute") . " and try again.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code AND COALESCE(is_deleted,0) = 0");
            $stmt->execute(['code' => $orderCode]);
            $found = $stmt->fetch();   // false, not null, when missing

            $ownsIt = $found
                && !empty($_SESSION['customer_id'])
                && (int)$found['customer_id'] === (int)$_SESSION['customer_id'];

            $verified = false;
            if ($found && $verify !== '') {
                $normalise = fn($v) => strtolower(preg_replace('/\s+/', '', (string)$v));
                $verified = hash_equals($normalise($found['customer_email']), $normalise($verify))
                         || hash_equals($normalise($found['postcode']),       $normalise($verify));
            }

            if ($found && ($ownsIt || $verified)) {
                $order = $found;
                // A correct match clears the counter, so a customer who mistyped
                // twice before getting it right is not left half-locked.
                clearFailedLogins($pdo, $trackKey);
            } else {
                // Deliberately the same message whether or not the code exists, so
                // this cannot be used to discover which codes are real. The failure
                // is recorded either way, for the same reason — a code that exists
                // must not be cheaper to probe than one that does not.
                $error = "We could not match that order. Please check the order code and the "
                       . "email address or postcode used on the order.";
                recordFailedLogin($pdo, $trackKey);
            }
        } catch (PDOException $e) {
            $error = "Error retrieving order details.";
        }
    }
}

$pageTitle = $orderCode ? "Order " . $orderCode : "Track Order";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "Track a Dievon order using your order number.";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero section-mb-sm has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(3) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Atelier Courier</span>
        <h1>Track Bespoke Order</h1>
        <p>Monitor the delivery pathway and atelier processing of your collections order.</p>
    </div>
</section>

<!-- ══ Orders Lookup / Detail Panel ═══════════════════════ -->
<section class="section-space">
    <div class="container reveal-on-scroll" style="max-width: 800px; margin: 0 auto; padding: 0 20px;">
        
        <?php if ($order === null): ?>
            <!-- Lookup Form -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-light); padding: 40px; text-align: center;">
                <span class="editorial-label">Courier Lookup</span>
                <h2 style="font-family: var(--font-heading); font-size: 26px; font-weight: 300; text-transform: uppercase; margin-bottom: 25px; margin-top: 5px;">Enter Order Code</h2>
                
                <?php if ($error !== ''): ?>
                    <?= dvNotice(htmlspecialchars($error), 'danger') ?>
                <?php endif; ?>

                <?php // Second field so an order code on its own no longer reveals a customer's address. ?>
                <form action="orders.php" method="GET" class="track-form">
                    <input type="text" name="code" value="<?= htmlspecialchars($orderCode) ?>"
                           <?php /* From the constant, not typed here: a hard-coded example
                                    goes stale the moment the prefix changes, and this is the
                                    box a customer copies the format from. */ ?>
                           placeholder="Order code, e.g. <?= htmlspecialchars(orderCodeExample()) ?>" required
                           class="form-luxury-input track-input track-input-code">
                    <input type="text" name="verify" placeholder="Email or postcode on the order" required
                           class="form-luxury-input track-input">
                    <button type="submit" class="btn-luxury track-submit">Track Order</button>
                </form>
                <p class="track-hint">
                    For your security we ask for the email address or postcode used on the order.
                    <?php if (!isset($_SESSION['customer_id'])): ?>
                        <a href="<?= SITE_URL ?>/login">Sign in</a> to see all your orders without this step.
                    <?php endif; ?>
                </p>
                
                <?php if (isset($_SESSION['customer_id'])): ?>
                    <div style="margin-top: 25px;">
                        <a href="<?= SITE_URL ?>/account" style="font-size: 12px; color: var(--color-accent); font-weight: 600; text-decoration: underline;">Back to Dashboard</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: 
            // Parse order items
            $items = [];
            try {
                $items = orderItems($order['items_json']);
            } catch(Exception $e) {}

            // Determine tracking step index
            // Pending -> Processing -> Shipped -> Delivered
            $status = $order['status'];
            // Every status the shop can actually set, mapped to a stage.
            //
            // The chain here handled Processing, Delivered and Cancelled and sent
            // everything else to stage 1 — so Confirmed, Packed and, worst of all,
            // SHIPPED all drew a 0% progress bar. A customer whose parcel was
            // genuinely on its way opened the tracker and saw nothing had started.
            // Stage 3 was unreachable: no branch ever produced it, so the "Shipped"
            // marker on the timeline could never light up at all.
            $stepMap = [
                'Pending'                => 1,
                'Pending Payment'        => 1,
                'Cancellation Requested' => 1,
                'Confirmed'              => 2,
                'Processing'             => 2,
                'Packed'                 => 2,
                'Shipped'                => 3,
                'Out for Delivery'       => 3,
                'Delivered'              => 4,
                'Cancelled'              => -1,
                'Returned'               => -1,
                'Refunded'               => -1,
                'RTO'                    => -1,
            ];
            $step = $stepMap[$status] ?? 1;
        ?>
            <!-- Order Details & Timeline -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-light); padding: 40px; margin-bottom: 40px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-light); padding-bottom: 20px; margin-bottom: 35px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h2 style="font-family: var(--font-heading); font-size: 26px; font-weight: 400; text-transform: uppercase; margin: 0; color: var(--text-primary);">Order: <?= htmlspecialchars($order['order_code']) ?></h2>
                        <span style="font-size: 12px; color: var(--text-muted);">Placed on <?= date('F d, Y &bull; h:i A', strtotime($order['created_at'])) ?></span>
                    </div>
                    <div>
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 6px 14px; 
                            background: <?php 
                                if ($status === 'Delivered') echo '#e6faf7; color:#2e7d32;';
                                elseif ($status === 'Pending') echo '#fff8e1; color:#b7791f;';
                                elseif ($status === 'Cancelled') echo '#fdf2f2; color:#a94442;';
                                else echo '#f0ece6; color:#4a4a4a;'; 
                            ?>">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </div>
                </div>

                <!-- Timeline Progress Bar -->
                <?php if ($step > 0): ?>
                    <div style="margin-bottom: 50px; padding: 0 10px;">
                        <div style="display: flex; justify-content: space-between; position: relative; margin-bottom: 10px;">
                            <!-- Progress line background -->
                            <div style="position: absolute; top: 12px; left: 0; width: 100%; height: 2px; background: var(--border-light); z-index: 1;"></div>
                            <!-- Active progress line -->
                            <div style="position: absolute; top: 12px; left: 0; width: <?= (($step - 1) / 3) * 100 ?>%; height: 2px; background: var(--color-accent); z-index: 2; transition: 0.5s;"></div>

                            <!-- Step 1 -->
                            <div style="z-index: 3; text-align: center;">
                                <div style="width: 26px; height: 26px; border-radius: 50%; background: <?= $step >= 1 ? 'var(--color-accent)' : 'var(--bg-surface)' ?>; border: 2px solid var(--color-accent); display: flex; align-items: center; justify-content: center; margin: 0 auto; color: <?= $step >= 1 ? 'white' : 'var(--color-accent)' ?>; font-size: 11px; font-weight: 700;">✓</div>
                                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; display: block; margin-top: 8px; color: <?= $step >= 1 ? 'var(--text-primary)' : 'var(--text-muted)' ?>;">Placed</span>
                            </div>

                            <!-- Step 2 -->
                            <div style="z-index: 3; text-align: center;">
                                <div style="width: 26px; height: 26px; border-radius: 50%; background: <?= $step >= 2 ? 'var(--color-accent)' : 'var(--bg-surface)' ?>; border: 2px solid <?= $step >= 2 ? 'var(--color-accent)' : 'var(--border-strong)' ?>; display: flex; align-items: center; justify-content: center; margin: 0 auto; color: <?= $step >= 2 ? 'white' : 'var(--text-muted)' ?>; font-size: 11px; font-weight: 700;">2</div>
                                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; display: block; margin-top: 8px; color: <?= $step >= 2 ? 'var(--text-primary)' : 'var(--text-muted)' ?>;">Processing</span>
                            </div>

                            <!-- Step 3 -->
                            <div style="z-index: 3; text-align: center;">
                                <div style="width: 26px; height: 26px; border-radius: 50%; background: <?= $step >= 3 ? 'var(--color-accent)' : 'var(--bg-surface)' ?>; border: 2px solid <?= $step >= 3 ? 'var(--color-accent)' : 'var(--border-strong)' ?>; display: flex; align-items: center; justify-content: center; margin: 0 auto; color: <?= $step >= 3 ? 'white' : 'var(--text-muted)' ?>; font-size: 11px; font-weight: 700;">3</div>
                                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; display: block; margin-top: 8px; color: <?= $step >= 3 ? 'var(--text-primary)' : 'var(--text-muted)' ?>;">Shipped</span>
                            </div>

                            <!-- Step 4 -->
                            <div style="z-index: 3; text-align: center;">
                                <div style="width: 26px; height: 26px; border-radius: 50%; background: <?= $step >= 4 ? 'var(--color-accent)' : 'var(--bg-surface)' ?>; border: 2px solid <?= $step >= 4 ? 'var(--color-accent)' : 'var(--border-strong)' ?>; display: flex; align-items: center; justify-content: center; margin: 0 auto; color: <?= $step >= 4 ? 'white' : 'var(--text-muted)' ?>; font-size: 11px; font-weight: 700;">4</div>
                                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; display: block; margin-top: 8px; color: <?= $step >= 4 ? 'var(--text-primary)' : 'var(--text-muted)' ?>;">Delivered</span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="padding: 15px; background: #fdf2f2; color: #a94442; border: 1px solid #f5c6cb; font-size: 14px; font-weight: 500; margin-bottom: 40px; text-align: center;">
                        <i class="fa-solid fa-ban"></i> This order was cancelled. An invoice credit note has been generated.
                    </div>
                <?php endif; ?>

                <!-- Order summary details -->
                <div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 40px; border-top: 1px solid var(--border-light); padding-top: 30px;">
                    <div>
                        <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 500; text-transform: uppercase; margin-bottom: 15px;">Pieces Ordered</h3>
                        <?php $ordSym = orderCurrencySymbol($order); ?>
                        <?php foreach ($items as $item): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 13px; padding: 12px 0; border-bottom: 1px solid var(--border-light);">
                                <span><?= htmlspecialchars($item['name']) ?> (x<?= $item['quantity'] ?>) <?php
                                    $subBits = [];
                                    if (!empty($item['color_name'])) { $subBits[] = 'Colour: ' . htmlspecialchars($item['color_name']); }
                                    if (!empty($item['variant_name'])) { $subBits[] = htmlspecialchars($item['variant_name']); }
                                    if ($subBits) { echo '<br><small style="color:var(--text-muted);">' . implode(' · ', $subBits) . '</small>'; }
                                ?></span>
                                <span style="font-weight: 600;"><?= $ordSym . number_format($item['price'] * $item['quantity'], 2) ?></span>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Totals details -->
                        <div style="margin-top: 20px; font-size: 13px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: var(--text-secondary);">Discount applied:</span>
                                <span style="font-weight: 600; color: var(--color-success);">-<?= $ordSym . number_format($order['discount_amount'], 2) ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: var(--text-secondary);">Express Delivery Charge:</span>
                                <span style="font-weight: 600; text-transform: uppercase; color: var(--color-accent); font-size: 11px;"><?= (float)($order['delivery_charge'] ?? 0) > 0 ? '+' . $ordSym . number_format($order['delivery_charge'], 2) : 'Free' ?></span>
                            </div>
                            <?php if ((float)($order['cod_fee'] ?? 0) > 0): ?>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: var(--text-secondary);">COD Handling Fee:</span>
                                <span style="font-weight: 600; text-transform: uppercase; color: var(--color-accent); font-size: 11px;">+<?= $ordSym . number_format($order['cod_fee'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 600; text-transform: uppercase; border-top: 1px solid var(--border-strong); padding-top: 10px; margin-top: 10px;">
                                <span>Total Price:</span>
                                <span><?= $ordSym . number_format($order['total_price'], 2) ?></span>
                            </div>
                        </div>
                    </div>

                    <div style="border-left: 1px solid var(--border-light); padding-left: 30px;">
                        <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 500; text-transform: uppercase; margin-bottom: 15px;">Shipping Destination</h3>
                        <p style="font-size: 13px; line-height: 1.7; color: var(--text-secondary); margin-bottom: 25px;">
                            <strong><?= htmlspecialchars($order['customer_name']) ?></strong><br>
                            <?= nl2br(htmlspecialchars($order['address'])) ?><br>
                            Postcode: <?= htmlspecialchars($order['postcode']) ?>
                        </p>
                        
                        <h3 style="font-family: var(--font-heading); font-size: 18px; font-weight: 500; text-transform: uppercase; margin-bottom: 15px;">Payment Details</h3>
                        <p style="font-size: 13px; line-height: 1.7; color: var(--text-secondary);">
                            <strong>Mode:</strong> <?= htmlspecialchars($order['payment_method'] === 'online' ? 'Online Card Payment' : 'Private Invoice Billing') ?><br>
                            <strong>Status:</strong> <?= htmlspecialchars($order['payment_status']) ?>
                        </p>
                    </div>
                </div>

                <div style="margin-top: 40px; text-align: center; border-top: 1px solid var(--border-light); padding-top: 30px; display: flex; gap: 20px; justify-content: center;">
                    <a href="<?= SITE_URL ?>/orders" class="btn-luxury-outline" style="font-size: 11px; padding: 10px 24px;">Track Another Order</a>
                    <?php if (isset($_SESSION['customer_id'])): ?>
                        <a href="<?= SITE_URL ?>/account" class="btn-luxury" style="font-size: 11px; padding: 10px 24px;">Member Dashboard</a>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
