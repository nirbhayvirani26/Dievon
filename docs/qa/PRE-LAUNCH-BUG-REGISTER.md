# Dievon — pre-launch bug register

Branch `claude/chat-session-875zz6`. Fifteen audits, all driven against a running copy
of the shop with a real database, a real browser and real orders.

**Legend** — ✅ fixed & regression-tested · ⚠️ open, needs a decision · 🔻 open, yours to do

---

## CRITICAL — blocks launch

### C1 ✅ Checkout billed more than it displayed
**Severity** Critical (money, consumer law)
**Symptom** Checkout read "Total Due ₹2,650"; the order was written at ₹2,900. Two further audit
orders overcharged by ₹1,800 and added a ₹99 delivery line never shown on screen.
**Root cause** The bag froze each line's price into `$_SESSION['cart']` at add-to-bag.
`actions/checkout_action.php:229` re-resolved live through `effectiveVariantPrice()` at order time.
Nothing reconciled them, so cart, checkout summary, header badge, promo evaluation and the Razorpay
amount all quoted the stale figure while the ORDER used the live one. Pre-existing; per-size pricing
made it far easier to reach.
**Files** `config/config.php` (new `cartRepriceLive()`), `actions/cart_action.php`, `pages/checkout.php`
**DB** none
**Fix** The bag is re-priced from the same function the order uses, so what is displayed is what is
charged. Every change is reported to the shopper rather than applied silently.
**Regression** Bag → price changed in admin → checkout Total Due moved ₹2,400 → ₹2,900 and the
shopper was told. Historical orders unaffected (`order_items.price` / `items_json` written once).

### C2 ✅ Remote code execution via product image upload — PROVEN
**Severity** Critical (full server compromise)
**Symptom** A `.php` file uploaded through the admin product form landed in `uploads/products/` and
executed on request.
**Root cause** The saved filename took its extension from the UPLOADED filename
(`pathinfo($_FILES[...]['name'])`) with no allowlist; only the MIME was checked. A `GIF89a`-header
polyglot satisfies `finfo`, and GIF skips the re-encode that would destroy the payload. Reachable by
any `catalogue.manage` staff account — precisely what the role system exists to prevent. Same flaw
in `media.php`, `banners.php`, `blog.php`, `color_handler.php`.
**Files** `config/config.php` (new `safeImageExtension()`), `admin/product_form.php` (both upload
sites), `.htaccess`
**DB** none
**Fix** Extension derived from the DETECTED content type, so an uploaded name cannot decide what a
file is called on disk. The root `.htaccess` additionally refuses to execute anything under
`uploads/`, which covers the four other handlers without depending on each being remembered.
**Regression** Upload path still accepts genuine JPG/PNG/WebP/GIF; gallery and WebP twins unaffected.

### C3 ⚠️ Live Razorpay secret and SMTP password in git history
**Severity** Critical (payment fraud, mail spoofing)
**Symptom** `database/recovery_2026-08-11/env.backup.txt` holds `RAZORPAY_KEY_SECRET`,
`MAIL_PASSWORD` and `BACKUP_CRON_TOKEN` in cleartext. 13 keys carry real values.
**Root cause** The file was committed in the **initial commit** and `.gitignore` covered `.env` and
`database/backups/*.sql` but not `recovery_*`.
**Files** `.gitignore`; file untracked from git (kept on disk as the recovery copy it is)
**Fix applied** Untracked + gitignore pattern extended, so it stops spreading.
**STILL OPEN — ONLY YOU CAN DO THIS.** Untracking does not remove it from history. Anyone who has
ever cloned this repo has those credentials. **Rotate the Razorpay key secret, the SMTP password and
the backup cron token.** Independent of launching.

