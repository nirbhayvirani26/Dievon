<?php
// ============================================================
//  Dievon – Admin: Update Order Status / Payment Status / Delete
// ============================================================
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
require_once '../includes/mailer.php';

// ── Delete Order ──────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }
    try {
        $pdo->prepare("DELETE FROM orders WHERE id = :id")->execute(['id' => $orderId]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

// ── Update Order Status ───────────────────────────────────
if (isset($_POST['status'])) {
    $status  = trim($_POST['status']);
    // Must match the <select> options in admin/orders.php exactly.
    $allowed = ['Pending Payment', 'Confirmed', 'Processing', 'Packed', 'Shipped', 'Delivered', 'Cancelled', 'Return Requested', 'Returned', 'Refunded', 'Exchange Requested', 'RTO'];

    if (!in_array($status, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    try {
        $stmtOrd = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
        $stmtOrd->execute(['id' => $orderId]);
        $orderRow = $stmtOrd->fetch(PDO::FETCH_ASSOC);

        if ($orderRow) {
            $trackingNum = trim($_POST['tracking_number'] ?? ($orderRow['tracking_number'] ?? ''));
            $carrier     = trim($_POST['carrier'] ?? ($orderRow['carrier'] ?? ''));

            $pdo->prepare("UPDATE orders SET status = :status, tracking_number = :tn, carrier = :cr WHERE id = :id")
                ->execute(['status' => $status, 'tn' => $trackingNum, 'cr' => $carrier, 'id' => $orderId]);

            // Trigger EmailService Order Status Update (duplicate email prevention enforced inside EmailService)
            try {
                require_once __DIR__ . '/../services/EmailService.php';
                $emailService = new EmailService($pdo);
                $emailService->sendOrderStatusEmail($orderRow, $status, $trackingNum, $carrier);
            } catch (\Throwable $exEmail) {
                error_log("Order status email error: " . $exEmail->getMessage());
            }

            // ── Deduct / count stock when order is marked Delivered ───
            if (in_array($status, ['Delivered', 'Completed'])) {
                if (empty($orderRow['stock_deducted'])) {
                    $items = json_decode($orderRow['items_json'], true) ?? [];
                    foreach ($items as $item) {
                        $pid = (int)$item['product_id'];
                        $qty = (int)$item['quantity'];
                        try {
                            $pdo->prepare("UPDATE products SET sold_online = sold_online + :qty WHERE id = :id AND track_stock = 1")
                                ->execute(['qty' => $qty, 'id' => $pid]);
                        } catch (PDOException $e) {
                            try {
                                $pdo->prepare("UPDATE products SET stock_qty = GREATEST(0, stock_qty - :qty) WHERE id = :id AND track_stock = 1")
                                    ->execute(['qty' => $qty, 'id' => $pid]);
                            } catch (PDOException $e2) {}
                        }

                        // Colour-scoped size — also deduct from its own per-colour stock
                        if (!empty($item['color_id']) && !empty($item['variant_id'])) {
                            try {
                                $pdo->prepare("UPDATE product_variants SET stock_qty = GREATEST(0, stock_qty - :qty) WHERE id = :vid AND stock_qty IS NOT NULL")
                                    ->execute(['qty' => $qty, 'vid' => (int)$item['variant_id']]);
                            } catch (PDOException $e3) {}
                        }
                    }
                    try {
                        $pdo->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = :id")->execute(['id' => $orderId]);
                    } catch (PDOException $e) {}
                }
            }

            // ── Restore stock if a Delivered order is later Cancelled/Refunded/Returned ───
            // Only reverses when stock was actually deducted (i.e. it had reached Delivered
            // before) — mirrors the exact restoration logic already used for customer
            // self-service cancellations in actions/customer_action.php.
            if (in_array($status, ['Cancelled', 'Refunded', 'Returned']) && !empty($orderRow['stock_deducted'])) {
                $items = json_decode($orderRow['items_json'], true) ?? [];
                foreach ($items as $item) {
                    $pid = (int)($item['product_id'] ?? 0);
                    $qty = (int)($item['quantity'] ?? 0);
                    try {
                        $pdo->prepare("UPDATE products SET sold_online = GREATEST(0, sold_online - :qty) WHERE id = :pid AND track_stock = 1")
                            ->execute(['qty' => $qty, 'pid' => $pid]);
                    } catch (PDOException $e) {
                        try {
                            $pdo->prepare("UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :pid AND track_stock = 1")
                                ->execute(['qty' => $qty, 'pid' => $pid]);
                        } catch (PDOException $e2) {}
                    }

                    // Colour-scoped size — restore its own per-colour stock too
                    if (!empty($item['color_id']) && !empty($item['variant_id'])) {
                        try {
                            $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + :qty WHERE id = :vid AND stock_qty IS NOT NULL")
                                ->execute(['qty' => $qty, 'vid' => (int)$item['variant_id']]);
                        } catch (PDOException $e3) {}
                    }
                }
                try {
                    $pdo->prepare("UPDATE orders SET stock_deducted = 0 WHERE id = :id")->execute(['id' => $orderId]);
                } catch (PDOException $e) {}
            }
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Update Payment Status ─────────────────────────────────
if (isset($_POST['payment_status'])) {
    $ps      = trim($_POST['payment_status']);
    $allowed = ['Unpaid', 'Paid', 'Cash'];

    if (!in_array($ps, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment status']);
        exit;
    }

    try {
        $pdo->prepare("UPDATE orders SET payment_status = :ps WHERE id = :id")
            ->execute(['ps' => $ps, 'id' => $orderId]);

        // Send payment receipt email to customer if newly paid
        if (in_array($ps, ['Paid', 'Cash'])) {
            try {
                $order = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
                $order->execute(['id' => $orderId]);
                $orderRow = $order->fetch();
                if ($orderRow && !empty($orderRow['customer_email'])) {
                    sendPaymentReceiptEmail($orderRow);
                }
            } catch (Exception $e) {
                error_log('Payment receipt email failed: ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Nothing to update']);
