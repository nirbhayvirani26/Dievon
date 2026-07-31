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

// Fetch categories for menu
$menuCategories = [];
try {
    $menuCategories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php 
        $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $defaultDesc = "Dievon is an ultra-premium luxury women's fashion brand offering elegant silk kurtas, hand-embroidered 3-piece suits, designer dresses, organza dupattas, and tailored co-ord sets.";
        $desc = isset($metaDescription) ? htmlspecialchars($metaDescription) : $defaultDesc;
        if (isset($pageTitle)) {
            // Avoid double-branding (e.g. "Dievon Atelier | ... – Dievon") when the title already names the brand.
            $title = (stripos($pageTitle, SHOP_NAME) !== false) ? htmlspecialchars($pageTitle) : htmlspecialchars($pageTitle) . " – " . SHOP_NAME;
        } else {
            $title = SHOP_NAME . " – " . SHOP_TAGLINE;
        }
        $image = isset($ogImage) ? htmlspecialchars($ogImage) : SITE_URL . "/assets/images/logo/logo.PNG";
    ?>
    <?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= $title ?></title>
    <meta name="description" content="<?= $desc ?>">
    <?php if (!empty($metaKeywords)): ?>
    <meta name="keywords" content="<?= htmlspecialchars($metaKeywords) ?>">
    <?php endif; ?>
    <?php // NOTE: the canonical link is emitted further down (it strips the query string
          // and honours a $canonicalUrl override) — do not add a second one here. ?>

    <?php if (defined('META_PIXEL_ID') && !empty(META_PIXEL_ID) && META_PIXEL_ID !== 'YOUR_FACEBOOK_PIXEL_ID'): ?>
    <!-- Facebook / Meta Pixel Base Code -->
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
    <meta property="og:locale" content="en_GB">
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
    <?php $canonicalHref = isset($canonicalUrl) ? $canonicalUrl : strtok($currentUrl, '?'); ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonicalHref) ?>">

    <!-- Schema.org Organization Structured Data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "Dievon Atelier",
      "url": "<?= SITE_URL ?>",
      "logo": "<?= SITE_URL ?>/assets/images/logo/logo.PNG"
    }
    </script>

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://checkout.razorpay.com">
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/logo/logo.PNG">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/responsive.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/fontawesome/css/all.min.css">
    <!-- All styles are in assets/css/style.css and assets/css/responsive.css -->

    <!-- Global Multi-Currency Engine (Defined Early in Head) -->
    <script>
    window.SITE_URL = '<?= SITE_URL ?>';
    window.CURRENCY_RATES = <?= json_encode($GLOBALS['CURRENCY_RATES']) ?>;
    const ENABLE_CURRENCY_SWITCHER = <?= defined('ENABLE_CURRENCY_SWITCHER') && ENABLE_CURRENCY_SWITCHER ? 'true' : 'false' ?>;

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    function getCurrentCurrency() {
        if (!ENABLE_CURRENCY_SWITCHER) return 'INR';
        return localStorage.getItem('dievon_currency') || getCookie('dievon_currency') || 'INR';
    }

    function formatPriceJS(amountInINR) {
        const curr = getCurrentCurrency();
        const rates = window.CURRENCY_RATES || {};
        const data = rates[curr] || rates['INR'] || { symbol: '₹', rate: 1.00 };
        const converted = (parseFloat(amountInINR) || 0) * data.rate;
        return data.symbol + converted.toFixed(2);
    }

    function changeCurrency(val) {
        document.cookie = "dievon_currency=" + val + "; path=/; max-age=" + (86400 * 30);
        localStorage.setItem('dievon_currency', val);
        if (typeof showToast === 'function') {
            showToast('Currency Updated', 'Prices switched to ' + val);
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
    window.addEventListener('resize', syncHeaderPadding);
    window.addEventListener('orientationchange', syncHeaderPadding);
    </script>
</head>
<body>

<!-- ══ Luxury Multi-Tier Header with Mega Menu ════════════════════════════ -->
<header class="navbar-luxury">
    <!-- Main Header Bar -->
    <div class="main-header-bar">
        <div class="container main-header-container">
            <!-- Left Hamburger Menu Button (Mobile) -->
            <button class="nav-hamburger" id="navHamburger" onclick="openMobileMenu()" aria-label="Open menu"><span></span><span></span><span></span></button>

            <!-- Brand Group: Logo -->
            <div class="header-brand-group">
                <a href="<?= SITE_URL ?>/home" class="logo">
                    <img src="<?= SITE_URL ?>/assets/images/logo/logo.PNG" alt="Dievon Luxury" fetchpriority="high">
                </a>
            </div>

            <!-- Center Search Bar (Desktop) -->
            <div class="header-search-wrap" onclick="openSearchOverlay()">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="inlineSearchInput" placeholder="Search kurta, shirts, dupattas and dresses..." readonly>
                <button class="header-image-search-btn" type="button">
                    <i class="fa-solid fa-camera"></i> <span class="hide-mobile">Image Search</span>
                </button>
            </div>

            <!-- Right Action Icons -->
            <div class="nav-actions">
                <!-- Multi-Currency Switcher -->
                <?php if (defined('ENABLE_CURRENCY_SWITCHER') && ENABLE_CURRENCY_SWITCHER): ?>
                <div class="currency-selector-wrapper">
                    <?php $currMode = getCurrentCurrency(); ?>
                    <select id="currencySelector" onchange="changeCurrency(this.value)" aria-label="Select Currency">
                        <option value="INR" <?= $currMode === 'INR' ? 'selected' : '' ?>>INR ₹</option>
                        <option value="GBP" <?= $currMode === 'GBP' ? 'selected' : '' ?>>GBP £</option>
                        <option value="USD" <?= $currMode === 'USD' ? 'selected' : '' ?>>USD $</option>
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
                <a href="<?= SITE_URL ?>/wishlist" class="nav-action-item" aria-label="Wishlist">
                    <i class="fa-regular fa-heart"></i>
                    <span class="action-badge" id="wishlistBadge">0</span>
                </a>
                <button class="nav-action-item btn-cart-trigger" id="cartToggle" onclick="openCart()" aria-label="View cart">
                    <i class="fa-solid fa-bag-shopping"></i>
                    <span class="action-badge" id="cartBadge"><?php echo $cartCount; ?></span>
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

    // Dynamic Parent & Sub-Category Query
    $headerParents = [];
    $headerSubMap  = [];
    try {
        $catRows = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC, name ASC")->fetchAll();
        foreach ($catRows as $cr) {
            if ((int)$cr['parent_id'] === 0) {
                $headerParents[] = $cr;
            } else {
                $headerSubMap[$cr['parent_id']][] = $cr;
            }
        }
    } catch (PDOException $e) {}
    ?>
    <div class="bottom-header-bar hide-mobile">
        <div class="container bottom-header-container">
            <ul class="nav-links">
                <li><a href="<?= SITE_URL ?>/shop?badge=New" class="nav-link-item nav-highlight-new <?= $isNewAct ? 'active' : '' ?>">NEW</a></li>
                
                <?php foreach ($headerParents as $pCat): ?>
                    <?php 
                    $pId     = (int)$pCat['id'];
                    $pName   = htmlspecialchars($pCat['name']);
                    $subCats = $headerSubMap[$pId] ?? [];
                    $pActive = ($curCat === $pCat['name'] || strpos($curUri, 'category=' . urlencode($pCat['name'])) !== false);
                    ?>
                    <!-- Dynamic Mega Menu for <?= $pName ?> -->
                    <li class="has-mega-menu">
                        <a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>" class="nav-link-item <?= $pActive ? 'active' : '' ?>">
                            <?= strtoupper($pName) ?> <i class="fa-solid fa-angle-down nav-arrow"></i>
                        </a>
                        <div class="mega-dropdown">
                            <div class="container mega-dropdown-container">
                                <div class="mega-column">
                                    <h5 class="mega-heading">Sub-Categories</h5>
                                    <ul>
                                        <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>">Shop All <?= $pName ?></a></li>
                                        <?php if (!empty($subCats)): ?>
                                            <?php foreach ($subCats as $sub): ?>
                                            <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($sub['name']) ?>"><?= htmlspecialchars($sub['name']) ?></a></li>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&style=Classic">Classic Edit</a></li>
                                            <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&style=Handcrafted">Handcrafted Essentials</a></li>
                                            <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&style=Printed">Printed Curation</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="mega-column">
                                    <h5 class="mega-heading">Collections &amp; Edits</h5>
                                    <ul>
                                        <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&badge=New">New Arrivals</a></li>
                                        <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&badge=Bestseller">Bestseller Edit</a></li>
                                        <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&badge=Festive">Festive Curation</a></li>
                                        <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&fabric=Pure+Silk">Pure Silk Ensemble</a></li>
                                    </ul>
                                </div>
                                <div class="mega-promo-column">
                                    <div class="mega-promo-card">
                                        <img src="<?= SITE_URL ?>/uploads/gallery/lookbook_1.png" alt="<?= $pName ?>">
                                        <div class="mega-promo-overlay">
                                            <h4><?= $pName ?> Collection</h4>
                                            <a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>" class="btn-mega-shop">Shop Now <i class="fa-solid fa-arrow-right"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>

                <li><a href="<?= SITE_URL ?>/blog" class="nav-link-item <?= $isJournalAct ? 'active' : '' ?>">JOURNAL</a></li>
                <li><a href="<?= SITE_URL ?>/about" class="nav-link-item <?= $isStoryAct ? 'active' : '' ?>">OUR STORY</a></li>
                <li><a href="<?= SITE_URL ?>/shop?sale=1" class="nav-link-item <?= $isSaleAct ? 'active' : '' ?>">SALE</a></li>
            </ul>
        </div>
    </div>
</header>

<!-- ══ Luxury Mobile Navigation Full-Screen Overlay ════════════════════════ -->
<div class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-nav-panel">
        
        <!-- Header Bar -->
        <div class="mobile-drawer-header">
            <a href="<?= SITE_URL ?>/home" class="mobile-drawer-logo-link" onclick="closeMobileMenu()">
                <img src="<?= SITE_URL ?>/assets/images/logo/logo.PNG" alt="Dievon Luxury">
            </a>
            <button class="mobile-drawer-close" id="mobileDrawerClose" onclick="closeMobileMenu()" aria-label="Close menu">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Search Bar -->
        <div class="mobile-drawer-search-bar" onclick="closeMobileMenu(); openSearchOverlay();">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <span class="search-placeholder">Search kurta, suits & dresses...</span>
        </div>

        <!-- Main Navigation Links -->
        <div class="mobile-drawer-body">
            <ul class="mobile-nav-links">
                <li><a href="<?= SITE_URL ?>/home" onclick="closeMobileMenu()" class="<?php echo $path == 'home' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="<?= SITE_URL ?>/shop?badge=New" onclick="closeMobileMenu()" class="nav-highlight-new">New Arrivals</a></li>
                
                <!-- Dynamic Mobile Accordion Categories (Matches Desktop Mega Menu) -->
                <?php foreach ($headerParents as $pCat): ?>
                    <?php 
                    $pId = (int)$pCat['id'];
                    $pName = htmlspecialchars($pCat['name']);
                    $subCats = $headerSubMap[$pId] ?? [];
                    ?>
                    <li class="mobile-nav-accordion">
                        <div class="mobile-accordion-header" onclick="toggleMobileCategoryMenu(this)">
                            <span><?= $pName ?></span>
                            <i class="fa-solid fa-angle-down accordion-arrow"></i>
                        </div>
                        <div class="mobile-accordion-body" style="display:none;">
                            <ul class="mobile-subnav-list">
                                <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>" onclick="closeMobileMenu()" style="font-weight:700; color:var(--color-primary);">Shop All <?= $pName ?> →</a></li>
                                <?php if (!empty($subCats)): ?>
                                    <?php foreach ($subCats as $sub): ?>
                                    <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($sub['name']) ?>" onclick="closeMobileMenu()"><?= htmlspecialchars($sub['name']) ?></a></li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&style=Classic" onclick="closeMobileMenu()">Classic Edit</a></li>
                                    <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&style=Handcrafted" onclick="closeMobileMenu()">Handcrafted Essentials</a></li>
                                    <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&style=Printed" onclick="closeMobileMenu()">Printed Curation</a></li>
                                <?php endif; ?>
                                <li class="mobile-subnav-divider" style="margin-top:6px; padding-top:6px; border-top:1px dashed var(--border-light); font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-muted);">Curated Edits</li>
                                <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&badge=New" onclick="closeMobileMenu()">New Arrivals</a></li>
                                <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&badge=Bestseller" onclick="closeMobileMenu()">Bestseller Edit</a></li>
                                <li><a href="<?= SITE_URL ?>/shop?category=<?= urlencode($pCat['name']) ?>&badge=Festive" onclick="closeMobileMenu()">Festive Curation</a></li>
                            </ul>
                        </div>
                    </li>
                <?php endforeach; ?>

                <li><a href="<?= SITE_URL ?>/blog" onclick="closeMobileMenu()" class="<?php echo $path == 'blog' ? 'active' : ''; ?>">Journal</a></li>
                <li><a href="<?= SITE_URL ?>/about" onclick="closeMobileMenu()" class="<?php echo $path == 'about' ? 'active' : ''; ?>">Our Story</a></li>
                <li><a href="<?= SITE_URL ?>/shop?sale=1" onclick="closeMobileMenu()" class="<?= $isSaleAct ? 'active' : '' ?>">Sale</a></li>
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
function toggleMobileCategoryMenu(el) {
    const parentLi = el.closest('.mobile-nav-accordion');
    const body = parentLi ? parentLi.querySelector('.mobile-accordion-body') : null;
    const arrow = el.querySelector('.accordion-arrow');
    if (!body) return;
    if (body.style.display === 'block') {
        body.style.display = 'none';
        if (arrow) arrow.style.transform = 'rotate(0deg)';
    } else {
        body.style.display = 'block';
        if (arrow) arrow.style.transform = 'rotate(180deg)';
    }
}
</script>



<!-- ══ Instant Search Overlay ═══════════════════════════════ -->

<div class="search-overlay" id="searchOverlay">
    <div class="search-header">
        <button class="search-close" onclick="closeSearchOverlay()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="search-container">
        <div class="search-input-wrapper">
            <input type="text" class="search-input" id="searchInput" placeholder="Search for products, categories..." autocomplete="off">
            <button class="voice-search-btn" id="voiceSearchBtn" title="Search by Voice">
                <i class="fa-solid fa-microphone"></i>
            </button>
        </div>
        
        <div class="search-results-area">
            <div class="search-suggestions">
                <h4>Popular Searches</h4>
                <ul>
                    <li><a href="<?= SITE_URL ?>/shop?category=Kurtis">Kurtis</a></li>
                    <li><a href="<?= SITE_URL ?>/shop?category=Wedding+Guest+Edit">Wedding Guest Edit</a></li>
                    <li><a href="<?= SITE_URL ?>/shop?category=Co-Ord+Sets">Co-Ord Sets</a></li>
                    <li><a href="<?= SITE_URL ?>/shop?search=Silk">Silk Dresses</a></li>
                </ul>
                
                <h4>Recent Searches</h4>
                <ul id="recentSearchesList">
                    <!-- Populated by JS -->
                </ul>
            </div>
            
            <div class="instant-results">
                <h4>Products</h4>
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
        document.body.style.overflow = 'hidden';
    }
    function closeSearchOverlay() {
        const overlay = document.getElementById('searchOverlay');
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
        }, 300);
        document.body.style.overflow = '';
    }
</script>
