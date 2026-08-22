<?php
// ============================================================
//  Dievon – THE invoice template
// ------------------------------------------------------------
//  One design, used by every invoice in the system:
//    pages/print_invoice.php  (customer, session-scoped)
//    admin/print_invoice.php  (admin, any order)
//
//  Previously there were two separate implementations — one rendered in PHP and
//  one built with document.write() inside admin/orders.php — which shared 6 of 7
//  class names but had to be edited twice. They had already drifted (neither
//  showed the logo, both claimed "TAX INVOICE" with no tax fields). This file
//  exists so that can never happen again: change the design here, and every
//  invoice changes.
//
//  Expects, from the including file:
//    $order  array  a row from `orders`
//    $items  array  line items: product_name, variant_name, quantity, price
//
//  GST fields are rendered ONLY when the data genuinely exists. Until HSN codes,
//  GST rates and a place of supply are recorded, this prints as an "Order
//  Summary" rather than claiming to be a tax invoice it cannot substantiate.
// ============================================================

if (!isset($order) || !is_array($order)) {
    http_response_code(500);
    die('Invoice template requires $order.');
}
$items = isset($items) && is_array($items) ? $items : [];

$inv_logo   = siteLogoUrl($pdo ?? null);
$inv_brand  = brandName($pdo ?? null);
$inv_entity = legalEntityName($pdo ?? null);

$inv_gstin   = storeSetting($pdo ?? null, 'seller_gstin', '');
$inv_addr    = storeSetting($pdo ?? null, 'seller_address', '');
$inv_email   = storeSetting($pdo ?? null, 'contact_email', '');
$inv_phone   = storeSetting($pdo ?? null, 'contact_phone', '');
$inv_state   = storeSetting($pdo ?? null, 'seller_state', '');

// A document may only call itself a Tax Invoice once it can actually carry the
// fields the law requires. Until then it is an Order Summary — an incorrect tax
// invoice is a worse problem than not issuing one.
$inv_hasTax = trim((string)$inv_gstin) !== ''
    && !empty(array_filter($items, fn($i) => !empty($i['hsn_code']) && (float)($i['gst_rate'] ?? 0) > 0));
$inv_title  = $inv_hasTax ? 'TAX INVOICE' : 'ORDER SUMMARY';

$inv_sym = !empty($order['currency_symbol']) ? $order['currency_symbol'] : '₹';
$money = function ($n) use ($inv_sym) { return $inv_sym . number_format((float)$n, 2); };

$inv_subtotal = 0.0;
foreach ($items as $it) { $inv_subtotal += (float)$it['price'] * (int)$it['quantity']; }
$inv_discount = (float)($order['discount_amount'] ?? 0);
$inv_delivery = (float)($order['delivery_charge'] ?? 0);
$inv_codFee   = (float)($order['cod_fee'] ?? 0);
$inv_total    = (float)($order['total_price'] ?? 0);

// Tax is derived per line only where the item carries its own rate.
// The mode is frozen per line at checkout (price_includes_gst):
//   inclusive (default) → the price already holds the tax, so the taxable value
//                         is back-calculated:  base = line / (1 + rate/100)
//   exclusive            → GST was added on top when the order was charged, so
//                         the tax is re-derived exactly the same way.
$inv_taxable = 0.0; $inv_taxTotal = 0.0; $inv_hsnRows = [];

// A coupon reduces what the customer actually paid, so it must reduce the value
// tax is charged on.
//
// The discount was printed as a row BELOW the tax rows and never entered this
// calculation, so output tax was declared on the pre-discount value — measured
// on order CB-98048136: ₹552.04 IGST declared where ₹469.24 was due, 17.6% too
// much, tax the shop would remit on money it never received. Apportioning
// pro-rata to line value keeps each HSN row's share proportionate.
$inv_discountFactor = ($inv_discount > 0 && $inv_subtotal > 0)
    ? max(0.0, ($inv_subtotal - $inv_discount) / $inv_subtotal)
    : 1.0;

