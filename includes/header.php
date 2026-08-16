<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Current route/page slug (e.g. 'home', 'shop', 'about'), used to highlight the active nav link.
// Pages loaded via the front controller (index.php) already have $path set; direct clean-URL
// rewrites to pages/*.php do not, so derive it from the script name in that case.
if (!isset($path)) {
    $path = basename($_SERVER['SCRIPT_NAME'], '.php');
}

// Calculate cart count
$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += $item['quantity'];
    }
}

// Fetch categories for menu.
//
// A category with nothing to sell is a dead end: the shopper clicks, lands on an
// empty grid, and concludes the shop is broken. Six of eleven were in that state,
// including one called "Testing" — visible to every visitor.
//
// Counted across the whole SUBTREE, not just direct children: a parent whose
// pieces all live in its subcategories has zero of its own and would otherwise
// disappear along with everything under it.
$menuCategories = [];
$categoryHasStock = [];
try {
    $allCats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();

    // Live product count per category, by id and by legacy name match.
    $liveCounts = [];
    foreach ($pdo->query(
        "SELECT c.id,
                (SELECT COUNT(*) FROM products p
                  WHERE (p.category_id = c.id OR p.category = c.name)
                    AND p.available = 1
                    AND (p.is_deleted = 0 OR p.is_deleted IS NULL)) AS live
           FROM categories c"
    ) as $r) {
        $liveCounts[(int)$r['id']] = (int)$r['live'];
    }

    // Roll each count up to every ancestor, so a parent inherits its children's.
    $parentOf = [];
    foreach ($allCats as $c) { $parentOf[(int)$c['id']] = (int)($c['parent_id'] ?? 0); }

    $subtreeCounts = $liveCounts;
    foreach ($liveCounts as $cid => $n) {
        if ($n === 0) { continue; }
        $seen = [$cid => true];
        $pid  = $parentOf[$cid] ?? 0;
        // The seen-guard stops a self-referential or circular parent_id looping.
        while ($pid > 0 && !isset($seen[$pid])) {
            $seen[$pid] = true;
            $subtreeCounts[$pid] = ($subtreeCounts[$pid] ?? 0) + $n;
            $pid = $parentOf[$pid] ?? 0;
        }
    }

    // Published as a lookup the nav below uses: id => has something to sell.
    //
    // "Something to sell" now also means "on the side of the shop being
    // browsed". Filtering in this one place keeps the desktop mega menu, the
    // mobile drawer and the footer's Collections column in step, because all
    // three read these two variables — the alternative was three filters that
    // could drift apart and show a man a Kurtis link.
    //
    // Inert until both sides genuinely trade: shopGenderSelectorEnabled() is
    // false while only womenswear has stock, so the menu behaves exactly as it
    // always has until the day menswear goes live.
    $categoryHasStock = [];
    $splitByAudience  = function_exists('shopGenderSelectorEnabled') && shopGenderSelectorEnabled();
    foreach ($allCats as $c) {
        $has = ($subtreeCounts[(int)$c['id']] ?? 0) > 0;
        if ($has && $splitByAudience && !categoryMatchesShopGender((int)$c['id'])) {
            $has = false;
        }
        $categoryHasStock[(int)$c['id']] = $has;
        if ($has) { $menuCategories[] = $c; }
    }

    // ── Real "edits" for the mega menu ──────────────────────────────────────
    // The menu offered Classic Edit / Handcrafted Essentials / Printed Curation
    // (?style=…, a column that does not exist), Bestseller Edit and Festive
    // Curation (?badge=…, values the catalogue has never held) and Pure Silk
    // Ensemble (?fabric=Pure+Silk, when the stored value is "Silk"). Seven links
    // per category, none of which filtered anything — a shopper clicking
    // "Festive Curation" got the unfiltered category back.
    //
    // These are read from the products actually in each category, so every link
    // in the menu leads somewhere real and stays true as the stock changes.
    $categoryEdits     = [];
    $categoryBadges    = [];
    $categoryPromos    = [];
    try {
        /* All three of these look INSIDE the subcategories too.
           ────────────────────────────────────────────────────────────────
           The promo query below always did; these two did not, and the
           inconsistency only shows on a parent whose products all live in its
           children. Group the catalogue as "Indian Wear → Kurtis, Sarees" and
           nothing sits in Indian Wear directly — so its menu lost the Shop by
           Fabric and badge columns entirely and dropped to a single thin list,
           while Kurtis, which does hold products, still looked full.

           Measured before this: INDIAN WEAR → Sub-Categories [3] and nothing
           else. The rollup is one clause, and it is the same clause the promo
           images already use. */
        $fabricStmt = $pdo->prepare(
            "SELECT fabric, COUNT(*) AS n FROM products
              WHERE (category_id = :cid OR category = :cname
                     OR category_id IN (SELECT id FROM categories WHERE parent_id = :pid))
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                AND fabric IS NOT NULL AND fabric <> ''
              GROUP BY fabric ORDER BY n DESC, fabric ASC LIMIT 4"
        );
        $badgeStmt = $pdo->prepare(
            "SELECT DISTINCT badge FROM products
              WHERE (category_id = :cid OR category = :cname
                     OR category_id IN (SELECT id FROM categories WHERE parent_id = :pid))
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                AND badge IS NOT NULL AND badge <> ''
              ORDER BY badge ASC LIMIT 4"
        );
        /* Two photographs per collection, for that collection's menu.
           ────────────────────────────────────────────────────────────────
           The promo card used lookbookUrl(1) — one fixed brand photograph —
           so Kurtis, 3 Piece Suits, Coord Sets, Women's Tops and Bottoms all
           showed the SAME picture. Opening any two menus in a row showed the
           identical Greek terrace, which tells a shopper nothing about what
           is behind the link.

           Newest first, so the menu keeps up with the catalogue on its own.
           Subcategories are included: a parent like Kurtis often holds no
           products directly — they sit under Long Kurtis, Anarkali Sets and
           so on — and without this its menu would find nothing to show. */
        /* Six candidates, not two, because the two newest products are often
           the same photograph — a shop will list a suit in two colourways off
           one shoot, and the menu then shows the identical picture twice,
           which reads as a bug rather than a pair. Deduplicated below. */
        $promoStmt = $pdo->prepare(
            "SELECT image FROM products
              WHERE (category_id = :cid OR category = :cname
                     OR category_id IN (SELECT id FROM categories WHERE parent_id = :pid))
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                AND image IS NOT NULL AND image <> ''
              ORDER BY created_at DESC, id DESC
              LIMIT 6"
        );

        foreach ($menuCategories as $mc) {
            if ((int)($mc['parent_id'] ?? 0) !== 0) { continue; }
            // :pid is the same value as :cid — named separately because all three
            // statements now carry the subcategory clause and PDO wants one bound
            // parameter per placeholder.
            $args = ['cid' => (int)$mc['id'], 'cname' => $mc['name'], 'pid' => (int)$mc['id']];
            $fabricStmt->execute($args);
            $categoryEdits[(int)$mc['id']] = $fabricStmt->fetchAll(PDO::FETCH_COLUMN);
            $badgeStmt->execute($args);
            $categoryBadges[(int)$mc['id']] = $badgeStmt->fetchAll(PDO::FETCH_COLUMN);


            try {
                $promoStmt->execute($args);
                $categoryPromos[(int)$mc['id']] = array_values(array_unique(
                    $promoStmt->fetchAll(PDO::FETCH_COLUMN)
                ));
            } catch (PDOException $e) {
                // A menu is not worth a fatal; it falls back to the lookbook.
                error_log('Mega menu promo images failed for category ' . (int)$mc['id'] . ': ' . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log('Mega menu fabric/badge lookup failed: ' . $e->getMessage());
    }
} catch (PDOException $e) {}

// What to suggest in the search box, from the categories that have stock.
//
// Defined HERE, next to $menuCategories, because both search boxes are
// rendered further down and one of them sits ABOVE where this used to be —
// so the desktop placeholder read an undefined variable and came out empty.
// Locally that was a visible PHP warning; on live, where display_errors is
// off, it was simply a blank placeholder and nothing said why.
//
// Both placeholders were hand-written before this and named clothes the shop
// does not sell — "dupattas and dresses", never stocked, and later "shirts
// and trousers", the two archived menswear categories.
// Budgeted by LENGTH, not just by count.
//
// Three names was the only limit, which assumed three short ones. With the
// categories this shop actually has it produced "Search qa test womenswear, qa
// test accessories, kurtis…" — 57 characters into a box that shows about 30, so
// the hint was cut off mid-word hard against the Image Search button and the
// shopper saw a broken sentence naming a category they could not finish reading.
//
// A third name is only taken if it fits. Two long names stop at two, three short
// ones still all fit, so the box stays full without ever overflowing.
$searchHintNames = [];
$searchHintLen   = 0;
foreach ($menuCategories as $mc) {
    if ((int)($mc['parent_id'] ?? 0) !== 0) { continue; }
    $mcName = mb_strtolower($mc['name']);
    // +2 for the ", " this name would arrive with. Always take the first one,
    // however long — a hint naming nothing is worse than one that is snug.
    if ($searchHintNames !== [] && ($searchHintLen + mb_strlen($mcName) + 2) > 30) { break; }
    $searchHintNames[] = $mcName;
    $searchHintLen    += mb_strlen($mcName) + 2;
    if (count($searchHintNames) >= 3) { break; }
}
$searchHint = $searchHintNames
    ? 'Search ' . implode(', ', $searchHintNames) . '…'
    : 'Search the collection…';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php 
        // Built on the TRUSTED host (see trustedSiteHost() in config.php), not
        // the browser's claimed Host header — og:url and the canonical link used
        // to point at whatever host the visitor claimed, an attacker's site
        // included. currentPageUrl() takes SITE_URL's scheme+host and appends
        // the request URI, which works on MAMP (where the URI carries the
        // /DievonOrders prefix) as well as the live host.
        $currentUrl = currentPageUrl();
        // Built from the categories that actually have stock, not a hand-written
        // sentence. The previous fixed string advertised "designer dresses" and
        // "organza dupattas" — neither of which the catalogue has ever held — and
        // was served verbatim on ten indexable pages, so /blog, /returns,
        // /shipping, /terms and /privacy all described themselves identically.
        $defaultDesc = dievonDefaultMetaDescription($pdo ?? null);
        $desc = isset($metaDescription) ? htmlspecialchars($metaDescription, ENT_COMPAT, 'UTF-8') : $defaultDesc;
        if (isset($pageTitle)) {
            // Avoid double-branding (e.g. "Dievon | ... – Dievon") when the title already names the brand.
            $title = (stripos($pageTitle, SHOP_NAME) !== false) ? htmlspecialchars($pageTitle, ENT_COMPAT, 'UTF-8') : htmlspecialchars($pageTitle, ENT_COMPAT, 'UTF-8') . " – " . SHOP_NAME;
        } else {
            $title = SHOP_NAME . " – " . SHOP_TAGLINE;
        }
        $image = isset($ogImage) ? htmlspecialchars($ogImage) : siteLogoUrl($pdo ?? null);
    ?>
    <?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>

    <?php
        // Google Search Console ownership. Pasted into admin › SEO, stored in
        // store_settings, so verifying the property never needs a code change.
        $gscToken = storeSetting($pdo ?? null, 'google_site_verification', '');
        if ($gscToken):
    ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($gscToken) ?>">
    <?php endif; ?>

    <?php
        /* Google Analytics 4, and only when an ID has actually been saved.
           ────────────────────────────────────────────────────────────────────
           Same arrangement as the verification token above: pasted into admin ›
           SEO, kept in store_settings, so pointing the shop at a different
           property never needs a deploy. With the field empty NOTHING is
           emitted — no script tag, no request to google-analytics.com, no
           cookie — so the shop carries no tracking until the owner asks for it.

           The ID is narrowed to the G-XXXXXXX shape when it is saved, because
           it is interpolated into a <script> block here; rawurlencode on the
           src and json_encode on the config argument close the same door from
           this side, so a bad value stored by any other route still cannot
           break out into executable JavaScript. */
        $gaId = storeSetting($pdo ?? null, 'google_analytics_id', '');
        if ($gaId && preg_match('/^G-[A-Z0-9]{4,20}$/i', $gaId)):
    ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= rawurlencode($gaId) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', <?= json_encode($gaId) ?>);
    </script>
    <?php endif; ?>
    <title><?= $title ?></title>
    <meta name="description" content="<?= $desc ?>">
    <?php if (!empty($metaKeywords)): ?>
    <meta name="keywords" content="<?= htmlspecialchars($metaKeywords) ?>">
    <?php endif; ?>
    <?php // NOTE: the canonical link is emitted further down (it strips the query string
          // and honours a $canonicalUrl override) — do not add a second one here. ?>

    <?php if (dievonMayLoadTrackers()): ?>
    <!-- Facebook / Meta Pixel Base Code
         Gated on dievonMayLoadTrackers(): an ID must be configured AND this
         visitor must have actively agreed. The test used to be "is an ID set",
         with a comment saying a consent step needed adding before anyone set
         one — which is the kind of note that gets missed on the day someone
         pastes an ID in. Now the gate is the code, not the comment. -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '<?= META_PIXEL_ID ?>');
    fbq('track', 'PageView');
    </script>
    <?php endif; ?>
    
    <!-- Open Graph / Facebook / Instagram -->
    <!-- og:type is "product" on product pages so shares render as a rich product
         card rather than a generic link; pages set $ogType to opt in. -->
    <meta property="og:type" content="<?= htmlspecialchars($ogType ?? 'website') ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars(SHOP_NAME) ?>">
    <meta property="og:locale" content="en_IN">
    <meta property="og:url" content="<?= htmlspecialchars($currentUrl) ?>">
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:description" content="<?= $desc ?>">
    <meta property="og:image" content="<?= $image ?>">
    <meta property="og:image:alt" content="<?= $title ?>">
    <?php if (!empty($ogProduct) && is_array($ogProduct)): ?>
    <meta property="product:price:amount" content="<?= htmlspecialchars((string)$ogProduct['price']) ?>">
    <meta property="product:price:currency" content="<?= htmlspecialchars($ogProduct['currency']) ?>">
    <meta property="product:availability" content="<?= htmlspecialchars($ogProduct['availability']) ?>">
    <?php if (!empty($ogProduct['brand'])): ?>
    <meta property="product:brand" content="<?= htmlspecialchars($ogProduct['brand']) ?>">
    <?php endif; ?>
    <?php endif; ?>

    <!-- Twitter Cards -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $title ?>">
    <meta name="twitter:description" content="<?= $desc ?>">
    <meta name="twitter:image" content="<?= $image ?>">

    <!-- Canonical Link to prevent duplicate content SEO penalties -->
    <?php
    /* An error response names no canonical at all.
       ────────────────────────────────────────────────────────────────────────
       pages/404.php sets $noindex but never $canonicalUrl, so the fallback below
       used the REQUESTED url — and for a query-string 404 like
       /shop?category=Nonexistent that resolves to /shop. The page then answered
       404 with "noindex" and a canonical pointing at a live money page, which is
       the one combination Google is documented to resolve badly: it may follow
       the canonical and apply the noindex to the target. pages/shop.php:802
       already documents guarding against exactly this on its own path; the error
       path was left open, and /blog-single?id=1 aimed at /blog the same way.

       Decided from the response code rather than patched into 404.php, so every
       error path this template serves is covered — including the 410 Gone that
       product.php issues for a withdrawn product. */
    $dvStatus = function_exists('http_response_code') ? (int)http_response_code() : 200;
    $canonicalHref = ($dvStatus >= 400)
        ? null
        : (isset($canonicalUrl) ? $canonicalUrl : strtok($currentUrl, '?'));
    ?>
    <?php if ($canonicalHref !== null): ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonicalHref) ?>">
    <?php endif; ?>

    <!-- Schema.org Organization Structured Data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "Dievon",
      "url": "<?= SITE_URL ?>",
      "logo": "<?= htmlspecialchars(siteLogoUrl($pdo ?? null)) ?>"
    }
    </script>

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://checkout.razorpay.com">
    <?php
    // Favicon. When a dark-mode variant exists we emit BOTH links with a
    // prefers-color-scheme media query, so a dark logo does not disappear against
    // a dark browser tab. Browsers that ignore the media attribute fall back to
    // the last matching link, which is why the light one is listed first.
    $faviconLight = siteFaviconUrl($pdo ?? null);
    $faviconDark  = siteFaviconDarkUrl($pdo ?? null);
    ?>
    <?php if ($faviconDark): ?>
    <link rel="icon" type="<?= siteFaviconMime($pdo ?? null, $faviconLight) ?>" href="<?= htmlspecialchars($faviconLight) ?>" media="(prefers-color-scheme: light)">
    <link rel="icon" type="<?= siteFaviconMime($pdo ?? null, $faviconDark) ?>" href="<?= htmlspecialchars($faviconDark) ?>" media="(prefers-color-scheme: dark)">
    <?php else: ?>
    <link rel="icon" type="<?= siteFaviconMime($pdo ?? null, $faviconLight) ?>" href="<?= htmlspecialchars($faviconLight) ?>">
    <?php endif; ?>
    <!-- Versioned with filemtime, not time(): a cache-buster that changes on every
         request defeats browser caching and re-downloads the CSS on each page view,
         hurting LCP and PageSpeed. filemtime only changes when the file does. -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/responsive.css?v=<?= filemtime(__DIR__ . '/../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/fontawesome/css/all.min.css">
    <!-- All styles are in assets/css/style.css and assets/css/responsive.css -->

    <?php /* OwlCarousel — the slider engine.
             Served from this server rather than a CDN: the shop takes payments,
             and a third-party script tag is one more thing that can be blocked,
             go down, or change under us. Same files, no external request.
             The theme CSS is deliberately NOT loaded — Owl's default styling
             would fight the existing hero design, which is staying as it is.
             Only owl.carousel.min.css, which is layout mechanics alone. */ ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/owlcarousel/owl.carousel.min.css?v=<?= filemtime(__DIR__ . '/../assets/vendor/owlcarousel/owl.carousel.min.css') ?>">

    <?php /* Both deferred, in this order.
             defer keeps them out of the critical render path AND guarantees
             ordering: deferred scripts run in document order, after parsing and
             BEFORE DOMContentLoaded. So any inline script further down that does
             its work inside a DOMContentLoaded handler can rely on both being
             ready — which is exactly how the hero slider initialises. Loading
             them without defer would block first paint on a 130 KB download. */ ?>
    <script defer src="<?= SITE_URL ?>/assets/vendor/jquery.min.js?v=<?= filemtime(__DIR__ . '/../assets/vendor/jquery.min.js') ?>"></script>
    <script defer src="<?= SITE_URL ?>/assets/vendor/owlcarousel/owl.carousel.min.js?v=<?= filemtime(__DIR__ . '/../assets/vendor/owlcarousel/owl.carousel.min.js') ?>"></script>

    <!-- Global Pricing Engine (Defined Early in Head) -->
    <script>
    window.SITE_URL = '<?= SITE_URL ?>';
    // The symbol this shopper is actually being charged in right now, resolved
    // server-side by currencySymbol() — which follows the chosen COUNTRY
    // (Countries We Sell To). formatPriceJS() below reads this directly rather
    // than re-deriving a currency client-side.
    window.DIEVON_CURRENT_SYMBOL = <?= json_encode(currencySymbol()) ?>;

    function formatPriceJS(amount) {
        // Matches formatPrice() in config/config.php: a symbol that ends in a
        // letter is a word, not a sign, and needs a space. Without this the cart,
        // the drawer and the checkout totals all read "AED80.00" while the
        // server-rendered price beside them reads "AED 80.00".
        const sym = window.DIEVON_CURRENT_SYMBOL || '₹';
        const gap = /\p{L}$/u.test(sym) ? ' ' : '';

        // The thousands separators matter for the same reason the space does.
        // formatPrice() runs number_format(), so PHP writes "₹1,500.00"; this
        // used to stop at toFixed() and write "₹1500.00" — and the two appear
        // side by side, the drawer against the cart page, the checkout totals
        // against the order summary that PHP rendered above them.
        //
        // Grouped in threes to match number_format(), NOT the Indian lakh
        // grouping. toLocaleString('en-IN') would give "₹1,50,000.00" against
        // PHP's "₹150,000.00" and simply move the disagreement. Done by hand
        // rather than through Intl so the grouping cannot follow the visitor's
        // system locale — a machine set to German would otherwise render
        // "1.500,00", which reads as one and a half rupees.
        const fixed = (parseFloat(amount) || 0).toFixed(2);
        const neg   = fixed.charAt(0) === '-';
        const body  = neg ? fixed.slice(1) : fixed;
        const dot   = body.indexOf('.');
        const grouped = body.slice(0, dot).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + body.slice(dot);

        // Sign inside the symbol, again because that is where number_format()
        // leaves it: formatPrice() concatenates "₹" onto "-1,500.00".
        return sym + gap + (neg ? '-' : '') + grouped;
    }

    // Real per-country selling: currentCountryCode() (config.php) reads this
    // cookie server-side and every price on the reload comes from
    // productPriceForCountry() for this country, not a converted guess.
    function changeCountry(code) {
        document.cookie = "dievon_country=" + code + "; path=/; max-age=" + (86400 * 30);
        if (typeof showToast === 'function') {
            showToast('Country Updated', 'Showing prices for ' + code);
        }
        setTimeout(() => { location.reload(); }, 300);
    }

    // Dynamic Header Padding Sync (ensures no banner/content gets hidden under fixed header on any screen size)
    function syncHeaderPadding() {
        const header = document.querySelector('.navbar-luxury') || document.querySelector('header');
        if (header) {
            const h = Math.ceil(header.getBoundingClientRect().height || header.offsetHeight);
            if (h > 0) {
                document.documentElement.style.setProperty('--header-height', h + 'px');
                if (document.body && !document.body.classList.contains('admin-wrapper')) {
                    document.body.style.setProperty('padding-top', h + 'px', 'important');
                }
            }
        }
    }
    document.addEventListener('DOMContentLoaded', () => {
        syncHeaderPadding();
        const headerEl = document.querySelector('.navbar-luxury') || document.querySelector('header');
        if (headerEl && window.ResizeObserver) {
            new ResizeObserver(syncHeaderPadding).observe(headerEl);
        }
    });
    window.addEventListener('load', syncHeaderPadding);
    window.addEventListener('orientationchange', syncHeaderPadding);

    /* Re-measure only when the viewport WIDTH changes.
       ─────────────────────────────────────────────────────────────
       On a phone, scrolling collapses and expands the browser's own address
       bar. That changes the viewport HEIGHT, which fires `resize` — so a plain
       resize listener re-measured the header and rewrote body padding-top
       several times during an ordinary scroll. The content shifts by a few
       pixels each time and, because the header is fixed and the page slides
       underneath it, it looks as though the header itself is moving.

       iOS Safari is the worst for this: the address bar animates over several
       frames, firing a burst of resize events the whole way.

       The header's height depends on how WIDE the screen is — which
       breakpoint applies, whether the nav wraps — never on how tall it is. So
       a height-only change can be ignored outright. Rotating the device does
       change the width, and orientationchange above covers that anyway. */
    let lastViewportWidth = window.innerWidth;
    window.addEventListener('resize', () => {
        if (window.innerWidth === lastViewportWidth) { return; }
        lastViewportWidth = window.innerWidth;
        syncHeaderPadding();
    });
    </script>
</head>
<body>

<?php /* Skip link — the first thing in the tab order on every page.
         ────────────────────────────────────────────────────────────────────────
         The header carries a search box, a mega menu and a full nav; without this
         a keyboard or screen-reader user tabbed through all of it on every single
         page before reaching the products. WCAG 2.4.1, and the cheapest
         accessibility win in the codebase. Visually hidden until focused, so it
         changes nothing for a mouse user. */ ?>
<a href="#mainContent" class="skip-to-content">Skip to content</a>

<!-- ══ Luxury Multi-Tier Header with Mega Menu ════════════════════════════ -->
<header class="navbar-luxury">
    <!-- Main Header Bar -->
    <div class="main-header-bar">
        <div class="container main-header-container">
            <!-- Left Hamburger Menu Button (Mobile) -->
            <button class="nav-hamburger" id="navHamburger" onclick="openMobileMenu()" aria-label="Open menu"><span></span><span></span><span></span></button>

            <!-- Brand Group: Logo -->
            <div class="header-brand-group">
                <a href="<?= SITE_URL ?>/" class="logo">
                    <img src="<?= htmlspecialchars(siteLogoUrl($pdo ?? null)) ?>" alt="Dievon Luxury" fetchpriority="high">
                </a>
            </div>

            <!-- Center Search Bar (Desktop) -->
            <div class="header-search-wrap" onclick="openSearchOverlay()">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <?php // Names real categories only. The old text offered "dupattas and
                      // dresses", neither of which the catalogue has ever stocked, and its
                      // mobile twin below listed different clothes again — the same search
                      // box disagreeing with itself about what the shop sells. ?>
                <?php // This box never takes typing: it is a doorway that opens the real
                      // search overlay, which is why it is readonly. Two consequences had
                      // been missed. It carried no name, so it was announced as an unnamed
                      // text field whose placeholder rotates with the hint. And the opener
                      // lived on the wrapping div's onclick, so a mouse worked and a
                      // keyboard did not — tab to it, press Enter, nothing happened. ?>
                <input type="text" id="inlineSearchInput" aria-label="Search products" readonly
                       placeholder="<?= htmlspecialchars($searchHint) ?>"
                       onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openSearchOverlay(); }">
                <button class="header-image-search-btn" type="button">
                    <i class="fa-solid fa-camera"></i> <span class="hide-mobile">Image Search</span>
                </button>
            </div>

            <!-- Right Action Icons -->
            <div class="nav-actions">
                <!-- Country Picker -->
                <?php if (countrySelectorEnabled()): ?>
                <?php // Real per-country selling (Countries We Sell To): the option list is
                      // exactly the countries enabled there, each priced separately. Picking
                      // one sets dievon_country and reloads, so productCountryPricing() then
                      // charges what was actually typed in for that country. Shown only once
                      // a second country is enabled — one country is no choice to make. ?>
                <div class="currency-selector-wrapper">
                    <?php
                    $curCountryCode = currentCountryCode();

                    /* Country CODE only, at every screen size: IN, UK, US.
                       ────────────────────────────────────────────────────────
                       This briefly rendered two controls — a long label for desktop
                       and a short one for phones — because a <select> renders one
                       string and CSS cannot rewrite the text inside an <option>. With
                       the same short label wanted everywhere, the second control is
                       just dead weight, so there is one again.

                       Two letters is ~40px against ~192px for "United Kingdom
                       (£ GBP)". That matters beyond looks: the search bar is
                       absolutely centred, so it does not reserve space for its
                       neighbours — at 192px the icon row was pushed left until the
                       search box was drawn straight over the top of it.

                       GB is the ISO code and what the database stores. The option
                       VALUE stays GB, so nothing downstream changes — only the label
                       reads UK, because that is what shoppers say. The full country
                       name and currency stay on the title for anyone hovering. */
                    $shortLabel = static function (string $code): string {
                        return ['GB' => 'UK'][$code] ?? $code;
                    };
                    $cc = enabledCountries()[$curCountryCode] ?? null;
                    $ctrlTitle = $cc ? $cc['country_name'] . ' — prices in ' . $cc['currency_code'] : '';
                    ?>
                    <select id="countrySelector" class="country-select"
                            onchange="changeCountry(this.value)"
                            aria-label="Select country and currency"
                            title="<?= htmlspecialchars($ctrlTitle) ?>">
                        <?php foreach (enabledCountries() as $ccode => $crow): ?>
                        <option value="<?= htmlspecialchars($ccode) ?>" <?= $curCountryCode === $ccode ? 'selected' : '' ?>
                                title="<?= htmlspecialchars($crow['country_name'] . ' — ' . $crow['currency_symbol'] . ' ' . $crow['currency_code']) ?>">
                            <?= htmlspecialchars($shortLabel($ccode)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Multi-Language Translator Widget Slot -->
                <div class="nav-action-item hide-mobile" id="google_translate_element" style="padding:0;"></div>

                <a href="<?= SITE_URL ?>/contact" class="nav-action-item" title="Contact Us" aria-label="Contact Us">
                    <i class="fa-solid fa-location-dot"></i>
                </a>
                <?php if (isset($_SESSION['customer_id'])): ?>
                    <a href="<?= SITE_URL ?>/account" class="nav-action-item" title="My Account" aria-label="My Account">
                        <i class="fa-solid fa-user"></i>
                    </a>
                <?php else: ?>
                    <a href="<?= SITE_URL ?>/login" class="nav-action-item" title="Login / Register" aria-label="Login / Register">
                        <i class="fa-regular fa-user"></i>
                    </a>
                <?php endif; ?>
                <?php // Both badges start HIDDEN and are shown by JS only when the
                      // count is above zero. Printed visible, the bag wore a filled
                      // maroon "0" on every page — and on first paint, before any
                      // script runs, it would flash even for a shopper who does have
                      // items. The wishlist badge always had this rule in JS; the
                      // cart one did not, which is why the two looked different. ?>
                <a href="<?= SITE_URL ?>/wishlist" class="nav-action-item" aria-label="Wishlist">
                    <i class="fa-regular fa-heart"></i>
                    <span class="action-badge" id="wishlistBadge" style="display:none;">0</span>
                </a>
                <button class="nav-action-item btn-cart-trigger" id="cartToggle" onclick="openCart()" aria-label="View cart">
                    <i class="fa-solid fa-bag-shopping"></i>
                    <span class="action-badge" id="cartBadge"<?= $cartCount > 0 ? '' : ' style="display:none;"' ?>><?php echo $cartCount; ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Bottom Category Navigation Bar with Rich Mega Menus -->
    <?php
    $curUri = $_SERVER['REQUEST_URI'] ?? '';
    $curCat = trim($_GET['category'] ?? '');
    $curBdg = trim($_GET['badge'] ?? '');

    $isNewAct     = ($curBdg === 'New' || strpos($curUri, 'badge=New') !== false);
    $isJournalAct = (strpos($curUri, 'blog') !== false);
    $isStoryAct   = (strpos($curUri, 'about') !== false);
    $isSaleAct    = (strpos($curUri, 'sale=1') !== false);

    // What to suggest in the search box, taken from the categories that actually
    // have stock rather than typed in.
    //

    // Is anything actually reduced? The SALE link only appears if so.
    //
    // It was rendered unconditionally — the brightest item in the navigation,
    // on every page, leading to an empty grid reading "Nothing is reduced right
    // now". On launch day that is the first thing a curious visitor clicks and
    // the first thing that disappoints them. Scoped by audience the same way
    // the shop page counts it, so a men's visitor is not shown a pill that only
    // womenswear can satisfy.
    $hasLiveSale = false;
    try {
        $saleSql = "SELECT COUNT(*) FROM products
                     WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                       AND mrp_price > 0 AND mrp_price > price";
        if (function_exists('shopGenderSqlFilter')) { $saleSql .= shopGenderSqlFilter(); }
        $hasLiveSale = (int)$pdo->query($saleSql)->fetchColumn() > 0;
    } catch (PDOException $e) { $hasLiveSale = false; }

    // Dynamic Parent & Sub-Category Query
    //
    // Only categories with something to sell. A shopper who clicks a menu entry
    // and lands on an empty grid concludes the shop is broken — six of eleven
    // were in that state, including one called "Testing".
    //
    // $categoryHasStock is built above from live product counts rolled up the
    // parent tree, so a parent whose pieces all sit in its subcategories still
    // appears rather than taking its own children down with it.
    $headerParents = [];
    $headerSubMap  = [];
    try {
        $catRows = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC, name ASC")->fetchAll();
        foreach ($catRows as $cr) {
            // No count data (pre-migration, or the query failed) means show
            // everything — an empty menu is a worse failure than a stale entry.
            if (!empty($categoryHasStock) && empty($categoryHasStock[(int)$cr['id']])) { continue; }

            if ((int)$cr['parent_id'] === 0) {
                $headerParents[] = $cr;
            } else {
                $headerSubMap[$cr['parent_id']][] = $cr;
            }
        }
    } catch (PDOException $e) {}

    /* The desktop nav is ONE ROW, and a row has a width.
     *
     * Every top-level collection was printed into it with no limit. .nav-links is
     * a flex row with a fixed 32px gap and no wrapping, so each new collection
     * simply made the row wider than the page: at 13 items the bar already
     * overruns a 1280px container, and one collection with a long name can do it
     * on its own. There is nothing to catch it — the row does not wrap, does not
     * scroll and does not truncate; it just pushes past the edge.
     *
     * So the first HEADER_NAV_MAX collections stay on the bar in their sort
     * order, and anything beyond that moves into a "More" dropdown. Nothing is
     * ever hidden — the mobile menu below still lists every one, and the
     * collections grid on the homepage is unaffected.
     *
     * 7 is what fits alongside NEW / JOURNAL / OUR STORY / SALE at the narrowest
     * desktop width the layout supports, with the longest current collection
     * name. The CSS also gained flex-wrap as a last-resort net, so an
     * exceptionally long name drops to a second line rather than off the page.
     */
    const HEADER_NAV_MAX = 7;
    $headerNavParents  = array_slice($headerParents, 0, HEADER_NAV_MAX);
    $headerMoreParents = array_slice($headerParents, HEADER_NAV_MAX);
    ?>
    <?php
    // ── Women / Men switch ──────────────────────────────────────────────────
    // Rendered only when BOTH sides genuinely have stock. A Men tab that leads
    // to an empty grid is worse than no tab, so an un-launched menswear range
    // simply does not appear — the same rule the country selector follows.
    //
    // Real <a href> links to real addresses, not a JavaScript toggle: /men has
    // to work for a crawler carrying no cookie, for a shared link and for
    // middle-click. The cookie only remembers the choice while browsing.
    $dvShopGender = function_exists('currentShopGender') ? currentShopGender() : 'women';
    if (function_exists('shopGenderSelectorEnabled') && shopGenderSelectorEnabled()): ?>
    <?php /* No .hide-mobile: this bar now shows on every screen size. It is the
             only control that changes what the whole rest of the site means, so
             it belongs where it can be seen rather than behind the hamburger. */ ?>
    <div class="audience-switch-bar">
        <div class="container">
            <nav class="audience-switch" aria-label="Shop for">
                <?php /* ?for=women is load-bearing, not decoration. Plain "/" leaves the
                         dievon_shop_for cookie saying men, so currentShopGender() keeps
                         answering men and the Women tab does nothing at all — the switch
                         becomes one-way once a visitor has chosen Men. */ ?>
                <a href="<?= SITE_URL ?>/?for=women" class="audience-switch-link <?= $dvShopGender === 'women' ? 'is-active' : '' ?>"
                   <?= $dvShopGender === 'women' ? 'aria-current="page"' : '' ?>>Women</a>
                <a href="<?= SITE_URL ?>/men" class="audience-switch-link <?= $dvShopGender === 'men' ? 'is-active' : '' ?>"
                   <?= $dvShopGender === 'men' ? 'aria-current="page"' : '' ?>>Men</a>
            </nav>
        </div>
    </div>
    <?php endif; ?>
    <div class="bottom-header-bar hide-mobile">
        <div class="container bottom-header-container">
            <ul class="nav-links">
                <li><a href="<?= SITE_URL ?>/shop?badges=New" class="nav-link-item nav-highlight-new <?= $isNewAct ? 'active' : '' ?>">NEW</a></li>
                
                <?php // Capped list — the remainder goes under "More" below. ?>
                <?php foreach ($headerNavParents as $pCat): ?>
                    <?php
                    $pId     = (int)$pCat['id'];
                    $pName   = htmlspecialchars($pCat['name']);
                    $subCats = $headerSubMap[$pId] ?? [];
                    $pActive = ($curCat === $pCat['name'] || strpos($curUri, 'category=' . urlencode($pCat['name'])) !== false);
                    ?>
                    <!-- Dynamic Mega Menu for <?= $pName ?> -->
                    <li class="has-mega-menu">
                        <a href="<?= htmlspecialchars(categoryUrlByName($pdo, $pCat['name'])) ?>" class="nav-link-item <?= $pActive ? 'active' : '' ?>">
                            <?= strtoupper($pName) ?> <i class="fa-solid fa-angle-down nav-arrow"></i>
                        </a>
                        <div class="mega-dropdown">
                            <div class="container mega-dropdown-container">
                                <div class="mega-column">
                                    <div class="mega-heading">Sub-Categories</div>
                                    <ul>
                                        <li><a href="<?= htmlspecialchars(categoryUrlByName($pdo, $pCat['name'])) ?>">Shop All <?= $pName ?></a></li>
                                        <?php if (!empty($subCats)): ?>
                                            <?php foreach ($subCats as $sub): ?>
                                            <li><a href="<?= htmlspecialchars(categoryUrlByName($pdo, $sub['name'])) ?>"><?= htmlspecialchars($sub['name']) ?></a></li>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <?php
                                    $pBase    = categoryUrlByName($pdo, $pCat['name']);
                                    $pFabrics = $categoryEdits[$pId] ?? [];
                                    $pBadges  = $categoryBadges[$pId] ?? [];
                                ?>
                                <?php if ($pFabrics || $pBadges): ?>
                                <div class="mega-column">
                                    <div class="mega-heading">Shop by Fabric</div>
                                    <ul>
                                        <?php foreach ($pFabrics as $pFabric): ?>
                                        <li><a href="<?= htmlspecialchars($pBase . '?fabrics=' . urlencode($pFabric)) ?>"><?= htmlspecialchars($pFabric) ?> <?= $pName ?></a></li>
                                        <?php endforeach; ?>
                                        <?php foreach ($pBadges as $pBadge): ?>
                                        <li><a href="<?= htmlspecialchars($pBase . '?badges=' . urlencode($pBadge)) ?>"><?= htmlspecialchars($pBadge) ?> <?= $pName ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>

                                <?php
                                /* Two photographs and a third tile that is not a photograph.
                                   ────────────────────────────────────────────────────────
                                   A single card 802 x 210 was a 3.8:1 letterbox holding
                                   portrait photography, so object-fit: cover kept a strip of
                                   background and threw the model away. Upright cards match
                                   the shape the pictures actually are.

                                   Three of them is what makes the row reach across the menu
                                   without any card having to grow — but a third PHOTOGRAPH
                                   cannot be relied on. Most collections here have exactly two
                                   distinct product images, and galleries are empty, so the
                                   third slot would vanish on nearly every menu and the empty
                                   band would come straight back.

                                   So the third slot is a designed tile instead: the piece
                                   count and a way in. It is always available, it never
                                   duplicates a photograph, and it tells the shopper something
                                   the pictures cannot. */
                                $pAllImgs = $categoryPromos[$pId] ?? [];
                                $pPromoImgs = [];
                                foreach (array_slice($pAllImgs, 0, 2) as $pImg) {
                                    $pPromoImgs[] = SITE_URL . '/uploads/products/' . rawurlencode($pImg);
                                }
                                /* The third slot always carries a photograph.
                                   ────────────────────────────────────────────────────────
                                   A flat panel beside two photographs reads as an image that
                                   failed to load. A third distinct photo is used whenever the
                                   collection has one — but most hold only two, so rather than
                                   fall back to a blank block it reuses the first under a heavy
                                   burgundy wash. At that strength the picture is texture, not
                                   a recognisable repeat, and the tile still reads as its own
                                   panel. The moment a third product is listed the wash lifts
                                   and it becomes a real third photograph on its own. */
                                $pTileSrc = $pAllImgs[2] ?? ($pAllImgs[0] ?? '');
                                $pTileImg = $pTileSrc !== ''
                                    ? SITE_URL . '/uploads/products/' . rawurlencode($pTileSrc)
                                    : '';
                                $pShopUrl   = categoryUrlByName($pdo, $pCat['name']);
                                $pLiveCount = (int)($subtreeCounts[$pId] ?? 0);
                                ?>
                                <?php /* Product photographs only. The lookbook stand-in was dropped
                                          deliberately: a brand photograph here promises a collection
                                          that has nothing in it, and the same stand-in appeared under
                                          several menus at once. With no products to show, the menu
                                          simply shows its links — which is the honest answer. */ ?>
                                <?php if ($pPromoImgs): ?>
                                <div class="mega-promo-column">
                                    <div class="mega-promo-grid">
                                        <?php foreach ($pPromoImgs as $pIdx => $pSrc): ?>
                                        <a href="<?= htmlspecialchars($pShopUrl) ?>" class="mega-promo-card"
                                           aria-label="Shop <?= htmlspecialchars($pName) ?>"<?= $pIdx ? ' tabindex="-1" aria-hidden="true"' : '' ?>>
                                            <img src="<?= htmlspecialchars($pSrc) ?>" alt="<?= htmlspecialchars($pName) ?>" loading="lazy">
                                        </a>
                                        <?php endforeach; ?>

                                        <a href="<?= htmlspecialchars($pShopUrl) ?>"
                                           class="mega-promo-card mega-promo-tile<?= $pTileImg ? ' mega-promo-tile--photo' : '' ?>"
                                           aria-label="View all <?= htmlspecialchars($pName) ?>">
                                            <?php if ($pTileImg): ?>
                                            <img src="<?= htmlspecialchars($pTileImg) ?>" alt="" loading="lazy">
                                            <?php endif; ?>
                                            <span class="mega-promo-tile-body">
                                                <span class="mega-promo-tile-count"><?= $pLiveCount ?></span>
                                                <span class="mega-promo-tile-label"><?= $pLiveCount === 1 ? 'piece' : 'pieces' ?></span>
                                                <span class="mega-promo-tile-cta">View all <i class="fa-solid fa-arrow-right"></i></span>
                                            </span>
                                        </a>
                                    </div>
                                    <div class="mega-promo-caption">
                                        <div class="mega-promo-title"><?= htmlspecialchars($pName) ?> Collection</div>
                                        <a href="<?= htmlspecialchars($pShopUrl) ?>" class="btn-mega-shop">Shop Now <i class="fa-solid fa-arrow-right"></i></a>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>

                <?php // Collections that did not fit on the bar. Same dropdown
                      // styling as a collection's own menu, so it does not read
                      // as a different kind of control. ?>
                <?php if (!empty($headerMoreParents)): ?>
                <li class="has-mega-menu">
                    <a href="<?= SITE_URL ?>/shop" class="nav-link-item">
                        MORE <i class="fa-solid fa-angle-down nav-arrow"></i>
                    </a>
                    <div class="mega-dropdown">
                        <div class="container mega-dropdown-container">
                            <div class="mega-column">
                                <div class="mega-heading">More Collections</div>
                                <ul>
                                    <?php foreach ($headerMoreParents as $mCat): ?>
                                    <li><a href="<?= htmlspecialchars(categoryUrlByName($pdo, $mCat['name'])) ?>"><?= htmlspecialchars($mCat['name']) ?></a></li>
                                    <?php endforeach; ?>
                                    <li><a href="<?= SITE_URL ?>/shop"><strong>Shop All</strong></a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </li>
                <?php endif; ?>

                <li><a href="<?= SITE_URL ?>/blog" class="nav-link-item <?= $isJournalAct ? 'active' : '' ?>">JOURNAL</a></li>
                <li><a href="<?= SITE_URL ?>/about" class="nav-link-item <?= $isStoryAct ? 'active' : '' ?>">OUR STORY</a></li>
                <?php if (!empty($hasLiveSale)): ?>
                <li><a href="<?= SITE_URL ?>/shop?sale=1" class="nav-link-item nav-link-sale <?= $isSaleAct ? 'active' : '' ?>">SALE</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</header>

<?php /* The <main> landmark, opened here and closed in includes/footer.php.
         Every page was header + divs + footer with no main region at all, so a
         screen-reader user had no way to jump past the navigation to the page's
         actual content, and axe reported every block of the page as outside a
         landmark. This is the target the skip link above points at. */ ?>
<main id="mainContent" tabindex="-1">

<!-- ══ Luxury Mobile Navigation Full-Screen Overlay ════════════════════════ -->
<div class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-nav-panel">
        
        <!-- Header Bar -->
        <div class="mobile-drawer-header">
            <a href="<?= SITE_URL ?>/" class="mobile-drawer-logo-link" onclick="closeMobileMenu()">
                <img src="<?= htmlspecialchars(siteLogoUrl($pdo ?? null)) ?>" alt="Dievon Luxury">
            </a>
            <button class="mobile-drawer-close" id="mobileDrawerClose" onclick="closeMobileMenu()" aria-label="Close menu">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Search Bar -->
        <div class="mobile-drawer-search-bar" onclick="closeMobileMenu(); openSearchOverlay();">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <?php // Kept word-for-word in step with the desktop placeholder above. Most
                  // Indian traffic is mobile, so this is the version most people read —
                  // and it was the women-only one. ?>
            <span class="search-placeholder"><?= htmlspecialchars($searchHint) ?></span>
        </div>

        <?php /* Women / Men switch, mobile.
                 The desktop bar carries .hide-mobile, so without this a phone
                 user — most of the traffic on a fashion site — had no way to
                 reach menswear at all. Placed above the links rather than
                 among them because it changes what every link below it means.
                 Same two real addresses as the desktop bar, and the same rule:
                 it does not render until both sides have stock. */ ?>
        <?php /* The Women/Men switch used to sit here. It has moved out to the main
                 header bar, which is visible on mobile without opening anything —
                 a switch buried inside a drawer is only found by someone who
                 already knows to look for it, and choosing a side is the first
                 decision a shopper makes, not a navigation detail. */ ?>

        <!-- Main Navigation Links -->
        <div class="mobile-drawer-body">
            <ul class="mobile-nav-links">
                <li><a href="<?= SITE_URL ?>/" onclick="closeMobileMenu()" class="<?php echo $path == 'home' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="<?= SITE_URL ?>/shop?badges=New" onclick="closeMobileMenu()" class="nav-highlight-new">New Arrivals</a></li>
                
                <!-- Dynamic Mobile Accordion Categories (Matches Desktop Mega Menu) -->
                <?php foreach ($headerParents as $pCat): ?>
                    <?php 
                    $pId = (int)$pCat['id'];
                    $pName = htmlspecialchars($pCat['name']);
                    $subCats = $headerSubMap[$pId] ?? [];
                    ?>
                    <li class="mobile-nav-accordion">
                        <?php /* A bare <div onclick> could not be reached by Tab at all, so
                                 these category rows were unopenable without a pointer. role +
                                 tabindex put it in the tab order, aria-expanded announces which
                                 state it is in, and Enter/Space are handled because a div does
                                 not fire click from the keyboard the way a button does. */ ?>
                        <div class="mobile-accordion-header" role="button" tabindex="0"
                             aria-expanded="false"
                             onclick="toggleMobileCategoryMenu(this)"
                             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleMobileCategoryMenu(this);}">
                            <span><?= $pName ?></span>
                            <i class="fa-solid fa-angle-down accordion-arrow" aria-hidden="true"></i>
                        </div>
                        <div class="mobile-accordion-body">
                            <ul class="mobile-subnav-list">
                                <li><a href="<?= htmlspecialchars(categoryUrlByName($pdo, $pCat['name'])) ?>" onclick="closeMobileMenu()" style="font-weight:700; color:var(--color-primary);">Shop All <?= $pName ?> →</a></li>
                                <?php if (!empty($subCats)): ?>
                                    <?php foreach ($subCats as $sub): ?>
                                    <li><a href="<?= htmlspecialchars(categoryUrlByName($pdo, $sub['name'])) ?>" onclick="closeMobileMenu()"><?= htmlspecialchars($sub['name']) ?></a></li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php
                                    $pBase    = categoryUrlByName($pdo, $pCat['name']);
                                    $pFabrics = $categoryEdits[$pId] ?? [];
                                    $pBadges  = $categoryBadges[$pId] ?? [];
                                ?>
                                <?php if ($pFabrics || $pBadges): ?>
                                <li class="mobile-subnav-divider">Shop by Fabric</li>
                                <?php foreach ($pFabrics as $pFabric): ?>
                                <li><a href="<?= htmlspecialchars($pBase . '?fabrics=' . urlencode($pFabric)) ?>" onclick="closeMobileMenu()"><?= htmlspecialchars($pFabric) ?> <?= $pName ?></a></li>
                                <?php endforeach; ?>
                                <?php foreach ($pBadges as $pBadge): ?>
                                <li><a href="<?= htmlspecialchars($pBase . '?badges=' . urlencode($pBadge)) ?>" onclick="closeMobileMenu()"><?= htmlspecialchars($pBadge) ?> <?= $pName ?></a></li>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </li>
                <?php endforeach; ?>

                <li><a href="<?= SITE_URL ?>/blog" onclick="closeMobileMenu()" class="<?php echo $path == 'blog' ? 'active' : ''; ?>">Journal</a></li>
                <li><a href="<?= SITE_URL ?>/about" onclick="closeMobileMenu()" class="<?php echo $path == 'about' ? 'active' : ''; ?>">Our Story</a></li>
                <?php if (!empty($hasLiveSale)): ?>
                <li><a href="<?= SITE_URL ?>/shop?sale=1" onclick="closeMobileMenu()" class="<?= $isSaleAct ? 'active' : '' ?>">Sale</a></li>
                <?php endif; ?>
                <li><a href="<?= SITE_URL ?>/contact" onclick="closeMobileMenu()" class="<?php echo $path == 'contact' ? 'active' : ''; ?>">Contact Us</a></li>
            </ul>
        </div>

        <!-- Footer Action Buttons -->
        <div class="mobile-drawer-footer">
            <?php if (isset($_SESSION['customer_id'])): ?>
                <a href="<?= SITE_URL ?>/account" onclick="closeMobileMenu()" class="btn-mobile-action-primary"><i class="fa-solid fa-user"></i> My Account</a>
                <a href="<?= SITE_URL ?>/logout.php" onclick="closeMobileMenu()" class="btn-mobile-action-secondary"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
            <?php else: ?>
                <a href="<?= SITE_URL ?>/login" onclick="closeMobileMenu()" class="btn-mobile-action-primary"><i class="fa-regular fa-user"></i> Login / Register</a>
            <?php endif; ?>
            
            <button class="btn-mobile-action-secondary" onclick="closeMobileMenu(); openCart();">
                <i class="fa-solid fa-bag-shopping"></i> Shopping Bag (<span id="mobileCartCount"><?php echo $cartCount; ?></span>)
            </button>
        </div>

    </div>
