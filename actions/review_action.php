<?php
// ============================================================
//  Dievon – Customer Product Review Submission Handler
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $author    = trim($_POST['author_name'] ?? $_SESSION['customer_name'] ?? 'Verified Customer');
    $rating    = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $title     = trim($_POST['title'] ?? 'Exquisite quality');
    $review    = trim($_POST['review_text'] ?? $_POST['content'] ?? '');

    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product selected.']);
        exit;
    }

    if (empty($review)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your review text.']);
        exit;
    }

    try {
        // Ensure product_reviews table exists (fresh installs) and has the columns
        // this app actually uses (older installs may have a legacy schema with
        // `user_id`/`reviewer_name` instead of `customer_id`/`author_name`, and no
        // `title`/`status` columns at all — auto-migrate those in either case).
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_reviews` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `customer_id` INT DEFAULT NULL,
            `author_name` VARCHAR(150) NOT NULL DEFAULT '',
            `rating` INT DEFAULT 5,
            `title` VARCHAR(255) DEFAULT NULL,
            `review_text` TEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $cols = $pdo->query("SHOW COLUMNS FROM product_reviews")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('customer_id', $cols)) $pdo->exec("ALTER TABLE product_reviews ADD COLUMN customer_id INT DEFAULT NULL");
        if (!in_array('author_name', $cols)) $pdo->exec("ALTER TABLE product_reviews ADD COLUMN author_name VARCHAR(150) NOT NULL DEFAULT ''");
        if (!in_array('title', $cols))       $pdo->exec("ALTER TABLE product_reviews ADD COLUMN title VARCHAR(255) DEFAULT NULL");
        if (!in_array('status', $cols))      $pdo->exec("ALTER TABLE product_reviews ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
        // Legacy installs have a NOT NULL `reviewer_name` column with no default,
        // which blocks inserts that only populate `author_name`. Relax it.
        if (in_array('reviewer_name', $cols)) {
            try { $pdo->exec("ALTER TABLE product_reviews MODIFY reviewer_name VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
        }

        $customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;

        $stmt = $pdo->prepare("INSERT INTO product_reviews (product_id, customer_id, author_name, rating, title, review_text, status) VALUES (:pid, :cid, :author, :rating, :title, :text, 'Pending')");
        $stmt->execute([
            'pid'     => $productId,
            'cid'     => $customerId,
            'author'  => $author,
            'rating'  => $rating,
            'title'   => $title,
            'text'    => $review
        ]);

        echo json_encode([
            'success' => true,
            'message' => '🎉 Thank you for your review! It will appear on the product page once approved.'
        ]);
        exit;
    } catch (PDOException $e) {
        error_log('Review submission error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Sorry, we could not record your review. Please try again.']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
