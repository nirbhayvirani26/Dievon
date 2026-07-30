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
    $country = trim($_POST['country'] ?? 'United Kingdom');
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
        jsonResp(true, 'Address deleted successfully.');
    } catch (PDOException $e) {
        jsonResp(false, 'Error deleting address.');
    }
}

// ── 3. SET DEFAULT ADDRESS ──────────────────────────────────────────
if ($action === 'set_default_address') {
    $addrId = (int)($_POST['address_id'] ?? 0);
    try {
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

        $upd = $pdo->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = :id");
        $upd->execute(['id' => $order['id']]);

        // Stock is only ever deducted when an order reaches "Delivered" (see admin/update_order.php,
        // which increments `sold_online` — not stock_qty directly), never at checkout — so only
        // reverse it here if it was actually deducted, and mirror the same field it touched,
        // otherwise cancelling would silently inflate stock that was never subtracted.
        if (!empty($order['stock_deducted'])) {
            try {
                $items = json_decode($order['items_json'] ?? '[]', true) ?: [];
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
                $pdo->prepare("UPDATE orders SET stock_deducted = 0 WHERE id = :id")->execute(['id' => $order['id']]);
            } catch (PDOException $exStock) {}
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

        // Verify order is Delivered / Completed
        $validStatuses = ['Delivered', 'Completed', 'delivered', 'completed'];
        if (!in_array(strtolower($order['status']), ['delivered', 'completed'])) {
            jsonResp(false, 'Returns are only permitted for orders that have been marked as Delivered.');
        }

        // Verify 14-Day Return Window
        $orderDate = strtotime($order['created_at']);
        $fourteenDaysAgo = strtotime('-14 days');
        if ($orderDate < $fourteenDaysAgo) {
            jsonResp(false, 'The 14-day return window for this order has expired.');
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
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
                // Create .htaccess to prevent PHP execution in uploads directory
                file_put_contents($uploadDir . '.htaccess', "php_flag engine off\n<FilesMatch \"\.(php|phtml|phar)$\">\nOrder allow,deny\nDeny from all\n</FilesMatch>");
            }

            $fileName = 'rma_' . bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
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

    try {
        $ticketCode = 'TCK-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("INSERT INTO customer_tickets (ticket_code, customer_id, order_id, subject, message, status) VALUES (:tcode, :cid, :oid, :subj, :msg, 'Open')");
        $stmt->execute([
            'tcode' => $ticketCode,
            'cid' => $customerId,
            'oid' => $orderId,
            'subj' => $subject,
            'msg' => $message
        ]);

        jsonResp(true, 'Support Ticket ' . $ticketCode . ' created. An advisor will reply shortly.');
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
            'store' => 'Dievon Atelier',
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
        $items = json_decode($order['items_json'] ?? '[]', true) ?: [];
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

jsonResp(false, 'Invalid customer action.');
