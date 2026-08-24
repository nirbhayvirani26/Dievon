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
| New Arrivals is the EXPANDING row running on Owl: one panel open at half the width, three narrow beside it, swapping on hover, one garment per press. The invariant that makes it work — **the four visible panels must always sum to exactly one viewport width, and every off-screen item must keep its quarter width**. Break it and the arrows drift by however much it is out. Widths are set in PIXELS by the script, never in CSS (`calc(var())` resolved against the stage, not the viewport). `.owl-item` needs `flex: 0 0 auto` or the flex stage shrinks them all back to equal | `pages/home.php`, `assets/css/style.css` (appended block, must stay LAST) | Widths read 717/239/239/239 at 1440px; row stays flush to both edges after pressing next |
| New Arrivals has NO grab cursor (unlike every other rail) — its panels open under the pointer and are links, so the hand was the wrong instruction. The global `.owl-carousel.owl-drag {cursor:grab}` rule must stay untouched for the other rails | `assets/css/style.css` | Hover New Arrivals = arrow; hover Shop by Occasion = hand |
| New Arrivals' panel widths animate WITH the slide, both 500ms, started from the same event. They must NOT be frozen during the translate and applied after — that reads as a blink, the incoming panel snapping to full width while the row is still gliding. The layout is handed the target index explicitly because Owl has not moved its `.active` classes yet at that moment | `pages/home.php` | Press next: the panel grows smoothly as the row moves, no jump |
| New Arrivals must NOT respond to a two-finger trackpad swipe — it is the one slider on the page whose panels resize as it moves, and a sideways gesture kept catching it while the homepage was being read. No `wheel` listener, and nothing calls preventDefault on it. Arrows, mouse drag and touch still move it. The OTHER rails keep their trackpad glide (`includes/footer.php`) | `pages/home.php` | Two fingers sideways over New Arrivals: nothing moves. Over Shop by Occasion: it slides |
| New Arrivals has a 12px gutter on mobile (matching every other rail) and NONE on desktop — edge-to-edge is the desktop idea, one continuous band of photography | `pages/home.php` (Owl `responsive.margin`) | Phone: gap between slides. Desktop: panels touch |
| `responsive.css` line 183's `@media (max-width: 991px)` must close at line 869, NOT at the end of the file. Closing it at EOF is valid CSS but traps ~1,300 top-level rules inside it — including the account page's ENTIRE desktop layout (`.account-layout-grid`, `.account-menu-list`), which exists nowhere else. Symptom: account page fine on a phone, unstyled bulleted list on a desktop | `assets/css/responsive.css` | `.account-layout-grid` computes `display: grid` at 1440 |
| EVERY slider on the homepage runs on Owl — hero, Collections, New Arrivals, Best Sellers, Trending, Shop by Occasion. Collections is Owl ONLY below 768px: above it the same markup is a mosaic grid with nothing to slide, so the init is gated on the breakpoint and Owl is destroyed when the viewport crosses back up | `pages/home.php`, `includes/footer.php` | Six rails report an Owl instance on a phone; Collections reports `display: grid` on a desktop |
| Collections' tile width is written in VIEWPORT units under Owl, not percentages. The mosaic sizes tiles with `calc((100% - gaps) / 2.3)` against the flex container; inside an `.owl-item` that percentage resolves against the STAGE and every tile comes out several screens wide | `assets/css/style.css` | Phone: 2.3 tiles visible, ~130px each |
| The fan's tilt and stacking live on `.owl-item:nth-child(...)`, NOT `.pf-item` — each `.pf-item` is an only child of its wrapper, so a `.pf-item:nth-child` rule makes every print print number one and the fan straightens out | `assets/css/style.css` | Prints alternate -3/-1/1/3; occasions -3/-1.5/upright/1.5/3 |
| Owl's `.owl-stage-outer` must carry the lift room (`padding-block` + negative `margin-block`) or the top of a hovered print is shaved off by the stage clip | `assets/css/style.css` | Hover a print: it lifts without being cut |
| New Arrivals has NO hover behaviour below 1025px — the panel opening under the pointer is the desktop idea, and on a touchscreen `:hover` sticks after a tap so a garment stayed zoomed and lifted. Gated on max-width, NOT `(hover: none)`, because a touchscreen laptop matches both and this is about the size of the row | `assets/css/style.css` | Tap a garment on a phone: nothing moves |
| Best Sellers and Trending show the SAME card as the other rails on a phone — photograph with the caption on it, no white box, no Quick View. The print styling is gated at 768px+, so without this they fall back to the boxed shop card and read as a different component | `assets/css/style.css` | Phone: caption sits on the photo like New Arrivals |
| Icon-only buttons must NOT carry the label gap. `button svg … { margin-right: 8px !important }` is for an icon before a word; on the pager arrows it put a 19px glyph 4px left of centre in a 44px button, on every pager | `assets/css/style.css` | Arrow has equal gaps both sides |
| Every rail's slide width is set in VIEWPORT units at each breakpoint, never percentages — under Owl a percentage resolves against the stage. Steps that exist: Collections 2.3 across; Occasion 5/4/3/1.5; New Arrivals 4-panel expand ≥1025, 38vw 768-1024, 67vw below. Miss a step and you get 154px tiles on a tablet or a panel taller than the screen | `assets/css/style.css` | Nothing taller than the viewport at 1024x768 |
| On mobile every rail matches: first slide starts at the edge inset (12-16px), ~two thirds of the screen per slide, 12px gutter, 40px section padding. New Arrivals gets there with `autoWidth` set AT INIT (never in `responsive` — Owl applies those after measuring and the row collapses to nothing) plus `.na-gallery.owl-loaded .na-panel { width: 67vw }`. The `.owl-loaded` in that selector is required: the desktop block sets `width: 100%` at the same specificity outside any media query | `pages/home.php`, `assets/css/style.css` | All three rails start within 4px of each other |
| `naPerView()` counts `.owl-item.active`, NOT `settings.items` — under autoWidth the setting is not what decides how many fit, and the counter read "1 / 5" on a phone showing one garment | `pages/home.php` | Phone counter differs from desktop's |
| Opening the Trending tab must RE-MEASURE the strip, not just rewind it. The inactive panel is `display:none`, so Owl initialises against a zero-width viewport and writes 0px onto every print — the tab opened onto an empty band | `pages/home.php` (`reset()` calls `sizeItems()` + `refresh.owl.carousel`) | Click Trending Now: garments appear |
| Quick View is centred TWICE over: the button in its card (`left:0; right:0; margin-inline:auto` — never `left:50%`, which shrink-to-fits an absolute box to the space from the centre to the right edge), and its CONTENTS in the button (`display:flex` with `align-items/justify-content:center` and `gap:9px` — the fan revealed it with `display:block !important`, which threw all three away and left the icon and label sitting left inside a perfectly centred button) | `assets/css/style.css` | Hover a print: eye and label centred on one line |
| Dievon Invitations lives in `includes/footer.php`, above `<footer>`, on every page — and is gated on `$minimalFooter` so checkout does not show it. Its handler `submitHomeNewsletter()` moved with it; left in home.php the form would ship everywhere and the code to one page | `includes/footer.php` | Band appears on /shop, /blog, /contact; not on checkout |
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
- **A new rail needs its hover gesture switched OFF on a phone.** `:hover` sticks after a tap on
  a touchscreen, so a hover zoom starts under the finger, animates through the whole drag and
  stays on after release — the rail reads as sluggish while the others feel clean. New Arrivals
  kills it at 1024, Collections at 768, the photo fan and Shop by Occasion at 767. Shop by
  Occasion was the one rail that never got the block, which is exactly how it was spotted.
