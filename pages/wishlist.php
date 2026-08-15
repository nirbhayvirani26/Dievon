<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

/* No catalogue is loaded here any more.
   ────────────────────────────────────────────────────────────────────────────
   This page used to SELECT every available product and json_encode the lot into
   the HTML, so the browser could filter it down to the handful of ids saved in
   localStorage. Every visitor was sent the whole shop in order to be shown the
   three things they had saved, and it got heavier with each product added.

   The saved ids are only known to the browser, so the browser now asks for just
   those, and actions/wishlist_cards_action.php returns them already rendered —
   through includes/product_card.php, the same partial the shop, the home rails
   and the suggested strip use. Country pricing, sold-out state, badges, Quick
   View and the hover photograph all come along with it, because they are the
   card's own behaviour rather than something this page re-implements. */

$pageTitle = "My Wishlist";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "Pieces you have saved from the Dievon collection.";
$noindex = true;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero section-mb-sm has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(3) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Your Curations</span>
        <h1>Private Wishlist</h1>
        <p>Your private, hand-selected edit of Dievon luxury pieces.</p>
    </div>
</section>

<!-- ══ Wishlist Items Section ═════════════════════════════ -->
<section class="section-space">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        
        <?php /* Saved pieces the shop is not currently selling. Named, not
                 counted — see the endpoint. */ ?>
        <p id="wishlistUnavailable" role="status"
           style="display:none; text-align:center; margin:0 0 26px; padding:12px 16px;
                  background:var(--bg-surface, #faf8f6); border:1px solid var(--border-light);
                  border-radius:8px; color:var(--text-secondary); font-size:13px; line-height:1.6;"></p>

        <!-- Grid of Wishlisted Items -->
        <div class="products-grid" id="wishlistGrid">
            <!-- Rendered by actions/wishlist_cards_action.php from the shared card partial -->
        </div>

        <?php /* Something has to be on screen between asking and answering, or
                 the page reads as an empty wishlist for the length of the round
                 trip — the one message it must not show by accident. */ ?>
        <div id="wishlistLoading" style="text-align:center; padding:60px 0; color:var(--text-secondary);">
            <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true" style="font-size:22px; color:var(--color-primary);"></i>
            <p style="margin-top:14px; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">Gathering your pieces…</p>
        </div>

        <div id="wishlistError" role="alert" style="display:none; text-align:center; padding:60px 20px;">
            <p style="color:var(--text-secondary); font-size:14px; margin-bottom:18px;">
                Your saved pieces could not be loaded just now.
            </p>
            <button type="button" class="btn-luxury" onclick="renderWishlist()">Try Again</button>
        </div>

        <!-- Empty Wishlist State -->
        <div id="wishlistEmptyState" style="text-align: center; padding: 80px 0; display: none;">
            <div style="font-size: 50px; margin-bottom: 20px; color: var(--text-muted);">🖤</div>
            <h2 style="font-family: var(--font-heading); font-size: 26px; font-weight: 400; text-transform: uppercase; margin-bottom: 15px;">Your Wishlist is Empty</h2>
            <p style="color: var(--text-secondary); font-size: 14px; max-width: 400px; margin: 0 auto 30px; line-height: 1.6;">
                Save your favorite luxury pieces here to revisit and style them later.
            </p>
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury">Shop Collections</a>
        </div>

    </div>
</section>

<script>
    /* The list of every live product id used to be printed here so the page
       could prune ids for deleted products out of localStorage. That answer now
       comes back with the cards themselves, in `alive` — one round trip instead
       of a second catalogue dump, and it only reports on the ids actually
       asked about rather than listing the whole shop. */

    document.addEventListener('DOMContentLoaded', renderWishlist);

    let wishlistBusy = false;

    async function renderWishlist() {
        const grid    = document.getElementById('wishlistGrid');
        const empty   = document.getElementById('wishlistEmptyState');
        const loading = document.getElementById('wishlistLoading');
        const errBox  = document.getElementById('wishlistError');
        const ids     = getWishlist();

        const show = (el, on) => { if (el) el.style.display = on ? (el === grid ? 'grid' : 'block') : 'none'; };

        if (!ids.length) {
            // The note describes saved pieces, so with nothing saved it has to
            // go too — otherwise an empty wishlist still explains an absence
            // that is no longer there.
            const note0 = document.getElementById('wishlistUnavailable');
            if (note0) { note0.style.display = 'none'; }
            grid.innerHTML = '';
            show(grid, false); show(loading, false); show(errBox, false); show(empty, true);
            return;
        }

        // Un-hearting several pieces quickly must not leave a slower earlier
        // response overwriting a newer one.
        if (wishlistBusy) { return; }
        wishlistBusy = true;

        show(empty, false); show(errBox, false); show(loading, true); show(grid, false);

        try {
            const url = `${window.SITE_URL}/actions/wishlist_cards_action.php?ids=`
                      + encodeURIComponent(ids.join(','));
            const data = await (await fetch(url)).json();
            if (!data.success) { throw new Error(data.message || 'load failed'); }

            /* Forget ids whose product no longer exists, then correct the badge.
               `alive` deliberately answers "does this still exist", not "is it
               sold where you are" — a piece unavailable in the shopper's country
               is still theirs and stays saved, it simply shows as unavailable. */
            const alive = new Set((data.alive || []).map(String));
            const kept  = ids.filter(id => alive.has(String(id)));
            if (kept.length !== ids.length) {
                localStorage.setItem('dievon_wishlist', JSON.stringify(kept));
                if (typeof updateWishlistBadge === 'function') { updateWishlistBadge(); }
            }

            show(loading, false);

            /* Name the pieces that are saved but not on sale at the moment, so
               a wishlist badge of three above a grid of two is explained rather
               than left looking broken. */
            const note = document.getElementById('wishlistUnavailable');
            const away = data.unavailable || [];
            if (note) {
                if (away.length) {
                    const list = away.map(n => '“' + n + '”').join(', ');
                    note.textContent = away.length === 1
                        ? list + ' is not on sale at the moment. It stays saved here.'
                        : list + ' are not on sale at the moment. They stay saved here.';
                    note.style.display = 'block';
                } else {
                    note.style.display = 'none';
                }
            }

            if (!kept.length || !data.html.trim()) {
                // Emptied, not merely hidden. Leaving the previous cards in the
                // DOM behind display:none means the next time the grid is shown
                // it flashes the pieces the shopper just removed.
                grid.innerHTML = '';
                show(grid, false);
                // Only truly empty when nothing is saved at all — otherwise the
                // note above is doing the explaining and "empty" would be a lie.
                show(empty, !away.length);
                return;
            }

            grid.innerHTML = data.html;
            show(grid, true);

            // Fill in the hearts, and let the entrance animation pick up cards
            // that did not exist when the observer was first set up.
            if (typeof syncWishlistHearts === 'function') { syncWishlistHearts(); }
            if (typeof dievonObserveReveal === 'function') {
                grid.querySelectorAll('.product-card').forEach(c => dievonObserveReveal(c));
            }
            if (typeof syncBagNotes === 'function') { syncBagNotes(); }
        } catch (e) {
            show(loading, false); show(grid, false); show(empty, false); show(errBox, true);
        } finally {
            wishlistBusy = false;
        }
    }

    /* The shared card's heart toggles rather than removes, so taking a piece
       off the list here means re-drawing it. Debounced, because the card is
       removed from a list the shopper is still looking at and an immediate
       redraw would fight their next click. */
    let wishlistRedraw = null;
    document.addEventListener('dievon:wishlist-changed', () => {
        clearTimeout(wishlistRedraw);
        wishlistRedraw = setTimeout(renderWishlist, 350);
    });
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
