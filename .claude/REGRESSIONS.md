# What must still work

Behaviours that were broken once, fixed, and are easy to lose again — because
most of them are invisible until they fail. Baseline commit: `e73ff24`.

When new code or a new design arrives, diff it against that commit and check
anything in this list that its files touch. **Do not overwrite blind.**

---

## Storefront — highest risk

A redesign usually replaces `assets/css/*`, `pages/*` and `includes/*` whole.
Every item below lives in one of those.

| Must still work | Where | How to check |
|---|---|---|
| Sliders answer a trackpad — two fingers sideways scrolls the rail, and the rail follows the fingers pixel-for-pixel rather than hopping a slide | `includes/footer.php` | Two-finger swipe a rail on a laptop |
| Sliders can be dragged with a mouse — links and images inside a rail have `draggable="false"`, or the browser's own drag-and-drop eats the gesture | `includes/footer.php`, `assets/css/style.css` | Press a tile and pull |
| Rails show a grab cursor — check the SELECTOR `.owl-carousel.owl-drag { cursor: grab }` exists, not just the text "cursor: grab", which also appears on the lightbox and photo-fan. The redesign deleted these three rules and left the comment behind, and a text search passed anyway | `assets/css/style.css` | Hover a rail |
| A sideways swipe on a slider does NOT scroll the page — `touch-action: pan-y` on `.owl-carousel.owl-drag`, its stage and stage-outer | `assets/css/style.css` | Swipe a rail on a phone |
| Checkout refuses a foreign postcode at the address step, with a red message under the field, not at Place Order | `pages/checkout.php`, `assets/css/style.css` | Type `HA3 8JD` |
| Section spacing is one value in one place (`responsive.css` line ~11), covering `.section-space` AND `.occasion-banners-section` | `assets/css/responsive.css` | Gaps identical between every homepage section |
| Home page sections do not repeat the same products | `pages/home.php` | Compare New Arrivals / Best Sellers / Trending |
| Occasion tiles are built from the occasions INSIDE each product's list, each tile a different garment | `pages/home.php`, `config/config.php` | Tiles read "Casual", not "Casual / Everyday / Day Out" |
| "New" badge disappears 30 days after a product was added — including the carousel's fallback badge | `includes/product_card.php`, `config/config.php` | A product added >30 days ago shows no badge |
| Product page and Quick View skip gallery rows whose file is missing, instead of drawing a broken tile | `pages/product.php`, `actions/quick_view_action.php` | — |
| Collections row centres when there are too few to fill it; the phone rail stays left-aligned and scrollable | `assets/css/style.css` | Resize with 2 collections |

## Money — check these hardest

| Must still work | Where |
|---|---|
| Razorpay charges the currency the shopper was quoted, not a hardcoded `INR` | `actions/razorpay_order.php` |
| The paid-amount check uses the same minor unit the charge was created with | `actions/checkout_action.php` |
| No Indian GST on an export — both price modes, frozen at zero, not `null` | `actions/checkout_action.php` |
| A foreign postcode is refused server-side whatever the browser did | `actions/checkout_action.php` |
| Cart lines never enter the bag at ₹0 — everything prices through `effectiveVariantPrice()` | `actions/cart_action.php` |

## Admin

| Must still work | Where |
|---|---|
| Saving a product is not refused on the first press — the page's own AJAX saves refresh the concurrent-edit stamp | `admin/product_form.php`, `admin/product_stamp.php` |
| Saving is fast — no PNG re-encode (3.9s for a 17% gain); the WebP twin does the real work | `config/config.php` |
| The admin header does NOT load the whole product table on every screen — only `settings.php` reads it | `admin/includes/header.php` |
| Gallery rows are never deleted because a file is missing — quarantined files must be restorable | `config/config.php` (`productCoverFallback`) |
| Renaming a product updates its slug and meta title, and leaves hand-written ones alone | `admin/product_form.php` |
| The fabric rule tests `str_contains`, not equality — "Khadi Cotton" must not contradict "Cotton". **This rule exists TWICE** | `admin/product_form.php` AND `config/config.php` |
| Stock Status shows a legible badge — 12px at 6:1 contrast, never 10px at 2.4:1 | `admin/assets/css/style.css` |
| Inventory & Stock and Customers & Inquiries keep their search, filters and sorting | `admin/settings.php`, `admin/customers.php` |
| Emails hold their light palette in dark mode | `services/EmailService.php` |

---

## Traps that have bitten this project

- **`hidden` needs a CSS guard.** Any author `display` rule beats the attribute. Always ship `.thing[hidden] { display: none; }` in the same edit.
- **`style.css` declares selectors twice.** The last one wins. Check before editing.
- **Two copies of one rule drift.** The fabric check was fixed in the publish path and stayed broken on the SEO page for weeks.
- **One scroll-lock owner.** Use `dievonScrollLock.lock/unlock`, never `body.style.overflow`.
- **Deploy excludes `uploads/` and `assets/images/`.** Add/overwrite only — mirror wipes live photographs.