- **Never bind per-slide work to `changed.owl.carousel`.** It fires as the settle animation
  BEGINS, so anything written there lands on the first frames of the incoming slide. Shop by
  Occasion renumbered every item's inline `z-index` there — values that never change, since they
  are derived from the index — and the layer re-ordering made that one strip feel rougher than
  the rest. Stacking belongs on `refreshed` (when Owl rebuilds items) or once at init, which is
  what the photo fan does.
- **A phone fires `resize` when the address bar slides away.** Height-only resizes hit while a
  finger is still on a rail. Any resize handler that measures or writes layout must compare
  `window.innerWidth` and bail when only the height moved.
- **Owl's last page overshoots under autoWidth.** It picks the final page by walking items from
  the right until they fill the viewport, then scrolls to that item's own coordinate — which lies
  past the end of the content when the last item is narrower than the viewport, parking the row
  with dead background on one side. `dvClampOwlTail()` in includes/footer.php corrects every rail
  on the site; do not re-solve it per rail. It skips `loop` rails (the hero has no end).
- **rAF does not fire in a background tab.** A correction scheduled on requestAnimationFrame
  silently does nothing while the tab is hidden, then shows the uncorrected state when the tab
  comes back. Use `setTimeout(fn, 0)` for anything that must run after a write regardless of
  visibility.
- **A full-bleed rail is a SECTION child, not a container child.** Shop by Occasion and The
  Dievon Edit both close `.container` after the heading, run the strip at the section's full
  width, and reopen a `.container` around the pager so the arrows stay on the page gutter.
  Compensating with a negative margin instead leaves the opposite edge inset and overflows the
  page the moment the parent changes.
- **Never pass a literal speed to `to.owl.carousel`.** `[next, 300]` silently overrides the
  rail's `smartSpeed`, so raising smartSpeed fixes only the drag-release path while every arrow
  press keeps the old speed. Shop by Occasion and Collections both did this: built at 500,
  arrowing at 300, beside New Arrivals at 500 and the fan at 620. Read `o.settings.smartSpeed`.
