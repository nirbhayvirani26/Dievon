<?php
// ============================================================
//  Dievon – Admin: Promo Code Handler
//  Actions: create | toggle | delete
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

require_once '../config/config.php';
require_once '../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('pricing.manage', true);


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method. POST required.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
    exit;
}

$action = $_POST['action'] ?? '';
logAdminAction($_SESSION['admin_id'] ?? 1, 'promo_action_' . $action, "Action: $action");

// ── CREATE ───────────────────────────────────────────────────
if ($action === 'create') {
    $code       = strtoupper(trim($_POST['code'] ?? ''));
    $desc       = trim($_POST['description'] ?? '');
    $type       = ($_POST['discount_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
    $value      = (float)($_POST['discount_value'] ?? 0);
    $minOrder   = (float)($_POST['min_order'] ?? 0);
    $maxUses    = trim($_POST['max_uses'] ?? '') !== '' ? (int)$_POST['max_uses'] : null;
    $expiresAt  = trim($_POST['expires_at'] ?? '') !== '' ? $_POST['expires_at'] : null;
    $active     = !empty($_POST['active']) ? 1 : 0;

    if (strlen($code) < 2) { echo json_encode(['success' => false, 'message' => 'Please enter a promo code.']); exit; }
    if ($value <= 0)       { echo json_encode(['success' => false, 'message' => 'Discount value must be greater than 0.']); exit; }
    if ($type === 'percentage' && $value > 100) {
        echo json_encode(['success' => false, 'message' => 'Percentage discount cannot exceed 100.']); exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO promo_codes (code, description, discount_type, discount_value, min_order, max_uses, active, expires_at) VALUES (:code, :desc, :type, :value, :min_order, :max_uses, :active, :expires_at)");
        $stmt->execute([
            'code' => $code, 'desc' => $desc, 'type' => $type, 'value' => $value,
            'min_order' => $minOrder, 'max_uses' => $maxUses, 'active' => $active, 'expires_at' => $expiresAt
        ]);
        $id = $pdo->lastInsertId();
        $row = $pdo->query("SELECT * FROM promo_codes WHERE id = $id")->fetch();
        echo json_encode(['success' => true, 'promo' => $row]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            echo json_encode(['success' => false, 'message' => "Promo code '$code' already exists."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error creating promo code.']);
        }
    }
    exit;
}

// ── TOGGLE ACTIVE/DISABLED ────────────────────────────────────
if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }
    try {
        $pdo->prepare("UPDATE promo_codes SET active = IF(active=1,0,1) WHERE id = :id")->execute(['id' => $id]);
        $row = $pdo->query("SELECT active FROM promo_codes WHERE id = $id")->fetch();
        echo json_encode(['success' => true, 'active' => (bool)($row['active'] ?? 0)]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error toggling promo code.']);
    }
    exit;
}

// ── DELETE ────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }
    try {
        $pdo->prepare("DELETE FROM promo_codes WHERE id = :id")->execute(['id' => $id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error deleting promo code.']);
    }
    exit;
}

/* ── SET A CODE'S VALUE FOR ONE COUNTRY ───────────────────────
   A fixed-amount code carries one figure, in the shop's own currency. Abroad
   that figure is meaningless — this shop converts nothing, every country
   carries its own prices — so each country needs its own amount before the
   code can honestly be offered there. Blank clears the row, and a code with
   no row for a country is simply not offered in it. */
if ($action === 'set_country_value') {
    $promoId = (int)($_POST['promo_id'] ?? 0);
    $country = strtoupper(trim((string)($_POST['country_code'] ?? '')));

    if ($promoId <= 0 || !preg_match('/^[A-Z]{2}$/', $country)) {
        echo json_encode(['success' => false, 'message' => 'Invalid promo or country.']);
        exit;
    }

    // The country must be one the shop actually sells to, and not the home one
    // (home uses the base figures — that is the currency they were typed in).
    $enabled = allStoreCountries()[$country] ?? null;
    if (!$enabled || empty($enabled['is_enabled']) || !empty($enabled['is_home'])) {
        echo json_encode(['success' => false, 'message' => 'That country is not one you sell to.']);
        exit;
    }

    $rawVal = trim((string)($_POST['discount_value'] ?? ''));
    $rawMin = trim((string)($_POST['min_order'] ?? ''));

    try {
        // Both blank = remove the override entirely, so the code stops being
        // offered in that country rather than falling back to a rupee figure.
        if ($rawVal === '' && $rawMin === '') {
            $pdo->prepare("DELETE FROM promo_country_values WHERE promo_id = :p AND country_code = :c")
                ->execute(['p' => $promoId, 'c' => $country]);
            echo json_encode(['success' => true, 'cleared' => true]);
            exit;
        }

        // A minimum with no amount off is not a usable code — the shopper would
        // qualify for nothing. Refused rather than stored as a silent zero.
        if ($rawVal === '' || (float)$rawVal <= 0) {
            echo json_encode(['success' => false, 'message' => 'Enter the amount off for this country, or clear both boxes to withdraw the code there.']);
            exit;
        }

        $pdo->prepare(
            "INSERT INTO promo_country_values (promo_id, country_code, discount_value, min_order)
             VALUES (:p, :c, :v, :m)
             ON DUPLICATE KEY UPDATE discount_value = VALUES(discount_value), min_order = VALUES(min_order)"
        )->execute([
            'p' => $promoId,
            'c' => $country,
            'v' => max(0, (float)$rawVal),
            'm' => $rawMin === '' ? 0 : max(0, (float)$rawMin),
        ]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log('promo_country_values save failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not save that value.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
