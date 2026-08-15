<?php
// ============================================================
//  Dievon – Canonical menswear size ladder.
//
//  The womenswear ladder lives beside this in size_ladder.php. Do not merge
//  them: the CODES are deliberately identical (XS … 5XL) so that
//  product_variants.size_code means the same thing for every product and needs
//  no migration, while the NUMERIC differs because it measures a different part
//  of a different body.
//
//    womenswear  numeric = BUST   (30/XS … 46/5XL)
//    menswear    numeric = CHEST  (36/XS … 52/5XL)
//
//  That distinction is the whole reason this file exists. Serving the women's
//  ladder on a men's product told a 40" chest he was a 5XL.
//
//  Nine rungs, mirroring the women's ladder, so any code that pairs the two by
//  index stays aligned.
//
//  These are the conventional Indian ready-to-wear menswear chest sizes and are
//  a sane starting point, not gospel — the same footing as the women's ladder.
//  Confirm them against your actual patterns before the first men's product goes
//  live, because a size chart is a promise you can be returned against.
//
//  Nothing here is per-product data, so this file is safe to require from both
//  the admin panel and the storefront.
// ============================================================
return [
    ['code' => 'XS',  'numeric' => 36, 'label' => '36/XS'],
    ['code' => 'S',   'numeric' => 38, 'label' => '38/S'],
    ['code' => 'M',   'numeric' => 40, 'label' => '40/M'],
    ['code' => 'L',   'numeric' => 42, 'label' => '42/L'],
    ['code' => 'XL',  'numeric' => 44, 'label' => '44/XL'],
    ['code' => '2XL', 'numeric' => 46, 'label' => '46/2XL'],
    ['code' => '3XL', 'numeric' => 48, 'label' => '48/3XL'],
    ['code' => '4XL', 'numeric' => 50, 'label' => '50/4XL'],
    ['code' => '5XL', 'numeric' => 52, 'label' => '52/5XL'],
];
