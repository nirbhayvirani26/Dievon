-- ============================================================
-- COD remittance tracking + refund payout channel
-- ============================================================
-- COD remittance: delivery marks cash as handed over (payment_status 'Cash');
-- REMITTANCE is the courier paying the shop later. remitted_at/remitted_amount
-- record that second step so the books can show outstanding courier cash.
ALTER TABLE `orders`
    ADD COLUMN `remitted_at` DATETIME DEFAULT NULL AFTER `razorpay_payment_id`,
    ADD COLUMN `remitted_amount` DECIMAL(10,2) DEFAULT NULL AFTER `remitted_at`;

-- How a manual (non-Razorpay) refund was handed back — cash / UPI / bank transfer.
ALTER TABLE `order_refunds`
    ADD COLUMN `payout_channel` VARCHAR(20) DEFAULT NULL AFTER `method`;
