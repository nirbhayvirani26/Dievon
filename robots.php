<?php
// Dynamically generated so the Sitemap: line always points at whichever domain
// this is actually being served from (dievon.com, stage.dievon.com, localhost, etc.)
// instead of a hardcoded domain that goes stale the moment the site moves.
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

Sitemap: <?= SITE_URL ?>/sitemap.xml
