<?php
// ============================================================
//  Dievon – Canonical numeric+letter size ladder.
//  Colour variants pick a subset of these per colour; nothing
//  here is per-product data, so this file is safe to require
//  from both the admin panel and the storefront.
//
//  ── Why M is 38 and not 34 ──────────────────────────────────
//  This started on a Biba-style grade where M was 34. The mills
//  Dievon actually buys from grade two sizes larger: a supplier
//  sheet reads "M-38, L-40, XL-42, XXL-44".
//
//  Under the old numbers that collided head-on. 38 meant XL here
//  and M to the supplier, 40 meant 2XL here and L there — so
//  ticking the box that matched the supplier's NUMBER sold the
//  garment under a letter two full sizes too large, and ticking
//  the LETTER printed a number two sizes too small. Every size of
//  every product from those suppliers was wrong in the same
//  direction, which is the kind of error that comes back as
//  returns rather than as a complaint.
//
//  'code' is deliberately unchanged. It is the value stored in
//  product_variants.size_code, so it is the key that ties an
//  existing variant, a cart line and an order back to a size.
//  Renumbering the labels is cosmetic; renaming the codes would
//  orphan every variant already saved.
//
//  NOTE: variants saved BEFORE this change keep the old label in
//  product_variants.name ("38/XL" and so on), because that column
//  holds what the shopper was shown at the time. New ticks use
//  the labels below. See the note in admin/product_form.php.
// ============================================================
return [
    ['code' => 'XS',  'numeric' => 34, 'label' => '34/XS'],
    ['code' => 'S',   'numeric' => 36, 'label' => '36/S'],
    ['code' => 'M',   'numeric' => 38, 'label' => '38/M'],
    ['code' => 'L',   'numeric' => 40, 'label' => '40/L'],
    ['code' => 'XL',  'numeric' => 42, 'label' => '42/XL'],
    ['code' => '2XL', 'numeric' => 44, 'label' => '44/2XL'],
    ['code' => '3XL', 'numeric' => 46, 'label' => '46/3XL'],
    ['code' => '4XL', 'numeric' => 48, 'label' => '48/4XL'],
    ['code' => '5XL', 'numeric' => 50, 'label' => '50/5XL'],
];
