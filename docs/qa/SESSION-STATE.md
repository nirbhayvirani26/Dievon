# Where this work stands — read this first if you are picking it back up

Last updated 15 Aug 2026. Branch `claude/chat-session-875zz6`, 22 commits, all pushed.

## What is finished and safe

All code work is **committed and pushed**. Nothing is lost by stopping here.

Every finding below was driven against a running copy of the shop — real MariaDB,
real browser, real orders — not read off the source. `.claude/skills/verify/SKILL.md`
is the recipe for standing that environment up again; it takes about ten minutes.

The audit ran in waves: ten targeted passes, then five QA engineers covering
product lifecycle, money, orders/returns, permissions and discovery. The engineers'
own commit (`e444dbf`) went in unreviewed when they were cut short — **that has
since been read line by line and re-driven**, so it is no longer an open risk.
Two of their reported findings turned out to be the auditor's test error rather
than real defects, which is why nothing here is recorded on a report alone.

`docs/qa/` holds the bug register, the go-live plan and the pre-flight SQL.
`PRE-LAUNCH-BUG-REGISTER.md` is the full account: severity, root cause, files,
fix and regression result for each.

## What is left

**Nothing in the code.** The last three open items closed on 15 Aug:

- the admin password-reset code could be ground out by deleting a cookie (H11),
- six form fields had a placeholder and no name, and the header search box could
  not be opened from a keyboard at all,
- every price JavaScript wrote was missing its thousands separator, so a checkout
  page carried `₹14,400.00` and `₹14400.00` at the same time.

Two paths were **never exercised end to end**, and both are blocked by the
environment rather than by the code:

- **Exchanges.** The flow allows one request per order, and that one was spent on
  a return. Worth walking through once on live with a throwaway order.
- **Automated Razorpay refunds.** Their servers are unreachable from here, so the
  refund call itself has only been verified up to the point of dispatch.

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

`docs/qa/indexes.sql` holds the index additions, and `preflight-price-audit.sql`
the queries to run against live before deploying.

Separately worth knowing: **a fresh install of this shop cannot bootstrap itself.**
`update_new_database.php` needs `store_settings` to exist before it will run, and
it is itself the thing that creates it. The local database had to be built by
scraping `CREATE TABLE` statements out of the app's own source before the
migration tool would start. On live this only matters for a new environment.

## The two things that do not wait, and are yours

Neither is a code change, which is why neither can be done from here.

**1. Rotate three credentials.** `database/recovery_2026-08-11/env.backup.txt` held
the live **Razorpay key secret**, the **SMTP password** and a **backup cron token**
in cleartext, and was git-tracked from the initial commit. It is untracked now and
`.gitignore` covers the pattern — but **it is still in git history**, and history is
permanent. Anyone who has ever cloned this repo has those three secrets. Rotate all
three. This is independent of launching, of this branch, and of everything else here.

**2. Confirm this repository is what dievon.com actually runs.** Everything in this
document was verified against a local copy of `main` plus this branch. dievon.com was
never contacted. Before deploying, diff the live server's `pages/product.php` and
`admin/variant_handler.php` against `main` — if the live files differ, the live site
is carrying changes this branch does not know about, and merging would revert them.

## Launch position

**READY WITH MINOR FIXES** — blocked only on the two items above.

Every Critical and every High finding is fixed and regression-tested. The money
paths hold: order totals are computed server-side, the Razorpay signature is
verified in both the checkout action and the webhook, historical orders are
immutable, coupons cap correctly, and all 40 test orders reconcile as
`lines − discount + delivery_charge + cod_fee`. A normal purchase — browse, pick a
size, pay, receive the invoice, return, refund — works from end to end.