</div>


<script>
/* One owner for the page's scroll lock.
 * ─────────────────────────────────────────────────────────────────────────────
 * Five separate overlays used to write document.body.style.overflow directly —
 * the cart drawer, the mobile menu, the search overlay, the image lightbox and
 * the product details sheet — and every close set it back to '' unconditionally,
 * no matter what else was still open.
 *
 * That is where "sometimes the page will not scroll, sometimes it scrolls when
 * it should not" came from. Measured: open the cart, open the details sheet,
 * close the details sheet — the lock was released while the cart was still open.
 * The mirror image is worse: two overlays open, only one closed, and the lock
 * stays on for ever. The page then looks frozen, which is exactly what it was.
 *
 * Keyed rather than counted, so it cannot drift out of step: locking twice with
 * the same name is harmless, and unlocking something that never locked does
 * nothing. Scroll returns only when the last holder lets go.
 *
 * Still the same body.style.overflow underneath, so footer.php's scroll-direction
 * check, which reads that property, goes on working unchanged.
 */
window.dievonScrollLock = window.dievonScrollLock || (function () {
    var holders = {};
    function apply() {
        var any = false;
        for (var k in holders) { if (Object.prototype.hasOwnProperty.call(holders, k)) { any = true; break; } }
        document.body.style.overflow = any ? 'hidden' : '';
    }
    return {
        lock:   function (key) { holders[key] = true; apply(); },
        unlock: function (key) { delete holders[key]; apply(); },
        held:   function () { return Object.keys(holders); }
    };
})();

