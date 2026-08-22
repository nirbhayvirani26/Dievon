<?php
/* ============================================================================
   The price a shopper can actually pay, for ONE product.
   ----------------------------------------------------------------------------
   Lifted out of includes/product_card.php unchanged so the card and the New
   Arrivals editorial gallery resolve a price the same way. They were about to
   hold two copies of this, and the comment in the middle of it records what
   happens when two halves of the same section disagree about a price: a badge
   computed from products.price over a price line showing the cheapest buyable
   figure, understating a real 31% saving as 22%.

   Include it with $cardProduct set. It defines:
     $cardNotSoldHere, $cardCountryPricing, $cardPrice, $cardMrp,
     $cardRange, $cardVaries, $cardDearest

   $cardCountryPricing is initialised explicitly. In the card this was only
   assigned inside the country branch, so in a loop it would have carried the
   previous product's value into a product that skipped that branch. It never
   could in practice — the branch is taken for every product or for none, since
   the condition does not depend on the product — but a variable that survives
   an iteration by luck is not one to move into a second call site.
   ============================================================================ */

$cardCountryPricing = null;

// Country pricing: product_country_prices carries one flat price per
// product (see productCountryPricing() in config.php), so a shopper abroad
// either sees that figure or, with no row for their country yet, the card
// behaves like a sold-out one rather than showing an untranslated INR price.
$cardNotSoldHere = false;
if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
    $cardCountryPricing = productCountryPricing($cardProduct);
    if ($cardCountryPricing === null) {
        $cardNotSoldHere = true;
        $cardPrice = (float)($cardProduct['price'] ?? 0);
        $cardMrp   = (float)($cardProduct['mrp_price'] ?? 0);
    } else {
        $cardPrice = $cardCountryPricing['price'];
        $cardMrp   = $cardCountryPricing['mrp'];
    }
} else {
    $cardPrice = (float)($cardProduct['price'] ?? 0);
    $cardMrp   = (float)($cardProduct['mrp_price'] ?? 0);
}

/* The price a shopper can actually pay — resolved BEFORE anything is drawn.
   ────────────────────────────────────────────────────────────────────────────
   This used to sit further down, beside the price line, which put it AFTER the
   badge at the top of the card. So the badge was worked out from
   products.price while the price line beneath it showed the cheapest buyable
   figure, and the two disagreed on every product with a colourway override:
   trousers listed at 1250 with an MRP of 1600 and an Ivory colourway at 1100
   rendered "22% OFF" over "₹1,600 ₹1,100" — a real saving of 31%, understated
   by nine points by the card's own two halves not agreeing.

   Resolving it once, first, is what makes the badge, the strikethrough and the
   price a single consistent statement.

   Country pricing is one flat figure per product and overrides sizes entirely
   (see productCountryPricing), so it is left exactly as it was — the spread
   only applies where the product's own columns are the ones in play. */
$cardRange  = $cardCountryPricing ?? null;
$cardVaries = false;
$cardDearest = $cardPrice;   // the priciest size, needed to keep a "From" saving honest
if ($cardRange === null && !$cardNotSoldHere) {
    $r = productPriceRange($cardProduct);
    if ($r['varies'] || $r['min'] < $cardPrice - 0.005) {
        $cardPrice  = $r['min'];
        $cardVaries = $r['varies'];
    }
    $cardDearest = max((float)($r['max'] ?? $cardPrice), $cardPrice);
}
