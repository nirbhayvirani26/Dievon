<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// Check admin login
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Role check BEFORE any output. The nav hides links this account cannot use,
// but the URL is still typeable and a handler still accepts a POST — so
// permission is decided here, on the server, every time.
//
// This ran AFTER includes/header.php had already printed the page chrome, so a
// role without returns.manage got HTTP 200, a half-rendered admin page, and a
// "headers already sent" PHP warning instead of a clean redirect — the refusal
// could not send its own Location header once bytes were on the wire.
requireAdminCapability('returns.manage');

$activeTab = 'returns';
$pageTitle = "RMA Returns & Exchanges";
require_once __DIR__ . '/includes/header.php';


// Handle status updates
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    // Token first — this was the only admin write on the site with no CSRF
    // check left, so another page could move a customer's return through to
    // "Completed" using the owner's own browser.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Security token expired. Please refresh and try again.';
        $returnId = 0;
        $newStatus = null;
    } else {

    $returnId = (int)$_POST['return_id'];

    // Only the six values the dropdown actually offers.
    //
    // $_POST['status'] went straight into the UPDATE, so any string at all
    // became a status — a typo, or something deliberately chosen to break the
    // status filters and badge styling further down this page. A return stuck
    // on an invented status cannot be found or progressed.
    $allowedStatuses = ['Pending', 'Approved', 'Rejected', 'Pickup Scheduled', 'Quality Check', 'Completed'];
    $newStatus = in_array($_POST['status'] ?? '', $allowedStatuses, true) ? $_POST['status'] : null;
    if ($newStatus === null) {
        $msg = 'That is not a valid return status.';
        $returnId = 0;
    }

    try {
        if ($returnId > 0 && $newStatus !== null) {
        /* Courier and AWB for the parcel coming BACK.
           ────────────────────────────────────────────────────────────────────
           There was nowhere to record the inbound shipment at all. "Pickup
           Scheduled" was a label and nothing more — it booked no courier, and the
           number the courier gave you had no home, so once a return was on its
           way the only record of where it had got to lived in Shiprocket. Blank
           is left blank; this is a note of what you arranged, not a requirement. */
        $retCarrier = trim((string)($_POST['return_carrier'] ?? ''));
        $retAwb     = trim((string)($_POST['return_awb'] ?? ''));

        /* The STATUS is the only column this screen must be able to write.
           ────────────────────────────────────────────────────────────────────
           return_carrier / return_awb were added to this handler, to the form
           below, to pages/account.php and to EmailService — but nothing ever
           creates them. config/db.php builds customer_returns without them
           (config/db.php:367) and no migration adds them, so on any install
           whose table came from db.php rather than from a production dump, this
           single UPDATE threw "Unknown column 'return_carrier'".

           The catch below then turned that into "Error updating RMA status."
           and the whole returns workflow was dead: no RMA could be approved,
           progressed or completed — which means a returned garment never came
           back into stock, no refund was ever triggered, and the order sat on
           'Return Requested' for ever. The customer could not even re-file,
           because actions/customer_action.php refuses a second request while
           one exists.

           An optional note about the inbound courier must never be able to
           block the state machine, so the columns are written only where they
           exist. The real fix is the missing migration — see the report — but
           this screen should degrade rather than die either way. */
        static $rmaShipCols = null;
        if ($rmaShipCols === null) {
            $rmaShipCols = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM customer_returns")->fetchAll(PDO::FETCH_COLUMN) as $c) {
                    if (in_array($c, ['return_carrier', 'return_awb'], true)) { $rmaShipCols[] = $c; }
                }
            } catch (PDOException $e) { $rmaShipCols = []; }
        }

        $sets   = ['status = :st'];
        $params = ['st' => $newStatus, 'id' => $returnId];
        if (in_array('return_carrier', $rmaShipCols, true)) {
            $sets[] = 'return_carrier = :rc';
            $params['rc'] = $retCarrier !== '' ? mb_substr($retCarrier, 0, 100) : null;
        }
        if (in_array('return_awb', $rmaShipCols, true)) {
            $sets[] = 'return_awb = :ra';
            $params['ra'] = $retAwb !== '' ? mb_substr($retAwb, 0, 100) : null;
        }

        $stmt = $pdo->prepare("UPDATE customer_returns SET " . implode(', ', $sets) . " WHERE id = :id");
        $stmt->execute($params);
        $msg = "RMA Status updated to " . htmlspecialchars($newStatus) . " successfully.";
        if (($retCarrier !== '' || $retAwb !== '') && count($rmaShipCols) < 2) {
            // Said out loud rather than dropped: the owner typed a courier and an
            // AWB and this install cannot store them.
            $msg .= " (Return courier/AWB could not be saved — this database is missing the"
                  . " customer_returns.return_carrier / return_awb columns.)";
        }

        // This used to be the whole handler: one column, on one table.
        // Two consequences, both fixed below.
        $rStmt = $pdo->prepare(
            "SELECT r.*, c.name AS customer_name, c.email AS customer_email,
                    o.id AS oid, o.order_code, o.status AS order_status,
                    o.refunded_amount, o.stock_deducted, o.items_json
               FROM customer_returns r
               JOIN customers c ON r.customer_id = c.id
               JOIN orders    o ON r.order_id    = o.id
              WHERE r.id = :id"
        );
        $rStmt->execute(['id' => $returnId]);
        $rma = $rStmt->fetch(PDO::FETCH_ASSOC);   // false, not null, when missing

        if ($rma) {
            // 1. The customer heard nothing at all before — they posted a garment
            //    back and had no confirmation until (and unless) a refund appeared.
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                getEmailService()->sendReturnStatusEmail($rma, $newStatus);
            } catch (\Throwable $e) {
                error_log('RMA status email failed for return ' . $returnId . ': ' . $e->getMessage());
            }

            // 2. Only customer_returns was touched, so the ORDER stayed on
            //    "Return Requested" forever — which also locked the customer out
            //    of ever opening another return on it, because the eligibility
            //    check in actions/customer_action.php requires Delivered/Completed.
            $syncTo = null;

            // An EXCHANGE is not a return, and must not be recorded as one.
            //
            // Completing any RMA set the order to 'Returned', exchanges included.
            // But an exchange means the customer kept a garment and the sale
            // stands — and admin/gst_report.php excludes 'Returned' orders, so
            // every completed exchange silently deleted a real, taxable sale from
            // the GST report. It also slammed the return window shut, because the
            // eligibility check requires Delivered/Completed.
            $isReturn = strtolower(trim((string)($rma['request_type'] ?? 'return'))) === 'return';

            switch (strtolower(trim($newStatus))) {
                case 'completed':
                    if ($isReturn) {
                        $syncTo = 'Returned';
                        break;
                    }
                    /* A completed EXCHANGE has to be put BACK to Delivered.
                       ────────────────────────────────────────────────────────
                       The comment on the old line said "exchange stays
                       Delivered", but null means "do not sync", and the order
                       had not been left on Delivered at all: filing the request
                       moved it to 'Return Requested'
                       (actions/customer_action.php:376). Nothing then moved it
                       back, so a finished exchange sat on 'Return Requested'
                       for ever.

                       Measured on order DV-75417242: RMA-36936D completed, the
                       replacement arranged, and the order still reading
                       'Return Requested' — the customer's own order history
                       says a return is outstanding on an order that is closed,
                       and the eligibility check in customer_action.php only
                       accepts Delivered/Completed, so they can never file
                       another RMA on it. That is precisely the lock-out this
                       block's own comment above says it exists to prevent.

                       Same conservatism as the reject branch below: only an
                       order this request actually moved is moved back, and
                       never one whose money has already gone out. */
                    $exchangeRefunded = (float)($rma['refunded_amount'] ?? 0) > 0;
                    $syncTo = (!$exchangeRefunded && (string)$rma['order_status'] === 'Return Requested')
                        ? 'Delivered'
                        : null;
                    break;

                case 'rejected':
                case 'declined':
                    // Hand the order back to them — but never resurrect one whose
                    // money has already moved. Rejecting an RMA on a fully refunded
                    // order used to reset it to 'Delivered', which put the refunded
                    // amount straight back into the GST report as revenue the shop
                    // no longer holds. Only an untouched, still-pending return may
                    // be handed back.
                    $alreadyRefunded = (float)($rma['refunded_amount'] ?? 0) > 0;
                    $syncTo = (!$alreadyRefunded && (string)$rma['order_status'] === 'Return Requested')
                        ? 'Delivered'
                        : null;
                    break;
            }

            if ($syncTo !== null && (string)$rma['order_status'] !== $syncTo) {
                try {
                    $pdo->prepare("UPDATE orders SET status = :s WHERE id = :id")
                        ->execute(['s' => $syncTo, 'id' => (int)$rma['oid']]);
                    $msg .= " Order #{$rma['order_code']} set to {$syncTo}.";

                    // A returned garment comes BACK into stock.
                    //
                    // This screen changed the order's status and stopped there, so
                    // a completed return left the goods counted as sold for ever —
                    // the shop under-sold its own shelf, permanently, every time a
                    // customer returned something. The Orders screen has always
                    // restored on 'Returned'; this one simply never did.
                    // restoreOrderStock() is shared with that screen and is a no-op
                    // unless stock_deducted is set, so it cannot double-credit.
                    if ($syncTo === 'Returned') {
                        $restored = restoreOrderStock($pdo, [
                            'id'             => (int)$rma['oid'],
                            'items_json'     => $rma['items_json'],
                            'stock_deducted' => $rma['stock_deducted'],
                            // Both read by the stock ledger, so Stock History
                            // shows a return as a return and names the order.
                            'order_code'     => $rma['order_code'] ?? ('#' . (int)$rma['oid']),
                            '_restock_reason' => 'returned',
                        ]);
                        if ($restored) { $msg .= " Stock returned to inventory."; }
                    }
                } catch (PDOException $e) {
                    error_log('Order sync after RMA update failed: ' . $e->getMessage());
                }
            }
        }
        }   // end: valid id + allowed status
    } catch (PDOException $e) {
        /* Logged, not swallowed. This handler failing is how the entire returns
           workflow died silently for every install missing return_carrier /
           return_awb: the screen said "Error updating RMA status." and there was
           nothing anywhere naming the column. The message stays generic for the
           browser — it is an admin screen, but the DB error text is still not
           something to print — while the log now says exactly what broke. */
        error_log('RMA status update failed (return ' . (int)$returnId . ' -> '
            . (string)$newStatus . '): ' . $e->getMessage());
        $msg = "Error updating RMA status. The reason was written to the error log.";
    }

    }   // end: CSRF token accepted
}

