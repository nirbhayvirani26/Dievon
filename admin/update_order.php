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
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('orders.manage', true);

require_once '../includes/mailer.php';

// ── Delete Order ──────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_order') {

    // This endpoint had no CSRF token, unlike the product handlers. Deleting an
    // order destroys a tax document, so it gets the same protection the refund
    // handler does. window.ADMIN_CSRF_TOKEN is already published by the header.
    if (!function_exists('verifyCsrfToken') || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security check failed — reload the page and try again.']);
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }

    /*
     * Soft delete, not DELETE FROM orders.
     *
     * An order row IS the GST tax invoice — admin/print_invoice.php rebuilds the
     * document live from it. The old handler destroyed that permanently: no
     * archive table, no backup, no audit log (logAdminAction() writes to a table
     * that does not exist in this database, so those 22 calls are silent no-ops).
     * A delivered-then-deleted order also left sold_online permanently inflated,
     * because the stock restore lives on the status change, not on delete.
     *
     * The row now stays and is hidden from the listing instead, so it can be
     * brought back and the invoice can still be produced if it is ever demanded.
     */
    try {
        // Archiving hides an order from every screen and every total. Recorded
        // so it can be traced back to whoever did it.
        if (function_exists('logAdminAction')) {
            logAdminAction((int)($_SESSION['admin_id'] ?? 1), 'order_archived', 'Order #' . $orderId . ' archived');
        }
        $pdo->prepare("UPDATE orders SET is_deleted = 1, deleted_at = NOW() WHERE id = :id")
            ->execute(['id' => $orderId]);
        echo json_encode(['success' => true, 'message' => 'Order archived. The record is kept for your tax invoice.']);
    } catch (PDOException $e) {
        error_log('Order archive failed for ' . $orderId . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not archive this order.']);
    }
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

// ── Issue a Refund ────────────────────────────────────────
// Real money. Unlike the status/payment handlers below this one requires a CSRF
// token, because a forged status change is annoying whereas a forged refund
// sends cash out of the business.
if (isset($_POST['action']) && $_POST['action'] === 'refund_order') {

    // refunds.manage, NOT the orders.manage this file is gated on at the top.
    //
    // Everything else here changes a status, a note or a tracking number. This
    // one moves money out of the business, and gating it on orders.manage meant
    // the order clerk hired to mark parcels dispatched could also send cash back
    // — which contradicts the note on ADMIN_CAPABILITIES saying refunds belong
    // to the owner and nowhere else. An owner holds '*' so is unaffected.
    requireAdminCapability('refunds.manage', true);

    if (!function_exists('verifyCsrfToken') || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security check failed — reload the page and try again.']);
        exit;
    }

    $orderId      = (int)($_POST['order_id'] ?? 0);
    $amount       = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
    $reason       = trim((string)($_POST['reason'] ?? ''));
    // How a manual (non-Razorpay) refund is being handed back — cash / UPI / bank.
    $payoutChannel = trim((string)($_POST['payout_channel'] ?? ''));

    require_once __DIR__ . '/../services/RefundService.php';
    $refunds = new RefundService($pdo);
    $result  = $refunds->issueRefund($orderId, $amount, $reason, (int)($_SESSION['admin_id'] ?? 0) ?: null, $payoutChannel);

    // Tell the customer only once the money has actually gone back.
    if (!empty($result['success'])) {
        try {
            $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
            $oStmt->execute(['id' => $orderId]);
            $orderRow = $oStmt->fetch(PDO::FETCH_ASSOC);
            if ($orderRow && !empty($orderRow['customer_email'])) {
                getEmailService()->sendRefundIssuedEmail(
                    $orderRow,
                    $amount,
                    (string)($result['reference'] ?? ''),
                    (string)($result['mode'] ?? 'manual')
                );
            }
        } catch (Throwable $e) {
            error_log('Refund email failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    echo json_encode($result);
    exit;
}

// ── Record COD Remittance ────────────────────────────────
// Delivery = cash handed to the courier (payment_status 'Cash'). REMITTANCE is
// the courier actually paying the shop, usually days later. Until it is recorded
// the books cannot say how much courier cash is still outstanding — the COD
// report's "Awaiting Remittance" figure comes from exactly this column.
if (isset($_POST['action']) && $_POST['action'] === 'record_remittance') {
    if (!function_exists('verifyCsrfToken') || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security check failed — reload the page and try again.']);
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }

    try {
        $row = $pdo->prepare("SELECT payment_method, payment_status, total_price, remitted_at FROM orders WHERE id = :id");
        $row->execute(['id' => $orderId]);
        $order = $row->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }
        if (strcasecmp(trim((string)$order['payment_method']), 'cod') !== 0) {
            echo json_encode(['success' => false, 'message' => 'Only COD orders have a courier remittance.']);
            exit;
        }
        if (strcasecmp(trim((string)$order['payment_status']), 'Cash') !== 0) {
            echo json_encode(['success' => false, 'message' => 'Record Cash Received first — remittance is the courier paying you after delivery.']);
            exit;
        }

        $orderTotal = round((float)$order['total_price'], 2);
        $typed      = round((float)str_replace(',', '', (string)($_POST['amount'] ?? '0')), 2);

        $amount   = $typed;
        $defaulted = false;
        $clamped   = false;

        if ($amount <= 0) { $amount = $orderTotal; $defaulted = true; }

        // A remittance can never exceed what the customer paid for the order —
        // clamp so a typo cannot record phantom income.
        //
        // The clamp is now REPORTED. It happened silently, so entering 5000
        // against a ₹500 order recorded ₹500 and answered "Remittance recorded."
        // — the same words as a correct entry. The owner had no way to tell a
        // typo from a real short payment, and a courier genuinely remitting less
        // than the order value (the case this figure exists to track) looked
        // identical to a mistake.
        if ($amount > $orderTotal) { $amount = $orderTotal; $clamped = true; }

        // A SHORT remittance is the thing worth noticing — it means the courier
        // has not handed over everything. Said plainly rather than left for
        // someone to spot in a report.
        $short = round($orderTotal - $amount, 2);

        if (!empty($order['remitted_at'])) {
            // Already recorded once — allow correcting the amount, keep the date.
            $pdo->prepare("UPDATE orders SET remitted_amount = :amt WHERE id = :id")
                ->execute(['amt' => $amount, 'id' => $orderId]);
            $msg = 'Remittance updated.';
        } else {
            $pdo->prepare("UPDATE orders SET remitted_at = NOW(), remitted_amount = :amt WHERE id = :id AND remitted_at IS NULL")
                ->execute(['amt' => $amount, 'id' => $orderId]);
            $msg = 'Remittance recorded.';
        }

        $sym = function_exists('currencySymbol') ? currencySymbol() : '₹';
        if ($clamped) {
            $msg .= ' You entered ' . $sym . number_format($typed, 2)
                  . ', which is more than the order total — recorded as ' . $sym . number_format($orderTotal, 2) . '.';
        } elseif ($defaulted) {
            $msg .= ' Recorded the full order total, ' . $sym . number_format($orderTotal, 2) . '.';
        } elseif ($short > 0) {
            $msg .= ' Note: ' . $sym . number_format($short, 2) . ' short of the order total — '
                  . $sym . number_format($orderTotal, 2) . '.';
        }

        if (function_exists('logAdminAction')) {
            logAdminAction((int)($_SESSION['admin_id'] ?? 1), 'cod_remitted',
                'Order #' . $orderId . ' — COD remittance recorded: ' . $amount
                . ($clamped ? ' (clamped from ' . $typed . ')' : '')
                . ($short > 0 ? ' (short by ' . $short . ')' : ''));
        }
        echo json_encode(['success' => true, 'message' => $msg]);
    } catch (PDOException $e) {
        error_log('Remittance record failed for order ' . $orderId . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not record the remittance.']);
    }
    exit;
}

// ── Update Order Status ───────────────────────────────────
if (isset($_POST['status'])) {
    // Same CSRF gate the delete, refund and remittance actions in this file
    // already have. Status and payment were the two mutating branches without
    // one, so any page the owner visited while signed in could move an order
    // through the shop — mark it Delivered, mark it Cancelled — silently, and a
    // status change here restores stock and rewrites what the GST report counts.
    if (!function_exists('verifyCsrfToken') || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security check failed — reload the page and try again.']);
        exit;
    }

    $status  = trim($_POST['status']);
    // Shares orderStatusList() with the <select> in admin/orders.php, so the
    // two can no longer drift apart the way they had (see config/config.php).
    if (!isValidOrderStatus($status)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    // Refunded and Returned cannot be DECLARED here — they are outcomes.
    //
    // This branch is gated on orders.manage; the Refund panel below is gated on
    // refunds.manage. With 'Refunded' selectable from the ordinary dropdown, an
    // order clerk could reach it in one click and skip the refund system
    // entirely: stock went back on sale, the sale left the GST report, and no
    // money moved and no refund record existed. Same for 'Returned', which
    // belongs to the Returns screen.
    //
    // Enforced HERE and not only by shortening the <select>, because the POST is
    // typeable by anyone who knows the field name — the exact mistake that left
    // variant_handler.php open. See manuallySettableOrderStatuses().
    /* ── Reopening an order that your tax report has written off ──────────
       admin/gst_report.php excludes Cancelled, Refunded and Returned from
       taxable sales. Moving an order OUT of one of those three puts it back in
       — so a mis-picked dropdown quietly makes you remit GST on money you
       refunded, and nothing on screen would say so.

       A CONFIRMATION, not a prohibition, and deliberately so. This shop is run
       by one person who will occasionally mark the wrong row; a hard block
       would trap the order and force a trip to the database to escape, which is
       worse than the mistake. Correcting your own status is a normal operation.
       The only thing worth interrupting is the one move that changes what you
       owe.

       Enforced here rather than in the browser because the POST is typeable by
       anyone who knows the field names — the same reasoning as the manual-status
       check below. */
    $reopenGuarded = ['Cancelled', 'Refunded', 'Returned'];
    try {
        $curStmt = $pdo->prepare("SELECT status FROM orders WHERE id = :id");
        $curStmt->execute(['id' => $orderId]);
        $currentStatus = (string)$curStmt->fetchColumn();
    } catch (PDOException $e) {
        $currentStatus = '';
    }

    if ($currentStatus !== ''
        && in_array($currentStatus, $reopenGuarded, true)
        && !in_array($status, $reopenGuarded, true)
        && ($_POST['confirm_reopen'] ?? '') !== '1') {
        echo json_encode([
            'success'        => false,
            'needs_confirm'  => true,
            'confirm_action' => 'reopen',
            'message'        => 'This order is marked ' . $currentStatus . ', so your GST report currently '
                              . 'excludes it from taxable sales. Changing it to ' . $status . ' counts it as a '
                              . 'sale again, and you would owe GST on it. Continue?',
        ]);
        exit;
    }

    if (!isManuallySettableOrderStatus($status)) {
        echo json_encode([
            'success' => false,
            'message' => $status === 'Refunded'
                ? 'Use the Refund panel to refund this order — that records the money and sets this status itself.'
                : ($status === 'Returned'
                    ? 'Complete the return on the Returns screen — it sets this status and puts the stock back.'
                    : 'That status is set by the shop automatically and cannot be chosen here.'),
        ]);
        exit;
    }

    // Stamp the delivery date the first time an order reaches Delivered.
    // COALESCE keeps the original date if the status is set to Delivered again,
    // so re-saving an order cannot quietly extend the customer's return window.
    if ($status === 'Delivered') {
        try {
            $pdo->prepare("UPDATE orders SET delivered_at = COALESCE(delivered_at, NOW()) WHERE id = :id")
                ->execute(['id' => $orderId]);
        } catch (PDOException $e) {
            error_log('Could not stamp delivered_at for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    try {
        $stmtOrd = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
        $stmtOrd->execute(['id' => $orderId]);
        $orderRow = $stmtOrd->fetch(PDO::FETCH_ASSOC);

        if ($orderRow) {
            $trackingNum = trim($_POST['tracking_number'] ?? ($orderRow['tracking_number'] ?? ''));
            $carrier     = trim($_POST['carrier'] ?? ($orderRow['carrier'] ?? ''));

            /* What the order held before this save, so we can tell what actually
               changed. Read before the UPDATE below overwrites it. */
            $prevStatus   = trim((string)($orderRow['status'] ?? ''));
            $prevTracking = trim((string)($orderRow['tracking_number'] ?? ''));
            $prevCarrier  = trim((string)($orderRow['carrier'] ?? ''));

            // The status is what the customer is told, and dispatch starts the
            // returns clock — both worth being able to trace later.
            if (function_exists('logAdminAction')) {
                logAdminAction((int)($_SESSION['admin_id'] ?? 1), 'order_status_changed',
                    'Order #' . $orderId . ' set to ' . $status);
            }
            $pdo->prepare("UPDATE orders SET status = :status, tracking_number = :tn, carrier = :cr WHERE id = :id")
                ->execute(['status' => $status, 'tn' => $trackingNum, 'cr' => $carrier, 'id' => $orderId]);

            /* Tell the customer when the TRACKING changed, even if the status did not.
               ────────────────────────────────────────────────────────────────────────
               Marking an order Shipped and filling in the courier and AWB afterwards
               is the natural way to work — you often do not have the number until
               Shiprocket has issued it. But the second save left the status on
               Shipped, so it matched last_notified_status and the email was silently
               swallowed. The parcel had a tracking number the buyer was never shown,
               and nothing anywhere said so.

               Only a REAL change counts: the number must be non-empty and different
               from what was stored. Pressing Save again with the same values sends
               nothing, so the ordinary double-click is still protected — and a
               corrected typo does notify, which is right, because the number the
               customer was given before was wrong. */
            $statusChanged   = strcasecmp($prevStatus, $status) !== 0;
            $trackingChanged = $trackingNum !== '' && $trackingNum !== $prevTracking;
            $carrierChanged  = $carrier !== ''     && $carrier     !== $prevCarrier;
            $forceForTracking = !$statusChanged && ($trackingChanged || $carrierChanged);

            // Trigger EmailService Order Status Update (duplicate email prevention enforced inside EmailService)
            try {
                require_once __DIR__ . '/../services/EmailService.php';
                $emailService = new EmailService($pdo);
                $emailService->sendOrderStatusEmail($orderRow, $status, $trackingNum, $carrier, $forceForTracking);
            } catch (\Throwable $exEmail) {
                error_log("Order status email error: " . $exEmail->getMessage());
            }

            // ── Ask for a review, once, on first delivery ──────────────────
            // The review system is fully built, but no customer was ever asked to
            // use it — orders kept arriving with zero reviews. Sent the first time
            // an order reaches Delivered/Completed, and review_requested_at is
            // stamped only when the email actually sent, so a transient mail
            // failure retries on the next status save.
            if (in_array($status, ['Delivered', 'Completed'], true)
                && empty($orderRow['review_requested_at'] ?? null)
                && isset($emailService)) {
                try {
                    if ($emailService->sendReviewRequestEmail($orderRow)) {
                        $pdo->prepare("UPDATE orders SET review_requested_at = NOW() WHERE id = :id")
                            ->execute(['id' => $orderId]);
                    }
                } catch (\Throwable $exReview) {
                    error_log("Review request email error: " . $exReview->getMessage());
                }
            }

            // ── A delivered COD order has been paid ───────────────────
            //
            // payment_status was only ever set by the separate dropdown further
            // down this file, so a COD parcel could be handed over, the cash
            // taken, and the order still read "Unpaid" until somebody remembered
            // a second click. That is not just untidy: the revenue figures count
            // only payment_status IN ('Paid','Cash'), so real takings stayed
            // invisible in the reports. Most of this shop's orders are COD.
            //
            // Narrow on purpose:
            //   * COD only — an online order that is not already Paid has genuinely
            //     not been paid, and inventing a payment would be worse than the
            //     gap it fills.
            //   * Unpaid only — an explicit Paid/Cash chosen by a person is never
            //     overwritten.
            // Recorded in the audit log, because it moves an order's money state.
            if (in_array($status, ['Delivered', 'Completed'], true)
                && strcasecmp(trim((string)($orderRow['payment_method'] ?? '')), 'cod') === 0
                && strcasecmp(trim((string)($orderRow['payment_status'] ?? '')), 'Unpaid') === 0) {
                try {
                    $pdo->prepare("UPDATE orders SET payment_status = 'Cash' WHERE id = :id AND payment_status = 'Unpaid'")
                        ->execute(['id' => $orderId]);
                    if (function_exists('logAdminAction')) {
                        logAdminAction($_SESSION['admin_id'] ?? 1, 'cod_marked_paid',
                            'Order ' . ($orderRow['order_code'] ?? $orderId) . ' marked Cash on delivery');
                    }
                } catch (PDOException $e) {
                    error_log('COD auto-mark failed for order ' . $orderId . ': ' . $e->getMessage());
                }
            }

            // ── Deduct / count stock when order is marked Delivered ───
            if (in_array($status, ['Delivered', 'Completed'])) {
                if (empty($orderRow['stock_deducted'])) {
                    $items = orderItems($orderRow['items_json']);
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

                        // Deduct the variant's own stock — colour-scoped OR NOT.
                        //
                        // The mirror image of the restore defect fixed in
                        // restoreOrderStock(): this demanded a color_id, while
                        // checkout deducts on variant_id alone. A size-only
                        // variant reaching Delivered through this fallback had
                        // sold_online incremented but its size never debited, so
                        // the shop went on offering units it had already shipped.
                        // The two directions have to use the same rule or stock
                        // drifts one way on the way out and another on the way back.
                        if (!empty($item['variant_id'])) {
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
            // One shared implementation, in config/config.php — see the note there.
            // This screen, the customer's cancel button and the Returns screen all
            // call it, so they cannot drift apart again.
            if (in_array($status, ['Cancelled', 'Refunded', 'Returned'], true)) {
                // Tell the stock ledger WHY the units came back, so a
                // cancellation reads differently from a return in Stock History.
                $orderRow['_restock_reason'] = strtolower($status);
                restoreOrderStock($pdo, $orderRow);
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
    // Payment status decides whether an order counts as revenue at all, so this
    // gets the same CSRF gate as the status branch above and the money actions
    // further up. It had none.
    if (!function_exists('verifyCsrfToken') || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security check failed — reload the page and try again.']);
        exit;
    }

    $ps = trim($_POST['payment_status']);
    if (!isValidPaymentStatus($ps)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment status']);
        exit;
    }

    /* 'Paid' means a gateway confirmed the money. A person may not declare it.
       See manuallySettablePaymentStatuses() for the reasoning — briefly, it books
       GST revenue with no settlement behind it. 'Cash' is the state for money the
       shop confirmed itself, whatever form it arrived in. */
    if (!isManuallySettablePaymentStatus($ps)) {
        echo json_encode([
            'success' => false,
            'message' => '"Paid" is set by the payment gateway itself and cannot be applied by hand. '
                       . 'Use "Cash Received" to record money you confirmed yourself.',
        ]);
        exit;
    }

    /* An order the gateway has already confirmed is not ours to edit.
       Razorpay's webhook is the record of that money, and it fires once — anything
       written afterwards wins permanently with nothing left to correct it. Worse,
       demoting a genuinely-paid order to Unpaid makes RefundService refuse to
       refund it, stranding money the shop is actually holding.

       Checked on the server, not just hidden in the dropdown: the browser can be
       made to post anything, and this decides what goes in a tax return. */
    $gwSt = $pdo->prepare("SELECT razorpay_payment_id FROM orders WHERE id = :id");
    $gwSt->execute(['id' => $orderId]);
    $gwRow = $gwSt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (orderIsGatewayPaid($gwRow)) {
        echo json_encode([
            'success' => false,
            'message' => 'This order was paid online and confirmed by Razorpay (payment '
                       . htmlspecialchars((string)$gwRow['razorpay_payment_id']) . '). '
                       . 'Its payment status is set by the gateway and cannot be changed here. '
                       . 'To return money to the customer, use the Refund panel.',
        ]);
        exit;
    }

    try {
        /* Read what the order says BEFORE writing anything.
           ────────────────────────────────────────────────────────────────────
           Everything below used to run on every submission, whether or not the
           value had actually changed. The comment on the email said "if newly
           paid" but nothing checked "newly" — the test was only "is the value
           being submitted Paid or Cash". So selecting Paid on an order that was
           already Paid sent the customer another receipt, and clicking it four
           times sent four. It also wrote four identical audit-log entries,
           which is what an audit log is least useful for.

           A guard cannot live inside the mail function either.
           EmailService::sendPaymentReceivedEmail() documents why it must not
           write last_notified_status — that column tracks fulfilment, and a
           payment is not a fulfilment step — so the payment path has no memory
           of its own to check. The transition is only visible here, at the one
           place that knows both the old value and the new one. */
        $prevSt = $pdo->prepare("SELECT payment_status FROM orders WHERE id = :id");
        $prevSt->execute(['id' => $orderId]);
        $prevPs = trim((string)($prevSt->fetchColumn() ?: ''));

        // Nothing changed: report success and stop. Success is honest — the order
        // does hold the requested status — and re-clicking must be free of charge.
        if (strcasecmp($prevPs, $ps) === 0) {
            echo json_encode([
                'success'   => true,
                'unchanged' => true,
                'message'   => 'Payment status is already ' . $ps . '. No email sent.',
            ]);
            exit;
        }

        // Payment status decides whether an order counts as revenue.
        if (function_exists('logAdminAction')) {
            logAdminAction((int)($_SESSION['admin_id'] ?? 1), 'order_payment_status_changed',
                'Order #' . $orderId . ' payment ' . ($prevPs !== '' ? $prevPs : 'unset') . ' → ' . $ps);
        }
        $pdo->prepare("UPDATE orders SET payment_status = :ps WHERE id = :id")
            ->execute(['ps' => $ps, 'id' => $orderId]);

        /* The receipt goes out on the TRANSITION into a paid state, not on the
           state itself. Paid → Cash is a correction to how the money arrived,
           not a second payment, and the customer must not be told twice that
           their money was received. Only Unpaid → Paid/Cash earns an email. */
        $wasPaid = in_array(strtolower($prevPs), ['paid', 'cash'], true);
        $nowPaid = in_array(strtolower($ps),     ['paid', 'cash'], true);

        if ($nowPaid && !$wasPaid) {
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
