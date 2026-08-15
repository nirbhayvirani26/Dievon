<?php
// ============================================================
//  Dievon – Delivery / COD availability check (product page)
// ------------------------------------------------------------
//  The product page's old checker was a lie: it approved any postcode longer
//  than three characters with a hardcoded setTimeout. This endpoint answers
//  from the exact server-side rules the checkout enforces — a postcode that is
//  domestic gets delivery + COD, a plausible foreign one gets international
//  delivery with no COD, and anything else is refused.
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pin = strtoupper(trim((string)($_GET['pin'] ?? ($_GET['postcode'] ?? ''))));
$pin = preg_replace('/[^A-Z0-9]/', '', $pin);

if ($pin === '') {
    echo json_encode(['ok' => false, 'reason' => 'empty']);
    exit;
}

$zone  = detectShippingZone($pin, $pdo);
$cod   = codAvailableForPostcode($pin, $pdo);
$state = function_exists('indianStateForPin') ? indianStateForPin($pin) : '';

// isDeliverablePostcode() accepts any plausible 3–10 alphanumeric foreign code,
// which a three-letter keystroke mash like "abc" sails through. A real postcode
// almost always contains a digit, so the international path requires one here —
// otherwise the checker would solemnly confirm "international delivery" for
// garbage. (Domestic PINs are decided before this, by detectShippingZone.)
$deliverable = isDeliverablePostcode($pin, $pdo)
    && ($zone === 'domestic' || preg_match('/[0-9]/', $pin) === 1);

echo json_encode([
    'ok'          => true,
    'zone'        => $zone,
    'cod'         => $cod,
    'state'       => $state,
    'deliverable' => $deliverable,
]);
