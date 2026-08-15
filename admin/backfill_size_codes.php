<?php
// ============================================================
//  Dievon – Admin: Backfill size_code on plain product sizes
//
//  Product sizes used to be free text. A row said "Size: M" and nothing else,
//  so the shop had no way to know that this was the same size as a colour
//  variant's "34/M", and no way to order XS before XL except by the order the
//  sizes happened to be typed in.
//
//  product_variants.size_code is that missing key. Colour variants have carried
//  it from the start; plain ones did not, because the old panel only ever asked
//  for a name. This page fills it in from the name that is already there.
//
//  What it earns, once every row has one:
//   * The buy box lists sizes in ladder order, not entry order.
//   * The shop's size filter shows ONE chip per size — "XS" finds a product
//     whose variant is named "30/XS" as well as one named "Size: XS".
//   * The size guide can match a size to its measurements.
//
//  Safety properties, matching repair_category_tree.php:
//
//   * Owner-level access required (catalogue.manage), checked before output.
//   * Nothing runs on GET. The page only ever REPORTS until you press Apply.
//   * Only size_code is written. Names, prices, stock and availability are
//     never touched, so nothing on the storefront changes wording.
//   * Rows that already have a size_code are left alone entirely.
//   * A name the ladder does not recognise — "Free Size", "500ml" — is listed
//     and skipped rather than guessed at.
//   * Idempotent. Running it twice does nothing the second time.
//   * One transaction, so a failure part-way leaves every row as it was.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('catalogue.manage');

$activeTab       = 'products';
$hideHeaderTitle = true;

$successMsg = '';
$errorMsg   = '';

/**
 * The ladder code a stored size name refers to, or null when nothing fits.
 *
 * Longest code first so that "XS" is never swallowed by "S", nor "5XL" by "XL" —
 * matching short-first would file every extra-small under S.
 */
function dievonSizeCodeFromName(string $name, array $codes): ?string {
    $clean = strtoupper(trim(preg_replace('/^\s*size\s*:\s*/i', '', $name)));
    if ($clean === '') { return null; }

    foreach ($codes as $code) {
        if ($clean === strtoupper($code)) { return $code; }
    }

    // "30/XS" and "46/5XL" — the ladder label rather than the bare code.
    if (preg_match('~^\d+\s*/\s*(.+)$~', $clean, $m)) {
        $tail = trim($m[1]);
        foreach ($codes as $code) {
            if ($tail === strtoupper($code)) { return $code; }
        }
    }

    return null;
}

// Both ladders share one set of codes, so either file answers the question.
$ladderCodes = array_column(require __DIR__ . '/../config/size_ladder.php', 'code');
usort($ladderCodes, static fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));

// ── Read the rows still missing a code ──────────────────────────────────────
$pending  = [];
$skipped  = [];
$totalRows = 0;
$alreadyOk = 0;

