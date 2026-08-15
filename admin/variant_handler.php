<?php
// ============================================================
//  Dievon – Admin: Variant Handler
//  Actions: list | add | update | delete
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
requireAdminCapability('catalogue.manage', true);


header('Content-Type: application/json');

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

// CSRF check for every mutating action (list is read-only, safe to skip).
// This file had no check at all while the ten other handlers in this folder
// all had one — so add, update and DELETE were forgeable by any page the
// owner visited while signed in. Deleting a variant destroys its row, and
// add/update write the stock column checkout enforces against.
if ($action !== 'list') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF security token invalid or missing.']);
        exit;
    }
}

// Canonical size ladder — used only to validate an optional size_code (colour-scoped sizes)
$sizeLadder = require __DIR__ . '/../config/size_ladder.php';
$validSizeCodes = array_column($sizeLadder, 'code');

/**
 * Say so when a size price will not reach the storefront.
 *
 * effectiveVariantPrice() (config/config.php) clamps a size priced ABOVE the
 * product's own price back down to it whenever the product is on sale — that
 * is, whenever mrp_price is higher than price. The clamp is deliberate: it is
 * what stops the product page quoting a figure the bag then refuses to charge.
 *
 * What was NOT deliberate is that it happened in silence. This handler accepted
 * the number, wrote it to product_variants.price, and answered {"success":true}
 * — so the row saved, the box redrew with the typed figure still in it, and the
 * storefront went on showing the product price. Every visible signal said the
 * edit had worked. The only way to find out otherwise was to compare the size
 * box against the live page by eye, which is exactly the check nobody makes on
 * a screen that has just told them it saved.
 *
 * The row is still written unchanged — the figure is kept so that clearing the
 * MRP later brings it into effect with nothing to re-enter. This only returns
 * the sentence the admin screen shows alongside the save.
 *
 * A colour's price_override is checked FIRST, because effectiveVariantPrice()
 * returns it before it ever looks at a size's price — so on a colour that has
 * one, every per-size price under it is inert, sale or no sale. That is the
 * older of the two silences and the easier one to walk into now that those
 * cards have a price box of their own to type into.
 *
 * Returns null when the price will be charged as typed, which is the normal case.
 */
