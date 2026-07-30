<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$orderCode = isset($_GET['code']) ? strtoupper(trim($_GET['code'])) : '';
$order = null;
$error = '';

if ($orderCode !== '') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code");
        $stmt->execute(['code' => $orderCode]);
        $order = $stmt->fetch();
        
        if (!$order) {
            $error = "Order with code " . htmlspecialchars($orderCode) . " not found.";
        }
    } catch (PDOException $e) {
        $error = "Error retrieving order details.";
    }
}

$pageTitle = $orderCode ? "Order " . $orderCode : "Track Order";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero section-mb-sm">
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
                    <div style="padding: 12px; background: #fdf2f2; color: #a94442; border: 1px solid #f5c6cb; font-size: 13px; margin-bottom: 25px; text-align: left; max-width: 400px; margin-left: auto; margin-right: auto;">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form action="orders.php" method="GET" style="max-width: 400px; margin: 0 auto; display: flex; gap: 10px;">
                    <input type="text" name="code" placeholder="e.g. CB-123456" required class="form-luxury-input" style="text-transform: uppercase; text-align: center; font-weight: 600; font-size: 16px;">
                    <button type="submit" class="btn-luxury" style="padding: 0 30px; font-size: 12px;">Track</button>
                </form>
                
                <?php if (isset($_SESSION['customer_id'])): ?>
                    <div style="margin-top: 25px;">
                        <a href="account" style="font-size: 12px; color: var(--color-accent); font-weight: 600; text-decoration: underline;">Back to Dashboard</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: 
            // Parse order items
            $items = [];
            try {
                $items = json_decode($order['items_json'], true) ?: [];
            } catch(Exception $e) {}

            // Determine tracking step index
            // Pending -> Processing -> Shipped -> Delivered
            $status = $order['status'];
            $step = 1;
            if ($status === 'Processing') $step = 2;
            elseif ($status === 'Delivered') $step = 4;
            elseif ($status === 'Cancelled') $step = -1;
            else $step = 1; // Pending
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
                        <?php $ordSym = !empty($order['currency_symbol']) ? $order['currency_symbol'] : (!empty($order['currency']) && $order['currency'] === 'INR' ? '₹' : (!empty($order['currency']) && $order['currency'] === 'USD' ? '$' : '£')); ?>
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
                    <a href="orders" class="btn-luxury-outline" style="font-size: 11px; padding: 10px 24px;">Track Another Order</a>
                    <?php if (isset($_SESSION['customer_id'])): ?>
                        <a href="account" class="btn-luxury" style="font-size: 11px; padding: 10px 24px;">Member Dashboard</a>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