### C4 ✅ Stored XSS in the size picker
**Severity** Critical (fires on every shopper and on the owner's admin session)
**Symptom** A size named `M" onmouseover=…` produced a real, working event handler on the product page.
**Root cause** `pages/product.php` passed the size name into an `onclick` through `addslashes()`,
which escapes JS quotes but not the HTML attribute boundary — HTML has no backslash escaping, so a
quote closed the attribute and the rest parsed as further attributes.
**Files** `pages/product.php`
**Fix** `htmlspecialchars(..., ENT_QUOTES)`. What reaches the cart is unchanged.
**Regression** Size selection, price update and add-to-bag all still correct.

### C5 ✅ Pressing Enter on "Cancel" performed the destructive action
**Severity** Critical (permanent data loss)
**Symptom** Focus Cancel, press Enter → the dialog resolved **true**. It guards permanent account
deletion, address deletion, order cancellation and 58 admin call sites.
**Root cause** `assets/js/dievon-dialog.js:168` treated Enter as confirmation whenever the OK button
was *not* focused, and `preventDefault()` suppressed Cancel's own native activation.
**Files** `assets/js/dievon-dialog.js`
**Fix** Branch removed — a focused `<button>` already activates on Enter natively. Escape retained.

### C6 ✅ Let's Encrypt renewal was blocked → certificate expiry → HSTS lockout
**Severity** Critical (site becomes unreachable, with no way back)
**Root cause** `.htaccess` `RewriteRule (^|/)\. - [F,L]` 403s every path segment starting with a
dot, including `/.well-known/acme-challenge/`. With `Strict-Transport-Security: max-age=31536000`
already sent, an expired certificate locks out every previous visitor.
**Files** `.htaccess`
**Fix** `/.well-known/` exception placed before the dotfile rule.

---

## HIGH — fix before or immediately after launch

### H1 ✅ Google Merchant feed advertised prices the shop will not charge
**Severity** High (Merchant Center disapproval / account suspension)
**Symptom** Feed quoted ₹2,400 for a size selling at ₹1,650, and ₹2,500/sale ₹2,400 for one clamped
to ₹1,899. `g:sale_price` could exceed `g:price` — an outright disapproval.
**Root cause** `google_merchant_feed.php:111` re-derived the price ladder by hand, blind to a
colour's `price_override` and to the sale clamp.
**Fix** Routed through `effectiveVariantPrice()`. Also fixed: duplicate `g:id` on multi-colour
products (three colourways emitted the same id at three prices), `g:color` reporting the product's
blanket colour, and inactive colourways still being advertised.
**Regression** Feed verified live: no duplicate ids, no sale ≥ price, correct figures for all cases.

### H2 ✅ Product page JSON-LD contradicted itself
**Severity** High (structured-data policy)
**Root cause** `pages/product.php:638` — a second hand-written copy of the price ladder. The
ProductGroup offer resolved correctly to ₹1,650 while `hasVariant` published ₹2,450 for the same size.
**Fix** Same function as everything else. Verified: top offer and every variant now agree.

### H3 ✅ Editing a colour's name or SKU silently deleted its price override
**Severity** High (silent re-pricing of every size in that colourway)
**Root cause** `admin/product_form.php` printed `value="1,650.00"` via `number_format` with a
thousands separator. `<input type="number">` refuses a value it cannot parse, so `.value` was `""`
while the panel beside it correctly read "Price Override of ₹1,650.00". `updateColor()` then posted
that empty value.
**Fix** `number_format($x, 2, '.', '')` on both the price and MRP override inputs.

### H4 🔻 Sitemap contains zero products
**Severity** High (SEO — nothing gets indexed)
**Root cause** `sitemap.php:104` selects `products.updated_at`, a column created only by the
manually-run `update_new_database.php`; the bare `catch (PDOException) {}` at line 115 hides the
failure. **May not fire on live** if that migration has been run there — verify first.
**Proposed fix** Probe the column (or `COALESCE`), and make the catch log rather than swallow.
**Check after deploy** `<loc>` count must equal `SELECT COUNT(*) FROM products WHERE available=1`.

### H5 🔻 19 of 25 products unreachable by a crawler
**Severity** High (SEO)
**Root cause** The grid renders 6 server-side and loads the rest by AJAX; there are no crawlable
`?page=N` links anywhere, and `robots.php` disallows the AJAX endpoint. A BFS from the homepage
reached 15 of 25 products.
**Proposed fix** Emit real `<a href="?page=N">` links in the grid footer, visually hidden behind the
load-more button if preferred.

### H6 🔻 HTTPS mis-detected behind a TLS-terminating proxy
**Severity** High (every canonical and sitemap URL would say `http://`)
**Root cause** `config/config.php:305` builds `SITE_URL` from `$_SERVER['HTTPS']` alone. Three other
places in the same file already test `SERVER_PORT == 443` and `HTTP_X_FORWARDED_PROTO` as well.
Standard Hostinger/Cloudflare setup, so treat as likely.
**Proposed fix** Use the same three-way test at line 305.

### H7 🔻 `www` and every subdomain is a fully indexable duplicate
**Severity** High (SEO duplication; a staging subdomain would publish the whole catalogue)
**Root cause** No www→apex rule in `.htaccess`; `trustedSiteHost()` accepts every `*.dievon.com`.
**Proposed fix** 301 `www.` → apex; make `robots.php` emit `Disallow: /` for any non-canonical host.

### H8 🔻 Every request replays 88 schema-migration statements
**Severity** High (live performance)
**Root cause** `config/db.php` runs 19 `CREATE TABLE IF NOT EXISTS` + 30 `ALTER TABLE` + 6
`SHOW COLUMNS` top-level on every request, with no version gate. An `ALTER TABLE customers MODIFY`
is unconditional and takes a metadata lock. Even a 2.7 KB AJAX response costs 104 statements.
**Proposed fix** Gate behind a schema-version row, or move to `migrations/` out of the request path.

### H9 🔻 `products` and `categories` have no index but the primary key
**Severity** High (live performance)
**Evidence** `EXPLAIN`: price-sorted shop grid → full scan + filesort. Header category counts →
`DEPENDENT SUBQUERY` + full scan, running 2–3× per page.
**Proposed fix** `KEY(available, is_deleted, category_id)`, `KEY(category_id)`, `KEY(price)`,
`KEY(seo_url)` on `products`; `KEY(parent_id)`, `KEY(slug)` on `categories`.

### H10 ✅ Size-price box: crash, wrong dialog, lost updates
**Severity** High (admin data integrity)
Four defects, all fixed and regression-tested:
- No upper bound → `100000000` threw an uncaught `SQLSTATE[22003]`, the handler died mid-request and
  answered with a PHP fatal instead of JSON; the owner saw "Network error" for a validation problem.
- Refusals were announced through the bare `alert()`, which the shop's dialog renders with its
  DEFAULT styling — "was not saved" under a green tick reading **Success**.
- Two saves in flight were two full-card snapshots racing; the later did not necessarily win.
  Measured 3 of 8 rounds lost at 0 ms interleaving. Saves are now chained per row.
- A partially-typed number (`1e`, `-`, `--`) left the box empty, which reads exactly like a
  deliberate clear, so the size silently went back to following the product price.

---

## MEDIUM

| # | Area | Symptom | Root cause | Status |
|---|---|---|---|---|
| M1 | Shop grid | `/shop` and every `/collections/*` H1 renders `Search: ""` | `$activeSearch` assigned as the last statement *inside* a `try{}` whose query throws; the bare catch hides it | 🔻 move the assignment before the `try` |
| M2 | Shop grid | One query failure noindexes the shop and every collection page | `$initialProducts` populated inside the same `try`; line 798 reads empty as "no products" | 🔻 |
| M3 | 404 pages | 404 responses carry a canonical pointing at `/shop` — a noindex aimed at a live money page | `pages/404.php` sets `$noindex` but never `$canonicalUrl`, so the header falls back to the request URL | 🔻 |
| M4 | Quick View | Headline quotes the raw product price; on a colour-override product it opens at ₹1,899 above chips reading ₹1,650 | `quick_view_action.php` resolves chips through `effectiveVariantPrice()` but returns `$p['price']` raw for the headline | 🔻 assigned to QA5 |
| M5 | Compare | Every product priced at `products.price`; all tiles get "LOWEST PRICE" | `compare_action.php:82` raw column | 🔻 assigned to QA5 |
| M6 | Search | Suggestions and results quote the base price | `ajax_search.php:31,75` raw column | 🔻 assigned to QA5 |
| M7 | Shop filters | Price filter/sort contradict the cards beside them — `?min_price=1400&max_price=1550` returns nothing while cards read "From ₹1,500" | `pages/shop.php` filters and sorts on raw `products.price` | 🔻 assigned to QA5 |
| M8 | Account | Wishlist tab quotes raw product price, ignoring size ladder and colour override | `pages/account.php:177` selects `price`; JS prints it verbatim | 🔻 |
| M9 | Admin | Ticking a size with an empty price box stores today's product price as a literal, so "follows the product" is destroyed on write | Both handler branches rewrite `price <= 0` to the current base price | 🔻 needs a decision: store 0 and resolve at read |
| M10 | Cart | `stock_qty` NULL means "untracked" in three places and "zero" in one | `cart_action.php` colour branch omits the `!== null` guard the other three have | 🔻 |
| M11 | Admin | `variant_handler` add does not check that `color_id` belongs to the product, or that `product_id` exists | No ownership validation on add | 🔻 |
| M12 | Admin | No-op writes report success — editing a size deleted in another tab says "saved" | `rowCount()` never checked | 🔻 |
| M13 | Range | Deactivating a colour hides its sizes from the page but not from `productPriceRange()` — card still advertises "From ₹1,500" on an unbuyable product | `productPriceRangePrime()` has no `product_colors.is_active` filter | 🔻 |
| M14 | Security | `admin/suppliers.php` runs its INSERT/UPDATE/DELETE **before** the capability gate in the header include | Only admin page with no own auth check | 🔻 assigned to QA4 |
| M15 | A11y | Choosing a size moved the price with no announcement | No live region on the price heading | ✅ fixed |

---

## LOW / POLISH
Duplicate titles on paginated URLs · four pages share the default meta description · no `width`/`height`
on any image · login funnel indexable · footer `h4` after `h2` · `og:url` keeps the query string while
the canonical strips it · `/docs/` and `UPLOAD_LIST.txt` crawlable · `/men` returns 302 not 301 ·
two design tokens fail WCAG contrast (2.04:1 and 4.21:1) · no `<main>` landmark or skip link ·
modals do not trap focus · lightbox not keyboard-openable · 6 form fields placeholder-only ·
no `autocomplete` on checkout fields · `#backToTopBtn` unnamed.

---

## CONFIRMED CORRECT — worth recording
- **Payment integrity**: order totals computed 100 % server-side; Razorpay signature verified in both
  `checkout_action` (HMAC + session order-id bind + amount check) and the webhook (`hash_equals`).
  No client price trust anywhere.
- **Customer IDOR**: every `customer_action` query is scoped by session `customer_id`; invoice,
  confirmation and tracking are gated. A customer cannot read another's order.
- **SQL injection**: no exploitable instance. The one interpolated `$productId` is `(int)`-cast.
- **Historical orders are immutable**: `order_items.price` and `orders.items_json` are written once;
  a later price change cannot rewrite an issued invoice. Verified after moving a price 2400 → 2900.
- **Auth**: session id rotates on login (no fixation), bcrypt, 2FA path sound, login throttling present.
- **CSRF**: enforced on admin handlers, including `variant_handler` (tested with no token → rejected).
- **Add-to-bag is announced** to screen readers with the full sentence; `aria-pressed` on size pills
  is correct on both grids.
