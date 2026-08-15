<?php
// ============================================================
//  Dievon – Product Enquiry Action Handler
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/EmailService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$productId   = (int)($_POST['product_id'] ?? 0);
$productName = trim($_POST['product_name'] ?? '');
$name        = trim($_POST['name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$phone       = trim($_POST['phone'] ?? '');
$message     = trim($_POST['message'] ?? '');

if (!$productId || !$productName || !$name || !$email || !$message) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields (Name, Email, Message).']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
    exit;
}

// 1. Save to Database
//
// A failed INSERT is told to the CUSTOMER, not just the error log.
//
// This swallowed the exception and carried on to answer "we have received your
// enquiry". The commonest cause is ordinary: the name column is limited, and the
// product name is appended to it, so a long name or a long product title
// overflows and MySQL rejects the row. The shopper was thanked, the enquiry
// existed nowhere, and nothing on any screen showed it had been lost. Better to
// say plainly that it did not send, so they can try again or phone.
$enquirySaved = false;
try {
    $ins = $pdo->prepare("INSERT INTO inquiries (name, email, phone, message, created_at) VALUES (:name, :email, :phone, :msg, NOW())");
    $enquirySaved = $ins->execute([
        // Trimmed to fit the column rather than allowed to overflow it.
        'name'    => mb_substr($name . " [Product: " . $productName . "]", 0, 120),
        'email'   => mb_substr($email, 0, 180),
        'phone'   => mb_substr($phone, 0, 30),
        'msg'     => "Product: " . $productName . "\nEnquiry: " . $message
    ]);
} catch (PDOException $e) {
    error_log("Product enquiry DB save error: " . $e->getMessage());
    $enquirySaved = false;
}

// 2. Dispatch Emails (Admin Notification + Customer Acknowledgement)
$productUrl = SITE_URL . "/pages/product.php?id=" . $productId;
$emailSent = false;

try {
    $emailService = new EmailService($pdo);
    $emailSent = $emailService->sendProductEnquiryEmails($name, $email, $phone, $productName, $productUrl, $message);
} catch (\Throwable $e) {
    error_log("Product enquiry email dispatch error: " . $e->getMessage());
}

// An email failure must NOT stop the enquiry from being confirmed — the record
// is in the database and the shop will see it. But if the RECORD failed too,
// then nothing anywhere holds this enquiry and saying "we received it" is simply
// untrue. Confirm only when at least one of the two actually worked.
if (!$enquirySaved && !$emailSent) {
    echo json_encode([
        'success' => false,
        'message' => 'Sorry — we could not send your enquiry just now. Please try again, '
                   . 'or email us directly at ' . htmlspecialchars(storeSetting($pdo, 'contact_email', 'info@dievon.com')) . '.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Thank you for your enquiry regarding ' . htmlspecialchars($productName) . '. Our styling team will respond shortly!'
]);
exit;