// Delivery and COD handling are part of a COMPOSITE SUPPLY and carry the rate
// of the principal supply — the garment. They were printed as untaxed rows
// below the tax lines, so on order CB-36421013 ₹148 was collected from the
// customer with ₹0 GST declared against it, and admin/gst_report.php carried
// the same gap into the filed figure.
//
// They are treated as TAX-INCLUSIVE, because they are already part of
// total_price — the customer paid them. Back-calculating keeps the invoice
// TOTAL exactly what was charged while declaring the tax inside it.
$inv_principalRate = 0.0; $inv_principalLine = -1.0;

if ($inv_hasTax) {
    foreach ($items as $it) {
        $line = (float)$it['price'] * (int)$it['quantity'] * $inv_discountFactor;
        $rate = (float)($it['gst_rate'] ?? 0);
        $excl = (int)($it['price_includes_gst'] ?? 1) === 0;
        if ($rate > 0) {
            $base = $excl ? $line : $line / (1 + $rate / 100);
            $tax  = $excl ? round($line * $rate / 100, 2) : $line - $base;
            if ($line > $inv_principalLine) { $inv_principalLine = $line; $inv_principalRate = $rate; }
        } else {
            $base = $line; $tax = 0.0;
        }
        $inv_taxable  += $base;
        $inv_taxTotal += $tax;
        $h = $it['hsn_code'] ?? '—';
        if (!isset($inv_hsnRows[$h])) { $inv_hsnRows[$h] = ['taxable' => 0, 'tax' => 0, 'rate' => $rate]; }
        $inv_hsnRows[$h]['taxable'] += $base;
        $inv_hsnRows[$h]['tax']     += $tax;
    }

    // A zero-rated principal supply still carries its delivery into the taxable
    // value — at 0% tax, not excluded. Gating this on `$inv_principalRate > 0`
    // dropped the delivery and COD money from the taxable line entirely whenever
    // no item carried a rate, so the invoice under-stated turnover.
    $inv_charges = $inv_delivery + $inv_codFee;
    if ($inv_charges > 0) {
        $chargeBase = $inv_principalRate > 0 ? $inv_charges / (1 + $inv_principalRate / 100) : $inv_charges;
        $chargeTax  = $inv_charges - $chargeBase;
        $inv_taxable  += $chargeBase;
        $inv_taxTotal += $chargeTax;
        // SAC 996812 — courier/delivery services, shown separately from the
        // garment HSNs so the summary states what was taxed and at what rate.
        $sac = '996812';
        if (!isset($inv_hsnRows[$sac])) { $inv_hsnRows[$sac] = ['taxable' => 0, 'tax' => 0, 'rate' => $inv_principalRate]; }
        $inv_hsnRows[$sac]['taxable'] += $chargeBase;
        $inv_hsnRows[$sac]['tax']     += $chargeTax;
    }
}
// Same state as the seller = CGST + SGST; different state = IGST.
//
// orders has no `state` column, so this always came back empty and every
// invoice printed IGST — wrong for every sale inside the seller's own state.
// The delivery PIN identifies the circle, so place of supply is derived from
// what the shopper already gave rather than from a field nobody filled in.
$inv_placeOfSupply = trim((string)($order['state'] ?? ''));
if ($inv_placeOfSupply === '' && function_exists('indianStateForPin')) {
    $inv_placeOfSupply = indianStateForPin($order['postcode'] ?? '');
}
$inv_isIntraState  = $inv_state !== '' && $inv_placeOfSupply !== ''
    && strcasecmp(trim($inv_state), $inv_placeOfSupply) === 0;

$inv_paid = strtolower((string)($order['payment_status'] ?? '')) === 'paid'
         || strtolower((string)($order['payment_method'] ?? '')) === 'razorpay';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($inv_title) ?> – <?= htmlspecialchars($order['order_code'] ?? '') ?></title>
