<?php
// ============================================================
//  Dievon – Admin: Repair the Category Tree
//
//  For the case where every category has come out as a MAIN category and every
//  sub-category count reads zero.
//
//  That is a data problem rather than a code one. categories.parent_id is added
//  automatically by admin/categories.php if it is missing:
//
//      ALTER TABLE categories ADD COLUMN parent_id INT DEFAULT 0;
//
//  Every existing row therefore gets parent_id = 0 — and parent_id = 0 IS the
//  definition of a main category. So on an install where that column arrived
//  after the categories did, the whole hierarchy flattens: the counts are
//  telling the truth about a tree that no longer has any branches.
//
//  This page re-attaches them. Safety properties, all deliberate and all
//  matching update_new_database.php:
//
//   * Owner-level access required (catalogue.manage), checked before output.
//   * Nothing runs on GET. The page only ever REPORTS until you press Apply.
//   * Nothing is destructive. It only ever sets parent_id. No category is
//     created, renamed or deleted, and no other column is touched.
//   * You confirm every row. Suggestions are pre-selected, never auto-applied.
//   * Idempotent. Re-running with the same choices changes nothing.
//   * One transaction, so a failure part-way leaves the tree exactly as it was.
//   * Two levels only — a parent must itself be top-level, which is what the
//     mega menu and the shop's category lookups assume.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('catalogue.manage');

$activeTab       = 'categories';
$hideHeaderTitle = true;

$successMsg = '';
$errorMsg   = '';
$applied    = [];

// ── Apply (POST only) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_tree') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Your session has expired. Refresh the page and try again.';
    } else {
        $assign = $_POST['parent_for'] ?? [];
        if (!is_array($assign)) { $assign = []; }

        try {
            // Read the current tree once, inside the request that will change it,
            // so the validation below cannot be fooled by a stale page left open
            // in another tab.
            $rows = $pdo->query("SELECT id, name, parent_id FROM categories")->fetchAll(PDO::FETCH_ASSOC);
            $byId = [];
            foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }

            $topLevelIds = [];
            foreach ($rows as $r) {
                if ((int)($r['parent_id'] ?? 0) === 0) { $topLevelIds[] = (int)$r['id']; }
            }

            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE categories SET parent_id = :pid WHERE id = :id");

            foreach ($assign as $childId => $parentId) {
                $childId  = (int)$childId;
                $parentId = (int)$parentId;

                if (!isset($byId[$childId]))            { continue; }   // vanished since page load
                if ($parentId === 0)                    { continue; }   // "leave as main" — no write at all
                if ($parentId === $childId)             { continue; }   // a category cannot parent itself
                if (!isset($byId[$parentId]))           { continue; }   // parent no longer exists
                // The chosen parent must itself be top-level. Without this you could
                // build a three-deep chain, which the mega menu and the shop's
                // category lookups do not walk — the products would simply vanish
                // from the site while still looking correctly filed in here.
                if ((int)($byId[$parentId]['parent_id'] ?? 0) !== 0) { continue; }
                // Nothing to do if it is already filed there.
                if ((int)($byId[$childId]['parent_id'] ?? 0) === $parentId) { continue; }
                // Refuse to re-parent something that is itself already a parent,
                // for the same depth reason.
                $hasOwnChildren = false;
                foreach ($rows as $r2) {
                    if ((int)($r2['parent_id'] ?? 0) === $childId) { $hasOwnChildren = true; break; }
                }
                if ($hasOwnChildren) { continue; }

                $upd->execute([':pid' => $parentId, ':id' => $childId]);
                $applied[] = $byId[$childId]['name'] . ' → ' . $byId[$parentId]['name'];
            }

            $pdo->commit();

            if ($applied) {
                logAdminAction($_SESSION['admin_id'] ?? 1, 'category_tree_repaired',
                    count($applied) . ' category(ies) re-parented: ' . implode(', ', $applied));
                $successMsg = count($applied) . ' category(ies) re-filed. The counts on the Categories page should now be correct.';
            } else {
                $successMsg = 'Nothing needed changing — every category is already filed where you asked.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('repair_category_tree: ' . $e->getMessage());
            $errorMsg = 'Could not apply the changes. Nothing was altered.';
        }
    }
}

