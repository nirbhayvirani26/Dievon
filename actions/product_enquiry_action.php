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
try {
    $ins = $pdo->prepare("INSERT INTO inquiries (name, email, phone, message, created_at) VALUES (:name, :email, :phone, :msg, NOW())");
    $ins->execute([
        'name'    => $name . " [Product: " . $productName . "]",
        'email'   => $email,
        'phone'   => $phone,
        'msg'     => "Product: " . $productName . "\nEnquiry: " . $message
    ]);
} catch (PDOException $e) {
    error_log("Product enquiry DB save error: " . $e->getMessage());
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

// An email failure must NOT stop the enquiry from being confirmed to the user
echo json_encode([
    'success' => true,
    'message' => 'Thank you for your enquiry regarding ' . htmlspecialchars($productName) . '. Our styling team will respond shortly!'
]);
exit;
