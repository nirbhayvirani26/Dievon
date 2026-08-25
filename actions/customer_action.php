<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please sign in.']);
    exit;
}

$customerId = $_SESSION['customer_id'];

// Helper response function
function jsonResp($success, $msg, $data = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $msg], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResp(false, 'Invalid request method. POST required.');
}

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResp(false, 'Security validation failed (Invalid or missing CSRF token). Please refresh and try again.');
}

$action = $_POST['action'] ?? '';

// ── 1. ADD CUSTOMER ADDRESS ─────────────────────────────────────────
if ($action === 'add_address') {
    $type = trim($_POST['address_type'] ?? 'shipping');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $line1 = trim($_POST['address_line1'] ?? '');
    $line2 = trim($_POST['address_line2'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $postcode = trim($_POST['postcode'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $isDefault = !empty($_POST['is_default']) ? 1 : 0;

    if (!$fullName || !$line1 || !$city || !$state || !$postcode) {
        jsonResp(false, 'Please fill in all required address fields.');
    }

    try {
        if ($isDefault) {
            $reset = $pdo->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid");
            $reset->execute(['cid' => $customerId]);
        }

        $stmt = $pdo->prepare("INSERT INTO customer_addresses (customer_id, address_type, full_name, phone, address_line1, address_line2, city, state, postcode, country, is_default) VALUES (:cid, :type, :fname, :phone, :l1, :l2, :city, :state, :postcode, :country, :def)");
        $stmt->execute([
            'cid' => $customerId,
            'type' => in_array($type, ['shipping', 'billing', 'both']) ? $type : 'shipping',
            'fname' => $fullName,
            'phone' => $phone,
            'l1' => $line1,
            'l2' => $line2,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
            'country' => $country,
            'def' => $isDefault
        ]);

        jsonResp(true, 'New address added to your address book successfully.');
    } catch (PDOException $e) {
        jsonResp(false, 'Database error adding address.');
    }
}

// ── 2. DELETE CUSTOMER ADDRESS ──────────────────────────────────────
if ($action === 'delete_address') {
    $addrId = (int)($_POST['address_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM customer_addresses WHERE id = :id AND customer_id = :cid");
        $stmt->execute(['id' => $addrId, 'cid' => $customerId]);
        /* Report what actually happened, not what was attempted.
           ────────────────────────────────────────────────────────────────
           The `AND customer_id` above is doing its job — another customer's
           address genuinely cannot be deleted — but nothing checked whether a
           row had matched, so the endpoint answered "deleted successfully" to
           a request that changed nothing. Signed in as one customer and
           passing another customer's address id returned success while the
           address stayed exactly where it was.

           Harmless to the data, misleading to everything reading the reply:
           a screen that trusts it removes a row that still exists. */
        if ($stmt->rowCount() === 0) {
            jsonResp(false, 'That address could not be found on your account.');
        }
        jsonResp(true, 'Address deleted successfully.');
    } catch (PDOException $e) {
        jsonResp(false, 'Error deleting address.');
    }
}

// ── 3. SET DEFAULT ADDRESS ──────────────────────────────────────────
if ($action === 'set_default_address') {
    $addrId = (int)($_POST['address_id'] ?? 0);
    try {
        /* Checked BEFORE clearing the existing default.
           Same fault as delete_address above, with a sharper edge: the reset
           runs first, so passing an id that is not yours cleared the customer's
           real default and then set nothing — leaving the account with no
           default address at all, and answering "updated". Verifying ownership
           first means a bad id changes nothing whatsoever. */
        $own = $pdo->prepare("SELECT id FROM customer_addresses WHERE id = :id AND customer_id = :cid");
        $own->execute(['id' => $addrId, 'cid' => $customerId]);
        if (!$own->fetchColumn()) {
            jsonResp(false, 'That address could not be found on your account.');
        }

        $reset = $pdo->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid");
        $reset->execute(['cid' => $customerId]);

        $stmt = $pdo->prepare("UPDATE customer_addresses SET is_default = 1 WHERE id = :id AND customer_id = :cid");
        $stmt->execute(['id' => $addrId, 'cid' => $customerId]);
        jsonResp(true, 'Default address updated.');
    } catch (PDOException $e) {
        jsonResp(false, 'Error setting default address.');
    }
}

// ── 4. CANCEL ORDER (ELIGIBLE ORDERS ONLY) ──────────────────────────
if ($action === 'cancel_order') {
    $orderCode = trim($_POST['order_code'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code AND customer_id = :cid");
        $stmt->execute(['code' => $orderCode, 'cid' => $customerId]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonResp(false, 'Order not found.');
        }

        $eligibleStatuses = ['Pending', 'Pending Payment', 'Confirmed', 'Processing'];
        if (!in_array($order['status'], $eligibleStatuses)) {
            jsonResp(false, 'This order is already ' . $order['status'] . ' and cannot be cancelled automatically. Please contact concierge support.');
        }

        /*
         * A PAID order is not cancelled here.
         *
         * 'Pending' is the status every order is created with, and it is the first
         * entry above — so before this check a customer could pay by card and then
         * self-cancel one second later. The order went to Cancelled, payment_status
         * stayed 'Paid', the money stayed in the shop's Razorpay account, and
         * nobody was told. Online payment is the default at checkout, so that was
         * the normal path rather than an edge case.
         *
         * Now it becomes a request the shop approves, then refunds from the admin
         * Refund panel (services/RefundService.php). Unpaid orders are unaffected
         * and still cancel immediately — there is nothing to give back.
         */
        $isPaid = in_array((string)($order['payment_status'] ?? ''), ['Paid', 'Cash'], true);

        if ($isPaid) {
            $pdo->prepare("UPDATE orders SET status = 'Cancellation Requested' WHERE id = :id")
                ->execute(['id' => $order['id']]);

            $order['status'] = 'Cancellation Requested';
            try {
                // mailer.php is pulled in per-branch in this file (see the ticket
                // handler further down), not at the top — so it has to be required
                // here too or getEmailService() is undefined.
                require_once __DIR__ . '/../includes/mailer.php';
                getEmailService()->sendCancellationRequestEmails($order);
            } catch (\Throwable $e) {
                error_log('Cancellation request email failed for ' . $orderCode . ': ' . $e->getMessage());
            }

            jsonResp(true, 'We have received your cancellation request for ' . htmlspecialchars($orderCode)
                . '. As this order is already paid, our team will confirm it and refund you — '
                . 'you will get an email with the refund reference.');
        }

        $upd = $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = :id");
        $upd->execute(['id' => $order['id']]);

        // Reverse the checkout deduction, through the one shared implementation in
        // config/config.php — the same call the Orders screen and the Returns
        // screen make, so all three can never disagree again.
        //
        // The comment that stood here claimed "stock is only ever deducted when an
        // order reaches Delivered … never at checkout". That stopped being true
        // when checkout started reserving stock at order time, and the stale
        // assumption is how the colour-guard asymmetry survived unnoticed: this
        // path answered the customer "cancelled and stock restored" while a
        // size-only variant got nothing back. restoreOrderStock() is a no-op
        // unless stock_deducted is set, so an order that never deducted is still
        // never inflated.
        try {
            // The shopper cancelled it themselves — recorded as such in the
            // stock ledger, so it reads differently from a return.
            $order['_restock_reason'] = 'cancelled';
            restoreOrderStock($pdo, $order);
        } catch (PDOException $exStock) {
            /* Stock that fails to come back is stock the shop has lost.
               ────────────────────────────────────────────────────────────────
               The customer is told their order is cancelled either way, and
               that is right — the cancellation itself succeeded. But if the
               units never returned to the shelf, the shop is now understating
               what it owns, with no order to explain the difference and nothing
               anywhere saying so. Loud in the log, because putting them back
               afterwards needs a person and they need to know to look. */
            error_log('RESTOCK FAILED after customer cancellation — order '
                . ($order['order_code'] ?? ($order['id'] ?? '?'))
                . ' — stock NOT returned: ' . $exStock->getMessage());
        }

        // Tell both sides. Neither was notified before: the shop could pack and ship
        // an order the customer had already cancelled (courier cost, then an RTO),
        // and the customer had no written proof the cancellation went through.
        // EmailService already had finished 'cancelled' wording — nothing on the
        // customer path ever reached it.
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $order['status'] = 'Cancelled';
            getEmailService()->sendOrderStatusEmail($order, 'Cancelled');
            getEmailService()->sendOrderCancelledAdminEmail($order);
        } catch (\Throwable $e) {
            error_log('Cancellation email failed for ' . $orderCode . ': ' . $e->getMessage());
        }

        jsonResp(true, 'Order ' . htmlspecialchars($orderCode) . ' has been cancelled and stock restored.');
    } catch (PDOException $e) {
        jsonResp(false, 'Error processing cancellation.');
    }
}

// ── 5. SUBMIT RMA RETURN / EXCHANGE REQUEST ─────────────────────────
if ($action === 'submit_return') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $requestType = trim($_POST['request_type'] ?? 'return');
    $reason = trim($_POST['reason'] ?? '');
    $exchangeSize = trim($_POST['exchange_size'] ?? '');
    $details = trim($_POST['details'] ?? '');

    if (!$orderId || !$reason) {
        jsonResp(false, 'Please select an order and provide a return reason.');
    }

    try {
        // Verify order ownership & return eligibility (14-Day window & Delivered status)
        $chk = $pdo->prepare("SELECT * FROM orders WHERE id = :oid AND customer_id = :cid");
        $chk->execute(['oid' => $orderId, 'cid' => $customerId]);
        $order = $chk->fetch();

        if (!$order) {
            jsonResp(false, 'Unauthorized or invalid order selected.');
        }

        /* An order that already has a request is a different answer.
           ────────────────────────────────────────────────────────────────
           Submitting a return moves the order to "Return Requested", so a
           second attempt fell through to the Delivered check below and was
           told "Returns are only permitted for orders that have been marked as
           Delivered" — about an order the customer had just had delivered and
           just filed a return on. The refusal was correct; the reason given
           was nonsense, and the natural next step is to contact support about
           a problem that does not exist.

           Checked before the status test, and it names the existing request so
           the customer can quote it. */
        $existing = $pdo->prepare(
            "SELECT return_code, status FROM customer_returns
              WHERE order_id = :oid AND customer_id = :cid
              ORDER BY id DESC LIMIT 1"
        );
        $existing->execute(['oid' => $orderId, 'cid' => $customerId]);
        if ($prior = $existing->fetch()) {
            jsonResp(false, 'A request already exists for this order (' . $prior['return_code']
                          . ', currently ' . $prior['status'] . '). Please contact concierge support to change it.');
        }

        // Verify order is Delivered / Completed
        $validStatuses = ['Delivered', 'Completed', 'delivered', 'completed'];
        if (!in_array(strtolower($order['status']), ['delivered', 'completed'])) {
            jsonResp(false, 'Returns are only permitted for orders that have been marked as Delivered.');
        }

        /*
         * 14-day return window, counted from DELIVERY.
         *
         * This used to measure from $order['created_at'] — the date the order was
         * placed — while pages/returns.php:23 and the printed invoice both promise
         * "within 14 days of delivery". With 2–5 days in transit the customer
         * really got 9–11 days and was then told the window had expired, which is
         * a promise the shop was publicly making and quietly not keeping.
         *
         * delivered_at is stamped by admin/update_order.php the first time an
         * order is marked Delivered. Orders delivered before that column existed
         * have no stamp, so they fall back to created_at — the old behaviour, but
         * only for that shrinking set of historical orders.
         */
        $deliveredAt = !empty($order['delivered_at']) ? strtotime((string)$order['delivered_at']) : null;
        $windowStart = $deliveredAt ?: strtotime((string)$order['created_at']);
        $cutoff      = strtotime('-' . RETURN_WINDOW_DAYS . ' days');

        if ($windowStart < $cutoff) {
            $daysAgo = (int)floor((time() - $windowStart) / 86400);
            jsonResp(false, sprintf(
                'The %d-day return window for this order has expired (%s %d days ago).',
                RETURN_WINDOW_DAYS,
                $deliveredAt ? 'delivered' : 'ordered',
                $daysAgo
            ));
        }

        // Check if RMA already exists for this order
        $dupChk = $pdo->prepare("SELECT COUNT(*) FROM customer_returns WHERE order_id = :oid AND status != 'Rejected'");
        $dupChk->execute(['oid' => $orderId]);
        if ((int)$dupChk->fetchColumn() > 0) {
            jsonResp(false, 'A return request has already been submitted for this order.');
        }

        // File upload validation & security
        $photoPath = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // Strict executable script block
            $forbiddenExts = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'js', 'sh', 'exe', 'py', 'cgi', 'pl'];
            if (in_array($ext, $forbiddenExts) || !in_array($ext, $allowedExts)) {
                jsonResp(false, 'Invalid file type. Only JPG, PNG, WebP, and PDF files are allowed.');
            }

            if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
                jsonResp(false, 'Photo size must be 5MB or smaller.');
            }

            // MIME type check
            $mimeType = @mime_content_type($file['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            if ($mimeType && !in_array($mimeType, $allowedMimes)) {
                jsonResp(false, 'File MIME type validation failed.');
            }

            $uploadDir = __DIR__ . '/../uploads/returns/';
            // Creates the dir and writes the script-execution guard. Uses the shared
            // helper because the previous inline .htaccess used an unguarded `php_flag`,
            // which is fatal under CGI/FPM and made Apache 500 on every RMA photo.
            hardenUploadDir($uploadDir);

            $fileName = 'rma_' . bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                // Phone photos are frequently 4-8MB; shrink before storing.
                optimizeUploadedImage($uploadDir . $fileName);
                $photoPath = $fileName;
            }
        }

        $returnCode = 'RMA-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("INSERT INTO customer_returns (return_code, order_id, customer_id, request_type, reason, exchange_size, details, photo_path, status) VALUES (:rcode, :oid, :cid, :rtype, :reason, :esize, :details, :photo, 'Pending')");
        $stmt->execute([
            'rcode' => $returnCode,
            'oid' => $orderId,
            'cid' => $customerId,
            'rtype' => in_array($requestType, ['return', 'exchange']) ? $requestType : 'return',
            'reason' => $reason,
            'esize' => $exchangeSize ?: null,
            'details' => $details,
            'photo' => $photoPath
        ]);

        // Update order status to Return Requested
        $updOrder = $pdo->prepare("UPDATE orders SET status = 'Return Requested' WHERE id = :oid");
        $updOrder->execute(['oid' => $orderId]);

        /* Tell both sides, exactly as the cancellation handler above does.
           ────────────────────────────────────────────────────────────────────
           Neither was told before. The customer got an on-screen line they lose
           on refresh and nothing in writing — no RMA code in their inbox. The
           shop got nothing at all, so a return surfaced only when somebody
           happened to open the returns page and could sit unseen for days.

           The customer's email already existed and was simply never reached:
           sendOrderStatusEmail() has a 'return requested' case, written and
           ready, but the raw UPDATE above moves the order without going through
           the status path. Note the asymmetry that hid this — a return status
           set in ADMIN does email the customer (admin/update_order.php), so only
           the customer's own submission was silent.

           Wrapped in its own try/catch and placed AFTER the save, so a mail
           failure can never cost the customer their return: the RMA is already
           written and the order already moved. Failures are logged, not shown —
           the request genuinely did succeed. */
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $order['status'] = 'Return Requested';
            getEmailService()->sendOrderStatusEmail($order, 'Return Requested');
            getEmailService()->sendReturnRequestedAdminEmail([
                'return_code'   => $returnCode,
                'order_code'    => $order['order_code'] ?? ('#' . $orderId),
                'customer_name' => $order['customer_name'] ?? '',
                'request_type'  => $requestType,
                'reason'        => $reason,
                'details'       => $details,
                'exchange_size' => $exchangeSize,
                'photo_path'    => $photoPath,
            ]);
        } catch (\Throwable $e) {
            error_log('Return request email failed for ' . $returnCode . ': ' . $e->getMessage());
        }

        jsonResp(true, 'RMA Request ' . $returnCode . ' submitted successfully. Our atelier team will review your photos.');
    } catch (PDOException $e) {
        jsonResp(false, 'Database error submitting return request.');
    }
}

