<?php
// ============================================================
//  Dievon – Wishlist card renderer
// ------------------------------------------------------------
//  Returns the saved pieces as finished card markup, built from the SAME
//  partial the shop, the home rails and the suggested strip use.
//
//  Why an endpoint at all, when the wishlist used to render its cards in
//  JavaScript from a catalogue dumped into the page:
//
//    * The wishlist lives in localStorage, so only the browser knows which
//      ids to draw. The old answer was to send the browser EVERY product and
//      let it filter — the whole catalogue, to every visitor, to display the
//      three things they saved. This asks for the three.
//
//    * That client-side renderer was a fourth hand-written copy of the card
//      and had drifted badly: no Sold Out or discount badge, no Quick View,
//      no Compare, no WebP on the main image, no sold-out styling, and links
//      to the old product.php?id= URLs rather than the SEO routes. A saved
//      piece looked poorer than the same piece on the shop. Rendering through
//      includes/product_card.php means it cannot drift again.
//
//  Read-only and public, exactly like quick_view_action.php: it returns what
//  the shop already shows anyone who visits it. No CSRF token, because there
//  is nothing here to forge — it changes nothing.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

/* Ids come from the shopper's own localStorage, so they are untrusted input
   like anything else: cast to int, drop the rest, and cap the count. The cap
   is not about the honest shopper — it stops a crafted request asking the
   server to render thousands of cards in one go. */
$raw = (string)($_GET['ids'] ?? '');
$ids = array_values(array_unique(array_filter(
    array_map('intval', explode(',', $raw)),
    static fn(int $id): bool => $id > 0
)));
$ids = array_slice($ids, 0, 200);

if (!$ids) {
    echo json_encode(['success' => true, 'html' => '', 'alive' => [], 'unavailable' => []]);
    exit;
}

try {
    $ph = implode(',', array_fill(0, count($ids), '?'));

    /* Which of these still EXIST, ignoring country and availability.
       ────────────────────────────────────────────────────────────────────
       Kept separate from the render list on purpose, preserving the rule the
       page already had: a piece that is merely not sold in the shopper's
       country must NOT be forgotten from their wishlist, while one whose
       product has been deleted should be. Existence and availability are
       different questions and only the first justifies dropping something
       the shopper chose to save. */
    $aliveSt = $pdo->prepare(
        "SELECT id, name, available FROM products WHERE COALESCE(is_deleted,0) = 0 AND id IN ($ph)"
    );
    $aliveSt->execute($ids);
    $aliveRows = $aliveSt->fetchAll(PDO::FETCH_ASSOC);
    $alive = array_map(static fn(array $r): int => (int)$r['id'], $aliveRows);

    /* Saved, still exists, but the shop is not currently selling it.
       ────────────────────────────────────────────────────────────────────
       These are returned BY NAME rather than as a number. The piece stays in
       the wishlist — the owner has only hidden it, not deleted it — so
       without this the shopper would see a badge saying three and a grid
       showing two, with nothing on the page accounting for the difference.
       A count that does not add up reads as a bug; a name reads as an
       explanation. */
    $unavailable = [];
    foreach ($aliveRows as $r) {
        if ((int)$r['available'] !== 1) { $unavailable[] = (string)$r['name']; }
    }

    /* Only the columns the card and its helpers actually read — never
       SELECT *. The stock columns are here because productSellableStock()
       needs them to decide the sold-out state; they are used to compute a
       yes/no and never reach the markup. */
    $st = $pdo->prepare(
        "SELECT id, name, seo_url, category, price, mrp_price, image, image_alt, emoji,
                badge, video_url, available, track_stock,
                total_stock, damage_stock, sold_offline, sold_online
           FROM products
          WHERE available = 1 AND COALESCE(is_deleted,0) = 0 AND id IN ($ph)"
    );
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Saved order is the shopper's order — keep the sequence they chose
    // rather than whatever the database hands back.
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
    $ordered = [];
    foreach ($ids as $id) { if (isset($byId[$id])) { $ordered[] = $byId[$id]; } }

    // One pair of queries for the hover photographs, not a pair per card.
    productHoverImagePrime($ordered, $pdo);
    productPriceRangePrime($ordered, $pdo);

    /* Rendered through the shared partial. $card is the variable it reads,
       and $GLOBALS['pdo'] is how it reaches the database for the sold-out
       check — both matching how pages/shop.php includes it. */
    $GLOBALS['pdo'] = $pdo;
    ob_start();
    foreach ($ordered as $row) {
        $card = $row;
        // The heart on a wishlist card removes the piece; Compare is a
        // browsing tool and has no place on a list already chosen.
        $cardCompare   = false;
        $cardQuickView = true;
        $cardExtraClass = 'product-card-wishlist';
        include __DIR__ . '/../includes/product_card.php';
    }
    $html = ob_get_clean();

    echo json_encode([
        'success'     => true,
        'html'        => $html,
        'alive'       => $alive,
        'unavailable' => $unavailable,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Could not load your wishlist.']);
}