function sizePriceClampNotice(PDO $pdo, int $productId, float $enteredPrice, ?int $colorId = null): ?string
{
    // 0 is not a price, it is "follow the product" — nothing to warn about.
    if ($enteredPrice <= 0 || $productId <= 0) { return null; }

    // Checked in effectiveVariantPrice()'s order: colour override wins outright.
    if ($colorId !== null && $colorId > 0) {
        $cs = $pdo->prepare("SELECT color_name, price_override FROM product_colors WHERE id = :cid AND product_id = :pid");
        $cs->execute(['cid' => $colorId, 'pid' => $productId]);
        $crow = $cs->fetch(PDO::FETCH_ASSOC);
        /* Nothing to warn about when the two figures agree.
           This tested only that an override EXISTS, so entering the override's
           own price produced "this size will still sell at ₹1,650.00, not
           ₹1,650.00" — a warning that contradicts itself and teaches the owner
           to dismiss the ones that matter. The clamp branch below already
           compares before speaking; this one now does too. */
        if ($crow && $crow['price_override'] !== null
            && abs((float)$crow['price_override'] - $enteredPrice) > 0.005) {
            return sprintf(
                'Saved — but this size will still sell at %s, not %s. The colour “%s” has a Price Override '
                . 'of %s, and a colour override takes precedence over a per-size price, so every size in '
                . 'this colour sells at that figure. To charge %s for this size alone, clear the colour\'s '
                . 'Price Override box. Your figure is kept either way and takes effect the moment it is cleared.',
                formatPrice((float)$crow['price_override']),
                formatPrice($enteredPrice),
                (string)$crow['color_name'],
                formatPrice((float)$crow['price_override']),
                formatPrice($enteredPrice)
            );
        }
    }

    $st = $pdo->prepare("SELECT price, mrp_price FROM products WHERE id = :pid");
    $st->execute(['pid' => $productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }

    $base = (float)($row['price'] ?? 0);
    $mrp  = (float)($row['mrp_price'] ?? 0);

    // Same test as effectiveVariantPrice(), in the same order, so this cannot
    // warn about a clamp that will not happen or stay quiet about one that will.
    $onSale = $mrp > $base && $base > 0;
    if (!$onSale || $enteredPrice <= $base) { return null; }

    return sprintf(
        'Saved — but this size will still sell at %s, not %s. This product is on sale '
        . '(price %s against MRP %s), and a size dearer than the sale price is capped to it '
        . 'so the product page, the bag and the invoice cannot quote three different figures. '
        . 'To charge %s: raise the product price, or clear the MRP so the product is no longer on sale. '
        . 'Your figure is kept either way and takes effect the moment the sale ends.',
        formatPrice($base),
        formatPrice($enteredPrice),
        formatPrice($base),
        formatPrice($mrp),
        formatPrice($enteredPrice)
    );
}

/** The largest figure product_variants.price can hold: DECIMAL(10,2). */
const SIZE_PRICE_MAX = 99999999.99;

/**
 * A price outside what the column can hold is a typo, and must not be read as
 * an instruction — in either direction.
 *
 * Blank and 0 both mean "this size follows the price above", and the branches
 * below turn any figure of 0 or less into the product price to express that.
 * A NEGATIVE number fell into the same branch — so a size selling at 2,000,
 * mistyped as "-50" while correcting it, silently lost its 2,000 and went back
 * to following the product. The row was rewritten, the answer was
 * {"success":true}, and the box redrew blank on the next load: an override
 * destroyed by a slipped keystroke, with nothing on screen having objected.
 *
 * The min="0" on the input does NOT prevent this. HTML constraint validation
 * only runs when a FORM is submitted, and none of these boxes submit a form —
 * every one saves itself through fetch() on its change event, which fires with
 * the field in exactly this invalid state. Measured in a browser: value "-50",
 * checkValidity() false, rangeUnderflow true, and the request sent regardless.
 *
 * So the refusal has to be here, where the write is. Nothing is written when
 * this returns a message, which is the point — the price already stored stays
 * stored, and the caller is told which figure was rejected.
 *
 * The upper bound is the same rule, and was missed when the lower one was added.
 * price is DECIMAL(10,2) and the connection runs STRICT_TRANS_TABLES, so 100000000
 * does not round or truncate — PDO throws SQLSTATE[22003] out of range. Nothing
 * caught it, so the handler died mid-request and answered with a PHP fatal
 * instead of JSON; the caller's r.json() then threw and reported "Network error.
 * Please try again." for what is purely a validation problem, leaving the box
 * showing a figure the database had refused. Measured: 99999999.99 writes,
 * 100000000 throws. Bounded here so both ends fail the same clean way.
 */
function rejectPriceOutOfRange(bool $priceProvided, float $price): ?string
{
    if (!$priceProvided) { return null; }

    if ($price < 0) {
        return sprintf(
            'A price cannot be negative, so %s was not saved and nothing on this size was changed. '
            . 'To make this size follow the price above, clear the box or enter 0.',
            formatPrice($price)
        );
    }
    if ($price > SIZE_PRICE_MAX) {
        return sprintf(
            '%s is higher than this shop can store, so it was not saved and nothing on this size '
            . 'was changed. The most a size can be priced at is %s.',
            formatPrice($price),
            formatPrice(SIZE_PRICE_MAX)
        );
    }
    return null;
}

// ── LIST all variants for a product (optionally filtered by colour) ──
if ($action === 'list') {
    if ($productId <= 0) { echo json_encode(['success' => false]); exit; }
    $colorFilter = array_key_exists('color_id', $_POST) || array_key_exists('color_id', $_GET);
    if ($colorFilter) {
        $colorId = (int)($_POST['color_id'] ?? $_GET['color_id'] ?? 0);
        $rows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND color_id = :cid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['pid' => $productId, 'cid' => $colorId]);
    } else {
        $rows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['pid' => $productId]);
    }
    echo json_encode(['success' => true, 'variants' => $rows->fetchAll()]);
    exit;
}

