---
name: verify
description: Build, run and drive the Dievon shop locally to observe a change working — storefront, admin panel and the AJAX handlers. Use when verifying a change, reproducing a bug, or capturing before/after evidence.
---

# Running Dievon locally

No MySQL, web server or seed data ships with the repo, so a runtime check needs
the setup below. Roughly 10 minutes cold; every step is scripted.

## 1. Database

`pdo_mysql` is present but no server is. Install and start one:

```bash
apt-get update -qq && apt-get install -y -qq mariadb-server
mariadbd-safe --user=root --datadir=/var/lib/mysql --socket=/var/run/mysqld/mysqld.sock &
```

Root authenticates by unix socket, which PDO cannot use — create a password user:

```sql
CREATE USER 'dievon'@'%' IDENTIFIED BY 'dievonpass';
GRANT ALL PRIVILEGES ON dievonfashion.* TO 'dievon'@'%';
```

Then `.env` (gitignored — never commit it):

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=dievonfashion
DB_USER=dievon
DB_PASS=dievonpass
```

## 2. Schema

**There is no full schema dump in the repo.** `config/db.php` auto-creates ~19
tables on first successful connection, but it assumes `products`,
`product_variants`, `categories` and `product_country_prices` already exist —
create those four by hand first, then load any page and db.php fills in the rest.

- The `products` column list is the INSERT in `admin/product_form.php` (~line 1170).
- `product_variants` needs `id, product_id, name, price, available, sort_order,
  image`; db.php then adds `color_id`, `size_code`, `stock_qty`.
- `admin_users` / `admin_login_history` are created by `update_new_database.php`
  (~line 848) — copy those CREATEs out; you cannot reach the admin panel without them.
- Expect one or two more missing columns as you go (e.g. `orders.is_deleted`);
  add them as the fatal errors name them.

## 3. Web server

The app **hardcodes** `SITE_URL = http://localhost:8888/DievonOrders` and only
trusts hosts `localhost:8888` / `127.0.0.1:8888` (`TRUSTED_LOCAL_HOSTS`,
config/config.php:45). Anything else 301s to the live site — do not let that
happen by accident. So: serve on port **8888**, from a docroot where
`DievonOrders` is a symlink to the repo.

`php -S` ignores `.htaccess`, so a router is required for clean URLs
(`/product/<slug>-<id>` → `pages/product.php?id=<id>`, `/<name>` →
`pages/<name>.php`). **Require the target at top level, never inside a function
or closure** — the app relies on `$pdo` being a true global, and
`includes/product_card.php` reads it as one.

```bash
mkdir -p /tmp/docroot && ln -sfn "$PWD" /tmp/docroot/DievonOrders
php -S 127.0.0.1:8888 -t /tmp/docroot /tmp/docroot/router.php
```

## 4. Driving it

Playwright + Chromium are preinstalled — import from
`/opt/node22/lib/node_modules/playwright/index.mjs`. Do not run
`playwright install`.

- Storefront product page: `/DievonOrders/product?id=N` (301s to the canonical
  slug URL — follow redirects).
- Admin: `/DievonOrders/admin/login.php`, then reuse `storageState`.

Gotchas that cost time:

- **`page.fill()` does not fire this app's `onchange` handlers.** Every admin
  field saves via `onchange`. Click the field, `Control+A`, `keyboard.type(...)`,
  then `Tab` to blur. Watch for the `variant_handler.php` POST to confirm a save
  actually happened — a green flash is not proof.
- The branded dialog (`dievonAlert`) needs `.dv-dialog-overlay.is-open`, and it
  is `position: fixed`, so `offsetParent === null` is **not** a valid visibility
  test. Use `page.isVisible('.dv-dialog-overlay.is-open')`.
- Verify money end to end: the product page, the bag and the DB row are three
  different answers and have disagreed before. Check all three.

## 5. Before/after evidence

`git checkout origin/main -- <file>` to reproduce the old behaviour, then
`git checkout HEAD -- <file>` to restore. Confirm `git status` is clean afterwards.
