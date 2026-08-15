<?php
// ============================================================
//  Dievon – /men
// ------------------------------------------------------------
//  The menswear half of the shop, as a REAL address rather than a cookie state.
//
//  That distinction is the whole point. If menswear were only a cookie flipped
//  by a button, Googlebot — which carries no cookies — would only ever see the
//  womenswear catalogue, and every men's page would be invisible in search. A
//  crawler, a shared link and a bookmarked tab all land here correctly because
//  the URL itself carries the meaning.
//
//  It renders the HOMEPAGE in menswear context, not the shop grid. The two sides
//  have to mirror each other: Women lands on the homepage, so Men landing on a
//  bare product listing made the switch feel like it had gone somewhere else
//  entirely rather than showing the same shop for someone else. Same hero, same
//  collections, same new arrivals — different contents.
//
//  The global below is read by currentShopGender() in config/config.php and
//  deliberately outranks the cookie, so this address is correct for a crawler
//  and a shared link, neither of which carries one.
//
//  Routing needs no rewrite rule — .htaccess already maps /<name> to
//  pages/<name>.php.
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Nothing to sell here yet? Send them to the shop that does exist.
//
// The gender filters throughout the site are gated on BOTH audiences having
// stock — a sensible rule everywhere except this address, which is reachable
// directly whatever the catalogue holds. With menswear empty the filters go
// inert, so this page answered 200 with a "Menswear" title, an "Explore
// Menswear" hero and ten kurtis underneath it. Worse than an empty page: it is
// a page that states something untrue, and one Google can index.
//
// 302, deliberately not 301: menswear is coming, and a permanent redirect is
// cached hard by browsers and search engines. This has to stop the day the
// first men's product goes live, which a 301 would not.
if (!function_exists('gendersWithLiveStock') || !in_array('men', gendersWithLiveStock(), true)) {
    header('Location: ' . SITE_URL . '/', true, 302);
    exit;
}

$GLOBALS['DIEVON_FORCE_SHOP_GENDER'] = 'men';

require __DIR__ . '/home.php';