// Fetch all returns
$returns = [];
try {
    // The money columns are joined in as well.
    //
    // Approving a return does NOT refund anything — the two systems never spoke
    // to each other, and there is no call to RefundService anywhere in this file.
    // So a return could sit here marked Approved for weeks with the customer
    // still waiting, and nothing on this screen said whether the money had gone
    // back. Carrying total_price and refunded_amount lets each row answer that.
    // LEFT JOIN, not JOIN.
    //
    // Both joins were inner, so a return whose order or customer row had gone —
    // deleted, or never matching in the first place — was dropped from this
    // query entirely. Measured on this database: 1 return request existed and
    // 0 were shown, because it pointed at an order id and a customer id that do
    // not exist. The request had simply vanished, with the customer waiting and
    // nothing on any screen saying a return had ever been asked for.
    //
    // A return that cannot be joined is exactly the one somebody needs to look
    // at, so it is now listed with its missing pieces marked rather than hidden.
    $returns = $pdo->query(
        "SELECT r.*, c.name as customer_name, c.email as customer_email,
                o.order_code, o.total_price, o.refunded_amount, o.payment_method
           FROM customer_returns r
           LEFT JOIN customers c ON r.customer_id = c.id
           LEFT JOIN orders o ON r.order_id = o.id
          ORDER BY r.id DESC"
    )->fetchAll();
} catch (PDOException $e) {}
?>

