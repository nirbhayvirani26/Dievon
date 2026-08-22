<?php
/**
 * Dievon – the data side of the product card.
 *
 * includes/product_card.php is the one definition of what a card LOOKS like.
 * This is the one definition of how you get the rows to feed it when all you
 * hold is a list of ids — which is the situation every client-side store puts
 * you in: the wishlist and the recently-viewed list both live in localStorage,
 * so only the browser knows which products to draw.
 *
 * It exists because the alternative kept producing hand-written copies of the
 * card in JavaScript, and those copies drifted: the wishlist's had lost its
 * Sold Out badge, its Quick View and its Compare; the recently-viewed one still
 * has no sold-out state and no hover photograph. A renderer that cannot be
 * reached from a list of ids is a renderer that gets rewritten.
 *
 * The column list below is the card's real requirement — never SELECT *. The
 * stock columns are here because productSellableStock() needs them to decide
 * the sold-out state; they are used to compute a yes/no and never reach the
 * markup. That matters: /shop?ajax=1 once served cost_price, true inventory and
 * supplier fields to anyone who asked.
 */

/** The card's column requirement, in one place. */
const DIEVON_CARD_COLUMNS =
    "id, name, seo_url, category, price, mrp_price, image, image_alt, emoji,
     badge, video_url, available, track_stock,
     total_stock, damage_stock, sold_offline, sold_online";

/**
 * Ids straight off a query string, made safe.
 *
 * These come from the shopper's own localStorage, so they are untrusted like
 * any other input: cast to int, drop what is left, and cap the count — not for
 * the honest shopper, but so a crafted request cannot ask the server to render
 * thousands of cards in one go.
 */
function dievonCardIdsFromRequest(string $raw, int $cap = 200): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $raw)),
        static fn(int $id): bool => $id > 0
    )));
    return array_slice($ids, 0, $cap);
}

/**
 * Which of these products still EXIST, availability aside.
 *
 * Deliberately a different question from "which can I render". A piece the
 * owner has merely hidden must not be forgotten from a shopper's wishlist or
 * their history; a piece that has been deleted should be. Only the second
 * justifies dropping something from a list the shopper did not ask to change.
 *
 * @return array{ids: int[], unavailable: string[]}
 */
function dievonCardAliveIds(PDO $pdo, array $ids): array
{
    if (!$ids) { return ['ids' => [], 'unavailable' => []]; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, available FROM products
                          WHERE COALESCE(is_deleted,0) = 0 AND id IN ($ph)");
    $st->execute($ids);
    $alive = [];
    $unavailable = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $alive[] = (int)$r['id'];
        if ((int)$r['available'] !== 1) { $unavailable[] = (string)$r['name']; }
    }
    return ['ids' => $alive, 'unavailable' => $unavailable];
}

/**
 * The rows to render, in the order the caller asked for them.
 *
 * The saved order is the shopper's order — the sequence they wishlisted or
 * browsed in — not whatever the database hands back. Hover photographs and
 * price ranges are primed once for the whole set rather than once per card,
 * which is the difference between two queries and two per product.
 */
function dievonCardRows(PDO $pdo, array $ids): array
{
    if (!$ids) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT " . DIEVON_CARD_COLUMNS . " FROM products
          WHERE available = 1 AND COALESCE(is_deleted,0) = 0 AND id IN ($ph)"
    );
    $st->execute($ids);

    $byId = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $byId[(int)$r['id']] = $r; }

    $ordered = [];
    foreach ($ids as $id) { if (isset($byId[$id])) { $ordered[] = $byId[$id]; } }

    if ($ordered) {
        productHoverImagePrime($ordered, $pdo);
        productPriceRangePrime($ordered, $pdo);
    }
    return $ordered;
}

/**
 * Rows in, finished card markup out — through the one partial.
 *
 * $opts takes the same switches the partial documents: compare, quickView,
 * extraClass, fallbackBadge. They are set on every iteration rather than once,
 * because the partial reads them as loose variables and an unset one would
 * otherwise inherit whatever the previous card left behind.
 */
function dievonRenderCards(PDO $pdo, array $rows, array $opts = []): string
{
    if (!$rows) { return ''; }
    // The partial reaches the database through $GLOBALS['pdo'] for its sold-out
    // check — the same way pages/shop.php's own grid provides it.
    $GLOBALS['pdo'] = $pdo;

    ob_start();
    foreach ($rows as $row) {
        $card              = $row;
        $cardCompare       = $opts['compare']       ?? true;
        $cardQuickView     = $opts['quickView']     ?? true;
        $cardExtraClass    = $opts['extraClass']    ?? '';
        $cardFallbackBadge = $opts['fallbackBadge'] ?? '';
        include __DIR__ . '/product_card.php';
    }
    return (string)ob_get_clean();
}
