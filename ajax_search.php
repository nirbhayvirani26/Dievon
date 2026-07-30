<?php
ob_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

try {
    // Search across name, description, category, brand, color, fabric, tags, and SKU using unique parameter placeholders
    $stmt = $pdo->prepare("
        SELECT id, name, price, image, emoji, category 
        FROM products 
        WHERE available = 1 
          AND (name LIKE :q1 
               OR description LIKE :q2 
               OR category LIKE :q3 
               OR brand LIKE :q4 
               OR color LIKE :q5
               OR fabric LIKE :q6
               OR tags LIKE :q7
               OR sku LIKE :q8)
        ORDER BY 
            CASE 
                WHEN LOWER(name) = LOWER(:q_raw) THEN 1
                WHEN name LIKE :q_start1 THEN 2
                WHEN description LIKE :q_start2 THEN 3
                ELSE 4
            END, id DESC
        LIMIT 8
    ");
    
    $likeQuery  = "%" . $query . "%";
    $startQuery = $query . "%";
    
    $stmt->execute([
        'q1'       => $likeQuery,
        'q2'       => $likeQuery,
        'q3'       => $likeQuery,
        'q4'       => $likeQuery,
        'q5'       => $likeQuery,
        'q6'       => $likeQuery,
        'q7'       => $likeQuery,
        'q8'       => $likeQuery,
        'q_raw'    => $query,
        'q_start1' => $startQuery,
        'q_start2' => $startQuery
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format image URLs and multi-currency formatted price
    foreach ($results as &$r) {
        $r['formatted_price'] = formatPrice($r['price']);
        $imgFile = $r['image'] ?? '';
        if (!empty($imgFile) && file_exists(__DIR__ . '/uploads/products/' . $imgFile)) {
            $r['image_url'] = 'uploads/products/' . $imgFile;
        } elseif (!empty($imgFile) && file_exists(__DIR__ . '/uploads/gallery/' . $imgFile)) {
            $r['image_url'] = 'uploads/gallery/' . $imgFile;
        } elseif (!empty($imgFile)) {
            $r['image_url'] = 'uploads/products/' . $imgFile;
        } else {
            $r['image_url'] = '';
        }
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database search error', 'details' => $e->getMessage()]);
}