// ── ADD variant ───────────────────────────────────────────────
if ($action === 'add') {
    $name  = trim($_POST['name']  ?? '');
    $price = (float)($_POST['price'] ?? 0);
    // Kept before the "0 means follow the product" fallback below rewrites it —
    // the notice has to speak about what was TYPED, not about what was stored.
    $enteredPrice = $price;

    // Optional colour-scoped fields (NULL for legacy plain-size variants)
    $colorId  = trim($_POST['color_id'] ?? '') !== '' ? (int)$_POST['color_id'] : null;
    $sizeCode = trim($_POST['size_code'] ?? '');
    $sizeCode = in_array($sizeCode, $validSizeCodes, true) ? $sizeCode : null;
    /* An empty stock box means ZERO, not "unlimited".
       ────────────────────────────────────────────────────────────────────────
       This stored NULL when the field was left blank, and NULL means "this size
       is not tracked" — which productSellableStock() and productIsInStock() both
       read as making the WHOLE product sell without limit. So adding a size and
       not typing a number quietly removed the stock ceiling from a garment that
       showed a healthy figure on the stock screen. Two products in this shop had
       exactly that: 30 units on the screen, no limit in reality.

       An empty box is far more likely to mean "none yet" than "sell for ever",
       and none-yet is the answer that cannot oversell. A shop that genuinely does
       not want to count a product turns track_stock off on the product itself,
       which is the deliberate, visible way to say it. */
    $stockQty = trim($_POST['stock_qty'] ?? '') !== '' ? max(0, (int)$_POST['stock_qty']) : 0;

    if ($productId <= 0 || strlen($name) < 1) {
        echo json_encode(['success' => false, 'message' => 'Size name is required.']);
        exit;
    }

    /* The product must exist, and the colour must belong to it.
       ────────────────────────────────────────────────────────────────────────
       Neither was checked. A size could be created against a product_id that
       has never existed — a row nothing will ever read or clean up — and, worse,
       against a colour belonging to a DIFFERENT product. The storefront's
       colour-size query selects by color_id without also filtering on
       product_id, so a size added to product A under product B's colour
       rendered on B's page as one of B's sizes. It was unbuyable, because the
       cart does check ownership, so a shopper could pick a size that then
       refused to go in the bag with no explanation on screen.

       Both are cheap lookups on a path that runs once per size added. */
    $pExists = $pdo->prepare("SELECT id FROM products WHERE id = :pid");
    $pExists->execute(['pid' => $productId]);
    if (!$pExists->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'That product no longer exists.']);
        exit;
    }
    if ($colorId !== null) {
        $cOwns = $pdo->prepare("SELECT id FROM product_colors WHERE id = :cid AND product_id = :pid");
        $cOwns->execute(['cid' => $colorId, 'pid' => $productId]);
        if (!$cOwns->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'That colour does not belong to this product.']);
            exit;
        }
    }

    // Refused before the INSERT, so a mistyped figure creates nothing.
    if ($negMsg = rejectPriceOutOfRange(array_key_exists('price', $_POST), $enteredPrice)) {
        echo json_encode(['success' => false, 'message' => $negMsg]);
        exit;
    }

    /* A blank price is stored AS ZERO — "follow the product" — not as today's
       product price copied in.
       ────────────────────────────────────────────────────────────────────────
       This looked up products.price and wrote that figure into the row, so the
       one state the column can express was the one state it could never hold.
       Tick a size on a ₹1,899 product with the price box empty and the row
       stored 1899.00; raise the product to ₹2,999 and that size stayed behind at
       ₹1,899 — a per-size override the owner never set, on a size the form still
       drew as "follows the product". Every size added this way silently detached
       itself from the product price at the moment it was created.

       effectiveVariantPrice() already resolves a stored 0 by falling through to
       the product price (config/config.php), and the form already draws a 0 as
       an empty box, so zero is the representation the rest of the system is
       written for. Nothing downstream needed changing — only this substitution
       had to stop.

       Existing rows are left alone: they hold a real figure now and rewriting
       them would be guessing which were deliberate. Clearing the price box on
       such a size stores 0 and restores the link. */
    $price = max(0.0, $price);

    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM product_variants WHERE product_id = $productId")->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, name, price, sort_order, color_id, size_code, stock_qty) VALUES (:pid, :name, :price, :sort_order, :color_id, :size_code, :stock_qty)");
    $stmt->execute([
        'pid' => $productId, 'name' => $name, 'price' => $price, 'sort_order' => $maxOrder + 1,
        'color_id' => $colorId, 'size_code' => $sizeCode, 'stock_qty' => $stockQty,
    ]);
    $response = ['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'price' => number_format($price, 2), 'stock_qty' => $stockQty];
    if ($notice = sizePriceClampNotice($pdo, $productId, $enteredPrice, $colorId)) { $response['warning'] = $notice; }
    echo json_encode($response);
    exit;
}