/* Open/close a mobile category row.
 *
 * The old version set body.style.display to 'block' / 'none'. `display` cannot be
 * transitioned, so the submenu appeared instantly however much CSS was written for
 * it, and the arrow was rotated by an inline transform with nothing to ease it.
 *
 * Height is animated instead. `auto` is not a transitionable value in either
 * direction, so each move starts from a measured pixel height; the open state is
 * released back to `auto` once the transition ends, so a submenu still reflows if
 * the phone is rotated or the font size changes. The arrow rotation and the open
 * state now live in CSS, driven by the .is-open class. */
function toggleMobileCategoryMenu(el) {
    const parentLi = el.closest('.mobile-nav-accordion');
    const body = parentLi ? parentLi.querySelector('.mobile-accordion-body') : null;
    if (!body) return;

    if (parentLi.classList.contains('is-open')) {
        body.style.height = body.scrollHeight + 'px';   // pin the current auto height
        void body.offsetHeight;                          // commit it before collapsing
        parentLi.classList.remove('is-open');
        el.setAttribute('aria-expanded', 'false');
        body.style.height = '0px';
    } else {
        parentLi.classList.add('is-open');
        el.setAttribute('aria-expanded', 'true');
        body.style.height = body.scrollHeight + 'px';
        body.addEventListener('transitionend', function done(e) {
            if (e.propertyName !== 'height') return;
            body.style.height = 'auto';
            body.removeEventListener('transitionend', done);
        });
    }
}
</script>