try {
    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE color_id IS NULL")->fetchColumn();
    $alreadyOk = (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE color_id IS NULL AND size_code IS NOT NULL AND size_code <> ''")->fetchColumn();

    $rows = $pdo->query(
        "SELECT v.id, v.product_id, v.name, p.name AS product_name
           FROM product_variants v
           LEFT JOIN products p ON p.id = v.product_id
          WHERE v.color_id IS NULL
            AND (v.size_code IS NULL OR v.size_code = '')
          ORDER BY v.product_id ASC, v.sort_order ASC, v.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $code = dievonSizeCodeFromName((string)$r['name'], $ladderCodes);
        if ($code !== null) { $r['code'] = $code; $pending[] = $r; }
        else                { $skipped[] = $r; }
    }
} catch (PDOException $e) {
    $errorMsg = 'Could not read the sizes: ' . $e->getMessage();
}

// ── Apply (POST only) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_size_codes') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Your session has expired. Refresh the page and try again.';
    } elseif (!$pending) {
        $successMsg = 'Nothing to do — every plain size already has a code.';
    } else {
        try {
            $pdo->beginTransaction();

            // Re-derived inside the transaction rather than trusting the form, so a
            // page left open in another tab cannot write a code for a size that has
            // since been renamed or deleted.
            $fresh = $pdo->query(
                "SELECT id, name FROM product_variants
                  WHERE color_id IS NULL AND (size_code IS NULL OR size_code = '')"
            )->fetchAll(PDO::FETCH_ASSOC);

            $upd = $pdo->prepare("UPDATE product_variants SET size_code = :c WHERE id = :id");
            $done = 0;
            foreach ($fresh as $f) {
                $code = dievonSizeCodeFromName((string)$f['name'], $ladderCodes);
                if ($code === null) { continue; }
                $upd->execute([':c' => $code, ':id' => (int)$f['id']]);
                $done++;
            }

            $pdo->commit();

            logAdminAction(
                $_SESSION['admin_id'] ?? 1,
                'size_codes_backfilled',
                sprintf('%d plain size row(s) given a size_code', $done)
            );

            $successMsg = $done . ' size(s) updated. They now sort by the ladder and answer the shop size filter.';

            // Reload so the report reflects what is actually stored now.
            $pending = [];
            $rows = $pdo->query(
                "SELECT v.id, v.product_id, v.name, p.name AS product_name
                   FROM product_variants v
                   LEFT JOIN products p ON p.id = v.product_id
                  WHERE v.color_id IS NULL AND (v.size_code IS NULL OR v.size_code = '')
                  ORDER BY v.product_id ASC, v.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $skipped = $rows;
            $alreadyOk = (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE color_id IS NULL AND size_code IS NOT NULL AND size_code <> ''")->fetchColumn();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errorMsg = 'Nothing was changed — the update failed: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="margin-bottom:22px;">
    <h1 class="admin-page-title">📏 Backfill Size Codes</h1>
    <p class="admin-page-subtitle">
        Gives every plain product size its ladder code, so sizes sort correctly and the shop
        size filter can find them. Names, prices and stock are not touched.
    </p>
</div>

<?php if ($successMsg): ?>
<?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>
<?php if ($errorMsg): ?>
<?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<div class="glass-panel" style="padding:24px; margin-bottom:22px;">
    <h3 style="font-size:16px; font-weight:700; margin-bottom:14px;">Where things stand</h3>
    <div style="display:flex; gap:26px; flex-wrap:wrap; font-size:14px;">
        <div><strong style="font-size:22px;"><?= (int)$totalRows ?></strong><br><span style="color:var(--text-muted);">plain sizes in total</span></div>
        <div><strong style="font-size:22px;"><?= (int)$alreadyOk ?></strong><br><span style="color:var(--text-muted);">already have a code</span></div>
        <div><strong style="font-size:22px; color:var(--color-secondary);"><?= count($pending) ?></strong><br><span style="color:var(--text-muted);">ready to fill in</span></div>
        <div><strong style="font-size:22px;"><?= count($skipped) ?></strong><br><span style="color:var(--text-muted);">not a ladder size</span></div>
    </div>
</div>

<?php if ($pending): ?>
<div class="glass-panel" style="padding:24px; margin-bottom:22px;">
    <h3 style="font-size:16px; font-weight:700; margin-bottom:6px;">These will be filled in</h3>
    <p style="font-size:13px; color:var(--text-secondary); margin-bottom:16px;">
        Only the <strong>Code</strong> column is written. The name stays exactly as it is,
        so nothing on the website changes wording.
    </p>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead><tr><th>Product</th><th>Size name</th><th>Code to set</th></tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
                <tr>
                    <td>#<?= (int)$p['product_id'] ?> <?= htmlspecialchars((string)($p['product_name'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)$p['name']) ?></td>
                    <td><strong><?= htmlspecialchars((string)$p['code']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="POST" style="margin-top:18px;">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="action" value="apply_size_codes">
        <button type="submit" class="btn-primary" style="padding:11px 22px;"
            onclick="return dvConfirmForm(this.form, 'Fill in <?= count($pending) ?> size code(s)?\n\nOnly size_code is written — no name, price or stock is changed.', { danger: false, type: 'confirm', confirmText: 'Fill in codes' });">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Apply <?= count($pending) ?> size code(s)
        </button>
    </form>
</div>
<?php elseif (!$errorMsg): ?>
<div class="glass-panel" style="padding:24px; margin-bottom:22px;">
    <p style="margin:0; font-size:14px;"><i class="fa-solid fa-circle-check" style="color:var(--color-success);"></i>
        Every plain size already has a ladder code. There is nothing to do here.</p>
</div>
<?php endif; ?>

<?php if ($skipped): ?>
<div class="glass-panel" style="padding:24px;">
    <h3 style="font-size:16px; font-weight:700; margin-bottom:6px;">Left alone</h3>
    <p style="font-size:13px; color:var(--text-secondary); margin-bottom:16px;">
        These names do not match any rung of the ladder, so no code is guessed for them.
        That is correct for a genuine one-off like &ldquo;Free Size&rdquo; or &ldquo;500ml&rdquo;.
        If one of these <em>is</em> a garment size, rename it on the product form and run this again.
    </p>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead><tr><th>Product</th><th>Size name</th></tr></thead>
            <tbody>
            <?php foreach ($skipped as $s): ?>
                <tr>
                    <td>#<?= (int)$s['product_id'] ?> <?= htmlspecialchars((string)($s['product_name'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)$s['name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
