<?php
// ============================================================
//  Dievon – Report a Product Review
//
//  Open to signed-out visitors on purpose: the person best placed to spot a
//  defamatory, fake or off-topic review is often not a customer. That makes
//  rate limiting the control that matters here, not authentication.
//
//  A report never edits or hides the review. It records a flag and bumps a
//  counter so an admin can look; letting a report hide a review outright would
//  hand any competitor a delete button.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

function reportFail(string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reportFail('Invalid request method.', 405);
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    reportFail('Your session has expired. Please refresh the page and try again.', 419);
}

$reviewId = (int)($_POST['review_id'] ?? 0);
$reason   = trim(strip_tags((string)($_POST['reason'] ?? '')));
$details  = trim(strip_tags((string)($_POST['details'] ?? '')));

if ($reviewId <= 0) { reportFail('Invalid review.'); }

// A fixed list, so the reason column cannot become a second free-text field
// that has to be sanitised and moderated in its own right.
$allowedReasons = ['Offensive or abusive', 'Not about this product', 'Spam or advertising', 'Fake or misleading', 'Other'];
if (!in_array($reason, $allowedReasons, true)) { $reason = 'Other'; }
if (mb_strlen($details) > 500) { $details = mb_substr($details, 0, 500); }

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    // The review has to exist. Without this check the table would happily
    // collect reports against invented ids.
    $exists = $pdo->prepare("SELECT id FROM product_reviews WHERE id = :id");
    $exists->execute([':id' => $reviewId]);
    if (!$exists->fetchColumn()) { reportFail('That review no longer exists.'); }

    // Rate limit: one report per review per IP per day. Stops a single person
    // burying a genuine bad review by reporting it fifty times.
    $recent = $pdo->prepare(
        "SELECT COUNT(*) FROM review_reports
          WHERE review_id = :rid AND ip_address = :ip
            AND created_at > (NOW() - INTERVAL 1 DAY)"
    );
    $recent->execute([':rid' => $reviewId, ':ip' => $ip]);
    if ((int)$recent->fetchColumn() > 0) {
        // Reported as success: telling someone their duplicate was rejected
        // just invites them to try again from another address.
        echo json_encode(['success' => true, 'message' => 'Thank you — this review has been flagged for us to look at.']);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO review_reports (review_id, customer_id, reason, details, ip_address)
         VALUES (:rid, :cid, :reason, :details, :ip)"
    )->execute([
        ':rid'     => $reviewId,
        ':cid'     => isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null,
        ':reason'  => $reason,
        ':details' => $details !== '' ? $details : null,
        ':ip'      => $ip,
    ]);

    // Denormalised counter so the admin list can sort by it without a join.
    $pdo->prepare("UPDATE product_reviews SET reported_count = reported_count + 1 WHERE id = :id")
        ->execute([':id' => $reviewId]);
    $pdo->commit();

    /* Tell the shop, because nothing else will.
       ────────────────────────────────────────────────────────────────────────
       The shopper has just been told the review is "flagged for us to look at",
       and until now nobody was told to look. This is worse than the pending-review
       queue beside it: a reported review is ALREADY on the product page, so
       whatever somebody found objectionable stays in front of every other
       shopper until a human notices a counter on an admin screen.

       Reporting deliberately does not hide the review — one press should not let
       anyone remove a genuine review — which is exactly why the notification has
       to exist: hiding it is a judgement only the shop can make, and it cannot
       make it without knowing.

       After the commit, in its own try/catch: the report is recorded, and a mail
       failure must not tell the shopper their report failed when it did not. */
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $ctx = $pdo->prepare(
            "SELECT r.author_name, r.review_text, r.reported_count, p.name AS product_name
               FROM product_reviews r
               LEFT JOIN products p ON p.id = r.product_id
              WHERE r.id = :id"
        );
        $ctx->execute([':id' => $reviewId]);
        $row = $ctx->fetch(PDO::FETCH_ASSOC) ?: [];

        getEmailService()->sendReviewReportedAdminEmail([
            'product_name'   => $row['product_name']   ?? '',
            'author_name'    => $row['author_name']    ?? '',
            'review_text'    => $row['review_text']    ?? '',
            'reported_count' => $row['reported_count'] ?? 1,
            'reason'         => $reason,
            'details'        => $details,
        ]);
    } catch (\Throwable $e) {
        error_log('Review report email failed for review ' . $reviewId . ': ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Thank you — this review has been flagged for us to look at.']);
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Review report error: ' . $e->getMessage());
    reportFail('Sorry, we could not record that. Please try again.');
}