<style>
  :root{ --brand:#511126; --gold:#b08d3f; --ink:#111827; --muted:#6b7280; }
  *{box-sizing:border-box}
  body{ background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
        color:var(--ink); margin:0; padding:30px 16px; font-size:14px; line-height:1.5; }
  .print-bar{ max-width:800px; margin:0 auto 14px; }
  .print-btn{ background:var(--brand); color:#fff; border:none; padding:10px 20px;
              font-size:13px; font-weight:600; cursor:pointer; }
  .print-btn:hover{ background:#6d1832; }

  .dn-container{ max-width:800px; margin:0 auto; background:#fff; border:1px solid #e5e7eb;
                 padding:40px; box-shadow:0 4px 12px rgba(0,0,0,.04); }
  .dn-header{ display:flex; justify-content:space-between; align-items:flex-start;
              border-bottom:2px solid var(--brand); padding-bottom:20px; margin-bottom:24px; gap:24px; }
  .dn-logo-img{ height:80px; width:auto; max-width:230px; object-fit:contain; display:block; margin-bottom:10px; }
  .muted-note{ font-size:11.5px; color:var(--muted); line-height:1.65; }
  .muted-note strong{ color:var(--ink); }
  .align-right{ text-align:right; flex-shrink:0; }
  .doc-title{ font-size:22px; font-weight:800; letter-spacing:.04em; margin:0 0 10px; color:var(--ink); }
  .dn-badge{ background:var(--brand); color:#fff; padding:4px 12px;
             font-size:11px; font-weight:700; display:inline-block; letter-spacing:.05em; }
  .dn-badge--due{ background:#b45309; }
  .doc-code{ font-size:13px; margin-top:9px; }
  .doc-date{ font-size:12px; color:var(--muted); }

  .dn-grid{ display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:28px; }
  .dn-card{ background:#f9fafb; border:1px solid #f3f4f6; padding:16px; }
  .dn-card h3{ margin:0 0 9px; font-size:10px; letter-spacing:.12em; text-transform:uppercase;
               color:var(--brand); font-weight:800; }
  .who{ font-weight:700; font-size:14px; margin-bottom:4px; }
  .line{ font-size:12.5px; color:#374151; line-height:1.7; }
  .field{ font-size:12.5px; margin-bottom:4px; }

  .dn-table{ width:100%; border-collapse:collapse; margin-bottom:10px; }
  .dn-table th{ background:#f3f4f6; font-size:10px; letter-spacing:.08em; text-transform:uppercase;
                padding:10px 8px; text-align:left; color:#374151; font-weight:700; border-bottom:1px solid #e5e7eb; }
  .dn-table td{ padding:12px 8px; border-bottom:1px solid #f3f4f6; font-size:13px; vertical-align:top; }
  .r{text-align:right} .c{text-align:center}
  .item-name{ font-weight:700; }
  .item-sub{ color:var(--muted); font-size:11.5px; }
  .hsn{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:11.5px; color:var(--muted); }

  .totals-wrap{ display:flex; justify-content:space-between; gap:24px; align-items:flex-start; margin-bottom:30px; }
  .words{ flex:1; background:#f9fafb; border-left:3px solid var(--gold); padding:12px 14px;
 font-size:12.5px; }
  .words span{ display:block; font-size:9.5px; letter-spacing:.12em; text-transform:uppercase;
               color:var(--muted); margin-bottom:3px; }
  .dn-totals{ width:260px; flex-shrink:0; }
  .dn-totals .row{ display:flex; justify-content:space-between; padding:5px 0; font-size:13px; }
  .dn-totals .row span:first-child{ color:var(--muted); }
  .dn-totals .sep{ border-top:1px solid #e5e7eb; margin-top:5px; padding-top:9px; }
  .grand-total{ border-top:2px solid var(--brand); margin-top:8px; padding-top:10px;
                display:flex; justify-content:space-between; font-size:17px; font-weight:800; color:var(--brand); }

  .taxsum{ margin-bottom:28px; }
  .taxsum h4{ font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--brand);
              font-weight:800; margin:0 0 8px; }

  .dn-footer{ display:grid; grid-template-columns:1fr auto; gap:40px; align-items:end; }
  .terms{ font-size:11px; color:var(--muted); line-height:1.7; }
  .sig-wrap{ text-align:center; min-width:190px; }
  .sig-label{ font-size:11px; letter-spacing:.06em; color:var(--muted); }
  .sig-box{ border-bottom:1px solid #9ca3af; height:40px; margin-top:10px; }
  .sig-hint{ font-size:11px; color:var(--muted); margin-top:6px; }
  .thanks{ text-align:center; margin-top:26px; padding-top:18px; border-top:1px solid #f3f4f6;
           font-family:Georgia,serif; font-size:15px; color:var(--brand); }

  @media print{
    body{ background:#fff; padding:0; }
    .dn-container{ border:none; box-shadow:none; padding:0; }
    .no-print{ display:none !important; }
  }
  @media (max-width:640px){
    .dn-grid{ grid-template-columns:1fr; }
    .dn-footer{ grid-template-columns:1fr; }
    .totals-wrap{ flex-direction:column; } .dn-totals{ width:100%; }
    .dn-header{ flex-direction:column; } .align-right{ text-align:left; }
  }
</style>
</head>
<body>

<div class="print-bar no-print">
  <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

<div class="dn-container">

  <div class="dn-header">
    <div>
      <img src="<?= htmlspecialchars($inv_logo) ?>" alt="<?= htmlspecialchars($inv_brand) ?>" class="dn-logo-img">
      <div class="muted-note">
        <strong><?= htmlspecialchars($inv_entity) ?></strong><br>
        <?php if ($inv_addr): ?><?= nl2br(htmlspecialchars($inv_addr)) ?><br><?php endif; ?>
        <?php if ($inv_gstin): ?>GSTIN: <strong><?= htmlspecialchars($inv_gstin) ?></strong><?php if ($inv_state): ?> &middot; <?= htmlspecialchars($inv_state) ?><?php endif; ?><br><?php endif; ?>
        <?= htmlspecialchars($inv_email) ?><?= $inv_phone ? ' | ' . htmlspecialchars($inv_phone) : '' ?>
      </div>
    </div>
    <div class="align-right">
      <h2 class="doc-title"><?= htmlspecialchars($inv_title) ?></h2>
      <div class="dn-badge <?= $inv_paid ? '' : 'dn-badge--due' ?>">
        <?= $inv_paid ? 'PAID' : 'PAYMENT DUE' ?><?= !empty($order['payment_method']) ? ' &middot; ' . htmlspecialchars(strtoupper($order['payment_method'])) : '' ?>
      </div>
      <?php if (!empty($order['invoice_number'])): ?>
      <div class="doc-code"><span style="font-size:11px; color:var(--muted);"><?= $inv_hasTax ? 'Invoice No.' : 'Receipt No.' ?>:</span> <b><?= htmlspecialchars($order['invoice_number']) ?></b></div>
      <?php endif; ?>
      <div class="doc-date">Order Ref: <?= htmlspecialchars($order['order_code'] ?? '') ?></div>
      <div class="doc-date">Date: <?= !empty($order['created_at']) ? date('j F Y', strtotime($order['created_at'])) : '' ?></div>
    </div>
  </div>

  <div class="dn-grid">
    <div class="dn-card">
      <h3>Billed To</h3>
      <div class="who"><?= htmlspecialchars($order['customer_name'] ?? '') ?></div>
      <div class="line">
        <?= nl2br(htmlspecialchars($order['address'] ?? '')) ?><br>
        <?= htmlspecialchars($order['postcode'] ?? '') ?><br>
        <?php if (!empty($order['phone'])): ?>Phone: <?= htmlspecialchars($order['phone']) ?><br><?php endif; ?>
        <?php if (!empty($order['customer_email'])): ?><?= htmlspecialchars($order['customer_email']) ?><?php endif; ?>
      </div>
    </div>
    <div class="dn-card">
      <h3>Order Details</h3>
      <?php if ($inv_placeOfSupply): ?>
      <div class="field"><b>Place of Supply:</b> <?= htmlspecialchars($inv_placeOfSupply) ?></div>
      <?php endif; ?>
      <div class="field"><b>Payment:</b> <?= htmlspecialchars(strtoupper($order['payment_method'] ?? '—')) ?><?= !empty($order['razorpay_payment_id']) ? ' &middot; ' . htmlspecialchars($order['razorpay_payment_id']) : '' ?></div>
      <div class="field"><b>Status:</b> <?= htmlspecialchars($order['status'] ?? '') ?></div>
      <?php if (!empty($order['tracking_number'])): ?>
      <div class="field"><b>Tracking:</b> <?= htmlspecialchars($order['carrier'] ?? '') ?> <?= htmlspecialchars($order['tracking_number']) ?></div>
      <?php endif; ?>
      <?php if (!empty($order['promo_code'])): ?>
      <div class="field"><b>Promo:</b> <?= htmlspecialchars($order['promo_code']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <table class="dn-table">
    <thead>
      <tr>
        <th style="width:28px">#</th>
        <th>Item Description</th>
        <?php if ($inv_hasTax): ?><th style="width:76px">HSN</th><?php endif; ?>
        <th class="c" style="width:40px">Qty</th>
        <th class="r" style="width:86px">Rate</th>
        <?php if ($inv_hasTax): ?>
        <th class="r" style="width:88px">Taxable</th>
        <th class="r" style="width:92px">GST</th>
        <?php endif; ?>
        <th class="r" style="width:92px">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $it):
        $qty  = (int)$it['quantity'];
        $line = (float)$it['price'] * $qty;
        $rate = (float)($it['gst_rate'] ?? 0);
        $excl = (int)($it['price_includes_gst'] ?? 1) === 0;
        if ($inv_hasTax && $rate > 0) {
            $base = $excl ? $line : $line / (1 + $rate / 100);
            $tax  = $excl ? round($line * $rate / 100, 2) : $line - $base;
        } else {
            $base = $line; $tax = 0.0;
        }
      ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td>
          <div class="item-name"><?= htmlspecialchars($it['product_name'] ?? $it['name'] ?? '') ?></div>
          <?php if (!empty($it['variant_name']) || !empty($it['color_name'])): ?>
          <?php $invSize = variantSizeLabel($it['variant_name'] ?? ''); ?>
          <div class="item-sub"><?= htmlspecialchars(trim(($it['color_name'] ?? '') . ($invSize !== '' ? ' Size: ' . $invSize : ''))) ?></div>
          <?php endif; ?>
        </td>
        <?php if ($inv_hasTax): ?><td class="hsn"><?= htmlspecialchars($it['hsn_code'] ?? '—') ?></td><?php endif; ?>
        <td class="c"><?= $qty ?></td>
        <td class="r"><?= $money($it['price']) ?></td>
        <?php if ($inv_hasTax): ?>
        <td class="r"><?= $money($base) ?></td>
        <td class="r"><?= $money($tax) ?><div class="hsn">@ <?= rtrim(rtrim(number_format($rate, 2), '0'), '.') ?>%<?= $excl ? ' <span style="color:#b08d3f;">(added)</span>' : '' ?></div></td>
        <?php endif; ?>
        <td class="r"><?= $money($line) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals-wrap">
    <div class="words">
      <span>Amount in words</span>
      <?= htmlspecialchars(ucwords(numberToWords($inv_total))) ?>
    </div>
    <div class="dn-totals">
      <?php if ($inv_hasTax): ?>
        <?php
          // Round the tax ONCE, then derive the second half by subtraction.
          //
          // Both halves were printed as $inv_taxTotal / 2, each rounded on its
          // own, so an odd number of paise made CGST + SGST fail to add up to
          // the tax actually charged and the column footed a paisa short of the
          // printed TOTAL. On a tax invoice the halves must always reconstitute
          // the whole.
          $inv_taxRounded = round($inv_taxTotal, 2);
          $inv_cgst = round($inv_taxRounded / 2, 2);
          $inv_sgst = $inv_taxRounded - $inv_cgst;
        ?>
        <?php // Shown before the taxable value, because they are inside it. ?>
        <div class="row"><span>Subtotal</span><span><?= $money($inv_subtotal) ?></span></div>
        <?php if ($inv_discount > 0): ?>
        <div class="row"><span>Discount</span><span>&minus; <?= $money($inv_discount) ?></span></div>
        <?php endif; ?>
        <div class="row"><span>Delivery</span><span><?= $inv_delivery > 0 ? $money($inv_delivery) : 'FREE' ?></span></div>
        <?php if ($inv_codFee > 0): ?>
        <div class="row"><span>COD Handling Fee</span><span><?= $money($inv_codFee) ?></span></div>
        <?php endif; ?>
        <div class="row sep"><span>Taxable Value</span><span><?= $money($inv_taxable) ?></span></div>
        <?php if ($inv_isIntraState): ?>
          <div class="row"><span>CGST</span><span><?= $money($inv_cgst) ?></span></div>
          <div class="row"><span>SGST</span><span><?= $money($inv_sgst) ?></span></div>
        <?php else: ?>
          <div class="row"><span>IGST</span><span><?= $money($inv_taxRounded) ?></span></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="row"><span>Subtotal</span><span><?= $money($inv_subtotal) ?></span></div>
        <div class="row"><span>Delivery</span><span><?= $inv_delivery > 0 ? $money($inv_delivery) : 'FREE' ?></span></div>
        <?php if ($inv_codFee > 0): ?>
        <div class="row"><span>COD Handling Fee</span><span><?= $money($inv_codFee) ?></span></div>
        <?php endif; ?>
        <?php if ($inv_discount > 0): ?>
        <div class="row"><span>Discount</span><span>&minus; <?= $money($inv_discount) ?></span></div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="grand-total"><span>TOTAL</span><span><?= $money($inv_total) ?></span></div>
    </div>
  </div>

  <?php if ($inv_hasTax && $inv_hsnRows): ?>
  <div class="taxsum">
    <h4>HSN / Tax Summary</h4>
    <table class="dn-table" style="margin-bottom:0;">
      <thead>
        <tr>
          <th>HSN</th><th class="r">Taxable</th>
          <?php if ($inv_isIntraState): ?><th class="r">CGST</th><th class="r">SGST</th><?php else: ?><th class="r">IGST</th><?php endif; ?>
          <th class="r">Total Tax</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inv_hsnRows as $h => $r): ?>
        <tr>
          <td class="hsn"><?= htmlspecialchars($h) ?></td>
          <td class="r"><?= $money($r['taxable']) ?></td>
          <?php
            // Same halve-once rule as the totals column, per HSN row, so each
            // row's CGST + SGST ties exactly to its own Total Tax cell.
            $rowTax  = round((float)$r['tax'], 2);
            $rowCgst = round($rowTax / 2, 2);
            $rowSgst = $rowTax - $rowCgst;
          ?>
          <?php if ($inv_isIntraState): ?>
            <td class="r"><?= $money($rowCgst) ?></td><td class="r"><?= $money($rowSgst) ?></td>
          <?php else: ?>
            <td class="r"><?= $money($rowTax) ?></td>
          <?php endif; ?>
          <td class="r"><?= $money($rowTax) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="dn-footer">
    <div class="terms">
      Returns accepted within 14 days in original condition with tags intact, per our Returns Policy.<br>
      This is a computer-generated document and does not require a physical signature.
      <?php if (!$inv_hasTax): ?><br><em>Not a tax invoice.</em><?php endif; ?>
    </div>
    <div class="sig-wrap">
      <div class="sig-label">For <?= htmlspecialchars($inv_entity) ?></div>
      <div class="sig-box"></div>
      <div class="sig-hint">Authorised Signatory</div>
    </div>
  </div>

  <div class="thanks">Thank you for choosing <?= htmlspecialchars($inv_brand) ?></div>

</div>
</body>
</html>