// ── 6. SUBMIT SUPPORT TICKET ────────────────────────────────────────
if ($action === 'submit_ticket') {
    $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$subject || !$message) {
        jsonResp(false, 'Please fill in both the subject and message fields.');
    }

    // Optional photo of the product issue. Mirrors the RMA upload rules in the
    // return-request handler above: extension allowlist, explicit executable
    // blocklist, size cap, MIME check, random filename, and an .htaccess that
    // turns off PHP execution inside the upload directory.
    $ticketPhoto = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $forbiddenExts = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'js', 'sh', 'exe', 'py', 'cgi', 'pl'];
        if (in_array($ext, $forbiddenExts) || !in_array($ext, $allowedExts)) {
            jsonResp(false, 'Invalid file type. Please attach a JPG, PNG or WebP image.');
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            jsonResp(false, 'Photo size must be 5MB or smaller.');
        }
        $mimeType = @mime_content_type($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if ($mimeType && !in_array($mimeType, $allowedMimes)) {
            jsonResp(false, 'File MIME type validation failed.');
        }

        $uploadDir = __DIR__ . '/../uploads/tickets/';
        hardenUploadDir($uploadDir);

        $fileName = 'tck_' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            // Phone photos are frequently 4-8MB; shrink before storing.
            optimizeUploadedImage($uploadDir . $fileName);
            $ticketPhoto = $fileName;
        }
    } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        jsonResp(false, 'The photo could not be uploaded. Please try again.');
    }

    try {
        $ticketCode = 'TCK-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("INSERT INTO customer_tickets (ticket_code, customer_id, order_id, subject, message, attachment, status) VALUES (:tcode, :cid, :oid, :subj, :msg, :att, 'Open')");
        $stmt->execute([
            'tcode' => $ticketCode,
            'cid' => $customerId,
            'oid' => $orderId,
            'subj' => $subject,
            'msg' => $message,
            'att' => $ticketPhoto
        ]);

        // Notify the customer (with their ticket code) and alert an advisor.
        // Wrapped so a mail/SMTP failure can never lose an already-saved ticket.
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $cStmt = $pdo->prepare("SELECT name, email FROM customers WHERE id = :id LIMIT 1");
            $cStmt->execute(['id' => $customerId]);
            $cust = $cStmt->fetch();

            $orderCode = null;
            if ($orderId) {
                $oStmt = $pdo->prepare("SELECT order_code FROM orders WHERE id = :id LIMIT 1");
                $oStmt->execute(['id' => $orderId]);
                $orderCode = $oStmt->fetchColumn() ?: null;
            }

            if ($cust && !empty($cust['email'])) {
                getEmailService()->sendTicketCreatedEmails([
                    'ticket_code' => $ticketCode,
                    'subject'     => $subject,
                    'message'     => $message,
                    'attachment'  => $ticketPhoto,
                    'order_code'  => $orderCode,
                ], $cust['name'] ?? 'Customer', $cust['email']);
            }
        } catch (\Throwable $mailErr) {
            error_log('Ticket email failed for ' . $ticketCode . ': ' . $mailErr->getMessage());
        }

        jsonResp(true, 'Support Ticket ' . $ticketCode . ' created. A confirmation email is on its way and an advisor will reply shortly.');
    } catch (PDOException $e) {
        jsonResp(false, 'Error logging support ticket.');
    }
}

