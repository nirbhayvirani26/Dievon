# Dievon — go-live plan for per-size pricing

Branch `claude/chat-session-875zz6`, 4 commits. Written 15 Aug 2026.

---

## 1. Upload exactly these 4 files

```
pages/product.php
admin/product_form.php
admin/variant_handler.php      ← NOT in UPLOAD_LIST.txt. Easy to miss.
admin/assets/css/style.css
```

`.claude/skills/verify/SKILL.md` is a developer note. It changes nothing on the
site; upload it or don't.

**These four must go up together.** They are not independent:

- `product_form.php` without `variant_handler.php` → the admin sends a price for
  colour sizes to a handler that doesn't know about `$priceProvided`. Editing a
  size's stock silently wipes its price again, no warnings appear, and a
  negative price is accepted. This is the worst of the partial states.
- `variant_handler.php` without `product_form.php` → the new refusals work but
  there is no price box to type into.
- `style.css` missing → prices still work; the dashed "this box is inactive"
  styling just doesn't show. Cosmetic only.
- `product.php` missing → admin can set prices the shop still won't display.
  Back to the original bug.

CSS is cache-busted with `filemtime`, so uploading the file is enough — no
version number to bump, no cache to clear.

## 2. No database changes

Nothing to migrate. No new tables, no new columns, no data rewritten on deploy.
Do **not** run `update_new_database.php` for this release.

This also means rollback is just re-uploading the 4 previous files.

## 3. Before you upload — run this on the live database

Read-only. Nothing is modified. Run it in phpMyAdmin.

```sql
SELECT
    p.id AS product_id, p.name AS product, c.color_name AS colour, v.name AS size,
    p.price AS product_price, p.mrp_price AS product_mrp,
    c.price_override AS colour_override, v.price AS size_price,
    COALESCE(c.price_override, p.price) AS shown_before,
    CASE
      WHEN c.price_override IS NOT NULL THEN c.price_override
      WHEN v.price > 0 AND p.mrp_price > p.price AND p.price > 0 AND v.price > p.price
           THEN p.price
      WHEN v.price > 0 THEN v.price
      ELSE p.price
    END AS shown_after
FROM product_variants v
JOIN products       p ON p.id = v.product_id
JOIN product_colors c ON c.id = v.color_id
WHERE v.color_id IS NOT NULL
  AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
HAVING shown_before <> shown_after
ORDER BY ABS(shown_after - shown_before) DESC, p.id;
```

**Every row it returns is a product whose displayed price will change.**

Read that carefully, because the direction matters: the bag was **already
charging** the `shown_after` figure. These pages have been quoting a price the
checkout would not honour. The change makes the page tell the truth.

- **No rows returned** → nothing visible changes for shoppers. Safest possible
  deploy.
- **A few rows, and the figures look intentional** → those are prices you set.
  They start displaying correctly. Ship it.
- **Rows you don't recognise** → stop. A price you never typed is most likely a
  leftover from duplicating a product, and it has been quietly charging
  customers. Fix the data first: set the size price to the product price, or
  blank it, on the old admin before deploying. Deploying first would make a
  wrong price *visible* rather than merely charged.

## 4. After you upload — 5-minute smoke test

Use one real product that has colours **and** per-size prices.

1. Open the product page. Click each size. **The price heading must move.**
2. Add one size to the bag. **The bag total must equal what the page showed.**
   This is the whole point of the release — if these two disagree, roll back.
3. Admin → that product → a colour → confirm a **Price** box now sits beside
   each size's Stock box, with the stored figure in it.
4. Change one size's **stock only**, save, reload the page. **The price must
   still be there.** (It used to vanish.)
5. Type a price into a size on a product that is **on sale**. You should get a
   dialog saying it will still sell at the sale price. That's expected, not a bug.
6. Type `-50` into any price box. It must be **refused** and the box must snap
   back to the previous figure.

## 5. Two behaviours that will look like bugs but aren't

You chose both of these deliberately.

- **A size priced above a discounted product sells at the discounted price.**
  If the product has an MRP higher than its price, a dearer size is capped.
  Admin now tells you when this happens. To actually charge more, raise the
  product price or clear the MRP.
- **A colour's Price Override beats every per-size price under it.** While an
  override is set, the size price boxes in that colour do nothing — they're
  drawn dashed and dimmed, and saving one repeats the warning. Clear the
  override to make size prices apply.

## 6. Rollback

Re-upload the previous versions of those 4 files. No database step, no data
loss, no orders affected. Roughly a two-minute recovery.

---

## Status — read before shipping

**Verified in a browser against a local copy of the shop:**
size click updates the price; page and bag agree; the old bug reproduced and
confirmed fixed; per-size price box renders and saves; stock edit no longer
wipes a price; clamp, override and negative-price paths all behave.

**Not yet verified:** five audits are still running — storefront, admin,
customer account, and two on end-to-end wiring — including how per-size prices
reach **orders, invoices and the Google Merchant feed**. I have not seen their
results yet.

Nothing tested so far has been run against your **real product data**, only a
local database I built.

If you want to ship before those finish, section 3's query is the check that
matters most — it tells you what your own catalogue will do.
