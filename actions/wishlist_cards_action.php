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
require_once __DIR__ . '/../includes/product_card_data.php';

header('Content-Type: application/json; charset=utf-8');

/* Ids come from the shopper's own localStorage, so they are untrusted input
   like anything else. Sanitising and capping them is the same job on every
   endpoint that takes a list of product ids, so it lives in
   includes/product_card_data.php with the rest of the card's data side. */
$ids = dievonCardIdsFromRequest((string)($_GET['ids'] ?? ''));

if (!$ids) {
    echo json_encode(['success' => true, 'html' => '', 'alive' => [], 'unavailable' => []]);
    exit;
}

try {
    /* Two different questions, and this endpoint answers both.
       ────────────────────────────────────────────────────────────────────────
       "Does it still exist" decides what stays in the wishlist: a piece that is
       merely not sold in the shopper's country, or that the owner has hidden,
       must NOT be forgotten — only a deleted one should be. "Can it be rendered"
       decides what appears in the grid, and that one does require availability.

       Keeping them apart is why a hidden piece stays saved, and why the
       unavailable names come back: without them the shopper would see a badge
       saying three and a grid showing two, with nothing accounting for the
       difference. A count that does not add up reads as a bug; a name reads as
       an explanation.

       Both queries, the column list and the hover/price priming are shared with
       every other id-list caller — see includes/product_card_data.php. This file
       used to carry its own copy of all of it. */
    $aliveInfo = dievonCardAliveIds($pdo, $ids);
    $rows      = dievonCardRows($pdo, $ids);

    /* The heart on a wishlist card removes the piece; Compare is a browsing
       tool and has no place on a list already chosen. */
    $html = dievonRenderCards($pdo, $rows, [
        'compare'    => false,
        'extraClass' => 'product-card-wishlist',
    ]);

    echo json_encode([
        'success'     => true,
        'html'        => $html,
        'alive'       => $aliveInfo['ids'],
        'unavailable' => $aliveInfo['unavailable'],
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Could not load your wishlist.']);
}
