-- ══════════════════════════════════════════════════════════════════════════
--  DIEVON PRE-LIVE AUDIT — READ ONLY. Nothing is changed by this query.
--  Lists every COLOUR-scoped size whose displayed price will change once the
--  new product page ships. The bag ALREADY charged the "after" figure; only
--  the price the shopper SEES is changing, so every row here is a page that
--  was quoting a figure the checkout would not honour.
-- ══════════════════════════════════════════════════════════════════════════
SELECT
    p.id                                   AS product_id,
    p.name                                 AS product,
    c.color_name                           AS colour,
    v.name                                 AS size,
    p.price                                AS product_price,
    p.mrp_price                            AS product_mrp,
    c.price_override                       AS colour_override,
    v.price                                AS size_price,
    -- What the page showed BEFORE: the colour's price, never the size's.
    COALESCE(c.price_override, p.price)    AS shown_before,
    -- What it shows AFTER: effectiveVariantPrice() precedence, then sale clamp.
    CASE
      WHEN c.price_override IS NOT NULL THEN c.price_override
      WHEN v.price > 0 AND p.mrp_price > p.price AND p.price > 0 AND v.price > p.price
           THEN p.price                                   -- sale clamp
      WHEN v.price > 0 THEN v.price
      ELSE p.price
    END                                    AS shown_after
FROM product_variants v
JOIN products       p ON p.id = v.product_id
JOIN product_colors c ON c.id = v.color_id
WHERE v.color_id IS NOT NULL
  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
HAVING shown_before <> shown_after
ORDER BY ABS(shown_after - shown_before) DESC, p.id;
