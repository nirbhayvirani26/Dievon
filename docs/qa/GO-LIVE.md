# Dievon — go-live plan

Branch `claude/chat-session-875zz6`, 26 commits, 36 files. Rewritten 15 Aug 2026.

> **This document replaced an earlier one that described a 4-file release.** That
> version was written when the branch was 4 commits long and is no longer safe to
> follow — uploading 4 of these 36 files lands you in exactly the broken half-state
> it warned about. If you have a copy of the old plan, discard it.

---

## 0. Two things to do first, before any upload

**Rotate three credentials.** `database/recovery_2026-08-11/env.backup.txt` held the
live Razorpay key secret, the SMTP password and the backup cron token in cleartext,
and was git-tracked from the initial commit. The file is untracked now, but git
history is permanent: anyone who has ever cloned this repository has all three.
Rotate them. This is independent of this release and does not wait for it.

**Confirm this repository is what dievon.com actually runs.** Everything below was
verified against a local copy of `main` plus this branch. dievon.com was never
contacted from here. Download the live `pages/product.php` and
`admin/variant_handler.php` and diff them against `main`:

```bash
diff live_product.php pages/product.php          # after: git checkout main
diff live_variant_handler.php admin/variant_handler.php
```

If they match, the live site is `main` and this plan applies as written. If they
differ, live is carrying changes this branch does not know about, and uploading
would silently revert them — stop and reconcile first.

---

## 1. Upload these 36 files

```
.htaccess                          ← read section 2 before uploading this one
config/config.php
includes/header.php
includes/footer.php
includes/product_card.php
robots.php
sitemap.php
ajax_search.php
google_merchant_feed.php

pages/product.php
pages/shop.php
pages/cart.php
pages/checkout.php
pages/account.php
pages/home.php

actions/cart_action.php
actions/promo_action.php
actions/compare_action.php
actions/quick_view_action.php

services/EmailService.php
services/RefundService.php

admin/product_form.php
admin/variant_handler.php          ← NOT in UPLOAD_LIST.txt. Easy to miss.
admin/products.php
admin/category_handler.php
admin/returns.php
admin/suppliers.php
admin/banners.php
admin/blog.php
admin/seo.php
admin/email_logs.php
admin/reset_password.php

assets/css/style.css
assets/css/responsive.css
assets/js/dievon-dialog.js
admin/assets/css/style.css
```

**Do not upload** `docs/`, `.claude/`, `.gitignore` or anything under
`database/`. They are developer notes and change nothing on the site.

CSS and JS are cache-busted with `filemtime()`, so uploading the file is enough —
no version number to bump, no cache to clear.

### These are not independent

The whole set goes up together. The dependencies that bite hardest:

- **`config/config.php` is the spine.** `cartRepriceLive()`, `safeImageExtension()`
  and `isCanonicalSiteHost()` all live in it, and `cart_action.php`,
  `checkout.php`, `product_form.php` and `robots.php` all call into it. Any of
  those four without config.php is an immediate fatal error on a live page.
- **`product_form.php` without `variant_handler.php`** → the admin sends a price to
  a handler that does not know about it. Editing a size's stock silently wipes its
  price, no warnings appear, and a negative price is accepted. The worst partial
  state.
- **`cart_action.php` / `checkout.php` without `config.php`** → the bag stops
  re-pricing, and checkout goes back to billing more than it displays. That was the
  most serious bug in this release; a partial upload reintroduces it.
- **`header.php` without `footer.php`** → the page opens `<main>` and never closes
  it, and the two copies of `formatPriceJS()` disagree about thousands separators.
- **`product.php` missing** → admin can set per-size prices the shop still will not
  display. Back to the original bug.

---

## 2. `.htaccess` — the one file that can take the site down

It is the only file here that changes how the **server** behaves rather than how a
page renders, and three of its rules are new:

1. **Force HTTPS** in production (skips localhost).
2. **`www` → apex, 301.** `www.dievon.com/x` now redirects to `dievon.com/x`.
3. **Refuse to execute anything under `uploads/`** — this is what closes the proven
   remote-code-execution hole, and it must not be dropped.

**Check before uploading: is `dievon.com` (no www) already serving the site on its
own?** Open `https://dievon.com` in a private window. If it loads, rule 2 is safe.
If your hosting is set up with `www` as the primary and the apex is not configured,
rule 2 sends every visitor to a domain that does not answer. That is a full outage,
and it is the single riskiest line in this release.

Related, and worth knowing so it does not surprise you: `robots.txt` is now served
by `robots.php`, and a **non-canonical host answers `Disallow: /`**. That is
deliberate — every `*.dievon.com` serves the full catalogue, so www and any staging
subdomain were complete self-endorsing duplicates competing with production. The
www 301 means crawlers never reach that branch on www. But it does mean the two
files belong together: `robots.php` without the `.htaccess` rewrite is not served
at all, and `.htaccess` without `robots.php` rewrites to a file that is not there.

---

## 3. No database changes