// ── UPDATE variant ───────────────────────────────────────────
if ($action === 'update') {
    $id    = (int)($_POST['id']    ?? 0);
    $name  = trim($_POST['name']   ?? '');
    $price = (float)($_POST['price'] ?? 0);
    // See the add branch: captured before the fallback rewrites it.
    $enteredPrice = $price;
    $avail = isset($_POST['available']) ? 1 : 0;
    /* Same rule as the add branch: a blank box is zero, not unlimited.
       $stockProvided stays as it is — a form that does not send the field at all
       leaves the stored value alone, which is what lets a caller edit a size's
       name or price without touching its stock. Only a field that IS sent and IS
       empty now resolves to 0. */
    $stockProvided = array_key_exists('stock_qty', $_POST);
    $stockQty = $stockProvided
        ? (trim((string)$_POST['stock_qty']) !== '' ? max(0, (int)$_POST['stock_qty']) : 0)
        : null;

    /* Price follows the same "was the field sent?" rule as stock above.
       ────────────────────────────────────────────────────────────────────────
       It did not, and the statement below rewrote price unconditionally — so a
       caller that had no price box to send still set one. saveColorSize() in
       admin/product_form.php is exactly that caller: the colour-size cards carry
       a label and a stock box and no price, so it posted a hard-coded price=0,
       and 0 means "follow the product price". Editing a colour size's STOCK
       therefore overwrote its price with the product's, every time, with no
       price field anywhere on that screen to show what had been lost.

       Harmless while colour-size prices were invisible; not harmless now that
       the storefront reads them (see the 'price' field in DIEVON_COLORS in
       pages/product.php). A duplicated product carries its source's size prices
       across — admin/products.php:191 copies the column — so these rows do exist
       in the wild, and one stock correction was enough to flatten them.

       Omitting the field now means "leave it alone". The two callers that DO own
       a price box, savePlainSize() and saveVariantRow(), send it on every save
       and are unaffected. */
    $priceProvided = array_key_exists('price', $_POST);

    if ($id <= 0 || strlen($name) < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }

    /* Refused before the UPDATE, so the stored price survives the typo.
       The stored figure travels back with the refusal: the box on screen still
       holds the rejected number, and a box showing one thing while the database
       holds another is the fault this whole screen has been fixed for twice. */
    if ($negMsg = rejectPriceOutOfRange($priceProvided, $enteredPrice)) {
        $cur = $pdo->prepare("SELECT price FROM product_variants WHERE id = :id AND product_id = :pid");
        $cur->execute(['id' => $id, 'pid' => $productId]);
        $stored = $cur->fetchColumn();
        echo json_encode([
            'success'       => false,
            'message'       => $negMsg,
            'stored_price'  => $stored === false || $stored === null ? '' : number_format((float)$stored, 2, '.', ''),
        ]);
        exit;
    }

    // Same rule as the add branch: an empty box means "follow the product",
    // which is stored as 0 rather than as a copy of today's product price.
    // Clearing the box on a size that carries an override is how the link is
    // restored, so this path has to be able to write a genuine zero.
    if ($priceProvided) { $price = max(0.0, $price); }

    /* Built from the fields actually sent rather than as one statement per
       combination — with price and stock each optional that is four branches of
       nearly identical SQL, which is how the two above drifted apart in the
       first place. */
    $sets   = ['name=:name', 'available=:available'];
    $params = ['name' => $name, 'available' => $avail, 'id' => $id, 'pid' => $productId];
    if ($priceProvided) { $sets[] = 'price=:price';         $params['price']     = $price; }
    if ($stockProvided) { $sets[] = 'stock_qty=:stock_qty'; $params['stock_qty'] = $stockQty; }

    /* A write that matched no row is not a save.
       ────────────────────────────────────────────────────────────────────────
       The statement is scoped `WHERE id = :id AND product_id = :pid`, so a
       mismatched pair — a size deleted in another tab, a stale page, an id that
       never existed — updates zero rows. This reported {"success":true}
       regardless, so the row flashed green and the owner was told their edit had
       been saved when nothing had been written anywhere. That exact fault is
       documented a few files away in admin/product_form.php, where saveVariantRow
       replaced a handler posting product_id=0 and silently changing nothing.

       rowCount() is 0 for "no such row" and also for "the values were already
       these", so the two are distinguished by re-reading the row: still there
       means the save was simply redundant and success is honest. */
    $upd = $pdo->prepare('UPDATE product_variants SET ' . implode(', ', $sets) . ' WHERE id=:id AND product_id=:pid');
    $upd->execute($params);

    if ($upd->rowCount() === 0) {
        $still = $pdo->prepare("SELECT id FROM product_variants WHERE id = :id AND product_id = :pid");
        $still->execute(['id' => $id, 'pid' => $productId]);
        if (!$still->fetchColumn()) {
            echo json_encode([
                'success' => false,
                'message' => 'That size no longer exists on this product — it may have been removed in another tab. Reload the page.',
            ]);
            exit;
        }
    }

    /* Read from the row rather than the request: saveColorSize() posts no
       color_id, and the colour is what decides whether this price can ever be
       charged. Fetched after the write so it describes the row as it now is. */
    $ownerColor = null;
    if ($priceProvided && $enteredPrice > 0) {
        $cq = $pdo->prepare("SELECT color_id FROM product_variants WHERE id = :id AND product_id = :pid");
        $cq->execute(['id' => $id, 'pid' => $productId]);
        $cid = $cq->fetchColumn();
        $ownerColor = ($cid === false || $cid === null) ? null : (int)$cid;
    }

    $response = ['success' => true];
    if ($notice = sizePriceClampNotice($pdo, $productId, $enteredPrice, $ownerColor)) { $response['warning'] = $notice; }
    echo json_encode($response);
    exit;
}

// ── DELETE variant ───────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false]); exit; }
    $pdo->prepare("DELETE FROM product_variants WHERE id=:id AND product_id=:pid")
        ->execute(['id' => $id, 'pid' => $productId]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
