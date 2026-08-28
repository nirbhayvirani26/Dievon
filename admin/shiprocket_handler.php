<?php
/**
 * Dievon – Book one order with Shiprocket.
 * ============================================================================
 * POST only, owner-triggered, one order at a time. There is deliberately no
 * "book everything" action: each booking creates a real courier job that bills
 * the shop, and a loop over the order list is the kind of button somebody
 * presses once by accident and pays for forty times.
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
requireAdminCapability('orders.manage');
require_once __DIR__ . '/../services/ShiprocketService.php';

/* Back to the Orders screen with a message, rather than a JSON body.
   The button is a plain form so it can use dvConfirmForm(), the confirm the
   rest of admin already uses — which meant no new JavaScript, and no second
   way of asking "are you sure" that could drift from the first. */
function srDone(string $msg, bool $ok): void {
    $_SESSION['sr_flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: orders.php');
    exit;
}
function srFail(string $msg, int $code = 400): void { srDone($msg, false); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST')            { srFail('POST only', 405); }
if (!verifyCsrfToken($_POST['csrf_token'] ?? ''))     { srFail('Security check failed. Reload the page and try again.'); }

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) { srFail('No order given.'); }

$st = $pdo->prepare("SELECT * FROM orders WHERE id = :id AND COALESCE(is_deleted,0) = 0");
$st->execute([':id' => $orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) { srFail('That order no longer exists.', 404); }

/* Refuse a second booking rather than quietly making one.
   ────────────────────────────────────────────────────────────────────────────
   Shiprocket accepts the same order_code twice and creates two shipments, two
   pickups and two charges. The shop would not notice until the invoice. */
if (!empty($order['shiprocket_shipment_id'])) {
    srFail('This order is already booked with Shiprocket (shipment '
         . $order['shiprocket_shipment_id'] . '). Cancel it there first if you need to rebook.');
}
/* An order that reached Shiprocket without a shipment is still an order there.
   The guard above only knew about shipments, so an incomplete booking — the one
   that lands under "Action needed" — read as never sent, and a second press
   created a second copy of it. */
if (!empty($order['shiprocket_order_id'])) {
    srFail('This order is already IN Shiprocket (their order ' . $order['shiprocket_order_id']
         . ') but has no shipment — it needs fixing there, under "Action needed". Booking again '
         . 'would create a duplicate.');
}

$items = orderItems($order['items_json']);
if (!$items) { srFail('This order has no items to ship.'); }

$sr = new ShiprocketService($pdo);
if (!$sr->isConfigured()) {
    srFail('Shiprocket is not set up — SHIPROCKET_EMAIL and SHIPROCKET_PASSWORD are missing from .env.');
}
if (trim((string)storeSetting($pdo, 'shiprocket_pickup_location', '')) === '') {
    srFail('Set your pickup location in Settings first. It must match a pickup address registered in your Shiprocket account exactly, or the booking is refused.');
}

$res = $sr->bookOrder($order, $items);
if ($res === null) {
    // Their message, verbatim — "Shiprocket refused this" without the reason
    // sends the owner to a dashboard to guess.
    error_log('Shiprocket booking failed for order ' . $order['order_code'] . ': ' . $sr->lastError());
    srFail('Shiprocket refused the booking: ' . $sr->lastError(), 502);
}

/* Shiprocket can accept the order and still not ship it.
   ────────────────────────────────────────────────────────────────────────────
   /orders/create/adhoc answers 200 with an order_id and no shipment_id when the
   order is missing something it needs — the dashboard then shows it under
   "Action needed: some orders are missing required information". That is not a
   refusal, so the check above passes, and the UPDATE below wrote NULL into
   shiprocket_shipment_id, which is what orders.php reads to decide whether to
   show the button. The order existed in Shiprocket and the panel still invited
   another one.

   Saved anyway, because the order is REAL: shiprocket_order_id is the only
   record the shop has that it exists, and booking again would duplicate it. */
if (empty($res['shipment_id'])) {
    try {
        $pdo->prepare("UPDATE orders SET shiprocket_order_id = :soid, shiprocket_booked_at = NOW() WHERE id = :id")
            ->execute([':soid' => $res['shiprocket_order_id'], ':id' => $orderId]);
    } catch (PDOException $e) {
        error_log('Shiprocket: incomplete booking, could not save order id for ' . $orderId . ': ' . $e->getMessage());
    }
    logAdminAction($_SESSION['admin_id'] ?? 1, 'shiprocket_book_incomplete',
        'Order ' . $order['order_code'] . ' reached Shiprocket as ' . ($res['shiprocket_order_id'] ?? 'unknown') . ' with no shipment');
    error_log('Shiprocket incomplete booking for order ' . $order['order_code']
        . ' (their order id ' . ($res['shiprocket_order_id'] ?? 'unknown') . ') — no shipment_id returned');
    srFail('Shiprocket accepted order ' . $order['order_code'] . ' but did not ship it — it is sitting in your '
         . 'Shiprocket dashboard under "Action needed", missing something it needs. Their order id is '
         . ($res['shiprocket_order_id'] ?? 'unknown') . '. Fix it there rather than booking again, or you will '
         . 'have two.', 502);
}

/* Written AFTER a confirmed booking, never before. The AWB also goes into
   tracking_number because the status emails and the customer's own order page
   already read that column — one number, in the place everything looks. */
try {
    $pdo->prepare(
        "UPDATE orders
            SET shiprocket_order_id    = :soid,
                shiprocket_shipment_id = :sid,
                shiprocket_booked_at   = NOW(),
                carrier                = COALESCE(NULLIF(carrier,''), 'Shiprocket'),
                tracking_number        = COALESCE(NULLIF(tracking_number,''), :awb)
          WHERE id = :id"
    )->execute([
        ':soid' => $res['shiprocket_order_id'],
        ':sid'  => $res['shipment_id'],
        ':awb'  => $res['awb'],
        ':id'   => $orderId,
    ]);
} catch (PDOException $e) {
    // The shipment EXISTS at this point. Saying "failed" would invite a second
    // booking and a second charge.
    error_log('Shiprocket: booked but could not save ids for order ' . $orderId . ': ' . $e->getMessage());
    srDone('Booked with Shiprocket (shipment ' . $res['shipment_id']
                   . '), but saving the reference here failed. Do NOT book again — note that number.', true);
}

logAdminAction($_SESSION['admin_id'] ?? 1, 'shiprocket_book',
    'Booked order ' . $order['order_code'] . ' — shipment ' . $res['shipment_id']);

$p = $res['parcel'];
srDone(
    'Booked with Shiprocket. Shipment ' . $res['shipment_id']
    . ($res['awb'] ? ', AWB ' . $res['awb'] : '')
    . '. Parcel sent as ' . $p['weight'] . 'kg, '
    . $p['length'] . 'x' . $p['breadth'] . 'x' . $p['height'] . 'cm'
    /* Names the garments whose weight was a default rather than a measurement.
       The parcel is re-weighed at the hub and the difference charged back, so
       the owner needs to know WHICH pieces to go and weigh — a total alone
       does not tell them that. */
    . ($p['estimated_for']
        ? ' — weight ESTIMATED for: ' . implode(', ', $p['estimated_for'])
        : ''),
    true
);
