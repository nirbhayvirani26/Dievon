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
/* Suggest a starting price for every product in one country.
   ────────────────────────────────────────────────────────────────────────────
   The product form already has "Convert all from ₹", but it fills ONE product.
   At 16 products and three countries that is 48 prices typed by hand, and the
   number only grows — which is the reason a shop puts off selling abroad.

   Same rule as that button, applied across the catalogue: the rate SUGGESTS a
   figure, and what gets stored is an ordinary price the owner can edit. No rate
   is ever applied to a price a shopper sees, so a rate moving overnight still
   cannot change what a garment costs.

   Two things it will not do. It never touches a product that already has a price
   for the country — a considered price must not be flattened by one click. And
   it does one country per press, because rounding and what a market bears differ
   per country, and a button that fills everything everywhere is one you press
   once and regret. */
if (($_POST['action'] ?? '') === 'suggest_prices') {
    $code = strtoupper(trim((string)($_POST['country_code'] ?? '')));
    $home = strtoupper((string)(homeCountryRow()['country_code'] ?? 'IN'));

    if ($code === '' || $code === $home) {
        cnFail('Choose a country other than the home country.');
    }

    $row = null;
    foreach (enabledCountries() as $cc => $c) {
        if (strtoupper((string)$cc) === $code) { $row = $c; break; }
    }
    if ($row === null) { cnFail('That country is not enabled.'); }

    $rate = (float)($row['fx_rate'] ?? 0);
    if ($rate <= 0) { cnFail('No exchange rate on record for ' . $code . '. Refresh the rates first, or type a rate in.'); }

    try {
        $live = $pdo->query(
            "SELECT id, price, mrp_price FROM products
              WHERE COALESCE(is_deleted, 0) = 0 AND price > 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        $already = $pdo->prepare("SELECT product_id FROM product_country_prices WHERE country_code = :cc");
        $already->execute([':cc' => $code]);
        $skip = array_flip(array_map('intval', $already->fetchAll(PDO::FETCH_COLUMN)));

        $ins = $pdo->prepare(
            "INSERT INTO product_country_prices (product_id, country_code, price, sale_price)
             VALUES (:pid, :cc, :price, :sale)"
        );

        $pdo->beginTransaction();
        $filled = 0;
        foreach ($live as $p) {
            if (isset($skip[(int)$p['id']])) { continue; }

            /* Rounded UP to a whole unit, matching dievonFxAmounts() in the
               product form. 7230 INR at 0.00766 is 55.38: rounding down gives
               away margin on every sale, up costs the shopper 62 paise. The two
               must agree, or the same product priced two ways lands on two
               different numbers. */
            $inrPrice = (float)$p['price'];
            $inrMrp   = (float)($p['mrp_price'] ?? 0);
            $price = ceil(($inrMrp > $inrPrice ? $inrMrp : $inrPrice) * $rate);
            $sale  = ($inrMrp > $inrPrice) ? ceil($inrPrice * $rate) : null;

            $ins->execute([':pid' => (int)$p['id'], ':cc' => $code, ':price' => $price, ':sale' => $sale]);
            $filled++;
        }
        $pdo->commit();

        logAdminAction($_SESSION['admin_id'] ?? 0, 'country_prices_suggested',
            "Suggested $filled price(s) for $code at rate $rate");

        echo json_encode([
            'success' => true,
            'filled'  => $filled,
            'skipped' => count($live) - $filled,
            'message' => $filled === 0
                ? 'Every product already has a price for ' . $code . ' — nothing was changed.'
                : $filled . ' product' . ($filled === 1 ? '' : 's') . ' given a suggested price. '
                  . (count($live) - $filled) . ' already priced and left alone. Review them under Products.',
        ]);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('suggest_prices failed: ' . $e->getMessage());
        cnFail('Could not write the prices. Nothing was changed.');
    }
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

        /* The exchange rate is optional and hand-editable.
           ────────────────────────────────────────────────────────────────────
           It only decides what the product form SUGGESTS, so a wrong one costs
           a re-type rather than a mispriced sale — but it is still worth
           refusing nonsense.

           Blank clears the stored rate, which in practice means "fetch a fresh
           one": fxRatesForCountries() treats a missing rate as due and refetches
           on the next page load. So this is the way to throw away a hand-typed
           rate and go back to the live figure, not a way to switch conversion
           off for a country.

           Home is forced to 1: it converts to itself, and a home rate of
           anything else would make its own suggestions wrong.

           Written only when the column exists, so this handler keeps working on
           an install where update_new_database.php has not been run yet. */
        $fxSet   = null;
        $fxDirty = array_key_exists('fx_rate', $r);
        if ($fxDirty) {
            $raw = trim((string)$r['fx_rate']);
            if ($isHome) {
                $fxSet = 1.0;
            } elseif ($raw === '') {
                $fxSet = null;
            } elseif (!is_numeric($raw) || (float)$raw <= 0) {
                $pdo->rollBack();
                cnFail("“{$code}” needs a positive exchange rate, or leave it blank — “"
                     . htmlspecialchars($raw) . "” is not a number.");
            } else {
                $fxSet = (float)$raw;
            }
        }

        static $hasFxColumn = null;
        if ($hasFxColumn === null) {
            try {
                $hasFxColumn = (bool)$pdo->query("SHOW COLUMNS FROM store_countries LIKE 'fx_rate'")->fetchColumn();
            } catch (PDOException $e) { $hasFxColumn = false; }
        }

        $sql = "UPDATE store_countries
                   SET currency_code = :cur, currency_symbol = :sym, is_enabled = :en,
                       cod_allowed = :cod, shipping_fee = :fee, free_shipping_min = :free";
        $args = [
            ':cur' => $currency, ':sym' => $symbol, ':en' => $enabled,
            ':cod' => $cod, ':fee' => $fee, ':free' => $free, ':code' => $code,
        ];
        if ($hasFxColumn && $fxDirty) {
            // Only stamp the time when a rate is actually present, so "never
            // fetched" stays distinguishable from "cleared just now".
            $sql .= $fxSet === null
                  ? ", fx_rate = NULL, fx_rate_updated_at = NULL"
                  : ", fx_rate = :fx, fx_rate_updated_at = NOW()";
            if ($fxSet !== null) { $args[':fx'] = $fxSet; }
        }
        $sql .= " WHERE country_code = :code";

        $pdo->prepare($sql)->execute($args);
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