// ── 7. EXPORT PERSONAL DATA (GDPR / PRIVACY) ────────────────────────
if ($action === 'export_data') {
    try {
        $custStmt = $pdo->prepare("SELECT id, name, email, phone, address, created_at FROM customers WHERE id = :cid");
        $custStmt->execute(['cid' => $customerId]);
        $profile = $custStmt->fetch();

        $ordStmt = $pdo->prepare("SELECT order_code, total_price, status, payment_method, created_at FROM orders WHERE customer_id = :cid");
        $ordStmt->execute(['cid' => $customerId]);
        $orders = $ordStmt->fetchAll();

        $addrStmt = $pdo->prepare("SELECT full_name, phone, address_line1, address_line2, city, state, postcode, country FROM customer_addresses WHERE customer_id = :cid");
        $addrStmt->execute(['cid' => $customerId]);
        $addresses = $addrStmt->fetchAll();

        $exportData = [
            'export_date' => date('Y-m-d H:i:s'),
            'store' => 'Dievon',
            'profile' => $profile,
            'addresses' => $addresses,
            'order_history' => $orders
        ];

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="dievon_personal_data_' . $customerId . '.json"');
        echo json_encode($exportData, JSON_PRETTY_PRINT);
        exit;
    } catch (PDOException $e) {
        jsonResp(false, 'Error exporting data.');
    }
}

