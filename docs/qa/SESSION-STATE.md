# Where this work stands — read this first if you are picking it back up

Last updated 15 Aug 2026, mid-audit. Branch `claude/chat-session-875zz6`.

## What is finished and safe

All code work is **committed and pushed**. Nothing is lost by stopping here.

| Commit | What it did |
|---|---|
| `9021731` | Per-size prices reach the storefront; stop losing them in admin |
| `e36972e` | Price box on colour-size cards; warn when a price cannot apply |
| `ef9792f` | Refuse a negative size price instead of reading it as "follow the product" |
| `1a7e0a1` | `.claude/skills/verify/SKILL.md` — how to run this shop locally |
| `3691561` | Every price through `effectiveVariantPrice()`; harden the size-price box |
| `ee86544` | Checkout no longer bills more than it displays; RCE and XSS closed |
| `e444dbf` | Checkpoint of in-flight QA fixes — **not reviewed** |

`docs/qa/` holds the bug register, the go-live plan and the pre-flight SQL.

## What was still in flight when this stopped

Five QA engineers were running, roughly 30 minutes in, covering areas the first
ten audits did not reach:

1. Product create/edit/**clone**, SKU, categories, stock, media, validation
2. Coupons, GST, shipping, COD, rounding, order totals
3. Order numbers, invoices, cancellations, returns, refunds, stock restoration
4. Staff permissions, admin accounts, 2FA, CMS, blog XSS
5. Search, filters, pagination, wishlist, compare, quick view

**Their findings were not delivered.** Commit `e444dbf` contains the code they had
changed by that point, unreviewed. Anything in it may need revising or reverting
once the reasoning behind it is known — in the first wave, two reported findings
turned out to be the auditor's own test error rather than real defects.

If you resume: re-run those five audits rather than trusting `e444dbf` blind. The
briefs are in the conversation; the environment recipe is in
`.claude/skills/verify/SKILL.md`.

## Database changes made during the audit

These were applied to a **local test database only**. Decide separately whether
they belong on live.

```sql
-- Real bug: productCountryPricing() selects price, sale_price while the table
-- carried price, mrp. The query threw 1054, a bare catch swallowed it, and
-- country pricing was silently dead for every product.
ALTER TABLE product_country_prices ADD COLUMN sale_price DECIMAL(10,2) NULL AFTER price;
ALTER TABLE product_country_prices ADD UNIQUE KEY uniq_product_country (product_id, country_code);
ALTER TABLE product_country_prices ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
```

Separately worth knowing: **a fresh install of this shop cannot bootstrap itself.**
`update_new_database.php` needs `store_settings` to exist before it will run, and
it is itself the thing that creates it. The local database had to be built by
scraping `CREATE TABLE` statements out of the app's own source before the
migration tool would start. On live this only matters for a new environment.

## The one thing that does not wait

`database/recovery_2026-08-11/env.backup.txt` held the live **Razorpay key
secret**, the **SMTP password** and a **backup cron token** in cleartext, and was
git-tracked from the initial commit. It is untracked now and `.gitignore` covers
the pattern — but **it is still in git history**, and history is permanent.

**Rotate all three.** This is independent of launching, of this branch, and of
anything else in this document.

## Launch position at the time of stopping

**NOT READY**, for these reasons, in order:

1. Credentials in git history — rotate (above).
2. Six critical defects were found; five are fixed. See `PRE-LAUNCH-BUG-REGISTER.md`.
3. Six High findings remain open: empty sitemap, 19 of 25 products uncrawlable,
   HTTPS mis-detected behind a proxy, `www`/subdomains indexable, 88 schema
   statements per request, and no database indexes beyond primary keys.
4. Product lifecycle, coupons, GST, shipping, returns, refunds and staff
   permissions were **never fully tested** — the audits covering them did not finish.

Nothing found so far says the shop is broken for a normal purchase. The blockers
are credential exposure, discoverability, and untested money paths — not a
storefront that fails to sell.
