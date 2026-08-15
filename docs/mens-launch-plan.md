# Menswear launch — Women / Men selector

Plan for the Aza-style **WOMEN | MEN** switcher. Written 2026-08-03.

Nothing here is built yet except Step 0, which is already done and live in the code.

---

## The goal

A toggle at the top of the site. Click **MEN** and the whole shop becomes menswear —
menu, categories, banners, listings. Click **WOMEN** and it switches back. The choice
is remembered while the customer browses.

---

## Step 0 — Already done ✅

`categories.gender` — `ENUM('women','men','unisex') DEFAULT 'women'`.

- Migration: `update_new_database.php`, group "Menswear groundwork"
- Set in admin: **Categories → Collection Page SEO → Who this collection is for**
- Only on top-level collections; sub-collections inherit from the top of the tree
- Read anywhere via `categoryGender(int $catId)` / `productGender(array $product)` in `config/config.php`

Already consuming it: category page titles, the Google Merchant feed (`g:gender`),
and the site meta description.

**This is the foundation the whole switcher depends on.** Without it there is nothing
to filter by.

---

## The one decision that shapes everything: URLs, not just a cookie

The obvious build is "set a cookie, filter everything". **Do not do only that.**

If `/` shows womenswear by default and menswear only when a cookie is set, then
Googlebot — which carries no cookie — only ever sees the women's homepage. The entire
menswear homepage would be invisible to search, and no amount of good product data
fixes it.

**Do this instead:**

| URL | Shows | Notes |
|---|---|---|
| `/` | Women (default) | Unchanged from today |
| `/men` | Men | A real, indexable page |

`/men` needs no routing work — `.htaccess:90` already maps `/<name>` to
`pages/<name>.php`, so creating `pages/men.php` is enough.

The cookie still exists, but only to keep **context while browsing** (so the menu stays
menswear after clicking into a product). The URL is what makes the content real.

Category pages are already fine — they have their own distinct addresses
(`/collections/mens-shirts`), so they index correctly whatever the cookie says.

---

## Step 1 — Data model

There are **two different kinds of content**, and only one of them needs tagging. Getting
this distinction right removes most of the work.

### A. Content that filters itself — no new column

Anything built from products or categories already knows its gender, because
`categories.gender` exists and `productGender()` derives from it. These sections change
automatically:

| Section | Filters by |
|---|---|
| `collections` | category gender |
| `new_arrivals` | product gender |
| `best_sellers` | product gender |

Nothing to tag. Add the gender condition to the query and they are done.

### B. Content a computer cannot judge — must be tagged

A photograph of a woman in a kurti is not machine-readable. Hand-made content has to be
labelled by a human:

```sql
ALTER TABLE banners         ADD COLUMN gender ENUM('women','men','both') NOT NULL DEFAULT 'women';
ALTER TABLE mega_menu_links ADD COLUMN gender ENUM('women','men','both') NOT NULL DEFAULT 'women';
```

Optionally, to hide a whole section from one gender (a "Wedding Edit" row that only makes
sense for womenswear):

```sql
ALTER TABLE homepage_sections ADD COLUMN gender ENUM('women','men','both') NOT NULL DEFAULT 'both';
```

Note `homepage_sections` defaults to **`both`**, not `women` — these are structural rows
(hero, newsletter, Instagram) that should appear in every view unless deliberately
restricted. `banners` and `mega_menu_links` default to `women`, because the three banners
and eight menu links that exist today genuinely are womenswear.

The third value is **`both`**, not `unisex`, on purpose. On a category "unisex" describes
the clothing; on a banner it means "show in either view" — a sale message or a delivery
promise. Different meaning, different word.

### What the current 8 sections do when someone clicks MEN

| Section | Behaviour |
|---|---|
| `hero_slider` | **Changes** — needs men's banners uploaded, else it shows kurtis to a man |
| `collections` | **Changes** — automatic, lists men's categories |
| `new_arrivals` | **Changes** — automatic, newest men's products |
| `best_sellers` | **Changes** — automatic, men's best sellers |
| `editorial_story` | **Stays** — the brand story is not gendered |
| `reviews` | **Stays** — brand-level |
| `instagram` | **Stays** — one account |
| `newsletter` | **Stays** — one list |

So the page keeps its shape. Four sections swap their contents, four are brand-level and
never change. The layout a customer sees is the same either way — which is the point.

**The one that will look broken if skipped is `hero_slider`.** All three banners today are
womenswear (The Silk Kurtis, 3-Piece Suits, Coord Sets). Without men's banners the toggle
switches the menu but the biggest image on the page still shows a woman.

---

## Step 2 — Which gender is the visitor shopping?

Add to `config/config.php`, mirroring the existing `currentCountryCode()`:

```php
function currentShopGender(): string   // 'women' | 'men'
function shopGenderSelectorEnabled(): bool
```

