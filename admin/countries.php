<?php
// ============================================================
//  Dievon – Admin: Countries We Sell To
//
//  A country is invisible to shoppers until its row is enabled here. Enabling
//  the second country is what makes the country chooser appear on the shop —
//  there is no separate "turn on international" switch to forget.
// ============================================================
// Owner only, checked before the header renders so a refusal is a real 403.
// Enabling a country changes the currency shoppers are charged in — that sits
// with refunds and pricing as an owner decision.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('settings.manage');

$activeTab = 'countries';
$hideHeaderTitle = true;
require_once 'includes/header.php';

$countries = [];
try {
    $countries = $pdo->query("SELECT * FROM store_countries ORDER BY sort_order ASC, country_name ASC")->fetchAll();
} catch (PDOException $e) {}

// How many products have a price for each country — the number that decides
// whether enabling it would actually show a shopper anything.
$pricedCounts = [];
try {
    foreach ($pdo->query("SELECT country_code, COUNT(*) c FROM product_country_prices GROUP BY country_code") as $r) {
        $pricedCounts[strtoupper($r['country_code'])] = (int)$r['c'];
    }
} catch (PDOException $e) {}

$totalProducts = 0;
try { $totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_deleted = 0")->fetchColumn(); } catch (PDOException $e) {}

$enabledCount = 0;
foreach ($countries as $c) { if ((int)$c['is_enabled'] === 1) { $enabledCount++; } }
?>

<div class="glass-panel" style="padding:28px;">
    <div class="admin-page-header" style="margin-bottom:24px;">
        <div>
            <h2 class="admin-page-title" style="margin:0;"><i class="fa-solid fa-earth-asia"></i> Countries We Sell To</h2>
            <p class="admin-page-subtitle" style="margin-top:4px;">
                Enable a country only when you are genuinely ready to ship there.
                <?php if ($enabledCount <= 1): ?>
                    Right now the shop is <strong>India only</strong> and no country chooser is shown.
                <?php else: ?>
                    <strong><?= $enabledCount ?> countries</strong> are live, so shoppers see the country chooser.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div id="countryMsg" class="cn-msg" hidden></div>

    <div class="cn-note">
        <strong>Prices are set per country, not converted.</strong>
        A product with no price for a country is simply not sold there — that is also how you keep a piece India-only.
        Before enabling anywhere new: Razorpay international payments must be active, you need an LUT filed for zero-rated exports,
        and you must decide who pays customs duty. A surprise duty bill on delivery is the main reason international parcels get refused.
    </div>

    <div class="table-wrapper">
        <table class="data-table cn-table">
            <thead>
                <tr>
                    <th>Country</th>
                    <th>Currency</th>
                    <th>Symbol</th>
                    <th>Delivery charge</th>
                    <th>Free over</th>
                    <th>COD</th>
                    <th>Products priced</th>
                    <th style="text-align:right;">Selling here</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($countries as $c):
                $code    = strtoupper($c['country_code']);
                $isHome  = (int)$c['is_home'] === 1;
                $priced  = $pricedCounts[$code] ?? 0;
                // The home country prices every product through products.price,
                // so its "priced" count is always the full catalogue.
                $shown   = $isHome ? $totalProducts : $priced;
            ?>
                <tr data-code="<?= htmlspecialchars($code) ?>">
                    <td>
                        <strong><?= htmlspecialchars($c['country_name']) ?></strong>
                        <?php if ($isHome): ?><span class="cn-pill cn-pill-home">HOME</span><?php endif; ?>
                    </td>
                    <td><input type="text" class="form-control cn-in cn-in-sm" data-f="currency_code" value="<?= htmlspecialchars($c['currency_code']) ?>" maxlength="3"></td>
                    <td><input type="text" class="form-control cn-in cn-in-xs" data-f="currency_symbol" value="<?= htmlspecialchars($c['currency_symbol']) ?>" maxlength="8"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control cn-in cn-in-md" data-f="shipping_fee" value="<?= htmlspecialchars($c['shipping_fee']) ?>"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control cn-in cn-in-md" data-f="free_shipping_min" value="<?= htmlspecialchars($c['free_shipping_min']) ?>"></td>
                    <td>
                        <?php if ($isHome): ?>
                            <label class="cn-check"><input type="checkbox" class="cn-in" data-f="cod_allowed" <?= (int)$c['cod_allowed'] ? 'checked' : '' ?>> allow</label>
                        <?php else: ?>
                            <span class="cn-muted" title="Cash cannot be collected by an international courier">not possible</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isHome): ?>
                            <span class="cn-muted">all <?= $totalProducts ?></span>
                        <?php else: ?>
                            <span class="<?= $shown === 0 ? 'cn-warn' : '' ?>"><?= $shown ?> of <?= $totalProducts ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if ($isHome): ?>
                            <span class="cn-pill cn-pill-on">Always on</span>
                        <?php else: ?>
                            <label class="cn-switch">
                                <input type="checkbox" class="cn-in cn-enable" data-f="is_enabled" <?= (int)$c['is_enabled'] ? 'checked' : '' ?>
                                       data-priced="<?= $shown ?>" data-name="<?= htmlspecialchars($c['country_name']) ?>">
                                <span class="cn-slider"></span>
                            </label>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="cn-actions">
        <button type="button" class="btn-primary" onclick="saveCountries()"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        <span id="cnDirty" class="cn-muted" hidden>Unsaved changes</span>
    </div>
</div>

<script>
const CN_CSRF = '<?= generateCsrfToken() ?>';

function cnMsg(text, ok) {
    const el = document.getElementById('countryMsg');
    el.textContent = text;
    el.className = 'cn-msg ' + (ok ? 'is-ok' : 'is-err');
    el.hidden = false;
}

document.addEventListener('change', e => {
    if (!e.target.classList.contains('cn-in')) { return; }
    document.getElementById('cnDirty').hidden = false;

    // Enabling a country with no priced products would show a shopper an empty
    // shop. Warn at the moment of the click rather than after saving.
    if (e.target.classList.contains('cn-enable') && e.target.checked
        && parseInt(e.target.dataset.priced, 10) === 0) {
        cnMsg('Note: no products have a price for ' + e.target.dataset.name +
              ' yet, so the shop would look empty there. Set prices on the product pages first.', false);
    }
});

function saveCountries() {
    const rows = [...document.querySelectorAll('.cn-table tbody tr')].map(tr => {
        const o = { country_code: tr.dataset.code };
        tr.querySelectorAll('.cn-in').forEach(i => {
            o[i.dataset.f] = (i.type === 'checkbox') ? (i.checked ? 1 : 0) : i.value;
        });
        return o;
    });

    const fd = new FormData();
    fd.append('action', 'save_countries');
    fd.append('csrf_token', CN_CSRF);
    fd.append('rows', JSON.stringify(rows));

    fetch('country_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            cnMsg(d.message, d.success);
            if (d.success) {
                document.getElementById('cnDirty').hidden = true;
                setTimeout(() => window.location.reload(), 900);
            }
        })
        .catch(() => cnMsg('Could not reach the server. Nothing was saved.', false));
}
</script>

<?php require_once 'includes/footer.php'; ?>