<?php /* This page used to print its own title block here, and it was the only
         admin page that did so WITHOUT setting $hideHeaderTitle — so the shared
         header printed "RMA Returns & Exchanges" and then this printed "RMA
         Returns & Size Exchanges" directly underneath. Two titles, saying the
         same thing, on every visit.

         It was also the only page using class="admin-header", which carries no
         styling at all — products, customers, banners and email_logs all use
         admin-page-header. That is why this screen looked plainer than the rest
         of the panel: its heading was unstyled text, not a heading block.

         Deleted rather than restyled. admin/includes/header.php already has a
         'returns' entry with both the title and the subtitle (line 618), so the
         page now gets the same header every other screen gets, from the one
         place that defines them. Nothing here to drift. */ ?>

<?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom: 20px">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<div class="card" style="background: var(--bg-surface); padding: 25px; border-radius: 6px; border: 1px solid var(--border-light);">
    <?php if (empty($returns)): ?>
        <p style="color: var(--text-muted);">No customer RMA return requests submitted yet.</p>
    <?php else: ?>
        <?php /* Column sizing for this table.
                 ────────────────────────────────────────────────────────────────
                 Ten columns were declared with no widths at all, so the browser
                 shared the space out by content. Nine of them hold short fixed
                 values — RETURN, XL, None, Pending — and one holds free text the
                 customer typed. The short ones took what they wanted and Reason
                 was left with what remained, wrapping to four or five lines and
                 making every row that tall. The identifiers broke mid-word too:
                 an RMA code split across two lines is not a code any more.

                 Fixed by telling the short columns not to wrap, so they collapse
                 to the width they actually need, and giving Reason a floor. The
                 remaining space then falls to Reason, which is the only column
                 that can use it.

                 .table-responsive was on this table already but was styled
                 NOWHERE, in any stylesheet — so it did nothing, and the table was
                 simply clipped by the card instead of scrolling. It scrolls now. */ ?>
        <style>
            .rma-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .rma-table { min-width: 1060px; }
            /* Identifiers, short labels and controls: never wrap. */
            .rma-table th:nth-child(1), .rma-table td:nth-child(1),   /* RMA code   */
            .rma-table th:nth-child(2), .rma-table td:nth-child(2),   /* order code */
            .rma-table th:nth-child(4), .rma-table td:nth-child(4),   /* type       */
            .rma-table th:nth-child(6), .rma-table td:nth-child(6),   /* exch. size */
            .rma-table th:nth-child(7), .rma-table td:nth-child(7),   /* photo      */
            .rma-table th:nth-child(8), .rma-table td:nth-child(8),   /* status     */
            .rma-table th:nth-child(10), .rma-table td:nth-child(10)  /* action     */
                { white-space: nowrap; }
            /* The one column with real prose in it gets the leftover width. */
            .rma-table th:nth-child(5), .rma-table td:nth-child(5) { min-width: 210px; }
            .rma-table th:nth-child(3), .rma-table td:nth-child(3) { min-width: 170px; }
            .rma-table th:nth-child(9), .rma-table td:nth-child(9) { min-width: 130px; }
            .rma-table tbody tr:hover { background: var(--bg-main); }
        </style>
        <div class="table-responsive rma-table-wrap">
            <table class="table rma-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-strong); text-align: left;">
                        <th style="padding: 10px;">RMA Code</th>
                        <th style="padding: 10px;">Order Code</th>
                        <th style="padding: 10px;">Customer</th>
                        <th style="padding: 10px;">Type</th>
                        <th style="padding: 10px;">Reason</th>
                        <th style="padding: 10px;">Exch. Size</th>
                        <th style="padding: 10px;">Photo</th>
                        <th style="padding: 10px;">Status</th>
                        <th style="padding: 10px;">Refund</th>
                        <th style="padding: 10px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($returns as $r): ?>
                        <tr style="border-bottom: 1px solid var(--border-light);">
                            <td style="padding: 12px 10px; font-weight: 700; color: var(--color-primary);"><?= htmlspecialchars($r['return_code']) ?></td>
                            <?php // Null-safe now the joins are LEFT: a missing order or customer
                                  // is stated on the row instead of removing the whole request. ?>
                            <td style="padding: 12px 10px;">
                                <?php if (!empty($r['order_code'])): ?>
                                    <?= htmlspecialchars($r['order_code']) ?>
                                <?php else: ?>
                                    <span style="color: var(--color-danger); font-size: 11px; font-weight: 700;">
                                        Order #<?= (int)$r['order_id'] ?> missing
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 10px;">
                                <?php if (!empty($r['customer_name']) || !empty($r['customer_email'])): ?>
                                    <strong><?= htmlspecialchars((string)$r['customer_name']) ?></strong><br>
                                    <span style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars((string)$r['customer_email']) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--color-danger); font-size: 11px; font-weight: 700;">
                                        Customer #<?= (int)$r['customer_id'] ?> missing
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 10px; font-weight: 600;"><?= strtoupper(htmlspecialchars($r['request_type'])) ?></td>
                            <?php /* Customer-typed text, so it needs a wrapping rule — the same
                                     flaw that stretched the support-tickets table off the screen.
                                     The .rma-table sizing already gives this column a minimum
                                     width; this stops a long unbroken run from taking the rest. */ ?>
                            <td class="rma-reason-cell"><?= htmlspecialchars($r['reason']) ?></td>
                            <td style="padding: 12px 10px;"><?= htmlspecialchars($r['exchange_size'] ?: 'N/A') ?></td>
                            <td style="padding: 12px 10px;">
                                <?php if (!empty($r['photo_path'])): ?>
                                    <a href="<?= SITE_URL ?>/uploads/returns/<?= htmlspecialchars($r['photo_path']) ?>" target="_blank" style="color: var(--color-primary); font-weight: 700; text-decoration: underline;">View Photo 📷</a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 10px;">
                                <span style="font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 3px; background: #fff8e1; color: #b7791f;">
                                    <?= htmlspecialchars($r['status']) ?>
                                </span>
                            </td>
                            <?php
                            // Whether the money has actually gone back, per return.
                            //
                            // Approving a return does not refund — that is still a separate,
                            // deliberate act on the Orders screen. What was missing was any
                            // way to SEE which approved returns were still owed money, so a
                            // customer could be waiting with nothing on screen saying so.
                            $rPaid     = (float)($r['total_price'] ?? 0);
                            $rRefunded = (float)($r['refunded_amount'] ?? 0);
                            $rOwed     = max(0.0, round($rPaid - $rRefunded, 2));
                            // Only returns heading towards a refund are chased. A rejected
                            // return owes nothing, and neither does one still being decided.
                            //
                            // An EXCHANGE owes nothing either — the customer swaps a size
                            // and no money changes hands. This chased them anyway, showing
                            // "Not refunded" in red with a "Refund ₹X" link, so the owner
                            // was invited to pay out on an order that was never refundable.
                            $rIsReturn   = strtolower(trim((string)($r['request_type'] ?? 'return'))) === 'return';
                            $rNeedsMoney = $rIsReturn
                                && in_array((string)$r['status'], ['Approved', 'Quality Check', 'Completed'], true);
                            ?>
                            <td style="padding: 12px 10px; white-space: nowrap;">
                                <?php if ($rRefunded > 0 && $rOwed <= 0): ?>
                                    <span style="font-size:11px; font-weight:700; color: var(--color-success);">
                                        <i class="fa-solid fa-check"></i> <?= formatPrice($rRefunded) ?> refunded
                                    </span>
                                <?php elseif ($rRefunded > 0): ?>
                                    <span style="font-size:11px; font-weight:700; color: var(--color-secondary);">
                                        <?= formatPrice($rRefunded) ?> of <?= formatPrice($rPaid) ?>
                                    </span><br>
                                    <a href="orders.php#order-<?= (int)$r['order_id'] ?>" style="font-size:10.5px;"><?= formatPrice($rOwed) ?> still owed &rarr;</a>
                                <?php elseif ($rNeedsMoney): ?>
                                    <span style="font-size:11px; font-weight:700; color: var(--color-danger);">
                                        <i class="fa-solid fa-triangle-exclamation"></i> Not refunded
                                    </span><br>
                                    <a href="orders.php#order-<?= (int)$r['order_id'] ?>" style="font-size:10.5px;">Refund <?= formatPrice($rOwed) ?> &rarr;</a>
                                <?php else: ?>
                                    <span style="font-size:11px; color: var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 10px;">
                                <form action="returns.php" method="POST" style="display: flex; gap: 6px;">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
                                    <select name="status" style="font-size: 11px; padding: 4px;">
                                        <option value="Pending" <?= $r['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Approved" <?= $r['status'] === 'Approved' ? 'selected' : '' ?>>Approved</option>
                                        <option value="Rejected" <?= $r['status'] === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                                        <option value="Pickup Scheduled" <?= $r['status'] === 'Pickup Scheduled' ? 'selected' : '' ?>>Pickup Scheduled</option>
                                        <option value="Quality Check" <?= $r['status'] === 'Quality Check' ? 'selected' : '' ?>>Quality Check</option>
                                        <option value="Completed" <?= $r['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                    </select>
                                    <?php /* The parcel coming back. Free text and optional — you copy in
                                             whatever Shiprocket gave you when you booked the reverse
                                             pickup, so the inbound leg is visible here instead of only in
                                             their panel. Sent with the status so one Save records both. */ ?>
                                    <input type="text" name="return_carrier" class="rma-ship-in"
                                           value="<?= htmlspecialchars((string)($r['return_carrier'] ?? '')) ?>"
                                           placeholder="Courier" title="Courier bringing it back">
                                    <input type="text" name="return_awb" class="rma-ship-in"
                                           value="<?= htmlspecialchars((string)($r['return_awb'] ?? '')) ?>"
                                           placeholder="Return AWB" title="Tracking number for the return leg">
                                    <button type="submit" name="update_status" class="btn btn-sm btn-primary" style="font-size: 10px; padding: 4px 8px;">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
