<?php
// ============================================================
//  Dievon – Newsletter Subscribe Handler
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `email` VARCHAR(191) NOT NULL UNIQUE,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $pdo->prepare("INSERT IGNORE INTO newsletter_subscribers (email) VALUES (:email)");
    $stmt->execute(['email' => $email]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => true, 'message' => "You're already subscribed — thank you!"]);
    } else {
        echo json_encode(['success' => true, 'message' => 'Thank you for subscribing to the Dievon newsletter.']);
    }
} catch (PDOException $e) {
    error_log('Newsletter subscribe error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Sorry, something went wrong. Please try again.']);
}