<!-- ══ Instant Search Overlay ═══════════════════════════════ -->

<div class="search-overlay" id="searchOverlay">
    <div class="search-header">
        <?php /* The only way out of a full-screen overlay, so it needs a name: an
                 unlabelled × is announced as just "button". The icon is hidden from
                 assistive tech because the label now says the same thing. */ ?>
        <button class="search-close" onclick="closeSearchOverlay()" aria-label="Close search"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="search-container">
        <div class="search-input-wrapper">
            <input type="text" class="search-input" id="searchInput" aria-label="Search for products or categories"
                   placeholder="Search for products, categories..." autocomplete="off">
            <?php /* title alone is not a reliable accessible name and shows nothing on
                     touch, where this button mostly lives. Every other icon-only control
                     in this header carries aria-label; this one was the exception. */ ?>
            <button class="voice-search-btn" id="voiceSearchBtn" title="Search by Voice" aria-label="Search by voice">
                <i class="fa-solid fa-microphone"></i>
            </button>
        </div>
        
        <div class="search-results-area">
            <div class="search-suggestions">
                <div class="search-suggest-title">Popular Searches</div>
                <ul>
                    <?php
                    // "Wedding Guest Edit" and "Co-Ord Sets" are not categories that
                    // exist — both suggestions returned an empty shop. Suggest real
                    // ones that actually have stock.
                    foreach (array_slice($menuCategories ?? [], 0, 4) as $sc): ?>
                        <li><a href="<?= htmlspecialchars(categoryUrlByName($pdo, $sc['name'])) ?>"><?= htmlspecialchars($sc['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                
                <div class="search-suggest-title">Recent Searches</div>
                <ul id="recentSearchesList">
                    <!-- Populated by JS -->
                </ul>
            </div>
            
            <div class="instant-results">
                <div class="instant-results-title">Products</div>
                <div class="instant-results-grid" id="instantResultsGrid">
                    <p class="search-placeholder-text">Start typing to see results...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function openSearchOverlay() {
        const overlay = document.getElementById('searchOverlay');
        overlay.style.display = 'flex';
        // Small delay to allow display block to apply before opacity transition
        setTimeout(() => {
            overlay.style.opacity = '1';
            document.getElementById('searchInput').focus();
        }, 10);
        dievonScrollLock.lock('search');
    }
    function closeSearchOverlay() {
        const overlay = document.getElementById('searchOverlay');
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
        }, 300);
        dievonScrollLock.unlock('search');
    }
</script>
