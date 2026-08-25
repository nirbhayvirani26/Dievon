<?php
// ============================================================
//  Dievon – Customer Product Review Submission Handler
//
//  Previously this endpoint accepted a review from anyone who could POST to
//  it, with no CSRF token, and defaulted the author name to the literal string
//  "Verified Customer" and the title to "Exquisite quality". An unauthenticated
//  stranger could therefore file glowing praise attributed to a verified buyer,
//  in words the shop had written for them.
//
//  A review now requires: a logged-in customer, a valid CSRF token, and a real
//  order containing the product. It is stored Pending and only a human can
//  publish it.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

function reviewFail(string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reviewFail('Invalid request method.', 405);
}

// ── 1. Must be signed in ────────────────────────────────────
// Anonymous reviews cannot be tied to a purchase, cannot be limited to one per
// product, and cannot be appealed. Requiring an account is what makes every
// other control below possible.
$customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;
if ($customerId <= 0) {
    reviewFail('Please sign in to write a review.', 401);
}

// ── 2. CSRF ─────────────────────────────────────────────────
// Without this, any page on the internet could post a review as a signed-in
// Dievon customer while they browsed it.
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    reviewFail('Your session has expired. Please refresh the page and try again.', 419);
}

$productId = (int)($_POST['product_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);

// Strip tags rather than trusting output escaping alone. The product page does
// escape on render, but a review body also reaches admin screens and emails,
// and not every one of those is guaranteed to escape.
$title  = trim(strip_tags((string)($_POST['title'] ?? '')));
$review = trim(strip_tags((string)($_POST['review_text'] ?? $_POST['content'] ?? '')));

if ($productId <= 0)                 { reviewFail('Invalid product selected.'); }
if ($rating < 1 || $rating > 5)      { reviewFail('Please choose a rating between 1 and 5 stars.'); }
if ($review === '')                  { reviewFail('Please enter your review.'); }
if (mb_strlen($review) < 10)         { reviewFail('Please write a little more — at least 10 characters.'); }
if (mb_strlen($review) > 4000)       { reviewFail('Please keep your review under 4000 characters.'); }
if (mb_strlen($title) > 150)         { reviewFail('Please keep your title under 150 characters.'); }

// No invented title. An empty one renders as no title, rather than the shop
// putting "Exquisite quality" in a customer's mouth.
if ($title === '') { $title = null; }

try {
    // ── 3. The reviewer must actually own this product ──────
    // Scans the stored items_json of this customer's non-cancelled orders. The
    // order_items table is not populated in this codebase, so items_json is the
    // only record of what was bought.
    $orderStmt = $pdo->prepare(
        "SELECT id, items_json, status FROM orders
          WHERE customer_id = :cid
            AND (is_deleted = 0 OR is_deleted IS NULL)
            AND status NOT IN ('Cancelled', 'Cancellation Requested')
          ORDER BY id DESC"
    );
    $orderStmt->execute([':cid' => $customerId]);

    $matchedOrderId = null;
    foreach ($orderStmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        foreach (json_decode((string)$order['items_json'], true) ?: [] as $item) {
            if ((int)($item['product_id'] ?? 0) === $productId) {
                $matchedOrderId = (int)$order['id'];
                break 2;
            }
        }
    }

    if ($matchedOrderId === null) {
        reviewFail('You can review a piece once you have ordered it.', 403);
    }

    // ── 4. One review per customer per product ──────────────
    // Checked here for a friendly message; the UNIQUE index on
    // (customer_id, product_id) is what actually guarantees it, because two
    // simultaneous submissions would both pass this check.
    $dupe = $pdo->prepare("SELECT id FROM product_reviews WHERE customer_id = :cid AND product_id = :pid");
    $dupe->execute([':cid' => $customerId, ':pid' => $productId]);
    if ($dupe->fetchColumn()) {
        reviewFail('You have already reviewed this piece. Contact us if you would like to change it.');
    }

    // Author name comes from the account, never from the request body.
    $nameStmt = $pdo->prepare("SELECT name FROM customers WHERE id = :id");
    $nameStmt->execute([':id' => $customerId]);
    $author = trim((string)$nameStmt->fetchColumn());
    if ($author === '') { $author = 'Dievon Customer'; }

    // ── 5. Stored Pending — a human publishes it ────────────
    $stmt = $pdo->prepare(
        "INSERT INTO product_reviews
            (product_id, customer_id, order_id, author_name, rating, title, review_text, is_verified_purchase, status)
         VALUES (:pid, :cid, :oid, :author, :rating, :title, :text, 1, 'Pending')"
    );
    $stmt->execute([
        ':pid'    => $productId,
        ':cid'    => $customerId,
        ':oid'    => $matchedOrderId,
        ':author' => $author,
        ':rating' => $rating,
        ':title'  => $title,
        ':text'   => $review,
    ]);

    /* Tell the shop it is waiting.
       ────────────────────────────────────────────────────────────────────────
       The customer has just been promised their words will appear "once
       approved", and nothing told the person who does the approving. The queue
       was only ever seen by somebody happening to open the reviews screen, so a
       review could sit unpublished indefinitely against that promise.

       After the save and in its own try/catch: the review is already stored, so
       a mail failure must not turn a successful submission into an error. */
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $pName = '';
        try {
            $pn = $pdo->prepare("SELECT name FROM products WHERE id = :id");
            $pn->execute(['id' => $productId]);
            $pName = (string)$pn->fetchColumn();
        } catch (\Throwable $e) { /* the name is a courtesy; the id is in the subject */ }

        getEmailService()->sendReviewPendingAdminEmail([
            'product_id'   => $productId,
            'product_name' => $pName,
            'author_name'  => $author,
            'rating'       => $rating,
            'title'        => $title,
            'review_text'  => $review,
        ]);
    } catch (\Throwable $e) {
        error_log('Review pending email failed for product ' . $productId . ': ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Thank you for your review. It will appear on the product page once approved.',
    ]);
    exit;

} catch (PDOException $e) {
    // The unique index firing means a duplicate slipped past the check above,
    // which is exactly the race it exists to catch. Report it as the same
    // friendly message rather than a database error.
    if ($e->getCode() === '23000') {
        reviewFail('You have already reviewed this piece.');
    }
    error_log('Review submission error: ' . $e->getMessage());
    reviewFail('Sorry, we could not record your review. Please try again.');
}
