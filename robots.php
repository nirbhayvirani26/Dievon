<?php
// Dynamically generated so the Sitemap: line always points at whichever domain
// this is actually being served from (dievon.com, stage.dievon.com, localhost, etc.)
// instead of a hardcoded domain that goes stale the moment the site moves.
//
// What is NOT blocked here, deliberately: /cart, /checkout, /account, /wishlist,
// /orders and internal search results. Those carry <meta robots="noindex">, and
// blocking a URL in robots.txt stops a crawler fetching it — which means it never
// sees the noindex, so the page can still surface in results as a bare link.
// Crawlable-and-noindex is the combination that actually keeps them out.
require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');
?>
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /actions/
Disallow: /config/
Disallow: /scratch/
Disallow: /tools/
Disallow: /services/
Disallow: /update_new_database.php

# The catalogue's own JSON endpoint for "load more". Crawling it wastes budget on
# responses that render nothing.
Disallow: /*?ajax=
Disallow: /*&ajax=

Sitemap: <?= SITE_URL ?>/sitemap.xml