// ── 8. REORDER PAST ITEMS ───────────────────────────────────────────
if ($action === 'reorder') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    try {
        // Verify order ownership
        $chk = $pdo->prepare("SELECT * FROM orders WHERE id = :oid AND customer_id = :cid");
        $chk->execute(['oid' => $orderId, 'cid' => $customerId]);
        $order = $chk->fetch();
        if (!$order) jsonResp(false, 'Unauthorized order access.');

        // Items are stored as JSON on the order itself — order_items is never populated at checkout.
        $items = orderItems($order['items_json']);
        if (empty($items)) jsonResp(false, 'No items found in this past order.');

        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        $addedCount = 0;
        $skippedCount = 0;

        foreach ($items as $it) {
            $productId = (int)($it['product_id'] ?? 0);
            $variantId = !empty($it['variant_id']) ? (int)$it['variant_id'] : 0;

            // Only re-add items whose product (and variant, if any) is still available today.
            $pChk = $pdo->prepare("SELECT * FROM products WHERE id = :pid AND available = 1");
            $pChk->execute(['pid' => $productId]);
            $product = $pChk->fetch();
            if (!$product) { $skippedCount++; continue; }

            if ($variantId > 0) {
                $vChk = $pdo->prepare("SELECT * FROM product_variants WHERE id = :vid AND product_id = :pid AND available = 1");
                $vChk->execute(['vid' => $variantId, 'pid' => $productId]);
                if (!$vChk->fetch()) { $skippedCount++; continue; }
            }

            $cartKey = $variantId > 0 ? "{$productId}:var{$variantId}" : (string)$productId;
            $qty = (int)($it['quantity'] ?? 1);

            if (isset($_SESSION['cart'][$cartKey])) {
                $_SESSION['cart'][$cartKey]['quantity'] += $qty;
            } else {
                $_SESSION['cart'][$cartKey] = [
                    'cart_key'     => $cartKey,
                    'product_id'   => $productId,
                    'variant_id'   => $variantId > 0 ? $variantId : null,
                    'size'         => null,
                    'variant_name' => $it['variant_name'] ?? '',
                    'name'         => $it['name'] ?? $product['name'],
                    'emoji'        => $it['emoji'] ?? ($product['emoji'] ?? '✨'),
                    'image'        => $it['image'] ?? ($product['image'] ?? ''),
                    'price'        => (float)($it['price'] ?? $product['price']),
                    'quantity'     => $qty,
                ];
            }
            $addedCount++;
        }

        if ($addedCount === 0) {
            jsonResp(false, 'None of the items from this order are still available.');
        }

        $msg = 'Items from order re-added to your shopping bag!';
        if ($skippedCount > 0) {
            $msg .= " ({$skippedCount} item" . ($skippedCount > 1 ? 's' : '') . " no longer available and skipped.)";
        }

        jsonResp(true, $msg, ['redirect' => 'cart.php']);
    } catch (PDOException $e) {
        jsonResp(false, 'Error reordering items.');
    }
}

