<?php
// ============================================================
//  Dievon – Admin: Countries We Sell To (handler)
//
//  Owner only. Enabling a country changes the currency shoppers are charged in,
//  which sits alongside refunds and pricing as an owner decision.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

requireAdminCapability('settings.manage', true);

function cnFail(string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cnFail('Invalid request method.', 405); }
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    cnFail('Your session has expired. Please refresh the page and try again.', 419);
}
if (($_POST['action'] ?? '') !== 'save_countries') { cnFail('Unknown action.'); }

$rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
if (!is_array($rows) || !$rows) { cnFail('Nothing to save.'); }

try {
    $pdo->beginTransaction();

    // Read the current state once so the home-country rules can be checked
    // against what is actually stored rather than what the browser sent.
    $current = [];
    foreach ($pdo->query("SELECT * FROM store_countries") as $r) {
        $current[strtoupper($r['country_code'])] = $r;
    }

    $saved = 0;
    foreach ($rows as $r) {
        $code = strtoupper(trim((string)($r['country_code'] ?? '')));
        if ($code === '' || !isset($current[$code])) { continue; }

        $isHome = ((int)$current[$code]['is_home'] === 1);

        // The home country is always on sale. Switching it off would leave the
        // shop unable to sell anywhere, and currentCountryCode() falls back to
        // it — so a disabled home country is an unreachable state, not a choice.
        $enabled = $isHome ? 1 : (!empty($r['is_enabled']) ? 1 : 0);

        // Cash on delivery is only ever possible at home: an international
        // courier cannot collect cash. The page renders this as text rather
        // than a control for other countries; enforced here regardless.
        $cod = $isHome ? (!empty($r['cod_allowed']) ? 1 : 0) : 0;

        // Validate what was SUBMITTED, then use it. Truncating to three
        // characters first made the check meaningless: "POUNDS" became "POU",
        // which matches /^[A-Z]{3}$/ perfectly and sailed through — the shop was
        // saved as trading in a currency that does not exist.
        $currency = strtoupper(trim((string)($r['currency_code'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $pdo->rollBack();
            cnFail("“{$code}” needs a three-letter currency code such as INR or GBP — “"
                 . htmlspecialchars((string)($r['currency_code'] ?? '')) . "” is not one.");
        }

        $symbol = trim((string)($r['currency_symbol'] ?? ''));
        if ($symbol === '') {
            $pdo->rollBack();
            cnFail("“{$code}” needs a currency symbol.");
        }

        $fee  = max(0, (float)($r['shipping_fee'] ?? 0));
        $free = max(0, (float)($r['free_shipping_min'] ?? 0));

        $pdo->prepare(
            "UPDATE store_countries
                SET currency_code = :cur, currency_symbol = :sym, is_enabled = :en,
                    cod_allowed = :cod, shipping_fee = :fee, free_shipping_min = :free
              WHERE country_code = :code"
        )->execute([
            ':cur' => $currency, ':sym' => $symbol, ':en' => $enabled,
            ':cod' => $cod, ':fee' => $fee, ':free' => $free, ':code' => $code,
        ]);
        $saved++;
    }

    // At least one country must remain on sale. The home country is forced on
    // above, so this can only trip if the home flag is missing from the data —
    // worth catching rather than committing a shop that sells nowhere.
    $stillOn = (int)$pdo->query("SELECT COUNT(*) FROM store_countries WHERE is_enabled = 1")->fetchColumn();
    if ($stillOn === 0) {
        $pdo->rollBack();
        cnFail('At least one country must remain enabled, otherwise the shop cannot sell anywhere.');
    }

    $pdo->commit();

    $enabledNames = $pdo->query(
        "SELECT country_name FROM store_countries WHERE is_enabled = 1 ORDER BY sort_order"
    )->fetchAll(PDO::FETCH_COLUMN);

    logAdminAction($_SESSION['admin_id'] ?? 1, 'countries_saved',
        'Selling in: ' . implode(', ', $enabledNames));

    echo json_encode([
        'success' => true,
        'message' => "Saved {$saved} countr" . ($saved === 1 ? 'y' : 'ies') . '. Now selling in: '
                   . implode(', ', $enabledNames) . '.',
    ]);
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('country_handler: ' . $e->getMessage());
    cnFail('Something went wrong. Nothing was changed.');
}
