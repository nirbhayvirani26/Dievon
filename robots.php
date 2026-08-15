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

/* A non-canonical host invites nobody.
   ────────────────────────────────────────────────────────────────────────────
   Every *.dievon.com serves the full shop, and this file used to answer
   "Allow: /" with its own Sitemap: line on all of them — so www.dievon.com and
   any staging subdomain were complete, self-endorsing duplicates of the
   catalogue, each advertising its own sitemap. That is how a staging site ends
   up competing with production for its own products.

   www is additionally 301'd to the apex in .htaccess. This is the second line of
   defence, and the only one that covers a subdomain nobody remembered to
   redirect. */
if (!isCanonicalSiteHost()) {
    echo "User-agent: *\nDisallow: /\n";
    exit;
}
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
