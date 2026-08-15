<?php
/**
 * Dievon — Refunds
 *
 * Actually moves money. Before this file existed, "Refunded" was only a word an
 * admin picked from a dropdown: the site never called Razorpay's refund endpoint,
 * and there was nowhere to record an amount, a date or a reference.
 *
 * Two payment paths, deliberately different:
 *   - online (Razorpay)  → a real API call to /v1/payments/{id}/refund
 *   - COD / cash / bank  → recorded only, because the shop hands the cash back
 *                          itself. Still logged so the books balance.
 *
 * Ordering matters. The refund row is written and the order's running total is
 * raised BEFORE the API call, inside a transaction that locks the order row.
 * That way two admins pressing Refund at the same moment cannot both pass the
 * "is there anything left to refund?" check and double-pay a customer. If the
 * API then fails, the reservation is released and the row is marked failed.
 */

if (!class_exists('RefundService')) {

class RefundService
{
    /** Refund rows that count against the order total. */
    const COUNTED_STATUSES = ['pending', 'processed'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * How much of this order may still be refunded.
     * Never negative, and never more than what was actually charged.
     */
    public function refundableAmount(array $order): float
    {
        $paid     = (float)($order['total_price'] ?? 0);
        $already  = (float)($order['refunded_amount'] ?? 0);
        return max(0.0, round($paid - $already, 2));
    }

    /**
     * True when this order was paid by card/UPI and can go back through Razorpay.
     *
     * Delegates to orderIsOnlineRefundable() in config/config.php. The rule used to
     * live here AND in the admin refund panel, and the two had drifted — the panel
     * omitted the payment_status check, so it promised an automatic Razorpay refund
     * on an order this method would have refunded manually.
     */
    public function isOnlineRefundable(array $order): bool
    {
        return function_exists('orderIsOnlineRefundable')
            ? orderIsOnlineRefundable($order)
            : (strtolower(trim((string)($order['payment_method'] ?? ''))) === 'online'
                && trim((string)($order['razorpay_payment_id'] ?? '')) !== ''
                && strtolower(trim((string)($order['payment_status'] ?? ''))) === 'paid');
    }

    /**
     * Issue a refund.
     *
     * @param int    $orderId
     * @param float  $amount   in major units (rupees), not paise
     * @param string $reason
     * @param int|null $adminId
     * @param string|null $payoutChannel  cash | upi | bank — how a MANUAL refund was
     *                    handed back, so the books say where the money went.
     * @return array{success:bool, message:string, refund_id?:int, reference?:string, mode?:string}
     */
    public function issueRefund(int $orderId, float $amount, string $reason = '', ?int $adminId = null, ?string $payoutChannel = null): array
    {
        $amount = round($amount, 2);
        if ($orderId <= 0)  { return ['success' => false, 'message' => 'Invalid order.']; }
        if ($amount <= 0)   { return ['success' => false, 'message' => 'Refund amount must be greater than zero.']; }

        // Only meaningful for manual refunds; anything else is rejected silently.
        $channel = in_array(strtolower(trim((string)$payoutChannel)), ['cash', 'upi', 'bank'], true)
            ? strtolower(trim((string)$payoutChannel)) : null;

        // ── Phase 1: reserve, under a row lock ────────────────────────────────
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);   // false, not null, when missing
            if (!$order) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Order not found.'];
            }

            // Money can only go back if it came in.
            //
            // The guards below check the AMOUNT (not more than the order, not
            // already refunded) but nothing checked that the order was ever paid.
            // refundableAmount() is total_price − refunded_amount, which is the
            // full total on an Unpaid order — so a refund against an order the
            // customer never paid for succeeded, wrote a refund record, and put
            // that amount in the books as money returned. On COD that is the
            // ordinary state of every order until the parcel is delivered.
            $paidState = strtolower(trim((string)($order['payment_status'] ?? '')));
            if (!in_array($paidState, ['paid', 'cash'], true)) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'This order has not been paid, so there is nothing to refund. '
                               . 'Mark it Cancelled instead — payment status is currently "'
                               . ($order['payment_status'] ?: 'Unpaid') . '".',
                ];
            }

            $refundable = $this->refundableAmount($order);
            if ($refundable <= 0) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'This order has already been refunded in full.'];
            }
            // Half a paisa of slack absorbs float representation noise only.
            if ($amount > $refundable + 0.005) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => sprintf(
                    'Too much: only %s is left to refund on this order.',
                    function_exists('formatPrice') ? formatPrice($refundable) : number_format($refundable, 2)
                )];
            }

            $online = $this->isOnlineRefundable($order);
            $mode   = $online ? 'razorpay' : 'manual';

            $ins = $this->pdo->prepare(
                "INSERT INTO order_refunds
                    (order_id, order_code, amount, currency, method, payout_channel, razorpay_payment_id, status, reason, created_by)
                 VALUES
                    (:oid, :code, :amt, :cur, :method, :channel, :pay, :status, :reason, :admin)"
            );
            $ins->execute([
                'oid'     => $orderId,
                'code'    => (string)($order['order_code'] ?? ''),
                'amt'     => $amount,
                'cur'     => (string)($order['currency'] ?? 'INR'),
                'method'  => $mode,
                'channel' => $online ? null : $channel,
                'pay'     => $online ? (string)$order['razorpay_payment_id'] : null,
                // A manual refund is done by hand and is true the moment it is logged.
                // An online one is only a claim until Razorpay confirms it.
                'status' => $online ? 'pending' : 'processed',
                'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'admin'  => $adminId,
            ]);
            $refundId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare(
                "UPDATE orders SET refunded_amount = ROUND(COALESCE(refunded_amount,0) + :amt, 2) WHERE id = :id"
            )->execute(['amt' => $amount, 'id' => $orderId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            error_log('Refund reserve failed for order ' . $orderId . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not start the refund. Nothing was charged back.'];
        }

        // ── Phase 2: manual refunds are already done ──────────────────────────
        if ($mode === 'manual') {
            $this->markOrderRefundState($orderId);
            // Money leaving the business is the single most audit-worthy action
            // in the panel, and it had no entry at all.
            $this->logRefund($orderId, $order, $amount, 'manual', (string)$refundId, $reason, $adminId);
            $channelLabel = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank transfer'][$channel ?? ''] ?? '';
            return [
                'success'   => true,
                'mode'      => 'manual',
                'refund_id' => $refundId,
                'message'   => 'Refund recorded. Hand the money back to the customer yourself — '
                             . 'this order was not paid online, so there is nothing to send back through Razorpay.'
                             . ($channelLabel !== '' ? ' Payout: ' . $channelLabel . '.' : ''),
            ];
        }

        // ── Phase 3: call Razorpay, outside the lock ──────────────────────────
        $api = $this->callRazorpayRefund(
            (string)$order['razorpay_payment_id'],
            $amount,
            (string)($order['order_code'] ?? ''),
            $reason
        );

        if (!empty($api['success'])) {
            $this->pdo->prepare(
                "UPDATE order_refunds
                    SET status = 'processed', razorpay_refund_id = :rid, notes = :notes, processed_at = NOW()
                  WHERE id = :id"
            )->execute([
                'rid'   => $api['refund_id'] ?? null,
                'notes' => $api['raw'] ?? null,
                'id'    => $refundId,
            ]);
            $this->markOrderRefundState($orderId);
            $this->logRefund($orderId, $order, $amount, 'razorpay', (string)($api['refund_id'] ?? ''), $reason, $adminId);
            return [
                'success'   => true,
                'mode'      => 'razorpay',
                'refund_id' => $refundId,
                'reference' => $api['refund_id'] ?? '',
                'message'   => 'Refund sent to Razorpay successfully.',
            ];
        }

        // Failed — release the reservation so the money can be tried again.
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare(
                "UPDATE order_refunds SET status = 'failed', notes = :notes WHERE id = :id"
            )->execute(['notes' => $api['error'] ?? 'Unknown error', 'id' => $refundId]);
            $this->pdo->prepare(
                "UPDATE orders SET refunded_amount = GREATEST(0, ROUND(COALESCE(refunded_amount,0) - :amt, 2)) WHERE id = :id"
            )->execute(['amt' => $amount, 'id' => $orderId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            error_log('Refund rollback failed for refund ' . $refundId . ': ' . $e->getMessage());
        }

        return [
            'success' => false,
            'mode'    => 'razorpay',
            'message' => 'Razorpay refused the refund: ' . ($api['error'] ?? 'unknown error')
                       . ' — nothing was refunded, you can try again.',
        ];
    }

    /**
     * POST /v1/payments/{id}/refund
     *
     * Same auth and timeout shape as actions/razorpay_order.php so there is one
     * way this shop talks to Razorpay.
     */
    protected function callRazorpayRefund(string $paymentId, float $amount, string $orderCode, string $reason): array
    {
        if (!defined('RAZORPAY_KEY_ID') || !defined('RAZORPAY_KEY_SECRET')
            || RAZORPAY_KEY_ID === '' || RAZORPAY_KEY_SECRET === '') {
            return ['success' => false, 'error' => 'Razorpay keys are not configured.'];
        }

        $payload = json_encode([
            'amount' => (int)round($amount * 100),   // paise
            'speed'  => 'normal',
            'notes'  => [
                'order_code' => $orderCode,
                'reason'     => mb_substr($reason, 0, 200),
                'source'     => 'dievon-admin',
            ],
        ]);

        $ch = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId) . '/refund');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                // Lets Razorpay collapse an accidental retry into one refund.
                'X-Razorpay-Idempotency-Key: ' . substr(hash('sha256', $orderCode . '|' . $paymentId . '|' . $amount), 0, 40),
            ],
            CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            error_log("Razorpay refund network error for $paymentId: $curlErr");
            return ['success' => false, 'error' => 'Network error contacting Razorpay.'];
        }

        $data = json_decode((string)$response, true);

        if ($httpCode >= 400 || empty($data['id'])) {
            $msg = $data['error']['description'] ?? ('HTTP ' . $httpCode);
            error_log("Razorpay refund rejected for $paymentId: HTTP $httpCode | $response");
            return ['success' => false, 'error' => $msg];
        }

        return ['success' => true, 'refund_id' => (string)$data['id'], 'raw' => (string)$response];
    }

    /**
     * Keep orders.status honest after a refund. Only ever moves it INTO a refund
     * state — it will not drag an order back out of Cancelled or Returned, since
     * those say something a refund does not.
     */
    private function markOrderRefundState(int $orderId): void
    {
        try {
            $s = $this->pdo->prepare("SELECT total_price, refunded_amount, status FROM orders WHERE id = :id");
            $s->execute(['id' => $orderId]);
            $o = $s->fetch(PDO::FETCH_ASSOC);
            if (!$o) { return; }

            $full = ((float)$o['refunded_amount'] + 0.005) >= (float)$o['total_price'];
            if (!$full) { return; }   // partial refunds leave the status alone

            $keep = ['Cancelled', 'Returned', 'Refunded'];
            if (in_array((string)$o['status'], $keep, true)) { return; }

            $this->pdo->prepare("UPDATE orders SET status = 'Refunded' WHERE id = :id")
                      ->execute(['id' => $orderId]);
        } catch (Throwable $e) {
            error_log('markOrderRefundState failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    /** Every refund logged against an order, newest first. */
    public function refundsForOrder(int $orderId): array
    {
        try {
            $s = $this->pdo->prepare(
                "SELECT * FROM order_refunds WHERE order_id = :id ORDER BY id DESC"
            );
            $s->execute(['id' => $orderId]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }


    /**
     * Record a refund in the admin audit trail.
     *
     * Separate from order_refunds, which is the accounting record. This is the
     * "who did it" record, and it sits in the same log as product and order
     * changes so one timeline answers "what happened to this order".
     *
     * Wrapped so a logging failure can never roll back a refund that has
     * already left the building.
     */
    private function logRefund(int $orderId, array $order, float $amount, string $mode, string $reference, string $reason, ?int $adminId): void
    {
        if (!function_exists('logAdminAction')) { return; }
        try {
            logAdminAction(
                $adminId ?: 1,
                'refund_issued',
                sprintf('Order %s (#%d) — %s via %s%s%s',
                    (string)($order['order_code'] ?? ''),
                    $orderId,
                    function_exists('formatPrice') ? formatPrice($amount) : number_format($amount, 2),
                    $mode,
                    $reference !== '' ? ', ref ' . $reference : '',
                    $reason !== '' ? ' — ' . $reason : ''
                )
            );
        } catch (\Throwable $e) {
            error_log('logRefund: ' . $e->getMessage());
        }
    }
}
}
