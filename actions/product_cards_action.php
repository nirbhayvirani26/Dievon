<?php
// ============================================================
//  Dievon – product cards for a list of ids
// ------------------------------------------------------------
//  Give it product ids, get back finished card markup built from the SAME
//  partial the shop grid, the home rails and the suggested strip use.
//
//  Every client-side store of products ends up here. The wishlist and the
//  recently-viewed list both live in localStorage, so only the browser knows
//  which ids to draw, and the answer has repeatedly been to rebuild the card in
//  JavaScript. Those rebuilds drift: the wishlist's copy had lost its Sold Out
//  and discount badges, Quick View, Compare, WebP and its sold-out styling; the
//  recently-viewed copy still had no sold-out state, no hover photograph and no
//  bag note. A card rendered here cannot drift, because there is nothing here
//  to drift from.
//
//  Read-only and public, exactly like quick_view_action.php and
//  wishlist_cards_action.php: it returns what the shop already shows anyone who
//  visits it. No CSRF token, because it changes nothing.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/product_card_data.php';

header('Content-Type: application/json; charset=utf-8');

$ids = dievonCardIdsFromRequest((string)($_GET['ids'] ?? ''));

if (!$ids) {
    echo json_encode(['success' => true, 'html' => '', 'alive' => [], 'unavailable' => []]);
    exit;
}

/* The caller says what kind of list this is, and that decides two switches on
   the card — nothing else. Anything unrecognised gets the ordinary card, so a
   typo shows a normal product rather than an error. */
$context = (string)($_GET['context'] ?? '');
$opts = match ($context) {
    // The heart on a wishlist card removes the piece; Compare is a browsing
    // tool and has no place on a list already chosen.
    'wishlist' => ['compare' => false, 'extraClass' => 'product-card-wishlist'],
    default    => [],
};

try {
    /* Alive and renderable are different questions, and both answers go back.
       The caller prunes its stored list against `alive` — so a deleted product
       is forgotten for good — while only available products are rendered. A
       piece the owner has merely hidden therefore stays in the shopper's list
       without appearing in the grid, and `unavailable` names it so a count that
       does not match the cards has an explanation. */
    $aliveInfo = dievonCardAliveIds($pdo, $ids);
    $rows      = dievonCardRows($pdo, $ids);
    $html      = dievonRenderCards($pdo, $rows, $opts);

    echo json_encode([
        'success'     => true,
        'html'        => $html,
        'alive'       => $aliveInfo['ids'],
        'unavailable' => $aliveInfo['unavailable'],
        'rendered'    => array_map(static fn(array $r): int => (int)$r['id'], $rows),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Could not load these pieces.']);
}