Rules, in order:

1. If the page is `/men`, the answer is `men` — the URL always beats the cookie
2. Otherwise use the `dievon_shop_for` cookie if it holds a valid value
3. Otherwise `women`

**Validate the cookie against reality**, exactly as `currentCountryCode()` validates
against *enabled* countries: if a stale cookie says `men` but no men's category has live
stock, fall back to `women`. Never trust a cookie to describe the catalogue.

`shopGenderSelectorEnabled()` returns false unless **both** genders have live stock —
the same principle as `countrySelectorEnabled()`. Do not offer a choice that is not real.
While the shop is womenswear-only the toggle simply does not render, so this whole
feature can ship before menswear exists.

---

## Step 3 — The toggle itself

Two links, styled as tabs, in `includes/header.php` above or beside the logo:

```
WOMEN  |  MEN
```

- They are real `<a href>` links to `/` and `/men` — not JavaScript. Crawlable, and they
  work with middle-click and "open in new tab"
- Clicking also sets the `dievon_shop_for` cookie so the choice survives into
  `/shop` and product pages
- Mark the active side with `aria-current="page"`, not colour alone

⚠️ `includes/header.php` is shared with the home page. Confirm no other session is
editing it before starting.

---

## Step 4 — Filter the menu

`includes/header.php` builds `$menuCategories` and already drops categories with no live
stock (`categoryHasStock`). Add a second condition: keep a category when its
`categoryGender()` matches `currentShopGender()`, **or** is `unisex`.

The footer's Collections list reads the same `$menuCategories`, so it follows for free.

---

## Step 5 — Filter the shop page

`pages/shop.php`:

- Product listings limited to the current gender
- The **filter sidebar category list** limited too — it currently lists *every* category
  with no stock check at all (`shop.php:520`), which is why "Testing" appears today
- A `?collection=` URL for the other gender should **switch context** rather than return
  an empty grid — if someone opens a men's collection while the cookie says women, flip
  the cookie and show it

---

## Step 6 — Homepage per gender

`pages/home.php` filters `homepage_sections` and `banners` by
`gender IN (currentShopGender(), 'both')`.

`pages/men.php` can be a thin wrapper that sets the gender and includes the same home
template — one layout, two URLs. Avoid duplicating the homepage markup.

---

## Edge cases — decide these before building

| Case | Behaviour |
|---|---|
| Unisex category | Appears in **both** views |
| Men has no live stock | Toggle hidden entirely, `/men` 404s or redirects |
| Men's product opened directly while cookie says women | Switch context to men — never wrap a men's product in womenswear navigation |
| Search results | Search **across both**. Someone searching "linen shirt" wants it found |
| Cart | **Never filter.** A cart may legitimately hold both genders |
| Wishlist | Same — never filter |
| `/men` when menswear is off | Redirect to `/`, do not show an empty page |

---

## Test checklist

- [ ] Toggle does not render while only womenswear has stock
- [ ] `/` unchanged for a visitor with no cookie
- [ ] `/men` reachable and indexable with no cookie set (test with cookies disabled)
- [ ] Choice survives: home → menu → category → product → back
- [ ] Unisex category appears under both
- [ ] Cart holding one men's and one women's item survives a toggle
- [ ] Search finds a men's product while in the women's view
- [ ] Google feed still sends `female` for womenswear, `male` for menswear
- [ ] Sitemap lists both genders' collections
- [ ] Stale `dievon_shop_for=men` cookie with menswear switched off falls back cleanly

---

## Build order

Blockers first — the toggle is worthless without menswear to show.

1. **Men's size ladder** — `config/size_ladder.php` is one global women's array
   (`30/XS…46/5XL`, where the number is a **bust** measurement) required by **6 files**.
   Men's sizing is chest / collar / waist. This must be done before any real men's
   product is loaded, or every men's size button shows women's numbers.
2. **Product form category dropdown** — currently a flat list of names, resolved by
   `WHERE name = ... LIMIT 1` (`admin/product_form.php:123`). The day "Bottoms" exists
   under both Men and Women, the dropdown shows it twice with no way to tell them apart
   and products silently land in the wrong gender. Show `Men → Bottoms` and post the id.
3. **Create Men in admin** — collection, sub-collections, size chart, products kept
   unavailable
4. **Steps 1–6 above** — the switcher
5. **Go live** — set the men's products available

Items 1 and 2 are genuinely blocking. 2 is small and prevents bad data being entered,
so it is the cheapest thing to do first.

---

## Owner work (not code)

- Men's banners and homepage imagery — otherwise the men's view shows women's photos
- Men's size chart measurements
- Men's category meta titles and descriptions (leave blank and the generated title is
  used, which is now gender-aware — but written copy is better)
- Decide the men's category tree, **two levels only**: the menu renders
  `Men → Shirts` but not `Men → Shirts → Formal`