// ── 9. DELETE ACCOUNT ───────────────────────────────────────────────
//
// The privacy notice tells customers they can have their account removed, and
// that order and invoice records are the one thing we cannot remove because
// Indian tax and GST law requires eight years of them. This carries that out
// literally: everything personal goes, the financial record stays but stops
// naming anybody.
//
// It used to open a support ticket saying a person would erase the account
// "within 48 hours" — nothing was erased, and no tool existed to erase it, so
// the promise depended entirely on somebody remembering.
if ($action === 'delete_account') {

    // Re-authenticate. A session left open on a shared or borrowed device must
    // not be enough to destroy an account; the password is the thing only the
    // account holder has.
    $confirmPassword = (string)($_POST['password'] ?? '');
    if ($confirmPassword === '') {
        jsonResp(false, 'Please enter your password to confirm.');
    }

    try {
        $meStmt = $pdo->prepare("SELECT id, name, email, password FROM customers WHERE id = :cid");
        $meStmt->execute(['cid' => $customerId]);
        $me = $meStmt->fetch();

        if (!$me || !password_verify($confirmPassword, (string)$me['password'])) {
            // Deliberately not "wrong password" vs "no such account" — this
            // endpoint is reached only when signed in, but the log records the
            // attempt either way.
            try {
                $pdo->prepare("INSERT INTO customer_logs (customer_id, action, ip_address, user_agent) VALUES (:cid, 'delete_account_failed', :ip, :ua)")
                    ->execute([
                        'cid' => $customerId,
                        'ip'  => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                        'ua'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    ]);
            } catch (PDOException $e) {}
            jsonResp(false, 'That password is not correct. Your account has not been changed.');
        }

        // An order still on its way needs the delivery address to arrive, and a
        // refund still owed needs somebody to pay it to. Erasing the account
        // underneath either one strands the customer, so those are refused with
        // the reason rather than half-performed.
        $liveStmt = $pdo->prepare(
            "SELECT order_code, status FROM orders
              WHERE customer_id = :cid
                AND (is_deleted IS NULL OR is_deleted = 0)
                AND status IN ('Pending', 'Processing', 'Confirmed', 'Packed', 'Shipped', 'Out for Delivery', 'Refund Pending')"
        );
        $liveStmt->execute(['cid' => $customerId]);
        $liveOrders = $liveStmt->fetchAll();

        if ($liveOrders) {
            $codes = implode(', ', array_column($liveOrders, 'order_code'));
            jsonResp(false, 'You still have an order in progress (' . htmlspecialchars($codes) . '). '
                . 'We need your delivery details until it arrives. Please wait until it is delivered or cancelled, '
                . 'then delete your account — or email ' . shopContactEmail() . ' and we will handle it for you.');
        }

        // Files first, and outside the transaction: a rolled-back transaction
        // cannot un-delete a file, so the paths are collected now and only
        // unlinked once the database work has actually committed.
        $filesToRemove = [];

        $tkStmt = $pdo->prepare("SELECT attachment FROM customer_tickets WHERE customer_id = :cid AND attachment IS NOT NULL AND attachment <> ''");
        $tkStmt->execute(['cid' => $customerId]);
        foreach ($tkStmt->fetchAll(PDO::FETCH_COLUMN) as $f) {
            $filesToRemove[] = __DIR__ . '/../uploads/tickets/' . basename((string)$f);
        }

        $rtStmt = $pdo->prepare("SELECT photo_path FROM customer_returns WHERE customer_id = :cid AND photo_path IS NOT NULL AND photo_path <> ''");
        $rtStmt->execute(['cid' => $customerId]);
        foreach ($rtStmt->fetchAll(PDO::FETCH_COLUMN) as $f) {
            $filesToRemove[] = __DIR__ . '/../uploads/returns/' . basename((string)$f);
        }

        $email = (string)$me['email'];

        /* The receipt goes out BEFORE the rows are erased.
           ────────────────────────────────────────────────────────────────────
           This is the one message that cannot be sent afterwards: the address is
           about to be deleted, so there is nothing left to write to. A person
           asking to be forgotten gets proof it was honoured, which is the whole
           point of the request, and until now they got only an on-screen line.

           Counted first, because the receipt states how many orders were kept —
           tax law requires a shop to hold its sales records, so they survive
           anonymised, and a receipt claiming "everything has been deleted" while
           order rows remain would be untrue.

           Its own try/catch, and deliberately NOT allowed to stop the deletion:
           a mail failure must never be the reason somebody cannot close their
           account. Logged instead. */
        $ordersKept = 0;
        try {
            $okStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = :cid");
            $okStmt->execute(['cid' => $customerId]);
            $ordersKept = (int)$okStmt->fetchColumn();
        } catch (\Throwable $e) { /* the count is a courtesy, not a precondition */ }

        try {
            require_once __DIR__ . '/../includes/mailer.php';
            getEmailService()->sendAccountDeletedEmail($email, (string)($me['name'] ?? ''), $ordersKept);
        } catch (\Throwable $e) {
            error_log('Account deletion receipt failed for customer ' . $customerId . ': ' . $e->getMessage());
        }

        $pdo->beginTransaction();

        // Orders: kept, because tax law requires them — but they stop being
        // about a person. customer_id is nullable, so the row survives with no
        // route back to the deleted account.
        $pdo->prepare(
            "UPDATE orders
                SET customer_id = NULL,
                    customer_name  = 'Deleted account',
                    customer_email = '',
                    phone = '',
                    address = CONCAT('[erased at customer request] ', COALESCE(postcode, ''))
              WHERE customer_id = :cid"
        )->execute(['cid' => $customerId]);

        // Reviews stay on the product — the notice says we take the name off
        // rather than pull the review, so other shoppers keep the rating.
        $pdo->prepare(
            "UPDATE product_reviews
                SET customer_id = NULL, user_id = NULL,
                    reviewer_name = 'Dievon Customer', author_name = 'Dievon Customer'
              WHERE customer_id = :cid"
        )->execute(['cid' => $customerId]);

        // Returns are part of the refund trail, so the row is kept and scrubbed.
        // customer_id is NOT NULL here, so it keeps a number that now points at
        // nothing — which is exactly what makes it non-identifying.
        $pdo->prepare(
            "UPDATE customer_returns
                SET details = '[erased at customer request]', photo_path = NULL
              WHERE customer_id = :cid"
        )->execute(['cid' => $customerId]);

        // Everything below is ours to delete outright.
        foreach ([
            "DELETE FROM customer_remember_tokens WHERE customer_id = :cid",
            "DELETE FROM customer_tickets        WHERE customer_id = :cid",
            "DELETE FROM customer_addresses      WHERE customer_id = :cid",
            "DELETE FROM review_reports          WHERE customer_id = :cid",
            "DELETE FROM razorpay_pending_orders WHERE customer_id = :cid",
            "DELETE FROM customer_logs           WHERE customer_id = :cid",
        ] as $sql) {
            $pdo->prepare($sql)->execute(['cid' => $customerId]);
        }

        foreach ([
            "DELETE FROM password_resets        WHERE email = :email",
            "DELETE FROM newsletter_subscribers WHERE email = :email",
            "DELETE FROM inquiries              WHERE email = :email",
        ] as $sql) {
            $pdo->prepare($sql)->execute(['email' => $email]);
        }

        $pdo->prepare("DELETE FROM customers WHERE id = :cid")->execute(['cid' => $customerId]);

        // Proof the deletion happened, holding no personal data: a one-way hash
        // of the address means a later "did you erase me?" can be answered
        // without us having kept the thing we were asked to erase.
        try {
            $pdo->prepare("INSERT INTO account_deletions (email_hash, deleted_at, ip_address) VALUES (:h, NOW(), :ip)")
                ->execute([
                    'h'  => hash('sha256', mb_strtolower(trim($email))),
                    'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ]);
        } catch (PDOException $e) {
            // The audit table is a nicety; its absence must not roll back a
            // deletion the customer asked for and is entitled to.
            error_log('account_deletions insert failed: ' . $e->getMessage());
        }

        $pdo->commit();

        foreach ($filesToRemove as $f) {
            if (is_file($f)) { @unlink($f); }
        }

        // End the session completely — a surviving session id keyed to a
        // customer row that no longer exists is a broken half-signed-in state.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        jsonResp(true, 'Your account has been deleted. Order and invoice records are kept for 8 years as Indian tax law requires, but they no longer carry your name or contact details.', ['redirect' => 'index.php']);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Account deletion failed for customer ' . $customerId . ': ' . $e->getMessage());
        jsonResp(false, 'We could not complete the deletion. Nothing has been changed. Please email ' . shopContactEmail() . ' and we will do it for you.');
    }
}

jsonResp(false, 'Invalid customer action.');