// ── Read the current state ──────────────────────────────────────────────────
$cats = [];
try {
    $cats = $pdo->query("SELECT id, name, parent_id, gender FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $errorMsg = $errorMsg ?: 'Could not read the categories table.'; }

$tops = [];
$subs = [];
foreach ($cats as $c) {
    if ((int)($c['parent_id'] ?? 0) === 0) { $tops[] = $c; } else { $subs[] = $c; }
}

// Which top-level categories are parents of something? Those are certainly
// meant to be main categories and are not offered for re-filing.
$isParentOf = [];
foreach ($cats as $c) {
    $p = (int)($c['parent_id'] ?? 0);
    if ($p > 0) { $isParentOf[$p] = true; }
}

/**
 * A suggested parent for a category that currently sits at the top level.
 *
 * Deliberately conservative — it only suggests where the name itself carries
 * the answer, and returns 0 (leave alone) for everything else. A wrong
 * suggestion that gets accepted without reading is worse than no suggestion,
 * so this would rather stay quiet than guess.
 */
function suggestParentId(array $cat, array $tops): int {
    $name = strtolower(trim((string)$cat['name']));
    $selfId = (int)$cat['id'];

    // The set this shop shipped with originally — these belong under Kurtis.
    // (admin/categories.php used to recreate them on every page load; that
    // block is gone, so this is only a naming hint for the suggestions below.)
    $knownKurtiSubs = ['short kurtis', 'long kurtis', 'anarkali sets',
                       'sharara & gharara sets', 'straight cut kurtis'];

    $findTop = function (array $needles) use ($tops, $selfId): int {
        foreach ($tops as $t) {
            if ((int)$t['id'] === $selfId) { continue; }
            $tn = strtolower(trim((string)$t['name']));
            foreach ($needles as $n) {
                if ($tn === $n) { return (int)$t['id']; }
            }
        }
        return 0;
    };

    if (in_array($name, $knownKurtiSubs, true)) {
        return $findTop(['kurtis', 'kurtas']);
    }
    // A name that ends in a main category's name — "Straight Cut Kurtis" under
    // "Kurtis" — but only when it is genuinely longer, so "Kurtis" never
    // suggests itself.
    foreach ($tops as $t) {
        if ((int)$t['id'] === $selfId) { continue; }
        $tn = strtolower(trim((string)$t['name']));
        if ($tn !== '' && $name !== $tn && str_ends_with($name, ' ' . $tn)) {
            return (int)$t['id'];
        }
    }
    return 0;
}

// Only top-level rows that are not themselves parents are candidates.
$candidates = [];
foreach ($tops as $t) {
    if (!empty($isParentOf[(int)$t['id']])) { continue; }
    $candidates[] = $t + ['suggested' => suggestParentId($t, $tops)];
}
$suggestedCount = count(array_filter($candidates, fn($c) => $c['suggested'] > 0));

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="margin-bottom:22px;">
    <h1 class="admin-page-title"><i class="fa-solid fa-folder-tree"></i> Repair Category Tree</h1>
    <p class="admin-page-subtitle">
        Re-file sub-categories under their main category. Only <code>parent_id</code> is changed —
        nothing is created, renamed or deleted.
    </p>
</div>

<?php if ($successMsg): ?>
<div class="alert alert-success" style="margin-bottom:20px">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?>
    <?php if ($applied): ?>
    <ul style="margin:8px 0 0 18px; font-size:13px;">
        <?php foreach ($applied as $line): ?><li><?= htmlspecialchars($line) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div style="margin-top:10px;"><a href="categories.php" style="font-weight:600;">Back to Categories &rarr;</a></div>
</div>
<?php endif; ?>

<?php if ($errorMsg): ?>
<?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<!-- ── Diagnosis ─────────────────────────────────────────── -->
<div class="glass-panel" style="padding:24px; margin-bottom:22px;">
    <h3 style="margin:0 0 14px; font-size:15px; text-transform:uppercase; letter-spacing:.05em;">What the database says right now</h3>
    <div class="rct-diag">
        <div class="rct-diag-item"><span class="rct-diag-num"><?= count($cats) ?></span><span>categories in total</span></div>
        <div class="rct-diag-item"><span class="rct-diag-num"><?= count($tops) ?></span><span>filed as MAIN (parent_id = 0)</span></div>
        <div class="rct-diag-item"><span class="rct-diag-num <?= empty($subs) ? 'is-bad' : '' ?>"><?= count($subs) ?></span><span>filed as SUB</span></div>
    </div>

    <?php if (empty($subs)): ?>
    <p class="rct-note is-bad">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong>No sub-categories exist at all.</strong> This is exactly the flattened state described above —
        every sub-category count on the Categories page will read zero, and it is reporting the tree honestly.
        Choose a parent for each row below to rebuild it.
    </p>
    <?php else: ?>
    <p class="rct-note">
        <i class="fa-solid fa-circle-check"></i>
        The tree has <?= count($subs) ?> sub-categor<?= count($subs) === 1 ? 'y' : 'ies' ?>, so it is not flattened.
        You can still use this page to file any stray category below.
    </p>
    <?php endif; ?>
</div>

<!-- ── Repair ────────────────────────────────────────────── -->
<div class="glass-panel" style="padding:24px;">
    <h3 style="margin:0 0 6px; font-size:15px; text-transform:uppercase; letter-spacing:.05em;">Choose where each one belongs</h3>
    <p style="font-size:13px; color:var(--text-secondary); margin:0 0 18px;">
        Anything left on <strong>“Keep as a main category”</strong> is not touched.
        <?php if ($suggestedCount): ?>
            <?= $suggestedCount ?> suggestion<?= $suggestedCount === 1 ? ' has' : 's have' ?> been pre-selected from the
            category names — check them before applying.
        <?php endif; ?>
    </p>

    <?php if (empty($candidates)): ?>
        <p class="rct-note">Every main category already has sub-categories under it. There is nothing to re-file.</p>
    <?php else: ?>
    <?php /* dvConfirmForm, not the browser's confirm().
             ────────────────────────────────────────────────────────────────
             This page asked with window.confirm, which draws the grey OS box
             titled with the site's address — the one every other screen here
             was moved off, because it reads as a browser security warning
             rather than part of the panel. dvConfirmForm is what newsletter,
             brands, attributes and media_cleanup already use.

             danger:false because this is not a deletion — the defaults are
             tuned for one, and a red "Delete" button on a repair that only
             sets parent_id would say the opposite of what it does. */ ?>
    <form method="POST" action="repair_category_tree.php"
          onsubmit="return dvConfirmForm(this, 'Apply these changes?\n\nOnly parent_id is updated — nothing is deleted.', { danger: false, confirmText: 'Apply changes', type: 'confirm' });">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="action" value="apply_tree">

        <div class="table-wrapper">
            <table class="data-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th>Category</th>
                        <th style="width:300px;">File it under</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($candidates as $c): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;">#<?= (int)$c['id'] ?></td>
                        <td style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></td>
                        <td>
                            <select name="parent_for[<?= (int)$c['id'] ?>]" class="form-control">
                                <option value="0">Keep as a main category</option>
                                <?php foreach ($tops as $t):
                                    if ((int)$t['id'] === (int)$c['id']) { continue; }
                                ?>
                                <option value="<?= (int)$t['id'] ?>" <?= (int)$c['suggested'] === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['name']) ?><?= (int)$c['suggested'] === (int)$t['id'] ? '  (suggested)' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:20px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Apply changes
            </button>
            <a href="categories.php" style="font-size:13px; color:var(--text-muted);">Cancel and go back</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<style>
.rct-diag { display:flex; gap:26px; flex-wrap:wrap; margin-bottom:14px; }
.rct-diag-item { display:flex; align-items:baseline; gap:8px; font-size:13px; color:var(--text-secondary); }
.rct-diag-num { font-size:24px; font-weight:700; color:var(--text-primary); font-variant-numeric:tabular-nums; }
.rct-diag-num.is-bad { color:var(--color-danger); }
.rct-note { font-size:13px; line-height:1.6; color:var(--text-secondary); background:var(--bg-surface-soft);
            border:1px solid var(--border-light); border-radius:8px; padding:12px 15px; margin:0; }
.rct-note.is-bad { border-color:rgba(169,68,66,.35); background:rgba(169,68,66,.06); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