Nothing to migrate. No new tables, no new columns, no data rewritten on deploy.
Do **not** run `update_new_database.php` for this release.

Two columns the new code touches are already handled:

- `orders.stock_deducted` — added automatically by `config/db.php` on connect.
- `login_attempts` — already in use by `admin/login.php` on the current live code,
  so the table is there. If it somehow is not, the throttle fails **open**: sign-in
  and password reset keep working, just without the server-side limit.

Because there is no database step, **rollback is just re-uploading the previous
files.** No data loss, no orders affected, roughly a five-minute recovery.

---

## 4. Before you upload — run this on the live database

Read-only. Nothing is modified. Run it in phpMyAdmin.
The same query is in `docs/qa/preflight-price-audit.sql`.

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

Read that carefully, because the direction matters: the bag was **already charging**
the `shown_after` figure. These pages have been quoting a price the checkout would
not honour. The change makes the page tell the truth.

- **No rows** → nothing visible changes for shoppers. Safest possible deploy.
- **A few rows, figures look intentional** → those are prices you set. They start
  displaying correctly. Ship it.
- **Rows you don't recognise** → stop. A price you never typed is most likely a
  leftover from duplicating a product, and it has been quietly charging customers.
  Fix the data on the old admin first. Deploying first would make a wrong price
  *visible* rather than merely charged.

---

## 5. After you upload — smoke test, about ten minutes

Use one real product that has colours **and** per-size prices.

**Money — this is the part that matters**
1. Product page: click each size. **The price heading must move.**
2. Add one size to the bag. **The bag total must equal what the page showed.**
3. Go to checkout. **Total Due must equal the bag.** If any two of these three
   disagree, roll back — that is the bug this release exists to fix.
4. Switch to COD. The ₹49 fee appears and the total goes up by exactly ₹49.
5. Place one real order, smallest item, and check the invoice matches the screen.

**Admin**
6. That product → a colour → confirm a **Price** box sits beside each size's Stock
   box, with the stored figure in it.
7. Change one size's **stock only**, save, reload. **The price must still be there.**
   (It used to vanish.)
8. Type `-50` into a price box. It must be refused and snap back.
9. Clear a price box entirely. It should store "follow the product" — the size then
   shows whatever the product price is, and changes with it.

**Server**
10. `https://dievon.com/robots.txt` → must read `Allow: /`, not `Disallow: /`.
    If it reads `Disallow: /`, the host check is not matching; roll back
    `robots.php` immediately, before Google recrawls.
11. `https://www.dievon.com` → must land on `https://dievon.com`, one hop.
12. `https://dievon.com/sitemap.xml` → must list products, not be empty.
13. Upload a normal JPG as a product image — it must still work.

**On an actual phone** — this release reshapes the mobile product page
14. Open a product with a long description. The photograph, the name and the
    price should all be visible **without scrolling**. The old decorative band
    above the photo is gone on a phone; it is unchanged on a desktop.
15. The description shows four lines and a **Read more**. Tap it: the rest opens
    and the label becomes Read less. A product with a one-line description
    should show no toggle at all.
16. A sticky **Add to Bag** bar sits at the bottom, **in place of** the
    Home / Search / Login dock — on product pages only. Every other page keeps
    its dock.
17. Scroll down to the real Add to Bag button: the sticky bar should retreat, and
    return once you scroll past it.
18. Tap the sticky bar **before** choosing a size → the same "Please select a
    size" message, and the bag stays empty. Choose a size, and the bar's price
    must move to that size's price. Tap again → added.

---

## 6. Two behaviours that will look like bugs but aren't

You chose both deliberately.

- **A size priced above a discounted product sells at the discounted price.** If the
  product has an MRP higher than its price, a dearer size is capped. Admin now tells
  you when this happens. To actually charge more, raise the product price or clear
  the MRP.
- **A colour's Price Override beats every per-size price under it.** While an
  override is set, the size price boxes in that colour do nothing — they are drawn
  dashed and dimmed, and saving one repeats the warning. Clear the override to make
  size prices apply.

---

## 7. What has and has not been verified

**Driven in a real browser against a running copy of the shop**, with a real
database and real orders: the price ladder end to end (admin → database →
product page → bag → checkout → order → invoice); the original bug reproduced
and confirmed fixed; COD checkout end to end; returns and refunds with stock
restoration; coupon capping; order status ladder; product cloning; customer
account scoping; the admin reset-code throttle; and the whole storefront's
price formatting. `PRE-LAUNCH-BUG-REGISTER.md` has the per-bug detail.

**Not verified, both blocked by the environment rather than the code:**

- **Exchanges end to end.** The flow allows one request per order and that one went
  to a return. Walk it through once on live with a throwaway order.
- **Automated Razorpay refunds.** Their servers are unreachable from here, so the
  refund call is verified up to the point of dispatch and no further. Watch the
  first live refund.

Nothing here has been run against your **real product data** — only a local database
built for the audit. Section 4's query is the check that covers that gap, and it is
the one to run even if you skip everything else.
